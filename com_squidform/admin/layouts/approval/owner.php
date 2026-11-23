<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * The submitter's actions. $displayData: item, action, return, canWithdraw,
 * canResubmit, idp
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$d    = $displayData;
$item = $d['item'];
$e    = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

if (!$d['canWithdraw'] && !$d['canResubmit']) {
	return;
}
?>
<form action="<?php echo $e($d['action']); ?>" method="post" class="d-flex flex-wrap gap-2 mb-3" id="<?php echo $d['idp']; ?>-owner">
	<?php if ($d['canResubmit']) : ?>
		<button type="submit" class="btn btn-primary" name="task" value="approval.resubmit"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_RESUBMIT'); ?></button>
	<?php endif; ?>
	<?php if ($d['canWithdraw']) : ?>
		<button type="submit" class="btn btn-danger" name="task" value="approval.withdraw" data-sf-confirm="<?php echo $e(Text::_('COM_SQUIDFORM_APPROVAL_WITHDRAW_CONFIRM')); ?>"><?php echo Text::_('COM_SQUIDFORM_APPROVAL_WITHDRAW'); ?></button>
	<?php endif; ?>
	<input type="hidden" name="uid" value="<?php echo $e($item->uid); ?>">
	<input type="hidden" name="sf_return" value="<?php echo $e(base64_encode($d['return'])); ?>">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
