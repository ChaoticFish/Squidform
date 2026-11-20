<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Engine\FileDownload;

\defined('_JEXEC') or die;

/** Uploaded files (0.9.0): task=file.download&uid=… */
class FileController extends BaseController
{
    public function download(): void
    {
        FileDownload::send($this->app, $this->input->getCmd('uid', ''));
    }
}
