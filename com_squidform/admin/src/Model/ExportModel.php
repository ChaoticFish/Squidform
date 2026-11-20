<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Exporter;
use SquidForm\Component\SquidForm\Administrator\Engine\ExportService;

\defined('_JEXEC') or die;

/**
 * Data for the export screen and the download: which submissions are in
 * scope, which columns exist, and the rows themselves (read in chunks).
 */
class ExportModel extends BaseDatabaseModel
{
    /** User-state keys. */
    public const STATE_IDS   = 'com_squidform.export.ids';

    /** @return int[]  submission ids ticked on the list when Export was pressed */
    public function getTicked(): array
    {
        return array_values(array_filter(array_map('intval', (array) Factory::getApplication()->getUserState(self::STATE_IDS, []))));
    }

    /**
     * Forms the ticked submissions belong to.
     *
     * @return int[]
     */
    public function getTickedForms(): array
    {
        $ids = $this->getTicked();

        if (!$ids) {
            return [];
        }

        $db = $this->getDatabase();
        $q  = $db->createQuery()
            ->select('DISTINCT form_id')
            ->from($db->quoteName('#__squidform_submissions'))
            ->whereIn($db->quoteName('id'), $ids);

        return array_map('intval', $db->setQuery($q)->loadColumn());
    }

    /** @return int[]  ticked submissions that belong to this form */
    public function getSelectedIds(int $formId): array
    {
        $ids = $this->getTicked();

        if (!$ids || !$formId) {
            return [];
        }

        $db = $this->getDatabase();
        $q  = $db->createQuery()
            ->select('id')
            ->from($db->quoteName('#__squidform_submissions'))
            ->whereIn($db->quoteName('id'), $ids)
            ->where('form_id = :f')
            ->order('id')
            ->bind(':f', $formId, ParameterType::INTEGER);

        return array_map('intval', $db->setQuery($q)->loadColumn());
    }

    /**
     * Submissions of this form that match the Submissions list's current
     * search, status and archived filters (the form filter is replaced).
     *
     * @return int[]
     */
    public function getFilteredIds(int $formId): array
    {
        if (!$formId) {
            return [];
        }

        /** @var SubmissionsModel $list */
        $list = $this->getMVCFactory()->createModel('Submissions', 'Administrator', ['ignore_request' => false]);

        return $list->getIdsForExport($formId);
    }

    /** Current list filter for the form, if one is set. */
    public function getListFormFilter(): int
    {
        $f = Factory::getApplication()->getUserState('com_squidform.submissions.filter', []);

        return (int) (\is_array($f) ? ($f['form_id'] ?? 0) : ($f->form_id ?? 0));
    }

    /** @return object|null  id, title, alias */
    public function getFormRow(int $formId): ?object
    {
        if (!$formId || !Acl::can('squidform.export', $formId)) {
            return null;
        }

        $db = $this->getDatabase();
        $q  = $db->createQuery()
            ->select(['id', 'title', 'alias'])
            ->from($db->quoteName('#__squidform_forms'))
            ->where('id = :id')
            ->bind(':id', $formId, ParameterType::INTEGER);

        return $db->setQuery($q)->loadObject() ?: null;
    }

    /** @return array<int, object>  id, title of every form */
    public function getForms(): array
    {
        $db      = $this->getDatabase();
        $allowed = array_flip(Acl::forms('squidform.export'));
        $rows    = $db->setQuery(
            $db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__squidform_forms'))->order('title')
        )->loadObjectList();

        return array_values(array_filter($rows, static fn ($r) => isset($allowed[(int) $r->id])));
    }

    /** @return array<int, array> */
    public function getFieldColumns(int $formId): array
    {
        return $formId ? Exporter::fieldColumns($formId) : [];
    }

    /** @return string[]|null  last picks for this form in this session */
    public function getPicks(int $formId): ?array
    {
        return ExportService::picks($formId);
    }
}
