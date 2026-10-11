<?php
use MAD_Oficina\Clients\Actions;
use MAD_Oficina\Core\ClientContext;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Roles;

/**
 * Admin form handlers: only managers, only with a valid nonce, and no email
 * leaves the site unless Jessica explicitly asks for it.
 */
class ActionsTest extends MADOF_TestCase {

    private function post($action, array $data){
        return Actions::dispatch($action, $data + ['_wpnonce' => wp_create_nonce('madof_' . $action)]);
    }

    public function test_clients_and_team_cannot_run_admin_actions(){
        $a = $this->make_client('AAA');
        foreach ( [$this->make_client_user($a), self::factory()->user->create(['role' => Roles::ROLE_TEAM])] as $user ){
            wp_set_current_user($user);
            try {
                $this->post('client_save', ['name' => 'Hack', 'code' => 'HACK']);
                $this->fail('Expected wp_die');
            } catch ( WPDieException $e ) {
                $this->assertNull(Clients::find_by_code('HACK'));
            }
        }
    }

    public function test_nonce_is_required(){
        wp_set_current_user($this->make_admin());
        $this->expectException(WPDieException::class);
        Actions::dispatch('client_save', ['name' => 'X', 'code' => 'XYZ', '_wpnonce' => 'bad']);
    }

    public function test_unknown_actions_are_rejected(){
        wp_set_current_user($this->make_admin());
        $this->expectException(WPDieException::class);
        Actions::dispatch('delete_everything', ['_wpnonce' => wp_create_nonce('madof_delete_everything')]);
    }

    public function test_manager_creates_client_and_contact(){
        wp_set_current_user($this->make_admin());
        $r = $this->post('client_save', ['name' => 'Museo', 'code' => 'mom', 'domains' => 'museo.example']);
        $this->assertSame('success', $r['type'], $r['notice']);
        $client = Clients::find_by_code('MOM');

        $r = $this->post('contact_save', ['client_id' => $client['id'], 'name' => 'Ana', 'work_emails' => 'ana@museo.example', 'personal_emails' => 'ana@gmail.example']);
        $this->assertSame('success', $r['type'], $r['notice']);
        $this->assertCount(2, Clients::contacts($client['id'])[0]['emails']);

        $r = $this->post('contact_save', ['client_id' => $client['id'], 'name' => 'Mal', 'work_emails' => 'no-es-un-email']);
        $this->assertSame('error', $r['type']);
    }

    public function test_cannot_edit_a_contact_through_another_client(){
        wp_set_current_user($this->make_admin());
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $contact = Clients::save_contact($a, ['name' => 'Ana'], []);
        $r = $this->post('contact_save', ['client_id' => $b, 'contact_id' => $contact, 'name' => 'Movida']);
        $this->assertSame('error', $r['type']);
        $this->assertSame('Ana', Clients::contacts($a)[0]['name']);
    }

    public function test_giving_portal_access_creates_client_user_without_sending_email(){
        reset_phpmailer_instance();
        wp_set_current_user($this->make_admin());
        $a = $this->make_client('AAA');

        $r = $this->post('user_add', ['client_id' => $a, 'email' => 'nuevo@aaa.example', 'name' => 'Nuevo']);
        $this->assertSame('success', $r['type'], $r['notice']);

        $user = get_user_by('email', 'nuevo@aaa.example');
        $this->assertContains(Roles::ROLE_CLIENT, $user->roles);
        $this->assertSame($a, ClientContext::client_for_user($user->ID));
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent(), 'No email may be sent when creating a portal user');
    }

    public function test_staff_cannot_become_client_users(){
        wp_set_current_user($this->make_admin());
        $a = $this->make_client('AAA');
        $editor = self::factory()->user->create(['role' => 'editor', 'user_email' => 'editor@imad.example']);
        $team = self::factory()->user->create(['role' => Roles::ROLE_TEAM, 'user_email' => 'liz@imad.example']);

        foreach ( ['editor@imad.example', 'liz@imad.example'] as $email ){
            $r = $this->post('user_add', ['client_id' => $a, 'email' => $email]);
            $this->assertSame('error', $r['type'], $email);
        }
        $this->assertNull(ClientContext::client_for_user($editor));
        $this->assertNull(ClientContext::client_for_user($team));
    }

    public function test_removing_access_unlinks_and_removes_role(){
        wp_set_current_user($this->make_admin());
        $a = $this->make_client('AAA');
        $user = $this->make_client_user($a);
        $this->post('user_remove', ['client_id' => $a, 'user_id' => $user]);
        $this->assertNull(ClientContext::client_for_user($user));
        $this->assertFalse(user_can($user, Roles::CAP_PORTAL));
    }

    public function test_send_access_only_for_users_of_that_client(){
        wp_set_current_user($this->make_admin());
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $user_b = $this->make_client_user($b);
        reset_phpmailer_instance();
        $r = $this->post('user_send_access', ['client_id' => $a, 'user_id' => $user_b]);
        $this->assertSame('error', $r['type']);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent());
    }

    public function test_delete_requires_typing_the_code(){
        wp_set_current_user($this->make_admin());
        $a = $this->make_client('AAA');
        $user = $this->make_client_user($a);

        $r = $this->post('client_delete', ['id' => $a, 'confirm_code' => 'nope']);
        $this->assertSame('error', $r['type']);
        $this->assertNotNull(Clients::get($a));

        $r = $this->post('client_delete', ['id' => $a, 'confirm_code' => 'aaa']);
        $this->assertSame('success', $r['type']);
        $this->assertNull(Clients::get($a));
        $this->assertFalse(user_can($user, Roles::CAP_PORTAL), 'Users of a deleted client lose portal access');
    }

    public function test_team_member_with_access_gets_team_role(){
        wp_set_current_user($this->make_admin());
        $r = $this->post('team_save', ['name' => 'Liz', 'workspace_email' => 'liz@imad.example', 'skills' => 'diseño, vídeo', 'portal_access' => 1, 'active' => 1]);
        $this->assertSame('success', $r['type'], $r['notice']);
        $member = MAD_Oficina\Core\Repo\Team::find_by_slug('liz');
        $this->assertSame(['diseño', 'vídeo'], $member['skills']);
        $this->assertTrue(user_can($member['wp_user_id'], Roles::CAP_TEAM));
        $this->assertFalse(user_can($member['wp_user_id'], Roles::CAP_MANAGE));

        $this->post('team_save', ['id' => $member['id'], 'name' => 'Liz']); // unchecked "active"
        $this->assertSame(0, (int) MAD_Oficina\Core\Repo\Team::get($member['id'])['active']);
    }
}
