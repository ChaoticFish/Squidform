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
use SquidForm\Component\SquidForm\Administrator\Engine\Activity;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Activity\HtmlView $this */

$f = $this->filters;
?>
<form action="<?php echo Route::_('index.php?option=com_squidform&view=activity'); ?>" method="post" name="adminForm" id="adminForm">
	<p><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_INTRO'); ?></p>
	<div class="d-flex flex-wrap gap-2 align-items-end mb-3" id="sf-activity-filters">
		<div>
			<label class="form-label" for="sf-act-form"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></label>
			<select class="form-select" id="sf-act-form" name="form_id">
				<option value="0"><?php echo Text::_('COM_SQUIDFORM_RESUMES_ALL_FORMS'); ?></option>
				<?php foreach ($this->forms as $id => $title) : ?>
					<option value="<?php echo (int) $id; ?>"<?php echo $id === $f['form_id'] ? ' selected' : ''; ?>><?php echo $this->escape($title); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="form-label" for="sf-act-group"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_WHAT'); ?></label>
			<select class="form-select" id="sf-act-group" name="group">
				<option value=""><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_ALL'); ?></option>
				<?php foreach (array_keys(Activity::GROUPS) as $g) : ?>
					<option value="<?php echo $g; ?>"<?php echo $g === $f['group'] ? ' selected' : ''; ?>><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_GROUP_' . strtoupper($g)); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="form-label" for="sf-act-user"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_WHO'); ?></label>
			<input type="search" class="form-control" id="sf-act-user" name="user" value="<?php echo $this->escape($f['user']); ?>" aria-describedby="sf-act-user-help">
			<div class="visually-hidden" id="sf-act-user-help"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_WHO_HELP'); ?></div>
		</div>
		<div>
			<label class="form-label" for="sf-act-from"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_FROM'); ?></label>
			<input type="date" class="form-control" id="sf-act-from" name="from" value="<?php echo $this->escape($f['from']); ?>">
		</div>
		<div>
			<label class="form-label" for="sf-act-to"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_TO'); ?></label>
			<input type="date" class="form-control" id="sf-act-to" name="to" value="<?php echo $this->escape($f['to']); ?>">
		</div>
		<button type="submit" class="btn btn-primary" id="sf-act-go" onclick="if (this.form.limitstart) this.form.limitstart.value=0"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
	</div>

	<?php if (!$this->rows) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_EMPTY'); ?></div>
	<?php else : ?>
		<p class="small text-body-secondary" id="sf-act-count"><?php echo Text::plural('COM_SQUIDFORM_ACTIVITY_N_ROWS', $this->total); ?></p>
		<table class="table" id="sf-activity">
			<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_TITLE'); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_WHEN'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_WHO'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_WHAT'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_PDF_REFERENCE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_DETAIL'); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ($this->rows as $r) : ?>
				<tr data-action="<?php echo $this->escape($r->action); ?>">
					<td class="text-nowrap"><?php echo HTMLHelper::_('date', $r->created, Text::_('DATE_FORMAT_LC5')); ?></td>
					<td><?php echo $r->user_id ? $this->escape($r->user_name ?? '#' . (int) $r->user_id) : Text::_('COM_SQUIDFORM_GUEST'); ?></td>
					<td><?php echo $this->escape(Activity::label((string) $r->action)); ?></td>
					<td><?php echo $r->form_id ? $this->escape($r->form_title ?? '#' . (int) $r->form_id) : '<span class="text-body-secondary">' . Text::_('COM_SQUIDFORM_ACTIVITY_EVERY_FORM') . '</span>'; ?></td>
					<td>
						<?php if ($r->sub_uid) : ?>
							<a href="<?php echo Route::_('index.php?option=com_squidform&view=submission&id=' . (int) $r->submission_id); ?>"><code class="text-reset"><?php echo $this->escape(Ref::short((string) $r->sub_uid)); ?></code></a>
						<?php elseif ($r->submission_id) : ?>
							<span class="text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_GONE'); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo $this->escape(Activity::detail($r)); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $this->pagination->getPaginationLinks(); ?>
	<?php endif; ?>
	<p class="small text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_ACTIVITY_EDITS_NOTE'); ?></p>
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
