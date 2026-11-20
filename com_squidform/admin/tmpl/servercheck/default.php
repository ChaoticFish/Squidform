<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Servercheck\HtmlView $this */
$marks = ['ok' => ['✓', 'text-success', 'COM_SQUIDFORM_SC_OK'], 'warn' => ['!', 'text-warning-emphasis', 'COM_SQUIDFORM_SC_WARN'], 'bad' => ['✕', 'text-danger', 'COM_SQUIDFORM_SC_BAD'], 'info' => ['i', 'text-body-secondary', 'COM_SQUIDFORM_SC_INFO']];
?>
<div class="sf-servercheck">
	<p><?php echo Text::_('COM_SQUIDFORM_SERVERCHECK_INTRO'); ?></p>
	<table class="table">
		<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_SERVERCHECK_TITLE'); ?></caption>
		<thead><tr><th scope="col"><?php echo Text::_('COM_SQUIDFORM_SC_CHECK'); ?></th><th scope="col"><?php echo Text::_('COM_SQUIDFORM_SC_RESULT'); ?></th></tr></thead>
		<tbody>
		<?php foreach ($this->checks as $c) : [$glyph, $cls, $word] = $marks[$c['status']] ?? $marks['info']; ?>
			<tr class="sf-sc-<?php echo $this->escape($c['status']); ?>">
				<th scope="row"><span class="<?php echo $cls; ?> fw-bold me-1" aria-hidden="true"><?php echo $glyph; ?></span><span class="visually-hidden"><?php echo Text::_($word); ?>: </span><?php echo $this->escape($c['label']); ?></th>
				<td><div class="font-monospace small" style="white-space:pre-wrap"><?php echo $this->escape($c['value']); ?></div>
					<?php if ($c['help'] !== '') : ?><div class="form-text"><?php echo $this->escape($c['help']); ?></div><?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<form method="post" action="<?php echo Route::_('index.php?option=com_squidform&task=servercheck.eicar'); ?>">
		<button type="submit" class="btn btn-primary" id="sf-sc-eicar" aria-describedby="sf-sc-eicar-desc"<?php echo $this->canTest ? '' : ' disabled'; ?>><?php echo Text::_('COM_SQUIDFORM_SC_EICAR'); ?></button>
		<p class="form-text" id="sf-sc-eicar-desc"><?php echo Text::_($this->canTest ? 'COM_SQUIDFORM_SC_EICAR_DESC' : ($this->hasAddon ? 'COM_SQUIDFORM_SC_EICAR_NEEDS' : 'COM_SQUIDFORM_SC_EICAR_ADDON')); ?></p>
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</div>
