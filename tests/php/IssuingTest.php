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

        $r = $this->issuing()->replace_for_new_details($id, 'NIF equivocado');
        $this->assertIsArray($r);
        $rect = Invoices::get($r['rectify']);
        $orig = Invoices::get($id);
        $this->assertSame(['R', $id, 'substitution', 'NIF equivocado'], [$rect['series'], $rect['rectifies_id'], $rect['rectify_mode'], $rect['rectify_reason']]);
        $this->assertSame(['B22222222', 'Taller Azul Nuevo SL'], [$rect['client_tax_id'], $rect['client_name']], 'by substitution: the corrected recipient');
        $this->assertSame([400.0, 51.0], array_map(fn($l) => (float) $l['amount'], $rect['lines']), 'the same concepts and amounts, printed whole');
        $this->assertSame([0.0, 0.0, 0.0], [$rect['base'], $rect['vat'], $rect['total']], 'it counts only what changes: nothing');
        $this->assertSame(['B11111111', '26-008'], [$orig['client_tax_id'], $orig['number']], 'the original is never touched');
        $this->assertSame(0, $r['new'], 'no second invoice with a new number and date');
        $this->assertSame((int) $r['rectify'], (int) Issuing::replacement_of($id)['rectify']['id']);

        // Pressed again with a new reason: the same draft, updated.
        $again = $this->issuing()->replace_for_new_details($id, 'Razón social y NIF equivocados');
        $this->assertSame([$r['rectify'], true], [$again['rectify'], $again['existing']]);
        $this->assertSame('Razón social y NIF equivocados', Invoices::get($r['rectify'])['rectify_reason']);

        $html = InvoicePdf::preview_html(Invoices::get($r['rectify']), []);
        foreach ( ['Factura rectificativa', 'por sustitución', 'Serie F · Nº 26-008 · expedida el 28/10/2026', 'Razón social y NIF equivocados', 'Datos que se sustituyen', 'B11111111', 'Original', 'Rectificada', 'Diferencia'] as $needle ) $this->assertStringContainsString($needle, $html);
        $mail = $this->issuing()->email_for(Invoices::get($r['rectify']), current_time('Y-m-d'), 'R-26-01');
        $this->assertSame('Factura rectificativa R-26-01 · MAD', $mail['subject']);
        $this->assertStringContainsString('rectifica la factura 26-008 del 28/10/2026', $mail['body']);
        $this->assertTrue(is_int($this->issuing()->request($r['rectify'])), 'a zero one can be issued');
    }

    public function test_the_number_it_will_take_is_shown_before_issuing_and_the_preview_has_no_draft_mark(){
        $id = $this->draft();
        $inv = Invoices::get($id);
        $this->assertSame('26-008', Invoices::preview_number($inv));
        $html = InvoicePdf::preview_html($inv, Issuing::issuer_for($inv, get_option(MAD_Oficina_Core::option_key('billing'))));
        $this->assertStringContainsString('26-008', $html);
        $this->assertStringNotContainsString('BORRADOR', $html);
        $this->assertStringContainsString(MAD_OFICINA_URL, $html, 'fonts and header by URL for the browser');
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $this->assertSame('26-008', Invoices::get($id)['number'], 'what she saw is what it took');
    }

    public function test_rectifying_some_concepts_carries_only_the_differences_and_its_reason(){
        $id = $this->draft();
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $i = $this->issuing();
        $this->assertWPError($i->rectify_lines($id, [], [], 'Error de importe'), 'nothing changed');
        $this->assertWPError($i->rectify_lines($id, [0 => ['amount' => '350']], [], ''), 'the reason is required');

        $r = $i->rectify_lines($id, [0 => ['amount' => '350,00'], 1 => ['description' => 'Banner animado', 'amount' => '51']], [['description' => 'Cartel', 'amount' => '20'], ['description' => '', 'amount' => '']], 'Error en el importe de redes');
        $this->assertIsInt($r);
        $rect = Invoices::get($r);
        $this->assertSame(['R', $id, 'Error en el importe de redes'], [$rect['series'], $rect['rectifies_id'], $rect['rectify_reason']]);
        $this->assertSame([-50.0, -51.0, 51.0, 20.0], array_map(fn($l) => (float) $l['amount'], $rect['lines']));
        $this->assertSame(-30.0, (float) $rect['base']);
        $this->assertSame('B11111111', $rect['client_tax_id']);

        $html = InvoicePdf::preview_html($rect, Issuing::issuer_for($rect, []));
        $this->assertStringContainsString('Factura rectificativa', $html);
        $this->assertStringContainsString('Serie F · Nº 26-008', $html);
        $this->assertStringContainsString('por diferencias', $html);
        $this->assertStringContainsString('Error en el importe de redes', $html);
        $this->assertStringContainsString('R-26-01', $html);

        // «Ver cómo queda» again: the same draft is rewritten, not a second one.
        $this->assertSame($r, $i->rectify_lines($id, [0 => ['amount' => '300']], [], 'Otro motivo', null, $r));
        $this->assertSame(-100.0, (float) Invoices::get($r)['base']);
        // Only a concept swapped for another at the same price: a zero rectifying invoice can still go out.
        $z = $i->rectify_lines($id, [1 => ['description' => 'Banner fijo']], [], 'Concepto equivocado');
        $this->assertSame(0.0, (float) Invoices::get($z)['total']);
        $this->assertIsInt($i->request($z));
    }

    public function test_send_now_issues_the_rectifying_and_the_new_one_without_the_inbox_and_checks_the_numbers(){
        $id = $this->draft();
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        Profiles::save(['legal_name' => 'Taller Azul Nuevo SL', 'tax_id' => 'B22222222', 'email' => 'pagos@taz.example', 'client_id' => $this->client], $this->profile);
        $pair = ['rectify' => $this->issuing()->replace_for_new_details($id)['rectify'], 'new' => $this->draft()];
        $this->assertSame(Issuing::DEFAULT_DETAILS_REASON, Invoices::get($pair['rectify'])['rectify_reason']);
        $before = count($this->orders('invoice_archive'));

        $wrong = $this->issuing()->send_now([$pair['new'] => ['expected' => '26-099', 'send' => true, 'to' => 'pagos@taz.example'], $pair['rectify'] => ['expected' => 'R-26-01', 'send' => true, 'to' => 'pagos@taz.example']]);
        $this->assertNotNull($wrong['error']);
        $this->assertNull(Invoices::get($pair['rectify'])['number'], 'a number that changed stops everything, even the other one');

        $ok = $this->issuing()->send_now([
            $pair['new'] => ['expected' => Invoices::preview_number(Invoices::get($pair['new'])), 'send' => true, 'to' => 'pagos@taz.example', 'subject' => 'Factura {numero}'],
            $pair['rectify'] => ['expected' => 'R-26-01', 'send' => true, 'to' => 'pagos@taz.example'],
        ], get_current_user_id());
        $this->assertNull($ok['error']);
        $this->assertSame(['R-26-01', '26-009'], [Invoices::get($pair['rectify'])['number'], Invoices::get($pair['new'])['number']]);
        $orders = array_slice($this->orders('invoice_archive'), $before);
        $this->assertCount(2, $orders, 'two emails, one each');
        $this->assertSame('R-26-01', $orders[0]['payload']['number'], 'the rectifying one goes first');
        $this->assertSame('Factura 26-009', $orders[1]['payload']['email']['subject']);
        $this->assertSame([], array_filter(Approvals::for_object('invoice', $pair['new']), fn($a) => $a['status'] === Approvals::PENDING), 'nothing left in the inbox');
    }

    public function test_an_email_typed_at_sending_is_kept_in_the_fiscal_details(){
        $p = Profiles::save(['legal_name' => 'Fuera de la oficina SL', 'tax_id' => 'B44444444']);
        $d = $this->issuing()->save_draft(['profile_id' => $p, 'issue_date' => '2026-10-28'], [['description' => 'Diseño', 'unit_price' => '100']]);
        $this->assertSame('', $this->issuing()->email_for(Invoices::get($d), '2026-10-28', 'x')['to']);
        $this->assertTrue(Profiles::set_email($p, 'Admin@Fuera.example'));
        $this->assertSame('admin@fuera.example', Profiles::get($p)['email']);
        $this->assertSame('admin@fuera.example', Invoices::get($d)['client_email'], 'and on its pending drafts');
        $this->assertFalse(Profiles::set_email($p, 'no-es-un-email'));
    }

    public function test_an_issued_invoice_can_be_sent_again_with_its_pdf(){
        $id = $this->draft();
        $this->assertWPError($this->issuing()->queue_resend($id, ['to' => 'pagos@taz.example']), 'a draft is not resent');
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $this->assertWPError($this->issuing()->queue_resend($id, ['to' => 'no-es-email']));
        $this->assertNotWPError($this->issuing()->queue_resend($id, ['to' => 'pagos@taz.example']));
        $this->assertNotWPError($this->issuing()->queue_resend($id, ['to' => 'pagos@taz.example']));
        $orders = $this->orders('payment_reminder');
        $this->assertCount(2, $orders, 'each press sends once');
        $this->assertNotSame($orders[0]['payload']['key'], $orders[1]['payload']['key']);
        $this->assertSame(['Factura 26-008 · MAD', $id], [$orders[0]['payload']['email']['subject'], $orders[0]['payload']['invoice_id']]);
        Issuing::order_done('payment_reminder', $orders[0]['payload'], true, ['sent' => true]);
        $this->assertSame('sent', Invoices::get($id)['status'], 'only issued before: now it counts as sent');
    }

    public function test_an_invoice_issued_by_mistake_goes_back_to_draft_only_while_it_is_the_last(){
        $id = $this->draft();
        Approvals::resolve($this->issuing()->request($id), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $r = $this->issuing()->rectify_lines($id, [0 => ['amount' => '350']], [], 'Importe equivocado');
        Approvals::resolve($this->issuing()->request($r), 'approve', ['send' => '1', 'to' => 'pagos@taz.example', 'issue_date' => current_time('Y-m-d')], get_current_user_id());
        $this->assertSame('R-26-01', Invoices::get($r)['number']);
        $this->assertWPError(Issuing::undo_issue($id), 'the original already has a rectifying invoice');

        $u = Issuing::undo_issue($r);
        $this->assertIsArray($u);
        $back = Invoices::get($r);
        $this->assertSame(['draft', null, null], [$back['status'], $back['number'], $back['seq']]);
        $this->assertSame([], array_filter($this->orders('invoice_archive'), fn($o) => (int) $o['payload']['invoice_id'] === $r), 'the cartero will not send the wrong one');
        $this->assertSame('R-26-01', Invoices::preview_number($back), 'the same number for the corrected one');

        // Corrected and issued again: a new file name, and the email goes as a resend (the cartero sends each invoice once).
        $this->issuing()->rectify_lines($id, [0 => ['amount' => '300']], [], 'Importe equivocado', null, $r);
        Approvals::resolve($this->issuing()->request($r), 'approve', ['send' => '1', 'to' => 'pagos@taz.example', 'issue_date' => current_time('Y-m-d')], get_current_user_id());
        $again = Invoices::get($r);
        $this->assertSame(['R-26-01', -100.0], [$again['number'], (float) $again['base']]);
        $archive = array_values(array_filter($this->orders('invoice_archive'), fn($o) => (int) $o['payload']['invoice_id'] === $r));
        $this->assertStringContainsString('(v2)', $archive[0]['payload']['filename']);
        $this->assertNull($archive[0]['payload']['email']);
        $this->assertCount(1, array_filter($this->orders('payment_reminder'), fn($o) => (int) $o['payload']['invoice_id'] === $r && ! empty($o['payload']['resend'])));

        // Not the last of its series any more: never a gap.
        $next = $this->issuing()->rectify_lines($id, [1 => ['amount' => '40']], [], 'Otro error');
        Approvals::resolve($this->issuing()->request($next), 'approve', ['send' => '', 'issue_date' => current_time('Y-m-d')], get_current_user_id());
        $this->assertWPError(Issuing::undo_issue($r));
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
        $rect = Invoices::get($r['rectify']);
        $this->assertSame(['Gestión redes febrero'], array_column($rect['lines'], 'description'), 'the concept from the old sheet');
        $this->assertSame([0.0, 'B22222222', 21.0, 15.0, 'substitution'], [$rect['total'], $rect['client_tax_id'], $rect['vat_rate'], $rect['irpf_rate'], $rect['rectify_mode']], 'rates from its amounts; it counts zero');
        $this->assertSame(0, $r['new']);
    }

    public function test_an_unjoined_import_finds_its_profile_by_tax_id_and_a_new_company_is_the_current_profile(){
        $id = $this->legacy_invoice(['profile_id' => null, 'number' => '26-020', 'seq' => 20]);
        // The client changed company: the old legal name stays inactive, the new one is active.
        \MAD_Oficina\Billing\Profiles::set_status($this->profile, 'inactive');
        $new_profile = Profiles::save(['legal_name' => 'Azul Estudio SL', 'tax_id' => 'B33333333', 'client_id' => $this->client]);
        [$orig, $cur] = Issuing::profiles_for_new_details(Invoices::get($id));
        $this->assertSame([$this->profile, $new_profile], [(int) $orig['id'], (int) $cur['id']]);
        $r = $this->issuing()->replace_for_new_details($id);
        $this->assertSame('B33333333', Invoices::get($r['rectify'])['client_tax_id'], 'by substitution: to the new company');

        $orphan = $this->legacy_invoice(['profile_id' => null, 'number' => '26-021', 'seq' => 21, 'client_name' => 'Nadie SL', 'client_tax_id' => 'X0000000T']);
        $e = $this->issuing()->replace_for_new_details($orphan);
        $this->assertWPError($e);
        $this->assertStringContainsString('No encuentro los datos fiscales', $e->get_error_message());
    }

    public function test_a_new_legal_name_takes_the_pending_drafts_and_the_running_agreements(){
        Profiles::save(['legal_name' => 'Taller Azul SL', 'tax_id' => 'B11111111', 'email' => 'pagos@taz.example', 'client_id' => $this->client, 'billing_day' => 15], $this->profile);
        $issued = $this->draft();
        Approvals::resolve($this->issuing()->request($issued), 'approve', ['send' => '', 'issue_date' => '2026-10-28'], get_current_user_id());
        $pending = $this->draft(['issue_date' => '2026-11-28']);
        $fee = \MAD_Oficina\Billing\Agreements::create(['profile_id' => $this->profile, 'kind' => 'monthly', 'concept' => 'Fee', 'amount' => '500', 'start_month' => '2026-01']);
        $new = Profiles::save(['legal_name' => 'Azul Estudio SL', 'tax_id' => 'B33333333', 'client_id' => $this->client]);

        // On the client card: the new one is «actual», the old one «anterior».
        $admin = $this->factory()->user->create(['role' => 'administrator']);
        get_user_by('id', $admin)->add_cap(MAD_Oficina_Core::CAPABILITY);
        wp_set_current_user($admin);
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce('madof_billing_profile_status'), 'profile_id' => $this->profile, 'status' => 'inactive'];
        try { MAD_Oficina_Core::instance()->get_module('billing')->handle_billing_profile_status(); } catch ( \Throwable $e ) {}
        $_POST = $_REQUEST = [];

        $this->assertSame([$new, 'B33333333'], [(int) Invoices::get($pending)['profile_id'], Invoices::get($pending)['client_tax_id']], 'the next draft goes to the new legal name');
        $this->assertSame($new, (int) \MAD_Oficina\Billing\Agreements::get($fee)['profile_id'], 'and so does the monthly fee');
        $this->assertSame(['B11111111', $this->profile], [Invoices::get($issued)['client_tax_id'], (int) Invoices::get($issued)['profile_id']], 'an issued invoice is never touched');
        $this->assertSame(15, Profiles::get($new)['billing_day'], 'its billing day comes along');

        // A draft never goes back to the one marked «anterior».
        $this->issuing()->save_draft(['profile_id' => $this->profile], [['description' => 'x', 'unit_price' => '10']], $pending);
        $this->assertSame('B33333333', Invoices::get($pending)['client_tax_id']);
    }

    public function test_marked_before_the_move_existed_the_draft_and_the_calendar_still_follow_the_current_one(){
        // PPT: both legal names joined, the new one «actual» and the old one «anterior», but the draft and the fee still under the old one.
        $draft = $this->draft(['issue_date' => '2026-11-28']);
        $fee = \MAD_Oficina\Billing\Agreements::create(['profile_id' => $this->profile, 'kind' => 'monthly', 'concept' => 'Fee', 'amount' => '500', 'start_month' => '2026-01']);
        $new = Profiles::save(['legal_name' => 'POO POO TRAS ECO, SL', 'tax_id' => 'B66888991', 'client_id' => $this->client]);
        Profiles::set_status($this->profile, 'inactive');
        $this->assertSame('B11111111', Invoices::get($draft)['client_tax_id']);

        // Opening the draft is enough.
        $admin = $this->factory()->user->create(['role' => 'administrator']);
        get_user_by('id', $admin)->add_cap(MAD_Oficina_Core::CAPABILITY);
        wp_set_current_user($admin);
        $_GET = ['edit' => (string) $draft];
        ob_start(); MAD_Oficina_Core::instance()->get_module('billing')->render_settings_page(); $html = ob_get_clean();
        $_GET = [];
        $this->assertSame(['B66888991', $new], [Invoices::get($draft)['client_tax_id'], (int) Invoices::get($draft)['profile_id']]);
        $this->assertStringContainsString('POO POO TRAS ECO, SL', $html);
        $this->assertStringNotContainsString('<select name="profile_id"', $html, 'one current legal name: nothing to choose');
        $this->assertSame($new, (int) \MAD_Oficina\Billing\Agreements::get($fee)['profile_id']);

        // The calendar, asked for the old one, does not bring it back.
        $cal = new \MAD_Oficina\Billing\Calendar([]);
        $cal->prepare_one(Profiles::get($this->profile), '2026-11');
        $this->assertSame('B66888991', Invoices::get($draft)['client_tax_id']);
        $this->assertSame(1, count(Invoices::all(['profile_id' => $new])), 'no second draft');
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
