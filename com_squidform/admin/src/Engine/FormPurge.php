<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Permanent deletion of an archived form: drops its data tables and removes
 * its definition, versions, submissions and permission record. One line stays
 * in the shared log (who, when, alias, tables, how many rows; no answers).
 */
final class FormPurge
{
    /**
     * Archived forms among $ids that the user may permanently delete.
     *
     * @param   int[]  $ids
     *
     * @return  list<object>  id, title, alias
     */
    public static function eligible(array $ids, ?User $user = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $user ??= Factory::getApplication()->getIdentity();
        $db   = SubmissionStore::db();
        $rows = $db->setQuery(
            $db->createQuery()->select(['id', 'title', 'alias'])->from($db->quoteName('#__squidform_forms'))
                ->whereIn($db->quoteName('id'), $ids)->where('state = 2')->order('title')
        )->loadObjectList();

        return array_values(array_filter($rows, static fn ($r) => Acl::can('squidform.delete.hard', (int) $r->id, $user) && self::heldUntil((int) $r->id) === null));
    }

    /** A form with signed records still in their retention period (0.11.0) can't be deleted for good. */
    public static function heldUntil(int $formId): ?string
    {
        try {
            $db    = SubmissionStore::db();
            $today = Factory::getDate()->format('Y-m-d');
            $until = $db->setQuery(
                $db->createQuery()->select('MAX(retain_until)')->from($db->quoteName('#__squidform_signatures'))
                    ->where('form_id = :f')->where('retain_until > :t')->bind(':f', $formId, ParameterType::INTEGER)->bind(':t', $today)
            )->loadResult();

            return $until ? (string) $until : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{tables: string[], rows: int} */
    public static function run(int $formId, ?User $user = null): array
    {
        $user ??= Factory::getApplication()->getIdentity();
        $db   = SubmissionStore::db();
        $form = $db->setQuery(
            $db->createQuery()->select(['id', 'title', 'alias', 'asset_id', 'state'])->from($db->quoteName('#__squidform_forms'))
                ->where('id = :id')->bind(':id', $formId, ParameterType::INTEGER)
        )->loadObject();

        if (!$form || (int) $form->state !== 2) {
            throw new \RuntimeException('Only archived forms can be permanently deleted.');
        }

        if (($held = self::heldUntil($formId)) !== null) {
            throw new \RuntimeException('This form has signed records kept until ' . $held . '.');
        }

        // DROP TABLE commits on its own, so the tables go first; the rows after.
        $dropped = FormData::forForm($formId)->drop();
        FormData::forget($formId);

        $count = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_submissions'))->where('form_id = ' . $formId)
        )->loadResult();

        $ids = array_map('intval', $db->setQuery(
            $db->createQuery()->select('id')->from($db->quoteName('#__squidform_submissions'))->where('form_id = ' . $formId)
        )->loadColumn());

        Uploads::deleteForSubmissions($ids);
        Uploads::deleteForForm($formId);

        foreach (['#__squidform_approvals', '#__squidform_approval_queue', '#__squidform_submission_files'] as $t) {
            if ($ids) {
                $db->setQuery($db->createQuery()->delete($db->quoteName($t))->whereIn($db->quoteName('submission_id'), $ids))->execute();
            }
        }

        // Delegations for this form only (not "all forms" ones) go with it.
        foreach (['#__squidform_delegates' => 'form_id', '#__squidform_drafts' => 'form_id', '#__squidform_resume' => 'form_id', '#__squidform_signatures' => 'form_id', '#__squidform_esign_events' => 'form_id', '#__squidform_imports' => 'form_id', '#__squidform_counters' => 'form_id', '#__squidform_submissions' => 'form_id', '#__squidform_form_versions' => 'form_id', '#__squidform_forms' => 'id'] as $t => $col) {
            $db->setQuery($db->createQuery()->delete($db->quoteName($t))->where($db->quoteName($col) . ' = ' . $formId))->execute();
        }

        if ((int) $form->asset_id) {
            $asset = new Asset($db);

            if ($asset->load((int) $form->asset_id)) {
                $asset->delete();
            }
        }

        Audit::log(null, $formId, (int) $user->id, 'purge_form', [
            'alias'       => (string) $form->alias,
            'title'       => (string) $form->title,
            'tables'      => $dropped['tables'],
            'submissions' => $count,
        ]);

        return ['tables' => $dropped['tables'], 'rows' => $count];
    }
}
