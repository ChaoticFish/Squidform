<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Engine\Import\ChronoImport;

\defined('_JEXEC') or die;

/**
 * Import from ChronoForms (0.12.0): CF8 forms on this site, or a backup
 * file. Needs Create on SquidForm; bringing the data log too needs Edit
 * Submissions as well.
 */
class ChronoformsController extends BaseController
{
    private const VIEW = 'index.php?option=com_squidform&view=chronoforms';

    public function site(): void
    {
        $this->checkToken();
        $this->need('core.create');
        $ids     = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array'))));
        $withLog = (bool) $this->input->getInt('with_log', 0);

        if (!$ids) {
            $this->setRedirect(self::VIEW, Text::_('COM_SQUIDFORM_CF_TICK_ONE'), 'warning');

            return;
        }

        if ($withLog && !$this->app->getIdentity()->authorise('squidform.edit', 'com_squidform')) {
            $withLog = false;
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_CF_NO_LOG_PERMISSION'), 'warning');
        }

        $seven = $this->input->getInt('cf_version', 8) === 7;
        $this->finish(ChronoImport::run($seven ? ChronoImport::cf7FromSite($ids) : ChronoImport::cf8FromSite($ids), $seven ? 'cf7-site' : 'cf8-site', $withLog, $this->app->getIdentity()));
    }

    public function file(): void
    {
        $this->checkToken();
        $this->need('core.create');
        $file = $this->input->files->get('cf_file', [], 'raw');

        if (empty($file['tmp_name']) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 20971520) {
            $this->setRedirect(self::VIEW, Text::_('COM_SQUIDFORM_IMPORT_NO_FILE'), 'warning');

            return;
        }

        try {
            $parsed = ChronoImport::parseFile((string) file_get_contents($file['tmp_name']));
        } catch (\Throwable $e) {
            $this->setRedirect(self::VIEW, htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');

            return;
        }

        $this->finish(ChronoImport::run($parsed['forms'], $parsed['version'] === 'cf7' ? 'cf7-file' : 'cf8-file', false, $this->app->getIdentity()));
    }

    /** @param list<array<string, mixed>> $lines */
    private function finish(array $lines): void
    {
        // The save of each form says "version 1 is now live"; the report says it better.
        $this->app->getMessageQueue(true);
        $this->app->setUserState('com_squidform.chronoimport', $lines);
        $this->setRedirect(self::VIEW . '&layout=report');
    }

    private function need(string $action): void
    {
        if (!$this->app->getIdentity()->authorise($action, 'com_squidform')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
