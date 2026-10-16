<?php
/** @var array $settings @var string $tab */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Agents\ClaudeClient;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Meetings\Hooks;
use MAD_Oficina\Meetings\Meetings;
use MAD_Oficina\Meetings\Secretary;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-reuniones');
$tabs = ['list' => __('Reuniones', 'mad-oficina'), 'import' => __('Importar', 'mad-oficina'), 'settings' => __('Apps Script y ajustes', 'mad-oficina')];
$statuses = [
    'received' => __('Recibida', 'mad-oficina'), 'processing' => __('Procesando', 'mad-oficina'), 'extracted' => __('En la bandeja', 'mad-oficina'),
    'approved' => __('Aprobada', 'mad-oficina'), 'archived' => __('Archivada', 'mad-oficina'), 'no_transcript' => __('Sin transcripción', 'mad-oficina'),
    'error' => __('Error', 'mad-oficina'), 'discarded' => __('Descartada', 'mad-oficina'),
];
$clients = Clients::all();
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Sala de reuniones', 'mad-oficina'), __('Reuniones', 'mad-oficina'), __('Cada reunión pasa por la Secretaria de actas: identifica al cliente, saca tareas, ideas y temas pendientes, y te deja el borrador en la bandeja.', 'mad-oficina')); ?>

    <nav class="madof-tabs" aria-label="<?php esc_attr_e('Secciones', 'mad-oficina'); ?>">
        <?php foreach ( $tabs as $key => $label ) : ?>
            <a href="<?php echo esc_url($base . '&tab=' . $key); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ( $tab === 'import' ) : ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Importar a mano', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Pega las notas de Gemini (con la pestaña de transcripción) o una transcripción, o sube una conversación exportada de WhatsApp. La Secretaria sacará tareas, temas e ideas a la bandeja.', 'mad-oficina'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="madof-form">
            <?php wp_nonce_field('madof_meeting_import'); ?>
            <input type="hidden" name="action" value="madof_meeting_import">
            <fieldset class="madof-fieldset"><legend><?php esc_html_e('¿Qué vas a importar?', 'mad-oficina'); ?></legend>
                <label class="madof-check"><input type="radio" name="kind" value="meeting" checked> <?php esc_html_e('Una reunión (notas de Gemini o transcripción)', 'mad-oficina'); ?></label>
                <label class="madof-check"><input type="radio" name="kind" value="whatsapp"> <?php esc_html_e('Una conversación de WhatsApp (en el chat: ⋮ → Más → Exportar chat → Sin archivos)', 'mad-oficina'); ?></label>
                <label><?php esc_html_e('Solo mensajes desde (WhatsApp)', 'mad-oficina'); ?><input type="date" name="since" value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('-14 days'))); ?>">
                    <small><?php esc_html_e('El archivo trae todo el historial: así solo se procesan los mensajes recientes.', 'mad-oficina'); ?></small></label>
            </fieldset>
            <div class="madof-cols">
                <label><?php esc_html_e('Título', 'mad-oficina'); ?><input type="text" name="title" placeholder="RF x MAD · o vacío para WhatsApp"></label>
                <label><?php esc_html_e('Fecha', 'mad-oficina'); ?><input type="date" name="date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label>
                <label><?php esc_html_e('Hora', 'mad-oficina'); ?><input type="time" name="time"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Cliente', 'mad-oficina'); ?>
                    <select name="client_id"><option value=""><?php esc_html_e('Detectarlo automáticamente', 'mad-oficina'); ?></option>
                    <?php foreach ( $clients as $c ) : ?><option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html($c['code'] . ' · ' . $c['name']); ?></option><?php endforeach; ?></select></label>
                <label><?php esc_html_e('Emails de los invitados (opcional)', 'mad-oficina'); ?><input type="text" name="attendees" placeholder="ana@cliente.com, otro@gmail.com"></label>
            </div>
            <label><?php esc_html_e('Notas de Gemini / transcripción', 'mad-oficina'); ?><textarea name="transcript" rows="10"></textarea></label>
            <label><?php esc_html_e('…o súbela como archivo (.txt, .zip de WhatsApp, .md, .vtt, .srt)', 'mad-oficina'); ?><input type="file" name="transcript_file" accept=".txt,.zip,.md,.vtt,.srt"></label>
            <label class="madof-check"><input type="checkbox" name="process_now" value="1" checked> <?php esc_html_e('Procesarla ahora (tarda entre 20 y 90 segundos)', 'mad-oficina'); ?></label>
            <button class="madof-btn"><?php esc_html_e('Importar', 'mad-oficina'); ?></button>
        </form>
    </section>

    <?php elseif ( $tab === 'settings' ) : $secret = Hooks::secret_from($settings); ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Conexión con el Apps Script', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Copia estos dos valores en las propiedades del script (Configuración del proyecto → Propiedades del script). El código del script está en apps-script/ del plugin.', 'mad-oficina'); ?></p>
        <table class="madof-table"><tbody>
            <tr><td><code>MAD_ENDPOINT</code></td><td><code class="madof-copy"><?php echo esc_html(untrailingslashit(rest_url('mad-oficina/v1'))); ?></code></td></tr>
            <tr><td><code>MAD_SECRET</code></td><td><?php if ( $secret ) : ?><code class="madof-copy"><?php echo esc_html($secret); ?></code><?php else : ?><em><?php esc_html_e('Guarda los ajustes para generarlo.', 'mad-oficina'); ?></em><?php endif; ?></td></tr>
        </tbody></table>
    </section>
    <?php echo \MAD_Oficina\Meetings\Cartero::render(); // phpcs:ignore -- escaped inside ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_meetings_settings'); ?>
        <input type="hidden" name="action" value="madof_meetings_settings">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Secretaria de actas', 'mad-oficina'); ?></span>
            <label><?php esc_html_e('Modelo', 'mad-oficina'); ?>
                <select name="secretary_model"><?php foreach ( ClaudeClient::PRICES as $model => $price ) : ?>
                    <option value="<?php echo esc_attr($model); ?>" <?php selected($settings['secretary_model'] ?? Secretary::DEFAULT_MODEL, $model); ?>><?php echo esc_html(sprintf('%s · $%s / $%s por millón', $model, $price[0], $price[1])); ?></option>
                <?php endforeach; ?></select>
                <small><?php esc_html_e('Con Sonnet 5.5, una reunión de una hora cuesta unos 0,05–0,10 $.', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('Indicaciones adicionales', 'mad-oficina'); ?>
                <textarea name="secretary_prompt" rows="5" placeholder="<?php esc_attr_e('Ej.: «En RF, Candy es Candela Rodríguez». «Las tareas de subir anuncios son siempre de Jessica».', 'mad-oficina'); ?>"><?php echo esc_textarea($settings['secretary_prompt'] ?? ''); ?></textarea>
                <small><?php esc_html_e('Se añaden al final de sus instrucciones. Las reglas de seguridad no se pueden quitar.', 'mad-oficina'); ?></small></label>
            <?php if ( $secret ) : ?><label class="madof-check"><input type="checkbox" name="regenerate_secret" value="1"> <?php esc_html_e('Generar un secreto nuevo (tendrás que pegarlo otra vez en el script)', 'mad-oficina'); ?></label><?php endif; ?>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>

    <?php else : $list = Meetings::latest(60); $last = (int) get_option('madoficina_meetings_last_run'); ?>
        <p class="description"><?php echo $last
            ? esc_html(sprintf(__('Procesado automático: la última revisión fue hace %s. Las reuniones nuevas se procesan solas en unos minutos.', 'mad-oficina'), human_time_diff($last)))
            : esc_html__('Procesado automático: todavía no se ha ejecutado ninguna revisión. Se activa con el Apps Script (cada 10 min) o con el cron de WordPress.', 'mad-oficina'); ?></p>
        <?php if ( ! $list ) : ?>
            <div class="madof-card is-muted"><p><?php esc_html_e('Todavía no hay reuniones. Conecta el Apps Script o importa una a mano.', 'mad-oficina'); ?></p></div>
        <?php else : ?>
        <table class="madof-table">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Fecha', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Reunión', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Cliente', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Origen', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Estado', 'mad-oficina'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $list as $m ) : $c = $m['client_id'] ? Clients::get($m['client_id']) : null; ?>
                <tr>
                    <td><?php echo esc_html($m['starts_at'] ? get_date_from_gmt($m['starts_at'], 'j M Y H:i') : '—'); ?></td>
                    <td><a href="<?php echo esc_url($base . '&meeting=' . $m['id']); ?>"><strong><?php echo esc_html($m['title']); ?></strong></a></td>
                    <td><?php echo $c ? '<span class="madof-code">' . esc_html($c['code']) . '</span>' : '—'; ?></td>
                    <td><?php echo $m['source'] === 'apps_script' ? 'Apps Script' : ($m['source'] === 'whatsapp' ? 'WhatsApp' : esc_html__('Manual', 'mad-oficina')); ?></td>
                    <td><span class="madof-pill <?php echo in_array($m['status'], ['extracted', 'error', 'no_transcript'], true) ? 'is-on' : ''; ?>"><?php echo esc_html($statuses[$m['status']] ?? $m['status']); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
