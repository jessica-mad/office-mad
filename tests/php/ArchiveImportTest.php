<?php
use MAD_Oficina\Billing\Taxes;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Meetings\Hooks;
use MAD_Oficina\Meetings\Orders;
use MAD_Oficina\Suppliers\Expenses;
use MAD_Oficina\Suppliers\Intake;
use MAD_Oficina\Suppliers\Reader;
use MAD_Oficina\Suppliers\Suppliers;

/**
 * The Drive archive import, payment proofs joined to their invoices and tax payments.
 * Claude is faked; all data is invented.
 */
class ArchiveImportTest extends MADOF_TestCase {

    private $next = []; private $blocks = [];

    public function set_up(){
        parent::set_up();
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        update_option(MAD_Oficina_Core::option_key('billing'), ['issuer_name' => 'Ana Prueba Gómez', 'issuer_tax_id' => 'X1234567L']);
        update_option(MAD_Oficina_Core::option_key('suppliers'), ['model' => 'claude-haiku-5-5', 'archive_folder' => 'ARCHIVE_ROOT_ID_1']);
        wp_set_current_user($this->make_admin());
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
        Suppliers::save(['name' => 'Nube SL', 'tax_id' => 'B99999999']);
        Suppliers::save(['name' => 'Estudio Lila', 'currency' => 'USD']);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        if ( $url !== 'https://api.anthropic.com/v1/messages' ) return $pre;
        $body = json_decode($args['body'], true);
        $this->blocks[] = $body['messages'][0]['content'][0]['type'];
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
            'content' => [['type' => 'text', 'text' => wp_json_encode($this->next)]]])];
    }

    private function x(array $o = []){
        return $o + ['is_invoice' => true, 'doc_type' => 'invoice', 'payee_name' => '', 'paid_eur' => 0, 'invoice_ref' => '', 'tax_kind' => '', 'tax_period' => '',
            'supplier_name' => 'Nube SL', 'supplier_tax_id' => 'B99999999', 'recipient_name' => 'Ana Prueba Gómez', 'recipient_tax_id' => 'X1234567L', 'recipient_email' => '',
            'number' => 'N-' . wp_rand(1, 99999), 'issue_date' => '2025-02-03', 'currency' => 'EUR', 'base' => 10.0, 'vat' => 2.1, 'irpf' => 0, 'total' => 12.1,
            'concept' => 'Hosting', 'category' => 'hosting', 'periodicity' => 'monthly', 'confidence' => 'high'];
    }

    private function intake(){ return new Intake(get_option(MAD_Oficina_Core::option_key('suppliers'))); }
    private function orders($type){ return array_values(array_filter(Orders::pending(), fn($o) => $o['type'] === $type)); }
    private function doc($x, array $meta = [], $bytes = null){
        $this->next = $x;
        return $this->intake()->receive($bytes ?? '%PDF-1.4 ' . wp_generate_password(16, false), $meta + ['source' => 'upload', 'filename' => 'doc.pdf']);
    }

    public function test_files_from_the_drive_archive(){
        $this->assertSame('2025-03-31', Intake::folder_end('Facturas Compras IMAD/2025/T1'));
        $this->assertSame('2026-09-30', Intake::folder_end('Facturas para MAD/2026/2026-T3'));
        $this->assertSame('2025-12-31', Intake::folder_end('Facturas Compras IMAD/2025'));
        $this->assertNull(Intake::folder_end('Varios'));

        // From her personal folder: copied into the archive, the original is never touched.
        $r = $this->doc($this->x(['issue_date' => '2025-02-03']), ['source' => 'drive', 'file_id' => 'PERSONAL1', 'folder' => 'Facturas Compras IMAD/2025/T1', 'filename' => 'invoice.pdf']);
        $e = Expenses::get($r['expense_id']);
        $this->assertSame(['registered', 'drive', 'drive:PERSONAL1', null, '2025-03-31'], [$e['status'], $e['source'], $e['source_ref'], $e['drive_file_id'], $e['received_at']]);
        $copy = $this->orders('expense_archive')[0]['payload'];
        $this->assertSame(['2025', '2025-T1'], $copy['folder']);
        $this->assertSame('ARCHIVE_ROOT_ID_1', $copy['root']);
        $this->assertSame([], Expenses::unknown_refs(['drive:PERSONAL1']), 'not sent again');

        // Already in the archive (old copies): moved and renamed in place.
        $r2 = $this->doc($this->x(['number' => 'IN-1', 'issue_date' => '2025-03-03', 'total' => 30.0]), ['source' => 'drive', 'file_id' => 'ARCH1', 'in_archive' => true, 'folder' => 'Facturas para MAD/2025/2025-T1', 'filename' => 'IONOS.pdf']);
        $move = $this->orders('expense_move')[0]['payload'];
        $this->assertSame(['ARCH1', ['2025', '2025-T1']], [$move['file_id'], $move['folder']]);
        $this->assertSame('registered', Expenses::get($r2['expense_id'])['status']);

        // The same bytes again from the archive while the known one already has its file: a duplicate.
        $bytes = '%PDF-1.4 same bytes';
        $first = $this->doc($this->x(['number' => 'D-1', 'issue_date' => '2025-01-20']), ['source' => 'drive', 'file_id' => 'ARCH2', 'in_archive' => true, 'folder' => 'Facturas para MAD/2025/2025-T1'], $bytes);
        $dup = $this->intake()->receive($bytes, ['source' => 'drive', 'file_id' => 'ARCH3', 'in_archive' => true]);
        $this->assertSame(['duplicate', true, $first['expense_id']], [$dup['reason'], $dup['duplicate'], $dup['expense_id']]);
        // …and from her personal folder: remembered, so it is not downloaded again.
        $this->intake()->receive($bytes, ['source' => 'drive', 'file_id' => 'PERSONAL2']);
        $this->assertSame(['drive:NEW'], Expenses::unknown_refs(['drive:PERSONAL2', 'drive:NEW', 'drive:ARCH2']));

        // A file Gmail brought (not archived yet) adopts the archive's copy.
        $g = $this->doc($this->x(['recipient_name' => '', 'recipient_tax_id' => '']), ['source' => 'gmail'], '%PDF-1.4 from gmail');
        $this->assertSame('review', $g['status']);
        $adopt = $this->intake()->receive('%PDF-1.4 from gmail', ['source' => 'drive', 'file_id' => 'ARCH4', 'in_archive' => true]);
        $this->assertSame('adopted', $adopt['reason']);
        $this->assertSame('ARCH4', Expenses::get($g['expense_id'])['drive_file_id']);
    }

    public function test_images_are_read_too(){
        $png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 40);
        $this->assertSame('image/png', Reader::media_type($png));
        $this->assertSame('image/jpeg', Reader::media_type("\xFF\xD8\xFF\xE0" . 'jfif'));
        $this->assertNull(Reader::media_type('hello'));
        $this->assertSame(1, Reader::pages($png));
        $r = $this->doc($this->x(), ['filename' => 'Captura de pantalla.png'], $png);
        $this->assertSame('image', end($this->blocks));
        $e = Expenses::get($r['expense_id']);
        $this->assertStringEndsWith('.png', Expenses::filename($e));
        $this->assertWPError($this->intake()->receive('just text', ['source' => 'upload']));
    }

    public function test_a_payment_proof_sits_next_to_its_invoice(){
        $inv = $this->doc($this->x(['number' => 'WF-0023', 'issue_date' => '2026-09-28', 'total' => 24.2, 'base' => 20, 'vat' => 4.2]));
        $this->assertSame('registered', $inv['status']);
        $pay = $this->doc($this->x(['is_invoice' => false, 'doc_type' => 'payment', 'number' => 'R-2891', 'invoice_ref' => '', 'issue_date' => '2026-09-28', 'total' => 24.2, 'base' => 24.2, 'vat' => 0]));
        $this->assertSame(['payment', null], [$pay['status'], $pay['reason']]);
        $p = Expenses::get($pay['expense_id']);
        $this->assertSame($inv['expense_id'], $p['payment_for']);
        $all = $this->orders('expense_archive');
        $order = end($all)['payload'];
        $this->assertSame(['2026', '2026-T3'], $order['folder']);
        $this->assertSame('2026-09-28_Nube-SL_24,20_pago.pdf', $order['name']);
        // Archived: the invoice points to its proof.
        Intake::order_done('expense_archive', $order, true, ['file_id' => 'PAYFILE', 'url' => 'https://drive.google.com/file/d/PAYFILE/view']);
        $this->assertSame('https://drive.google.com/file/d/PAYFILE/view', Expenses::get($inv['expense_id'])['payment_url']);
        // A proof is never counted as an expense.
        $f = \MAD_Oficina\Billing\Finance::year(2026);
        $this->assertSame(20.0, $f['months'][9]['expenses']);
    }

    public function test_paid_in_euros_for_an_invoice_in_another_currency(){
        // Liz-like case: the payment arrives first (Western Union, in euros), the invoice later.
        $pay = $this->doc($this->x(['is_invoice' => false, 'doc_type' => 'payment', 'supplier_name' => 'Estudio Lila', 'supplier_tax_id' => '', 'payee_name' => 'Estudio Lila',
            'currency' => 'USD', 'total' => 500, 'base' => 500, 'vat' => 0, 'paid_eur' => 471.3, 'issue_date' => '2026-08-04', 'number' => 'WU-1']));
        $this->assertSame(['payment', 'unmatched'], [$pay['status'], $pay['reason']]);
        $this->assertSame([], $this->orders('expense_archive'), 'not archived until its invoice is known');
        $inv = $this->doc($this->x(['supplier_name' => 'Estudio Lila', 'supplier_tax_id' => '', 'currency' => 'USD', 'total' => 500, 'base' => 500, 'vat' => 0, 'issue_date' => '2026-08-03', 'number' => 'L-7']));
        $this->assertSame('registered', $inv['status']);
        $this->assertSame($inv['expense_id'], Expenses::get($pay['expense_id'])['payment_for'], 'joined when the invoice came');
        $this->assertSame(471.3, Expenses::get($inv['expense_id'])['paid_eur']);
        $this->assertSame(471.3, \MAD_Oficina\Billing\Finance::year(2026)['months'][8]['expenses'], 'the summary counts what was paid in euros');
    }

    public function test_by_hand_and_receipt_as_invoice(){
        $pay = $this->doc($this->x(['is_invoice' => false, 'doc_type' => 'payment', 'total' => 99, 'issue_date' => '2026-05-12', 'number' => 'R-1']));
        $this->assertSame('unmatched', $pay['reason']);
        $inv = $this->doc($this->x(['total' => 50, 'issue_date' => '2026-03-01', 'number' => 'OLD-1']));
        $this->assertSame($inv['expense_id'], $this->intake()->link_payment($pay['expense_id'], $inv['expense_id']));
        // No invoice at all: the receipt is registered as the expense.
        $alone = $this->doc($this->x(['is_invoice' => false, 'doc_type' => 'payment', 'total' => 7, 'issue_date' => '2026-06-01', 'number' => 'R-2']));
        $r = $this->intake()->as_invoice($alone['expense_id']);
        $this->assertSame('registered', $r['status']);
    }

    public function test_tax_payments_go_to_impuestos(){
        $r = $this->doc($this->x(['is_invoice' => false, 'doc_type' => 'tax', 'tax_kind' => 'autonomos', 'tax_period' => '', 'supplier_name' => 'Tesorería General de la Seguridad Social', 'supplier_tax_id' => '',
            'total' => 294, 'issue_date' => '2025-03-31', 'concept' => 'Cuota RETA marzo']));
        $this->assertSame('tax', $r['status']);
        $t = Taxes::find('autonomos', '2025-03');
        $this->assertSame([294.0, '2025-03-31', 'document'], [$t['amount'], $t['paid_on'], $t['source']]);
        $this->assertSame(0, Taxes::apply_monthly(['autonomos_amount' => 300, 'autonomos_from' => '2025-03', 'autonomos_day' => 28], '2025-03-31'), 'the real payment is not doubled by the monthly note');
        $order = $this->orders('expense_archive')[0]['payload'];
        $this->assertSame('2025-03-31_Impuesto-autonomos_294,00.pdf', $order['name']);
        Intake::order_done('expense_archive', $order, true, ['file_id' => 'TAXFILE', 'url' => 'https://drive.google.com/file/d/TAXFILE/view']);
        $this->assertSame('https://drive.google.com/file/d/TAXFILE/view', Taxes::get($t['id'])['file_url']);

        // A 303 paid in April liquidates the first quarter.
        $this->doc($this->x(['is_invoice' => false, 'doc_type' => 'tax', 'tax_kind' => 'vat_303', 'tax_period' => 'nope', 'total' => 410.5, 'issue_date' => '2025-04-18']));
        $this->assertSame(410.5, Taxes::find('vat_303', '2025-T1')['amount']);
    }

    private function hook($method, $route, $body = null){
        $req = new WP_REST_Request($method, '/mad-oficina/v1' . $route);
        $raw = $body === null ? '' : wp_json_encode($body);
        $ts = (string) time();
        $req->set_header('X-Mad-Timestamp', $ts);
        $req->set_header('X-Mad-Signature', Hooks::sign('test-secret-123', $ts, $method, '/mad-oficina/v1' . $route, $raw));
        if ( $body !== null ) { $req->set_header('Content-Type', 'application/json'); $req->set_body($raw); }
        return rest_do_request($req);
    }

    public function test_cartero_import_endpoints(){
        update_option(MAD_Oficina_Core::option_key('meetings'), ['hook_secret' => Crypto::encrypt('test-secret-123')]);
        $s = get_option(MAD_Oficina_Core::option_key('suppliers'));
        $s['drive_import'] = ['active' => true, 'since' => 2025, 'folders' => ['PERSONAL_ROOT']];
        update_option(MAD_Oficina_Core::option_key('suppliers'), $s);
        $GLOBALS['wp_rest_server'] = null;
        $cfg = $this->hook('GET', '/hooks/expenses/config')->get_data();
        $this->assertSame('ARCHIVE_ROOT_ID_1', $cfg['archive_root']);
        $this->assertSame(['active' => true, 'since' => 2025, 'folders' => ['ARCHIVE_ROOT_ID_1', 'PERSONAL_ROOT']], $cfg['drive_import'], 'the archive first');

        $this->next = $this->x();
        $res = $this->hook('POST', '/hooks/expenses', ['source' => 'drive', 'file_id' => 'F1', 'folder' => 'Facturas Compras IMAD/2025/T1', 'in_archive' => false, 'filename' => 'a.pdf', 'base64' => base64_encode('%PDF-1.4 drive one')])->get_data();
        $this->assertSame('queued', $res['status']);
        $this->assertSame(['drive:F2'], $this->hook('POST', '/hooks/expenses/known', ['refs' => ['drive:F1', 'drive:F2']])->get_data()['unknown_refs']);

        $this->hook('POST', '/hooks/expenses/import', ['files' => 10, 'sent' => 3, 'pending' => 0, 'errors' => 0, 'done' => true]);
        $imp = get_option(MAD_Oficina_Core::option_key('suppliers'))['drive_import'];
        $this->assertFalse($imp['active'], 'finished: the cartero stops walking the folders');
        $this->assertSame(3, $imp['last']['sent']);
    }
}
