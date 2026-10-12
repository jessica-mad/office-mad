<?php
/**
 * Finanzas → Resumen: income against expenses and taxes, month by month (without VAT).
 */
use MAD_Oficina\Billing\Finance;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$this_year = (int) current_time('Y');
$years = array_unique(array_merge([$this_year], Invoices::years()));
rsort($years);
$year = isset($_GET['year']) ? absint($_GET['year']) : $this_year;
if ( $year < 2000 || $year > 2100 ) $year = $this_year;
$f = Finance::year($year);
$t = $f['totals'];
$base = admin_url('admin.php?page=mad-finanzas');
$bill = admin_url('admin.php?page=mad-oficina-facturacion');
$money = fn($n) => Money::format($n);
$short = fn($n) => abs($n) >= 1000 ? number_format($n / 1000, abs($n) >= 10000 ? 0 : 1, ',', '.') . ' k' : number_format($n, 0, ',', '.');
$max = 1.0;
foreach ( $f['months'] as $r ) $max = max($max, $r['income'] + $r['income_ar'], $r['expenses'] + $r['tax_costs']);
$categories = class_exists('MAD_Oficina\\Suppliers\\Suppliers') ? \MAD_Oficina\Suppliers\Suppliers::CATEGORIES : [];
$now_month = $year === $this_year ? (int) current_time('n') : 12;
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Finanzas', 'mad-oficina'), __('Resumen', 'mad-oficina'), __('Lo que entra frente a lo que sale, sin IVA. Argentina va aparte. Son cifras orientativas: las buenas las cierra la gestoría.', 'mad-oficina')); ?>

    <nav class="madof-filters" aria-label="<?php esc_attr_e('Año', 'mad-oficina'); ?>">
        <?php foreach ( $years as $y ) : ?>
            <a class="madof-pill <?php echo $y === $year ? 'is-on' : ''; ?>" href="<?php echo esc_url($base . '&year=' . $y); ?>"><?php echo esc_html($y); ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="madof-forecast-head">
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Ingresos España', 'mad-oficina'); ?></span>
            <strong><?php echo esc_html($money($t['income'])); ?></strong>
            <small><?php echo esc_html(sprintf(__('IVA cobrado %s', 'mad-oficina'), $money($t['vat_charged']))); ?></small></div>
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Ingresos Argentina', 'mad-oficina'); ?></span>
            <strong><?php echo esc_html($money($t['income_ar'])); ?></strong>
            <small><?php esc_html_e('Serie AR, fuera de los libros españoles', 'mad-oficina'); ?></small></div>
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Gastos', 'mad-oficina'); ?></span>
            <strong><?php echo esc_html($money($t['expenses'])); ?></strong>
            <small><?php echo esc_html(sprintf(__('IVA soportado %s', 'mad-oficina'), $money($t['vat_paid']))); ?></small></div>
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Cuotas e impuestos', 'mad-oficina'); ?></span>
            <strong><?php echo esc_html($money($t['tax_costs'])); ?></strong>
            <small><?php esc_html_e('Autónomos y otros (el IVA no cuenta: es de tus clientes)', 'mad-oficina'); ?></small></div>
        <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Te queda', 'mad-oficina'); ?></span>
            <strong class="<?php echo $t['result'] < 0 ? 'madof-neg' : ''; ?>"><?php echo esc_html($money($t['result'])); ?></strong>
            <small><?php esc_html_e('Antes del IRPF de la renta', 'mad-oficina'); ?></small></div>
    </section>

    <?php if ( $f['foreign_missing'] ) : ?>
    <section class="madof-card">
        <p><?php echo AdminUi::icon('alert'); ?> <?php echo esc_html(sprintf(_n('Hay %d gasto en otra moneda sin el importe pagado en euros: no cuenta en el resumen.', 'Hay %d gastos en otra moneda sin el importe pagado en euros: no cuentan en el resumen.', $f['foreign_missing'], 'mad-oficina'), $f['foreign_missing'])); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-gastos')); ?>"><?php esc_html_e('Ver gastos', 'mad-oficina'); ?></a></p>
    </section>
    <?php endif; ?>

    <section class="madof-card">
        <span class="madof-kicker"><?php echo esc_html(sprintf(__('Mes a mes · %d', 'mad-oficina'), $year)); ?></span>
        <ul class="madof-legend">
            <li><span class="madof-sw is-real"></span><?php esc_html_e('Ingresos España', 'mad-oficina'); ?></li>
            <li><span class="madof-sw is-confirmed"></span><?php esc_html_e('Ingresos Argentina', 'mad-oficina'); ?></li>
            <li><span class="madof-sw is-expense"></span><?php esc_html_e('Gastos', 'mad-oficina'); ?></li>
            <li><span class="madof-sw is-tax"></span><?php esc_html_e('Cuotas e impuestos', 'mad-oficina'); ?></li>
        </ul>
        <div class="madof-bars" role="img" aria-label="<?php esc_attr_e('Gráfica de ingresos y gastos por mes; los datos están en la tabla de abajo.', 'mad-oficina'); ?>">
            <?php foreach ( $f['months'] as $i => $r ) :
                $m = sprintf('%04d-%02d', $year, $i);
                $tip = Months::label($m) . ' · ' . __('Entra', 'mad-oficina') . ' ' . $money($r['income'] + $r['income_ar']) . ' · ' . __('Sale', 'mad-oficina') . ' ' . $money($r['expenses'] + $r['tax_costs']) . ' · ' . __('Queda', 'mad-oficina') . ' ' . $money($r['result']);
                $edge = $i <= 2 ? 'is-first' : ( $i >= 11 ? 'is-last' : '' );
                $h = fn($n) => esc_attr(round(100 * $n / $max, 2)); ?>
                <div class="madof-bar <?php echo esc_attr($edge); ?> <?php echo $year === $this_year && $i === $now_month ? 'is-now' : ''; ?>" tabindex="0" data-tip="<?php echo esc_attr($tip); ?>" aria-label="<?php echo esc_attr($tip); ?>">
                    <div class="madof-bar-pair">
                        <div class="madof-bar-stack">
                            <?php if ( $r['income_ar'] > 0 ) : ?><span class="madof-seg is-confirmed" style="height:<?php echo $h($r['income_ar']); ?>%"></span><?php endif; ?>
                            <?php if ( $r['income'] > 0 ) : ?><span class="madof-seg is-real" style="height:<?php echo $h($r['income']); ?>%"></span><?php endif; ?>
                        </div>
                        <div class="madof-bar-stack">
                            <?php if ( $r['tax_costs'] > 0 ) : ?><span class="madof-seg is-tax" style="height:<?php echo $h($r['tax_costs']); ?>%"></span><?php endif; ?>
                            <?php if ( $r['expenses'] > 0 ) : ?><span class="madof-seg is-expense" style="height:<?php echo $h($r['expenses']); ?>%"></span><?php endif; ?>
                        </div>
                    </div>
                    <span class="madof-bar-label"><?php echo esc_html(Months::label($m, false)); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="madof-scroll">
    <table class="madof-table madof-num">
        <thead><tr>
            <th><?php esc_html_e('Mes', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Ingresos', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Argentina', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Gastos', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Cuotas', 'mad-oficina'); ?></th>
            <th><?php esc_html_e('Te queda', 'mad-oficina'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $f['quarters'] as $q => $qr ) :
            foreach ( range(($q - 1) * 3 + 1, $q * 3) as $i ) : $r = $f['months'][$i]; ?>
            <tr>
                <td><?php echo esc_html(Months::label(sprintf('%04d-%02d', $year, $i))); ?></td>
                <td><?php echo esc_html($short($r['income'])); ?></td>
                <td><?php echo esc_html($short($r['income_ar'])); ?></td>
                <td><?php echo esc_html($short($r['expenses'])); ?></td>
                <td><?php echo esc_html($short($r['tax_costs'])); ?></td>
                <td class="<?php echo $r['result'] < 0 ? 'madof-neg' : ''; ?>"><?php echo esc_html($short($r['result'])); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="is-total">
                <th><?php echo esc_html(sprintf(__('T%d', 'mad-oficina'), $q)); ?></th>
                <td><?php echo esc_html($money($qr['income'])); ?></td>
                <td><?php echo esc_html($money($qr['income_ar'])); ?></td>
                <td><?php echo esc_html($money($qr['expenses'])); ?></td>
                <td><?php echo esc_html($money($qr['tax_costs'])); ?></td>
                <td class="<?php echo $qr['result'] < 0 ? 'madof-neg' : ''; ?>"><?php echo esc_html($money($qr['result'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('IVA por trimestre · orientativo', 'mad-oficina'); ?></span>
        <div class="madof-scroll">
        <table class="madof-table madof-num">
            <thead><tr>
                <th><?php esc_html_e('Trimestre', 'mad-oficina'); ?></th>
                <th><?php esc_html_e('IVA cobrado', 'mad-oficina'); ?></th>
                <th><?php esc_html_e('IVA soportado', 'mad-oficina'); ?></th>
                <th><?php esc_html_e('Diferencia', 'mad-oficina'); ?></th>
                <th><?php esc_html_e('IVA pagado (303)', 'mad-oficina'); ?></th>
                <th><?php esc_html_e('IRPF que te retuvieron', 'mad-oficina'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $f['quarters'] as $q => $qr ) : ?>
                <tr>
                    <td><?php echo esc_html(sprintf(__('T%1$d %2$d', 'mad-oficina'), $q, $year)); ?></td>
                    <td><?php echo esc_html($money($qr['vat_charged'])); ?></td>
                    <td><?php echo esc_html($money($qr['vat_paid'])); ?></td>
                    <td><strong><?php echo esc_html($money($qr['vat_charged'] - $qr['vat_paid'])); ?></strong></td>
                    <td><?php echo $qr['vat_settled'] ? esc_html($money($qr['vat_settled'])) : '<span class="madof-muted">·</span>'; ?></td>
                    <td><?php echo esc_html($money($qr['irpf'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="description"><?php esc_html_e('IVA soportado: solo el de facturas de gastos en euros. El 303 lo pagas en el mes siguiente al trimestre, por eso aparece en la fila en que lo pagaste.', 'mad-oficina'); ?>
            <a href="<?php echo esc_url($bill . '&tab=taxes&year=' . $year); ?>"><?php esc_html_e('Apuntar impuestos', 'mad-oficina'); ?></a></p>
    </section>

    <div class="madof-cols">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('En qué se va', 'mad-oficina'); ?></span>
            <?php if ( ! $f['categories'] ) : ?>
                <p class="madof-muted"><?php esc_html_e('Aún no hay gastos registrados este año.', 'mad-oficina'); ?></p>
            <?php else : ?>
                <table class="madof-totals madof-num"><?php foreach ( $f['categories'] as $k => $v ) : ?>
                    <tr><th><?php echo esc_html($categories[$k] ?? $k); ?></th><td><?php echo esc_html($money($v)); ?></td></tr>
                <?php endforeach; ?></table>
            <?php endif; ?>
        </section>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Quién te paga', 'mad-oficina'); ?></span>
            <?php if ( ! $f['clients'] ) : ?>
                <p class="madof-muted"><?php esc_html_e('Aún no hay facturas emitidas este año.', 'mad-oficina'); ?></p>
            <?php else : ?>
                <table class="madof-totals madof-num"><?php foreach ( $f['clients'] as $c ) : ?>
                    <tr><th><?php echo esc_html($c['name'] ?: '—'); ?></th><td><?php echo esc_html($money($c['base'])); ?></td></tr>
                <?php endforeach; ?></table>
            <?php endif; ?>
        </section>
    </div>
</div>
