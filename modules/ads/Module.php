<?php
/**
 * Módulo: Campañas · Analista (Fase 3).
 * Lee (solo lectura) las campañas de Google Ads y Meta de cada cliente: campañas,
 * métricas y el historial de cambios, y guarda por qué se hizo cada cambio.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const CRON_HOOK = 'madoficina_ads_hourly';

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Ads\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'ads'; }
    public function title(){ return __('Campañas', 'mad-oficina'); }
    public function menu_label(){ return __('Campañas', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-campanas'; }
    public function description(){
        return __('Analista de campañas: lee cada día (solo lectura) las campañas de Google Ads y Meta de tus clientes, sus métricas y el historial de cambios, y guarda por qué se hizo cada cambio.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients', 'google']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function sync(){ return new \MAD_Oficina\Ads\Sync($this->settings()); }

    /* ==== Hooks ==== */
    public function init(){
        // Google Ads only asks for its permission once there is a developer token to use it with.
        add_filter('madoficina_google_scopes', function($scopes){
            return ( new \MAD_Oficina\Ads\GoogleAds($this->settings()) )->token() !== '' ? array_merge($scopes, [\MAD_Oficina\Ads\GoogleAds::SCOPE]) : $scopes;
        });
        add_action(self::CRON_HOOK, fn() => $this->sync()->run());
        add_action('madoficina_tick', fn() => $this->sync()->run());
        add_action('madoficina_tick', fn() => \MAD_Oficina\Ads\MetaImport::remind($this->settings())); // no Meta report lately: Nuria asks for it
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);

        foreach ( ['ads_settings', 'ads_discover', 'ads_link', 'ads_sync', 'ads_notes', 'ads_meta_import'] as $a ) add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
        add_action('wp_ajax_madof_ads_reason', [$this, 'ajax_reason']);
    }

    public function admin_init(){}

    /* ==== Admin ==== */

    private function guard($action){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_' . $action);
    }

    private function back(array $args, $notice, $type = 'success'){
        \MAD_Oficina\Core\AdminUi::flash($notice, $type);
        wp_safe_redirect(add_query_arg(['page' => $this->menu_slug()] + $args, admin_url('admin.php')));
        exit;
    }

    public function handle_ads_settings(){
        $this->guard('ads_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        $s['google_mcc'] = \MAD_Oficina\Ads\GoogleAds::digits($p['google_mcc'] ?? '');
        $s['google_version'] = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($p['google_version'] ?? ''))) ?: \MAD_Oficina\Ads\GoogleAds::DEFAULT_VERSION;
        $s['meta_version'] = preg_replace('/[^v0-9.]/', '', (string) ($p['meta_version'] ?? '')) ?: \MAD_Oficina\Ads\Meta::DEFAULT_VERSION;
        // Secrets: kept encrypted; an empty field keeps the saved one, «borrar» removes it.
        foreach ( ['google_dev_token' => 'google_dev_token', 'meta_token' => 'meta_token'] as $field => $key ) {
            if ( ! empty($p['forget_' . $field]) ) $s[$key] = '';
            elseif ( trim((string) ($p[$field] ?? '')) !== '' ) $s[$key] = \MAD_Oficina\Core\Crypto::encrypt(trim(sanitize_text_field($p[$field])));
        }
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $google = \MAD_Oficina_Core::instance()->get_module('google');
        $needs = $google && ( new \MAD_Oficina\Ads\GoogleAds($s) )->token() !== '' && in_array(\MAD_Oficina\Ads\GoogleAds::SCOPE, $google->oauth()->missing_scopes(), true);
        $this->back(['tab' => 'connection'], $needs
            ? __('Guardado. Ahora reconecta Google en Conexiones para dar el permiso de Google Ads (solo se usará para leer).', 'mad-oficina')
            : __('Guardado.', 'mad-oficina'));
    }

    public function handle_ads_discover(){
        $this->guard('ads_discover');
        $platform = in_array($_POST['platform'] ?? '', ['google', 'meta'], true) ? $_POST['platform'] : null;
        $r = $this->sync()->discover($platform);
        $msg = sprintf(_n('%d cuenta encontrada.', '%d cuentas encontradas.', $r['found'], 'mad-oficina'), $r['found']) . ( $r['errors'] ? ' ' . implode(' · ', $r['errors']) : '' );
        $this->back(['tab' => 'accounts'], $msg, $r['errors'] ? 'error' : 'success');
    }

    public function handle_ads_link(){
        $this->guard('ads_link');
        foreach ( (array) ($_POST['client'] ?? []) as $account => $client ) \MAD_Oficina\Ads\Accounts::link(absint($account), absint($client));
        $this->back(['tab' => 'accounts'], __('Cuentas guardadas. Las vinculadas se leen cada mañana.', 'mad-oficina'));
    }

    public function handle_ads_sync(){
        $this->guard('ads_sync');
        if ( function_exists('set_time_limit') ) @set_time_limit(180);
        $a = \MAD_Oficina\Ads\Accounts::get(absint($_POST['account'] ?? 0));
        if ( ! $a || ! $a['client_id'] ) $this->back(['tab' => 'accounts'], __('Vincula primero la cuenta a un cliente.', 'mad-oficina'), 'error');
        $r = $this->sync()->account($a);
        if ( is_wp_error($r) ) $this->back(['tab' => 'accounts'], $r->get_error_message(), 'error');
        \MAD_Oficina\Ads\Changes::fill_after();
        $this->back(['tab' => 'accounts'], sprintf(__('%1$s leída: %2$d campañas, %3$d días de métricas, %4$d cambios nuevos.', 'mad-oficina'), $a['name'], $r['campaigns'], $r['days'], $r['changes']));
    }

    public function handle_ads_notes(){
        $this->guard('ads_notes');
        $c = \MAD_Oficina\Ads\Accounts::campaign(absint($_POST['campaign'] ?? 0));
        if ( ! $c ) $this->back(['tab' => 'campaigns'], __('Esa campaña no existe.', 'mad-oficina'), 'error');
        \MAD_Oficina\Ads\Accounts::save_notes($c['id'], sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')));
        $this->back(['tab' => 'campaigns', 'client' => $c['client_id']], __('Notas guardadas.', 'mad-oficina'));
    }

    /** Ads Manager reports (one or several files at once). */
    public function handle_ads_meta_import(){
        $this->guard('ads_meta_import');
        if ( function_exists('set_time_limit') ) @set_time_limit(120);
        $files = $_FILES['reports'] ?? null;
        if ( ! $files || empty($files['tmp_name']) ) $this->back(['tab' => 'meta'], __('Elige el informe exportado del Administrador de anuncios.', 'mad-oficina'), 'error');
        $client = absint($_POST['client'] ?? 0);
        $ok = []; $errors = [];
        foreach ( (array) $files['tmp_name'] as $i => $tmp ) {
            $name = sanitize_file_name((string) ((array) $files['name'])[$i]);
            if ( ! $tmp || ! is_uploaded_file($tmp) ) continue;
            if ( (int) ((array) $files['size'])[$i] > 15 * MB_IN_BYTES ) { $errors[] = $name . ': ' . __('demasiado grande', 'mad-oficina'); continue; }
            $r = \MAD_Oficina\Ads\MetaImport::import($tmp, $client);
            if ( is_wp_error($r) ) { $errors[] = $name . ': ' . $r->get_error_message(); continue; }
            $ok[] = sprintf(__('%1$s: %2$d campañas, %3$d días (%4$s → %5$s)%6$s', 'mad-oficina'), implode(', ', $r['accounts']), $r['campaigns'], $r['days'],
                date_i18n('j M', strtotime($r['from'])), date_i18n('j M', strtotime($r['to'])), $r['changes'] ? ' · ' . sprintf(_n('%d cambio a explicar en la bitácora', '%d cambios a explicar en la bitácora', $r['changes'], 'mad-oficina'), $r['changes']) : '');
        }
        $this->back(['tab' => 'meta'], implode(' · ', array_merge($ok, $errors)) ?: __('No he recibido ningún archivo.', 'mad-oficina'), $errors ? 'error' : 'success');
    }

    /** The why, typed in the log: saved without reloading. */
    public function ajax_reason(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-oficina')], 403);
        if ( ! check_ajax_referer('madof_ads_reason', '_ajax_nonce', false) ) wp_send_json_error(['message' => __('La página ha caducado: recárgala.', 'mad-oficina')], 403);
        $ids = array_map('absint', explode(',', (string) ($_POST['ids'] ?? '')));
        $vis = isset($_POST['visibility']) ? sanitize_key($_POST['visibility']) : null;
        $n = \MAD_Oficina\Ads\Changes::set_reason($ids, wp_unslash($_POST['reason'] ?? ''), 'manual', get_current_user_id(), $vis);
        wp_send_json_success(['saved' => $n, 'message' => $n ? __('Guardado.', 'mad-oficina') : __('Nada que guardar.', 'mad-oficina')]);
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $tab = in_array($_GET['tab'] ?? '', ['log', 'campaigns', 'accounts', 'meta', 'connection'], true) ? $_GET['tab'] : 'log';
        $settings = $this->settings();
        $sync = $this->sync();
        include __DIR__ . '/views/ads.php';
    }
};
