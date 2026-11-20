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

/** @var \SquidForm\Component\SquidForm\Administrator\View\Form\HtmlView $this */

$this->getDocument()->getWebAssetManager()->useScript('keepalive')->useScript('form.validate')
	->getRegistry()->addExtensionRegistryFile('com_squidform');
$this->getDocument()->getWebAssetManager()->useScript('com_squidform.definition')
	->useStyle('com_squidform.builder')->useScript('com_squidform.builder')
	->addInlineStyle('.sf-def-nowrap{overflow-x:auto;white-space:pre;}.sf-preview-dialog{border:0;border-radius:.5rem;height:90vh;max-width:70rem;padding:0;width:95vw;}.sf-preview-dialog iframe{border:0;height:calc(90vh - 3.5rem);width:100%;}.sf-preview-dialog::backdrop{background:rgb(0 0 0/.45);}.sf-discard summary{display:inline-block;list-style:none;}');
$errorAt = $this->errorAt;
?>
<form action="<?php echo Route::_('index.php?option=com_squidform&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" name="adminForm" id="form-form" aria-label="<?php echo Text::_(empty($this->item->id) ? 'COM_SQUIDFORM_FORM_NEW' : 'COM_SQUIDFORM_FORM_EDIT'); ?>" class="form-validate">

	<div class="row title-alias form-vertical mb-3">
		<div class="col-12 col-md-6"><?php echo $this->form->renderField('title'); ?></div>
		<div class="col-12 col-md-6"><?php echo $this->form->renderField('alias'); ?></div>
	</div>

	<div class="main-card">
		<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'definition', 'recall' => true, 'breakpoint' => 768]); ?>

		<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'definition', Text::_('COM_SQUIDFORM_TAB_DEFINITION')); ?>
		<?php if ($this->draft) : ?>
			<div class="alert alert-info d-flex flex-wrap align-items-center gap-3 sf-draft-bar" role="status">
				<span><?php echo Text::sprintf('COM_SQUIDFORM_DRAFT_BANNER', $this->escape($this->draft->at), $this->escape($this->draft->by), (int) ($this->item->version_no ?? 0)); ?>
					<?php if ((int) $this->draft->base_version_no < (int) ($this->item->version_no ?? 0)) : ?>
						<strong><?php echo Text::sprintf('COM_SQUIDFORM_DRAFT_OUTDATED', (int) $this->item->version_no); ?></strong>
					<?php endif; ?>
				</span>
				<details class="sf-discard">
					<summary class="btn btn-sm btn-danger"><?php echo Text::_('COM_SQUIDFORM_DRAFT_DISCARD'); ?></summary>
					<span class="ms-2 small"><?php echo Text::_('COM_SQUIDFORM_DRAFT_DISCARD_SURE'); ?></span>
					<button type="button" class="btn btn-sm btn-danger ms-1" id="sf-discard-yes" onclick="Joomla.submitform('form.discardDraft', document.getElementById('form-form'))"><?php echo Text::_('COM_SQUIDFORM_DRAFT_DISCARD_YES'); ?></button>
				</details>
			</div>
		<?php endif; ?>
		<div id="sf-builder-root" hidden></div>
		<?php
		// Image choice's picker (0.10.0): Joomla's own Media Manager field, copied
		// by the builder for each picture (choose or upload an image there).
		try {
			$sfMedia = \Joomla\CMS\Form\Form::getInstance('com_squidform.mediapicker', '<form><field name="sfb_media" type="media" types="images" preview="false" label="Image" /></form>')->getInput('sfb_media');
		} catch (\Throwable $e) {
			$sfMedia = '';
		}
		?>
		<template id="sfb-media-proto"><?php echo $sfMedia; ?></template>
		<noscript><p class="alert alert-warning"><?php echo Text::_('COM_SQUIDFORM_BUILDER_NOSCRIPT'); ?></p></noscript>
		<div id="sf-json-pane">
			<?php echo $this->form->renderField('schema_json'); ?>
			<div class="control-group"><div class="control-label"></div><div class="controls d-flex flex-wrap align-items-center gap-2">
				<?php if ($errorAt) : ?>
					<button type="button" class="btn btn-sm btn-warning" id="sf-def-goto" data-line="<?php echo (int) $errorAt['line']; ?>" data-column="<?php echo (int) ($errorAt['column'] ?? 1); ?>">
						<?php echo Text::sprintf('COM_SQUIDFORM_DEF_GOTO_ERROR', (int) $errorAt['line']); ?>
					</button>
				<?php endif; ?>
				<span class="small text-body-secondary" id="sf-def-pos" data-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_DEF_POSITION')); ?>"></span>
			</div></div>
		</div>
		<div class="row mt-2">
			<div class="col-lg-8">
				<?php echo $this->form->renderField('version_note'); ?>
			</div>
			<div class="col-lg-4">
				<div class="card card-body">
					<?php if (!empty($this->item->alias)) : ?>
						<p class="small mb-1"><?php echo Text::_('COM_SQUIDFORM_HELP_SHORTCODE'); ?></p>
						<p><code>{squidform <?php echo $this->escape($this->item->alias); ?>}</code></p>
					<?php endif; ?>
					<?php if (!empty($this->item->version_no)) : ?>
						<p class="small mb-1"><?php echo Text::sprintf('COM_SQUIDFORM_HELP_VERSION', (int) $this->item->version_no); ?></p>
					<?php endif; ?>
					<p class="small mb-0"><?php echo Text::_('COM_SQUIDFORM_HELP_BUILDER'); ?></p>
				</div>
			</div>
		</div>
		<?php echo HTMLHelper::_('uitab.endTab'); ?>

		<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'details', Text::_('COM_SQUIDFORM_TAB_DETAILS')); ?>
		<div class="row">
			<div class="col-lg-8">
				<?php echo $this->form->renderField('description'); ?>
				<?php echo $this->form->renderField('id'); ?>
			</div>
			<div class="col-lg-4"><?php echo $this->form->renderFieldset('publishing'); ?></div>
		</div>
		<?php echo HTMLHelper::_('uitab.endTab'); ?>

		<?php if (\Joomla\CMS\Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_squidform')) : ?>
			<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'permissions', Text::_('JCONFIG_PERMISSIONS_LABEL')); ?>
			<fieldset id="fieldset-rules" class="options-form">
				<legend><?php echo Text::_('JCONFIG_PERMISSIONS_LABEL'); ?></legend>
				<p class="small"><?php echo Text::_('COM_SQUIDFORM_FORM_PERMISSIONS_HELP'); ?></p>
				<?php echo $this->form->getInput('rules'); ?>
			</fieldset>
			<?php echo HTMLHelper::_('uitab.endTab'); ?>
		<?php endif; ?>

		<?php echo HTMLHelper::_('uitab.endTabSet'); ?>
	</div>

	<?php echo $this->form->getInput('asset_id'); ?>
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

<?php // Preview (0.7.0): posts what's on screen to the preview view, shown in a dialog. Outside the main form. ?>
<form id="sf-preview-post" method="post" action="<?php echo Route::_('index.php?option=com_squidform&task=form.preview'); ?>" target="sf-preview-frame" hidden>
	<input type="hidden" name="id" value="<?php echo (int) ($this->item->id ?? 0); ?>">
	<input type="hidden" name="json" value="">
	<input type="hidden" name="page" value="0">
	<input type="hidden" name="title" value="">
	<input type="hidden" name="alias" value="">
	<input type="hidden" name="as" value="user">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
<dialog id="sf-preview-dialog" class="sf-preview-dialog" aria-labelledby="sf-preview-heading">
	<div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
		<h2 class="h5 m-0" id="sf-preview-heading"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_TITLE'); ?></h2>
		<button type="button" class="btn btn-sm btn-secondary" onclick="this.closest('dialog').close()"><?php echo Text::_('JTOOLBAR_CLOSE'); ?></button>
	</div>
	<iframe name="sf-preview-frame" title="<?php echo $this->escape(Text::_('COM_SQUIDFORM_PREVIEW_TITLE')); ?>"></iframe>
</dialog>
