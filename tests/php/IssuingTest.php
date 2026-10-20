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

        // Editing the rectifying draft keeps the original's details; refreshing drafts leaves it alone.
        Issuing::refresh_drafts($this->profile);
        $this->issuing()->save_draft(['profile_id' => $this->profile], [['description' => 'x', 'unit_price' => '-10']], $r['rectify']);
        $this->assertSame('B11111111', Invoices::get($r['rectify'])['client_tax_id']);
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
