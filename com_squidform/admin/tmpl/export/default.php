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
use SquidForm\Component\SquidForm\Administrator\Engine\Exporter;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Export\HtmlView $this */

?>
<div class="row">
	<div class="col-lg-9">

		<form action="<?php echo Route::_('index.php'); ?>" method="get" class="card mb-3">
			<div class="card-body">
				<input type="hidden" name="option" value="com_squidform">
				<input type="hidden" name="view" value="export">
				<label for="sfx-form" class="form-label fw-bold"><?php echo Text::_('COM_SQUIDFORM_EXPORT_FORM'); ?></label>
				<div class="input-group">
					<select id="sfx-form" name="export_form" class="form-select" onchange="this.form.submit()">
						<option value=""><?php echo Text::_('COM_SQUIDFORM_EXPORT_CHOOSE_FORM'); ?></option>
						<?php foreach ($this->forms as $f) : ?>
							<option value="<?php echo (int) $f->id; ?>"<?php echo (int) $f->id === $this->formId ? ' selected' : ''; ?>><?php echo $this->escape($f->title); ?></option>
						<?php endforeach; ?>
					</select>
					<noscript><button type="submit" class="btn btn-secondary"><?php echo Text::_('JGLOBAL_FILTER_BUTTON'); ?></button></noscript>
				</div>
				<?php if ($this->tickedMixed) : ?>
					<div class="alert alert-warning mt-3 mb-0"><?php echo Text::sprintf('COM_SQUIDFORM_EXPORT_TICKED_MIXED', $this->tickedCount); ?></div>
				<?php endif; ?>
			</div>
		</form>

		<form action="<?php echo Route::_('index.php?option=com_squidform&view=export'); ?>" method="post" name="adminForm" id="adminForm">
		<?php if (!$this->formId) : ?>
			<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_EXPORT_PICK_FORM_FIRST'); ?></div>
		<?php else : ?>
			<input type="hidden" name="export_form" value="<?php echo (int) $this->formId; ?>">

			<?php echo \Joomla\CMS\Layout\LayoutHelper::render('export.picklist', [
				'picks' => $this->picks, 'fieldColumns' => $this->fieldColumns,
				'selectedCount' => $this->selectedCount, 'filteredCount' => $this->filteredCount,
			], JPATH_ADMINISTRATOR . '/components/com_squidform/layouts'); ?>
		<?php endif; ?>

			<input type="hidden" name="task" value="export.download">
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	</div>

	<div class="col-lg-3">
		<div class="card">
			<div class="card-body small">
				<h2 class="h6"><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT'); ?></h2>
				<ul class="ps-3 mb-0">
					<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_VALUES'); ?></li>
					<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_REPEATERS'); ?></li>
					<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_FORMULA'); ?></li>
					<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_AUDIT'); ?></li>
				</ul>
			</div>
		</div>
	</div>
</div>
