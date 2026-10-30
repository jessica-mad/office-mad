<?php
/**
 * Quotes (recorded for the forecast): list, editor and acceptance.
 * Included from billing.php ($base available).
 */
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Billing\Quotes;
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;
$now = Months::current();
$profiles = Profiles::all('active');
$codes = array_column(Clients::all(), 'code', 'id');
$leads = class_exists('\\MAD_Oficina\\Visitors\\Leads') ? array_filter(\MAD_Oficina\Visitors\Leads::all(), fn($l) => ! $l['client_id'] && $l['status'] !== 'archived') : [];
$profile_options = function($selected = 0) use ($profiles, $codes){
    $out = '<option value="">' . esc_html__('— Cliente con datos fiscales —', 'mad-oficina') . '</option>';
    foreach ( $profiles as $p ) $out .= '<option value="' . (int) $p['id'] . '" ' . selected((int) $selected, (int) $p['id'], false) . '>' . esc_html(($p['client_id'] && isset($codes[$p['client_id']]) ? $codes[$p['client_id']] . ' · ' : '') . $p['legal_name']) . '</option>';
    return $out;
};
// Links and documents of a quote, and a small form to add more.
$attachments = function(array $q){
    $list = \MAD_Oficina\Billing\QuoteFiles::of($q); ?>
    <div class="madof-attach">
        <?php foreach ( $list as $a ) : ?>
            <span class="madof-attach-item"><a href="<?php echo esc_url(\MAD_Oficina\Billing\QuoteFiles::url($q['id'], $a)); ?>" target="_blank" rel="noopener"><?php echo esc_html(($a['type'] === 'file' ? strtoupper($a['ext']) . ' · ' : '') . $a['name']); ?></a>
                <?php echo AdminUi::form_open('billing_quote_detach', 'madof-inline'); ?><input type="hidden" name="quote_id" value="<?php echo (int) $q['id']; ?>"><input type="hidden" name="att" value="<?php echo esc_attr($a['id']); ?>"><button class="madof-link" aria-label="<?php echo esc_attr(sprintf(__('Quitar %s', 'mad-oficina'), $a['name'])); ?>" onclick="return confirm('<?php echo esc_js(__('¿Quitar este adjunto del presupuesto?', 'mad-oficina')); ?>')">×</button></form></span>
        <?php endforeach; ?>
        <details class="madof-more madof-attach-add"><summary><?php esc_html_e('Adjuntar enlace o PDF', 'mad-oficina'); ?></summary>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form madof-mini">
                <input type="hidden" name="action" value="madof_billing_quote_attach"><?php wp_nonce_field('madof_billing_quote_attach'); ?>
                <input type="hidden" name="quote_id" value="<?php echo (int) $q['id']; ?>">
                <div class="madof-cols">
                    <label><?php esc_html_e('Enlace', 'mad-oficina'); ?><input type="url" name="url" placeholder="https://"></label>
                    <label><?php esc_html_e('…o el documento (PDF o imagen)', 'mad-oficina'); ?><input type="file" name="file" accept="application/pdf,image/png,image/jpeg,image/webp"></label>
                    <label><?php esc_html_e('Nombre (opcional)', 'mad-oficina'); ?><input type="text" name="name" maxlength="120" placeholder="<?php esc_attr_e('Presupuesto firmado', 'mad-oficina'); ?>"></label>
                </div>
                <button class="madof-btn is-small"><?php esc_html_e('Adjuntar', 'mad-oficina'); ?></button>
            </form>
        </details>
    </div>
<?php };
$editing = $_GET['quote'] ?? null;
$q = $editing && $editing !== 'new' ? Quotes::get(absint($editing)) : null;
?>
<?php if ( $editing && ( $q || $editing === 'new' ) && ( ! $q || in_array($q['status'], Quotes::OPEN, true) ) ) :
    $lines = $q['lines'] ?? [['kind' => 'monthly', 'concept' => '', 'amount' => '', 'start_month' => Months::add($now, 1), 'months' => null]]; ?>
    <?php echo AdminUi::form_open('billing_quote_save', 'madof-form madof-quote-form'); ?>
        <input type="hidden" name="quote_id" value="<?php echo (int) ($q['id'] ?? 0); ?>">
        <section class="madof-card">
            <span class="madof-kicker"><?php echo $q ? esc_html($q['number']) : esc_html__('Nuevo presupuesto', 'mad-oficina'); ?></span>
            <label><?php esc_html_e('Título', 'mad-oficina'); ?><input type="text" name="title" required value="<?php echo esc_attr($q['title'] ?? ''); ?>" placeholder="<?php esc_attr_e('Campaña de Navidad', 'mad-oficina'); ?>"></label>
            <div class="madof-cols">
                <label><?php esc_html_e('Para', 'mad-oficina'); ?><select name="profile_id"><?php echo $profile_options($q['profile_id'] ?? absint($_GET['profile'] ?? 0)); ?></select></label>
                <?php if ( $leads ) : ?><label><?php esc_html_e('…o un lead', 'mad-oficina'); ?><select name="lead_id"><option value=""></option><?php foreach ( $leads as $l ) echo '<option value="' . (int) $l['id'] . '" ' . selected((int) ($q['lead_id'] ?? 0), (int) $l['id'], false) . '>' . esc_html(($l['company'] ?: $l['name']) . ' · ' . $l['email']) . '</option>'; ?></select></label><?php endif; ?>
                <label><?php esc_html_e('…o escribe el nombre', 'mad-oficina'); ?><input type="text" name="prospect_name" value="<?php echo esc_attr(! empty($q) && ! $q['profile_id'] && ! $q['lead_id'] ? $q['prospect_name'] : ''); ?>"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Probabilidad', 'mad-oficina'); ?>
                    <select name="probability"><?php foreach ( [10, 25, 50, 75, 90] as $pr ) echo '<option value="' . $pr . '" ' . selected((int) ($q['probability'] ?? 50), $pr, false) . '>' . $pr . ' %</option>'; ?></select>
                    <small><?php esc_html_e('Cuánto crees que saldrá. En la previsión, «posible» se pondera con esto.', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Enviado el', 'mad-oficina'); ?><input type="date" name="sent_on" value="<?php echo esc_attr($q['sent_on'] ?? ''); ?>">
                    <small><?php esc_html_e('Vacío = aún en borrador (no cuenta en la previsión).', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Válido hasta', 'mad-oficina'); ?><input type="date" name="valid_until" value="<?php echo esc_attr($q['valid_until'] ?? ''); ?>"></label>
            </div>
        </section>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Líneas (importes sin IVA)', 'mad-oficina'); ?></span>
            <table class="madof-table madof-qlines">
                <thead><tr><th><?php esc_html_e('Tipo', 'mad-oficina'); ?></th><th><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><th><?php esc_html_e('Importe', 'mad-oficina'); ?></th><th><?php esc_html_e('Mes (o desde)', 'mad-oficina'); ?></th><th><?php esc_html_e('Meses', 'mad-oficina'); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ( array_values($lines) as $i => $l ) : ?>
                    <tr>
                        <td><select name="lines[<?php echo (int) $i; ?>][kind]" aria-label="<?php esc_attr_e('Tipo', 'mad-oficina'); ?>"><option value="monthly" <?php selected($l['kind'], 'monthly'); ?>><?php esc_html_e('Al mes', 'mad-oficina'); ?></option><option value="once" <?php selected($l['kind'], 'once'); ?>><?php esc_html_e('Una vez', 'mad-oficina'); ?></option></select></td>
                        <td><input type="text" name="lines[<?php echo (int) $i; ?>][concept]" value="<?php echo esc_attr($l['concept']); ?>" aria-label="<?php esc_attr_e('Concepto', 'mad-oficina'); ?>"></td>
                        <td><input type="text" inputmode="decimal" name="lines[<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr($l['amount'] === '' ? '' : number_format((float) $l['amount'], 2, ',', '')); ?>" class="madof-price" aria-label="<?php esc_attr_e('Importe', 'mad-oficina'); ?>"></td>
                        <td><input type="month" name="lines[<?php echo (int) $i; ?>][start_month]" value="<?php echo esc_attr($l['start_month']); ?>" aria-label="<?php esc_attr_e('Mes', 'mad-oficina'); ?>"></td>
                        <td><input type="number" min="1" max="120" name="lines[<?php echo (int) $i; ?>][months]" value="<?php echo esc_attr((string) ($l['kind'] === 'monthly' ? ($l['months'] ?? '') : '')); ?>" class="madof-qty" placeholder="∞" aria-label="<?php esc_attr_e('Meses (vacío = sin fin)', 'mad-oficina'); ?>"></td>
                        <td><button type="button" class="madof-link madof-remove-line"><?php esc_html_e('Quitar', 'mad-oficina'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="madof-btn is-ghost is-small madof-add-line"><?php esc_html_e('Añadir línea', 'mad-oficina'); ?></button>
                <small><?php esc_html_e('«Al mes» con meses vacíos = fee sin fecha de fin. «Una vez» = pago único en ese mes.', 'mad-oficina'); ?></small></p>
            <label><?php esc_html_e('Notas', 'mad-oficina'); ?><textarea name="notes" rows="2"><?php echo esc_textarea((string) ($q['notes'] ?? '')); ?></textarea></label>
        </section>
        <div class="madof-actions">
            <button class="madof-btn"><?php esc_html_e('Guardar presupuesto', 'mad-oficina'); ?></button>
            <a href="<?php echo esc_url($base . '&tab=quotes'); ?>"><?php esc_html_e('Cancelar', 'mad-oficina'); ?></a>
        </div>
    </form>
    <script>
    (function () {
      var body = document.querySelector('.madof-qlines tbody');
      if (!body) return;
      var renumber = function () {
        [].forEach.call(body.rows, function (row, i) {
          [].forEach.call(row.querySelectorAll('[name^="lines["]'), function (el) { el.name = el.name.replace(/^lines\[\d+\]/, 'lines[' + i + ']'); });
        });
      };
      document.querySelector('.madof-quote-form .madof-add-line').addEventListener('click', function () {
        var last = body.rows[body.rows.length - 1], row = last.cloneNode(true);
        row.querySelector('select').value = last.querySelector('select').value;
        [].forEach.call(row.querySelectorAll('input[type=text], input[type=number]'), function (el) { el.value = ''; });
        body.appendChild(row); renumber();
        row.querySelector('input[type=text]').focus();
      });
      body.addEventListener('click', function (e) {
        var btn = e.target.closest('.madof-remove-line');
        if (!btn || body.rows.length < 2) return;
        btn.closest('tr').remove(); renumber();
      });
    })();
    </script>

<?php else :
    $open = Quotes::all(Quotes::OPEN);
    $closed = Quotes::all(['accepted', 'rejected', 'expired']);
    ?>
    <p><a class="madof-btn" href="<?php echo esc_url($base . '&tab=quotes&quote=new'); ?>"><?php esc_html_e('Nuevo presupuesto', 'mad-oficina'); ?></a></p>
    <p class="description"><?php esc_html_e('Los presupuestos enviados cuentan como «posible» en la previsión. Al aceptar uno (todo o una parte), sus líneas pasan a Acuerdos: desde ahí se preparan sus facturas en cada fecha, como cualquier otro acuerdo.', 'mad-oficina'); ?></p>
    <?php if ( ! $open ) : ?><p class="madof-empty"><?php esc_html_e('No hay presupuestos abiertos.', 'mad-oficina'); ?></p><?php endif; ?>
    <?php foreach ( $open as $o ) :
        $expired = $o['valid_until'] && $o['valid_until'] < current_time('Y-m-d'); ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php echo esc_html($o['number'] . ' · ' . __(Quotes::STATUSES[$o['status']], 'mad-oficina') . ($o['sent_on'] ? ' · ' . sprintf(__('enviado el %s', 'mad-oficina'), date_i18n('j M', strtotime($o['sent_on']))) : '')); ?></span>
        <h3><?php if ( $o['client_id'] && isset($codes[$o['client_id']]) ) echo '<span class="madof-code">' . esc_html($codes[$o['client_id']]) . '</span> '; ?><?php echo esc_html($o['prospect_name'] . ' · ' . $o['title']); ?>
            <span class="madof-pill is-on"><?php echo (int) $o['probability']; ?> %</span>
            <?php if ( $expired ) echo '<span class="madof-pill is-alert">' . esc_html__('Caducado', 'mad-oficina') . '</span>'; ?></h3>
        <p><?php echo esc_html(Quotes::describe($o)); ?></p>
        <?php $attachments($o); ?>
        <div class="madof-actions">
            <a class="madof-btn is-small is-ghost" href="<?php echo esc_url($base . '&tab=quotes&quote=' . $o['id']); ?>"><?php esc_html_e('Editar', 'mad-oficina'); ?></a>
            <?php if ( $o['status'] === 'draft' ) : echo AdminUi::form_open('billing_quote_status', 'madof-inline'); ?>
                <input type="hidden" name="quote_id" value="<?php echo (int) $o['id']; ?>"><input type="hidden" name="status" value="sent">
                <button class="madof-btn is-small is-ghost"><?php esc_html_e('Marcar como enviado hoy', 'mad-oficina'); ?></button></form><?php endif; ?>
            <?php foreach ( ['rejected' => __('Rechazado', 'mad-oficina'), 'expired' => __('Caducado', 'mad-oficina')] as $st => $label ) : echo AdminUi::form_open('billing_quote_status', 'madof-inline'); ?>
                <input type="hidden" name="quote_id" value="<?php echo (int) $o['id']; ?>"><input type="hidden" name="status" value="<?php echo esc_attr($st); ?>">
                <button class="madof-link"><?php echo esc_html($label); ?></button></form>
            <?php endforeach; ?>
        </div>
        <details class="madof-more madof-accept"><summary><?php esc_html_e('¡Aceptado! (todo o una parte)', 'mad-oficina'); ?></summary>
            <?php echo AdminUi::form_open('billing_quote_accept', 'madof-form madof-mini'); $lead = ! empty($o['lead_id']) && class_exists('\\MAD_Oficina\\Visitors\\Leads') ? \MAD_Oficina\Visitors\Leads::get((int) $o['lead_id']) : null; ?>
                <input type="hidden" name="quote_id" value="<?php echo (int) $o['id']; ?>">
                <label><?php esc_html_e('Se factura a', 'mad-oficina'); ?>
                    <select name="profile_id" data-who required><?php echo $profile_options($o['profile_id']); ?><option value="new" <?php selected(! $o['profile_id']); ?>><?php esc_html_e('+ Cliente nuevo (lo creo ahora)', 'mad-oficina'); ?></option></select></label>
                <fieldset class="madof-new-client" data-new-client <?php echo $o['profile_id'] ? 'hidden' : ''; ?>>
                    <legend><?php esc_html_e('Cliente nuevo: sus datos fiscales', 'mad-oficina'); ?></legend>
                    <div class="madof-cols">
                        <label><?php esc_html_e('Nombre fiscal', 'mad-oficina'); ?><input type="text" name="new[legal_name]" value="<?php echo esc_attr($o['prospect_name']); ?>"></label>
                        <label><?php esc_html_e('NIF / CIF', 'mad-oficina'); ?><input type="text" name="new[tax_id]"></label>
                        <label><?php esc_html_e('Email para facturas', 'mad-oficina'); ?><input type="email" name="new[email]" value="<?php echo esc_attr($lead['email'] ?? ''); ?>"></label>
                    </div>
                    <div class="madof-cols">
                        <label><?php esc_html_e('Domicilio', 'mad-oficina'); ?><input type="text" name="new[address]"></label>
                        <label><?php esc_html_e('Localidad', 'mad-oficina'); ?><input type="text" name="new[city]"></label>
                        <label><?php esc_html_e('IVA %', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="new[vat_rate]" value="21"></label>
                        <label><?php esc_html_e('IRPF %', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="new[irpf_rate]" value="15"></label>
                    </div>
                    <label class="madof-check"><input type="checkbox" name="new[office]" value="1" checked data-office> <?php esc_html_e('Crearlo también como cliente de la oficina (portal, reuniones, tareas)', 'mad-oficina'); ?></label>
                    <div class="madof-cols" data-office-fields>
                        <label><?php esc_html_e('Nombre comercial', 'mad-oficina'); ?><input type="text" name="new[name]" value="<?php echo esc_attr($lead['company'] ?? $o['prospect_name']); ?>"></label>
                        <label><?php esc_html_e('Código corto', 'mad-oficina'); ?><input type="text" name="new[code]" maxlength="12" placeholder="MOM" value="<?php echo esc_attr(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', remove_accents((string) $o['prospect_name'])), 0, 3))); ?>">
                            <small><?php esc_html_e('El que usas en Calendar (2 a 12 letras o números).', 'mad-oficina'); ?></small></label>
                    </div>
                </fieldset>

                <table class="madof-table madof-accept-lines">
                    <thead><tr><th><span class="screen-reader-text"><?php esc_html_e('Aceptada', 'mad-oficina'); ?></span></th><th><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><th><?php esc_html_e('Presupuestado', 'mad-oficina'); ?></th><th><?php esc_html_e('Acordado: cómo se paga', 'mad-oficina'); ?></th><th><?php esc_html_e('Importe', 'mad-oficina'); ?></th><th><?php esc_html_e('Desde', 'mad-oficina'); ?></th><th><?php esc_html_e('Meses', 'mad-oficina'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ( array_values($o['lines']) as $i => $l ) : $n = 'pick[' . (int) $i . ']'; ?>
                        <tr data-pick>
                            <td><input type="checkbox" name="<?php echo esc_attr($n); ?>[on]" value="1" checked aria-label="<?php echo esc_attr(sprintf(__('Aceptada: %s', 'mad-oficina'), $l['concept'])); ?>"></td>
                            <td><input type="text" name="<?php echo esc_attr($n); ?>[concept]" value="<?php echo esc_attr($l['concept']); ?>" aria-label="<?php esc_attr_e('Concepto', 'mad-oficina'); ?>"></td>
                            <td class="madof-muted"><?php echo esc_html(Quotes::describe(['lines' => [$l]])); ?></td>
                            <td><select name="<?php echo esc_attr($n); ?>[kind]" aria-label="<?php esc_attr_e('Cómo se paga', 'mad-oficina'); ?>"><option value="once" <?php selected($l['kind'], 'once'); ?>><?php esc_html_e('Una vez', 'mad-oficina'); ?></option><option value="monthly" <?php selected($l['kind'], 'monthly'); ?>><?php esc_html_e('Al mes (o a plazos)', 'mad-oficina'); ?></option></select></td>
                            <td><input type="text" inputmode="decimal" class="madof-price" name="<?php echo esc_attr($n); ?>[amount]" value="<?php echo esc_attr(number_format((float) $l['amount'], 2, ',', '')); ?>" aria-label="<?php esc_attr_e('Importe acordado (sin IVA)', 'mad-oficina'); ?>"></td>
                            <td><input type="month" name="<?php echo esc_attr($n); ?>[start_month]" value="<?php echo esc_attr($l['start_month']); ?>" aria-label="<?php esc_attr_e('Desde', 'mad-oficina'); ?>"></td>
                            <td><input type="number" min="1" max="120" class="madof-qty" name="<?php echo esc_attr($n); ?>[months]" value="<?php echo esc_attr($l['kind'] === 'monthly' ? (string) ($l['months'] ?? '') : ''); ?>" placeholder="∞" aria-label="<?php esc_attr_e('Meses (vacío = sin fin)', 'mad-oficina'); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Desmarca lo que no han aceptado y ajusta lo acordado: importe sin IVA de cada pago, si es una vez o al mes (para pagar a plazos: «al mes», el importe de cada plazo y cuántos meses), y desde cuándo.', 'mad-oficina'); ?></p>
                <fieldset class="madof-choice">
                    <legend><?php esc_html_e('Cuando llegue cada pago', 'mad-oficina'); ?></legend>
                    <label class="madof-check"><input type="radio" name="auto_send" value="" checked> <?php esc_html_e('Te pregunto ese día (bandeja y Chat) antes de enviar la factura', 'mad-oficina'); ?></label>
                    <label class="madof-check"><input type="radio" name="auto_send" value="1"> <?php esc_html_e('Se emite y se envía sola en su fecha, y te aviso', 'mad-oficina'); ?></label>
                </fieldset>
                <fieldset class="madof-choice">
                    <legend><?php esc_html_e('Lo que no se acepta', 'mad-oficina'); ?></legend>
                    <label class="madof-check"><input type="radio" name="rest" value="reject" checked> <?php esc_html_e('Se queda apuntado como no aceptado', 'mad-oficina'); ?></label>
                    <label class="madof-check"><input type="radio" name="rest" value="new"> <?php esc_html_e('Pasa a un presupuesto nuevo para replantearlo', 'mad-oficina'); ?></label>
                </fieldset>
                <button class="madof-btn is-small"><?php esc_html_e('Aceptar lo marcado y crear los acuerdos', 'mad-oficina'); ?></button>
            </form>
        </details>
    </section>
    <?php endforeach; ?>

    <?php if ( $closed ) : ?>
    <details class="madof-more"><summary><?php echo esc_html(sprintf(__('Cerrados (%d)', 'mad-oficina'), count($closed))); ?></summary>
        <table class="madof-table">
            <thead><tr><th><?php esc_html_e('Número', 'mad-oficina'); ?></th><th><?php esc_html_e('Para', 'mad-oficina'); ?></th><th><?php esc_html_e('Qué', 'mad-oficina'); ?></th><th><?php esc_html_e('Estado', 'mad-oficina'); ?></th><th><?php esc_html_e('Adjuntos', 'mad-oficina'); ?></th></tr></thead>
            <tbody><?php foreach ( $closed as $c ) : ?>
                <tr><td><?php echo esc_html($c['number']); ?></td><td><?php echo esc_html($c['prospect_name']); ?></td><td><?php echo esc_html($c['title'] . ' · ' . Quotes::describe($c)); ?></td>
                    <td><span class="madof-pill <?php echo $c['status'] === 'accepted' ? 'is-on' : ''; ?>"><?php echo esc_html__(Quotes::STATUSES[$c['status']], 'mad-oficina'); ?></span>
                        <?php if ( $c['status'] === 'accepted' ) : ?><br><a href="<?php echo esc_url($base . '&tab=agreements&from_quote=' . (int) $c['id']); ?>"><?php esc_html_e('Sus acuerdos y cobros', 'mad-oficina'); ?></a><?php endif; ?></td>
                    <td><?php $attachments($c); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </details>
    <?php endif; ?>
<?php endif; ?>
<script>
document.querySelectorAll('.madof-accept form').forEach(function (f) {
    var who = f.querySelector('[data-who]'), box = f.querySelector('[data-new-client]'), office = f.querySelector('[data-office]'), of = f.querySelector('[data-office-fields]');
    var sync = function () {
        box.hidden = who.value !== 'new';
        of.hidden = !office.checked;
        box.querySelector('[name="new[legal_name]"]').required = who.value === 'new';
    };
    who.addEventListener('change', sync); office.addEventListener('change', sync); sync();
    f.querySelectorAll('[data-pick] input[type=checkbox]').forEach(function (c) {
        c.addEventListener('change', function () { c.closest('tr').classList.toggle('is-removed', !c.checked); });
    });
});
</script>
