<?php
/**
 * Search box for «Vincular»: the dropdown only suggests a few (the ones the concept
 * names first, then by amount); typing searches every open invoice, receipt and tax.
 */
use MAD_Oficina\Billing\Bank;

if ( ! defined('ABSPATH') ) exit;
?>
<?php echo str_replace('<form ', '<form id="madof-bank-linkall" hidden ', \MAD_Oficina\Core\AdminUi::form_open('billing_bank_action', 'madof-bulk madof-bank-linkall')); ?>
    <input type="hidden" name="do" value="link_all">
    <span class="madof-bulk-count" aria-live="polite"></span>
    <button class="madof-btn is-small"><?php esc_html_e('Vincular todas las elegidas', 'mad-oficina'); ?></button>
    <small><?php esc_html_e('Elige en cada fila con qué va (o marca varias en «Con varias…») y vincúlalas de una vez.', 'mad-oficina'); ?></small>
</form>
<script>
(function () {
  // What she chose in each row: the ticked ones in «Con varias…», else the dropdown.
  var bar = document.getElementById('madof-bank-linkall');
  var label = <?php echo wp_json_encode(['one' => __('1 movimiento elegido', 'mad-oficina'), 'many' => __('%d movimientos elegidos', 'mad-oficina')]); ?>;
  var chosen = function () {
    var out = {};
    [].forEach.call(document.querySelectorAll('table.madof-bank tbody tr'), function (tr) {
      var mv = tr.querySelector('input[name="movement"]');
      if (!mv) return;
      var ticked = [].map.call(tr.querySelectorAll('.madof-bank-several input[name="targets[]"]:checked'), function (c) { return c.value; });
      var sel = tr.querySelector('select[name="target"]');
      var list = ticked.length ? ticked : (sel && sel.value ? [sel.value] : []);
      if (list.length) out[mv.value] = list;
    });
    return out;
  };
  var refresh = function () {
    var n = Object.keys(chosen()).length;
    bar.hidden = n < 2;
    bar.querySelector('.madof-bulk-count').textContent = n === 1 ? label.one : label.many.replace('%d', n);
  };
  document.addEventListener('change', refresh);
  bar.addEventListener('submit', function () {
    [].forEach.call(bar.querySelectorAll('input[name^="pairs"]'), function (i) { i.remove(); });
    var c = chosen();
    Object.keys(c).forEach(function (mid) {
      c[mid].forEach(function (v) {
        var i = document.createElement('input'); i.type = 'hidden'; i.name = 'pairs[' + mid + '][]'; i.value = v; bar.appendChild(i);
      });
    });
  });
  refresh();
})();
(function () {
  var items = <?php echo wp_json_encode(Bank::searchable()); ?>;
  var t = <?php echo wp_json_encode([
      'found' => __('%d encontradas: elige…', 'mad-oficina'),
      'none' => __('Nada con eso. Prueba con otra palabra o el importe.', 'mad-oficina'),
      'more' => __('…y %d más: escribe algo más concreto', 'mad-oficina'),
  ]); ?>;
  var MAX = 60;
  var fold = function (s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
  var search = function (side, q) {
    var words = fold(q).split(/\s+/).filter(Boolean);
    var pool = side ? items[side] : items['in'].concat(items['out']);
    return pool.filter(function (i) { return words.every(function (w) { return i.s.indexOf(w) !== -1; }); });
  };
  var opt = function (v, l, disabled) { var o = document.createElement('option'); o.value = v; o.textContent = l; o.disabled = !!disabled; return o; };

  [].forEach.call(document.querySelectorAll('.madof-bank-find'), function (input) {
    input.hidden = false;
    var target = input.nextElementSibling;
    var original = target.innerHTML;
    var timer;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = input.value.trim();
        if (target.tagName === 'SELECT') {
          if (!q) { target.innerHTML = original; target.dispatchEvent(new Event('change', { bubbles: true })); return; }
          var found = search(input.dataset.side, q);
          target.innerHTML = '';
          target.appendChild(opt('', found.length ? t.found.replace('%d', found.length) : t.none));
          found.slice(0, MAX).forEach(function (i) { target.appendChild(opt(i.v, i.l)); });
          if (found.length > MAX) target.appendChild(opt('', t.more.replace('%d', found.length - MAX), true));
          if (found.length === 1) target.value = found[0].v;
          target.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
          // Checkbox list: the ones already ticked stay, the rest are the results.
          var kept = [].filter.call(target.querySelectorAll('input:checked'), Boolean).map(function (c) { return c.closest('label'); });
          var keep = kept.map(function (l) { return l.querySelector('input').value; });
          target.innerHTML = original;
          [].forEach.call(target.querySelectorAll('label'), function (l) { if (keep.indexOf(l.querySelector('input').value) !== -1) l.remove(); });
          if (q) {
            target.innerHTML = '';
            search(input.dataset.side, q).slice(0, MAX).forEach(function (i) {
              if (keep.indexOf(i.v) !== -1) return;
              var l = document.createElement('label'); l.className = 'madof-check';
              var c = document.createElement('input'); c.type = 'checkbox'; c.name = 'targets[]'; c.value = i.v;
              l.appendChild(c); l.appendChild(document.createTextNode(' ' + i.l)); target.appendChild(l);
            });
          }
          kept.reverse().forEach(function (l) { target.insertBefore(l, target.firstChild); });
        }
      }, 120);
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); target.focus(); } });
  });
})();
</script>
