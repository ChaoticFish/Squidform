<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

/** @var \SquidForm\Component\SquidForm\Site\View\Submission\HtmlView $this */

$item = $this->item;
$opt  = Viewer::url('task=submission.options&format=json&uid=' . $item->uid, false);
?>
<div class="com-squidform sf-viewer squidform sf-edit" id="sf-edit">
	<h1><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_VIEWER_EDIT_N', \SquidForm\Component\SquidForm\Administrator\Engine\Ref::short($item->uid))); ?></h1>
	<p class="text-body-secondary"><?php echo Text::sprintf('COM_SQUIDFORM_EDIT_HELP', (int) $item->version_no); ?></p>
	<?php if ($sfNote = \SquidForm\Component\SquidForm\Administrator\Engine\Approvals::editNotice($item)) : ?>
		<div class="alert alert-info"><?php echo $this->escape($sfNote); ?></div>
	<?php endif; ?>
	<?php echo $this->errorHtml; ?>
	<form action="<?php echo Viewer::url('view=submission&layout=change&uid=' . $item->uid); ?>" method="post" class="sf-form" novalidate
		data-sf-alias="<?php echo $this->escape($item->form_alias); ?>" data-sf-options-url="<?php echo $this->escape($opt); ?>" data-sf-known="{}"
		aria-label="<?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_VIEWER_EDIT_N', \SquidForm\Component\SquidForm\Administrator\Engine\Ref::short($item->uid))); ?>">
		<?php echo $this->editHtml; ?>
		<input type="hidden" name="uid" value="<?php echo $this->escape($item->uid); ?>">
		<input type="hidden" name="sf_seen" value="<?php echo $this->escape($this->seen); ?>">
		<input type="hidden" name="task" value="submission.save">
		<?php echo HTMLHelper::_('form.token'); ?>
		<div class="sf-actions d-flex gap-2">
			<button type="submit" class="btn btn-primary"><?php echo Text::_($item->status === 'returned' && \SquidForm\Component\SquidForm\Administrator\Engine\Acl::owns($item) ? 'COM_SQUIDFORM_APPROVAL_SAVE_RESUBMIT' : 'COM_SQUIDFORM_VIEWER_SAVE'); ?></button>
			<button type="submit" class="btn btn-secondary" name="task" value="submission.cancel" formnovalidate><?php echo Text::_('JCANCEL'); ?></button>
		</div>
	</form>
</div>
