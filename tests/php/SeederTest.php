<?php
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Seeder;

class SeederTest extends MADOF_TestCase {

    public function test_seed_then_purge_leaves_real_data_untouched(){
        $real = $this->make_client('MOM', 'Cliente real');
        $real_user = $this->make_client_user($real);
        // A real account that happens to use a demo login must never be adopted or deleted.
        $lookalike = self::factory()->user->create(['user_login' => 'demo_lua']);

        $r = Seeder::seed();
        $this->assertSame(3, $r['clients']);
        $this->assertTrue(Seeder::has_demo());
        $this->assertCount(4, Clients::all());
        $this->assertNotNull(Team::find_by_slug('demo-liz'));
        $this->assertNull(MAD_Oficina\Core\ClientContext::client_for_user($lookalike));

        Seeder::seed(); // re-seeding replaces, never duplicates
        $this->assertCount(4, Clients::all());

        Seeder::purge();
        $this->assertFalse(Seeder::has_demo());
        $this->assertSame([$real], array_column(Clients::all(), 'id'));
        $this->assertNull(Team::find_by_slug('demo-liz'));
        $this->assertNotFalse(get_user_by('id', $real_user));
        $this->assertNotFalse(get_user_by('id', $lookalike));
        $this->assertSame([], get_users(['meta_key' => Seeder::USER_META, 'meta_value' => 1]));
    }
}
