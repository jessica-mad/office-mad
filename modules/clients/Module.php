<?php
/**
 * Módulo: Clientes y equipo.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Clients\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'clients'; }
    public function title(){ return __('Clientes', 'mad-oficina'); }
    public function menu_label(){ return __('Clientes', 'mad-oficina'); }
    public function menu_slug(){ return \MAD_Oficina\Clients\Actions::CLIENTS_PAGE; }
    public function description(){
        return __('Fichas de clientes (código, dominios, Drive), sus contactos y emails, usuarios del portal y el equipo (Liz).', 'mad-oficina');
    }

    /* ==== Hooks ==== */
    public function init(){
        foreach ( \MAD_Oficina\Clients\Actions::ACTIONS as $action ){
            add_action('admin_post_madof_' . $action, function() use ($action){
                $result = \MAD_Oficina\Clients\Actions::dispatch($action, $_POST);
                \MAD_Oficina\Core\AdminUi::flash($result['notice'], $result['type']);
                wp_safe_redirect($result['url']);
                exit;
            });
        }

        add_action('admin_menu', function(){
            add_submenu_page(
                MAD_Oficina_Core::MENU_SLUG_ROOT,
                __('Equipo', 'mad-oficina'),
                __('Equipo', 'mad-oficina'),
                MAD_Oficina_Core::CAPABILITY,
                \MAD_Oficina\Clients\Actions::TEAM_PAGE,
                [$this, 'render_team_page']
            );
        }, 20);
    }

    public function admin_init(){}

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $client_id = isset($_GET['client']) ? absint($_GET['client']) : 0;
        if ( $client_id || isset($_GET['new']) ) {
            $client = $client_id ? \MAD_Oficina\Core\Repo\Clients::get($client_id) : null;
            if ( $client_id && ! $client ) wp_die(esc_html__('Cliente no encontrado.', 'mad-oficina'));
            include __DIR__ . '/views/client-edit.php';
        } else {
            include __DIR__ . '/views/clients-list.php';
        }
    }

    public function render_team_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        include __DIR__ . '/views/team.php';
    }
};
