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
            <p class="description"><?php esc_html_e('Nombres, cargos y saludos que ve el cliente ({nombre} = el nombre de quien entra). Los sprites siguen el formato de 112×96 px (7 columnas de 16 px × 3 filas de 32 px: abajo, arriba, derecha; columnas: caminar 1-3, teclear 1-2, leer 1-2) en assets/game/characters/{slug}.png.', 'mad-oficina'); ?></p>
            <table class="madof-table">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Sala', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Nombre', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Cargo', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Saludo en su primer plano', 'mad-oficina'); ?></th>
                    <th scope="col"><?php esc_html_e('Tipo', 'mad-oficina'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $current as $slug => $c ) : ?>
                    <tr>
                        <td><?php echo esc_html(Characters::ROOMS[$c['room']]); ?></td>
                        <td><input type="text" name="characters[<?php echo esc_attr($slug); ?>][name]" value="<?php echo esc_attr($c['name']); ?>" placeholder="<?php echo esc_attr($defaults[$slug]['name']); ?>"></td>
                        <td><input type="text" name="characters[<?php echo esc_attr($slug); ?>][role]" value="<?php echo esc_attr($c['role']); ?>" placeholder="<?php echo esc_attr($defaults[$slug]['role']); ?>"></td>
                        <td><input type="text" maxlength="140" name="characters[<?php echo esc_attr($slug); ?>][greeting]" value="<?php echo esc_attr($c['greeting'] ?? ''); ?>" placeholder="<?php echo esc_attr($defaults[$slug]['greeting'] ?? ''); ?>" aria-label="<?php echo esc_attr(sprintf(__('Saludo de %s', 'mad-oficina'), $c['name'])); ?>">
                            <?php if ( isset(Characters::ALT_GREETING[$slug]) ) : ?>
                                <small><?php echo esc_html(Characters::ALT_GREETING[$slug]); ?></small>
                                <input type="text" maxlength="140" name="characters[<?php echo esc_attr($slug); ?>][greeting_alt]" value="<?php echo esc_attr($c['greeting_alt'] ?? ''); ?>" placeholder="<?php echo esc_attr($defaults[$slug]['greeting_alt'] ?? ''); ?>" aria-label="<?php echo esc_attr(sprintf(__('Saludo de %1$s: %2$s', 'mad-oficina'), $c['name'], mb_strtolower(Characters::ALT_GREETING[$slug]))); ?>">
                            <?php endif; ?></td>
                        <td><span class="madof-pill <?php echo $c['ai'] ? '' : 'is-on'; ?>"><?php echo $c['ai'] ? esc_html__('IA', 'mad-oficina') : esc_html__('Persona', 'mad-oficina'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Recepcionista', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <input type="hidden" name="receptionist_model" value="<?php echo esc_attr($settings['receptionist_model'] ?? 'claude-haiku-5-5'); ?>"><div><?php echo \MAD_Oficina\Core\AdminUi::agents_link('recepcionista'); ?></div>
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
