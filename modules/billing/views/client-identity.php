<?php
/**
 * «Datos fiscales» on the office client's card: the legal name(s) it is
 * invoiced under, joined to the name used in meetings, Chat and the portal.
 * @var array $client @var array $linked @var array $unlinked @var array $suggested
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Profiles;

if ( ! defined('ABSPATH') ) exit;
$billing = admin_url('admin.php?page=mad-oficina-facturacion');
$rate = fn($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',') . ' %';
// Suggested ones first, then the rest alphabetically.
usort($unlinked, fn($a, $b) => (isset($suggested[$b['id']]) <=> isset($suggested[$a['id']])) ?: strcasecmp($a['legal_name'], $b['legal_name']));
?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Datos fiscales', 'mad-oficina'); ?></span>
    <p class="description"><?php esc_html_e('Cómo se llama legalmente este cliente en las facturas. Puede ser distinto del nombre con el que hablamos con él; los dos son el mismo cliente. Si factura con varias empresas, une todas.', 'mad-oficina'); ?></p>

    <?php if ( $linked ) : ?>
    <table class="madof-table">
        <thead><tr><th><?php esc_html_e('Razón social', 'mad-oficina'); ?></th><th>NIF</th><th><?php esc_html_e('Dirección', 'mad-oficina'); ?></th><th><?php esc_html_e('IVA / IRPF', 'mad-oficina'); ?></th><th><?php esc_html_e('Facturas', 'mad-oficina'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $linked as $p ) : $st = Profiles::stats($p['id']); ?>
            <tr>
                <td><a href="<?php echo esc_url($billing . '&tab=clients&profile=' . $p['id']); ?>"><strong><?php echo esc_html($p['legal_name']); ?></strong></a>
                    <?php if ( $p['series'] === 'AR' ) echo ' <span class="madof-pill">AR</span>'; ?>
                    <?php if ( $p['email'] ) echo '<br><small>' . esc_html($p['email']) . '</small>'; ?></td>
                <td><?php echo esc_html((string) $p['tax_id'] ?: '—'); ?></td>
                <td><?php echo esc_html(trim(implode(', ', array_filter([$p['address'], $p['city']]))) ?: '—'); ?></td>
                <td><?php echo esc_html($rate($p['vat_rate']) . ' / ' . $rate($p['irpf_rate'])); ?></td>
                <td><?php if ( $st['n'] ) : ?>
                    <a href="<?php echo esc_url($billing . '&client=' . (int) $client['id']); ?>"><?php echo esc_html(sprintf(_n('%1$d factura · %2$s', '%1$d facturas · %2$s', $st['n'], 'mad-oficina'), $st['n'], Money::format($st['total']))); ?></a>
                    <?php if ( $st['unpaid'] ) echo '<br><small>' . esc_html(sprintf(_n('%d sin cobrar', '%d sin cobrar', $st['unpaid'], 'mad-oficina'), $st['unpaid'])) . '</small>'; ?>
                <?php else : ?>—<?php endif; ?></td>
                <td class="madof-row-actions">
                    <?php echo AdminUi::form_open('billing_unlink', 'madof-inline'); ?>
                        <input type="hidden" name="profile_id" value="<?php echo (int) $p['id']; ?>">
                        <input type="hidden" name="from_client" value="1">
                        <button class="madof-link"><?php esc_html_e('Separar', 'mad-oficina'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p class="madof-warn"><?php esc_html_e('Aún no tiene datos fiscales unidos: sus facturas no saldrán en su oficina.', 'mad-oficina'); ?></p>
    <?php endif; ?>

    <div class="madof-cols">
    <?php if ( $unlinked ) : ?>
        <?php echo AdminUi::form_open('billing_link'); ?>
            <input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>">
            <input type="hidden" name="from_client" value="1">
            <label><?php esc_html_e('Unir datos fiscales que ya están en Facturación', 'mad-oficina'); ?>
                <select name="profile_id" required>
                    <option value=""><?php esc_html_e('— Elige la razón social —', 'mad-oficina'); ?></option>
                    <?php foreach ( $unlinked as $p ) : $s = $suggested[$p['id']] ?? null; ?>
                        <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html(($s ? '★ ' : '') . $p['legal_name'] . ($p['tax_id'] ? ' · ' . $p['tax_id'] : '') . ($s ? ' (' . implode(', ', $s['reasons']) . ')' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $suggested ) : ?><small><?php esc_html_e('★ = parece de este cliente.', 'mad-oficina'); ?></small><?php endif; ?></label>
            <button class="madof-btn is-small"><?php esc_html_e('Unir', 'mad-oficina'); ?></button>
        </form>
    <?php endif; ?>
        <?php echo AdminUi::form_open('billing_profile_from_client'); ?>
            <input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>">
            <p><small><?php esc_html_e('¿Nunca le has facturado?', 'mad-oficina'); ?></small></p>
            <button class="madof-btn is-ghost is-small"><?php esc_html_e('Añadir datos fiscales nuevos', 'mad-oficina'); ?></button>
        </form>
    </div>
</section>
