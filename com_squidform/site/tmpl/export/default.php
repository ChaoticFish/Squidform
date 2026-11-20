<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

/** @var \SquidForm\Component\SquidForm\Site\View\Export\HtmlView $this */
?>
<div class="com-squidform sf-viewer sf-viewer-export">
	<p><a href="<?php echo $this->escape(\SquidForm\Component\SquidForm\Site\Controller\ExportController::backUrl()); ?>">← <?php echo Text::_('COM_SQUIDFORM_VIEWER_BACK'); ?></a></p>
	<h1><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_VIEWER_EXPORT_TITLE', $this->viewer['form']['title'])); ?></h1>
	<form action="<?php echo Viewer::url('view=export'); ?>" method="post" id="adminForm">
		<?php echo $this->picklist; ?>
		<input type="hidden" name="task" value="export.download">
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
	<ul class="small mt-3">
		<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_VALUES'); ?></li>
		<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_REPEATERS'); ?></li>
		<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_FORMULA'); ?></li>
		<li><?php echo Text::_('COM_SQUIDFORM_EXPORT_ABOUT_AUDIT'); ?></li>
	</ul>
</div>
