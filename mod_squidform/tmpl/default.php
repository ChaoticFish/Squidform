<?php

/**
 * @package     SquidForm
 * @subpackage  mod_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var string $formHtml */
/** @var \Joomla\Registry\Registry $params */

if ($formHtml === '') {
    return;
}
?>
<div class="mod-squidform <?php echo htmlspecialchars((string) $params->get('moduleclass_sfx', ''), ENT_QUOTES, 'UTF-8'); ?>">
	<?php echo $formHtml; ?>
</div>
