<?php
/**
 * An Argentine invoice: made in ARCA, sent from here. Up goes ARCA's PDF, with its number and
 * date, and the cartero archives it and sends it from her Gmail like any other invoice.
 * @var array $inv @var array $settings
 */
use MAD_Oficina\Billing\InvoiceFiles;
use MAD_Oficina\Billing\Issuing;

if ( ! defined('ABSPATH') ) exit;
$ar_mail = (new Issuing(is_array($settings ?? null) ? $settings : []))->email_for($inv, $inv['issue_date'], (string) ($inv['external_id'] ?: '{numero}'));
$ar_has = InvoiceFiles::exists($inv['id']);
$ar_sent = $inv['status'] === 'sent';
?>
<section class="madof-card madof-ar-send" id="enviar">
    <span class="madof-kicker"><?php echo esc_html($inv['status'] === 'draft' ? __('Enviar la factura de Argentina', 'mad-oficina') : __('Reenviar la factura de Argentina', 'mad-oficina')); ?></span>
    <p class="description"><?php esc_html_e('Hazla en ARCA, descarga su PDF y súbelo aquí: queda emitida con su número y su fecha, se guarda en Emitidas y sale desde tu Gmail con el PDF de ARCA.', 'mad-oficina'); ?></p>
    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <input type="hidden" name="action" value="madof_billing_ar_send"><?php wp_nonce_field('madof_billing_ar_send'); ?>
        <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
        <div class="madof-cols">
            <label><?php esc_html_e('PDF de ARCA', 'mad-oficina'); ?><input type="file" name="pdf" accept="application/pdf" <?php echo $ar_has ? '' : 'required'; ?>>
                <?php if ( $ar_has ) : ?><small><?php esc_html_e('Ya está subido. Sube otro solo si quieres cambiarlo.', 'mad-oficina'); ?> <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=madof_billing_pdf&invoice=' . $inv['id']), 'madof_billing_pdf_' . $inv['id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Verlo', 'mad-oficina'); ?></a></small><?php endif; ?></label>
            <label><?php esc_html_e('Nº de factura en ARCA', 'mad-oficina'); ?><input type="text" name="external_id" maxlength="60" value="<?php echo esc_attr((string) $inv['external_id']); ?>" placeholder="0001-00000123"></label>
            <label><?php esc_html_e('Fecha de la factura', 'mad-oficina'); ?><input type="date" name="issue_date" value="<?php echo esc_attr($inv['issue_date']); ?>"></label>
        </div>
        <label><?php esc_html_e('Para', 'mad-oficina'); ?><input type="text" name="to" value="<?php echo esc_attr($ar_mail['to']); ?>" placeholder="email@cliente.com"></label>
        <?php if ( ! $ar_mail['to'] ) : ?><label class="madof-check"><input type="checkbox" name="save_email" value="1" checked> <?php esc_html_e('Guardar este email en sus datos fiscales para la próxima', 'mad-oficina'); ?></label><?php endif; ?>
        <label><?php esc_html_e('Asunto', 'mad-oficina'); ?><input type="text" name="subject" value="<?php echo esc_attr($ar_mail['subject']); ?>"></label>
        <label><?php esc_html_e('Mensaje', 'mad-oficina'); ?><textarea name="body" rows="7"><?php echo esc_textarea($ar_mail['body']); ?></textarea>
            <small><?php esc_html_e('Revisa el importe y la moneda del mensaje: la factura de ARCA manda.', 'mad-oficina'); ?></small></label>
        <p class="madof-actions">
            <button class="madof-btn" name="mode" value="send"><?php echo esc_html($ar_sent ? __('Reenviar con el PDF de ARCA', 'mad-oficina') : __('Guardar como emitida y enviar', 'mad-oficina')); ?></button>
            <?php if ( ! $ar_sent ) : ?><button class="madof-btn is-ghost" name="mode" value="issue"><?php esc_html_e('Solo guardarla (ya la envié yo)', 'mad-oficina'); ?></button><?php endif; ?>
        </p>
    </form>
</section>
