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
 *      «Facturas para MAD/AAAA/AAAA-Tn/»; unknown → she is asked whether it is a new supplier.
 * Payment proofs are joined to the invoice they pay; payments to Hacienda or the
 * Seguridad Social go to Impuestos. Files can also come from her Drive archive.
 */
final class Intake {

    const NEW_SUPPLIER = 'supplier_new';
    const READ_HOOK = 'madoficina_expense_read';
    const MAX_TRIES = 3;
    const REVIEW = 'expense_review';
    const SOURCES = ['gmail', 'upload', 'drive'];
    /** Drive files that turned out to be copies of one already known (so they are not sent again). */
    const SEEN_OPTION = 'madoficina_drive_seen';

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
     * A document arrives (cartero, upload or Drive archive): kept privately, then read.
     * If reading fails or times out it stays «queued» and the next tick retries.
     * @param string $pdf  raw bytes (PDF or image)
     * @param array  $meta source (gmail|upload|drive), message_id, from, subject, filename, date;
     *                     from Drive: file_id, folder (path, a hint), in_archive (already in the
     *                     archive: it is moved and renamed there, not copied)
     * @return array{expense_id:int, status:string, reason:?string}|\WP_Error
     */
    public function receive($pdf, array $meta){
        if ( ! Reader::media_type($pdf) ) return new \WP_Error('madof_pdf_type', __('El archivo no es un PDF ni una imagen.', 'mad-oficina'), ['status' => 422]);
        $source = in_array($meta['source'] ?? '', self::SOURCES, true) ? $meta['source'] : 'gmail';
        $file_id = $source === 'drive' ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($meta['file_id'] ?? '')) : '';
        $in_archive = $file_id && ! empty($meta['in_archive']);
        $hash = hash('sha256', $pdf);
        if ( $seen = Expenses::by_hash($hash) ) return $this->seen($seen, $file_id, $in_archive);
        $folder = mb_substr(sanitize_text_field((string) ($meta['folder'] ?? '')), 0, 255);
        $id = Expenses::insert([
            'status' => 'queued',
            'source' => $source,
            'source_ref' => $file_id ? 'drive:' . $file_id : null,
            'drive_file_id' => $in_archive ? $file_id : null,
            'gmail_message_id' => isset($meta['message_id']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $meta['message_id']) : null,
            'received_at' => ($source === 'drive' ? self::folder_end($folder) : null) ?: self::day($meta['date'] ?? '') ?: current_time('Y-m-d'),
            'sender' => self::clean_sender($meta['from'] ?? '') ?: null,
            // From Drive the «subject» is the folder it was in (shown in the lists, and a hint for the date).
            'subject' => ($source === 'drive' ? $folder : mb_substr(sanitize_text_field($meta['subject'] ?? ''), 0, 255)) ?: null,
            'filename' => mb_substr(sanitize_file_name($meta['filename'] ?? 'factura.pdf'), 0, 255),
            'file_hash' => $hash,
        ]);
        if ( ! $id ) return new \WP_Error('madof_expense', __('No he podido guardar la factura.', 'mad-oficina'));
        Uploads::store($id, $pdf);
        if ( ! empty($meta['later']) ) {
            // From the cartero: answered at once, read in the background (a slow reading
            // must never be cut together with the cartero's request).
            self::schedule($id);
            return ['expense_id' => (int) $id, 'status' => 'queued', 'reason' => null];
        }
        return $this->process($id);
    }

    /**
     * The same file again. From the Drive archive: if the known one has no file in the
     * archive yet, this one becomes its file (moved and renamed into place); if it has
     * one, this copy is a duplicate (the cartero puts it in «Duplicados», never deletes).
     */
    private function seen(array $seen, $file_id, $in_archive){
        $out = ['expense_id' => (int) $seen['id'], 'status' => $seen['status'], 'reason' => 'seen'];
        if ( ! $file_id ) return $out;
        if ( ! $in_archive ) {
            $known = get_option(self::SEEN_OPTION, []);
            $known = is_array($known) ? $known : [];
            if ( ! in_array('drive:' . $file_id, $known, true) ) { $known[] = 'drive:' . $file_id; update_option(self::SEEN_OPTION, array_slice($known, -5000), false); }
            return $out;
        }
        if ( $seen['drive_file_id'] === $file_id ) return $out;
        if ( ! $seen['drive_file_id'] ) {
            Expenses::update($seen['id'], ['drive_file_id' => $file_id, 'source_ref' => $seen['source_ref'] ?: 'drive:' . $file_id]);
            $this->queue_archive($seen['id']);
            return ['reason' => 'adopted'] + $out;
        }
        return ['reason' => 'duplicate', 'duplicate' => true] + $out;
    }

    /** «Facturas Compras IMAD/2025/T1» → «2025-03-31» (last day of the quarter or month named in the path). */
    public static function folder_end($path){
        if ( ! preg_match('/\b(20\d\d)\b/', (string) $path, $y) ) return null;
        $parts = explode('/', (string) $path);
        $last = end($parts);
        if ( preg_match('/\bT\s*([1-4])\b/i', $last, $q) || preg_match('/-T([1-4])\b/i', $last, $q) ) return gmdate('Y-m-t', strtotime(sprintf('%s-%02d-01', $y[1], $q[1] * 3)));
        return $y[1] . '-12-31';
    }

    public static function schedule($id){
        if ( function_exists('as_enqueue_async_action') ) as_enqueue_async_action(self::READ_HOOK, [(int) $id], 'mad-oficina');
        else wp_schedule_single_event(time(), self::READ_HOOK, [(int) $id]);
    }

    /** Invoices still waiting to be read (a slow answer or a Claude hiccup). */
    public function process_queued($limit = 10, $seconds = 45){
        global $wpdb;
        $n = 0; $start = time();
        foreach ( $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Expenses::table() . " WHERE status = 'queued' ORDER BY id LIMIT %d", $limit)) as $id ) {
            if ( time() - $start > $seconds ) break; // the next tick carries on
            $this->process((int) $id);
            $n++;
        }
        return $n;
    }

    /** @return array{expense_id:int, status:string, reason:?string} */
    public function process($id){
        $e = Expenses::get($id);
        if ( ! $e || $e['status'] !== 'queued' ) return ['expense_id' => (int) $id, 'status' => $e['status'] ?? 'gone', 'reason' => null];
        // «Pausar la lectura» (Gastos → Ajustes): PDFs keep arriving and wait, nothing is spent.
        if ( ! empty($this->settings['paused']) ) return ['expense_id' => (int) $id, 'status' => 'queued', 'reason' => 'paused'];
        $pdf = Uploads::read($id);
        if ( $pdf === null ) {
            Expenses::update($id, ['status' => 'ignored', 'reason' => 'no_file']);
            return ['expense_id' => (int) $id, 'status' => 'ignored', 'reason' => 'no_file'];
        }
        // Long documents (contracts, dossiers…) are rarely invoices and cost a lot to read:
        // they wait for Jessica unless she asked to read them anyway.
        $max = (int) ($this->settings['max_pages'] ?? 5);
        $pages = Reader::pages($pdf);
        if ( $e['reason'] !== 'force' && $max > 0 && $pages > $max ) {
            Expenses::update($id, ['status' => 'review', 'reason' => 'long', 'notes' => sprintf(__('Documento de %d páginas: no lo leo solo para no gastar. Si es una factura, márcalo y pulsa «Volver a leer».', 'mad-oficina'), $pages)]);
            return ['expense_id' => (int) $id, 'status' => 'review', 'reason' => 'long'];
        }
        if ( $e['reason'] === 'force' ) $e['reason'] = null;
        // The attempt is counted (and noted) before reading: if the server cuts a slow request,
        // the next attempt knows it, and after MAX_TRIES Jessica completes it by hand.
        $tries = (int) str_replace('try:', '', (string) $e['reason']) + 1;
        $log = trim((string) $e['notes']);
        if ( $tries > 1 && str_ends_with($log, '…') ) {
            $log = preg_replace('/…$/u', ': ' . __('se cortó (tardó demasiado)', 'mad-oficina'), $log);
        }
        if ( $tries > self::MAX_TRIES ) {
            Expenses::update($id, ['status' => 'review', 'reason' => 'unreadable', 'notes' => trim($log . "\n" . __('No he podido leerla. Ábrela y completa los datos a mano, o descártala.', 'mad-oficina'))]);
            return ['expense_id' => (int) $id, 'status' => 'review', 'reason' => 'unreadable'];
        }
        $log = trim($log . "\n" . sprintf(__('Intento %d', 'mad-oficina'), $tries) . '…');
        Expenses::update($id, ['reason' => 'try:' . $tries, 'notes' => $log]);
        if ( function_exists('set_time_limit') ) @set_time_limit(150);
        $drive = $e['source'] === 'drive';
        $x = (new Reader($this->settings['model'] ?? ''))->read($pdf, ['from' => $e['sender'], 'subject' => $drive ? '' : $e['subject'], 'filename' => $e['filename'], 'received' => $drive ? '' : $e['received_at'], 'folder' => $drive ? $e['subject'] : '']);
        if ( is_wp_error($x) ) {
            $log = preg_replace('/…$/u', ': ' . $x->get_error_message(), $log);
            Expenses::update($id, $tries >= self::MAX_TRIES
                ? ['status' => 'review', 'reason' => 'unreadable', 'notes' => trim($log . "\n" . __('No he podido leerla. Ábrela y completa los datos a mano, o descártala.', 'mad-oficina'))]
                : ['notes' => $log]);
            if ( $tries < self::MAX_TRIES ) self::schedule($id);
            return ['expense_id' => (int) $id, 'status' => $tries >= self::MAX_TRIES ? 'review' : 'queued', 'reason' => 'unreadable'];
        }
        Expenses::update($id, ['notes' => null]); // the reading log is no longer needed
        return $this->decide($id, $x);
    }

    /** «2025-10-14T09:12:00.000Z» → «2025-10-14» (local day). */
    private static function day($iso){
        if ( ! $iso ) return null;
        try { return (new \DateTimeImmutable((string) $iso))->setTimezone(wp_timezone())->format('Y-m-d'); } catch ( \Exception $e ) { return null; }
    }

    /**
     * An invoice cannot be dated after the email that brings it, nor years before.
     * A wrong year (the usual reading slip) is corrected with the email's date.
     * @return array{0:?string, 1:?string} [date, note]
     */
    public static function check_date($date, $received){
        if ( ! $received ) return [$date ?: null, null];
        if ( ! $date ) return [$received, __('Sin fecha legible en la factura: uso la del email.', 'mad-oficina')];
        $ok = fn($d) => $d <= gmdate('Y-m-d', strtotime($received . ' +7 days')) && $d >= gmdate('Y-m-d', strtotime($received . ' -400 days'));
        if ( $ok($date) ) return [$date, null];
        $md = substr($date, 4);
        foreach ( [(int) substr($received, 0, 4), (int) substr($received, 0, 4) - 1] as $y ) {
            $try = $y . $md;
            if ( checkdate((int) substr($try, 5, 2), (int) substr($try, 8, 2), $y) && $ok($try) ) {
                return [$try, sprintf(__('Fecha corregida: la factura se leyó como %1$s, pero llegó el %2$s.', 'mad-oficina'), date_i18n('j/m/Y', strtotime($date)), date_i18n('j/m/Y', strtotime($received)))];
            }
        }
        return [$date, sprintf(__('Ojo con la fecha: %1$s, y el email llegó el %2$s.', 'mad-oficina'), date_i18n('j/m/Y', strtotime($date)), date_i18n('j/m/Y', strtotime($received)))];
    }

    /** Steps 2–4 with what the Contable read. */
    private function decide($id, array $x){
        $e = Expenses::get($id);
        // An upload can be years old: only an email's (or the archive folder's) date says something.
        [$x['issue_date'], $date_note] = self::check_date($x['issue_date'], $e['source'] === 'upload' && $x['issue_date'] ? null : $e['received_at']);
        $from = self::address($e['sender'] ?? '');
        $row = [
            'issue_date' => $x['issue_date'] ?: null, 'number' => $x['number'] ?: null,
            'supplier_name' => $x['supplier_name'] ?: null, 'supplier_tax_id' => $x['supplier_tax_id'] ?: null,
            'recipient_name' => $x['recipient_name'] ?: null, 'recipient_tax_id' => $x['recipient_tax_id'] ?: null,
            'concept' => $x['concept'] ?: null, 'base' => $x['base'], 'vat' => $x['vat'], 'irpf' => $x['irpf'], 'total' => $x['total'],
            'currency' => $x['currency'], 'category' => $x['category'], 'extraction' => wp_json_encode($x), 'reason' => null,
        ];
        if ( $date_note ) $row['notes'] = $date_note;
        $done = function($status, $reason = null, array $more = []) use ($id, $row){
            Expenses::update($id, ['status' => $status, 'reason' => $reason ? mb_substr($reason, 0, 40) : null] + $more + $row);
            if ( $status === 'ignored' ) {
                Uploads::delete($id);
                if ( $reason === 'duplicate' && Expenses::get($id)['drive_file_id'] ) $this->queue_archive($id); // a copy already in the archive → «Duplicados»
            }
            if ( in_array($status, ['registered', 'not_mine'], true) ) $this->queue_archive($id);
            if ( $status === 'registered' ) do_action('madoficina_expense_registered', $id);
            return ['expense_id' => (int) $id, 'status' => $status, 'reason' => $reason];
        };

        if ( ($x['doc_type'] ?? '') === 'payment' ) return $this->payment($id, $x, $row);
        if ( ($x['doc_type'] ?? '') === 'tax' ) return $this->tax($id, $x, $row);
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
        if ( $supplier['usual_amount'] && ($x['currency'] ?? 'EUR') === $supplier['currency'] && $x['total'] > $supplier['usual_amount'] * 1.1 ) {
            $notes = sprintf(__('Ojo: %1$s es más de lo habitual (%2$s).', 'mad-oficina'), Suppliers::money($x['total'], $supplier['currency']), Suppliers::money($supplier['usual_amount'], $supplier['currency']));
        }
        return ['status' => 'registered', 'reason' => $notes ? 'price_up' : null, 'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'], 'category' => $supplier['category'], 'client_id' => $supplier['client_id']]
            + ( $notes ? ['notes' => $notes] : [] ); // (no key: a note about the date is kept)
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

    /** The archive folder in the shared drive (Gastos → Ajustes); empty = the cartero's «Recibidas». */
    public function archive_root(){
        return preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($this->settings['archive_folder'] ?? ''));
    }

    public function queue_archive($id){
        global $wpdb;
        $e = Expenses::get($id);
        if ( ! $e ) return 0;
        $ok = in_array($e['status'], ['registered', 'not_mine', 'tax'], true)
            || ( $e['status'] === 'payment' && $e['payment_for'] )
            || ( $e['status'] === 'ignored' && $e['reason'] === 'duplicate' && $e['drive_file_id'] ); // only a copy already in the archive
        if ( ! $ok ) return 0;
        // The latest decision wins: an older order for this file that has not run yet is dropped.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . \MAD_Oficina\Core\Schema::table('tasks') . " WHERE agent = %s AND status = 'queued' AND type IN ('expense_archive','expense_move') AND payload LIKE %s",
            Orders::AGENT, $wpdb->esc_like('{"expense_id":' . (int) $id . ',') . '%'));
        $payload = ['expense_id' => (int) $id, 'folder' => Expenses::folder($e), 'name' => Expenses::filename($e), 'root' => $this->archive_root()];
        if ( $e['status'] === 'ignored' ) $payload['name'] = $e['filename']; // a duplicate keeps its name
        if ( $e['drive_file_id'] ) return Orders::queue('expense_move', $payload + ['file_id' => $e['drive_file_id']]);
        return Orders::queue('expense_archive', $payload);
    }

    /** «Reorganizar el archivo»: every archived file goes to its place in the current archive folder. @return int orders */
    public function reorganize(){
        global $wpdb;
        $n = 0;
        foreach ( $wpdb->get_col('SELECT id FROM ' . Expenses::table() . " WHERE drive_file_id IS NOT NULL AND status IN ('registered','not_mine','payment','tax') ORDER BY id") as $id ) {
            if ( $this->queue_archive((int) $id) ) $n++;
        }
        return $n;
    }

    public static function order_done($type, array $payload, $ok, array $result){
        if ( ! $ok || ! in_array($type, ['expense_archive', 'expense_move'], true) ) return;
        $fields = [];
        if ( ! empty($result['file_id']) ) $fields['drive_file_id'] = preg_replace('/[^A-Za-z0-9_\-]/', '', $result['file_id']);
        if ( ! empty($result['url']) && preg_match('#^https://(drive|docs)\.google\.com/#', $result['url']) ) $fields['drive_url'] = esc_url_raw($result['url']);
        $id = (int) $payload['expense_id'];
        if ( $fields ) Expenses::update($id, $fields);
        if ( $type === 'expense_archive' && ! empty($fields['drive_file_id']) ) Uploads::delete($id); // safe in Drive now
        $e = Expenses::get($id);
        if ( ! $e || empty($fields['drive_url']) ) return;
        // The proof of payment is reachable from its invoice; a tax document from Impuestos.
        if ( $e['status'] === 'payment' && $e['payment_for'] ) Expenses::update($e['payment_for'], ['payment_file_id' => $fields['drive_file_id'] ?? $e['drive_file_id'], 'payment_url' => $fields['drive_url']]);
        if ( $e['status'] === 'tax' && ! empty($e['extraction']['tax_entry_id']) ) \MAD_Oficina\Billing\Taxes::set_file((int) $e['extraction']['tax_entry_id'], $fields['drive_url']);
    }

    /* ==== Payments and taxes ==== */

    /** A proof of payment: joined to the invoice it pays, or kept waiting for it. */
    private function payment($id, array $x, array $row){
        $supplier = Suppliers::match($x['supplier_tax_id'], '', $x['supplier_name'] ?: $x['payee_name']);
        Expenses::update($id, ['status' => 'payment', 'reason' => 'unmatched', 'supplier_id' => $supplier['id'] ?? null, 'paid_eur' => $x['paid_eur'] ?: null] + $row);
        $inv = $this->link_payment($id);
        return ['expense_id' => (int) $id, 'status' => 'payment', 'reason' => $inv ? null : 'unmatched'];
    }

    /**
     * The invoice a payment pays: the one it names, or one of the same supplier (or payee)
     * with the same amount (same currency) dated up to 90 days before or 10 after;
     * among them, one not yet paid and closest in date. @return int invoice id or 0
     */
    public function link_payment($pid, $invoice_id = 0){
        global $wpdb;
        $p = Expenses::get($pid);
        if ( ! $p || $p['status'] !== 'payment' ) return 0;
        if ( ! $invoice_id ) {
            $date = $p['issue_date'] ?: $p['received_at'];
            if ( ! $date ) return 0;
            $cands = array_map(fn($r) => Expenses::get((int) $r), $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Expenses::table() . " WHERE status = 'registered' AND issue_date BETWEEN %s AND %s",
                gmdate('Y-m-d', strtotime($date . ' -90 days')), gmdate('Y-m-d', strtotime($date . ' +10 days')))));
            $ref = Suppliers::key_name((string) ($p['extraction']['invoice_ref'] ?? ''));
            $payee = array_filter([Suppliers::key_name((string) $p['supplier_name']), Suppliers::key_name((string) ($p['extraction']['payee_name'] ?? ''))]);
            $best = null; $score = -1;
            foreach ( $cands as $c ) {
                $named = $ref && $c['number'] && Suppliers::key_name($c['number']) === $ref;
                $same = ( $p['supplier_id'] && $p['supplier_id'] === $c['supplier_id'] ) || in_array(Suppliers::key_name((string) $c['supplier_name']), $payee, true);
                $amount = $c['currency'] === $p['currency'] ? abs((float) $c['total'] - (float) $p['total']) < 0.02 : null; // other currency: the amount says nothing
                if ( ! $named && ! ( $same && $amount !== false ) ) continue;
                $sc = ($named ? 100 : 0) + ($amount ? 20 : 0) + (Expenses::payments_for($c['id']) ? 0 : 10) - abs(strtotime($c['issue_date']) - strtotime($date)) / DAY_IN_SECONDS / 10;
                if ( $sc > $score ) { $score = $sc; $best = $c; }
            }
            if ( ! $best ) return 0;
            $invoice_id = $best['id'];
        }
        $inv = Expenses::get($invoice_id);
        if ( ! $inv ) return 0;
        Expenses::update($pid, ['payment_for' => $inv['id'], 'reason' => null, 'supplier_id' => $inv['supplier_id']]);
        // An invoice in another currency counts in euros by what was actually paid.
        if ( $inv['currency'] !== 'EUR' ) {
            $eur = (float) ($p['paid_eur'] ?? 0) ?: ( $p['currency'] === 'EUR' ? (float) $p['total'] : 0 );
            if ( $eur > 0 ) Expenses::update($inv['id'], ['paid_eur' => round((float) $inv['paid_eur'] + $eur, 2)]);
        }
        $this->queue_archive($pid);
        return (int) $inv['id'];
    }

    /** A newly registered invoice may be the one a waiting payment pays. */
    public function payments_waiting($invoice_id){
        global $wpdb;
        $inv = Expenses::get($invoice_id);
        if ( ! $inv || ! $inv['issue_date'] ) return 0;
        $n = 0;
        foreach ( $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Expenses::table() . " WHERE status = 'payment' AND payment_for IS NULL AND issue_date BETWEEN %s AND %s",
            gmdate('Y-m-d', strtotime($inv['issue_date'] . ' -10 days')), gmdate('Y-m-d', strtotime($inv['issue_date'] . ' +90 days')))) as $pid ) {
            if ( $this->link_payment((int) $pid) ) $n++;
        }
        return $n;
    }

    /** A payment to Hacienda or the Seguridad Social: noted in Impuestos (one per tax and period). */
    private function tax($id, array $x, array $row){
        $date = $x['issue_date'] ?: Expenses::get($id)['received_at'];
        $kind = $x['tax_kind'] ?: 'other';
        $period = $x['tax_period'];
        if ( ! preg_match('/^\d{4}(-T[1-4]|-\d{2})?$/', $period) ) {
            $prev = gmdate('Y-m-d', strtotime(substr($date, 0, 7) . '-01 -1 month'));
            $period = $kind === 'autonomos' ? substr($date, 0, 7)
                : ( in_array($kind, ['vat_303', 'irpf_130'], true) ? substr($prev, 0, 4) . '-T' . (int) ceil((int) substr($prev, 5, 2) / 3) : substr($date, 0, 4) );
        }
        $tax = \MAD_Oficina\Billing\Taxes::save(['kind' => $kind, 'period' => $period, 'paid_on' => $date, 'amount' => number_format(abs((float) $x['total']), 2, ',', ''),
            'source' => 'document', 'notes' => trim(($x['concept'] ?: '') . ' · ' . Expenses::get($id)['filename'], ' ·')]);
        $row['extraction'] = wp_json_encode($x + ['tax_entry_id' => is_wp_error($tax) ? 0 : $tax]);
        Expenses::update($id, ['status' => is_wp_error($tax) ? 'review' : 'tax', 'reason' => is_wp_error($tax) ? 'unreadable' : null] + $row);
        if ( ! is_wp_error($tax) ) $this->queue_archive($id);
        return ['expense_id' => (int) $id, 'status' => is_wp_error($tax) ? 'review' : 'tax', 'reason' => null];
    }

    /** «Es una factura»: a document read as a payment proof that is in fact the only invoice there is. */
    public function as_invoice($id){
        $e = Expenses::get($id);
        if ( ! $e || ! in_array($e['status'], ['payment', 'ignored', 'review'], true) || ! $e['extraction'] ) return new \WP_Error('madof_expense', __('No puedo tratar este documento como factura.', 'mad-oficina'));
        Expenses::update($id, ['payment_for' => null]);
        $x = $e['extraction'];
        $x['is_invoice'] = true; $x['doc_type'] = 'invoice';
        if ( empty($x['supplier_name']) ) $x['supplier_name'] = $x['payee_name'] ?? '';
        return $this->decide($id, $x);
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

    /**
     * Several at once from the lists: «aside» (not mine, kept in «No son mías»),
     * «discard» (not an invoice: forgotten) or «read» (read again now).
     * @return int how many
     */
    public function bulk($action, array $ids){
        $n = 0;
        foreach ( array_unique(array_map('intval', $ids)) as $id ) {
            $e = Expenses::get($id);
            if ( ! $e || $e['status'] === 'registered' ) continue;
            foreach ( Approvals::for_object('expense', $id) as $a ) {
                if ( $a['status'] === Approvals::PENDING ) Approvals::resolve($a['id'], 'reject', [], get_current_user_id());
            }
            $e = Expenses::get($id);
            if ( $action === 'read' ) {
                Expenses::update($id, ['status' => 'queued', 'reason' => 'force', 'notes' => null]); // she asked: read even if long
                self::schedule($id);
            } elseif ( $action === 'discard' ) {
                Expenses::update($id, ['status' => 'ignored', 'reason' => 'jessica']);
                Uploads::delete($id);
            } else {
                if ( $e['status'] !== 'not_mine' ) Expenses::update($id, ['status' => 'not_mine', 'reason' => 'jessica']);
                $this->queue_archive($id);
            }
            $n++;
        }
        return $n;
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
        } elseif ( $known = Suppliers::match($input['tax_id'] ?? '', self::address($e['sender']), $input['name'] ?? '') ) {
            // Already there (e.g. another of its invoices was approved first): joined, never duplicated.
            $supplier = $known;
            if ( $from = self::address($e['sender']) ) Suppliers::add_email($supplier['id'], $from);
        } else {
            $sid = Suppliers::save($input + ['currency' => $e['currency'], 'expected_month' => $e['issue_date'] && ($input['periodicity'] ?? '') === 'yearly' ? (int) substr($e['issue_date'], 5, 2) : null]);
            if ( is_wp_error($sid) ) return $sid;
            $supplier = Suppliers::get($sid);
        }
        Expenses::update($id, $this->register_fields($supplier, $e + ['total' => (float) $e['total']]));
        $this->queue_archive($id);
        do_action('madoficina_expense_registered', $id);
        $msg = sprintf(__('%s dado de alta y su factura registrada.', 'mad-oficina'), $supplier['name']);
        // Its other invoices waiting with the same question are registered too.
        $more = 0;
        foreach ( Approvals::pending(500, self::NEW_SUPPLIER) as $other ) {
            if ( (int) $other['id'] === (int) $approval['id'] ) continue;
            $oe = Expenses::get((int) ($other['payload']['expense_id'] ?? 0));
            if ( ! $oe ) continue;
            $match = Suppliers::match((string) $oe['supplier_tax_id'], self::address($oe['sender']), (string) $oe['supplier_name']);
            if ( ! $match || (int) $match['id'] !== (int) $supplier['id'] ) continue;
            $r = Approvals::resolve($other['id'], 'approve', ['mode' => 'existing', 'existing' => $supplier['id']], get_current_user_id(), 'auto');
            if ( ! is_wp_error($r) ) $more++;
        }
        if ( $more ) $msg .= ' ' . sprintf(_n('Y %d factura más suya que esperaba.', 'Y %d facturas más suyas que esperaban.', $more, 'mad-oficina'), $more);
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
            $e['issue_date'] ? date_i18n('j M Y', strtotime($e['issue_date'])) : '', Suppliers::money((float) $e['total'], $e['currency']),
        ]))) . '<br><small>' . esc_html(sprintf(__('A nombre de: %s', 'mad-oficina'), trim(($e['recipient_name'] ?: '—') . ' ' . ($e['recipient_tax_id'] ?: '')))) . ' · ' . esc_html(sprintf(__('De: %s', 'mad-oficina'), $e['sender'] ?: __('subida manual', 'mad-oficina'))) . '</small>'
            . ' · <a href="' . esc_url($view) . '">' . esc_html__('Ver el gasto', 'mad-oficina') . '</a></p>';
    }
}
