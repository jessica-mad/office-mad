<?php
/**
 * Módulo: Conexiones (Google, Anthropic y, más adelante, facturación y Ads).
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    private $core;
    private $oauth;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Google\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'google'; }
    public function title(){ return __('Conexiones', 'mad-oficina'); }
    public function menu_label(){ return __('Conexiones', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-conexiones'; }
    public function description(){
        return __('Conecta tu cuenta de Google Workspace y comprueba la clave de Claude. Los tokens se guardan cifrados.', 'mad-oficina');
    }

    public function oauth(){
        if ( ! $this->oauth ) $this->oauth = new \MAD_Oficina\Google\OAuth(MAD_Oficina_Core::option_key($this->slug()));
        return $this->oauth;
    }

    /* ==== Hooks ==== */
    public function init(){
        add_action('admin_post_madof_google_save', [$this, 'handle_save']);
        add_action('admin_post_madof_google_connect', [$this, 'handle_connect']);
        add_action('admin_post_madof_google_disconnect', [$this, 'handle_disconnect']);
    }

    public function admin_init(){
        // OAuth callback lands on the module page (same pattern as MAD Suite).
        if ( ($_GET['page'] ?? '') === $this->menu_slug() && ($_GET['action'] ?? '') === 'google_oauth_callback' ) {
            $this->handle_callback();
        }
    }

    private function guard($nonce_action){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer($nonce_action);
    }

    private function back($notice, $type = 'success'){
        \MAD_Oficina\Core\AdminUi::flash($notice, $type);
        wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug()));
        exit;
    }

    public function handle_save(){
        $this->guard('madof_google_save');
        $this->oauth()->save_credentials(
            wp_unslash($_POST['client_id'] ?? ''),
            wp_unslash($_POST['client_secret'] ?? ''),
            wp_unslash($_POST['workspace_domain'] ?? '')
        );
        $this->back(__('Credenciales guardadas.', 'mad-oficina'));
    }

    public function handle_connect(){
        $this->guard('madof_google_connect');
        if ( ! $this->oauth()->has_credentials() ) $this->back(__('Primero guarda el Client ID y el Client secret.', 'mad-oficina'), 'error');
        wp_redirect($this->oauth()->authorization_url()); // external: accounts.google.com
        exit;
    }

    public function handle_disconnect(){
        $this->guard('madof_google_disconnect');
        $this->oauth()->disconnect();
        $this->back(__('Google desconectado y acceso revocado.', 'mad-oficina'));
    }

    private function handle_callback(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        if ( isset($_GET['error']) ) $this->back(sprintf(__('Google canceló la conexión: %s', 'mad-oficina'), sanitize_text_field(wp_unslash($_GET['error']))), 'error');

        $result = $this->oauth()->handle_callback(
            sanitize_text_field(wp_unslash($_GET['code'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['state'] ?? ''))
        );
        if ( is_wp_error($result) ) $this->back($result->get_error_message(), 'error');
        $this->back(sprintf(__('Conectado como %s.', 'mad-oficina'), $this->oauth()->connected_email()));
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $oauth = $this->oauth();
        include __DIR__ . '/views/settings.php';
    }
};
