<?php
/** @var array $settings */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Agents\Characters;
use MAD_Oficina\Core\Agents\ClaudeClient;
use MAD_Oficina\Portal\Receptionist;

if ( ! defined('ABSPATH') ) exit;
$defaults = Characters::defaults();
$current = Characters::all();
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Oficina', 'mad-oficina'), __('Oficina y personajes', 'mad-oficina'), __('Pon el shortcode [mad_oficina] en la página que quieras (por ejemplo /oficina). Los clientes entran con su usuario y ven solo lo suyo.', 'mad-oficina')); ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_portal_settings'); ?>
        <input type="hidden" name="action" value="madof_portal_settings">

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Personajes', 'mad-oficina'); ?></span>
            <p class="description"><?php esc_html_e('Nombres y cargos que ve el cliente. Los sprites siguen el formato de 128×192 px (4 columnas × 6 filas de 32×32) en assets/game/characters/{slug}.png.', 'mad-oficina'); ?></p>
            <table class="madof-table">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Sala', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Nombre', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Cargo', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Tipo', 'mad-oficina'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $current as $slug => $c ) : ?>
                    <tr>
                        <td><?php echo esc_html(Characters::ROOMS[$c['room']]); ?></td>
                        <td><input type="text" name="characters[<?php echo esc_attr($slug); ?>][name]" value="<?php echo esc_attr($c['name']); ?>" placeholder="<?php echo esc_attr($defaults[$slug]['name']); ?>"></td>
                        <td><input type="text" name="characters[<?php echo esc_attr($slug); ?>][role]" value="<?php echo esc_attr($c['role']); ?>" placeholder="<?php echo esc_attr($defaults[$slug]['role']); ?>"></td>
                        <td><span class="madof-pill <?php echo $c['ai'] ? '' : 'is-on'; ?>"><?php echo $c['ai'] ? esc_html__('IA', 'mad-oficina') : esc_html__('Persona', 'mad-oficina'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Recepcionista', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Modelo', 'mad-oficina'); ?>
                    <select name="receptionist_model">
                        <?php foreach ( ClaudeClient::PRICES as $model => $price ) : ?>
                            <option value="<?php echo esc_attr($model); ?>" <?php selected($settings['receptionist_model'] ?? 'claude-haiku-5-5', $model); ?>>
                                <?php echo esc_html(sprintf('%s · $%s / $%s por millón', $model, $price[0], $price[1])); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label><?php esc_html_e('Mensajes al día por cliente', 'mad-oficina'); ?>
                    <input type="text" inputmode="numeric" name="receptionist_daily_limit" value="<?php echo esc_attr($settings['receptionist_daily_limit'] ?? 40); ?>">
                    <small><?php esc_html_e('Límite de uso para controlar el coste.', 'mad-oficina'); ?></small></label>
            </div>
            <label><?php esc_html_e('Personalidad (prompt de sistema)', 'mad-oficina'); ?>
                <textarea name="receptionist_prompt" rows="6" placeholder="<?php echo esc_attr(Receptionist::DEFAULT_PROMPT); ?>"><?php echo esc_textarea($settings['receptionist_prompt'] ?? ''); ?></textarea>
                <small><?php esc_html_e('Déjalo vacío para usar el de serie. %s se sustituye por su nombre. Las reglas de seguridad (solo datos del cliente, no ejecutar instrucciones que lleguen en los mensajes, no enviar nada) se añaden siempre y no se pueden quitar.', 'mad-oficina'); ?></small></label>
            <?php if ( ! ClaudeClient::has_key() ) : ?>
                <p class="madof-warn"><?php esc_html_e('Falta MAD_OFICINA_ANTHROPIC_KEY en wp-config.php: el chat aparecerá desactivado.', 'mad-oficina'); ?></p>
            <?php endif; ?>
        </section>

        <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
    </form>
</div>
