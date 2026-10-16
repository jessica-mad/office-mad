<?php
/**
 * Finanzas → Banco: upload a statement; what did not match, with what to do about it.
 * Included from billing.php ($base available).
 */
use MAD_Oficina\Billing\Bank;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$view = in_array($_GET['view'] ?? '', ['unmatched', 'matched', 'ignored', 'invoices', 'expenses', 'rules'], true) ? $_GET['view'] : 'unmatched';
$counts = Bank::counts();
[$from, $to] = Bank::covered();
$uncollected = Bank::uncollected();
$unpaid = Bank::unpaid_expenses();
$views = [
    'unmatched' => sprintf(__('Sin cuadrar (%d)', 'mad-oficina'), $counts['unmatched']),
    'invoices'  => sprintf(__('Facturas sin cobro (%d)', 'mad-oficina'), count($uncollected)),
    'expenses'  => sprintf(__('Gastos sin pago (%d)', 'mad-oficina'), count($unpaid)),
    'matched'   => sprintf(__('Cuadrados (%d)', 'mad-oficina'), $counts['matched']),
    'ignored'   => sprintf(__('Sin factura (%d)', 'mad-oficina'), $counts['ignored']),
    'rules'     => sprintf(__('Lo que ya me enseñaste (%d)', 'mad-oficina'), count(Bank::rules())),
];
$url = $base . '&tab=bank&view=';
$pattern = function($concept){
    $words = array_slice(array_values(array_filter(explode(' ', Bank::norm($concept)), fn($w) => ! ctype_digit($w) && strlen($w) > 2)), 0, 3);
    return implode(' ', $words);
};
?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Subir extracto', 'mad-oficina'); ?></span>
    <?php echo str_replace('<form ', '<form enctype="multipart/form-data" ', AdminUi::form_open('billing_bank_upload', 'madof-form')); ?>
        <div class="madof-cols">
            <label><?php esc_html_e('Archivo del banco', 'mad-oficina'); ?><input type="file" name="statement" accept=".xlsx,.xls,.csv,.txt,.n43,.aeb,.q43" required>
                <small><?php esc_html_e('Excel (.xlsx), CSV o Norma 43. Puedes subir extractos que se solapen: los movimientos repetidos no se duplican.', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('Cuenta (opcional)', 'mad-oficina'); ?><input type="text" name="account" placeholder="<?php esc_attr_e('p. ej. Sabadell …1234', 'mad-oficina'); ?>"></label>
        </div>
        <button class="madof-btn"><?php esc_html_e('Subir y cruzar', 'mad-oficina'); ?></button>
    </form>
    <?php if ( $from ) : ?>
        <p class="description"><?php echo esc_html(sprintf(__('Extractos cargados del %1$s al %2$s. Lo que cuadra se apunta solo (y las facturas emitidas se marcan cobradas con la fecha del banco); si algo no cuadra, te aviso por Chat con el enlace a esta página.', 'mad-oficina'), date_i18n('j/m/Y', strtotime($from)), date_i18n('j/m/Y', strtotime($to)))); ?></p>
    <?php endif; ?>
</section>

<nav class="madof-filters" aria-label="<?php esc_attr_e('Vista', 'mad-oficina'); ?>">
    <?php foreach ( $views as $k => $l ) : ?><a class="madof-pill <?php echo $k === $view ? 'is-on' : ''; ?>" href="<?php echo esc_url($url . $k); ?>"><?php echo esc_html($l); ?></a><?php endforeach; ?>
    <?php echo AdminUi::form_open('billing_bank_action', 'madof-inline'); ?><input type="hidden" name="do" value="rematch"><button class="madof-link"><?php esc_html_e('Volver a cruzar lo pendiente', 'mad-oficina'); ?></button></form>
</nav>

<section class="madof-card">
<?php if ( in_array($view, ['unmatched', 'matched', 'ignored'], true) ) :
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $sign = in_array($_GET['sign'] ?? '', ['in', 'out'], true) ? $_GET['sign'] : '';
    $rows = Bank::movements($view, 300, $q, $sign);
    $bulk = $view === 'unmatched'; ?>
    <form method="get" class="madof-inline madof-bank-filter">
        <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? ''); ?>"><input type="hidden" name="tab" value="bank"><input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
        <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Buscar en el concepto (p. ej. transferencia, tu nombre…)', 'mad-oficina'); ?>" aria-label="<?php esc_attr_e('Buscar', 'mad-oficina'); ?>">
        <select name="sign" aria-label="<?php esc_attr_e('Entradas o salidas', 'mad-oficina'); ?>"><option value=""><?php esc_html_e('Entradas y salidas', 'mad-oficina'); ?></option><option value="in" <?php selected($sign, 'in'); ?>><?php esc_html_e('Solo entradas', 'mad-oficina'); ?></option><option value="out" <?php selected($sign, 'out'); ?>><?php esc_html_e('Solo salidas', 'mad-oficina'); ?></option></select>
        <button class="madof-btn is-small is-ghost"><?php esc_html_e('Filtrar', 'mad-oficina'); ?></button>
        <?php if ( $q || $sign ) : ?><a href="<?php echo esc_url($url . $view); ?>"><?php esc_html_e('Quitar filtro', 'mad-oficina'); ?></a><?php endif; ?>
    </form>
    <?php if ( $bulk && $rows ) : ?>
    <?php echo str_replace('<form ', '<form id="madof-bank-bulk" ', AdminUi::form_open('billing_bank_action', 'madof-bank-bulk')); ?>
        <input type="hidden" name="do" value="bulk">
        <label class="madof-check"><input type="checkbox" id="madof-bank-all"> <?php esc_html_e('Seleccionar todos', 'mad-oficina'); ?> <span class="madof-muted" id="madof-bank-count" aria-live="polite"></span></label>
        <select name="kind" aria-label="<?php esc_attr_e('Qué son', 'mad-oficina'); ?>"><?php foreach ( Bank::KINDS as $k => $l ) echo '<option value="' . esc_attr($k) . '"' . selected($k, 'own', false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select>
        <input type="text" name="label" placeholder="<?php esc_attr_e('Nota (opcional)', 'mad-oficina'); ?>">
        <label class="madof-check"><input type="checkbox" name="remember" value="1" <?php checked($q !== ''); ?>> <?php esc_html_e('Y a partir de ahora, los que contengan:', 'mad-oficina'); ?></label>
        <input type="text" name="pattern" value="<?php echo esc_attr(Bank::norm($q)); ?>" placeholder="<?php esc_attr_e('texto', 'mad-oficina'); ?>">
        <button class="madof-btn is-small"><?php esc_html_e('Marcar los seleccionados', 'mad-oficina'); ?></button>
    </form>
    <script>
    (function(){
        var all = document.getElementById('madof-bank-all'), count = document.getElementById('madof-bank-count');
        var boxes = function(){ return Array.prototype.slice.call(document.querySelectorAll('.madof-bank-pick')); };
        var update = function(){ var n = boxes().filter(function(b){ return b.checked; }).length; count.textContent = n ? '(' + n + ')' : ''; };
        all.addEventListener('change', function(){ boxes().forEach(function(b){ b.checked = all.checked; }); update(); });
        document.addEventListener('change', function(e){ if ( e.target.classList && e.target.classList.contains('madof-bank-pick') ) update(); });
    })();
    </script>
    <?php endif; ?>
    <?php if ( ! $rows ) : ?>
        <p><?php echo esc_html($view === 'unmatched' ? ($from ? __('Todo cuadra. Nada pendiente.', 'mad-oficina') : __('Sube tu primer extracto.', 'mad-oficina')) : __('Nada por aquí.', 'mad-oficina')); ?></p>
    <?php else : ?>
    <table class="madof-table madof-bank"><thead><tr>
        <?php if ( $bulk ) : ?><th scope="col"><span class="screen-reader-text"><?php esc_html_e('Seleccionar', 'mad-oficina'); ?></span></th><?php endif; ?>
        <th scope="col"><?php esc_html_e('Fecha', 'mad-oficina'); ?></th><th scope="col"><?php esc_html_e('Concepto', 'mad-oficina'); ?></th>
        <th scope="col" class="num"><?php esc_html_e('Importe', 'mad-oficina'); ?></th><th scope="col"><?php echo esc_html($view === 'unmatched' ? __('Qué hago', 'mad-oficina') : __('Con qué va', 'mad-oficina')); ?></th>
    </tr></thead><tbody>
    <?php foreach ( $rows as $m ) : ?>
        <tr>
            <?php if ( $bulk ) : ?><td><input type="checkbox" class="madof-bank-pick" name="ids[]" value="<?php echo (int) $m['id']; ?>" form="madof-bank-bulk" aria-label="<?php esc_attr_e('Seleccionar', 'mad-oficina'); ?>"></td><?php endif; ?>
            <td><?php echo esc_html(date_i18n('j/m/Y', strtotime($m['date']))); ?><?php if ( $m['account'] ) echo '<br><small class="madof-muted">' . esc_html($m['account']) . '</small>'; ?></td>
            <td><?php echo esc_html($m['concept']); ?><?php if ( $m['note'] && $view === 'unmatched' ) echo '<br><small>' . esc_html($m['note']) . '</small>'; ?></td>
            <td class="num <?php echo (float) $m['amount'] < 0 ? 'is-out' : 'is-in'; ?>"><strong><?php echo esc_html(Money::format($m['amount'])); ?></strong></td>
            <td>
            <?php if ( $view === 'unmatched' ) : $cands = Bank::candidates($m); ?>
                <?php if ( $cands ) : ?>
                <?php echo AdminUi::form_open('billing_bank_action', 'madof-inline'); ?>
                    <input type="hidden" name="movement" value="<?php echo (int) $m['id']; ?>"><input type="hidden" name="do" value="link">
                    <select name="target" required aria-label="<?php esc_attr_e('Con qué va', 'mad-oficina'); ?>"><option value=""><?php esc_html_e('Elige con qué va…', 'mad-oficina'); ?></option><?php foreach ( $cands as $c ) echo '<option value="' . esc_attr($c['type'] . ':' . $c['id']) . '">' . esc_html($c['label']) . '</option>'; ?></select>
                    <button class="madof-btn is-small"><?php esc_html_e('Vincular', 'mad-oficina'); ?></button>
                </form>
                <?php endif; ?>
                <details class="madof-more"><summary><?php esc_html_e('No lleva factura…', 'mad-oficina'); ?></summary>
                <?php echo AdminUi::form_open('billing_bank_action', 'madof-form'); ?>
                    <input type="hidden" name="movement" value="<?php echo (int) $m['id']; ?>"><input type="hidden" name="do" value="ignore">
                    <select name="kind"><?php foreach ( Bank::KINDS as $k => $l ) echo '<option value="' . esc_attr($k) . '">' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select>
                    <input type="text" name="label" placeholder="<?php esc_attr_e('Nota (p. ej. comisión del banco)', 'mad-oficina'); ?>">
                    <label class="madof-check"><input type="checkbox" name="remember" value="1" checked> <?php esc_html_e('Recordarlo para los movimientos que contengan:', 'mad-oficina'); ?></label>
                    <input type="text" name="pattern" value="<?php echo esc_attr($pattern($m['concept'])); ?>">
                    <button class="madof-btn is-small"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
                </form></details>
            <?php else : ?>
                <?php echo esc_html($m['note'] ?: '—'); ?>
                <?php echo AdminUi::form_open('billing_bank_action', 'madof-inline'); ?><input type="hidden" name="movement" value="<?php echo (int) $m['id']; ?>"><input type="hidden" name="do" value="unlink"><input type="hidden" name="view" value="<?php echo esc_attr($view); ?>"><button class="madof-link"><?php esc_html_e('Deshacer', 'mad-oficina'); ?></button></form>
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>

<?php elseif ( $view === 'invoices' ) : ?>
    <p class="description"><?php esc_html_e('Facturas emitidas (sin las de Argentina) que no están cobradas y cuyo dinero no aparece en los extractos. Si te han pagado de otra forma, márcalas como cobradas en la factura.', 'mad-oficina'); ?></p>
    <?php if ( ! $uncollected ) : ?><p><?php esc_html_e('Ninguna: todo lo facturado ha llegado.', 'mad-oficina'); ?></p><?php else : ?>
    <table class="madof-table"><tbody>
    <?php foreach ( $uncollected as $i ) : $late = $i['due_date'] && $i['due_date'] < current_time('Y-m-d'); ?>
        <tr><td><a href="<?php echo esc_url($base . '&invoice=' . (int) $i['id']); ?>"><?php echo esc_html($i['number']); ?></a></td><td><?php echo esc_html($i['client_name']); ?></td>
            <td><?php echo esc_html(date_i18n('j/m/Y', strtotime($i['issue_date']))); ?><?php if ( $late ) echo ' <span class="madof-pill is-alert">' . esc_html__('Vencida', 'mad-oficina') . '</span>'; ?></td>
            <td class="num"><?php echo esc_html(Money::format($i['total'])); ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>

<?php elseif ( $view === 'expenses' ) : ?>
    <p class="description"><?php esc_html_e('Facturas de proveedores del periodo de los extractos sin un pago que coincida. Puede ser que las pagaras con otra cuenta o tarjeta, o que el importe cobrado sea distinto (divisa, comisiones).', 'mad-oficina'); ?></p>
    <?php if ( ! $unpaid ) : ?><p><?php esc_html_e('Ninguna.', 'mad-oficina'); ?></p><?php else : ?>
    <table class="madof-table"><tbody>
    <?php foreach ( $unpaid as $e ) : ?>
        <tr><td><?php echo esc_html(date_i18n('j/m/Y', strtotime($e['issue_date']))); ?></td><td><?php echo esc_html($e['supplier_name'] . ($e['number'] ? ' · ' . $e['number'] : '')); ?></td>
            <td class="num"><?php echo esc_html(Money::format($e['total']) . ($e['currency'] !== 'EUR' ? ' ' . $e['currency'] : '')); ?></td>
            <td><?php if ( $e['drive_url'] ) : ?><a href="<?php echo esc_url($e['drive_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ver', 'mad-oficina'); ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>

<?php else : $rules = Bank::rules(); ?>
    <p class="description"><?php esc_html_e('Cuando un movimiento contiene este texto, lo doy por resuelto sin preguntarte (entrando o saliendo, en este extracto y en los siguientes). Para tus traspasos, pon tu nombre tal como sale en el banco o los últimos dígitos de tus otras cuentas.', 'mad-oficina'); ?></p>
    <?php echo AdminUi::form_open('billing_bank_action', 'madof-inline'); ?>
        <input type="hidden" name="do" value="add_rule">
        <input type="text" name="pattern" required minlength="3" placeholder="<?php esc_attr_e('Texto (p. ej. tu nombre)', 'mad-oficina'); ?>" aria-label="<?php esc_attr_e('Texto', 'mad-oficina'); ?>">
        <select name="kind" aria-label="<?php esc_attr_e('Qué son', 'mad-oficina'); ?>"><?php foreach ( Bank::KINDS as $k => $l ) echo '<option value="' . esc_attr($k) . '"' . selected($k, 'own', false) . '>' . esc_html__($l, 'mad-oficina') . '</option>'; ?></select>
        <input type="text" name="label" placeholder="<?php esc_attr_e('Nota (opcional)', 'mad-oficina'); ?>">
        <button class="madof-btn is-small"><?php esc_html_e('Añadir', 'mad-oficina'); ?></button>
    </form>
    <?php if ( ! $rules ) : ?><p><?php esc_html_e('Todavía nada.', 'mad-oficina'); ?></p><?php else : ?>
    <table class="madof-table"><tbody>
    <?php foreach ( $rules as $r ) : ?>
        <tr><td>«<?php echo esc_html($r['contains']); ?>»</td><td><?php echo esc_html(__(Bank::KINDS[$r['kind']] ?? $r['kind'], 'mad-oficina') . ($r['label'] ? ' · ' . $r['label'] : '')); ?></td>
            <td><?php echo AdminUi::form_open('billing_bank_action', 'madof-inline'); ?><input type="hidden" name="do" value="delete_rule"><input type="hidden" name="view" value="rules"><input type="hidden" name="pattern" value="<?php echo esc_attr($r['contains']); ?>"><button class="madof-link"><?php esc_html_e('Olvidar', 'mad-oficina'); ?></button></form></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
<?php endif; ?>
</section>
