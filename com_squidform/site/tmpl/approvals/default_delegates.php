<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;

/** @var \SquidForm\Component\SquidForm\Site\View\Approvals\HtmlView $this */

echo LayoutHelper::render('approval.delegates', [
	'action'      => $this->url('view=approvals', false),
	'rows'        => $this->delegations,
	'people'      => $this->delegateChoices,
	'groups'      => $this->groupChoices,
	'forms'       => $this->formChoices,
	'forAnyone'   => [],
	'me'          => (int) \Joomla\CMS\Factory::getApplication()->getIdentity()->id,
	'idp'         => 'sfap',
], JPATH_ADMINISTRATOR . '/components/com_squidform/layouts');
