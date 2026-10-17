<?php
/**
 * «Revisar»: one invoice at a time in a window over Por revisar, the file next to its
 * question. Deciding removes the row and opens the next one, without reloading.
 */
if ( ! defined('ABSPATH') ) exit;
?>
<dialog id="madof-review" class="madof-review" aria-labelledby="madof-review-title">
    <form method="dialog" class="madof-review-form">
        <header class="madof-review-head">
            <span class="madof-kicker" id="madof-review-pos"></span>
            <h2 id="madof-review-title"></h2>
            <button type="button" class="madof-btn is-small is-ghost madof-review-close" value="close"><?php esc_html_e('Cerrar', 'mad-oficina'); ?></button>
        </header>
        <div class="madof-review-body">
            <div class="madof-review-file"></div>
            <div class="madof-review-data"></div>
        </div>
        <footer class="madof-review-foot">
            <button type="button" class="madof-btn" data-do="approve"></button>
            <button type="button" class="madof-btn is-ghost" data-do="aside"><?php esc_html_e('No es mía: guardar aparte', 'mad-oficina'); ?></button>
            <button type="button" class="madof-btn is-ghost" data-do="discard"><?php esc_html_e('No es factura: descartar', 'mad-oficina'); ?></button>
            <button type="button" class="madof-btn is-ghost" data-do="read"><?php esc_html_e('Volver a leer', 'mad-oficina'); ?></button>
            <a class="madof-review-edit" href="#"><?php esc_html_e('Abrir la ficha', 'mad-oficina'); ?></a>
            <button type="button" class="madof-btn is-small is-ghost madof-review-skip"><?php esc_html_e('Saltar', 'mad-oficina'); ?></button>
        </footer>
        <p class="madof-review-msg" role="status" aria-live="polite"></p>
    </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('madof-review');
  if (!dlg || !dlg.showModal) return;
  var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  var nonce = <?php echo wp_json_encode(wp_create_nonce('madof_expense_panel')); ?>;
  var t = <?php echo wp_json_encode([
      'loading' => __('Cargando…', 'mad-oficina'),
      'saving' => __('Guardando…', 'mad-oficina'),
      'of' => __('%1$d de %2$d', 'mad-oficina'),
      'done' => __('No queda ninguna por revisar.', 'mad-oficina'),
      'error' => __('No he podido hacerlo. Recarga la página y vuelve a intentarlo.', 'mad-oficina'),
  ]); ?>;
  var current = 0, busy = false;
  var $ = function (s) { return dlg.querySelector(s); };
  var rows = function () { return [].slice.call(document.querySelectorAll('tr[data-expense]')); };
  var rowOf = function (id) { return document.querySelector('tr[data-expense="' + id + '"]'); };
  var say = function (m) { $('.madof-review-msg').textContent = m || ''; };

  var post = function (data) {
    data.append('_ajax_nonce', nonce);
    return fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { success: false, data: { message: t.error } }; }); });
  };

  var open = function (id) {
    current = id;
    var list = rows().map(function (r) { return +r.dataset.expense; });
    $('#madof-review-pos').textContent = t.of.replace('%1$d', list.indexOf(id) + 1).replace('%2$d', list.length);
    $('#madof-review-title').textContent = t.loading;
    $('.madof-review-file').innerHTML = '';
    $('.madof-review-data').innerHTML = '';
    [].forEach.call(dlg.querySelectorAll('[data-do]'), function (b) { b.disabled = true; });
    if (!dlg.open) dlg.showModal();
    var fd = new FormData();
    fd.append('action', 'madof_expense_panel');
    fd.append('expense_id', id);
    post(fd).then(function (r) {
      if (current !== id) return;
      if (!r.success) { say(r.data && r.data.message || t.error); return; }
      var d = r.data;
      $('#madof-review-title').textContent = d.title;
      $('.madof-review-data').innerHTML = d.html;
      var file = $('.madof-review-data .madof-invoice-view');
      if (file) { file.open = true; $('.madof-review-file').appendChild(file); }
      $('.madof-review-edit').href = d.edit_url;
      var approve = $('[data-do="approve"]');
      approve.textContent = d.approve_label;
      approve.hidden = !d.can_approve;
      [].forEach.call(dlg.querySelectorAll('[data-do]'), function (b) { b.disabled = false; });
      (d.can_approve ? approve : $('[data-do="aside"]')).focus();
    });
  };

  var next = function (from) {
    var list = rows();
    if (!list.length) return null;
    var i = list.indexOf(from);
    return +(list[i + 1] || list[0]).dataset.expense;
  };

  var updateCounts = function () {
    var n = rows().length;
    var tab = document.querySelector('.madof-tabs a.is-active');
    if (tab) tab.textContent = tab.textContent.replace(/\(\d+\)/, '(' + n + ')');
    if (!n) {
      var table = document.querySelector('.madof-wrap .madof-table');
      if (table) table.outerHTML = '<p class="madof-empty">' + t.done + '</p>';
    }
    document.dispatchEvent(new Event('change')); // the bulk bar recounts
  };

  dlg.addEventListener('click', function (e) {
    var b = e.target.closest('[data-do]');
    if (b && !busy) {
      busy = true;
      say(t.saving);
      var fd = new FormData(dlg.querySelector('form'));
      fd.append('action', 'madof_expense_decide');
      fd.append('expense_id', current);
      fd.append('do', b.dataset.do);
      post(fd).then(function (r) {
        busy = false;
        if (!r.success) { say(r.data && r.data.message || t.error); return; }
        if (r.data.again) { var same = current; open(same); say(r.data.message); return; }
        var row = rowOf(current), to = next(row);
        if (to === current) to = null;
        if (row) row.remove();
        updateCounts();
        say(r.data.message + (to ? '' : ' ' + t.done));
        if (to) open(to); else { $('.madof-review-body').innerHTML = '<div class="madof-review-file"></div><div class="madof-review-data"></div>'; [].forEach.call(dlg.querySelectorAll('[data-do]'), function (x) { x.disabled = true; }); }
      }, function () { busy = false; say(t.error); });
      return;
    }
    if (e.target.closest('.madof-review-skip')) { var to = next(rowOf(current)); if (to && to !== current) open(to); return; }
    if (e.target.closest('.madof-review-close') || e.target === dlg) dlg.close();
  });
  dlg.addEventListener('close', function () { current = 0; say(''); });

  document.addEventListener('click', function (e) {
    var b = e.target.closest('.madof-review-open');
    if (b) { e.preventDefault(); say(''); open(+b.dataset.expense); }
  });
})();
</script>
