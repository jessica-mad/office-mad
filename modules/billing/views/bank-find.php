<?php
/**
 * Search box for «Vincular»: the dropdown only suggests a few (the ones the concept
 * names first, then by amount); typing searches every open invoice, receipt and tax.
 */
use MAD_Oficina\Billing\Bank;

if ( ! defined('ABSPATH') ) exit;
?>
<script>
(function () {
  var items = <?php echo wp_json_encode(Bank::searchable()); ?>;
  var t = <?php echo wp_json_encode([
      'found' => __('%d encontradas: elige…', 'mad-oficina'),
      'none' => __('Nada con eso. Prueba con otra palabra o el importe.', 'mad-oficina'),
      'more' => __('…y %d más: escribe algo más concreto', 'mad-oficina'),
  ]); ?>;
  var MAX = 60;
  var fold = function (s) { return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
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
          if (!q) { target.innerHTML = original; return; }
          var found = search(input.dataset.side, q);
          target.innerHTML = '';
          target.appendChild(opt('', found.length ? t.found.replace('%d', found.length) : t.none));
          found.slice(0, MAX).forEach(function (i) { target.appendChild(opt(i.v, i.l)); });
          if (found.length > MAX) target.appendChild(opt('', t.more.replace('%d', found.length - MAX), true));
          if (found.length === 1) target.value = found[0].v;
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
