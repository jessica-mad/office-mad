<?php
namespace MAD_Oficina\Suppliers;

use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Meetings\Orders;

if ( ! defined('ABSPATH') ) exit;

/**
 * What happens to every received PDF:
 *   1. same file seen before → nothing;
 *   2. the Contable reads it; not an invoice → ignored;
 *   3. only invoices addressed to Jessica count. Addressed to a client or to
 *      someone else → «no es mía», kept aside (she can recover it). No clear
 *      recipient → she is asked;
 *   4. known supplier → registered (internal, nothing goes out) and archived in
 *      «Recibidas/AAAA/Tn/»; unknown → she is asked whether it is a new supplier.
 */
final class Intake {

    const NEW_SUPPLIER = 'supplier_new';
    const REVIEW = 'expense_review';

    private $settings;

    public function __construct(array $settings){
        $this->settings = $settings;
    }

    /* ==== Who is «me» ==== */

    public function my_tax_ids(){
        $billing = get_option(\MAD_Oficina_Core::option_key('billing'), []);
        $ids = preg_split('/[\s,;]+/', (string) ($this->settings['my_tax_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $ids[] = $billing['issuer_tax_id'] ?? '';
        return array_values(array_filter(array_unique(array_map([Profiles::class, 'norm_tax_id'], $ids))));
    }

    public function my_names(){
        $billing = get_option(\MAD_Oficina_Core::option_key('billing'), []);
        $names = preg_split('/\R+/', (string) ($this->settings['my_names'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $names[] = $billing['issuer_name'] ?? '';
        $names[] = 'MAD Marketing & Ads';
        return array_values(array_filter(array_unique(array_map([Profiles::class, 'norm_name'], $names)), fn($n) => strlen($n) >= 3));
    }

    public function my_emails(){
        $emails = preg_split('/[\s,;]+/', (string) ($this->settings['my_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $emails[] = get_option('admin_email');
        return array_values(array_unique(array_filter(array_map('strtolower', $emails), 'is_email')));
    }

    /**
     * Who the invoice is addressed to.
     * @return array{0:string, 1:string} [mine|not_mine|unclear, reason]
     */
    public function recipient(array $x){
        $tax = $x['recipient_tax_id'];
        $name = Profiles::norm_name($x['recipient_name']);
        $client = $this->client_named($tax, $name);
        if ( $tax !== '' ) {
            if ( in_array($tax, $this->my_tax_ids(), true) ) return ['mine', ''];
            return ['not_mine', $client ? 'client:' . $client : 'other'];
        }
        if ( $name !== '' ) {
            foreach ( $this->my_names() as $mine ) {
                if ( $name === $mine || str_contains($name, $mine) || str_contains($mine, $name) ) return ['mine', ''];
            }
            return ['not_mine', $client ? 'client:' . $client : 'other'];
        }
        if ( $x['recipient_email'] && in_array($x['recipient_email'], $this->my_emails(), true) ) return ['mine', ''];
        return ['unclear', 'recipient'];
    }

    /** Legal or trade name of one of Jessica's clients matching the recipient, if any. */
    private function client_named($tax, $name){
        foreach ( Profiles::all() as $p ) {
            if ( $tax !== '' && $p['tax_id'] === $tax ) return $p['legal_name'];
            if ( $name !== '' && Profiles::norm_name($p['legal_name']) === $name ) return $p['legal_name'];
        }
        foreach ( Clients::all() as $c ) if ( $name !== '' && Profiles::norm_name($c['name']) === $name ) return $c['name'];
        return '';
    }

    /* ==== The pipeline ==== */

    /**
     * A PDF arrives (cartero or upload): kept privately, then read straight away.
     * If reading fails or times out it stays «queued» and the next tick retries.
     * @param string $pdf  raw bytes
     * @param array  $meta source (gmail|upload), message_id, from, subject, filename
     * @return array{expense_id:int, status:string, reason:?string}|\WP_Error
     */
    public function receive($pdf, array $meta){
        if ( strncmp((string) $pdf, '%PDF', 4) !== 0 ) return new \WP_Error('madof_pdf_type', __('El archivo no es un PDF.', 'mad-oficina'));
        $hash = hash('sha256', $pdf);
        if ( $seen = Expenses::by_hash($hash) ) return ['expense_id' => $seen['id'], 'status' => $seen['status'], 'reason' => 'seen'];
        $id = Expenses::insert([
            'status' => 'queued',
            'source' => in_array($meta['source'] ?? '', ['gmail', 'upload'], true) ? $meta['source'] : 'gmail',
            'gmail_message_id' => isset($meta['message_id']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $meta['message_id']) : null,
            'sender' => self::clean_sender($meta['from'] ?? '') ?: null,
            'subject' => mb_substr(sanitize_text_field($meta['subject'] ?? ''), 0, 255) ?: null,
            'filename' => mb_substr(sanitize_file_name($meta['filename'] ?? 'factura.pdf'), 0, 255),
            'file_hash' => $hash,
        ]);
        if ( ! $id ) return new \WP_Error('madof_expense', __('No he podido guardar la factura.', 'mad-oficina'));
        Uploads::store($id, $pdf);
        return $this->process($id);
    }

    /** Invoices still waiting to be read (a slow answer or a Claude hiccup). */
    public function process_queued($limit = 3){
        global $wpdb;
        $n = 0;
        foreach ( $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Expenses::table() . " WHERE status = 'queued' ORDER BY id LIMIT %d", $limit)) as $id ) {
            $this->process((int) $id);
            $n++;
        }
        return $n;
    }

    /** @return array{expense_id:int, status:string, reason:?string} */
    public function process($id){
        $e = Expenses::get($id);
        if ( ! $e || $e['status'] !== 'queued' ) return ['expense_id' => (int) $id, 'status' => $e['status'] ?? 'gone', 'reason' => null];
        $pdf = Uploads::read($id);
        if ( $pdf === null ) {
            Expenses::update($id, ['status' => 'ignored', 'reason' => 'no_file']);
            return ['expense_id' => (int) $id, 'status' => 'ignored', 'reason' => 'no_file'];
        }
        $x = (new Reader($this->settings['model'] ?? ''))->read($pdf, ['from' => $e['sender'], 'subject' => $e['subject'], 'filename' => $e['filename']]);
        if ( is_wp_error($x) ) {
            $tries = (int) str_replace('try:', '', (string) $e['reason']) + 1;
            Expenses::update($id, $tries >= 3
                ? ['status' => 'review', 'reason' => 'unreadable', 'notes' => sprintf(__('No he podido leerla: %s Ábrela y completa los datos a mano.', 'mad-oficina'), $x->get_error_message())]
                : ['reason' => 'try:' . $tries]);
            return ['expense_id' => (int) $id, 'status' => $tries >= 3 ? 'review' : 'queued', 'reason' => 'unreadable'];
        }
        return $this->decide($id, $x);
    }

    /** Steps 2–4 with what the Contable read. */
    private function decide($id, array $x){
        $e = Expenses::get($id);
        $from = self::address($e['sender'] ?? '');
        $row = [
            'issue_date' => $x['issue_date'] ?: null, 'number' => $x['number'] ?: null,
            'supplier_name' => $x['supplier_name'] ?: null, 'supplier_tax_id' => $x['supplier_tax_id'] ?: null,
            'recipient_name' => $x['recipient_name'] ?: null, 'recipient_tax_id' => $x['recipient_tax_id'] ?: null,
            'concept' => $x['concept'] ?: null, 'base' => $x['base'], 'vat' => $x['vat'], 'irpf' => $x['irpf'], 'total' => $x['total'],
            'currency' => $x['currency'], 'category' => $x['category'], 'extraction' => wp_json_encode($x), 'reason' => null,
        ];
        $done = function($status, $reason = null, array $more = []) use ($id, $row){
            Expenses::update($id, ['status' => $status, 'reason' => $reason ? mb_substr($reason, 0, 40) : null] + $more + $row);
            if ( $status === 'ignored' ) Uploads::delete($id);
            if ( in_array($status, ['registered', 'not_mine'], true) ) $this->queue_archive($id);
            if ( $status === 'registered' ) do_action('madoficina_expense_registered', $id);
            return ['expense_id' => (int) $id, 'status' => $status, 'reason' => $reason];
        };

        if ( ! $x['is_invoice'] ) return $done('ignored', 'not_invoice');

        [$who, $why] = $this->recipient($x);
        if ( $who === 'not_mine' ) return $done('not_mine', $why);

        $supplier = Suppliers::match($x['supplier_tax_id'], $from, $x['supplier_name']);
        if ( $supplier && ($dup = Expenses::duplicate_of($x, $supplier['id'])) ) {
            return $done('ignored', 'duplicate', ['supplier_id' => $supplier['id'], 'notes' => sprintf(__('Repetida: ya estaba registrada (gasto #%d).', 'mad-oficina'), $dup)]);
        }

        if ( $who === 'unclear' ) {
            $r = $done('review', 'recipient', ['supplier_id' => $supplier['id'] ?? null]);
            Approvals::create(Reader::AGENT, self::REVIEW, sprintf(__('¿Es tuya esta factura de %1$s (%2$s)?', 'mad-oficina'), $x['supplier_name'] ?: $from, \MAD_Oficina\Billing\Money::format($x['total'])), ['expense_id' => (int) $id],
                ['object_type' => 'expense', 'object_id' => $id, 'summary' => __('No dice a nombre de quién está. Si es tuya, la registro como gasto; si no, la guardo aparte.', 'mad-oficina')]);
            return $r;
        }

        if ( ! $supplier ) {
            $r = $done('review', 'supplier');
            Approvals::create(Reader::AGENT, self::NEW_SUPPLIER, sprintf(__('¿Proveedor nuevo? Factura de %1$s por %2$s', 'mad-oficina'), $x['supplier_name'] ?: $from, \MAD_Oficina\Billing\Money::format($x['total'])), ['expense_id' => (int) $id],
                ['object_type' => 'expense', 'object_id' => $id, 'summary' => trim(($x['concept'] ? $x['concept'] . ' · ' : '') . sprintf(__('Llegó de %s.', 'mad-oficina'), $from ?: __('una subida manual', 'mad-oficina')))]);
            return $r;
        }

        if ( $from ) Suppliers::add_email($supplier['id'], $from);
        $f = $this->register_fields($supplier, $x);
        return $done('registered', $f['reason'], $f);
    }

    private function register_fields(array $supplier, array $x){
        $notes = null;
        if ( $supplier['usual_amount'] && $x['total'] > $supplier['usual_amount'] * 1.1 ) {
            $notes = sprintf(__('Ojo: %1$s es más de lo habitual (%2$s).', 'mad-oficina'), \MAD_Oficina\Billing\Money::format($x['total']), \MAD_Oficina\Billing\Money::format($supplier['usual_amount']));
        }
        return ['status' => 'registered', 'reason' => $notes ? 'price_up' : null, 'notes' => $notes, 'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'], 'category' => $supplier['category'], 'client_id' => $supplier['client_id']];
    }

    /** «Name <a@b.c>» kept readable and safe (a text filter would drop the <address> as a tag). */
    public static function clean_sender($from){
        $email = self::address($from);
        $name = trim(sanitize_text_field(preg_replace('/<[^>]*>/', '', (string) $from)), " \t\"'");
        if ( $email && strtolower($name) === $email ) $name = '';
        return mb_substr($email ? trim($name . ' <' . $email . '>') : $name, 0, 255);
    }

    /** «Name <a@b.c>» → «a@b.c». */
    public static function address($from){
        if ( preg_match('/<([^>]+)>/', (string) $from, $m) ) $from = $m[1];
        $from = strtolower(trim((string) $from));
        return is_email($from) ? $from : '';
    }

    /* ==== Archive in the shared drive (the cartero) ==== */

    public function queue_archive($id){
        global $wpdb;
        $e = Expenses::get($id);
        if ( ! $e || ! in_array($e['status'], ['registered', 'not_mine'], true) ) return 0;
        // The latest decision wins: an older order for this file that has not run yet is dropped.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . \MAD_Oficina\Core\Schema::table('tasks') . " WHERE agent = %s AND status = 'queued' AND type IN ('expense_archive','expense_move') AND payload LIKE %s",
            Orders::AGENT, $wpdb->esc_like('{"expense_id":' . (int) $id . ',') . '%'));
        $payload = ['expense_id' => (int) $id, 'folder' => Expenses::folder($e), 'name' => Expenses::filename($e)];
        if ( $e['drive_file_id'] ) return Orders::queue('expense_move', $payload + ['file_id' => $e['drive_file_id']]);
        return Orders::queue('expense_archive', $payload);
    }

    public static function order_done($type, array $payload, $ok, array $result){
        if ( ! $ok || ! in_array($type, ['expense_archive', 'expense_move'], true) ) return;
        $fields = [];
        if ( ! empty($result['file_id']) ) $fields['drive_file_id'] = preg_replace('/[^A-Za-z0-9_\-]/', '', $result['file_id']);
        if ( ! empty($result['url']) && preg_match('#^https://(drive|docs)\.google\.com/#', $result['url']) ) $fields['drive_url'] = esc_url_raw($result['url']);
        if ( $fields ) Expenses::update((int) $payload['expense_id'], $fields);
        if ( $type === 'expense_archive' && ! empty($fields['drive_file_id']) ) Uploads::delete((int) $payload['expense_id']); // safe in Drive now
    }

    /* ==== Jessica's answers ==== */

    /** «Es mía» / «No es mía» (also from the lists, to recover one kept aside). @return array|\WP_Error */
    public function reclassify($id, $mine){
        $e = Expenses::get($id);
        if ( ! $e ) return new \WP_Error('madof_expense', __('Ese gasto no existe.', 'mad-oficina'));
        // A pending «¿es tuya?» card is answered the same way, so it does it once.
        foreach ( Approvals::for_object('expense', $id) as $a ) {
            if ( $a['status'] === Approvals::PENDING && $a['action_type'] === self::REVIEW ) {
                $r = Approvals::resolve($a['id'], $mine ? 'approve' : 'reject', [], get_current_user_id());
                return $mine ? $r : ['message' => __('Guardada aparte, en «No son mías». No cuenta como gasto.', 'mad-oficina')];
            }
        }
        if ( ! $mine ) {
            Expenses::update($id, ['status' => 'not_mine', 'reason' => 'jessica']);
            $this->queue_archive($id);
            return ['message' => __('Guardada aparte, en «No son mías». No cuenta como gasto.', 'mad-oficina')];
        }
        return $this->reclassify_without_approvals($id, true);
    }

    /** Inbox: «¿Es tuya?» — approve = mine. */
    public function approve_review(array $approval, array $input){
        return $this->reclassify_without_approvals((int) $approval['payload']['expense_id'], true);
    }

    public function reject_review(array $approval){
        $id = (int) $approval['payload']['expense_id'];
        $e = Expenses::get($id);
        if ( $e && $e['status'] === 'review' ) {
            Expenses::update($id, ['status' => 'not_mine', 'reason' => 'jessica']);
            $this->queue_archive($id);
        }
    }

    private function reclassify_without_approvals($id, $mine){
        $e = Expenses::get($id);
        if ( ! $e ) return new \WP_Error('madof_expense', __('Ese gasto ya no existe.', 'mad-oficina'));
        $supplier = $e['supplier_id'] ? Suppliers::get($e['supplier_id']) : Suppliers::match((string) $e['supplier_tax_id'], self::address($e['sender']), (string) $e['supplier_name']);
        if ( $supplier ) {
            Expenses::update($id, $this->register_fields($supplier, $e + ['total' => (float) $e['total']]));
            $this->queue_archive($id);
            do_action('madoficina_expense_registered', $id);
            return ['message' => sprintf(__('Registrada como gasto de %s.', 'mad-oficina'), $supplier['name'])];
        }
        Expenses::update($id, ['status' => 'review', 'reason' => 'supplier']);
        Approvals::create(Reader::AGENT, self::NEW_SUPPLIER, sprintf(__('¿Proveedor nuevo? Factura de %1$s por %2$s', 'mad-oficina'), $e['supplier_name'] ?: $e['sender'], \MAD_Oficina\Billing\Money::format((float) $e['total'])), ['expense_id' => $id], ['object_type' => 'expense', 'object_id' => $id]);
        return ['message' => __('Es tuya. El proveedor es nuevo: te he dejado otra tarjeta para darlo de alta.', 'mad-oficina')];
    }

    /** Defaults of the «new supplier» card (also what Chat's «Aprobar tal cual» uses). */
    public function new_supplier_defaults(array $approval){
        $e = Expenses::get((int) ($approval['payload']['expense_id'] ?? 0));
        if ( ! $e ) return [];
        $x = $e['extraction'];
        return [
            'mode' => 'new', 'name' => $e['supplier_name'] ?: '', 'tax_id' => (string) $e['supplier_tax_id'],
            'emails' => self::address($e['sender']), 'category' => $x['category'] ?? 'other', 'periodicity' => $x['periodicity'] ?? 'irregular',
            'expected_day' => $e['issue_date'] ? (int) substr($e['issue_date'], 8, 2) : '', 'usual_amount' => $e['total'] ? number_format((float) $e['total'], 2, ',', '') : '',
            'service' => $e['concept'] ?: '', 'client_id' => '', 'delivery' => 'email', 'existing' => '',
        ];
    }

    /** Inbox: new supplier approved → supplier created (or joined to an existing one), invoice registered. */
    public function approve_new_supplier(array $approval, array $input){
        $id = (int) ($approval['payload']['expense_id'] ?? 0);
        $e = Expenses::get($id);
        if ( ! $e ) return new \WP_Error('madof_expense', __('Esa factura ya no existe.', 'mad-oficina'));
        $input += $this->new_supplier_defaults($approval);
        if ( ($input['mode'] ?? 'new') === 'existing' && ! empty($input['existing']) ) {
            $supplier = Suppliers::get((int) $input['existing']);
            if ( ! $supplier ) return new \WP_Error('madof_supplier', __('Ese proveedor no existe.', 'mad-oficina'));
            if ( $from = self::address($e['sender']) ) Suppliers::add_email($supplier['id'], $from);
        } else {
            $sid = Suppliers::save($input + ['expected_month' => $e['issue_date'] && ($input['periodicity'] ?? '') === 'yearly' ? (int) substr($e['issue_date'], 5, 2) : null]);
            if ( is_wp_error($sid) ) return $sid;
            $supplier = Suppliers::get($sid);
        }
        Expenses::update($id, $this->register_fields($supplier, $e + ['total' => (float) $e['total']]));
        $this->queue_archive($id);
        do_action('madoficina_expense_registered', $id);
        $msg = sprintf(__('%s dado de alta y su factura registrada.', 'mad-oficina'), $supplier['name']);
        if ( ! empty($input['filter']) && $supplier['emails'] ) {
            Orders::queue('gmail_filter', ['from' => $supplier['emails'], 'label' => 'Facturas']);
            $msg .= ' ' . __('El cartero le pondrá la etiqueta «Facturas» en Gmail.', 'mad-oficina');
        }
        return ['message' => $msg];
    }

    public function reject_new_supplier(array $approval){
        // «Descartar»: not a supplier invoice after all (kept aside, recoverable).
        $id = (int) ($approval['payload']['expense_id'] ?? 0);
        $e = Expenses::get($id);
        if ( $e && $e['status'] === 'review' ) Expenses::update($id, ['status' => 'not_mine', 'reason' => 'jessica']);
        if ( $e ) $this->queue_archive($id);
    }

    /* ==== Inbox forms ==== */

    public function render_review_form($html, array $approval){
        $e = Expenses::get((int) ($approval['payload']['expense_id'] ?? 0));
        if ( ! $e ) return '<p class="madof-warn">' . esc_html__('Esa factura ya no existe.', 'mad-oficina') . '</p>';
        return $this->facts($e) . '<p class="description">' . esc_html__('«Aprobar» = es mía (se registra como gasto). «Descartar» = no es mía (se guarda aparte, sin contar).', 'mad-oficina') . '</p>';
    }

    public function render_new_supplier_form($html, array $approval){
        $e = Expenses::get((int) ($approval['payload']['expense_id'] ?? 0));
        if ( ! $e ) return '<p class="madof-warn">' . esc_html__('Esa factura ya no existe.', 'mad-oficina') . '</p>';
        $d = $this->new_supplier_defaults($approval);
        $sel = fn($name, $opts, $cur) => '<select name="input[' . $name . ']">' . implode('', array_map(fn($k) => '<option value="' . esc_attr($k) . '" ' . selected($cur, $k, false) . '>' . esc_html__($opts[$k], 'mad-oficina') . '</option>', array_keys($opts))) . '</select>';
        ob_start(); ?>
        <?php echo $this->facts($e); ?>
        <label class="madof-check"><input type="radio" name="input[mode]" value="new" checked> <?php esc_html_e('Es un proveedor nuevo', 'mad-oficina'); ?></label>
        <div class="madof-cols">
            <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="input[name]" value="<?php echo esc_attr($d['name']); ?>"></label>
            <label><?php esc_html_e('NIF', 'mad-oficina'); ?><input type="text" name="input[tax_id]" value="<?php echo esc_attr($d['tax_id']); ?>"></label>
            <label><?php esc_html_e('Emails desde los que factura', 'mad-oficina'); ?><input type="text" name="input[emails]" value="<?php echo esc_attr($d['emails']); ?>"></label>
        </div>
        <div class="madof-cols">
            <label><?php esc_html_e('Categoría', 'mad-oficina'); ?><?php echo $sel('category', Suppliers::CATEGORIES, $d['category']); ?></label>
            <label><?php esc_html_e('Periodicidad', 'mad-oficina'); ?><?php echo $sel('periodicity', Suppliers::PERIODICITIES, $d['periodicity']); ?></label>
            <label><?php esc_html_e('Día esperado', 'mad-oficina'); ?><input type="number" min="1" max="31" name="input[expected_day]" value="<?php echo esc_attr((string) $d['expected_day']); ?>"></label>
            <label><?php esc_html_e('Importe habitual', 'mad-oficina'); ?><input type="text" name="input[usual_amount]" value="<?php echo esc_attr($d['usual_amount']); ?>"></label>
        </div>
        <div class="madof-cols">
            <label><?php esc_html_e('¿Es para un cliente? (se le repercute)', 'mad-oficina'); ?><select name="input[client_id]"><option value=""><?php esc_html_e('No, es de MAD', 'mad-oficina'); ?></option><?php foreach ( Clients::all() as $c ) echo '<option value="' . (int) $c['id'] . '">' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select></label>
            <label><?php esc_html_e('Cómo llega la factura', 'mad-oficina'); ?><?php echo $sel('delivery', Suppliers::DELIVERIES, 'email'); ?></label>
        </div>
        <label class="madof-check"><input type="checkbox" name="input[filter]" value="1" checked> <?php esc_html_e('Crear en Gmail el filtro que pone la etiqueta «Facturas» a sus correos', 'mad-oficina'); ?></label>
        <?php $others = Suppliers::all(); if ( $others ) : ?>
            <label class="madof-check"><input type="radio" name="input[mode]" value="existing"> <?php esc_html_e('…o es de un proveedor que ya tengo:', 'mad-oficina'); ?>
                <select name="input[existing]"><option value=""></option><?php foreach ( $others as $s ) echo '<option value="' . (int) $s['id'] . '">' . esc_html($s['name']) . '</option>'; ?></select></label>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('«Descartar» = no es una factura tuya de proveedor: la guardo aparte, sin contar.', 'mad-oficina'); ?></p>
        <?php
        return ob_get_clean();
    }

    private function facts(array $e){
        $view = admin_url('admin.php?page=mad-oficina-gastos&expense=' . $e['id']);
        return '<p>' . esc_html(implode(' · ', array_filter([
            $e['supplier_name'], $e['supplier_tax_id'], $e['number'] ? sprintf(__('nº %s', 'mad-oficina'), $e['number']) : '',
            $e['issue_date'] ? date_i18n('j M Y', strtotime($e['issue_date'])) : '', \MAD_Oficina\Billing\Money::format((float) $e['total']) . ($e['currency'] !== 'EUR' ? ' ' . $e['currency'] : ''),
        ]))) . '<br><small>' . esc_html(sprintf(__('A nombre de: %s', 'mad-oficina'), trim(($e['recipient_name'] ?: '—') . ' ' . ($e['recipient_tax_id'] ?: '')))) . ' · ' . esc_html(sprintf(__('De: %s', 'mad-oficina'), $e['sender'] ?: __('subida manual', 'mad-oficina'))) . '</small>'
            . ' · <a href="' . esc_url($view) . '">' . esc_html__('Ver el gasto', 'mad-oficina') . '</a></p>';
    }
}
