<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\FormData;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;
use SquidForm\Component\SquidForm\Administrator\Engine\DefinitionCheck;
use SquidForm\Component\SquidForm\Administrator\Engine\Drafts;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;
use SquidForm\Component\SquidForm\Administrator\Engine\SchemaException;

\defined('_JEXEC') or die;

/**
 * Create and edit a form. In phase 1 the definition is edited as JSON (the
 * visual builder arrives in phase 3). Every save with a changed definition
 * publishes a new version; old versions are kept so stored submissions keep
 * their labels.
 */
class FormModel extends AdminModel
{
    public $typeAlias = 'com_squidform.form';

    protected $text_prefix = 'COM_SQUIDFORM_FORM';

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_squidform.form', 'form', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_squidform.edit.form.data', []);

        if (empty($data)) {
            $data = $this->getItem();

            // Unpublished changes win over the live version in the editor (0.7.0).
            if (\is_object($data) && ($draft = Drafts::get((int) ($data->id ?? 0)))) {
                $data->schema_json = $draft->schema_json;
            }
        }

        // New forms start from the bundled example so there is something to edit.
        if (\is_object($data) && empty($data->schema_json)) {
            $file              = JPATH_ADMINISTRATOR . '/components/com_squidform/examples/starter.json';
            $data->schema_json = is_file($file) ? (string) file_get_contents($file) : '';
        }

        $this->preprocessData('com_squidform.form', $data);

        return $data;
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && (int) $item->current_version_id > 0) {
            $db = $this->getDatabase();
            $vid = (int) $item->current_version_id;
            $q  = $db->createQuery()
                ->select(['schema_json', 'version_no'])
                ->from($db->quoteName('#__squidform_form_versions'))
                ->where('id = :id')
                ->bind(':id', $vid, ParameterType::INTEGER);
            $v = $db->setQuery($q)->loadObject();

            $item->schema_json = $v->schema_json ?? '';
            $item->version_no  = (int) ($v->version_no ?? 0);
        }

        return $item;
    }

    /**
     * Check the JSON definition before the row is saved.
     */
    public function validate($form, $data, $group = null)
    {
        // Only users who may configure the component can change a form's permissions.
        if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_squidform')) {
            unset($data['rules']);
        }

        $data = parent::validate($form, $data, $group);

        if ($data === false) {
            return false;
        }

        $json  = (string) ($data['schema_json'] ?? '');
        $check = DefinitionCheck::run($json);

        if ($check['problems']) {
            $first = null;
            $lines = [];

            foreach ($check['problems'] as $p) {
                $msg = $p['message'];

                // Syntax errors already say where; others get "(line N)".
                if ($p['line'] > 0 && !str_starts_with($msg, 'JSON syntax error')) {
                    $msg .= ' ' . Text::sprintf('COM_SQUIDFORM_ERR_AT_LINE', $p['line']);
                }

                $first ??= $p['line'] > 0 ? ['line' => $p['line'], 'column' => $p['column']] : null;
                $lines[] = htmlspecialchars(Text::sprintf('COM_SQUIDFORM_ERR_SCHEMA', $msg), ENT_QUOTES, 'UTF-8');
            }

            // One message: Joomla shows only the first three errors a model sets.
            $this->setError(implode('<br>', $lines));

            $this->rememberErrorAt($first);

            return false;
        }

        $this->rememberErrorAt(null);

        return $data;
    }

    /** Where the first definition problem is, for the edit screen to jump to (null clears it). */
    private function rememberErrorAt(?array $at): void
    {
        Factory::getApplication()->setUserState('com_squidform.edit.form.error_at', $at);
    }

    public function save($data)
    {
        $json = (string) ($data['schema_json'] ?? '');
        $note = trim((string) ($data['version_note'] ?? ''));
        unset($data['schema_json'], $data['version_note'], $data['version_no']);

        $schema = Schema::fromJson($json); // already validated
        $db     = $this->getDatabase();

        // E-signatures (0.11.0): adding, changing or removing one needs "Use e-signatures".
        $formId = (int) ($data['id'] ?? 0);
        $before = '';

        if ($formId > 0) {
            $was    = $db->setQuery(
                $db->createQuery()->select('v.schema_json')->from($db->quoteName('#__squidform_forms', 'f'))
                    ->join('INNER', $db->quoteName('#__squidform_form_versions', 'v'), 'v.id = f.current_version_id')
                    ->where('f.id = :id')->bind(':id', $formId, ParameterType::INTEGER)
            )->loadResult();
            $before = \SquidForm\Component\SquidForm\Administrator\Engine\ESign::settingsPrint($was ? json_decode((string) $was, true) : null);
        }

        if (\SquidForm\Component\SquidForm\Administrator\Engine\ESign::settingsPrint($schema->toArray()) !== $before
            && !\SquidForm\Component\SquidForm\Administrator\Engine\ESign::mayChange(Factory::getApplication()->getInput()->get('task') === 'save2copy' ? 0 : $formId)) {
            $this->setError(Text::_('COM_SQUIDFORM_ESIGN_ERR_PERMISSION'));

            return false;
        }

        // New forms (including Save as Copy) get a free alias: name, name-2, name-3 ...
        if (empty($data['id'])) {
            $base  = \Joomla\CMS\Filter\OutputFilter::stringURLSafe(($data['alias'] ?? '') ?: ($data['title'] ?? 'form')) ?: 'form';
            $base  = ctype_digit($base) ? 'form-' . $base : $base;
            $alias = $base;

            for ($n = 2; $this->aliasExists($alias); $n++) {
                $alias = $base . '-' . $n;
            }

            $data['alias'] = $alias;

            if (Factory::getApplication()->getInput()->get('task') === 'save2copy') {
                $data['state'] = 0;
            }
        }

        $db->transactionStart();

        try {
            if (!parent::save($data)) {
                $db->transactionRollback();

                return false;
            }

            $id = (int) $this->getState($this->getName() . '.id');

            // Store pretty-printed, normalised JSON so diffs stay readable.
            $normalised = $schema->toJson();

            $q = $db->createQuery()
                ->select(['v.id', 'v.version_no', 'v.schema_json'])
                ->from($db->quoteName('#__squidform_forms', 'f'))
                ->join('LEFT', $db->quoteName('#__squidform_form_versions', 'v'), 'v.id = f.current_version_id')
                ->where('f.id = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $current = $db->setQuery($q)->loadObject();

            if (!$current || $current->schema_json !== $normalised) {
                $max = $db->createQuery()
                    ->select('COALESCE(MAX(version_no), 0)')
                    ->from($db->quoteName('#__squidform_form_versions'))
                    ->where('form_id = :fid')
                    ->bind(':fid', $id, ParameterType::INTEGER);
                $next = (int) $db->setQuery($max)->loadResult() + 1;

                $version = (object) [
                    'form_id'     => $id,
                    'version_no'  => $next,
                    'schema_json' => $normalised,
                    'note'        => mb_substr($note, 0, 255),
                    'created'     => Factory::getDate()->toSql(),
                    'created_by'  => (int) Factory::getApplication()->getIdentity()->id,
                ];
                $db->insertObject('#__squidform_form_versions', $version, 'id');

                $u = $db->createQuery()
                    ->update($db->quoteName('#__squidform_forms'))
                    ->set('current_version_id = :vid')
                    ->where('id = :id')
                    ->bind(':vid', $version->id, ParameterType::INTEGER)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $db->setQuery($u)->execute();

                Factory::getApplication()->enqueueMessage(Text::sprintf('COM_SQUIDFORM_VERSION_PUBLISHED', $next));
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            $this->setError($e->getMessage());

            return false;
        }

        // Published: the draft has done its job.
        Drafts::delete($id);

        // The form's own data tables (0.4.0): create them, or add columns for
        // new fields. Runs after the commit because CREATE/ALTER would commit.
        try {
            $alias = (string) $db->setQuery(
                $db->createQuery()->select('alias')->from($db->quoteName('#__squidform_forms'))->where('id = ' . (int) $id)
            )->loadResult();
            $fd = FormData::forForm($id);
            $fd->ensure($schema, $alias);
            $fd->markRetired($schema);
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('COM_SQUIDFORM_ERR_DATA_TABLE', $e->getMessage()), 'warning');
        }

        return true;
    }

    /**
     * Publish, unpublish and archive. Archiving (state 2) renames the form's
     * data tables sfd_ → sfa_; leaving the archive renames them back. The
     * form and every answer are always kept.
     */
    public function publish(&$pks, $value = 1)
    {
        $db     = $this->getDatabase();
        $pks    = array_map('intval', (array) $pks);
        $before = $pks ? $db->setQuery(
            $db->createQuery()->select(['id', 'state'])->from($db->quoteName('#__squidform_forms'))->whereIn($db->quoteName('id'), $pks)
        )->loadAssocList('id', 'state') : [];

        if ((int) $value === -2) {
            $value = 2; // there is no trash: "remove" means archive
        }

        if (!parent::publish($pks, $value)) {
            return false;
        }

        foreach ($pks as $id) {
            $was = (int) ($before[$id] ?? 0);

            try {
                if ((int) $value === 2 && $was !== 2) {
                    FormData::forForm($id)->rename(true);
                    \SquidForm\Component\SquidForm\Administrator\Engine\Audit::log(null, $id, (int) Factory::getApplication()->getIdentity()->id, 'archive_form');
                } elseif ((int) $value !== 2 && $was === 2) {
                    FormData::forForm($id)->rename(false);
                    \SquidForm\Component\SquidForm\Administrator\Engine\Audit::log(null, $id, (int) Factory::getApplication()->getIdentity()->id, 'restore_form');
                }
            } catch (\Throwable $e) {
                Factory::getApplication()->enqueueMessage(Text::sprintf('COM_SQUIDFORM_ERR_DATA_TABLE', $e->getMessage()), 'warning');
            }
        }

        return true;
    }

    private function aliasExists(string $alias): bool
    {
        $db = $this->getDatabase();
        $q  = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__squidform_forms'))
            ->where('alias = :a')
            ->bind(':a', $alias);

        return (int) $db->setQuery($q)->loadResult() > 0;
    }

    /**
     * A new row (including a copy) starts without a version; save() adds one.
     */
    protected function prepareTable($table)
    {
        if (empty($table->id)) {
            $table->current_version_id = 0;
        }
    }
}
