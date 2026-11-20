<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\Resume;
use SquidForm\Component\SquidForm\Site\View\Mine\HtmlView;

/** @var HtmlView $this */

$act = $this->url('view=mine');
?>
<div class="com-squidform sf-viewer sf-mine">
	<h1><?php echo $this->escape($this->params->get('page_heading', '') ?: Text::_('COM_SQUIDFORM_MINE_TITLE')); ?></h1>

	<form action="<?php echo $act; ?>" method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3" id="sfmine-filter">
		<div>
			<label class="form-label" for="sfmine-status"><?php echo Text::_('COM_SQUIDFORM_MINE_SHOW'); ?></label>
			<select class="form-select" id="sfmine-status" name="status">
				<option value=""><?php echo Text::_('COM_SQUIDFORM_MINE_ALL'); ?></option>
				<?php if ($this->showSaved) : ?>
					<option value="saved"<?php echo $this->status === 'saved' ? ' selected' : ''; ?>><?php echo Text::_('COM_SQUIDFORM_MINE_SAVED'); ?></option>
				<?php endif; ?>
				<?php foreach (HtmlView::STATUSES as $s) : ?>
					<option value="<?php echo $s; ?>"<?php echo $this->status === $s ? ' selected' : ''; ?>><?php echo Text::_('COM_SQUIDFORM_STATUS_' . strtoupper($s)); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<input type="hidden" name="option" value="com_squidform">
		<input type="hidden" name="view" value="mine">
		<?php if ($this->itemId) : ?><input type="hidden" name="Itemid" value="<?php echo (int) $this->itemId; ?>"><?php endif; ?>
		<button type="submit" class="btn btn-primary"><?php echo Text::_('COM_SQUIDFORM_MINE_GO'); ?></button>
	</form>

	<?php if ($this->saved) : ?>
		<h2 class="h4" id="sfmine-saved-h"><?php echo Text::_('COM_SQUIDFORM_MINE_SAVED'); ?></h2>
		<div class="table-responsive">
			<table class="table table-striped align-middle" id="sfmine-saved" aria-labelledby="sfmine-saved-h">
				<thead><tr>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_MINE_SAVED_ON'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_MINE_DELETED_ON'); ?></th>
					<th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_MINE_ACTIONS'); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ($this->saved as $d) : ?>
					<tr data-draft="<?php echo (int) $d->id; ?>">
						<th scope="row"><?php echo $this->escape($d->form_title); ?> <span class="badge bg-secondary"><?php echo Text::_('COM_SQUIDFORM_MINE_SAVED_BADGE'); ?></span></th>
						<td><?php echo HTMLHelper::_('date', $d->modified, Text::_('DATE_FORMAT_LC4')); ?></td>
						<td><?php echo HTMLHelper::_('date', $d->expires, Text::_('DATE_FORMAT_LC4')); ?></td>
						<td class="text-nowrap">
							<a class="btn btn-sm btn-primary" href="<?php echo $this->escape(Resume::continueUrl($d)); ?>"><?php echo Text::_('COM_SQUIDFORM_MINE_CONTINUE'); ?><span class="visually-hidden">: <?php echo $this->escape($d->form_title); ?></span></a>
							<form action="<?php echo $this->url('task=mine.deletesaved'); ?>" method="post" class="d-inline">
								<input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
								<?php echo HTMLHelper::_('form.token'); ?>
								<button type="submit" class="btn btn-sm btn-outline-danger" data-sf-confirm="<?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_MINE_DELETE_CONFIRM', $d->form_title)); ?>"><?php echo Text::_('JACTION_DELETE'); ?><span class="visually-hidden">: <?php echo $this->escape($d->form_title); ?></span></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ($this->status !== 'saved') : ?>
		<h2 class="h4" id="sfmine-sent-h"><?php echo Text::_('COM_SQUIDFORM_MINE_SENT'); ?></h2>
		<p class="sf-viewer-count" role="status"><?php echo Text::plural('COM_SQUIDFORM_MINE_N_SENT', $this->pagination->total); ?></p>
		<?php if ($this->items) : ?>
			<div class="table-responsive">
				<table class="table table-striped align-middle" id="sfmine-list" aria-labelledby="sfmine-sent-h">
					<thead><tr>
						<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
						<th scope="col"><?php echo Text::_('COM_SQUIDFORM_PDF_REFERENCE'); ?></th>
						<th scope="col"><?php echo Text::_('COM_SQUIDFORM_MINE_SENT_ON'); ?></th>
						<th scope="col"><?php echo Text::_('COM_SQUIDFORM_MINE_STATUS'); ?></th>
						<th scope="col"><?php echo Text::_('COM_SQUIDFORM_MINE_CHANGED'); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($this->items as $r) : ?>
						<tr data-uid="<?php echo $this->escape($r->uid); ?>">
							<td><?php echo $this->escape($r->form_title); ?></td>
							<td>
								<?php if ($r->canView) : ?>
									<a href="<?php echo $this->url('view=submission&uid=' . $r->uid); ?>"><code class="text-reset"><?php echo $this->escape(Ref::short($r->uid)); ?></code><span class="visually-hidden">: <?php echo $this->escape(Text::_('COM_SQUIDFORM_MINE_VIEW')); ?></span></a>
								<?php else : ?>
									<code class="text-reset"><?php echo $this->escape(Ref::short($r->uid)); ?></code>
								<?php endif; ?>
							</td>
							<td><?php echo HTMLHelper::_('date', $r->created, Text::_('DATE_FORMAT_LC4')); ?></td>
							<td><?php echo $this->escape($r->statusText); ?></td>
							<td><?php echo $r->modified && $r->modified !== $r->created ? HTMLHelper::_('date', $r->modified, Text::_('DATE_FORMAT_LC4')) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php echo $this->pagination->getPaginationLinks(); ?>
			<p class="small text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_MINE_NO_LINK_NOTE'); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if (!$this->items && !$this->saved && $this->status === '') : ?>
		<p><?php echo Text::_('COM_SQUIDFORM_MINE_EMPTY'); ?></p>
	<?php endif; ?>
</div>
<?php
$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('submit', (e) => {
  const b = e.submitter;
  if (b && b.dataset.sfConfirm && b.closest('.sf-mine') && !window.confirm(b.dataset.sfConfirm)) e.preventDefault();
});
JS, ['name' => 'com_squidform.mine']);
