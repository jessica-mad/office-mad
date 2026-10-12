<?php
/**
 * @var array $settings @var array|null $lead @var array $leads @var string $tab @var string $filter
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Visitors\Leads;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-leads');
$sources = ['brief' => __('Brief en la web', 'mad-oficina'), 'meeting' => __('Primera reunión', 'mad-oficina'), 'portal' => __('Nuevo encargo (cliente)', 'mad-oficina'), 'chat' => __('Charla con la recepcionista', 'mad-oficina')];
$date = fn($d) => $d ? mysql2date('j M Y · H:i', get_date_from_gmt($d)) : '—';
$labels = fn(array $keys, array $map) => implode(', ', array_map(fn($k) => $map[$k] ?? $k, $keys));
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Recepción', 'mad-oficina'), __('Visitantes y leads', 'mad-oficina'), __('Briefs y peticiones de quien todavía no es cliente, y nuevos encargos de tus clientes. Un visitante solo aparece aquí cuando confirma su email.', 'mad-oficina')); ?>

    <nav class="madof-tabs" aria-label="<?php esc_attr_e('Secciones', 'mad-oficina'); ?>">
        <a href="<?php echo esc_url($base); ?>" class="<?php echo $tab !== 'settings' ? 'is-active' : ''; ?>"><?php esc_html_e('Leads', 'mad-oficina'); ?></a>
        <a href="<?php echo esc_url($base . '&tab=settings'); ?>" class="<?php echo $tab === 'settings' ? 'is-active' : ''; ?>"><?php esc_html_e('Ajustes', 'mad-oficina'); ?></a>
    </nav>

    <?php if ( $lead ) : ?>
    <p><a href="<?php echo esc_url($base); ?>">← <?php esc_html_e('Todos los leads', 'mad-oficina'); ?></a></p>
    <section class="madof-card">
        <span class="madof-kicker"><?php echo esc_html(($sources[$lead['source']] ?? $lead['source']) . ' · ' . $date($lead['created_at'])); ?></span>
        <h2><?php echo esc_html($lead['name']); ?><?php if ( $lead['company'] ) echo ' · ' . esc_html($lead['company']); ?></h2>
        <table class="madof-table"><tbody>
            <tr><th scope="row"><?php esc_html_e('Email', 'mad-oficina'); ?></th><td><a href="mailto:<?php echo esc_attr($lead['email']); ?>"><?php echo esc_html($lead['email']); ?></a>
                <?php echo $lead['verified_at'] ? ' <span class="madof-pill is-on">' . esc_html__('Confirmado', 'mad-oficina') . '</span>' : ' <span class="madof-pill">' . esc_html__('Sin confirmar', 'mad-oficina') . '</span>'; ?></td></tr>
            <?php if ( $lead['website'] ) : ?><tr><th scope="row"><?php esc_html_e('Web', 'mad-oficina'); ?></th><td><a href="<?php echo esc_url($lead['website']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($lead['website']); ?></a></td></tr><?php endif; ?>
            <?php if ( $lead['meeting_start'] ) : $ap = $lead['approval_id'] ? \MAD_Oficina\Core\Approvals::get($lead['approval_id']) : null; ?>
            <tr><th scope="row"><?php esc_html_e('Reunión pedida', 'mad-oficina'); ?></th><td><?php echo esc_html(\MAD_Oficina\Visitors\FirstMeeting::label($lead['meeting_start'])); ?>
                <?php if ( $ap ) : ?> · <?php echo esc_html(['pending' => __('en tu bandeja', 'mad-oficina'), 'processing' => __('creándose', 'mad-oficina'), 'approved' => __('aprobada', 'mad-oficina'), 'rejected' => __('descartada', 'mad-oficina')][$ap['status']] ?? $ap['status']); ?>
                    <?php if ( $ap['status'] === 'pending' ) : ?> <a href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-bandeja#approval-' . $ap['id'])); ?>"><?php esc_html_e('Abrir la bandeja', 'mad-oficina'); ?></a><?php endif; ?>
                <?php elseif ( ! $lead['verified_at'] ) : ?> · <?php esc_html_e('esperando a que confirme su email', 'mad-oficina'); ?><?php endif; ?></td></tr>
            <?php endif; ?>
            <tr><th scope="row"><?php esc_html_e('Necesita', 'mad-oficina'); ?></th><td><?php echo esc_html($labels($lead['services'], Leads::SERVICES) ?: '—'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Plazo', 'mad-oficina'); ?></th><td><?php echo esc_html(Leads::TIMELINES[$lead['timeline']] ?? '—'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Presupuesto', 'mad-oficina'); ?></th><td><?php echo esc_html(Leads::BUDGETS[$lead['budget'] ?? ''] ?? '—'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Consentimiento', 'mad-oficina'); ?></th><td><?php echo esc_html($lead['client_id'] && ! $lead['consent_at'] ? __('Cliente (contrato)', 'mad-oficina') : $date($lead['consent_at'])); ?></td></tr>
        </tbody></table>
        <?php if ( trim((string) $lead['message']) !== '' ) : ?>
        <h3><?php esc_html_e('Lo que nos cuenta', 'mad-oficina'); ?></h3>
        <div class="madof-quote"><?php echo nl2br(esc_html((string) $lead['message'])); ?></div>
        <?php endif; ?>

        <?php echo AdminUi::form_open('lead_update'); ?>
            <input type="hidden" name="lead_id" value="<?php echo (int) $lead['id']; ?>">
            <div class="madof-cols">
                <label><?php esc_html_e('Estado', 'mad-oficina'); ?>
                    <select name="status"><?php foreach ( Leads::STATUSES as $k => $l ) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($lead['status'], $k); ?>><?php echo esc_html__($l, 'mad-oficina'); ?></option><?php endforeach; ?></select></label>
            </div>
            <label><?php esc_html_e('Notas internas', 'mad-oficina'); ?><textarea name="notes" rows="3"><?php echo esc_textarea((string) $lead['notes']); ?></textarea></label>
            <div class="madof-actions"><button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button></div>
        </form>
        <div class="madof-actions">
            <?php if ( ! $lead['client_id'] ) : ?>
                <?php echo AdminUi::form_open('lead_convert', 'madof-inline'); ?><input type="hidden" name="lead_id" value="<?php echo (int) $lead['id']; ?>"><button class="madof-btn is-ghost"><?php esc_html_e('Convertir en cliente', 'mad-oficina'); ?></button></form>
            <?php else : ?>
                <a class="madof-btn is-ghost" href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-clientes&client=' . $lead['client_id'])); ?>"><?php esc_html_e('Ver ficha del cliente', 'mad-oficina'); ?></a>
            <?php endif; ?>
            <?php echo AdminUi::form_open('lead_delete', 'madof-inline'); ?><input type="hidden" name="lead_id" value="<?php echo (int) $lead['id']; ?>"><button class="madof-btn is-ghost" onclick="return confirm('<?php echo esc_js(__('¿Borrar este lead y todos sus datos?', 'mad-oficina')); ?>')"><?php esc_html_e('Borrar (RGPD)', 'mad-oficina'); ?></button></form>
        </div>
    </section>

    <?php elseif ( $tab === 'settings' ) : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_visitors_settings'); ?>
        <input type="hidden" name="action" value="madof_visitors_settings">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Cloudflare Turnstile', 'mad-oficina'); ?></span>
            <p class="description"><?php esc_html_e('Comprueba que quien escribe no es un robot, casi siempre sin que tenga que hacer nada. En Cloudflare: Turnstile → Add widget → dominio imad.pro, modo «Managed». Copia aquí las dos claves.', 'mad-oficina'); ?></p>
            <?php if ( empty($settings['turnstile_secret']) ) : ?><p class="madof-warn"><?php esc_html_e('Sin Turnstile, los formularios públicos solo tienen el campo trampa, los límites por IP y la confirmación por email.', 'mad-oficina'); ?></p><?php endif; ?>
            <div class="madof-cols">
                <label><?php esc_html_e('Clave del sitio (Site key)', 'mad-oficina'); ?><input type="text" name="turnstile_site" value="<?php echo esc_attr($settings['turnstile_site'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Clave secreta (Secret key)', 'mad-oficina'); ?><input type="password" name="turnstile_secret" value="" placeholder="<?php echo empty($settings['turnstile_secret']) ? '' : esc_attr__('Guardada (cifrada). Escribe para cambiarla.', 'mad-oficina'); ?>" autocomplete="off"></label>
            </div>
            <?php if ( ! empty($settings['turnstile_secret']) ) : ?><label class="madof-check"><input type="checkbox" name="turnstile_clear" value="1"> <?php esc_html_e('Quitar Turnstile', 'mad-oficina'); ?></label><?php endif; ?>
        </section>
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Páginas', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Política de privacidad', 'mad-oficina'); ?><input type="url" name="privacy_url" value="<?php echo esc_attr($settings['privacy_url'] ?? ''); ?>" placeholder="<?php echo esc_attr(get_privacy_policy_url() ?: 'https://imad.pro/privacidad/'); ?>">
                    <small><?php esc_html_e('Vacío = la página de privacidad de WordPress.', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Página de la oficina', 'mad-oficina'); ?><input type="url" name="portal_url" value="<?php echo esc_attr($settings['portal_url'] ?? home_url('/oficina/')); ?>"></label>
            </div>
        </section>
        <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
    </form>

    <?php else : ?>
    <nav class="madof-filters">
        <?php foreach ( ['' => __('Todos', 'mad-oficina')] + array_map(fn($l) => __($l, 'mad-oficina'), Leads::STATUSES) + ['unverified' => __('Sin confirmar', 'mad-oficina')] as $k => $l ) : ?>
            <a href="<?php echo esc_url($base . ($k ? '&status=' . $k : '')); ?>" class="madof-pill <?php echo $filter === $k ? 'is-on' : ''; ?>"><?php echo esc_html($l); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ( ! $leads ) : ?>
        <section class="madof-card"><p class="description"><?php esc_html_e('Aún no hay leads aquí. Llegarán desde la recepción de la oficina.', 'mad-oficina'); ?></p></section>
    <?php else : ?>
    <table class="madof-table">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Fecha', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Quién', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Necesita', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Origen', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Estado', 'mad-oficina'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $leads as $l ) : ?>
            <tr>
                <td><?php echo esc_html($date($l['created_at'])); ?></td>
                <td><a href="<?php echo esc_url($base . '&lead=' . $l['id']); ?>"><strong><?php echo esc_html($l['name']); ?></strong></a><?php if ( $l['company'] ) echo '<br><small>' . esc_html($l['company']) . '</small>'; ?></td>
                <td><?php echo esc_html($labels($l['services'], Leads::SERVICES) ?: '—'); ?></td>
                <td><?php echo esc_html($sources[$l['source']] ?? $l['source']); ?></td>
                <td><span class="madof-pill <?php echo $l['status'] === 'new' ? 'is-on' : ''; ?>"><?php echo esc_html(! $l['verified_at'] ? __('Sin confirmar', 'mad-oficina') : __(Leads::STATUSES[$l['status']] ?? $l['status'], 'mad-oficina')); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>
</div>
