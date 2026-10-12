<?php
/**
 * Módulo: Visitantes y leads.
 * Recepción abierta para quien no tiene usuario (brief, primera reunión y,
 * más adelante, charla con la recepcionista) y «Nuevo encargo» de clientes.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const NOTIFY_HOOK = 'madoficina_lead_notify';
    const PURGE_HOOK  = 'madoficina_leads_purge';

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Visitors\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'visitors'; }
    public function title(){ return __('Visitantes y leads', 'mad-oficina'); }
    public function menu_label(){
        $n = \MAD_Oficina\Visitors\Leads::count_new();
        return __('Leads', 'mad-oficina') . ($n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '');
    }
    public function menu_slug(){ return 'mad-oficina-leads'; }
    public function description(){
        return __('Recepción para visitantes sin usuario: dejar un brief y pedir una primera reunión (con confirmación por email y Turnstile). Activa también «Nuevo encargo» para clientes.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients', 'portal']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    /* ==== Hooks ==== */
    public function init(){
        add_filter('madoficina_visitor_config', [$this, 'visitor_config']);
        add_filter('madoficina_portal_me', fn($data) => $data + ['brief' => $this->brief_options()]);
        add_action('madoficina_lead_created', function($id){
            if ( function_exists('as_enqueue_async_action') ) as_enqueue_async_action(self::NOTIFY_HOOK, [(int) $id], 'mad-oficina');
            else wp_schedule_single_event(time(), self::NOTIFY_HOOK, [(int) $id]);
        });
        add_action(self::NOTIFY_HOOK, [$this, 'notify']);
        add_action(self::PURGE_HOOK, fn() => \MAD_Oficina\Visitors\Leads::purge_unverified(7));
        if ( ! wp_next_scheduled(self::PURGE_HOOK) ) wp_schedule_event(time() + 300, 'daily', self::PURGE_HOOK);
        foreach ( ['visitors_settings', 'lead_update', 'lead_convert', 'lead_delete'] as $a ) add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
    }

    public function admin_init(){}

    public function rest_routes(){
        (new \MAD_Oficina\Visitors\Rest([$this, 'settings']))->register();
    }

    /** What the portal script needs when nobody is logged in. */
    public function visitor_config($config){
        $s = $this->settings();
        return [
            'visitor'   => true,
            'turnstile' => $s['turnstile_site'] ?? '',
            'privacy'   => ($s['privacy_url'] ?? '') ?: (get_privacy_policy_url() ?: ''),
            'login'     => wp_login_url(get_permalink() ?: home_url('/oficina/')),
            'brief'     => $this->brief_options(),
            'rooms'     => \MAD_Oficina\Core\Agents\Characters::ROOMS,
        ] + (array) $config;
    }

    /** Choices of the brief form, as label maps (validated again on the server). */
    private function brief_options(){
        $t = fn(array $map) => array_map(fn($l) => __($l, 'mad-oficina'), $map);
        return [
            'services'  => $t(\MAD_Oficina\Visitors\Leads::SERVICES),
            'timelines' => $t(\MAD_Oficina\Visitors\Leads::TIMELINES),
            'budgets'   => $t(\MAD_Oficina\Visitors\Leads::BUDGETS),
        ];
    }

    /** New lead (confirmed) → short note in Jessica's Chat. The brief is shown as data. */
    public function notify($lead_id){
        $lead = \MAD_Oficina\Visitors\Leads::get($lead_id);
        $chat = MAD_Oficina_Core::instance()->get_module('chat');
        if ( ! $lead || ! $chat ) return;
        $labels = \MAD_Oficina\Visitors\Leads::SERVICES;
        $services = implode(', ', array_map(fn($k) => $labels[$k] ?? $k, $lead['services']));
        $title = $lead['client_id'] ? sprintf(__('Nuevo encargo de %s', 'mad-oficina'), $lead['company']) : sprintf(__('Nuevo brief de %1$s%2$s', 'mad-oficina'), $lead['name'], $lead['company'] ? ' (' . $lead['company'] . ')' : '');
        $text = '*' . $title . "*\n" . ($services ? $services . "\n" : '') . '> ' . mb_substr(str_replace("\n", ' ', (string) $lead['message']), 0, 400)
            . "\n\n" . admin_url('admin.php?page=' . $this->menu_slug() . '&lead=' . $lead['id']);
        $chat->messenger()->to_manager(['text' => $text]);
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

    public function handle_visitors_settings(){
        $this->guard('visitors_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        $s['turnstile_site'] = sanitize_text_field($p['turnstile_site'] ?? '');
        if ( ! empty($p['turnstile_secret']) ) $s['turnstile_secret'] = \MAD_Oficina\Core\Crypto::encrypt(sanitize_text_field($p['turnstile_secret']));
        if ( ! empty($p['turnstile_clear']) ) { $s['turnstile_site'] = ''; $s['turnstile_secret'] = ''; }
        $s['privacy_url'] = esc_url_raw($p['privacy_url'] ?? '');
        $s['portal_url'] = esc_url_raw($p['portal_url'] ?? '') ?: home_url('/oficina/');
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'settings'], __('Ajustes guardados.', 'mad-oficina'));
    }

    public function handle_lead_update(){
        $this->guard('lead_update');
        $id = absint($_POST['lead_id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');
        $fields = ['notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''))];
        if ( isset(\MAD_Oficina\Visitors\Leads::STATUSES[$status]) ) $fields['status'] = $status;
        \MAD_Oficina\Visitors\Leads::update($id, $fields);
        $this->back(['lead' => $id], __('Lead actualizado.', 'mad-oficina'));
    }

    public function handle_lead_convert(){
        $this->guard('lead_convert');
        $id = absint($_POST['lead_id'] ?? 0);
        $client_id = \MAD_Oficina\Visitors\Leads::convert_to_client($id);
        if ( is_wp_error($client_id) ) $this->back(['lead' => $id], $client_id->get_error_message(), 'error');
        \MAD_Oficina\Core\AdminUi::flash(__('Ficha de cliente creada con su contacto. Revisa el código y completa lo que falte.', 'mad-oficina'));
        wp_safe_redirect(admin_url('admin.php?page=mad-oficina-clientes&client=' . $client_id));
        exit;
    }

    public function handle_lead_delete(){
        $this->guard('lead_delete');
        \MAD_Oficina\Visitors\Leads::delete(absint($_POST['lead_id'] ?? 0));
        $this->back([], __('Lead borrado con todos sus datos.', 'mad-oficina'));
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        $lead_id = absint($_GET['lead'] ?? 0);
        $lead = $lead_id ? \MAD_Oficina\Visitors\Leads::get($lead_id) : null;
        $tab = sanitize_key($_GET['tab'] ?? 'list');
        $filter = sanitize_key($_GET['status'] ?? '');
        $leads = $lead ? [] : \MAD_Oficina\Visitors\Leads::all($filter);
        include __DIR__ . '/views/leads.php';
    }
};
