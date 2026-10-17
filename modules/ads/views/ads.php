<?php
/**
 * Campañas: bitácora de cambios (con su porqué), campañas por cliente, cuentas y conexión.
 * @var string $tab @var array $settings @var \MAD_Oficina\Ads\Sync $sync
 */
use MAD_Oficina\Ads\Accounts;
use MAD_Oficina\Ads\Changes;
use MAD_Oficina\Ads\GoogleAds;
use MAD_Oficina\Ads\Meta;
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-campanas');
$clients = Clients::all();
$counts = Changes::counts();
$unlinked = count(Accounts::all(['linked' => false]));
$tabs = [
    'log' => $counts['pending'] ? sprintf(__('Bitácora (%d sin motivo)', 'mad-oficina'), $counts['pending']) : __('Bitácora', 'mad-oficina'),
    'campaigns' => __('Campañas', 'mad-oficina'),
    'accounts' => $unlinked ? sprintf(__('Cuentas (%d sin cliente)', 'mad-oficina'), $unlinked) : __('Cuentas', 'mad-oficina'),
    'meta' => __('Informes de Meta', 'mad-oficina'),
    'connection' => __('Conexión', 'mad-oficina'),
];
$name = \MAD_Oficina\Core\Agents\Characters::get('analista')['name'] ?? 'Nuria';
$when = fn($d) => date_i18n('j M Y', strtotime($d));
$platform = fn($p) => '<span class="madof-pill madof-ads-' . esc_attr($p) . '">' . esc_html(Accounts::PLATFORMS[$p] ?? $p) . '</span>';
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Sala de campañas', 'mad-oficina'), __('Campañas', 'mad-oficina'), sprintf(__('%s lee cada mañana (solo lectura) las campañas de Google Ads y Meta de tus clientes: métricas y todos los cambios. Las plataformas dicen qué cambió; aquí queda por qué.', 'mad-oficina'), $name)); ?>
    <nav class="madof-tabs" aria-label="<?php esc_attr_e('Secciones', 'mad-oficina'); ?>">
        <?php foreach ( $tabs as $k => $l ) : ?><a href="<?php echo esc_url($base . '&tab=' . $k); ?>" class="<?php echo $tab === $k ? 'is-active' : ''; ?>"><?php echo esc_html($l); ?></a><?php endforeach; ?>
    </nav>

<?php if ( $tab === 'log' ) :
    $f = [
        'client_id' => absint($_GET['client'] ?? 0), 'platform' => in_array($_GET['platform'] ?? '', ['google', 'meta'], true) ? $_GET['platform'] : '',
        'pending' => ! empty($_GET['pending']), 'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? '')),
        'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    ];
    $auto = ! empty($_GET['auto']);
    $rows = Changes::find(array_filter($f) + ($auto ? [] : ['automatic' => false]) + ['linked' => true], 400);
    // One block per client, campaign and day: a decision usually touches several fields at once.
    $groups = [];
    foreach ( $rows as $r ) $groups[$r['client_id'] . '|' . ($r['campaign_id'] ?: 'acc' . $r['account_id']) . '|' . substr($r['changed_at'], 0, 10)][] = $r;
    ?>
    <form method="get" class="madof-bank-filter">
        <input type="hidden" name="page" value="mad-oficina-campanas"><input type="hidden" name="tab" value="log">
        <select name="client" aria-label="<?php esc_attr_e('Cliente', 'mad-oficina'); ?>"><option value=""><?php esc_html_e('Todos los clientes', 'mad-oficina'); ?></option><?php foreach ( $clients as $c ) echo '<option value="' . (int) $c['id'] . '" ' . selected($f['client_id'], (int) $c['id'], false) . '>' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select>
        <select name="platform" aria-label="<?php esc_attr_e('Plataforma', 'mad-oficina'); ?>"><option value=""><?php esc_html_e('Google y Meta', 'mad-oficina'); ?></option><?php foreach ( Accounts::PLATFORMS as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($f['platform'], $k, false) . '>' . esc_html($l) . '</option>'; ?></select>
        <input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>" aria-label="<?php esc_attr_e('Desde', 'mad-oficina'); ?>">
        <input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>" aria-label="<?php esc_attr_e('Hasta', 'mad-oficina'); ?>">
        <input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" placeholder="<?php esc_attr_e('Buscar en cambios y motivos', 'mad-oficina'); ?>">
        <label class="madof-check"><input type="checkbox" name="pending" value="1" <?php checked($f['pending']); ?>> <?php esc_html_e('Solo sin motivo', 'mad-oficina'); ?></label>
        <label class="madof-check"><input type="checkbox" name="auto" value="1" <?php checked($auto); ?>> <?php esc_html_e('Ver también los automáticos', 'mad-oficina'); ?></label>
        <button class="madof-btn is-small"><?php esc_html_e('Filtrar', 'mad-oficina'); ?></button>
    </form>
    <?php if ( ! $groups ) : ?>
        <p class="madof-empty"><?php echo esc_html(Accounts::all(['linked' => true]) ? __('Nada con estos filtros.', 'mad-oficina') : __('Todavía no hay cuentas vinculadas: empieza por la pestaña Conexión.', 'mad-oficina')); ?></p>
    <?php else : ?>
    <ol class="madof-ads-log">
    <?php foreach ( $groups as $g ) : $first = $g[0];
        $reasons = array_values(array_unique(array_filter(array_column($g, 'reason'))));
        $client_vis = (bool) array_filter($g, fn($r) => $r['visibility'] === 'client');
        $effect = Changes::effect($first);
        $actors = array_values(array_unique(array_filter(array_column($g, 'actor')))); ?>
        <li class="madof-card madof-ads-change <?php echo $reasons ? 'has-reason' : ''; ?>">
            <header>
                <span class="madof-kicker"><?php echo esc_html($when($first['changed_at']) . ' · ' . $first['client_code']); ?></span>
                <?php echo $platform($first['platform']); ?>
                <strong><?php echo esc_html($first['campaign_name'] ?: __('Cuenta', 'mad-oficina')); ?></strong>
                <?php if ( $first['automatic'] ) echo '<span class="madof-pill">' . esc_html__('automático', 'mad-oficina') . '</span>'; ?>
            </header>
            <ul>
                <?php foreach ( $g as $r ) : ?>
                    <li><?php echo esc_html($r['summary']); ?> <small class="madof-muted"><?php echo esc_html(trim(($r['changed_at'] && substr($r['changed_at'], 11, 5) !== '00:00' ? substr($r['changed_at'], 11, 5) : '') . ($r['source'] === 'sync' ? ' · ' . __('visto al comparar con el día anterior', 'mad-oficina') : ''), ' ·')); ?></small></li>
                <?php endforeach; ?>
            </ul>
            <p class="madof-muted"><?php echo esc_html(($actors ? sprintf(__('Lo hizo: %s', 'mad-oficina'), implode(', ', $actors)) : '') . ($effect ? ($actors ? ' · ' : '') . sprintf(__('7 días antes → 7 días después: %s (comparación de periodos, no prueba de causa)', 'mad-oficina'), $effect) : '')); ?></p>
            <?php if ( ! $first['automatic'] ) : ?>
            <form class="madof-ads-reason" data-ids="<?php echo esc_attr(implode(',', array_column($g, 'id'))); ?>">
                <label><span class="madof-kicker"><?php esc_html_e('¿Por qué?', 'mad-oficina'); ?></span>
                    <textarea rows="2" name="reason" placeholder="<?php esc_attr_e('p. ej. convertía a 6 € y sobraba presupuesto; prueba de 2 semanas', 'mad-oficina'); ?>"><?php echo esc_textarea(implode(' / ', $reasons)); ?></textarea></label>
                <label class="madof-check"><input type="checkbox" name="visibility" value="client" <?php checked($client_vis); ?>> <?php esc_html_e('Puede salir en el informe del cliente', 'mad-oficina'); ?></label>
                <button class="madof-btn is-small"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
                <span class="madof-ads-saved" role="status" aria-live="polite"></span>
                <?php if ( $first['reason_source'] && $first['reason_source'] !== 'manual' ) echo '<small class="madof-muted">' . esc_html(sprintf(__('Motivo tomado de: %s', 'mad-oficina'), ['chat' => __('lo que le dijiste en Chat', 'mad-oficina'), 'meeting' => __('el acta de una reunión', 'mad-oficina')][$first['reason_source']] ?? $first['reason_source'])) . '</small>'; ?>
            </form>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ol>
    <script>
    (function () {
      var nonce = <?php echo wp_json_encode(wp_create_nonce('madof_ads_reason')); ?>, ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      document.addEventListener('submit', function (e) {
        var f = e.target.closest('.madof-ads-reason');
        if (!f) return;
        e.preventDefault();
        var out = f.querySelector('.madof-ads-saved'), fd = new FormData();
        fd.append('action', 'madof_ads_reason'); fd.append('_ajax_nonce', nonce); fd.append('ids', f.dataset.ids);
        fd.append('reason', f.reason.value); fd.append('visibility', f.visibility.checked ? 'client' : 'internal');
        out.textContent = '…';
        fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
          out.textContent = r.success ? r.data.message : ((r.data && r.data.message) || 'Error');
          f.closest('.madof-ads-change').classList.toggle('has-reason', !!f.reason.value.trim());
        }, function () { out.textContent = 'Error'; });
      });
    })();
    </script>
    <?php endif; ?>

<?php elseif ( $tab === 'campaigns' ) :
    $linked_clients = array_values(array_filter($clients, fn($c) => Accounts::all(['client_id' => (int) $c['id']])));
    $cid = absint($_GET['client'] ?? 0) ?: ( $linked_clients[0]['id'] ?? 0 ); ?>
    <?php if ( ! $linked_clients ) : ?>
        <p class="madof-empty"><?php esc_html_e('Ningún cliente tiene cuentas vinculadas todavía (pestaña Cuentas).', 'mad-oficina'); ?></p>
    <?php else : ?>
    <nav class="madof-filters"><?php foreach ( $linked_clients as $c ) : ?><a class="madof-pill <?php echo (int) $c['id'] === (int) $cid ? 'is-alert' : ''; ?>" href="<?php echo esc_url($base . '&tab=campaigns&client=' . (int) $c['id']); ?>"><?php echo esc_html($c['code']); ?></a><?php endforeach; ?></nav>
    <?php $list = Accounts::campaigns_of($cid); ?>
    <p class="description"><?php esc_html_e('Últimos 30 días. Las notas («cómo se creó la campaña») son internas y las usa la analista para sus respuestas y para el informe previo a cada reunión.', 'mad-oficina'); ?></p>
    <?php if ( ! $list ) : ?><p class="madof-empty"><?php esc_html_e('Aún no hay campañas leídas para este cliente.', 'mad-oficina'); ?></p><?php else : ?>
    <table class="madof-table madof-ads-campaigns"><thead><tr>
        <th><?php esc_html_e('Campaña', 'mad-oficina'); ?></th><th><?php esc_html_e('Estado', 'mad-oficina'); ?></th><th><?php esc_html_e('Presupuesto', 'mad-oficina'); ?></th>
        <th class="num"><?php esc_html_e('Gasto', 'mad-oficina'); ?></th><th class="num"><?php esc_html_e('Clics', 'mad-oficina'); ?></th><th class="num"><?php esc_html_e('Conv.', 'mad-oficina'); ?></th><th class="num"><?php esc_html_e('CPA', 'mad-oficina'); ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ( $list as $c ) : $conv = (float) $c['conversions']; ?>
        <tr>
            <td><?php echo $platform($c['platform']); ?> <strong><?php echo esc_html($c['name']); ?></strong><br><small class="madof-muted"><?php echo esc_html(trim(str_replace('_', ' ', $c['objective']) . ($c['bidding'] ? ' · ' . str_replace('_', ' ', $c['bidding']) : '') . ($c['start_date'] ? ' · ' . $when($c['start_date']) . ($c['end_date'] ? ' → ' . $when($c['end_date']) : '') : ''), ' ·')); ?></small></td>
            <td><?php echo esc_html(__(Changes::STATUSES[$c['status']] ?? $c['status'], 'mad-oficina')); ?></td>
            <td class="madof-nowrap"><?php echo $c['budget'] !== null ? esc_html(Changes::money($c['budget']) . ($c['budget_period'] === 'day' ? __('/día', 'mad-oficina') : ' ' . __('total', 'mad-oficina'))) : '—'; ?></td>
            <td class="num"><?php echo esc_html(Changes::money($c['spend'])); ?></td>
            <td class="num"><?php echo esc_html(number_format_i18n((int) $c['clicks'])); ?></td>
            <td class="num"><?php echo esc_html(number_format_i18n($conv, floor($conv) == $conv ? 0 : 1)); ?></td>
            <td class="num"><?php echo $conv > 0 ? esc_html(Changes::money((float) $c['spend'] / $conv)) : '—'; ?></td>
            <td><a href="<?php echo esc_url($base . '&tab=log&client=' . (int) $cid . '&q=' . rawurlencode($c['name'])); ?>"><?php esc_html_e('Cambios', 'mad-oficina'); ?></a></td>
        </tr>
        <tr class="madof-ads-notes"><td colspan="8">
            <details><summary><?php echo esc_html($c['notes'] ? __('Cómo se creó (notas)', 'mad-oficina') : __('Añadir cómo se creó la campaña…', 'mad-oficina')); ?></summary>
            <?php echo AdminUi::form_open('ads_notes', 'madof-form'); ?><input type="hidden" name="campaign" value="<?php echo (int) $c['id']; ?>">
                <textarea name="notes" rows="3"><?php echo esc_textarea((string) $c['notes']); ?></textarea>
                <button class="madof-btn is-small"><?php esc_html_e('Guardar notas', 'mad-oficina'); ?></button>
            </form></details>
            <?php if ( $c['notes'] ) echo '<p class="madof-muted">' . nl2br(esc_html(wp_html_excerpt($c['notes'], 220, '…'))) . '</p>'; ?>
        </td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; endif; ?>

<?php elseif ( $tab === 'accounts' ) : $accounts = Accounts::all(); ?>
    <p class="description"><?php esc_html_e('Las cuentas que ven tus conexiones. Vincula cada una a su cliente: solo las vinculadas se leen cada mañana. Las que no son de clientes (pruebas, antiguas) déjalas sin cliente.', 'mad-oficina'); ?></p>
    <div class="madof-ads-find">
    <?php foreach ( Accounts::PLATFORMS as $p => $label ) : $why = $sync->missing($p); ?>
        <div>
            <?php echo AdminUi::form_open('ads_discover', 'madof-inline'); ?><input type="hidden" name="platform" value="<?php echo esc_attr($p); ?>">
                <button class="madof-btn is-small" <?php disabled($why !== ''); ?>><?php echo esc_html(sprintf(__('Buscar cuentas en %s', 'mad-oficina'), $label)); ?></button></form>
            <small class="<?php echo $why ? 'madof-muted' : ''; ?>"><?php echo $why ? esc_html($why) : AdminUi::icon('check') . ' ' . esc_html__('Lista.', 'mad-oficina'); ?></small>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if ( ! $accounts ) : ?><p class="madof-empty"><?php esc_html_e('Aún no hay cuentas. Configura la conexión y pulsa «Buscar cuentas».', 'mad-oficina'); ?></p><?php else : ?>
    <?php echo AdminUi::form_open('ads_link', 'madof-form'); ?>
    <table class="madof-table"><thead><tr><th><?php esc_html_e('Cuenta', 'mad-oficina'); ?></th><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><th><?php esc_html_e('Última lectura', 'mad-oficina'); ?></th><th></th></tr></thead><tbody>
    <?php foreach ( $accounts as $a ) : ?>
        <tr>
            <td><?php echo $platform($a['platform']); ?> <?php echo esc_html($a['name'] ?: $a['external_id']); ?><br><small class="madof-muted"><?php echo esc_html($a['external_id'] . ' · ' . $a['currency'] . ($a['status'] !== 'active' ? ' · ' . __('inactiva', 'mad-oficina') : '')); ?></small></td>
            <td><select name="client[<?php echo (int) $a['id']; ?>]" aria-label="<?php esc_attr_e('Cliente', 'mad-oficina'); ?>"><option value="0"><?php esc_html_e('— Sin cliente (no se lee) —', 'mad-oficina'); ?></option><?php foreach ( $clients as $c ) echo '<option value="' . (int) $c['id'] . '" ' . selected((int) $a['client_id'], (int) $c['id'], false) . '>' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select></td>
            <td><?php echo $a['last_sync'] ? esc_html(date_i18n('j M H:i', strtotime($a['last_sync']))) : '—'; ?><?php if ( $a['last_error'] ) echo '<br><small class="madof-warn">' . esc_html($a['last_error']) . '</small>'; ?></td>
            <td><?php if ( $a['client_id'] ) : ?><button class="madof-btn is-small is-ghost" form="madof-ads-sync-<?php echo (int) $a['id']; ?>"><?php esc_html_e('Leer ahora', 'mad-oficina'); ?></button><?php endif; ?></td>
        </tr>
    <?php endforeach; ?></tbody></table>
    <button class="madof-btn"><?php esc_html_e('Guardar vínculos', 'mad-oficina'); ?></button>
    </form>
    <?php foreach ( $accounts as $a ) if ( $a['client_id'] ) echo str_replace('<form ', '<form id="madof-ads-sync-' . (int) $a['id'] . '" ', AdminUi::form_open('ads_sync', 'madof-inline')) . '<input type="hidden" name="account" value="' . (int) $a['id'] . '"></form>'; ?>
    <?php endif; ?>

<?php elseif ( $tab === 'meta' ) :
    $imported = array_values(array_filter(Accounts::all(['platform' => 'meta']), fn($a) => $a['last_sync'])); ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Subir informes del Administrador de anuncios', 'mad-oficina'); ?></span>
        <p><?php esc_html_e('Mientras Meta no nos deje leer por la API, sus métricas llegan así: exportas el informe y lo subes aquí. Rellena las campañas y métricas de cada cliente igual que la conexión; si el presupuesto o el estado de una campaña cambia entre un informe y el siguiente, aparece en la bitácora para que apuntes por qué.', 'mad-oficina'); ?></p>
        <?php echo str_replace('<form ', '<form enctype="multipart/form-data" ', AdminUi::form_open('ads_meta_import', 'madof-form')); ?>
            <div class="madof-cols">
                <label><?php esc_html_e('Informes (CSV o Excel; puedes elegir varios)', 'mad-oficina'); ?><input type="file" name="reports[]" accept=".csv,.xlsx,.xls,.txt" multiple required></label>
                <label><?php esc_html_e('Cliente', 'mad-oficina'); ?><select name="client"><option value="0"><?php esc_html_e('Lo deduzco de la cuenta del informe', 'mad-oficina'); ?></option><?php foreach ( $clients as $c ) echo '<option value="' . (int) $c['id'] . '">' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select>
                    <small><?php esc_html_e('Hace falta si el informe no trae el identificador de la cuenta, o si la cuenta aún no está vinculada y su nombre no lleva el del cliente.', 'mad-oficina'); ?></small></label>
            </div>
            <button class="madof-btn"><?php esc_html_e('Subir', 'mad-oficina'); ?></button>
        </form>
        <details class="madof-more" open><summary><?php esc_html_e('Cómo exportar el informe (una vez; luego se reutiliza)', 'mad-oficina'); ?></summary>
            <ol>
                <li><?php esc_html_e('En el Administrador de anuncios, pestaña Campañas, elige el periodo (por ejemplo, los últimos 7 o 30 días; si se solapan con un informe anterior no se duplica nada).', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Desglose → Por tiempo → Día.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Columnas → Personalizar: deja Nombre de la campaña, Entrega, Presupuesto, Resultados, Importe gastado, Impresiones y Clics en el enlace; añade Nombre e Identificador de la cuenta y de la campaña. Guárdalo como columnas predeterminadas «MAD Oficina».', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Informes → Exportar datos de la tabla → CSV o Excel. Con varias cuentas, puedes hacerlo desde la vista de todas las cuentas del negocio y subir un solo archivo.', 'mad-oficina'); ?></li>
            </ol>
        </details>
    </section>
    <?php if ( $imported ) : ?>
    <table class="madof-table"><thead><tr><th><?php esc_html_e('Cuenta de Meta', 'mad-oficina'); ?></th><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><th><?php esc_html_e('Último informe', 'mad-oficina'); ?></th></tr></thead><tbody>
    <?php global $wpdb; foreach ( $imported as $a ) : $last = $wpdb->get_var($wpdb->prepare('SELECT MAX(m.date) FROM ' . \MAD_Oficina\Core\Schema::table('ad_metrics_daily') . ' m JOIN ' . \MAD_Oficina\Core\Schema::table('ad_campaigns') . ' c ON c.id = m.campaign_id WHERE c.account_id = %d', $a['id'])); $cl = $a['client_id'] ? Clients::get((int) $a['client_id']) : null; ?>
        <tr><td><?php echo esc_html($a['name']); ?></td><td><?php echo esc_html($cl ? $cl['code'] . ' · ' . $cl['name'] : '—'); ?></td>
            <td><?php echo $last ? esc_html(sprintf(__('datos hasta el %s', 'mad-oficina'), date_i18n('j M Y', strtotime($last)))) : '—'; ?><?php if ( $last && strtotime($last) < strtotime('-8 days') ) echo ' <span class="madof-pill is-alert">' . esc_html__('desactualizado', 'mad-oficina') . '</span>'; ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>

<?php else : $g = $sync->google(); $m = $sync->meta(); ?>
    <?php echo AdminUi::form_open('ads_settings', 'madof-form'); ?>
    <section class="madof-card">
        <span class="madof-kicker">Google Ads</span>
        <p><?php echo $g->ready() ? AdminUi::icon('check') . ' ' . esc_html__('Lista para leer.', 'mad-oficina') : esc_html($g->missing()); ?></p>
        <div class="madof-cols">
            <label><?php esc_html_e('ID de tu cuenta de administrador (MCC)', 'mad-oficina'); ?><input type="text" name="google_mcc" value="<?php echo esc_attr((string) ($settings['google_mcc'] ?? '')); ?>" placeholder="123-456-7890"></label>
            <label><?php esc_html_e('Developer token', 'mad-oficina'); ?><input type="password" name="google_dev_token" autocomplete="off" placeholder="<?php echo esc_attr($g->token() !== '' ? __('Guardado (escribe para cambiarlo)', 'mad-oficina') : ''); ?>"></label>
            <label><?php esc_html_e('Versión de la API', 'mad-oficina'); ?><input type="text" name="google_version" value="<?php echo esc_attr((string) ($settings['google_version'] ?? GoogleAds::DEFAULT_VERSION)); ?>"></label>
        </div>
        <?php if ( $g->token() !== '' ) : ?><label class="madof-check"><input type="checkbox" name="forget_google_dev_token" value="1"> <?php esc_html_e('Borrar el developer token', 'mad-oficina'); ?></label><?php endif; ?>
        <details class="madof-more"><summary><?php esc_html_e('Cómo conseguir el developer token', 'mad-oficina'); ?></summary>
            <ol>
                <li><?php esc_html_e('Entra en tu cuenta de administrador (MCC) → Administración → Centro de API.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Rellena el formulario. El token sale en «acceso de prueba», que no lee cuentas reales.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Pide el «acceso básico» (Basic access). Uso: herramienta interna de la agencia, solo lectura de campañas, métricas e historial de cambios de las cuentas de la MCC. Suele tardar unos días.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Pega aquí el token y el ID de la MCC, guarda y reconecta Google en Conexiones (te pedirá el permiso de Google Ads una sola vez).', 'mad-oficina'); ?></li>
            </ol>
        </details>
    </section>
    <section class="madof-card">
        <span class="madof-kicker">Meta</span>
        <p><?php echo $m->ready() ? AdminUi::icon('check') . ' ' . esc_html__('Token guardado.', 'mad-oficina') : esc_html__('Sin configurar.', 'mad-oficina'); ?></p>
        <div class="madof-cols">
            <label><?php esc_html_e('Token de acceso (ads_read)', 'mad-oficina'); ?><input type="password" name="meta_token" autocomplete="off" placeholder="<?php echo esc_attr($m->ready() ? __('Guardado (escribe para cambiarlo)', 'mad-oficina') : ''); ?>"></label>
            <label><?php esc_html_e('Versión de la API', 'mad-oficina'); ?><input type="text" name="meta_version" value="<?php echo esc_attr((string) ($settings['meta_version'] ?? Meta::DEFAULT_VERSION)); ?>"></label>
        </div>
        <?php if ( $m->ready() ) : ?><label class="madof-check"><input type="checkbox" name="forget_meta_token" value="1"> <?php esc_html_e('Borrar el token', 'mad-oficina'); ?></label><?php endif; ?>
        <details class="madof-more"><summary><?php esc_html_e('Cómo conseguir el token (cuentas en las que sois socios)', 'mad-oficina'); ?></summary>
            <ol>
                <li><?php esc_html_e('En developers.facebook.com crea una app de tipo «Empresa» vinculada al Business Manager de MAD y añade el producto «API de marketing».', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('En el Business Manager de MAD → Usuarios → Usuarios del sistema: crea uno (rol empleado) y asígnale las cuentas publicitarias de los clientes con permiso de ver rendimiento. Las cuentas en las que sois socios también aparecen ahí para asignarlas.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Genera el token de ese usuario del sistema para tu app con el permiso ads_read (sin caducidad) y pégalo aquí.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Pulsa «Buscar cuentas» en la pestaña Cuentas. Si alguna cuenta de socio da error de permisos, dímelo: puede que Meta pida acceso avanzado para esa cuenta y lo vemos.', 'mad-oficina'); ?></li>
            </ol>
        </details>
    </section>
    <p class="description"><?php esc_html_e('Los tokens se guardan cifrados. Todo es solo lectura: nadie de la oficina cambia tus campañas.', 'mad-oficina'); ?></p>
    <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
    </form>
<?php endif; ?>
</div>
