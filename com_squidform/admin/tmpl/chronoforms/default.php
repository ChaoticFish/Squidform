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

/** @var \SquidForm\Component\SquidForm\Administrator\View\Chronoforms\HtmlView $this */

HTMLHelper::_('behavior.multiselect');
?>
<p><?php echo Text::_('COM_SQUIDFORM_CF_INTRO'); ?></p>

<?php
// One section per ChronoForms version on this site (8 first; 7 since 0.12.1).
$sites = [];

if ($this->here || !$this->here7) {
	$sites[] = ['v' => 8, 'here' => $this->here, 'forms' => $this->forms, 'h' => 'sf-cf-site-h', 'table' => 'sf-cf-forms', 'form' => 'adminForm', 'stub' => 'cb', 'log' => 'sf-cf-log', 'go' => 'sf-cf-site-go'];
}

if ($this->here7) {
	$sites[] = ['v' => 7, 'here' => true, 'forms' => $this->forms7, 'h' => 'sf-cf7-site-h', 'table' => 'sf-cf7-forms', 'form' => 'sf-cf7-form', 'stub' => 'cf7cb', 'log' => 'sf-cf7-log', 'go' => 'sf-cf7-site-go'];
}
?>
<?php foreach ($sites as $s) : ?>
<section class="card card-body mb-4" aria-labelledby="<?php echo $s['h']; ?>">
	<h2 class="h4" id="<?php echo $s['h']; ?>"><?php echo Text::_($s['v'] === 7 ? 'COM_SQUIDFORM_CF7_FROM_SITE' : 'COM_SQUIDFORM_CF_FROM_SITE'); ?></h2>
	<?php if (!$s['here']) : ?>
		<p class="mb-0"><?php echo Text::_('COM_SQUIDFORM_CF_NOT_HERE'); ?></p>
	<?php elseif (!$s['forms']) : ?>
		<p class="mb-0"><?php echo Text::_($s['v'] === 7 ? 'COM_SQUIDFORM_CF7_NONE' : 'COM_SQUIDFORM_CF_NONE'); ?></p>
	<?php else : ?>
		<form action="<?php echo Route::_('index.php?option=com_squidform&view=chronoforms'); ?>" method="post" name="<?php echo $s['form']; ?>" id="<?php echo $s['form']; ?>">
			<table class="table" id="<?php echo $s['table']; ?>">
				<caption class="visually-hidden"><?php echo Text::_($s['v'] === 7 ? 'COM_SQUIDFORM_CF7_FROM_SITE' : 'COM_SQUIDFORM_CF_FROM_SITE'); ?></caption>
				<thead><tr>
					<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall', 'checkall-toggle', "Joomla.checkAll(this, '" . $s['stub'] . "')"); ?></td>
					<th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
					<th scope="col"><?php echo Text::_('JFIELD_ALIAS_LABEL'); ?></th>
					<th scope="col"><?php echo Text::_('JPUBLISHED'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_CF_LOG_ROWS'); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ($s['forms'] as $i => $f) : ?>
					<tr>
						<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $f->id, false, 'cid', $s['stub'], (string) $f->title, $s['form']); ?></td>
						<td><?php echo $this->escape($f->title); ?></td>
						<td><code><?php echo $this->escape($f->alias); ?></code></td>
						<td><?php echo Text::_((int) $f->published ? 'JYES' : 'JNO'); ?></td>
						<td><?php echo (int) $f->logs; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="form-check mb-3">
				<input class="form-check-input" type="checkbox" name="with_log" value="1" id="<?php echo $s['log']; ?>"<?php echo $this->canLog ? '' : ' disabled'; ?> aria-describedby="<?php echo $s['log']; ?>-help">
				<label class="form-check-label" for="<?php echo $s['log']; ?>"><?php echo Text::_('COM_SQUIDFORM_CF_WITH_LOG'); ?></label>
				<div class="form-text" id="<?php echo $s['log']; ?>-help"><?php echo Text::_($this->canLog ? 'COM_SQUIDFORM_CF_WITH_LOG_HELP' : 'COM_SQUIDFORM_CF_NO_LOG_PERMISSION'); ?></div>
			</div>
			<button type="submit" class="btn btn-primary" name="task" value="chronoforms.site" id="<?php echo $s['go']; ?>"><?php echo Text::_('COM_SQUIDFORM_CF_IMPORT_TICKED'); ?></button>
			<input type="hidden" name="cf_version" value="<?php echo (int) $s['v']; ?>">
			<input type="hidden" name="boxchecked" value="0">
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	<?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card card-body mb-4" aria-labelledby="sf-cf-file-h">
	<h2 class="h4" id="sf-cf-file-h"><?php echo Text::_('COM_SQUIDFORM_CF_FROM_FILE'); ?></h2>
	<form action="<?php echo Route::_('index.php?option=com_squidform&task=chronoforms.file'); ?>" method="post" enctype="multipart/form-data">
		<label class="form-label" for="sf-cf-file"><?php echo Text::_('COM_SQUIDFORM_CF_FILE'); ?></label>
		<input class="form-control mb-2" type="file" id="sf-cf-file" name="cf_file" accept=".cf8bak,.cf7bak,.json" aria-describedby="sf-cf-file-help" required>
		<p class="form-text" id="sf-cf-file-help"><?php echo Text::_('COM_SQUIDFORM_CF_FILE_HELP'); ?></p>
		<button type="submit" class="btn btn-primary" id="sf-cf-file-go"><?php echo Text::_('COM_SQUIDFORM_CF_IMPORT_FILE'); ?></button>
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</section>
