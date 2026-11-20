<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Controller\SubmissionTasksTrait;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Front-end viewer tasks: submission.edit / save / cancel / options /
 * delete / restore. Only for the form of the current viewer menu item.
 */
class SubmissionController extends BaseController
{
    use SubmissionTasksTrait;

    public function execute($task)
    {
        Renderer::loadLanguage();

        if (!Viewer::current()) {
            throw new \Exception('Not found', 404);
        }

        return parent::execute($task);
    }

    protected function uidOnly(): bool
    {
        return true;
    }

    protected function formAllowedHere(int $formId): bool
    {
        return (Viewer::current()['formId'] ?? 0) === $formId;
    }

    protected function detailUrl(int $id): string
    {
        return Viewer::url('view=submission&uid=' . Ref::uidOf($id), false);
    }

    protected function signUrl(int $id): string
    {
        return Viewer::url('view=submission&layout=sign&uid=' . Ref::uidOf($id), false);
    }

    protected function editUrl(int $id): string
    {
        return Viewer::url('view=submission&layout=change&uid=' . Ref::uidOf($id), false);
    }

    protected function listUrl(): string
    {
        // Lists shown by a module send people back to the page they were on.
        return Viewer::returnUrl() ?? Viewer::listUrl(false);
    }
}
