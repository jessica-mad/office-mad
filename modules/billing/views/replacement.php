<?php
/**
 * The three invoices of a change of fiscal details (original, rectifying, new), linked from any of them.
 * @var array $rep from Issuing::replacement_of() @var int $here the invoice being shown
 */
use MAD_Oficina\Billing\Money;

if ( ! defined('ABSPATH') ) exit;
$rep_base = admin_url('admin.php?page=mad-oficina-facturacion');
$rep_item = function($inv, $label) use ($rep_base, $here){
    if ( ! $inv ) return '<li>' . esc_html($label) . ': ' . esc_html__('borrada', 'mad-oficina') . '</li>';
    $url = $rep_base . ($inv['status'] === 'draft' ? '&edit=' : '&invoice=') . (int) $inv['id'];
    $what = $inv['number'] ? $inv['number'] : __('borrador sin número (aún no emitida)', 'mad-oficina');
    $text = esc_html($label) . ': ' . ((int) $inv['id'] === (int) $here ? '<strong>' . esc_html($what) . '</strong> ' . esc_html__('(esta)', 'mad-oficina') : '<a href="' . esc_url($url) . '">' . esc_html($what) . '</a>');
    return '<li>' . $text . ' · ' . esc_html($inv['client_name'] . ' · ' . $inv['client_tax_id'] . ' · ' . Money::format($inv['total'])) . '</li>';
};
$rep_drafts = array_filter([$rep['rectify'], $rep['new']], fn($i) => $i && $i['status'] === 'draft');
?>
<section class="madof-card madof-replacement">
    <span class="madof-kicker"><?php esc_html_e('Cambio de datos fiscales', 'mad-oficina'); ?></span>
    <ul>
        <?php echo $rep_item($rep['original'], __('Factura original (no se modifica)', 'mad-oficina')); ?>
        <?php echo $rep_item($rep['rectify'], __('Rectificativa que la anula (con los datos antiguos)', 'mad-oficina')); ?>
        <?php echo $rep_item($rep['new'], __('Factura nueva (con los datos nuevos)', 'mad-oficina')); ?>
    </ul>
    <p class="madof-muted"><?php echo $rep_drafts
        ? esc_html__('Falta emitir lo que está en borrador: abre cada uno y pulsa «Enviar ahora» (te pide aprobar en la bandeja, donde eliges si se envía al cliente). Al emitirse, cada una recibe su número.', 'mad-oficina')
        : esc_html__('Las dos están emitidas: la original queda anulada por la rectificativa.', 'mad-oficina'); ?></p>
</section>
