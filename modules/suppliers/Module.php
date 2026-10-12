<?php
/**
 * Módulo: Proveedores y gastos · la Contable.
 * El cartero le pasa los PDF de tu Gmail; ella los lee, registra solo las facturas
 * a tu nombre (las de otros las guarda aparte), las archiva en «Recibidas/AAAA/Tn/»
 * y te pregunta por proveedores nuevos y facturas que no llegan.
 */
if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Oficina_Module {

    const CRON_HOOK = 'madoficina_suppliers_hourly';

    private $core;

    public function __construct($core){
        $this->core = $core;
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        $prefix = 'MAD_Oficina\\Suppliers\\';
        if ( strpos($class, $prefix) !== 0 ) return;
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if ( file_exists($file) ) require_once $file;
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'suppliers'; }
    public function title(){ return __('Proveedores y gastos', 'mad-oficina'); }
    public function menu_label(){ return __('Gastos', 'mad-oficina'); }
    public function menu_slug(){ return 'mad-oficina-gastos'; }
    public function menu_parent(){ return 'mad-finanzas'; }
    public function description(){
        return __('La Contable lee las facturas de tus proveedores en Gmail, registra solo las que van a tu nombre, las archiva en la unidad compartida y te avisa de las que faltan.', 'mad-oficina');
    }
    public function dependencies(){ return ['clients', 'meetings', 'billing']; } // meetings: the cartero · billing: fiscal profiles and re-billing

    public function settings(){
        $s = get_option(MAD_Oficina_Core::option_key($this->slug()), []);
        return is_array($s) ? $s : [];
    }

    public function intake(){ return new \MAD_Oficina\Suppliers\Intake($this->settings()); }
    public function watch(){ return new \MAD_Oficina\Suppliers\Watch($this->settings()); }

    /* ==== Hooks ==== */
    public function init(){
        add_action('rest_api_init', [$this, 'register_hooks']);
        add_action('madoficina_order_done', ['MAD_Oficina\\Suppliers\\Intake', 'order_done'], 10, 4);
        add_action('madoficina_expense_registered', ['MAD_Oficina\\Suppliers\\Watch', 'on_registered']);

        // Daily watch and leftovers to read (the cartero's tick every 10 min is the reliable clock).
        add_action(\MAD_Oficina\Suppliers\Intake::READ_HOOK, fn($id) => $this->intake()->process((int) $id));
        $tick = function(){ $this->intake()->process_queued(); $this->watch()->run(); };
        add_action(self::CRON_HOOK, $tick);
        add_action('madoficina_tick', $tick);
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) wp_schedule_event(time() + 600, 'hourly', self::CRON_HOOK);

        $I = \MAD_Oficina\Suppliers\Intake::class;
        add_filter('madoficina_approval_handler_' . $I::NEW_SUPPLIER, fn() => fn($a, $input) => $this->intake()->approve_new_supplier($a, $input));
        add_filter('madoficina_approval_form_' . $I::NEW_SUPPLIER, fn($h, $a) => $this->intake()->render_new_supplier_form($h, $a), 10, 2);
        add_filter('madoficina_approval_default_input_' . $I::NEW_SUPPLIER, fn($in, $a) => $this->intake()->new_supplier_defaults($a) + ['filter' => '1'], 10, 2);
        add_action('madoficina_approval_rejected_' . $I::NEW_SUPPLIER, fn($a) => $this->intake()->reject_new_supplier($a));
        add_filter('madoficina_approval_handler_' . $I::REVIEW, fn() => fn($a, $input) => $this->intake()->approve_review($a, $input));
        add_filter('madoficina_approval_form_' . $I::REVIEW, fn($h, $a) => $this->intake()->render_review_form($h, $a), 10, 2);
        add_action('madoficina_approval_rejected_' . $I::REVIEW, fn($a) => $this->intake()->reject_review($a));

        $W = \MAD_Oficina\Suppliers\Watch::class;
        add_filter('madoficina_approval_handler_' . $W::ACTION, fn() => fn($a, $input) => $W::answer($a, sanitize_key($input['choice'] ?? 'later'), (int) ($input['days'] ?? 7)));
        add_filter('madoficina_approval_form_' . $W::ACTION, [$W, 'render_form'], 10, 2);
        add_filter('madoficina_approval_default_input_' . $W::ACTION, fn() => ['choice' => 'later', 'days' => 7]);
        add_action('madoficina_approval_rejected_' . $W::ACTION, fn($a) => $W::answer($a, 'none'));
        add_filter('madoficina_chat_approval_buttons', [$W, 'chat_buttons'], 10, 2);
        add_filter('madoficina_chat_action', [$W, 'chat_action'], 10, 5);

        // Re-billable expenses ride on the client's next invoice.
        add_filter('madoficina_billing_extra_lines', [$this, 'rebill_lines'], 10, 3);
        add_filter('madoficina_billing_pending_profiles', [$this, 'rebill_profiles'], 10, 2);

        add_action('admin_menu', function(){
            $cap = MAD_Oficina_Core::CAPABILITY;
            add_submenu_page('mad-finanzas', $this->title(), __('Gastos', 'mad-oficina'), $cap, $this->menu_slug(), [$this, 'render_settings_page'], 10);
            add_submenu_page('mad-finanzas', __('Proveedores', 'mad-oficina'), __('Proveedores', 'mad-oficina'), $cap, 'admin.php?page=' . $this->menu_slug() . '&tab=suppliers', '', 11);
        }, 11);

        foreach ( ['supplier_save', 'supplier_merge', 'expense_bulk', 'expense_read_now', 'expense_save', 'expense_mine', 'expense_upload', 'suppliers_settings'] as $a ) add_action('admin_post_madof_' . $a, [$this, 'handle_' . $a]);
    }

    public function admin_init(){}

    /* ==== Signed endpoints for the cartero ==== */

    public function register_hooks(){
        $meetings = get_option(MAD_Oficina_Core::option_key('meetings'), []);
        $hooks = new \MAD_Oficina\Meetings\Hooks(fn() => \MAD_Oficina\Meetings\Hooks::secret_from(is_array($meetings) ? $meetings : []), fn() => null);
        $ns = 'mad-oficina/v1';
        // What to look for in Gmail.
        register_rest_route($ns, '/hooks/expenses/config', ['methods' => 'GET', 'permission_callback' => [$hooks, 'verify'], 'callback' => fn() => rest_ensure_response([
            'senders' => \MAD_Oficina\Suppliers\Suppliers::known_emails(),
            'keywords' => ['factura', 'invoice', 'recibo', 'receipt', 'fattura', 'rechnung', 'facture', 'billing'],
            'max_bytes' => 4 * 1024 * 1024,
        ])]);
        // Which attachments are new (so the same PDF is never uploaded twice).
        register_rest_route($ns, '/hooks/expenses/known', ['methods' => 'POST', 'permission_callback' => [$hooks, 'verify'], 'callback' => function(\WP_REST_Request $r){
            return rest_ensure_response(['unknown' => \MAD_Oficina\Suppliers\Expenses::unknown_hashes((array) ($r->get_json_params()['hashes'] ?? []))]);
        }]);
        // A PDF from Gmail. Its content is data: it is only read, never obeyed.
        register_rest_route($ns, '/hooks/expenses', ['methods' => 'POST', 'permission_callback' => [$hooks, 'verify'], 'callback' => function(\WP_REST_Request $r){
            $p = $r->get_json_params();
            if ( ! is_array($p) ) return new \WP_Error('madof_pdf', 'Empty body: the PDF may be larger than the server accepts (post_max_size).', ['status' => 413]);
            $pdf = base64_decode((string) ($p['base64'] ?? ''), true);
            unset($p['base64']);
            if ( ! $pdf ) return new \WP_Error('madof_pdf', 'Missing or invalid PDF data', ['status' => 400]);
            if ( function_exists('set_time_limit') ) @set_time_limit(150);
            if ( function_exists('wp_raise_memory_limit') ) wp_raise_memory_limit('admin');
            try {
                $res = $this->intake()->receive($pdf, ['source' => 'gmail', 'later' => true] + array_intersect_key($p, array_flip(['message_id', 'from', 'subject', 'filename', 'date'])));
            } catch ( \Throwable $e ) {
                return new \WP_Error('madof_expense_error', 'Error al recibir la factura: ' . $e->getMessage(), ['status' => 500]);
            }
            if ( is_wp_error($res) ) {
                $data = (array) $res->get_error_data();
                if ( empty($data['status']) ) $res->add_data(['status' => 422] + $data);
            }
            return is_wp_error($res) ? $res : rest_ensure_response($res);
        }]);
        // The kept PDF, for the cartero to archive it in the shared drive.
        register_rest_route($ns, '/hooks/expenses/(?P<id>\d+)/file', ['methods' => 'GET', 'permission_callback' => [$hooks, 'verify'], 'callback' => function(\WP_REST_Request $r){
            $e = \MAD_Oficina\Suppliers\Expenses::get((int) $r['id']);
            $pdf = $e ? \MAD_Oficina\Suppliers\Uploads::read($e['id']) : null;
            if ( $pdf === null ) return new \WP_Error('madof_not_found', 'No file', ['status' => 404]);
            return rest_ensure_response(['filename' => \MAD_Oficina\Suppliers\Expenses::filename($e), 'base64' => base64_encode($pdf)]);
        }]);
    }

    /* ==== Re-billing ==== */

    /** Lines for a client's draft: its re-billable expenses up to the end of the month. */
    public function rebill_lines(array $lines, array $profile, $month){
        if ( ! $profile['client_id'] ) return $lines;
        $current = \MAD_Oficina\Billing\Profiles::current_for_client($profile['client_id']);
        if ( $current && (int) $current['id'] !== (int) $profile['id'] ) return $lines; // only on its current legal name
        foreach ( \MAD_Oficina\Suppliers\Expenses::pending_rebill($profile['client_id'], $month . '-31') as $e ) {
            $lines[] = [
                'description' => sprintf(__('Repercutido: %1$s%2$s (%3$s)', 'mad-oficina'), $e['supplier_name'], $e['concept'] ? ' · ' . $e['concept'] : '', $e['issue_date'] ? date_i18n('j/m/Y', strtotime($e['issue_date'])) : ''),
                'quantity' => '1', 'unit_price' => number_format((float) $e['base'], 2, ',', ''), 'expense_id' => $e['id'],
            ];
        }
        return $lines;
    }

    /** Current legal name of every client with re-billable expenses waiting. */
    public function rebill_profiles(array $ids, $month){
        global $wpdb;
        $clients = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT client_id FROM ' . \MAD_Oficina\Suppliers\Expenses::table() . " WHERE client_id IS NOT NULL AND status = 'registered' AND issue_date <= %s", $month . '-31'));
        foreach ( $clients as $cid ) {
            if ( ! \MAD_Oficina\Suppliers\Expenses::pending_rebill((int) $cid, $month . '-31') ) continue;
            $p = \MAD_Oficina\Billing\Profiles::current_for_client((int) $cid);
            if ( $p ) $ids[] = $p['id'];
        }
        return $ids;
    }

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

    public function handle_supplier_save(){
        $this->guard('supplier_save');
        $id = absint($_POST['supplier_id'] ?? 0);
        $r = \MAD_Oficina\Suppliers\Suppliers::save(wp_unslash($_POST), $id);
        if ( is_wp_error($r) ) $this->back(['tab' => 'suppliers', 'supplier' => $id ?: 'new'], $r->get_error_message(), 'error');
        $this->back(['tab' => 'suppliers'], __('Proveedor guardado.', 'mad-oficina'));
    }

    /** «Guardar aparte» / «Descartar» / «Volver a leer» several at once. */
    public function handle_expense_bulk(){
        $this->guard('expense_bulk');
        $tab = sanitize_key($_POST['tab'] ?? 'review');
        $do = sanitize_key($_POST['do'] ?? '');
        if ( ! in_array($do, ['aside', 'discard', 'read'], true) ) $this->back(['tab' => $tab], __('Elige qué hacer.', 'mad-oficina'), 'error');
        $n = $this->intake()->bulk($do, (array) ($_POST['ids'] ?? []));
        $msg = ['aside' => _n('%d guardada aparte, en «No son mías».', '%d guardadas aparte, en «No son mías».', $n, 'mad-oficina'),
            'discard' => _n('%d descartada.', '%d descartadas.', $n, 'mad-oficina'),
            'read' => _n('%d vuelve a la cola de lectura.', '%d vuelven a la cola de lectura.', $n, 'mad-oficina')][$do];
        $this->back(['tab' => $tab], sprintf($msg, $n), $n ? 'success' : 'error');
    }

    /** «Leer ahora»: the waiting ones, in this request (about a minute at most). */
    public function handle_expense_read_now(){
        $this->guard('expense_read_now');
        if ( function_exists('set_time_limit') ) @set_time_limit(180);
        $id = absint($_POST['expense_id'] ?? 0);
        if ( $id ) { $this->intake()->process($id); $n = 1; }
        else $n = $this->intake()->process_queued(20, 60);
        global $wpdb;
        $left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . \MAD_Oficina\Suppliers\Expenses::table() . " WHERE status = 'queued'");
        $this->back($id ? ['expense' => $id] : ['tab' => 'reading'], sprintf(__('Leídas %1$d. Quedan %2$d en cola (se siguen leyendo solas).', 'mad-oficina'), $n, $left));
    }

    /** Duplicates joined into one (its invoices and emails follow). */
    public function handle_supplier_merge(){
        $this->guard('supplier_merge');
        $keep = absint($_POST['keep'] ?? 0);
        $n = 0; $err = null;
        foreach ( array_map('absint', (array) ($_POST['remove'] ?? [])) as $rm ) {
            $r = \MAD_Oficina\Suppliers\Suppliers::merge($keep, $rm);
            if ( is_wp_error($r) ) $err = $r->get_error_message(); else $n++;
        }
        if ( ! $n ) $this->back(['tab' => 'suppliers'], $err ?: __('Elige con cuál unirlo.', 'mad-oficina'), 'error');
        $s = \MAD_Oficina\Suppliers\Suppliers::get($keep);
        $this->back(['tab' => 'suppliers'], sprintf(_n('Unido: %2$s ahora es un solo proveedor (%1$d repetido menos).', 'Unido: %2$s ahora es un solo proveedor (%1$d repetidos menos).', $n, 'mad-oficina'), $n, $s['name']));
    }

    /** Corrections by hand (amounts, date, category, client to re-bill to). */
    public function handle_expense_save(){
        $this->guard('expense_save');
        $id = absint($_POST['expense_id'] ?? 0);
        $e = \MAD_Oficina\Suppliers\Expenses::get($id);
        if ( ! $e ) $this->back([], __('Ese gasto no existe.', 'mad-oficina'), 'error');
        $p = wp_unslash($_POST);
        $num = fn($k) => \MAD_Oficina\Billing\Money::parse((string) ($p[$k] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['issue_date'] ?? '') ? $p['issue_date'] : $e['issue_date'];
        $fields = [
            'issue_date' => $date, 'number' => mb_substr(sanitize_text_field($p['number'] ?? ''), 0, 80) ?: null,
            'supplier_id' => absint($p['supplier_id'] ?? 0) ?: null,
            'concept' => mb_substr(sanitize_text_field($p['concept'] ?? ''), 0, 255) ?: null,
            'base' => $num('base') ?? 0, 'vat' => $num('vat') ?? 0, 'irpf' => $num('irpf') ?? 0, 'total' => $num('total') ?? 0,
            'category' => isset(\MAD_Oficina\Suppliers\Suppliers::CATEGORIES[$p['category'] ?? '']) ? $p['category'] : 'other',
            'client_id' => absint($p['client_id'] ?? 0) ?: null,
            'notes' => sanitize_textarea_field($p['notes'] ?? '') ?: null,
        ];
        if ( $fields['supplier_id'] && ($s = \MAD_Oficina\Suppliers\Suppliers::get($fields['supplier_id'])) ) $fields['supplier_name'] = $s['name'];
        \MAD_Oficina\Suppliers\Expenses::update($id, $fields);
        $moved = $e['issue_date'] !== $date || ($e['supplier_name'] !== ($fields['supplier_name'] ?? $e['supplier_name'])) || (float) $e['total'] !== (float) $fields['total'];
        if ( $moved ) $this->intake()->queue_archive($id); // the file name and folder follow the data
        $this->back(['expense' => $id], __('Gasto guardado.', 'mad-oficina'));
    }

    /** «Es mía» / «No es mía». */
    public function handle_expense_mine(){
        $this->guard('expense_mine');
        $r = $this->intake()->reclassify(absint($_POST['expense_id'] ?? 0), ! empty($_POST['mine']));
        $this->back(['expense' => absint($_POST['expense_id'] ?? 0)], is_wp_error($r) ? $r->get_error_message() : ($r['message'] ?? __('Hecho.', 'mad-oficina')), is_wp_error($r) ? 'error' : 'success');
    }

    /** Invoices downloaded from a supplier's panel. */
    public function handle_expense_upload(){
        $this->guard('expense_upload');
        $files = $_FILES['pdfs'] ?? null;
        $done = []; $errors = [];
        if ( $files && is_array($files['tmp_name']) ) {
            foreach ( $files['tmp_name'] as $i => $tmp ) {
                if ( ! $tmp || ! is_uploaded_file($tmp) || $files['size'][$i] > 15 * MB_IN_BYTES ) continue;
                if ( function_exists('set_time_limit') ) @set_time_limit(150);
                $r = $this->intake()->receive(file_get_contents($tmp), ['source' => 'upload', 'filename' => $files['name'][$i]]);
                if ( is_wp_error($r) ) $errors[] = $files['name'][$i] . ': ' . $r->get_error_message();
                else $done[] = $r;
            }
        }
        if ( ! $done && ! $errors ) $this->back(['tab' => 'upload'], __('Elige uno o varios PDF.', 'mad-oficina'), 'error');
        $count = array_count_values(array_column($done, 'status'));
        $msg = sprintf(__('Leídas %1$d: %2$d registradas, %3$d por revisar, %4$d no son tuyas, %5$d descartadas.', 'mad-oficina'), count($done), $count['registered'] ?? 0, ($count['review'] ?? 0) + ($count['queued'] ?? 0), $count['not_mine'] ?? 0, $count['ignored'] ?? 0);
        if ( $errors ) $msg .= ' ' . implode(' · ', $errors);
        $this->back(['tab' => 'expenses'], $msg, $errors ? 'error' : 'success');
    }

    public function handle_suppliers_settings(){
        $this->guard('suppliers_settings');
        $p = wp_unslash($_POST);
        $s = $this->settings();
        $s['my_names'] = sanitize_textarea_field($p['my_names'] ?? '');
        $s['my_tax_ids'] = sanitize_text_field($p['my_tax_ids'] ?? '');
        $s['my_emails'] = sanitize_text_field($p['my_emails'] ?? '');
        $s['grace_days'] = max(1, min(30, (int) ($p['grace_days'] ?? 5)));
        $s['weekly'] = ! empty($p['weekly']);
        $s['max_pages'] = max(0, min(50, (int) ($p['max_pages'] ?? 5)));
        $s['paused'] = ! empty($p['paused']);
        $s['model'] = in_array($p['model'] ?? '', ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], true) ? $p['model'] : 'claude-opus-5-5';
        update_option(MAD_Oficina_Core::option_key($this->slug()), $s, false);
        $this->back(['tab' => 'settings'], __('Ajustes guardados.', 'mad-oficina'));
    }

    public function render_settings_page(){
        if ( ! current_user_can(MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'));
        $settings = $this->settings();
        $intake = $this->intake();
        $tab = sanitize_key($_GET['tab'] ?? 'expenses');
        $expense = isset($_GET['expense']) ? \MAD_Oficina\Suppliers\Expenses::get(absint($_GET['expense'])) : null;
        include __DIR__ . '/views/expenses.php';
    }
};
