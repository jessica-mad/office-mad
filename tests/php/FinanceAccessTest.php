<?php
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\FinanceAccess;

/**
 * Finanzas can be reserved to some managers: other administrators do not see it,
 * nor the accountant's requests. Invented users.
 */
class FinanceAccessTest extends MADOF_TestCase {

    private $jessica; private $liz;

    public function set_up(){
        parent::set_up();
        $this->jessica = $this->make_admin();
        $this->liz = $this->make_admin();
        Approvals::create('accountant', 'invoice_issue', '¿Envío la factura de Prueba SL?', []);
        Approvals::create('secretary', 'meeting_draft', 'Acta de la reunión', []);
    }

    private function req($method, $route, array $params = []){
        $r = new WP_REST_Request($method, '/mad-oficina/v1' . $route);
        foreach ( $params as $k => $v ) $r->set_param($k, $v);
        return rest_do_request($r);
    }

    public function test_open_to_every_manager_until_reserved(){
        $this->assertTrue(FinanceAccess::can($this->liz));
        $this->assertSame(2, FinanceAccess::pending_count($this->liz));
        $this->assertFalse(FinanceAccess::can(self::factory()->user->create(['role' => 'subscriber'])));
    }

    public function test_reserved_to_jessica(){
        wp_set_current_user($this->jessica);
        $this->assertTrue(FinanceAccess::set([]));
        $this->assertSame([$this->jessica], FinanceAccess::users(), 'whoever reserves it always keeps it');
        $this->assertFalse(FinanceAccess::can($this->liz));
        $this->assertTrue(FinanceAccess::can($this->jessica));

        // Liz: her inbox has no money matters and she cannot take Finanzas back.
        wp_set_current_user($this->liz);
        $this->assertSame(['Acta de la reunión'], array_column(FinanceAccess::visible(Approvals::pending()), 'title'));
        $this->assertSame(1, FinanceAccess::pending_count());
        $this->assertWPError(FinanceAccess::set([$this->liz]));
        $this->assertSame([$this->jessica], FinanceAccess::users());
        $invoice = array_values(array_filter(Approvals::pending(), fn($a) => $a['action_type'] === 'invoice_issue'))[0];
        $this->assertTrue(FinanceAccess::hides($invoice));

        // Nor in the office: the antechamber and the invoices of a client.
        $GLOBALS['wp_rest_server'] = null;
        $this->assertArrayNotHasKey('contable', (array) $this->req('GET', '/office/waiting')->get_data()['characters']);
        $this->assertSame(404, $this->req('POST', '/office/approvals/' . $invoice['id'], ['decision' => 'reject'])->get_status());
        $client = $this->make_client('PRU');
        $this->assertSame(403, $this->req('GET', '/portal/invoices', ['client' => $client])->get_status());

        // Jessica sees everything; she can share it again.
        wp_set_current_user($this->jessica);
        $this->assertSame(2, FinanceAccess::pending_count());
        $this->assertArrayHasKey('contable', (array) $this->req('GET', '/office/waiting')->get_data()['characters']);
        $this->assertSame(200, $this->req('GET', '/portal/invoices', ['client' => $client])->get_status());
        FinanceAccess::set([$this->jessica, $this->liz]);
        $this->assertTrue(FinanceAccess::can($this->liz));
    }
}
