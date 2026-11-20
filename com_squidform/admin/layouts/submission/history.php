<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * $displayData: history (list), views (object count/last_name/last_time), schema (Schema|null)
 * Edits list each changed field: old value → new value.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Display;

$e      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$schema = $displayData['schema'] ?? null;
$views  = $displayData['views'];
$label  = static function (string $key) use ($schema): array {
	$f = $schema ? $schema->field($key) : null;

	return $f ?: ['key' => $key, 'label' => $key, 'type' => 'text'];
};
$show = static function (array $field, mixed $v): string {
	if (($field['type'] ?? '') === 'repeater') {
		$rows = [];
		foreach ((array) $v as $i => $row) {
			$parts = [];
			foreach ($field['fields'] ?? [] as $c) {
				$parts[] = Display::value($c, $row[$c['key']] ?? null, '');
			}
			$rows[] = ($i + 1) . ': ' . implode(' / ', array_filter($parts, 'strlen'));
		}

		return $rows ? implode('; ', $rows) : '—';
	}

	return Display::value($field, $v);
};
?>
<ul class="list-unstyled small mb-0 sf-history">
	<?php foreach ($displayData['history'] as $a) : ?>
		<li class="mb-2">
			<?php echo HTMLHelper::_('date', $a->created, Text::_('DATE_FORMAT_LC5')); ?> —
			<?php echo $e(\SquidForm\Component\SquidForm\Administrator\Engine\Activity::label((string) $a->action)); ?> —
			<?php echo $e($a->user_name ?: Text::_('COM_SQUIDFORM_GUEST')); ?>
			<?php if ($a->action === 'export') : ?>
				<span class="text-body-secondary">(<?php echo $e(\SquidForm\Component\SquidForm\Administrator\Engine\Activity::detail($a)); ?>)</span>
			<?php elseif ($a->action === 'file_download' && is_array($a->diff)) : ?>
				<span class="text-body-secondary">(<?php echo $e($a->diff['file'] ?? ''); ?>)</span>
			<?php endif; ?>
			<?php if ($a->action === 'edit' && is_array($a->diff) && $a->diff) : ?>
				<details class="mt-1">
					<summary><?php echo Text::plural('COM_SQUIDFORM_N_FIELDS_CHANGED', count($a->diff)); ?></summary>
					<ul class="mt-1 ps-3">
						<?php foreach ($a->diff as $key => $chg) : $f = $label((string) $key); ?>
							<li><strong><?php echo $e($f['label'] ?? $key); ?>:</strong>
								<del><?php echo $e($show($f, $chg['from'] ?? null)); ?></del>
								<span aria-hidden="true">→</span><span class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_CHANGED_TO'); ?></span>
								<ins><?php echo $e($show($f, $chg['to'] ?? null)); ?></ins></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
	<?php if ($views->count) : ?>
		<li class="text-body-secondary"><?php echo $e(Text::plural('COM_SQUIDFORM_VIEWED_N', $views->count, $views->last_name ?: Text::_('COM_SQUIDFORM_GUEST'), HTMLHelper::_('date', $views->last_time, Text::_('DATE_FORMAT_LC5')))); ?></li>
	<?php endif; ?>
</ul>
