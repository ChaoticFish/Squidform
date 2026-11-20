<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Chronoforms\HtmlView $this */

$levels = ['todo' => ['warning', 'COM_SQUIDFORM_CF_LEVEL_TODO'], 'changed' => ['info', 'COM_SQUIDFORM_CF_LEVEL_CHANGED'], 'info' => ['secondary', 'COM_SQUIDFORM_CF_LEVEL_INFO']];
?>
<?php if (!$this->lines) : ?>
	<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_CF_NO_REPORT'); ?></div>
<?php else : ?>
	<p><?php echo Text::_('COM_SQUIDFORM_CF_REPORT_INTRO'); ?></p>
	<?php foreach ($this->lines as $n => $l) : ?>
		<section class="card card-body mb-3 sf-cf-result" aria-labelledby="sf-cf-r<?php echo (int) $n; ?>">
			<h2 class="h4" id="sf-cf-r<?php echo (int) $n; ?>">
				<?php echo $this->escape($l['title']); ?>
				<?php if (!empty($l['ok'])) : ?>
					<span class="badge text-bg-success"><?php echo Text::_('COM_SQUIDFORM_CF_MADE'); ?></span>
				<?php else : ?>
					<span class="badge text-bg-secondary"><?php echo Text::_('COM_SQUIDFORM_CF_NOT_MADE'); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($l['ok'])) : ?>
				<p class="mb-2">
					<a href="<?php echo Route::_('index.php?option=com_squidform&task=form.edit&id=' . (int) $l['id']); ?>"><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_CF_OPEN', $l['alias'])); ?></a>
					<?php if (!empty($l['logs'])) : ?> · <?php echo Text::plural('COM_SQUIDFORM_CF_N_SUBMISSIONS', (int) $l['logs']); ?><?php endif; ?>
					· <a href="<?php echo Route::_('index.php?option=com_squidform&view=csvimport&form_id=' . (int) $l['id']); ?>"><?php echo Text::_('COM_SQUIDFORM_CF_CSV_LINK'); ?></a>
				</p>
			<?php endif; ?>
			<?php if (!empty($l['message'])) : ?>
				<p class="mb-2"><?php echo $this->escape($l['message']); ?></p>
			<?php endif; ?>
			<?php $items = (array) ($l['report'] ?? []); ?>
			<?php if ($items) : ?>
				<?php foreach (['todo', 'changed', 'info'] as $lv) :
					$these = array_values(array_filter($items, static fn ($r) => ($r['level'] ?? '') === $lv));
					if (!$these) {
						continue;
					}
					?>
					<h3 class="h6 mt-2"><span class="badge text-bg-<?php echo $levels[$lv][0]; ?>"><?php echo Text::_($levels[$lv][1]); ?></span> <?php echo \count($these); ?></h3>
					<ul class="mb-2">
						<?php foreach ($these as $r) : ?>
							<li><strong><?php echo $this->escape($r['what']); ?>:</strong> <?php echo $this->escape($r['text']); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			<?php elseif (!empty($l['ok'])) : ?>
				<p class="mb-0"><?php echo Text::_('COM_SQUIDFORM_CF_CLEAN'); ?></p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
<?php endif; ?>
