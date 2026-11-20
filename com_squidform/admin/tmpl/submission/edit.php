<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Submission\HtmlView $this */

$item = $this->item;
$opt  = Route::_('index.php?option=com_squidform&task=submission.options&format=json&id=' . (int) $item->id, false);
?>
<div class="row squidform sf-edit">
	<div class="col-lg-8">
		<?php echo $this->errorHtml; ?>
		<form action="<?php echo Route::_('index.php?option=com_squidform&view=submission&layout=edit&id=' . (int) $item->id); ?>" method="post" name="adminForm" id="adminForm"
			class="sf-form card card-body mb-3" novalidate data-sf-alias="<?php echo $this->escape($item->form_alias); ?>" data-sf-options-url="<?php echo $this->escape($opt); ?>" data-sf-known="{}"
			aria-label="<?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_SUBMISSION_EDIT_TITLE', (int) $item->id)); ?>">
			<h2 class="h4"><?php echo $this->escape($item->form_title); ?></h2>
			<p class="small text-body-secondary"><?php echo Text::sprintf('COM_SQUIDFORM_EDIT_HELP', (int) $item->version_no); ?></p>
			<?php if ($sfNote = \SquidForm\Component\SquidForm\Administrator\Engine\Approvals::editNotice($item)) : ?>
		<div class="alert alert-info"><?php echo $this->escape($sfNote); ?></div>
	<?php endif; ?>
			<?php echo $this->editHtml; ?>
			<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
			<input type="hidden" name="sf_seen" value="<?php echo $this->escape($this->seen); ?>">
			<input type="hidden" name="task" value="">
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	</div>
	<div class="col-lg-4">
		<div class="card card-body">
			<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_DETAILS'); ?></h2>
			<?php echo $this->layout('details', ['item' => $item, 'show_ip' => true]); ?>
		</div>
	</div>
</div>
