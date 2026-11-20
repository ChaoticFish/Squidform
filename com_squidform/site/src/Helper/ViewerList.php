<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Helper;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Display;
use SquidForm\Component\SquidForm\Administrator\Engine\ViewerConfig;

\defined('_JEXEC') or die;

/**
 * Cell text, headings and filter options for the viewer list (menu item and module).
 */
final class ViewerList
{
    public static function cell(array $col, object $row): string
    {
        if ($col['kind'] === 'field') {
            return Display::value($col['field'], $row->data[$col['name']] ?? null);
        }

        return match ($col['name']) {
            'uid'          => \SquidForm\Component\SquidForm\Administrator\Engine\Ref::short((string) $row->uid),
            'id'           => (string) (int) $row->id,
            'created'      => HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC5')),
            'modified'     => HTMLHelper::_('date', $row->modified, Text::_('DATE_FORMAT_LC5')),
            'status'       => ApprovalFlow::statusText((string) $row->status, (string) ($row->current_step ?? ''), FormRepository::version((int) $row->version_id)),
            'submitted_by' => (int) $row->user_id ? (string) $row->user_name : Text::_('COM_SQUIDFORM_GUEST'),
            default        => '',
        };
    }

    /** Column heading: the custom heading if one is set, else the field label or detail name. */
    public static function label(array $col): string
    {
        if (($col['heading'] ?? '') !== '') {
            return (string) $col['heading'];
        }

        return $col['kind'] === 'field' ? (string) ($col['field']['label'] ?? $col['name']) : Text::_('COM_SQUIDFORM_VIEWER_COL_' . strtoupper($col['name']));
    }

    /** @return array<string, string> value => label for a field filter */
    public static function filterOptions(array $f): array
    {
        if (($f['field']['type'] ?? '') === 'checkbox') {
            return ['1' => Text::_('JYES'), '0' => Text::_('JNO')];
        }

        return Display::map($f['field']);
    }

    public static function sortable(array $col): bool
    {
        return $col['kind'] === 'meta'
            ? \in_array($col['name'], ['id', 'created', 'modified', 'status'], true)
            : \in_array($col['field']['type'], ViewerConfig::SORT_TYPES, true);
    }

    /** $url with extra query parameters. */
    public static function withQuery(string $url, array $add): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . http_build_query($add, '', '&');
    }

    /** Hidden inputs that keep a GET form on the page's own URL. @return array<string, string> */
    public static function pageQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return array_filter($q, 'is_string');
    }
}
