<?php
/**
 * Módulo: Agenda.
 * Huecos reales de tu calendario (siempre en vivo), peticiones de reunión desde
 * el portal y creación del evento con Meet tras tu aprobación.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Scheduling\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'scheduling'; }
    public function title(){ return __('Agenda', 'mad-oficina'); }
    public function menu_label(){ return __('Agenda', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-agenda'; }
    public function description(){
        return __('Los clientes piden reunión desde el portal con huecos reales de tu calendario. Tú apruebas y se crea el evento con Meet e invitaciones.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients', 'google']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function requests(){
        return new \MAD_Oficina\Scheduling\Requests($this->settings());
    }

    /* ==== Hooks ==== */
    public function init(){
        add_filter('madoficina_google_scopes', fn($s) => array_merge($s, \MAD_Oficina\Scheduling\Calendar::SCOPES));
        $type = \MAD_Oficina\Scheduling\Requests::ACTION;
        add_filter('madoficina_approval_handler_' . $type, fn() => fn($approval, $input) => $this->requests()->approve($approval, $input));
        add_filter('madoficina_approval_form_' . $type, ['MAD_Oficina\\Scheduling\\Requests', 'render_form'], 10, 2);
        add_action('admin_post_madof_scheduling_settings', [$this, 'handle_settings']);
    }

    public function admin_init(){}

    public function rest_routes(){
        (new \MAD_Oficina\Scheduling\Rest($this->requests()))->register();
    }

    /* ==== Admin ==== */

    public function handle_settings(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_scheduling_settings');
        $p = wp_unslash($_POST);
        $int = fn($k, $min, $max) => max($min, min($max, (int) ($p['rules'][$k] ?? \MAD_Oficina\Scheduling\Slots::DEFAULT_RULES[$k])));

        $hours = [];
        for ( $d = 1; $d <= 7; $d++ ) {
            $windows = \MAD_Oficina\Scheduling\Slots::windows($p['rules']['hours'][$d] ?? '');
            $hours[$d] = implode(', ', array_map(fn($w) => sprintf('%02d:%02d-%02d:%02d', intdiv($w[0], 60), $w[0] % 60, intdiv($w[1], 60), $w[1] % 60), $windows));
        }
        $blocked = [];
        foreach ( preg_split('/[\s,;]+/', (string) ($p['rules']['blocked'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $date ) {
            if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= current_time('Y-m-d') ) $blocked[] = $date;
        }

        $s = $this->settings();
        $s['rules'] = [
            'hours'        => $hours,
            'duration'     => $int('duration', 15, 240),
            'buffer'       => $int('buffer', 0, 120),
            'min_notice'   => $int('min_notice', 0, 336),
            'max_per_day'  => $int('max_per_day', 1, 12),
            'horizon_days' => $int('horizon_days', 1, 60),
            'step'         => in_array((int) ($p['rules']['step'] ?? 30), [15, 30, 60], true) ? (int) $p['rules']['step'] : 30,
            'blocked'      => array_values(array_unique($blocked)),
        ];
        $calendars = array_map('sanitize_text_field', (array) ($p['calendars'] ?? []));
        $s['calendars'] = array_values(array_filter($calendars, fn($c) => $c !== '' && strlen($c) < 200)) ?: ['primary'];
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);

        \MAD_Oficina\Core\AdminUi::flash(__('Reglas de agenda guardadas.', 'mad-oficina'));
        wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug()));
        exit;
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        $requests = $this->requests();
        $rules = $requests->rules();
        $ready = \MAD_Oficina\Scheduling\Calendar::is_ready();
        $calendars = $ready ? \MAD_Oficina\Scheduling\Calendar::calendars() : null;
        $preview = $ready ? $requests->free_slots() : null;
        include __DIR__ . '/views/settings.php';
    }
};
