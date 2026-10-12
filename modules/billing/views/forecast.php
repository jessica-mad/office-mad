<?php
/**
 * Forecast: income month by month without VAT (internal only).
 * Included from billing.php ($base available).
 */
use MAD_Oficina\Billing\Forecast;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Months;

if ( ! defined('ABSPATH') ) exit;
$now = Months::current();
$from = Months::valid($_GET['from'] ?? '') ?: Months::add($now, -3);
$to = Months::add($from, 12);
$f = Forecast::compute($from, $to);
$months = $f['months'];
$weighted_view = ($_GET['possible'] ?? 'weighted') !== 'raw';
$possible_key = $weighted_view ? 'weighted' : 'possible';
$max = 1.0;
foreach ( $months as $m ) $max = max($max, $f['totals']['expected'][$m] + $f['totals'][$possible_key][$m]);
$short = fn($n) => $n >= 1000 ? number_format($n / 1000, $n >= 10000 ? 0 : 1, ',', '.') . ' k' : number_format($n, 0, ',', '.');
$sum = fn($key, $a, $b) => array_sum(array_map(fn($m) => $f['totals'][$key][$m] ?? 0, Months::range($a, $b)));
$nav = fn($m) => esc_url($base . '&tab=forecast&from=' . $m . ($weighted_view ? '' : '&possible=raw'));
?>
<section class="madof-forecast-head">
    <div class="madof-stat"><span class="madof-kicker"><?php echo esc_html(sprintf(__('Este mes · %s', 'mad-oficina'), Months::label($now))); ?></span>
        <strong><?php echo esc_html(Money::format($f['totals']['expected'][$now] ?? 0)); ?></strong>
        <small><?php echo esc_html(sprintf(__('facturado %s', 'mad-oficina'), Money::format($f['totals']['real'][$now] ?? 0))); ?></small></div>
    <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Próximos 3 meses · confirmado', 'mad-oficina'); ?></span>
        <strong><?php echo esc_html(Money::format($sum('confirmed', Months::add($now, 1), Months::add($now, 3)))); ?></strong>
        <small><?php echo esc_html(sprintf(__('+ %s posible (ponderado)', 'mad-oficina'), Money::format($sum('weighted', Months::add($now, 1), Months::add($now, 3))))); ?></small></div>
    <div class="madof-stat"><span class="madof-kicker"><?php esc_html_e('Presupuestos en el aire', 'mad-oficina'); ?></span>
        <strong><?php echo esc_html(Money::format($sum('possible', $now, $to))); ?></strong>
        <small><?php echo esc_html(sprintf(__('%s ponderado por su probabilidad', 'mad-oficina'), Money::format($sum('weighted', $now, $to)))); ?></small></div>
</section>

<?php if ( $f['alerts'] ) : ?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Ojo', 'mad-oficina'); ?></span>
    <ul class="madof-alerts"><?php foreach ( $f['alerts'] as $a ) echo '<li>' . \MAD_Oficina\Core\AdminUi::icon('alert') . ' ' . esc_html($a) . '</li>'; ?></ul>
</section>
<?php endif; ?>

<section class="madof-card">
    <div class="madof-forecast-tools">
        <span class="madof-kicker"><?php esc_html_e('Ingresos sin IVA, mes a mes', 'mad-oficina'); ?></span>
        <nav class="madof-filters">
            <a class="madof-pill" href="<?php echo $nav(Months::add($from, -6)); ?>">← <?php esc_html_e('6 meses', 'mad-oficina'); ?></a>
            <a class="madof-pill" href="<?php echo $nav(Months::add($now, -3)); ?>"><?php esc_html_e('Hoy', 'mad-oficina'); ?></a>
            <a class="madof-pill" href="<?php echo $nav(Months::add($from, 6)); ?>"><?php esc_html_e('6 meses', 'mad-oficina'); ?> →</a>
            <a class="madof-pill <?php echo $weighted_view ? 'is-on' : ''; ?>" href="<?php echo esc_url($base . '&tab=forecast&from=' . $from); ?>"><?php esc_html_e('Posible ponderado', 'mad-oficina'); ?></a>
            <a class="madof-pill <?php echo $weighted_view ? '' : 'is-on'; ?>" href="<?php echo esc_url($base . '&tab=forecast&possible=raw&from=' . $from); ?>"><?php esc_html_e('Posible al 100 %', 'mad-oficina'); ?></a>
        </nav>
    </div>
    <ul class="madof-legend">
        <li><span class="madof-sw is-real"></span><?php esc_html_e('Facturado', 'mad-oficina'); ?></li>
        <li><span class="madof-sw is-confirmed"></span><?php esc_html_e('Confirmado (fees y encargos)', 'mad-oficina'); ?></li>
        <li><span class="madof-sw is-possible"></span><?php echo $weighted_view ? esc_html__('Posible (presupuestos × probabilidad)', 'mad-oficina') : esc_html__('Posible (presupuestos enviados)', 'mad-oficina'); ?></li>
    </ul>
    <div class="madof-bars" role="img" aria-label="<?php esc_attr_e('Gráfica de ingresos por mes; los datos están en la tabla de abajo.', 'mad-oficina'); ?>">
        <?php foreach ( $months as $m ) :
            $exp = $f['totals']['expected'][$m]; $pos = $f['totals'][$possible_key][$m];
            $is_real = $m < $now;
            $tip = Months::label($m) . ' · ' . ($is_real ? __('Facturado', 'mad-oficina') : ( $m === $now ? __('Previsto', 'mad-oficina') : __('Confirmado', 'mad-oficina') )) . ' ' . Money::format($exp)
                . ($m === $now ? ' (' . sprintf(__('facturado %s', 'mad-oficina'), Money::format($f['totals']['real'][$m])) . ')' : '')
                . ($pos ? ' · ' . __('Posible', 'mad-oficina') . ' ' . Money::format($pos) : '');
            $i = array_search($m, $months, true); $edge = $i < 2 ? 'is-first' : ( $i > count($months) - 3 ? 'is-last' : '' ); ?>
            <div class="madof-bar <?php echo $m === $now ? 'is-now' : ''; ?> <?php echo esc_attr($edge); ?>" tabindex="0" data-tip="<?php echo esc_attr($tip); ?>" aria-label="<?php echo esc_attr($tip); ?>">
                <div class="madof-bar-stack">
                    <?php if ( $pos > 0 ) : ?><span class="madof-seg is-possible" style="height:<?php echo esc_attr(round(100 * $pos / $max, 2)); ?>%"></span><?php endif; ?>
                    <?php if ( $exp > 0 ) : ?><span class="madof-seg <?php echo $is_real ? 'is-real' : 'is-confirmed'; ?>" style="height:<?php echo esc_attr(round(100 * $exp / $max, 2)); ?>%"></span><?php endif; ?>
                </div>
                <span class="madof-bar-label"><?php echo esc_html(Months::label($m, substr($m, 5) === '01' || $m === $from)); ?></span>
                <?php if ( $m === $now ) : ?><span class="madof-bar-value"><?php echo esc_html($short($exp)); ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="madof-scroll">
<table class="madof-table madof-forecast">
    <thead><tr><th><?php esc_html_e('Cliente', 'mad-oficina'); ?></th><?php foreach ( $months as $m ) echo '<th class="' . ($m === $now ? 'is-now' : '') . '">' . esc_html(Months::label($m)) . '</th>'; ?></tr></thead>
    <tbody>
    <?php foreach ( $f['rows'] as $r ) : if ( array_sum($r['expected']) + array_sum($r['possible']) < 0.01 ) continue; ?>
        <tr>
            <td><?php if ( $r['code'] ) echo '<span class="madof-code">' . esc_html($r['code']) . '</span> '; ?><?php echo esc_html($r['label']); ?>
                <?php foreach ( $r['quotes'] as $q ) echo '<br><small><a href="' . esc_url($base . '&tab=quotes&quote=' . $q['id']) . '">' . esc_html($q['number'] . ' · ' . $q['title'] . ' · ' . $q['probability'] . ' %') . '</a></small>'; ?></td>
            <?php foreach ( $months as $m ) :
                $e = $r['expected'][$m]; $p = $r[$possible_key][$m];
                $gap = $m < $now && $r['confirmed'][$m] - $r['real'][$m] >= 1; ?>
                <td class="<?php echo $m < $now ? 'is-past' : ''; ?> <?php echo $m === $now ? 'is-now' : ''; ?>">
                    <?php echo $e ? esc_html($short($e)) : '<span class="madof-muted">·</span>'; ?>
                    <?php if ( $gap ) echo '<br><small class="madof-gap" title="' . esc_attr(sprintf(__('Acordado %s', 'mad-oficina'), Money::format($r['confirmed'][$m]))) . '">' . esc_html(sprintf(__('falta %s', 'mad-oficina'), $short($r['confirmed'][$m] - $r['real'][$m]))) . '</small>'; ?>
                    <?php if ( $p >= 0.01 ) echo '<br><small class="madof-possible">+' . esc_html($short($p)) . '</small>'; ?>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><th><?php esc_html_e('Facturado / confirmado', 'mad-oficina'); ?></th><?php foreach ( $months as $m ) echo '<td><strong>' . esc_html($short($f['totals']['expected'][$m])) . '</strong></td>'; ?></tr>
        <tr><th><?php echo $weighted_view ? esc_html__('Posible ponderado', 'mad-oficina') : esc_html__('Posible', 'mad-oficina'); ?></th><?php foreach ( $months as $m ) echo '<td>' . esc_html($short($f['totals'][$possible_key][$m])) . '</td>'; ?></tr>
        <tr><th><?php esc_html_e('Total', 'mad-oficina'); ?></th><?php foreach ( $months as $m ) echo '<td><strong>' . esc_html($short($f['totals']['expected'][$m] + $f['totals'][$possible_key][$m])) . '</strong></td>'; ?></tr>
    </tfoot>
</table>
</div>
<p class="description"><?php esc_html_e('Meses pasados: lo facturado. Este mes: lo facturado o lo acordado, lo que sea mayor. Meses que vienen: fees y encargos de Acuerdos. «+» en gris: presupuestos enviados. «falta»: acordado pero aún sin facturar. Importes sin IVA; solo lo ves tú.', 'mad-oficina'); ?></p>
