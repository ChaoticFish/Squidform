<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Parses, validates and queries a SquidForm form definition (the JSON schema).
 *
 * The definition is read-only at runtime. Every page, field and action carries a
 * stable `key` that never changes when the item is renamed or moved, so stored
 * submissions and cross references stay valid.
 *
 * Format version 1 (see docs/SCHEMA.md for the full reference).
 */
final class Schema
{
    public const FORMAT = 1;

    /** Field types that hold a value and are stored. */
    public const VALUE_TYPES = [
        'text', 'textarea', 'email', 'number', 'tel', 'url', 'date', 'hidden',
        'select', 'radios', 'checkboxes', 'checkbox', 'user', 'repeater',
        // 0.8.0
        'multiselect', 'time', 'datetime', 'rating', 'editor',
        // 0.8.1
        'signature', 'grid', 'lookup', 'name', 'address',
        // 0.9.0
        'upload',
        // 0.9.2
        'ranking', 'slider', 'daterange', 'phone', 'consent', 'imagechoice',
        // 0.10.0
        'calc',
    ];

    /** Field types a web address may fill in (0.10.0, "url_param"). */
    public const URL_TYPES = ['text', 'textarea', 'email', 'number', 'tel', 'url', 'date', 'time', 'datetime', 'hidden',
        'select', 'radios', 'checkboxes', 'checkbox', 'multiselect', 'rating', 'slider'];

    /** The name in the web address: ?dept=HR. */
    public const URL_PARAM = '/^[A-Za-z][A-Za-z0-9_\-]{0,39}$/';

    /** Field types that only display content. */
    public const DISPLAY_TYPES = ['html', 'section', 'divider'];

    /** A field's own CSS classes (0.9.1): letters, digits, - and _, separated by spaces. */
    public const CSS_CLASS = '/^[A-Za-z_][A-Za-z0-9_-]*( [A-Za-z_][A-Za-z0-9_-]*){0,9}$/';

    /** Characters an input mask may contain: 9 = digit, a = letter, * = either; the rest is typed for you. */
    private const MASK = '/^[9a*A-Za-z0-9 ()\/.:+#_\-]{1,60}$/';

    /** Form-level action lists run after an edit or an approval decision. */
    public const HOOKS = ['on_edit', 'on_approve', 'on_reject', 'on_return'];

    /** Characters allowed in keys: start with a letter, then letters, digits, underscore. */
    private const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /** @var array<string, mixed> */
    private array $def;

    /**
     * The pages as the engine uses them (0.8.0): every field after a section
     * carries the section's show_if joined to its own, so hiding a section
     * hides (and empties) its fields everywhere conditions are checked. The
     * stored definition ($def) is never changed.
     *
     * @var list<array<string, mixed>>
     */
    private array $pages = [];

    /** @var array<string, array<string, mixed>> field key => field, including repeater children */
    private array $fieldIndex = [];

    /** @var array<string, string> child field key => parent repeater key */
    private array $parentIndex = [];

    /**
     * @param   array<string, mixed>  $definition  A definition that has passed validate().
     */
    private function __construct(array $definition)
    {
        $this->def   = $definition;
        $this->pages = self::withSections($definition['pages']);

        foreach ($this->pages() as $page) {
            foreach ($page['fields'] ?? [] as $field) {
                $this->indexField($field, null);
            }
        }
    }

    /**
     * Parse JSON and return a Schema, or throw with every problem found.
     *
     * @throws SchemaException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // json_decode() only says "Syntax error": find where, and why.
            $err = JsonCheck::scan($json)['error'];

            if ($err === null) {
                throw new SchemaException(['JSON: ' . $e->getMessage()]);
            }

            throw new SchemaException(
                [sprintf('JSON syntax error on line %d, column %d: %s.', $err['line'], $err['column'], $err['message'])],
                ['line' => $err['line'], 'column' => $err['column']]
            );
        }

        if (!\is_array($data)) {
            throw new SchemaException(['The definition must be a JSON object.']);
        }

        return self::fromArray($data);
    }

    /**
     * @param   array<string, mixed>  $data
     *
     * @throws SchemaException
     */
    public static function fromArray(array $data): self
    {
        $data   = self::migrate($data);
        $errors = self::validate($data);

        if ($errors) {
            throw new SchemaException($errors);
        }

        return new self($data);
    }

    /**
     * Upgrade older format versions. Only format 1 exists today; this is the hook
     * where later versions are converted so stored versions keep loading.
     *
     * @param   array<string, mixed>  $data
     *
     * @return  array<string, mixed>
     */
    public static function migrate(array $data): array
    {
        $data['schema'] ??= self::FORMAT;

        if (\is_array($data['pages'] ?? null)) {
            foreach ($data['pages'] as &$page) {
                if (\is_array($page) && \is_array($page['fields'] ?? null) && array_is_list($page['fields'])) {
                    $page['fields'] = self::nestSections($page['fields']);
                }
            }

            unset($page);
        }

        return $data;
    }

    /**
     * 0.8.x sections ran to the next section. Since 0.9.1 a section holds its
     * fields in its own "fields" list; a section without one takes the
     * fields after it, up to the next section, as before.
     *
     * @param list<mixed> $fields
     * @return list<mixed>
     */
    public static function nestSections(array $fields): array
    {
        $out  = [];
        $open = null; // index in $out of a flat section taking fields

        foreach ($fields as $f) {
            if (\is_array($f) && ($f['type'] ?? '') === 'section') {
                $open = null;

                if (!\array_key_exists('fields', $f)) {
                    $f['fields'] = [];
                    $open        = \count($out);
                }

                $out[] = $f;
                continue;
            }

            if ($open !== null) {
                $out[$open]['fields'][] = $f;
            } else {
                $out[] = $f;
            }
        }

        return $out;
    }

    /**
     * A page's fields with each section's own fields listed after it (for
     * the checks that look at every field of a page).
     *
     * @return list<mixed>
     */
    private static function unnest(mixed $fields): array
    {
        $out = [];

        foreach (\is_array($fields) ? $fields : [] as $f) {
            $out[] = $f;

            if (\is_array($f) && ($f['type'] ?? '') === 'section' && \is_array($f['fields'] ?? null)) {
                foreach ($f['fields'] as $c) {
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    /**
     * Check a definition and return a list of human-readable problems (empty = valid).
     *
     * @param   array<string, mixed>  $data
     *
     * @return  string[]
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $keys   = [];

        if ((int) ($data['schema'] ?? 0) !== self::FORMAT) {
            $errors[] = 'Unsupported "schema" format version; expected ' . self::FORMAT . '.';
        }

        if (isset($data['settings']) && !\is_array($data['settings'])) {
            $errors[] = '"settings" must be an object.';
        }

        if (\is_array($data['settings'] ?? null)) {
            $set = $data['settings'];

            // Rate limit (0.6.0): false, or { "max": 1–1000, "minutes": 1–1440 }.
            if (\array_key_exists('rate_limit', $set) && $set['rate_limit'] !== false) {
                $r = $set['rate_limit'];

                if (!\is_array($r) || array_is_list($r)
                    || (isset($r['max']) && (!\is_int($r['max']) || $r['max'] < 1 || $r['max'] > 1000))
                    || (isset($r['minutes']) && (!\is_int($r['minutes']) || $r['minutes'] < 1 || $r['minutes'] > 1440))
                    || array_diff(array_keys($r), ['max', 'minutes'])) {
                    $errors[] = 'settings.rate_limit must be false or {"max": 1–1000, "minutes": 1–1440}.';
                }
            }

            // Your own CSS and JavaScript (0.9.0), each up to 50,000 characters.
            foreach (['css' => '</style', 'js' => '</script'] as $k => $end) {
                if (!\array_key_exists($k, $set)) {
                    continue;
                }

                if (!\is_string($set[$k]) || mb_strlen($set[$k]) > 50000) {
                    $errors[] = 'settings.' . $k . ' must be text of at most 50,000 characters.';
                } elseif (stripos($set[$k], $end) !== false || stripos($set[$k], '<!--') !== false) {
                    $errors[] = 'settings.' . $k . ' can’t contain "' . $end . '" or "<!--" (it would end the ' . ($k === 'css' ? 'style' : 'script') . ' block early).';
                }
            }

            if (\array_key_exists('captcha', $set)) {
                $errors = array_merge($errors, Captcha::validate($set['captcha']));
            }

            // Open and close dates, limits and the review page (0.10.0).
            foreach (['opens', 'closes'] as $k) {
                if (\array_key_exists($k, $set) && (!\is_string($set[$k]) || !preg_match(Limits::DATETIME, $set[$k]))) {
                    $errors[] = 'settings.' . $k . ' must look like "2026-12-31T17:00" (in the site’s time zone).';
                }
            }

            if (\is_string($set['opens'] ?? null) && \is_string($set['closes'] ?? null) && strcmp($set['closes'], $set['opens']) <= 0) {
                $errors[] = 'settings.closes must be after settings.opens.';
            }

            foreach (['max_submissions' => 1000000, 'max_per_user' => 1000] as $k => $most) {
                if (\array_key_exists($k, $set) && (!\is_int($set[$k]) || $set[$k] < 1 || $set[$k] > $most)) {
                    $errors[] = 'settings.' . $k . ' must be a whole number from 1 to ' . number_format($most) . '.';
                }
            }

            foreach (['before_message', 'closed_message', 'full_message', 'user_message', 'review_title', 'review_intro'] as $k) {
                if (\array_key_exists($k, $set) && (!\is_string($set[$k]) || mb_strlen($set[$k]) > 5000)) {
                    $errors[] = 'settings.' . $k . ' must be text of at most 5,000 characters.';
                }
            }

            if (\array_key_exists('review', $set) && !\is_bool($set['review'])) {
                $errors[] = 'settings.review must be true or false.';
            }

            // Looks (0.14.1).
            if (\array_key_exists('look', $set) && !(\is_string($set['look']) && Looks::normal($set['look']) !== null)) {
                $errors[] = 'settings.look is one of ' . implode(', ', Looks::ALL) . ' (leave it out for the site\'s default).';
            }

            // Save and finish later, and the copy after sending (0.11.0).
            foreach (['save_later', 'save_later_guests', 'copy_pdf', 'test_mode'] as $k) {
                if (\array_key_exists($k, $set) && !\is_bool($set[$k])) {
                    $errors[] = 'settings.' . $k . ' must be true or false.';
                }
            }

            // Test mode (0.15.0): where emails go when nobody is signed in.
            if (\array_key_exists('test_email', $set) && !(\is_string($set['test_email']) && ($set['test_email'] === '' || filter_var($set['test_email'], FILTER_VALIDATE_EMAIL)))) {
                $errors[] = 'settings.test_email must be one email address.';
            }

            // Languages (0.15.0): the packs a form offers visitors.
            if (\array_key_exists('languages', $set) && !(\is_array($set['languages']) && array_is_list($set['languages'])
                && \count($set['languages']) <= \count(Lang::packs()) && !array_filter($set['languages'], static fn ($t) => !\is_string($t) || !isset(Lang::packs()[$t])))) {
                $errors[] = 'settings.languages must be a list of ' . implode(', ', array_keys(Lang::packs())) . '.';
            }
        }

        $errors = array_merge($errors, Lang::problems($data['translations'] ?? null));

        $pages = $data['pages'] ?? null;

        if (!\is_array($pages) || $pages === [] || !array_is_list($pages)) {
            $errors[] = '"pages" must be a non-empty list.';

            return $errors;
        }

        $fieldKeys = [];

        foreach ($pages as $pi => $page) {
            $where = 'pages[' . $pi . ']';

            if (!\is_array($page)) {
                $errors[] = $where . ' must be an object.';
                continue;
            }

            self::checkKey($page['key'] ?? null, $where, $keys, $errors);

            // Skip pages (0.10.0): the first and last page always show.
            if (\array_key_exists('show_if', $page) && !empty($page['show_if']) && ($pi === 0 || $pi === \count($pages) - 1)) {
                $errors[] = $where . '.show_if: the ' . ($pi === 0 ? 'first' : 'last') . ' page always shows' . ($pi === 0 ? '.' : ' (it’s where the form is sent).');
            }

            $fields = $page['fields'] ?? [];

            if (!\is_array($fields) || !array_is_list($fields)) {
                $errors[] = $where . '.fields must be a list.';
                continue;
            }

            foreach ($fields as $fi => $field) {
                self::validateField($field, $where . '.fields[' . $fi . ']', $keys, $fieldKeys, $errors, null, $pi);
            }

            $events = $page['events'] ?? [];

            if (!\is_array($events)) {
                $errors[] = $where . '.events must be an object.';
                continue;
            }

            foreach ($events as $event => $actions) {
                if (!\in_array($event, ['load', 'submit'], true)) {
                    $errors[] = $where . '.events: unknown event "' . $event . '" (use load or submit).';
                    continue;
                }

                self::validateActions($actions, $where . '.events.' . $event, $keys, $errors, $event);
            }
        }

        // 1.0.3: fields other forms (and add-ons' choice lists) may read as choices.
        $shared = \is_array($data['settings'] ?? null) ? ($data['settings']['shared_fields'] ?? null) : null;

        if ($shared !== null) {
            if (!\is_array($shared) || !array_is_list($shared) || \count($shared) > 50) {
                $errors[] = 'settings.shared_fields must be a list of field keys.';
            } else {
                foreach ($shared as $sk) {
                    if (!\is_string($sk) || !isset($fieldKeys[$sk]) || ($fieldKeys[$sk]['parent'] ?? null) !== null) {
                        $errors[] = 'settings.shared_fields: "' . (\is_scalar($sk) ? $sk : '?') . '" isn\'t a field on this form (outside repeating groups).';
                    }
                }
            }
        }

        // Form-level action lists: run after an edit or an approval decision.
        foreach (self::HOOKS as $hook) {
            if (isset($data[$hook])) {
                self::validateActions($data[$hook], $hook, $keys, $errors, 'hook');
            }
        }

        if (isset($data['approval'])) {
            $types = [];

            foreach ($pages as $page) {
                foreach (self::unnest(\is_array($page) ? ($page['fields'] ?? []) : []) as $f) {
                    if (\is_array($f) && \is_string($f['key'] ?? null)) {
                        $types[$f['key']] = (string) ($f['type'] ?? '');
                    }
                }
            }

            foreach (ApprovalFlow::validate($data['approval'], $types, $fieldKeys, \count($pages) - 1) as $err) {
                $errors[] = $err;
            }
        } else {
            foreach (['on_approve', 'on_reject', 'on_return'] as $hook) {
                if (isset($data[$hook])) {
                    $errors[] = '"' . $hook . '" needs an "approval" block.';
                }
            }
        }

        if (isset($data['viewer'])) {
            $top = [];

            foreach ($pages as $page) {
                foreach (self::unnest(\is_array($page) ? ($page['fields'] ?? []) : []) as $f) {
                    if (\is_array($f) && \is_string($f['key'] ?? null)) {
                        $top[$f['key']] = (string) ($f['type'] ?? '');
                    }
                }
            }

            foreach (ViewerConfig::validate($data['viewer'], $top) as $err) {
                $errors[] = $err;
            }
        }

        // Conditions: well-formed, known operators, and they may only use fields
        // the person can already see (same or earlier page; inside a repeater,
        // the same row or top-level fields).
        $last     = \count($pages) - 1;
        $calcs    = [];
        $allTypes = [];
        $params   = [];

        foreach ($pages as $pi => $page) {
            if (!\is_array($page)) {
                continue;
            }

            if (!empty($page['show_if']) && $pi > 0 && $pi < $last) {
                self::checkCondition($page['show_if'], 'pages[' . $pi . '].show_if', null, $pi - 1, false, $fieldKeys, $errors);
            }

            $stack = [];

            foreach (self::unnest($page['fields'] ?? []) as $f) {
                $stack[] = [$f, null];
            }

            while ($stack) {
                [$f, $parent] = array_pop($stack);

                if (!\is_array($f)) {
                    continue;
                }

                $fk = (string) ($f['key'] ?? '?');

                $allTypes[$fk] = (string) ($f['type'] ?? '');

                if (($f['type'] ?? '') === 'calc') {
                    $calcs[] = $f + ['_page' => $pi, '_parent' => $parent];
                }

                if (\is_string($f['url_param'] ?? null)) {
                    if (isset($params[strtolower($f['url_param'])])) {
                        $errors[] = 'pages[' . $pi . '] ' . $fk . '.url_param: "' . $f['url_param'] . '" already fills "' . $params[strtolower($f['url_param'])] . '".';
                    }

                    $params[strtolower($f['url_param'])] = $fk;
                }

                foreach (['show_if', 'disabled_if'] as $c) {
                    if (\array_key_exists($c, $f)) {
                        self::checkCondition($f[$c], 'pages[' . $pi . '] ' . $fk . '.' . $c, $parent, $pi, false, $fieldKeys, $errors);
                    }
                }

                if (isset($f['parts_by'])) {
                    self::checkCondition(['field' => $f['parts_by'], 'op' => 'eq', 'value' => ''], 'pages[' . $pi . '] ' . $fk . '.parts_by', $parent, $pi, false, $fieldKeys, $errors);
                }

                $opts = \is_array($f['options'] ?? null) ? $f['options'] : [];

                foreach (['table', 'file'] as $src) {
                    if (isset($opts[$src]['filter_by'])) {
                        $ref = $opts[$src]['filter_by']['field'] ?? null;
                        $cond = ['field' => $ref, 'op' => 'eq', 'value' => ''];
                        self::checkCondition($cond, 'pages[' . $pi . '] ' . $fk . '.options.' . $src . '.filter_by', $parent, $pi, false, $fieldKeys, $errors);
                    }
                }

                if (($f['type'] ?? '') === 'repeater') {
                    foreach ((array) ($f['fields'] ?? []) as $child) {
                        $stack[] = [$child, $fk];
                    }
                }
            }

            foreach ((array) ($page['events'] ?? []) as $actions) {
                foreach ((array) $actions as $a) {
                    if (\is_array($a) && \array_key_exists('when', $a)) {
                        self::checkCondition($a['when'], 'pages[' . $pi . '] ' . ($a['key'] ?? '?') . '.when', null, $pi, true, $fieldKeys, $errors);
                    }

                    // Routed email (0.11.0): each route's condition.
                    foreach (\is_array($a) && ($a['type'] ?? '') === 'email' ? (array) ($a['config']['routes'] ?? []) : [] as $ri => $r) {
                        if (\is_array($r) && \array_key_exists('when', $r)) {
                            self::checkCondition($r['when'], 'pages[' . $pi . '] ' . ($a['key'] ?? '?') . '.routes[' . $ri . '].when', null, $pi, true, $fieldKeys, $errors);
                        }
                    }
                }
            }
        }

        foreach (self::HOOKS as $hook) {
            foreach ((array) ($data[$hook] ?? []) as $a) {
                if (\is_array($a) && \array_key_exists('when', $a)) {
                    self::checkCondition($a['when'], $hook . ' ' . ($a['key'] ?? '?') . '.when', null, $last, true, $fieldKeys, $errors);
                }
            }
        }

        // Calculations (0.10.0): fields they use, and no loops.
        if ($calcs) {
            foreach (Calc::problems($calcs, $fieldKeys, $allTypes) as $err) {
                $errors[] = $err;
            }
        }

        // A confirmation email (0.11.0) goes to what someone typed in an email field.
        $hasSave = false;

        // "After another date" (0.13.0): a date field on the same or an earlier page, not in a repeating group.
        $walk = static function (array $fields, int $pi) use (&$walk, $allTypes, $fieldKeys, &$errors): void {
            foreach ($fields as $f) {
                if (!\is_array($f)) {
                    continue;
                }

                $ref = $f['rules']['after']['field'] ?? null;

                if (\is_string($ref) && $ref !== ($f['key'] ?? '')
                    && (!\in_array($allTypes[$ref] ?? '', ['date', 'datetime', 'daterange'], true) || ($fieldKeys[$ref]['parent'] ?? null) !== null || ($fieldKeys[$ref]['page'] ?? 99) > $pi)) {
                    $errors[] = 'pages[' . $pi . '] ' . ($f['key'] ?? '?') . ': rules.after.field must be a date field on this page or an earlier one (not in a repeating group).';
                }

                if (\is_array($f['fields'] ?? null) && ($f['type'] ?? '') === 'section') {
                    $walk($f['fields'], $pi);
                }
            }
        };

        foreach ($pages as $pi => $page) {
            if (\is_array($page) && \is_array($page['fields'] ?? null)) {
                $walk($page['fields'], $pi);
            }
        }

        foreach ($pages as $pi => $page) {
            foreach ((array) (\is_array($page) ? ($page['events'] ?? []) : []) as $actions) {
                foreach ((array) $actions as $a) {
                    if (!\is_array($a)) {
                        continue;
                    }

                    $hasSave = $hasSave || ($a['type'] ?? '') === 'save_data';

                    if (($a['type'] ?? '') === 'confirm_email') {
                        $tf = $a['config']['to_field'] ?? null;

                        if (!\is_string($tf) || ($allTypes[$tf] ?? '') !== 'email' || ($fieldKeys[$tf]['parent'] ?? null) !== null) {
                            $errors[] = 'pages[' . $pi . '] ' . ($a['key'] ?? '?') . '.config.to_field: choose an Email field (not one in a repeating group).';
                        }
                    }
                }
            }
        }

        // An e-signature is a signed record: the form has to save its submissions.
        foreach ($allTypes as $fk => $t) {
            if ($t === 'signature' && !$hasSave) {
                foreach ($pages as $page) {
                    foreach (self::unnest(\is_array($page) ? ($page['fields'] ?? []) : []) as $f) {
                        if (\is_array($f) && ($f['key'] ?? null) === $fk && isset($f['esign'])) {
                            $errors[] = 'Field "' . $fk . '" is an e-signature, so the form needs a Save Data action (submissions must be saved to be signed records).';
                        }
                    }
                }
            }
        }

        // Read data fills (0.10.0): the fields exist.
        foreach ($pages as $pi => $page) {
            foreach ((array) (\is_array($page) ? ($page['events'] ?? []) : []) as $actions) {
                foreach ((array) $actions as $a) {
                    if (\is_array($a) && ($a['type'] ?? '') === 'read_data' && \is_array($a['config']['fill'] ?? null)) {
                        foreach ($a['config']['fill'] as $fk => $col) {
                            $info = $fieldKeys[$fk] ?? null;

                            if ($info === null || $info['parent'] !== null || \in_array($allTypes[$fk] ?? '', self::DISPLAY_TYPES, true) || ($allTypes[$fk] ?? '') === 'calc') {
                                $errors[] = 'pages[' . $pi . '] ' . ($a['key'] ?? '?') . '.config.fill: "' . $fk . '" isn’t a field that can be filled in.';
                            } elseif (($allTypes[$fk] ?? '') === 'repeater' && !\is_array($col)) {
                                $errors[] = 'pages[' . $pi . '] ' . ($a['key'] ?? '?') . '.config.fill: a repeating group is filled with {"its field": "column"}.';
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param   mixed                                                $field
     * @param   array<string, true>                                  $keys
     * @param   array<string, array{page: int, parent: ?string}>     $fieldKeys
     * @param   string[]                                             $errors
     */
    private static function validateField($field, string $where, array &$keys, array &$fieldKeys, array &$errors, ?string $parent, int $page): void
    {
        $inRepeater = $parent !== null;

        if (!\is_array($field)) {
            $errors[] = $where . ' must be an object.';

            return;
        }

        $key  = $field['key'] ?? null;
        $type = $field['type'] ?? null;

        if (self::checkKey($key, $where, $keys, $errors)) {
            $fieldKeys[$key] = ['page' => $page, 'parent' => $parent];
            $where .= ' ("' . $key . '")';
        }

        if (\array_key_exists('editable', $field) && !\is_bool($field['editable'])) {
            $errors[] = $where . ': "editable" must be true or false.';
        }

        $known = array_merge(self::VALUE_TYPES, self::DISPLAY_TYPES);

        if (!\is_string($type) || !\in_array($type, $known, true)) {
            if (!\is_string($type) || !FieldRegistry::has($type)) {
                $errors[] = $where . ': unknown field type "' . (\is_string($type) ? $type : '?') . '".';

                return;
            }
        }

        // 0.8.0: layout and the new field options.
        if (\array_key_exists('width', $field) && (!\is_int($field['width']) || $field['width'] < 1 || $field['width'] > 12)) {
            $errors[] = $where . ': "width" must be a whole number of columns from 1 to 12 (12 = the full width).';
        }

        if (\array_key_exists('css_class', $field) && (!\is_string($field['css_class']) || !preg_match(self::CSS_CLASS, $field['css_class']))) {
            $errors[] = $where . ': "css_class" is one or more class names (letters, digits, - and _), separated by spaces.';
        }

        // 0.10.0
        if (\array_key_exists('url_param', $field)) {
            if (!\in_array($type, self::URL_TYPES, true) || $inRepeater) {
                $errors[] = $where . ': a web address can fill only simple fields (text, numbers, dates, choices, hidden values), not ' . ($inRepeater ? 'fields in a repeating group' : 'a ' . $type) . '.';
            } elseif (!\is_string($field['url_param']) || !preg_match(self::URL_PARAM, $field['url_param'])) {
                $errors[] = $where . ': "url_param" is the name in the web address (letters, digits, - and _; a letter first), such as "dept" for ?dept=HR.';
            }
        }

        if ($type === 'calc') {
            if (!\is_string($field['formula'] ?? null) || trim($field['formula']) === '') {
                $errors[] = $where . ': a calculation needs a "formula", such as "{f_qty} * {f_price}".';
            }

            if (isset($field['decimals']) && (!\is_int($field['decimals']) || $field['decimals'] < 0 || $field['decimals'] > 10)) {
                $errors[] = $where . ': "decimals" must be from 0 to 10.';
            }

            foreach (['prefix', 'suffix'] as $k) {
                if (isset($field[$k]) && (!\is_string($field[$k]) || mb_strlen($field[$k]) > 20)) {
                    $errors[] = $where . ': "' . $k . '" is up to 20 characters, such as "$" or "km".';
                }
            }

            if (isset($field['thousands']) && !\is_bool($field['thousands'])) {
                $errors[] = $where . ': "thousands" must be true or false.';
            }

            // 0.13.1: age() is the whole formula (its number is years, months
            // and days packed together, so sums with it mean nothing).
            if (\is_string($field['formula'] ?? null) && preg_match('/\bage\s*\(/i', $field['formula']) && !Field\CalcField::isAge($field)) {
                $errors[] = $where . ': age() must be the whole formula, such as "age({f_born}, today())".';
            }
        }

        if (\in_array($type, ['select', 'radios', 'checkboxes', 'multiselect', 'imagechoice'], true)) {
            $items  = $type === 'imagechoice' ? ($field['choices'] ?? []) : ($field['options']['static'] ?? []);
            $capped = false;

            foreach (\is_array($items) ? $items : [] as $oi => $o) {
                if (\is_array($o) && \array_key_exists('max', $o)) {
                    $capped = true;

                    if (!\is_int($o['max']) || $o['max'] < 0 || $o['max'] > 100000) {
                        $errors[] = $where . ': choice ' . ($oi + 1) . '’s "max" (places) must be a whole number from 0 to 100,000.';
                    }
                }
            }

            if ($capped && $inRepeater) {
                $errors[] = $where . ': choices with a number of places can’t go inside a repeating group.';
            }

            if (isset($field['show_left']) && !\is_bool($field['show_left'])) {
                $errors[] = $where . ': "show_left" must be true or false.';
            }
        }

        if ($type === 'divider' && isset($field['space']) && !\in_array($field['space'], ['none', 'small', 'medium', 'large'], true)) {
            $errors[] = $where . ': a divider\'s "space" must be "none", "small", "medium" or "large".';
        }

        if ($type === 'html' && isset($field['parts_by']) && !\is_string($field['parts_by'])) {
            $errors[] = $where . ': "parts_by" is the key of the field whose answer picks the parts to show.';
        }

        foreach (['hide_input', 'counter', 'collapsible', 'collapsed', 'search', 'named', 'align_parts', 'striped'] as $b) {
            if (\array_key_exists($b, $field) && !\is_bool($field[$b])) {
                $errors[] = $where . ': "' . $b . '" must be true or false.';
            }
        }

        // 0.14.0: stripes between options: a several-choice dropdown, or one with Type to search.
        if (!empty($field['striped']) && !($type === 'multiselect' || $type === 'select' && !empty($field['search']))) {
            $errors[] = $where . ': "striped" works on Dropdown (several choices), or on a dropdown with "search": true (a plain dropdown\'s list is drawn by the browser).';
        }

        // 0.13.2: Formatted text may keep fewer tags and attributes than SquidForm's list.
        foreach (['allowed_tags' => Html::TAGS, 'allowed_attributes' => array_values(array_diff(Html::ATTRIBUTES, ['data-show-when']))] as $k => $all) {
            if (!\array_key_exists($k, $field)) {
                continue;
            }

            if ($type !== 'editor') {
                $errors[] = $where . ': "' . $k . '" is for Formatted text fields.';
            } elseif (!\is_array($field[$k]) || array_filter($field[$k], static fn ($t) => !\is_string($t))) {
                $errors[] = $where . ': "' . $k . '" must be a list such as ["p", "strong", "a"].';
            } elseif ($bad = array_diff($field[$k], $all)) {
                $errors[] = $where . ': "' . $k . '" can only narrow SquidForm\'s list; not allowed: ' . implode(', ', array_map('strval', $bad)) . '. Choose from: ' . implode(', ', $all) . '.';
            }
        }

        if (\array_key_exists('html_note', $field) && ($type !== 'editor' || !\is_string($field['html_note']) || mb_strlen($field['html_note']) > 500)) {
            $errors[] = $where . ': "html_note" is text up to 500 characters, on a Formatted text field.';
        }

        // Choices in columns: the builder offers 1 to 3, JSON takes up to 12
        // (0.13.2); "2" in quotes counts as 2.
        if (\in_array($type, ['radios', 'checkboxes'], true) && \array_key_exists('columns', $field)) {
            $c = $field['columns'];

            if (!((\is_int($c) || (\is_string($c) && ctype_digit($c))) && (int) $c >= 1 && (int) $c <= 12)) {
                $errors[] = $where . ': "columns" is ' . json_encode($c) . '; use 1 to 12, or "inline": true for a row.';
            }
        }

        // 0.13.0: named values are calculations that aren't shown.
        if (!empty($field['named']) && ($type !== 'calc' || $inRepeater)) {
            $errors[] = $where . ': "named" is for a Calculation outside a repeating group (a value worked out once and not shown).';
        }

        if ($type === 'section') {
            if ($inRepeater) {
                $errors[] = $where . ': a section can’t go inside a repeating group.';
            }

            if (\array_key_exists('style', $field) && !\in_array($field['style'], ['box', 'rule'], true)) {
                $errors[] = $where . ': section "style" must be "box" or "rule".';
            }

            // 0.9.1: a section holds its own fields (no sections inside sections).
            $kids = $field['fields'] ?? [];

            if (!\is_array($kids) || !array_is_list($kids)) {
                $errors[] = $where . ': a section\'s "fields" must be a list.';
            } elseif (!$inRepeater) {
                foreach ($kids as $ci => $child) {
                    if (\is_array($child) && ($child['type'] ?? '') === 'section') {
                        $errors[] = $where . '.fields[' . $ci . ']: a section can’t go inside another section.';
                        continue;
                    }

                    self::validateField($child, $where . '.fields[' . $ci . ']', $keys, $fieldKeys, $errors, null, $page);
                }
            }
        }

        if (\array_key_exists('mask', $field) && (!\in_array($type, ['text', 'tel'], true) || !\is_string($field['mask']) || !preg_match(self::MASK, $field['mask']) || !preg_match('/[9a*]/', $field['mask']))) {
            $errors[] = $where . ': "mask" is for text and phone fields: up to 60 characters, using 9 (a digit), a (a letter) and * (either), for example "(999) 999-9999".';
        }

        if ($type === 'rating') {
            $max = $field['max'] ?? 5;

            if (!\is_int($max) || $max < 2 || $max > 10) {
                $errors[] = $where . ': a rating\'s "max" must be from 2 to 10.';
            }
        }

        if ($type === 'time') {
            foreach (['min', 'max'] as $r) {
                if (isset($field['rules'][$r]) && !preg_match('/^\d{2}:\d{2}$/', (string) $field['rules'][$r])) {
                    $errors[] = $where . ': rules.' . $r . ' must look like "14:30".';
                }
            }
        }

        // Date limits (0.13.0): fixed or "today+N", weekdays, hours, after another date, shortest range.
        if (\in_array($type, ['date', 'datetime', 'daterange'], true)) {
            $rules = (array) ($field['rules'] ?? []);

            foreach (['min', 'max'] as $r) {
                if (isset($rules[$r]) && !DateLimits::validRule($rules[$r], $type)) {
                    $errors[] = $where . ': rules.' . $r . ' must be a date like ' . ($type === 'datetime' ? '"2026-12-31T14:30"' : '"2026-12-31"') . ', or "today", "today+2", "today-30".';
                }
            }

            if (isset($rules['weekdays']) && (!\is_array($rules['weekdays']) || !$rules['weekdays'] || array_filter($rules['weekdays'], static fn ($d) => !\is_int($d) || $d < 1 || $d > 7))) {
                $errors[] = $where . ': rules.weekdays must list days from 1 (Monday) to 7 (Sunday), like [1, 2, 3, 4, 5].';
            }

            if (isset($rules['hours'])) {
                $h = $rules['hours'];

                if ($type !== 'datetime' || !\is_array($h) || !preg_match('/^\d{2}:\d{2}$/', (string) ($h['from'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/', (string) ($h['to'] ?? '')) || $h['from'] >= $h['to']) {
                    $errors[] = $where . ': rules.hours is for date-and-time fields: {"from": "09:00", "to": "17:00"}, from before to.';
                }
            }

            // Its field is checked once every field is known (below, with confirm_email).
            if (isset($rules['after'])) {
                $a = $rules['after'];

                if (!\is_array($a) || !\is_string($a['field'] ?? null) || ($a['field'] ?? '') === ($field['key'] ?? '')
                    || (isset($a['days']) && (!\is_int($a['days']) || $a['days'] < 0 || $a['days'] > 3650))) {
                    $errors[] = $where . ': rules.after needs another date field\'s key, and "days" from 0 to 3650: {"field": "f_start", "days": 1}.';
                }
            }

            if ($type === 'daterange' && isset($field['min_days']) && (!\is_int($field['min_days']) || $field['min_days'] < 1 || (isset($field['max_days']) && \is_int($field['max_days']) && $field['min_days'] > $field['max_days']))) {
                $errors[] = $where . ': "min_days" must be a whole number, 1 or more, and not more than max_days.';
            }
        }

        // 0.8.1
        // 0.9.2
        if ($type === 'email' && \array_key_exists('confirm', $field)) {
            if (!\is_bool($field['confirm'])) {
                $errors[] = $where . ': "confirm" must be true or false.';
            } elseif ($field['confirm'] && $inRepeater) {
                $errors[] = $where . ': "Ask for it twice" isn’t available inside a repeating group.';
            }
        }

        if ($type === 'ranking') {
            $items = Field\GridField::items($field['items'] ?? null);
            $vals  = array_column($items, 'value');

            if (\count($items) < 2 || \count($items) > 20 || \count($items) !== \count((array) ($field['items'] ?? [])) || \count(array_unique($vals)) !== \count($vals)
                || array_filter($vals, static fn ($v) => $v === '' || preg_match('/[\[\]]/', $v))) {
                $errors[] = $where . ': a ranking needs "items": 2–20 texts or {"value", "label"}, each value once (no [ or ]).';
            }
        }

        if ($type === 'slider') {
            foreach (['min', 'max', 'step', 'default'] as $k) {
                if (isset($field[$k]) && !is_numeric($field[$k])) {
                    $errors[] = $where . ': a slider\'s "' . $k . '" must be a number.';
                }
            }

            if (is_numeric($field['min'] ?? 0) && is_numeric($field['max'] ?? 100) && (float) ($field['max'] ?? 100) <= (float) ($field['min'] ?? 0)) {
                $errors[] = $where . ': a slider\'s "max" must be more than its "min".';
            }

            if (isset($field['step']) && is_numeric($field['step']) && (float) $field['step'] <= 0) {
                $errors[] = $where . ': a slider\'s "step" must be more than 0.';
            }
        }

        if ($type === 'daterange') {
            if (isset($field['max_days']) && (!\is_int($field['max_days']) || $field['max_days'] < 1)) {
                $errors[] = $where . ': "max_days" must be a whole number of days, 1 or more.';
            }
        }

        if ($type === 'phone' && isset($field['default_country']) && !isset(Places::DIAL[$field['default_country']])) {
            $errors[] = $where . ': "default_country" must be a two-letter country code with a dialling code, such as "US" or "GB".';
        }

        if ($type === 'consent' && (!\is_string($field['terms'] ?? null) || trim(strip_tags((string) $field['terms'])) === '')) {
            $errors[] = $where . ': a consent block needs "terms": the text people agree to.';
        }

        if ($type === 'imagechoice') {
            $cs   = \is_array($field['choices'] ?? null) ? $field['choices'] : [];
            $vals = [];

            if (\count($cs) < 1 || \count($cs) > 50) {
                $errors[] = $where . ': an image choice needs "choices": 1–50 of {"value", "label", "image", "alt"}.';
            }

            foreach ($cs as $ci => $c) {
                if (!\is_array($c) || !isset($c['value']) || !\is_scalar($c['value']) || (string) $c['value'] === '') {
                    $errors[] = $where . '.choices[' . $ci . ']: needs a "value".';
                    continue;
                }

                $vals[] = (string) $c['value'];

                if (isset($c['image']) && (string) $c['image'] !== '' && !Field\ImageChoiceField::safePath(Field\ImageChoiceField::cleanPath((string) $c['image']))) {
                    $errors[] = $where . '.choices[' . $ci . ']: "image" must be a path on this site, such as images/forms/a.jpg (no web addresses).';
                }
            }

            if (\count(array_unique($vals)) !== \count($vals)) {
                $errors[] = $where . ': each image choice needs its own "value".';
            }

            if (isset($field['multiple']) && !\is_bool($field['multiple'])) {
                $errors[] = $where . ': "multiple" must be true or false.';
            }
        }

        if ($type === 'upload') {
            if (isset($field['max_files']) && (!\is_int($field['max_files']) || $field['max_files'] < 1 || $field['max_files'] > 20)) {
                $errors[] = $where . ': "max_files" must be from 1 to 20.';
            }

            if (isset($field['max_mb']) && (!\is_int($field['max_mb']) || $field['max_mb'] < 1)) {
                $errors[] = $where . ': "max_mb" must be a whole number of megabytes (the Options set the most).';
            }

            if (isset($field['accept']) && (!\is_array($field['accept']) || $field['accept'] === []
                || array_filter($field['accept'], static fn ($t) => !\is_string($t) || !preg_match('/^\.?[A-Za-z0-9]{1,10}$/', $t)))) {
                $errors[] = $where . ': "accept" is a list of file extensions such as ["pdf", "docx"].';
            } elseif (isset($field['accept']) && array_intersect(array_map(static fn ($t) => strtolower(ltrim($t, '.')), $field['accept']), Uploads::NEVER)) {
                $errors[] = $where . ': "accept" lists a type SquidForm never accepts (programs, scripts, web pages, SVG).';
            }
        }

        if ($inRepeater && \in_array($type, ['signature', 'grid', 'upload'], true)) {
            $errors[] = $where . ': a ' . $type . ' can’t go inside a repeating group.';
        }

        if ($type === 'signature') {
            $m = $field['modes'] ?? ['draw', 'type'];

            if (!\is_array($m) || $m === [] || array_diff($m, ['draw', 'type']) !== []) {
                $errors[] = $where . ': a signature\'s "modes" is a list of "draw" and/or "type".';
            }

            // E-signature (0.11.0)
            if (\array_key_exists('esign', $field)) {
                $es = $field['esign'];

                if (!\is_array($es) || (array_is_list($es) && $es !== [])) {
                    $errors[] = $where . ': "esign" must be an object such as {"statement": "I agree to sign this electronically."}.';
                } else {
                    if (isset($es['statement']) && (!\is_string($es['statement']) || mb_strlen($es['statement']) > 1000)) {
                        $errors[] = $where . ': esign.statement must be text of at most 1,000 characters.';
                    }

                    if (isset($es['disclosure']) && (!\is_string($es['disclosure']) || mb_strlen($es['disclosure']) > 20000)) {
                        $errors[] = $where . ': esign.disclosure must be text (HTML) of at most 20,000 characters.';
                    }

                    if (isset($es['after_edit']) && !\in_array($es['after_edit'], ['lock', 'void'], true)) {
                        $errors[] = $where . ': esign.after_edit must be "lock" or "void".';
                    }

                    if (isset($es['retain_years']) && (!\is_int($es['retain_years']) || $es['retain_years'] < 0 || $es['retain_years'] > 100)) {
                        $errors[] = $where . ': esign.retain_years must be a whole number from 0 to 100.';
                    }

                    if (isset($es['send_copy']) && !\is_bool($es['send_copy'])) {
                        $errors[] = $where . ': esign.send_copy must be true or false.';
                    }

                    if ($parent !== null) {
                        $errors[] = $where . ': an e-signature can’t be inside a repeating group.';
                    }
                }
            }
        }

        if ($type === 'grid') {
            $cell = $field['cell'] ?? 'radio';

            if (!\in_array($cell, Field\GridField::CELLS, true)) {
                $errors[] = $where . ': a grid\'s "cell" must be "radio", "checkbox", "text" or "select".';
            }

            foreach (['rows', 'columns'] as $part) {
                $items = Field\GridField::items($field[$part] ?? null);
                $vals  = array_column($items, 'value');

                if (!\is_array($field[$part] ?? null) || $items === [] || \count($items) !== \count($field[$part]) || \count($items) > 50) {
                    $errors[] = $where . ': a grid needs "' . $part . '": a list of 1–50 texts or {"value", "label"} objects.';
                } elseif (\count(array_unique($vals)) !== \count($vals) || array_filter($vals, static fn ($v) => $v === '' || preg_match('/[\[\]]/', $v) || mb_strlen($v) > 100)) {
                    $errors[] = $where . ': each of the grid\'s ' . $part . ' needs its own value (up to 100 characters, no [ or ]).';
                }
            }

            if ($cell === 'select') {
                $shared = Field\GridField::items($field['choices'] ?? null);

                foreach (Field\GridField::items($field['columns'] ?? null) as $c) {
                    if (empty($c['choices']) && $shared === []) {
                        $errors[] = $where . ': a grid of dropdowns needs "choices" (for every column), or "choices" on column "' . $c['value'] . '".';
                        break;
                    }
                }
            }
        }

        if ($type === 'name' && \array_key_exists('parts', $field)
            && (!\is_array($field['parts']) || $field['parts'] === [] || array_diff($field['parts'], Field\NameField::PARTS) !== [])) {
            $errors[] = $where . ': a name\'s "parts" is a list from title, first, middle, last and suffix.';
        }

        if ($type === 'address') {
            if (\array_key_exists('format', $field) && !\in_array($field['format'], ['us', 'intl'], true) && !(\is_string($field['format']) && AddressFormats::get($field['format']) !== null)) {
                $errors[] = $where . ': an address\'s "format" must be "us", "intl"' . (AddressFormats::all() ? ' or one of ' . implode(', ', array_map(static fn ($k) => '"' . $k . '"', array_keys(AddressFormats::all()))) : '') . '.';
            }

            if (isset($field['default_country']) && !isset(Places::COUNTRIES[$field['default_country']])) {
                $errors[] = $where . ': "default_country" must be a two-letter country code such as "US" or "GB".';
            }
        }

        if ($type === 'lookup') {
            if (isset($field['min_chars']) && (!\is_int($field['min_chars']) || $field['min_chars'] < 1 || $field['min_chars'] > 5)) {
                $errors[] = $where . ': "min_chars" must be from 1 to 5.';
            }
        }

        if (\in_array($type, ['select', 'radios', 'checkboxes', 'multiselect', 'lookup'], true)) {
            $options = $field['options'] ?? null;

            if (!\is_array($options) || \count(array_intersect(array_keys($options), ['static', 'table', 'file', 'user_field', 'form', 'addon'])) !== 1) {
                $errors[] = $where . ': "options" needs exactly one of static, table, file, user_field, form or addon.';
            } elseif (isset($options['form'])) {
                $fo = $options['form'];

                if (!\is_array($fo) || !\is_string($fo['alias'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,399}$/', $fo['alias'])
                    || !\is_string($fo['value'] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $fo['value'])
                    || (isset($fo['label']) && (!\is_string($fo['label']) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $fo['label'])))) {
                    $errors[] = $where . ': options.form needs "alias" (the other form\'s alias), "value" and "label" (field keys on that form).';
                } else {
                    foreach ((array) ($fo['where']['all'] ?? []) as $c) {
                        if (!\is_array($c) || !\is_string($c['field'] ?? null) || !\in_array($c['op'] ?? 'eq', ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'], true)) {
                            $errors[] = $where . ': options.form.where is {"all": [{"field": …, "op": eq|neq|gt|gte|lt|lte, "value": …}]}.';
                            break;
                        }
                    }

                    if (isset($fo['where']) && (!\is_array($fo['where']) || array_diff(array_keys($fo['where']), ['all']))) {
                        $errors[] = $where . ': options.form.where takes "all" only.';
                    }

                    if (isset($fo['order']) && (!\is_string($fo['order']) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s+(asc|desc))?$/i', trim($fo['order'])))) {
                        $errors[] = $where . ': options.form.order is a field key, then asc or desc.';
                    }
                }
            } elseif (isset($options['addon'])) {
                if (!\is_string($options['addon']) || !preg_match('/^[a-z][a-z0-9_.-]{0,59}$/', $options['addon'])) {
                    $errors[] = $where . ': options.addon is the name of a choice list an add-on offers, such as "tidetable.categories".';
                }
            } elseif (isset($options['user_field'])) {
                if (!\is_string($options['user_field']) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $options['user_field'])) {
                    $errors[] = $where . ': options.user_field is the name of a Joomla List, Radio or Checkboxes user field.';
                }
            } elseif (isset($options['table'])) {
                foreach (['name', 'value', 'label'] as $part) {
                    if (empty($options['table'][$part]) || !\is_string($options['table'][$part])) {
                        $errors[] = $where . ': options.table.' . $part . ' is required.';
                    }
                }
            } elseif (isset($options['file']) && (empty($options['file']['path']) || !\is_string($options['file']['path']))) {
                $errors[] = $where . ': options.file.path is required.';
            }
        }

        if ($type === 'user') {
            $g = $field['groups'] ?? null;

            if (!\is_array($g) || $g === [] || !array_is_list($g) || array_filter($g, static fn ($x) => !\is_string($x) && !\is_int($x))) {
                $errors[] = $where . ': a user field needs "groups": a list of user group titles or ids.';
            }

            if (\array_key_exists('exclude_self', $field) && !\is_bool($field['exclude_self'])) {
                $errors[] = $where . ': "exclude_self" must be true or false.';
            }
        }

        if ($type === 'repeater') {
            if ($inRepeater) {
                $errors[] = $where . ': repeaters can nest only one level in this version.';
            }

            $children = $field['fields'] ?? null;

            if (!\is_array($children) || $children === [] || !array_is_list($children)) {
                $errors[] = $where . ': a repeater needs a non-empty "fields" list.';
            } else {
                foreach ($children as $ci => $child) {
                    self::validateField($child, $where . '.fields[' . $ci . ']', $keys, $fieldKeys, $errors, (string) $key, $page);
                }
            }

            $min = (int) ($field['min'] ?? 0);
            $max = (int) ($field['max'] ?? 50);

            if ($min < 0 || $max < 1 || $min > $max || $max > 500) {
                $errors[] = $where . ': repeater min/max must satisfy 0 <= min <= max <= 500.';
            }
        }

        if (isset($field['rules']) && !\is_array($field['rules'])) {
            $errors[] = $where . ': "rules" must be an object.';
        }

        if (!empty($field['rules']['pattern']) && ($msg = self::checkPattern((string) $field['rules']['pattern'])) !== null) {
            $errors[] = $where . ': rules.pattern ' . $msg;
        }

        if (isset($field['prefill']) && !\is_string($field['prefill'])) {
            $errors[] = $where . ': "prefill" must be a placeholder string such as {user:email}.';
        }
    }

    /**
     * @param   mixed                $actions
     * @param   array<string, true>  $keys
     * @param   string[]             $errors
     */
    private static function validateActions($actions, string $where, array &$keys, array &$errors, string $event = 'submit'): void
    {
        if (!\is_array($actions) || !array_is_list($actions)) {
            $errors[] = $where . ' must be a list of actions.';

            return;
        }

        foreach ($actions as $ai => $action) {
            $w = $where . '[' . $ai . ']';

            if (!\is_array($action)) {
                $errors[] = $w . ' must be an object.';
                continue;
            }

            self::checkKey($action['key'] ?? null, $w, $keys, $errors);

            if (!\is_string($action['type'] ?? null) || !ActionRegistry::has($action['type'])) {
                $errors[] = $w . ': unknown action type "' . (\is_string($action['type'] ?? null) ? $action['type'] : '?') . '".';
            }

            if (isset($action['config']) && !\is_array($action['config'])) {
                $errors[] = $w . ': "config" must be an object.';
            }

            $cfg = \is_array($action['config'] ?? null) ? $action['config'] : [];

            // Read data (0.10.0)
            if (($action['type'] ?? '') === 'read_data') {
                $mode = $cfg['mode'] ?? 'one';

                if (!\is_string($cfg['table'] ?? null) || trim($cfg['table']) === '') {
                    $errors[] = $w . ': Read data needs "table" (a table or view on the read allow-list).';
                }

                if (!\in_array($mode, ['one', 'all', 'count'], true)) {
                    $errors[] = $w . ': "mode" must be "one", "all" or "count".';
                }

                if ($mode !== 'count' && (!\is_array($cfg['columns'] ?? null) || !array_is_list($cfg['columns']) || $cfg['columns'] === [] || \count($cfg['columns']) > 50
                    || array_filter($cfg['columns'], static fn ($c) => !\is_string($c) || !preg_match('/^(?:[a-z][a-z0-9_]{0,15}\.)?[A-Za-z_][A-Za-z0-9_]{0,63}$/', $c)))) {
                    $errors[] = $w . ': "columns" is a list of 1–50 column names (c.title for a joined table).';
                }

                if (isset($cfg['join']) && (!\is_array($cfg['join']) || !array_is_list($cfg['join']) || \count($cfg['join']) > 3)) {
                    $errors[] = $w . ': "join" is a list of up to 3 tables.';
                } else {
                    foreach ((array) ($cfg['join'] ?? []) as $ji => $j) {
                        if (!\is_array($j) || !\is_string($j['table'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,15}$/', (string) ($j['as'] ?? '')) || ($j['as'] ?? '') === 't'
                            || !\is_array($j['on'] ?? null) || \count($j['on']) !== 2 || !\in_array($j['type'] ?? 'left', ['left', 'inner'], true)) {
                            $errors[] = $w . '.join[' . $ji . ']: needs "table", "as" (a short name, not "t"), "on": [column, its column] and "type" "left" or "inner".';
                        }
                    }
                }

                if (isset($cfg['where']) && (!\is_array($cfg['where']) || array_is_list($cfg['where']) && $cfg['where'] !== [])) {
                    $errors[] = $w . ': "where" must be an object such as {"all": [{"col": "id", "op": "eq", "value": "{url:id}"}]}.';
                }

                if (isset($cfg['limit']) && (!\is_int($cfg['limit']) || $cfg['limit'] < 1 || $cfg['limit'] > 500)) {
                    $errors[] = $w . ': "limit" must be from 1 to 500.';
                }

                if (isset($cfg['fill']) && (!\is_array($cfg['fill']) || array_is_list($cfg['fill']) && $cfg['fill'] !== [])) {
                    $errors[] = $w . ': "fill" is {"field key": "column"}.';
                }
            }

            // Email routes (0.11.0): who it goes to depends on the answers.
            if (($action['type'] ?? '') === 'email' && \array_key_exists('routes', $cfg)) {
                if (!\is_array($cfg['routes']) || !array_is_list($cfg['routes']) || \count($cfg['routes']) > 50) {
                    $errors[] = $w . ': "routes" is a list of up to 50 {"when": condition, "to": "addresses"}.';
                } else {
                    foreach ($cfg['routes'] as $ri => $r) {
                        if (!\is_array($r) || !\is_string($r['to'] ?? null) || trim($r['to']) === '' || !\is_array($r['when'] ?? null) || $r['when'] === []
                            || (isset($r['cc']) && !\is_string($r['cc']))) {
                            $errors[] = $w . '.routes[' . $ri . ']: needs "when" (a condition) and "to" (addresses); "cc" is optional.';
                        }
                    }
                }

                if (isset($cfg['route_match']) && !\in_array($cfg['route_match'], ['first', 'all'], true)) {
                    $errors[] = $w . ': "route_match" must be "first" or "all".';
                }

                if (isset($cfg['attach_csv']) && !\is_bool($cfg['attach_csv'])) {
                    $errors[] = $w . ': "attach_csv" must be true or false.';
                }
            }

            // Confirmation email (0.11.0)
            if (($action['type'] ?? '') === 'confirm_email') {
                foreach (['subject' => 500, 'body' => 50000, 'from_name' => 200, 'reply_to' => 500] as $k => $most) {
                    if (isset($cfg[$k]) && (!\is_string($cfg[$k]) || mb_strlen($cfg[$k]) > $most)) {
                        $errors[] = $w . ': "' . $k . '" must be text of at most ' . number_format($most) . ' characters.';
                    }
                }

                if (isset($cfg['attach_pdf']) && !\is_bool($cfg['attach_pdf'])) {
                    $errors[] = $w . ': "attach_pdf" must be true or false.';
                }

                if (isset($cfg['attach_csv']) && !\is_bool($cfg['attach_csv'])) {
                    $errors[] = $w . ': "attach_csv" must be true or false.';
                }

                if ($event !== 'submit') {
                    $errors[] = $w . ': a confirmation email is sent when the form is sent; put it under submit.';
                }
            }

            // Run PHP handler (0.10.0)
            if (($action['type'] ?? '') === 'run_php') {
                if (!\is_string($cfg['handler'] ?? null) || !preg_match('/^[A-Z][A-Za-z0-9_]{0,63}$/', $cfg['handler'])) {
                    $errors[] = $w . ': pick a PHP handler ("handler" is its name: a starter ticked in Options → Developers, or one in the handler folder).';
                }

                if (isset($cfg['settings']) && (!\is_array($cfg['settings']) || array_filter($cfg['settings'], static fn ($v) => !\is_scalar($v)))) {
                    $errors[] = $w . ': a handler\'s "settings" are names and plain values.';
                }
            }

            // Redirect (0.12.0 security pass): never a script or data address. Checked when the form is sent too.
            if (($action['type'] ?? '') === 'redirect' && preg_match('#^(javascript|data|vbscript|file):#i', (string) preg_replace('/[\s\x00-\x1f]+/', '', (string) ($cfg['url'] ?? '')))) {
                $errors[] = $w . ': a redirect goes to a web address on this site (or an allowed one), never a javascript:, data:, vbscript: or file: address.';
            }

            // Load events run on every page view, outside any transaction: only
            // actions that change nothing are allowed there.
            if ($event === 'load' && \in_array($action['type'] ?? '', ['save_data', 'email', 'redirect'], true)) {
                $errors[] = $w . ': "' . $action['type'] . '" cannot run on a load event; put it under submit.';
            }

            // Hooks run after someone saves an edit or decides on an approval:
            // there is no page to show a message on or redirect, and nothing new to save.
            if ($event === 'hook' && \in_array($action['type'] ?? '', ['save_data', 'message', 'redirect'], true)) {
                $errors[] = $w . ': "' . $action['type'] . '" cannot be used in ' . preg_replace('/\[.*$/', '', $where) . '; use email.';
            }
        }
    }

    /**
     * @param   mixed                $key
     * @param   array<string, true>  $keys
     * @param   string[]             $errors
     */
    private static function checkKey($key, string $where, array &$keys, array &$errors): bool
    {
        if (!\is_string($key) || !preg_match(self::KEY_PATTERN, $key)) {
            $errors[] = $where . ': "key" is missing or invalid (letter first, then letters, digits or _; max 64).';

            return false;
        }

        if (isset($keys[$key])) {
            $errors[] = $where . ': key "' . $key . '" is used more than once.';

            return false;
        }

        $keys[$key] = true;

        return true;
    }

    /**
     * @param   mixed                                             $cond
     * @param   array<string, array{page: int, parent: ?string}>  $fieldKeys
     * @param   string[]                                          $errors
     */
    private static function checkCondition($cond, string $where, ?string $scope, int $page, bool $allowAction, array $fieldKeys, array &$errors, int $depth = 0): void
    {
        if ($depth > 16) {
            $errors[] = $where . ': condition is nested more than 16 levels.';

            return;
        }

        if (!\is_array($cond) || array_is_list($cond) && $cond !== []) {
            $errors[] = $where . ': a condition must be an object such as {"field": "f_x", "op": "eq", "value": "y"}.';

            return;
        }

        if ($cond === []) {
            return;
        }

        foreach (['all', 'any'] as $g) {
            if (\array_key_exists($g, $cond)) {
                if (!\is_array($cond[$g]) || !array_is_list($cond[$g])) {
                    $errors[] = $where . ': "' . $g . '" must be a list of conditions.';

                    return;
                }

                foreach ($cond[$g] as $c) {
                    self::checkCondition($c, $where, $scope, $page, $allowAction, $fieldKeys, $errors, $depth + 1);
                }

                return;
            }
        }

        if (\array_key_exists('not', $cond)) {
            self::checkCondition($cond['not'], $where, $scope, $page, $allowAction, $fieldKeys, $errors, $depth + 1);

            return;
        }

        if (\array_key_exists('action', $cond)) {
            if (!$allowAction) {
                $errors[] = $where . ': "action" conditions are only allowed in an action\'s "when".';
            } elseif (!\in_array($cond['is'] ?? 'success', ['success', 'fail', 'skipped'], true)) {
                $errors[] = $where . ': "is" must be success, fail or skipped.';
            }

            return;
        }

        // Joomla user data (0.14.0), judged on the server.
        if (\array_key_exists('user_group', $cond)) {
            $g = $cond['user_group'];

            if (!(\is_string($g) && trim($g) !== '' || \is_int($g)) && !(\is_array($g) && $g !== [] && array_is_list($g) && !array_filter($g, static fn ($x) => !(\is_string($x) && trim($x) !== '' || \is_int($x))))) {
                $errors[] = $where . ': "user_group" is a user group title or id, or a list of them.';
            }

            return;
        }

        if (\array_key_exists('session', $cond)) {
            $op = $cond['op'] ?? 'eq';

            if (!\is_string($cond['session']) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,200}$/', $cond['session'])) {
                $errors[] = $where . ': "session" is a dotted path in the session, such as "abcdefuser.employeetype".';
            } elseif (!\is_string($op) || !\in_array($op, Conditions::OPS, true)) {
                $errors[] = $where . ': unknown operator "' . (\is_string($op) ? $op : '?') . '" (use ' . implode(', ', Conditions::OPS) . ').';
            }

            return;
        }

        if (\array_key_exists('user_field', $cond)) {
            $op = $cond['op'] ?? 'eq';

            if (!\is_string($cond['user_field']) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $cond['user_field'])) {
                $errors[] = $where . ': "user_field" is the name of a Joomla user field, such as "department".';
            } elseif (!\is_string($op) || !\in_array($op, Conditions::OPS, true)) {
                $errors[] = $where . ': unknown operator "' . (\is_string($op) ? $op : '?') . '" (use ' . implode(', ', Conditions::OPS) . ').';
            } elseif ($op === 'matches' && ($msg = self::checkPattern((string) ($cond['value'] ?? ''))) !== null) {
                $errors[] = $where . ': "matches" pattern ' . $msg;
            }

            return;
        }

        if (!\array_key_exists('field', $cond) || !\is_string($cond['field'])) {
            $errors[] = $where . ': a condition needs "field", "all", "any", "not", "action", "user_group", "user_field" or "session".';

            return;
        }

        $ref  = $cond['field'];
        $info = $fieldKeys[$ref] ?? null;

        if ($info === null) {
            $errors[] = $where . ': refers to unknown field "' . $ref . '".';
        } elseif ($info['parent'] !== null && $info['parent'] !== $scope) {
            $errors[] = $where . ': "' . $ref . '" is inside repeater "' . $info['parent'] . '"; only fields in the same row can be used there.';
        } elseif ($info['page'] > $page) {
            $errors[] = $where . ': "' . $ref . '" is on a later page, so its value is not known yet.';
        }

        $op = $cond['op'] ?? 'eq';

        if (!\is_string($op) || !\in_array($op, Conditions::OPS, true)) {
            $errors[] = $where . ': unknown operator "' . (\is_string($op) ? $op : '?') . '" (use ' . implode(', ', Conditions::OPS) . ').';
        } elseif ($op === 'matches' && ($msg = self::checkPattern((string) ($cond['value'] ?? ''))) !== null) {
            $errors[] = $where . ': "matches" pattern ' . $msg;
        } elseif (\in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && (!isset($cond['value']) || $cond['value'] === '')) {
            $errors[] = $where . ': "' . $op . '" needs a value to compare with.';
        }
    }

    /**
     * Problems with a condition used outside the page flow (approval steps).
     *
     * @param   array<string, array{page: int, parent: ?string}>  $fieldKeys
     *
     * @return  string[]
     */
    public static function conditionErrors(mixed $cond, string $where, int $page, array $fieldKeys): array
    {
        $errors = [];
        self::checkCondition($cond, $where, null, $page, false, $fieldKeys, $errors);

        return $errors;
    }

    /**
     * A pattern must work the same in PHP (PCRE) and in browsers (JavaScript),
     * so inline flags such as (?i) and look-behind are refused.
     */
    public static function checkPattern(string $pattern): ?string
    {
        if ($pattern === '') {
            return 'is empty.';
        }

        if (preg_match('/\(\?[a-zA-Z<]/', $pattern)) {
            return 'uses inline flags or look-behind, which browsers do not support the same way.';
        }

        if (@preg_match(Conditions::regex($pattern), '') === false) {
            return 'is not a valid regular expression.';
        }

        return null;
    }

    /**
     * @param   array<string, mixed>  $field
     */
    private function indexField(array $field, ?string $parent): void
    {
        $this->fieldIndex[$field['key']] = $field;

        if ($parent !== null) {
            $this->parentIndex[$field['key']] = $parent;
        }

        if (($field['type'] ?? '') === 'repeater') {
            foreach ($field['fields'] as $child) {
                $this->indexField($child, $field['key']);
            }
        }
    }

    // ----------------------------------------------------------------- queries

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->def;
    }

    public function toJson(): string
    {
        return json_encode($this->def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return \is_array($this->def['settings'] ?? null) ? $this->def['settings'] : [];
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->settings()[$name] ?? $default;
    }

    /** @return list<array<string, mixed>> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function pageCount(): int
    {
        return \count($this->pages);
    }

    /** @return array<string, mixed> */
    public function page(int $index): array
    {
        return $this->pages[$index] ?? throw new \OutOfRangeException('No page ' . $index);
    }

    /**
     * A section runs from its own entry to the next section (or the end of the
     * page). Its show_if is added to each field in it.
     *
     * @param  list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private static function withSections(array $pages): array
    {
        // Sections that hold their fields (0.9.1) are laid out flat for the
        // engine: the section, its fields, then a "section_end" marker.
        foreach ($pages as &$pg) {
            if (!\is_array($pg['fields'] ?? null)) {
                continue;
            }

            $flat = [];

            foreach ($pg['fields'] as $f) {
                if (($f['type'] ?? '') === 'section' && \is_array($f['fields'] ?? null)) {
                    $kids = $f['fields'];
                    unset($f['fields']);
                    $flat[] = $f;

                    foreach ($kids as $k) {
                        $flat[] = $k;
                    }

                    $flat[] = ['key' => $f['key'] . '__end', 'type' => 'section_end'];
                    continue;
                }

                $flat[] = $f;
            }

            $pg['fields'] = $flat;
        }

        unset($pg);

        foreach ($pages as &$page) {
            $cond = null;

            if (!isset($page['fields']) || !\is_array($page['fields'])) {
                continue;
            }

            // By reference into the page itself (not "?? []", which would be a copy).
            foreach ($page['fields'] as &$f) {
                if (($f['type'] ?? '') === 'section') {
                    $cond = !empty($f['show_if']) ? $f['show_if'] : null;
                    continue;
                }

                if (($f['type'] ?? '') === 'section_end') {
                    $cond = null;
                    continue;
                }

                if ($cond !== null) {
                    $f['show_if'] = !empty($f['show_if']) ? ['all' => [$cond, $f['show_if']]] : $cond;
                }
            }

            unset($f);
        }

        unset($page);

        return $pages;
    }

    /**
     * Top-level fields of a page (repeater children stay inside their repeater).
     *
     * @return list<array<string, mixed>>
     */
    public function pageFields(int $index): array
    {
        return $this->page($index)['fields'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function actions(int $pageIndex, string $event): array
    {
        return $this->page($pageIndex)['events'][$event] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function field(string $key): ?array
    {
        return $this->fieldIndex[$key] ?? null;
    }

    public function parentOf(string $key): ?string
    {
        return $this->parentIndex[$key] ?? null;
    }

    /**
     * Every field that stores a value, in form order, top level only
     * (repeaters are returned as one field whose value is a list of rows).
     *
     * @return list<array<string, mixed>>
     */
    public function valueFields(): array
    {
        $out = [];

        foreach ($this->pages() as $page) {
            foreach ($page['fields'] ?? [] as $f) {
                if (FieldRegistry::get($f['type'])->storesValue()) {
                    $out[] = $f;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> the optional "viewer" block (list defaults) */
    public function viewer(): array
    {
        return \is_array($this->def['viewer'] ?? null) ? $this->def['viewer'] : [];
    }

    /** @return array<string, mixed>|null */
    public function approval(): ?array
    {
        return $this->def['approval'] ?? null;
    }
}
