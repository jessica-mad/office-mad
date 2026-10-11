<?php
use MAD_Oficina\Core\ClientContext;
use MAD_Oficina\Core\Roles;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Visibility;

/**
 * A client must never reach another client's data or internal/team content.
 */
class IsolationTest extends MADOF_TestCase {

    public function test_client_user_resolves_to_own_client_even_if_asking_for_another(){
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $user = $this->make_client_user($a);

        $this->assertSame($a, ClientContext::resolve(null, $user));
        $this->assertSame($a, ClientContext::resolve($b, $user), 'A client must not be able to pick another client_id');
    }

    public function test_manager_can_pick_any_client(){
        $b = $this->make_client('BBB');
        $admin = $this->make_admin();
        $this->assertSame($b, ClientContext::resolve($b, $admin));
        $this->assertNull(ClientContext::resolve(null, $admin));
    }

    public function test_users_without_link_get_no_client(){
        $this->make_client('AAA');
        $team = self::factory()->user->create(['role' => Roles::ROLE_TEAM]);
        $sub  = self::factory()->user->create(['role' => 'subscriber']);
        $this->assertNull(ClientContext::resolve(1, $team));
        $this->assertNull(ClientContext::resolve(1, $sub));
        $this->assertNull(ClientContext::resolve(1, 0));
    }

    public function test_a_user_cannot_belong_to_two_clients(){
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $user = $this->make_client_user($a);
        $this->assertWPError(MAD_Oficina\Core\Repo\Clients::link_user($b, $user));
        $this->assertSame($a, ClientContext::client_for_user($user));
    }

    public function test_visibility_levels_per_role(){
        $client = self::factory()->user->create(['role' => Roles::ROLE_CLIENT]);
        $team   = self::factory()->user->create(['role' => Roles::ROLE_TEAM]);
        $sub    = self::factory()->user->create(['role' => 'subscriber']);

        $this->assertSame(['client'], Visibility::allowed_for_user($client));
        $this->assertSame(['team', 'client'], Visibility::allowed_for_user($team));
        $this->assertSame(Visibility::ALL, Visibility::allowed_for_user($this->make_admin()));
        $this->assertSame([], Visibility::allowed_for_user($sub));
        $this->assertSame([], Visibility::allowed_for_user(0));
    }

    public function test_sql_fragment_only_contains_whitelisted_values(){
        $this->assertSame("visibility IN ('client')", Visibility::sql_in(['client', "x') OR 1=1 --"]));
        $this->assertSame('1=0', Visibility::sql_in([]));
        $this->assertSame('internal', Visibility::sanitize('bogus'));
    }

    /** The pattern every portal query must follow: server-side client_id + visibility filter. */
    public function test_portal_query_pattern_hides_internal_and_foreign_rows(){
        global $wpdb;
        $a = $this->make_client('AAA');
        $b = $this->make_client('BBB');
        $user = $this->make_client_user($a);

        $this->insert('action_items', ['client_id' => $a, 'title' => 'A visible', 'visibility' => 'client']);
        $this->insert('action_items', ['client_id' => $a, 'title' => 'A internal', 'visibility' => 'internal']);
        $this->insert('action_items', ['client_id' => $a, 'title' => 'A team', 'visibility' => 'team']);
        $this->insert('action_items', ['client_id' => $b, 'title' => 'B visible', 'visibility' => 'client']);

        $client_id = ClientContext::resolve($b, $user); // tries to peek at B
        $titles = $wpdb->get_col($wpdb->prepare(
            'SELECT title FROM ' . Schema::table('action_items') . ' WHERE client_id = %d AND ' . Visibility::sql_in(Visibility::allowed_for_user($user)),
            $client_id
        ));
        $this->assertSame(['A visible'], $titles);
    }

    public function test_new_content_defaults_to_internal(){
        global $wpdb;
        $a = $this->make_client('AAA');
        foreach ( ['action_items' => ['title' => 'x'], 'ideas' => ['title' => 'x'], 'pending_topics' => ['text' => 'x'], 'meetings' => ['title' => 'x']] as $table => $row ){
            $id = $this->insert($table, $row + ['client_id' => $a]);
            $this->assertSame('internal', $wpdb->get_var('SELECT visibility FROM ' . Schema::table($table) . ' WHERE id = ' . $id), $table);
        }
    }
}
