<?php
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Roles;

abstract class MADOF_TestCase extends WP_UnitTestCase {

    protected function make_client($code, $name = null){
        $id = Clients::save(['name' => $name ?? "Cliente {$code}", 'code' => $code, 'domains' => strtolower($code) . '.example']);
        $this->assertIsInt($id);
        return $id;
    }

    protected function make_client_user($client_id){
        $user_id = self::factory()->user->create(['role' => Roles::ROLE_CLIENT]);
        $this->assertTrue(Clients::link_user($client_id, $user_id));
        return $user_id;
    }

    protected function make_admin(){
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        get_user_by('id', $user_id)->add_cap(Roles::CAP_MANAGE); // roles are cached per test; be explicit
        return $user_id;
    }

    protected function insert($table, array $row){
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $row += ['created_at' => $now];
        if ( $table !== 'agent_log' && $table !== 'messages' && $table !== 'client_users' ) $row += ['updated_at' => $now];
        $wpdb->insert(MAD_Oficina\Core\Schema::table($table), $row);
        return (int) $wpdb->insert_id;
    }
}
