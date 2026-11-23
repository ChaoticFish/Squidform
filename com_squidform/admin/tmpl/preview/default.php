<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Preview\HtmlView $this */
?>
<div class="sf-preview p-3">
	<div class="alert alert-info d-flex flex-wrap align-items-center gap-3 mb-3" role="status">
		<strong><?php echo Text::_('COM_SQUIDFORM_PREVIEW_TITLE'); ?></strong>
		<span><?php echo Text::_('COM_SQUIDFORM_PREVIEW_NOTE'); ?></span>
	</div>

	<?php if ($this->errors) : ?>
		<div class="alert alert-danger">
			<p class="fw-semibold mb-1"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_ERRORS'); ?></p>
			<ul class="mb-0"><?php foreach ($this->errors as $e) : ?><li><?php echo $this->escape($e); ?></li><?php endforeach; ?></ul>
		</div>
	<?php else : ?>
		<nav class="d-flex flex-wrap gap-3 align-items-center mb-3" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_PREVIEW_NAV')); ?>">
			<?php if (\count($this->pages) > 1) : ?>
				<ul class="nav nav-pills">
					<?php foreach ($this->pages as $i => $t) : ?>
						<li class="nav-item"><a class="nav-link<?php echo $i === $this->page ? ' active' : ''; ?>"<?php echo $i === $this->page ? ' aria-current="page"' : ''; ?> href="<?php echo $this->link($i, $this->as); ?>"><?php echo $this->escape(($i + 1) . '. ' . $t); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="btn-group" role="group" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_PREVIEW_AS')); ?>">
				<a class="btn btn-sm <?php echo $this->as === 'user' ? 'btn-dark' : 'btn-outline-dark'; ?>"<?php echo $this->as === 'user' ? ' aria-current="true"' : ''; ?> href="<?php echo $this->link($this->page, 'user'); ?>"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_AS_USER'); ?></a>
				<a class="btn btn-sm <?php echo $this->as === 'guest' ? 'btn-dark' : 'btn-outline-dark'; ?>"<?php echo $this->as === 'guest' ? ' aria-current="true"' : ''; ?> href="<?php echo $this->link($this->page, 'guest'); ?>"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_AS_GUEST'); ?></a>
			</div>
			<div class="btn-group" role="group" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_PREVIEW_LOOK')); ?>">
				<?php foreach (\SquidForm\Component\SquidForm\Administrator\Engine\Looks::ALL as $lk) : ?>
					<a class="btn btn-sm <?php echo $this->look === $lk ? 'btn-dark' : 'btn-outline-dark'; ?>"<?php echo $this->look === $lk ? ' aria-current="true"' : ''; ?> data-sf-look="<?php echo $lk; ?>" href="<?php echo $this->link($this->page, $this->as, $lk); ?>"><?php echo Text::_('COM_SQUIDFORM_LOOK_' . strtoupper($lk)); ?></a>
				<?php endforeach; ?>
			</div>
			<?php if (\count($this->langs) > 1) : ?>
				<div class="btn-group flex-wrap" role="group" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_PREVIEW_LANG')); ?>">
					<?php foreach ($this->langs as $lt) : ?>
						<a class="btn btn-sm <?php echo $this->lang === $lt ? 'btn-dark' : 'btn-outline-dark'; ?>"<?php echo $this->lang === $lt ? ' aria-current="true"' : ''; ?> data-sf-lang="<?php echo $lt; ?>" href="<?php echo $this->link($this->page, $this->as, null, $lt); ?>"><?php echo $this->escape(\SquidForm\Component\SquidForm\Administrator\Engine\Lang::packs()[$lt][0]); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="btn-group" role="group" aria-label="<?php echo $this->escape(Text::_('COM_SQUIDFORM_PREVIEW_MODE')); ?>">
				<button type="button" class="btn btn-sm btn-outline-dark" data-sf-mode="light" aria-pressed="false"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_LIGHT'); ?></button>
				<button type="button" class="btn btn-sm btn-outline-dark" data-sf-mode="dark" aria-pressed="false"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_DARK'); ?></button>
			</div>
		</nav>
		<p class="small text-body-secondary"><?php echo Text::_('COM_SQUIDFORM_PREVIEW_LOOK_NOTE'); ?></p>
<?php
// 0.15.0: see the form in light or dark without changing the admin's theme (remembered in this browser).
$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
(() => {
  const root = document.documentElement;
  const set = (m) => {
    root.setAttribute('data-bs-theme', m);
    root.setAttribute('data-color-scheme', m);
    document.querySelectorAll('[data-sf-mode]').forEach((b) => { const on = b.dataset.sfMode === m; b.setAttribute('aria-pressed', String(on)); b.classList.toggle('btn-dark', on); b.classList.toggle('btn-outline-dark', !on); });
  };
  let m = root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
  try { m = localStorage.getItem('squidform.preview.mode') || m; } catch (e) {}
  set(m);
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-sf-mode]');
    if (!b) return;
    set(b.dataset.sfMode);
    try { localStorage.setItem('squidform.preview.mode', b.dataset.sfMode); } catch (e2) {}
  });
  // 1.0.4: Escape closes Preview even after a click inside it (the key goes to this page, not the dialog).
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || window.parent === window) return;
    try { const d = window.parent.document.getElementById('sf-preview-dialog'); if (d && d.open) { e.preventDefault(); d.close(); } } catch (e3) {}
  });
})();
JS, ['name' => 'com_squidform.preview.mode']);
?>
		<?php echo $this->html; ?>
	<?php endif; ?>
</div>
