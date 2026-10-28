<?php
/**
 * «Revisar y enviar»: one draft, or a rectifying one and the invoice that replaces it.
 * @var int[] $send_ids @var array $settings @var \MAD_Oficina\Billing\Issuing $issuing
 */
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-facturacion');
?>
<div class="wrap madof-wrap">
    <p><a href="<?php echo esc_url($base); ?>">← <?php esc_html_e('Facturas', 'mad-oficina'); ?></a></p>
    <?php AdminUi::header(__('Facturación', 'mad-oficina'), count($send_ids) > 1 ? __('Revisar y enviar las dos', 'mad-oficina') : __('Revisar y enviar', 'mad-oficina'),
        __('Así saldrá, con el número que le va a tocar. Nada se emite ni se envía hasta que pulses el botón.', 'mad-oficina')); ?>
    <?php if ( ($rep = \MAD_Oficina\Billing\Issuing::replacement_of((int) reset($send_ids))) ) { $here = 0; include __DIR__ . '/replacement.php'; } ?>
    <?php include __DIR__ . '/invoice-send.php'; ?>
</div>
