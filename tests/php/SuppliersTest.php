<?php
use MAD_Oficina\Billing\Calendar;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Meetings\Hooks;
use MAD_Oficina\Meetings\Orders;
use MAD_Oficina\Suppliers\Expenses;
use MAD_Oficina\Suppliers\Intake;
use MAD_Oficina\Suppliers\Suppliers;
use MAD_Oficina\Suppliers\Uploads;
use MAD_Oficina\Suppliers\Watch;

/**
 * Supplier invoices: only those addressed to Jessica count; the rest are kept
 * aside. Claude is faked; all data is invented.
 */
class SuppliersTest extends MADOF_TestCase {

    private $next = []; private $calls = 0; private $client; private $profile;

    public function set_up(){
        parent::set_up();
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        update_option(MAD_Oficina_Core::option_key('billing'), ['issuer_name' => 'Ana Prueba Gómez', 'issuer_tax_id' => 'X1234567L', 'billing_day' => 28, 'payment_days' => 15, 'calendar_enabled' => true, 'calendar_from' => '2026-01']);
        update_option(MAD_Oficina_Core::option_key('suppliers'), ['my_emails' => 'ana@mad.example', 'model' => 'claude-haiku-5-5']);
        $this->client = $this->make_client('TAZ', 'Taller Azul');
        $this->profile = Profiles::save(['legal_name' => 'Taller Azul SL', 'tax_id' => 'B11111111', 'client_id' => $this->client]);
        wp_set_current_user($this->make_admin());
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        if ( $url !== 'https://api.anthropic.com/v1/messages' ) return $pre;
        $this->calls++;
        $body = json_decode($args['body'], true);
        $this->assertSame('document', $body['messages'][0]['content'][0]['type'], 'the PDF goes as a document block');
        if ( $this->next === 'error' ) return ['response' => ['code' => 529], 'body' => '{"error":{"message":"overloaded"}}'];
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
            'content' => [['type' => 'text', 'text' => wp_json_encode($this->next)]]])];
    }

    private function x(array $o = []){
        return $o + ['is_invoice' => true, 'doc_type' => 'invoice', 'supplier_name' => 'Nube SL', 'supplier_tax_id' => 'B99999999',
            'recipient_name' => 'Ana Prueba Gómez', 'recipient_tax_id' => 'X1234567L', 'recipient_email' => '', 'number' => 'N-' . wp_rand(1, 99999),
            'issue_date' => '2026-10-03', 'currency' => 'EUR', 'base' => 10.0, 'vat' => 2.1, 'irpf' => 0, 'total' => 12.1,
            'concept' => 'Hosting octubre', 'category' => 'hosting', 'periodicity' => 'monthly', 'confidence' => 'high'];
    }

    private function receive($x, $from = 'Nube <facturas@nube.example>'){
        $this->next = $x;
        return $this->intake()->receive('%PDF-1.4 fake ' . wp_generate_password(12, false), ['source' => 'gmail', 'message_id' => 'm' . wp_rand(), 'from' => $from, 'subject' => 'Tu factura', 'filename' => 'factura.pdf']);
    }

    private function intake(){ return new Intake(get_option(MAD_Oficina_Core::option_key('suppliers'))); }
    private function orders($type){ return array_values(array_filter(Orders::pending(), fn($o) => $o['type'] === $type)); }

    public function test_only_invoices_addressed_to_me_count(){
        $this->assertSame('not_mine', $this->receive($this->x(['recipient_name' => 'Taller Azul S.L.', 'recipient_tax_id' => 'B11111111']))['status'], 'a client\'s invoice');
        $r = $this->receive($this->x(['recipient_name' => 'Taller Azul SL', 'recipient_tax_id' => '']));
        $this->assertSame(['not_mine', 'client:Taller Azul SL'], [$r['status'], $r['reason']]);
        $this->assertSame(['not_mine', 'other'], array_values(array_slice($this->receive($this->x(['recipient_name' => 'Pepe Ruiz', 'recipient_tax_id' => '00000000T'])), 1)));
        $this->assertSame('review', $this->receive($this->x(['recipient_name' => 'ANA PRUEBA GÓMEZ', 'recipient_tax_id' => '']))['status'], 'mine by name (new supplier → asked)');
        $this->assertSame('recipient', $this->receive($this->x(['recipient_name' => '', 'recipient_tax_id' => '']))['reason'], 'nobody named: she is asked');
        $this->assertSame('supplier', $this->receive($this->x(['recipient_name' => '', 'recipient_tax_id' => '', 'recipient_email' => 'ana@mad.example']))['reason'], 'her email on a receipt: hers');

        $aside = Expenses::all(['status' => 'not_mine']);
        $this->assertCount(3, $aside);
        $this->assertCount(3, $this->orders('expense_archive'), 'kept aside in «Recibidas/No son mías»');
        $this->assertSame(['No son mías', '2026'], $this->orders('expense_archive')[0]['payload']['folder']);
    }

    public function test_new_supplier_then_known_supplier(){
        $r = $this->receive($this->x(['number' => 'A-1']));
        $this->assertSame(['review', 'supplier'], [$r['status'], $r['reason']]);
        $approval = Approvals::for_object('expense', $r['expense_id'])[0];
        $this->assertSame(Intake::NEW_SUPPLIER, $approval['action_type']);

        // «Aprobar tal cual» from Chat uses the defaults.
        $input = apply_filters('madoficina_approval_default_input_' . Intake::NEW_SUPPLIER, [], $approval);
        $res = Approvals::resolve($approval['id'], 'approve', $input, get_current_user_id(), 'chat');
        $this->assertNotWPError($res);
        $s = Suppliers::match('B99999999', '', '');
        $this->assertSame('Nube SL', $s['name']);
        $this->assertSame(['facturas@nube.example'], $s['emails']);
        $this->assertSame('hosting', $s['category']);
        $this->assertSame('registered', Expenses::get($r['expense_id'])['status']);
        $this->assertSame(['2026', 'T4'], $this->orders('expense_archive')[0]['payload']['folder']);
        $this->assertSame('2026-10-03_Nube-SL_12,10.pdf', $this->orders('expense_archive')[0]['payload']['name']);
        $this->assertSame(['facturas@nube.example'], $this->orders('gmail_filter')[0]['payload']['from']);

        // Next month: registered straight away, nothing asked.
        $r2 = $this->receive($this->x(['number' => 'A-2', 'issue_date' => '2026-11-03']));
        $this->assertSame('registered', $r2['status']);
        $this->assertSame([], Approvals::for_object('expense', $r2['expense_id']));
        // The same invoice again (another email, another file): ignored.
        $this->assertSame(['ignored', 'duplicate'], array_values(array_slice($this->receive($this->x(['number' => 'A-2', 'issue_date' => '2026-11-03'])), 1)));
        // The very same file: not even read.
        $calls = $this->calls;
        $this->next = $this->x();
        $pdf = '%PDF-1.4 same';
        $this->intake()->receive($pdf, ['source' => 'upload']);
        $again = $this->intake()->receive($pdf, ['source' => 'upload']);
        $this->assertSame('seen', $again['reason']);
        $this->assertSame($calls + 1, $this->calls);
    }

    public function test_not_an_invoice_and_unreadable(){
        $r = $this->receive($this->x(['is_invoice' => false, 'doc_type' => 'quote']));
        $this->assertSame(['ignored', 'not_invoice'], [$r['status'], $r['reason']]);
        $this->assertNull(Uploads::read($r['expense_id']), 'its file is not kept');

        $r = $this->receive('error');
        $this->assertSame('queued', $r['status']);
        $this->next = 'error';
        $this->intake()->process_queued();
        $this->intake()->process_queued();
        $this->assertSame(['review', 'unreadable'], [Expenses::get($r['expense_id'])['status'], Expenses::get($r['expense_id'])['reason']]);
    }

    public function test_recovering_one_kept_aside(){
        $sid = Suppliers::save(['name' => 'Nube SL', 'tax_id' => 'B99999999', 'category' => 'hosting']);
        $r = $this->receive($this->x(['recipient_name' => 'Otra Persona', 'recipient_tax_id' => '']));
        $this->assertSame('not_mine', $r['status']);
        Orders::complete($this->orders('expense_archive')[0]['id'], true, ['file_id' => 'f1', 'url' => 'https://drive.google.com/file/d/f1/view']);
        $this->assertSame('f1', Expenses::get($r['expense_id'])['drive_file_id']);

        $res = $this->intake()->reclassify($r['expense_id'], true);
        $this->assertSame('registered', Expenses::get($r['expense_id'])['status']);
        $this->assertSame($sid, Expenses::get($r['expense_id'])['supplier_id']);
        $move = $this->orders('expense_move')[0]['payload'];
        $this->assertSame(['f1', ['2026', 'T4']], [$move['file_id'], $move['folder']], 'the file moves out of «No son mías»');
    }

    public function test_unclear_recipient_card(){
        Suppliers::save(['name' => 'Nube SL', 'tax_id' => 'B99999999']);
        $r = $this->receive($this->x(['recipient_name' => '', 'recipient_tax_id' => '']));
        $a = Approvals::for_object('expense', $r['expense_id'])[0];
        $this->assertSame(Intake::REVIEW, $a['action_type']);
        Approvals::resolve($a['id'], 'reject', [], get_current_user_id());
        $this->assertSame('not_mine', Expenses::get($r['expense_id'])['status']);
        $r2 = $this->receive($this->x(['recipient_name' => '', 'recipient_tax_id' => '', 'number' => 'Z']));
        Approvals::resolve(Approvals::for_object('expense', $r2['expense_id'])[0]['id'], 'approve', [], get_current_user_id());
        $this->assertSame('registered', Expenses::get($r2['expense_id'])['status']);
        $this->assertCount(2, $this->orders('expense_archive'), 'each one archived once, in its folder');
    }

    public function test_rebilled_on_the_clients_next_invoice(){
        Suppliers::save(['name' => 'Plugins Pro', 'tax_id' => 'B88888888', 'client_id' => $this->client, 'category' => 'software']);
        $r = $this->receive($this->x(['supplier_name' => 'Plugins Pro', 'supplier_tax_id' => 'B88888888', 'concept' => 'Licencia WPML', 'base' => 39.0, 'vat' => 8.19, 'total' => 47.19]));
        $this->assertSame('registered', $r['status']);
        $this->assertSame($this->client, Expenses::get($r['expense_id'])['client_id']);

        $cal = new Calendar(get_option(MAD_Oficina_Core::option_key('billing')));
        $month = $cal->month('2026-10');
        $this->assertSame('to_prepare', $month[0]['state'], 'a client with only a re-billable expense shows up too');
        $id = $cal->prepare_one(Profiles::get($this->profile), '2026-10');
        $inv = Invoices::get($id);
        $this->assertStringStartsWith('Repercutido: Plugins Pro · Licencia WPML', $inv['lines'][0]['description']);
        $this->assertSame(39.0, (float) $inv['lines'][0]['amount'], 'base, no margin');
        $this->assertSame($r['expense_id'], (int) $inv['lines'][0]['expense_id']);
        $this->assertSame([], Expenses::pending_rebill($this->client), 'never twice');
        $this->assertSame(0, $cal->prepare_one(Profiles::get($this->profile), '2026-10'));
        $this->assertSame('borrador', Expenses::rebilled_in($r['expense_id']));
    }

    public function test_missing_invoice_is_asked_and_closes_itself(){
        global $wpdb;
        $sid = Suppliers::save(['name' => 'Nube SL', 'tax_id' => 'B99999999', 'periodicity' => 'monthly', 'expected_day' => 3, 'usual_amount' => '12,10']);
        $wpdb->update(Suppliers::table(), ['created_at' => '2026-01-01 00:00:00'], ['id' => $sid]);
        // October's arrived; November's did not.
        $this->receive($this->x(['number' => 'OCT', 'issue_date' => '2026-10-03']));
        $w = new Watch(['grace_days' => 5]);
        $this->assertSame(0, $w->missing('2026-11-07'), 'still within the grace days');
        $this->assertSame(1, $w->missing('2026-11-08'));
        $this->assertSame(0, $w->missing('2026-11-09'), 'asked once');
        $a = array_values(array_filter(Approvals::for_object('supplier', $sid), fn($x) => $x['action_type'] === Watch::ACTION))[0];
        $this->assertStringContainsString('noviembre 2026', $a['title']);
        $this->assertSame(['Te la reenvío', 'Recuérdamelo en 7 días', 'Este mes no hay'], array_column(Watch::chat_buttons([], $a), 'text'));

        // «Recuérdamelo en 7 días»: quiet until then, asked again after.
        Watch::answer($a, 'later', 7, '2026-11-08');
        $this->assertSame(0, $w->missing('2026-11-14'));
        $this->assertSame(1, $w->missing('2026-11-15'));
        // When it arrives, the open question closes itself.
        $open = array_values(array_filter(Approvals::for_object('supplier', $sid), fn($x) => $x['status'] === Approvals::PENDING));
        $this->assertNotEmpty($open);
        $this->receive($this->x(['number' => 'NOV', 'issue_date' => '2026-11-04']));
        foreach ( $open as $o ) $this->assertSame(Approvals::APPROVED, Approvals::get($o['id'])['status']);
        // «Este mes no hay» (or «Descartar») stops the questions for that period.
        $sid2 = Suppliers::save(['name' => 'Otra', 'periodicity' => 'monthly', 'expected_day' => 1]);
        $wpdb->update(Suppliers::table(), ['created_at' => '2026-01-01 00:00:00'], ['id' => $sid2]);
        $w->missing('2026-11-08');
        foreach ( Approvals::for_object('supplier', $sid2) as $x ) Approvals::resolve($x['id'], 'reject', [], get_current_user_id());
        $this->assertSame(0, $w->missing('2026-11-20'));
    }

    private function hook($method, $route, $body = null, $secret = 'test-secret-123'){
        $req = new WP_REST_Request($method, '/mad-oficina/v1' . $route);
        $raw = $body === null ? '' : wp_json_encode($body);
        $ts = (string) time();
        $req->set_header('X-Mad-Timestamp', $ts);
        $req->set_header('X-Mad-Signature', Hooks::sign($secret, $ts, $method, '/mad-oficina/v1' . $route, $raw));
        if ( $body !== null ) { $req->set_header('Content-Type', 'application/json'); $req->set_body($raw); }
        return rest_do_request($req);
    }

    public function test_cartero_endpoints_are_signed_and_never_send_a_pdf_twice(){
        update_option(MAD_Oficina_Core::option_key('meetings'), ['hook_secret' => Crypto::encrypt('test-secret-123')]);
        $GLOBALS['wp_rest_server'] = null;
        Suppliers::save(['name' => 'Nube SL', 'tax_id' => 'B99999999', 'emails' => 'facturas@nube.example']);
        $this->assertSame(401, $this->hook('GET', '/hooks/expenses/config', null, 'wrong')->get_status());
        $cfg = $this->hook('GET', '/hooks/expenses/config')->get_data();
        $this->assertContains('facturas@nube.example', $cfg['senders']);

        $pdf = '%PDF-1.4 from gmail';
        $hash = hash('sha256', $pdf);
        $this->assertSame([$hash], $this->hook('POST', '/hooks/expenses/known', ['hashes' => [$hash]])->get_data()['unknown']);
        $this->next = $this->x();
        $res = $this->hook('POST', '/hooks/expenses', ['message_id' => 'abc', 'from' => 'Nube <facturas@nube.example>', 'subject' => 'Factura', 'filename' => 'f.pdf', 'base64' => base64_encode($pdf)]);
        $this->assertSame('registered', $res->get_data()['status']);
        $other = str_repeat('a', 64);
        $this->assertSame([$other], $this->hook('POST', '/hooks/expenses/known', ['hashes' => [$hash, $other]])->get_data()['unknown'], 'only the new one');
        $file = $this->hook('GET', '/hooks/expenses/' . $res->get_data()['expense_id'] . '/file')->get_data();
        $this->assertSame($pdf, base64_decode($file['base64']));
        $this->assertSame('Nube <facturas@nube.example>', Expenses::get($res->get_data()['expense_id'])['sender']);
    }

    public function test_dates_are_checked_against_the_email(){
        $this->assertSame(['2025-10-03', null], Intake::check_date('2025-10-03', '2025-10-05'));
        [$d, $note] = Intake::check_date('2026-10-03', '2025-10-05');
        $this->assertSame('2025-10-03', $d, 'dated after the email: the year was misread');
        $this->assertStringContainsString('corregida', $note);
        $this->assertSame('2025-12-28', Intake::check_date('2026-12-28', '2026-01-04')[0], 'late December invoice arriving in January');
        $this->assertSame(['2025-11-02', 'Sin fecha legible en la factura: uso la del email.'], Intake::check_date('', '2025-11-02'));

        $this->next = $this->x(['issue_date' => '2026-10-03', 'number' => 'Y1']);
        Suppliers::save(['name' => 'Nube SL', 'tax_id' => 'B99999999']);
        $r = $this->intake()->receive('%PDF-1.4 dated', ['source' => 'gmail', 'date' => '2025-10-05T08:00:00.000Z', 'from' => 'x@nube.example']);
        $e = Expenses::get($r['expense_id']);
        $this->assertSame(['2025-10-03', '2025-10-05', 2025], [$e['issue_date'], $e['received_at'], $e['year']]);
        $this->assertStringContainsString('Fecha corregida', $e['notes'], 'the note survives registration');
        $this->assertTrue(\MAD_Oficina\Suppliers\Reader::is_pdf(str_repeat('x', 3000) . '%PDF-1.7'), 'signed documents wrap the PDF');
    }

    public function test_one_new_supplier_with_several_invoices_is_approved_once(){
        $ids = [];
        foreach ( ['B-1', 'B-2', 'B-3'] as $n ) $ids[] = $this->receive($this->x(['number' => $n, 'issue_date' => '2026-0' . substr($n, 2) . '-03']))['expense_id'];
        $cards = Approvals::pending(50, Intake::NEW_SUPPLIER);
        $this->assertCount(3, $cards);
        // Approving two at once (bulk): one supplier, all three invoices registered.
        Approvals::resolve_many([$cards[0]['id'], $cards[1]['id']], 'approve', get_current_user_id());
        $this->assertCount(1, Suppliers::all(), 'never duplicated');
        foreach ( $ids as $id ) $this->assertSame('registered', Expenses::get($id)['status']);
        $this->assertSame([], Approvals::pending(50, Intake::NEW_SUPPLIER), 'the other cards closed themselves');
    }
}
