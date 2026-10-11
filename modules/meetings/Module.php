<?php
/**
 * Módulo: Reuniones · Secretaria de actas.
 * Recibe reuniones del Apps Script (o importadas a mano), identifica al cliente,
 * extrae tareas/ideas/temas con Claude y deja un borrador en la bandeja.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const PROCESS_HOOK = 'madoficina_process_meeting';

    private $core;
    private $secretary;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Meetings\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'meetings'; }
    public function title(){ return __('Reuniones', 'mad-oficina'); }
    public function menu_label(){ return __('Reuniones', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-reuniones'; }
    public function description(){
        return __('Secretaria de actas: recibe tus reuniones de Meet (vía Apps Script o importadas), saca tareas, ideas y temas pendientes, y te deja el borrador en la bandeja.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function secretary(){
        if ( ! $this->secretary ) $this->secretary = new \MAD_Oficina\Meetings\Secretary($this->settings());
        return $this->secretary;
    }

    /* ==== Hooks ==== */
    public function init(){
        add_action(self::PROCESS_HOOK, fn($id) => $this->secretary()->process((int) $id));
        add_filter('madoficina_approval_handler_' . \MAD_Oficina\Meetings\Secretary::ACTION, fn() => [$this->secretary(), 'approve']);
        add_filter('madoficina_approval_form_' . \MAD_Oficina\Meetings\Secretary::ACTION, ['MAD_Oficina\\Meetings\\Secretary', 'render_form'], 10, 2);
        add_action('madoficina_approval_rejected_' . \MAD_Oficina\Meetings\Secretary::ACTION, ['MAD_Oficina\\Meetings\\Secretary', 'on_reject']);

        foreach ( ['meeting_import', 'meeting_process', 'meetings_settings'] as $action ){
            add_action('admin_post_madof_' . $action, [$this, 'handle_' . $action]);
        }
    }

    public function admin_init(){}

    public function rest_routes(){
        (new \MAD_Oficina\Meetings\Hooks(
            fn() => \MAD_Oficina\Meetings\Hooks::secret_from($this->settings()),
            fn($id) => $this->enqueue($id)
        ))->register();
    }

    /** Background processing (Action Scheduler; WP-Cron as a fallback). */
    public function enqueue($meeting_id){
        if ( function_exists('as_enqueue_async_action') ) {
            as_enqueue_async_action(self::PROCESS_HOOK, [(int) $meeting_id], 'mad-oficina');
        } else {
            wp_schedule_single_event(time(), self::PROCESS_HOOK, [(int) $meeting_id]);
        }
    }

    /* ==== Admin ==== */

    private function guard($action){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_' . $action);
    }

    private function back($args, $notice, $type = 'success'){
        \MAD_Oficina\Core\AdminUi::flash($notice, $type);
        wp_safe_redirect(add_query_arg(['page' => $this->menu_slug()] + $args, admin_url('admin.php')));
        exit;
    }

    public function handle_meeting_import(){
        $this->guard('meeting_import');
        $p = wp_unslash($_POST);
        $text = (string) ($p['transcript'] ?? '');
        if ( ! empty($_FILES['transcript_file']['tmp_name']) && is_uploaded_file($_FILES['transcript_file']['tmp_name']) ) {
            $ext = strtolower(pathinfo($_FILES['transcript_file']['name'], PATHINFO_EXTENSION));
            if ( ! in_array($ext, ['txt', 'md', 'vtt', 'srt'], true) ) $this->back(['tab' => 'import'], __('Sube un archivo .txt, .md, .vtt o .srt (o pega el texto).', 'mad-oficina'), 'error');
            $text = (string) file_get_contents($_FILES['transcript_file']['tmp_name'], false, null, 0, 2000000);
        }
        if ( trim($text) === '' ) $this->back(['tab' => 'import'], __('Pega la transcripción o las notas de Gemini.', 'mad-oficina'), 'error');

        $attendees = [];
        foreach ( preg_split('/[\s,;]+/', (string) ($p['attendees'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $email ) $attendees[] = ['email' => $email];
        $client_id = absint($p['client_id'] ?? 0);

        $id = \MAD_Oficina\Meetings\Meetings::upsert([
            'source'     => 'manual',
            'title'      => $p['title'] ?? '',
            'starts_at'  => ($p['date'] ?? '') ? $p['date'] . ' ' . (($p['time'] ?? '') ?: '10:00') : null,
            'attendees'  => $attendees,
            'client_id'  => $client_id && \MAD_Oficina\Core\Repo\Clients::get($client_id) ? $client_id : null,
            'transcript' => $text,
        ]);

        if ( ! empty($p['process_now']) ) {
            $r = $this->secretary()->process($id);
            if ( is_wp_error($r) ) $this->back(['meeting' => $id], $r->get_error_message(), 'error');
            $this->back(['meeting' => $id], __('Reunión procesada. El borrador te espera en la bandeja.', 'mad-oficina'));
        }
        $this->back(['meeting' => $id], __('Reunión importada.', 'mad-oficina'));
    }

    public function handle_meeting_process(){
        $this->guard('meeting_process');
        $id = absint($_POST['meeting_id'] ?? 0);
        $r = $this->secretary()->process($id);
        if ( is_wp_error($r) ) $this->back(['meeting' => $id], $r->get_error_message(), 'error');
        $this->back(['meeting' => $id], __('Reunión procesada. El borrador te espera en la bandeja.', 'mad-oficina'));
    }

    public function handle_meetings_settings(){
        $this->guard('meetings_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        if ( ! empty($p['regenerate_secret']) || empty($s['hook_secret']) ) {
            $s['hook_secret'] = \MAD_Oficina\Core\Crypto::encrypt(bin2hex(random_bytes(32)));
        }
        $s['secretary_model'] = isset(\MAD_Oficina\Core\Agents\ClaudeClient::PRICES[$p['secretary_model'] ?? '']) ? $p['secretary_model'] : \MAD_Oficina\Meetings\Secretary::DEFAULT_MODEL;
        $s['secretary_prompt'] = sanitize_textarea_field($p['secretary_prompt'] ?? '');
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'settings'], __('Ajustes guardados.', 'mad-oficina'));
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        $meeting_id = isset($_GET['meeting']) ? absint($_GET['meeting']) : 0;
        if ( $meeting_id ) {
            $meeting = \MAD_Oficina\Meetings\Meetings::get($meeting_id);
            if ( ! $meeting ) wp_die(esc_html__('Reunión no encontrada.', 'mad-oficina'));
            include __DIR__ . '/views/meeting.php';
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'list');
        include __DIR__ . '/views/meetings.php';
    }
};
