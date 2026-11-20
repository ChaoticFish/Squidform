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

/** @var \SquidForm\Component\SquidForm\Administrator\View\Resumes\HtmlView $this */

HTMLHelper::_('behavior.multiselect');
$days = \SquidForm\Component\SquidForm\Administrator\Engine\Resume::days();
?>
<form action="<?php echo Route::_('index.php?option=com_squidform&view=resumes'); ?>" method="post" name="adminForm" id="adminForm">
	<p><?php echo Text::sprintf('COM_SQUIDFORM_RESUMES_INTRO', $days); ?></p>
	<div class="d-flex flex-wrap gap-2 align-items-end mb-3">
		<div>
			<label class="form-label" for="sf-resumes-form"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></label>
			<select class="form-select" id="sf-resumes-form" name="form_id" onchange="this.form.submit()">
				<option value="0"><?php echo Text::_('COM_SQUIDFORM_RESUMES_ALL_FORMS'); ?></option>
				<?php foreach ($this->forms as $id => $title) : ?>
					<option value="<?php echo (int) $id; ?>"<?php echo $id === $this->formId ? ' selected' : ''; ?>><?php echo $this->escape($title); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="form-label" for="sf-resumes-search"><?php echo Text::_('COM_SQUIDFORM_RESUMES_SEARCH'); ?></label>
			<input type="search" class="form-control" id="sf-resumes-search" name="search" value="<?php echo $this->escape($this->search); ?>">
		</div>
		<button type="submit" class="btn btn-primary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
	</div>

	<?php if (!$this->rows) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_RESUMES_EMPTY'); ?></div>
	<?php else : ?>
		<table class="table" id="sf-resumes">
			<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_RESUMES_TITLE'); ?></caption>
			<thead><tr>
				<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_RESUMES_WHO'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_RESUMES_REACHED'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_RESUMES_SAVED'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_RESUMES_EXPIRES'); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ($this->rows as $i => $r) : ?>
				<tr>
					<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $r->id, false, 'cid', 'cb', $r->user_id ? (string) $r->user_name : (string) $r->email); ?></td>
					<td><?php echo $this->escape($r->form_title); ?></td>
					<td>
						<?php if ((int) $r->user_id > 0) : ?>
							<?php echo $this->escape($r->user_name); ?> <span class="small text-body-secondary">(<?php echo $this->escape($r->user_email); ?>)</span>
						<?php else : ?>
							<?php echo $this->escape($r->email); ?> <span class="badge text-bg-secondary"><?php echo Text::_('COM_SQUIDFORM_GUEST'); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo $r->pages > 1 ? $this->escape(Text::sprintf('COM_SQUIDFORM_STEP_OF', (int) $r->page + 1, (int) $r->pages)) : '—'; ?></td>
					<td><?php echo HTMLHelper::_('date', $r->modified, Text::_('DATE_FORMAT_LC5')); ?></td>
					<td><?php echo HTMLHelper::_('date', $r->expires, Text::_('DATE_FORMAT_LC4')); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="small text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_RESUMES_RESEND_HELP'); ?></p>
	<?php endif; ?>
	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
