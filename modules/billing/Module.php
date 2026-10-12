<?php
/**
 * Módulo: Facturación.
 * Libro de facturas emitidas (histórico importado y nuevas), clientes de
 * facturación, resumen por mes, trimestre y cliente. La emisión y el envío
 * llegan en la siguiente parte.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const IMPORT_TRANSIENT = 'madof_billing_import_';

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Billing\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'billing'; }
    public function title(){ return __('Facturación', 'mad-oficina'); }
    public function menu_label(){ return __('Facturación', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-facturacion'; }
    public function description(){
        return __('Libro de facturas emitidas, clientes de facturación y resumen por mes, trimestre y cliente. Importa el histórico de tu hoja antigua.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients']; }

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    /* ==== Hooks ==== */
    public function init(){
        foreach ( ['billing_import', 'billing_import_apply', 'billing_paid', 'billing_profile', 'billing_settings', 'billing_note'] as $a ) {
            add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
        }
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

    public function handle_billing_import(){
        $this->guard('billing_import');
        $f = $_FILES['csv'] ?? null;
        if ( ! $f || empty($f['tmp_name']) || ! is_uploaded_file($f['tmp_name']) ) $this->back(['tab' => 'import'], __('Sube el CSV de la pestaña «facturacion».', 'mad-oficina'), 'error');
        if ( $f['size'] > 5 * MB_IN_BYTES ) $this->back(['tab' => 'import'], __('El archivo es demasiado grande.', 'mad-oficina'), 'error');
        $analysis = \MAD_Oficina\Billing\LegacyImport::analyze(file_get_contents($f['tmp_name']));
        if ( is_wp_error($analysis) ) $this->back(['tab' => 'import'], $analysis->get_error_message(), 'error');
        set_transient(self::IMPORT_TRANSIENT . get_current_user_id(), $analysis, HOUR_IN_SECONDS);
        $this->back(['tab' => 'import', 'preview' => 1], __('Revisa la vista previa y confirma la importación.', 'mad-oficina'));
    }

    public function handle_billing_import_apply(){
        $this->guard('billing_import_apply');
        $analysis = get_transient(self::IMPORT_TRANSIENT . get_current_user_id());
        if ( ! is_array($analysis) ) $this->back(['tab' => 'import'], __('La vista previa ha caducado. Vuelve a subir el archivo.', 'mad-oficina'), 'error');
        $ar = array_map('sanitize_text_field', array_map('wp_unslash', (array) ($_POST['argentina'] ?? [])));
        $ar = array_values(array_intersect($ar, array_keys($analysis['ar_candidates'])));
        $count = \MAD_Oficina\Billing\LegacyImport::apply($analysis, $ar);
        delete_transient(self::IMPORT_TRANSIENT . get_current_user_id());
        $this->back(['tab' => 'import'], sprintf(
            __('Importadas %1$d facturas (%2$d de la serie Argentina, %3$d borradores, %4$d con número a reasignar). Ya existían %5$d; filas vacías omitidas: %6$d.', 'mad-oficina'),
            $count['imported'], $count['argentina'], $count['drafts'], $count['renumbered'], $count['skipped_existing'], $count['skipped_empty']
        ));
    }

    public function handle_billing_paid(){
        $this->guard('billing_paid');
        $id = absint($_POST['invoice_id'] ?? 0);
        \MAD_Oficina\Billing\Invoices::set_paid($id, ! empty($_POST['paid']));
        $this->back(['invoice' => $id], ! empty($_POST['paid']) ? __('Marcada como cobrada.', 'mad-oficina') : __('Marcada como pendiente de cobro.', 'mad-oficina'));
    }

    public function handle_billing_note(){
        $this->guard('billing_note');
        global $wpdb;
        $id = absint($_POST['invoice_id'] ?? 0);
        $wpdb->update(\MAD_Oficina\Billing\Invoices::table(), ['notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')), 'updated_at' => \MAD_Oficina\Core\Time::now()], ['id' => $id]);
        $this->back(['invoice' => $id], __('Nota guardada.', 'mad-oficina'));
    }

    public function handle_billing_profile(){
        $this->guard('billing_profile');
        $p = wp_unslash($_POST);
        $id = absint($p['profile_id'] ?? 0);
        $client = absint($p['client_id'] ?? 0);
        $data = $p + ['client_id' => $client && \MAD_Oficina\Core\Repo\Clients::get($client) ? $client : null];
        if ( trim($data['legal_name'] ?? '') === '' ) $this->back(['tab' => 'clients', 'profile' => $id], __('El nombre fiscal es obligatorio.', 'mad-oficina'), 'error');
        $id = \MAD_Oficina\Billing\Profiles::save($data, $id);
        $this->back(['tab' => 'clients', 'profile' => $id], __('Cliente de facturación guardado.', 'mad-oficina'));
    }

    public function handle_billing_settings(){
        $this->guard('billing_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        foreach ( ['issuer_name', 'issuer_tax_id', 'issuer_address', 'issuer_city', 'issuer_email', 'issuer_iban'] as $k ) $s[$k] = sanitize_text_field($p[$k] ?? '');
        $s['payment_terms'] = sanitize_textarea_field($p['payment_terms'] ?? '');
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'settings'], __('Datos del emisor guardados.', 'mad-oficina'));
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        $tab = sanitize_key($_GET['tab'] ?? 'invoices');
        $invoice = isset($_GET['invoice']) ? \MAD_Oficina\Billing\Invoices::get(absint($_GET['invoice'])) : null;
        $preview = $tab === 'import' && ! empty($_GET['preview']) ? get_transient(self::IMPORT_TRANSIENT . get_current_user_id()) : null;
        include __DIR__ . '/views/billing.php';
    }
};
