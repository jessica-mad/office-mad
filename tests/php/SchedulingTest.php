<?php
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Autonomy;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Scheduling\Slots;

class SchedulingTest extends MADOF_TestCase {

    private $busy = [];
    private $calls = [];
    private $tz;

    public function set_up(){
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        $this->tz = wp_timezone();
        $google = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        update_option(MAD_Oficina_Core::option_key('google'), [
            'refresh_token' => Crypto::encrypt('refresh'), 'access_token' => Crypto::encrypt('access'),
            'expires_at' => time() + 3600, 'scopes' => $google->required_scopes(),
        ]);
        add_filter('pre_http_request', [$this, 'fake_google'], 10, 3);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake_google']);
        delete_option(Autonomy::OPTION);
        parent::tear_down();
    }

    public function fake_google($pre, $args, $url){
        $body = isset($args['body']) ? json_decode($args['body'], true) : null;
        $this->calls[] = ['url' => $url, 'body' => $body];
        if ( strpos($url, 'freeBusy') !== false ) {
            $busy = array_map(fn($b) => ['start' => gmdate('c', $b[0]), 'end' => gmdate('c', $b[1])], $this->busy);
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['calendars' => ['primary' => ['busy' => $busy]]])];
        }
        if ( strpos($url, '/events?') !== false && $args['method'] === 'POST' ) {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['id' => 'evt1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])];
        }
        if ( strpos($url, 'api.anthropic.com') !== false ) {
            $next = (new DateTimeImmutable('next thursday', $this->tz))->modify('+7 days');
            return ['response' => ['code' => 200], 'body' => wp_json_encode([
                'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'content' => [['type' => 'text', 'text' => wp_json_encode(['windows' => [['date' => $next->format('Y-m-d'), 'from' => '15:00', 'to' => '20:00']]])]],
            ])];
        }
        return $pre;
    }

    private function ts($text){ return (new DateTimeImmutable($text, $this->tz))->getTimestamp(); }

    private function module(){ return MAD_Oficina_Core::instance()->get_module('scheduling'); }

    private function rest($method, $route, array $params = []){
        $req = new WP_REST_Request($method, '/mad-oficina/v1' . $route);
        foreach ( $params as $k => $v ) $req->set_param($k, $v);
        return rest_get_server()->dispatch($req);
    }

    /* ───── slot engine ───── */

    public function test_slots_respect_hours_notice_buffer_and_busy_time(){
        $now = $this->ts('2026-10-12 09:00'); // Monday
        $rules = ['horizon_days' => 2, 'min_notice' => 24];
        $busy = [[$this->ts('2026-10-13 11:00'), $this->ts('2026-10-13 12:00')]];
        $slots = Slots::compute($rules, $busy, $now, $this->tz);
        $labels = array_map(fn($s) => wp_date('D H:i', $s['start'], $this->tz), $slots);

        $this->assertNotContains('Mon 10:00', $labels, 'less than 24 h notice');
        $this->assertContains('Tue 10:00', $labels);
        $this->assertNotContains('Tue 10:30', $labels, '10:30-11:15 collides with the busy block');
        $this->assertNotContains('Tue 12:00', $labels, '15 min buffer after the busy block');
        $this->assertContains('Tue 12:30', $labels);
        $this->assertNotContains('Tue 13:30', $labels, 'would end after 14:00');
        $this->assertContains('Tue 16:00', $labels);
        $this->assertSame(45 * 60, $slots[0]['end'] - $slots[0]['start']);
    }

    public function test_full_weekends_and_blocked_days_offer_nothing(){
        $now = $this->ts('2026-10-15 09:00'); // Thursday
        $busy = [];
        foreach ( ['10:00', '12:00', '16:00'] as $h ) $busy[] = [$this->ts("2026-10-16 {$h}"), $this->ts("2026-10-16 {$h}") + 1800];
        $slots = Slots::compute(['horizon_days' => 5, 'blocked' => ['2026-10-19']], $busy, $now, $this->tz);
        $days = array_unique(array_map(fn($s) => wp_date('Y-m-d', $s['start'], $this->tz), $slots));
        $this->assertNotContains('2026-10-16', $days, 'Friday has 3 meetings already');
        $this->assertNotContains('2026-10-17', $days);
        $this->assertNotContains('2026-10-18', $days);
        $this->assertNotContains('2026-10-19', $days, 'blocked');
        $this->assertContains('2026-10-20', $days);
    }

    public function test_offer_spreads_over_days(){
        $slots = Slots::compute(['horizon_days' => 10], [], $this->ts('2026-10-12 09:00'), $this->tz);
        $offer = Slots::offer($slots, 5, $this->tz);
        $this->assertCount(5, $offer);
        $days = array_unique(array_map(fn($s) => wp_date('Y-m-d', $s['start'], $this->tz), $offer));
        $this->assertCount(5, $days);
    }

    /* ───── portal flow ───── */

    public function test_client_requests_a_free_slot_and_it_waits_in_the_inbox(){
        $client = $this->make_client('MOM', 'Museum of Mini');
        wp_set_current_user($this->make_client_user($client));

        $res = $this->rest('GET', '/portal/meeting-requests');
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['can_book']);
        $this->assertNotEmpty($data['slots']);
        $start = $data['slots'][0]['start'];

        $res = $this->rest('POST', '/portal/meeting-requests', ['start' => $start, 'topic' => 'Revisar la web']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('pending', $res->get_data()['status']);
        $approval = Approvals::get($res->get_data()['request']);
        $this->assertSame($client, $approval['client_id']);
        $this->assertSame('meeting_request', $approval['action_type']);
        $this->assertEmpty(array_filter($this->calls, fn($c) => strpos($c['url'], '/events?') !== false), 'nothing is created before approval');

        // Only one pending request per client.
        $this->assertSame(409, $this->rest('POST', '/portal/meeting-requests', ['start' => $data['slots'][1]['start']])->get_status());
        $this->assertSame('pending', $this->rest('GET', '/portal/meeting-requests')->get_data()['request']['status']);
    }

    public function test_a_slot_that_is_not_free_is_refused(){
        $client = $this->make_client('MOM');
        wp_set_current_user($this->make_client_user($client));
        $this->assertSame(409, $this->rest('POST', '/portal/meeting-requests', ['start' => $this->ts('next sunday 03:00')])->get_status());
    }

    public function test_the_client_comes_from_the_session_not_the_request(){
        $mine = $this->make_client('AAA');
        $other = $this->make_client('BBB');
        wp_set_current_user($this->make_client_user($mine));
        $start = $this->rest('GET', '/portal/meeting-requests')->get_data()['slots'][0]['start'];
        $res = $this->rest('POST', '/portal/meeting-requests', ['start' => $start, 'client' => $other]);
        $this->assertSame($mine, Approvals::get($res->get_data()['request'])['client_id']);
    }

    public function test_managers_preview_but_cannot_request(){
        $client = $this->make_client('MOM');
        wp_set_current_user($this->make_admin());
        $data = $this->rest('GET', '/portal/meeting-requests', ['client' => $client])->get_data();
        $this->assertFalse($data['can_book']);
        $this->assertSame(403, $this->rest('POST', '/portal/meeting-requests', ['start' => time() + DAY_IN_SECONDS])->get_status());
        wp_set_current_user(0);
        $this->assertSame(401, $this->rest('GET', '/portal/meeting-requests')->get_status());
    }

    public function test_approval_rechecks_the_calendar_and_creates_the_meet_event(){
        $client = $this->make_client('MOM', 'Museum of Mini');
        wp_set_current_user($this->make_client_user($client));
        $start = $this->rest('GET', '/portal/meeting-requests')->get_data()['slots'][0]['start'];
        $id = $this->rest('POST', '/portal/meeting-requests', ['start' => $start])->get_data()['request'];

        // Jessica booked something in the meantime.
        $this->busy = [[$start, $start + 1800]];
        $this->assertWPError(Approvals::resolve($id, 'approve', []));
        $this->assertSame('pending', Approvals::get($id)['status']);

        $this->busy = [[$start + 3 * HOUR_IN_SECONDS, $start + 4 * HOUR_IN_SECONDS]]; // busy later that day: no clash
        $result = Approvals::resolve($id, 'approve', ['attendees' => 'ana@mom.example, not-an-email']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['meet']);
        $event = array_values(array_filter($this->calls, fn($c) => strpos($c['url'], '/events?') !== false))[0];
        $this->assertStringContainsString('conferenceDataVersion=1', $event['url']);
        $this->assertStringContainsString('sendUpdates=all', $event['url']);
        $this->assertSame('MOM x MAD', $event['body']['summary']);
        $this->assertSame([['email' => 'ana@mom.example']], $event['body']['attendees']);
        $this->assertSame('approved', $this->rest('GET', '/portal/meeting-requests')->get_data()['request']['status']);
    }

    public function test_autonomy_creates_the_meeting_without_asking(){
        Autonomy::save(['meeting_request' => 'silent']);
        $client = $this->make_client('MOM');
        wp_set_current_user($this->make_client_user($client));
        $start = $this->rest('GET', '/portal/meeting-requests')->get_data()['slots'][0]['start'];
        $res = $this->rest('POST', '/portal/meeting-requests', ['start' => $start]);
        $this->assertSame('approved', $res->get_data()['status']);
        $this->assertSame('auto', Approvals::get($res->get_data()['request'])['channel']);
    }

    public function test_autonomy_only_applies_to_adjustable_actions(){
        Autonomy::save(['meeting_request' => 'silent', 'meeting_draft' => 'silent']);
        $this->assertSame('silent', Autonomy::level('meeting_request'));
        $this->assertSame('ask', Autonomy::level('meeting_draft'));
        Autonomy::save(['meeting_request' => 'yolo']);
        $this->assertSame('ask', Autonomy::level('meeting_request'));
    }

    public function test_free_text_availability_is_matched_against_live_slots(){
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        $client = $this->make_client('MOM');
        wp_set_current_user($this->make_client_user($client));
        $res = $this->rest('POST', '/portal/meeting-requests/interpret', ['text' => 'el jueves por la tarde']);
        $this->assertSame(200, $res->get_status());
        $slots = $res->get_data()['slots'];
        $this->assertNotEmpty($slots);
        foreach ( $slots as $s ) {
            $this->assertSame('Thu', wp_date('D', $s['start'], $this->tz));
            $this->assertGreaterThanOrEqual('15:00', wp_date('H:i', $s['start'], $this->tz));
        }
    }
}
