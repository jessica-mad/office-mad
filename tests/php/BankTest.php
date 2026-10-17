<?php
use MAD_Oficina\Billing\Bank;
use MAD_Oficina\Billing\BankStatement;
use MAD_Oficina\Billing\Collections;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Schema;

/**
 * Finanzas → Banco (statements matched with invoices, expenses and taxes) and Cobros
 * (due invoices not collected). Everything here is invented data.
 */
class BankTest extends MADOF_TestCase {

    private static $sa_key;
    private $client;
    private $sent = [];
    private $dir;

    public static function wpSetUpBeforeClass($factory){
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), self::$sa_key);
    }

    public function set_up(){
        parent::set_up();
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
        update_option(MAD_Oficina_Core::option_key('chat'), ['manager_email' => 'jessica@imad.example', 'manager_dm' => 'spaces/JESSDM',
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => self::$sa_key, 'token_uri' => 'https://oauth2.googleapis.com/token']))]);
        delete_transient('madof_chat_token_' . md5('bot@proj.iam.gserviceaccount.com'));
        delete_option(Bank::RULES); delete_option(Collections::ASKED); delete_option(Collections::DAY);
        $this->client = $this->make_client('LCS', 'Luz Clara');
        $this->dir = get_temp_dir() . 'madof-bank-' . wp_generate_password(6, false);
        wp_mkdir_p($this->dir);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        if ( $url === 'https://oauth2.googleapis.com/token' ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 't', 'expires_in' => 3600])];
        if ( strpos($url, 'https://chat.googleapis.com/v1/') === 0 ) {
            $this->sent[] = json_decode($args['body'] ?? '{}', true);
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['name' => 'spaces/JESSDM/messages/' . count($this->sent)])];
        }
        return $pre;
    }

    private function file($name, $content){
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function invoice(array $row){
        global $wpdb;
        static $seq = 0; $seq++;
        $wpdb->insert(Invoices::table(), $row + ['series' => 'F', 'number' => 'F-2026-0' . (10 + $seq), 'seq' => $seq, 'year' => 2026, 'issue_date' => '2026-02-01', 'client_id' => $this->client,
            'client_name' => 'Luz Clara SL', 'client_email' => 'pagos@luzclara.example', 'status' => 'sent', 'total' => 121, 'base' => 100, 'vat' => 21, 'paid' => 0, 'created_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-02-01 00:00:00']);
        return (int) $wpdb->insert_id;
    }

    private function inv($id){ return Invoices::get($id); }

    /* ───────── reading statements ───────── */

    public function test_csv_with_bank_header_lines_and_debit_credit_columns(){
        $csv = "Movimientos de la cuenta ES00 0000 0000 0000\nDesde 01/01/2026 hasta 31/03/2026\n\n"
            . "F. Operación;F. Valor;Concepto;Cargo;Abono;Saldo\n"
            . "03/02/2026;03/02/2026;TRANSF. DE LUZ CLARA SL FRA F-2026-011;;121,00;1.121,00\n"
            . "05/02/2026;05/02/2026;RECIBO ADOBE SYSTEMS;24,19;;1.096,81\n"
            . ";;Saldo final;;;1.096,81\n";
        $st = BankStatement::read($this->file('extracto.csv', mb_convert_encoding($csv, 'Windows-1252', 'UTF-8')), 'extracto.csv');
        $this->assertCount(2, $st['rows'], 'totals and header lines are skipped');
        $this->assertSame(['2026-02-03', 121.0, 'TRANSF. DE LUZ CLARA SL FRA F-2026-011'], [$st['rows'][0]['date'], $st['rows'][0]['amount'], $st['rows'][0]['concept']]);
        $this->assertSame(-24.19, $st['rows'][1]['amount']);
        $this->assertSame(1096.81, $st['rows'][1]['balance']);
    }

    public function test_xlsx_with_shared_strings_and_excel_dates(){
        $path = $this->dir . '/extracto.xlsx';
        $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Fecha</t></si><si><t>Concepto</t></si><si><t>Importe</t></si><si><t>Pago Google Ads</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
            . '<row r="2"><c r="A2"><v>46056</v></c><c r="B2" t="s"><v>3</v></c><c r="C2"><v>-50.5</v></c></row></sheetData></worksheet>');
        $zip->close();
        $st = BankStatement::read($path, 'extracto.xlsx');
        $this->assertSame([['date' => '2026-02-03', 'value_date' => null, 'concept' => 'Pago Google Ads', 'amount' => -50.5, 'balance' => null]], $st['rows']);
    }

    public function test_old_binary_xls_like_santanders(){
        // Invented statement with the same shape as a Santander export (title lines, IBAN, then the table).
        $st = BankStatement::read(dirname(__DIR__) . '/fixtures/banco-inventado.xls', 'transactions.xls');
        $this->assertIsArray($st, is_wp_error($st) ? $st->get_error_message() : '');
        $this->assertSame('…1234', $st['account'], 'the account comes from the lines above the table');
        $this->assertCount(3, $st['rows']);
        $this->assertSame(['2026-10-14', 121.0, 1000.0], [$st['rows'][0]['date'], $st['rows'][0]['amount'], $st['rows'][0]['balance']]);
        $this->assertStringContainsString('ñandú', $st['rows'][0]['concept']);
        $this->assertSame(-24.19, $st['rows'][1]['amount']);

        $a = $this->invoice(['total' => 121, 'issue_date' => '2026-10-01']);
        $this->assertSame(1, Bank::match(Bank::import($st)['ids'])['matched']);
        $this->assertSame(1, (int) $this->inv($a)['paid']);
    }

    public function test_norma_43_and_html_xls(){
        $n43 = "11" . str_repeat('0', 8) . "0000001234" . str_repeat(' ', 60) . "\n"
            . '22' . '    ' . '0000' . '260203' . '260203' . '02' . '000' . '2' . '00000000012100' . '0000000000' . str_pad('LUZCLARA', 12) . str_pad('F-2026-011', 16) . "\n"
            . "2301TRANSFERENCIA DE LUZ CLARA SL" . str_repeat(' ', 9) . str_repeat(' ', 38) . "\n";
        $st = BankStatement::read($this->file('cuenta.n43', $n43), 'cuenta.n43');
        $this->assertSame('2026-02-03', $st['rows'][0]['date']);
        $this->assertSame(121.0, $st['rows'][0]['amount'], 'D/H 2 = abono');
        $this->assertStringContainsString('TRANSFERENCIA DE LUZ CLARA', $st['rows'][0]['concept']);

        $html = '<html><body><table><tr><td>Fecha</td><td>Descripción</td><td>Importe</td></tr><tr><td>10/02/2026</td><td>Comisión mantenimiento</td><td>-6,00</td></tr></table></body></html>';
        $st = BankStatement::read($this->file('movs.xls', $html), 'movs.xls');
        $this->assertSame(-6.0, $st['rows'][0]['amount']);
        $this->assertWPError(BankStatement::read($this->file('raro.csv', "a;b;c\n1;2;3\n"), 'raro.csv'), 'unknown columns are explained, not guessed');
    }

    /* ───────── matching ───────── */

    public function test_money_in_marks_the_invoice_collected_silently_and_overlaps_do_not_duplicate(){
        $a = $this->invoice(['total' => 121, 'issue_date' => '2026-01-31']);
        $b = $this->invoice(['total' => 300, 'issue_date' => '2026-01-31']);
        $c = $this->invoice(['total' => 200, 'issue_date' => '2026-01-31']);
        $st = ['account' => '', 'rows' => [
            ['date' => '2026-02-03', 'value_date' => null, 'concept' => 'TRANSF LUZ CLARA SL F-2026-011', 'amount' => 121.0, 'balance' => null],
            ['date' => '2026-02-10', 'value_date' => null, 'concept' => 'TRANSFERENCIA LUZ CLARA FRAS FEBRERO', 'amount' => 500.0, 'balance' => null],
        ]];
        $imp = Bank::import($st);
        $m = Bank::match($imp['ids']);
        $this->assertSame(['matched' => 2, 'ignored' => 0, 'unmatched' => 0], $m);
        $this->assertSame(['1', '2026-02-03 12:00:00'], [(string) $this->inv($a)['paid'], $this->inv($a)['paid_at']], 'collected on the bank date');
        $this->assertSame([1, 1], [(int) $this->inv($b)['paid'], (int) $this->inv($c)['paid']], 'one transfer, two invoices of the same client');
        $this->assertSame(['new' => 0, 'repeated' => 2], array_slice(Bank::import($st), 0, 2), 'uploading again duplicates nothing');

        // «Deshacer» puts the invoice back to unpaid.
        Bank::unlink((int) $GLOBALS['wpdb']->get_var('SELECT id FROM ' . Bank::table() . ' WHERE amount = 121'));
        $this->assertSame(0, (int) $this->inv($a)['paid']);
    }

    public function test_money_out_with_supplier_invoices_taxes_rules_and_what_is_missing(){
        global $wpdb;
        $exp = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-02-01', 'supplier_name' => 'Adobe Systems', 'total' => 24.19, 'currency' => 'EUR']);
        $this->insert('suppliers', ['name' => 'Canva', 'status' => 'active']);
        $tax = $this->insert('taxes', ['kind' => 'autonomos', 'period' => '2026-02', 'paid_on' => '2026-02-28', 'amount' => 294.0]);
        $imp = Bank::import(['account' => '', 'rows' => [
            ['date' => '2026-02-05', 'value_date' => null, 'concept' => 'RECIBO ADOBE SYSTEMS', 'amount' => -24.19, 'balance' => null],
            ['date' => '2026-02-28', 'value_date' => null, 'concept' => 'TGSS COTIZACION 022026', 'amount' => -294.0, 'balance' => null],
            ['date' => '2026-02-12', 'value_date' => null, 'concept' => 'CANVA* 0233 PRO', 'amount' => -11.99, 'balance' => null],
            ['date' => '2026-02-28', 'value_date' => null, 'concept' => 'COMISION MANTENIMIENTO CUENTA', 'amount' => -6.0, 'balance' => null],
        ]]);
        Bank::match($imp['ids']);
        $rows = $wpdb->get_results('SELECT concept, status, match_type, match_ids, note FROM ' . Bank::table() . ' ORDER BY id', ARRAY_A);
        $this->assertSame(['matched', 'expense', (string) $exp], [$rows[0]['status'], $rows[0]['match_type'], $rows[0]['match_ids']]);
        $this->assertSame(['matched', 'tax', (string) $tax], [$rows[1]['status'], $rows[1]['match_type'], $rows[1]['match_ids']]);
        $this->assertSame('unmatched', $rows[2]['status']);
        $this->assertStringContainsString('Parece de Canva: falta su factura', $rows[2]['note']);

        // She says the fee needs no invoice and to remember it: next time it settles itself.
        $fee = (int) $wpdb->get_var('SELECT id FROM ' . Bank::table() . " WHERE concept LIKE 'COMISION%'");
        Bank::ignore($fee, 'no_invoice', 'comision mantenimiento', 'Comisión del banco');
        $next = Bank::import(['account' => '', 'rows' => [['date' => '2026-03-31', 'value_date' => null, 'concept' => 'COMISION MANTENIMIENTO CUENTA', 'amount' => -6.0, 'balance' => null]]]);
        $this->assertSame(['matched' => 0, 'ignored' => 1, 'unmatched' => 0], Bank::match($next['ids']));
        $this->assertSame([], array_column(Bank::unpaid_expenses(), 'id'), 'the Adobe one was paid');
    }

    public function test_one_charge_several_receipts_and_one_invoice_paid_in_instalments(){
        global $wpdb;
        // Three receipts of the same supplier, one card charge.
        $r1 = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-02', 'supplier_name' => 'Imprenta Inventada', 'total' => 40.0, 'currency' => 'EUR']);
        $r2 = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-03', 'supplier_name' => 'Imprenta Inventada', 'total' => 25.5, 'currency' => 'EUR']);
        $r3 = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-04', 'supplier_name' => 'Imprenta Inventada', 'total' => 10.0, 'currency' => 'EUR']);
        // A client invoice paid in two parts.
        $inv = $this->invoice(['total' => 1000, 'issue_date' => '2026-03-01']);
        $imp = Bank::import(['account' => '', 'rows' => [
            ['date' => '2026-03-06', 'value_date' => null, 'concept' => 'Pago con tarjeta IMPRENTA INVENTADA', 'amount' => -75.5, 'balance' => null],
            ['date' => '2026-03-10', 'value_date' => null, 'concept' => 'Transferencia LUZ CLARA SL primer pago', 'amount' => 400.0, 'balance' => null],
        ]]);
        $this->assertSame(['matched' => 2, 'ignored' => 0, 'unmatched' => 0], Bank::match($imp['ids']));
        $card = $wpdb->get_row('SELECT match_type, match_ids FROM ' . Bank::table() . ' WHERE amount = -75.5', ARRAY_A);
        $this->assertSame('expense', $card['match_type']);
        $this->assertEqualsCanonicalizing([$r1, $r2, $r3], array_map('intval', explode(',', $card['match_ids'])), 'the three receipts go with the one charge');
        $this->assertSame([], Bank::unpaid_expenses());

        $this->assertSame(0, (int) $this->inv($inv)['paid'], 'only part of it: not collected yet');
        $this->assertStringContainsString('faltan 600,00', $wpdb->get_var('SELECT note FROM ' . Bank::table() . ' WHERE amount = 400'));
        $this->assertSame(600.0, Bank::uncollected()[0]['left']);

        // The rest arrives in two more transfers that do not name the client: linked together by hand.
        $more = Bank::import(['account' => '', 'rows' => [
            ['date' => '2026-04-10', 'value_date' => null, 'concept' => 'Transferencia recibida', 'amount' => 300.0, 'balance' => null],
            ['date' => '2026-05-10', 'value_date' => null, 'concept' => 'Transferencia recibida 2', 'amount' => 300.0, 'balance' => null],
        ]]);
        Bank::match($more['ids']);
        $this->assertContains('invoice:' . $inv, array_map(fn($c) => $c['type'] . ':' . $c['id'], Bank::open_items('in')), 'still open, with what is left');
        $this->assertSame(2, Bank::link_many($more['ids'], 'invoice', $inv));
        $this->assertSame(['1', '2026-05-10 12:00:00'], [(string) $this->inv($inv)['paid'], $this->inv($inv)['paid_at']], 'collected when the last part arrives');
        $this->assertNotContains('invoice:' . $inv, array_map(fn($c) => $c['type'] . ':' . $c['id'], Bank::open_items('in')));

        // Undoing one part: no longer fully collected.
        Bank::unlink($more['ids'][0]);
        $this->assertSame(0, (int) $this->inv($inv)['paid']);
    }

    public function test_own_transfers_in_bulk_and_by_rule(){
        $imp = Bank::import(['account' => '', 'rows' => [
            ['date' => '2026-03-01', 'value_date' => null, 'concept' => 'Transferencia a favor de Ana Prueba Gómez', 'amount' => -500.0, 'balance' => null],
            ['date' => '2026-03-05', 'value_date' => null, 'concept' => 'Transferencia de ANA PRUEBA GOMEZ', 'amount' => 300.0, 'balance' => null],
            ['date' => '2026-03-06', 'value_date' => null, 'concept' => 'Pago con tarjeta en Papelería', 'amount' => -8.0, 'balance' => null],
        ]]);
        Bank::match($imp['ids']);
        $found = Bank::movements('unmatched', 300, 'ana prueba gomez');
        $this->assertCount(2, $found, 'the search ignores accents and case');
        $this->assertCount(1, Bank::movements('unmatched', 300, 'ana prueba', 'in'));

        $this->assertSame(2, Bank::ignore_many(array_column($found, 'id'), 'own', 'Mis cuentas', 'ana prueba gomez'));
        $this->assertCount(1, Bank::movements('unmatched'), 'only the card payment is left');
        $next = Bank::import(['account' => '', 'rows' => [['date' => '2026-04-02', 'value_date' => null, 'concept' => 'TRANSFERENCIA A FAVOR DE ANA PRUEBA GÓMEZ', 'amount' => -200.0, 'balance' => null]]]);
        $this->assertSame(1, Bank::match($next['ids'])['ignored'], 'next statements: settled by the rule');
    }

    /* ───────── Amparo's shifts and Friday report ───────── */

    public function test_waiting_supplier_invoices_that_pass_the_checks_register_themselves(){
        $known = \MAD_Oficina\Suppliers\Suppliers::save(['name' => 'Hosting Inventado', 'tax_id' => 'B11111111', 'usual_amount' => '12,10']);
        $this->assertIsInt($known);
        // 1. «¿Proveedor nuevo?» — but she gave it a card meanwhile.
        $new = $this->insert('expenses', ['status' => 'review', 'reason' => 'supplier', 'supplier_name' => 'Hosting Inventado', 'supplier_tax_id' => 'B11111111', 'issue_date' => '2026-10-01', 'total' => 12.10, 'currency' => 'EUR']);
        $a1 = Approvals::create('reader', 'supplier_new', 'Proveedor nuevo', ['expense_id' => $new], ['object_type' => 'expense', 'object_id' => $new]);
        // 2. «¿Es tuya?» from a supplier that always invoices her, usual amount.
        foreach ( [1, 2] as $i ) $this->insert('expenses', ['status' => 'registered', 'supplier_id' => $known, 'supplier_name' => 'Hosting Inventado', 'issue_date' => "2026-0{$i}-01", 'total' => 12.10, 'currency' => 'EUR']);
        $mine = $this->insert('expenses', ['status' => 'review', 'reason' => 'recipient', 'supplier_id' => $known, 'supplier_name' => 'Hosting Inventado', 'issue_date' => '2026-10-02', 'total' => 12.10, 'currency' => 'EUR']);
        $a2 = Approvals::create('reader', 'expense_review', '¿Es tuya?', ['expense_id' => $mine], ['object_type' => 'expense', 'object_id' => $mine]);
        // 3. Same, but three times the usual amount: stays for her.
        $odd = $this->insert('expenses', ['status' => 'review', 'reason' => 'recipient', 'supplier_id' => $known, 'supplier_name' => 'Hosting Inventado', 'issue_date' => '2026-10-03', 'total' => 36.30, 'currency' => 'EUR']);
        $a3 = Approvals::create('reader', 'expense_review', '¿Es tuya?', ['expense_id' => $odd], ['object_type' => 'expense', 'object_id' => $odd]);
        // 4. Truly unknown supplier: stays for her.
        $unknown = $this->insert('expenses', ['status' => 'review', 'reason' => 'supplier', 'supplier_name' => 'Nadie Conocido', 'issue_date' => '2026-10-04', 'total' => 5, 'currency' => 'EUR']);
        $a4 = Approvals::create('reader', 'supplier_new', 'Proveedor nuevo', ['expense_id' => $unknown], ['object_type' => 'expense', 'object_id' => $unknown]);

        $this->assertSame(2, (new \MAD_Oficina\Billing\Shifts([]))->review_supplier_invoices());
        $this->assertSame([Approvals::APPROVED, Approvals::APPROVED, Approvals::PENDING, Approvals::PENDING], array_map(fn($id) => Approvals::get($id)['status'], [$a1, $a2, $a3, $a4]));
        $this->assertSame('registered', \MAD_Oficina\Suppliers\Expenses::get($mine)['status']);
        $this->assertSame('auto', Approvals::get($a2)['channel']);
    }

    public function test_friday_report_once_with_what_is_missing_and_links(){
        $this->invoice(['total' => 1210, 'due_date' => '2026-10-01', 'client_name' => 'Luz Clara SL']);
        $imp = Bank::import(['account' => '', 'rows' => [['date' => '2026-10-10', 'value_date' => null, 'concept' => 'Cargo misterioso', 'amount' => -9.99, 'balance' => null]]]);
        Bank::match($imp['ids']);
        delete_option(\MAD_Oficina\Billing\Shifts::REPORT_DAY); delete_option(\MAD_Oficina\Billing\Shifts::DAY);
        $shifts = new \MAD_Oficina\Billing\Shifts([]);
        $thu = (new DateTimeImmutable('2026-10-15 13:00', wp_timezone()))->getTimestamp();
        $fri_morning = (new DateTimeImmutable('2026-10-16 10:00', wp_timezone()))->getTimestamp();
        $fri_noon = (new DateTimeImmutable('2026-10-16 12:05', wp_timezone()))->getTimestamp();
        $this->assertArrayNotHasKey('report', $shifts->run($thu));
        $this->assertArrayNotHasKey('report', $shifts->run($fri_morning));
        $this->assertTrue($shifts->run($fri_noon)['report']);
        $text = end($this->sent)['text'];
        $this->assertStringContainsString('tu semana en Finanzas', $text);
        $this->assertStringContainsString('1 movimiento del banco sin cuadrar', $text);
        $this->assertStringContainsString('tab=bank', $text, 'with the link');
        $this->assertStringContainsString('Luz Clara SL debe 1.210,00', $text);
        $this->assertStringContainsString('Trimestre 4', $text);
        $n = count($this->sent);
        $shifts->run($fri_noon + 3600);
        $this->assertCount($n, $this->sent, 'once per Friday');
    }

    /* ───────── cobros ───────── */

    public function test_due_and_not_collected_asks_jessica_once_a_week_argentina_included(){
        $due = $this->invoice(['total' => 121, 'issue_date' => '2026-02-01', 'due_date' => '2026-02-16']);
        $later = $this->invoice(['total' => 50, 'issue_date' => '2026-02-10', 'due_date' => '2026-03-10']);
        $ar = $this->invoice(['series' => 'AR', 'number' => null, 'currency' => 'USD', 'total' => 900, 'issue_date' => '2026-01-20', 'due_date' => null]);
        $col = new Collections(['payment_days' => 15]);
        $this->assertSame(2, $col->run('2026-02-16'));
        $texts = wp_json_encode($this->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('vence hoy y no consta cobrada', $texts);
        $this->assertStringContainsString('de Argentina', $texts);
        $this->assertStringContainsString('collect_remind', $texts);
        $this->assertStringNotContainsString('50,00', $texts, 'not due yet');

        delete_option(Collections::DAY);
        $this->assertSame(0, $col->run('2026-02-17'), 'once, then again a week later');
        delete_option(Collections::DAY);
        $this->assertSame(2, $col->run('2026-02-23'));

        // «Ya está cobrada» from Chat.
        Collections::chat_action(null, 'collect_paid', ['invoice_id' => $due], 'jessica@imad.example', true);
        $this->assertSame(1, (int) $this->inv($due)['paid']);
        delete_option(Collections::DAY);
        $this->assertSame(1, $col->run('2026-03-02'), 'collected ones stop asking (only Argentina left)');
    }

    public function test_the_friendly_reminder_waits_for_her_ok_and_goes_from_her_gmail(){
        update_option(\MAD_Oficina\Core\ClientCopy::OPTION, ['on' => true, 'template' => 'yo+c.{cliente}@agencia.example']);
        $id = $this->invoice(['total' => 121, 'due_date' => '2026-02-16']);
        $this->assertStringContainsString('bandeja', wp_json_encode(Collections::chat_action(null, 'collect_remind', ['invoice_id' => $id], 'jessica@imad.example', true), JSON_UNESCAPED_UNICODE));
        $this->assertSame('Solo Jessica puede decidir sobre los cobros.', Collections::chat_action(null, 'collect_remind', ['invoice_id' => $id], 'liz@imad.example', false));
        $approval = array_values(array_filter(Approvals::for_object('invoice', $id), fn($a) => $a['action_type'] === Collections::ACTION))[0];
        $this->assertSame(Approvals::PENDING, $approval['status']);
        $this->assertContains(Collections::ACTION, \MAD_Oficina\Core\FinanceAccess::types(), 'a money matter: hidden from who does not see Finanzas');

        Approvals::resolve($approval['id'], 'approve', [], 1, 'chat');
        $order = json_decode($GLOBALS['wpdb']->get_var("SELECT payload FROM " . Schema::table('tasks') . " WHERE type = 'payment_reminder'"), true);
        $this->assertSame(['pagos@luzclara.example', 'yo+c.luz-clara@agencia.example', $id], [$order['email']['to'], $order['email']['bcc'], $order['invoice_id']]);
        $this->assertStringContainsString('pendiente de pago', $order['email']['subject']);
        delete_option(\MAD_Oficina\Core\ClientCopy::OPTION);
    }

    public function test_vincular_suggests_whoever_the_concept_names_and_searches_everything(){
        for ( $n = 0; $n < 20; $n++ ) $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-01', 'supplier_name' => 'Otro Proveedor ' . $n, 'concept' => 'Varios', 'total' => 50 + $n / 100, 'currency' => 'EUR']);
        $generic = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-02-21', 'supplier_name' => 'Nube Hosting SL', 'concept' => 'Hosting', 'total' => 49.0, 'currency' => 'EUR']);
        $named = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-02-20', 'supplier_name' => 'Hostinger International', 'concept' => 'Plan Business 48 meses', 'total' => 187.20, 'currency' => 'EUR']);
        $m = ['amount' => -50.0, 'date' => '2026-03-02', 'concept' => 'PAGO TARJETA HOSTINGER* HOSTING'];
        $c = Bank::candidates($m);
        $this->assertSame([$named, $generic], [$c[0]['id'], $c[1]['id']], 'the brand the bank names comes first, even with another amount; a generic word next');
        $this->assertCount(15, $c);
        $this->assertNotContains($named, array_column(Bank::candidates(['amount' => -50.0, 'date' => '2026-03-02', 'concept' => 'TRANSFERENCIA 0042']), 'id'), 'without a name it falls out of the suggestions…');

        $all = Bank::searchable();
        $this->assertCount(22, $all['out'], '…but the search box sees every open one, not only the suggested');
        $h = array_values(array_filter($all['out'], fn($i) => $i['v'] === 'expense:' . $named))[0];
        foreach ( ['hostinger', 'business', '187,20', '187.20'] as $w ) $this->assertStringContainsString($w, $h['s']);
        $this->assertStringContainsString('Plan Business', $h['l'], 'the label shows what it was for');
    }

    public function test_link_all_the_chosen_ones_at_once(){
        global $wpdb;
        wp_set_current_user($this->make_admin());
        $a = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-01', 'supplier_name' => 'Papel Uno', 'total' => 10.0, 'currency' => 'EUR']);
        $b = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-01', 'supplier_name' => 'Papel Dos', 'total' => 5.0, 'currency' => 'EUR']);
        $c = $this->insert('expenses', ['status' => 'registered', 'issue_date' => '2026-03-01', 'supplier_name' => 'Papel Tres', 'total' => 7.0, 'currency' => 'EUR']);
        $inv = $this->invoice(['total' => 30]);
        $imp = Bank::import(['account' => '', 'rows' => [
            ['date' => '2026-03-02', 'value_date' => null, 'concept' => 'COMPRA 1', 'amount' => -10.0, 'balance' => null],
            ['date' => '2026-03-03', 'value_date' => null, 'concept' => 'COMPRA 2', 'amount' => -12.0, 'balance' => null],
            ['date' => '2026-03-04', 'value_date' => null, 'concept' => 'COMPRA 3', 'amount' => -3.0, 'balance' => null],
        ]]);
        [$m1, $m2, $m3] = $imp['ids'];
        $_POST = $_REQUEST = ['do' => 'link_all', '_wpnonce' => wp_create_nonce('madof_billing_bank_action'), 'pairs' => [
            $m1 => ['expense:' . $a], $m2 => ['expense:' . $b, 'expense:' . $c], $m3 => ['expense:' . $c, 'invoice:' . $inv], // the last one mixes: refused alone
        ]];
        $stop = function(){ throw new \RuntimeException('redirect'); };
        add_filter('wp_redirect', $stop);
        try { MAD_Oficina_Core::instance()->get_module('billing')->handle_billing_bank_action(); } catch ( \RuntimeException $e ) {}
        remove_filter('wp_redirect', $stop);
        $_POST = $_REQUEST = [];
        $st = fn($id) => $wpdb->get_row($wpdb->prepare('SELECT status, match_type, match_ids FROM ' . Bank::table() . ' WHERE id = %d', $id), ARRAY_A);
        $this->assertSame(['matched', 'expense', (string) $a], array_values($st($m1)));
        $this->assertSame(['matched', 'expense', $b . ',' . $c], array_values($st($m2)), 'one charge, two receipts');
        $this->assertSame('unmatched', $st($m3)['status']);
        $notice = get_transient('madof_notice_' . get_current_user_id());
        $this->assertSame('error', $notice['type']);
        $this->assertStringContainsString('2 movimientos vinculados', $notice['text']);
        $this->assertStringContainsString('COMPRA 3: Marca solo', $notice['text'], 'and says which one and why');
    }
}
