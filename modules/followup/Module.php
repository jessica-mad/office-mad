<?php
/**
 * Módulo: Seguimiento · Coordinadora.
 * Recordatorios de tareas, ideas que vuelven, ficha previa a cada reunión con
 * cliente y resumen de los lunes. Todo te llega por Chat; lo del cliente, por la bandeja.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const CRON_HOOK = 'madoficina_followup_hourly';

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Followup\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'followup'; }
    public function title(){ return __('Seguimiento', 'mad-oficina'); }
    public function menu_label(){ return __('Seguimiento', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-seguimiento'; }
    public function description(){
        return __('Coordinadora: recordatorios de tareas para ti y para Liz, ideas que vuelven en su fecha, ficha 24 h antes de cada reunión con cliente y resumen de los lunes por Chat.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients', 'chat']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function coordinator(){
        return new \MAD_Oficina\Followup\Coordinator($this->settings());
    }

    /* ==== Hooks ==== */
    public function init(){
        // WP-Cron depends on visits; the Apps Script tick (every 10 min) is the reliable clock.
        add_action(self::CRON_HOOK, fn() => $this->coordinator()->run());
        add_action('madoficina_tick', fn() => $this->coordinator()->run());
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) wp_schedule_event(time() + 120, 'hourly', self::CRON_HOOK);

        $type = \MAD_Oficina\Followup\Coordinator::ACTION;
        add_filter('madoficina_approval_handler_' . $type, fn() => fn($a, $input) => $this->coordinator()->approve_client_reminder($a, $input));
        add_filter('madoficina_approval_form_' . $type, ['MAD_Oficina\\Followup\\Coordinator', 'render_client_reminder_form'], 10, 2);
        add_filter('madoficina_chat_action', ['MAD_Oficina\\Followup\\Coordinator', 'chat_action'], 10, 5);

        // Valle keeps each client's tasks clean after every new contact (approved meeting or WhatsApp).
        $review = \MAD_Oficina\Followup\Reconciler::ACTION;
        add_filter('madoficina_approval_handler_' . $review, fn() => ['MAD_Oficina\\Followup\\Reconciler', 'approve']);
        add_filter('madoficina_approval_form_' . $review, ['MAD_Oficina\\Followup\\Reconciler', 'render_form'], 10, 2);
        add_action('madoficina_meeting_approved', function($meeting_id){
            $m = class_exists('MAD_Oficina\\Meetings\\Meetings') ? \MAD_Oficina\Meetings\Meetings::get((int) $meeting_id) : null;
            if ( ! $m ) return;
            // A team meeting touches several clients: Valle reviews each one.
            global $wpdb;
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT DISTINCT client_id FROM ' . \MAD_Oficina\Core\Schema::table('action_items') . ' WHERE meeting_id = %d AND client_id IS NOT NULL', (int) $meeting_id)));
            foreach ( array_unique(array_filter(array_merge([(int) $m['client_id']], $ids))) as $cid ) \MAD_Oficina\Followup\Reconciler::enqueue($cid);
        }, 20);

        add_action('admin_post_madof_followup_settings', [$this, 'handle_settings']);
        add_action('admin_post_madof_task_status', [$this, 'handle_task_status']);
        add_action('admin_menu', fn() => add_submenu_page(MAD_Oficina_Core::MENU_SLUG_ROOT, __('Tareas', 'mad-oficina'), __('Tareas', 'mad-oficina'), MAD_Oficina_Core::CAPABILITY, 'mad-oficina-tareas', [$this, 'render_tasks_page']));
        add_action('admin_post_madof_followup_run', [$this, 'handle_run']);
        add_action('admin_post_madof_idea_resolve', [$this, 'handle_idea_resolve']);
    }

    public function admin_init(){}

    /* ==== Admin ==== */

    private function guard($action){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_' . $action);
    }

    private function back($notice, $type = 'success'){
        \MAD_Oficina\Core\AdminUi::flash($notice, $type);
        wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug()));
        exit;
    }

    public function handle_settings(){
        $this->guard('followup_settings');
        $p = wp_unslash($_POST);
        $s = [];
        foreach ( \MAD_Oficina\Followup\Coordinator::DEFAULTS as $key => $default ) {
            $s[$key] = is_bool($default) ? ! empty($p[$key]) : (int) ($p[$key] ?? $default);
        }
        $s['daily_hour'] = max(6, min(20, $s['daily_hour']));
        $s['days_ahead'] = max(0, min(14, $s['days_ahead']));
        $s['client_every'] = max(1, min(60, $s['client_every']));
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(__('Ajustes de seguimiento guardados.', 'mad-oficina'));
    }

    /** "Run now" buttons, handy while testing. They ignore the once-a-day guard. */
    public function handle_run(){
        $this->guard('followup_run');
        $job = sanitize_key($_POST['job'] ?? '');
        $c = $this->coordinator();
        $today = current_time('Y-m-d');
        switch ( $job ) {
            case 'reminders': $n = $c->team_reminders($today); $msg = sprintf(__('Recordatorios enviados: %d.', 'mad-oficina'), $n); break;
            case 'client_reminders': $n = $c->client_reminders($today); $msg = sprintf(__('Recordatorios a clientes propuestos en la bandeja: %d.', 'mad-oficina'), $n); break;
            case 'ideas': $n = $c->surface_ideas($today); $msg = sprintf(__('Ideas enviadas a tu Chat: %d.', 'mad-oficina'), $n); break;
            case 'briefs': $n = $c->briefs(time()); $msg = sprintf(__('Fichas de reunión enviadas: %d (cada reunión recibe una sola).', 'mad-oficina'), $n); break;
            case 'review':
                $n = \MAD_Oficina\Followup\Reconciler::enqueue_all();
                $done = (new \MAD_Oficina\Followup\Reconciler())->run_queue();
                $msg = sprintf(__('Valle repasa las tareas de %1$d clientes: %2$d ya revisados; el resto en las próximas pasadas del cartero (unos minutos). Te avisa por Chat de lo que cambie.', 'mad-oficina'), $n, $done);
                break;
            case 'weekly': $n = $c->weekly_summary($today); $msg = $n ? __('Resumen semanal enviado a tu Chat.', 'mad-oficina') : __('No he podido enviar el resumen (mira el registro).', 'mad-oficina'); break;
            default: $this->back(__('Acción desconocida.', 'mad-oficina'), 'error');
        }
        $this->back($msg);
    }

    public function handle_idea_resolve(){
        $this->guard('idea_resolve');
        $r = \MAD_Oficina\Followup\Coordinator::resolve_idea(absint($_POST['idea_id'] ?? 0), sanitize_key($_POST['decision'] ?? ''));
        if ( is_wp_error($r) ) $this->back($r->get_error_message(), 'error');
        $this->back($r['idea']['title'] . ': ' . $r['message']);
    }

    /** «Hecha» / «Reabrir» from the task list. */
    public function handle_task_status(){
        $this->guard('task_status');
        global $wpdb;
        $id = absint($_POST['task'] ?? 0);
        $done = ($_POST['status'] ?? '') === 'done';
        $now = \MAD_Oficina\Core\Time::now();
        $wpdb->update(\MAD_Oficina\Core\Schema::table('action_items'), ['status' => $done ? 'done' : 'open', 'completed_at' => $done ? $now : null, 'updated_at' => $now], ['id' => $id]);
        \MAD_Oficina\Core\AdminUi::flash(sprintf($done ? __('Tarea #%d hecha.', 'mad-oficina') : __('Tarea #%d reabierta.', 'mad-oficina'), $id));
        parse_str((string) wp_unslash($_POST['back'] ?? ''), $q);
        $q = array_intersect_key($q, array_flip(['client', 'owner', 'status', 'q']));
        wp_safe_redirect(add_query_arg(['page' => 'mad-oficina-tareas'] + $q, admin_url('admin.php')) . '#tarea-' . $id);
        exit;
    }

    public function render_tasks_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        global $wpdb;
        $f = [
            'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? '')), 'client' => absint($_GET['client'] ?? 0),
            'owner' => in_array($_GET['owner'] ?? '', ['jessica', 'mad', 'liz', 'cliente'], true) ? $_GET['owner'] : '',
            'status' => in_array($_GET['status'] ?? '', ['open', 'done', 'cancelled', 'all'], true) ? $_GET['status'] : 'open',
        ];
        $w = ['1=1']; $args = [];
        if ( preg_match('/^#?(\d+)$/', $f['q'], $m) ) { $w[] = 'id = %d'; $args[] = (int) $m[1]; $f['status'] = 'all'; }
        elseif ( $f['q'] !== '' ) { $like = '%' . $wpdb->esc_like($f['q']) . '%'; $w[] = '(title LIKE %s OR description LIKE %s)'; $args[] = $like; $args[] = $like; }
        if ( $f['client'] ) { $w[] = 'client_id = %d'; $args[] = $f['client']; }
        if ( $f['owner'] === 'liz' ) $w[] = "(owner = 'liz' OR team_member_id IS NOT NULL)";
        elseif ( $f['owner'] ) { $w[] = 'owner = %s'; $args[] = $f['owner']; }
        if ( $f['status'] === 'open' ) $w[] = "status IN ('open','in_progress')";
        elseif ( $f['status'] !== 'all' ) { $w[] = 'status = %s'; $args[] = $f['status']; }
        $t = \MAD_Oficina\Core\Schema::table('action_items');
        $where = implode(' AND ', $w);
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$where}", ...$args)) : $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$where}"));
        $sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY (status IN ('open','in_progress')) DESC, (due_date IS NULL), due_date, id DESC LIMIT 300";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        include __DIR__ . '/views/tasks.php';
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        global $wpdb;
        $coordinator = $this->coordinator();
        $ideas = $wpdb->get_results(
            'SELECT i.*, c.code AS client_code FROM ' . \MAD_Oficina\Core\Schema::table('ideas') . ' i LEFT JOIN ' . \MAD_Oficina\Core\Schema::table('clients') . " c ON c.id = i.client_id WHERE i.status IN ('dormant','surfaced') ORDER BY (i.status = 'surfaced') DESC, (i.resurface_at IS NULL), i.resurface_at ASC LIMIT 50",
            ARRAY_A
        );
        $log = $wpdb->get_results("SELECT event, client_id, data, created_at FROM " . \MAD_Oficina\Core\Schema::table('agent_log') . " WHERE agent = 'coordinator' ORDER BY id DESC LIMIT 10", ARRAY_A);
        $next = wp_next_scheduled(self::CRON_HOOK);
        include __DIR__ . '/views/settings.php';
    }
};
