<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * 0.13.1 add-on hook: a field type or action from an add-on can also tell the
 * builder how to show it. Optional: without it the builder lists the type
 * under "Add-ons" by its name and edits its settings as JSON.
 *
 * builderInfo() returns:
 *   label     what the builder calls it ("Net Promoter score")
 *   icon      a short text or emoji for the palette
 *   group     the palette group (field types; default "Add-ons")
 *   defaults  settings a new one starts with (["max" => 10])
 */
interface BuilderInfoInterface
{
    /** @return array{label?: string, icon?: string, group?: string, defaults?: array<string, mixed>} */
    public function builderInfo(): array;
}
