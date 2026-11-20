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

/** @var \SquidForm\Component\SquidForm\Administrator\View\Csvimport\HtmlView $this */
?>
<p><?php echo Text::_('COM_SQUIDFORM_CSV_INTRO'); ?></p>
<?php if (!$this->forms) : ?>
	<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_CSV_NO_FORMS'); ?></div>
<?php else : ?>
	<form class="card card-body" action="<?php echo Route::_('index.php?option=com_squidform&task=csvimport.upload'); ?>" method="post" enctype="multipart/form-data">
		<div class="mb-3">
			<label class="form-label" for="sf-csv-form"><?php echo Text::_('COM_SQUIDFORM_CSV_FORM'); ?></label>
			<select class="form-select" id="sf-csv-form" name="form_id" required aria-describedby="sf-csv-form-help">
				<option value=""><?php echo Text::_('COM_SQUIDFORM_CSV_CHOOSE'); ?></option>
				<?php foreach ($this->forms as $id => $title) : ?>
					<option value="<?php echo (int) $id; ?>"<?php echo $id === $this->preselect ? ' selected' : ''; ?>><?php echo $this->escape($title); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="form-text" id="sf-csv-form-help"><?php echo Text::_('COM_SQUIDFORM_CSV_FORM_HELP'); ?></div>
		</div>
		<div class="mb-3">
			<label class="form-label" for="sf-csv-file"><?php echo Text::_('COM_SQUIDFORM_CSV_FILE'); ?></label>
			<input class="form-control" type="file" id="sf-csv-file" name="csv_file" accept=".csv,text/csv" required aria-describedby="sf-csv-file-help">
			<div class="form-text" id="sf-csv-file-help"><?php echo Text::_('COM_SQUIDFORM_CSV_FILE_HELP'); ?></div>
		</div>
		<div><button type="submit" class="btn btn-primary" id="sf-csv-next"><?php echo Text::_('COM_SQUIDFORM_CSV_NEXT'); ?></button></div>
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
<?php endif; ?>
