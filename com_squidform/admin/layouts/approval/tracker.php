<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Step tracker. $displayData: item (loaded submission), steps (Approvals::tracker),
 * eligible (Approvals::eligible), idp (id prefix)
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$item     = $displayData['item'];
$steps    = $displayData['steps'];
$eligible = $displayData['eligible'] ?? [];
$e        = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$mark     = ['done' => '✓', 'current' => '●', 'returned' => '↩', 'rejected' => '✕', 'withdrawn' => '–', 'skipped' => '–', 'upcoming' => '○', 'not_needed' => '–'];
?>
<ol class="sf-tracker list-unstyled mb-0">
	<?php foreach ($steps as $i => $s) : ?>
		<li class="sf-step sf-step-<?php echo $e($s['state']); ?>"<?php echo $s['state'] === 'current' ? ' aria-current="step"' : ''; ?>>
			<span class="sf-step-mark" aria-hidden="true"><?php echo $mark[$s['state']] ?? '○'; ?></span>
			<span class="sf-step-body">
				<strong><?php echo $e($s['title']); ?></strong>
				<span class="sf-step-state"><?php echo Text::_('COM_SQUIDFORM_STEP_' . strtoupper($s['state'])); ?></span>
				<?php if ($s['rule'] === 'all' && \in_array($s['state'], ['current', 'upcoming'], true)) : ?>
					<span class="small text-body-secondary">(<?php echo Text::_('COM_SQUIDFORM_STEP_RULE_ALL'); ?>)</span>
				<?php endif; ?>
				<?php if ($s['people']) : ?>
					<span class="d-block small"><?php echo $e(implode(', ', $s['people'])); ?><?php echo $s['when'] ? ' — ' . HTMLHelper::_('date', $s['when'], Text::_('DATE_FORMAT_LC5')) : ''; ?></span>
				<?php endif; ?>
				<?php if ($s['state'] === 'current') : ?>
					<?php if (!empty($item->step_since)) : ?>
						<span class="d-block small"><?php echo Text::sprintf('COM_SQUIDFORM_STEP_SINCE', HTMLHelper::_('date', $item->step_since, Text::_('DATE_FORMAT_LC5'))); ?></span>
					<?php endif; ?>
					<?php if ($eligible) : ?>
						<span class="d-block small"><?php echo Text::sprintf('COM_SQUIDFORM_STEP_WAITING_FOR', $e(implode(', ', array_map(static fn ($u) => $u->name, $eligible)))); ?></span>
					<?php else : ?>
						<span class="d-block small sf-step-stuck"><?php echo Text::_('COM_SQUIDFORM_STEP_NOBODY'); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</span>
		</li>
	<?php endforeach; ?>
</ol>
