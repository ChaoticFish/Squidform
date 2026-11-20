<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Export choices (scope, format, headers, columns) and the Download button.
 * Shared by the admin Export screen and the front-end viewer's export page.
 * $displayData: picks, fieldColumns, selectedCount, filteredCount
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Exporter;

$d = (object) $displayData;
$escape = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$picked = array_flip($d->picks);
$isOn   = static fn (string $id): string => isset($picked[$id]) ? ' checked' : '';
$n      = 0;
$cb     = static function (string $id, string $label) use ($isOn, &$n): string {
    $n++;

    return '<div class="form-check"><input class="form-check-input" type="checkbox" name="cols[]" id="sfx-c' . $n . '" value="'
        . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $isOn($id) . '>'
        . '<label class="form-check-label" for="sfx-c' . $n . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label></div>';
};

Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-sfx-all]');
  if (!b) return;
  const on = b.getAttribute('data-sfx-all') === '1';
  b.closest('fieldset').querySelectorAll('input[name="cols[]"]').forEach((c) => { c.checked = on; });
});
JS);
?>
			<div class="card mb-3">
	<div class="card-body">
		<fieldset class="mb-3">
			<legend class="h5"><?php echo Text::_('COM_SQUIDFORM_EXPORT_WHICH'); ?></legend>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="scope" id="sfx-scope-sel" value="selected"<?php echo $d->selectedCount ? ' checked' : ' disabled'; ?>>
				<label class="form-check-label" for="sfx-scope-sel"><?php echo Text::plural('COM_SQUIDFORM_EXPORT_SCOPE_SELECTED', $d->selectedCount); ?></label>
			</div>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="scope" id="sfx-scope-all" value="filtered"<?php echo $d->selectedCount ? '' : ' checked'; ?>>
				<label class="form-check-label" for="sfx-scope-all"><?php echo Text::plural('COM_SQUIDFORM_EXPORT_SCOPE_FILTERED', $d->filteredCount); ?></label>
			</div>
			<div class="form-text"><?php echo Text::_('COM_SQUIDFORM_EXPORT_SCOPE_HELP'); ?></div>
		</fieldset>

		<div class="row">
			<fieldset class="col-sm-6 mb-3">
				<legend class="h5"><?php echo Text::_('COM_SQUIDFORM_EXPORT_FORMAT'); ?></legend>
				<div class="form-check">
					<input class="form-check-input" type="radio" name="format" id="sfx-fmt-csv" value="csv" checked>
					<label class="form-check-label" for="sfx-fmt-csv"><?php echo Text::_('COM_SQUIDFORM_EXPORT_FORMAT_CSV'); ?></label>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="radio" name="format" id="sfx-fmt-json" value="json">
					<label class="form-check-label" for="sfx-fmt-json"><?php echo Text::_('COM_SQUIDFORM_EXPORT_FORMAT_JSON'); ?></label>
				</div>
			</fieldset>
			<fieldset class="col-sm-6 mb-3">
				<legend class="h5"><?php echo Text::_('COM_SQUIDFORM_EXPORT_HEADERS'); ?></legend>
				<div class="form-check">
					<input class="form-check-input" type="radio" name="headers" id="sfx-h-labels" value="labels" checked>
					<label class="form-check-label" for="sfx-h-labels"><?php echo Text::_('COM_SQUIDFORM_EXPORT_HEADERS_LABELS'); ?></label>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="radio" name="headers" id="sfx-h-keys" value="keys">
					<label class="form-check-label" for="sfx-h-keys"><?php echo Text::_('COM_SQUIDFORM_EXPORT_HEADERS_KEYS'); ?></label>
				</div>
				<div class="form-text"><?php echo Text::_('COM_SQUIDFORM_EXPORT_HEADERS_HELP'); ?></div>
			</fieldset>
		</div>
	</div>
</div>

<div class="card mb-3">
	<div class="card-body">
		<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_EXPORT_COLUMNS'); ?></h2>
		<p class="form-text mt-0"><?php echo Text::_('COM_SQUIDFORM_EXPORT_COLUMNS_HELP'); ?></p>

		<fieldset class="mb-3">
			<legend class="h6"><?php echo Text::_('COM_SQUIDFORM_EXPORT_META'); ?></legend>
			<?php foreach (Exporter::META as $key => $lang) : ?>
				<?php echo $cb('meta:' . $key, Text::_($lang)); ?>
			<?php endforeach; ?>
			<p class="form-text mb-1"><?php echo Text::_('COM_SQUIDFORM_EXPORT_NO_IP'); ?></p>
			<button type="button" class="btn btn-sm btn-link px-0" data-sfx-all="1"><?php echo Text::_('COM_SQUIDFORM_EXPORT_ALL'); ?></button>
			<button type="button" class="btn btn-sm btn-link" data-sfx-all="0"><?php echo Text::_('COM_SQUIDFORM_EXPORT_NONE'); ?></button>
		</fieldset>

		<fieldset class="mb-3">
			<legend class="h6"><?php echo Text::_('COM_SQUIDFORM_EXPORT_FIELDS'); ?></legend>
			<?php if (!$d->fieldColumns) : ?>
				<p><?php echo Text::_('COM_SQUIDFORM_EXPORT_NO_FIELDS'); ?></p>
			<?php endif; ?>
			<?php foreach ($d->fieldColumns as $col) : ?>
				<?php if ($col['type'] === 'repeater') : ?>
					<fieldset class="ms-1 mt-2 mb-2 ps-3 border-start">
						<legend class="h6 fs-6"><?php echo Text::sprintf('COM_SQUIDFORM_EXPORT_REPEATER', $escape($col['label'])); ?></legend>
						<?php foreach ($col['children'] as $ch) : ?>
							<?php echo $cb('f:' . $col['key'] . '.' . $ch['key'], $ch['label']); ?>
						<?php endforeach; ?>
					</fieldset>
				<?php else : ?>
					<?php echo $cb('f:' . $col['key'], $col['label']); ?>
				<?php endif; ?>
			<?php endforeach; ?>
			<button type="button" class="btn btn-sm btn-link px-0" data-sfx-all="1"><?php echo Text::_('COM_SQUIDFORM_EXPORT_ALL'); ?></button>
			<button type="button" class="btn btn-sm btn-link" data-sfx-all="0"><?php echo Text::_('COM_SQUIDFORM_EXPORT_NONE'); ?></button>
		</fieldset>
	</div>
</div>

<button type="submit" class="btn btn-primary" id="sfx-download">
	<span class="icon-download" aria-hidden="true"></span>
	<?php echo Text::_('COM_SQUIDFORM_EXPORT_DOWNLOAD'); ?>
</button>
