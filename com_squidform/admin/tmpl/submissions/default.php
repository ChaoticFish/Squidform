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
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Submissions\HtmlView $this */

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));

$this->getDocument()->getWebAssetManager()->useScript('table.columns')->useScript('multiselect');
?>
<form action="<?php echo Route::_('index.php?option=com_squidform&view=submissions'); ?>" method="post" name="adminForm" id="adminForm">
	<div id="j-main-container" class="j-main-container">
		<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

		<?php if (empty($this->items)) : ?>
			<div class="alert alert-info">
				<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
				<?php echo Text::_('COM_SQUIDFORM_NO_SUBMISSIONS'); ?>
			</div>
		<?php else : ?>
			<table class="table" id="submissionList">
				<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_SUBMISSIONS_TITLE'); ?></caption>
				<thead>
					<tr>
						<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
						<th scope="col" class="w-5"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 's.id', $listDirn, $listOrder); ?></th>
						<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_SUMMARY'); ?></th>
						<th scope="col" class="w-15"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
						<th scope="col" class="w-10"><?php echo HTMLHelper::_('searchtools.sort', 'COM_SQUIDFORM_HEADING_STATUS', 's.status', $listDirn, $listOrder); ?></th>
						<th scope="col" class="w-10 d-none d-md-table-cell"><?php echo Text::_('COM_SQUIDFORM_HEADING_USER'); ?></th>
						<th scope="col" class="w-15"><?php echo HTMLHelper::_('searchtools.sort', 'COM_SQUIDFORM_HEADING_CREATED', 's.created', $listDirn, $listOrder); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($this->items as $i => $item) : ?>
					<tr class="row<?php echo $i % 2; ?>">
						<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', '#' . $item->id); ?>
							<input type="hidden" name="sf_seen[<?php echo (int) $item->id; ?>]" value="<?php echo $this->escape($item->current_step . ':' . (int) $item->approval_round); ?>"></td>
						<td><?php echo (int) $item->id; ?></td>
						<th scope="row">
							<?php if (\SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::activeLock($item)) : ?>
								<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor_name ?: ('#' . (int) $item->checked_out), $item->checked_out_time, 'submissions.', \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::canCheckin($item)); ?>
							<?php endif; ?>
							<a href="<?php echo Route::_('index.php?option=com_squidform&view=submission&id=' . (int) $item->id); ?>">
								<?php echo $this->escape((trim((string) ($this->summaries[(int) $item->id] ?? '')) ?: Text::_('COM_SQUIDFORM_EMPTY_SUBMISSION'))); ?>
							</a>
						</th>
						<td><?php echo $this->escape($item->form_title ?? ('#' . $item->form_id)); ?></td>
						<td><span class="badge bg-secondary text-wrap"><?php echo $this->escape(ApprovalFlow::statusText((string) $item->status, (string) $item->current_step, FormRepository::version((int) $item->version_id))); ?></span><?php if (!empty($item->is_test)) : ?> <span class="badge text-bg-warning"><?php echo Text::_('COM_SQUIDFORM_TEST_BADGE'); ?></span><?php endif; ?></td>
						<td class="d-none d-md-table-cell"><?php echo $item->user_id ? $this->escape($item->user_name) : Text::_('COM_SQUIDFORM_GUEST'); ?></td>
						<td><?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC5')); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo $this->pagination->getListFooter(); ?>
		<?php endif; ?>

		<input type="hidden" name="task" value="">
		<input type="hidden" name="boxchecked" value="0">
		<?php echo HTMLHelper::_('form.token'); ?>
	</div>
</form>
