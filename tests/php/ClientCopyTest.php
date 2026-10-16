<?php
use MAD_Oficina\Core\ClientCopy;
use MAD_Oficina\Core\Repo\Clients;

/**
 * Hidden copy of client emails to Jessica+c.<cliente>. Invented data.
 */
class ClientCopyTest extends MADOF_TestCase {

    public function set_up(){
        parent::set_up();
        delete_option(ClientCopy::OPTION);
        update_option(MAD_Oficina_Core::option_key('chat'), ['manager_email' => 'ana@agencia.example']);
    }

    public function test_the_address_carries_the_client_and_follows_jessicas_email(){
        $this->assertSame('ana+c.{cliente}@agencia.example', ClientCopy::default_template());
        $this->assertSame('ana+c.lanas-del-norte-sl@agencia.example', ClientCopy::address(['name' => 'Lanas del Norte, S.L.', 'code' => 'LN']));
        $this->assertSame('ana+c.cafe-nino@agencia.example', ClientCopy::address('Café Niño'));

        $this->assertTrue(ClientCopy::save(true, 'copias+{codigo}@agencia.example'));
        $this->assertSame('copias+ln@agencia.example', ClientCopy::address(['name' => 'Lanas del Norte', 'code' => 'LN']));
        $this->assertWPError(ClientCopy::save(true, 'no es un email {cliente}'));

        ClientCopy::save(false, '');
        $this->assertSame('', ClientCopy::address('Lanas del Norte'), 'it can be turned off');
    }

    public function test_issued_invoices_carry_the_copy_to_the_cartero(){
        $client = Clients::save(['name' => 'Lanas del Norte', 'code' => 'LN']);
        $issuing = new \MAD_Oficina\Billing\Issuing([]);
        $issuing->queue_archive(['id' => 7, 'number' => 'F-2099-001', 'year' => 2099, 'issue_date' => '2099-01-10', 'client_id' => $client, 'client_name' => 'Lanas del Norte SL', 'series' => 'F', 'base' => 1, 'vat_rate' => 0, 'vat' => 0, 'irpf_rate' => 0, 'irpf' => 0, 'paid_at' => null, 'total' => 1, 'status' => 'issued', 'paid' => 0, 'due_date' => null, 'pdf_url' => null, 'client_tax_id' => 'B1', 'currency' => 'EUR', 'lines' => []],
            ['to' => 'pagos@lanas.example', 'subject' => 'Factura', 'body' => 'Hola']);
        $payload = json_decode($GLOBALS['wpdb']->get_var("SELECT payload FROM " . \MAD_Oficina\Core\Schema::table('tasks') . " WHERE type = 'invoice_archive' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame('ana+c.lanas-del-norte@agencia.example', $payload['email']['bcc'], 'the name in the office, not the legal one');
    }
}
