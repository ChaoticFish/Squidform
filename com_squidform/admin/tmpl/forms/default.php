<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Forms\HtmlView $this */

$user      = $this->getCurrentUser();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$canState  = $user->authorise('core.edit.state', 'com_squidform');

$this->getDocument()->getWebAssetManager()->useScript('table.columns')->useScript('multiselect');
$canCreate = $user->authorise('core.create', 'com_squidform');
?>
<?php if ($canCreate) : ?>
<details class="sf-import mb-3" id="sf-import"<?php echo \Joomla\CMS\Factory::getApplication()->getInput()->get('import') ? ' open' : ''; ?>>
	<summary class="btn btn-sm btn-outline-secondary"><?php echo Text::_('COM_SQUIDFORM_IMPORT_FORMS'); ?></summary>
	<form class="card card-body mt-2" action="<?php echo Route::_('index.php?option=com_squidform&task=forms.import'); ?>" method="post" enctype="multipart/form-data">
		<label class="form-label" for="sf-import-file"><?php echo Text::_('COM_SQUIDFORM_IMPORT_FILE'); ?></label>
		<input class="form-control" type="file" id="sf-import-file" name="sf_import" accept=".json,application/json" aria-describedby="sf-import-help" required>
		<p class="form-text" id="sf-import-help"><?php echo Text::_('COM_SQUIDFORM_IMPORT_HELP'); ?></p>
		<div><button type="submit" class="btn btn-primary" id="sf-import-go"><?php echo Text::_('COM_SQUIDFORM_IMPORT_GO'); ?></button></div>
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</details>
<?php endif; ?>
<form action="<?php echo Route::_('index.php?option=com_squidform&view=forms'); ?>" method="post" name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

				<?php if (empty($this->items)) : ?>
					<div class="alert alert-info">
						<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
						<?php echo Text::_('COM_SQUIDFORM_NO_FORMS'); ?>
					</div>
				<?php else : ?>
					<table class="table" id="formList">
						<caption class="visually-hidden">
							<?php echo Text::_('COM_SQUIDFORM_FORMS_TITLE'); ?>,
							<span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
							<span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
						</caption>
						<thead>
							<tr>
								<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
								<th scope="col" class="w-1 text-center"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?></th>
								<th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JGLOBAL_TITLE', 'a.title', $listDirn, $listOrder); ?></th>
								<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_SHORTCODE'); ?></th>
								<th scope="col" class="w-5 text-center"><?php echo Text::_('COM_SQUIDFORM_HEADING_VERSION'); ?></th>
								<th scope="col" class="w-10 text-center"><?php echo HTMLHelper::_('searchtools.sort', 'COM_SQUIDFORM_HEADING_SUBMISSIONS', 'submissions', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-10 d-none d-md-table-cell"><?php echo Text::_('JGRID_HEADING_ACCESS'); ?></th>
								<th scope="col" class="w-10 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_SQUIDFORM_HEADING_MODIFIED', 'a.modified', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-5 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($this->items as $i => $item) :
							$canEdit    = $user->authorise('core.edit', 'com_squidform');
							$canCheckin = $user->authorise('core.manage', 'com_checkin') || (int) $item->checked_out === (int) $user->id || is_null($item->checked_out);
							?>
							<tr class="row<?php echo $i % 2; ?>">
								<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->title); ?></td>
								<td class="text-center"><?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'forms.', $canState, 'cb'); ?></td>
								<th scope="row">
									<?php if ($item->checked_out) : ?>
										<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, 'forms.', $canCheckin); ?>
									<?php endif; ?>
									<?php if ($canEdit) : ?>
										<a href="<?php echo Route::_('index.php?option=com_squidform&task=form.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->title); ?></a>
									<?php else : ?>
										<?php echo $this->escape($item->title); ?>
									<?php endif; ?>
									<div class="small text-body-secondary"><?php echo Text::_('JFIELD_ALIAS_LABEL'); ?>: <?php echo $this->escape($item->alias); ?></div>
								</th>
								<td><code>{squidform <?php echo $this->escape($item->alias); ?>}</code></td>
								<td class="text-center"><?php echo (int) $item->version_no; ?></td>
								<td class="text-center">
									<a href="<?php echo Route::_('index.php?option=com_squidform&view=submissions&form_id=' . (int) $item->id); ?>">
										<?php echo (int) $item->submissions; ?>
										<span class="visually-hidden"><?php echo Text::sprintf('COM_SQUIDFORM_VIEW_SUBMISSIONS_FOR', $this->escape($item->title)); ?></span>
									</a>
								</td>
								<td class="d-none d-md-table-cell"><?php echo $this->escape($item->access_level); ?></td>
								<td class="d-none d-md-table-cell"><?php echo HTMLHelper::_('date', $item->modified, Text::_('DATE_FORMAT_LC4')); ?></td>
								<td class="d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
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
		</div>
	</div>
</form>
