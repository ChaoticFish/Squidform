<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

\defined('_JEXEC') or die;

/**
 * Replaces {namespace:name} placeholders. Output is HTML-escaped by default;
 * use {data.raw:field} to insert a value unescaped.
 *
 *   {data:KEY}         a field value (lists joined with ", ")
 *   {data.label:KEY}   the option label instead of the stored value
 *   {data.raw:KEY}     value with safe HTML kept (filtered: no scripts/handlers)
 *   {data:_all}        every field as a label/value table (HTML) or list (text)
 *   {var:NAME}         a variable (Variables action)
 *   {action:KEY}       an earlier action's output
 *   {action:KEY.COL}   a column of the row a Read data action found (0.10.0);
 *                      {action:KEY.count} how many rows it found
 *   {url:NAME}         a value from the page's web address, ?NAME=… (0.10.0)
 *   {user:id|name|username|email}
 *   {user.field:NAME}  a Joomla custom user field (raw value)
 *   {date:FORMAT}      now, in the user's time zone (PHP date format, default Y-m-d)
 *   {setting:NAME}     a form setting
 *   {submission:id|uid|ref}   ref = the short reference people see (first 8 characters of uid)
 *   {form:id|title|alias}
 *   {site:name|url}
 *   {approval:decision|step|step_title|comment|by|by_email}   (approval hooks)
 *   {submitter:id|name|username|email}   the account that submitted (hooks)
 *
 * Unknown placeholders become an empty string. Nothing here evaluates code.
 */
final class Placeholders
{
    private const PATTERN = '/\{([a-z]+)(?:\.(raw|label|field))?:([A-Za-z0-9_\-\.\/ :,]*)\}/';

    /** @var array<string, mixed> the web address's query of the page the form is on (0.10.0) */
    private static array $query = [];

    /** @var array<string, array<string, mixed>> per-user custom-field cache */
    private static array $userFields = [];

    /** Built-in namespaces; an add-on can't take one of these (0.14.0). */
    public const BUILT_IN = ['data', 'var', 'action', 'url', 'user', 'date', 'setting', 'submission', 'form', 'approval', 'submitter', 'site', 'js', 'handler', 'session'];

    /** @var array<string, array{title: string, items: array<string, string>, value: callable}>|null add-on placeholders */
    private static ?array $addons = null;

    /**
     * 0.14.0: true while filling text shown on the form's own page. Only then
     * does {js:NAME} stay as written, for the form's own JavaScript to fill in
     * the browser; everywhere else (emails, PDFs, messages) it's empty.
     */
    public bool $onPage = false;

    /**
     * @param   array<string, mixed>  $data
     * @param   array<string, mixed>  $vars
     * @param   array<string, mixed>  $outputs
     * @param   array<string, mixed>  $form
     */
    public function __construct(
        private readonly ?Schema $schema,
        private readonly User $user,
        private readonly array $data = [],
        private readonly array $vars = [],
        private readonly array $outputs = [],
        private readonly array $form = [],
        private readonly ?int $submissionId = null,
        private readonly ?string $submissionUid = null,
        private readonly array $meta = [],
    ) {
    }

    /** The page's web address query, for {url:NAME} and "Can be filled from the web address". */
    public static function setQuery(array $query): void
    {
        self::$query = $query;
    }

    public static function query(string $name): mixed
    {
        return self::$query[$name] ?? null;
    }

    public static function forContext(Context $ctx, ?Schema $schema = null): self
    {
        return new self($schema ?? $ctx->schema, $ctx->user, $ctx->data, $ctx->vars, $ctx->outputs, $ctx->form, $ctx->submissionId, $ctx->submissionUid, $ctx->meta);
    }

    /**
     * @param   string  $mode  'html' escapes values; 'text' leaves them plain (subjects, plain-text email, prefill)
     */
    public function replace(string $text, string $mode = 'html'): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }

        $text = self::aliases($text);

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($mode) {
            [$all, $ns, $mod, $name] = $m;

            // ".raw" exists only for submitted data; ".field" only for user; ".label" only for data.
            if (($mod === 'raw' || $mod === 'label') && $ns !== 'data' || $mod === 'field' && $ns !== 'user') {
                return '';
            }

            $value = $this->resolve($ns, $mod, $name, $mode);

            if ($value === null) {
                return '';
            }

            if ($name === '_all' && $ns === 'data') {
                return $value; // already built safely for the mode
            }

            if ($mode !== 'html') {
                return $value;
            }

            if ($mod === 'raw') {
                // Raw keeps the person's formatting but never scripts or event handlers.
                return \SquidForm\Component\SquidForm\Administrator\Engine\Html::clean((string) $value);
            }

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }, $text);
    }

    /**
     * Other names for placeholders (1.0.4): {AUTO_FIELDS}, from ChronoForms
     * (any case), is {data:_all}.
     */
    public static function aliases(string $text): string
    {
        return str_contains($text, '{') ? (string) preg_replace('/\{\s*auto_fields\s*\}/i', '{data:_all}', $text) : $text;
    }

    /**
     * Things in a text that look like placeholders but aren't (1.0.4), so the
     * builder can say so before a form goes live: {SOMETHING} without a
     * namespace, {ns:item} with a namespace nobody provides, and {data:KEY}
     * for a key the form doesn't have. {AUTO_FIELDS} is fine (an alias).
     *
     * @param   list<string>  $keys  every field key in the form
     * @return  list<string>  each suspicious token once, as written
     */
    public static function suspicious(string $text, array $keys): array
    {
        if (!str_contains($text, '{')) {
            return [];
        }

        $text = self::aliases($text);
        $out  = [];
        $ns   = array_merge(self::BUILT_IN, array_keys(self::addons()));

        // {Word} or {WORD_TWO}: no namespace.
        if (preg_match_all('/\{\s*([A-Za-z][A-Za-z0-9_]{0,60})\s*\}/', $text, $m)) {
            array_push($out, ...$m[0]);
        }

        if (preg_match_all(self::PATTERN, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as [$all, $space, , $name]) {
                if (!\in_array($space, $ns, true)) {
                    $out[] = $all;
                } elseif ($space === 'data' && $name !== '_all' && !\in_array(explode('.', $name)[0], $keys, true)) {
                    $out[] = $all;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * True when the text contains a placeholder whose value a visitor or user
     * can influence: submitted data, variables, action outputs, or a custom
     * user field (people edit their own profile). Used to stop such input
     * choosing email recipients or senders unless an admin allows it.
     */
    public static function readsInput(string $text): bool
    {
        if (preg_match('/\{(data(\.[a-z]+)?|var|action|user\.field|approval|url|js):/', $text)) {
            return true;
        }

        // Add-on placeholders (0.14.0) may repeat what people typed: treated the same way.
        foreach (array_keys(self::addons()) as $ns) {
            if (str_contains($text, '{' . $ns . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Placeholders added by add-ons through onSquidformPlaceholders (0.14.0):
     *
     *   $event['registry']['weather'] = [
     *     'title' => 'Weather add-on',
     *     'items' => ['today' => 'Today’s forecast'],      // listed on the Placeholders tab
     *     'value' => fn (string $name, array $ctx): ?string => ...,
     *   ];
     *
     * $ctx holds form (id, title, alias), user_id, data (the answers) and
     * submission_id. Worked out on the server; the result is escaped like any
     * other value. A namespace is 2 to 20 lower-case letters and not a built-in.
     *
     * @return array<string, array{title: string, items: array<string, string>, value: callable}>
     */
    public static function addons(): array
    {
        if (self::$addons !== null) {
            return self::$addons;
        }

        $registry = new \ArrayObject();

        try {
            \Joomla\CMS\Plugin\PluginHelper::importPlugin('squidform');
            Factory::getApplication()->getDispatcher()->dispatch('onSquidformPlaceholders', new \Joomla\Event\Event('onSquidformPlaceholders', ['registry' => $registry]));
        } catch (\Throwable) {
            // No add-ons.
        }

        $out = [];

        foreach ($registry as $ns => $p) {
            if (!\is_string($ns) || !preg_match('/^[a-z]{2,20}$/', $ns) || \in_array($ns, self::BUILT_IN, true) || !\is_array($p) || !\is_callable($p['value'] ?? null)) {
                continue;
            }

            $items = [];

            foreach ((array) ($p['items'] ?? []) as $k => $label) {
                if (\is_string($k) && preg_match('/^[A-Za-z0-9_\-.]{1,40}$/', $k)) {
                    $items[$k] = mb_substr((string) $label, 0, 80);
                }
            }

            $out[$ns] = ['title' => mb_substr((string) ($p['title'] ?? $ns), 0, 80), 'items' => $items, 'value' => $p['value']];
        }

        return self::$addons = $out;
    }

    private function resolve(string $ns, string $mod, string $name, string $mode): ?string
    {
        switch ($ns) {
            case 'data':
                if ($name === '_all') {
                    return $this->allData($mode);
                }

                // One part of a name or address (0.8.1): {data:f_address.city}
                if (!\array_key_exists($name, $this->data) && str_contains($name, '.')) {
                    [$base, $part] = explode('.', $name, 2);
                    $v = $this->data[$base] ?? null;

                    if (\is_array($v) && \in_array($this->schema?->field($base)['type'] ?? '', ['name', 'address', 'daterange', 'phone'], true)) {
                        return self::scalar($v[$part] ?? '');
                    }
                }

                // Files (0.9.1): {data.raw:key} gives the links.
                if ($mod === 'raw' && ($this->schema?->field($name)['type'] ?? '') === 'upload' && \is_array($this->data[$name] ?? null)) {
                    return implode('<br>', array_map(static fn ($l) => '<a href="' . htmlspecialchars($l['url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') . '</a>', Uploads::links($this->data[$name])));
                }

                // Rich text (0.8.0): its HTML for .raw, plain text otherwise.
                if ($mod === 'raw' && ($this->schema?->field($name)['type'] ?? '') === 'editor') {
                    return self::scalar($this->data[$name] ?? null);
                }

                return $this->display($name, $mod === 'label');

            case 'var':
                return self::scalar($this->vars[$name] ?? null);

            case 'action':
                if (!\array_key_exists($name, $this->outputs) && str_contains($name, '.')) {
                    [$k, $part] = explode('.', $name, 2);
                    $out        = $this->outputs[$k]['output'] ?? null;

                    if (!\is_array($out)) {
                        return null;
                    }

                    if (array_is_list($out)) {
                        if ($part === 'count') {
                            return (string) \count($out);
                        }

                        $out = \is_array($out[0] ?? null) ? $out[0] : [];
                    }

                    return self::scalar($out[$part] ?? null);
                }

                return self::scalar($this->outputs[$name]['output'] ?? null);

            case 'url':
                $v = self::$query[$name] ?? null;

                return \is_scalar($v) ? mb_substr((string) $v, 0, 500) : null;

            case 'user':
                if ($mod === 'field') {
                    return self::scalar($this->userField($name));
                }

                return match ($name) {
                    'id'       => (string) (int) $this->user->id,
                    'name'     => (string) $this->user->name,
                    'username' => (string) $this->user->username,
                    'email'    => (string) $this->user->email,
                    default    => null,
                };

            case 'date':
                $date = new Date('now', 'UTC');
                $date->setTimezone(new \DateTimeZone($this->user->getTimezone()->getName()));

                return $date->format($name !== '' ? $name : 'Y-m-d', true);

            case 'setting':
                return self::scalar($this->schema?->setting($name));

            case 'submission':
                return match ($name) {
                    'id'    => $this->submissionId !== null ? (string) $this->submissionId : null,
                    'uid'   => $this->submissionUid,
                    'ref'   => $this->submissionUid !== null ? Ref::short($this->submissionUid) : null,
                    default => null,
                };

            case 'form':
                return self::scalar($this->form[$name] ?? null);

            case 'approval':
            case 'submitter':
            case 'handler':
                // 'handler' (0.15.0): values a PHP handler shared with share(); server data, so trusted.
                return self::scalar($this->meta[$ns][$name] ?? null);

            case 'site':
                return match ($name) {
                    'name'  => (string) Factory::getApplication()->get('sitename'),
                    'url'   => Uri::root(),
                    default => null,
                };

            case 'session':
                // 0.15.0: session data another script put there (Options → Data Sources → Session data forms may read). Trusted.
                return SessionData::text($name);

            case 'js':
                // Filled in the browser by the form's own JavaScript; never on the server.
                return $this->onPage && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) ? '{js:' . $name . '}' : null;
        }

        $addon = self::addons()[$ns] ?? null;

        if ($addon !== null) {
            try {
                $v = ($addon['value'])($name, [
                    'form' => $this->form, 'user_id' => (int) $this->user->id, 'data' => $this->data, 'submission_id' => $this->submissionId,
                ]);

                return $v === null ? null : self::scalar($v);
            } catch (\Throwable $e) {
                \Joomla\CMS\Log\Log::add('SquidForm: add-on placeholder {' . $ns . ':' . $name . '} failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'squidform');

                return null;
            }
        }

        return null;
    }

    /** Value of a field for display; labels for choice fields when asked. */
    private function display(string $key, bool $labels): ?string
    {
        if (!\array_key_exists($key, $this->data)) {
            return null;
        }

        $value = $this->data[$key];
        $field = $this->schema?->field($key);

        if ($labels && $field && isset($field['options'])) {
            $map   = Options::labelMap($field);
            $value = \is_array($value)
                ? array_map(static fn ($v) => $map[(string) $v] ?? $v, $value)
                : ($map[(string) $value] ?? $value);
        }

        if ($labels && $field && ($field['type'] ?? '') === 'user') {
            return Users::name((int) $value);
        }

        if ($field && ($field['type'] ?? '') === 'repeater') {
            return \is_array($value) ? \count($value) . ' row(s)' : '';
        }

        if ($field && ($field['type'] ?? '') === 'editor') {
            return Display::plain((string) $value);
        }

        if ($field && \in_array($field['type'] ?? '', ['datetime', 'rating', 'signature', 'grid', 'name', 'address', 'upload', 'ranking', 'slider', 'daterange', 'phone', 'consent', 'imagechoice', 'calc'], true) && $value !== '' && $value !== null) {
            return Display::value($field, $value, '');
        }

        return self::scalar($value);
    }

    private function allData(string $mode): string
    {
        if (!$this->schema) {
            return '';
        }

        $esc   = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $lines = [];

        foreach ($this->schema->valueFields() as $field) {
            $key   = $field['key'];
            $label = (string) ($field['label'] ?? $key);

            // Hidden values and "Hide what's typed" answers (0.8.0) are never listed.
            if (!\array_key_exists($key, $this->data) || Display::isPrivate($field)) {
                continue;
            }

            if ($field['type'] === 'repeater') {
                $rows = \is_array($this->data[$key]) ? $this->data[$key] : [];

                foreach ($rows as $i => $row) {
                    foreach ($field['fields'] as $child) {
                        if (Display::isPrivate($child)) {
                            continue;
                        }

                        $cv      = $row[$child['key']] ?? '';
                        $v       = ($child['type'] ?? '') === 'editor' ? Display::plain((string) $cv)
                            : (\in_array($child['type'] ?? '', ['name', 'address', 'lookup', 'ranking', 'slider', 'daterange', 'phone', 'imagechoice', 'calc'], true) ? Display::value($child, $cv, '') : self::scalar($cv));
                        $lines[] = [$label . ' #' . ($i + 1) . ' — ' . ($child['label'] ?? $child['key']), $v];
                    }
                }

                continue;
            }

            // Files (0.9.1): each name links to the site's download.
            if (($field['type'] ?? '') === 'upload' && \is_array($this->data[$key])) {
                $links = Uploads::links($this->data[$key]);

                if ($mode === 'html') {
                    $lines[] = [$label, implode('<br>', array_map(static fn ($l) => '<a href="' . $esc($l['url']) . '">' . $esc($l['name']) . '</a> (' . $esc($l['size']) . ')', $links)), true];
                } else {
                    $lines[] = [$label, implode(', ', array_map(static fn ($l) => $l['name'] . ' (' . $l['size'] . ') ' . $l['url'], $links))];
                }

                continue;
            }

            // A grid (0.8.1) reads best a row per line.
            if (($field['type'] ?? '') === 'grid' && \is_array($this->data[$key])) {
                $lines[] = [$label, implode("\n", Field\GridField::lines($field, $this->data[$key]))];
                continue;
            }

            if (($field['type'] ?? '') === 'editor' && $mode === 'html') {
                $lines[] = [$label, Html::clean((string) $this->data[$key]), true];
                continue;
            }

            $lines[] = [$label, $this->display($key, true) ?? ''];
        }

        if ($mode !== 'html') {
            return implode("\n", array_map(static fn ($l) => $l[0] . ': ' . $l[1], $lines));
        }

        $html = '<table role="presentation" cellpadding="6" style="border-collapse:collapse">';

        foreach ($lines as $line) {
            [$l, $v] = $line;
            $html .= '<tr><th align="left" style="border-bottom:1px solid #ddd">' . $esc($l) . '</th>'
                . '<td style="border-bottom:1px solid #ddd">' . (!empty($line[2]) ? $v : nl2br($esc($v))) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private function userField(string $name): mixed
    {
        $id = (int) $this->user->id;

        if ($id === 0 || !class_exists(FieldsHelper::class)) {
            return null;
        }

        if (!isset(self::$userFields[$id])) {
            self::$userFields[$id] = [];

            try {
                foreach (FieldsHelper::getFields('com_users.user', $this->user, true) as $f) {
                    self::$userFields[$id][$f->name] = $f->rawvalue ?? $f->value ?? null;
                }
            } catch (\Throwable) {
                // Custom fields disabled or unavailable: treat as empty.
            }
        }

        return self::$userFields[$id][$name] ?? null;
    }

    private static function scalar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        if (\is_bool($v)) {
            return $v ? '1' : '0';
        }

        if (\is_array($v)) {
            $flat = array_filter($v, static fn ($x) => !\is_array($x));

            return \count($flat) === \count($v) ? implode(', ', array_map('strval', $v)) : json_encode($v);
        }

        if (\is_object($v)) {
            return json_encode($v);
        }

        return (string) $v;
    }
}
