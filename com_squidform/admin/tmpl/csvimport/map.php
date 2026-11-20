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
use SquidForm\Component\SquidForm\Administrator\Engine\Import\CsvImport;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Csvimport\HtmlView $this */

$st     = $this->state;
$res    = $st['result'] ?? null;
$choice = ['' => Text::_('COM_SQUIDFORM_CSV_SKIP'), CsvImport::CREATED => Text::_('COM_SQUIDFORM_CSV_CREATED'), CsvImport::USER => Text::_('COM_SQUIDFORM_CSV_USER')] + $this->targets;
?>
<p><?php echo Text::sprintf('COM_SQUIDFORM_CSV_MAP_INTRO', $this->escape($st['filename'] ?? ''), (int) ($st['count'] ?? 0), $this->escape($this->formTitle)); ?></p>

<?php if ($res) : ?>
	<div class="alert <?php echo $res['problems'] ? 'alert-warning' : 'alert-success'; ?>" role="status" id="sf-csv-result">
		<p class="mb-1"><strong><?php echo Text::plural('COM_SQUIDFORM_CSV_N_READY', (int) $res['added']); ?></strong></p>
		<?php if ($res['skipped_fields']) : ?>
			<p class="mb-1"><?php echo Text::sprintf('COM_SQUIDFORM_CSV_NOT_FILLED', $this->escape(implode(', ', $res['skipped_fields']))); ?></p>
		<?php endif; ?>
		<?php if ($res['problems']) : ?>
			<p class="mb-1"><?php echo Text::_('COM_SQUIDFORM_CSV_PROBLEMS'); ?></p>
			<ul class="mb-0 small"><?php foreach (array_slice($res['problems'], 0, 30) as $p) : ?><li><?php echo $this->escape($p); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
	</div>
<?php endif; ?>

<form action="<?php echo Route::_('index.php?option=com_squidform&task=csvimport.run'); ?>" method="post" id="sf-csv-map">
	<table class="table">
		<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_CSV_MATCH'); ?></caption>
		<thead><tr>
			<th scope="col"><?php echo Text::_('COM_SQUIDFORM_CSV_COLUMN'); ?></th>
			<th scope="col"><?php echo Text::_('COM_SQUIDFORM_CSV_EXAMPLE'); ?></th>
			<th scope="col"><?php echo Text::_('COM_SQUIDFORM_CSV_GOES_TO'); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ((array) $st['head'] as $i => $h) : ?>
			<tr>
				<th scope="row"><label for="sf-csv-m<?php echo (int) $i; ?>"><?php echo $this->escape($h !== '' ? $h : '#' . ($i + 1)); ?></label></th>
				<td class="small text-break"><?php echo $this->escape(mb_substr((string) ($st['sample'][$i] ?? ''), 0, 80)); ?></td>
				<td>
					<select class="form-select form-select-sm" name="match[<?php echo (int) $i; ?>]" id="sf-csv-m<?php echo (int) $i; ?>">
						<?php foreach ($choice as $v => $l) : ?>
							<option value="<?php echo $this->escape($v); ?>"<?php echo (string) ($st['match'][$i] ?? '') === (string) $v ? ' selected' : ''; ?>><?php echo $this->escape($l); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<div class="d-flex gap-2">
		<button type="submit" class="btn btn-secondary" name="go" value="check" id="sf-csv-check"><?php echo Text::_('COM_SQUIDFORM_CSV_CHECK'); ?></button>
		<button type="submit" class="btn btn-primary" name="go" value="import" id="sf-csv-import"><?php echo Text::_('COM_SQUIDFORM_CSV_IMPORT'); ?></button>
		<button type="submit" class="btn btn-link" formaction="<?php echo Route::_('index.php?option=com_squidform&task=csvimport.cancel'); ?>" formnovalidate><?php echo Text::_('JCANCEL'); ?></button>
	</div>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
