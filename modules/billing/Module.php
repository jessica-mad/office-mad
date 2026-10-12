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
        add_action('admin_post_madof_billing_pdf', [$this, 'handle_billing_pdf']);
        foreach ( ['billing_import', 'billing_import_apply', 'billing_paid', 'billing_profile', 'billing_settings', 'billing_note', 'billing_link', 'billing_unlink', 'billing_profile_from_client', 'billing_link_bulk'] as $a ) {
            add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
        }
        // One client, two faces: the office card shows the fiscal data it invoices under.
        add_action('madoficina_client_edit_identity', [$this, 'render_client_identity']);
        add_action('madoficina_client_list_head', [$this, 'render_client_list_head']);
        add_action('madoficina_client_list_cell', [$this, 'render_client_list_cell']);
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
        \MAD_Oficina\Billing\Profiles::link($id, $data['client_id']); // its invoices follow
        $this->back(['tab' => 'clients', 'profile' => $id], __('Cliente de facturación guardado.', 'mad-oficina'));
    }

    /** Back to the office client's card when the action started there. */
    private function back_to_client($client_id, $notice, $type = 'success'){
        \MAD_Oficina\Core\AdminUi::flash($notice, $type);
        wp_safe_redirect(\MAD_Oficina\Clients\Actions::client_url($client_id));
        exit;
    }

    public function handle_billing_link(){
        $this->guard('billing_link');
        $profile = \MAD_Oficina\Billing\Profiles::get(absint($_POST['profile_id'] ?? 0));
        $client = \MAD_Oficina\Core\Repo\Clients::get(absint($_POST['client_id'] ?? 0));
        $from_client = ! empty($_POST['from_client']);
        if ( ! $profile || ! $client ) {
            $msg = __('Elige los datos fiscales y el cliente que quieres unir.', 'mad-oficina');
            $client && $from_client ? $this->back_to_client($client['id'], $msg, 'error') : $this->back(['tab' => 'clients'], $msg, 'error');
        }
        \MAD_Oficina\Billing\Profiles::link($profile['id'], $client['id']);
        $msg = sprintf(__('«%1$s» ahora es la razón social de %2$s. Sus facturas aparecen en su ficha.', 'mad-oficina'), $profile['legal_name'], $client['name']);
        $from_client ? $this->back_to_client($client['id'], $msg) : $this->back(['tab' => 'clients'], $msg);
    }

    public function handle_billing_unlink(){
        $this->guard('billing_unlink');
        $profile = \MAD_Oficina\Billing\Profiles::get(absint($_POST['profile_id'] ?? 0));
        if ( ! $profile ) $this->back(['tab' => 'clients'], __('Esos datos fiscales no existen.', 'mad-oficina'), 'error');
        $client = $profile['client_id'];
        \MAD_Oficina\Billing\Profiles::link($profile['id'], null);
        $msg = sprintf(__('«%s» ya no está unido a este cliente. Sus facturas se conservan.', 'mad-oficina'), $profile['legal_name']);
        $client && ! empty($_POST['from_client']) ? $this->back_to_client($client, $msg) : $this->back(['tab' => 'clients', 'profile' => $profile['id']], $msg);
    }

    /** New fiscal data for an office client that has never been invoiced. */
    public function handle_billing_profile_from_client(){
        $this->guard('billing_profile_from_client');
        $client = \MAD_Oficina\Core\Repo\Clients::get(absint($_POST['client_id'] ?? 0));
        if ( ! $client ) $this->back(['tab' => 'clients'], __('Ese cliente no existe.', 'mad-oficina'), 'error');
        $id = \MAD_Oficina\Billing\Profiles::save(['legal_name' => $client['name'], 'client_id' => $client['id']]);
        $this->back(['tab' => 'clients', 'profile' => $id], __('Completa su nombre fiscal, NIF y dirección tal como deben salir en la factura.', 'mad-oficina'));
    }

    /** Joins every suggestion that is clearly right (same name, a contact's email…). */
    public function handle_billing_link_bulk(){
        $this->guard('billing_link_bulk');
        $n = 0;
        foreach ( \MAD_Oficina\Billing\Profiles::suggestions() as $s ) {
            if ( $s['score'] < \MAD_Oficina\Billing\Profiles::SURE ) continue;
            \MAD_Oficina\Billing\Profiles::link($s['profile']['id'], $s['client']['id']);
            $n++;
        }
        $this->back(['tab' => 'clients'], sprintf(_n('%d cliente unido.', '%d clientes unidos.', $n, 'mad-oficina'), $n));
    }

    /* ==== Client card and list ==== */

    public function render_client_identity($client){
        if ( empty($client['id']) || ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) return;
        $linked = \MAD_Oficina\Billing\Profiles::for_client($client['id']);
        $unlinked = \MAD_Oficina\Billing\Profiles::unlinked();
        $suggested = [];
        foreach ( \MAD_Oficina\Billing\Profiles::suggestions() as $s ) {
            if ( (int) $s['client']['id'] === (int) $client['id'] ) $suggested[$s['profile']['id']] = $s;
        }
        include __DIR__ . '/views/client-identity.php';
    }

    private $legal_names = null;

    public function render_client_list_head(){
        echo '<th scope="col">' . esc_html__('Razón social', 'mad-oficina') . '</th>';
    }

    public function render_client_list_cell($client){
        if ( $this->legal_names === null ) {
            $this->legal_names = [];
            foreach ( \MAD_Oficina\Billing\Profiles::all() as $p ) if ( $p['client_id'] ) $this->legal_names[$p['client_id']][] = $p['legal_name'];
        }
        $names = $this->legal_names[$client['id']] ?? [];
        echo '<td>' . ( $names ? esc_html(implode(' · ', $names)) : '<small>' . esc_html__('sin datos fiscales', 'mad-oficina') . '</small>' ) . '</td>';
    }

    public function handle_billing_settings(){
        $this->guard('billing_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        foreach ( ['issuer_name', 'issuer_tagline', 'issuer_tax_label', 'issuer_tax_id', 'issuer_address', 'issuer_city', 'issuer_email', 'issuer_phone', 'payment_method', 'issuer_bank', 'issuer_iban'] as $k ) $s[$k] = sanitize_text_field($p[$k] ?? '');
        $s['payment_terms'] = sanitize_textarea_field($p['payment_terms'] ?? '');
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'settings'], __('Datos del emisor guardados.', 'mad-oficina'));
    }

    /** The invoice as PDF with MAD's design (opens in the browser). */
    public function handle_billing_pdf(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        $id = absint($_GET['invoice'] ?? 0);
        check_admin_referer('madof_billing_pdf_' . $id);
        $inv = \MAD_Oficina\Billing\Invoices::get($id);
        if ( ! $inv ) wp_die(esc_html__('Esa factura no existe.', 'mad-oficina'), 404);
        $pdf = \MAD_Oficina\Billing\InvoicePdf::render($inv, $this->settings());
        if ( is_wp_error($pdf) ) wp_die(esc_html($pdf->get_error_message()));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode(\MAD_Oficina\Billing\InvoicePdf::filename($inv)) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf; // binary PDF
        exit;
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
