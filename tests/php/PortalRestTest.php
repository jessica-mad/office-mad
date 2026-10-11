<?php
use MAD_Oficina\Core\Roles;
use MAD_Oficina\Core\Schema;

/**
 * Portal REST API: authentication, client isolation and visibility.
 */
class PortalRestTest extends MADOF_TestCase {

    private $a; private $b; private $user_a;

    public function set_up(){
        parent::set_up();
        update_option(MAD_Oficina_Core::ENABLED_MODULES_OPTION, ['clients', 'google', 'portal']);
        $this->a = $this->make_client('AAA');
        $this->b = $this->make_client('BBB');
        $this->user_a = $this->make_client_user($this->a);

        foreach ( [$this->a => 'A', $this->b => 'B'] as $id => $tag ){
            $this->insert('action_items', ['client_id' => $id, 'title' => "{$tag} tarea visible", 'owner' => 'cliente', 'visibility' => 'client']);
            $this->insert('action_items', ['client_id' => $id, 'title' => "{$tag} tarea MAD", 'owner' => 'liz', 'team_member_id' => 7, 'visibility' => 'client']);
            $this->insert('action_items', ['client_id' => $id, 'title' => "{$tag} nota interna", 'owner' => 'jessica', 'visibility' => 'internal']);
            $this->insert('action_items', ['client_id' => $id, 'title' => "{$tag} solo equipo", 'owner' => 'liz', 'visibility' => 'team']);
            $this->insert('ideas', ['client_id' => $id, 'title' => "{$tag} idea visible", 'visibility' => 'client']);
            $this->insert('ideas', ['client_id' => $id, 'title' => "{$tag} idea interna", 'visibility' => 'internal']);
            $this->insert('processes', ['client_id' => $id, 'area' => 'web', 'title' => "{$tag} web", 'visibility' => 'client']);
            $this->insert('processes', ['client_id' => $id, 'area' => 'ads', 'title' => "{$tag} proceso interno", 'visibility' => 'internal']);
            $this->insert('meetings', ['client_id' => $id, 'title' => "{$tag} reunión", 'status' => 'approved', 'visibility' => 'client', 'summary' => 'ok']);
            $this->insert('meetings', ['client_id' => $id, 'title' => "{$tag} borrador", 'status' => 'extracted', 'visibility' => 'client']);
        }
    }

    private function get($route, array $params = []){
        $req = new WP_REST_Request('GET', '/mad-oficina/v1' . $route);
        foreach ( $params as $k => $v ) $req->set_param($k, $v);
        return rest_do_request($req);
    }

    private function titles($data){
        $out = [];
        array_walk_recursive($data, function($v, $k) use (&$out){ if ( $k === 'title' ) $out[] = $v; });
        sort($out);
        return $out;
    }

    public function test_anonymous_and_non_portal_users_are_rejected(){
        wp_set_current_user(0);
        $this->assertSame(401, $this->get('/portal/tasks')->get_status());
        $this->assertSame(401, $this->get('/me')->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->get('/portal/tasks')->get_status());
        $this->assertSame(403, $this->get('/office/state')->get_status());
    }

    public function test_client_only_sees_own_client_visible_rows(){
        wp_set_current_user($this->user_a);
        $all = [];
        foreach ( ['tasks', 'ideas', 'timeline', 'meetings'] as $section ){
            $res = $this->get('/portal/' . $section, ['client' => $this->b]); // tries to peek at B
            $this->assertSame(200, $res->get_status(), $section);
            $all = array_merge($all, $this->titles($res->get_data()));
        }
        sort($all);
        $this->assertSame(['A idea visible', 'A reunión', 'A tarea MAD', 'A tarea visible', 'A web'], $all);
    }

    public function test_task_output_never_leaks_internal_fields(){
        wp_set_current_user($this->user_a);
        $data = $this->get('/portal/tasks')->get_data();
        $this->assertSame(['A tarea visible'], array_column($data['client'], 'title'));
        $this->assertSame('Liz', $data['mad'][0]['owner']);
        $this->assertArrayNotHasKey('team_member_id', $data['mad'][0]);
        $this->assertArrayNotHasKey('visibility', $data['mad'][0]);
    }

    public function test_manager_must_pick_a_client_and_sees_the_client_view(){
        wp_set_current_user($this->make_admin());
        $this->assertSame(400, $this->get('/portal/tasks')->get_status());
        $res = $this->get('/portal/ideas', ['client' => $this->b]);
        $this->assertSame(['B idea visible'], $this->titles($res->get_data()), 'Preview shows exactly what the client sees');
        $this->assertCount(2, $this->get('/me')->get_data()['clients']);
    }

    public function test_unlinked_portal_users_get_a_clear_error(){
        wp_set_current_user(self::factory()->user->create(['role' => Roles::ROLE_CLIENT]));
        $res = $this->get('/portal/tasks');
        $this->assertSame(403, $res->get_status());
        $this->assertSame('madof_no_client', $res->get_data()['code']);
    }

    public function test_me_describes_the_client_and_never_lists_other_clients(){
        wp_set_current_user($this->user_a);
        $me = $this->get('/me')->get_data();
        $this->assertSame('client', $me['role']);
        $this->assertSame('AAA', $me['client']['code']);
        $this->assertArrayNotHasKey('clients', $me);
        $this->assertSame('Rita', $me['characters']['recepcionista']['name']);
    }

    public function test_team_sees_only_their_tasks_and_no_client_portal(){
        $team_user = self::factory()->user->create(['role' => Roles::ROLE_TEAM]);
        $member = MAD_Oficina\Core\Repo\Team::save(['name' => 'Liz', 'wp_user_id' => $team_user]);
        $this->insert('action_items', ['client_id' => $this->a, 'title' => 'Para Liz', 'owner' => 'liz', 'team_member_id' => $member, 'visibility' => 'team']);
        $this->insert('action_items', ['client_id' => $this->a, 'title' => 'Interna de Liz', 'owner' => 'liz', 'team_member_id' => $member, 'visibility' => 'internal']);

        wp_set_current_user($team_user);
        $this->assertSame(['Para Liz'], array_column($this->get('/team/tasks')->get_data(), 'title'));
        $this->assertSame(403, $this->get('/portal/tasks', ['client' => $this->a])->get_status());

        wp_set_current_user($this->user_a);
        $this->assertSame(403, $this->get('/team/tasks')->get_status());
    }

    public function test_office_state_reflects_pending_approvals(){
        $this->insert('approvals', ['agent' => 'secretary', 'action_type' => 'meeting_summary', 'title' => 'x', 'status' => 'pending']);
        wp_set_current_user($this->user_a);
        $states = $this->get('/office/state')->get_data()['characters'];
        $this->assertSame('esperando_gerencia', $states['secretaria']);
        $this->assertSame('idle', $states['recepcionista']);
        $this->assertSame('trabajando', $states['liz']);
    }
}
