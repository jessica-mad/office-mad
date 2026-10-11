<?php
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Schema;

class ClientsRepoTest extends MADOF_TestCase {

    public function test_code_is_normalised_and_unique(){
        $id = Clients::save(['name' => 'Museo', 'code' => ' m-o m ']);
        $this->assertSame('MOM', Clients::get($id)['code']);
        $this->assertWPError(Clients::save(['name' => 'Otro', 'code' => 'mom']));
        $this->assertWPError(Clients::save(['name' => 'Corto', 'code' => 'x']));
        $this->assertWPError(Clients::save(['name' => '', 'code' => 'ABC']));
        $this->assertSame($id, Clients::save(['name' => 'Museo 2', 'code' => 'MOM'], $id), 'Saving itself keeps its own code');
    }

    public function test_domains_and_drive_ids_are_parsed(){
        $this->assertSame(['empresa.com', 'empresa.es'], Clients::parse_domains('https://Empresa.com/, @empresa.es; nope'));
        $this->assertSame('1AbC_dEf-123456', Clients::parse_drive_id('https://drive.google.com/drive/folders/1AbC_dEf-123456?usp=sharing'));
        $this->assertSame('1AbC_dEf-123456', Clients::parse_drive_id('1AbC_dEf-123456'));
        $this->assertSame('', Clients::parse_drive_id('javascript:alert(1)'));
    }

    public function test_contacts_emails_lookup(){
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $contact = Clients::save_contact($a, ['name' => 'Marta', 'aliases' => 'Martita, M'], [
            ['email' => 'Marta@AAA.example', 'kind' => 'work'],
            ['email' => 'marta.personal@gmail.example', 'kind' => 'personal'],
        ]);
        $this->assertIsInt($contact);

        $hit = Clients::find_by_email('MARTA.personal@gmail.example');
        $this->assertSame($a, $hit['client_id']);
        $this->assertSame($contact, $hit['contact_id']);
        $this->assertSame('personal', $hit['kind']);

        // The same email cannot belong to a contact of another client.
        $this->assertWPError(Clients::save_contact($b, ['name' => 'Impostor'], [['email' => 'marta@aaa.example']]));

        $this->assertSame($a, Clients::find_by_domain('someone@aaa.example')['id']);
        $this->assertNull(Clients::find_by_domain('someone@gmail.com'));

        $contacts = Clients::contacts($a);
        $this->assertSame(['Martita', 'M'], $contacts[0]['aliases']);
        $this->assertCount(2, $contacts[0]['emails']);
    }

    public function test_learning_a_new_email(){
        $a = $this->make_client('AAA');
        $contact = Clients::save_contact($a, ['name' => 'Iago'], []);
        $this->assertTrue(Clients::add_email($a, $contact, 'iago.nuevo@aaa.example', 'work', 'meeting'));
        $this->assertFalse(Clients::add_email($a, $contact, 'iago.nuevo@aaa.example'), 'No duplicates');
        $this->assertSame($contact, Clients::find_by_email('iago.nuevo@aaa.example')['contact_id']);
    }

    public function test_delete_erases_everything_of_that_client_only(){
        global $wpdb;
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $this->make_client_user($a);
        Clients::save_contact($a, ['name' => 'A'], [['email' => 'a@aaa.example']]);
        foreach ( [$a, $b] as $id ){
            $this->insert('action_items', ['client_id' => $id, 'title' => 't']);
            $this->insert('ideas', ['client_id' => $id, 'title' => 'i']);
            $this->insert('messages', ['client_id' => $id, 'thread' => 'x', 'author_type' => 'client', 'body' => 'hola']);
        }

        Clients::delete($a);

        $this->assertNull(Clients::get($a));
        foreach ( array_merge(Clients::CONTENT_TABLES, ['client_contacts', 'contact_emails', 'client_users']) as $table ){
            $this->assertSame('0', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table($table) . ' WHERE client_id = %d', $a)), $table);
        }
        $this->assertSame('1', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('action_items') . ' WHERE client_id = %d', $b)));
    }
}
