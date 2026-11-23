<?php
/**
 * SquidForm hook test plugin: for tests/e2e-hooks.mjs only. Answers every
 * add-on hook, and adds a broken entry wherever SquidForm checks entries, so
 * the tests can see good ones used and broken ones left out.
 */
namespace SquidForm\Plugin\Squidform\HookTest\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\ActionInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\AbstractField;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RenderContext;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ValidateContext;
use SquidForm\Component\SquidForm\Administrator\Engine\Handler\AbstractHandler;

final class HookTest extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onSquidformActionTypes'      => 'actions',
            'onSquidformFieldTypes'       => 'fields',
            'onSquidformHandlers'         => 'handlers',
            'onSquidformPlaceholders'     => 'placeholders',
            'onSquidformOptionSources'    => 'options',
            'onSquidformLanguages'        => 'languages',
            'onSquidformStarters'         => 'starters',
            'onSquidformAddressFormats'   => 'addresses',
            'onSquidformEditor'           => 'editor',
            'onSquidformSubmissionsLinks' => 'links',
        ];
    }

    public function actions(Event $e): void
    {
        $e->getArgument('registry')['hook_echo'] = new class () implements ActionInterface {
            public function run(array $config, Context $ctx): ActionResult
            {
                return ActionResult::success(['said' => 'echo:' . (string) ($ctx->data['f_name'] ?? '')]);
            }
        };
        $e->getArgument('registry')['hook_broken'] = 'not an action';
    }

    public function fields(Event $e): void
    {
        $e->getArgument('registry')['hook_upper'] = new class () extends AbstractField {
            public function render(array $field, mixed $value, RenderContext $rc): string
            {
                $k = (string) $field['key'];
                return '<div class="mb-3 sf-field" data-sf-key="' . self::e($k) . '"><label class="form-label" for="' . self::e($rc->id($k)) . '">' . self::e($field['label'] ?? $k) . '</label>'
                    . '<input type="text" class="form-control" data-hooktest-field id="' . self::e($rc->id($k)) . '" name="' . self::e($rc->name($k)) . '" value="' . self::e((string) $value) . '"></div>';
            }

            public function normalize(array $field, mixed $raw): mixed
            {
                return \is_string($raw) ? mb_strtoupper(trim($raw)) : null;
            }

            public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
            {
                return $value === 'BAD' ? [$vc->pathPrefix . (string) $field['key'] => ($field['label'] ?? 'Upper') . ': not that word'] : [];
            }
        };
    }

    public function handlers(Event $e): void
    {
        $e->getArgument('registry')['HookShout'] = new class () extends AbstractHandler {
            public function label(): string { return 'Hook test: shout'; }
            public function description(): string { return 'Makes the name loud (tests only).'; }
            public function run(Context $ctx, array $settings): ActionResult
            {
                $ctx->data['f_name'] = mb_strtoupper((string) ($ctx->data['f_name'] ?? '')) . (string) ($settings['suffix'] ?? '!');
                return ActionResult::success();
            }
        };
        $e->getArgument('registry')['bad name!'] = 'nope';
    }

    public function placeholders(Event $e): void
    {
        $e->getArgument('registry')['hooky'] = ['title' => 'Hook test', 'items' => ['hello' => 'A greeting'], 'value' => static fn (string $n, array $ctx): ?string => $n === 'hello' ? 'Hello from a hook <b>bold</b>' : null];
        $e->getArgument('registry')['data'] = ['title' => 'Steals a built-in', 'value' => static fn () => 'stolen'];
    }

    public function options(Event $e): void
    {
        $e->getArgument('registry')['hooktest.colours'] = ['title' => 'Hook colours', 'options' => static fn (): array => [['value' => 'teal', 'label' => 'Teal'], ['value' => 'coral', 'label' => 'Coral']]];
        $e->getArgument('registry')['hooktest.broken'] = ['title' => 'Broken', 'options' => 'not callable'];
    }

    public function languages(Event $e): void
    {
        $e->getArgument('registry')['it-IT'] = ['name' => 'Italian', 'own' => 'Italiano', 'html' => 'it', 'path' => \dirname(__DIR__, 2) . '/language'];
        $e->getArgument('registry')['xx'] = ['name' => 'Broken tag'];
    }

    public function starters(Event $e): void
    {
        $e->getArgument('registry')['hooktest-starter'] = ['title' => 'Hook test starter', 'description' => 'From the hook test plugin.', 'json' => json_encode(['schema' => 1, 'settings' => [], 'pages' => [['key' => 'p1', 'title' => 'One', 'fields' => [['key' => 'f_hooked', 'type' => 'text', 'label' => 'Hooked field']], 'events' => ['load' => [], 'submit' => []]]]])];
        $e->getArgument('registry')['hooktest-broken'] = ['title' => 'Broken starter', 'json' => '{not json'];
    }

    public function addresses(Event $e): void
    {
        $e->getArgument('registry')['mx'] = [
            'name' => 'México',
            'rows' => [
                [['part' => 'street', 'label' => 'Calle y número', 'autocomplete' => 'address-line1', 'required' => true, 'width' => 12]],
                [['part' => 'zip', 'label' => 'Código postal', 'autocomplete' => 'postal-code', 'pattern' => '^\d{5}$', 'width' => 4], ['part' => 'city', 'label' => 'Ciudad', 'autocomplete' => 'address-level2', 'required' => true, 'width' => 8]],
            ],
            'display' => "{street}\n{zip} {city}",
        ];
        $e->getArgument('registry')['zz'] = ['name' => '<script>', 'rows' => 'nope'];
    }

    public function editor(Event $e): void
    {
        $name = (string) $e->getArgument('name');
        $id   = (string) $e->getArgument('id');
        $e->getArgument('result')['html'] = '<textarea data-hooktest-editor class="form-control" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '" rows="4">' . htmlspecialchars((string) $e->getArgument('value')) . '</textarea>';
    }

    public function links(Event $e): void
    {
        $e->getArgument('links')[] = ['label' => 'Hook results', 'url' => 'index.php?option=com_squidform&view=activity', 'icon' => 'chart'];
        $e->getArgument('links')[] = ['label' => 'Evil link', 'url' => 'https://evil.example/', 'icon' => 'chart'];
    }
}
