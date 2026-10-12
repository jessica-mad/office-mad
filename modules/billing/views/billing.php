<?php
/**
 * @var array $settings @var string $tab @var array|null $invoice @var array|null $preview
 */
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-facturacion');
// Each section is an entry of the «Finanzas» menu; only «Facturas» keeps sub-tabs.
$tabs = ['invoices' => __('Facturas', 'mad-oficina'), 'unpaid' => __('Sin cobrar', 'mad-oficina'), 'summary' => __('Por meses y clientes', 'mad-oficina'), 'import' => __('Importar histórico', 'mad-oficina')];
$heads = [
    'calendar' => [__('Calendario de facturación', 'mad-oficina'), __('Lo que toca facturar cada mes a cada cliente, según sus acuerdos.', 'mad-oficina')],
    'forecast' => [__('Previsión', 'mad-oficina'), __('Ingresos sin IVA mes a mes: lo facturado, lo confirmado y lo posible.', 'mad-oficina')],
    'agreements' => [__('Acuerdos', 'mad-oficina'), __('Fees mensuales y encargos puntuales de cada cliente.', 'mad-oficina')],
    'quotes' => [__('Presupuestos', 'mad-oficina'), __('Lo que está en el aire, con su probabilidad.', 'mad-oficina')],
    'clients' => [__('Clientes de facturación', 'mad-oficina'), __('Datos fiscales de cada cliente y con qué cliente de la oficina va.', 'mad-oficina')],
    'taxes' => [__('Impuestos y cuotas', 'mad-oficina'), __('Lo que pagas a Hacienda y a la Seguridad Social. Las estimaciones son orientativas: la cifra buena la da la gestoría.', 'mad-oficina')],
    'settings' => [__('Ajustes de finanzas', 'mad-oficina'), __('Tus datos de emisor, el email de las facturas, el Libro MAD y la lectura de gastos.', 'mad-oficina')],
];
$years = Invoices::years();
$year = isset($_GET['year']) ? absint($_GET['year']) : ($years[0] ?? (int) current_time('Y'));
$money = fn($n) => Money::format($n);
$status_pill = function($inv){
    $label = Invoices::STATUSES[$inv['status']] ?? $inv['status'];
    $cls = $inv['status'] === 'draft' ? '' : 'is-on';
    $out = '<span class="madof-pill ' . $cls . '">' . esc_html__($label, 'mad-oficina') . '</span>';
    if ( in_array($inv['status'], ['issued', 'sent'], true) && $inv['series'] !== 'AR' ) $out .= ' <span class="madof-pill ' . ($inv['paid'] ? 'is-on' : '') . '">' . ($inv['paid'] ? esc_html__('Cobrada', 'mad-oficina') : esc_html__('Sin cobrar', 'mad-oficina')) . '</span>';
    if ( ! $inv['paid'] && in_array($inv['status'], ['issued', 'sent'], true) && $inv['series'] !== 'AR' && ! empty($inv['due_date']) && $inv['due_date'] < current_time('Y-m-d') ) $out .= ' <span class="madof-pill is-alert">' . esc_html__('Vencida', 'mad-oficina') . '</span>';
    return $out;
};
?>
<div class="wrap madof-wrap">
    <?php if ( ! $invoice && isset($heads[$tab]) ) : ?>
        <?php AdminUi::header(__('Finanzas', 'mad-oficina'), $heads[$tab][0], $heads[$tab][1]); ?>
    <?php else : ?>
        <?php AdminUi::header(__('Finanzas', 'mad-oficina'), __('Facturas', 'mad-oficina'), __('Tu libro de facturas emitidas. La serie Argentina se guarda aparte: no cuenta en los libros españoles ni en el paquete de la gestoría.', 'mad-oficina')); ?>
        <nav class="madof-tabs" aria-label="<?php esc_attr_e('Secciones', 'mad-oficina'); ?>">
            <?php foreach ( $tabs as $k => $l ) : ?>
                <a href="<?php echo esc_url($base . '&tab=' . $k); ?>" class="<?php echo (($tab === $k || ($k === 'invoices' && ! isset($tabs[$tab]))) && ! $invoice) ? 'is-active' : ''; ?>"><?php echo esc_html($l); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

<?php if ( $invoice ) : $inv = $invoice; ?>
    <p><a href="<?php echo esc_url($base . '&year=' . $inv['year']); ?>">← <?php esc_html_e('Todas las facturas', 'mad-oficina'); ?></a></p>
    <section class="madof-card">
        <span class="madof-kicker"><?php echo esc_html(Invoices::SERIES[$inv['series']] . ' · ' . mysql2date('j M Y', $inv['issue_date'])); ?></span>
        <h2><?php echo esc_html($inv['number'] ?: __('Sin número', 'mad-oficina')); ?> · <?php echo esc_html($inv['client_name']); ?> <?php echo $status_pill($inv); ?></h2>
        <table class="madof-table"><tbody>
            <tr><th scope="row"><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><td><?php echo esc_html(implode(' · ', array_filter([$inv['client_name'], $inv['client_tax_id'], $inv['client_address'], $inv['client_city']]))); ?></td></tr>
            <?php if ( $inv['client_email'] ) : ?><tr><th scope="row"><?php esc_html_e('Email', 'mad-oficina'); ?></th><td><?php echo esc_html($inv['client_email']); ?></td></tr><?php endif; ?>
            <?php foreach ( $inv['lines'] as $l ) : ?>
                <tr><th scope="row"><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><td><?php echo esc_html($l['description']); ?> — <strong><?php echo esc_html($money($l['amount'])); ?></strong></td></tr>
            <?php endforeach; ?>
            <?php if ( $inv['due_date'] ) : ?><tr><th scope="row"><?php esc_html_e('Vencimiento', 'mad-oficina'); ?></th><td><?php echo esc_html(mysql2date('j M Y', $inv['due_date'])); ?></td></tr><?php endif; ?>
            <?php if ( $inv['sent_at'] ) : ?><tr><th scope="row"><?php esc_html_e('Enviada', 'mad-oficina'); ?></th><td><?php echo esc_html(mysql2date('j M Y H:i', get_date_from_gmt($inv['sent_at']))); ?></td></tr><?php endif; ?>
            <tr><th scope="row"><?php esc_html_e('Base', 'mad-oficina'); ?></th><td><?php echo esc_html($money($inv['base'])); ?></td></tr>
            <tr><th scope="row"><?php echo esc_html(sprintf(__('IVA (%s %%)', 'mad-oficina'), rtrim(rtrim(number_format($inv['vat_rate'], 2, ',', ''), '0'), ','))); ?></th><td><?php echo esc_html($money($inv['vat'])); ?></td></tr>
            <tr><th scope="row"><?php echo esc_html(sprintf(__('IRPF (%s %%)', 'mad-oficina'), rtrim(rtrim(number_format($inv['irpf_rate'], 2, ',', ''), '0'), ','))); ?></th><td><?php echo esc_html(($inv['irpf'] > 0 ? '−' : '') . $money($inv['irpf'])); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Total', 'mad-oficina'); ?></th><td><strong><?php echo esc_html($money($inv['total'])); ?></strong></td></tr>
            <tr><th scope="row">PDF</th><td>
                <?php if ( $inv['series'] !== 'AR' ) : ?><a class="madof-btn is-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=madof_billing_pdf&invoice=' . $inv['id']), 'madof_billing_pdf_' . $inv['id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ver con el diseño MAD', 'mad-oficina'); ?></a><?php endif; ?>
                <?php if ( $inv['pdf_url'] ) : ?> <a href="<?php echo esc_url($inv['pdf_url']); ?>" target="_blank" rel="noopener"><?php echo $inv['source'] === 'import' ? esc_html__('PDF original en Drive', 'mad-oficina') : esc_html__('PDF en Emitidas (Drive)', 'mad-oficina'); ?></a><?php endif; ?>
            </td></tr>
            <tr><th scope="row"><?php esc_html_e('Origen', 'mad-oficina'); ?></th><td><?php echo $inv['source'] === 'import' ? esc_html__('Importada de la hoja antigua', 'mad-oficina') : esc_html__('Creada en la oficina', 'mad-oficina'); ?></td></tr>
        </tbody></table>
        <div class="madof-actions">
        <?php if ( $inv['status'] === 'draft' ) : ?>
            <a class="madof-btn" href="<?php echo esc_url($base . '&edit=' . $inv['id']); ?>"><?php echo $inv['series'] === 'AR' ? esc_html__('Editar', 'mad-oficina') : esc_html__('Editar y emitir', 'mad-oficina'); ?></a>
            <?php if ( $inv['series'] === 'AR' ) : echo AdminUi::form_open('billing_ar_issued', 'madof-inline'); ?>
                <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
                <button class="madof-btn is-ghost"><?php esc_html_e('Marcar como emitida en Argentina', 'mad-oficina'); ?></button>
            </form><?php endif; ?>
        <?php endif; ?>
        <?php if ( $inv['number'] && $inv['series'] === 'F' ) : echo AdminUi::form_open('billing_rectify', 'madof-inline'); ?>
            <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
            <button class="madof-btn is-ghost"><?php esc_html_e('Rectificar', 'mad-oficina'); ?></button>
        </form><?php endif; ?>
        </div>
        <?php if ( in_array($inv['status'], ['issued', 'sent'], true) && $inv['series'] !== 'AR' ) : ?>
            <?php echo AdminUi::form_open('billing_paid', 'madof-inline'); ?>
                <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
                <input type="hidden" name="paid" value="<?php echo $inv['paid'] ? '' : '1'; ?>">
                <button class="madof-btn <?php echo $inv['paid'] ? 'is-ghost' : ''; ?>"><?php echo $inv['paid'] ? esc_html__('Marcar como no cobrada', 'mad-oficina') : esc_html__('Marcar como cobrada', 'mad-oficina'); ?></button>
            </form>
        <?php endif; ?>
        <?php echo AdminUi::form_open('billing_note'); ?>
            <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
            <label><?php esc_html_e('Notas internas', 'mad-oficina'); ?><textarea name="notes" rows="3"><?php echo esc_textarea((string) $inv['notes']); ?></textarea></label>
            <button class="madof-btn is-ghost"><?php esc_html_e('Guardar nota', 'mad-oficina'); ?></button>
        </form>
        <?php if ( $inv['legacy'] ) : ?>
            <details class="madof-more"><summary><?php esc_html_e('Fila original de la hoja antigua', 'mad-oficina'); ?></summary>
                <p><?php foreach ( $inv['legacy'] as $k => $v ) if ( $v !== '' ) echo '<strong>' . esc_html($k) . ':</strong> ' . esc_html($v) . '<br>'; ?></p>
            </details>
        <?php endif; ?>
    </section>

<?php elseif ( in_array($tab, ['calendar', 'forecast', 'agreements', 'quotes', 'taxes'], true) ) :
    include __DIR__ . '/' . $tab . '.php'; ?>

<?php elseif ( $tab === 'summary' ) :
    $months = Invoices::monthly($year);
    $q = [1 => [1, 2, 3], 2 => [4, 5, 6], 3 => [7, 8, 9], 4 => [10, 11, 12]];
    $sum = fn($keys, $field) => array_sum(array_map(fn($m) => $months[$m][$field], $keys));
    $ext = Invoices::external_total($year);
    $mnames = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']; ?>
    <nav class="madof-filters"><?php foreach ( $years as $y ) : ?><a class="madof-pill <?php echo $y === $year ? 'is-on' : ''; ?>" href="<?php echo esc_url($base . '&tab=summary&year=' . $y); ?>"><?php echo (int) $y; ?></a><?php endforeach; ?></nav>
    <section class="madof-card">
        <span class="madof-kicker"><?php echo esc_html(sprintf(__('Facturación %d · libros españoles', 'mad-oficina'), $year)); ?></span>
        <div class="madof-scroll"><table class="madof-table">
            <thead><tr><th></th><th><?php esc_html_e('Total', 'mad-oficina'); ?></th><?php for ( $i = 1; $i <= 4; $i++ ) echo '<th>T' . $i . '</th>'; foreach ( $mnames as $m ) echo '<th>' . esc_html($m) . '</th>'; ?></tr></thead>
            <tbody>
            <?php foreach ( ['base' => __('Bases', 'mad-oficina'), 'vat' => __('IVA', 'mad-oficina'), 'irpf' => __('IRPF', 'mad-oficina'), 'total' => __('Cobrable', 'mad-oficina'), 'n' => __('Nº facturas', 'mad-oficina')] as $f => $label ) : ?>
                <tr><th scope="row"><?php echo esc_html($label); ?></th>
                    <td><strong><?php echo esc_html($f === 'n' ? $sum(range(1, 12), $f) : Money::format($sum(range(1, 12), $f), false)); ?></strong></td>
                    <?php foreach ( $q as $keys ) echo '<td>' . esc_html($f === 'n' ? $sum($keys, $f) : Money::format($sum($keys, $f), false)) . '</td>'; ?>
                    <?php for ( $m = 1; $m <= 12; $m++ ) echo '<td>' . esc_html($f === 'n' ? $months[$m][$f] : Money::format($months[$m][$f], false)) . '</td>'; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ( ! empty($ext['n']) ) : ?><p class="description"><?php echo esc_html(sprintf(__('Aparte, serie Argentina: %1$d facturas, %2$s.', 'mad-oficina'), (int) $ext['n'], Money::format($ext['total']))); ?></p><?php endif; ?>
    </section>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Por cliente', 'mad-oficina'); ?></span>
        <?php $rows = Invoices::by_client($year); $total_base = array_sum(array_column($rows, 'base')) ?: 1; ?>
        <table class="madof-table"><thead><tr><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><th><?php esc_html_e('Bases', 'mad-oficina'); ?></th><th>%</th><th><?php esc_html_e('IVA', 'mad-oficina'); ?></th><th><?php esc_html_e('IRPF', 'mad-oficina'); ?></th><th><?php esc_html_e('Total', 'mad-oficina'); ?></th></tr></thead><tbody>
        <?php foreach ( $rows as $r ) : ?>
            <tr><td><?php echo esc_html($r['client_name']); ?></td><td><?php echo esc_html($money($r['base'])); ?></td><td><?php echo esc_html(number_format($r['base'] / $total_base * 100, 1, ',', '')); ?> %</td><td><?php echo esc_html($money($r['vat'])); ?></td><td><?php echo esc_html(($r['irpf'] > 0 ? '−' : '') . $money($r['irpf'])); ?></td><td><?php echo esc_html($money($r['total'])); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>

<?php elseif ( $tab === 'clients' ) :
    $profile = isset($_GET['profile']) ? Profiles::get(absint($_GET['profile'])) : null;
    $profiles = Profiles::all(); ?>
    <?php if ( $profile || isset($_GET['new']) ) : $p = $profile ?: ['id' => 0, 'legal_name' => '', 'tax_id' => '', 'address' => '', 'city' => '', 'country' => 'ES', 'email' => '', 'vat_rate' => 21, 'irpf_rate' => 15, 'series' => 'F', 'status' => 'active', 'client_id' => null, 'notes' => '']; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_billing_profile'); ?>
        <input type="hidden" name="action" value="madof_billing_profile">
        <input type="hidden" name="profile_id" value="<?php echo (int) $p['id']; ?>">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Datos fiscales del cliente', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre fiscal', 'mad-oficina'); ?><input type="text" name="legal_name" value="<?php echo esc_attr($p['legal_name']); ?>" required></label>
                <label><?php esc_html_e('NIF / CIF', 'mad-oficina'); ?><input type="text" name="tax_id" value="<?php echo esc_attr((string) $p['tax_id']); ?>"></label>
                <label><?php esc_html_e('Email para facturas', 'mad-oficina'); ?><input type="text" name="email" value="<?php echo esc_attr((string) $p['email']); ?>"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Dirección', 'mad-oficina'); ?><input type="text" name="address" value="<?php echo esc_attr((string) $p['address']); ?>"></label>
                <label><?php esc_html_e('Localidad', 'mad-oficina'); ?><input type="text" name="city" value="<?php echo esc_attr((string) $p['city']); ?>"></label>
                <label><?php esc_html_e('País (ES, AR…)', 'mad-oficina'); ?><input type="text" name="country" value="<?php echo esc_attr($p['country']); ?>" maxlength="2"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('IVA %', 'mad-oficina'); ?><input type="number" step="0.01" name="vat_rate" value="<?php echo esc_attr($p['vat_rate']); ?>"></label>
                <label><?php esc_html_e('IRPF %', 'mad-oficina'); ?><input type="number" step="0.01" name="irpf_rate" value="<?php echo esc_attr($p['irpf_rate']); ?>">
                    <small><?php esc_html_e('0 para particulares o clientes de fuera.', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Día de facturación', 'mad-oficina'); ?><input type="number" min="1" max="31" name="billing_day" value="<?php echo esc_attr((string) ($p['billing_day'] ?? '')); ?>" placeholder="<?php echo (int) ($settings['billing_day'] ?? 28); ?>">
                    <small><?php esc_html_e('Vacío = el de siempre (fin de mes).', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Días para pagar', 'mad-oficina'); ?><input type="number" min="0" max="365" name="payment_days" value="<?php echo esc_attr((string) ($p['payment_days'] ?? '')); ?>" placeholder="<?php echo (int) ($settings['payment_days'] ?? 15); ?>"></label>
                <label><?php esc_html_e('Serie', 'mad-oficina'); ?><select name="series"><?php foreach ( Invoices::SERIES as $k => $l ) if ( $k !== 'R' ) echo '<option value="' . esc_attr($k) . '" ' . selected($p['series'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Cliente de la oficina', 'mad-oficina'); ?><select name="client_id"><option value=""><?php esc_html_e('— Ninguno —', 'mad-oficina'); ?></option><?php foreach ( Clients::all() as $c ) echo '<option value="' . (int) $c['id'] . '" ' . selected((int) $p['client_id'], (int) $c['id'], false) . '>' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select></label>
                <label><?php esc_html_e('Estado', 'mad-oficina'); ?><select name="status"><option value="active" <?php selected($p['status'], 'active'); ?>><?php esc_html_e('Activo', 'mad-oficina'); ?></option><option value="inactive" <?php selected($p['status'], 'inactive'); ?>><?php esc_html_e('Inactiva (ya no trabajamos o razón social anterior)', 'mad-oficina'); ?></option></select></label>
            </div>
            <label><?php esc_html_e('Notas', 'mad-oficina'); ?><textarea name="notes" rows="2"><?php echo esc_textarea((string) $p['notes']); ?></textarea></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>
    <?php endif; ?>
    <?php $suggestions = Profiles::suggestions(); $sure = count(array_filter($suggestions, fn($x) => $x['score'] >= Profiles::SURE)); $codes = array_column(Clients::all(), null, 'id'); ?>
    <?php if ( $suggestions ) : ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Unir con los clientes de la oficina', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Estas razones sociales parecen de clientes que ya tienes en la oficina (reuniones, Chat, portal). Al unirlas, son el mismo cliente: sus facturas salen en su ficha y en su oficina.', 'mad-oficina'); ?></p>
        <table class="madof-table">
            <thead><tr><th><?php esc_html_e('Razón social', 'mad-oficina'); ?></th><th><?php esc_html_e('Cliente de la oficina', 'mad-oficina'); ?></th><th><?php esc_html_e('Por qué', 'mad-oficina'); ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $suggestions as $sg ) : ?>
                <tr>
                    <td><strong><?php echo esc_html($sg['profile']['legal_name']); ?></strong><?php if ( $sg['profile']['tax_id'] ) echo '<br><small>' . esc_html($sg['profile']['tax_id']) . '</small>'; ?></td>
                    <td><span class="madof-code"><?php echo esc_html($sg['client']['code']); ?></span> <?php echo esc_html($sg['client']['name']); ?></td>
                    <td><small><?php echo esc_html(implode(' · ', $sg['reasons'])); ?></small></td>
                    <td class="madof-row-actions"><?php echo AdminUi::form_open('billing_link', 'madof-inline'); ?>
                        <input type="hidden" name="profile_id" value="<?php echo (int) $sg['profile']['id']; ?>">
                        <input type="hidden" name="client_id" value="<?php echo (int) $sg['client']['id']; ?>">
                        <button class="madof-btn is-small <?php echo $sg['score'] >= Profiles::SURE ? '' : 'is-ghost'; ?>"><?php esc_html_e('Unir', 'mad-oficina'); ?></button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( $sure > 1 ) : ?>
        <?php echo AdminUi::form_open('billing_link_bulk', 'madof-inline'); ?><button class="madof-btn"><?php echo esc_html(sprintf(__('Unir las %d seguras', 'mad-oficina'), $sure)); ?></button></form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <p><a class="madof-btn is-ghost" href="<?php echo esc_url($base . '&tab=clients&new=1'); ?>"><?php esc_html_e('Nuevo cliente de facturación', 'mad-oficina'); ?></a></p>
    <table class="madof-table">
        <thead><tr><th><?php esc_html_e('Nombre fiscal', 'mad-oficina'); ?></th><th><?php esc_html_e('Cliente de la oficina', 'mad-oficina'); ?></th><th>NIF</th><th><?php esc_html_e('IVA / IRPF', 'mad-oficina'); ?></th><th><?php esc_html_e('Serie', 'mad-oficina'); ?></th><th><?php esc_html_e('Última factura', 'mad-oficina'); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $profiles as $p ) : ?>
            <tr>
                <td><a href="<?php echo esc_url($base . '&tab=clients&profile=' . $p['id']); ?>"><strong><?php echo esc_html($p['legal_name']); ?></strong></a><?php if ( $p['status'] === 'inactive' ) echo ' <small>(' . esc_html__('inactivo', 'mad-oficina') . ')</small>'; ?></td>
                <td><?php $oc = $p['client_id'] ? ($codes[$p['client_id']] ?? null) : null;
                    echo $oc ? '<a href="' . esc_url(\MAD_Oficina\Clients\Actions::client_url($oc['id'])) . '"><span class="madof-code">' . esc_html($oc['code']) . '</span> ' . esc_html($oc['name']) . '</a>' : '<small>' . esc_html__('sin unir', 'mad-oficina') . '</small>'; ?></td>
                <td><?php echo esc_html((string) $p['tax_id'] ?: '—'); ?></td>
                <td><?php echo esc_html(rtrim(rtrim(number_format($p['vat_rate'], 2, ',', ''), '0'), ',') . ' % / ' . rtrim(rtrim(number_format($p['irpf_rate'], 2, ',', ''), '0'), ',') . ' %'); ?></td>
                <td><?php echo esc_html($p['series']); ?></td>
                <td><?php echo esc_html($p['last_invoice'] ? mysql2date('j M Y', $p['last_invoice']) : '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ( $tab === 'import' ) : $last = get_option('madoficina_billing_last_import'); ?>
    <?php if ( is_array($preview) ) : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_billing_import_apply'); ?>
        <input type="hidden" name="action" value="madof_billing_import_apply">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Vista previa', 'mad-oficina'); ?></span>
            <p class="madof-lead"><?php echo esc_html(sprintf(__('%1$d filas: %2$d con número y %3$d sin número.', 'mad-oficina'), $preview['stats']['total'], $preview['stats']['numbered'], $preview['stats']['without_number'])); ?></p>
            <p><?php foreach ( $preview['stats']['years'] as $y => $n ) echo '<span class="madof-pill">' . (int) $y . ' · ' . (int) $n . '</span> '; ?></p>
            <?php if ( $preview['issues'] ) : ?>
                <h3><?php esc_html_e('Para revisar', 'mad-oficina'); ?></h3>
                <ul><?php foreach ( $preview['issues'] as $i ) echo '<li>' . esc_html($i) . '</li>'; ?></ul>
            <?php endif; ?>
            <?php if ( $preview['ar_candidates'] ) : ?>
                <h3><?php esc_html_e('Facturas sin número: ¿serie Argentina?', 'mad-oficina'); ?></h3>
                <p class="description"><?php esc_html_e('Marca los clientes que facturas desde la plataforma argentina. Sus facturas se guardan en la serie Argentina, fuera de los libros españoles. Las que no marques quedan como borradores para revisar.', 'mad-oficina'); ?></p>
                <?php foreach ( $preview['ar_candidates'] as $name => $c ) : ?>
                    <label class="madof-check"><input type="checkbox" name="argentina[]" value="<?php echo esc_attr($name); ?>" <?php checked($c['suggested']); ?>> <?php echo esc_html(sprintf(_n('%1$s (%2$d factura)', '%1$s (%2$d facturas)', $c['n'], 'mad-oficina'), $name, $c['n'])); ?></label>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Los borradores ya numerados pero sin enviar (como los de octubre) se importan sin número: al emitirlos recibirán el siguiente libre. Puedes importar otra vez el mismo archivo: no duplica nada.', 'mad-oficina'); ?></p>
            <button class="madof-btn"><?php esc_html_e('Importar', 'mad-oficina'); ?></button>
        </section>
    </form>
    <?php endif; ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Importar la hoja antigua', 'mad-oficina'); ?></span>
        <ol class="madof-steps">
            <li><?php esc_html_e('Abre «Facturas Autónomo Jessica Murillo» y ve a la pestaña «facturacion».', 'mad-oficina'); ?></li>
            <li><?php esc_html_e('Archivo → Descargar → Valores separados por comas (.csv).', 'mad-oficina'); ?></li>
            <li><?php esc_html_e('Súbelo aquí. Verás una vista previa antes de importar.', 'mad-oficina'); ?></li>
        </ol>
        <?php if ( $last ) : ?><p class="description"><?php echo esc_html(sprintf(__('Última importación: %1$s · %2$d facturas nuevas.', 'mad-oficina'), mysql2date('j M Y H:i', get_date_from_gmt($last['at'])), (int) $last['count']['imported'])); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="madof-form">
            <?php wp_nonce_field('madof_billing_import'); ?>
            <input type="hidden" name="action" value="madof_billing_import">
            <label><?php esc_html_e('Archivo CSV', 'mad-oficina'); ?><input type="file" name="csv" accept=".csv,text/csv" required></label>
            <button class="madof-btn"><?php esc_html_e('Ver vista previa', 'mad-oficina'); ?></button>
        </form>
    </section>

<?php elseif ( $tab === 'settings' ) : ?>
    <nav class="madof-filters" aria-label="<?php esc_attr_e('Otros ajustes', 'mad-oficina'); ?>">
        <a class="madof-pill" href="<?php echo esc_url($base . '&tab=import'); ?>"><?php esc_html_e('Importar histórico de facturas', 'mad-oficina'); ?></a>
        <a class="madof-pill" href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-gastos&tab=settings')); ?>"><?php esc_html_e('Lectura de gastos', 'mad-oficina'); ?></a>
        <a class="madof-pill" href="<?php echo esc_url($base . '&tab=taxes'); ?>"><?php esc_html_e('Cuota de autónomos', 'mad-oficina'); ?></a>
    </nav>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_billing_settings'); ?>
        <input type="hidden" name="action" value="madof_billing_settings">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Datos que salen en tus facturas', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre fiscal', 'mad-oficina'); ?><input type="text" name="issuer_name" value="<?php echo esc_attr($settings['issuer_name'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Debajo del nombre', 'mad-oficina'); ?><input type="text" name="issuer_tagline" value="<?php echo esc_attr($settings['issuer_tagline'] ?? ''); ?>" placeholder="Publicista especializada en marketing y diseño."></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Documento', 'mad-oficina'); ?><input type="text" name="issuer_tax_label" value="<?php echo esc_attr($settings['issuer_tax_label'] ?? 'N.I.E.'); ?>" placeholder="N.I.E."></label>
                <label><?php esc_html_e('Número', 'mad-oficina'); ?><input type="text" name="issuer_tax_id" value="<?php echo esc_attr($settings['issuer_tax_id'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Domicilio fiscal', 'mad-oficina'); ?><input type="text" name="issuer_address" value="<?php echo esc_attr($settings['issuer_address'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Localidad', 'mad-oficina'); ?><input type="text" name="issuer_city" value="<?php echo esc_attr($settings['issuer_city'] ?? ''); ?>"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Teléfono (contacto)', 'mad-oficina'); ?><input type="text" name="issuer_phone" value="<?php echo esc_attr($settings['issuer_phone'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Email (contacto)', 'mad-oficina'); ?><input type="text" name="issuer_email" value="<?php echo esc_attr($settings['issuer_email'] ?? ''); ?>"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Forma de pago', 'mad-oficina'); ?><input type="text" name="payment_method" value="<?php echo esc_attr($settings['payment_method'] ?? 'Transferencia bancaria'); ?>"></label>
                <label><?php esc_html_e('Banco', 'mad-oficina'); ?><input type="text" name="issuer_bank" value="<?php echo esc_attr($settings['issuer_bank'] ?? ''); ?>"></label>
                <label><?php esc_html_e('IBAN', 'mad-oficina'); ?><input type="text" name="issuer_iban" value="<?php echo esc_attr($settings['issuer_iban'] ?? ''); ?>"></label>
            </div>
            <label><?php esc_html_e('Nota bajo el pago (opcional)', 'mad-oficina'); ?><textarea name="payment_terms" rows="2"><?php echo esc_textarea($settings['payment_terms'] ?? ''); ?></textarea></label>
        </section>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Fechas y email de envío', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Día de facturación', 'mad-oficina'); ?><input type="number" min="1" max="31" name="billing_day" value="<?php echo (int) ($settings['billing_day'] ?? 28); ?>">
                    <small><?php esc_html_e('Para los clientes que no tienen su propio día (p. ej. DT). Si el mes es más corto, el último día.', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Días para pagar', 'mad-oficina'); ?><input type="number" min="0" max="365" name="payment_days" value="<?php echo (int) ($settings['payment_days'] ?? 15); ?>"></label>
            </div>
            <label><?php esc_html_e('Asunto', 'mad-oficina'); ?><input type="text" name="email_subject" value="<?php echo esc_attr($settings['email_subject'] ?? '') ?>" placeholder="<?php echo esc_attr(\MAD_Oficina\Billing\Issuing::DEFAULT_SUBJECT); ?>"></label>
            <label><?php esc_html_e('Mensaje', 'mad-oficina'); ?><textarea name="email_body" rows="8" placeholder="<?php echo esc_attr(\MAD_Oficina\Billing\Issuing::DEFAULT_BODY); ?>"><?php echo esc_textarea($settings['email_body'] ?? ''); ?></textarea>
                <small><?php esc_html_e('Puedes usar {numero}, {cliente}, {total}, {mes} y {vencimiento}. Vacío = el texto de ejemplo. Sale desde tu Gmail con el PDF adjunto; cada envío lo apruebas tú.', 'mad-oficina'); ?></small></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Libro MAD', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('La hoja «Libro MAD» de la unidad compartida refleja las facturas emitidas (pestaña Emitidas). Se actualiza sola al emitir y al marcar cobros. Para volcar todo el histórico la primera vez, pulsa aquí:', 'mad-oficina'); ?></p>
        <?php echo AdminUi::form_open('billing_libro_sync', 'madof-inline'); ?><button class="madof-btn is-ghost"><?php esc_html_e('Sincronizar todo el Libro MAD', 'mad-oficina'); ?></button></form>
    </section>

<?php else :
    $unpaid_tab = $tab === 'unpaid';
    $filters = ['year' => $year, 'status' => sanitize_key($_GET['status'] ?? ''), 'series' => sanitize_key(strtoupper($_GET['series'] ?? '')) ?: '', 'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? ''))];
    if ( $filters['series'] ) $filters['series'] = strtoupper($filters['series']);
    $for_client = isset($_GET['client']) ? Clients::get(absint($_GET['client'])) : null;
    if ( $for_client ) $filters = ['client_id' => $for_client['id'], 'year' => '', 'status' => '', 'series' => '', 'q' => '']; // every year
    $codes = array_column(Clients::all(), 'code', 'id');
    $unpaid = Invoices::unpaid();
    $list = $unpaid_tab ? array_reverse($unpaid) : Invoices::all($filters); ?>
    <?php if ( ! $years ) : ?>
        <section class="madof-card"><p class="madof-lead"><?php esc_html_e('Aún no hay facturas.', 'mad-oficina'); ?></p><p><a class="madof-btn" href="<?php echo esc_url($base . '&tab=import'); ?>"><?php esc_html_e('Importar el histórico', 'mad-oficina'); ?></a> <a class="madof-btn is-ghost" href="<?php echo esc_url($base . '&edit=new'); ?>"><?php esc_html_e('Nueva factura', 'mad-oficina'); ?></a></p></section>
    <?php else : ?>
    <p><a class="madof-btn" href="<?php echo esc_url($base . '&edit=new' . ($for_client ? '&client=' . (int) $for_client['id'] : '')); ?>"><?php esc_html_e('Nueva factura', 'mad-oficina'); ?></a></p>
    <?php if ( ! $for_client ) : ?>
    <form method="get" class="madof-filters">
        <input type="hidden" name="page" value="mad-oficina-facturacion">
        <select name="year"><?php foreach ( $years as $y ) echo '<option value="' . (int) $y . '" ' . selected($y, $year, false) . '>' . (int) $y . '</option>'; ?></select>
        <select name="status"><option value=""><?php esc_html_e('Todos los estados', 'mad-oficina'); ?></option><?php foreach ( Invoices::STATUSES as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($filters['status'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select>
        <select name="series"><option value=""><?php esc_html_e('Todas las series', 'mad-oficina'); ?></option><?php foreach ( Invoices::SERIES as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($filters['series'], $k, false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select>
        <input type="search" name="q" value="<?php echo esc_attr($filters['q']); ?>" placeholder="<?php esc_attr_e('Cliente o número', 'mad-oficina'); ?>">
        <button class="madof-btn is-small"><?php esc_html_e('Filtrar', 'mad-oficina'); ?></button>
    </form>
    <?php endif; ?>
    <?php if ( $for_client ) : ?><p class="madof-lead"><?php echo esc_html(sprintf(__('Facturas de %s, todos los años.', 'mad-oficina'), $for_client['name'])); ?> <a href="<?php echo esc_url($base); ?>"><?php esc_html_e('Ver todas', 'mad-oficina'); ?></a></p><?php endif; ?>
    <?php if ( $unpaid ) : ?><p class="madof-warn"><?php echo esc_html(sprintf(_n('Últimos 12 meses: %1$d factura sin cobrar (%2$s).', 'Últimos 12 meses: %1$d facturas sin cobrar (%2$s).', count($unpaid), 'mad-oficina'), count($unpaid), Money::format(array_sum(array_column($unpaid, 'total'))))); ?> <a href="<?php echo esc_url($base . '&tab=unpaid'); ?>"><?php esc_html_e('Ver cuáles', 'mad-oficina'); ?></a></p><?php endif; ?>
    <table class="madof-table">
        <thead><tr><th><?php esc_html_e('Número', 'mad-oficina'); ?></th><th><?php esc_html_e('Fecha', 'mad-oficina'); ?></th><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><th><?php esc_html_e('Base', 'mad-oficina'); ?></th><th><?php esc_html_e('Total', 'mad-oficina'); ?></th><th><?php esc_html_e('Estado', 'mad-oficina'); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $list as $inv ) : ?>
            <tr>
                <td><a href="<?php echo esc_url($base . ($inv['status'] === 'draft' && $inv['series'] !== 'AR' ? '&edit=' : '&invoice=') . $inv['id']); ?>"><strong><?php echo esc_html($inv['number'] ?: ($inv['series'] === 'AR' ? 'AR' : __('Borrador', 'mad-oficina'))); ?></strong></a></td>
                <td><?php echo esc_html(mysql2date('j M Y', $inv['issue_date'])); ?></td>
                <td><?php if ( $inv['client_id'] && isset($codes[$inv['client_id']]) ) echo '<a class="madof-code" href="' . esc_url($base . '&client=' . $inv['client_id']) . '">' . esc_html($codes[$inv['client_id']]) . '</a> '; ?><?php echo esc_html($inv['client_name']); ?><?php if ( $inv['notes'] ) echo ' <span title="' . esc_attr($inv['notes']) . '">' . AdminUi::icon('alert') . '</span>'; ?></td>
                <td><?php echo esc_html($money($inv['base'])); ?></td>
                <td><?php echo esc_html($money($inv['total'])); ?></td>
                <td><?php echo $status_pill($inv); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>
</div>
