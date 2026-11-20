<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;

/** @var \SquidForm\Component\SquidForm\Site\View\Approvals\HtmlView $this */

$act  = $this->url('view=approvals');
$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('change', (e) => {
  const all = e.target.closest('[data-sf-checkall]');
  if (all) document.querySelectorAll('#sfap-list input[name="cid[]"]').forEach((c) => { c.checked = all.checked; });
});
document.addEventListener('submit', (e) => {
  const b = e.submitter;
  if (b && b.dataset.sfConfirm && !window.confirm(b.dataset.sfConfirm)) e.preventDefault();
});
JS, ['name' => 'com_squidform.approvals']);
?>
<div class="com-squidform sf-viewer sf-approvals">
	<h1><?php echo $this->escape($this->params->get('page_heading', '') ?: Text::_('COM_SQUIDFORM_APPROVALS_TITLE')); ?></h1>

	<p class="sf-viewer-count" role="status"><?php echo Text::plural('COM_SQUIDFORM_APPROVALS_N_WAITING', $this->pagination->total); ?></p>

	<?php if ($this->items) : ?>
		<form action="<?php echo $act; ?>" method="post" id="sfap-list">
			<div class="table-responsive">
				<table class="table table-striped align-middle">
					<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_TITLE'); ?></caption>
					<thead>
						<tr>
							<th scope="col" class="sf-col-check"><input class="form-check-input" type="checkbox" data-sf-checkall id="sfap-all" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_VIEWER_SELECT_ALL')); ?>"></th>
							<th scope="col"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_COL_REQUEST'); ?></th>
							<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
							<th scope="col"><?php echo Text::_('COM_SQUIDFORM_VIEWER_COL_SUBMITTED_BY'); ?></th>
							<th scope="col"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_COL_STEP'); ?></th>
							<th scope="col"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_COL_WAITING'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($this->items as $row) : ?>
							<?php $flow = ApprovalFlow::of(FormRepository::version((int) $row->version_id)); ?>
							<tr>
								<td class="sf-col-check">
									<input class="form-check-input" type="checkbox" name="cid[]" value="<?php echo $this->escape($row->uid); ?>" id="sfap-c<?php echo $this->escape($row->uid); ?>"
										aria-label="<?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_VIEWER_SELECT_N', Ref::short($row->uid))); ?>">
									<input type="hidden" name="sf_seen[<?php echo $this->escape($row->uid); ?>]" value="<?php echo $this->escape($row->current_step . ':' . (int) $row->approval_round); ?>">
								</td>
								<td><a href="<?php echo $this->url('view=approval&uid=' . $row->uid); ?>"><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_APPROVALS_REQUEST_N', Ref::short($row->uid))); ?></a></td>
								<td><?php echo $this->escape($row->form_title); ?></td>
								<td><?php echo (int) $row->user_id ? $this->escape($row->user_name) : Text::_('COM_SQUIDFORM_GUEST'); ?></td>
								<td><?php echo $this->escape((string) (ApprovalFlow::step($flow ?? ['steps' => []], (string) $row->current_step)['title'] ?? $row->current_step)); ?></td>
								<td><?php echo $row->step_since ? HTMLHelper::_('date', $row->step_since, Text::_('DATE_FORMAT_LC5')) : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<fieldset class="card card-body mb-3">
				<legend class="h5"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_BULK'); ?></legend>
				<div class="mb-2">
					<label class="form-label" for="sfap-comment"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_BULK_COMMENT'); ?></label>
					<input type="text" class="form-control" id="sfap-comment" name="sf_comment" maxlength="4000">
				</div>
				<div>
					<button type="submit" class="btn btn-success" name="task" value="approval.bulkApprove"><?php echo Text::_('COM_SQUIDFORM_APPROVALS_BULK_BUTTON'); ?></button>
				</div>
			</fieldset>
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
		<div class="sf-pagination"><?php echo $this->pagination->getPagesLinks(); ?></div>
	<?php else : ?>
		<p><?php echo Text::_('COM_SQUIDFORM_APPROVALS_NONE'); ?></p>
	<?php endif; ?>

	<?php if ($this->canDelegate) : ?>
		<section class="card card-body mt-4" aria-labelledby="sfap-del-h">
			<h2 class="h4" id="sfap-del-h"><?php echo Text::_('COM_SQUIDFORM_DELEGATES'); ?></h2>
			<p class="small"><?php echo Text::_('COM_SQUIDFORM_DELEGATES_HELP'); ?></p>
			<?php echo $this->loadTemplate('delegates'); ?>
		</section>
	<?php endif; ?>
</div>
