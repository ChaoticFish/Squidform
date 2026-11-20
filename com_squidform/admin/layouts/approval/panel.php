<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Approver actions. $displayData: item, flow, action (form URL), return (URL
 * to come back to), canAct, canReassign, reassignTo (id => label), idp,
 * behalf (name of the person covered, or '')
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$d      = $displayData;
$item   = $d['item'];
$flow   = $d['flow'];
$p      = $d['idp'];
$e      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$hidden = '<input type="hidden" name="uid" value="' . $e($item->uid) . '">'
	. '<input type="hidden" name="sf_step" value="' . $e($item->current_step) . '">'
	. '<input type="hidden" name="sf_round" value="' . (int) $item->approval_round . '">'
	. '<input type="hidden" name="sf_return" value="' . $e(base64_encode($d['return'])) . '">'
	. HTMLHelper::_('form.token');
?>
<?php if ($d['canAct']) : ?>
	<form action="<?php echo $e($d['action']); ?>" method="post" class="sf-approve-form mb-3" id="<?php echo $p; ?>-decide">
		<?php if ($d['behalf'] !== '') : ?>
			<p class="small"><?php echo $e(Text::sprintf('COM_SQUIDFORM_APPROVAL_ACTING_FOR', $d['behalf'])); ?></p>
		<?php endif; ?>
		<div class="mb-2">
			<label class="form-label" for="<?php echo $p; ?>-comment"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_COMMENT'); ?></label>
			<textarea class="form-control" id="<?php echo $p; ?>-comment" name="sf_comment" rows="3" maxlength="4000" aria-describedby="<?php echo $p; ?>-comment-help"></textarea>
			<div class="form-text" id="<?php echo $p; ?>-comment-help"><?php echo Text::_($flow['allow_return'] ? 'COM_SQUIDFORM_APPROVAL_COMMENT_HELP' : 'COM_SQUIDFORM_APPROVAL_COMMENT_HELP_NORETURN'); ?></div>
		</div>
		<div class="d-flex flex-wrap gap-2">
			<button type="submit" class="btn btn-success" name="task" value="approval.approve"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_APPROVE'); ?></button>
			<?php if ($flow['allow_return']) : ?>
				<button type="submit" class="btn btn-outline-secondary" name="task" value="approval.return"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_RETURN'); ?></button>
			<?php endif; ?>
			<button type="submit" class="btn btn-outline-danger" name="task" value="approval.reject" data-sf-confirm="<?php echo $e(Text::_('COM_SQUIDFORM_APPROVAL_REJECT_CONFIRM')); ?>"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_REJECT'); ?></button>
		</div>
		<?php echo $hidden; ?>
	</form>
<?php endif; ?>
<?php if ($d['canReassign'] && $d['reassignTo']) : ?>
	<form action="<?php echo $e($d['action']); ?>" method="post" class="sf-reassign-form" id="<?php echo $p; ?>-reassign">
		<fieldset>
			<legend class="h6"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_REASSIGN'); ?></legend>
			<div class="mb-2">
				<label class="form-label" for="<?php echo $p; ?>-target"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_REASSIGN_TO'); ?></label>
				<select class="form-select" id="<?php echo $p; ?>-target" name="sf_target" required>
					<option value=""><?php echo Text::_('COM_SQUIDFORM_SELECT'); ?></option>
					<?php foreach ($d['reassignTo'] as $uid => $label) : ?>
						<option value="<?php echo (int) $uid; ?>"><?php echo $e($label); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mb-2">
				<label class="form-label" for="<?php echo $p; ?>-rcomment"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_REASSIGN_NOTE'); ?></label>
				<input type="text" class="form-control" id="<?php echo $p; ?>-rcomment" name="sf_comment" maxlength="500">
			</div>
			<button type="submit" class="btn btn-outline-primary" name="task" value="approval.reassign"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_REASSIGN_BUTTON'); ?></button>
		</fieldset>
		<?php echo $hidden; ?>
	</form>
<?php endif; ?>
