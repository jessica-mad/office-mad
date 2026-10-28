<?php
/**
 * Draft editor: who, dates, as many concept lines as needed, live totals.
 * @var array|null $draft @var array|null $profile @var \MAD_Oficina\Billing\Issuing $issuing
 */
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Approvals;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-facturacion');
$num = fn($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
$pending = $draft ? array_filter(Approvals::for_object('invoice', $draft['id']), fn($a) => $a['status'] === Approvals::PENDING) : [];
?>
<div class="wrap madof-wrap">
    <p><a href="<?php echo esc_url($base); ?>">← <?php esc_html_e('Facturas', 'mad-oficina'); ?></a></p>
    <?php AdminUi::header(__('Facturación', 'mad-oficina'), $draft ? ($draft['series'] === 'R' ? __('Rectificativa (borrador)', 'mad-oficina') : __('Borrador de factura', 'mad-oficina')) : __('Nueva factura', 'mad-oficina')); ?>
    <?php if ( $draft && ($rep = \MAD_Oficina\Billing\Issuing::replacement_of($draft['id'])) ) { $here = $draft['id']; include __DIR__ . '/replacement.php'; } ?>

<?php if ( ! $profile ) : ?>
    <form method="get" class="madof-form madof-card">
        <input type="hidden" name="page" value="mad-oficina-facturacion">
        <input type="hidden" name="edit" value="new">
        <label><?php esc_html_e('¿A quién va la factura?', 'mad-oficina'); ?>
            <select name="profile" required>
                <option value=""><?php esc_html_e('— Elige la razón social —', 'mad-oficina'); ?></option>
                <?php foreach ( Profiles::all('active') as $p ) echo '<option value="' . (int) $p['id'] . '">' . esc_html($p['legal_name'] . ($p['tax_id'] ? ' · ' . $p['tax_id'] : '')) . '</option>'; ?>
            </select></label>
        <button class="madof-btn"><?php esc_html_e('Continuar', 'mad-oficina'); ?></button>
    </form>
<?php else :
    $is_ar = $profile['series'] === 'AR';
    $lines = $draft['lines'] ?? [];
    $copied = false;
    if ( ! $draft ) {
        // Monthly fees repeat: start from the last invoice of this client.
        $last = Invoices::all(['profile_id' => $profile['id'], 'series' => $is_ar ? 'AR' : 'F'], 1)[0] ?? null;
        // (lines that re-billed an expense belong to that invoice only)
        if ( $last ) { $lines = array_values(array_filter(Invoices::get($last['id'])['lines'], fn($l) => empty($l['expense_id']))); $copied = $lines ? $last : false; }
        // Re-billable expenses of this client still waiting for an invoice.
        $extras = (array) apply_filters('madoficina_billing_extra_lines', [], $profile, Months::current());
        $lines = array_merge($lines, $extras);
    }
    if ( ! $lines ) $lines = [['description' => '', 'quantity' => 1, 'unit_price' => '']];
    $issue = $draft['issue_date'] ?? $issuing->default_issue_date($profile);
    $due = $draft['due_date'] ?? $issuing->due_date($issue, $profile);
?>
    <?php if ( $pending ) : ?><p class="madof-warn"><?php esc_html_e('Este borrador ya está en la bandeja esperando tu aprobación. Si lo cambias ahora, se emitirá con los cambios.', 'mad-oficina'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-bandeja')); ?>"><?php esc_html_e('Ir a la bandeja', 'mad-oficina'); ?></a></p><?php endif; ?>
    <?php if ( $draft && $draft['notes'] && $draft['source'] === 'import' ) : ?><p class="madof-warn"><?php echo esc_html($draft['notes']); ?></p><?php endif; ?>

    <?php echo AdminUi::form_open('billing_draft_save', 'madof-form madof-invoice-form'); ?>
        <input type="hidden" name="invoice_id" value="<?php echo (int) ($draft['id'] ?? 0); ?>">
        <?php
        // A client with two current legal names: she picks which one this draft goes to.
        $choices = $profile['client_id'] ? array_values(array_filter(Profiles::for_client((int) $profile['client_id']), fn($c) => $c['status'] === 'active')) : [];
        if ( ! $draft || ! empty($draft['rectifies_id']) || count($choices) < 2 ) : ?>
        <input type="hidden" name="profile_id" value="<?php echo (int) $profile['id']; ?>">
        <?php endif; ?>
        <?php if ( ! empty($draft['series']) && $draft['series'] === 'R' ) : ?><input type="hidden" name="series" value="R"><?php endif; ?>
        <?php if ( ! $is_ar && ($will = $draft ? Invoices::preview_number($draft) : Invoices::preview_number(['number' => null, 'series' => 'F', 'issue_date' => $issue])) ) : ?>
            <p class="madof-next-number"><span class="madof-kicker"><?php esc_html_e('Número', 'mad-oficina'); ?></span> <?php echo esc_html(sprintf(__('Si la emites ahora será la %1$s %2$s.', 'mad-oficina'), ($draft['series'] ?? '') === 'R' ? __('rectificativa', 'mad-oficina') : __('factura', 'mad-oficina'), $will)); ?></p>
        <?php endif; ?>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Para', 'mad-oficina'); ?></span>
            <?php if ( $draft && ! empty($draft['rectifies_id']) ) : // a rectifying invoice goes to the details the original was issued with ?>
            <p><strong><?php echo esc_html($draft['client_name']); ?></strong> · <?php echo esc_html(implode(' · ', array_filter([$draft['client_tax_id'], $draft['client_address'], $draft['client_city'], $draft['client_email']]))); ?>
                <br><small class="madof-muted"><?php esc_html_e('Los datos con los que se emitió la factura que rectifica.', 'mad-oficina'); ?></small></p>
            <?php else : ?>
            <?php if ( $draft && empty($draft['rectifies_id']) && count($choices) >= 2 ) : ?>
            <label><?php esc_html_e('Razón social', 'mad-oficina'); ?>
                <select name="profile_id"><?php foreach ( $choices as $c ) echo '<option value="' . (int) $c['id'] . '"' . selected((int) $profile['id'], (int) $c['id'], false) . '>' . esc_html($c['legal_name'] . ($c['tax_id'] ? ' · ' . $c['tax_id'] : '')) . '</option>'; ?></select></label>
            <?php endif; ?>
            <p><strong><?php echo esc_html($profile['legal_name']); ?></strong> · <?php echo esc_html(implode(' · ', array_filter([$profile['tax_id'], $profile['address'], $profile['city'], $profile['email']]))); ?>
                · <a href="<?php echo esc_url($base . '&tab=clients&profile=' . $profile['id']); ?>"><?php esc_html_e('Cambiar sus datos', 'mad-oficina'); ?></a></p>
            <?php endif; ?>
            <?php if ( ! $profile['tax_id'] && ! $is_ar ) : ?><p class="madof-warn"><?php esc_html_e('Le falta el NIF: complétalo en sus datos fiscales antes de emitir.', 'mad-oficina'); ?></p><?php endif; ?>
            <?php if ( ! $profile['email'] && ! $is_ar ) : ?><p class="madof-warn"><?php esc_html_e('No tiene email para facturas: podrás emitirla, pero no enviarla desde aquí.', 'mad-oficina'); ?></p><?php endif; ?>
            <div class="madof-cols">
                <label><?php esc_html_e('Fecha', 'mad-oficina'); ?><input type="date" name="issue_date" value="<?php echo esc_attr($issue); ?>">
                    <small><?php echo esc_html(sprintf(__('Su día de facturación: %d.', 'mad-oficina'), $issuing->billing_day($profile))); ?></small></label>
                <label><?php esc_html_e('Vencimiento', 'mad-oficina'); ?><input type="date" name="due_date" value="<?php echo esc_attr($due); ?>">
                    <small><?php echo esc_html(sprintf(__('%d días desde la fecha.', 'mad-oficina'), $issuing->payment_days($profile))); ?></small></label>
                <label><?php esc_html_e('IVA %', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="vat_rate" value="<?php echo esc_attr($num($draft['vat_rate'] ?? $profile['vat_rate'])); ?>" data-rate="vat"></label>
                <label><?php esc_html_e('IRPF %', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="irpf_rate" value="<?php echo esc_attr($num($draft['irpf_rate'] ?? $profile['irpf_rate'])); ?>" data-rate="irpf"></label>
            </div>
        </section>

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Conceptos', 'mad-oficina'); ?></span>
            <?php if ( $copied ) : ?><p class="description"><?php echo esc_html(sprintf(__('Copiados de su última factura (%1$s, %2$s). Cámbialos si este mes es distinto.', 'mad-oficina'), $copied['number'] ?: __('borrador', 'mad-oficina'), date_i18n('j M Y', strtotime($copied['issue_date'])))); ?></p><?php endif; ?>
            <table class="madof-table madof-lines">
                <thead><tr><th><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><th><?php esc_html_e('Cantidad', 'mad-oficina'); ?></th><th><?php esc_html_e('Precio', 'mad-oficina'); ?></th><th><?php esc_html_e('Importe', 'mad-oficina'); ?></th><th><span class="screen-reader-text"><?php esc_html_e('Quitar', 'mad-oficina'); ?></span></th></tr></thead>
                <tbody>
                <?php foreach ( array_values($lines) as $i => $l ) : ?>
                    <tr>
                        <td><textarea name="lines[<?php echo (int) $i; ?>][description]" rows="2" aria-label="<?php esc_attr_e('Concepto', 'mad-oficina'); ?>"><?php echo esc_textarea((string) $l['description']); ?></textarea>
                            <?php if ( ! empty($l['expense_id']) ) : ?><input type="hidden" name="lines[<?php echo (int) $i; ?>][expense_id]" value="<?php echo (int) $l['expense_id']; ?>"><small class="madof-muted"><?php esc_html_e('Gasto repercutido', 'mad-oficina'); ?></small><?php endif; ?>
                            <?php if ( $draft && ! empty($l['item_id']) ) : ?><input type="hidden" name="lines[<?php echo (int) $i; ?>][item_id]" value="<?php echo (int) $l['item_id']; ?>"><input type="hidden" name="lines[<?php echo (int) $i; ?>][item_month]" value="<?php echo esc_attr($l['item_month']); ?>"><small class="madof-muted"><?php echo esc_html(sprintf(__('Del acuerdo · %s', 'mad-oficina'), Months::label($l['item_month']))); ?></small><?php endif; ?></td>
                        <td><input type="text" inputmode="decimal" name="lines[<?php echo (int) $i; ?>][quantity]" value="<?php echo esc_attr($num($l['quantity'] ?? 1)); ?>" class="madof-qty" aria-label="<?php esc_attr_e('Cantidad', 'mad-oficina'); ?>"></td>
                        <td><input type="text" inputmode="decimal" name="lines[<?php echo (int) $i; ?>][unit_price]" value="<?php echo esc_attr($l['unit_price'] === '' ? '' : number_format((float) $l['unit_price'], 2, ',', '')); ?>" class="madof-price" aria-label="<?php esc_attr_e('Precio', 'mad-oficina'); ?>"></td>
                        <td class="madof-amount">—</td>
                        <td><button type="button" class="madof-link madof-remove-line"><?php esc_html_e('Quitar', 'mad-oficina'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="madof-btn is-ghost is-small madof-add-line"><?php esc_html_e('Añadir concepto', 'mad-oficina'); ?></button></p>
            <table class="madof-totals" aria-live="polite">
                <tr><th><?php esc_html_e('Base', 'mad-oficina'); ?></th><td data-total="base">—</td></tr>
                <tr><th><?php esc_html_e('IVA', 'mad-oficina'); ?></th><td data-total="vat">—</td></tr>
                <tr><th><?php esc_html_e('IRPF', 'mad-oficina'); ?></th><td data-total="irpf">—</td></tr>
                <tr class="is-total"><th><?php esc_html_e('Total', 'mad-oficina'); ?></th><td data-total="total">—</td></tr>
            </table>
            <?php if ( ($draft['series'] ?? '') === 'R' ) : ?>
            <label><?php esc_html_e('Motivo de la rectificación (va impreso en la factura)', 'mad-oficina'); ?><input type="text" name="rectify_reason" maxlength="500" value="<?php echo esc_attr((string) ($draft['rectify_reason'] ?? '')); ?>"></label>
            <?php endif; ?>
            <label><?php esc_html_e('Notas internas (no salen en la factura)', 'mad-oficina'); ?><textarea name="notes" rows="2"><?php echo esc_textarea((string) ($draft['notes'] ?? '')); ?></textarea></label>
        </section>

        <div class="madof-actions">
            <button class="madof-btn is-ghost" name="do" value="save"><?php esc_html_e('Guardar borrador', 'mad-oficina'); ?></button>
            <?php if ( $is_ar ) : ?>
                <span class="description"><?php esc_html_e('Serie Argentina: la factura se emite en la plataforma argentina. Guárdala y, desde su ficha, márcala como emitida allí.', 'mad-oficina'); ?></span>
            <?php else : ?>
                <button class="madof-btn is-ghost" name="do" value="test"><?php esc_html_e('Enviarme una prueba', 'mad-oficina'); ?></button>
                <button class="madof-btn" name="do" value="issue"><?php esc_html_e('Enviar ahora', 'mad-oficina'); ?></button>
            <?php endif; ?>
            <?php if ( $draft ) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=madof_billing_pdf&invoice=' . $draft['id']), 'madof_billing_pdf_' . $draft['id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ver PDF (último guardado)', 'mad-oficina'); ?></a><?php endif; ?>
        </div>
        <p class="description"><?php esc_html_e('«Enviar ahora» guarda el borrador y te enseña cómo saldrá, con su número y el email. Desde ahí la emites y sale desde tu Gmail.', 'mad-oficina'); ?></p>
        <?php if ( ! $is_ar ) : $when = $draft ? \MAD_Oficina\Billing\Calendar::scheduled_for($draft) : null; ?>
        <section class="madof-card madof-schedule">
            <span class="madof-kicker"><?php esc_html_e('O programar el envío', 'mad-oficina'); ?></span>
            <?php $alone = $draft && ! empty($draft['auto_send']); ?>
            <?php if ( $when ) : ?><p><strong><?php echo esc_html(sprintf($alone ? __('Programada: el %s se envía sola y te aviso.', 'mad-oficina') : __('Programada: el %s te pregunto si la envío.', 'mad-oficina'), date_i18n('j \d\e F', strtotime($when)))); ?></strong></p><?php endif; ?>
            <div class="madof-cols">
                <label><?php esc_html_e('Día de envío (será también la fecha de la factura)', 'mad-oficina'); ?><input type="date" name="send_on" min="<?php echo esc_attr(current_time('Y-m-d')); ?>" value="<?php echo esc_attr(max($issue, current_time('Y-m-d'))); ?>"></label>
            </div>
            <label class="madof-check"><input type="checkbox" name="send_alone" value="1" <?php checked($alone); ?>> <?php esc_html_e('Que se envíe sola ese día, sin preguntarme', 'mad-oficina'); ?></label>
            <p class="description"><?php esc_html_e('Sin marcar: ese día, por la mañana, te llega a la bandeja y a Chat (Enviar · Editar · Posponer 7 días · Este mes no). Marcada: ese día se le da el número, se guarda en Drive y sale desde tu Gmail con el email de siempre al email del cliente; te aviso por Chat cuando sale. Si algo falla (sin email, fecha anterior a la última factura), no sale y te la pregunto como siempre. Hasta ese día puedes editarla, cambiar el día o borrarla.', 'mad-oficina'); ?></p>
            <button class="madof-btn is-ghost" name="do" value="schedule"><?php echo $when ? esc_html__('Cambiar el día', 'mad-oficina') : esc_html__('Programar envío', 'mad-oficina'); ?></button>
        </section>
        <?php endif; ?>
    </form>

    <?php if ( $draft && array_filter(array_column($draft['lines'], 'item_id')) ) : ?>
    <?php echo AdminUi::form_open('billing_skip', 'madof-inline'); ?>
        <input type="hidden" name="invoice_id" value="<?php echo (int) $draft['id']; ?>">
        <button class="madof-link" onclick="return confirm('<?php echo esc_js(__('¿No facturar estos acuerdos este mes? Queda apuntado y no te lo vuelvo a preparar.', 'mad-oficina')); ?>')"><?php esc_html_e('Este mes no se factura', 'mad-oficina'); ?></button>
    </form> ·
    <?php endif; ?>
    <?php if ( $draft ) : ?>
    <?php echo AdminUi::form_open('billing_draft_delete', 'madof-inline'); ?>
        <input type="hidden" name="invoice_id" value="<?php echo (int) $draft['id']; ?>">
        <button class="madof-btn is-small is-ghost" onclick="return confirm('<?php echo esc_js(__('¿Borrar este borrador? Si lo preparó el calendario, no vuelve solo.', 'mad-oficina')); ?>')"><?php esc_html_e('Borrar borrador', 'mad-oficina'); ?></button>
    </form>
    <?php endif; ?>

    <script>
    (function () {
      var form = document.querySelector('.madof-invoice-form');
      if (!form) return;
      var body = form.querySelector('.madof-lines tbody');
      var fmt = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
      // «1.234,50» / «1234.50» / «-400» → number (same rules as Money::parse on the server).
      var parse = function (v) {
        v = String(v || '').replace(/[€\s]/g, '');
        if (v === '') return null;
        if (v.indexOf(',') > -1) v = v.replace(/\./g, '').replace(',', '.');
        else if ((v.match(/\./g) || []).length > 1) v = v.replace(/\./g, '');
        var n = parseFloat(v);
        return isNaN(n) ? null : n;
      };
      // Half up on the decimal value, like PHP's round() on the server: 147,105 → 147,11.
      var round = function (n) { var c = Number((Math.abs(n) * 100).toPrecision(15)); return Math.sign(n) * Math.round(c) / 100; };
      var renumber = function () {
        [].forEach.call(body.rows, function (row, i) {
          [].forEach.call(row.querySelectorAll('[name^="lines["]'), function (el) { el.name = el.name.replace(/^lines\[\d+\]/, 'lines[' + i + ']'); });
        });
      };
      var update = function () {
        var base = 0;
        [].forEach.call(body.rows, function (row) {
          var q = parse(row.querySelector('.madof-qty').value); if (q === null) q = 1;
          var p = parse(row.querySelector('.madof-price').value) || 0;
          var a = round(q * p);
          base += a;
          row.querySelector('.madof-amount').textContent = fmt.format(a);
        });
        base = round(base);
        var vat = round(base * (parse(form.querySelector('[data-rate=vat]').value) || 0) / 100);
        var irpf = round(base * (parse(form.querySelector('[data-rate=irpf]').value) || 0) / 100);
        form.querySelector('[data-total=base]').textContent = fmt.format(base);
        form.querySelector('[data-total=vat]').textContent = fmt.format(vat);
        form.querySelector('[data-total=irpf]').textContent = (irpf ? '−' : '') + fmt.format(irpf);
        form.querySelector('[data-total=total]').textContent = fmt.format(round(base + vat - irpf));
      };
      form.addEventListener('input', update);
      form.querySelector('.madof-add-line').addEventListener('click', function () {
        var row = body.rows[body.rows.length - 1].cloneNode(true);
        [].forEach.call(row.querySelectorAll('input[type=hidden], small'), function (el) { el.remove(); }); // a new line is not an agreement's
        [].forEach.call(row.querySelectorAll('textarea, input'), function (el) { el.value = el.classList.contains('madof-qty') ? '1' : ''; });
        body.appendChild(row);
        renumber(); update();
        row.querySelector('textarea').focus();
      });
      body.addEventListener('click', function (e) {
        var btn = e.target.closest('.madof-remove-line');
        if (!btn) return;
        if (body.rows.length > 1) btn.closest('tr').remove();
        else [].forEach.call(btn.closest('tr').querySelectorAll('textarea, input'), function (el) { el.value = el.classList.contains('madof-qty') ? '1' : ''; });
        renumber(); update();
      });
      update();
    })();
    </script>
<?php endif; ?>
</div>
