<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * The viewer list, shared by the "Submissions viewer" menu item and the
 * "SquidForm Viewer" module.
 * $displayData: ctx (Viewer context), items, pagination, state, canExport,
 *               canDelete, scope ('all'|'own'), heading, headingTag
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;
use SquidForm\Component\SquidForm\Site\Helper\ViewerList as VL;

$d       = (object) $displayData;
$v       = $d->ctx;
$cfg     = $v['config'];
$n       = $v['names'];
$p       = $v['idp'];
$e       = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$filter  = (array) $d->state->get('filter');
$sort    = (string) $d->state->get('sort');
$dir     = (string) $d->state->get('dir');
$bulk    = $d->canExport || $d->canDelete;
$total   = (int) $d->pagination->total;
$tag     = $d->headingTag ?? 'h1';
$page    = $v['pageUrl'];
$action  = strtok($page, '?');
$statuses = ['draft', 'submitted', 'pending', 'approved', 'rejected', 'returned', 'withdrawn', 'archived'];
$fn      = static fn (string $k) => $n['filter'] . '[' . $k . ']';
// In a module (often a narrow sidebar) every filter takes the full width.
$wide    = $v['ctx'] === '';
$c4      = $wide ? 'col-12 col-md-4' : 'col-12';
$c3      = $wide ? 'col-6 col-md-3' : 'col-12';
$c2      = $wide ? 'col-6 col-md-2' : 'col-12';
$anyFilter = array_filter([$filter['search'] ?? '', $filter['status'] ?? '', $filter['created_from'] ?? '', $filter['created_to'] ?? '', (int) ($filter['archived'] ?? 0) ?: '', ...array_values((array) ($filter['fields'] ?? []))], 'strlen');

Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('submit', (e) => {
  const b = e.submitter;
  if (b && b.dataset.sfConfirm && !window.confirm(b.dataset.sfConfirm)) e.preventDefault();
});
document.addEventListener('change', (e) => {
  const all = e.target.closest('[data-sf-checkall]');
  if (!all) return;
  all.closest('table').querySelectorAll('tbody input[name="cid[]"]').forEach((c) => { c.checked = all.checked; });
});
JS, ['name' => 'com_squidform.viewer.inline']);
?>
<div class="com-squidform sf-viewer" id="<?php echo $p; ?>">
	<?php if (($d->heading ?? '') !== '') : ?>
		<<?php echo $tag; ?>><?php echo $e($d->heading); ?></<?php echo $tag; ?>>
	<?php endif; ?>
	<?php if ($d->scope === 'own') : ?>
		<p class="text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_VIEWER_OWN_ONLY'); ?></p>
	<?php endif; ?>

	<form action="<?php echo $e($action); ?>" method="get" class="sf-viewer-filters card card-body mb-3" role="search" aria-label="<?php echo Text::_('COM_SQUIDFORM_VIEWER_FILTERS'); ?>">
		<?php foreach (VL::pageQuery($page) as $qk => $qv) : ?>
			<input type="hidden" name="<?php echo $e($qk); ?>" value="<?php echo $e($qv); ?>">
		<?php endforeach; ?>
		<div class="row g-3 align-items-end">
			<?php if ((int) $v['params']->get('show_search', 1)) : ?>
				<div class="<?php echo $c4; ?>">
					<label class="form-label" for="<?php echo $p; ?>-search"><?php echo Text::_('COM_SQUIDFORM_VIEWER_SEARCH'); ?></label>
					<input type="search" class="form-control" id="<?php echo $p; ?>-search" name="<?php echo $fn('search'); ?>" value="<?php echo $e($filter['search'] ?? ''); ?>" aria-describedby="<?php echo $p; ?>-search-help">
					<div id="<?php echo $p; ?>-search-help" class="form-text"><?php echo Text::_('COM_SQUIDFORM_VIEWER_SEARCH_HELP'); ?></div>
				</div>
			<?php endif; ?>

			<?php foreach ($cfg['filters'] as $i => $f) : ?>
				<?php if ($f['kind'] === 'meta' && $f['name'] === 'status') : ?>
					<div class="<?php echo $c2; ?>">
						<label class="form-label" for="<?php echo $p; ?>-status"><?php echo Text::_('COM_SQUIDFORM_VIEWER_COL_STATUS'); ?></label>
						<select class="form-select" id="<?php echo $p; ?>-status" name="<?php echo $fn('status'); ?>">
							<option value=""><?php echo Text::_('COM_SQUIDFORM_VIEWER_ANY'); ?></option>
							<?php foreach ($statuses as $st) : ?>
								<option value="<?php echo $st; ?>"<?php echo ($filter['status'] ?? '') === $st ? ' selected' : ''; ?>><?php echo Text::_('COM_SQUIDFORM_STATUS_' . strtoupper($st)); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php elseif ($f['kind'] === 'meta' && $f['name'] === 'created') : ?>
					<div class="<?php echo $c2; ?>">
						<label class="form-label" for="<?php echo $p; ?>-from"><?php echo Text::_('COM_SQUIDFORM_VIEWER_FROM'); ?></label>
						<input type="date" class="form-control" id="<?php echo $p; ?>-from" name="<?php echo $fn('created_from'); ?>" value="<?php echo $e($filter['created_from'] ?? ''); ?>">
					</div>
					<div class="<?php echo $c2; ?>">
						<label class="form-label" for="<?php echo $p; ?>-to"><?php echo Text::_('COM_SQUIDFORM_VIEWER_TO'); ?></label>
						<input type="date" class="form-control" id="<?php echo $p; ?>-to" name="<?php echo $fn('created_to'); ?>" value="<?php echo $e($filter['created_to'] ?? ''); ?>">
					</div>
				<?php elseif ($f['kind'] === 'field') : $cur = (string) ($filter['fields'][$f['name']] ?? ''); ?>
					<div class="<?php echo $c2; ?>">
						<label class="form-label" for="<?php echo $p; ?>-f<?php echo $i; ?>"><?php echo $e($f['field']['label'] ?? $f['name']); ?></label>
						<select class="form-select" id="<?php echo $p; ?>-f<?php echo $i; ?>" name="<?php echo $n['filter']; ?>[f][<?php echo $e($f['name']); ?>]">
							<option value=""><?php echo Text::_('COM_SQUIDFORM_VIEWER_ANY'); ?></option>
							<?php foreach (VL::filterOptions($f) as $val => $lab) : ?>
								<option value="<?php echo $e($val); ?>"<?php echo $cur === (string) $val ? ' selected' : ''; ?>><?php echo $e($lab); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php if ($d->canDelete) : ?>
				<div class="<?php echo $c3; ?>">
					<label class="form-label" for="<?php echo $p; ?>-archived"><?php echo Text::_('COM_SQUIDFORM_VIEWER_SHOW'); ?></label>
					<select class="form-select" id="<?php echo $p; ?>-archived" name="<?php echo $fn('archived'); ?>">
						<option value="0"><?php echo Text::_('COM_SQUIDFORM_FILTER_DELETED_NO'); ?></option>
						<option value="1"<?php echo (int) ($filter['archived'] ?? 0) === 1 ? ' selected' : ''; ?>><?php echo Text::_('COM_SQUIDFORM_FILTER_DELETED_YES'); ?></option>
					</select>
				</div>
			<?php endif; ?>

			<div class="<?php echo $wide ? 'col-12 col-md-auto' : 'col-12'; ?> d-flex flex-wrap gap-2">
				<button type="submit" class="btn btn-primary"><?php echo Text::_('COM_SQUIDFORM_VIEWER_APPLY'); ?></button>
				<?php if ($anyFilter) : ?>
					<button type="submit" class="btn btn-secondary" name="<?php echo $n['clear']; ?>" value="1"><?php echo Text::_('COM_SQUIDFORM_VIEWER_CLEAR'); ?></button>
				<?php endif; ?>
			</div>
		</div>
	</form>

	<p class="sf-viewer-count" role="status"><?php echo Text::plural('COM_SQUIDFORM_VIEWER_N_FOUND', $total); ?></p>

	<form action="<?php echo Viewer::url('view=submissions', true, $v); ?>" method="post" class="sf-viewer-bulk">
		<?php if ($bulk && $d->items) : ?>
			<div class="d-flex flex-wrap gap-2 mb-2" role="group" aria-label="<?php echo Text::_('COM_SQUIDFORM_VIEWER_ACTIONS'); ?>">
				<?php if ($d->canExport) : ?>
					<button type="submit" class="btn btn-outline-primary" name="task" value="export.start"><?php echo Text::_('COM_SQUIDFORM_VIEWER_EXPORT'); ?></button>
				<?php endif; ?>
				<?php if ($d->canDelete && !(int) ($filter['archived'] ?? 0)) : ?>
					<button type="submit" class="btn btn-outline-secondary" name="task" value="submission.archive"><?php echo Text::_('COM_SQUIDFORM_VIEWER_ARCHIVE_TICKED'); ?></button>
				<?php elseif ($d->canDelete) : ?>
					<button type="submit" class="btn btn-outline-secondary" name="task" value="submission.restore"><?php echo Text::_('COM_SQUIDFORM_VIEWER_RESTORE_TICKED'); ?></button>
				<?php endif; ?>
				<span class="form-text align-self-center"><?php echo Text::_('COM_SQUIDFORM_VIEWER_TICK_HELP'); ?></span>
			</div>
		<?php endif; ?>

		<?php if (!$d->items) : ?>
			<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_VIEWER_NONE'); ?></div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table table-striped table-hover sf-viewer-table">
					<caption class="visually-hidden"><?php echo $e($v['form']['title']); ?></caption>
					<thead>
						<tr>
							<?php if ($bulk) : ?>
								<td class="sf-col-check">
									<input class="form-check-input" type="checkbox" id="<?php echo $p; ?>-all" data-sf-checkall>
									<label class="visually-hidden" for="<?php echo $p; ?>-all"><?php echo Text::_('COM_SQUIDFORM_VIEWER_SELECT_ALL'); ?></label>
								</td>
							<?php endif; ?>
							<?php foreach ($cfg['columns'] as $col) :
								$isSorted = $sort === $col['name'];
								$aria     = $isSorted ? ($dir === 'asc' ? 'ascending' : 'descending') : null;
								?>
								<th scope="col"<?php echo $aria ? ' aria-sort="' . $aria . '"' : ''; ?>>
									<?php if (VL::sortable($col)) :
										$next = $isSorted && $dir === 'asc' ? 'desc' : 'asc';
										$href = VL::withQuery($page, [$n['sort'] => $col['name'], $n['dir'] => $next]);
										?>
										<a href="<?php echo $e($href); ?>"><?php echo $e(VL::label($col)); ?><?php if ($isSorted) : ?> <span aria-hidden="true"><?php echo $dir === 'asc' ? '▲' : '▼'; ?></span><?php endif; ?><span class="visually-hidden"><?php echo Text::_($next === 'asc' ? 'COM_SQUIDFORM_VIEWER_SORT_ASC' : 'COM_SQUIDFORM_VIEWER_SORT_DESC'); ?></span></a>
									<?php else : ?>
										<?php echo $e(VL::label($col)); ?>
									<?php endif; ?>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($d->items as $row) : $detail = Viewer::url('view=submission&uid=' . $row->uid, true, $v); ?>
							<tr>
								<?php if ($bulk) : ?>
									<td class="sf-col-check">
										<input class="form-check-input" type="checkbox" name="cid[]" value="<?php echo $e($row->uid); ?>" id="<?php echo $p; ?>-cb<?php echo $e($row->uid); ?>">
										<label class="visually-hidden" for="<?php echo $p; ?>-cb<?php echo $e($row->uid); ?>"><?php echo $e(Text::sprintf('COM_SQUIDFORM_VIEWER_SELECT_N', Ref::short($row->uid))); ?></label>
									</td>
								<?php endif; ?>
								<?php foreach ($cfg['columns'] as $ci => $col) : ?>
									<?php if ($ci === 0) : ?>
										<th scope="row"><a href="<?php echo $detail; ?>"><?php echo $e(VL::cell($col, $row)); ?><span class="visually-hidden"> (<?php echo $e(Text::sprintf('COM_SQUIDFORM_VIEWER_OPEN_N', Ref::short($row->uid))); ?>)</span></a><?php if ($sfLock = \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::activeLock($row)) : ?><span class="sf-lock ms-1" title="<?php echo $e(Text::sprintf('COM_SQUIDFORM_LOCKED_BY', $sfLock['by'], HTMLHelper::_('date', $sfLock['since'], Text::_('DATE_FORMAT_LC2')))); ?>"><svg aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V7H4a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1h-.5V4.5A3.5 3.5 0 0 0 8 1zm2 6H6V4.5a2 2 0 1 1 4 0V7z"/></svg><span class="visually-hidden"> <?php echo $e(Text::sprintf('COM_SQUIDFORM_LOCKED_BY', $sfLock['by'], HTMLHelper::_('date', $sfLock['since'], Text::_('DATE_FORMAT_LC2')))); ?></span></span><?php endif; ?></th>
									<?php else : ?>
										<td><?php echo $e(VL::cell($col, $row)); ?></td>
									<?php endif; ?>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="com-squidform-pagination"><?php echo $d->pagination->getPagesLinks(); ?></div>
		<?php endif; ?>
		<input type="hidden" name="sf_ctx" value="<?php echo $e($v['ctx']); ?>">
		<?php if ($v['ctx'] !== '') : ?>
			<input type="hidden" name="sf_return" value="<?php echo $e(base64_encode(Uri::getInstance()->toString(['path', 'query']))); ?>">
		<?php endif; ?>
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</div>
