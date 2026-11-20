<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;

\defined('_JEXEC') or die;

class DisplayController extends BaseController
{
    protected $default_view = 'forms';

    public function display($cachable = false, $urlparams = [])
    {
        $view = $this->input->get('view', $this->default_view);
        $id   = $this->input->getInt('id');

        // Edit screens must be opened through the controller (check-out, access).
        if ($view === 'form' && $this->input->get('layout') === 'edit' && !$this->checkEditId('com_squidform.edit.form', $id)) {
            $this->setMessage(\Joomla\CMS\Language\Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 'error');
            $this->setRedirect('index.php?option=com_squidform&view=forms');

            return false;
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.manage', 'com_squidform')) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Submitted data (personal details, IP addresses, history) needs its own
        // permission: managing forms does not by itself allow reading submissions.
        // Per form since 0.3.0: the user needs the permission on at least one form.
        // Approvers reach the requests waiting for them without View Submissions.
        if (\in_array($view, ['submissions', 'submission'], true) && !Acl::forms('squidform.view', $user) && !Acl::forms('squidform.approve', $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_ERR_NO_VIEW_PERMISSION'), 403);
        }

        // Import submissions (CSV) (0.12.0)
        if ($view === 'csvimport' && !Acl::forms('squidform.edit', $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Import from ChronoForms (0.12.0)
        if ($view === 'chronoforms' && !$user->authorise('core.create', 'com_squidform')) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Activity (0.13.0): the trail of forms the person may view submissions of.
        if ($view === 'activity' && !Acl::forms('squidform.view', $user) && !$user->authorise('core.admin', 'com_squidform')) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_ERR_NO_VIEW_PERMISSION'), 403);
        }

        // Saved for later (0.11.1): drafts of forms the person may view submissions of.
        if ($view === 'resumes' && !Acl::forms('squidform.view', $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_ERR_NO_VIEW_PERMISSION'), 403);
        }

        if ($view === 'delegates' && !\SquidForm\Component\SquidForm\Administrator\Engine\Delegates::canManage((int) $user->id, $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($view === 'purge' && !Acl::forms('squidform.delete.hard', $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Data tables (0.14.0): raw tables of every form, for Admin on SquidForm only.
        if ($view === 'datatables' && !$user->authorise('core.admin', 'com_squidform')) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // "Check this server" (0.9.0) is for the people who can change the Options.
        if ($view === 'servercheck' && !$user->authorise('core.admin', 'com_squidform')) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($view === 'export' && !Acl::forms('squidform.export', $user)) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_ERR_NO_EXPORT_PERMISSION'), 403);
        }

        return parent::display($cachable, $urlparams);
    }
}
