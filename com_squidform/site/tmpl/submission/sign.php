<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Sign again (0.11.0): an edit voided this person's e-signature. They see
 * the answers as they are now, then sign them.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\ESign;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RenderContext;
use SquidForm\Component\SquidForm\Administrator\Engine\FieldRegistry;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

/** @var \SquidForm\Component\SquidForm\Site\View\Submission\HtmlView $this */

$item  = $this->item;
$field = ESign::canResign($item);
$user  = Factory::getApplication()->getIdentity();
$ph    = new Placeholders($item->schema, $user, (array) $item->data);
$rc    = new RenderContext($item->schema, 'resign', $ph, [], []);
$field['rules'] = ['required' => true] + (array) ($field['rules'] ?? []);
?>
<div class="com-squidform sf-viewer sf-viewer-sign">
	<p><a href="<?php echo Viewer::url('view=submission&uid=' . $item->uid); ?>">← <?php echo Text::_('COM_SQUIDFORM_VIEWER_BACK'); ?></a></p>
	<h1><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_ESIGN_SIGN_AGAIN_N', Ref::short($item->uid))); ?></h1>
	<div class="alert alert-info"><?php echo Text::_('COM_SQUIDFORM_ESIGN_SIGN_AGAIN_INTRO'); ?></div>
	<section class="card card-body mb-3" aria-labelledby="sfv-values-h">
		<h2 class="h4" id="sfv-values-h"><?php echo $this->escape($item->form_title); ?></h2>
		<?php echo $this->layout('values', ['item' => $item]); ?>
	</section>
	<form class="sf-form squidform" method="post" action="<?php echo Viewer::url('view=submission&uid=' . $item->uid); ?>">
		<?php echo FieldRegistry::get('signature')->render($field, null, $rc); ?>
		<div class="sf-actions d-flex gap-2 mt-3">
			<button type="submit" class="btn btn-primary" name="task" value="submission.resign"><?php echo Text::_('COM_SQUIDFORM_ESIGN_SIGN'); ?></button>
		</div>
		<input type="hidden" name="uid" value="<?php echo $this->escape($item->uid); ?>">
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</div>
