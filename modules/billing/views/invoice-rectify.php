<?php
/**
 * Rectify part of an issued invoice: what each concept should have been, what was missing,
 * and why. «Ver cómo queda» draws the rectifying invoice right below, ready to send.
 * @var array $original @var array|null $rect_draft @var array $settings @var \MAD_Oficina\Billing\Issuing $issuing
 */
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Issuing;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-facturacion');
$old = Issuing::lines_of($original);
// A draft of «Datos del cliente equivocados» (either way) or of «Importes o conceptos».
$data_rep = $rect_draft ? Issuing::replacement_of((int) $rect_draft['id']) : null;
$is_sub = (bool) $data_rep;
$next_f = Invoices::preview_number(['number' => null, 'series' => 'F', 'issue_date' => current_time('Y-m-d')]);
[, $now_profile] = Issuing::profiles_for_new_details($original);
$data_changed = $now_profile && Issuing::details_differ($original, $now_profile);
$state = $rect_draft && ! $is_sub ? (array) get_transient('madof_rectify_form_' . (int) $rect_draft['id']) : [];
$num = fn($n) => number_format((float) $n, 2, ',', '');
$added = array_values((array) ($state['added'] ?? []));
while ( count($added) < 2 ) $added[] = ['description' => '', 'amount' => ''];
?>
<div class="wrap madof-wrap">
    <p><a href="<?php echo esc_url(add_query_arg('invoice', (int) $original['id'], $base)); ?>">← <?php echo esc_html(sprintf(__('Factura %s', 'mad-oficina'), $original['number'])); ?></a></p>
    <?php AdminUi::header(__('Facturación', 'mad-oficina'), sprintf(__('Rectificar la factura %s', 'mad-oficina'), $original['number']),
        sprintf(__('%1$s · serie F · expedida el %2$s · total %3$s. La factura original no se toca nunca: la rectificativa (serie R) la identifica, dice el motivo y corrige lo que haga falta.', 'mad-oficina'), $original['client_name'], date_i18n('j/m/Y', strtotime($original['issue_date'])), Money::format($original['total']))); ?>

    <?php echo AdminUi::form_open('billing_new_details', 'madof-form madof-card madof-rectify'); ?>
        <span class="madof-kicker" id="datos"><?php esc_html_e('1 · Datos del cliente equivocados', 'mad-oficina'); ?></span>
        <p><?php esc_html_e('Los mismos conceptos e importes con los datos correctos del cliente. Lo que te debe no cambia.', 'mad-oficina'); ?></p>
        <input type="hidden" name="invoice_id" value="<?php echo (int) $original['id']; ?>">
        <table class="madof-table madof-rectify-who">
            <thead><tr><th></th><th><?php esc_html_e('En la factura', 'mad-oficina'); ?></th><th><?php esc_html_e('Datos correctos (su ficha)', 'mad-oficina'); ?></th></tr></thead>
            <tbody>
            <?php foreach ( ['client_name' => [__('Razón social', 'mad-oficina'), 'legal_name'], 'client_tax_id' => [__('NIF', 'mad-oficina'), 'tax_id'], 'client_address' => [__('Domicilio', 'mad-oficina'), 'address'], 'client_city' => [__('Localidad', 'mad-oficina'), 'city']] as $k => [$label, $pk] ) :
                $new = (string) ($now_profile[$pk] ?? ''); ?>
                <tr<?php echo $now_profile && trim($new) !== trim((string) $original[$k]) ? ' class="is-changed"' : ''; ?>><th><?php echo esc_html($label); ?></th><td><?php echo esc_html((string) $original[$k]); ?></td><td><?php echo esc_html($new ?: '—'); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( ! $data_changed ) : ?>
            <p class="madof-warn"><?php esc_html_e('Su ficha tiene los mismos datos que la factura. Corrígelos primero en Facturación → Clientes de facturación y vuelve aquí.', 'mad-oficina'); ?>
                <?php if ( $now_profile ) : ?><a href="<?php echo esc_url(add_query_arg(['tab' => 'clients', 'profile' => (int) $now_profile['id']], $base)); ?>"><?php esc_html_e('Abrir su ficha', 'mad-oficina'); ?></a><?php endif; ?></p>
        <?php else : ?>
            <label><?php esc_html_e('Motivo (va impreso en la factura)', 'mad-oficina'); ?>
                <textarea name="reason" rows="2" required maxlength="500"><?php echo esc_textarea($is_sub ? (string) $rect_draft['rectify_reason'] : Issuing::DEFAULT_DETAILS_REASON); ?></textarea></label>
            <?php $same = ! $data_rep || $data_rep['same']; ?>
            <fieldset class="madof-choice">
                <legend><?php esc_html_e('La factura con los datos buenos', 'mad-oficina'); ?></legend>
                <label class="madof-check"><input type="radio" name="target" value="same" <?php checked($same); ?>>
                    <span><strong><?php echo esc_html(sprintf(__('Con su mismo número y fecha: %1$s del %2$s', 'mad-oficina'), $original['number'], date_i18n('d/m/Y', strtotime($original['issue_date'])))); ?></strong><br>
                    <small><?php esc_html_e('La rectificativa (por sustitución, importe cero) deja constancia del cambio y la factura se corrige con los datos buenos. Es lo que suele pedir la gestoría.', 'mad-oficina'); ?></small></span></label>
                <label class="madof-check"><input type="radio" name="target" value="new" <?php checked(! $same); ?>>
                    <span><strong><?php echo esc_html(sprintf(__('Como factura nueva de la serie F (será la %s)', 'mad-oficina'), $next_f)); ?></strong><br>
                    <small><?php esc_html_e('La rectificativa anula la original entera y sale una factura nueva con su propio número y la fecha que elijas.', 'mad-oficina'); ?></small></span></label>
                <label class="madof-new-date"><?php esc_html_e('Fecha de la factura nueva', 'mad-oficina'); ?>
                    <input type="date" name="new_date" value="<?php echo esc_attr($data_rep && $data_rep['new'] ? $data_rep['new']['issue_date'] : current_time('Y-m-d')); ?>">
                    <small><?php esc_html_e('Solo si es factura nueva. No puede ser anterior a la última de la serie.', 'mad-oficina'); ?></small></label>
            </fieldset>
            <p><button class="madof-btn"><?php esc_html_e('Ver cómo queda', 'mad-oficina'); ?></button></p>
        <?php endif; ?>
    </form>

    <?php echo AdminUi::form_open('billing_rectify_lines', 'madof-form madof-card madof-rectify'); ?>
        <span class="madof-kicker" id="importes"><?php esc_html_e('2 · Importes o conceptos · por diferencias', 'mad-oficina'); ?></span>
        <p><?php esc_html_e('Escribe lo que debería haber puesto: la rectificativa lleva solo la diferencia (en positivo o en negativo).', 'mad-oficina'); ?></p>
        <input type="hidden" name="invoice_id" value="<?php echo (int) $original['id']; ?>">
        <input type="hidden" name="draft_id" value="<?php echo (int) ($rect_draft && ! $is_sub ? $rect_draft['id'] : 0); ?>">
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
                <input type="text" name="reason" required maxlength="500" value="<?php echo esc_attr($state['reason'] ?? ($rect_draft && ! $is_sub ? (string) $rect_draft['rectify_reason'] : '')); ?>" placeholder="<?php esc_attr_e('Ej.: Error en el importe de la gestión de campañas de septiembre.', 'mad-oficina'); ?>"></label>
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
        <?php $send_ids = $data_rep && $data_rep['new'] ? [(int) $rect_draft['id'], (int) $data_rep['new']['id']] : [(int) $rect_draft['id']]; include __DIR__ . '/invoice-send.php'; ?>
    <?php endif; ?>
</div>
<script>
document.querySelector('[data-add-line]')?.addEventListener('click', function(){
    var body = document.querySelector('[data-added]'), row = body.lastElementChild.cloneNode(true), n = body.children.length;
    row.querySelectorAll('textarea,input').forEach(function(el){ el.value = ''; el.name = el.name.replace(/added\[\d+\]/, 'added[' + n + ']'); });
    body.appendChild(row);
});
</script>
