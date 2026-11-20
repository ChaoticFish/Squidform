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

$n = $this->leftovers;
$link = static fn ($t) => Route::_('index.php?option=com_squidform&view=datatables&layout=table&' . ($t->id ? 'id=' . (int) $t->id : 'name=' . rawurlencode((string) $t->table_name)));
?>
<div id="sf-datatables">
	<p><?php echo Text::_('COM_SQUIDFORM_DT_INTRO'); ?></p>
	<?php if ($n['columns'] || $n['orphans'] || $n['missing']) : ?>
		<div class="alert alert-warning" id="sf-dt-leftovers">
			<h2 class="h5"><?php echo Text::_('COM_SQUIDFORM_DT_LEFTOVERS'); ?></h2>
			<ul class="mb-0">
				<?php if ($n['columns']) : ?><li><?php echo Text::plural('COM_SQUIDFORM_DT_N_RETIRED', $n['columns']); ?></li><?php endif; ?>
				<?php if ($n['orphans']) : ?><li><?php echo Text::plural('COM_SQUIDFORM_DT_N_ORPHANS', $n['orphans']); ?></li><?php endif; ?>
				<?php if ($n['missing']) : ?><li><?php echo Text::plural('COM_SQUIDFORM_DT_N_MISSING', $n['missing']); ?></li><?php endif; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if (!$this->tables) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_DT_EMPTY'); ?></div>
	<?php else : ?>
		<table class="table" id="sf-dt-list">
			<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_DT_TITLE'); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_TABLE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_KIND'); ?></th>
				<th scope="col" class="text-end"><?php echo Text::_('COM_SQUIDFORM_DT_ROWS'); ?></th>
				<th scope="col" class="text-end"><?php echo Text::_('COM_SQUIDFORM_DT_SIZE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DT_NOTES'); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ($this->tables as $t) : ?>
				<tr data-table="<?php echo $this->escape($t->table_name); ?>">
					<th scope="row"><a href="<?php echo $link($t); ?>"><code class="text-reset"><?php echo $this->escape($t->table_name); ?></code></a></th>
					<td><?php echo $t->form_title !== null ? $this->escape($t->form_title) . (str_starts_with((string) $t->table_name, 'sfa_') ? ' <span class="badge text-bg-secondary">' . Text::_('COM_SQUIDFORM_DT_ARCHIVED') . '</span>' : '') : '<span class="text-body-secondary">' . Text::_('COM_SQUIDFORM_DT_NO_FORM') . '</span>'; ?></td>
					<td><?php echo $t->kind !== '' ? Text::_('COM_SQUIDFORM_DT_KIND_' . strtoupper($t->kind)) . ($t->field_key !== '' ? ' <code>' . $this->escape($t->field_key) . '</code>' : '') : '—'; ?></td>
					<td class="text-end"><?php echo $t->exists ? (int) $t->rows : '—'; ?></td>
					<td class="text-end text-nowrap"><?php echo $t->exists ? HtmlView::size((int) $t->bytes) : '—'; ?></td>
					<td>
						<?php if (!$t->exists) : ?><span class="badge text-bg-danger"><?php echo Text::_('COM_SQUIDFORM_DT_MISSING'); ?></span><?php endif; ?>
						<?php if ($t->orphan && $t->exists) : ?><span class="badge text-bg-warning"><?php echo Text::_('COM_SQUIDFORM_DT_ORPHAN'); ?></span><?php endif; ?>
						<?php if ($t->retired && $t->exists) : ?><span class="badge text-bg-info"><?php echo Text::plural('COM_SQUIDFORM_DT_N_RETIRED_SHORT', $t->retired); ?></span><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="small text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_DT_FOOT'); ?></p>
</div>
