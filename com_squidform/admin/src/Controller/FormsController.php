<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Session\Session;
use SquidForm\Component\SquidForm\Administrator\Engine\FormTransfer;

\defined('_JEXEC') or die;

/**
 * List actions for forms: publish, unpublish, archive, check-in; and
 * (0.9.1) duplicate, export and import.
 */
class FormsController extends AdminController
{
    protected $text_prefix = 'COM_SQUIDFORM_FORMS';

    public function getModel($name = 'Form', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /** Copies of the ticked forms, unpublished. */
    public function duplicate(): void
    {
        $this->checkToken();
        $this->need('core.create');
        $this->report(FormTransfer::duplicate($this->ticked()), 'COM_SQUIDFORM_DUPLICATED_N');
        $this->setRedirect('index.php?option=com_squidform&view=forms');
    }

    /** The ticked forms as one JSON file. */
    public function export(): void
    {
        $this->checkToken();
        $this->need('core.edit');
        $ids = $this->ticked();

        if (!$ids) {
            $this->setRedirect('index.php?option=com_squidform&view=forms', Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return;
        }

        $data = FormTransfer::export($ids);
        $name = \count($data['forms']) === 1 ? $data['forms'][0]['alias'] : \count($data['forms']) . '-forms';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="squidform-' . preg_replace('/[^a-z0-9-]+/', '-', $name) . '-' . gmdate('Ymd') . '.json"', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->sendHeaders();
        echo $json;
        $this->app->close();
    }

    /** Forms from an uploaded export file. */
    public function import(): void
    {
        $this->checkToken();
        $this->need('core.create');
        $file = $this->input->files->get('sf_import', [], 'raw');
        $back = 'index.php?option=com_squidform&view=forms';

        if (empty($file['tmp_name']) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->setRedirect($back, Text::_('COM_SQUIDFORM_IMPORT_NO_FILE'), 'warning');

            return;
        }

        if ((int) $file['size'] > 10485760) {
            $this->setRedirect($back, Text::_('COM_SQUIDFORM_IMPORT_TOO_BIG'), 'warning');

            return;
        }

        try {
            $this->report(FormTransfer::import((string) file_get_contents($file['tmp_name'])), 'COM_SQUIDFORM_IMPORTED_N');
        } catch (\Throwable $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->setRedirect($back);
    }

    /** @return list<int> */
    private function ticked(): array
    {
        return array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array'))));
    }

    private function need(string $action): void
    {
        if (!$this->app->getIdentity()->authorise($action, 'com_squidform')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /** One message for the batch: what was made (with its alias), what wasn't and why, and what's missing. */
    private function report(array $lines, string $countKey): void
    {
        // The save of each copy says "version 1 is now live"; one summary is enough.
        $this->app->getMessageQueue(true);

        $made = array_filter($lines, static fn ($l) => $l['ok']);
        $esc  = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        if ($made) {
            $items = array_map(static fn ($l) => '<li>' . $esc($l['title']) . ' (<code class="text-reset">' . $esc($l['alias']) . '</code>)'
                . (!empty($l['warnings']) ? '<ul>' . implode('', array_map(static fn ($w) => '<li>' . $esc($w) . '</li>', $l['warnings'])) . '</ul>' : '') . '</li>', $made);
            $this->app->enqueueMessage(Text::plural($countKey, \count($made)) . ' ' . Text::_('COM_SQUIDFORM_COPIES_UNPUBLISHED') . '<ul>' . implode('', $items) . '</ul>', 'success');
        }

        foreach (array_filter($lines, static fn ($l) => !$l['ok']) as $l) {
            $this->app->enqueueMessage(Text::sprintf('COM_SQUIDFORM_COPY_FAILED', $esc($l['title']), $esc($l['message'])), 'error');
        }

        if (!$lines) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');
        }
    }
}
