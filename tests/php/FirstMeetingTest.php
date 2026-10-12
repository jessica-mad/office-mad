<?php
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Autonomy;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Visitors\FirstMeeting;
use MAD_Oficina\Visitors\Leads;

/**
 * Visitors asking for a first meeting: real slots, email confirmation, inbox
 * request that always asks, and the Calendar event on approval.
 */
class FirstMeetingTest extends MADOF_TestCase {

    private $busy = [];
    private $events = [];

    public function set_up(){
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(1, 250);
        reset_phpmailer_instance();
        delete_transient('madof_public_slots');
        $google = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        update_option(MAD_Oficina_Core::option_key('google'), [
            'refresh_token' => Crypto::encrypt('r'), 'access_token' => Crypto::encrypt('a'), 'expires_at' => time() + 3600, 'scopes' => $google->required_scopes(),
        ]);
        add_filter('pre_http_request', [$this, 'fake_google'], 10, 3);
        wp_set_current_user(0);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake_google']);
        delete_option(Autonomy::OPTION);
        parent::tear_down();
    }

    public function fake_google($pre, $args, $url){
        if ( strpos($url, 'freeBusy') !== false ) {
            $busy = array_map(fn($b) => ['start' => gmdate('c', $b[0]), 'end' => gmdate('c', $b[1])], $this->busy);
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['calendars' => ['primary' => ['busy' => $busy]]])];
        }
        if ( strpos($url, '/events?') !== false && $args['method'] === 'POST' ) {
            $this->events[] = ['url' => $url, 'body' => json_decode($args['body'], true)];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['id' => 'evt9', 'hangoutLink' => 'https://meet.google.com/xyz'])];
        }
        return $pre;
    }

    private function rest($method, $route, array $body = []){
        $req = new WP_REST_Request($method, '/mad-oficina/v1' . $route);
        if ( $body ) { $req->set_header('Content-Type', 'application/json'); $req->set_body(wp_json_encode($body)); }
        return rest_get_server()->dispatch($req);
    }

    private function ask(array $over = []){
        $slots = $this->rest('GET', '/public/slots')->get_data()['slots'];
        $res = $this->rest('POST', '/public/meeting', $over + ['start' => $slots[0]['start'], 'name' => 'Marta Ruiz', 'email' => 'marta@tallerazul.example', 'company' => 'Taller Azul', 'topic' => 'Renovar la tienda', 'consent' => 1]);
        return [$res, $slots];
    }

    private function confirm(){
        $mail = tests_retrieve_phpmailer_instance()->get_sent(0);
        preg_match('#lead=(\d+)&token=([a-f0-9]+)#', html_entity_decode($mail->body), $m);
        $lead = Leads::verify((int) $m[1], $m[2]);
        do_action('madoficina_lead_verified', $lead);
        return Leads::get($lead['id']);
    }

    public function test_visitors_get_thirty_minute_slots(){
        $data = $this->rest('GET', '/public/slots')->get_data();
        $this->assertSame(30, $data['duration']);
        $this->assertCount(FirstMeeting::OFFER, $data['slots']);
        $this->assertSame(30 * 60, $data['slots'][0]['end'] - $data['slots'][0]['start']);
        $this->assertArrayHasKey('label', $data['slots'][0]);
    }

    public function test_request_waits_for_email_then_reaches_the_inbox_and_holds_the_slot(){
        [$res, $slots] = $this->ask();
        $this->assertSame(200, $res->get_status());
        $mail = tests_retrieve_phpmailer_instance()->get_sent(0);
        $this->assertSame('Confirma tu petición de reunión con MAD', $mail->subject);
        $this->assertSame([], Approvals::pending(), 'nothing in the inbox before the email is confirmed');

        $lead = $this->confirm();
        $this->assertSame('meeting', $lead['source']);
        $a = Approvals::get($lead['approval_id']);
        $this->assertSame('first_meeting', $a['action_type']);
        $this->assertSame('pending', $a['status']);
        $this->assertNull($a['client_id']);
        $this->assertSame($slots[0]['start'], $a['payload']['start']);

        // The slot is no longer offered to anyone.
        delete_transient('madof_public_slots');
        $this->assertNotContains($slots[0]['start'], array_column($this->rest('GET', '/public/slots')->get_data()['slots'], 'start'));

        // Confirming twice does not create a second request.
        do_action('madoficina_lead_verified', Leads::get($lead['id']));
        $this->assertCount(1, Approvals::pending());
    }

    public function test_first_meetings_always_ask_even_with_autonomy(){
        Autonomy::save(['meeting_request' => 'silent', 'first_meeting' => 'silent']);
        $this->ask();
        $lead = $this->confirm();
        $this->assertSame('pending', Approvals::get($lead['approval_id'])['status']);
        $this->assertSame([], $this->events);
    }

    public function test_approval_creates_the_event_and_marks_the_lead(){
        $this->ask();
        $lead = $this->confirm();
        $r = Approvals::resolve($lead['approval_id'], 'approve', [], 1);
        $this->assertSame('https://meet.google.com/xyz', $r['meet']);
        $this->assertSame('Taller Azul x MAD · Primera reunión', $this->events[0]['body']['summary']);
        $this->assertSame([['email' => 'marta@tallerazul.example']], $this->events[0]['body']['attendees']);
        $this->assertSame(30 * 60, strtotime($this->events[0]['body']['end']['dateTime']) - strtotime($this->events[0]['body']['start']['dateTime']));
        $this->assertSame('contacted', Leads::get($lead['id'])['status']);
    }

    public function test_taken_slots_and_missing_data_are_refused(){
        $slots = $this->rest('GET', '/public/slots')->get_data()['slots'];
        $this->busy = [[$slots[0]['start'], $slots[0]['end']]];
        [$res] = $this->ask(['start' => $slots[0]['start']]);
        $this->assertSame(409, $res->get_status());
        $this->busy = [];
        [$res] = $this->ask(['consent' => 0]);
        $this->assertSame(400, $res->get_status());
        [$res] = $this->ask(['email' => 'nope']);
        $this->assertSame(400, $res->get_status());
    }

    public function test_without_calendar_visitors_are_sent_to_the_brief(){
        delete_option(MAD_Oficina_Core::option_key('google'));
        $data = $this->rest('GET', '/public/slots')->get_data();
        $this->assertSame([], $data['slots']);
        $this->assertStringContainsString('brief', $data['error']);
    }
}
