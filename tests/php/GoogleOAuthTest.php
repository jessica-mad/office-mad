<?php
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Google\OAuth;

class GoogleOAuthTest extends MADOF_TestCase {

    private $oauth;
    private $responses = [];

    public function set_up(){
        parent::set_up();
        remove_all_filters('madoficina_google_scopes'); // only the base scopes, whatever modules are on
        $this->oauth = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        wp_set_current_user($this->make_admin());
        $this->oauth->save_credentials('client-id.apps.googleusercontent.com', 'shh-secret', 'imad.example');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake_http']);
        parent::tear_down();
    }

    public function fake_http($pre, $args, $url){
        $this->responses[] = ['url' => $url, 'body' => $args['body'] ?? null];
        if ( $url === OAuth::REVOKE_URL ) return ['response' => ['code' => 200], 'body' => '{}'];
        $body = $args['body'];
        if ( $body['grant_type'] === 'refresh_token' ) {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 'fresh-access', 'expires_in' => 3600])];
        }
        $hd = $body['code'] === 'code-other-domain' ? 'gmail.example' : 'imad.example';
        $claims = rtrim(strtr(base64_encode(wp_json_encode(['email' => 'jessica@' . $hd, 'hd' => $hd])), '+/', '-_'), '=');
        return ['response' => ['code' => 200], 'body' => wp_json_encode([
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email', 'id_token' => "h.{$claims}.s",
        ])];
    }

    private function state_from(string $url){
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    public function test_client_secret_is_stored_encrypted_and_kept_when_left_empty(){
        $stored = $this->oauth->settings()['client_secret'];
        $this->assertSame('shh-secret', Crypto::decrypt($stored));
        $this->oauth->save_credentials('client-id.apps.googleusercontent.com', '', 'imad.example');
        $this->assertSame($stored, $this->oauth->settings()['client_secret']);
    }

    public function test_authorization_url_asks_for_offline_access_in_the_workspace_domain(){
        $q = $this->state_from($this->oauth->authorization_url());
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('imad.example', $q['hd']);
        $this->assertSame('openid email', $q['scope']);
        $this->assertSame(32, strlen($q['state']));
    }

    public function test_callback_rejects_wrong_state(){
        $this->oauth->authorization_url();
        $this->assertWPError($this->oauth->handle_callback('code', 'forged-state'));
        $this->assertFalse($this->oauth->is_connected());
    }

    public function test_callback_stores_encrypted_tokens(){
        $q = $this->state_from($this->oauth->authorization_url());
        $this->assertTrue($this->oauth->handle_callback('good-code', $q['state']));
        $this->assertTrue($this->oauth->is_connected());
        $this->assertSame('jessica@imad.example', $this->oauth->connected_email());
        $this->assertSame([], $this->oauth->missing_scopes());

        $raw = get_option(MAD_Oficina_Core::option_key('google'));
        $this->assertStringNotContainsString('refresh-1', wp_json_encode($raw));

        // State is single-use.
        $this->assertWPError($this->oauth->handle_callback('good-code', $q['state']));
    }

    public function test_callback_rejects_accounts_outside_the_domain_and_revokes(){
        $q = $this->state_from($this->oauth->authorization_url());
        $this->assertWPError($this->oauth->handle_callback('code-other-domain', $q['state']));
        $this->assertFalse($this->oauth->is_connected());
        $this->assertSame(OAuth::REVOKE_URL, end($this->responses)['url']);
    }

    public function test_access_token_refreshes_when_expired(){
        $q = $this->state_from($this->oauth->authorization_url());
        $this->oauth->handle_callback('good-code', $q['state']);
        $this->assertSame('access-1', $this->oauth->access_token());

        $s = get_option(MAD_Oficina_Core::option_key('google'));
        $s['expires_at'] = time() - 10;
        update_option(MAD_Oficina_Core::option_key('google'), $s);
        $this->assertSame('fresh-access', $this->oauth->access_token());
    }

    public function test_extra_scopes_from_modules_require_reconnecting(){
        $q = $this->state_from($this->oauth->authorization_url());
        $this->oauth->handle_callback('good-code', $q['state']);
        $add = fn($s) => array_merge($s, ['https://www.googleapis.com/auth/calendar.freebusy']);
        add_filter('madoficina_google_scopes', $add);
        $this->assertSame(['https://www.googleapis.com/auth/calendar.freebusy'], $this->oauth->missing_scopes());
        remove_filter('madoficina_google_scopes', $add);
    }
}
