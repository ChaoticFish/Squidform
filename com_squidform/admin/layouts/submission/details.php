<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * $displayData: item, show_ip (bool; admin only — never on the front end)
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$item = $displayData['item'];
$e    = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$mod  = (string) $item->modified !== (string) $item->created;
?>
<dl class="row small mb-0 sf-details">
	<dt class="col-5"><?php echo Text::_('JGRID_HEADING_ID'); ?></dt><dd class="col-7"><?php echo (int) $item->id; ?></dd>
	<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_HEADING_STATUS'); ?></dt><dd class="col-7"><?php echo $e(\SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow::statusText((string) $item->status, (string) ($item->current_step ?? ''), $item->schema ?? null)); ?><?php echo $item->archived ? ' (' . Text::_('COM_SQUIDFORM_ARCHIVED') . ')' : ''; ?></dd>
	<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_HEADING_CREATED'); ?></dt><dd class="col-7"><?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC5')); ?></dd>
	<?php if ($mod) : ?>
		<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_EXPORT_COL_MODIFIED'); ?></dt><dd class="col-7"><?php echo HTMLHelper::_('date', $item->modified, Text::_('DATE_FORMAT_LC5')); ?><?php echo !empty($item->modified_by_name) ? ' — ' . $e($item->modified_by_name) : ''; ?></dd>
	<?php endif; ?>
	<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_HEADING_USER'); ?></dt><dd class="col-7"><?php echo $item->user_id ? $e($item->user_name) : Text::_('COM_SQUIDFORM_GUEST'); ?></dd>
	<?php if (!empty($displayData['show_ip'])) : ?>
		<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_IP'); ?></dt><dd class="col-7"><?php echo $e($item->ip); ?></dd>
	<?php endif; ?>
	<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_HEADING_VERSION'); ?></dt><dd class="col-7"><?php echo (int) $item->version_no; ?></dd>
	<dt class="col-5">UID</dt><dd class="col-7"><span class="font-monospace small text-break"><?php echo $e($item->uid); ?></span></dd>
</dl>
