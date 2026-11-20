<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 *
 * Print and Download PDF (0.11.0), on the admin and site submission pages.
 * $displayData: pdf_url
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-sf-print]');
  if (b) { e.preventDefault(); window.print(); }
});
JS, ['name' => 'com_squidform.print']);
?>
<div class="d-flex flex-wrap gap-2 mb-3 d-print-none sf-output">
	<button type="button" class="btn btn-outline-secondary" data-sf-print><?php echo Text::_('COM_SQUIDFORM_PRINT'); ?></button>
	<a class="btn btn-outline-secondary" href="<?php echo $e($displayData['pdf_url']); ?>"><?php echo Text::_('COM_SQUIDFORM_DOWNLOAD_PDF'); ?></a>
</div>
