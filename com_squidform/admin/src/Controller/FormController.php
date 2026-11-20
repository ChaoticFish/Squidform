<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController as BaseFormController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\DefinitionCheck;
use SquidForm\Component\SquidForm\Administrator\Engine\Drafts;

\defined('_JEXEC') or die;

class FormController extends BaseFormController
{
    protected $text_prefix = 'COM_SQUIDFORM_FORM';

    /** Archived forms are read-only: restore one before editing it. */
    protected function allowEdit($data = [], $key = 'id')
    {
        $id = (int) ($data[$key] ?? 0);

        if ($id) {
            $db    = \Joomla\CMS\Factory::getContainer()->get(DatabaseInterface::class);
            $state = (int) $db->setQuery('SELECT state FROM ' . $db->quoteName('#__squidform_forms') . ' WHERE id = ' . $id)->loadResult();

            if ($state === 2) {
                $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_FORM_ARCHIVED_READONLY'), 'warning');

                return false;
            }
        }

        return parent::allowEdit($data, $key);
    }

    /**
     * Builder autosave (0.7.0): store the draft and say what would stop it
     * from being published. AJAX, POST: id, json. A new form (id 0) is only
     * checked; it gets a draft once it has been saved.
     */
    public function draft(): void
    {
        if (!Session::checkToken('post')) {
            $this->json(['error' => Text::_('JINVALID_TOKEN')], 403);
        }

        $id   = $this->input->post->getInt('id');
        $json = (string) $this->input->post->getRaw('json', '');

        if ($id > 0 && !$this->allowEditQuiet($id)) {
            $this->json(['error' => Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED')], 403);
        }

        if (\strlen($json) > Drafts::MAX_BYTES) {
            $this->json(['error' => Text::_('COM_SQUIDFORM_DRAFT_TOO_BIG')], 413);
        }

        $live  = $this->liveVersionNo($id);
        $saved = false;
        $at    = '';

        if ($id > 0) {
            $existing = Drafts::get($id);
            $liveJson = $this->liveJson($id);

            // Back to exactly the live version: no draft needed.
            if ($liveJson !== '' && self::sameJson($json, $liveJson)) {
                if ($existing) {
                    Drafts::delete($id);
                }
            } elseif ($existing && $existing->schema_json === $json) {
                // Nothing new since the last save.
                $saved = true;
                $at    = HTMLHelper::_('date', $existing->modified, Text::_('DATE_FORMAT_LC2'));
            } else {
                $base = $existing ? (int) $existing->base_version_no : $live;
                Drafts::put($id, $json, $base, (int) $this->app->getIdentity()->id);
                $saved = true;
                $at    = HTMLHelper::_('date', 'now', Text::_('DATE_FORMAT_LC2'));
            }
        }

        $check = DefinitionCheck::run($json);

        $this->json(['saved' => $saved, 'at' => $at, 'live_version' => $live, 'problems' => $check['problems']]);
    }

    /** Throw the draft away; the live version is untouched. */
    public function discardDraft(): void
    {
        $this->checkToken();
        // The edit form carries the id in its address (and as jform[id]).
        $id = $this->input->getInt('id') ?: (int) ($this->input->post->get('jform', [], 'array')['id'] ?? 0);

        if ($id > 0 && $this->allowEditQuiet($id)) {
            Drafts::delete($id);
            $this->app->setUserState('com_squidform.edit.form.data', null);
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_DRAFT_DISCARDED'));
        }

        $this->setRedirect(Route::_('index.php?option=com_squidform&view=form&layout=edit&id=' . $id, false));
    }

    /** 0.15.0: permanently remove this form's test submissions. */
    public function cleartests(): void
    {
        $this->checkToken();
        $id   = $this->input->getInt('id', 0);
        $user = $this->app->getIdentity();

        if (!$id || !\SquidForm\Component\SquidForm\Administrator\Engine\Acl::can('squidform.delete.hard', $id, $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $n = \SquidForm\Component\SquidForm\Administrator\Engine\TestMode::clear($id, $user);
        $this->setRedirect(\Joomla\CMS\Router\Route::_('index.php?option=com_squidform&task=form.edit&id=' . $id, false),
            \Joomla\CMS\Language\Text::plural('COM_SQUIDFORM_TEST_CLEARED', $n));
    }

    /**
     * Preview (0.7.0): the builder posts what's on screen; it's kept in the
     * session and shown by the preview view, so nothing is published or saved.
     */
    public function preview(): void
    {
        $this->checkToken();
        $id = $this->input->post->getInt('id');

        if (!($id > 0 ? $this->allowEditQuiet($id) : $this->app->getIdentity()->authorise('core.create', 'com_squidform'))) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $json = (string) $this->input->post->getRaw('json', '');

        $this->app->setUserState('com_squidform.preview', [
            'json'  => \strlen($json) <= Drafts::MAX_BYTES ? $json : '',
            'title' => mb_substr($this->input->post->getString('title', ''), 0, 255),
            'alias' => preg_replace('/[^a-z0-9-]/', '', strtolower($this->input->post->getString('alias', ''))) ?: 'preview',
            'id'    => $id,
        ]);

        $page = max(0, $this->input->post->getInt('page'));
        $as   = $this->input->post->getCmd('as') === 'guest' ? 'guest' : 'user';
        $this->setRedirect(Route::_('index.php?option=com_squidform&view=preview&tmpl=component&page=' . $page . '&as=' . $as, false));
    }

    /** Cascading options for the preview, from the posted (unpublished) definition. */
    public function previewOptions(): void
    {
        $state = (array) $this->app->getUserState('com_squidform.preview', []);
        $list  = [];

        try {
            $schema = \SquidForm\Component\SquidForm\Administrator\Engine\Schema::fromJson((string) ($state['json'] ?? ''));
            $field  = $schema->field($this->input->getCmd('field'));

            if ($field && \SquidForm\Component\SquidForm\Administrator\Engine\Options::parentOf($field) !== null) {
                $form = ['id' => (int) ($state['id'] ?? 0), 'title' => (string) ($state['title'] ?? ''), 'alias' => (string) ($state['alias'] ?? 'preview')];
                $ph   = new \SquidForm\Component\SquidForm\Administrator\Engine\Placeholders($schema, $this->app->getIdentity(), [], [], [], $form);
                $list = \SquidForm\Component\SquidForm\Administrator\Engine\Options::resolve($field, $this->input->getString('parent'), $ph);
            }
        } catch (\Throwable) {
            $list = [];
        }

        $this->json(['options' => $list]);
    }

    private function allowEditQuiet(int $id): bool
    {
        $user = $this->app->getIdentity();
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $row  = $db->setQuery('SELECT state, checked_out FROM ' . $db->quoteName('#__squidform_forms') . ' WHERE id = ' . $id)->loadObject();

        if (!$row || (int) $row->state === 2) {
            return false;
        }

        // Someone else has it open.
        if ((int) $row->checked_out > 0 && (int) $row->checked_out !== (int) $user->id) {
            return false;
        }

        return $user->authorise('core.edit', 'com_squidform.form.' . $id) || $user->authorise('core.edit', 'com_squidform');
    }

    private function liveVersionNo(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (int) $db->setQuery(
            'SELECT v.version_no FROM ' . $db->quoteName('#__squidform_forms', 'f')
            . ' JOIN ' . $db->quoteName('#__squidform_form_versions', 'v') . ' ON v.id = f.current_version_id WHERE f.id = ' . $id
        )->loadResult();
    }

    private function liveJson(int $id): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (string) $db->setQuery(
            'SELECT v.schema_json FROM ' . $db->quoteName('#__squidform_forms', 'f')
            . ' JOIN ' . $db->quoteName('#__squidform_form_versions', 'v') . ' ON v.id = f.current_version_id WHERE f.id = ' . $id
        )->loadResult();
    }

    /** Same definition, ignoring formatting. */
    private static function sameJson(string $a, string $b): bool
    {
        $x = json_decode($a, true);
        $y = json_decode($b, true);

        return $x !== null && $x === $y;
    }

    private function json(array $data, int $status = 200): never
    {
        $this->app->setHeader('status', $status, true);
        $this->app->mimeType = 'application/json';
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo json_encode($status === 200 ? $data : ['success' => false] + $data, JSON_UNESCAPED_UNICODE);
        $this->app->close();
    }
}
