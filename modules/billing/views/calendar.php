<?php
/**
 * Billing calendar: what is billed this month to each client, from the agreements.
 * Included from billing.php ($base, $settings available).
 */
use MAD_Oficina\Billing\Calendar;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$cal = new Calendar($settings);
$now = Months::current();
$month = Months::valid($_GET['month'] ?? '') ?: $now;
$rows = $cal->month($month);
$states = [
    'to_prepare' => __('Por preparar', 'mad-oficina'),
    'draft'      => __('Borrador listo', 'mad-oficina'),
    'asked'      => __('Esperando tu OK', 'mad-oficina'),
    'issued'     => __('Emitida', 'mad-oficina'),
    'sent'       => __('Enviada', 'mad-oficina'),
    'skipped'    => __('Este mes no', 'mad-oficina'),
];
$total = array_sum(array_column($rows, 'total'));
$done = array_sum(array_map(fn($r) => in_array($r['state'], ['issued', 'sent'], true) ? $r['total'] : 0, $rows));
?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Facturación automática', 'mad-oficina'); ?></span>
    <?php if ( ! $cal->enabled() ) : ?><p class="madof-warn"><?php esc_html_e('Está en pausa: actívala para que la oficina prepare los borradores de cada mes y te pregunte el día que toca.', 'mad-oficina'); ?></p><?php endif; ?>
    <?php echo AdminUi::form_open('billing_calendar_settings'); ?>
        <label class="madof-check"><input type="checkbox" name="calendar_enabled" value="1" <?php checked(! empty($settings['calendar_enabled'])); ?>> <?php esc_html_e('Preparar los borradores con los acuerdos de cada mes y preguntarme el día de facturación', 'mad-oficina'); ?></label>
        <div class="madof-cols">
            <label><?php esc_html_e('Empezar en', 'mad-oficina'); ?><input type="month" name="calendar_from" value="<?php echo esc_attr($settings['calendar_from'] ?? Months::add($now, 1)); ?>">
                <small><?php esc_html_e('Los meses anteriores no se tocan (p. ej. lo que ya hiciste con la hoja antigua).', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('Preparar el borrador', 'mad-oficina'); ?><select name="calendar_lead_days"><?php foreach ( [1, 2, 3, 5, 7] as $d ) echo '<option value="' . $d . '" ' . selected((int) ($settings['calendar_lead_days'] ?? 3), $d, false) . '>' . esc_html(sprintf(_n('%d día antes', '%d días antes', $d, 'mad-oficina'), $d)) . '</option>'; ?></select></label>
            <label><?php esc_html_e('Preguntar a partir de las', 'mad-oficina'); ?><select name="calendar_hour"><?php foreach ( range(7, 12) as $h ) echo '<option value="' . $h . '" ' . selected((int) ($settings['calendar_hour'] ?? 9), $h, false) . '>' . $h . ':00</option>'; ?></select></label>
        </div>
        <button class="madof-btn is-small"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
    </form>
</section>

<div class="madof-forecast-tools">
    <h2><?php echo esc_html(ucfirst(Months::label($month))); ?> · <?php echo esc_html(Money::format($total)); ?> <small class="madof-muted"><?php echo esc_html(sprintf(__('(emitido %s)', 'mad-oficina'), Money::format($done))); ?></small></h2>
    <nav class="madof-filters">
        <a class="madof-pill" href="<?php echo esc_url($base . '&tab=calendar&month=' . Months::add($month, -1)); ?>">← <?php echo esc_html(Months::label(Months::add($month, -1), false)); ?></a>
        <a class="madof-pill <?php echo $month === $now ? 'is-on' : ''; ?>" href="<?php echo esc_url($base . '&tab=calendar'); ?>"><?php esc_html_e('Este mes', 'mad-oficina'); ?></a>
        <a class="madof-pill" href="<?php echo esc_url($base . '&tab=calendar&month=' . Months::add($month, 1)); ?>"><?php echo esc_html(Months::label(Months::add($month, 1), false)); ?> →</a>
    </nav>
</div>

<?php if ( ! $rows ) : ?>
    <p class="madof-empty"><?php esc_html_e('No hay acuerdos este mes. Añádelos en Acuerdos.', 'mad-oficina'); ?></p>
<?php else : ?>
<table class="madof-table">
    <thead><tr><th><?php esc_html_e('Día', 'mad-oficina'); ?></th><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><th><?php esc_html_e('Qué se factura', 'mad-oficina'); ?></th><th><?php esc_html_e('Base', 'mad-oficina'); ?></th><th><?php esc_html_e('Estado', 'mad-oficina'); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ( $rows as $r ) : $inv = $r['invoice']; ?>
        <tr>
            <td class="madof-nowrap"><strong><?php echo esc_html(date_i18n('j', strtotime($r['date']))); ?></strong> <?php echo esc_html(Months::label(Months::of($r['date']), false)); ?></td>
            <td><?php echo esc_html($r['profile']['legal_name']); ?><?php if ( $r['profile']['series'] === 'AR' ) echo ' <span class="madof-pill">AR</span>'; ?></td>
            <td><?php foreach ( $r['items'] as $it ) {
                echo '<div' . ($it['skipped'] ? ' class="madof-muted"' : '') . '>' . esc_html($it['concept'] . ' · ' . Money::format($it['amount']));
                if ( $it['skipped'] ) echo ' <small>(' . esc_html__('este mes no', 'mad-oficina') . ')</small>';
                elseif ( $it['billed'] && $it['billed']['number'] ) echo ' <small>(' . esc_html($it['billed']['number']) . ')</small>';
                echo '</div>';
            }
            foreach ( $r['extras'] as $x ) echo '<div>' . esc_html($x['description'] . ' · ' . Money::format(Money::parse((string) $x['unit_price']))) . '</div>'; ?></td>
            <td class="madof-nowrap"><strong><?php echo esc_html(Money::format($r['total'])); ?></strong></td>
            <td><span class="madof-pill <?php echo in_array($r['state'], ['issued', 'sent'], true) ? 'is-on' : ''; ?>"><?php echo esc_html($states[$r['state']] ?? $r['state']); ?></span>
                <?php if ( $r['state'] === 'to_prepare' && $cal->enabled() && $month >= ($settings['calendar_from'] ?? '9999') ) echo '<br><small>' . esc_html(sprintf(__('lo preparo el %s', 'mad-oficina'), date_i18n('j/m', strtotime($r['date'] . ' -' . $cal->lead_days() . ' days')))) . '</small>'; ?>
                <?php if ( $r['pending'] && $inv ) echo '<br><small>' . esc_html(sprintf(_n('%d acuerdo sin facturar aún', '%d acuerdos sin facturar aún', $r['pending'], 'mad-oficina'), $r['pending'])) . '</small>'; ?></td>
            <td class="madof-row-actions">
                <?php if ( $r['state'] === 'to_prepare' || ( $r['pending'] && $inv && $inv['status'] === 'draft' ) ) : echo AdminUi::form_open('billing_prepare', 'madof-inline'); ?>
                    <input type="hidden" name="profile_id" value="<?php echo (int) $r['profile']['id']; ?>"><input type="hidden" name="month" value="<?php echo esc_attr($month); ?>">
                    <button class="madof-btn is-small"><?php esc_html_e('Preparar ahora', 'mad-oficina'); ?></button></form>
                <?php endif; ?>
                <?php if ( $inv && $inv['status'] === 'draft' ) : ?>
                    <a class="madof-btn is-small is-ghost" href="<?php echo esc_url($base . '&edit=' . $inv['id']); ?>"><?php esc_html_e('Abrir', 'mad-oficina'); ?></a>
                    <?php if ( $inv['series'] !== 'AR' && $inv['status'] === 'draft' ) : ?><a class="madof-btn is-small" href="<?php echo esc_url($base . '&edit=' . $inv['id'] . '#enviar'); ?>"><?php esc_html_e('Ver y enviar', 'mad-oficina'); ?></a><?php endif; ?>
                    <?php echo AdminUi::form_open('billing_postpone', 'madof-inline'); ?>
                        <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>"><input type="hidden" name="days" value="7">
                        <button class="madof-link"><?php esc_html_e('Posponer 7 días', 'mad-oficina'); ?></button></form>
                    <?php echo AdminUi::form_open('billing_skip', 'madof-inline'); ?>
                        <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
                        <button class="madof-link" onclick="return confirm('<?php echo esc_js(__('¿No facturar estos acuerdos este mes?', 'mad-oficina')); ?>')"><?php esc_html_e('Este mes no', 'mad-oficina'); ?></button></form>
                <?php elseif ( $inv ) : ?>
                    <a class="madof-link" href="<?php echo esc_url($base . '&invoice=' . $inv['id']); ?>"><?php esc_html_e('Ver factura', 'mad-oficina'); ?></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<p class="description"><?php esc_html_e('Cada línea de factura recuerda qué acuerdo y qué mes cobra: así nada se factura dos veces ni se queda sin facturar. Nada sale sin tu OK: el día que toca te pregunto en la bandeja y en Chat (Enviar · Editar · Posponer 7 días · Este mes no).', 'mad-oficina'); ?></p>
