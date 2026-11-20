<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;

/** @var \SquidForm\Component\SquidForm\Site\View\Submissions\HtmlView $this */

echo LayoutHelper::render('viewer.list', [
	'ctx'        => $this->viewer,
	'items'      => $this->items,
	'pagination' => $this->pagination,
	'state'      => $this->state,
	'canExport'  => $this->canExport,
	'canDelete'  => $this->canDelete,
	'scope'      => $this->scope,
	'heading'    => $this->params->get('show_page_heading') ? $this->params->get('page_heading') : $this->viewer['form']['title'],
	'headingTag' => 'h1',
], JPATH_SITE . '/components/com_squidform/layouts');
