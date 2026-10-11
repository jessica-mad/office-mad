<?php
/**
 * Plugin Name: MAD Oficina
 * Description: Oficina virtual para clientes (pixel art) y back-office automatizado con agentes de IA para MAD · Marketing & Ads.
 * Version: 0.1.0
 * Author: MAD · Marketing & Ads
 * Author URI: https://imad.pro
 * Text Domain: mad-oficina
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.9
 */

if ( ! defined('ABSPATH') ) exit;

define('MAD_OFICINA_VERSION', '0.1.0');
define('MAD_OFICINA_FILE', __FILE__);
define('MAD_OFICINA_DIR', plugin_dir_path(__FILE__));
define('MAD_OFICINA_URL', plugin_dir_url(__FILE__));

// Background jobs. Action Scheduler resolves version conflicts by itself when
// WooCommerce (or another plugin) ships its own copy.
if ( file_exists(MAD_OFICINA_DIR . 'lib/action-scheduler/action-scheduler.php') ) {
    require_once MAD_OFICINA_DIR . 'lib/action-scheduler/action-scheduler.php';
}

// Shared code: MAD_Oficina\Core\... → core/...
spl_autoload_register(function($class){
    $prefix = 'MAD_Oficina\\Core\\';
    if ( strpos($class, $prefix) !== 0 ) return;
    $file = MAD_OFICINA_DIR . 'core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if ( file_exists($file) ) require_once $file;
});

final class MAD_Oficina_Core {
    const CAPABILITY             = 'mad_manage_office';
    const MENU_SLUG_ROOT         = 'mad-oficina';
    const ENABLED_MODULES_OPTION = 'madoficina_enabled_modules';

    private static $instance = null;
    private $modules = [];
    private $all_modules_info = []; // Info de todos los módulos (habilitados o no)

    public static function instance(){
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct(){
        add_action('plugins_loaded', [$this,'load_textdomain']);
        // Git-based deploys never fire the activation hook, so check the schema on every load.
        add_action('plugins_loaded', ['MAD_Oficina\\Core\\Installer','maybe_upgrade'], 5);
        add_action('init', [$this,'init_modules_early'], 1);
        add_action('admin_menu', [$this,'register_root_menu']);
        add_action('admin_init', [$this,'admin_init']);
        add_action('admin_enqueue_scripts', [$this,'enqueue_admin_assets']);
        add_action('rest_api_init', [$this,'register_rest_routes']);
        add_action('admin_post_madof_toggle_module', [$this, 'handle_toggle_module']);
        add_action('admin_post_madof_approval_resolve', [$this, 'handle_approval_resolve']);
        add_action('admin_post_madof_autonomy_save', [$this, 'handle_autonomy_save']);
        add_action('admin_notices', [$this,'admin_notices']);

        if ( defined('WP_CLI') && WP_CLI ) {
            \WP_CLI::add_command('mad-oficina', 'MAD_Oficina\\Core\\Cli');
        }
    }

    public function load_textdomain(){
        load_plugin_textdomain('mad-oficina', false, dirname(plugin_basename(MAD_OFICINA_FILE)).'/languages');
    }

    public function register_root_menu(){
        add_menu_page(
            __('MAD Oficina','mad-oficina'),
            __('MAD Oficina','mad-oficina'),
            self::CAPABILITY,
            self::MENU_SLUG_ROOT,
            [$this,'render_root_page'],
            'dashicons-building',
            56
        );
        $pending = \MAD_Oficina\Core\Approvals::count_pending();
        add_submenu_page(
            self::MENU_SLUG_ROOT,
            __('Bandeja de gerencia','mad-oficina'),
            __('Bandeja','mad-oficina') . ($pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : ''),
            self::CAPABILITY,
            self::MENU_SLUG_ROOT . '-bandeja',
            [$this,'render_inbox_page']
        );
        add_submenu_page(
            self::MENU_SLUG_ROOT,
            __('Módulos','mad-oficina'),
            __('Módulos','mad-oficina'),
            self::CAPABILITY,
            self::MENU_SLUG_ROOT,
            [$this,'render_root_page']
        );
    }

    public function render_root_page(){
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        }
        include MAD_OFICINA_DIR . 'views/modules-manager.php';
    }

    public function render_inbox_page(){
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        }
        include MAD_OFICINA_DIR . 'views/inbox.php';
    }

    /** Approve/reject from the inbox form. Inputs are passed to the action's handler, which sanitises them. */
    public function handle_approval_resolve(){
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        }
        $id = isset($_POST['approval_id']) ? absint($_POST['approval_id']) : 0;
        check_admin_referer('madof_approval_' . $id);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $input = isset($_POST['input']) && is_array($_POST['input']) ? wp_unslash($_POST['input']) : [];

        $result = \MAD_Oficina\Core\Approvals::resolve($id, $decision, $input, get_current_user_id());
        if ( is_wp_error($result) ) {
            \MAD_Oficina\Core\AdminUi::flash($result->get_error_message(), 'error');
        } else {
            \MAD_Oficina\Core\AdminUi::flash($result['message'] ?? __('Hecho.', 'mad-oficina'));
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_ROOT . '-bandeja'));
        exit;
    }

    public function handle_autonomy_save(){
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        }
        check_admin_referer('madof_autonomy_save');
        \MAD_Oficina\Core\Autonomy::save(isset($_POST['autonomy']) && is_array($_POST['autonomy']) ? wp_unslash($_POST['autonomy']) : []);
        \MAD_Oficina\Core\AdminUi::flash(__('Niveles de autonomía guardados.', 'mad-oficina'));
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_ROOT . '-bandeja#autonomia'));
        exit;
    }

    public function init_modules_early(){
        $dir = MAD_OFICINA_DIR . 'modules';
        if ( ! is_dir($dir) ) return;

        $enabled_modules = $this->get_enabled_modules();

        foreach ( glob($dir.'/*/Module.php') as $file ){
            $core = $this; // disponible dentro del módulo: "return new class($core) ..."
            $module = include $file;

            if ( ! is_object($module) || ! ($module instanceof MAD_Oficina_Module) ) continue;

            $slug = $module->slug();
            $this->all_modules_info[$slug] = [
                'instance'         => $module,
                'slug'             => $slug,
                'title'            => $module->title(),
                'description'      => method_exists($module, 'description') ? $module->description() : '',
                'required_plugins' => method_exists($module, 'required_plugins') ? $module->required_plugins() : [],
                'dependencies'     => method_exists($module, 'dependencies') ? $module->dependencies() : [],
                'enabled'          => in_array($slug, $enabled_modules, true),
            ];
        }

        // Second pass so dependencies are known before anything initialises.
        foreach ( $this->all_modules_info as $slug => $info ){
            if ( ! $info['enabled'] ) continue;
            $missing = array_diff($info['dependencies'], $enabled_modules);
            if ( $missing ) {
                $this->all_modules_info[$slug]['missing_dependencies'] = array_values($missing);
                continue;
            }

            $module = $info['instance'];
            $this->modules[$slug] = $module;
            $module->init();

            // Submenú de ajustes
            add_action('admin_menu', function() use ($module){
                add_submenu_page(
                    self::MENU_SLUG_ROOT,
                    $module->title(),
                    $module->menu_label(),
                    self::CAPABILITY,
                    $module->menu_slug(),
                    [$module,'render_settings_page']
                );
            });
        }
    }

    public function admin_init(){
        foreach ( $this->modules as $m ){
            $m->admin_init();
        }
    }

    public function register_rest_routes(){
        foreach ( $this->modules as $m ){
            if ( method_exists($m, 'rest_routes') ) $m->rest_routes();
        }
    }

    public function enqueue_admin_assets($hook){
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ( strpos($page, self::MENU_SLUG_ROOT) !== 0 ) return;
        wp_enqueue_style('mad-oficina-brand', MAD_OFICINA_URL.'assets/css/mad.css', [], MAD_OFICINA_VERSION);
        wp_enqueue_style('mad-oficina-admin', MAD_OFICINA_URL.'assets/css/admin.css', ['mad-oficina-brand'], MAD_OFICINA_VERSION);
    }

    public function admin_notices(){
        if ( ! current_user_can(self::CAPABILITY) ) return;
        if ( ! \MAD_Oficina\Core\Crypto::has_dedicated_key() ) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ( ! $screen || strpos((string) $screen->id, self::MENU_SLUG_ROOT) === false ) return;
            echo '<div class="notice notice-warning"><p>';
            echo wp_kses_post(sprintf(
                /* translators: %s: constant name */
                __('MAD Oficina: añade %s a wp-config.php para cifrar los tokens con una clave propia. Ahora se usa una clave derivada de las sales de WordPress (si cambias las sales, tendrás que volver a conectar Google).', 'mad-oficina'),
                "<code>define('MAD_OFICINA_SECRET', '…');</code>"
            ));
            echo '</p></div>';
        }
    }

    /* ===== Helpers globales de opciones ===== */
    public static function option_key( $module_slug ){
        return 'madoficina_'. sanitize_key($module_slug) .'_settings';
    }

    public function get_module($slug){
        return $this->modules[$slug] ?? null;
    }

    /* ===== Gestión de módulos habilitados ===== */
    public function get_enabled_modules(){
        $enabled = get_option(self::ENABLED_MODULES_OPTION, []);
        return is_array($enabled) ? $enabled : [];
    }

    public function get_all_modules_info(){
        return $this->all_modules_info;
    }

    public function is_module_enabled($slug){
        return in_array($slug, $this->get_enabled_modules(), true);
    }

    public function enable_module($slug){
        $enabled = $this->get_enabled_modules();
        if ( ! in_array($slug, $enabled, true) ) {
            $enabled[] = $slug;
            update_option(self::ENABLED_MODULES_OPTION, $enabled);
        }
    }

    public function disable_module($slug){
        $enabled = array_diff($this->get_enabled_modules(), [$slug]);
        update_option(self::ENABLED_MODULES_OPTION, array_values($enabled));
    }

    public function handle_toggle_module(){
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        }

        check_admin_referer('madof_toggle_module', 'madof_nonce');

        $module_slug = isset($_POST['module_slug']) ? sanitize_key(wp_unslash($_POST['module_slug'])) : '';
        $action      = isset($_POST['module_action']) ? sanitize_key(wp_unslash($_POST['module_action'])) : '';

        if ( ! $module_slug || ! isset($this->all_modules_info[$module_slug]) ) {
            wp_die(esc_html__('Módulo inválido.', 'mad-oficina'));
        }

        if ( $action === 'enable' ) {
            $this->enable_module($module_slug);
        } elseif ( $action === 'disable' ) {
            $this->disable_module($module_slug);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_ROOT . '&updated=1'));
        exit;
    }
}

/* ===== Interfaz (contrato) que cada módulo debe cumplir ===== */
interface MAD_Oficina_Module {
    public function slug();                 // identificador corto, p.ej. "clients"
    public function title();                // Título de la página
    public function menu_label();           // Texto del submenú
    public function menu_slug();            // slug único del submenú
    public function init();                 // Hooks públicos (front y/o admin)
    public function admin_init();           // Registro de ajustes, handlers de formularios…
    public function render_settings_page(); // Dibuja la página del módulo

    /* ===== Métodos opcionales ===== */
    // public function description();       // Descripción del módulo
    // public function required_plugins();  // ['Plugin Name' => 'plugin/file.php']
    // public function dependencies();      // Slugs de otros módulos de MAD Oficina que deben estar activos
    // public function rest_routes();       // Registro de rutas REST (se llama en rest_api_init)
}

register_activation_hook(__FILE__, ['MAD_Oficina\\Core\\Installer','activate']);

// Bootstrap
MAD_Oficina_Core::instance();
