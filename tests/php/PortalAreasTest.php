<?php
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Clients\Actions;
use MAD_Oficina\Core\PortalAreas;
use MAD_Oficina\Portal\Receptionist;

/**
 * Each person of a client company only sees the parts of the office opened for
 * them (e.g. invoices only for whoever pays). Invented data only.
 */
class PortalAreasTest extends MADOF_TestCase {

    private $a; private $b; private $boss; private $staff;
    private $requests = [];

    public function set_up(){
        parent::set_up();
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        $this->a = $this->make_client('AAA', 'Cliente A');
        $this->b = $this->make_client('BBB', 'Cliente B');
        $this->boss = $this->make_client_user($this->a);
        $this->staff = $this->make_client_user($this->a);
        PortalAreas::set($this->a, $this->boss, array_keys(PortalAreas::AREAS));
        $this->insert('meetings', ['client_id' => $this->a, 'title' => 'A reunión', 'status' => 'approved', 'visibility' => 'client', 'summary' => 'ok']);

        foreach ( [$this->a => 'A', $this->b => 'B'] as $client => $tag ) {
            $p = Profiles::save(['legal_name' => "{$tag} Sociedad SL", 'client_id' => $client]);
            Invoices::insert(['series' => 'F', 'number' => "26-00{$client}", 'year' => 2026, 'seq' => $client, 'status' => 'sent', 'issue_date' => '2026-09-01', 'client_name' => "{$tag} Sociedad SL", 'profile_id' => $p, 'base' => 100, 'vat' => 21, 'irpf' => 15, 'total' => 106, 'vat_rate' => 21, 'irpf_rate' => 15, 'notes' => 'margen interno'], [['description' => "Fee {$tag}", 'amount' => 100]]);
            Invoices::insert(['series' => 'F', 'year' => 2026, 'status' => 'draft', 'issue_date' => '2026-10-01', 'client_name' => "{$tag} Sociedad SL", 'profile_id' => $p, 'base' => 50, 'total' => 50], [['description' => "Borrador {$tag}", 'amount' => 50]]);
        }
        add_filter('pre_http_request', [$this, 'fake_claude'], 10, 3);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake_claude']);
        parent::tear_down();
    }

    public function fake_claude($pre, $args, $url){
        if ( $url !== 'https://api.anthropic.com/v1/messages' ) return $pre;
        $this->requests[] = json_decode($args['body'], true);
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hola']], 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])];
    }

    private function get($route){
        return rest_do_request(new WP_REST_Request('GET', '/mad-oficina/v1' . $route));
    }

    public function test_money_is_closed_by_default(){
        $this->assertNotContains('billing', PortalAreas::for_user($this->staff));
        $this->assertContains('meetings', PortalAreas::for_user($this->staff));
        $this->assertSame(array_keys(PortalAreas::AREAS), PortalAreas::for_user($this->make_admin()));

        wp_set_current_user($this->staff);
        $this->assertSame(403, $this->get('/portal/invoices')->get_status());
        $this->assertSame(200, $this->get('/portal/meetings')->get_status());
        $this->assertNotContains('billing', $this->get('/me')->get_data()['areas']);
    }

    public function test_closed_areas_are_refused_by_the_server(){
        PortalAreas::set($this->a, $this->staff, ['tasks']);
        wp_set_current_user($this->staff);
        $res = $this->get('/portal/meetings');
        $this->assertSame(403, $res->get_status());
        $this->assertSame('madof_area', $res->get_data()['code']);
        $this->assertSame(200, $this->get('/portal/tasks')->get_status());
        $this->assertSame(['tasks', 'billing'], PortalAreas::clean(['billing ', 'everything', 'tasks']), 'known areas only, canonical order');
    }

    public function test_whoever_pays_sees_only_their_issued_invoices(){
        wp_set_current_user($this->boss);
        $res = $this->get('/portal/invoices');
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertCount(1, $data['invoices'], 'drafts and other clients never appear');
        $this->assertSame('26-00' . $this->a, $data['invoices'][0]['number']);
        $this->assertSame(1, $data['pending']['count']);
        $json = wp_json_encode($data);
        $this->assertStringNotContainsString('margen interno', $json);
        $this->assertStringNotContainsString('B Sociedad', $json);
        $this->assertStringNotContainsString('Borrador', $json);

        $other = Invoices::all(['client_id' => $this->b, 'status' => 'sent'])[0];
        $this->assertSame(404, $this->get('/portal/invoices/' . $other['id'] . '/pdf')->get_status(), 'another client\'s invoice');
        $draft = Invoices::all(['client_id' => $this->a, 'status' => 'draft'])[0];
        $this->assertSame(404, $this->get('/portal/invoices/' . $draft['id'] . '/pdf')->get_status(), 'a draft');
    }

    public function test_receptionist_only_gets_the_tools_of_open_areas(){
        PortalAreas::set($this->a, $this->staff, ['tasks', 'ideas']);
        wp_set_current_user($this->staff);
        $req = new WP_REST_Request('POST', '/mad-oficina/v1/portal/chat');
        $req->set_param('message', '¿Qué hablamos en la última reunión?');
        $this->assertSame(200, rest_do_request($req)->get_status());
        $this->assertSame(['get_tasks', 'get_future_ideas'], array_column($this->requests[0]['tools'], 'name'));

        $r = new Receptionist([]);
        $this->assertSame(['error' => 'not_available_for_this_user'], $r->run_tool('get_meetings', $this->a, ['tasks']));

        PortalAreas::set($this->a, $this->staff, []);
        rest_do_request($req);
        $this->assertArrayNotHasKey('tools', $this->requests[1], 'no tools at all, not an empty list');
    }

    public function test_access_form_sets_what_a_new_person_sees(){
        wp_set_current_user($this->make_admin());
        $run = fn($action, $data) => Actions::dispatch($action, $data + ['_wpnonce' => wp_create_nonce('madof_' . $action)]);
        $run('user_add', ['client_id' => $this->a, 'email' => 'nueva@aaa.example', 'areas_sent' => 1, 'areas' => ['meetings', 'billing']]);
        $user = get_user_by('email', 'nueva@aaa.example');
        $this->assertSame(['meetings', 'billing'], PortalAreas::for_user($user->ID));

        $run('user_areas', ['client_id' => $this->a, 'user_id' => $user->ID, 'areas' => ['tasks']]);
        $this->assertSame(['tasks'], PortalAreas::for_user($user->ID));
        $res = $run('user_areas', ['client_id' => $this->b, 'user_id' => $user->ID, 'areas' => ['billing']]);
        $this->assertSame('error', $res['type'], 'only through the client the person belongs to');
        $this->assertSame(['tasks'], PortalAreas::for_user($user->ID));
    }

    public function test_previous_legal_name_keeps_its_history(){
        $old = Profiles::for_client($this->a)[0]['id'];
        $new = Profiles::save(['legal_name' => 'A Nueva Sociedad SL', 'client_id' => $this->a]);
        Profiles::set_status($old, 'inactive');
        $this->assertSame($new, Profiles::current_for_client($this->a)['id']);
        $this->assertSame([$new, $old], array_column(Profiles::for_client($this->a), 'id'), 'current one first');

        Invoices::insert(['series' => 'F', 'number' => '26-099', 'year' => 2026, 'seq' => 99, 'status' => 'sent', 'issue_date' => '2026-10-05', 'client_name' => 'A Nueva Sociedad SL', 'profile_id' => $new, 'base' => 10, 'total' => 10], [['description' => 'Fee', 'amount' => 10]]);
        wp_set_current_user($this->boss);
        $names = array_column($this->get('/portal/invoices')->get_data()['invoices'], 'legal_name');
        $this->assertSame(['A Nueva Sociedad SL', 'A Sociedad SL'], $names, 'both legal names, one history');
    }
}
