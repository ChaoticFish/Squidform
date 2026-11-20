<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Table;

use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Access\Rules;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

\defined('_JEXEC') or die;

/**
 * Row in #__squidform_forms. The definition itself lives in
 * #__squidform_form_versions; current_version_id points at the live one.
 */
class FormTable extends Table
{
    public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
    {
        $this->_supportNullValue = true;
        parent::__construct('#__squidform_forms', 'id', $db, $dispatcher);
        $this->setColumnAlias('published', 'state');

        // Per-form permissions (0.3.0): each form has an asset row
        // com_squidform.form.<id> whose parent is the component's asset, so
        // anything not set on the form inherits the component settings.
        $this->_trackAssets = true;
    }

    protected function _getAssetName()
    {
        return 'com_squidform.form.' . (int) $this->id;
    }

    protected function _getAssetTitle()
    {
        return (string) $this->title;
    }

    protected function _getAssetParentId(?Table $table = null, $id = null)
    {
        $db = $this->getDatabase();
        $q  = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_squidform'));

        return (int) $db->setQuery($q)->loadResult() ?: parent::_getAssetParentId($table, $id);
    }

    public function bind($array, $ignore = '')
    {
        if (isset($array['rules']) && \is_array($array['rules'])) {
            $this->setRules(new Rules($array['rules']));
        }

        return parent::bind($array, $ignore);
    }

    public function check(): bool
    {
        try {
            parent::check();
        } catch (\Exception $e) {
            $this->setError($e->getMessage());

            return false;
        }

        $this->title = trim((string) $this->title);

        if ($this->title === '') {
            $this->setError(Text::_('COM_SQUIDFORM_ERR_TITLE_REQUIRED'));

            return false;
        }

        // ASCII only (shortcodes, menu links and the submit handler use a-z0-9-_),
        // and never all digits (would look like an id).
        $this->alias = OutputFilter::stringURLSafe(trim((string) $this->alias) !== '' ? (string) $this->alias : (string) $this->title);

        if ($this->alias === '') {
            $this->alias = 'form-' . Factory::getDate()->format('YmdHis');
        } elseif (ctype_digit($this->alias)) {
            $this->alias = 'form-' . $this->alias;
        }

        // Alias must be unique: it is how shortcodes and menu items find the form.
        $db  = $this->getDatabase();
        $id  = (int) $this->id;
        $q   = $db->createQuery()
            ->select('id')
            ->from($db->quoteName('#__squidform_forms'))
            ->where($db->quoteName('alias') . ' = :alias')
            ->where($db->quoteName('id') . ' <> :id')
            ->bind(':alias', $this->alias)
            ->bind(':id', $id, ParameterType::INTEGER);

        if ($db->setQuery($q)->loadResult()) {
            $this->setError(Text::sprintf('COM_SQUIDFORM_ERR_ALIAS_TAKEN', $this->alias));

            return false;
        }

        return true;
    }

    public function store($updateNulls = true): bool
    {
        $now  = Factory::getDate()->toSql();
        $user = Factory::getApplication()->getIdentity();

        if (!(int) $this->id) {
            $this->created    = $now;
            $this->created_by = (int) $user?->id;
        }

        $this->modified    = $now;
        $this->modified_by = (int) $user?->id;

        return parent::store($updateNulls);
    }
}
