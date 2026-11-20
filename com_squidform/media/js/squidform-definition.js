/**
 * SquidForm admin: the definition box (0.6.2).
 * Shows the cursor's line and column, keeps long lines unwrapped so line
 * numbers match what you see, and "Go to the problem" selects the line a
 * refused save pointed at.
 */
(() => {
  const init = () => {
    const ta = document.getElementById('jform_schema_json');
    const pos = document.getElementById('sf-def-pos');
    if (!ta || !pos) return;
    ta.setAttribute('wrap', 'off');
    ta.classList.add('sf-def-nowrap');
    const label = pos.dataset.label || 'Line %1$d, column %2$d';
    const show = () => {
      const before = ta.value.slice(0, ta.selectionStart);
      const line = before.split('\n').length;
      const col = before.length - before.lastIndexOf('\n');
      pos.textContent = label.replace('%1$d', line).replace('%2$d', col);
    };
    ['keyup', 'click', 'input', 'focus', 'select'].forEach((e) => ta.addEventListener(e, show));
    show();
    const goTo = (line, _col, focus) => {
      const lines = ta.value.split('\n');
      const l = Math.min(Math.max(1, line), lines.length);
      let start = 0;
      for (let k = 0; k < l - 1; k++) start += lines[k].length + 1;
      const end = start + lines[l - 1].length;
      const lh = parseFloat(getComputedStyle(ta).lineHeight) || 20;
      ta.scrollTop = Math.max(0, (l - 1) * lh - ta.clientHeight / 3);
      ta.scrollLeft = 0;
      if (focus) {
        ta.focus({ preventScroll: true });
        // Select the whole line (without its indent), so it shows even when the column is at its end.
        const indent = lines[l - 1].length - lines[l - 1].trimStart().length;
        ta.setSelectionRange(start + indent, end);
        ta.scrollTop = Math.max(0, (l - 1) * lh - ta.clientHeight / 3);
        show();
      }
    };
    const btn = document.getElementById('sf-def-goto');
    if (btn) {
      const line = parseInt(btn.dataset.line, 10) || 1;
      const col = parseInt(btn.dataset.column, 10) || 1;
      goTo(line, col, false); // bring it into view, without moving focus away from the messages
      btn.addEventListener('click', () => goTo(line, col, true));
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
