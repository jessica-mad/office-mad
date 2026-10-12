<?php
/**
 * Proveedores y gastos.
 * @var array $settings @var string $tab @var array|null $expense @var \MAD_Oficina\Suppliers\Intake $intake
 */
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Suppliers\Expenses;
use MAD_Oficina\Suppliers\Suppliers;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-gastos');
global $wpdb;
$count = fn($status) => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Expenses::table() . ' WHERE status = %s', $status));
$tabs = [
    'expenses' => __('Gastos', 'mad-oficina'),
    'review' => sprintf(__('Por revisar (%d)', 'mad-oficina'), $count('review')),
    'reading' => sprintf(__('Leyendo (%d)', 'mad-oficina'), $count('queued')),
    'aside' => sprintf(__('No son mías (%d)', 'mad-oficina'), $count('not_mine')),
    'suppliers' => __('Proveedores', 'mad-oficina'),
    'upload' => __('Subir factura', 'mad-oficina'),
    'settings' => __('Ajustes', 'mad-oficina'),
];
$codes = array_column(Clients::all(), 'code', 'id');
$sup_names = array_column(Suppliers::all(), 'name', 'id');
$reason = function(array $e){
    $r = (string) $e['reason'];
    if ( str_starts_with($r, 'client:') ) return sprintf(__('A nombre de tu cliente %s', 'mad-oficina'), substr($r, 7));
    return [
        'other' => __('A nombre de otra persona o empresa', 'mad-oficina'), 'jessica' => __('Lo decidiste tú', 'mad-oficina'),
        'recipient' => __('No dice a nombre de quién está', 'mad-oficina'), 'supplier' => __('Proveedor nuevo', 'mad-oficina'),
        'unreadable' => __('No he podido leerla', 'mad-oficina'), 'not_invoice' => __('No es una factura', 'mad-oficina'),
        'duplicate' => __('Repetida', 'mad-oficina'), 'price_up' => __('Más cara de lo habitual', 'mad-oficina'),
    ][$r] ?? '';
};
$table = function(array $list, $show_reason = false, $pick = false) use ($base, $codes, $reason){
    if ( ! $list ) { echo '<p class="madof-empty">' . esc_html__('Nada por aquí.', 'mad-oficina') . '</p>'; return; } ?>
    <table class="madof-table">
        <thead><tr><?php if ( $pick ) : ?><th><input type="checkbox" class="madof-select-all" aria-label="<?php esc_attr_e('Seleccionar todas', 'mad-oficina'); ?>"></th><?php endif; ?><th><?php esc_html_e('Fecha', 'mad-oficina'); ?></th><th><?php esc_html_e('Proveedor', 'mad-oficina'); ?></th><th><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><th><?php esc_html_e('Base', 'mad-oficina'); ?></th><th><?php esc_html_e('IVA', 'mad-oficina'); ?></th><th><?php esc_html_e('Total', 'mad-oficina'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $list as $e ) : ?>
            <tr>
                <?php if ( $pick ) : ?><td><input type="checkbox" form="madof-exp-bulk" name="ids[]" value="<?php echo (int) $e['id']; ?>" class="madof-pick" aria-label="<?php echo esc_attr($e['supplier_name'] ?: $e['sender']); ?>"></td><?php endif; ?>
                <td class="madof-nowrap"><a href="<?php echo esc_url($base . '&expense=' . $e['id']); ?>"><?php echo esc_html($e['issue_date'] ? date_i18n('j M Y', strtotime($e['issue_date'])) : '—'); ?></a>
                    <?php if ( $e['received_at'] && $e['received_at'] !== $e['issue_date'] ) echo '<br><small class="madof-muted">' . esc_html(sprintf(__('llegó %s', 'mad-oficina'), date_i18n('j M Y', strtotime($e['received_at'])))) . '</small>'; ?>
                    <?php if ( $e['notes'] && str_contains((string) $e['notes'], __('Fecha', 'mad-oficina')) ) echo ' <span title="' . esc_attr($e['notes']) . '">' . AdminUi::icon('alert') . '</span>'; ?></td>
                <td><?php echo esc_html($e['supplier_name'] ?: ($e['sender'] ?: '—')); ?><?php if ( $e['number'] ) echo '<br><small>' . esc_html($e['number']) . '</small>'; ?></td>
                <td><?php echo esc_html((string) $e['concept']); ?>
                    <?php if ( $e['client_id'] ) echo ' <span class="madof-code" title="' . esc_attr__('Se repercute a este cliente', 'mad-oficina') . '">' . esc_html($codes[$e['client_id']] ?? '?') . '</span>'; ?>
                    <?php if ( $show_reason || $e['reason'] === 'price_up' ) echo '<br><small>' . esc_html($reason($e)) . '</small>'; ?>
                    <?php if ( in_array($e['status'], ['queued', 'review'], true) && $e['notes'] && ( $e['status'] === 'queued' || $e['reason'] === 'unreadable' ) ) echo '<br><small class="madof-muted">' . nl2br(esc_html($e['notes'])) . '</small>'; ?>
                    <?php if ( $e['status'] === 'queued' && $e['subject'] ) echo '<br><small class="madof-muted">' . esc_html($e['subject']) . '</small>'; ?></td>
                <td class="madof-nowrap"><?php echo $e['base'] !== null ? esc_html(Money::format($e['base'])) : '—'; ?></td>
                <td class="madof-nowrap"><?php echo $e['vat'] !== null ? esc_html(Money::format($e['vat'])) : '—'; ?></td>
                <td class="madof-nowrap"><strong><?php echo $e['total'] !== null ? esc_html(Money::format($e['total']) . ($e['currency'] !== 'EUR' ? ' ' . $e['currency'] : '')) : '—'; ?></strong></td>
                <td class="madof-row-actions"><?php if ( $e['drive_url'] ) : ?><a href="<?php echo esc_url($e['drive_url']); ?>" target="_blank" rel="noopener">PDF</a><?php elseif ( in_array($e['status'], ['registered', 'not_mine'], true) ) : ?><small class="madof-muted"><?php esc_html_e('archivando…', 'mad-oficina'); ?></small><?php else : ?><small class="madof-muted"><?php esc_html_e('se archiva al decidir', 'mad-oficina'); ?></small><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php };
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Administración', 'mad-oficina'), __('Proveedores y gastos', 'mad-oficina'), __('La Contable lee las facturas de tus proveedores en Gmail. Solo cuentan las que van a tu nombre: las de clientes u otros las guarda aparte por si alguna fuera tuya.', 'mad-oficina')); ?>
    <nav class="madof-tabs" aria-label="<?php esc_attr_e('Secciones', 'mad-oficina'); ?>">
        <?php foreach ( $tabs as $k => $l ) : ?><a href="<?php echo esc_url($base . '&tab=' . $k); ?>" class="<?php echo ($tab === $k && ! $expense) ? 'is-active' : ''; ?>"><?php echo esc_html($l); ?></a><?php endforeach; ?>
    </nav>

<?php if ( $expense ) : $e = $expense; ?>
    <p><a href="<?php echo esc_url($base); ?>">← <?php esc_html_e('Gastos', 'mad-oficina'); ?></a></p>
    <section class="madof-card">
        <span class="madof-kicker"><?php echo esc_html(__(Expenses::STATUSES[$e['status']] ?? $e['status'], 'mad-oficina') . ($reason($e) ? ' · ' . $reason($e) : '')); ?></span>
        <h2><?php echo esc_html(($e['supplier_name'] ?: __('Factura', 'mad-oficina')) . ($e['number'] ? ' · ' . $e['number'] : '')); ?></h2>
        <p><?php echo esc_html(sprintf(__('A nombre de: %s', 'mad-oficina'), trim(($e['recipient_name'] ?: '—') . ' ' . ($e['recipient_tax_id'] ?: '')))); ?>
            · <?php echo esc_html(sprintf(__('De: %s', 'mad-oficina'), $e['sender'] ?: __('subida manual', 'mad-oficina'))); ?>
            <?php if ( $e['subject'] ) echo '· ' . esc_html($e['subject']); ?>
            <?php if ( $e['drive_url'] ) : ?>· <a href="<?php echo esc_url($e['drive_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ver el PDF en Drive', 'mad-oficina'); ?></a><?php endif; ?></p>
        <?php if ( $e['notes'] ) echo '<p class="madof-warn">' . esc_html($e['notes']) . '</p>'; ?>
        <?php if ( $e['client_id'] && ( $inv = Expenses::rebilled_in($e['id']) ) ) echo '<p>' . esc_html(sprintf(__('Repercutido en la factura %s.', 'mad-oficina'), $inv)) . '</p>'; ?>
        <div class="madof-actions">
            <?php if ( $e['status'] !== 'registered' ) : echo AdminUi::form_open('expense_mine', 'madof-inline'); ?><input type="hidden" name="expense_id" value="<?php echo (int) $e['id']; ?>"><input type="hidden" name="mine" value="1"><button class="madof-btn is-small"><?php esc_html_e('Es mía: registrarla', 'mad-oficina'); ?></button></form><?php endif; ?>
            <?php if ( $e['status'] !== 'not_mine' ) : echo AdminUi::form_open('expense_mine', 'madof-inline'); ?><input type="hidden" name="expense_id" value="<?php echo (int) $e['id']; ?>"><button class="madof-btn is-small is-ghost"><?php esc_html_e('No es mía: guardarla aparte', 'mad-oficina'); ?></button></form><?php endif; ?>
        </div>
    </section>
    <?php echo AdminUi::form_open('expense_save'); ?>
        <input type="hidden" name="expense_id" value="<?php echo (int) $e['id']; ?>">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Datos (corrígelos si la Contable se equivocó)', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Proveedor', 'mad-oficina'); ?><select name="supplier_id"><option value=""><?php echo esc_html($e['supplier_name'] ?: '—'); ?></option><?php foreach ( $sup_names as $id => $n ) echo '<option value="' . (int) $id . '" ' . selected((int) $e['supplier_id'], (int) $id, false) . '>' . esc_html($n) . '</option>'; ?></select></label>
                <label><?php esc_html_e('Fecha', 'mad-oficina'); ?><input type="date" name="issue_date" value="<?php echo esc_attr((string) $e['issue_date']); ?>"></label>
                <label><?php esc_html_e('Número', 'mad-oficina'); ?><input type="text" name="number" value="<?php echo esc_attr((string) $e['number']); ?>"></label>
            </div>
            <label><?php esc_html_e('Concepto', 'mad-oficina'); ?><input type="text" name="concept" value="<?php echo esc_attr((string) $e['concept']); ?>"></label>
            <div class="madof-cols">
                <?php foreach ( ['base' => __('Base', 'mad-oficina'), 'vat' => __('IVA', 'mad-oficina'), 'irpf' => __('IRPF', 'mad-oficina'), 'total' => __('Total', 'mad-oficina')] as $k => $l ) : ?>
                    <label><?php echo esc_html($l); ?><input type="text" inputmode="decimal" name="<?php echo esc_attr($k); ?>" value="<?php echo $e[$k] === null ? '' : esc_attr(number_format((float) $e[$k], 2, ',', '')); ?>"></label>
                <?php endforeach; ?>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Categoría', 'mad-oficina'); ?><select name="category"><?php foreach ( Suppliers::CATEGORIES as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($e['category'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select></label>
                <label><?php esc_html_e('Se repercute a', 'mad-oficina'); ?><select name="client_id"><option value=""><?php esc_html_e('Nadie: es gasto de MAD', 'mad-oficina'); ?></option><?php foreach ( Clients::all() as $c ) echo '<option value="' . (int) $c['id'] . '" ' . selected((int) $e['client_id'], (int) $c['id'], false) . '>' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select>
                    <small><?php esc_html_e('Se suma (base, sin margen) a su próxima factura.', 'mad-oficina'); ?></small></label>
            </div>
            <label><?php esc_html_e('Notas', 'mad-oficina'); ?><textarea name="notes" rows="2"><?php echo esc_textarea((string) $e['notes']); ?></textarea></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>

<?php elseif ( $tab === 'review' || $tab === 'reading' ) :
    $bulk_bar = function(array $actions) use ($tab){ ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="madof-exp-bulk" class="madof-bulk">
            <?php wp_nonce_field('madof_expense_bulk'); ?><input type="hidden" name="action" value="madof_expense_bulk"><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <span class="madof-bulk-count" aria-live="polite"></span>
            <?php foreach ( $actions as $do => $label ) : ?><button class="madof-btn is-small <?php echo $do === 'aside' ? '' : 'is-ghost'; ?>" name="do" value="<?php echo esc_attr($do); ?>" disabled><?php echo esc_html($label); ?></button><?php endforeach; ?>
        </form>
        <script>
        (function () {
          var bulk = document.getElementById('madof-exp-bulk');
          var picks = function () { return [].slice.call(document.querySelectorAll('.madof-pick')); };
          var update = function () {
            var n = picks().filter(function (c) { return c.checked; }).length;
            bulk.querySelector('.madof-bulk-count').textContent = n ? n + ' <?php echo esc_js(__('marcadas', 'mad-oficina')); ?>' : '<?php echo esc_js(__('Marca las que quieras', 'mad-oficina')); ?>';
            [].forEach.call(bulk.querySelectorAll('button'), function (b) { b.disabled = !n; });
          };
          document.addEventListener('change', function (e) {
            if (e.target.classList.contains('madof-select-all')) picks().forEach(function (c) { c.checked = e.target.checked; });
            update();
          });
          update();
        })();
        </script>
    <?php };
    if ( $tab === 'review' ) : ?>
    <p class="description"><?php esc_html_e('Facturas que esperan tu decisión (también están en la bandeja): proveedores nuevos, facturas sin destinatario claro o que no he podido leer. Ábrelas para decidir una a una, o márcalas y apártalas.', 'mad-oficina'); ?></p>
    <?php $bulk_bar(['aside' => __('No son mías: guardar aparte', 'mad-oficina'), 'discard' => __('No son facturas: descartar', 'mad-oficina'), 'read' => __('Volver a leer', 'mad-oficina')]); ?>
    <?php $table(Expenses::all(['status' => 'review']), true, true); ?>
    <?php else : ?>
    <p class="description"><?php esc_html_e('PDFs que han llegado y la Contable aún no ha leído. Se leen solos en segundo plano (unos pocos cada pocos minutos); si uno falla, se reintenta hasta 3 veces y aquí ves por qué.', 'mad-oficina'); ?></p>
    <?php echo AdminUi::form_open('expense_read_now', 'madof-inline'); ?><button class="madof-btn is-small"><?php esc_html_e('Leer ahora las que pueda (1 minuto)', 'mad-oficina'); ?></button></form>
    <?php $bulk_bar(['discard' => __('Descartar (no son facturas)', 'mad-oficina'), 'aside' => __('Guardar aparte sin leer', 'mad-oficina')]); ?>
    <?php $table(Expenses::all(['status' => 'queued']), false, true); ?>
    <?php endif; ?>

<?php elseif ( $tab === 'aside' ) : ?>
    <p class="description"><?php esc_html_e('Facturas a nombre de tus clientes u otras personas: no cuentan como gasto tuyo. Están en «Recibidas/No son mías» por si alguna fuera tuya: ábrela y pulsa «Es mía».', 'mad-oficina'); ?></p>
    <?php $table(Expenses::all(['status' => 'not_mine']), true); ?>

<?php elseif ( $tab === 'suppliers' ) :
    $edit = $_GET['supplier'] ?? null;
    $s = $edit && $edit !== 'new' ? Suppliers::get(absint($edit)) : null;
    if ( $edit ) : $s = $s ?: ['id' => 0, 'name' => '', 'tax_id' => '', 'emails' => [], 'service' => '', 'category' => 'software', 'periodicity' => 'monthly', 'expected_day' => null, 'expected_month' => null, 'usual_amount' => null, 'currency' => 'EUR', 'delivery' => 'email', 'panel_url' => '', 'client_id' => null, 'status' => 'active', 'notes' => '']; ?>
    <?php echo AdminUi::form_open('supplier_save'); ?>
        <input type="hidden" name="supplier_id" value="<?php echo (int) $s['id']; ?>">
        <section class="madof-card">
            <span class="madof-kicker"><?php echo $s['id'] ? esc_html($s['name']) : esc_html__('Nuevo proveedor', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="name" required value="<?php echo esc_attr($s['name']); ?>"></label>
                <label><?php esc_html_e('NIF', 'mad-oficina'); ?><input type="text" name="tax_id" value="<?php echo esc_attr((string) $s['tax_id']); ?>"></label>
                <label><?php esc_html_e('Servicio', 'mad-oficina'); ?><input type="text" name="service" value="<?php echo esc_attr((string) $s['service']); ?>"></label>
            </div>
            <label><?php esc_html_e('Emails desde los que factura', 'mad-oficina'); ?><input type="text" name="emails" value="<?php echo esc_attr(implode(', ', $s['emails'])); ?>"></label>
            <div class="madof-cols">
                <label><?php esc_html_e('Categoría', 'mad-oficina'); ?><select name="category"><?php foreach ( Suppliers::CATEGORIES as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($s['category'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select></label>
                <label><?php esc_html_e('Periodicidad', 'mad-oficina'); ?><select name="periodicity"><?php foreach ( Suppliers::PERIODICITIES as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($s['periodicity'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select></label>
                <label><?php esc_html_e('Día esperado', 'mad-oficina'); ?><input type="number" min="1" max="31" name="expected_day" value="<?php echo esc_attr((string) $s['expected_day']); ?>"></label>
                <label><?php esc_html_e('Mes (anual / trimestral)', 'mad-oficina'); ?><input type="number" min="1" max="12" name="expected_month" value="<?php echo esc_attr((string) $s['expected_month']); ?>"></label>
                <label><?php esc_html_e('Importe habitual', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="usual_amount" value="<?php echo $s['usual_amount'] ? esc_attr(number_format($s['usual_amount'], 2, ',', '')) : ''; ?>"></label>
                <label><?php esc_html_e('Moneda', 'mad-oficina'); ?><input type="text" name="currency" maxlength="3" value="<?php echo esc_attr($s['currency'] ?? 'EUR'); ?>" style="width:80px"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Cómo llega la factura', 'mad-oficina'); ?><select name="delivery"><?php foreach ( Suppliers::DELIVERIES as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($s['delivery'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select></label>
                <label><?php esc_html_e('URL de su panel', 'mad-oficina'); ?><input type="url" name="panel_url" value="<?php echo esc_attr((string) $s['panel_url']); ?>"></label>
                <label><?php esc_html_e('Se repercute a', 'mad-oficina'); ?><select name="client_id"><option value=""><?php esc_html_e('Nadie: es de MAD', 'mad-oficina'); ?></option><?php foreach ( Clients::all() as $c ) echo '<option value="' . (int) $c['id'] . '" ' . selected((int) $s['client_id'], (int) $c['id'], false) . '>' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select></label>
                <label><?php esc_html_e('Estado', 'mad-oficina'); ?><select name="status"><option value="active" <?php selected($s['status'], 'active'); ?>><?php esc_html_e('Activo', 'mad-oficina'); ?></option><option value="inactive" <?php selected($s['status'], 'inactive'); ?>><?php esc_html_e('Ya no trabajamos', 'mad-oficina'); ?></option></select></label>
            </div>
            <label><?php esc_html_e('Notas', 'mad-oficina'); ?><textarea name="notes" rows="2"><?php echo esc_textarea((string) $s['notes']); ?></textarea></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>
    <?php if ( $s['id'] && count($sup_names) > 1 ) : echo AdminUi::form_open('supplier_merge', 'madof-form madof-card'); ?>
        <input type="hidden" name="keep" value="<?php echo (int) $s['id']; ?>">
        <span class="madof-kicker"><?php esc_html_e('¿Está repetido?', 'mad-oficina'); ?></span>
        <label><?php echo esc_html(sprintf(__('Unir con %s (sus facturas y emails pasan a esta ficha y la otra desaparece)', 'mad-oficina'), $s['name'])); ?>
            <select name="remove[]" required><option value=""></option><?php foreach ( $sup_names as $id => $n ) if ( (int) $id !== (int) $s['id'] ) echo '<option value="' . (int) $id . '">' . esc_html($n) . '</option>'; ?></select></label>
        <button class="madof-btn is-small is-ghost"><?php esc_html_e('Unir', 'mad-oficina'); ?></button>
    </form><?php endif; ?>
    <?php else : $list = Suppliers::all(); $dups = Suppliers::duplicates(); ?>
    <?php if ( $dups ) : ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Parecen el mismo proveedor', 'mad-oficina'); ?></span>
        <?php foreach ( $dups as $g ) : $keep = array_shift($g); ?>
            <?php echo AdminUi::form_open('supplier_merge', 'madof-form madof-suggest'); ?>
                <input type="hidden" name="keep" value="<?php echo (int) $keep['id']; ?>">
                <?php foreach ( $g as $d ) echo '<input type="hidden" name="remove[]" value="' . (int) $d['id'] . '">'; ?>
                <p><strong><?php echo esc_html($keep['name']); ?></strong> ×<?php echo count($g) + 1; ?>
                    <small><?php echo esc_html(sprintf(__('%d facturas en total. Se queda la ficha con más facturas y se le suman las demás y sus emails.', 'mad-oficina'), $keep['invoices'] + array_sum(array_column($g, 'invoices')))); ?></small></p>
                <button class="madof-btn is-small"><?php esc_html_e('Unir en uno', 'mad-oficina'); ?></button>
            </form>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <p><a class="madof-btn" href="<?php echo esc_url($base . '&tab=suppliers&supplier=new'); ?>"><?php esc_html_e('Nuevo proveedor', 'mad-oficina'); ?></a></p>
    <?php if ( ! $list ) : ?><p class="madof-empty"><?php esc_html_e('Aún no hay proveedores. Se dan de alta solos cuando apruebas sus facturas (o aquí, a mano).', 'mad-oficina'); ?></p><?php else : ?>
    <table class="madof-table">
        <thead><tr><th><?php esc_html_e('Proveedor', 'mad-oficina'); ?></th><th><?php esc_html_e('Categoría', 'mad-oficina'); ?></th><th><?php esc_html_e('Cada cuánto', 'mad-oficina'); ?></th><th><?php esc_html_e('Habitual', 'mad-oficina'); ?></th><th><?php esc_html_e('Última factura', 'mad-oficina'); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $list as $s ) : $last = $wpdb->get_var($wpdb->prepare('SELECT MAX(issue_date) FROM ' . Expenses::table() . " WHERE supplier_id = %d AND status = 'registered'", $s['id'])); ?>
            <tr>
                <td><a href="<?php echo esc_url($base . '&tab=suppliers&supplier=' . $s['id']); ?>"><strong><?php echo esc_html($s['name']); ?></strong></a><?php if ( $s['status'] === 'inactive' ) echo ' <small>(' . esc_html__('inactivo', 'mad-oficina') . ')</small>'; ?>
                    <?php if ( $s['client_id'] ) echo ' <span class="madof-code">' . esc_html($codes[$s['client_id']] ?? '?') . '</span>'; ?>
                    <?php if ( $s['emails'] ) echo '<br><small>' . esc_html(implode(', ', $s['emails'])) . '</small>'; ?></td>
                <td><?php echo esc_html__(Suppliers::CATEGORIES[$s['category']] ?? $s['category'], 'mad-oficina'); ?></td>
                <td><?php echo esc_html__(Suppliers::PERIODICITIES[$s['periodicity']] ?? '', 'mad-oficina'); ?><?php if ( $s['expected_day'] ) echo ' · ' . esc_html(sprintf(__('día %d', 'mad-oficina'), $s['expected_day'])); ?></td>
                <td class="madof-nowrap"><?php echo $s['usual_amount'] ? esc_html(Suppliers::money($s['usual_amount'], $s['currency'])) : '—'; ?></td>
                <td><?php echo $last ? esc_html(date_i18n('j M Y', strtotime($last))) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; endif; ?>

<?php elseif ( $tab === 'upload' ) : ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Subir facturas', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Para las que se descargan del panel del proveedor (o las que te dieron en papel y escaneaste). Pasan por lo mismo que las de Gmail: la Contable las lee, comprueba que van a tu nombre y las archiva.', 'mad-oficina'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="madof-form">
            <?php wp_nonce_field('madof_expense_upload'); ?><input type="hidden" name="action" value="madof_expense_upload">
            <input type="file" name="pdfs[]" accept="application/pdf" multiple required>
            <button class="madof-btn"><?php esc_html_e('Subir y leer', 'mad-oficina'); ?></button>
        </form>
    </section>

<?php elseif ( $tab === 'settings' ) : ?>
    <?php echo AdminUi::form_open('suppliers_settings'); ?>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Quién eres tú en las facturas', 'mad-oficina'); ?></span>
            <p class="description"><?php esc_html_e('Solo cuentan como gasto las facturas a tu nombre. Ya uso tu nombre fiscal y tu NIE de Facturación → Datos del emisor; añade aquí otras formas en que aparezcas.', 'mad-oficina'); ?></p>
            <label><?php esc_html_e('Otros nombres (uno por línea)', 'mad-oficina'); ?><textarea name="my_names" rows="3" placeholder="MAD Marketing &amp; Ads"><?php echo esc_textarea($settings['my_names'] ?? ''); ?></textarea></label>
            <div class="madof-cols">
                <label><?php esc_html_e('Otros NIF/NIE', 'mad-oficina'); ?><input type="text" name="my_tax_ids" value="<?php echo esc_attr($settings['my_tax_ids'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Tus emails (para recibos sin destinatario)', 'mad-oficina'); ?><input type="text" name="my_emails" value="<?php echo esc_attr($settings['my_emails'] ?? ''); ?>" placeholder="jessica@imad.pro, facturas@imad.pro"></label>
            </div>
        </section>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Avisos', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Preguntar por una factura que falta tras', 'mad-oficina'); ?><select name="grace_days"><?php foreach ( [3, 5, 7, 10, 15] as $d ) echo '<option value="' . $d . '" ' . selected((int) ($settings['grace_days'] ?? 5), $d, false) . '>' . esc_html(sprintf(__('%d días', 'mad-oficina'), $d)) . '</option>'; ?></select></label>
                <label><?php esc_html_e('Modelo que lee las facturas', 'mad-oficina'); ?><select name="model"><?php foreach ( ['claude-opus-5-5' => 'Claude Opus 5.5 (más fiable)', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5 (más barato)'] as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($settings['model'] ?? 'claude-opus-5-5', $k, false) . '>' . esc_html($l) . '</option>'; ?></select></label>
            </div>
            <label class="madof-check"><input type="checkbox" name="weekly" value="1" <?php checked($settings['weekly'] ?? true); ?>> <?php esc_html_e('Resumen de gastos los lunes por Chat', 'mad-oficina'); ?></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>

<?php else :
    $year = absint($_GET['year'] ?? current_time('Y'));
    $quarter = absint($_GET['q'] ?? 0);
    $list = Expenses::all(['status' => 'registered', 'year' => $year, 'quarter' => $quarter ?: null]);
    $sum = fn($k) => array_sum(array_map(fn($e) => $e['currency'] === 'EUR' ? (float) $e[$k] : 0, $list)); ?>
    <nav class="madof-filters">
        <?php foreach ( [$year - 1, $year, $year + 1] as $y ) echo '<a class="madof-pill ' . ($y === $year ? 'is-on' : '') . '" href="' . esc_url($base . '&year=' . $y) . '">' . (int) $y . '</a>'; ?>
        <?php foreach ( [0, 1, 2, 3, 4] as $q ) echo '<a class="madof-pill ' . ($q === $quarter ? 'is-on' : '') . '" href="' . esc_url($base . '&year=' . $year . ($q ? '&q=' . $q : '')) . '">' . esc_html($q ? 'T' . $q : __('Todo el año', 'mad-oficina')) . '</a>'; ?>
    </nav>
    <section class="madof-forecast-head">
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Base', 'mad-oficina'); ?></span><strong><?php echo esc_html(Money::format($sum('base'))); ?></strong><small><?php echo esc_html(sprintf(_n('%d factura', '%d facturas', count($list), 'mad-oficina'), count($list))); ?></small></div>
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('IVA soportado', 'mad-oficina'); ?></span><strong><?php echo esc_html(Money::format($sum('vat'))); ?></strong><small><?php esc_html_e('lo revisa la gestoría', 'mad-oficina'); ?></small></div>
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Total pagado', 'mad-oficina'); ?></span><strong><?php echo esc_html(Money::format($sum('total'))); ?></strong><small><?php esc_html_e('solo en euros', 'mad-oficina'); ?></small></div>
    </section>
    <?php $table($list); ?>
<?php endif; ?>
</div>
