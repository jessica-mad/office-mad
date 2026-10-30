<?php
/**
 * Agreements: monthly fees and one-off jobs per client (base, without VAT).
 * Included from billing.php ($base available).
 */
use MAD_Oficina\Billing\Agreements;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;
$now = Months::current();
$profiles = Profiles::all('active');
$codes = array_column(Clients::all(), 'code', 'id');
$profile_options = function($selected = 0) use ($profiles, $codes){
    $out = '<option value="">' . esc_html__('— Elige el cliente —', 'mad-oficina') . '</option>';
    foreach ( $profiles as $p ) {
        $label = ($p['client_id'] && isset($codes[$p['client_id']]) ? $codes[$p['client_id']] . ' · ' : '') . $p['legal_name'];
        $out .= '<option value="' . (int) $p['id'] . '" ' . selected((int) $selected, (int) $p['id'], false) . '>' . esc_html($label) . '</option>';
    }
    return $out;
};
$from_quote = isset($_GET['from_quote']) ? \MAD_Oficina\Billing\Quotes::get(absint($_GET['from_quote'])) : null;
$items = $from_quote ? array_values(array_filter(Agreements::of_quote($from_quote['id']), fn($i) => $i['status'] === 'active')) : Agreements::all();
$issuing = new \MAD_Oficina\Billing\Issuing(is_array($settings ?? null) ? $settings : []);
$profiles_all = [];
foreach ( Profiles::all() as $p ) $profiles_all[$p['id']] = $p;
// The next invoices an agreement will bring: date (the client's billing day), and whether it is already a draft or issued.
$next = function(array $it, $n = 3) use ($issuing, $profiles_all, $now){
    $out = [];
    $profile = $profiles_all[$it['profile_id']] ?? null;
    for ( $m = max($it['start_month'], $now), $i = 0; $i < 24 && count($out) < $n; $m = Months::add($m, 1), $i++ ) {
        if ( $it['end_month'] !== null && $m > $it['end_month'] ) break;
        if ( $it['kind'] === 'once' && $m !== $it['start_month'] ) break;
        if ( \MAD_Oficina\Billing\Calendar::skipped($it['id'], $m) ) continue;
        $out[] = ['date' => $issuing->default_issue_date($profile, $m . '-01'), 'invoice' => \MAD_Oficina\Billing\Calendar::billed_in($it['id'], $m)];
    }
    return $out;
};
$current = array_filter($items, fn($i) => $i['end_month'] === null || $i['end_month'] >= $now);
$ended = array_filter($items, fn($i) => $i['end_month'] !== null && $i['end_month'] < $now);
$suggestions = Agreements::suggestions();
$names = array_column($profiles, 'legal_name', 'id');
foreach ( Profiles::all() as $p ) $names[$p['id']] = $p['legal_name'];
?>
<?php if ( $from_quote ) : ?>
<section class="madof-card madof-replacement">
    <span class="madof-kicker"><?php echo esc_html(sprintf(__('Del presupuesto %s', 'mad-oficina'), $from_quote['number'])); ?></span>
    <p><strong><?php echo esc_html($from_quote['prospect_name'] . ' · ' . $from_quote['title']); ?></strong></p>
    <p class="description"><?php esc_html_e('Lo aceptado ya son acuerdos, como los demás: el calendario prepara cada factura unos días antes de su fecha y, ese día, sale sola o te la pregunta (cámbialo en «Cambiar»).', 'mad-oficina'); ?></p>
    <?php $q_atts = \MAD_Oficina\Billing\QuoteFiles::of($from_quote); if ( $q_atts ) : ?><p><?php foreach ( $q_atts as $a ) echo '<a class="madof-pill" href="' . esc_url(\MAD_Oficina\Billing\QuoteFiles::url($from_quote['id'], $a)) . '" target="_blank" rel="noopener">' . esc_html($a['name']) . '</a> '; ?></p><?php endif; ?>
    <p><a href="<?php echo esc_url($base . '&tab=agreements'); ?>"><?php esc_html_e('Ver todos los acuerdos', 'mad-oficina'); ?></a></p>
</section>
<?php endif; ?>
<?php if ( $suggestions && ! $from_quote ) : ?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Fees que veo en tu histórico', 'mad-oficina'); ?></span>
    <p class="description"><?php esc_html_e('Estos clientes se han facturado casi todos los meses y aún no tienen un fee. Revisa el importe y el concepto, pon hasta cuándo si ya lo sabes (vacío = sin fin) y créalo.', 'mad-oficina'); ?></p>
    <?php foreach ( $suggestions as $sg ) : ?>
        <?php echo AdminUi::form_open('billing_item_create', 'madof-form madof-suggest'); ?>
            <input type="hidden" name="profile_id" value="<?php echo (int) $sg['profile']['id']; ?>">
            <input type="hidden" name="kind" value="monthly">
            <p><strong><?php echo esc_html($sg['profile']['legal_name']); ?></strong>
                <small><?php echo esc_html(sprintf(__('facturado %1$d de los últimos 4 meses · último: %2$s', 'mad-oficina'), $sg['months'], Months::label($sg['last']))); ?></small></p>
            <div class="madof-cols">
                <label><?php esc_html_e('Concepto', 'mad-oficina'); ?><input type="text" name="concept" value="<?php echo esc_attr($sg['concept']); ?>"></label>
                <label><?php esc_html_e('€/mes sin IVA', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="amount" value="<?php echo esc_attr(number_format($sg['amount'], 2, ',', '')); ?>"></label>
                <label><?php esc_html_e('Desde', 'mad-oficina'); ?><input type="month" name="start_month" value="<?php echo esc_attr($sg['start_month']); ?>"></label>
                <label><?php esc_html_e('Hasta (opcional)', 'mad-oficina'); ?><input type="month" name="end_month" value=""></label>
            </div>
            <button class="madof-btn is-small"><?php esc_html_e('Crear fee', 'mad-oficina'); ?></button>
        </form>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ( ! $from_quote ) : ?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Nuevo acuerdo', 'mad-oficina'); ?></span>
    <?php echo AdminUi::form_open('billing_item_create'); ?>
        <div class="madof-cols">
            <label><?php esc_html_e('Cliente', 'mad-oficina'); ?><select name="profile_id" required><?php echo $profile_options(absint($_GET['profile'] ?? 0)); ?></select></label>
            <label><?php esc_html_e('Tipo', 'mad-oficina'); ?><select name="kind">
                <?php foreach ( Agreements::KINDS as $k => $l ) echo '<option value="' . esc_attr($k) . '">' . esc_html__($l, 'mad-oficina') . '</option>'; ?>
            </select></label>
        </div>
        <div class="madof-cols">
            <label><?php esc_html_e('Concepto', 'mad-oficina'); ?><input type="text" name="concept" required placeholder="<?php esc_attr_e('Mantenimiento web y SEM', 'mad-oficina'); ?>"></label>
            <label><?php esc_html_e('Importe sin IVA', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="amount" required placeholder="550,00">
                <small><?php esc_html_e('Al mes, si es un fee.', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('Mes (o desde)', 'mad-oficina'); ?><input type="month" name="start_month" required value="<?php echo esc_attr($now); ?>"></label>
            <label><?php esc_html_e('Hasta (solo fees, opcional)', 'mad-oficina'); ?><input type="month" name="end_month"></label>
        </div>
        <label><?php esc_html_e('Notas', 'mad-oficina'); ?><input type="text" name="notes"></label>
        <label class="madof-check"><input type="checkbox" name="auto_send" value="1"> <?php esc_html_e('Sus facturas salen solas en su fecha (si no, te pregunto ese día)', 'mad-oficina'); ?></label>
        <button class="madof-btn"><?php esc_html_e('Guardar acuerdo', 'mad-oficina'); ?></button>
    </form>
</section>
<?php endif; ?>

<?php
$render = function(array $list) use ($names, $codes, $now, $next, $base, $from_quote){
    if ( ! $list ) { echo '<p class="madof-empty">' . esc_html__('Nada por aquí.', 'mad-oficina') . '</p>'; return; }
    usort($list, fn($a, $b) => strcasecmp($names[$a['profile_id']] ?? '', $names[$b['profile_id']] ?? '') ?: strcmp($b['start_month'], $a['start_month']));
    ?>
    <table class="madof-table">
        <thead><tr><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><th><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><th><?php esc_html_e('Importe', 'mad-oficina'); ?></th><th><?php esc_html_e('Cuándo', 'mad-oficina'); ?></th><th><?php esc_html_e('Próximas facturas', 'mad-oficina'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $list as $it ) :
            $state = $it['start_month'] > $now ? __('Empieza más adelante', 'mad-oficina') : ( $it['end_month'] !== null && $it['end_month'] < $now ? __('Terminado', 'mad-oficina') : ( $it['end_month'] !== null && $it['end_month'] <= Months::add($now, 3) ? __('Termina pronto', 'mad-oficina') : '' ) ); ?>
            <tr>
                <td><?php if ( $it['client_id'] && isset($codes[$it['client_id']]) ) echo '<span class="madof-code">' . esc_html($codes[$it['client_id']]) . '</span> '; ?><?php echo esc_html($names[$it['profile_id']] ?? '—'); ?></td>
                <td><?php echo esc_html($it['concept']); ?><?php if ( $it['quote_id'] && ! $from_quote ) : ?><br><small><a href="<?php echo esc_url($base . '&tab=agreements&from_quote=' . (int) $it['quote_id']); ?>"><?php echo esc_html($it['notes'] ?: __('Del presupuesto', 'mad-oficina')); ?></a></small><?php elseif ( $it['notes'] ) : echo '<br><small>' . esc_html($it['notes']) . '</small>'; endif; ?></td>
                <td class="madof-nowrap"><strong><?php echo esc_html(Money::format($it['amount'])); ?></strong><?php echo $it['kind'] === 'monthly' ? '<small>/' . esc_html__('mes', 'mad-oficina') . '</small>' : ''; ?></td>
                <td><?php echo esc_html(Agreements::period($it)); ?><?php if ( $state ) echo ' <span class="madof-pill">' . esc_html($state) . '</span>'; ?></td>
                <td class="madof-next-bills"><?php $nx = $next($it); if ( ! $nx ) echo '<small class="madof-muted">—</small>'; foreach ( $nx as $b ) : ?>
                    <div><?php echo esc_html(date_i18n('j M Y', strtotime($b['date']))); ?>
                    <?php if ( $b['invoice'] ) : ?><a class="madof-pill madof-st madof-st-<?php echo esc_attr($b['invoice']['status']); ?>" href="<?php echo esc_url($base . ($b['invoice']['number'] ? '&invoice=' : '&edit=') . (int) $b['invoice']['invoice_id']); ?>"><?php echo esc_html($b['invoice']['number'] ?: __('Borrador', 'mad-oficina')); ?></a>
                    <?php else : ?><span class="madof-pill <?php echo $it['auto_send'] ? 'madof-st madof-st-sent' : ''; ?>"><?php echo esc_html($it['auto_send'] ? __('sale sola', 'mad-oficina') : __('te pregunto', 'mad-oficina')); ?></span><?php endif; ?></div>
                <?php endforeach; ?></td>
                <td class="madof-row-actions">
                    <details class="madof-more"><summary><?php esc_html_e('Cambiar', 'mad-oficina'); ?></summary>
                    <?php if ( $it['kind'] === 'monthly' ) : ?>
                        <?php echo AdminUi::form_open('billing_item_change', 'madof-form madof-mini'); ?>
                            <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                            <label><?php esc_html_e('Nuevo importe desde', 'mad-oficina'); ?><input type="month" name="from_month" value="<?php echo esc_attr(max(Months::add($now, 1), Months::add($it['start_month'], 1))); ?>"></label>
                            <label><?php esc_html_e('€/mes', 'mad-oficina'); ?><input type="text" inputmode="decimal" name="amount" value="<?php echo esc_attr(number_format($it['amount'], 2, ',', '')); ?>"></label>
                            <button class="madof-btn is-small is-ghost"><?php esc_html_e('Cambiar importe', 'mad-oficina'); ?></button>
                        </form>
                        <?php echo AdminUi::form_open('billing_item_end', 'madof-form madof-mini'); ?>
                            <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                            <label><?php esc_html_e('Último mes', 'mad-oficina'); ?><input type="month" name="end_month" value="<?php echo esc_attr($it['end_month'] ?? $now); ?>"></label>
                            <button class="madof-btn is-small is-ghost"><?php esc_html_e('Terminar', 'mad-oficina'); ?></button>
                            <?php if ( $it['end_month'] !== null ) : ?><button class="madof-link" name="reopen" value="1"><?php esc_html_e('Quitar la fecha de fin', 'mad-oficina'); ?></button><?php endif; ?>
                        </form>
                    <?php endif; ?>
                        <?php echo AdminUi::form_open('billing_item_autosend', 'madof-inline'); ?>
                            <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>"><input type="hidden" name="on" value="<?php echo $it['auto_send'] ? '' : '1'; ?>">
                            <?php if ( $from_quote ) : ?><input type="hidden" name="from_quote" value="<?php echo (int) $from_quote['id']; ?>"><?php endif; ?>
                            <button class="madof-link"><?php echo esc_html($it['auto_send'] ? __('Que me pregunte antes de enviar', 'mad-oficina') : __('Que sus facturas salgan solas', 'mad-oficina')); ?></button>
                        </form><br>
                        <?php echo AdminUi::form_open('billing_item_cancel', 'madof-inline'); ?>
                            <input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
                            <button class="madof-link" onclick="return confirm('<?php echo esc_js(__('¿Borrarlo? Úsalo solo si se metió por error; si el cliente lo deja, mejor «Terminar».', 'mad-oficina')); ?>')"><?php esc_html_e('Borrar (error)', 'mad-oficina'); ?></button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php };
?>
<h2><?php esc_html_e('En curso y próximos', 'mad-oficina'); ?></h2>
<?php $render($current); ?>
<?php if ( $ended ) : ?>
<details class="madof-more"><summary><?php echo esc_html(sprintf(__('Terminados (%d)', 'mad-oficina'), count($ended))); ?></summary><?php $render($ended); ?></details>
<?php endif; ?>
