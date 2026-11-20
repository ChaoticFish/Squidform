<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\Users;

\defined('_JEXEC') or die;

/**
 * The approval part of a submission's detail page (admin, front-end viewer
 * and the approver's page): step tracker, the approver's buttons, the
 * submitter's Withdraw / Resubmit, and the trail with comments.
 */
final class ApprovalSection
{
    /**
     * @param  string  $action  URL the forms post to (task=approval.*)
     * @param  string  $return  where to come back to after an action
     *
     * @return string  HTML, or '' when the submission is not under approvals
     */
    public static function render(object $item, string $action, string $return, string $idp = 'sfa', string $heading = 'h2'): string
    {
        $flow = Approvals::flow($item);

        if (!$flow || !Approvals::applies($item)) {
            return '';
        }

        $user     = Factory::getApplication()->getIdentity();
        $acting   = Approvals::acting($item, $user);
        $canRe    = Approvals::canReassign($item, $user);
        $eligible = Approvals::eligible($item);
        $choices  = [];

        if ($canRe) {
            $exclude = array_filter([(int) $user->id, $flow['allow_self_approval'] ? 0 : (int) $item->user_id]);
            $people  = array_values(array_filter(
                Users::withPermission('squidform.approve', (int) $item->form_id),
                static fn ($u) => !\in_array($u->id, $exclude, true)
            ));
            $choices = Users::labels($people);
        }

        $base = JPATH_ADMINISTRATOR . '/components/com_squidform/layouts';
        $data = ['item' => $item, 'flow' => $flow, 'action' => $action, 'return' => $return, 'idp' => $idp];
        $h    = preg_match('/^h[2-6]$/', $heading) ? $heading : 'h2';
        $sub  = 'h' . min(6, (int) substr($h, 1) + 1);

        $html = '<section class="card card-body mb-3 sf-approval" aria-labelledby="' . $idp . '-h">'
            . '<' . $h . ' class="h5" id="' . $idp . '-h">' . Text::_('COM_SQUIDFORM_APPROVAL') . '</' . $h . '>'
            . LayoutHelper::render('approval.tracker', ['item' => $item, 'steps' => Approvals::tracker($item), 'eligible' => $eligible, 'idp' => $idp], $base);

        $panel = LayoutHelper::render('approval.panel', $data + [
            'canAct'      => $acting !== null,
            'canReassign' => $canRe,
            'reassignTo'  => $choices,
            'behalf'      => $acting && $acting['behalf'] ? Users::name((int) $acting['behalf']) : '',
        ], $base);

        $owner = LayoutHelper::render('approval.owner', $data + [
            'canWithdraw' => Approvals::canWithdraw($item, $user),
            'canResubmit' => Approvals::canResubmit($item, $user),
        ], $base);

        if (trim($panel . $owner) !== '') {
            $html .= '<div class="sf-approval-actions mt-3">' . $owner . $panel . '</div>';
        }

        $html .= '<' . $sub . ' class="h6 mt-3">' . Text::_('COM_SQUIDFORM_TRAIL') . '</' . $sub . '>'
            . LayoutHelper::render('approval.trail', ['trail' => Approvals::trail((int) $item->id), 'flow' => $flow], $base)
            . '</section>';

        return $html;
    }
}
