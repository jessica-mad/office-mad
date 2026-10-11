<?php
use MAD_Oficina\Core\Installer;
use MAD_Oficina\Core\Roles;
use MAD_Oficina\Core\Schema;

class InstallerTest extends MADOF_TestCase {

    public function test_all_tables_exist(){
        global $wpdb;
        foreach ( Schema::names() as $name ){
            $table = Schema::table($name);
            $this->assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), "Missing table {$name}");
        }
    }

    public function test_install_is_idempotent(){
        global $wpdb;
        Installer::install();
        Installer::install();
        $this->assertSame('', $wpdb->last_error);
        $this->assertSame(Installer::DB_VERSION, (int) get_option(Installer::DB_VERSION_OPTION));
    }

    public function test_maybe_upgrade_runs_when_version_is_behind(){
        update_option(Installer::DB_VERSION_OPTION, 0);
        Installer::maybe_upgrade();
        $this->assertSame(Installer::DB_VERSION, (int) get_option(Installer::DB_VERSION_OPTION));
    }

    public function test_roles_and_capabilities(){
        $team = get_role(Roles::ROLE_TEAM);
        $client = get_role(Roles::ROLE_CLIENT);
        $this->assertNotNull($team);
        $this->assertNotNull($client);

        $this->assertTrue($team->has_cap(Roles::CAP_TEAM));
        $this->assertFalse($team->has_cap(Roles::CAP_MANAGE));
        $this->assertTrue($client->has_cap(Roles::CAP_PORTAL));
        $this->assertFalse($client->has_cap(Roles::CAP_TEAM));
        $this->assertFalse($client->has_cap(Roles::CAP_MANAGE));
        $this->assertFalse($client->has_cap('edit_posts'));
        $this->assertTrue(get_role('administrator')->has_cap(Roles::CAP_MANAGE));
    }
}
