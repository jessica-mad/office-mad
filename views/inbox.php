<?php
/**
 * Vista: bandeja de gerencia (aprobaciones de los agentes).
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Agents\Characters;
use MAD_Oficina\Core\OfficeState;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;

$pending = Approvals::pending();
$resolved = Approvals::recent_resolved(20);
$who = function($agent){
    $slug = OfficeState::AGENT_TO_CHARACTER[$agent] ?? null;
    $c = $slug ? Characters::get($slug) : null;
    return $c ? $c['name'] . ' · ' . $c['role'] : $agent;
};
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Despacho de gerencia', 'mad-oficina'), __('Bandeja', 'mad-oficina'), __('Aquí llega todo lo que los agentes quieren hacer. Nada sale hacia fuera ni aparece en el portal del cliente hasta que lo apruebas.', 'mad-oficina')); ?>

    <?php if ( ! $pending ) : ?>
        <div class="madof-card is-muted"><p><?php esc_html_e('No hay nada pendiente. Todo en orden.', 'mad-oficina'); ?></p></div>
    <?php endif; ?>

    <?php foreach ( $pending as $a ) :
        $client = $a['client_id'] ? Clients::get($a['client_id']) : null;
        $form = apply_filters('madoficina_approval_form_' . $a['action_type'], '', $a);
    ?>
    <section class="madof-card madof-approval" id="approval-<?php echo (int) $a['id']; ?>">
        <span class="madof-kicker"><?php echo esc_html($who($a['agent'])); ?> · <?php echo esc_html(mysql2date('j M Y H:i', get_date_from_gmt($a['created_at']))); ?></span>
        <h2><?php if ( $client ) : ?><span class="madof-code"><?php echo esc_html($client['code']); ?></span> <?php endif; ?><?php echo esc_html($a['title']); ?></h2>
        <?php if ( $a['summary'] ) : ?><p><?php echo esc_html($a['summary']); ?></p><?php endif; ?>
        <?php if ( $a['status'] === Approvals::PROCESSING ) : ?>
            <p class="madof-warn"><?php esc_html_e('Ejecutándose…', 'mad-oficina'); ?></p>
        <?php else : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
            <?php wp_nonce_field('madof_approval_' . $a['id']); ?>
            <input type="hidden" name="action" value="madof_approval_resolve">
            <input type="hidden" name="approval_id" value="<?php echo (int) $a['id']; ?>">
            <?php echo $form; // built by the module with escaped values ?>
            <div class="madof-actions">
                <button class="madof-btn" name="decision" value="approve"><?php echo AdminUi::icon('check'); ?> <?php esc_html_e('Aprobar', 'mad-oficina'); ?></button>
                <button class="madof-btn is-ghost" name="decision" value="reject" formnovalidate><?php esc_html_e('Descartar', 'mad-oficina'); ?></button>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <?php if ( $resolved ) : ?>
    <h2><?php esc_html_e('Resueltas recientemente', 'mad-oficina'); ?></h2>
    <table class="madof-table">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Fecha', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Quién', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Petición', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Resultado', 'mad-oficina'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $resolved as $a ) : ?>
            <tr>
                <td><?php echo esc_html(mysql2date('j M H:i', get_date_from_gmt($a['resolved_at']))); ?></td>
                <td><?php echo esc_html($who($a['agent'])); ?></td>
                <td><?php echo esc_html($a['title']); ?></td>
                <td><span class="madof-pill <?php echo $a['status'] === 'approved' ? 'is-on' : ''; ?>"><?php echo $a['status'] === 'approved' ? esc_html__('Aprobada', 'mad-oficina') : esc_html__('Descartada', 'mad-oficina'); ?></span>
                    <?php if ( ! empty($a['resolution']['result']['message']) ) : ?><br><small><?php echo esc_html($a['resolution']['result']['message']); ?></small><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
