<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Import;

\defined('_JEXEC') or die;

/**
 * One ChronoForms 7 form → the shape of a ChronoForms 8 form (0.12.1), so the
 * CF8 converter does the rest: field types, rules, the report, JavaScript,
 * placeholders and listings work the same for both.
 *
 * CF7 ("GCore 3") keeps a form as pages (in page groups), views (fields,
 * areas, text, code) and functions (email, save data, …), each placed on a
 * page or inside another one. Moving from one page to the next runs the next
 * page's functions, then shows its views; so a page's functions are what
 * happens when the page before it is sent, and a last page without fields
 * is the "thank you" page. Show/hide lives on the field being shown, as
 * events with rules on other fields ("show me if source == x").
 *
 * Nothing here runs code from the form; PHP is only carried to the report.
 *
 * Returns ['form' => CF8-shaped form, 'notes' => report items found here].
 */
final class Cf7Adapter
{
    /** CF7 view types that hold an answer. */
    private const INPUTS = ['field_text', 'field_textarea', 'field_select', 'field_radios', 'field_checkboxes', 'field_checkbox', 'field_calendar', 'field_hidden',
        'field_password', 'field_range', 'field_file', 'wfield_signature', 'wfield_rating'];

    /** @var array<string, array<string, mixed>> uid → view */
    private array $views = [];

    /** @var array<string, array<string, mixed>> uid → function */
    private array $fns = [];

    /** @var array<string, string> function uid → CF8 element id */
    private array $fnId = [];

    /** @var array<string, array<string, mixed>> CF8 elements, in order */
    private array $el = [];

    /** @var list<array{level: string, what: string, text: string}> */
    private array $notes = [];

    /** @var array<string, list<array<string, mixed>>> source view uid → CF8 triggers */
    private array $triggers = [];

    /** @var array<string, true> views that reloaded their choices on an event */
    private array $reloads = [];

    /** @var array<string, list<array<string, mixed>>> target view uid → CF8 listeners */
    private array $listeners = [];

    /**
     * @param array<string, mixed> $conn  a row of #__chronog3_forms7, or the "Connection" of a .cf7bak item
     *
     * @return array{form: array<string, mixed>, notes: list<array<string, string>>}
     */
    public static function adapt(array $conn): array
    {
        return (new self())->run($conn);
    }

    private function run(array $c): array
    {
        $dec = static fn ($v) => \is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
        $settings = $dec($c['settings'] ?? []);
        $pgroups  = $dec($c['pgroups'] ?? []);
        $pages    = $dec($c['pages'] ?? []);
        $this->views = array_filter($dec($c['views'] ?? []), 'is_array');
        $this->fns   = array_filter($dec($c['functions'] ?? []), 'is_array');

        // Function ids that don't clash with view ids (CF8 elements share one list).
        foreach ($this->fns as $uid => $fn) {
            $id = (string) $uid;
            while (isset($this->views[$id]) || \in_array($id, $this->fnId, true)) {
                $id = (string) ((int) $id + 100000);
            }
            $this->fnId[(string) $uid] = $id;
        }

        // The pages people fill in: the page group of the first page.
        $first = array_key_first($pages);
        $group = $first !== null ? (string) ($pages[$first]['pgroup'] ?? '1') : '1';
        $main  = [];

        foreach ($pages as $pid => $p) {
            if ((string) ($p['pgroup'] ?? '1') === $group) {
                $main[(string) $pid] = $p;
            } else {
                $this->note('info', 'Page "' . ($p['name'] ?? $pid) . '"', 'A page in another page group (used to load choices or for AJAX calls); SquidForm doesn\'t need it, so it wasn\'t imported.');
                $this->unplaced((string) $pid);
            }
        }

        if (!$main) {
            $main = ['1' => ['name' => 'start_page']];
        }

        $this->events();

        // Pages with something to fill in become SquidForm pages; the others are what happens after.
        $formPages = [];

        foreach (array_keys($main) as $pid) {
            if ($this->hasInputs((string) $pid)) {
                $formPages[] = (string) $pid;
            }
        }

        $prev = null;

        if (!$formPages) {
            $pid = (string) array_key_first($main);
            $this->el['pg' . $pid] = ['id' => 'pg' . $pid, 'type' => 'page', 'title' => $this->title((string) ($main[$pid]['name'] ?? $pid), 1)];
            $this->functions($pid, 'pg' . $pid, 'load');
            $this->viewsOn($pid, 'pg' . $pid);

            foreach (array_slice(array_keys($main), 1) as $other) {
                $this->note('info', 'Page "' . ($main[$other]['name'] ?? $other) . '"', 'Another page of a listing (to view, edit or delete a row); SquidForm\'s viewer shows, edits and archives submissions itself, so it wasn\'t imported.');
                $this->unplaced((string) $other);
            }
            $main = [];
        }

        foreach (array_keys($main) as $pid) {
            $pid  = (string) $pid;
            $name = (string) ($main[$pid]['name'] ?? $pid);

            if (\in_array($pid, $formPages, true)) {
                $this->el['pg' . $pid] = ['id' => 'pg' . $pid, 'type' => 'page', 'title' => $this->title($name, \count($formPages))];
                // The functions of a fill-in page run when the page before it is sent (or as it opens, for the first).
                $this->functions($pid, $prev !== null ? 'pg' . $prev : 'pg' . $pid, $prev !== null ? 'submit' : 'load');
                $this->viewsOn($pid, 'pg' . $pid);
                $prev = $pid;
            } elseif ($prev !== null || $formPages) {
                // An end page: its functions and its text run after the last page before it.
                $host = $prev ?? $formPages[0];
                $this->functions($pid, 'pg' . $host, $prev !== null ? 'submit' : 'load');
                $this->endViews($pid, 'pg' . $host, $name);
            } else {
                // A page with nothing to fill in and nothing before it (a listing, a PDF): load-time.
                $this->el['pg' . $pid] = ['id' => 'pg' . $pid, 'type' => 'page', 'title' => $this->title($name, 1)];
                $this->functions($pid, 'pg' . $pid, 'load');
                $this->viewsOn($pid, 'pg' . $pid);
                $prev = null;
            }
        }

        $params = $this->settings((array) ($settings['form'] ?? []), $formPages ? 'pg' . end($formPages) : null);

        return ['form' => ['id' => (int) ($c['id'] ?? 0), 'title' => (string) ($c['title'] ?? ''), 'alias' => (string) ($c['alias'] ?? ''), 'elements' => $this->el, 'params' => $params], 'notes' => $this->notes];
    }

    private function title(string $name, int $pages): string
    {
        return \in_array($name, ['start_page', 'start', 'page1', 'form'], true) ? 'Page 1' : ucfirst(trim(str_replace('_', ' ', $name)));
    }

    // ── Pages ──────────────────────────────────────────────────────────────

    private function hasInputs(string $pid): bool
    {
        foreach ($this->views as $uid => $v) {
            if (\in_array($v['type'] ?? '', self::INPUTS, true) && ($v['type'] ?? '') !== 'field_hidden' && !$this->isFilter($v) && $this->pageOf((string) $uid) === $pid) {
                return true;
            }
        }

        return false;
    }

    /** A search box or filter for a listing, not a question. */
    private function isFilter(array $v): bool
    {
        return (bool) array_intersect(['field_listing_filter', 'field_listing_search'], $this->flat($v));
    }

    private function pageOf(string $uid, int $depth = 0): ?string
    {
        $v = $this->views[$uid] ?? null;

        if ($v === null || $depth > 20) {
            return null;
        }

        $parent = (string) ($v['_parent'] ?? '');

        return $parent === '' ? (string) ($v['_area'] ?? '') : $this->pageOf($parent, $depth + 1);
    }

    private function fnsOn(string $pid): bool
    {
        foreach ($this->fns as $f) {
            if ((string) ($f['_parent'] ?? '') === '' && (string) ($f['_area'] ?? '') === $pid) {
                return true;
            }
        }

        return false;
    }

    /** Functions of a page that isn't imported: still reported. */
    private function unplaced(string $pid): void
    {
        foreach ($this->fns as $f) {
            if ((string) ($f['_parent'] ?? '') === '' && (string) ($f['_area'] ?? '') === $pid && ($f['type'] ?? '') === 'php') {
                $this->note('todo', 'PHP ' . ($f['uid'] ?? ''), 'PHP code on an extra page wasn\'t imported: SquidForm never keeps server code in a form.');
            }
        }
    }

    // ── Views ──────────────────────────────────────────────────────────────

    private function viewsOn(string $pid, string $parent): void
    {
        foreach ($this->views as $uid => $v) {
            if ((string) ($v['_parent'] ?? '') === '' && (string) ($v['_area'] ?? '') === $pid) {
                $this->view((string) $uid, $parent);
            }
        }
    }

    /** Children of an area, in CF order. */
    private function viewsIn(string $areaUid, string $parent): void
    {
        foreach ($this->views as $uid => $v) {
            if ((string) ($v['_parent'] ?? '') === $areaUid) {
                $this->view((string) $uid, $parent);
            }
        }
    }

    private function view(string $uid, string $parent): void
    {
        $v     = $this->views[$uid];
        $type  = (string) ($v['type'] ?? '');
        $nodes = (array) ($v['nodes'] ?? []);
        $label = trim(strip_tags((string) ($nodes['label']['content'] ?? '')));
        $name  = (string) ($nodes['main']['attrs']['name'] ?? '');
        $b     = $this->flat($v);

        if ($this->isFilter($v)) {
            $this->note('info', 'Listing filters', 'Its search and filter boxes were for a listing; SquidForm\'s Submissions viewer has its own search and filters.');

            return;
        }

        $e     = ['id' => $uid, 'type' => 'views', 'parent' => $parent, 'section' => 'load', 'label' => $label, 'fieldname' => $name, 'behaviors' => []];
        $what  = $label !== '' ? $label : ($name !== '' ? $name : str_replace('_', ' ', $type) . ' ' . $uid);

        switch ($type) {
            case 'field_text':
                $e['name'] = 'field_text';
                $t         = strtolower((string) ($nodes['main']['attrs']['type'] ?? ''));
                $e['input_type'] = \in_array($t, ['email', 'number', 'tel', 'url', 'date', 'time', 'password'], true) ? $t : '';
                break;

            case 'field_password':
            case 'field_textarea':
            case 'field_checkboxes':
            case 'field_radios':
            case 'field_select':
            case 'field_hidden':
            case 'field_file':
                $e['name'] = $type;
                break;

            case 'field_checkbox':
                $e['name']  = 'field_checkbox';
                $e['value'] = (string) ($nodes['main']['attrs']['value'] ?? '1');
                $label      = $label !== '' ? $label : trim(strip_tags((string) ($nodes['checkbox']['content'] ?? '')));
                $e['label'] = $label;
                break;

            case 'field_calendar':
                $e['name'] = 'field_calendar';
                $cal       = (array) ($v['calendar'] ?? []);
                $kind      = (string) ($cal['type'] ?? 'date');

                if ($kind === 'time') {
                    $e['sf']['type'] = 'time';
                } elseif ($kind === 'datetime') {
                    $e['data-format'] = 'YYYY-MM-DD HH:mm';
                } elseif (\in_array($kind, ['month', 'year'], true)) {
                    $this->note('changed', $what, 'Picked a ' . $kind . ' only; it\'s a date in SquidForm.');
                }

                foreach (['mindate' => 'min', 'maxdate' => 'max'] as $cf => $sf) {
                    $d = strtolower(trim((string) ($cal[$cf] ?? '')));

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        $e['sf']['rules'][$sf] = $d;
                    } elseif (preg_match('/^(?:today|now|0)$/', $d)) {
                        $e['sf']['rules'][$sf] = 'today';
                    } elseif (preg_match('/^([+-]?\d{1,4})d?$/', $d, $mm)) {
                        // "+60d" / "-1" / "30": days from today (0.13.0 date limits).
                        $n = (int) $mm[1];
                        $e['sf']['rules'][$sf] = $n === 0 ? 'today' : 'today' . ($n > 0 ? '+' : '') . $n;
                    } elseif ($d !== '') {
                        $this->note('todo', $what, 'Its ' . ($sf === 'min' ? 'earliest' : 'latest') . ' date was "' . $d . '" (worked out by ChronoForms); set a date, or "today+N", in SquidForm.');
                    }
                }

                // CF7 counts days from Sunday = 0; SquidForm from Monday = 1.
                $open = array_values(array_unique(array_map(static fn ($x) => ((int) $x + 6) % 7 + 1, array_filter((array) ($cal['opendays'] ?? []), static fn ($x) => is_numeric($x) && (int) $x >= 0 && (int) $x <= 6))));

                if ($open && \count($open) < 7) {
                    sort($open);
                    $e['sf']['rules']['weekdays'] = $open;
                }

                if (!empty($cal['openhours'])) {
                    $this->note('todo', $what, 'Allowed only some hours; set "Opens at" and "Closes at" on a date-and-time field in SquidForm.');
                }
                break;

            case 'field_range':
                $a         = (array) ($nodes['main']['attrs'] ?? []);
                $e['name'] = 'field_range';
                $e += ['min' => $a['min'] ?? 0, 'max' => $a['max'] ?? 100, 'step' => $a['step'] ?? 1];
                break;

            case 'wfield_signature':
                $e['name'] = 'signature';
                break;

            case 'wfield_rating':
                $e['name'] = 'field_text';
                $e['sf']   = ['type' => 'rating', 'max' => max(2, min(10, (int) ($nodes['main']['attrs']['data-max-rating'] ?? 5)))];
                break;

            case 'wfield_calculator':
                $this->note('todo', $what, 'A calculator field: use a SquidForm calculation (Calculation field with a formula).');

                return;

            case 'field_button':
                $e['name']  = 'field_button';
                $e['label'] = trim(strip_tags((string) ($nodes['main']['content'] ?? '')));
                $bt         = strtolower((string) ($nodes['main']['attrs']['type'] ?? 'submit'));
                $e['btype'] = !empty($v['repeater']['action']) ? 'repeater' : ($bt === 'button' ? 'button' : ($bt === 'reset' ? 'reset' : 'submit'));

                if ($e['btype'] === 'repeater') {
                    return; // "add a row" buttons: SquidForm's repeating group has its own
                }
                break;

            case 'header':
                $e['name'] = 'header';
                $e['tag']  = (string) ($nodes['main']['tag'] ?? 'h3');
                $e['text'] = trim((string) ($nodes['content']['content'] ?? $nodes['main']['content'] ?? '')) . (!empty($nodes['subheader']['content']) ? '<br><small>' . $nodes['subheader']['content'] . '</small>' : '');
                break;

            case 'text_node':
                $e['name'] = 'text_node';
                $e['text'] = (string) ($nodes['main']['content'] ?? '');
                break;

            case 'html_code':
                $e['name'] = 'html';
                $e['code'] = (string) ($nodes['main']['d_content'] ?? $nodes['main']['content'] ?? $v['content'] ?? '');
                break;

            case 'divider':
                $e['name'] = 'divider';
                break;

            case 'css':
                $e['name'] = 'css';
                $e['code'] = (string) ($v['content'] ?? '');
                break;

            case 'javascript':
                $e['name']     = 'javascript';
                $e['code']     = (string) ($v['content'] ?? '');
                $e['domready'] = !empty($v['domready']);

                if (!empty($v['files'])) {
                    $e['files'] = array_map(static fn ($f) => \is_array($f) ? (string) ($f['url'] ?? '') : (string) $f, (array) $v['files']);
                }
                break;

            case 'field_captcha':
            case 'gcaptcha':
            case 'field_secicon':
                $e['name'] = 'security_recaptcha';
                break;

            case 'field_honeypot':
                $e['name'] = 'security_honeypot';
                break;

            case 'area_fields':
                $e['name'] = 'area_multi_field';
                $group     = trim(strip_tags((string) ($nodes['label_field']['content'] ?? '')));

                // A labelled group: its label as a line of text above the fields.
                if ($group !== '' && \in_array('area_fields_group', $b, true)) {
                    $this->el['lbl' . $uid] = ['id' => 'lbl' . $uid, 'type' => 'views', 'parent' => $parent, 'section' => 'load', 'name' => 'text_node', 'text' => '<p><strong>' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '</strong></p>', 'behaviors' => []];
                }
                $this->add($e, $v, $b, $what);
                $this->viewsIn($uid, $uid);

                return;

            case 'area_segment':
            case 'area_container':
            case 'area_group':
            case 'area_grid':
            case 'area_partitions':
                $legend    = trim(strip_tags((string) ($nodes['title']['content'] ?? '')));
                $e['name'] = $legend !== '' || $type === 'area_segment' ? 'area_fieldset' : 'area_container';
                $e['legend'] = $legend;
                $this->add($e, $v, $b, $what);
                $this->viewsIn($uid, $uid);

                return;

            case 'area_repeater':
                $a         = (array) ($nodes['main']['attrs'] ?? []);
                $e['name'] = 'area_repeater';
                $e['key']  = (string) ($v['index_name'] ?? '') ?: 'rows_' . $uid;
                $e['start_count'] = (int) ($a['data-mincount'] ?? 1);
                $e['max_count']   = (int) ($a['data-maxcount'] ?? 0);
                $this->add($e, $v, $b, $what);
                $this->viewsIn($uid, $uid);

                return;

            case 'area_modal':
            case 'area_popup':
            case 'area_message':
                $this->note('changed', str_replace('_', ' ', $type) . ' ' . $uid, 'A ' . str_replace('area_', '', $type) . ' box: its contents are shown on the page in SquidForm.');
                $this->viewsIn($uid, $parent);

                return;

            case 'list_table':
                $e['name'] = 'table';
                $src       = (string) (array_values((array) ($v['data_source'] ?? []))[0] ?? '');
                $e['datasource'] = $src !== '' && isset($this->fnId[$src]) ? '{var:read_data' . $this->fnId[$src] . '}' : '';
                $cols      = [];

                foreach ((array) ($v['columns'] ?? []) as $k => $col) {
                    $path = (string) ($v['areas'][$k]['name'] ?? '');
                    $cols[] = ['path' => preg_replace('/^[A-Za-z0-9_]+\./', '', $path) ?? $path, 'header' => (string) ($col['title'] ?? '')];
                }
                $e['columns'] = $cols;
                $this->el[$uid] = $e + ['behaviors' => []];

                return;

            case 'paginator':
            case 'menu':
                $e['name'] = $type;
                break;

            default:
                $this->note('todo', $what, 'ChronoForms 7 "' . str_replace('_', ' ', $type) . '" has no SquidForm equivalent; left out.');

                return;
        }

        $this->add($e, $v, $b, $what);
    }

    /** A view's behaviours, CF7 style → CF8 style, then the element in. */
    private function add(array $e, array $v, array $b, string $what): void
    {
        $nodes = (array) ($v['nodes'] ?? []);
        $uid   = (string) $e['id'];
        $rules = (array) ($v['fns']['validation']['fields'][$uid]['rules'] ?? array_values((array) ($v['fns']['validation']['fields'] ?? []))[0]['rules'] ?? []);
        $error = trim((string) ($v['fns']['validation']['fields'][$uid]['error'] ?? ''));
        $out   = [];

        if (\in_array('field_validation_required', $b, true)) {
            $out[] = 'validation_required';
            $e['validation_required'] = ['prompt' => $error];
        }

        if (\in_array('field_validation_email', $b, true)) {
            $out[] = 'validation_email';
        }

        if (\in_array('field_validation_url', $b, true) && ($e['name'] ?? '') === 'field_text') {
            $e['input_type'] = 'url';
        }

        if (\in_array('field_validation_numbers', $b, true) && ($e['name'] ?? '') === 'field_text') {
            $e['input_type'] = 'number';
        }

        if (\in_array('field_validation_regex', $b, true)) {
            if (!empty($rules['regExp'])) {
                $out[] = 'validation_regex';
                $e['validation_regex'] = ['regex' => (string) $rules['regExp'], 'prompt' => $error];
            }

            if (!empty($rules['contains']) || !empty($rules['doesntContain'])) {
                $this->note('todo', $what, 'Had to contain (or not contain) some text; use a pattern rule in SquidForm.');
            }
        }

        if (\in_array('field_validation_length', $b, true)) {
            foreach (['minLength' => 'min_length', 'maxLength' => 'max_length'] as $cf => $sf) {
                if ((int) ($rules[$cf] ?? 0) > 0) {
                    $e['sf']['rules'][$sf] = (int) $rules[$cf];
                }
            }
        }

        if (\in_array('field_validation_choices', $b, true)) {
            $out[] = 'validation_count';
            $e['validation_count'] = ['mincount' => (int) ($rules['minChecked'] ?? 0), 'maxcount' => (int) ($rules['maxChecked'] ?? 0)];
        }

        if (\in_array('field_validation_match', $b, true) && (!empty($rules['match']) || !empty($rules['different']))) {
            $out[] = 'validation_matches';
            $e['validation_matches'] = ['id' => (string) ($rules['match'] ?? $rules['different'])];
        }

        if (\in_array('field_validation_custom', $b, true)) {
            $out[] = 'validation_function';
            $e['validation_function'] = ['php' => ''];
        }

        if (\in_array('field_value_placeholder', $b, true)) {
            $a = (array) ($nodes['main']['attrs'] ?? []);

            if (($a['placeholder'] ?? '') !== '') {
                $out[] = 'placeholder';
                $e['placeholder'] = ['text' => (string) $a['placeholder']];
            }

            $dv = (string) ($a['value'] ?? $nodes['main']['content'] ?? '');

            if ($dv !== '' && !\in_array($e['name'] ?? '', ['field_checkbox', 'field_button'], true)) {
                $out[] = 'default_value';
                $e['default_value'] = ['value' => $dv];
            }
        }

        if (($e['name'] ?? '') === 'field_hidden' && ($nodes['main']['attrs']['value'] ?? '') !== '') {
            $out[] = 'default_value';
            $e['default_value'] = ['value' => (string) $nodes['main']['attrs']['value']];
        }

        if (!empty($nodes['help']['content'])) {
            $out[] = 'hint';
            $e['hint'] = ['text' => trim(strip_tags((string) $nodes['help']['content']))];
        }

        if (!empty($nodes['tooltip']['attrs']['data-hint'])) {
            $out[] = 'tooltip';
            $e['tooltip'] = ['text' => trim(strip_tags((string) $nodes['tooltip']['attrs']['data-hint']))];
        }

        if (\in_array('field_disabled', $b, true) || \in_array('field_readonly', $b, true)) {
            $out[] = 'state';
            $e['state'] = ['disabled' => true];
        }

        if (\in_array('field_mask', $b, true)) {
            $m = (string) ($nodes['main']['attrs']['data-inputmask'] ?? '');

            if (preg_match('/[\'"]?mask[\'"]?\s*:\s*[\'"]([^\'"]+)[\'"]/', $m, $mm) || preg_match('/^[9a*\-\s()\/.+]+$/', $m, $mm)) {
                $mask = $mm[1] ?? $mm[0];

                if (preg_match('/^[9a*\-\s()\/.+#]+$/', $mask) && strlen($mask) <= 40) {
                    $e['sf']['mask'] = $mask;
                } else {
                    $this->note('todo', $what, 'Had the input mask "' . $mask . '"; SquidForm masks use 9 (digit), a (letter) and * (either): set one in the field\'s Text extras.');
                }
            } elseif ($m !== '') {
                $this->note('todo', $what, 'Had an input mask; set one in the field\'s Text extras (9, a and *).');
            }
        }

        if (\in_array('field_multiple', $b, true) && ($e['name'] ?? '') === 'field_select') {
            $out[] = 'multi_selection';
        }

        if (\in_array('field_select', ['field_select'], true) && !empty($nodes['main']['attrs']['data-searchable']) && ($e['name'] ?? '') === 'field_select') {
            $e['sf']['search'] = true;
        }

        // Choices: "value=Text" lines, or a list.
        if (\in_array($e['name'] ?? '', ['field_select', 'field_radios', 'field_checkboxes'], true)) {
            if (isset($v['multiline_options']) && trim((string) $v['multiline_options']) !== '') {
                $e['options'] = (string) $v['multiline_options'];
            } else {
                $lines = [];

                foreach ((array) ($v['options'] ?? []) as $o) {
                    $val = (string) ($o['value'] ?? '');
                    $txt = trim(strip_tags((string) ($o['content'] ?? $val)));
                    $lines[] = $val === '' && $txt === '' ? '' : $val . '=' . ($txt !== '' ? $txt : $val);
                }
                $e['options'] = implode("\n", array_filter($lines, static fn ($l) => $l !== '' && !str_starts_with($l, '=')));
            }

            $sel = (string) ($v['multiline_selected'] ?? '');

            if ($sel !== '') {
                $out[] = 'selected_values';
                $e['selected_values'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $sel) ?: [])));
            }

            if (\in_array('field_dynamic_options', $b, true)) {
                $src = (string) (array_values((array) ($v['data_source'] ?? []))[0] ?? '');
                $tpl = static fn (string $t) => preg_replace('/\{var:[A-Za-z0-9_]+\.([A-Za-z0-9_]+)\}/', '{row:$1}', trim($t));
                $od  = [];

                foreach ((array) ($v['dynamic_options']['option_data'] ?? []) as $o) {
                    $od[(string) ($o['type'] ?? '')] = (string) ($o['value'] ?? '');
                }

                if ($src !== '' && isset($this->fnId[$src]) && ($this->fns[$src]['type'] ?? '') === 'read_data') {
                    $this->readEl($src);
                    $value = $tpl($od['value'] ?? '');
                    $text  = $tpl($od['content'] ?? '');

                    // "title (id: 5)": SquidForm shows one column; the first that isn't the value.
                    if (!preg_match('/^\{row:[A-Za-z0-9_]+\}$/', $text) && preg_match_all('/\{row:([A-Za-z0-9_]+)\}/', $text, $m)) {
                        $pick = current(array_filter($m[1], static fn ($c) => '{row:' . $c . '}' !== $value)) ?: $m[1][0];
                        $this->note('changed', $what, 'Its choices showed "' . $od['content'] . '"; SquidForm shows one column: ' . $pick . '.');
                        $text = '{row:' . $pick . '}';
                    }

                    $out[] = 'dynamic_options';
                    $e['dynamic_options'] = ['datasource' => '{var:read_data' . $this->fnId[$src] . '}', 'value' => $value, 'text' => $text];

                    // The read's "column = {data:other_field}" condition: the list follows that field.
                    foreach ((array) ($this->fns[$src]['models']['data']['conditions'] ?? []) as $c) {
                        if (\in_array($c['op'] ?? '=', ['=', '=='], true) && preg_match('/^\{data:([^}]+)\}$/', trim((string) ($c['value'] ?? '')), $mm)) {
                            $e['sf']['filter_by'] = ['name' => $mm[1], 'column' => (string) ($c['field'] ?? '')];
                            $this->note('changed', $what, 'Its choices follow "' . $mm[1] . '" (rows where ' . ($c['field'] ?? '') . ' matches it), as in ChronoForms.');
                        } else {
                            $this->note('todo', $what, 'Its choices were limited (' . ($c['field'] ?? '') . ' ' . ($c['op'] ?? '=') . ' ' . ($c['value'] ?? '') . '); SquidForm lists the whole table, or the rows matching another field: check the choices.');
                        }
                    }
                } else {
                    $out[] = 'dynamic_options';
                    $e['dynamic_options'] = ['datasource' => '(a ChronoForms data source)'];
                }
            }
        }

        if (\in_array('field_dblog', $b, true) === false && \in_array($e['name'] ?? '', ['field_text', 'field_textarea'], true) && !empty($v['dblog']) && empty($v['dblog']['enabled'])) {
            $this->note('info', $what, 'Wasn\'t kept in ChronoForms\' data log; SquidForm saves every answer.');
        }

        if (($e['name'] ?? '') === 'field_textarea' && (int) ($nodes['main']['attrs']['rows'] ?? 0) > 0) {
            $e['rows'] = (int) $nodes['main']['attrs']['rows'];
        }

        if (($e['name'] ?? '') === 'field_textarea' && !empty($nodes['main']['attrs']['data-toolbar1'])) {
            $e['sf']['type'] = 'editor';
            $this->note('changed', $what, 'Had a text editor; it\'s a Formatted text field in SquidForm.');
        }

        if (isset($this->reloads[$uid]) && empty($e['sf']['filter_by'])) {
            $this->note('todo', $what, \in_array($e['name'] ?? '', ['field_select', 'field_radios', 'field_checkboxes'], true)
                ? 'Reloaded its choices when another field changed; for linked lists, use a table source with a parent field (Choices → A database table), or a file with groups.'
                : 'Was reloaded from the server when another field changed; not imported. A calculation or "Fill in from" may do the same.');
        }

        // Show/hide from this view's own events.
        if (isset($this->listeners[$uid])) {
            $e['listeners'] = $this->listeners[$uid];
        }

        if (isset($this->triggers[$uid])) {
            $e['triggers'] = $this->triggers[$uid];
        }

        $e['behaviors'] = array_values(array_unique($out));
        $this->el[$uid] = $e;
    }

    /** A last page's text: shown after sending (CF8 "message"). */
    private function endViews(string $pid, string $host, string $pageName): void
    {
        $text = '';

        foreach ($this->views as $uid => $v) {
            if ((string) ($v['_area'] ?? '') !== $pid || (string) ($v['_parent'] ?? '') !== '') {
                continue;
            }

            $nodes = (array) ($v['nodes'] ?? []);
            $type  = (string) ($v['type'] ?? '');
            $text .= match ($type) {
                'text_node' => (string) ($nodes['main']['content'] ?? ''),
                'html_code' => (string) ($nodes['main']['d_content'] ?? $nodes['main']['content'] ?? ''),
                'header'    => '<h3>' . (string) ($nodes['content']['content'] ?? '') . '</h3>',
                default     => '',
            };

            if (!\in_array($type, ['text_node', 'html_code', 'header', 'field_button', 'css', 'javascript'], true)) {
                $this->note('todo', str_replace('_', ' ', $type) . ' ' . $uid, 'On the "' . $pageName . '" page after sending; SquidForm shows a message there, so this was left out.');
            }
        }

        if (trim(strip_tags($text)) !== '') {
            $this->el['msg' . $pid] = ['id' => 'msg' . $pid, 'type' => 'views', 'name' => 'message', 'parent' => $host, 'section' => 'submit', 'text' => $text, 'behaviors' => []];
        }
    }

    // ── Functions ──────────────────────────────────────────────────────────

    private function functions(string $pid, string $host, string $section, string $parentFn = '', string $area = ''): void
    {
        foreach ($this->fns as $uid => $f) {
            $uid = (string) $uid;

            if ($parentFn === '') {
                if ((string) ($f['_parent'] ?? '') !== '' || (string) ($f['_area'] ?? '') !== $pid) {
                    continue;
                }
            } elseif ((string) ($f['_parent'] ?? '') !== $parentFn || (string) ($f['_area'] ?? '') !== $area) {
                continue;
            }

            $this->fn($uid, $f, $host, $section);

            // Its "success" functions follow it; "fail" ones are reported.
            $this->functions($pid, $host, $section, $uid, 'success');

            foreach ($this->fns as $k => $g) {
                if ((string) ($g['_parent'] ?? '') === $uid && !\in_array((string) ($g['_area'] ?? ''), ['success', ''], true)) {
                    $this->note('todo', ucfirst(str_replace('_', ' ', (string) ($g['type'] ?? ''))) . ' ' . $k, 'Ran when "' . ($f['wtitle'] ?? $f['type']) . '" ' . ((string) $g['_area'] === 'fail' ? 'failed' : 'gave "' . $g['_area'] . '"') . '; SquidForm shows its own message when saving fails, so this was left out.');
                }
            }
        }
    }

    private function fn(string $uid, array $f, string $host, string $section): void
    {
        $type = (string) ($f['type'] ?? '');
        $id   = $this->fnId[$uid];
        $e    = ['id' => $id, 'type' => 'actions', 'parent' => $host, 'section' => $section, 'name' => $type, 'behaviors' => []];
        $what = trim((string) ($f['wtitle'] ?? '')) ?: ucfirst(str_replace('_', ' ', $type)) . ' ' . $uid;

        if (!empty($f['behaviors']) && \in_array('conditions', $this->flat($f), true) && $type !== 'var_constructor') {
            $e['run_conditions'] = true;
        }

        switch ($type) {
            case 'email':
                $e += [
                    'recipients' => array_values(array_filter(array_map('strval', (array) ($f['recipients'] ?? [])))),
                    'subject'    => (string) ($f['subject'] ?? ''),
                    'body'       => (string) ($f['body'] ?? ''),
                    'cc'         => array_values((array) ($f['cc'] ?? [])),
                    'bcc'        => array_values((array) ($f['bcc'] ?? [])),
                    'reply'      => (string) ($f['reply_email'] ?? ''),
                    'fromname'   => (string) ($f['from_name'] ?? ''),
                    'from'       => (string) ($f['from_email'] ?? ''),
                ];

                if (!empty($f['attachments']) || \in_array('email_attachments', $this->flat($f), true) && empty($f['attachments_disabled'])) {
                    $e['attachments'] = true;
                }
                break;

            case 'save_data':
                $e['dbtable'] = (string) ($f['models']['data']['name'] ?? '');
                $act = (string) ($f['models']['data']['action'] ?? 'insert');

                if ($act !== 'insert' && $e['dbtable'] !== '' && !str_contains($e['dbtable'], 'chronoforms')) {
                    $this->note('todo', $what, 'Updated rows in ' . $e['dbtable'] . ' (' . $act . '); SquidForm saves new submissions of its own form, and never writes other tables.');
                }
                break;

            case 'read_data':
                // Kept off the pages: CF8's converter finds it when a list or a listing uses it.
                $this->readEl($uid);

                if (!$this->readUsed($uid)) {
                    $this->note('todo', $what, 'Read data from ' . ($f['models']['data']['name'] ?? 'a table') . ($section === 'submit' ? ' after sending' : ' when the page opened') . '; SquidForm has Read data too: add it to the page\'s "When the page opens" with the table on the read allow-list.');
                }

                return;

            case 'redirect':
                $url = (string) ($f['pageurl'] ?? '');

                if (ctype_digit($url)) {
                    $this->note('todo', $what, 'Went to page ' . $url . ' of this ChronoForms form; SquidForm shows its message after sending. Add a Redirect to a web address if one is needed.');

                    return;
                }
                $e['url'] = $url;
                break;

            case 'message':
                $e['type'] = 'views';
                $e['text'] = (string) ($f['content'] ?? '');
                break;

            case 'php':
                $e['code'] = (string) ($f['code'] ?? '');
                break;

            case 'validation':
            case 'upload':
            case 'check_honeypot':
            case 'gcaptcha':
            case 'secicon':
            case 'event_load':
                return; // SquidForm does these itself

            case 'var_constructor':
                $this->note('todo', $what, 'Set variables (' . implode(', ', array_map(static fn ($x) => (string) ($x['name'] ?? ''), (array) ($f['values'] ?? []))) . '); use a hidden field with "Fill in from", or a calculation.');

                return;

            case 'unit_reference':
                return; // calls another function already in the report

            default:
                // CF8's converter reports anything it doesn't know by name.
                break;
        }

        $this->el[$id] = $e;
    }

    /** A read_data function as a CF8 element (dbtable), not on any page. */
    private function readEl(string $uid): void
    {
        $f = $this->fns[$uid] ?? null;

        if ($f === null || ($f['type'] ?? '') !== 'read_data') {
            return;
        }

        $id = $this->fnId[$uid];
        $this->el[$id] ??= ['id' => $id, 'type' => 'actions', 'name' => 'read_data', 'parent' => '', 'section' => 'load', 'behaviors' => [], 'dbtable' => (string) ($f['models']['data']['name'] ?? ''), 'fields' => ''];
    }

    private function readUsed(string $uid): bool
    {
        foreach ($this->views as $v) {
            if (\in_array($uid, array_map('strval', (array) ($v['data_source'] ?? [])), true)) {
                return true;
            }
        }

        return false;
    }

    // ── Settings ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> CF8 params, and the form-wide emails, message and redirect as actions */
    private function settings(array $s, ?string $last): array
    {
        $b      = $this->flat($s);
        $params = ['log_data' => \in_array('log_data', $b, true) ? '1' : '0'];

        if ($last === null) {
            return $params;
        }

        foreach (['admin_email', 'user_email'] as $k) {
            if (\in_array($k, $b, true) && !empty($s[$k]['recipients'])) {
                $this->el['set_' . $k] = ['id' => 'set_' . $k, 'type' => 'actions', 'name' => 'email', 'parent' => $last, 'section' => 'submit', 'behaviors' => [],
                    'recipients' => array_values((array) $s[$k]['recipients']), 'subject' => (string) ($s[$k]['subject'] ?? ''), 'body' => '', 'reply' => (string) ($s[$k]['reply_email'] ?? '')];
            }
        }

        if (\in_array('confirmation_message', $b, true) && trim((string) ($s['confirmation_message']['content'] ?? '')) !== '') {
            $this->el['set_msg'] = ['id' => 'set_msg', 'type' => 'views', 'name' => 'message', 'parent' => $last, 'section' => 'submit', 'behaviors' => [], 'text' => (string) $s['confirmation_message']['content']];
        }

        if (\in_array('redirect', $b, true) && trim((string) ($s['redirect']['pageurl'] ?? '')) !== '') {
            $this->el['set_redirect'] = ['id' => 'set_redirect', 'type' => 'actions', 'name' => 'redirect', 'parent' => $last, 'section' => 'submit', 'behaviors' => [], 'url' => (string) $s['redirect']['pageurl']];
        }

        if (\in_array('css', $b, true) && trim((string) ($s['css']['content'] ?? '')) !== '') {
            $this->el['set_css'] = ['id' => 'set_css', 'type' => 'views', 'name' => 'css', 'parent' => array_key_first(array_filter($this->el, static fn ($e) => $e['type'] === 'page')) ?? $last, 'section' => 'load', 'behaviors' => [], 'code' => (string) $s['css']['content']];
        }

        if (\in_array('gcaptcha', $b, true)) {
            $this->el['set_captcha'] = ['id' => 'set_captcha', 'type' => 'views', 'name' => 'security_recaptcha', 'parent' => $last, 'section' => 'load', 'behaviors' => []];
        }

        if (\in_array('acl', $b, true)) {
            $params['acl'] = true;
        }

        return $params;
    }

    // ── Show / hide ────────────────────────────────────────────────────────

    /** CF7 events (on the target) → CF8 triggers (on the source) and listeners (on the target). */
    private function events(): void
    {
        $ops = ['==' => 'in', '!=' => 'not-in', 'in' => 'in', '!in' => 'not-in', 'empty' => 'empty', '!empty' => 'not-empty', 'regex' => 'regex'];
        $acts = ['show' => 'show', 'hide' => 'hide', 'enable' => 'enable', 'disable' => 'disable', 'toggle_show' => 'show', 'toggle_enable' => 'enable'];

        foreach ($this->views as $tuid => $v) {
            if ($this->isFilter($v)) {
                continue;
            }

            foreach ((array) ($v['vevents'] ?? []) as $n => $ev) {
                $what   = trim(strip_tags((string) ($v['nodes']['label']['content'] ?? ''))) ?: str_replace('_', ' ', (string) ($v['type'] ?? '')) . ' ' . $tuid;
                $mine   = [];
                $others = [];

                foreach ((array) ($ev['actions'] ?? []) as $a) {
                    if (isset($acts[$a])) {
                        $mine[] = $acts[$a];

                        if (str_starts_with($a, 'toggle_')) {
                            $this->note('changed', $what, 'Toggled (' . $a . '); in SquidForm it shows (or is enabled) while the condition holds.');
                        }
                    } else {
                        $others[] = $a;
                    }
                }

                if (!empty($ev['cactions'])) {
                    $others[] = 'complex actions';
                }

                foreach ($others as $o) {
                    if ($o === 'reload') {
                        $this->reloads[(string) $tuid] = true;
                        continue;
                    }

                    $this->note('todo', $what, match ($o) {
                        'clear'  => 'Was cleared when another field changed; SquidForm clears answers that are hidden when the form is sent.',
                        'ajax'   => 'Called the server (AJAX) when something changed; not imported.',
                        'enable_validation', 'disable_validation', 'toggle_validation' => 'Turned its checks on or off; in SquidForm a field\'s rules apply only while it shows.',
                        default  => 'Had the event action "' . $o . '"; not imported.',
                    });
                }

                if (!$mine) {
                    continue;
                }

                $rules = array_values((array) ($ev['rules'] ?? []));
                $and   = ($ev['logic'] ?? 'or') === 'and';
                $names = [];
                $bad   = false;

                foreach ($rules as $i => $r) {
                    $src = (string) ($r['source'] ?? '');
                    $op  = (string) ($r['action'] ?? '');

                    if (!isset($ops[$op]) || !isset($this->views[$src])) {
                        $this->note('todo', $what, isset($ops[$op]) ? 'Showed or hid depending on something that isn\'t a field; add a condition in SquidForm.'
                            : 'Showed or hid "' . match ($op) { 'change' => 'when another field changed', 'input' => 'while typing', 'click' => 'on a click', 'ready' => 'when the page loaded', '>', '>=', '<', '<=' => 'when a number was ' . $op . ' a value', default => 'on "' . $op . '"' } . '"; add a condition in SquidForm (it has greater/less than too).');
                        $bad = true;
                        break;
                    }

                    // AND: one trigger name for every rule (CF8 ANDs conditions under one trigger); OR: one each.
                    $name     = 'cf7_' . $tuid . '_' . $n . ($and ? '' : '_' . $i);
                    $names[]  = $name;
                    $this->triggers[$src][] = ['name' => [$name], 'condition' => $ops[$op], 'value' => array_values(array_filter(array_map('strval', (array) ($r['value'] ?? [])), static fn ($x) => $x !== '' || $ops[$op] !== 'in'))];
                }

                if (!$bad && $names) {
                    $this->listeners[(string) $tuid][] = ['trigger' => array_values(array_unique($names)), 'actions' => array_values(array_unique($mine))];
                }
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** CF7 behaviours are grouped ({"data": [...], "validation": [...]}); one flat list. @return list<string> */
    private function flat(array $unit): array
    {
        $out = [];

        foreach ((array) ($unit['behaviors'] ?? []) as $g => $list) {
            foreach ((array) $list as $x) {
                $out[] = (string) $x;
            }
        }

        return $out;
    }

    private function note(string $level, string $what, string $text): void
    {
        foreach ($this->notes as $r) {
            if ($r['what'] === $what && $r['text'] === $text) {
                return;
            }
        }

        $this->notes[] = ['level' => $level, 'what' => $what, 'text' => $text];
    }
}
