<?php
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\InvoicePdf;
use MAD_Oficina\Billing\Issuing;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Meetings\Orders;

/**
 * Issuing invoices: draft → approval → number → cartero order. Invented data only.
 */
class IssuingTest extends MADOF_TestCase {

    private $client; private $profile;

    public function set_up(){
        parent::set_up();
        update_option(MAD_Oficina_Core::option_key('billing'), ['issuer_name' => 'Ana Prueba', 'issuer_iban' => 'ES00 0000', 'billing_day' => 28, 'payment_days' => 15]);
        $this->client = $this->make_client('TAZ', 'Taller Azul');
        $this->profile = Profiles::save(['legal_name' => 'Taller Azul SL', 'tax_id' => 'B11111111', 'email' => 'pagos@taz.example', 'client_id' => $this->client]);
        wp_set_current_user($this->make_admin());
        // An invoice already issued this year: 26-007 on 30 Sep.
        Invoices::insert(['series' => 'F', 'number' => '26-007', 'year' => 2026, 'seq' => 7, 'status' => 'sent', 'issue_date' => '2026-09-30', 'client_name' => 'Otro', 'base' => 1, 'total' => 1], [['description' => 'x', 'amount' => 1]]);
    }

    private function issuing(array $extra = []){
        return new Issuing(get_option(MAD_Oficina_Core::option_key('billing')) + $extra);
    }

    private function draft(array $data = []){
        $id = $this->issuing()->save_draft($data + ['profile_id' => $this->profile, 'issue_date' => '2026-10-28'], [
            ['description' => 'Gestión de redes · octubre', 'quantity' => '1', 'unit_price' => '400,00'],
            ['description' => 'Banner', 'quantity' => '2', 'unit_price' => '25,5'],
        ]);
        $this->assertIsInt($id);
        return $id;
    }

    private function orders($type){
        return array_values(array_filter(Orders::pending(), fn($o) => $o['type'] === $type));
    }

    public function test_billing_day_and_due_date(){
        $i = $this->issuing();
        $this->assertSame('2026-10-28', $i->default_issue_date(null, '2026-10-05'));
        $this->assertSame('2026-02-28', $i->default_issue_date(['billing_day' => 31], '2026-02-10'), 'shorter month: its last day');
        $this->assertSame('2026-11-15', $i->default_issue_date(['billing_day' => 15], '2026-11-02'), 'a client with its own day (like DT)');
        $this->assertSame('2026-11-12', $i->due_date('2026-10-28', null));
        $this->assertSame('2026-11-27', $i->due_date('2026-10-28', ['payment_days' => 30]));
    }

    public function test_draft_with_several_lines(){
        $inv = Invoices::get($this->draft());
        $this->assertCount(2, $inv['lines']);
        $this->assertSame(451.0, $inv['base']);
        $this->assertSame(94.71, $inv['vat']);
        $this->assertSame(67.65, $inv['irpf']);
        $this->assertSame(478.06, $inv['total']);
        $half = $this->issuing()->save_draft(['profile_id' => $this->profile], [['description' => 'x', 'unit_price' => '700,50']]);
        $this->assertSame(147.11, Invoices::get($half)['vat'], 'half up: 147,105 → 147,11 (the editor rounds the same way)');
        $this->assertSame('2026-11-12', $inv['due_date']);
        $this->assertSame($this->client, $inv['client_id']);
        $this->assertNull($inv['number'], 'drafts have no number');
        $this->assertWPError($this->issuing()->save_draft(['profile_id' => $this->profile], [['description' => '', 'unit_price' => '']]));
    }

    public function test_approval_numbers_freezes_issuer_and_queues_the_cartero(){
        $id = $this->draft();
        $approval = $this->issuing()->request($id);
        $this->assertIsInt($approval);
        $this->assertSame($approval, $this->issuing()->request($id), 'one pending approval per draft');
        $this->assertSame(['send' => '1', 'issue_date' => '2026-10-28', 'to' => 'pagos@taz.example', 'subject' => '', 'body' => ''], $this->issuing()->default_input(Approvals::get($approval)));

        $result = Approvals::resolve($approval, 'approve', ['send' => '1', 'issue_date' => '2026-10-28', 'to' => 'pagos@taz.example', 'subject' => 'Factura {numero}', 'body' => 'Hola, va la {numero}.'], get_current_user_id());
        $this->assertNotWPError($result);
        $inv = Invoices::get($id);
        $this->assertSame('26-008', $inv['number']);
        $this->assertSame('issued', $inv['status']);
        $this->assertNotEmpty($inv['issued_at']);

        $order = $this->orders('invoice_archive')[0]['payload'];
        $this->assertSame(['2026', 'T4'], $order['folder']);
        $this->assertSame('Factura 26-008', $order['email']['subject']);
        $this->assertSame('Hola, va la 26-008.', $order['email']['body']);
        $this->assertSame('26-008', $order['row']['Número']);
        $this->assertSame('TAZ', $order['row']['Código']);
        $mail = $this->issuing()->email_for($inv = Invoices::get($id), '2026-10-28', '26-008');
        $this->assertStringContainsString('26-008 de octubre', $mail['body']);
        $this->assertStringContainsString('vencimiento el 12 de noviembre', $mail['body']);

        // Changing the issuer data later does not change an issued invoice.
        update_option(MAD_Oficina_Core::option_key('billing'), ['issuer_name' => 'Otra Persona']);
        $this->assertSame('Ana Prueba', Issuing::issuer_for($inv, ['issuer_name' => 'Otra Persona'])['issuer_name']);
        $this->assertStringContainsString('Ana Prueba', InvoicePdf::html($inv, Issuing::issuer_for($inv, [])));

        // The cartero reports back: archived and sent.
        Orders::complete($this->orders('invoice_archive')[0]['id'], true, ['file_id' => 'abc_123', 'url' => 'https://drive.google.com/file/d/abc_123/view', 'sent' => true]);
        $inv = Invoices::get($id);
        $this->assertSame('sent', $inv['status']);
        $this->assertSame('abc_123', $inv['pdf_file_id']);
        $this->assertSame('https://drive.google.com/file/d/abc_123/view', $inv['pdf_url']);
        $this->assertWPError($this->issuing()->save_draft(['profile_id' => $this->profile], [['description' => 'x', 'unit_price' => '1']], $id), 'issued invoices cannot be edited');
    }

    public function test_numbers_follow_dates_and_rejections_leave_no_gaps(){
        $id = $this->draft(['issue_date' => '2026-09-01']);
        $approval = $this->issuing()->request($id);
        $r = Approvals::resolve($approval, 'approve', ['send' => '', 'issue_date' => '2026-09-01'], get_current_user_id());
        $this->assertWPError($r, 'dated before 26-007 (30 Sep)');
        $this->assertSame(Approvals::PENDING, Approvals::get($approval)['status']);
        $this->assertNull(Invoices::get($id)['number']);

        Approvals::resolve($approval, 'reject');
        $other = $this->draft();
        $a2 = $this->issuing()->request($other);
        Approvals::resolve($a2, 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $this->assertSame('26-008', Invoices::get($other)['number'], 'the discarded draft took no number');
        $this->assertNull($this->orders('invoice_archive')[0]['payload']['email'], '«Solo emitir»: archived, not sent');
    }

    public function test_sending_needs_an_address_and_a_tax_id(){
        $id = $this->draft();
        $approval = $this->issuing()->request($id);
        $this->assertWPError(Approvals::resolve($approval, 'approve', ['send' => '1', 'to' => 'no-es-un-email'], get_current_user_id()));
        $this->assertNull(Invoices::get($id)['number']);

        $p = Profiles::save(['legal_name' => 'Sin NIF', 'client_id' => $this->client]);
        $d = $this->issuing()->save_draft(['profile_id' => $p], [['description' => 'x', 'unit_price' => '10']]);
        $this->assertWPError($this->issuing()->request($d));
    }

    public function test_rectifying_invoice(){
        $id = $this->draft();
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $r = $this->issuing()->rectify($id);
        $this->assertIsInt($r);
        $rect = Invoices::get($r);
        $this->assertSame('R', $rect['series']);
        $this->assertSame($id, $rect['rectifies_id']);
        $this->assertSame(-478.06, $rect['total']);
        Approvals::resolve($this->issuing()->request($r), 'approve', ['send' => '', 'issue_date' => current_time('Y-m-d')], get_current_user_id());
        $this->assertSame('R-' . substr(current_time('Y'), 2) . '-01', Invoices::get($r)['number']);
    }

    public function test_new_fiscal_details_cancel_the_issued_invoice_and_reach_the_drafts(){
        $id = $this->draft();
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $pending = $this->draft(['issue_date' => '2026-11-28']);
        $this->assertWPError($this->issuing()->replace_for_new_details($id), 'nothing changed in the fiscal details yet');

        Profiles::save(['legal_name' => 'Taller Azul Nuevo SL', 'tax_id' => 'B22222222', 'email' => 'pagos@taz.example', 'client_id' => $this->client], $this->profile);
        $this->assertSame(1, Issuing::refresh_drafts($this->profile));
        $this->assertSame(['Taller Azul Nuevo SL', 'B22222222'], [Invoices::get($pending)['client_name'], Invoices::get($pending)['client_tax_id']], 'the next draft takes the new details');
        $this->assertSame('B11111111', Invoices::get($id)['client_tax_id'], 'an issued invoice is never touched');

        $r = $this->issuing()->replace_for_new_details($id);
        $this->assertIsArray($r);
        $rect = Invoices::get($r['rectify']);
        $new = Invoices::get($r['new']);
        $this->assertSame(['R', $id, -478.06, 'B11111111'], [$rect['series'], $rect['rectifies_id'], $rect['total'], $rect['client_tax_id']], 'the rectifying one cancels it, to the old details');
        $this->assertSame(['F', 478.06, 'B22222222', 'draft'], [$new['series'], $new['total'], $new['client_tax_id'], $new['status']], 'the new one: same concepts, new details, waiting for «Emitir»');
        $this->assertStringContainsString(Invoices::get($id)['number'], $new['notes']);
        $this->assertGreaterThanOrEqual(Invoices::get($id)['issue_date'], $rect['issue_date'], 'never dated before the invoice it cancels');
        $this->assertSame([(int) $r['rectify'], (int) $r['new']], [(int) Issuing::replacement_of($id)['rectify']['id'], (int) Issuing::replacement_of($r['new'])['new']['id']], 'the three are linked from any of them');
        $this->assertEquals($r + ['existing' => true], $this->issuing()->replace_for_new_details($id), 'pressed again: the same two drafts');

        // Editing the rectifying draft keeps the original's details; refreshing drafts leaves it alone.
        Issuing::refresh_drafts($this->profile);
        $this->issuing()->save_draft(['profile_id' => $this->profile], [['description' => 'x', 'unit_price' => '-10']], $r['rectify']);
        $this->assertSame('B11111111', Invoices::get($r['rectify'])['client_tax_id']);
    }

    private function legacy_invoice(array $over = []){
        return Invoices::insert($over + ['series' => 'F', 'number' => '26-019', 'year' => 2026, 'seq' => 19, 'issue_date' => '2026-02-10', 'profile_id' => $this->profile,
            'client_name' => 'Taller Azul SL', 'client_tax_id' => 'B11111111', 'base' => 300, 'vat' => 63, 'irpf' => 45, 'total' => 318, 'vat_rate' => 0, 'irpf_rate' => 0,
            'status' => 'sent', 'source' => 'import', 'legacy' => wp_json_encode(['concepto_1' => 'Gestión redes febrero', 'valor_1' => '', 'base' => '300'])], []);
    }

    public function test_an_invoice_imported_without_lines_is_redone_with_its_sheet_concept_and_rates(){
        $id = $this->legacy_invoice();
        $this->assertWPError($this->issuing()->replace_for_new_details($id), 'same details: explained, not silent');
        Profiles::save(['legal_name' => 'Taller Azul Nuevo SL', 'tax_id' => 'B22222222', 'email' => 'pagos@taz.example', 'client_id' => $this->client], $this->profile);
        $r = $this->issuing()->replace_for_new_details($id);
        $this->assertIsArray($r, is_wp_error($r) ? $r->get_error_message() : '');
        $rect = Invoices::get($r['rectify']); $new = Invoices::get($r['new']);
        $this->assertSame(['Rectifica 26-019: Gestión redes febrero'], array_column($rect['lines'], 'description'), 'the concept from the old sheet');
        $this->assertSame([-318.0, 'B11111111'], [$rect['total'], $rect['client_tax_id']], 'base, 21 % VAT and 15 % IRPF from its amounts');
        $this->assertSame([318.0, 'B22222222', 21.0, 15.0], [$new['total'], $new['client_tax_id'], $new['vat_rate'], $new['irpf_rate']]);
    }

    public function test_an_unjoined_import_finds_its_profile_by_tax_id_and_a_new_company_is_the_current_profile(){
        $id = $this->legacy_invoice(['profile_id' => null, 'number' => '26-020', 'seq' => 20]);
        // The client changed company: the old legal name stays inactive, the new one is active.
        \MAD_Oficina\Billing\Profiles::set_status($this->profile, 'inactive');
        $new_profile = Profiles::save(['legal_name' => 'Azul Estudio SL', 'tax_id' => 'B33333333', 'client_id' => $this->client]);
        [$orig, $cur] = Issuing::profiles_for_new_details(Invoices::get($id));
        $this->assertSame([$this->profile, $new_profile], [(int) $orig['id'], (int) $cur['id']]);
        $r = $this->issuing()->replace_for_new_details($id);
        $this->assertSame('B33333333', Invoices::get($r['new'])['client_tax_id']);
        $this->assertSame($this->profile, (int) Invoices::get($r['rectify'])['profile_id'], 'the rectifying one hangs from the profile it was issued to');

        $orphan = $this->legacy_invoice(['profile_id' => null, 'number' => '26-021', 'seq' => 21, 'client_name' => 'Nadie SL', 'client_tax_id' => 'X0000000T']);
        $e = $this->issuing()->replace_for_new_details($orphan);
        $this->assertWPError($e);
        $this->assertStringContainsString('No encuentro los datos fiscales', $e->get_error_message());
    }

    public function test_only_drafts_can_be_deleted(){
        $id = $this->draft();
        $approval = $this->issuing()->request($id);
        $this->assertTrue(Issuing::delete_draft($id));
        $this->assertNull(Invoices::get($id));
        $this->assertSame(Approvals::REJECTED, Approvals::get($approval)['status'], 'its pending approval is discarded');
        $issued = Invoices::all(['q' => '26-007'])[0];
        $this->assertFalse(Issuing::delete_draft($issued['id']));
    }

    public function test_test_email_goes_only_to_jessica_and_pdf_hook_is_signed(){
        $id = $this->draft();
        $this->issuing()->queue_test($id, 'jess@imad.example');
        $order = $this->orders('invoice_test')[0]['payload'];
        $this->assertSame('jess@imad.example', $order['email']['to']);
        $this->assertStringStartsWith('[Prueba]', $order['email']['subject']);

        $res = rest_do_request(new WP_REST_Request('GET', '/mad-oficina/v1/hooks/invoices/' . $id . '/pdf'));
        $this->assertContains($res->get_status(), [401, 503], 'only the cartero, with its signature');
    }

    public function test_paid_status_updates_the_book(){
        $id = $this->draft();
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        Invoices::set_paid($id, true);
        Issuing::queue_libro($id);
        $row = $this->orders('libro_upsert')[0]['payload']['rows'][0];
        $this->assertSame('Sí', $row['Cobrada']);
        $this->assertSame($id, $row['ID']);
        Issuing::queue_libro_sync();
        $this->assertCount(2, $this->orders('libro_sync')[0]['payload']['rows']);
    }
}
