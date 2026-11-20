<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;
use SquidForm\Component\SquidForm\Administrator\Engine\ViewerConfig;

\defined('_JEXEC') or die;

/**
 * The form a viewer setting belongs to: the menu item's form ("form_id" in
 * the menu link), or, on the SquidForm Viewer module, the form of the viewer
 * menu item it points at. Joomla can't refresh a field when another changes,
 * so the item must be saved once after choosing.
 */
trait ViewerFormTrait
{
    /** Module and menu edit screens don't load SquidForm's strings by themselves. */
    protected function viewerLanguage(): void
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_squidform', JPATH_ADMINISTRATOR . '/components/com_squidform') || $lang->load('com_squidform', JPATH_ADMINISTRATOR);
    }

    protected function viewerFormId(): int
    {
        $id = (int) $this->form->getValue('form_id', 'request');

        if ($id) {
            return $id;
        }

        $item = (int) $this->form->getValue('viewer_item', 'params');

        if (!$item) {
            return 0;
        }

        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $link = (string) $db->setQuery('SELECT link FROM ' . $db->quoteName('#__menu') . ' WHERE id = ' . $item)->loadResult();
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return (int) ($q['form_id'] ?? 0);
    }

    protected function viewerSchema(): ?Schema
    {
        $id = $this->viewerFormId();

        try {
            $loaded = $id ? FormRepository::load($id, null, false) : null;
        } catch (\Throwable) {
            $loaded = null;
        }

        return $loaded['schema'] ?? null;
    }

    /**
     * Choices for columns, filters or sort, in form order with ID first.
     *
     * @return array<string, string> name => label
     */
    protected function viewerChoices(string $mode): array
    {
        $this->viewerLanguage();
        $schema = $this->viewerSchema();

        if (!$schema) {
            return [];
        }

        $meta  = $mode === 'filters' ? ViewerConfig::META_FILTERS : ViewerConfig::META;
        $types = $mode === 'filters' ? ViewerConfig::FILTER_TYPES : ($mode === 'sort' ? ViewerConfig::SORT_TYPES : ViewerConfig::COLUMN_TYPES);
        $out   = [];

        if (\in_array('uid', $meta, true)) {
            $out['uid'] = Text::_('COM_SQUIDFORM_VIEWER_COL_UID');
        }

        if (\in_array('id', $meta, true)) {
            $out['id'] = Text::_('COM_SQUIDFORM_VIEWER_COL_ID');
        }

        foreach ($schema->pages() as $i => $p) {
            foreach ($schema->pageFields($i) as $f) {
                if (\in_array($f['type'], $types, true)) {
                    $out[$f['key']] = (string) ($f['label'] ?? $f['key']);
                }
            }
        }

        foreach ($meta as $m) {
            if (!\in_array($m, ['id', 'uid'], true)) {
                $out[$m] = Text::_('COM_SQUIDFORM_VIEWER_COL_' . strtoupper($m));
            }
        }

        return $out;
    }
}
