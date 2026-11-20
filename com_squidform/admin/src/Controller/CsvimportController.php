<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Import\CsvImport;
use SquidForm\Component\SquidForm\Administrator\Engine\Import\SubmissionLoader;

\defined('_JEXEC') or die;

/**
 * Import submissions (CSV) (0.12.0): choose → match columns → check → import.
 * Needs Edit Submissions on the form the answers go into.
 */
class CsvimportController extends BaseController
{
    private const VIEW  = 'index.php?option=com_squidform&view=csvimport';
    private const STATE = 'com_squidform.csvimport';

    public function upload(): void
    {
        $this->checkToken();
        $formId = $this->input->getInt('form_id', 0);
        $file   = $this->input->files->get('csv_file', [], 'raw');

        if (!Acl::can('squidform.edit', $formId)) {
            $this->setRedirect(self::VIEW, Text::_('COM_SQUIDFORM_CSV_PICK_FORM'), 'warning');

            return;
        }

        if (empty($file['tmp_name']) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > CsvImport::MAX_BYTES) {
            $this->setRedirect(self::VIEW, Text::_('COM_SQUIDFORM_CSV_NO_FILE'), 'warning');

            return;
        }

        $old = (array) $this->app->getUserState(self::STATE, []);
        CsvImport::forget($old['file'] ?? null);

        try {
            $name   = CsvImport::keep($file['tmp_name']);
            $csv    = CsvImport::read((string) CsvImport::path($name));
            $schema = FormRepository::load($formId, $this->app->getIdentity(), false)['schema'] ?? null;

            if (!$schema) {
                throw new \RuntimeException(Text::_('COM_SQUIDFORM_CSV_PICK_FORM'));
            }
        } catch (\Throwable $e) {
            CsvImport::forget($name ?? null);
            $this->setRedirect(self::VIEW, htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');

            return;
        }

        $this->app->setUserState(self::STATE, [
            'form' => $formId, 'file' => $name, 'filename' => mb_substr((string) ($file['name'] ?? ''), 0, 120), 'head' => $csv['head'],
            'count' => \count($csv['rows']), 'sample' => $csv['rows'][0] ?? [], 'match' => CsvImport::guess($csv['head'], $schema, $formId), 'result' => null,
        ]);
        $this->setRedirect(self::VIEW . '&layout=map');
    }

    /** Check (a dry run) or import. */
    public function run(): void
    {
        $this->checkToken();
        $st = (array) $this->app->getUserState(self::STATE, []);

        if (empty($st['file']) || !($path = CsvImport::path((string) $st['file'])) || !Acl::can('squidform.edit', (int) ($st['form'] ?? 0))) {
            $this->setRedirect(self::VIEW, Text::_('COM_SQUIDFORM_CSV_START_AGAIN'), 'warning');

            return;
        }

        $loaded  = FormRepository::load((int) $st['form'], $this->app->getIdentity(), false);
        $allowed = array_merge(array_keys(CsvImport::targets($loaded['schema'])), [CsvImport::CREATED, CsvImport::USER, '']);
        $match   = [];

        foreach (array_keys((array) $st['head']) as $i) {
            $t       = (string) ($this->input->post->get('match', [], 'array')[$i] ?? '');
            $match[] = \in_array($t, $allowed, true) ? $t : '';
        }

        // Each field once.
        $seen = [];

        foreach ($match as $i => $t) {
            if ($t !== '' && isset($seen[$t])) {
                $match[$i] = '';
            }

            $seen[$t] = true;
        }

        $st['match'] = $match;
        $dry         = $this->input->post->getCmd('go') !== 'import';

        if (!array_filter($match)) {
            $st['result'] = null;
            $this->app->setUserState(self::STATE, $st);
            $this->setRedirect(self::VIEW . '&layout=map', Text::_('COM_SQUIDFORM_CSV_NOTHING_MATCHED'), 'warning');

            return;
        }

        $rows = CsvImport::rows(CsvImport::read($path)['rows'], $match);
        $res  = SubmissionLoader::load((int) $st['form'], $rows, $this->app->getIdentity(), $dry);

        if ($dry) {
            $st['result'] = $res;
            $this->app->setUserState(self::STATE, $st);
            $this->setRedirect(self::VIEW . '&layout=map');

            return;
        }

        CsvImport::forget((string) $st['file']);
        $this->app->setUserState(self::STATE, null);

        foreach (\array_slice($res['problems'], 0, 10) as $p) {
            $this->app->enqueueMessage(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), 'warning');
        }

        $this->setRedirect('index.php?option=com_squidform&view=submissions&filter[form_id]=' . (int) $st['form'], Text::plural('COM_SQUIDFORM_CSV_N_IMPORTED', $res['added']), 'success');
    }

    public function cancel(): void
    {
        $this->checkToken();
        $st = (array) $this->app->getUserState(self::STATE, []);
        CsvImport::forget($st['file'] ?? null);
        $this->app->setUserState(self::STATE, null);
        $this->setRedirect(self::VIEW);
    }
}
