<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Shared by the admin detail view and the front-end viewer.
 * $displayData: item (object with ->schema and ->data)
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Display;

$item   = $displayData['item'];
$schema = $item->schema;
$data   = (array) $item->data;
$e      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// "Hide what's typed" answers (0.8.0) are shown only to people with View Submissions on the form.
$reveal = \SquidForm\Component\SquidForm\Administrator\Engine\Acl::can('squidform.view', (int) ($item->form_id ?? 0));
?>
<?php if (!$schema) : ?>
	<div class="alert alert-warning"><?php echo Text::_('COM_SQUIDFORM_VERSION_MISSING'); ?></div>
	<pre class="small"><?php echo $e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
<?php else : ?>
	<dl class="row mb-0 sf-values">
	<?php foreach ($schema->valueFields() as $field) :
		$key = $field['key'];
		if (!array_key_exists($key, $data)) {
			continue;
		}
		?>
		<?php if ($field['type'] === 'repeater') : ?>
			<dt class="col-12 mt-2"><?php echo $e($field['label'] ?? $key); ?></dt>
			<dd class="col-12">
				<?php $rows = (array) $data[$key]; ?>
				<?php if (!$rows) : ?>
					—
				<?php else : ?>
					<div class="table-responsive">
					<table class="table table-sm table-bordered">
						<caption class="visually-hidden"><?php echo $e($field['label'] ?? $key); ?></caption>
						<thead><tr>
							<th scope="col">#</th>
							<?php foreach ($field['fields'] as $child) : ?>
								<th scope="col"><?php echo $e($child['label'] ?? $child['key']); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
						<?php foreach (array_values($rows) as $n => $row) : ?>
							<tr>
								<th scope="row"><?php echo $n + 1; ?></th>
								<?php foreach ($field['fields'] as $child) : ?>
									<td><?php echo Display::html($child, $row[$child['key']] ?? null, $reveal); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</dd>
		<?php else : ?>
			<dt class="col-sm-4"><?php echo $e($field['label'] ?? $key); ?></dt>
			<dd class="col-sm-8"><?php echo Display::html($field, $data[$key], $reveal); ?></dd>
		<?php endif; ?>
	<?php endforeach; ?>
	</dl>
<?php endif; ?>
