<?php
/**
 * Módulo: Oficina y portal de clientes.
 * Shortcode [mad_oficina], API REST del portal, recepcionista con IA y ajustes de personajes.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    private $core;
    private $receptionist;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Portal\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'portal'; }
    public function title(){ return __('Oficina y personajes', 'mad-oficina'); }
    public function menu_label(){ return __('Oficina', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-oficina'; }
    public function description(){
        return __('La oficina pixel art para clientes (shortcode [mad_oficina]), la vista clásica, la recepcionista con IA y los nombres de los personajes.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function receptionist(){
        if ( ! $this->receptionist ) $this->receptionist = new \MAD_Oficina\Portal\Receptionist($this->settings());
        return $this->receptionist;
    }

    /* ==== Hooks ==== */
    public function init(){
        add_shortcode('mad_oficina', [$this, 'render_shortcode']);
        add_action('admin_post_madof_portal_settings', [$this, 'save_settings']);
    }

    public function admin_init(){}

    public function rest_routes(){
        (new \MAD_Oficina\Portal\Rest($this->receptionist()))->register();
    }

    /** One version for the map and sprites: changes when any of them changes (e.g. new sprites). */
    private function game_assets_version(){
        $files = array_merge(glob(MAD_OFICINA_DIR . 'assets/game/*.{json,png}', GLOB_BRACE) ?: [], glob(MAD_OFICINA_DIR . 'assets/game/characters/*.png') ?: []);
        $sig = '';
        foreach ( $files as $f ) $sig .= basename($f) . filesize($f) . filemtime($f);
        return substr(md5($sig), 0, 10);
    }

    public function render_shortcode(){
        $base = MAD_OFICINA_URL . 'assets/';
        wp_enqueue_style('mad-oficina-brand', $base . 'css/mad.css', [], MAD_Oficina_Core::asset_version('css/mad.css'));

        if ( ! is_user_logged_in() ) {
            // With the Visitors module on, visitors get the open reception; otherwise the login form.
            $visitor = apply_filters('madoficina_visitor_config', null);
            if ( is_array($visitor) && ! empty($visitor['visitor']) ) return $this->render_app($base, $visitor);
            ob_start();
            include __DIR__ . '/views/login.php';
            return ob_get_clean();
        }
        if ( ! current_user_can(\MAD_Oficina\Core\Roles::CAP_PORTAL) ) {
            return '<div class="madof-portal-note"><p>' . esc_html__('Tu usuario no tiene acceso a la oficina. Si crees que es un error, escribe a Jessica.', 'mad-oficina') . '</p></div>';
        }

        return $this->render_app($base, []);
    }

    private function render_app($base, array $extra){
        wp_enqueue_style('mad-oficina-portal', $base . 'game/portal.css', ['mad-oficina-brand'], MAD_Oficina_Core::asset_version('game/portal.css'));
        wp_enqueue_script_module('mad-oficina-portal', $base . 'game/portal.js', [], MAD_Oficina_Core::asset_version('game/portal.js'));

        $config = [
            'rest'    => esc_url_raw(rest_url('mad-oficina/v1/')),
            // Visitors send no nonce: a cached page would carry a stale one and break every request.
            'nonce'   => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'assets'  => $base . 'game/',
            'version' => MAD_Oficina_Core::asset_version('game/portal.js'),
            'assets_v' => $this->game_assets_version(),
        ];
        if ( ! empty($extra['visitor']) ) {
            $characters = [];
            foreach ( \MAD_Oficina\Core\Agents\Characters::all() as $slug => $c ) {
                $characters[$slug] = ['name' => $c['name'], 'role' => $c['role'], 'room' => $c['room'], 'ai' => $c['ai']];
            }
            $config += $extra + ['characters' => $characters];
        }
        return '<div id="mad-oficina" class="madof-app" data-config="' . esc_attr(wp_json_encode($config)) . '">'
            . '<p class="madof-app-loading">' . esc_html__('Abriendo la oficina…', 'mad-oficina') . '</p>'
            . '<noscript>' . esc_html__('La oficina necesita JavaScript.', 'mad-oficina') . '</noscript></div>';
    }

    public function save_settings(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_portal_settings');
        $p = wp_unslash($_POST);

        \MAD_Oficina\Core\Agents\Characters::save((array) ($p['characters'] ?? []));

        $models = array_keys(\MAD_Oficina\Core\Agents\ClaudeClient::PRICES);
        update_option(MAD_Oficina_Core::option_key($this->slug()), [
            'receptionist_model'       => in_array($p['receptionist_model'] ?? '', $models, true) ? $p['receptionist_model'] : 'claude-haiku-5-5',
            'receptionist_prompt'      => sanitize_textarea_field($p['receptionist_prompt'] ?? ''),
            'receptionist_daily_limit' => max(1, min(500, (int) ($p['receptionist_daily_limit'] ?? 40))),
        ], false);

        \MAD_Oficina\Core\AdminUi::flash(__('Ajustes de la oficina guardados.', 'mad-oficina'));
        wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug()));
        exit;
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        include __DIR__ . '/views/settings.php';
    }
};
