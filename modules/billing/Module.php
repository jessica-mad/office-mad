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
    const CRON_HOOK = 'madoficina_billing_calendar';
    const FINANCE = 'mad-finanzas';

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
    public function menu_parent(){ return self::FINANCE; }
    public function description(){
        return __('Libro de facturas emitidas, clientes de facturación y resumen por mes, trimestre y cliente. Importa el histórico de tu hoja antigua.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients', 'meetings']; } // meetings: the cartero (Apps Script) archives and sends

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    /* ==== Hooks ==== */
    public function init(){
        add_action('admin_post_madof_billing_pdf', [$this, 'handle_billing_pdf']);
        foreach ( ['billing_import', 'billing_import_apply', 'billing_paid', 'billing_profile', 'billing_settings', 'billing_note', 'billing_link', 'billing_unlink', 'billing_profile_from_client', 'billing_link_bulk', 'billing_profile_status', 'billing_draft_save', 'billing_draft_delete', 'billing_rectify', 'billing_libro_sync', 'billing_ar_issued',
            'billing_item_create', 'billing_item_end', 'billing_item_change', 'billing_item_cancel', 'billing_quote_save', 'billing_quote_status', 'billing_quote_accept',
            'billing_calendar_settings', 'billing_prepare', 'billing_postpone', 'billing_skip', 'billing_tax_save', 'billing_tax_delete', 'billing_autonomos_settings', 'billing_finance_access'] as $a ) {
            add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
        }
        add_action('rest_api_init', fn() => (new \MAD_Oficina\Billing\PortalRest(fn() => $this->settings()))->register());
        add_action('rest_api_init', [$this, 'register_hooks']);

        // Issuing: approval in the inbox (and Chat), then the cartero.
        $type = \MAD_Oficina\Billing\Issuing::ACTION;
        add_filter('madoficina_approval_handler_' . $type, fn() => fn($approval, $input) => $this->issuing()->approve($approval, $input));
        add_filter('madoficina_approval_form_' . $type, fn($html, $approval) => $this->issuing()->render_form($html, $approval), 10, 2);
        add_filter('madoficina_approval_default_input_' . $type, fn($input, $approval) => $this->issuing()->default_input($approval), 10, 2);
        add_action('madoficina_order_done', ['MAD_Oficina\\Billing\\Issuing', 'order_done'], 10, 4);

        // Billing calendar: prepares the month's drafts and asks on each client's day.
        add_action(self::CRON_HOOK, fn() => $this->calendar()->run());
        add_action('madoficina_tick', fn() => $this->calendar()->run());
        // The cuota de autónomos notes itself each month.
        add_action(self::CRON_HOOK, fn() => \MAD_Oficina\Billing\Taxes::apply_monthly($this->settings()));
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        add_filter('madoficina_chat_approval_buttons', ['MAD_Oficina\\Billing\\Calendar', 'chat_buttons'], 10, 2);
        add_filter('madoficina_chat_action', ['MAD_Oficina\\Billing\\Calendar', 'chat_action'], 10, 5);
        // «Finanzas»: its own menu (billing, expenses, taxes), apart from the office.
        add_action('admin_menu', [$this, 'register_finance_menu'], 9);
        add_action('admin_menu', [$this, 'sort_finance_menu'], 999);
        add_filter('parent_file', fn($f) => in_array($_GET['page'] ?? '', [self::FINANCE, $this->menu_slug(), 'mad-oficina-gastos'], true) ? self::FINANCE : $f);
        add_filter('submenu_file', [$this, 'finance_submenu_file']);

        // One client, two faces: the office card shows the fiscal data it invoices under.
        add_action('madoficina_client_edit_identity', [$this, 'render_client_identity']);
        add_action('madoficina_client_list_head', [$this, 'render_client_list_head']);
        add_action('madoficina_client_list_cell', [$this, 'render_client_list_cell']);
    }

    public function admin_init(){}

    /** Finanzas → Resumen, Facturas, Calendario… Sections of a page are direct links to its tab. */
    public function register_finance_menu(){
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) return; // reserved for whoever Jessica chose
        $cap = MAD_Oficina_Core::CAPABILITY;
        $url = fn($page, $tab) => 'admin.php?page=' . $page . '&tab=' . $tab;
        add_menu_page(__('Finanzas', 'mad-oficina'), __('Finanzas', 'mad-oficina'), $cap, self::FINANCE, [$this, 'render_summary_page'], 'dashicons-chart-area', 57);
        add_submenu_page(self::FINANCE, __('Resumen', 'mad-oficina'), __('Resumen', 'mad-oficina'), $cap, self::FINANCE, [$this, 'render_summary_page'], 0);
        add_submenu_page(self::FINANCE, __('Facturas', 'mad-oficina'), __('Facturas', 'mad-oficina'), $cap, $this->menu_slug(), [$this, 'render_settings_page'], 1);
        foreach ( [2 => ['calendar', __('Calendario', 'mad-oficina')], 3 => ['forecast', __('Previsión', 'mad-oficina')], 4 => ['agreements', __('Acuerdos', 'mad-oficina')], 5 => ['quotes', __('Presupuestos', 'mad-oficina')], 6 => ['clients', __('Clientes de facturación', 'mad-oficina')], 20 => ['taxes', __('Impuestos', 'mad-oficina')], 30 => ['settings', __('Ajustes', 'mad-oficina')]] as $pos => [$tab, $label] ) {
            add_submenu_page(self::FINANCE, $label, $label, $cap, $url($this->menu_slug(), $tab), '', $pos);
        }
    }

    /** Other modules add their entries later (Gastos, Proveedores): «Impuestos» and «Ajustes» stay last. */
    public function sort_finance_menu(){
        global $submenu;
        if ( empty($submenu[self::FINANCE]) ) return;
        $last = fn($item) => (int) (str_contains($item[2], '&tab=settings') ? 2 : str_contains($item[2], '&tab=taxes'));
        $items = array_values($submenu[self::FINANCE]);
        $keyed = [];
        foreach ( $items as $i => $item ) $keyed[] = [$last($item), $i, $item];
        sort($keyed);
        $submenu[self::FINANCE] = array_column($keyed, 2);
    }

    /** Highlights the right entry of «Finanzas» for each tab. */
    public function finance_submenu_file($file){
        $page = $_GET['page'] ?? '';
        $tab = sanitize_key($_GET['tab'] ?? '');
        if ( $page === $this->menu_slug() && in_array($tab, ['calendar', 'forecast', 'agreements', 'quotes', 'clients', 'taxes', 'settings'], true) && ! isset($_GET['invoice']) && ! isset($_GET['edit']) ) {
            return 'admin.php?page=' . $page . '&tab=' . $tab;
        }
        if ( $page === 'mad-oficina-gastos' && $tab === 'suppliers' ) return 'admin.php?page=mad-oficina-gastos&tab=suppliers';
        return $file;
    }

    public function render_summary_page(){
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        include __DIR__ . '/views/finance-summary.php';
    }

    public function calendar(){
        return new \MAD_Oficina\Billing\Calendar($this->settings());
    }

    public function issuing(){
        return new \MAD_Oficina\Billing\Issuing($this->settings());
    }

    /** The cartero downloads the PDF it archives and sends (signed like the rest of its calls). */
    public function register_hooks(){
        $meetings = get_option(MAD_Oficina_Core::option_key('meetings'), []);
        $hooks = new \MAD_Oficina\Meetings\Hooks(fn() => \MAD_Oficina\Meetings\Hooks::secret_from(is_array($meetings) ? $meetings : []), fn() => null);
        register_rest_route('mad-oficina/v1', '/hooks/invoices/(?P<id>\d+)/pdf', [
            'methods' => 'GET', 'permission_callback' => [$hooks, 'verify'],
            'callback' => function(\WP_REST_Request $req){
                $inv = \MAD_Oficina\Billing\Invoices::get((int) $req['id']);
                if ( ! $inv ) return new \WP_Error('madof_not_found', 'Unknown invoice', ['status' => 404]);
                $pdf = \MAD_Oficina\Billing\InvoicePdf::render($inv, \MAD_Oficina\Billing\Issuing::issuer_for($inv, $this->settings()));
                if ( is_wp_error($pdf) ) return $pdf;
                return rest_ensure_response(['filename' => \MAD_Oficina\Billing\InvoicePdf::filename($inv), 'base64' => base64_encode($pdf)]);
            },
        ]);
    }

    /* ==== Admin ==== */

    private function guard($action){
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
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
        \MAD_Oficina\Billing\Issuing::queue_libro($id);
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

    /** A client that changed company: the old legal name stays (with its history) as «anterior». */
    public function handle_billing_profile_status(){
        $this->guard('billing_profile_status');
        $profile = \MAD_Oficina\Billing\Profiles::get(absint($_POST['profile_id'] ?? 0));
        if ( ! $profile || ! $profile['client_id'] ) $this->back(['tab' => 'clients'], __('Esos datos fiscales no existen.', 'mad-oficina'), 'error');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        \MAD_Oficina\Billing\Profiles::set_status($profile['id'], $status);
        $this->back_to_client($profile['client_id'], $status === 'active'
            ? sprintf(__('«%s» vuelve a ser una razón social actual.', 'mad-oficina'), $profile['legal_name'])
            : sprintf(__('«%s» queda como razón social anterior. Su historial sigue en la ficha y en su oficina.', 'mad-oficina'), $profile['legal_name']));
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
        if ( empty($client['id']) || ! \MAD_Oficina\Core\FinanceAccess::can() ) return;
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
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) return;
        echo '<th scope="col">' . esc_html__('Razón social', 'mad-oficina') . '</th>';
    }

    public function render_client_list_cell($client){
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) return;
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
        $s['billing_day'] = max(1, min(31, (int) ($p['billing_day'] ?? 28)));
        $s['payment_days'] = max(0, min(365, (int) ($p['payment_days'] ?? 15)));
        $s['email_subject'] = sanitize_text_field($p['email_subject'] ?? '');
        $s['email_body'] = sanitize_textarea_field($p['email_body'] ?? '');
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'settings'], __('Datos del emisor guardados.', 'mad-oficina'));
    }

    /** Editor: save the draft, and optionally ask to issue it or send a test to Jessica. */
    public function handle_billing_draft_save(){
        $this->guard('billing_draft_save');
        $p = wp_unslash($_POST);
        $id = absint($p['invoice_id'] ?? 0);
        $lines = [];
        foreach ( (array) ($p['lines'] ?? []) as $l ) if ( is_array($l) ) $lines[] = $l;
        $saved = $this->issuing()->save_draft($p, $lines, $id);
        if ( is_wp_error($saved) ) {
            $this->back($id ? ['edit' => $id] : ['edit' => 'new', 'profile' => absint($p['profile_id'] ?? 0)], $saved->get_error_message(), 'error');
        }
        $do = sanitize_key($p['do'] ?? 'save');
        if ( $do === 'issue' ) {
            $approval = $this->issuing()->request($saved);
            if ( is_wp_error($approval) ) $this->back(['edit' => $saved], $approval->get_error_message(), 'error');
            \MAD_Oficina\Core\AdminUi::flash(__('Aquí la tienes: pulsa «Aprobar» y se numera, se guarda en Drive y sale desde tu Gmail. También te ha llegado a Chat.', 'mad-oficina'));
            wp_safe_redirect(admin_url('admin.php?page=mad-oficina-bandeja#approval-' . (int) $approval));
            exit;
        }
        if ( $do === 'schedule' ) {
            $r = \MAD_Oficina\Billing\Calendar::schedule($saved, sanitize_text_field($p['send_on'] ?? ''), ! empty($p['send_alone']));
            $this->back(['edit' => $saved], is_wp_error($r) ? $r->get_error_message() : $r['message'], is_wp_error($r) ? 'error' : 'success');
        }
        if ( $do === 'test' ) {
            $queued = $this->issuing()->queue_test($saved, wp_get_current_user()->user_email);
            $this->back(['edit' => $saved], is_wp_error($queued) ? $queued->get_error_message() : __('Borrador guardado. El cartero te enviará la prueba a tu email en unos minutos.', 'mad-oficina'), is_wp_error($queued) ? 'error' : 'success');
        }
        $this->back(['edit' => $saved], __('Borrador guardado.', 'mad-oficina'));
    }

    /** One draft (editor, card, list) or several marked in the list. */
    public function handle_billing_draft_delete(){
        $this->guard('billing_draft_delete');
        $ids = array_filter(array_map('absint', isset($_POST['ids']) ? (array) $_POST['ids'] : [$_POST['invoice_id'] ?? 0]));
        $done = 0;
        foreach ( $ids as $id ) if ( \MAD_Oficina\Billing\Issuing::delete_draft($id) ) $done++;
        if ( ! $done ) $this->back([], __('No he borrado nada: solo se borran borradores (las facturas emitidas se rectifican).', 'mad-oficina'), 'error');
        $this->back([], sprintf(_n('Borrador eliminado.', '%d borradores eliminados.', $done, 'mad-oficina'), $done)
            . ' ' . __('Los que preparó el calendario ya no vuelven solos; si quieres rehacer uno, Calendario → «Preparar ahora».', 'mad-oficina'));
    }

    public function handle_billing_rectify(){
        $this->guard('billing_rectify');
        $id = $this->issuing()->rectify(absint($_POST['invoice_id'] ?? 0));
        if ( is_wp_error($id) ) $this->back(['invoice' => absint($_POST['invoice_id'] ?? 0)], $id->get_error_message(), 'error');
        $this->back(['edit' => $id], __('Rectificativa preparada con los conceptos en negativo. Ajústala si solo corrige una parte y pide emitirla.', 'mad-oficina'));
    }

    public function handle_billing_libro_sync(){
        $this->guard('billing_libro_sync');
        \MAD_Oficina\Billing\Issuing::queue_libro_sync();
        $this->back(['tab' => 'settings'], __('El cartero volcará todas las facturas emitidas en el Libro MAD en su próxima vuelta (unos minutos).', 'mad-oficina'));
    }

    /** Argentina: the invoice is issued on the Argentine platform; here it is only recorded. */
    public function handle_billing_ar_issued(){
        $this->guard('billing_ar_issued');
        global $wpdb;
        $id = absint($_POST['invoice_id'] ?? 0);
        $wpdb->update(\MAD_Oficina\Billing\Invoices::table(), ['status' => 'sent', 'sent_at' => \MAD_Oficina\Core\Time::now(), 'updated_at' => \MAD_Oficina\Core\Time::now()], ['id' => $id, 'series' => 'AR', 'status' => 'draft']);
        \MAD_Oficina\Billing\Issuing::queue_libro($id);
        $this->back(['invoice' => $id], __('Apuntada como emitida en Argentina.', 'mad-oficina'));
    }

    /* ==== Agreements and quotes (forecast) ==== */

    private function back_to($default_tab, $notice, $type = 'success', array $extra = []){
        $to = sanitize_key($_POST['return'] ?? '');
        $this->back(['tab' => in_array($to, ['forecast', 'agreements', 'quotes'], true) ? $to : $default_tab] + $extra, $notice, $type);
    }

    public function handle_billing_item_create(){
        $this->guard('billing_item_create');
        $r = \MAD_Oficina\Billing\Agreements::create(wp_unslash($_POST));
        if ( is_wp_error($r) ) $this->back_to('agreements', $r->get_error_message(), 'error');
        $this->back_to('agreements', __('Acuerdo guardado. Ya cuenta en la previsión.', 'mad-oficina'));
    }

    public function handle_billing_item_end(){
        $this->guard('billing_item_end');
        $month = ! empty($_POST['reopen']) ? null : sanitize_text_field(wp_unslash($_POST['end_month'] ?? ''));
        $r = \MAD_Oficina\Billing\Agreements::end(absint($_POST['item_id'] ?? 0), $month);
        if ( is_wp_error($r) ) $this->back_to('agreements', $r->get_error_message(), 'error');
        $this->back_to('agreements', $month === null ? __('El fee vuelve a ser sin fecha de fin.', 'mad-oficina') : sprintf(__('El fee termina en %s.', 'mad-oficina'), \MAD_Oficina\Billing\Months::label($month)));
    }

    public function handle_billing_item_change(){
        $this->guard('billing_item_change');
        $r = \MAD_Oficina\Billing\Agreements::change_amount(absint($_POST['item_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['from_month'] ?? '')), sanitize_text_field(wp_unslash($_POST['amount'] ?? '')));
        if ( is_wp_error($r) ) $this->back_to('agreements', $r->get_error_message(), 'error');
        $this->back_to('agreements', __('Importe cambiado. El anterior queda en el historial hasta el mes de antes.', 'mad-oficina'));
    }

    public function handle_billing_item_cancel(){
        $this->guard('billing_item_cancel');
        \MAD_Oficina\Billing\Agreements::cancel(absint($_POST['item_id'] ?? 0));
        $this->back_to('agreements', __('Acuerdo borrado de la previsión.', 'mad-oficina'));
    }

    public function handle_billing_quote_save(){
        $this->guard('billing_quote_save');
        $p = wp_unslash($_POST);
        $id = absint($p['quote_id'] ?? 0);
        $lines = [];
        foreach ( (array) ($p['lines'] ?? []) as $l ) if ( is_array($l) ) $lines[] = $l;
        $r = \MAD_Oficina\Billing\Quotes::save($p, $lines, $id);
        if ( is_wp_error($r) ) $this->back(['tab' => 'quotes', 'quote' => $id ?: 'new'], $r->get_error_message(), 'error');
        $this->back(['tab' => 'quotes'], __('Presupuesto guardado. Si está enviado, ya suma en «posible» de la previsión.', 'mad-oficina'));
    }

    public function handle_billing_quote_status(){
        $this->guard('billing_quote_status');
        $r = \MAD_Oficina\Billing\Quotes::set_status(absint($_POST['quote_id'] ?? 0), sanitize_key($_POST['status'] ?? ''));
        $this->back_to('quotes', is_wp_error($r) ? $r->get_error_message() : __('Presupuesto actualizado.', 'mad-oficina'), is_wp_error($r) ? 'error' : 'success');
    }

    public function handle_billing_quote_accept(){
        $this->guard('billing_quote_accept');
        $r = \MAD_Oficina\Billing\Quotes::accept(absint($_POST['quote_id'] ?? 0), absint($_POST['profile_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['start_month'] ?? '')));
        if ( is_wp_error($r) ) $this->back_to('quotes', $r->get_error_message(), 'error');
        $this->back(['tab' => 'agreements'], sprintf(_n('¡Aceptado! He creado %d acuerdo: ya cuenta como confirmado.', '¡Aceptado! He creado %d acuerdos: ya cuentan como confirmados.', count($r), 'mad-oficina'), count($r)));
    }

    /* ==== Billing calendar ==== */

    public function handle_billing_calendar_settings(){
        $this->guard('billing_calendar_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        $s['calendar_enabled'] = ! empty($p['calendar_enabled']);
        $s['calendar_from'] = \MAD_Oficina\Billing\Months::valid($p['calendar_from'] ?? '') ?: \MAD_Oficina\Billing\Months::add(\MAD_Oficina\Billing\Months::current(), 1);
        $s['calendar_lead_days'] = max(0, min(15, (int) ($p['calendar_lead_days'] ?? 3)));
        $s['calendar_hour'] = max(6, min(20, (int) ($p['calendar_hour'] ?? 9)));
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'calendar'], $s['calendar_enabled']
            ? sprintf(__('Calendario activado desde %s. Prepararé cada borrador %d días antes y te preguntaré el día que toca.', 'mad-oficina'), \MAD_Oficina\Billing\Months::label($s['calendar_from']), $s['calendar_lead_days'])
            : __('Calendario en pausa: no preparo borradores ni pregunto.', 'mad-oficina'));
    }

    /** «Preparar ahora»: the client's draft for the month, without waiting. */
    public function handle_billing_prepare(){
        $this->guard('billing_prepare');
        $profile = \MAD_Oficina\Billing\Profiles::get(absint($_POST['profile_id'] ?? 0));
        $month = \MAD_Oficina\Billing\Months::valid($_POST['month'] ?? '');
        if ( ! $profile || ! $month ) $this->back(['tab' => 'calendar'], __('No sé qué preparar.', 'mad-oficina'), 'error');
        \MAD_Oficina\Billing\Calendar::dismiss($profile['id'], [$month], false); // she asked for it again
        $id = $this->calendar()->prepare_one($profile, $month);
        if ( is_wp_error($id) ) $this->back(['tab' => 'calendar', 'month' => $month], $id->get_error_message(), 'error');
        if ( ! $id ) $this->back(['tab' => 'calendar', 'month' => $month], __('Ese mes ya estaba todo facturado.', 'mad-oficina'));
        global $wpdb;
        $wpdb->update(\MAD_Oficina\Billing\Invoices::table(), ['source' => \MAD_Oficina\Billing\Calendar::SOURCE], ['id' => $id]);
        $this->back(['edit' => $id], __('Borrador preparado con los acuerdos del mes. Revísalo y pide la emisión cuando quieras (o espera: te pregunto el día que toca).', 'mad-oficina'));
    }

    public function handle_billing_postpone(){
        $this->guard('billing_postpone');
        $r = \MAD_Oficina\Billing\Calendar::postpone(absint($_POST['invoice_id'] ?? 0), absint($_POST['days'] ?? 7));
        $this->back(['tab' => 'calendar'], is_wp_error($r) ? $r->get_error_message() : $r['message'], is_wp_error($r) ? 'error' : 'success');
    }

    public function handle_billing_skip(){
        $this->guard('billing_skip');
        $r = \MAD_Oficina\Billing\Calendar::skip_invoice(absint($_POST['invoice_id'] ?? 0));
        $this->back(['tab' => 'calendar'], is_wp_error($r) ? $r->get_error_message() : $r['message'], is_wp_error($r) ? 'error' : 'success');
    }

    /* ==== Who sees Finanzas ==== */

    /** «Reservármela» or the list in Ajustes: only the chosen managers see Finanzas. */
    public function handle_billing_finance_access(){
        $this->guard('billing_finance_access');
        $ids = isset($_POST['users']) ? array_map('absint', (array) $_POST['users']) : [get_current_user_id()];
        $r = \MAD_Oficina\Core\FinanceAccess::set($ids);
        if ( is_wp_error($r) ) $this->back(['tab' => 'settings'], $r->get_error_message(), 'error');
        $names = array_map(fn($id) => get_userdata($id)->display_name, \MAD_Oficina\Core\FinanceAccess::users());
        \MAD_Oficina\Core\AdminUi::flash(sprintf(__('Finanzas solo la ve: %s.', 'mad-oficina'), implode(', ', $names)));
        wp_safe_redirect(admin_url(! empty($_POST['from_summary']) ? 'admin.php?page=' . self::FINANCE : 'admin.php?page=' . $this->menu_slug() . '&tab=settings'));
        exit;
    }

    /* ==== Taxes ==== */

    public function handle_billing_tax_save(){
        $this->guard('billing_tax_save');
        $p = wp_unslash($_POST);
        $id = \MAD_Oficina\Billing\Taxes::save($p, absint($p['tax_id'] ?? 0));
        $year = (int) substr((string) ($p['period'] ?? ''), 0, 4) ?: (int) current_time('Y');
        if ( is_wp_error($id) ) $this->back(['tab' => 'taxes', 'year' => $year], $id->get_error_message(), 'error');
        $this->back(['tab' => 'taxes', 'year' => $year], __('Apuntado.', 'mad-oficina'));
    }

    public function handle_billing_tax_delete(){
        $this->guard('billing_tax_delete');
        \MAD_Oficina\Billing\Taxes::delete(absint($_POST['tax_id'] ?? 0));
        $this->back(['tab' => 'taxes', 'year' => absint($_POST['year'] ?? 0) ?: (int) current_time('Y')], __('Apunte borrado.', 'mad-oficina'));
    }

    public function handle_billing_autonomos_settings(){
        $this->guard('billing_autonomos_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        $s['autonomos_amount'] = (float) (\MAD_Oficina\Billing\Money::parse((string) ($p['autonomos_amount'] ?? '')) ?? 0);
        $s['autonomos_from'] = \MAD_Oficina\Billing\Months::valid($p['autonomos_from'] ?? '') ?: \MAD_Oficina\Billing\Months::current();
        $s['autonomos_day'] = max(1, min(31, (int) ($p['autonomos_day'] ?? 28)));
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $n = \MAD_Oficina\Billing\Taxes::apply_monthly($s);
        $this->back(['tab' => 'taxes'], $s['autonomos_amount'] > 0
            ? sprintf(__('Cuota guardada: %1$s al mes desde %2$s. He apuntado %3$d meses.', 'mad-oficina'), \MAD_Oficina\Billing\Money::format($s['autonomos_amount']), \MAD_Oficina\Billing\Months::label($s['autonomos_from']), $n)
            : __('Sin cuota: no apunto nada automático.', 'mad-oficina'));
    }

    /** The invoice as PDF with MAD's design (opens in the browser). */
    public function handle_billing_pdf(){
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        $id = absint($_GET['invoice'] ?? 0);
        check_admin_referer('madof_billing_pdf_' . $id);
        $inv = \MAD_Oficina\Billing\Invoices::get($id);
        if ( ! $inv ) wp_die(esc_html__('Esa factura no existe.', 'mad-oficina'), 404);
        $pdf = \MAD_Oficina\Billing\InvoicePdf::render($inv, \MAD_Oficina\Billing\Issuing::issuer_for($inv, $this->settings()));
        if ( is_wp_error($pdf) ) wp_die(esc_html($pdf->get_error_message()));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode(\MAD_Oficina\Billing\InvoicePdf::filename($inv)) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf; // binary PDF
        exit;
    }

    public function render_settings_page(){
        if ( ! \MAD_Oficina\Core\FinanceAccess::can() ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        if ( isset($_GET['edit']) ) {
            $issuing = $this->issuing();
            $draft = $_GET['edit'] === 'new' ? null : \MAD_Oficina\Billing\Invoices::get(absint($_GET['edit']));
            if ( $draft && $draft['status'] !== 'draft' ) { wp_safe_redirect(add_query_arg(['page' => $this->menu_slug(), 'invoice' => $draft['id']], admin_url('admin.php'))); exit; }
            if ( $draft ) $profile = \MAD_Oficina\Billing\Profiles::get((int) $draft['profile_id']);
            elseif ( isset($_GET['profile']) ) $profile = \MAD_Oficina\Billing\Profiles::get(absint($_GET['profile']));
            elseif ( isset($_GET['client']) ) $profile = \MAD_Oficina\Billing\Profiles::current_for_client(absint($_GET['client']));
            else $profile = null;
            include __DIR__ . '/views/invoice-edit.php';
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'invoices');
        $invoice = isset($_GET['invoice']) ? \MAD_Oficina\Billing\Invoices::get(absint($_GET['invoice'])) : null;
        $preview = $tab === 'import' && ! empty($_GET['preview']) ? get_transient(self::IMPORT_TRANSIENT . get_current_user_id()) : null;
        include __DIR__ . '/views/billing.php';
    }
};
