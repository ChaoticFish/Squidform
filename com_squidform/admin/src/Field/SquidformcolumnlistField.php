<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\ViewerConfig;

\defined('_JEXEC') or die;

/**
 * Ordered list of viewer columns, each with an optional heading:
 * [{"name": "f_name", "heading": "Name"}, ...], stored as JSON.
 * Rows can be moved up and down, removed, added one at a time, or all at
 * once. Empty = use the form definition's "viewer" setting or the defaults.
 */
class SquidformcolumnlistField extends FormField
{
    use ViewerFormTrait;

    protected $type = 'Squidformcolumnlist';

    protected function getInput()
    {
        $choices = $this->viewerChoices('columns');

        if (!$choices) {
            return '<p class="form-text">' . Text::_('COM_SQUIDFORM_MENU_SAVE_FIRST') . '</p>'
                . '<input type="hidden" name="' . $this->name . '" value="' . htmlspecialchars((string) (\is_array($this->value) ? json_encode($this->value) : $this->value), ENT_QUOTES, 'UTF-8') . '">';
        }

        $rows = ViewerConfig::columnList($this->value) ?? [];
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $e    = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $id   = $this->id;

        $opts = '';

        foreach ($choices as $v => $label) {
            $opts .= '<option value="' . $e($v) . '">' . $e($label) . '</option>';
        }

        $t = [
            'col'     => Text::_('COM_SQUIDFORM_COLLIST_COLUMN'),
            'heading' => Text::_('COM_SQUIDFORM_COLLIST_HEADING'),
            'up'      => Text::_('COM_SQUIDFORM_COLLIST_UP'),
            'down'    => Text::_('COM_SQUIDFORM_COLLIST_DOWN'),
            'remove'  => Text::_('COM_SQUIDFORM_COLLIST_REMOVE'),
            'add'     => Text::_('COM_SQUIDFORM_COLLIST_ADD'),
            'all'     => Text::_('COM_SQUIDFORM_COLLIST_ADD_ALL'),
            'none'    => Text::_('COM_SQUIDFORM_COLLIST_REMOVE_ALL'),
            'empty'   => Text::_('COM_SQUIDFORM_COLLIST_EMPTY'),
            'hint'    => Text::_('COM_SQUIDFORM_COLLIST_HEADING_HINT'),
        ];

        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
(() => {
  const init = (box) => {
    if (box.dataset.ready) return;
    box.dataset.ready = '1';
    const hidden = document.getElementById(box.dataset.for);
    const tbody = box.querySelector('tbody');
    const tpl = box.querySelector('template');
    const empty = box.querySelector('.sf-collist-empty');
    const sync = () => {
      const rows = [...tbody.querySelectorAll('tr')].map((tr) => ({ name: tr.querySelector('select').value, heading: tr.querySelector('input').value.trim() }));
      hidden.value = rows.length ? JSON.stringify(rows) : '';
      empty.hidden = rows.length > 0;
      tbody.querySelectorAll('tr').forEach((tr, i) => {
        tr.querySelector('.sf-n').textContent = String(i + 1);
        tr.querySelector('[data-act=up]').disabled = i === 0;
        tr.querySelector('[data-act=down]').disabled = i === rows.length - 1;
      });
    };
    const add = (name, heading) => {
      const tr = tpl.content.firstElementChild.cloneNode(true);
      const n = tbody.children.length + 1;
      const sel = tr.querySelector('select');
      const inp = tr.querySelector('input');
      sel.id = box.dataset.for + '-c' + n + '-' + Date.now();
      inp.id = sel.id + '-h';
      tr.querySelector('label.sf-lc').htmlFor = sel.id;
      tr.querySelector('label.sf-lh').htmlFor = inp.id;
      if (name) sel.value = name;
      inp.value = heading || '';
      tbody.appendChild(tr);
      return tr;
    };
    JSON.parse(hidden.value || '[]').forEach((r) => add(r.name, r.heading));
    sync();
    box.addEventListener('change', sync);
    box.addEventListener('input', sync);
    box.addEventListener('click', (e) => {
      const b = e.target.closest('button[data-act]');
      if (!b) return;
      e.preventDefault();
      const tr = b.closest('tr');
      switch (b.dataset.act) {
        case 'up': if (tr.previousElementSibling) tr.parentNode.insertBefore(tr, tr.previousElementSibling); break;
        case 'down': if (tr.nextElementSibling) tr.parentNode.insertBefore(tr.nextElementSibling, tr); break;
        case 'remove': tr.remove(); break;
        case 'add': add('', '').querySelector('select').focus(); break;
        case 'all': {
          const have = new Set([...tbody.querySelectorAll('select')].map((s) => s.value));
          [...tpl.content.querySelectorAll('option')].forEach((o) => { if (!have.has(o.value)) add(o.value, ''); });
          break;
        }
        case 'none': tbody.innerHTML = ''; break;
      }
      sync();
      if (b.dataset.act === 'up' || b.dataset.act === 'down') b.focus();
    });
  };
  const boot = () => document.querySelectorAll('.sf-collist').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS, ['name' => 'com_squidform.collist']);

        return '<input type="hidden" name="' . $this->name . '" id="' . $id . '" value="' . $e($json === '[]' ? '' : $json) . '">'
            . '<div class="sf-collist" data-for="' . $id . '">'
            . '<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th scope="col">#</th><th scope="col">' . $t['col'] . '</th><th scope="col">' . $t['heading'] . '</th><th scope="col"><span class="visually-hidden">' . $t['up'] . '</span></th></tr></thead><tbody></tbody></table></div>'
            . '<p class="form-text sf-collist-empty">' . $t['empty'] . '</p>'
            . '<template><tr><td class="sf-n"></td>'
            . '<td><label class="visually-hidden sf-lc">' . $t['col'] . '</label><select class="form-select form-select-sm" style="min-width:10rem">' . $opts . '</select></td>'
            . '<td><label class="visually-hidden sf-lh">' . $t['heading'] . '</label><input type="text" class="form-control form-control-sm" maxlength="100" placeholder="' . $e($t['hint']) . '"></td>'
            . '<td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-secondary" data-act="up" aria-label="' . $e($t['up']) . '">↑</button> '
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="down" aria-label="' . $e($t['down']) . '">↓</button> '
            . '<button type="button" class="btn btn-sm btn-danger" data-act="remove" aria-label="' . $e($t['remove']) . '">×</button></td></tr></template>'
            . '<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-secondary" data-act="add">' . $t['add'] . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="all">' . $t['all'] . '</button>'
            . '<button type="button" class="btn btn-sm btn-secondary" data-act="none">' . $t['none'] . '</button></div>'
            . '</div>';
    }
}
