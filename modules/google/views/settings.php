<?php
/** @var \MAD_Oficina\Google\OAuth $oauth */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Crypto;

if ( ! defined('ABSPATH') ) exit;
$s = $oauth->settings();
$missing = $oauth->is_connected() ? $oauth->missing_scopes() : [];
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Conexiones', 'mad-oficina'), __('Google, Claude y seguridad', 'mad-oficina')); ?>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Google Workspace', 'mad-oficina'); ?></span>
        <?php if ( $oauth->is_connected() ) : ?>
            <p class="madof-status is-ok"><?php echo AdminUi::icon('check'); ?>
                <?php echo esc_html(sprintf(__('Conectado como %s', 'mad-oficina'), $oauth->connected_email())); ?></p>
            <p class="description"><?php echo esc_html(sprintf(__('Permisos concedidos: %s', 'mad-oficina'), implode(', ', array_filter($oauth->granted_scopes())))); ?></p>
            <?php if ( $missing ) : ?>
                <p class="madof-warn"><?php echo esc_html(sprintf(__('Los módulos activos necesitan permisos nuevos (%s). Vuelve a conectar.', 'mad-oficina'), implode(', ', $missing))); ?></p>
            <?php endif; ?>
            <div class="madof-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('madof_google_connect'); ?>
                    <input type="hidden" name="action" value="madof_google_connect">
                    <button class="madof-btn"><?php esc_html_e('Volver a conectar', 'mad-oficina'); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('madof_google_disconnect'); ?>
                    <input type="hidden" name="action" value="madof_google_disconnect">
                    <button class="madof-btn is-ghost"><?php esc_html_e('Desconectar', 'mad-oficina'); ?></button>
                </form>
            </div>
        <?php else : ?>
            <p class="madof-status"><?php echo AdminUi::icon('cross'); ?> <?php esc_html_e('Sin conectar', 'mad-oficina'); ?></p>
            <?php if ( $oauth->has_credentials() ) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('madof_google_connect'); ?>
                    <input type="hidden" name="action" value="madof_google_connect">
                    <button class="madof-btn"><?php esc_html_e('Conectar con Google', 'mad-oficina'); ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <details class="madof-details" <?php echo $oauth->has_credentials() ? '' : 'open'; ?>>
            <summary><?php esc_html_e('Credenciales de la app OAuth', 'mad-oficina'); ?></summary>
            <ol class="madof-steps">
                <li><?php esc_html_e('En Google Cloud Console, crea un proyecto y en «Pantalla de consentimiento OAuth» elige el tipo Interna.', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('En «Credenciales», crea un ID de cliente OAuth de tipo «Aplicación web».', 'mad-oficina'); ?></li>
                <li><?php esc_html_e('Añade este URI de redirección autorizado:', 'mad-oficina'); ?>
                    <code class="madof-copy"><?php echo esc_html($oauth->redirect_uri()); ?></code></li>
            </ol>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
                <?php wp_nonce_field('madof_google_save'); ?>
                <input type="hidden" name="action" value="madof_google_save">
                <label><?php esc_html_e('Client ID', 'mad-oficina'); ?>
                    <input type="text" name="client_id" value="<?php echo esc_attr($s['client_id'] ?? ''); ?>" autocomplete="off"></label>
                <label><?php esc_html_e('Client secret', 'mad-oficina'); ?>
                    <input type="password" name="client_secret" value="" autocomplete="new-password"
                        placeholder="<?php echo ! empty($s['client_secret']) ? esc_attr__('Guardado (déjalo vacío para mantenerlo)', 'mad-oficina') : ''; ?>"></label>
                <label><?php esc_html_e('Dominio de Workspace', 'mad-oficina'); ?>
                    <input type="text" name="workspace_domain" value="<?php echo esc_attr($s['workspace_domain'] ?? 'imad.pro'); ?>">
                    <small><?php esc_html_e('Solo se aceptan cuentas de este dominio.', 'mad-oficina'); ?></small></label>
                <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
            </form>
        </details>
    </section>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Claude (Anthropic)', 'mad-oficina'); ?></span>
        <?php if ( defined('MAD_OFICINA_ANTHROPIC_KEY') && MAD_OFICINA_ANTHROPIC_KEY ) : ?>
            <p class="madof-status is-ok"><?php echo AdminUi::icon('check'); ?> <?php esc_html_e('Clave configurada en wp-config.php', 'mad-oficina'); ?></p>
        <?php else : ?>
            <p class="madof-status"><?php echo AdminUi::icon('cross'); ?> <?php esc_html_e('Falta la clave. Añade a wp-config.php:', 'mad-oficina'); ?></p>
            <code class="madof-copy">define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-…');</code>
        <?php endif; ?>
    </section>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Cifrado', 'mad-oficina'); ?></span>
        <?php if ( Crypto::has_dedicated_key() ) : ?>
            <p class="madof-status is-ok"><?php echo AdminUi::icon('check'); ?> <?php esc_html_e('Clave propia MAD_OFICINA_SECRET activa', 'mad-oficina'); ?></p>
        <?php else : ?>
            <p class="madof-status"><?php echo AdminUi::icon('key'); ?> <?php esc_html_e('Recomendado: añade una clave propia a wp-config.php (64 caracteres aleatorios). Si la añades después de conectar Google, tendrás que volver a conectar.', 'mad-oficina'); ?></p>
            <code class="madof-copy">define('MAD_OFICINA_SECRET', '<?php echo esc_html(bin2hex(random_bytes(32))); ?>');</code>
        <?php endif; ?>
    </section>
</div>
