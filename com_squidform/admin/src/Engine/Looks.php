<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Looks (0.14.1): ready-made styles for a form. "simple" (called "plain" in
 * 0.14.1, still accepted) is the template's
 * own Bootstrap look (no extra CSS). The others add classes to the form's
 * box and load squidform-themes.min.css, which only styles inside it, and
 * follow the template's light or dark mode ([data-bs-theme]).
 *
 * The form's settings.look picks one; empty means the site's default
 * (Options → Saving and PDFs → Default look).
 */
final class Looks
{
    public const ALL = ['simple', 'elegant', 'chaotic', 'civic', 'friendly'];

    /** 0.14.1's name for Simple. */
    private const OLD = ['plain' => 'simple'];

    public static function normal(?string $v): ?string
    {
        $v = self::OLD[$v ?? ''] ?? $v;

        return \in_array($v, self::ALL, true) ? $v : null;
    }

    /** The site's default look. */
    public static function siteDefault(): string
    {
        return self::normal((string) ComponentHelper::getParams('com_squidform')->get('default_look', 'simple')) ?? 'simple';
    }

    /** The look a form uses: $override (preview), its own setting, or the site's default. */
    public static function of(Schema $schema, ?string $override = null): string
    {
        foreach ([$override, (string) $schema->setting('look', '')] as $v) {
            if (($n = self::normal($v)) !== null) {
                return $n;
            }
        }

        return self::siteDefault();
    }

    /** Extra classes for the form's box (with a leading space), and its CSS loaded. */
    public static function classes(string $look): string
    {
        if ($look === 'simple' || !\in_array($look, self::ALL, true)) {
            return '';
        }

        try {
            $doc = Factory::getApplication()->getDocument();

            if ($doc && method_exists($doc, 'getWebAssetManager')) {
                $wa = $doc->getWebAssetManager();
                $wa->getRegistry()->addExtensionRegistryFile('com_squidform');
                $wa->useStyle('com_squidform.themes');
            }
        } catch (\Throwable) {
            // No document (a test, the command line): the classes are enough.
        }

        return ' sf-themed sf-theme-' . $look;
    }
}
