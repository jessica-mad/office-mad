<?php
/**
 * What will go out, before it goes: each draft drawn as it will be (with the number it will
 * take), the email that goes with it, and «Emitir y enviar» right here, without the inbox.
 * A rectifying invoice and the one that replaces it are shown together and go out together.
 * @var int[] $send_ids @var array $settings @var \MAD_Oficina\Billing\Issuing $issuing
 */
use MAD_Oficina\Billing\InvoicePdf;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Issuing;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$docs = array_values(array_filter(array_map(fn($id) => Invoices::get((int) $id), $send_ids), fn($i) => $i && $i['status'] === 'draft' && ! $i['number']));
usort($docs, fn($a, $b) => ($b['series'] === 'R') <=> ($a['series'] === 'R'));
if ( ! $docs ) { echo '<p class="madof-warn">' . esc_html__('No hay borradores que enviar: puede que ya se hayan emitido.', 'mad-oficina') . '</p>'; return; }
$base = admin_url('admin.php?page=mad-oficina-facturacion');
?>
<?php echo AdminUi::form_open('billing_send', 'madof-form madof-send'); ?>
    <?php foreach ( $docs as $inv ) :
        $number = Invoices::preview_number($inv);
        $mail = $issuing->email_for($inv, $inv['issue_date'], $number);
        $key = 'docs[' . (int) $inv['id'] . ']'; ?>
    <section class="madof-card madof-send-doc">
        <div class="madof-send-head">
            <div>
                <span class="madof-kicker"><?php echo esc_html($inv['series'] === 'R' ? __('Rectificativa', 'mad-oficina') : __('Factura', 'mad-oficina')); ?></span>
                <h2><?php echo esc_html(sprintf(__('Será la %1$s %2$s', 'mad-oficina'), $inv['series'] === 'R' ? __('rectificativa', 'mad-oficina') : __('factura', 'mad-oficina'), $number ?: '—')); ?></h2>
                <p><?php echo esc_html($inv['client_name']); ?> · <?php echo esc_html((string) $inv['client_tax_id']); ?> · <?php echo esc_html($inv['series'] === 'R' ? __('Diferencia', 'mad-oficina') : __('Total', 'mad-oficina')); ?> <strong><?php echo esc_html(Money::format($inv['total'])); ?></strong></p>
                <?php if ( $inv['series'] === 'R' ) : ?><p><?php echo esc_html(($inv['rectify_mode'] === 'substitution' ? __('Por sustitución', 'mad-oficina') : __('Por diferencias', 'mad-oficina')) . ' · ' . __('Motivo:', 'mad-oficina') . ' ' . ($inv['rectify_reason'] ?: '—')); ?></p><?php endif; ?>
            </div>
            <a class="madof-btn is-ghost" href="<?php echo esc_url($inv['rectifies_id'] ? add_query_arg(['rectify' => (int) $inv['rectifies_id'], 'draft' => (int) $inv['id']], $base) . ($inv['rectify_mode'] === 'substitution' ? '#datos' : '#rectificar') : add_query_arg('edit', (int) $inv['id'], $base)); ?>"><?php esc_html_e('Cambiar algo', 'mad-oficina'); ?></a>
        </div>
        <input type="hidden" name="<?php echo esc_attr($key); ?>[expected]" value="<?php echo esc_attr($number); ?>">
        <input type="hidden" name="<?php echo esc_attr($key); ?>[issue_date]" value="<?php echo esc_attr($inv['issue_date']); ?>">

        <div class="madof-send-grid">
            <?php echo InvoicePdf::preview_frame($inv, Issuing::issuer_for($inv, $settings)); ?>
            <div class="madof-send-mail">
                <span class="madof-kicker"><?php esc_html_e('El email', 'mad-oficina'); ?></span>
                <label><?php esc_html_e('Para', 'mad-oficina'); ?><input type="text" name="<?php echo esc_attr($key); ?>[to]" value="<?php echo esc_attr($mail['to']); ?>" placeholder="<?php esc_attr_e('email@cliente.com', 'mad-oficina'); ?>"></label>
                <?php if ( ! $mail['to'] ) : ?>
                    <p class="madof-warn"><?php esc_html_e('Sus datos fiscales no tienen email. Escríbelo aquí:', 'mad-oficina'); ?></p>
                    <label class="madof-check"><input type="checkbox" name="<?php echo esc_attr($key); ?>[save_email]" value="1" checked> <?php esc_html_e('Guardarlo en sus datos fiscales para la próxima', 'mad-oficina'); ?></label>
                <?php endif; ?>
                <label><?php esc_html_e('Asunto', 'mad-oficina'); ?><input type="text" name="<?php echo esc_attr($key); ?>[subject]" value="<?php echo esc_attr($mail['subject']); ?>"></label>
                <label><?php esc_html_e('Mensaje', 'mad-oficina'); ?><textarea name="<?php echo esc_attr($key); ?>[body]" rows="9"><?php echo esc_textarea($mail['body']); ?></textarea></label>
                <p class="description"><?php esc_html_e('Sale desde tu Gmail con el PDF adjunto, en unos minutos.', 'mad-oficina'); ?></p>
            </div>
        </div>
    </section>
    <?php endforeach; ?>

    <p class="madof-sticky-save">
        <button class="madof-btn" name="mode" value="send"><?php echo esc_html(count($docs) > 1 ? __('Emitir y enviar las dos', 'mad-oficina') : __('Emitir y enviar', 'mad-oficina')); ?></button>
        <button class="madof-btn is-ghost" name="mode" value="issue"><?php esc_html_e('Solo emitir (la envío yo)', 'mad-oficina'); ?></button>
        <small class="madof-muted"><?php echo esc_html(count($docs) > 1
            ? __('Primero la rectificativa y luego la nueva, cada una en su email. Al emitir se numeran, se guardan en Emitidas y se apuntan en el Libro MAD.', 'mad-oficina')
            : __('Al emitir se numera, se guarda en Emitidas y se apunta en el Libro MAD.', 'mad-oficina')); ?></small>
    </p>
</form>
