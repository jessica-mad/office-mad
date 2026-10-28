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
        <?php echo $rep_item($rep['original'], $rep['new'] ? __('Factura original (queda anulada)', 'mad-oficina') : __('Factura', 'mad-oficina')); ?>
        <?php if ( $rep['new'] ) : // made before rectifying by substitution: a cancelling one and a new invoice ?>
        <?php echo $rep_item($rep['rectify'], __('Rectificativa que la anula (con los datos antiguos)', 'mad-oficina')); ?>
        <?php echo $rep_item($rep['new'], __('Factura nueva (con los datos nuevos)', 'mad-oficina')); ?>
        <?php else : ?>
        <?php echo $rep_item($rep['rectify'], __('Rectificativa por sustitución (con los datos correctos)', 'mad-oficina')); ?>
        <li><?php echo ($c = \MAD_Oficina\Billing\Issuing::corrected_of((int) $rep['original']['id']))
            ? esc_html(sprintf(__('La factura %1$s ya lleva los datos buenos, con su mismo número y fecha (antes: %2$s · %3$s).', 'mad-oficina'), $rep['original']['number'], $c['from']['client_name'], $c['from']['client_tax_id']))
            : esc_html(sprintf(__('Al emitir la rectificativa, la factura %s se corrige con los datos buenos y conserva su número y fecha.', 'mad-oficina'), $rep['original']['number'])); ?></li>
        <?php endif; ?>
    </ul>
    <?php if ( $rep_drafts && $here ) : ?>
        <p><a class="madof-btn" href="<?php echo esc_url($rep_base . '&review=' . implode(',', array_map(fn($i) => (int) $i['id'], $rep_drafts))); ?>"><?php echo esc_html(count($rep_drafts) > 1 ? __('Ver cómo quedan y enviar las dos', 'mad-oficina') : __('Ver cómo queda y enviar', 'mad-oficina')); ?></a></p>
    <?php elseif ( ! $rep_drafts ) : ?>
        <p class="madof-muted"><?php echo $rep['new'] ? esc_html__('Las dos están emitidas: la original queda anulada por la rectificativa.', 'mad-oficina') : esc_html__('Emitida: la rectificativa sustituye los datos de la original.', 'mad-oficina'); ?></p>
    <?php endif; ?>
</section>
