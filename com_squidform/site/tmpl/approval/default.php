<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Helper\ApprovalSection;

/** @var \SquidForm\Component\SquidForm\Site\View\Approval\HtmlView $this */

$item = $this->item;
$list = $this->itemId ? Route::_('index.php?Itemid=' . $this->itemId) : Route::_('index.php?option=com_squidform&view=approvals');
$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('submit', (e) => {
  const b = e.submitter;
  if (b && b.dataset.sfConfirm && !window.confirm(b.dataset.sfConfirm)) e.preventDefault();
});
JS, ['name' => 'com_squidform.confirm']);
?>
<div class="com-squidform sf-viewer sf-viewer-detail sf-approval-detail">
	<p><a href="<?php echo $list; ?>">← <?php echo Text::_('COM_SQUIDFORM_APPROVALS_BACK'); ?></a></p>
	<h1><?php echo $this->escape(Text::sprintf('COM_SQUIDFORM_APPROVALS_REQUEST_N', \SquidForm\Component\SquidForm\Administrator\Engine\Ref::short($item->uid))); ?></h1>

	<div class="row">
		<div class="col-lg-8">
			<section class="card card-body mb-3" aria-labelledby="sfap-values-h">
				<h2 class="h4" id="sfap-values-h"><?php echo $this->escape($item->form_title); ?></h2>
				<?php echo $this->layout('values', ['item' => $item]); ?>
			</section>
		</div>
		<div class="col-lg-4">
			<?php // After a decision, back to the list for the next request. ?>
			<?php echo ApprovalSection::render($item, $this->url('view=approval&uid=' . $item->uid, false), $this->itemId ? Route::_('index.php?Itemid=' . $this->itemId, false) : Route::_('index.php?option=com_squidform&view=approvals', false), 'sfap'); ?>
			<section class="card card-body mb-3" aria-labelledby="sfap-details-h">
				<h2 class="h5" id="sfap-details-h"><?php echo Text::_('COM_SQUIDFORM_DETAILS'); ?></h2>
				<?php echo $this->layout('details', ['item' => $item, 'show_ip' => false]); ?>
			</section>
		</div>
	</div>
</div>
