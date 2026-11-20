/**
 * SquidForm front-end behaviour (no dependencies, no network calls except the
 * site's own cascading-options endpoint).
 *
 * - show_if: hides fields whose condition is false. The server applies the
 *   same rules and drops hidden values, so this is a convenience only.
 * - Repeaters: add/remove rows, keep numbering, announce changes.
 * - Cascading selects: reload options when the parent changes.
 * - Focus: moves focus to the error summary or result message after a submit.
 * - 0.8.0: multiple-choice dropdowns, columns that hide with their field,
 *   character counters, input masks, and collapsed sections that open when
 *   an error link points inside them.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const t = (key, fallback) => (window.Joomla && Joomla.Text && Joomla.Text._(key, fallback)) || fallback;

  /* ---------------------------------------------------------- conditions */

  const isEmpty = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);

  function compare(left, op, right) {
    if (Array.isArray(left) && ['eq', 'neq', 'contains', 'ncontains'].includes(op)) {
      const hit = left.map(String).includes(String(right));
      return op === 'neq' || op === 'ncontains' ? !hit : hit;
    }
    if (op === 'ncontains') return !compare(left, 'contains', right);
    const l = Array.isArray(left) ? '' : String(left ?? '');
    switch (op) {
      case 'eq': return l === String(right);
      case 'neq': return l !== String(right);
      case 'in': return [].concat(right).map(String).includes(l);
      case 'nin': return ![].concat(right).map(String).includes(l);
      case 'empty': return isEmpty(left);
      case 'filled': return !isEmpty(left);
      case 'gt': case 'gte': case 'lt': case 'lte': {
        // Same rule as PHP is_numeric on both sides, else plain text order.
        const num = (x) => x !== null && x !== undefined && String(x).trim() !== '' && !isNaN(Number(x));
        const bothNum = num(l) && num(right);
        const cmp = bothNum ? Math.sign(Number(l) - Number(right)) : (l < String(right) ? -1 : (l > String(right) ? 1 : 0));
        return { gt: cmp > 0, gte: cmp >= 0, lt: cmp < 0, lte: cmp <= 0 }[op];
      }
      case 'contains': return right !== '' && right != null && l.toLowerCase().includes(String(right).toLowerCase());
      case 'matches': try { return new RegExp(String(right), 'u').test(l); } catch (e) { return false; }
      default: return false;
    }
  }

  function evaluate(cond, lookup, depth = 0) {
    if (!cond || depth > 16) return true;
    if (cond.all) return [].concat(cond.all).every((c) => evaluate(c, lookup, depth + 1));
    if (cond.any) return [].concat(cond.any).some((c) => evaluate(c, lookup, depth + 1));
    if (cond.not) return !evaluate(cond.not, lookup, depth + 1);
    // User data (0.14.0) is worked out on the server; the page gets the result.
    if ('user_result' in cond) return cond.user_result === true;
    if (!cond.field) return true;
    return compare(lookup(cond.field), cond.op || 'eq', cond.value);
  }

  /* ---------------------------------------------------------- values */

  // Controls inside a field hidden by show_if are disabled. The server drops
  // their values, so the browser must treat them as absent too.
  const live = (el) => !el.disabled || el.dataset.sfLocked === '1';

  // Name prefix for a scope: "sf" for top level, "sf[rep][i]" inside a repeater row.
  function readValue(form, prefix, key) {
    const wrapper = form.querySelector(`[data-sf-key="${CSS.escape(key)}"]`);
    if (prefix === 'sf' && wrapper && wrapper.closest('[hidden]')) return undefined;
    // Look-up (0.8.1): the chosen value. Name, address, grid, signature: the
    // filled-in parts as a list, so "is filled in" and "contains" work.
    const base = `${prefix}[${key}]`;
    const lk = form.querySelector(`[data-sf-lookup-value][name="${CSS.escape(base + '[value]')}"]`);
    if (lk) return live(lk) ? lk.value : undefined;
    const parts = Array.from(form.querySelectorAll(`[name^="${CSS.escape(base + '[')}"]`)).filter(live);
    if (parts.length && !form.querySelector(`[name="${CSS.escape(base + '[]')}"]`)) {
      return parts.filter((el) => ((el.type === 'radio' || el.type === 'checkbox') ? el.checked : el.value !== '')).map((el) => el.value);
    }
    const single = Array.from(form.querySelectorAll(`[name="${CSS.escape(base)}"]`)).filter(live);
    const multi = Array.from(form.querySelectorAll(`[name="${CSS.escape(`${prefix}[${key}][]`)}"]`)).filter(live);
    if (multi.length) {
      const hiddenCarry = multi.filter((el) => el.type === 'hidden' && el.value !== '');
      if (hiddenCarry.length) return hiddenCarry.map((el) => el.value);
      const list = multi.find((el) => el.tagName === 'SELECT');
      if (list) return Array.from(list.selectedOptions).map((o) => o.value);
      return multi.filter((el) => el.type === 'checkbox' && el.checked).map((el) => el.value);
    }
    if (!single.length) return undefined;
    const els = single;
    const radios = els.filter((el) => el.type === 'radio');
    if (radios.length) {
      const c = radios.find((el) => el.checked);
      return c ? c.value : '';
    }
    const box = els.find((el) => el.type === 'checkbox');
    if (box) return box.checked ? '1' : '';
    return els[els.length - 1].value;
  }

  function rowPrefix(rowEl) {
    const rep = rowEl.closest('[data-sf-repeater]');
    return `sf[${rep.dataset.sfKey}][${rowEl.dataset.sfRow}]`;
  }

  // Values entered on earlier pages (rendered by the server as data-sf-known).
  function known(form) {
    if (!form._sfKnown) {
      try { form._sfKnown = JSON.parse(form.dataset.sfKnown || '{}'); } catch (e) { form._sfKnown = {}; }
    }
    return form._sfKnown;
  }

  function lookupFor(form, el) {
    const row = el.closest('.sf-row');
    return (key) => {
      if (row) {
        const v = readValue(form, rowPrefix(row), key);
        if (v !== undefined) return v;
      }
      const v = readValue(form, 'sf', key);
      if (v !== undefined) return v;
      const k = known(form);
      return Object.prototype.hasOwnProperty.call(k, key) ? k[key] : undefined;
    };
  }

  function applyConditions(form) {
    applyCalcs(form);
    // Two passes so a field that depends on a hidden field updates too.
    for (let pass = 0; pass < 2; pass++) {
      form.querySelectorAll('[data-sf-show-if]').forEach((el) => {
        if (el.closest('template')) return;
        let cond;
        try { cond = JSON.parse(el.dataset.sfShowIf); } catch (e) { return; }
        const show = evaluate(cond, lookupFor(form, el));
        el.hidden = !show;
        // A field in a column takes its column with it, so the row closes up.
        if (el.parentElement && el.parentElement.classList.contains('sf-col')) el.parentElement.hidden = !show;
        // Disabled controls are not posted; the server drops hidden values anyway.
        el.querySelectorAll('input, select, textarea, button').forEach((c) => {
          if (show) {
            if (c.dataset.sfHiddenDisabled) { c.disabled = false; delete c.dataset.sfHiddenDisabled; }
          } else if (!c.disabled) {
            c.disabled = true; c.dataset.sfHiddenDisabled = '1';
          }
        });
      });
    }
    // Text blocks that show parts by an answer (0.9.1): data-show-when="A|B".
    form.querySelectorAll('[data-sf-parts-by]').forEach((block) => {
      const v = lookupFor(form, block)(block.dataset.sfPartsBy);
      const have = [].concat(v ?? []).map(String);
      block.querySelectorAll('[data-show-when]').forEach((part) => {
        part.hidden = !part.getAttribute('data-show-when').split('|').map((x) => x.trim()).some((x) => have.includes(x));
      });
    });
  }


  /* ---------------------------------------------------------- calculations */

  // Calculations (0.10.0). The server parses each formula into a small tree
  // (data-sf-calc) and works out the saved value itself; this shows the same
  // result while people type. The tree is evaluated here exactly as in PHP
  // (Engine\Formula). A field that isn't on this page and isn't known from an
  // earlier page leaves the server's figure in place.
  const UNKNOWN = Symbol('unknown');
  const isNum = (v) => typeof v === 'number' || (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v.trim())));
  const toNum = (v) => (isNum(v) ? Number(typeof v === 'string' ? v.trim() : v) : 0);
  const truthy = (v) => !(v === null || v === '' || (isNum(v) ? toNum(v) === 0 : v === '0'));
  const finite = (v) => (v === null || !Number.isFinite(v) ? null : v);
  function calcScalar(v) {
    if (v === undefined) throw UNKNOWN;
    if (v === null || v === '') return '';
    if (typeof v === 'boolean') return v ? 1 : 0;
    if (Array.isArray(v)) return v.reduce((a, x) => a + (isNum(x) ? Number(x) : 0), 0);
    if (typeof v === 'object') return v.from ?? '';
    return typeof v === 'number' ? v : String(v);
  }
  const dateOf = (v) => { const m = typeof v === 'string' && /^(\d{4})-(\d{2})-(\d{2})/.exec(v); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null; };
  // 0.13.1: the site's today (its time zone), as the server counts it; else the browser's.
  const today = () => { const t = document.querySelector('form[data-sf-today]')?.getAttribute('data-sf-today'); if (t && /^\d{4}-\d\d-\d\d$/.test(t)) return t; const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  const refsIn = (n, out = []) => {
    if (n[0] === 'ref') out.push([n[1]]); else if (n[0] === 'rref') out.push([n[1], n[2]]);
    else if (n[0] === 'op') { refsIn(n[2], out); refsIn(n[3], out); } else if (n[0] === 'neg' || n[0] === 'not') refsIn(n[1], out);
    else if (n[0] === 'fn') n[2].forEach((a) => refsIn(a, out));
    return out;
  };
  function phpRound(v, d) {
    const a = Math.abs(v);
    const r = Number(Math.round(Number(`${a}e${d}`)) + `e${-d}`);
    return v < 0 ? -r : r;
  }

  function calcEval(n, get, rows) {
    switch (n[0]) {
      case 'num': return Number(n[1]);
      case 'str': return String(n[1]);
      case 'ref': { const rs = rows(n[1]); return rs !== null ? rs.length : calcScalar(get(n[1])); }
      case 'rref': return null;
      case 'neg': { const v = calcEval(n[1], get, rows); return v === null ? null : -toNum(v); }
      case 'not': return truthy(calcEval(n[1], get, rows)) ? 0 : 1;
      case 'op': {
        const op = n[1];
        if (op === 'and' || op === 'or') {
          const l = truthy(calcEval(n[2], get, rows));
          if (op === 'and' && !l) return 0;
          if (op === 'or' && l) return 1;
          return truthy(calcEval(n[3], get, rows)) ? 1 : 0;
        }
        const a = calcEval(n[2], get, rows);
        const b = calcEval(n[3], get, rows);
        if (['=', '!=', '<', '<=', '>', '>='].includes(op)) {
          if (a === null || b === null) return 0;
          let c;
          if (isNum(a) && isNum(b)) c = Math.sign(toNum(a) - toNum(b));
          else { const x = String(a).trim().toLowerCase(); const y = String(b).trim().toLowerCase(); c = x < y ? -1 : (x > y ? 1 : 0); }
          return ({ '=': c === 0, '!=': c !== 0, '<': c < 0, '<=': c <= 0, '>': c > 0, '>=': c >= 0 })[op] ? 1 : 0;
        }
        if (a === null || b === null) return null;
        const x = toNum(a); const y = toNum(b);
        switch (op) {
          case '+': return finite(x + y);
          case '-': return finite(x - y);
          case '*': return finite(x * y);
          case '/': return y === 0 ? null : finite(x / y);
          case '%': return y === 0 ? null : finite(x % y);
        }
        return null;
      }
      case 'fn': return calcCall(n[1], n[2], get, rows);
    }
    return null;
  }

  function calcCall(fn, args, get, rows) {
    const ev = (a) => calcEval(a, get, rows);
    switch (fn) {
      case 'if': return truthy(ev(args[0])) ? ev(args[1]) : ev(args[2]);
      case 'round': { const v = ev(args[0]); const p = args[1] ? Math.trunc(toNum(ev(args[1]) ?? 0)) : 0; return v === null ? null : phpRound(toNum(v), Math.max(-6, Math.min(10, p))); }
      case 'floor': case 'ceil': case 'abs': { const v = ev(args[0]); return v === null ? null : Math[fn](toNum(v)); }
      case 'today': return today();
      case 'days': {
        if (args.length === 1) {
          if (args[0][0] !== 'ref') return null;
          const r = get(args[0][1]);
          if (r === undefined) throw UNKNOWN;
          const pair = Array.isArray(r) ? r : (r && typeof r === 'object' ? [r.from, r.to] : []);
          const a = dateOf(pair[0]); const b = dateOf(pair[1]);
          return a === null || b === null ? null : Math.round((b - a) / 864e5) + 1;
        }
        const a = dateOf(ev(args[0])); const b = dateOf(ev(args[1]));
        return a === null || b === null ? null : Math.round((b - a) / 864e5);
      }
      case 'age': {
        // 0.13.1: years × 10000 + months × 100 + days, counted on the calendar.
        const a = ev(args[0]); const b = ev(args[1]);
        if (dateOf(a) === null || dateOf(b) === null || b.slice(0, 10) < a.slice(0, 10)) return null;
        const [y1, m1, d1] = a.slice(0, 10).split('-').map(Number);
        const [y2, m2] = b.slice(0, 10).split('-').map(Number);
        const plus = (k) => { const t = y1 * 12 + (m1 - 1) + k; const y = Math.floor(t / 12); const m = t % 12; const len = new Date(Date.UTC(y, m + 1, 0)).getUTCDate(); return new Date(Date.UTC(y, m, Math.min(d1, len))); };
        let total = (y2 - y1) * 12 + (m2 - m1);
        const end = new Date(b.slice(0, 10) + 'T00:00:00Z');
        if (plus(total) > end) total -= 1;
        const y = Math.floor(total / 12); const m = total % 12;
        const d = Math.round((end - plus(total)) / 86400000);
        return y * 10000 + m * 100 + d;
      }
      case 'years': {
        const a = ev(args[0]); const b = ev(args[1]);
        if (dateOf(a) === null || dateOf(b) === null) return null;
        const [y1, m1, d1] = a.slice(0, 10).split('-').map(Number);
        const [y2, m2, d2] = b.slice(0, 10).split('-').map(Number);
        return y2 - y1 - (m2 < m1 || (m2 === m1 && d2 < d1) ? 1 : 0);
      }
    }
    // Aggregates
    let vals = [];
    args.forEach((a) => {
      const group = (refsIn(a).find((r) => r.length === 2) || [])[0];
      if (a[0] === 'ref') {
        const rs = rows(a[1]);
        if (rs !== null) { vals = vals.concat(rs.map(() => 1)); return; }
      }
      if (group !== undefined) {
        const rs = rows(group);
        if (rs === null) throw UNKNOWN;
        rs.forEach((rowGet) => {
          const g = (k) => { const v = rowGet(k); return v !== undefined ? v : get(k); };
          const swap = (n) => (n[0] === 'rref' ? (n[1] === group ? ['ref', n[2]] : n)
            : n[0] === 'op' ? ['op', n[1], swap(n[2]), swap(n[3])]
              : (n[0] === 'neg' || n[0] === 'not') ? [n[0], swap(n[1])]
                : n[0] === 'fn' ? ['fn', n[1], n[2].map(swap)] : n);
          const v = calcEval(swap(a), g, rows);
          if (v !== null && v !== '') vals.push(v);
        });
        return;
      }
      if (a[0] === 'ref') {
        const raw = get(a[1]);
        if (raw === undefined) throw UNKNOWN;
        if (Array.isArray(raw)) { raw.forEach((x) => { if (fn === 'count' || isNum(x)) vals.push(fn === 'count' ? 1 : Number(x)); }); return; }
      }
      const v = ev(a);
      if (v !== null && !(fn === 'count' && v === '')) vals.push(v);
    });
    if (fn === 'count') return vals.length;
    const nums = vals.map(toNum);
    if (!nums.length) return fn === 'sum' ? 0 : null;
    const sum = nums.reduce((x, y) => x + y, 0);
    return finite(({ sum, avg: sum / nums.length, min: Math.min(...nums), max: Math.max(...nums) })[fn]);
  }

  function calcStore(v, d) {
    if (v === null || v === '' || !isNum(v)) return '';
    let n = toNum(v);
    if (d !== null) n = phpRound(n, d);
    let s = n.toFixed(10).replace(/0+$/, '').replace(/\.$/, '');
    return s === '-0' ? '0' : s;
  }
  function calcFormat(el, raw) {
    if (raw === '' || !isNum(raw)) return '';
    if (el.dataset.sfAge) {
      const n = Math.trunc(Number(raw));
      const unit = (v, u) => (Joomla.Text._(`COM_SQUIDFORM_AGE_${u}${v === 1 ? '_1' : ''}`) || `%d ${u.toLowerCase()}`).replace('%d', v);
      const parts = [[Math.trunc(n / 10000), 'YEARS'], [Math.trunc((n % 10000) / 100), 'MONTHS'], [n % 100, 'DAYS']].filter(([v]) => v > 0).map(([v, u]) => unit(v, u));
      if (!parts.length) return unit(0, 'DAYS');
      const last = parts.pop();
      return parts.length ? (Joomla.Text._('COM_SQUIDFORM_AGE_JOIN') || '%1$s and %2$s').replace('%1$s', parts.join(', ')).replace('%2$s', last) : last;
    }
    const n = Number(raw);
    const d = el.dataset.sfDecimals !== undefined ? Number(el.dataset.sfDecimals) : ((raw.split('.')[1] || '').length);
    let s = phpRound(Math.abs(n), d).toFixed(d);
    if (el.dataset.sfThousands) { const [i, f] = s.split('.'); s = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (f !== undefined ? '.' + f : ''); }
    const neg = n < 0 && Number(s.replace(/,/g, '')) !== 0;
    const pre = el.dataset.sfPrefix || '';
    const suf = el.dataset.sfSuffix || '';
    return (neg ? '-' : '') + pre + s + (suf ? ' ' + suf : '');
  }

  function rowsOf(form, key) {
    const rep = form.querySelector(`[data-sf-repeater][data-sf-key="${CSS.escape(key)}"]`);
    if (rep) {
      if (rep.closest('[hidden]')) return [];
      return Array.from(rep.querySelectorAll(':scope > .sf-rows > .sf-row')).map((row) => (k) => readValue(form, rowPrefix(row), k));
    }
    const k = known(form);
    if (Array.isArray(k[key]) && k[key].every((r) => r && typeof r === 'object' && !Array.isArray(r))) return k[key].map((r) => (c) => (Object.prototype.hasOwnProperty.call(r, c) ? r[c] : undefined));
    return null;
  }

  function applyCalcs(form) {
    const outs = Array.from(form.querySelectorAll('input[data-sf-calc]')).filter((el) => !el.closest('template'));
    if (!outs.length) return;
    const rows = (k) => rowsOf(form, k);
    // As many passes as there are calculations: one may use another.
    for (let pass = 0; pass <= outs.length; pass++) {
      outs.forEach((el) => {
        let tree;
        try { tree = JSON.parse(el.dataset.sfCalc); } catch (e) { return; }
        if (!tree) return;
        const hidden = el.parentElement.querySelector('input[data-sf-calc-value]');
        try {
          const base = lookupFor(form, el);
          // A field on this page that's hidden counts as empty (the server drops it too).
          const get = (k) => { const v = base(k); return v !== undefined ? v : (form.querySelector(`[data-sf-key="${CSS.escape(k)}"]`) ? '' : undefined); };
          const v = calcEval(tree, get, rows);
          const d = el.dataset.sfDecimals !== undefined ? Number(el.dataset.sfDecimals) : null;
          const raw = calcStore(v, d);
          if (hidden) hidden.value = raw;
          el.value = calcFormat(el, raw);
        } catch (e) {
          if (e !== UNKNOWN) throw e; // unknown: keep the server's figure
        }
      });
    }
  }

  /* ---------------------------------------------------------- repeaters */

  function renumber(rep) {
    const rows = rep.querySelectorAll(':scope > .sf-rows > .sf-row');
    rows.forEach((row, i) => row.querySelectorAll('.sf-row-num').forEach((n) => { n.textContent = String(i + 1); }));
    const max = Number(rep.dataset.sfMax || 50);
    const min = Number(rep.dataset.sfMin || 0);
    const add = rep.querySelector(':scope > .sf-add');
    if (add) add.disabled = rows.length >= max;
    rows.forEach((row) => {
      const rm = row.querySelector(':scope > .sf-remove');
      if (rm) rm.disabled = rows.length <= Math.max(min, 1);
    });
  }

  function announce(rep, text) {
    const live = rep.querySelector(':scope > .sf-live');
    if (live) { live.textContent = ''; window.setTimeout(() => { live.textContent = text; }, 50); }
  }

  function addRow(rep, form) {
    const rowsEl = rep.querySelector(':scope > .sf-rows');
    const tpl = rep.querySelector(':scope > template.sf-row-template');
    const max = Number(rep.dataset.sfMax || 50);
    if (rowsEl.children.length >= max) { announce(rep, t('COM_SQUIDFORM_MAX_ROWS_REACHED', 'Maximum rows reached')); return; }
    const idx = Number(rep.dataset.sfNext || rowsEl.children.length);
    rep.dataset.sfNext = String(idx + 1);
    const html = tpl.innerHTML.replaceAll('__SFROW__', String(idx)).replaceAll('__SFNUM__', String(rowsEl.children.length + 1));
    rowsEl.insertAdjacentHTML('beforeend', html);
    const row = rowsEl.lastElementChild;
    row.querySelectorAll('[data-sf-lookup]').forEach((b) => initLookup(form, b));
    row.querySelectorAll('select[data-sf-search]').forEach((s) => initSearchSelect(form, s));
    row.querySelectorAll('[data-sf-reveal]').forEach(initReveal);
    row.querySelectorAll('[data-sf-rank]').forEach(initRanking);
    row.querySelectorAll('.sf-slider input[type=range]').forEach(initSlider);
    row.querySelectorAll('[data-sf-range-to]').forEach(initRange);
    renumber(rep);
    applyConditions(form);
    const first = row.querySelector('input:not([type=hidden]), select, textarea');
    if (first) first.focus();
    announce(rep, t('COM_SQUIDFORM_ROW_ADDED', 'Row added').replace('%s', String(rowsEl.children.length)));
  }

  function removeRow(btn, form) {
    const rep = btn.closest('[data-sf-repeater]');
    const row = btn.closest('.sf-row');
    row.remove();
    renumber(rep);
    applyConditions(form);
    const add = rep.querySelector(':scope > .sf-add');
    if (add) add.focus();
    announce(rep, t('COM_SQUIDFORM_ROW_REMOVED', 'Row removed'));
  }

  /* ---------------------------------------------------------- cascading */

  async function reloadOptions(form, select) {
    const parentKey = select.dataset.sfParent;
    const fieldKey = select.dataset.sfField;
    const parentVal = lookupFor(form, select)(parentKey);
    const url = new URL(form.dataset.sfOptionsUrl, window.location.href);
    url.searchParams.set('form', form.dataset.sfAlias);
    url.searchParams.set('field', fieldKey);
    url.searchParams.set('parent', Array.isArray(parentVal) ? '' : (parentVal ?? ''));
    const keep = select.value;
    select.setAttribute('aria-busy', 'true');
    try {
      const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const json = res.ok ? await res.json() : { options: [] };
      const first = select.options[0] && select.options[0].value === '' ? select.options[0].cloneNode(true) : null;
      select.innerHTML = '';
      if (first) select.appendChild(first);
      (json.options || []).forEach((o) => {
        const opt = document.createElement('option');
        opt.value = o.value; opt.textContent = o.label;
        if (o.value === keep) opt.selected = true;
        select.appendChild(opt);
      });
      const live = form.querySelector('.sf-form-live');
      if (live) live.textContent = t('COM_SQUIDFORM_OPTIONS_LOADED', '%s options available').replace('%s', String((json.options || []).length));
      select.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {
      // Leave the list as it was; the server re-checks the choice on submit.
    } finally {
      select.removeAttribute('aria-busy');
    }
  }

  function refreshChildren(form, changedEl) {
    const name = changedEl.getAttribute('name') || '';
    const m = name.match(/\[([A-Za-z][A-Za-z0-9_]*)\](\[\])?$/);
    if (!m) return;
    const key = m[1];
    const scope = changedEl.closest('.sf-row') || form;
    scope.querySelectorAll(`select[data-sf-parent="${CSS.escape(key)}"]`).forEach((sel) => {
      if (!sel.closest('template')) reloadOptions(form, sel);
    });
  }

  /* ---------------------------------------------------------- counters, masks */

  function updateCounter(input, announceIt) {
    const out = document.querySelector(`[data-sf-counter-for="${CSS.escape(input.id)}"]`);
    if (!out) return;
    const n = [...input.value].length;
    const max = Number(out.dataset.sfMax || 0);
    out.textContent = max ? t('COM_SQUIDFORM_COUNTER_OF', '%1$d of %2$d characters').replace('%1$d', n).replace('%2$d', max)
      : (n === 1 ? t('COM_SQUIDFORM_COUNTER_N_1', '1 character') : t('COM_SQUIDFORM_COUNTER_N', '%d characters').replace('%d', n));
    if (!max || !announceIt) return;
    // Screen readers hear it only near the limit, not on every key.
    const live = out.nextElementSibling;
    const left = max - n;
    const state = left <= 0 ? 'full' : (left <= Math.max(10, Math.round(max / 10)) ? 'near' : '');
    if (live && state && out.dataset.sfSaid !== state + left) {
      out.dataset.sfSaid = state + left;
      if (state === 'full' || left % 5 === 0 || left <= 3) {
        live.textContent = state === 'full' ? t('COM_SQUIDFORM_COUNTER_FULL', 'You have reached the limit of %d characters.').replace('%d', max)
          : t('COM_SQUIDFORM_COUNTER_LEFT', '%d characters left').replace('%d', left);
      }
    }
  }

  /** "(999) 999-9999": 9 a digit, a a letter, * either; the rest is typed for you. */
  function applyMask(input) {
    const mask = input.dataset.sfMask;
    const slot = { 9: /[0-9]/, a: /[A-Za-z]/, '*': /[A-Za-z0-9]/ };
    const raw = [...input.value].filter((c) => /[A-Za-z0-9]/.test(c));
    let out = '';
    let i = 0;
    for (const m of mask) {
      if (i >= raw.length) break;
      if (slot[m]) {
        while (i < raw.length && !slot[m].test(raw[i])) i++;
        if (i >= raw.length) break;
        out += raw[i++];
      } else {
        out += m;
        if (raw[i] === m) i++;
      }
    }
    if (out !== input.value) input.value = out;
  }

  /* ---------------------------------------------------------- init */

  /* ---------------------------------------------------------- signature (0.8.1) */

  function initSignature(pad) {
    const canvas = pad.querySelector('canvas');
    const out = pad.querySelector('[data-sf-sig-png]');
    const state = pad.querySelector('[aria-live]');
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let drawn = false;
    let last = null;
    pad.hidden = false;

    const ink = () => {
      ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      ctx.strokeStyle = getComputedStyle(canvas).color || '#000';
    };
    const point = (e) => {
      const r = canvas.getBoundingClientRect();
      return { x: (e.clientX - r.left) * (canvas.width / r.width), y: (e.clientY - r.top) * (canvas.height / r.height) };
    };
    const save = () => {
      // Store as dark ink on a transparent background, whatever the theme.
      const copy = document.createElement('canvas');
      copy.width = canvas.width; copy.height = canvas.height;
      const c2 = copy.getContext('2d');
      c2.drawImage(canvas, 0, 0);
      c2.globalCompositeOperation = 'source-in';
      c2.fillStyle = '#111';
      c2.fillRect(0, 0, copy.width, copy.height);
      out.value = copy.toDataURL('image/png');
    };
    if (canvas.dataset.sfInitial) {
      const img = new Image();
      img.onload = () => { ctx.drawImage(img, 0, 0); drawn = true; };
      img.src = canvas.dataset.sfInitial;
    }
    canvas.addEventListener('pointerdown', (e) => {
      if (out.disabled) return;
      drawing = true; last = point(e); ink();
      canvas.setPointerCapture(e.pointerId);
      ctx.beginPath(); ctx.arc(last.x, last.y, 1.2, 0, Math.PI * 2); ctx.fill();
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drawing) return;
      const p = point(e);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; drawn = true;
      e.preventDefault();
    });
    const end = () => {
      if (!drawing) return;
      drawing = false;
      if (drawn) { save(); state.textContent = t('COM_SQUIDFORM_SIG_DRAWN', 'Signature drawn.'); }
    };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
    pad.querySelector('[data-sf-sig-clear]').addEventListener('click', () => {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      drawn = false; out.value = '';
      state.textContent = t('COM_SQUIDFORM_SIG_CLEARED', 'Signature cleared.');
    });
  }

  /* ---------------------------------------------------------- look-up (0.8.1) */

  function initLookup(form, box) {
    const input = box.querySelector('[data-sf-lookup-input]');
    const hidden = box.querySelector('[data-sf-lookup-value]');
    const list = box.querySelector('[role=listbox]');
    const liveEl = box.querySelector('[data-sf-lookup-live]');
    const min = Number(box.dataset.sfMin || 2);
    let items = [];
    let active = -1;
    let timer = null;
    let seq = 0;
    if (input.readOnly) return;

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);

    const close = () => {
      list.hidden = true; input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant'); active = -1;
    };
    const mark = (i) => {
      active = i;
      list.querySelectorAll('[role=option]').forEach((li, n) => li.setAttribute('aria-selected', String(n === i)));
      if (i >= 0) {
        const li = list.children[i];
        input.setAttribute('aria-activedescendant', li.id);
        li.scrollIntoView({ block: 'nearest' });
      } else input.removeAttribute('aria-activedescendant');
    };
    const choose = (i) => {
      const o = items[i];
      if (!o) return;
      input.value = o.label; hidden.value = o.value;
      close();
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const show = (opts) => {
      items = opts;
      list.textContent = '';
      opts.forEach((o, i) => {
        const li = document.createElement('li');
        li.id = `${input.id}-opt-${i}`;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.className = 'sf-lookup-option';
        li.textContent = o.label;
        li.addEventListener('mousedown', (e) => { e.preventDefault(); choose(i); });
        list.appendChild(li);
      });
      list.hidden = opts.length === 0;
      input.setAttribute('aria-expanded', String(opts.length > 0));
      active = -1;
      let msg = opts.length === 0 ? t('COM_SQUIDFORM_LOOKUP_NONE', 'No matches.')
        : (opts.length === 1 ? t('COM_SQUIDFORM_LOOKUP_1', '1 suggestion.') : t('COM_SQUIDFORM_LOOKUP_N', '%d suggestions.').replace('%d', String(opts.length)));
      if (opts.length >= 20) msg += ' ' + t('COM_SQUIDFORM_LOOKUP_MORE', 'Showing the first %d.').replace('%d', '20');
      liveEl.textContent = msg;
    };
    const search = async () => {
      const q = input.value.trim();
      if (q.length < min) { close(); liveEl.textContent = ''; return; }
      const url = new URL(form.dataset.sfOptionsUrl, window.location.href);
      url.searchParams.set('task', 'form.lookup');
      url.searchParams.set('form', form.dataset.sfAlias);
      url.searchParams.set('field', box.dataset.sfLookup);
      url.searchParams.set('q', q);
      if (box.dataset.sfParent) {
        const pv = lookupFor(form, box)(box.dataset.sfParent);
        url.searchParams.set('parent', Array.isArray(pv) ? (pv[0] || '') : (pv || ''));
      }
      const mine = ++seq;
      try {
        const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const json = res.ok ? await res.json() : { options: [] };
        if (mine === seq && document.activeElement === input) show(json.options || []);
      } catch (e) { /* offline: the server still checks what's typed */ }
    };

    input.addEventListener('input', () => {
      hidden.value = '';
      window.clearTimeout(timer);
      timer = window.setTimeout(search, 200);
    });
    input.addEventListener('keydown', (e) => {
      const open = !list.hidden && items.length;
      if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) { search(); return; } mark(Math.min(items.length - 1, active + 1)); }
      else if (e.key === 'ArrowUp') { if (open) { e.preventDefault(); mark(Math.max(0, active - 1)); } }
      else if (e.key === 'Enter') { if (open && active >= 0) { e.preventDefault(); choose(active); } }
      else if (e.key === 'Escape') { if (open) { e.preventDefault(); close(); } }
      else if (e.key === 'Home' || e.key === 'End') { if (open && active >= 0) { e.preventDefault(); mark(e.key === 'Home' ? 0 : items.length - 1); } }
    });
    input.addEventListener('blur', () => window.setTimeout(() => {
      // One exact match counts as chosen.
      if (!hidden.value && input.value.trim()) {
        const exact = items.find((o) => o.label.toLowerCase() === input.value.trim().toLowerCase());
        if (exact) { hidden.value = exact.value; input.value = exact.label; }
      }
      close();
    }, 0));
  }

  /* ---------------------------------------------------------- searchable dropdown (0.12.1) */

  // Turns a <select data-sf-search> into a combobox (ARIA 1.2 pattern). The select stays in the
  // form and holds the value, so show/hide, cascades and the server work exactly as before.
  const fold = (s) => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  function initSearchSelect(form, select) {
    if (select.dataset.sfSearchReady || select.disabled) return;
    select.dataset.sfSearchReady = '1';
    const id = select.id;
    const box = document.createElement('div');
    box.className = 'sf-search';
    const input = document.createElement('input');
    input.type = 'text';
    input.className = select.className.replace('form-select', 'form-control');
    input.id = id;
    input.autocomplete = 'off';
    input.spellcheck = false;
    ['aria-describedby', 'aria-invalid', 'aria-required'].forEach((a) => { if (select.hasAttribute(a)) input.setAttribute(a, select.getAttribute(a)); });
    input.required = select.required;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    const list = document.createElement('ul');
    list.className = 'sf-lookup-list' + (select.classList.contains('sf-striped') ? ' sf-striped' : '');
    list.id = id + '-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    const label = form.querySelector(`label[for="${CSS.escape(id)}"]`);
    if (label) { if (!label.id) label.id = id + '-lbl'; list.setAttribute('aria-labelledby', label.id); }
    input.setAttribute('aria-controls', list.id);
    const live = document.createElement('div');
    live.className = 'visually-hidden';
    live.setAttribute('aria-live', 'polite');
    select.id = id + '-native';
    select.required = false;
    select.hidden = true;
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.insertAdjacentElement('beforebegin', box);
    box.append(input, list, live, select);

    let items = [];
    let active = -1;
    const current = () => select.selectedOptions[0] && select.value !== '' ? select.selectedOptions[0].textContent : '';
    input.value = current();
    if (!input.placeholder) input.placeholder = (select.options[0] && select.options[0].value === '') ? select.options[0].textContent : '';
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
    const mark = (i) => {
      active = i;
      Array.from(list.children).forEach((li, n) => li.setAttribute('aria-selected', String(n === i)));
      if (i >= 0) { input.setAttribute('aria-activedescendant', list.children[i].id); list.children[i].scrollIntoView({ block: 'nearest' }); }
    };
    const pick = (o) => {
      if (!o || o.disabled) return;
      if (select.value !== o.value) { select.value = o.value; select.dispatchEvent(new Event('change', { bubbles: true })); }
      input.value = o.label;
      close();
      live.textContent = t('COM_SQUIDFORM_SEARCH_CHOSEN', '%s chosen.').replace('%s', o.label);
    };
    const open = (all) => {
      const q = all ? '' : fold(input.value.trim());
      items = Array.from(select.options).filter((o) => o.value !== '').map((o) => ({ value: o.value, label: o.textContent, disabled: o.disabled }))
        .filter((o) => q === '' || fold(o.label).includes(q));
      list.textContent = '';
      items.forEach((o, i) => {
        const li = document.createElement('li');
        li.id = `${id}-opt-${i}`;
        li.className = 'sf-lookup-option' + (o.disabled ? ' is-disabled' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        if (o.disabled) li.setAttribute('aria-disabled', 'true');
        li.textContent = o.label;
        li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(o); });
        list.appendChild(li);
      });
      list.hidden = items.length === 0;
      input.setAttribute('aria-expanded', String(items.length > 0));
      active = -1;
      live.textContent = items.length === 0 ? t('COM_SQUIDFORM_LOOKUP_NONE', 'No matches.')
        : (q === '' ? t('COM_SQUIDFORM_SEARCH_ALL', '%d choices. Type to narrow the list, or use the up and down arrows.')
          : t(items.length === 1 ? 'COM_SQUIDFORM_SEARCH_N' : 'COM_SQUIDFORM_SEARCH_N_MORE', '%d matches. Use the up and down arrows to choose.')).replace('%d', String(items.length));
    };
    input.addEventListener('input', () => {
      if (select.value !== '' && input.value !== current()) { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); }
      open(false);
    });
    input.addEventListener('click', () => { if (list.hidden) open(input.value === current()); });
    input.addEventListener('keydown', (e) => {
      const shown = !list.hidden && items.length;
      if (e.key === 'ArrowDown') { e.preventDefault(); if (!shown) { open(input.value === current()); if (items.length) mark(0); return; } mark(Math.min(items.length - 1, active + 1)); }
      else if (e.key === 'ArrowUp') { if (shown) { e.preventDefault(); mark(Math.max(0, active - 1)); } }
      else if (e.key === 'Enter') { if (shown && active >= 0) { e.preventDefault(); pick(items[active]); } }
      else if (e.key === 'Escape') { if (shown) { e.preventDefault(); close(); } else if (input.value) { input.value = current(); } }
      else if (e.key === 'Home' || e.key === 'End') { if (shown && active >= 0) { e.preventDefault(); mark(e.key === 'Home' ? 0 : items.length - 1); } }
    });
    input.addEventListener('blur', () => window.setTimeout(() => {
      if (select.value === '' && input.value.trim()) {
        const exact = Array.from(select.options).find((o) => o.value !== '' && !o.disabled && fold(o.textContent) === fold(input.value.trim()));
        if (exact) pick({ value: exact.value, label: exact.textContent }); else input.value = '';
      } else if (select.value !== '') input.value = current();
      close();
    }, 0));
    // Options reloaded by a cascade, or the value set from elsewhere.
    select.addEventListener('change', () => { if (document.activeElement !== input) input.value = current(); });
  }

  // Show / Hide for typing shown as dots (0.12.1).
  // 0.13.1: "Ask for it twice" for someone signed in. While the address is
  // their account's, the second box stays hidden; change it and it's back.
  function initAgain(box) {
    const again = box.querySelector('input');
    const first = box.closest('.sf-field')?.querySelector('input[type=email]:not([name^="sf_confirm"])');
    if (!again || !first) return;
    const mine = (box.getAttribute('data-sf-account') || '').trim().toLowerCase();
    const needRequired = again.hasAttribute('required') || first.hasAttribute('required');
    const sync = () => {
      const same = mine !== '' && first.value.trim().toLowerCase() === mine;
      if (!same && box.hidden && again.value.trim().toLowerCase() === mine) again.value = '';
      box.hidden = same;
      again.disabled = same;
      if (same || !needRequired) { again.removeAttribute('required'); again.removeAttribute('aria-required'); } else { again.required = true; again.setAttribute('aria-required', 'true'); }
    };
    first.addEventListener('input', sync);
    sync();
  }

  // 0.13.2: the "HTML you can use here" note shows only while the editor's
  // raw HTML is showing (Toggle Editor), and is tied to that box.
  function initHtmlNote(box) {
    const note = document.getElementById(box.getAttribute('data-sf-htmlnote'));
    if (!note) return;
    const sync = () => {
      const ta = box.querySelector('textarea');
      const raw = !!ta && ta.offsetParent !== null && getComputedStyle(ta).display !== 'none';
      note.hidden = !raw;
      // TinyMCE leaves aria-hidden on the box it shows for the HTML.
      if (ta && raw) ta.removeAttribute('aria-hidden');
      if (ta) {
        const ids = (ta.getAttribute('aria-describedby') || '').split(/\s+/).filter((x) => x && x !== note.id);
        if (raw) ids.unshift(note.id);
        if (ids.length) ta.setAttribute('aria-describedby', ids.join(' ')); else ta.removeAttribute('aria-describedby');
      }
    };
    box.addEventListener('click', (e) => { if (e.target.closest('.js-tiny-toggler-button')) setTimeout(sync, 60); });
    setTimeout(sync, 400);
  }

  function initReveal(wrap) {
    const input = wrap.querySelector('input');
    if (!input || wrap.querySelector('.sf-reveal-btn')) return;
    const label = (document.querySelector(`label[for="${CSS.escape(input.id)}"]`) || {}).textContent || '';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-secondary sf-reveal-btn';
    btn.setAttribute('aria-controls', input.id);
    const name = label.replace(/\s*\*?\s*\(required\)\s*$/i, '').replace(/\s*\*\s*$/, '').trim();
    // The spoken name starts with the word on screen (WCAG 2.5.3): "Show Locker code".
    const word = document.createElement('span');
    const field = document.createElement('span');
    field.className = 'visually-hidden';
    field.textContent = ' ' + name;
    const set = (shown) => { word.textContent = shown ? t('COM_SQUIDFORM_REVEAL_HIDE', 'Hide') : t('COM_SQUIDFORM_REVEAL_SHOW', 'Show'); };
    set(false);
    btn.append(word, field);
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      set(show);
    });
    wrap.classList.add('input-group');
    input.insertAdjacentElement('afterend', btn);
    // Back to dots before the page is sent, so it never stays on screen on the next page.
    input.form && input.form.addEventListener('submit', () => { input.type = 'password'; });
  }

  /* ---------------------------------------------------------- date limits (0.13.0) */

  // Weekdays, hours and "after another date": said as soon as a date is picked. The server checks again.
  function checkDate(input) {
    const val = input.value;
    let msg = '';
    if (val) {
      const day = new Date(val.slice(0, 10) + 'T12:00:00');
      const dow = ((day.getDay() + 6) % 7) + 1; // 1 = Monday … 7 = Sunday
      if (input.dataset.sfWeekdays && !input.dataset.sfWeekdays.split(',').map(Number).includes(dow)) msg = input.dataset.sfWeekdaysMsg || '';
      if (!msg && input.dataset.sfHours && val.length > 10) {
        const [from, to] = input.dataset.sfHours.split('-');
        const t = val.slice(11, 16);
        if (t < from || t > to) msg = input.dataset.sfHoursMsg || '';
      }
      if (!msg && input.dataset.sfAfter) {
        const other = document.getElementById(input.dataset.sfAfter) || document.getElementById(input.dataset.sfAfter + '-to');
        if (other && other.value) {
          const min = new Date(other.value.slice(0, 10) + 'T12:00:00');
          min.setDate(min.getDate() + Number(input.dataset.sfAfterDays || 0));
          if (day < min) {
            const iso = `${min.getFullYear()}-${String(min.getMonth() + 1).padStart(2, '0')}-${String(min.getDate()).padStart(2, '0')}`;
            msg = t('COM_SQUIDFORM_DATE_FROM', 'The earliest you can choose is %s.').replace('%s', iso);
          }
        }
      }
    }
    let note = input.parentElement.querySelector('.sf-date-note');
    if (!note) {
      note = document.createElement('div');
      note.className = 'sf-date-note invalid-feedback d-block';
      note.id = input.id + '-datenote';
      note.setAttribute('role', 'alert');
      input.insertAdjacentElement('afterend', note);
      input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + note.id).trim());
    }
    note.textContent = msg;
    input.classList.toggle('is-invalid', msg !== '');
    input.setCustomValidity(msg);
  }

  /* ---------------------------------------------------------- uploads (0.9.0) */

  // Says at once when a file is too big or there are too many; the server checks again.
  function checkFiles(input) {
    const max = Number(input.dataset.sfMaxBytes || 0);
    const most = Number(input.dataset.sfMaxFiles || 1);
    const kept = input.closest('.sf-field').querySelectorAll('input[name$="[keep][]"]:checked').length;
    const files = Array.from(input.files || []);
    const big = files.filter((f) => max && f.size > max).map((f) => f.name);
    let msg = '';
    if (big.length) msg = t('COM_SQUIDFORM_FILE_TOO_BIG', '%s is too large.').replace('%s', big.join(', '));
    else if (files.length + kept > most) msg = t('COM_SQUIDFORM_FILE_TOO_MANY', 'At most %d file(s).').replace('%d', String(most));
    let note = input.parentElement.querySelector('.sf-file-note');
    if (!note) {
      note = document.createElement('div');
      note.className = 'sf-file-note invalid-feedback d-block';
      note.id = input.id + '-note';
      note.setAttribute('role', 'alert');
      input.insertAdjacentElement('afterend', note);
      input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + note.id).trim());
    }
    note.textContent = msg;
    input.classList.toggle('is-invalid', msg !== '');
    input.setCustomValidity(msg);
  }

  /* ---------------------------------------------------------- ranking, slider, date range (0.9.2) */

  // Ranking: ↑ ↓ buttons move an item; the rank dropdowns follow the order.
  function initRanking(list) {
    const live = list.parentElement.querySelector('[data-sf-rank-live]');
    const items = () => Array.from(list.children);
    const label = (li) => li.querySelector('label').textContent;
    const renumber = () => items().forEach((li, i) => { const s = li.querySelector('select'); s.value = String(i + 1); s.dataset.prev = s.value; });
    const sortByRank = () => {
      const ranked = items().filter((li) => li.querySelector('select').value !== '').sort((a, b) => a.querySelector('select').value - b.querySelector('select').value);
      ranked.concat(items().filter((li) => li.querySelector('select').value === '')).forEach((li) => list.appendChild(li));
    };
    const buttons = () => items().forEach((li, i) => {
      li.querySelector('.sf-rank-up').disabled = i === 0;
      li.querySelector('.sf-rank-down').disabled = i === items().length - 1;
    });
    const move = (li, d) => {
      const all = items();
      const i = all.indexOf(li);
      const j = i + d;
      if (j < 0 || j >= all.length) return;
      if (d < 0) list.insertBefore(li, all[j]); else list.insertBefore(all[j], li);
      renumber(); buttons();
      li.querySelector(d < 0 ? '.sf-rank-up' : '.sf-rank-down').focus();
      if (live) live.textContent = t('COM_SQUIDFORM_RANK_MOVED', '%1$s is now number %2$d').replace('%1$s', label(li)).replace('%2$d', String(items().indexOf(li) + 1));
    };
    items().forEach((li) => {
      const name = label(li);
      const up = document.createElement('button');
      up.type = 'button'; up.className = 'btn btn-sm btn-outline-secondary sf-rank-up'; up.textContent = '↑';
      up.setAttribute('aria-label', t('COM_SQUIDFORM_RANK_UP', 'Move %s up').replace('%s', name));
      const down = document.createElement('button');
      down.type = 'button'; down.className = 'btn btn-sm btn-outline-secondary sf-rank-down'; down.textContent = '↓';
      down.setAttribute('aria-label', t('COM_SQUIDFORM_RANK_DOWN', 'Move %s down').replace('%s', name));
      up.addEventListener('click', () => move(li, -1));
      down.addEventListener('click', () => move(li, +1));
      li.prepend(up, down);
      // Picking a rank in a dropdown: an item already there takes this one's old rank.
      const sel = li.querySelector('select');
      sel.dataset.prev = sel.value;
      sel.addEventListener('change', () => {
        const other = items().find((x) => x !== li && x.querySelector('select').value === sel.value && sel.value !== '');
        if (other) other.querySelector('select').value = sel.dataset.prev;
        items().forEach((x) => { x.querySelector('select').dataset.prev = x.querySelector('select').value; });
        sortByRank(); buttons(); sel.focus();
      });
    });
    buttons();
  }

  function initSlider(input) {
    const out = document.getElementById(input.id + '-out');
    const unit = input.dataset.sfUnit || '';
    const show = () => {
      const text = input.value + (unit ? ' ' + unit : '');
      if (out) out.textContent = text;
      if (unit) input.setAttribute('aria-valuetext', text);
    };
    input.addEventListener('input', show);
    show();
  }

  function initRange(to) {
    const from = document.getElementById(to.dataset.sfRangeTo);
    if (!from) return;
    const sync = () => { to.min = from.value || from.min || ''; };
    from.addEventListener('change', sync);
    sync();
  }

  /* ---------------------------------------------- choice counter (0.14.0) */

  // "Choose at least 1 more" / "You can choose 2 more." / "You've reached the
  // limit of 3." under fields that limit how many can be picked. The server's
  // check still applies.
  function updateChoiceCount(box) {
    const field = box.closest('[data-sf-key]');
    if (!field) return;
    const sel = field.querySelector('select[multiple]');
    const n = sel ? [...sel.selectedOptions].filter((o) => o.value !== '').length : field.querySelectorAll('input[type=checkbox]:checked').length;
    const min = Number(box.dataset.sfMin) || 0;
    const max = Number(box.dataset.sfMax) || 0;
    const d = box.dataset;
    const fill = (t, ...v) => { let i = 0; return t.replace(/%(\d\$)?d/g, (m, pos) => String(pos ? v[Number(pos[0]) - 1] : v[i++])); };
    let text;
    if (n < min) text = fill(d.sfTLeast, min - n);
    else if (max && n > max) text = fill(d.sfTOver, n - max, max);
    else if (max && n === max) text = fill(d.sfTLimit, max);
    else if (max) text = fill(d.sfTCan, max - n);
    else text = d.sfTEnough;
    if (box.textContent !== text) box.textContent = text;
  }

  // {js:name} (0.14.0): the form's own JavaScript fills these with sf.set().
  // Until it does, they show nothing.
  function jsPlaceholders(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const hits = [];
    while (walker.nextNode()) if (/\{js:[a-z][a-z0-9_]{0,39}\}/.test(walker.currentNode.nodeValue)) hits.push(walker.currentNode);
    hits.forEach((t) => {
      const frag = document.createDocumentFragment();
      t.nodeValue.split(/(\{js:[a-z][a-z0-9_]{0,39}\})/).forEach((part) => {
        const m = part.match(/^\{js:([a-z][a-z0-9_]*)\}$/);
        if (m) { const s = document.createElement('span'); s.className = 'sf-js-value'; s.dataset.sfJs = m[1]; frag.append(s); } else if (part) frag.append(part);
      });
      t.replaceWith(frag);
    });
  }

  function init(form) {
    if (form.dataset.sfReady) return;
    form.dataset.sfReady = '1';
    jsPlaceholders(form.closest('.squidform') || form);

    const live = document.createElement('div');
    live.className = 'visually-hidden sf-form-live';
    live.setAttribute('aria-live', 'polite');
    form.appendChild(live);

    form.querySelectorAll('[data-sf-repeater]').forEach(renumber);
    form.querySelectorAll('[data-sf-signature]').forEach(initSignature);
    form.querySelectorAll('[data-sf-lookup]').forEach((b) => initLookup(form, b));
    form.querySelectorAll('select[data-sf-search]').forEach((s) => initSearchSelect(form, s));
    form.querySelectorAll('[data-sf-reveal]').forEach(initReveal);
    form.querySelectorAll('.sf-again[data-sf-account]').forEach(initAgain);
    form.querySelectorAll('.sf-editor[data-sf-htmlnote]').forEach(initHtmlNote);
    form.querySelectorAll('[data-sf-rank]').forEach(initRanking);
    form.querySelectorAll('.sf-slider input[type=range]').forEach(initSlider);
    form.querySelectorAll('[data-sf-range-to]').forEach(initRange);
    applyConditions(form);
    form.querySelectorAll('[data-sf-choice-count]').forEach(updateChoiceCount);

    form.addEventListener('change', (e) => {
      if (e.target.matches && e.target.matches('[data-sf-weekdays], [data-sf-hours], [data-sf-after]')) checkDate(e.target);
      if (e.target.id) form.querySelectorAll(`[data-sf-after="${CSS.escape(e.target.id.replace(/-(from|to)$/, ''))}"], [data-sf-after="${CSS.escape(e.target.id)}"]`).forEach((d) => { if (d.value) checkDate(d); });
      if (e.target.type === 'file') checkFiles(e.target);
      applyConditions(form); refreshChildren(form, e.target);
      const cc = e.target.closest && e.target.closest('[data-sf-key]')?.querySelector('[data-sf-choice-count]');
      if (cc) updateChoiceCount(cc);
    });
    form.addEventListener('input', (e) => {
      const el = e.target;
      if (el.dataset && el.dataset.sfMask && !(e.inputType || '').startsWith('delete')) applyMask(el);
      if (el.id) updateCounter(el, true);
      if (el.matches('input[type=text], input[type=number], input[type=tel], input[type=range], input[type=date], textarea')) applyConditions(form);
    });
    form.addEventListener('focusout', (e) => { if (e.target.dataset && e.target.dataset.sfMask) applyMask(e.target); });
    form.querySelectorAll('[data-sf-counter-for]').forEach((c) => { const el = document.getElementById(c.dataset.sfCounterFor); if (el) updateCounter(el, false); });

    form.addEventListener('click', (e) => {
      const add = e.target.closest('.sf-add');
      if (add) { e.preventDefault(); addRow(add.closest('[data-sf-repeater]'), form); return; }
      const rm = e.target.closest('.sf-remove');
      if (rm) { e.preventDefault(); removeRow(rm, form); }
    });

    // Prevent double submits but keep the pressed button's value.
    form.addEventListener('submit', (e) => {
      if (form.dataset.sfSubmitting) { e.preventDefault(); return; }
      form.dataset.sfSubmitting = '1';
      const btn = e.submitter;
      if (btn && btn.name) {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = btn.name; h.value = btn.value;
        form.appendChild(h);
      }
      // Re-enable hidden-by-condition controls? No: hidden values must not post.
      window.setTimeout(() => form.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; }), 0);
    });
  }

  // Language tabs (0.15.0): post the page first, so nothing typed is lost.
  // Without JavaScript the link still works; this page's answers start over.
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a.sf-lang-link[data-sf-lang]');
    if (!a || a.getAttribute('aria-current') === 'true') return;
    const root = a.closest('.squidform');
    const form = root && root.querySelector('form.sf-form');
    const lang = form && form.querySelector('input[name="sf_lang"]');
    if (!lang) return;
    e.preventDefault();
    lang.value = a.dataset.sfLang;
    const nav = document.createElement('input');
    nav.type = 'hidden'; nav.name = 'sf_nav'; nav.value = 'lang';
    form.appendChild(nav);
    if (form.requestSubmit) form.requestSubmit(); else form.submit();
  });

  // An error link to a field in a collapsed section opens the section first.
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('.sf-error-summary a[href^="#"]');
    if (!a) return;
    const target = document.getElementById(a.getAttribute('href').slice(1));
    const det = target && target.closest('details');
    if (det && !det.open) det.open = true;
    const ed = target && target.dataset.sfEditor && window.tinymce && window.tinymce.get(target.dataset.sfEditor);
    if (ed) {
      e.preventDefault();
      target.scrollIntoView({ block: 'center' });
      ed.focus();
    }
  });

  // Rich text (TinyMCE): name the editing frame after the field and fill in
  // what screen readers need that TinyMCE leaves out.
  function a11yEditor(ed) {
    const ta = ed.getElement && ed.getElement();
    const wrap = ta && ta.closest('.sf-editor');
    if (!wrap) return;
    const label = document.querySelector('label[for="' + ta.id + '"]');
    const name = label ? label.textContent.replace(/\s+/g, ' ').trim() : '';
    const fix = () => {
      const fr = ed.iframeElement;
      if (fr && name) fr.title = name;
      const body = ed.getBody && ed.getBody();
      if (body) {
        body.setAttribute('role', 'textbox');
        body.setAttribute('aria-multiline', 'true');
        if (name) body.setAttribute('aria-label', name);
      }
      const box = ed.getContainer && ed.getContainer();
      // Older TinyMCE: a mouse-only handle with a label but no role.
      wrap.querySelectorAll('.tox-statusbar__resize-handle:not([role])').forEach((h) => {
        if (h.hasAttribute('tabindex')) h.setAttribute('role', 'separator');
        else h.setAttribute('aria-hidden', 'true');
      });
      // Newer TinyMCE gives the resize handle a role but no value.
      wrap.querySelectorAll('.tox-statusbar__resize-handle[role]').forEach((h) => {
        h.setAttribute('aria-valuenow', String(box ? Math.round(box.offsetHeight) : 0));
        h.setAttribute('aria-valuemin', '100');
        h.setAttribute('aria-valuemax', '2000');
      });
      // Older TinyMCE puts aria-level on the element path's buttons.
      wrap.querySelectorAll('.tox-statusbar__path-item[aria-level]').forEach((b) => b.removeAttribute('aria-level'));
    };
    if (ed.initialized) fix(); else ed.on('init', fix);
    ed.on('ResizeEditor NodeChange', () => window.setTimeout(fix, 0));
  }

  function watchEditors() {
    const tm = window.tinymce;
    if (!tm || !document.querySelector('.squidform .sf-editor')) return;
    tm.get().forEach(a11yEditor);
    tm.on('AddEditor', (e) => a11yEditor(e.editor));
  }

  function focusResult() {
    const target = document.querySelector('.squidform .sf-error-summary, .squidform [data-sf-focus]');
    if (target) {
      target.focus();
      target.scrollIntoView({ block: 'center' });
    }
  }

  function boot() {
    document.querySelectorAll('form.sf-form').forEach(init);
    if (window.tinymce) watchEditors(); else window.addEventListener('load', watchEditors, { once: true });
    // The page loads with #sf-ALIAS so no-JS users land on the form. Browsers
    // reset focus when they jump to that anchor, so move focus after the jump.
    if (document.readyState === 'complete') {
      window.setTimeout(focusResult, 0);
    } else {
      window.addEventListener('load', () => window.setTimeout(focusResult, 0), { once: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
