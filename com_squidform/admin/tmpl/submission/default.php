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
use SquidForm\Component\SquidForm\Administrator\Helper\ApprovalSection;

$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('submit', (e) => {
  const b = e.submitter;
  if (b && b.dataset.sfConfirm && !window.confirm(b.dataset.sfConfirm)) e.preventDefault();
});
JS, ['name' => 'com_squidform.confirm']);

/** @var \SquidForm\Component\SquidForm\Administrator\View\Submission\HtmlView $this */

$item = $this->item;
?>
<?php if ($sfLock = \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::activeLock($item)) : ?>
	<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 sf-lock-note" role="status">
		<span><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_LOCKED_BY', $sfLock['by'], HTMLHelper::_('date', $sfLock['since'], Text::_('DATE_FORMAT_LC2')))); ?></span>
		<?php if (\SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::canCheckin($item)) : ?>
			<form action="<?php echo Route::_('index.php?option=com_squidform'); ?>" method="post" class="ms-auto">
				<button type="submit" class="btn btn-sm btn-outline-dark" name="task" value="submission.checkin" id="sf-checkin"><?php echo Text::_('COM_SQUIDFORM_CHECKIN'); ?></button>
				<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
				<?php echo HTMLHelper::_('form.token'); ?>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>
<?php echo $this->layout('output', ['pdf_url' => Route::_('index.php?option=com_squidform&task=submission.pdf&id=' . (int) $item->id)]); ?>
<div class="row">
	<div class="col-lg-8">
		<div class="card mb-3">
			<div class="card-body">
				<h2 class="h4"><?php echo $this->escape($item->form_title); ?></h2>
				<?php echo $this->layout('values', ['item' => $item]); ?>
			</div>
		</div>
	</div>

	<div class="col-lg-4">
		<?php echo $this->layout('esign', ['item' => $item, 'show_ip' => true, 'sign_url' => '']); ?>
		<?php echo ApprovalSection::render($item, Route::_('index.php?option=com_squidform'), 'index.php?option=com_squidform&view=submission&id=' . (int) $item->id, 'sfa'); ?>
		<div class="card mb-3">
			<div class="card-body">
				<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_DETAILS'); ?></h2>
				<?php echo $this->layout('details', ['item' => $item, 'show_ip' => true]); ?>
			</div>
		</div>
		<div class="card">
			<div class="card-body">
				<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_AUDIT'); ?></h2>
				<?php echo $this->layout('history', ['history' => $item->history, 'views' => $item->views, 'schema' => $item->schema]); ?>
			</div>
		</div>
	</div>
</div>
<?php // The toolbar's form: hidden fields only, so the approval forms above are not nested in it. ?>
<form action="<?php echo Route::_('index.php?option=com_squidform&view=submission&id=' . (int) $item->id); ?>" method="post" name="adminForm" id="adminForm">
	<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
	<input type="hidden" name="cid[]" value="<?php echo (int) $item->id; ?>">
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
