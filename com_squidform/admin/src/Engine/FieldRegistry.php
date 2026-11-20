<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ChoiceField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\FieldTypeInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\EditorField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\HtmlField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RatingField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\SectionField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RepeaterField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\TextField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\UserField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\SignatureField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\GridField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\LookupField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\NameField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\AddressField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\UploadField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\DividerField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RankingField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\SliderField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\DateRangeField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\PhoneField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ConsentField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ImageChoiceField;

\defined('_JEXEC') or die;

/**
 * Maps field type names to implementations.
 *
 * Plugins in the "squidform" group can add types by listening to
 * `onSquidformFieldTypes` and adding to the `registry` argument:
 *
 *     $event->getArgument('registry')['rating'] = new RatingField();
 */
final class FieldRegistry
{
    /** @var array<string, FieldTypeInterface>|null */
    private static ?array $types = null;

    /** @return array<string, FieldTypeInterface> */
    public static function all(): array
    {
        if (self::$types === null) {
            $text   = new TextField();
            $choice = new ChoiceField();
            $types  = [
                'text'       => $text,
                'textarea'   => $text,
                'email'      => $text,
                'number'     => $text,
                'tel'        => $text,
                'url'        => $text,
                'date'       => $text,
                'hidden'     => $text,
                'select'     => $choice,
                'radios'     => $choice,
                'checkboxes' => $choice,
                'checkbox'   => $choice,
                'user'       => new UserField(),
                'repeater'   => new RepeaterField(),
                'html'       => new HtmlField(),
                // 0.8.0
                'section'     => new SectionField(),
                'multiselect' => $choice,
                'time'        => $text,
                'datetime'    => $text,
                'rating'      => new RatingField(),
                'editor'      => new EditorField(),
                // 0.8.1
                'signature'   => new SignatureField(),
                'grid'        => new GridField(),
                'lookup'      => new LookupField(),
                'name'        => new NameField(),
                'address'     => new AddressField(),
                // 0.9.0
                'upload'      => new UploadField(),
                // 0.9.1 (section_end is internal: where a section's fields stop)
                'divider'     => new DividerField(),
                'section_end' => new SectionField(),
                // 0.9.2
                'ranking'     => new RankingField(),
                'slider'      => new SliderField(),
                'calc'        => new Field\CalcField(),
                'daterange'   => new DateRangeField(),
                'phone'       => new PhoneField(),
                'consent'     => new ConsentField(),
                'imagechoice' => new ImageChoiceField(),
            ];

            $registry = new \ArrayObject();

            try {
                PluginHelper::importPlugin('squidform');
                Factory::getApplication()->getDispatcher()->dispatch(
                    'onSquidformFieldTypes',
                    new Event('onSquidformFieldTypes', ['registry' => $registry])
                );
            } catch (\Throwable) {
                // No application (CLI tests) or no plugins: built-ins only.
            }

            foreach ($registry as $name => $impl) {
                if (\is_string($name) && $impl instanceof FieldTypeInterface && !isset($types[$name])) {
                    $types[$name] = $impl;
                }
            }

            self::$types = $types;
        }

        return self::$types;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function get(string $type): FieldTypeInterface
    {
        return self::all()[$type] ?? throw new \InvalidArgumentException('Unknown field type: ' . $type);
    }
}
