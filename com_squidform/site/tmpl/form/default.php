<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var \SquidForm\Component\SquidForm\Site\View\Form\HtmlView $this */
?>
<div class="com-squidform">
	<?php if ($this->params->get('show_page_heading')) : ?>
		<h1><?php echo $this->escape($this->params->get('page_heading')); ?></h1>
	<?php endif; ?>
	<?php echo $this->formHtml; ?>
</div>
