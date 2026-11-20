<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Delegates list and add form. $displayData:
 *   action     URL the forms post to
 *   rows       Delegates::listFor()
 *   people     delegate choices (id => label)
 *   groups     group delegate choices (id => title)
 *   forms      form choices (id => title); "All forms" is always offered
 *   forAnyone  person-covered choices (id => label); empty = the signed-in person only
 *   me         signed-in user id
 *   idp        id prefix
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$d   = $displayData;
$p   = $d['idp'];
$e   = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$ret = '<input type="hidden" name="sf_return" value="' . base64_encode(html_entity_decode($d['action'], ENT_QUOTES, 'UTF-8')) . '">';
$day = static fn ($v) => $v ? HTMLHelper::_('date', $v . ' 12:00:00', Text::_('DATE_FORMAT_LC4'), 'UTC') : '—';
?>
<?php if ($d['rows']) : ?>
	<div class="table-responsive">
		<table class="table table-sm align-middle sf-delegates">
			<caption class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_DELEGATES'); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_COL_COVERS'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_COL_DELEGATE'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_COL_DATES'); ?></th>
					<th scope="col"><?php echo Text::_('COM_SQUIDFORM_HEADING_STATUS'); ?></th>
					<th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_REMOVE'); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($d['rows'] as $r) : ?>
					<tr>
						<td><?php echo $e($r->user_name); ?></td>
						<td><?php echo $e($r->delegate_name); ?><?php echo $r->note !== '' ? '<span class="d-block small">' . $e($r->note) . '</span>' : ''; ?></td>
						<td><?php echo (int) $r->form_id ? $e($r->form_title ?: '#' . (int) $r->form_id) : Text::_('COM_SQUIDFORM_DELEGATE_ALL_FORMS'); ?></td>
						<td><?php echo $day($r->start_date) . ' – ' . $day($r->end_date); ?></td>
						<td><?php echo Text::_($r->expired ? 'COM_SQUIDFORM_DELEGATE_EXPIRED' : ($r->active ? 'COM_SQUIDFORM_DELEGATE_ACTIVE' : 'COM_SQUIDFORM_DELEGATE_SCHEDULED')); ?></td>
						<td>
							<?php if ((int) $r->user_id === $d['me'] || $d['forAnyone']) : ?>
								<form action="<?php echo $e($d['action']); ?>" method="post">
									<button type="submit" class="btn btn-sm btn-outline-danger" name="task" value="approval.removeDelegate"
										aria-label="<?php echo $e(Text::sprintf('COM_SQUIDFORM_DELEGATE_REMOVE_N', $r->delegate_name, $r->user_name)); ?>"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_REMOVE'); ?></button>
									<input type="hidden" name="sf_delegation" value="<?php echo (int) $r->id; ?>">
									<?php echo $ret . HTMLHelper::_('form.token'); ?>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php else : ?>
	<p><?php echo Text::_('COM_SQUIDFORM_DELEGATES_NONE'); ?></p>
<?php endif; ?>

<?php if ($d['people'] || !empty($d['groups'])) : ?>
	<form action="<?php echo $e($d['action']); ?>" method="post" class="sf-delegate-add" id="<?php echo $p; ?>-dadd">
		<fieldset>
			<legend class="h5"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_ADD'); ?></legend>
			<div class="row g-2">
				<?php if ($d['forAnyone']) : ?>
					<div class="col-md-6">
						<label class="form-label" for="<?php echo $p; ?>-duser"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_COL_COVERS'); ?></label>
						<select class="form-select" id="<?php echo $p; ?>-duser" name="sf_user" required>
							<option value=""><?php echo Text::_('COM_SQUIDFORM_SELECT'); ?></option>
							<?php foreach ($d['forAnyone'] as $id => $label) : ?>
								<option value="<?php echo (int) $id; ?>"><?php echo $e($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
				<div class="col-md-6">
					<label class="form-label" for="<?php echo $p; ?>-ddel"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_COL_DELEGATE'); ?></label>
					<select class="form-select" id="<?php echo $p; ?>-ddel" name="sf_delegate" required aria-describedby="<?php echo $p; ?>-ddel-help">
						<option value=""><?php echo Text::_('COM_SQUIDFORM_SELECT'); ?></option>
						<optgroup label="<?php echo $e(Text::_('COM_SQUIDFORM_DELEGATE_PEOPLE')); ?>">
							<?php foreach ($d['people'] as $id => $label) : ?>
								<option value="u:<?php echo (int) $id; ?>"><?php echo $e($label); ?></option>
							<?php endforeach; ?>
						</optgroup>
						<?php if (!empty($d['groups'])) : ?>
							<optgroup label="<?php echo $e(Text::_('COM_SQUIDFORM_DELEGATE_GROUPS')); ?>">
								<?php foreach ($d['groups'] as $id => $title) : ?>
									<option value="g:<?php echo (int) $id; ?>"><?php echo $e(Text::sprintf('COM_SQUIDFORM_DELEGATE_GROUP_LABEL', $title)); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					</select>
					<div class="form-text" id="<?php echo $p; ?>-ddel-help"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_PICK_HELP'); ?></div>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="<?php echo $p; ?>-dform"><?php echo Text::_('COM_SQUIDFORM_HEADING_FORM'); ?></label>
					<select class="form-select" id="<?php echo $p; ?>-dform" name="sf_form">
						<option value="0"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_ALL_FORMS'); ?></option>
						<?php foreach ($d['forms'] as $id => $title) : ?>
							<option value="<?php echo (int) $id; ?>"><?php echo $e($title); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-sm-6 col-md-3">
					<label class="form-label" for="<?php echo $p; ?>-dstart"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_START'); ?></label>
					<input type="date" class="form-control" id="<?php echo $p; ?>-dstart" name="sf_start" aria-describedby="<?php echo $p; ?>-ddates">
				</div>
				<div class="col-sm-6 col-md-3">
					<label class="form-label" for="<?php echo $p; ?>-dend"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_END'); ?></label>
					<input type="date" class="form-control" id="<?php echo $p; ?>-dend" name="sf_end" aria-describedby="<?php echo $p; ?>-ddates">
				</div>
				<div class="col-12"><p class="form-text mt-0" id="<?php echo $p; ?>-ddates"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_DATES_HELP'); ?></p></div>
				<div class="col-12">
					<label class="form-label" for="<?php echo $p; ?>-dnote"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_NOTE'); ?></label>
					<input type="text" class="form-control" id="<?php echo $p; ?>-dnote" name="sf_note" maxlength="255">
				</div>
			</div>
			<button type="submit" class="btn btn-primary mt-2" name="task" value="approval.addDelegate"><?php echo Text::_('COM_SQUIDFORM_DELEGATE_ADD_BUTTON'); ?></button>
		</fieldset>
		<?php echo $ret . HTMLHelper::_('form.token'); ?>
	</form>
<?php endif; ?>
