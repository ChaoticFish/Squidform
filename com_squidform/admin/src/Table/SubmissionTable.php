<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

\defined('_JEXEC') or die;

/**
 * Row in #__squidform_submissions. Writes go through Engine\SubmissionStore;
 * this table is used by the admin list for loading and soft delete.
 */
class SubmissionTable extends Table
{
    public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct('#__squidform_submissions', 'id', $db, $dispatcher);
    }
}
