<?php
/** @var array $client @var array|\WP_Error|null $spaces */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Chat\Messenger;

if ( ! defined('ABSPATH') ) exit;
$types = ['none' => __('No enviar', 'mad-oficina'), 'space' => __('Espacio de Chat (bot)', 'mad-oficina'), 'webhook' => __('Webhook de un espacio', 'mad-oficina'), 'email' => __('Email a los contactos', 'mad-oficina')];
?>
<section class="madof-card">
    <span class="madof-kicker"><?php esc_html_e('Mensajes del bot', 'mad-oficina'); ?></span>
    <p class="description"><?php esc_html_e('Dónde publica el asistente de MAD para este cliente. Siempre escribe como bot, nunca en tu nombre, y solo después de que lo apruebes. Para usar un espacio: créalo tú desde tu cuenta, invita al cliente y añade la app «MAD Oficina».', 'mad-oficina'); ?></p>
    <?php if ( is_wp_error($spaces) ) : ?><p class="madof-warn"><?php echo esc_html($spaces->get_error_message()); ?></p><?php endif; ?>
    <?php echo AdminUi::form_open('client_chat_targets'); ?>
        <input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>">
        <?php foreach ( Messenger::KINDS as $kind => $label ) : $t = Messenger::target($client, $kind); ?>
        <div class="madof-cols">
            <label><?php echo esc_html($label); ?>
                <select name="targets[<?php echo esc_attr($kind); ?>][type]">
                    <?php foreach ( $types as $v => $l ) : ?><option value="<?php echo esc_attr($v); ?>" <?php selected($t['type'], $v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                </select></label>
            <label><?php esc_html_e('Espacio', 'mad-oficina'); ?>
                <?php if ( is_array($spaces) && $spaces ) : ?>
                    <select name="targets[<?php echo esc_attr($kind); ?>][space]">
                        <option value=""><?php esc_html_e('— elige un espacio donde esté el bot —', 'mad-oficina'); ?></option>
                        <?php foreach ( $spaces as $s ) : if ( in_array($s['type'], ['DIRECT_MESSAGE', 'DM'], true) ) continue; ?>
                            <option value="<?php echo esc_attr($s['name']); ?>" <?php selected($t['type'] === 'space' ? $t['value'] : '', $s['name']); ?>><?php echo esc_html(($s['title'] ?: $s['name']) . ($s['external'] ? ' · ' . __('externo', 'mad-oficina') : '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text" name="targets[<?php echo esc_attr($kind); ?>][space]" value="<?php echo esc_attr($t['type'] === 'space' ? $t['value'] : ''); ?>" placeholder="spaces/AAAA…">
                    <small><?php esc_html_e('Cuando el bot esté configurado verás aquí la lista de espacios donde lo has añadido.', 'mad-oficina'); ?></small>
                <?php endif; ?></label>
            <label><?php esc_html_e('URL del webhook (solo si eliges webhook)', 'mad-oficina'); ?>
                <input type="password" name="targets[<?php echo esc_attr($kind); ?>][webhook]" value="" autocomplete="off" placeholder="<?php echo $t['type'] === 'webhook' ? esc_attr__('Guardada (déjalo vacío para mantenerla)', 'mad-oficina') : 'https://chat.googleapis.com/v1/spaces/…/messages?key=…'; ?>"></label>
        </div>
        <?php endforeach; ?>
        <button class="madof-btn"><?php esc_html_e('Guardar destinos', 'mad-oficina'); ?></button>
    </form>
</section>
