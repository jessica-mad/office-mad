<?php
/**
 * Vista: gestión de módulos de MAD Oficina.
 */
if ( ! defined('ABSPATH') ) exit;

$core = MAD_Oficina_Core::instance();
$all_modules = $core->get_all_modules_info();
uasort($all_modules, fn($a, $b) => strcmp($a['title'], $b['title']));
?>
<div class="wrap madof-wrap">
    <header class="madof-hero">
        <span class="madof-kicker"><?php esc_html_e('MAD Oficina', 'mad-oficina'); ?></span>
        <h1><?php esc_html_e('Módulos', 'mad-oficina'); ?></h1>
        <p><?php esc_html_e('Todos los módulos vienen apagados. Enciéndelos uno a uno cuando estén listos: así nada cambia en la web hasta que tú lo decides.', 'mad-oficina'); ?></p>
    </header>
    <hr class="wp-header-end">

    <?php if ( isset($_GET['updated']) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Módulo actualizado.', 'mad-oficina'); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $all_modules ) : ?>
        <p><?php esc_html_e('No se han encontrado módulos.', 'mad-oficina'); ?></p>
    <?php else : ?>
    <div class="madof-grid">
        <?php foreach ( $all_modules as $info ) :
            $missing_plugins = [];
            foreach ( $info['required_plugins'] as $name => $file ) {
                if ( ! function_exists('is_plugin_active') ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
                if ( ! is_plugin_active($file) ) $missing_plugins[] = $name;
            }
            $missing_deps = array_diff($info['dependencies'], $core->get_enabled_modules());
            $can_enable = ! $missing_plugins && ! $missing_deps;
        ?>
        <div class="madof-card <?php echo $info['enabled'] ? 'is-on' : ''; ?>">
            <span class="madof-pill <?php echo $info['enabled'] ? 'is-on' : ''; ?>">
                <?php echo $info['enabled'] ? esc_html__('Activo', 'mad-oficina') : esc_html__('Apagado', 'mad-oficina'); ?>
            </span>
            <h2><?php echo esc_html($info['title']); ?></h2>
            <?php if ( $info['description'] ) : ?><p><?php echo esc_html($info['description']); ?></p><?php endif; ?>
            <?php if ( $missing_deps ) : ?>
                <p class="madof-warn"><?php echo esc_html(sprintf(__('Necesita activar antes: %s', 'mad-oficina'), implode(', ', $missing_deps))); ?></p>
            <?php endif; ?>
            <?php if ( $missing_plugins ) : ?>
                <p class="madof-warn"><?php echo esc_html(sprintf(__('Necesita el plugin: %s', 'mad-oficina'), implode(', ', $missing_plugins))); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('madof_toggle_module', 'madof_nonce'); ?>
                <input type="hidden" name="action" value="madof_toggle_module">
                <input type="hidden" name="module_slug" value="<?php echo esc_attr($info['slug']); ?>">
                <?php if ( $info['enabled'] ) : ?>
                    <input type="hidden" name="module_action" value="disable">
                    <button class="madof-btn is-ghost"><?php esc_html_e('Apagar', 'mad-oficina'); ?></button>
                <?php else : ?>
                    <input type="hidden" name="module_action" value="enable">
                    <button class="madof-btn" <?php disabled(! $can_enable); ?>><?php esc_html_e('Activar', 'mad-oficina'); ?></button>
                <?php endif; ?>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
