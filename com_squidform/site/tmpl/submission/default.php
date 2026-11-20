<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use SquidForm\Component\SquidForm\Administrator\Helper\ApprovalSection;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

/** @var \SquidForm\Component\SquidForm\Site\View\Submission\HtmlView $this */

$item = $this->item;
$act  = Viewer::url('view=submission&uid=' . $item->uid);
$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('submit', (e) => {
  const b = e.submitter;
  if (b && b.dataset.sfConfirm && !window.confirm(b.dataset.sfConfirm)) e.preventDefault();
});
JS);
?>
<div class="com-squidform sf-viewer sf-viewer-detail">
	<p class="d-print-none"><a href="<?php echo Viewer::listUrl(); ?>">← <?php echo Text::_('COM_SQUIDFORM_VIEWER_BACK'); ?></a></p>
	<h1><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_VIEWER_SUBMISSION_N', \SquidForm\Component\SquidForm\Administrator\Engine\Ref::short($item->uid))); ?></h1>

	<?php if ($item->archived) : ?>
		<div class="alert alert-warning"><?php echo Text::_('COM_SQUIDFORM_VIEWER_IS_DELETED'); ?></div>
	<?php endif; ?>

	<?php if ($sfLock = \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::activeLock($item)) : ?>
		<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 sf-lock-note" role="status">
			<span><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_LOCKED_BY', $sfLock['by'], HTMLHelper::_('date', $sfLock['since'], Text::_('DATE_FORMAT_LC2')))); ?></span>
			<?php if (\SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::canCheckin($item)) : ?>
				<form action="<?php echo $act; ?>" method="post" class="ms-auto">
					<button type="submit" class="btn btn-sm btn-outline-dark" name="task" value="submission.checkin"><?php echo Text::_('COM_SQUIDFORM_CHECKIN'); ?></button>
					<input type="hidden" name="uid" value="<?php echo $this->escape($item->uid); ?>">
					<?php echo HTMLHelper::_('form.token'); ?>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ($this->canEdit || $this->canDelete) : ?>
		<form action="<?php echo $act; ?>" method="post" class="d-flex flex-wrap gap-2 mb-3 d-print-none" id="sfv-detail-actions">
			<?php if ($this->canEdit) : ?>
				<button type="submit" class="btn btn-primary" name="task" value="submission.edit"><?php echo Text::_('COM_SQUIDFORM_VIEWER_EDIT'); ?></button>
			<?php endif; ?>
			<?php if ($this->canDelete && !$item->archived) : ?>
				<button type="submit" class="btn btn-outline-secondary" name="task" value="submission.archive"><?php echo Text::_('COM_SQUIDFORM_VIEWER_ARCHIVE'); ?></button>
			<?php elseif ($this->canDelete) : ?>
				<button type="submit" class="btn btn-outline-secondary" name="task" value="submission.restore"><?php echo Text::_('COM_SQUIDFORM_VIEWER_RESTORE'); ?></button>
			<?php endif; ?>
			<input type="hidden" name="uid" value="<?php echo $this->escape($item->uid); ?>">
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	<?php endif; ?>

	<?php echo $this->layout('output', ['pdf_url' => Viewer::url('task=submission.pdf&uid=' . $item->uid)]); ?>

	<div class="row">
		<div class="col-lg-8">
			<section class="card card-body mb-3" aria-labelledby="sfv-values-h">
				<h2 class="h4" id="sfv-values-h"><?php echo $this->escape($item->form_title); ?></h2>
				<?php echo $this->layout('values', ['item' => $item]); ?>
			</section>
		</div>
		<div class="col-lg-4">
			<?php echo $this->layout('esign', ['item' => $item, 'show_ip' => false, 'sign_url' => \SquidForm\Component\SquidForm\Administrator\Engine\ESign::canResign($item) ? Viewer::url('view=submission&layout=sign&uid=' . $item->uid) : '']); ?>
			<?php echo ApprovalSection::render($item, Viewer::url('view=submission&uid=' . $item->uid, false), Uri::getInstance()->toString(['path', 'query']), 'sfva'); ?>
			<section class="card card-body mb-3" aria-labelledby="sfv-details-h">
				<h2 class="h5" id="sfv-details-h"><?php echo Text::_('COM_SQUIDFORM_DETAILS'); ?></h2>
				<?php echo $this->layout('details', ['item' => $item, 'show_ip' => false]); ?>
			</section>
			<section class="card card-body mb-3" aria-labelledby="sfv-history-h">
				<h2 class="h5" id="sfv-history-h"><?php echo Text::_('COM_SQUIDFORM_AUDIT'); ?></h2>
				<?php echo $this->layout('history', ['history' => $item->history, 'views' => $item->views, 'schema' => $item->schema]); ?>
			</section>
		</div>
	</div>
</div>
