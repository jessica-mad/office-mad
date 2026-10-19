<?php
/**
 * Módulo: Google Chat (bot «MAD Oficina»).
 * Tarjetas de aprobación para Jessica, tareas para Liz y resúmenes para clientes,
 * siempre como bot. Modo pruebas activado por defecto.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const APPROVAL_HOOK = 'madoficina_chat_approval';
    const MEETING_HOOK  = 'madoficina_chat_meeting';
    const NOTICE_HOOK   = 'madoficina_chat_autonomy_notice';
    const NOTE_HOOK     = 'madoficina_chat_note';

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Chat\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'chat'; }
    public function title(){ return __('Google Chat', 'mad-oficina'); }
    public function menu_label(){ return __('Chat', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-chat'; }
    public function description(){
        return __('Bot «MAD Oficina» en Google Chat: te avisa y te deja aprobar desde Chat, envía a Liz sus tareas y publica los resúmenes en los espacios de los clientes. Siempre habla como bot.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function save_settings(array $s){
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
    }

    public function api(){
        $sa = \MAD_Oficina\Chat\ServiceAccount::from_settings($this->settings());
        return $sa ? new \MAD_Oficina\Chat\ChatApi($sa) : null;
    }

    public function messenger(){
        return new \MAD_Oficina\Chat\Messenger($this->settings(), $this->api());
    }

    public function portal_url(){
        return $this->settings()['portal_url'] ?? home_url('/oficina/');
    }

    /* ==== Hooks ==== */
    public function init(){
        // Sends run in the background so approving never waits for Google.
        add_action('madoficina_approval_created', fn($id) => $this->queue(self::APPROVAL_HOOK, [(int) $id]));
        add_action('madoficina_meeting_approved', fn($id, $counts = [], $ctx = []) => $this->queue(self::MEETING_HOOK, [(int) $id, ! empty($ctx['notify_team']), ! empty($ctx['send_summary'])]), 10, 3);
        add_action('madoficina_autonomous_action', fn($id, $result = []) => $this->queue(self::NOTICE_HOOK, [(int) $id]), 10, 2);
        add_action(self::APPROVAL_HOOK, [$this, 'send_approval_card']);
        // Plain note to Jessica from any module (e.g. «today the Argentine invoice is due»).
        add_action('madoficina_notify_manager', fn($text) => $this->queue(self::NOTE_HOOK, [(string) $text]));
        add_action(self::NOTE_HOOK, [$this, 'send_note']);
        add_action(self::NOTICE_HOOK, [$this, 'send_autonomy_notice']);
        add_action(self::MEETING_HOOK, [$this, 'send_meeting_messages'], 10, 3);
        // What Jessica or Liz tell the bot about their tasks (words or voice).
        add_action(\MAD_Oficina\Chat\Assistant::HOOK, fn($email, $text, $voice = []) => (new \MAD_Oficina\Chat\Assistant($this->messenger(), $this->api(), $this->settings()))->run($email, $text, (array) $voice), 10, 3);

        add_action('madoficina_client_edit_sections', [$this, 'render_client_section']);
        foreach ( ['chat_settings', 'chat_test', 'client_chat_targets', 'chat_assistant'] as $a ) add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
        add_action('admin_post_madof_voice_failed', ['MAD_Oficina\\Chat\\Transcriber', 'serve_failed']); // the audio Google could not read, to look at it
    }

    public function admin_init(){}

    public function rest_routes(){
        (new \MAD_Oficina\Chat\Events($this->settings(), [$this, 'save_settings']))->register();
    }

    private function queue($hook, array $args){
        if ( function_exists('as_enqueue_async_action') ) as_enqueue_async_action($hook, $args, 'mad-oficina');
        else wp_schedule_single_event(time(), $hook, $args);
    }

    private function log_error($event, $message, $client_id = null){
        global $wpdb;
        $wpdb->insert(\MAD_Oficina\Core\Schema::table('agent_log'), [
            'agent' => 'chat', 'event' => $event, 'client_id' => $client_id,
            'data' => wp_json_encode(['error' => $message]), 'created_at' => \MAD_Oficina\Core\Time::now(),
        ]);
    }

    /** New approval → card in Jessica's DM. */
    public function send_approval_card($approval_id){
        global $wpdb;
        $a = \MAD_Oficina\Core\Approvals::get($approval_id);
        if ( ! $a || $a['status'] !== 'pending' ) return;
        $lines = [];
        if ( $a['action_type'] === 'meeting_draft' && class_exists('MAD_Oficina\\Meetings\\Meetings') ) {
            $m = \MAD_Oficina\Meetings\Meetings::get($a['payload']['meeting_id'] ?? 0);
            if ( $m ) {
                $x = $m['extraction'];
                $lines[] = sprintf(__('%1$d tareas · %2$d ideas · %3$d temas pendientes', 'mad-oficina'), count($x['tasks'] ?? []), count($x['ideas'] ?? []), count($x['pending_topics'] ?? []));
                if ( ! $m['client_id'] ) $lines[] = __('No he identificado el cliente: revísalo en la web.', 'mad-oficina');
            }
        }
        $res = $this->messenger()->to_manager(\MAD_Oficina\Chat\Cards::approval($a, admin_url('admin.php?page=mad-oficina-bandeja'), $lines));
        if ( is_wp_error($res) ) { $this->log_error('approval_card', $res->get_error_message(), $a['client_id']); return; }
        $wpdb->update(\MAD_Oficina\Core\Schema::table('approvals'), ['chat_message_name' => $res['name'] ?? null], ['id' => $a['id']]);
    }

    public function send_note($text){
        $res = $this->messenger()->to_manager(['text' => \MAD_Oficina\Chat\Cards::who('accountant') . ': ' . $text]);
        if ( is_wp_error($res) ) $this->log_error('note', $res->get_error_message());
    }

    /** An action that ran on its own ("hacerlo y avisarme") → short note to Jessica. */
    public function send_autonomy_notice($approval_id){
        $a = \MAD_Oficina\Core\Approvals::get($approval_id);
        if ( ! $a || $a['status'] !== 'approved' ) return;
        $done = $a['resolution']['result']['message'] ?? $a['title'];
        $res = $this->messenger()->to_manager(['text' => sprintf(__('Hecho sin preguntarte (lo tienes en autonomía): %s', 'mad-oficina'), $done)]);
        if ( is_wp_error($res) ) $this->log_error('autonomy_notice', $res->get_error_message(), $a['client_id']);
    }

    /** Approved meeting → Liz's tasks by DM and, if checked, the summary to the client. */
    public function send_meeting_messages($meeting_id, $notify_team, $send_summary){
        global $wpdb;
        $m = class_exists('MAD_Oficina\\Meetings\\Meetings') ? \MAD_Oficina\Meetings\Meetings::get($meeting_id) : null;
        if ( ! $m || ! $m['client_id'] ) return;
        $client = \MAD_Oficina\Core\Repo\Clients::get($m['client_id']);
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . \MAD_Oficina\Core\Schema::table('action_items') . ' WHERE meeting_id = %d ORDER BY id', $meeting_id), ARRAY_A);
        $messenger = $this->messenger();

        if ( $notify_team ) {
            foreach ( $items as $t ) {
                if ( ! $t['team_member_id'] || $t['visibility'] === 'internal' ) continue;
                $member = \MAD_Oficina\Core\Repo\Team::get((int) $t['team_member_id']);
                if ( ! $member ) continue;
                $res = $messenger->to_team_member($member, \MAD_Oficina\Chat\Cards::task_for_team($t, $client, $this->portal_url()));
                if ( is_wp_error($res) ) $this->log_error('team_task', $res->get_error_message(), $client['id']);
            }
        }

        if ( $send_summary && $m['visibility'] === 'client' ) {
            $visible = array_filter($items, fn($t) => $t['visibility'] === 'client');
            $text = \MAD_Oficina\Chat\Cards::client_summary(
                $m, $client,
                (array) ($m['extraction']['client_decisions'] ?? []),
                array_values(array_filter($visible, fn($t) => $t['owner'] === 'cliente')),
                array_values(array_filter($visible, fn($t) => $t['owner'] !== 'cliente')),
                $this->portal_url()
            );
            $res = $messenger->to_client($client, 'summaries', $text, sprintf(__('Resumen de la reunión · %s', 'mad-oficina'), $m['title']));
            if ( is_wp_error($res) ) {
                $this->log_error('client_summary', $res->get_error_message(), $client['id']);
                $messenger->to_manager(['text' => sprintf(__('No he podido enviar el resumen de «%1$s» a %2$s: %3$s', 'mad-oficina'), $m['title'], $client['name'], $res->get_error_message())]);
            }
        }
    }

    /* ==== Admin ==== */

    private function guard($action){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_' . $action);
    }

    private function back($url, $notice, $type = 'success'){
        \MAD_Oficina\Core\AdminUi::flash($notice, $type);
        wp_safe_redirect($url);
        exit;
    }

    public function handle_chat_settings(){
        $this->guard('chat_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        $url = admin_url('admin.php?page=' . $this->menu_slug());

        $json = trim((string) ($p['service_account'] ?? ''));
        if ( $json !== '' ) {
            $ok = \MAD_Oficina\Chat\ServiceAccount::validate_json($json);
            if ( is_wp_error($ok) ) $this->back($url, $ok->get_error_message(), 'error');
            $s['service_account'] = \MAD_Oficina\Core\Crypto::encrypt($json);
        }
        $s['project_number'] = preg_replace('/\D/', '', (string) ($p['project_number'] ?? ''));
        $s['manager_email'] = sanitize_email($p['manager_email'] ?? '');
        $s['portal_url'] = esc_url_raw($p['portal_url'] ?? '') ?: home_url('/oficina/');
        $s['test_mode'] = ! empty($p['test_mode']);
        $s['test_space'] = self::parse_space($p['test_space_link'] ?? '') ?: self::parse_space($p['test_space'] ?? '');
        $this->save_settings($s);
        $this->back($url, __('Ajustes de Chat guardados.', 'mad-oficina'));
    }

    /** «Tu asistente»: name, personality and what she remembers. */
    public function handle_chat_assistant(){
        $this->guard('chat_assistant');
        $p = wp_unslash($_POST);
        $url = admin_url('admin.php?page=' . $this->menu_slug()) . '#asistente';
        $email = $this->messenger()->manager_email();
        $mine = strtolower(wp_get_current_user()->user_email) === $email; // her memory is hers, even for other administrators
        if ( ( ! empty($p['forget']) || ! empty($p['clear_history']) ) && ! $mine ) $this->back($url, __('Esa memoria es de Jessica.', 'mad-oficina'), 'error');
        if ( ! empty($p['forget']) ) {
            \MAD_Oficina\Chat\Memory::forget($email, sanitize_key($p['forget']));
            $this->back($url, __('Olvidado.', 'mad-oficina'));
        }
        if ( ! empty($p['clear_history']) ) {
            \MAD_Oficina\Chat\Memory::clear_history($email);
            $this->back($url, __('Conversación borrada: la próxima vez empieza de cero.', 'mad-oficina'));
        }
        $s = $this->settings();
        $s['assistant_name'] = mb_substr(sanitize_text_field($p['assistant_name'] ?? ''), 0, 40);
        $s['assistant_personality'] = mb_substr(sanitize_textarea_field($p['assistant_personality'] ?? ''), 0, 800);
        $this->save_settings($s);
        $this->back($url, __('Tu asistente, guardada.', 'mad-oficina'));
    }

    public function handle_chat_test(){
        $this->guard('chat_test');
        $url = admin_url('admin.php?page=' . $this->menu_slug());
        $settings = $this->settings();
        $api = $this->api();
        if ( ! $api ) $this->back($url, __('Falta la clave de la cuenta de servicio.', 'mad-oficina'), 'error');

        $report = [];
        $ok = true;
        // 1. Direct message to Jessica.
        $dm = $settings['manager_dm'] ?? '';
        if ( ! $dm ) {
            $report[] = __('Chat directo: no enlazado (escríbele «hola» al bot).', 'mad-oficina');
            $ok = false;
        } else {
            $r = $api->create_message($dm, ['text' => __('Hola, soy el asistente de MAD Oficina. Si lees esto, el bot funciona.', 'mad-oficina')]);
            $report[] = is_wp_error($r) ? sprintf(__('Chat directo (%1$s): ERROR · %2$s', 'mad-oficina'), $dm, $r->get_error_message()) : sprintf(__('Chat directo: enviado (%s)', 'mad-oficina'), $dm);
            if ( is_wp_error($r) ) { $ok = false; $this->log_error('test_dm', $r->get_error_message()); }
        }
        // 2. Test space.
        $space = $settings['test_space'] ?? '';
        if ( ! $space ) {
            $report[] = __('Espacio de pruebas: no elegido. Elígelo y pulsa Guardar antes de probar.', 'mad-oficina');
            $ok = false;
        } else {
            $r = $api->create_message($space, ['text' => '*' . __('[PRUEBA]', 'mad-oficina') . '* ' . __('Así verás aquí los mensajes que irían a los clientes.', 'mad-oficina') . "\n\n_" . \MAD_Oficina\Chat\Cards::SIGNATURE . '_']);
            $report[] = is_wp_error($r) ? sprintf(__('Espacio de pruebas (%1$s): ERROR · %2$s', 'mad-oficina'), $space, $r->get_error_message()) : sprintf(__('Espacio de pruebas: enviado (%s)', 'mad-oficina'), $space);
            if ( is_wp_error($r) ) { $ok = false; $this->log_error('test_space', $r->get_error_message()); }
        }
        $this->back($url, implode(' · ', $report), $ok ? 'success' : 'error');
    }

    public function handle_client_chat_targets(){
        $this->guard('client_chat_targets');
        $p = wp_unslash($_POST);
        $client = \MAD_Oficina\Core\Repo\Clients::get(absint($p['client_id'] ?? 0));
        if ( ! $client ) wp_die(esc_html__('Cliente no encontrado.', 'mad-oficina'));
        $spaces = $this->spaces();
        $labels = is_array($spaces) ? array_column($spaces, 'title', 'name') : [];
        $targets = [];
        foreach ( array_keys(\MAD_Oficina\Chat\Messenger::KINDS) as $kind ) {
            $type = sanitize_key($p['targets'][$kind]['type'] ?? 'none');
            $space = self::parse_space($p['targets'][$kind]['link'] ?? '') ?: self::parse_space($p['targets'][$kind]['space'] ?? '');
            $hook = trim((string) ($p['targets'][$kind]['webhook'] ?? ''));
            $current = \MAD_Oficina\Chat\Messenger::target($client, $kind);
            if ( $type === 'space' && $space ) {
                $targets[$kind] = ['type' => 'space', 'value' => $space, 'label' => $labels[$space] ?? $space];
            } elseif ( $type === 'webhook' && preg_match('#^https://chat\.googleapis\.com/v1/spaces/[^/]+/messages\?\S+$#', $hook) ) {
                $targets[$kind] = ['type' => 'webhook', 'value' => \MAD_Oficina\Core\Crypto::encrypt($hook), 'label' => 'Webhook']; // the URL is a secret
            } elseif ( $type === 'webhook' && $hook === '' && $current['type'] === 'webhook' ) {
                $targets[$kind] = $current; // keep the stored URL
            } elseif ( $type === 'email' ) {
                $targets[$kind] = ['type' => 'email', 'value' => ''];
            }
        }
        global $wpdb;
        $wpdb->update(\MAD_Oficina\Core\Schema::table('clients'), ['chat_targets' => wp_json_encode($targets), 'updated_at' => \MAD_Oficina\Core\Time::now()], ['id' => $client['id']]);
        $this->back(admin_url('admin.php?page=mad-oficina-clientes&client=' . $client['id']), __('Destinos de mensajes guardados.', 'mad-oficina'));
    }

    /**
     * Spaces where the bot is a member, asked to Google on every page load so a
     * space the app was just added to shows up straight away.
     * @return array|\WP_Error|null
     */
    public function spaces(){
        $api = $this->api();
        return $api ? $api->list_spaces() : null;
    }

    /**
     * Accepts "spaces/AAAA", or a Chat link such as
     * https://mail.google.com/chat/u/0/#chat/space/AAAA or https://chat.google.com/room/AAAA.
     */
    public static function parse_space($value){
        $value = trim((string) $value);
        if ( preg_match('#^spaces/([A-Za-z0-9_-]+)$#', $value, $m) ) return 'spaces/' . $m[1];
        if ( preg_match('#(?:/space/|/room/|/spaces/)([A-Za-z0-9_-]{6,})#', $value, $m) ) return 'spaces/' . $m[1];
        return '';
    }

    public function render_client_section($client){
        if ( empty($client['id']) ) return;
        $spaces = $this->spaces();
        include __DIR__ . '/views/client-section.php';
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        $spaces = $this->spaces();
        $messenger = $this->messenger();
        include __DIR__ . '/views/settings.php';
    }
};
