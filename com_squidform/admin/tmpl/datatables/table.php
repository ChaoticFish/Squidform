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
use SquidForm\Component\SquidForm\Administrator\View\Datatables\HtmlView;

/** @var HtmlView $this */

$t    = $this->table;
$ref  = $t->id ? '<input type="hidden" name="id" value="' . (int) $t->id . '">' : '<input type="hidden" name="name" value="' . $this->escape($t->table_name) . '">';
$post = Route::_('index.php?option=com_squidform');
$here = Route::_('index.php?option=com_squidform&view=datatables&layout=table&' . ($t->id ? 'id=' . (int) $t->id : 'name=' . rawurlencode((string) $t->table_name)));
$retired = array_values(array_filter($this->columns, static fn ($c) => $c->retired));
?>
<div id="sf-dt-table">
	<dl class="row mb-3">
		<dt class="col-sm-3"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></dt>
		<dd class="col-sm-9"><?php echo $t->form_title !== null ? $this->escape($t->form_title) : Text::_('COM_SQUIDFORM_DT_NO_FORM'); ?></dd>
		<dt class="col-sm-3"><?php echo Text::_('COM_SQUIDFORM_DT_ROWS'); ?></dt>
		<dd class="col-sm-9"><?php echo $t->exists ? (int) $t->rows : '—'; ?></dd>
		<dt class="col-sm-3"><?php echo Text::_('COM_SQUIDFORM_DT_SIZE'); ?></dt>
		<dd class="col-sm-9"><?php echo $t->exists ? HtmlView::size((int) $t->bytes) : '—'; ?></dd>
	</dl>

	<?php if (!$t->exists) : ?>
		<div class="alert alert-danger">
			<p><?php echo Text::_('COM_SQUIDFORM_DT_MISSING_NOTE'); ?></p>
			<?php if ($this->canTidy) : ?>
				<form action="<?php echo $post; ?>" method="post">
					<?php echo $ref; ?>
					<input type="hidden" name="task" value="datatables.forget">
					<?php echo HTMLHelper::_('form.token'); ?>
					<button type="submit" class="btn btn-danger" id="sf-dt-forget"><?php echo Text::_('COM_SQUIDFORM_DT_FORGET'); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<?php if ($t->orphan) : ?>
			<div class="alert alert-warning">
				<p><?php echo Text::_('COM_SQUIDFORM_DT_ORPHAN_NOTE'); ?></p>
				<?php if ($this->canTidy) : ?>
					<details>
						<summary><?php echo Text::_('COM_SQUIDFORM_DT_DROP_TABLE'); ?></summary>
						<form action="<?php echo $post; ?>" method="post" class="mt-2" id="sf-dt-droptable">
							<?php echo $ref; ?>
							<label class="form-label" for="sf-dt-confirm-table"><?php echo Text::sprintf('COM_SQUIDFORM_DT_TYPE_TO_CONFIRM', $this->escape($t->table_name)); ?></label>
							<div class="d-flex gap-2 flex-wrap">
								<input type="text" class="form-control w-auto" id="sf-dt-confirm-table" name="confirm" autocomplete="off" spellcheck="false" required>
								<button type="submit" class="btn btn-danger"><?php echo Text::_('COM_SQUIDFORM_DT_DROP_TABLE_GO'); ?></button>
							</div>
							<input type="hidden" name="task" value="datatables.droptable">
							<?php echo HTMLHelper::_('form.token'); ?>
						</form>
					</details>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_DT_COLUMNS'); ?></h2>
		<table class="table table-sm" id="sf-dt-columns">
			<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_DT_COLUMNS'); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_COLUMN'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_TYPE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_FIELD'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_NOTES'); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ($this->columns as $c) : ?>
				<tr data-column="<?php echo $this->escape($c->name); ?>">
					<th scope="row"><code class="text-reset"><?php echo $this->escape($c->name); ?></code></th>
					<td><code class="text-reset"><?php echo $this->escape($c->type); ?></code></td>
					<td><?php echo $c->field_key !== '' ? '<code>' . $this->escape($c->field_key) . '</code>' : '<span class="text-body-secondary">' . Text::_('COM_SQUIDFORM_DT_SYSTEM') . '</span>'; ?></td>
					<td><?php echo $c->retired ? '<span class="badge text-bg-info">' . Text::_('COM_SQUIDFORM_DT_RETIRED') . '</span>' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ($retired && $this->canTidy) : ?>
			<details class="mb-3" id="sf-dt-tidy">
				<summary><?php echo Text::_('COM_SQUIDFORM_DT_DROP_COLUMNS'); ?></summary>
				<p class="mt-2"><?php echo Text::_('COM_SQUIDFORM_DT_DROP_COLUMN_NOTE'); ?></p>
				<?php foreach ($retired as $i => $c) : ?>
					<form action="<?php echo $post; ?>" method="post" class="mb-2 sf-dt-dropcolumn">
						<?php echo $ref; ?>
						<input type="hidden" name="column" value="<?php echo $this->escape($c->name); ?>">
						<label class="form-label" for="sf-dt-confirm-<?php echo $i; ?>"><?php echo Text::sprintf('COM_SQUIDFORM_DT_TYPE_TO_CONFIRM', $this->escape($c->name)); ?></label>
						<div class="d-flex gap-2 flex-wrap">
							<input type="text" class="form-control w-auto" id="sf-dt-confirm-<?php echo $i; ?>" name="confirm" autocomplete="off" spellcheck="false" required>
							<button type="submit" class="btn btn-danger"><?php echo Text::sprintf('COM_SQUIDFORM_DT_DROP_COLUMN_GO', $this->escape($c->name)); ?></button>
						</div>
						<input type="hidden" name="task" value="datatables.dropcolumn">
						<?php echo HTMLHelper::_('form.token'); ?>
					</form>
				<?php endforeach; ?>
			</details>
		<?php endif; ?>

		<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_DT_ROWS'); ?></h2>
		<?php if (!$this->rows) : ?>
			<p class="text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_DT_NO_ROWS'); ?></p>
		<?php else : ?>
			<div class="table-responsive" tabindex="0" role="region" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_DT_ROWS')); ?>">
				<table class="table table-sm table-striped" id="sf-dt-rows">
					<caption class="visually-hidden"><?php echo $this->escape($t->table_name); ?></caption>
					<thead><tr>
						<?php foreach (array_keys($this->rows[0]) as $k) : ?><th scope="col"><code class="text-reset"><?php echo $this->escape($k); ?></code></th><?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ($this->rows as $r) : ?>
						<tr><?php foreach ($r as $v) : ?><td><?php echo $this->escape(mb_strlen($v) > 200 ? mb_substr($v, 0, 200) . '…' : $v); ?></td><?php endforeach; ?></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<form action="<?php echo $here; ?>" method="post" name="adminForm" id="adminForm">
				<?php echo $this->pagination->getPaginationLinks(); ?>
				<input type="hidden" name="task" value="">
				<?php echo HTMLHelper::_('form.token'); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>
	<p class="small text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_DT_READ_ONLY'); ?></p>
</div>
