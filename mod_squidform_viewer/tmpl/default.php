<?php

/**
 * @package     SquidForm
 * @subpackage  mod_squidform_viewer
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var string $html */
/** @var \Joomla\Registry\Registry $params */

if ($html === '') {
    return;
}
?>
<div class="mod-squidform-viewer <?php echo htmlspecialchars((string) $params->get('moduleclass_sfx', ''), ENT_QUOTES, 'UTF-8'); ?>">
	<?php echo $html; ?>
</div>
