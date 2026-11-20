<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Import;

\defined('_JEXEC') or die;

/**
 * One ChronoForms 8 form → a SquidForm definition (0.12.0).
 *
 * CF8 keeps a form as "elements": pages, views (fields, layout, text, code)
 * and actions, each with a parent, a section ("load" = on the page, "submit"
 * = when it's sent) and a list of behaviours (required, placeholder, …).
 * Show/hide is done with triggers ("when my value is in [x], fire T") and
 * listeners ("on T, show me"); those become show_if conditions.
 *
 * Nothing here runs code from the form. PHP actions and validation
 * functions are reported, never imported. Custom JavaScript is carried
 * across switched off, for a person to look at.
 *
 * Returns ['definition' => array, 'report' => list, 'map' => list, 'listing' => ?array].
 * Report items: ['level' => 'changed'|'todo'|'info', 'what' => …, 'text' => …].
 */
final class Cf8Converter
{
    /** @var array<string, array<string, mixed>> */
    private array $el = [];

    /** @var list<array{level: string, what: string, text: string}> */
    private array $report = [];

    /** @var list<array<string, mixed>> CF element → SquidForm key */
    private array $map = [];

    /** @var array<string, string> CF field name → SquidForm key */
    private array $names = [];

    /** @var array<string, string> CF element id → SquidForm key */
    private array $ids = [];

    /** @var array<string, int> SquidForm key → page index */
    private array $keyPage = [];

    /** @var array<string, true> */
    private array $used = [];

    /** @var array<string, true> keys of fields that hold several values */
    private array $multi = [];

    private bool $promptNoted = false;

    /** @var array<string, list<array{key: string, condition: string, values: list<string>}>> trigger name → conditions */
    private array $triggers = [];

    /** @var array<string, array<string, mixed>> read_data actions by "read_dataN" */
    private array $reads = [];

    /** @var array<string, string> 0.15.0: ChronoForms var name => the session key its PHP action returned */
    private array $sessionVars = [];

    private string $css = '';

    private string $js = '';

    private bool $captcha = false;

    private ?array $listing = null;

    /** "ChronoForms 8", or "ChronoForms 7" when Cf7Adapter shaped the form. */
    private string $source = 'ChronoForms 8';

    /** @var list<string> tables its Save Data actions wrote to */
    private array $saves = [];

    /**
     * @param array<string, mixed> $form  a row of #__chronoforms8 (or an item of a .cf8bak), elements and params decoded
     *
     * @return array{definition: array<string, mixed>, report: list<array<string, string>>, map: list<array<string, mixed>>, listing: ?array, saves: list<string>}
     */
    public static function convert(array $form, string $source = 'ChronoForms 8'): array
    {
        $c         = new self();
        $c->source = $source;

        return $c->run($form);
    }

    private function run(array $form): array
    {
        $elements = \is_array($form['elements'] ?? null) ? $form['elements'] : [];
        $params   = \is_array($form['params'] ?? null) ? $form['params'] : [];

        foreach ($elements as $id => $e) {
            if (\is_array($e)) {
                $e['id']             = (string) ($e['id'] ?? $id);
                $this->el[$e['id']] = $e;
            }
        }

        // 0.15.0: a PHP action that only returns a session value ($x = JFactory::getSession()->get('abcdefuser'); return $x;)
        // becomes SquidForm's own session reading: {var:abcdefuser.employeenumber} → {session:abcdefuser.employeenumber}.
        foreach ($this->el as $e) {
            if (($e['name'] ?? '') === 'php' && preg_match('/getSession\(\)\s*->\s*get\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]{0,63})[\'"]/', (string) ($e['code'] ?? ''), $m)) {
                foreach ([$m[1], 'php' . $e['id'], (string) ($e['label'] ?? ''), (string) ($e['designer_label'] ?? ''), (string) ($e['var'] ?? '')] as $alias) {
                    if ($alias !== '' && preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
                        $this->sessionVars[$alias] = $m[1];
                    }
                }
            }
        }

        $pageIds = array_values(array_map(static fn ($e) => $e['id'], array_filter($this->el, static fn ($e) => ($e['type'] ?? '') === 'page')));

        if (!$pageIds) {
            $pageIds = ['__one'];
        }

        // Keys first, page by page, so conditions can point at fields.
        foreach ($pageIds as $pi => $pid) {
            foreach ($this->descendants($pid) as $e) {
                $this->keyFor($e, $pi);
            }
        }

        foreach ($this->el as $e) {
            if (($e['type'] ?? '') === 'actions' && ($e['name'] ?? '') === 'read_data') {
                $this->reads['read_data' . $e['id']] = $e;
            }
        }

        $this->collectTriggers();

        $pages        = [];
        $submitLabels = [];

        foreach ($pageIds as $pi => $pid) {
            $page   = $this->el[$pid] ?? ['title' => 'Page 1'];
            $fields = $this->fields($pid, 'load', $pi, $submitLabels);
            $submit = $this->actions($pid, $pi, \count($pageIds));
            $load   = $this->loadActions($pid);

            $p = ['key' => 'p' . ($pi + 1), 'title' => trim((string) ($page['title'] ?? '')) ?: 'Page ' . ($pi + 1), 'fields' => $fields, 'events' => ['load' => $load, 'submit' => $submit]];

            if (isset($submitLabels[$pi]) && $pi < \count($pageIds) - 1) {
                $p['submit_label'] = $submitLabels[$pi];
            }

            $pages[] = $p;
        }

        // CF8 keeps every sent form in its data log when "Log data" is on (the default): Save Data here.
        $last = \count($pages) - 1;
        $has  = false;

        foreach ($pages as $p) {
            foreach ($p['events']['submit'] as $a) {
                $has = $has || $a['type'] === 'save_data';
            }
        }

        if (!$has && (string) ($params['log_data'] ?? '1') === '1' && $this->valueCount() > 0) {
            array_unshift($pages[$last]['events']['submit'], ['key' => 'a_save', 'type' => 'save_data', 'config' => ['target' => 'submissions']]);
            $this->note('changed', 'Log data', 'ChronoForms kept every submission in its data log; this form saves its submissions (Save Data).');
        }

        $this->whenSaved($pages);

        $settings = ['rate_limit' => false];

        if (isset($submitLabels[$last])) {
            $settings['submit_label'] = $submitLabels[$last];
        }

        if ($this->captcha) {
            $settings['captcha'] = 'pow';
        }

        if ($this->css !== '') {
            $settings['css'] = mb_substr($this->css, 0, 50000);
        }

        if ($this->js !== '') {
            $settings['js'] = mb_substr("// Imported from " . $this->source . " and switched off: it was written for ChronoForms'\n// page, not this one. Check it against the import report, then remove the\n// \"return;\" line below to run it.\nreturn;\n\n" . $this->js, 0, 50000);
        }

        if (!empty($params['acl'])) {
            $this->note('todo', 'Access', 'The form was limited to some user groups in ChronoForms; set its Access level in SquidForm (the form\'s Details tab).');
        }

        if (!empty($params['ajax'])) {
            $this->note('info', 'AJAX', 'ChronoForms sent this form without reloading the page; SquidForm sends it normally.');
        }

        return [
            'definition' => ['schema' => 1, 'settings' => $settings, 'pages' => $pages],
            'report'     => $this->report,
            'map'        => $this->map,
            'listing'    => $this->listing,
            'saves'      => $this->saves,
        ];
    }

    // ── Structure ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> children of an element (in CF order) */
    private function children(string $parent, ?string $section = null): array
    {
        return array_values(array_filter($this->el, static fn ($e) => (string) ($e['parent'] ?? '') === $parent
            && ($section === null || (string) ($e['section'] ?? '') === $section || ($parent !== '' && $section === 'load' && !\in_array((string) ($e['section'] ?? ''), ['submit'], true)))));
    }

    /** Every view element under a page (any depth), for working out keys. @return list<array<string, mixed>> */
    private function descendants(string $parent): array
    {
        $out = [];

        foreach ($this->el as $e) {
            if ((string) ($e['parent'] ?? '') === $parent && ($e['type'] ?? '') === 'views' && ($e['section'] ?? 'load') !== 'submit') {
                $out[] = $e;
                $out   = array_merge($out, $this->descendants($e['id']));
            }
        }

        return $out;
    }

    private function valueCount(): int
    {
        return \count(array_filter($this->map, static fn ($m) => $m['key'] !== ''));
    }

    // ── Keys ───────────────────────────────────────────────────────────────

    private function keyFor(array $e, int $page): void
    {
        $name = (string) ($e['name'] ?? '');

        if (!str_starts_with($name, 'field_') || \in_array($name, ['field_button', 'field_virtual'], true) || ($e['fieldname'] ?? '') === '') {
            if ($name === 'signature' && ($e['fieldname'] ?? '') !== '') {
                // a signature is a field too
            } else {
                return;
            }
        }

        $raw = (string) $e['fieldname'];
        $key = preg_replace('/[^A-Za-z0-9_]+/', '_', str_replace(['[]', '[', ']'], ['', '_', ''], $raw)) ?? '';
        $key = trim($key, '_');
        $key = $key === '' || !ctype_alpha($key[0]) ? 'f_' . $key : $key;
        $key = substr($key, 0, 60);
        $base = $key;

        for ($n = 2; isset($this->used[strtolower($key)]); $n++) {
            $key = $base . '_' . $n;
        }

        $this->used[strtolower($key)] = true;
        $this->names[$raw]            = $key;
        $this->names[str_replace('[]', '', $raw)] = $key;
        $this->ids[$e['id']]          = $key;
        $this->keyPage[$key]          = $page;

        if ($name === 'field_checkboxes' || ($name === 'field_select' && \in_array('multi_selection', (array) ($e['behaviors'] ?? []), true))) {
            $this->multi[$key] = true;
        }
        $this->map[] = ['cf_id' => $e['id'], 'cf_name' => $raw, 'cf_label' => (string) ($e['label'] ?? ''), 'key' => $key, 'type' => $name];

        if ($key !== $raw) {
            $this->note('changed', $raw, 'Field name "' . $raw . '" is "' . $key . '" in SquidForm (letters, digits and _ only).');
        }
    }

    // ── Fields ─────────────────────────────────────────────────────────────

    /** @param array<int, string> $submitLabels @return list<array<string, mixed>> */
    private function fields(string $parent, string $section, int $page, array &$submitLabels): array
    {
        $out = [];

        foreach ($this->children($parent, $section) as $e) {
            if (($e['type'] ?? '') !== 'views') {
                continue;
            }

            foreach ($this->field($e, $page, $submitLabels) as $f) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** @param array<int, string> $submitLabels @return list<array<string, mixed>> (0, 1 or more SquidForm fields) */
    private function field(array $e, int $page, array &$submitLabels): array
    {
        $name  = (string) ($e['name'] ?? '');
        $label = trim((string) ($e['label'] ?? ''));
        $key   = $this->ids[$e['id']] ?? null;
        $what  = $label !== '' ? $label : $name . ' ' . $e['id'];
        $b     = \is_array($e['behaviors'] ?? null) ? $e['behaviors'] : [];
        $f     = $key !== null ? ['key' => $key, 'label' => $label !== '' ? $label : $key] : [];

        switch ($name) {
            case 'field_text':
                $type = match ((string) ($e['input_type'] ?? '')) {
                    'email' => 'email', 'number' => 'number', 'tel' => 'tel', 'url' => 'url', 'date' => 'date', 'time' => 'time', 'datetime-local' => 'datetime', 'password' => 'text', default => 'text',
                };

                if (\in_array('validation_email', $b, true)) {
                    $type = 'email';
                }

                $f['type'] = $type;

                if (($e['input_type'] ?? '') === 'password') {
                    $f['hide_input'] = true;
                }

                if (\in_array('field_text.input_mask', $b, true) || !empty($e['imask_options'])) {
                    $this->note('todo', $what, 'Had an input mask; SquidForm has its own masks (the field\'s Text extras), set one if needed.');
                }
                break;

            case 'field_password':
                $f += ['type' => 'text', 'hide_input' => true];
                break;

            case 'field_textarea':
                $f['type'] = 'textarea';

                if ((int) ($e['rows'] ?? 0) > 0) {
                    $f['rows'] = min(30, (int) $e['rows']);
                }
                break;

            case 'field_select':
            case 'field_radios':
            case 'field_checkboxes':
                $multi     = $name === 'field_checkboxes' || ($name === 'field_select' && \in_array('multi_selection', $b, true));

                // "Additions": people typed their own values. SquidForm has no such list, so: a text box.
                if ($name === 'field_select' && \in_array('field_select.additions', $b, true) && trim((string) ($e['options'] ?? '')) === '' && !\in_array('dynamic_options', $b, true)) {
                    $f += $multi ? ['type' => 'textarea', 'rows' => 3, 'help' => 'One per line.'] : ['type' => 'text'];
                    $this->note('changed', $what, 'Let people type their own choices; it\'s a text box now' . ($multi ? ' (one per line)' : '') . '.');
                    break;
                }

                $f['type'] = match ($name) { 'field_radios' => 'radios', 'field_checkboxes' => 'checkboxes', default => $multi ? 'multiselect' : 'select' };
                $f['options'] = $this->options($e, $what);

                if (\in_array('field_select.countries', $b, true)) {
                    $iso = !empty($e['countries']['iso_value']);
                    $f['options'] = ['static' => array_map(static fn ($c, $n) => $iso ? ['value' => $c, 'label' => $n] : $n, array_keys(\SquidForm\Component\SquidForm\Administrator\Engine\Places::COUNTRIES), \SquidForm\Component\SquidForm\Administrator\Engine\Places::COUNTRIES)];
                    $this->report = array_values(array_filter($this->report, static fn ($r) => !($r['what'] === $what && $r['text'] === 'Had no choices; add them.')));
                    $this->note('changed', $what, 'A list of countries: filled in from SquidForm\'s list' . ($iso ? ', storing the two-letter code' : '') . '.');
                }

                if (\in_array('validation_count', $b, true)) {
                    $c = (array) ($e['validation_count'] ?? []);
                    $f['rules'] = array_filter(['min_selected' => (int) ($c['mincount'] ?? 0) ?: null, 'max_selected' => (int) ($c['maxcount'] ?? 0) ?: null]);
                }
                break;

            case 'field_checkbox':
                $f['type'] = 'checkbox';

                if (($e['value'] ?? '1') !== '1' && ($e['value'] ?? '') !== '') {
                    $this->note('info', $what, 'Ticked, it sent "' . $e['value'] . '"; in SquidForm a single tick box stores 1 or 0.');
                }
                break;

            case 'field_calendar':
                $fmt       = (string) ($e['data-format'] ?? $e['data-sformat'] ?? '');
                $f['type'] = str_contains($fmt, 'HH') || str_contains($fmt, 'hh') ? 'datetime' : 'date';
                break;

            case 'field_hidden':
                $f = ['key' => $key, 'type' => 'hidden'];
                break;

            case 'field_range':
                $f += ['type' => 'slider', 'min' => (float) ($e['min'] ?? 0), 'max' => (float) ($e['max'] ?? 100), 'step' => (float) ($e['step'] ?? 1) ?: 1];
                break;

            case 'field_file':
                $f['type'] = 'upload';
                $ext = array_values(array_filter(array_map(static fn ($x) => strtolower(trim($x, " .\t")), preg_split('/[,\s]+/', (string) ($e['extensions'] ?? '')) ?: [])));

                if ($ext) {
                    $f['accept'] = $ext;
                }

                if ((float) ($e['max_size'] ?? 0) > 0) {
                    $f['max_mb'] = max(1, (int) ceil((float) $e['max_size'] / 1024)); // CF8: KB
                }

                if (\in_array('multi_selection', $b, true)) {
                    $f['max_files'] = 5;
                }

                $this->note('changed', $what, 'Uploads go in SquidForm\'s upload folder (Options → Uploads), not "' . ($e['upload_dir'] ?? 'ChronoForms\' folder') . '". Allowed types are limited by the Options too.');
                break;

            case 'field_photo':
                $f += ['type' => 'upload', 'accept' => ['jpg', 'jpeg', 'png', 'webp']];
                $this->note('changed', $what, 'Was a camera photo; it\'s an image upload now (phones offer the camera).');
                break;

            case 'signature':
                $f += ['type' => 'signature'];
                break;

            case 'field_button':
                $bt = (string) ($e['btype'] ?? 'submit');

                if ($bt === 'submit' && $label !== '') {
                    $submitLabels[$page] = $label;
                } elseif (!\in_array($bt, ['submit', 'lastpage', 'reset'], true)) {
                    $this->note('todo', $what, 'A "' . $bt . '" button: SquidForm has Next, Back and Send buttons only.');
                }

                return [];

            case 'field_virtual':
                $this->note('todo', $what, 'A virtual field (a value worked out by ChronoForms); use a calculation or a hidden field.');

                return [];

            case 'header':
                $tag = \in_array($e['tag'] ?? 'h3', ['h2', 'h3', 'h4', 'h5', 'h6'], true) ? (string) $e['tag'] : 'h3';

                return [['key' => $this->freeKey('h_' . $e['id']), 'type' => 'html', 'tag' => $tag, 'content' => $this->placeholders((string) ($e['text'] ?? ''), $what)]];

            case 'text_node':
            case 'message':
                return [['key' => $this->freeKey('h_' . $e['id']), 'type' => 'html', 'tag' => 'div', 'content' => $this->placeholders((string) ($e['text'] ?? ''), $what)]];

            case 'html':
                if (preg_match('/<\?|<\s*script/i', (string) ($e['code'] ?? ''))) {
                    $this->note('todo', 'HTML ' . $e['id'], 'Had PHP or a script in it; those parts were left out (SquidForm keeps only safe HTML in text blocks).');
                }

                return [['key' => $this->freeKey('h_' . $e['id']), 'type' => 'html', 'tag' => 'div', 'content' => $this->placeholders((string) ($e['code'] ?? ''), 'HTML ' . $e['id'])]];

            case 'divider':
                return [['key' => $this->freeKey('d_' . $e['id']), 'type' => 'divider']];

            case 'css':
                $this->css .= "/* from ChronoForms CSS " . $e['id'] . " */\n" . (string) ($e['code'] ?? '') . "\n";
                $this->note('changed', 'CSS ' . $e['id'], 'Moved to Settings → Your own CSS. It was written for ChronoForms\' classes (nui …), so check it.');

                return [];

            case 'javascript':
                $this->javascript($e);

                return [];

            case 'area_multi_field':
            case 'area_container':
            case 'area_fieldset':
            case 'area_group':
                return $this->area($e, $page, $submitLabels);

            case 'area_repeater':
                return $this->repeater($e, $page, $submitLabels);

            case 'table':
                $this->listing = ['element' => $e, 'reads' => $this->reads];

                return [];

            case 'security_honeypot':
                $this->note('info', 'Honeypot', 'SquidForm has its own hidden bot trap on every form.');

                return [];

            case 'security_recaptcha':
            case 'security_hcaptcha':
            case 'security_turnstile':
            case 'security_captchafox':
            case 'security_altcha':
            case 'security_question':
                $this->captcha = true;
                $this->note('changed', 'Human check', ucfirst(str_replace('security_', '', $name)) . ' is replaced by SquidForm\'s offline check (proof of work). Settings → Human check offers others.');

                return [];

            case 'paginator':
            case 'menu':
                $this->note('info', $name . ' ' . $e['id'], 'Not needed: SquidForm shows the step and the Back and Next buttons itself.');

                return [];

            default:
                $this->note('todo', $what, 'ChronoForms "' . $name . '" has no SquidForm equivalent; left out.');

                return [];
        }

        $f = $this->behaviours($f, $e, $what);

        // Extras a ChronoForms 7 form had that CF8 keeps elsewhere (Cf7Adapter): type, mask, length rules, search.
        foreach ((array) ($e['sf'] ?? []) as $k => $v) {
            if ($k === 'rules') {
                $f['rules'] = (array) ($f['rules'] ?? []) + (array) $v;

                // Date limits only fit date fields.
                if (!\in_array($f['type'] ?? '', ['date', 'datetime', 'daterange'], true)) {
                    unset($f['rules']['weekdays']);
                }
            } elseif (\in_array($k, ['type', 'mask', 'max', 'search'], true)) {
                $f[$k] = $v;
            } elseif ($k === 'filter_by' && isset($f['options']['table'], $this->names[$v['name'] ?? ''])) {
                $f['options']['table']['filter_by'] = ['field' => $this->names[$v['name']], 'column' => (string) $v['column']];
            }
        }

        if (($f['type'] ?? '') === 'rating') {
            unset($f['placeholder']);
        }

        return [$f];
    }

    /** @param array<int, string> $submitLabels @return list<array<string, mixed>> */
    private function area(array $e, int $page, array &$submitLabels): array
    {
        $kids  = [];

        foreach ($this->children($e['id']) as $c) {
            if (($c['type'] ?? '') === 'views') {
                foreach ($this->field($c, $page, $submitLabels) as $f) {
                    $kids[] = $f;
                }
            }
        }

        if (!$kids) {
            return [];
        }

        // Side by side: share the row.
        if (($e['name'] ?? '') === 'area_multi_field') {
            $n = \count(array_filter($kids, static fn ($k) => !\in_array($k['type'] ?? '', ['hidden', 'html', 'divider'], true)));

            if ($n > 1 && $n <= 6) {
                foreach ($kids as &$k) {
                    if (!\in_array($k['type'] ?? '', ['hidden', 'divider'], true)) {
                        $k['width'] = intdiv(12, $n);
                    }
                }
                unset($k);
            }

            $listen = $this->conditionsFor($e, 'area');

            if ($listen) {
                foreach ($kids as &$k) {
                    $k['show_if'] = isset($k['show_if']) ? ['all' => [$listen, $k['show_if']]] : $listen;
                }
                unset($k);
            }

            return $kids;
        }

        $s = ['key' => $this->freeKey('s_' . $e['id']), 'type' => 'section', 'label' => trim((string) ($e['legend'] ?? $e['label'] ?? '')), 'fields' => $kids];

        if (($e['name'] ?? '') === 'area_fieldset') {
            $s['style'] = 'box';
        }

        if ($s['label'] === '') {
            unset($s['label']);
        }

        if ($c = $this->conditionsFor($e, 'area')) {
            $s['show_if'] = $c;
        }

        return [$s];
    }

    /** @param array<int, string> $submitLabels @return list<array<string, mixed>> */
    private function repeater(array $e, int $page, array &$submitLabels): array
    {
        $kids = [];

        foreach ($this->children($e['id']) as $c) {
            if (($c['type'] ?? '') === 'views') {
                foreach ($this->field($c, $page, $submitLabels) as $f) {
                    if (\in_array($f['type'] ?? '', ['upload', 'signature', 'section', 'repeater'], true)) {
                        $this->note('todo', (string) ($f['label'] ?? $f['key']), 'Can\'t go inside a repeating group in SquidForm; left out.');
                        continue;
                    }

                    unset($f['show_if']);
                    $kids[] = $f;
                }
            }
        }

        if (!$kids) {
            return [];
        }

        $key = $this->freeKey(preg_replace('/[^A-Za-z0-9_]/', '_', (string) ($e['key'] ?? '')) ?: 'r_' . $e['id']);
        $this->keyPage[$key] = $page;
        $this->note('changed', 'Repeater', 'Became a repeating group "' . $key . '"; its rows are saved in their own table.');
        $cond = $this->conditionsFor($e, 'Repeater');

        return [array_filter([
            'key' => $key, 'type' => 'repeater', 'label' => ucfirst(str_replace('_', ' ', (string) ($e['key'] ?? ''))) ?: 'Rows', 'fields' => $kids,
            'min' => max(0, (int) ($e['start_count'] ?? 1)), 'max' => min(500, max(1, (int) ($e['max_count'] ?? 0) ?: 20)),
            'add_label' => trim((string) ($e['add_text'] ?? '')) ?: null,
            'show_if' => $cond,
        ], static fn ($v) => $v !== null)];
    }

    /** @return array<string, mixed> */
    private function options(array $e, string $what): array
    {
        $b = \is_array($e['behaviors'] ?? null) ? $e['behaviors'] : [];

        if (\in_array('dynamic_options', $b, true) && !empty($e['dynamic_options']['datasource'])) {
            $d   = (array) $e['dynamic_options'];
            $src = (string) $d['datasource'];

            if (preg_match('/^\{var:(read_data\d+)\}$/', $src, $m) && isset($this->reads[$m[1]])) {
                $r       = $this->reads[$m[1]];
                $aliases = [];

                foreach (preg_split('/\R/', (string) ($r['fields'] ?? '')) ?: [] as $line) {
                    if (str_contains($line, '=')) {
                        [$col, $as] = array_map('trim', explode('=', $line, 2));
                        $aliases[$as] = $col;
                    }
                }

                $col = static function (string $tpl) use ($aliases): ?string {
                    return preg_match('/^\{row:([A-Za-z0-9_]+)\}$/', trim($tpl), $m) ? ($aliases[$m[1]] ?? $m[1]) : null;
                };
                $value = $col((string) ($d['value'] ?? ''));
                $label = $col((string) ($d['text'] ?? ''));
                $table = (string) ($r['dbtable'] ?? '');

                if ($value !== null && $table !== '') {
                    if ($label === null) {
                        $this->note('todo', $what, 'Its choices showed "' . $d['text'] . '"; SquidForm shows one column, so it shows ' . ($label ?? $value) . '.');
                    }

                    if (str_contains($table, 'chronoforms')) {
                        $this->note('todo', $what, 'Its choices come from ' . $table . ', a ChronoForms table; you may want another source once ChronoForms is gone.');
                    }

                    return ['table' => ['name' => $table, 'value' => $value, 'label' => $label ?? $value]];
                }
            }

            $this->note('todo', $what, 'Its choices came from "' . $src . '" (PHP or another source SquidForm can\'t read); add the choices, or use a table or file source.');
        }

        $static = [];

        foreach (preg_split('/\R/', (string) ($e['options'] ?? '')) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$v, $l] = str_contains($line, '=') ? array_map('trim', explode('=', $line, 2)) : [$line, $line];

            // "=Choose…" or "=?" is the empty first choice; SquidForm adds its own.
            if ($v === '') {
                continue;
            }

            $static[] = $v === $l ? $v : ['value' => $v, 'label' => $l];
        }

        if (!$static) {
            $static = ['Choice 1'];
            $this->note('todo', $what, 'Had no choices; add them.');
        }

        return ['static' => $static];
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function behaviours(array $f, array $e, string $what): array
    {
        $b = \is_array($e['behaviors'] ?? null) ? $e['behaviors'] : [];

        if (\in_array('validation_required', $b, true) && ($f['type'] ?? '') !== 'hidden') {
            $f['rules'] = ['required' => true] + (array) ($f['rules'] ?? []);
        }

        if (\in_array('validation_regex', $b, true) && !empty($e['validation_regex']['regex'])) {
            $re = (string) $e['validation_regex']['regex'];

            if (preg_match('#^/(.*)/[a-z]*$#s', $re, $m)) {
                $re = $m[1];
            }

            $f['rules'] = (array) ($f['rules'] ?? []) + ['pattern' => $re];

            if (!empty($e['validation_regex']['prompt'])) {
                $f['rules']['pattern_message'] = (string) $e['validation_regex']['prompt'];
            }
        }

        if (\in_array('validation_matches', $b, true)) {
            $other = (string) ($e['validation_matches']['id'] ?? '');
            $this->note('todo', $what, 'Had to match ' . ($other !== '' ? 'another field (' . $other . ')' : 'another field') . '; for email, use "Ask for it twice" on the first email field and remove this one.');
        }

        if (\in_array('validation_function', $b, true)) {
            $php = trim((string) ($e['validation_function']['php'] ?? ''));
            $this->note('todo', $what, 'Had a custom validation function (' . ($php !== '' ? 'PHP: "' . mb_substr(preg_replace('/\s+/', ' ', $php) ?? '', 0, 90) . '…"' : 'JavaScript') . '); not imported. A rule (pattern, length, min/max) or a PHP handler can do the same.');
        }

        foreach ($this->promptNoted ? [] : ['validation_required', 'validation_email', 'validation_count'] as $v) {
            if (!empty($e[$v]['prompt']) && \in_array($v, $b, true) && !\in_array(trim((string) $e[$v]['prompt']), ['This field is required.', 'A valid email address is required'], true)) {
                $this->promptNoted = true;
                $this->note('info', 'Error messages', 'Fields\' own error messages (like "' . mb_substr((string) $e[$v]['prompt'], 0, 60) . '") aren\'t kept; SquidForm says what\'s wrong in its own words.');
                break;
            }
        }

        if (\in_array('placeholder', $b, true) && !empty($e['placeholder']['text']) && !\in_array($f['type'] ?? '', ['radios', 'checkboxes', 'checkbox', 'hidden'], true)) {
            $f['placeholder'] = (string) $e['placeholder']['text'];
        }

        if (\in_array('hint', $b, true) && !empty($e['hint']['text'])) {
            $f['help'] = (string) $e['hint']['text'];
        }

        if (\in_array('tooltip', $b, true) && !empty($e['tooltip']['text']) && empty($f['help'])) {
            $f['help'] = (string) $e['tooltip']['text'];
        }

        if (\in_array('default_value', $b, true) && isset($e['default_value']['value']) && $e['default_value']['value'] !== '') {
            $dv = (string) $e['default_value']['value'];

            if (preg_match('/^\{user:(name|email|username|id)\}$/', $dv)) {
                $f['prefill'] = $dv;
            } elseif (preg_match('/^\{var:([A-Za-z0-9_]+)((?:\.[A-Za-z0-9_-]+)*)\}$/', $dv, $vm) && isset($this->sessionVars[$vm[1]])) {
                // 0.15.0: session data, locked like SquidForm's own session prefills.
                $f['prefill'] = '{session:' . $this->sessionVars[$vm[1]] . $vm[2] . '}';
            } elseif (str_contains($dv, '{')) {
                $this->note('todo', $what, 'Its default "' . $dv . '" uses a ChronoForms placeholder; set a SquidForm one (the field\'s "Fill in from").');
            } else {
                $f['default'] = $dv;
            }
        }

        if (\in_array('selected_values', $b, true) && !empty($e['selected_values'])) {
            $sel        = array_values(array_map('strval', (array) $e['selected_values']));
            $f['default'] = \in_array($f['type'] ?? '', ['checkboxes', 'multiselect'], true) ? $sel : $sel[0];
        }

        if (\in_array('state', $b, true) && !empty($e['state']['disabled'])) {
            $f['readonly'] = true;
        }

        if (\in_array('html_attributes', $b, true) && !empty($e['attributes'])) {
            $this->note('info', $what, 'Its extra HTML attributes aren\'t kept.');
        }

        if (\in_array('icon', $b, true) || \in_array('color', $b, true)) {
            // look and feel: SquidForm uses the site template's styles
        }

        if ($c = $this->conditionsFor($e, $what)) {
            $f['show_if'] = $c;
        }

        if ($c = $this->conditionsFor($e, $what, ['enable', 'disable'])) {
            $f['disabled_if'] = $c;
        }

        return $f;
    }

    // ── Show / hide ────────────────────────────────────────────────────────

    private function collectTriggers(): void
    {
        foreach ($this->el as $e) {
            $key = $this->ids[$e['id']] ?? null;

            foreach ((array) ($e['triggers'] ?? []) as $t) {
                if (!\is_array($t) || $key === null) {
                    continue;
                }

                foreach ((array) ($t['name'] ?? []) as $n) {
                    if ((string) $n !== '') {
                        $this->triggers[(string) $n][] = ['key' => $key, 'condition' => (string) ($t['condition'] ?? ''), 'values' => array_values(array_map('strval', (array) ($t['value'] ?? [])))];
                    }
                }
            }
        }
    }

    /**
     * The show_if (or disabled_if) for an element from its listeners.
     *
     * @param list<string> $kinds ['show', 'hide'] or ['enable', 'disable']
     *
     * @return array<string, mixed>|null
     */
    private function conditionsFor(array $e, string $what, array $kinds = ['show', 'hide']): ?array
    {
        $myPage = $this->keyPage[$this->ids[$e['id']] ?? ''] ?? null;
        $any    = [];

        foreach ((array) ($e['listeners'] ?? []) as $l) {
            if (!\is_array($l)) {
                continue;
            }

            $acts = array_map('strval', (array) ($l['actions'] ?? []));
            $on   = \in_array($kinds[0], $acts, true) ? 'on' : (\in_array($kinds[1], $acts, true) ? 'off' : null);

            if ($on === null) {
                if (array_intersect($acts, ['disable_validation', 'enable_validation']) && $kinds[0] === 'show') {
                    $this->note('todo', $what, 'Turned its validation on or off on the page; in SquidForm a field\'s rules apply only while it shows.');
                }

                continue;
            }

            foreach ((array) ($l['trigger'] ?? []) as $t) {
                $conds = [];

                foreach ($this->triggers[(string) $t] ?? [] as $c) {
                    if ($myPage !== null && ($this->keyPage[$c['key']] ?? 0) > $myPage) {
                        $this->note('todo', $what, 'Showed or hid depending on a field on a later page; SquidForm can\'t do that, so it always shows.');
                        continue 2;
                    }

                    // Tick boxes: "is" means "includes"; a list of values means any of them.
                    $many = isset($this->multi[$c['key']]);
                    // phpcs:disable PHPCompatibility.Operators.RemovedTernaryAssociativity -- separate match arms, not chained ternaries
                    $one  = match ($c['condition']) {
                        'in'        => \count($c['values']) === 1 ? ['field' => $c['key'], 'op' => 'eq', 'value' => $c['values'][0]]
                            : ($many ? ['any' => array_map(static fn ($v) => ['field' => $c['key'], 'op' => 'eq', 'value' => $v], $c['values'])] : ['field' => $c['key'], 'op' => 'in', 'value' => $c['values']]),
                        'not-in'    => $many ? ['all' => array_map(static fn ($v) => ['field' => $c['key'], 'op' => 'ncontains', 'value' => $v], $c['values'])] : ['field' => $c['key'], 'op' => 'nin', 'value' => $c['values']],
                        'empty'     => ['field' => $c['key'], 'op' => 'empty'],
                        'not-empty', 'string_not_empty' => ['field' => $c['key'], 'op' => 'filled'],
                        'regex'     => ['field' => $c['key'], 'op' => 'matches', 'value' => preg_replace('#^/(.*)/[a-z]*$#s', '$1', $c['values'][0] ?? '')],
                        default     => null,
                    };
                    // phpcs:enable

                    if ($one === null) {
                        $this->note('todo', $what, 'Showed or hid "on change" or when the page loads (trigger "' . $t . '"); add a condition in SquidForm.');
                        continue 2;
                    }

                    $conds[] = $one;
                }

                if (!$conds) {
                    continue;
                }

                $c     = \count($conds) === 1 ? $conds[0] : ['all' => $conds];
                $any[] = $on === 'on' ? $c : ['not' => $c];
            }
        }

        if (!$any) {
            return null;
        }

        // A field shown on one trigger and hidden on its opposite: the "show" rule says it all.
        $shows = array_values(array_filter($any, static fn ($c) => !isset($c['not'])));
        $use   = $shows ?: $any;

        return \count($use) === 1 ? $use[0] : ['any' => $use];
    }

    // ── Actions ────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function actions(string $pageId, int $page, int $pages): array
    {
        $out = [];

        foreach ($this->children($pageId, 'submit') as $a) {
            $name = (string) ($a['name'] ?? '');
            $what = ucfirst(str_replace('_', ' ', $name)) . ' ' . $a['id'];
            $when = null;

            if (!empty($a['run_conditions'])) {
                $this->note('todo', $what, 'Ran only on some conditions in ChronoForms; set "Run only when…" on the action.');
            }

            switch ($a['type'] . ':' . $name) {
                case 'actions:email':
                    foreach ($this->email($a, $what) as $x) {
                        $out[] = $x;
                    }
                    break;

                case 'actions:save_data':
                    $this->saves[] = (string) ($a['dbtable'] ?? '');
                    $out[] = ['key' => $this->freeKey('a_save' . (\count($out) ? '_' . $a['id'] : '')), 'type' => 'save_data', 'config' => ['target' => 'submissions']];
                    $this->note('changed', $what, 'Saved into ' . ($a['dbtable'] ?? 'a table') . '; SquidForm saves into the form\'s own tables. Old rows can be brought in with Import submissions.');
                    break;

                case 'actions:redirect':
                    $url = (string) ($a['url'] ?? '');

                    if (preg_match('#^(javascript|data|vbscript|file):#i', (string) preg_replace('/[\s\x00-\x1f]+/', '', $url))) {
                        $this->note('todo', $what, 'Redirected to a script address; that isn\'t allowed, so it was left out. Add a Redirect action to a web address if one is needed.');
                    } elseif ($url !== '') {
                        $out[] = ['key' => $this->freeKey('a_redirect_' . $a['id']), 'type' => 'redirect', 'config' => ['url' => $this->placeholders($url, $what)]];
                    }

                    if (!empty($a['parameters'])) {
                        $this->note('todo', $what, 'Added parameters to the address; add them to the address in SquidForm.');
                    }
                    break;

                case 'views:message':
                    $out[] = ['key' => $this->freeKey('a_msg_' . $a['id']), 'type' => 'message', 'config' => ['text' => $this->placeholders((string) ($a['text'] ?? ''), $what), 'style' => 'success']];
                    break;

                case 'actions:log_data':
                    $out[] = ['key' => $this->freeKey('a_save'), 'type' => 'save_data', 'config' => ['target' => 'submissions']];
                    break;

                case 'actions:php':
                    if (preg_match('/getSession\(\)\s*->\s*get\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]{0,63})[\'"]/', (string) ($a['code'] ?? ''), $sm) && substr_count(trim((string) ($a['code'] ?? '')), "\n") < 4) {
                        $this->note('info', $what, 'Read "' . $sm[1] . '" from the session. SquidForm reads it itself: its placeholders became {session:' . $sm[1] . '.…}. Add ' . $sm[1] . ' to Options → Data Sources → Session data forms may read.');
                        break;
                    }

                    $this->note('todo', $what, 'PHP code (' . (substr_count((string) ($a['code'] ?? ''), "\n") + 1) . ' lines) wasn\'t imported: SquidForm never keeps server code in a form. Make it a PHP handler file (docs/DEVELOPER.md) and add a "Run PHP handler" action.');
                    break;

                default:
                    $this->note('todo', $what, 'Isn\'t something SquidForm does (' . $name . '); left out.');
            }

            unset($when);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function loadActions(string $pageId): array
    {
        foreach ($this->children($pageId, 'load') as $a) {
            if (($a['type'] ?? '') !== 'actions') {
                continue;
            }

            $name = (string) ($a['name'] ?? '');

            if ($name === 'read_data' && $this->usedForOptions('read_data' . $a['id'])) {
                continue;
            }

            $this->note('todo', ucfirst(str_replace('_', ' ', $name)) . ' ' . $a['id'], $name === 'read_data'
                ? 'Read data from ' . ($a['dbtable'] ?? 'a table') . ' when the page opened; SquidForm has Read data too: add it to the page\'s "When the page opens" with the table on the read allow-list.'
                : 'Ran "' . $name . '" when the page opened; not imported.');
        }

        return [];
    }

    private function usedForOptions(string $var): bool
    {
        foreach ($this->el as $e) {
            if (($e['dynamic_options']['datasource'] ?? '') === '{var:' . $var . '}' || (($e['name'] ?? '') === 'table' && ($e['datasource'] ?? '') === '{var:' . $var . '}')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function email(array $a, string $what): array
    {
        $to      = [];
        $confirm = [];

        foreach ((array) ($a['recipients'] ?? []) as $r) {
            $r = trim((string) $r);

            if (preg_match('/^\{data:([^}]+)\}$/', $r, $m) && isset($this->names[$m[1]])) {
                $confirm[] = $this->names[$m[1]];
            } elseif ($r !== '') {
                $to[] = $this->placeholders($r, $what);
            }
        }

        $subject = $this->placeholders((string) ($a['subject'] ?? ''), $what);
        $body    = $this->placeholders((string) ($a['body'] ?? ''), $what);
        $out     = [];

        if ($to) {
            $out[] = ['key' => $this->freeKey('a_email_' . $a['id']), 'type' => 'email', 'config' => array_filter([
                'to' => implode(', ', $to), 'subject' => $subject, 'body' => $body !== '' ? $body : '{data:_all}',
                'cc' => $this->placeholders(implode(', ', (array) ($a['cc'] ?? [])), $what), 'bcc' => $this->placeholders(implode(', ', (array) ($a['bcc'] ?? [])), $what),
                'reply_to' => preg_match('/^\{data:([^}]+)\}$/', (string) ($a['reply'] ?? ''), $m) && isset($this->names[$m[1]]) ? '{data:' . $this->names[$m[1]] . '}' : null,
                'from_name' => !str_contains((string) ($a['fromname'] ?? ''), '{') ? ((string) ($a['fromname'] ?? '') ?: null) : null,
                'format' => ($a['format'] ?? 'html') === 'text' ? 'text' : null,
            ], static fn ($v) => $v !== null && $v !== '')];
        }

        foreach ($confirm as $k) {
            $out[] = ['key' => $this->freeKey('a_confirm_' . $k), 'type' => 'confirm_email', 'config' => ['to_field' => $k, 'subject' => $subject, 'body' => $body !== '' ? $body : '{data:_all}']];
            $this->note('changed', $what, 'Sent to the address typed in "' . $k . '": a Confirmation email in SquidForm (only to that one address).');
        }

        if (!empty($a['from']) && !str_contains((string) $a['from'], '{')) {
            $this->note('info', $what, 'Sent from ' . $a['from'] . '; SquidForm uses the site\'s address unless you set "From address".');
        }

        if (!empty($a['attachments']) || !empty($a['files'])) {
            $this->note('todo', $what, 'Attached files; SquidForm links uploaded files in {data:_all} instead.');
        }

        return $out;
    }

    /** Actions after a save only run when it worked. @param list<array<string, mixed>> $pages */
    private function whenSaved(array &$pages): void
    {
        foreach ($pages as &$p) {
            $save = null;

            foreach ($p['events']['submit'] as $i => &$a) {
                if ($a['type'] === 'save_data' && $save === null) {
                    $save = $a['key'];
                } elseif ($save !== null && \in_array($a['type'], ['email', 'confirm_email', 'message'], true) && !isset($a['when'])) {
                    $a['when'] = ['action' => $save, 'is' => 'success'];
                }
            }
            unset($a);
        }
        unset($p);
    }

    // ── JavaScript ─────────────────────────────────────────────────────────

    private function javascript(array $e): void
    {
        $code = (string) ($e['code'] ?? '');

        if (stripos($code, '</script') !== false || str_contains($code, '<!--')) {
            $code = str_ireplace(['</script', '<!--'], ['<\\/script', '<!- -'], $code);
        }

        // Field names → SquidForm's: name="x" is name="sf[x]" here.
        $code = (string) preg_replace_callback('/\[\s*name\s*=\s*([\'"]?)([A-Za-z0-9_\-\[\]]+)\1\s*\]/', function ($m) {
            $k = $this->names[$m[2]] ?? $this->names[str_replace('[]', '', $m[2])] ?? null;

            if ($k === null) {
                return $m[0];
            }

            $v = 'sf[' . $k . ']' . (str_ends_with($m[2], '[]') ? '[]' : '');

            // Same quotes as before, so the JavaScript string around it stays whole; unquoted, CSS escapes.
            return $m[1] !== '' ? '[name=' . $m[1] . $v . $m[1] . ']' : '[name=' . str_replace(['[', ']'], ['\\\\[', '\\\\]'], $v) . ']';
        }, $code);

        $this->js .= ($this->js !== '' ? "\n\n" : '') . '// ChronoForms JavaScript ' . $e['id'] . (!empty($e['domready']) || \in_array('javascript.domready', (array) ($e['behaviors'] ?? []), true) ? ' (it ran when the page was ready; this does too)' : '') . "\n" . $code;

        $lines = [];

        foreach (preg_split('/\R/', $code) ?: [] as $n => $line) {
            $why = [];

            if (preg_match('/\$\(|jQuery/', $line)) {
                $why[] = 'jQuery (not loaded by SquidForm)';
            }

            if (preg_match('/\.nui\b|\bnui\.|Semantic|\.dropdown\(|\.checkbox\(|\.calendar\(/', $line)) {
                $why[] = 'ChronoForms / Semantic UI';
            }

            if (preg_match('/\bChrono\w*|\bcf8?\b|\bCF8\b/', $line)) {
                $why[] = 'a ChronoForms function';
            }

            if (preg_match('/#[A-Za-z_][\w\-]*/', $line, $m) && !preg_match('/#sf-/', $line)) {
                $why[] = 'an id (' . $m[0] . '); SquidForm ids are sf-<form alias>-<field key>';
            }

            if ($why) {
                $lines[] = 'line ' . ($n + 1) . ': ' . implode(', ', array_unique($why));
            }
        }

        if (!empty($e['files'])) {
            $lines[] = 'it loaded script files (' . mb_substr((string) (\is_array($e['files']) ? implode(', ', $e['files']) : $e['files']), 0, 120) . '); SquidForm loads nothing from elsewhere, so copy what you need';
        }

        $this->note('todo', 'JavaScript ' . $e['id'], 'Carried across switched off (Settings → Your own JavaScript). Field names in [name="…"] selectors were changed to SquidForm\'s.'
            . ($lines ? ' Look at: ' . implode('; ', \array_slice($lines, 0, 25)) . (\count($lines) > 25 ? '; and ' . (\count($lines) - 25) . ' more' : '') . '.' : ' Nothing else in it looks ChronoForms-specific.')
            . ' Often a SquidForm feature does it without code: conditions, calculations, "fill from the web address", Read data.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** {data:cf_name} → {data:key}; {email:data_table} → {data:_all}; others reported. */
    private function placeholders(string $s, string $what): string
    {
        return (string) preg_replace_callback('/\{([a-z_.]+):([^}]*)\}/i', function ($m) use ($what) {
            [$all, $ns, $name] = $m;

            if ($ns === 'email' && $name === 'data_table') {
                return '{data:_all}';
            }

            // A session value read by a PHP action (0.15.0).
            if ($ns === 'var' && preg_match('/^([A-Za-z0-9_]+)((?:\.[A-Za-z0-9_-]+)*)$/', $name, $vm) && isset($this->sessionVars[$vm[1]])) {
                return '{session:' . $this->sessionVars[$vm[1]] . $vm[2] . '}';
            }

            // The saved record: its reference.
            if ($ns === 'var' && preg_match('/^save_data\d+\.id$/', $name)) {
                return '{submission:ref}';
            }

            if ($ns === 'data' && ($name === '_all' || isset($this->names[$name]))) {
                if ($name === '_all') {
                    return $all;
                }

                return '{data:' . $this->names[$name] . '}';
            }

            if ($ns === 'user' && \in_array($name, ['name', 'email', 'username', 'id'], true)) {
                return $all;
            }

            $this->note('todo', $what, 'Uses the ChronoForms placeholder ' . $all . '; replace it with a SquidForm one (the Insert list beside the box).');

            return $all;
        }, $s);
    }

    private function freeKey(string $base): string
    {
        $base = substr(preg_replace('/[^A-Za-z0-9_]/', '_', $base) ?? 'x', 0, 60);
        $base = ctype_alpha($base[0] ?? '') ? $base : 'x_' . $base;
        $key  = $base;

        for ($n = 2; isset($this->used[strtolower($key)]); $n++) {
            $key = $base . '_' . $n;
        }

        $this->used[strtolower($key)] = true;

        return $key;
    }

    private function note(string $level, string $what, string $text): void
    {
        foreach ($this->report as $r) {
            if ($r['what'] === $what && $r['text'] === $text) {
                return;
            }
        }

        $this->report[] = ['level' => $level, 'what' => $what, 'text' => $text];
    }
}
