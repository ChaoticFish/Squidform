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

/** @var \SquidForm\Component\SquidForm\Administrator\View\Purge\HtmlView $this */

$isForm = $this->kind === 'forms';
$ready  = $isForm ? \count($this->forms) === 1 : (bool) $this->ids;
$expect = $isForm ? ($this->forms[0]->alias ?? '') : (string) \count($this->ids);
?>
<form action="<?php echo Route::_('index.php?option=com_squidform&view=purge'); ?>" method="post" name="adminForm" id="adminForm">
	<div class="card card-body border-danger">
		<h2 class="h4 text-danger-emphasis"><?php echo Text::_($isForm ? 'COM_SQUIDFORM_PURGE_FORM_HEADING' : 'COM_SQUIDFORM_PURGE_SUBMISSIONS_HEADING'); ?></h2>

		<?php if ($this->skipped) : ?>
			<div class="alert alert-warning"><?php echo Text::sprintf($isForm ? 'COM_SQUIDFORM_PURGE_FORMS_SKIPPED' : 'COM_SQUIDFORM_PURGE_SKIPPED', $this->skipped); ?></div>
		<?php endif; ?>

		<?php if (!$ready) : ?>
			<div class="alert alert-info"><?php echo Text::_($isForm ? 'COM_SQUIDFORM_PURGE_PICK_ONE_FORM' : 'COM_SQUIDFORM_PURGE_NOTHING'); ?></div>
		<?php elseif ($isForm) : $f = $this->forms[0]; ?>
			<p><?php echo Text::sprintf('COM_SQUIDFORM_PURGE_FORM_WHAT', $this->escape($f->title), (int) $this->rows); ?></p>
			<ul>
				<?php foreach ($this->tables as $t) : ?>
					<li><code><?php echo $this->escape($t); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p><?php echo Text::_('COM_SQUIDFORM_PURGE_KEPT'); ?></p>
		<?php else : ?>
			<p><?php echo Text::plural('COM_SQUIDFORM_PURGE_SUBMISSIONS_WHAT', \count($this->ids)); ?></p>
			<p class="small text-body-secondary"><?php echo Text::sprintf('COM_SQUIDFORM_PURGE_IDS', implode(', ', $this->ids)); ?></p>
			<p><?php echo Text::_('COM_SQUIDFORM_PURGE_KEPT'); ?></p>
		<?php endif; ?>

		<?php if ($ready) : ?>
			<div class="mb-3" style="max-width:28rem">
				<label for="sf-purge-confirm" class="form-label fw-bold"><?php echo Text::sprintf('COM_SQUIDFORM_PURGE_TYPE', $this->escape($expect)); ?></label>
				<input type="text" class="form-control" id="sf-purge-confirm" name="confirm" autocomplete="off" required aria-describedby="sf-purge-help">
				<div id="sf-purge-help" class="form-text"><?php echo Text::_('COM_SQUIDFORM_PURGE_CANNOT_UNDO'); ?></div>
			</div>
			<button type="submit" class="btn btn-danger" id="sf-purge-run"><?php echo Text::_('COM_SQUIDFORM_PURGE_BUTTON'); ?></button>
		<?php endif; ?>
	</div>
	<input type="hidden" name="task" value="purge.run">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
