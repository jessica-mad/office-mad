<?php
/**
 * Rectify part of an issued invoice: what each concept should have been, what was missing,
 * and why. «Ver cómo queda» draws the rectifying invoice right below, ready to send.
 * @var array $original @var array|null $rect_draft @var array $settings @var \MAD_Oficina\Billing\Issuing $issuing
 */
use MAD_Oficina\Billing\Issuing;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-facturacion');
$old = Issuing::lines_of($original);
$state = $rect_draft ? (array) get_transient('madof_rectify_form_' . (int) $rect_draft['id']) : [];
$num = fn($n) => number_format((float) $n, 2, ',', '');
$added = array_values((array) ($state['added'] ?? []));
while ( count($added) < 2 ) $added[] = ['description' => '', 'amount' => ''];
?>
<div class="wrap madof-wrap">
    <p><a href="<?php echo esc_url(add_query_arg('invoice', (int) $original['id'], $base)); ?>">← <?php echo esc_html(sprintf(__('Factura %s', 'mad-oficina'), $original['number'])); ?></a></p>
    <?php AdminUi::header(__('Facturación', 'mad-oficina'), sprintf(__('Rectificar la factura %s', 'mad-oficina'), $original['number']),
        sprintf(__('%1$s · %2$s · total %3$s. Escribe lo que debería haber puesto: la rectificativa lleva solo las diferencias. La factura original no cambia nunca.', 'mad-oficina'), $original['client_name'], date_i18n('j/m/Y', strtotime($original['issue_date'])), Money::format($original['total']))); ?>

    <?php echo AdminUi::form_open('billing_rectify_lines', 'madof-form madof-card madof-rectify'); ?>
        <input type="hidden" name="invoice_id" value="<?php echo (int) $original['id']; ?>">
        <input type="hidden" name="draft_id" value="<?php echo (int) ($rect_draft['id'] ?? 0); ?>">
        <?php if ( ! $old ) : ?>
            <p class="madof-warn"><?php esc_html_e('Esta factura no tiene conceptos ni base imponible guardados: no sé qué rectificar.', 'mad-oficina'); ?></p>
        <?php else : ?>
        <table class="madof-table madof-rectify-lines">
            <thead><tr><th><?php esc_html_e('Concepto', 'mad-oficina'); ?></th><th class="num"><?php esc_html_e('Facturado', 'mad-oficina'); ?></th><th class="num"><?php esc_html_e('Debería ser', 'mad-oficina'); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $old as $i => $l ) : $c = (array) ($state['lines'][$i] ?? []); ?>
                <tr>
                    <td><textarea name="lines[<?php echo (int) $i; ?>][description]" rows="2" aria-label="<?php esc_attr_e('Concepto', 'mad-oficina'); ?>"><?php echo esc_textarea($c['description'] ?? $l['description']); ?></textarea></td>
                    <td class="num"><?php echo esc_html(Money::format($l['quantity'] * $l['unit_price'])); ?></td>
                    <td class="num"><input type="text" inputmode="decimal" name="lines[<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr($c['amount'] ?? $num($l['quantity'] * $l['unit_price'])); ?>" aria-label="<?php esc_attr_e('Importe correcto', 'mad-oficina'); ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tbody data-added>
            <?php foreach ( $added as $j => $a ) : ?>
                <tr>
                    <td><textarea name="added[<?php echo (int) $j; ?>][description]" rows="1" placeholder="<?php esc_attr_e('Concepto que faltaba (opcional)', 'mad-oficina'); ?>"><?php echo esc_textarea($a['description'] ?? ''); ?></textarea></td>
                    <td class="num madof-muted">—</td>
                    <td class="num"><input type="text" inputmode="decimal" name="added[<?php echo (int) $j; ?>][amount]" value="<?php echo esc_attr($a['amount'] ?? ''); ?>" aria-label="<?php esc_attr_e('Importe', 'mad-oficina'); ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="madof-btn is-ghost" data-add-line><?php esc_html_e('+ Otro concepto', 'mad-oficina'); ?></button></p>
        <div class="madof-cols">
            <label><?php esc_html_e('Motivo (va impreso en la factura)', 'mad-oficina'); ?>
                <input type="text" name="reason" required maxlength="500" value="<?php echo esc_attr($state['reason'] ?? ($rect_draft['rectify_reason'] ?? '')); ?>" placeholder="<?php esc_attr_e('Ej.: Error en el importe de la gestión de campañas de septiembre.', 'mad-oficina'); ?>"></label>
            <label><?php esc_html_e('Fecha de la rectificativa', 'mad-oficina'); ?>
                <input type="date" name="date" value="<?php echo esc_attr($rect_draft['issue_date'] ?? ''); ?>">
                <small><?php esc_html_e('Vacía: hoy (o la de la última rectificativa, si es posterior).', 'mad-oficina'); ?></small></label>
        </div>
        <p>
            <button class="madof-btn" name="whole" value=""><?php esc_html_e('Ver cómo queda', 'mad-oficina'); ?></button>
            <button class="madof-btn is-ghost" name="whole" value="1" formnovalidate onclick="var r=this.form.reason; if(!r.value.trim()){ r.reportValidity(); return false; }"><?php esc_html_e('Anularla entera', 'mad-oficina'); ?></button>
        </p>
        <?php endif; ?>
    </form>

    <?php if ( $rect_draft ) : ?>
        <div id="rectificar"></div>
        <?php $send_ids = [(int) $rect_draft['id']]; include __DIR__ . '/invoice-send.php'; ?>
    <?php endif; ?>
</div>
<script>
document.querySelector('[data-add-line]')?.addEventListener('click', function(){
    var body = document.querySelector('[data-added]'), row = body.lastElementChild.cloneNode(true), n = body.children.length;
    row.querySelectorAll('textarea,input').forEach(function(el){ el.value = ''; el.name = el.name.replace(/added\[\d+\]/, 'added[' + n + ']'); });
    body.appendChild(row);
});
</script>
