<?php
/** @var array $settings @var array|\WP_Error|null $spaces @var \MAD_Oficina\Chat\Messenger $messenger */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Chat\ServiceAccount;

if ( ! defined('ABSPATH') ) exit;
$sa = ServiceAccount::from_settings($settings);
$events_url = rest_url('mad-oficina/v1/chat/events');
$avatar = MAD_OFICINA_URL . 'assets/img/bot-avatar.png';
$check = fn($ok, $text) => '<li class="' . ($ok ? 'is-ok' : '') . '">' . AdminUi::icon($ok ? 'check' : 'cross') . ' ' . esc_html($text) . '</li>';
global $wpdb;
$errors = $wpdb->get_results("SELECT event, data, created_at FROM " . \MAD_Oficina\Core\Schema::table('agent_log') . " WHERE agent = 'chat' ORDER BY id DESC LIMIT 5", ARRAY_A);
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Google Chat', 'mad-oficina'), __('Bot «MAD Oficina»', 'mad-oficina'), __('El asistente escribe siempre como bot (Google lo marca con la etiqueta «App»), nunca en tu nombre. Nada sale hacia un cliente sin tu aprobación.', 'mad-oficina')); ?>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Estado', 'mad-oficina'); ?></span>
        <ul class="madof-checklist">
            <?php
            echo $check((bool) $sa, $sa ? sprintf(__('Cuenta de servicio: %s', 'mad-oficina'), $sa->email()) : __('Falta la clave de la cuenta de servicio', 'mad-oficina'));
            echo $check(! empty($settings['project_number']), ! empty($settings['project_number']) ? sprintf(__('Número de proyecto: %s', 'mad-oficina'), $settings['project_number']) : __('Falta el número de proyecto', 'mad-oficina'));
            echo $check(! empty($settings['manager_dm']), ! empty($settings['manager_dm']) ? sprintf(__('Tu chat directo con el bot está enlazado (%s)', 'mad-oficina'), $messenger->manager_email()) : sprintf(__('Escribe «hola» al bot desde %s para enlazar tu chat directo', 'mad-oficina'), $messenger->manager_email()));
            echo $check(! empty($settings['test_space']), ! empty($settings['test_space']) ? __('Espacio de pruebas elegido', 'mad-oficina') : __('Elige tu espacio de pruebas', 'mad-oficina'));
            ?>
        </ul>
        <?php if ( $messenger->test_mode() ) : ?>
            <p class="madof-warn"><?php esc_html_e('MODO PRUEBAS ACTIVO: todo lo que iría a un cliente se publica en tu espacio de pruebas, con el destino real indicado arriba.', 'mad-oficina'); ?></p>
        <?php endif; ?>
        <?php if ( $sa ) : ?>
        <?php echo AdminUi::form_open('chat_test', 'madof-inline'); ?><button class="madof-btn is-ghost"><?php esc_html_e('Enviar mensajes de prueba', 'mad-oficina'); ?></button></form>
        <?php endif; ?>
    </section>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_chat_settings'); ?>
        <input type="hidden" name="action" value="madof_chat_settings">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Ajustes', 'mad-oficina'); ?></span>
            <label class="madof-check"><input type="checkbox" name="test_mode" value="1" <?php checked($messenger->test_mode()); ?>> <strong><?php esc_html_e('Modo pruebas', 'mad-oficina'); ?></strong> — <?php esc_html_e('desviar a mi espacio de pruebas todo lo que iría a clientes', 'mad-oficina'); ?></label>
            <label><?php esc_html_e('Espacio de pruebas', 'mad-oficina'); ?>
                <?php $listed = is_array($spaces) ? array_values(array_filter($spaces, fn($s) => ! in_array($s['type'], ['DIRECT_MESSAGE', 'DM'], true))) : []; ?>
                <?php if ( $listed ) : ?>
                    <select name="test_space"><option value=""><?php esc_html_e('— elige —', 'mad-oficina'); ?></option>
                    <?php foreach ( $listed as $s ) : ?>
                        <option value="<?php echo esc_attr($s['name']); ?>" <?php selected($settings['test_space'] ?? '', $s['name']); ?>><?php echo esc_html($s['title'] ?: $s['name']); ?></option>
                    <?php endforeach; ?></select>
                <?php elseif ( ! empty($settings['test_space']) ) : ?>
                    <input type="hidden" name="test_space" value="<?php echo esc_attr($settings['test_space']); ?>">
                    <code><?php echo esc_html($settings['test_space']); ?></code>
                <?php endif; ?>
                <small><?php
                    if ( is_wp_error($spaces) ) echo esc_html($spaces->get_error_message()) . ' · ';
                    elseif ( is_array($spaces) && ! $listed ) echo esc_html__('El bot todavía no aparece en ningún espacio (solo en tu chat directo).', 'mad-oficina') . ' ';
                    esc_html_e('La lista son los espacios donde está el bot, consultados ahora mismo.', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('…o pega el enlace del espacio', 'mad-oficina'); ?>
                <input type="text" name="test_space_link" value="" placeholder="https://mail.google.com/chat/u/0/#chat/space/AAAA…">
                <small><?php esc_html_e('Abre el espacio en Chat y copia la dirección del navegador. Si lo rellenas, tiene prioridad sobre el desplegable.', 'mad-oficina'); ?></small></label>
            <div class="madof-cols">
                <label><?php esc_html_e('Número de proyecto de Google Cloud', 'mad-oficina'); ?><input type="text" name="project_number" value="<?php echo esc_attr($settings['project_number'] ?? ''); ?>" inputmode="numeric"></label>
                <label><?php esc_html_e('Tu email (quien puede aprobar desde Chat)', 'mad-oficina'); ?><input type="email" name="manager_email" value="<?php echo esc_attr($settings['manager_email'] ?? ''); ?>" placeholder="<?php echo esc_attr($messenger->manager_email()); ?>"></label>
                <label><?php esc_html_e('Dirección de la oficina (para los enlaces)', 'mad-oficina'); ?><input type="text" name="portal_url" value="<?php echo esc_attr($settings['portal_url'] ?? home_url('/oficina/')); ?>"></label>
            </div>
            <label><?php esc_html_e('Clave JSON de la cuenta de servicio', 'mad-oficina'); ?>
                <textarea name="service_account" rows="4" placeholder="<?php echo $sa ? esc_attr__('Guardada y cifrada (déjalo vacío para mantenerla)', 'mad-oficina') : '{ "type": "service_account", … }'; ?>"></textarea></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>

    <?php $persona = \MAD_Oficina\Chat\Orchestrator::persona($settings); $memail = $messenger->manager_email(); $mine = strtolower(wp_get_current_user()->user_email) === $memail; $facts = $mine ? \MAD_Oficina\Chat\Memory::facts($memail) : []; ?>
    <section class="madof-card" id="asistente">
        <span class="madof-kicker"><?php esc_html_e('Tu asistente', 'mad-oficina'); ?></span>
        <p><?php esc_html_e('Escríbele o mándale una nota de voz al bot como a una persona: «envíame la última factura de LCS», «¿qué acordamos con MOM sobre la web?», «ya mandé el presupuesto a Lanas». Ella pregunta a cada área (Finanzas, Actas, Proyectos), te va contando mientras y te contesta con los datos tal cual. Los cambios en tareas llevan «Deshacer». Nunca envía nada a clientes. Liz solo llega a sus tareas.', 'mad-oficina'); ?></p>
        <?php echo AdminUi::form_open('chat_assistant', 'madof-form'); ?>
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="assistant_name" value="<?php echo esc_attr($settings['assistant_name'] ?? ''); ?>" placeholder="<?php echo esc_attr(\MAD_Oficina\Chat\Orchestrator::DEFAULT_NAME); ?>"></label>
            </div>
            <label><?php esc_html_e('Su forma de ser', 'mad-oficina'); ?><textarea name="assistant_personality" rows="3" placeholder="<?php echo esc_attr(\MAD_Oficina\Chat\Orchestrator::DEFAULT_PERSONALITY); ?>"><?php echo esc_textarea($settings['assistant_personality'] ?? ''); ?></textarea>
                <small><?php esc_html_e('Cambia cómo te habla, nunca los datos: esos salen siempre de cada área, tal cual.', 'mad-oficina'); ?></small></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </form>
        <?php if ( $mine ) : ?>
        <p><strong><?php echo esc_html(sprintf(__('Lo que %s recuerda de ti', 'mad-oficina'), $persona['name'])); ?></strong>
            <small class="madof-muted"><?php esc_html_e('(se lo pides en el chat: «recuerda que a LCS lo llamo Luz Clara»)', 'mad-oficina'); ?></small></p>
        <?php if ( ! $facts ) : ?><p class="madof-muted"><?php esc_html_e('Nada todavía.', 'mad-oficina'); ?></p><?php else : ?>
            <ul class="madof-cartero-list">
            <?php foreach ( $facts as $f ) : ?>
                <li><?php echo esc_html($f['text']); ?> <?php echo AdminUi::form_open('chat_assistant', 'madof-inline'); ?><input type="hidden" name="forget" value="<?php echo esc_attr($f['id']); ?>"><button class="madof-link"><?php esc_html_e('Olvidar', 'mad-oficina'); ?></button></form></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php echo AdminUi::form_open('chat_assistant', 'madof-inline'); ?><input type="hidden" name="clear_history" value="1"><button class="madof-link"><?php esc_html_e('Borrar la conversación reciente', 'mad-oficina'); ?></button></form>
        <p class="description"><?php esc_html_e('Recuerda la conversación de los últimos 3 días para seguir el hilo («¿y la anterior?»).', 'mad-oficina'); ?></p>
        <?php endif; ?>
        <ul class="madof-checklist">
            <?php echo $check(\MAD_Oficina\Core\Agents\ClaudeClient::has_key(), __('Clave de Claude (para entender frases; sin ella solo entiende números: «la 2 y la 5 hechas»)', 'mad-oficina')); ?>
        </ul>
        <p class="description"><?php echo esc_html(sprintf(
            __('Para los audios: si Google no le pasa al bot su propia transcripción, el bot transcribe el audio con Speech-to-Text (hasta 1 minuto). Para eso, en Google Cloud (proyecto %1$s): activa «Cloud Speech-to-Text API» y, en IAM, da a %2$s el rol «Cloud Speech Client». Cuesta unos céntimos al mes.', 'mad-oficina'),
            $sa ? ($sa->project_id() ?: '—') : '—', $sa ? $sa->email() : __('la cuenta de servicio del bot', 'mad-oficina')
        )); ?></p>
        <?php
        $ast = get_option(\MAD_Oficina\Chat\Assistant::STATUS, []);
        $ago = fn($k) => ! empty($ast[$k]['at']) ? sprintf(__('hace %s', 'mad-oficina'), human_time_diff((int) $ast[$k]['at'])) : __('nunca', 'mad-oficina');
        if ( $ast ) : ?>
            <p><?php echo esc_html(sprintf(__('Último mensaje que entendí: recibido %1$s · contestado %2$s.', 'mad-oficina'), $ago('received'), $ago('answered'))); ?>
            <?php if ( ! empty($ast['error']) && (int) $ast['error']['at'] >= (int) ($ast['answered']['at'] ?? 0) ) : ?>
                <br><span class="madof-warn"><?php echo esc_html(sprintf(__('Último error (%1$s): %2$s', 'mad-oficina'), $ago('error'), $ast['error']['detail'])); ?></span>
            <?php endif; ?></p>
        <?php endif; ?>
        <p class="description"><?php echo esc_html(\MAD_Oficina\Chat\Assistant::finisher() ? __('Tu servidor contesta a Google primero y trabaja después: perfecto para audios largos.', 'mad-oficina') : __('Tu servidor trabaja mientras Google espera (máximo 30 s): si un audio tarda más, Chat puede decir que el bot no responde, pero la respuesta te llega igual unos segundos después.', 'mad-oficina')); ?></p>
        <?php $shape = get_option(\MAD_Oficina\Chat\Transcriber::SHAPE_OPTION); if ( $shape ) : ?>
            <p class="description"><?php echo esc_html(sprintf(__('Último audio recibido %s.', 'mad-oficina'), sprintf(__('hace %s', 'mad-oficina'), human_time_diff((int) $shape['at'])))); ?></p>
        <?php endif; ?>
    </section>

    <details class="madof-card madof-details" <?php echo $sa ? '' : 'open'; ?>>
        <summary><?php esc_html_e('Cómo crear el bot en Google Cloud (una vez)', 'mad-oficina'); ?></summary>
        <ol class="madof-steps">
            <li><?php esc_html_e('En console.cloud.google.com abre el mismo proyecto de la conexión de Google. En «APIs y servicios → Biblioteca» busca «Google Chat API» y pulsa Habilitar.', 'mad-oficina'); ?></li>
            <li><?php esc_html_e('«IAM y administración → Cuentas de servicio → Crear cuenta de servicio»: nombre «mad-oficina-bot», sin roles. Dentro, «Claves → Agregar clave → Crear clave nueva → JSON». Pega el contenido del archivo arriba.', 'mad-oficina'); ?>
                <br><small><?php esc_html_e('Si Google dice que la creación de claves está deshabilitada (política iam.disableServiceAccountKeyCreation), en «IAM y administración → Políticas de la organización» busca esa política y desactívala para este proyecto. Necesitas ser administradora de la organización.', 'mad-oficina'); ?></small></li>
            <li><?php esc_html_e('En la página de Google Chat API, pestaña «Configuración»:', 'mad-oficina'); ?>
                <ul>
                    <li><?php esc_html_e('Desmarca «Compilar esta app de Chat como complemento de Google Workspace» si aparece.', 'mad-oficina'); ?></li>
                    <li><?php esc_html_e('Nombre: MAD Oficina · Descripción: Asistente automático de MAD', 'mad-oficina'); ?></li>
                    <li><?php esc_html_e('URL del avatar:', 'mad-oficina'); ?> <code class="madof-copy"><?php echo esc_html($avatar); ?></code></li>
                    <li><?php esc_html_e('Funciones: «Recibir mensajes 1:1» y «Unirse a espacios y conversaciones en grupo».', 'mad-oficina'); ?></li>
                    <li><?php esc_html_e('Conexión: «URL del extremo HTTP»:', 'mad-oficina'); ?> <code class="madof-copy"><?php echo esc_html($events_url); ?></code></li>
                    <li><?php esc_html_e('Audiencia de autenticación: «Número de proyecto».', 'mad-oficina'); ?></li>
                    <li><?php esc_html_e('Visibilidad: solo personas concretas de tu dominio (tú y Liz).', 'mad-oficina'); ?></li>
                </ul></li>
            <li><?php esc_html_e('Copia el «Número del proyecto» (página de inicio del proyecto) y pégalo arriba.', 'mad-oficina'); ?></li>
            <li><?php esc_html_e('Cloudflare: añade a tu regla otra ruta, para que la expresión quede así:', 'mad-oficina'); ?>
                <code class="madof-copy">(starts_with(http.request.uri.path, "/wp-json/mad-oficina/v1/hooks/")) or (starts_with(http.request.uri.path, "/wp-json/mad-oficina/v1/chat/"))</code></li>
            <li><?php esc_html_e('En Google Chat: «Nuevo chat → Buscar apps → MAD Oficina» y escríbele «hola». Crea un espacio «Pruebas MAD Oficina» solo contigo, añade la app y elígelo arriba como espacio de pruebas.', 'mad-oficina'); ?></li>
        </ol>
    </details>

    <?php if ( $errors ) : ?>
    <section class="madof-card is-muted">
        <span class="madof-kicker"><?php esc_html_e('Últimos errores de envío', 'mad-oficina'); ?></span>
        <ul><?php foreach ( $errors as $e ) : $d = json_decode($e['data'], true); ?>
            <li><?php echo esc_html(get_date_from_gmt($e['created_at'], 'j M H:i') . ' · ' . ($d['error'] ?? $e['event'])); ?></li>
        <?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
</div>
