<?php
/**
 * Finanzas → Impuestos: what was paid to Hacienda and the Seguridad Social.
 * Included from billing.php ($base, $settings available).
 */
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Billing\Taxes;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$this_year = (int) current_time('Y');
$tyear = isset($_GET['year']) ? absint($_GET['year']) : $this_year;
if ( $tyear < 2000 || $tyear > 2100 ) $tyear = $this_year;
$rows = Taxes::year($tyear);
$edit = isset($_GET['tax']) ? Taxes::get(absint($_GET['tax'])) : null;
// «Apuntar como pagado» from the VAT estimate prefills the form.
$prefill = $edit ?: [
    'kind' => sanitize_key($_GET['kind'] ?? 'vat_303'),
    'period' => sanitize_text_field($_GET['period'] ?? ''),
    'amount' => isset($_GET['amount']) ? (float) $_GET['amount'] : '',
    'paid_on' => '', 'file_url' => '', 'notes' => '', 'id' => 0,
];
$current_q = $tyear === $this_year ? (int) ceil(current_time('n') / 3) : 4;
?>
<nav class="madof-filters" aria-label="<?php esc_attr_e('Año', 'mad-oficina'); ?>">
    <?php foreach ( [$this_year + 0, $this_year - 1, $this_year - 2] as $y ) : ?>
        <a class="madof-pill <?php echo $y === $tyear ? 'is-on' : ''; ?>" href="<?php echo esc_url($base . '&tab=taxes&year=' . $y); ?>"><?php echo esc_html($y); ?></a>
    <?php endforeach; ?>
</nav>

<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('IVA de cada trimestre · estimación', 'mad-oficina'); ?></span>
    <div class="madof-scroll">
    <table class="madof-table madof-num">
        <thead><tr>
            <th><?php esc_html_e('Trimestre', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Cobrado', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Soportado', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('A pagar (aprox.)', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Apuntado', 'mad-oficina'); ?></th>
        </tr></thead>
        <tbody>
        <?php for ( $q = 1; $q <= $current_q; $q++ ) :
            $e = Taxes::vat_estimate($tyear, $q);
            $period = sprintf('%d-T%d', $tyear, $q);
            $done = Taxes::find('vat_303', $period); ?>
            <tr>
                <td><?php echo esc_html(Taxes::period_label($period)); ?></td>
                <td><?php echo esc_html(Money::format($e['charged'])); ?></td>
                <td><?php echo esc_html(Money::format($e['paid'])); ?></td>
                <td><strong><?php echo esc_html(Money::format($e['result'])); ?></strong></td>
                <td><?php if ( $done ) : ?>
                        <a href="<?php echo esc_url($base . '&tab=taxes&year=' . $tyear . '&tax=' . $done['id']); ?>"><?php echo esc_html(Money::format($done['amount'])); ?></a>
                    <?php else : ?>
                        <a class="madof-pill" href="<?php echo esc_url($base . '&tab=taxes&year=' . $tyear . '&kind=vat_303&period=' . $period . '&amount=' . max(0, $e['result']) . '#madof-tax-form'); ?>"><?php esc_html_e('Apuntar como pagado', 'mad-oficina'); ?></a>
                    <?php endif; ?></td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
    </div>
    <p class="description"><?php esc_html_e('IVA de tus facturas F/R menos el de los gastos en euros registrados. Las facturas de fuera de la UE o intracomunitarias pueden cambiar la cifra: la buena la calcula la gestoría.', 'mad-oficina'); ?></p>
</section>

<section class="madof-card">
    <span class="madof-kicker"><?php echo esc_html(sprintf(__('Apuntado en %d', 'mad-oficina'), $tyear)); ?></span>
    <?php if ( ! $rows ) : ?>
        <p class="madof-muted"><?php esc_html_e('Nada apuntado este año todavía.', 'mad-oficina'); ?></p>
    <?php else : ?>
    <div class="madof-scroll">
    <table class="madof-table">
        <thead><tr>
            <th><?php esc_html_e('Qué', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Periodo', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Pagado el', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Importe', 'mad-oficina'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $r ) : ?>
            <tr>
                <td><?php echo esc_html(Taxes::KINDS[$r['kind']] ?? $r['kind']); ?>
                    <?php if ( $r['source'] === 'monthly' ) echo ' <span class="madof-pill">' . esc_html__('automático', 'mad-oficina') . '</span>'; ?>
                    <?php if ( $r['source'] === 'document' ) echo ' <span class="madof-pill">' . esc_html__('del justificante', 'mad-oficina') . '</span>'; ?>
                    <?php if ( $r['notes'] ) echo '<br><small>' . esc_html($r['notes']) . '</small>'; ?></td>
                <td><?php echo esc_html(Taxes::period_label($r['period'])); ?></td>
                <td><?php echo $r['paid_on'] ? esc_html(date_i18n('j M Y', strtotime($r['paid_on']))) : '<span class="madof-muted">' . esc_html__('pendiente', 'mad-oficina') . '</span>'; ?></td>
                <td><?php echo esc_html(Money::format($r['amount'])); ?>
                    <?php if ( $r['file_url'] ) echo ' <a href="' . esc_url($r['file_url']) . '" target="_blank" rel="noopener">' . esc_html__('justificante', 'mad-oficina') . '</a>'; ?></td>
                <td>
                    <a href="<?php echo esc_url($base . '&tab=taxes&year=' . $tyear . '&tax=' . $r['id'] . '#madof-tax-form'); ?>"><?php esc_html_e('Editar', 'mad-oficina'); ?></a>
                    <?php echo AdminUi::form_open('billing_tax_delete', 'madof-inline'); ?>
                        <input type="hidden" name="tax_id" value="<?php echo esc_attr($r['id']); ?>">
                        <input type="hidden" name="year" value="<?php echo esc_attr($tyear); ?>">
                        <button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js(__('¿Borrar este apunte?', 'mad-oficina')); ?>')"><?php esc_html_e('Borrar', 'mad-oficina'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<div class="madof-cols">
<section class="madof-card" id="madof-tax-form">
    <span class="madof-kicker"><?php echo $edit ? esc_html__('Editar apunte', 'mad-oficina') : esc_html__('Apuntar un pago', 'mad-oficina'); ?></span>
    <?php echo AdminUi::form_open('billing_tax_save'); ?>
        <input type="hidden" name="tax_id" value="<?php echo esc_attr($prefill['id'] ?? 0); ?>">
        <label><?php esc_html_e('Qué es', 'mad-oficina'); ?>
            <select name="kind"><?php foreach ( Taxes::KINDS as $k => $l ) : ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($prefill['kind'], $k); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?></select></label>
        <div class="madof-cols">
            <label><?php esc_html_e('Periodo', 'mad-oficina'); ?><input type="text" name="period" required pattern="\d{4}(-[Tt][1-4]|-\d{2})?" value="<?php echo esc_attr($prefill['period']); ?>" placeholder="<?php echo esc_attr($tyear . '-T' . $current_q); ?>"></label>
            <label><?php esc_html_e('Importe (€)', 'mad-oficina'); ?><input type="text" name="amount" required inputmode="decimal" value="<?php echo esc_attr($prefill['amount'] === '' ? '' : number_format((float) $prefill['amount'], 2, ',', '')); ?>"></label>
            <label><?php esc_html_e('Pagado el', 'mad-oficina'); ?><input type="date" name="paid_on" value="<?php echo esc_attr($prefill['paid_on']); ?>"></label>
        </div>
        <p class="description"><?php esc_html_e('Periodo: un trimestre (2026-T3), un mes (2026-10) o un año (2026). Sin fecha de pago queda como pendiente.', 'mad-oficina'); ?></p>
        <label><?php esc_html_e('Enlace al justificante (Drive)', 'mad-oficina'); ?><input type="url" name="file_url" value="<?php echo esc_attr($prefill['file_url']); ?>"></label>
        <label><?php esc_html_e('Notas', 'mad-oficina'); ?><textarea name="notes" rows="2"><?php echo esc_textarea($prefill['notes']); ?></textarea></label>
        <p><button type="submit" class="button button-primary"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
            <?php if ( $edit ) : ?><a href="<?php echo esc_url($base . '&tab=taxes&year=' . $tyear); ?>"><?php esc_html_e('Cancelar', 'mad-oficina'); ?></a><?php endif; ?></p>
    </form>
</section>

<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Cuota de autónomos', 'mad-oficina'); ?></span>
    <p><?php esc_html_e('Pon tu cuota una vez y la apunto sola cada mes, el día que te la cargan. Si cambia, pon el importe nuevo y el mes desde el que cambia.', 'mad-oficina'); ?></p>
    <?php echo AdminUi::form_open('billing_autonomos_settings'); ?>
        <div class="madof-cols">
            <label><?php esc_html_e('Cuota mensual (€)', 'mad-oficina'); ?><input type="text" name="autonomos_amount" inputmode="decimal" value="<?php echo esc_attr(! empty($settings['autonomos_amount']) ? number_format((float) $settings['autonomos_amount'], 2, ',', '') : ''); ?>"></label>
            <label><?php esc_html_e('Desde el mes', 'mad-oficina'); ?><input type="month" name="autonomos_from" value="<?php echo esc_attr($settings['autonomos_from'] ?? Months::current()); ?>"></label>
            <label><?php esc_html_e('Día del cargo', 'mad-oficina'); ?><input type="number" name="autonomos_day" min="1" max="31" value="<?php echo esc_attr($settings['autonomos_day'] ?? 28); ?>"></label>
        </div>
        <p><button type="submit" class="button button-primary"><?php esc_html_e('Guardar cuota', 'mad-oficina'); ?></button></p>
    </form>
    <p class="description"><?php esc_html_e('Los meses anteriores con la cuota vieja los dejo como estaban. Vacío = no apunto nada.', 'mad-oficina'); ?></p>
</section>
</div>
