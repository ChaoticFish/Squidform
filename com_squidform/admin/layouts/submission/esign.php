<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * E-signatures of a submission (0.11.0): whether each still holds, who
 * signed, when and how, its fingerprint, and the trail.
 * $displayData: item, show_ip (admin only), sign_url (site: "Sign again", or '')
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\ESign;

$item = $displayData['item'];
$sigs = ESign::forSubmission((int) $item->id);
$e    = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

if (!$sigs) {
    return;
}

$badge = ['intact' => 'success', 'changed' => 'danger', 'broken' => 'danger', 'void' => 'secondary'];
?>
<section class="card card-body mb-3 sf-esign-record" aria-labelledby="sf-esign-h">
	<h2 class="h5" id="sf-esign-h"><?php echo Text::_('COM_SQUIDFORM_ESIGN_RECORD'); ?></h2>
	<?php foreach ($sigs as $sig) :
		$rec   = json_decode((string) $sig->record_json, true) ?: [];
		$state = ESign::verify($sig, $item);
		?>
		<div class="border-bottom pb-2 mb-2">
			<p class="mb-1"><strong><?php echo $e($rec['field_label'] ?? $sig->field_key); ?></strong>
				<span class="badge text-bg-<?php echo $badge[$state]; ?>"><?php echo Text::_('COM_SQUIDFORM_ESIGN_STATE_' . strtoupper($state)); ?></span></p>
			<dl class="row small mb-1">
				<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_ESIGN_SIGNER'); ?></dt>
				<dd class="col-7"><?php echo $e(($rec['signer']['name'] ?? '') . ' (' . ($rec['signer']['username'] ?? '') . ')'); ?></dd>
				<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_ESIGN_SIGNED_AT'); ?></dt>
				<dd class="col-7"><?php echo HTMLHelper::_('date', $sig->signed_at, Text::_('DATE_FORMAT_LC2')); ?></dd>
				<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_ESIGN_METHOD'); ?></dt>
				<dd class="col-7"><?php echo Text::_('COM_SQUIDFORM_ESIGN_METHOD_' . strtoupper((string) $sig->method)); ?></dd>
				<?php if (!empty($displayData['show_ip'])) : ?>
					<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_IP'); ?></dt>
					<dd class="col-7"><?php echo $e($rec['ip'] ?? ''); ?></dd>
				<?php endif; ?>
				<?php if (!empty($sig->retain_until)) : ?>
					<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_ESIGN_RETAIN'); ?></dt>
					<dd class="col-7"><?php echo HTMLHelper::_('date', $sig->retain_until, Text::_('DATE_FORMAT_LC4'), null); ?></dd>
				<?php endif; ?>
				<?php if ((string) $sig->status === 'void') : ?>
					<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_ESIGN_VOIDED'); ?></dt>
					<dd class="col-7"><?php echo HTMLHelper::_('date', $sig->voided_at, Text::_('DATE_FORMAT_LC2')); ?><?php echo $sig->void_reason !== '' ? ' — ' . $e($sig->void_reason) : ''; ?></dd>
				<?php endif; ?>
				<dt class="col-5"><?php echo Text::_('COM_SQUIDFORM_ESIGN_FINGERPRINT'); ?></dt>
				<dd class="col-7"><span class="font-monospace small text-break"><?php echo $e($sig->record_sha256); ?></span></dd>
			</dl>
			<details class="small">
				<summary><?php echo Text::_('COM_SQUIDFORM_ESIGN_TRAIL'); ?></summary>
				<ul class="list-unstyled mb-0 mt-1">
					<?php foreach (ESign::trail((string) $sig->run) as $ev) : ?>
						<?php if ($ev->field_key !== '' && $ev->field_key !== $sig->field_key) {
							continue;
						} ?>
						<li><?php echo HTMLHelper::_('date', $ev->created, Text::_('DATE_FORMAT_LC5')); ?> — <?php echo Text::_('COM_SQUIDFORM_ESIGN_EVENT_' . strtoupper((string) $ev->event)); ?><?php echo $ev->user_name ? ' (' . $e($ev->user_name) . ')' : ''; ?><?php echo !empty($displayData['show_ip']) && $ev->ip !== '' ? ', ' . $e($ev->ip) : ''; ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
	<?php endforeach; ?>
	<?php if (!empty($displayData['sign_url'])) : ?>
		<p class="mb-0 d-print-none"><a class="btn btn-primary" href="<?php echo $e($displayData['sign_url']); ?>"><?php echo Text::_('COM_SQUIDFORM_ESIGN_SIGN_AGAIN'); ?></a></p>
	<?php endif; ?>
</section>
