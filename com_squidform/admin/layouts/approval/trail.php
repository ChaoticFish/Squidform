<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Approval trail with comments. $displayData: trail (Approvals::trail), flow
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;

$flow  = $displayData['flow'];
$e     = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$title = static fn ($k) => (string) (ApprovalFlow::step($flow, (string) $k)['title'] ?? $k);
$rows  = $displayData['trail'];
?>
<?php if (!$rows) : ?>
	<p class="small mb-0"><?php echo Text::_('COM_SQUIDFORM_TRAIL_EMPTY'); ?></p>
<?php else : ?>
	<ol class="sf-trail list-unstyled small mb-0">
		<?php foreach ($rows as $r) : ?>
			<li class="sf-trail-<?php echo $e($r->decision); ?>">
				<span class="sf-trail-when"><?php echo HTMLHelper::_('date', $r->created, Text::_('DATE_FORMAT_LC5')); ?></span>
				<span class="sf-trail-what">
					<?php
					$who  = (int) $r->user_id ? $e($r->user_name ?: '#' . (int) $r->user_id) : '';
					$step = $r->step_key !== '' ? $e($title($r->step_key)) : '';
					$text = match ($r->decision) {
						'start'    => Text::sprintf('COM_SQUIDFORM_TRAIL_START', $who ?: Text::_('COM_SQUIDFORM_GUEST')),
						'skip'     => Text::sprintf('COM_SQUIDFORM_TRAIL_SKIP', $step),
						'reassign' => Text::sprintf('COM_SQUIDFORM_TRAIL_REASSIGN', $who, $step, $e($r->assignee_name ?: '#' . (int) $r->assignee_id)),
						'restart'  => Text::sprintf('COM_SQUIDFORM_TRAIL_RESTART', $who),
						'resubmit' => Text::sprintf('COM_SQUIDFORM_TRAIL_RESUBMIT', $who),
						'withdraw' => Text::sprintf('COM_SQUIDFORM_TRAIL_WITHDRAW', $who),
						default    => Text::sprintf('COM_SQUIDFORM_TRAIL_' . strtoupper($r->decision), $who, $step),
					};
					echo $text;

					if ((int) $r->on_behalf_of) {
						echo ' ' . $e(Text::sprintf('COM_SQUIDFORM_APPROVAL_FOR', $r->behalf_name ?: '#' . (int) $r->on_behalf_of));
					}
					?>
				</span>
				<?php if ($r->comment !== null && $r->comment !== '') : ?>
					<blockquote class="sf-trail-comment mb-0"><?php echo nl2br($e($r->comment)); ?></blockquote>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
<?php endif; ?>
