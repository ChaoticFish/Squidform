<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \SquidForm\Component\SquidForm\Administrator\View\Delegates\HtmlView $this */
?>
<div class="card card-body">
	<p><?php echo Text::_('COM_SQUIDFORM_DELEGATES_HELP'); ?></p>
	<?php echo LayoutHelper::render('approval.delegates', [
		'action'    => Route::_('index.php?option=com_squidform&view=delegates', false),
		'rows'      => $this->rows,
		'people'    => $this->people,
		'groups'    => $this->groups,
		'forms'     => $this->forms,
		'forAnyone' => $this->forAnyone,
		'me'        => $this->me,
		'idp'       => 'sfdl',
	], JPATH_ADMINISTRATOR . '/components/com_squidform/layouts'); ?>
</div>
