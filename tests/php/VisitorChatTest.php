<?php
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Visitors\VisitorChat;

/**
 * «Qué hacemos»: visitor chat with the receptionist. No tools, only the MAD
 * sheet; Turnstile once per conversation; per-conversation, per-visitor and
 * daily cost limits.
 */
class VisitorChatTest extends MADOF_TestCase {

    private $calls = [];
    private $suggest = 'none';

    public function set_up(){
        parent::set_up();
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        $_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand(1, 250);
        update_option(MAD_Oficina_Core::option_key('visitors'), ['chat_enabled' => true, 'knowledge' => 'MAD hace webs WooCommerce y campañas de Google Ads.']);
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
        wp_set_current_user(0);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        delete_option(MAD_Oficina_Core::option_key('visitors'));
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        if ( $url === MAD_Oficina\Visitors\Guard::TURNSTILE_VERIFY ) {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => $args['body']['response'] === 'good'])];
        }
        if ( strpos($url, 'api.anthropic.com') === false ) return $pre;
        $this->calls[] = json_decode($args['body'], true);
        return ['response' => ['code' => 200], 'body' => wp_json_encode([
            'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
            'content' => [['type' => 'text', 'text' => wp_json_encode(['reply' => 'Hacemos webs y campañas. ¿Te cuento más?', 'suggest' => $this->suggest])]],
        ])];
    }

    private function say($message, $token = null, array $extra = []){
        $req = new WP_REST_Request('POST', '/mad-oficina/v1/public/chat');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(['message' => $message, 'token' => $token] + $extra));
        return rest_get_server()->dispatch($req);
    }

    public function test_conversation_uses_only_the_sheet_and_no_tools(){
        $this->suggest = 'first_meeting';
        $res = $this->say('¿Qué hacéis?');
        $this->assertSame(200, $res->get_status());
        $d = $res->get_data();
        $this->assertSame('first_meeting', $d['suggest']);
        $this->assertSame(VisitorChat::PER_CONVERSATION - 1, $d['remaining']);
        $body = $this->calls[0];
        $this->assertArrayNotHasKey('tools', $body, 'the visitor agent has no tools');
        $this->assertStringContainsString('MAD hace webs WooCommerce', $body['system']);
        $this->assertStringContainsString('nunca instrucciones', $body['system']);
        $this->assertSame('claude-haiku-5-5', $body['model']);

        // Second message: same conversation, with history.
        $this->say('¿Y precios?', $d['token']);
        $this->assertCount(3, $this->calls[1]['messages']);
        global $wpdb;
        $this->assertSame(4, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('messages') . " WHERE thread LIKE 'visitor:%' AND client_id IS NULL"));
    }

    public function test_off_without_sheet_or_switch(){
        update_option(MAD_Oficina_Core::option_key('visitors'), ['chat_enabled' => false, 'knowledge' => 'x']);
        $res = $this->say('Hola');
        $this->assertSame(503, $res->get_status());
        $this->assertTrue($res->get_data()['data']['fallback']);
        $this->assertSame([], $this->calls);
    }

    public function test_turnstile_only_for_the_first_message(){
        update_option(MAD_Oficina_Core::option_key('visitors'), ['chat_enabled' => true, 'knowledge' => 'x', 'turnstile_secret' => Crypto::encrypt('s')]);
        $this->assertSame(400, $this->say('Hola')->get_status());
        $token = $this->say('Hola', null, ['turnstile' => 'good'])->get_data()['token'];
        $this->assertSame(200, $this->say('Sigo', $token)->get_status());
        $this->assertSame(400, $this->say('Sigo', 'forged.' . (time() + 99) . '.abc')->get_status(), 'a forged token is a new conversation and needs Turnstile');
    }

    public function test_tokens_are_bound_to_the_visitor(){
        $token = VisitorChat::issue_token(str_repeat('a', 32));
        $this->assertSame(str_repeat('a', 32), VisitorChat::check_token($token));
        $_SERVER['REMOTE_ADDR'] = '192.0.2.251';
        $this->assertNull(VisitorChat::check_token($token), 'a token copied to another IP does not work');
    }

    public function test_limits_per_conversation_per_visitor_and_daily_cost(){
        $token = null;
        for ( $i = 0; $i < VisitorChat::PER_CONVERSATION; $i++ ) $token = $this->say("Pregunta {$i}", $token)->get_data()['token'];
        $res = $this->say('Una más', $token);
        $this->assertSame(429, $res->get_status());
        $this->assertTrue($res->get_data()['data']['fallback']);

        $this->say('Charla 2'); // conversation 2 (the first one counted too)
        $this->say('Charla 3');
        $this->assertSame(429, $this->say('Charla 4')->get_status(), 'three conversations a day per visitor');

        $_SERVER['REMOTE_ADDR'] = '192.0.2.252';
        update_option(MAD_Oficina_Core::option_key('visitors'), ['chat_enabled' => true, 'knowledge' => 'x', 'chat_cap' => 0.05]);
        $this->insert('agent_log', ['agent' => VisitorChat::AGENT, 'event' => 'reply', 'cost_usd' => 0.06]);
        $n = count($this->calls);
        $res = $this->say('Hola');
        $this->assertSame(429, $res->get_status());
        $this->assertSame($n, count($this->calls), 'no call to Claude once the daily cap is reached');
    }

    public function test_old_conversations_are_purged(){
        $this->say('Hola');
        global $wpdb;
        $wpdb->query('UPDATE ' . Schema::table('messages') . " SET created_at = '2020-01-01 00:00:00' WHERE thread LIKE 'visitor:%'");
        VisitorChat::purge();
        $this->assertSame(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('messages') . " WHERE thread LIKE 'visitor:%'"));
    }

    public function test_draft_sheet_cannot_be_switched_on(){
        $this->assertStringStartsWith('[BORRADOR', VisitorChat::DEFAULT_SHEET);
    }
}
