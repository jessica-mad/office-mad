<?php
namespace MAD_Oficina\Google;

use MAD_Oficina\Core\Crypto;

if ( ! defined('ABSPATH') ) exit;

/**
 * OAuth 2.0 with Google for Jessica's Workspace account (Internal app).
 *
 * Scopes are requested incrementally: each phase adds what it needs through
 * required_scopes() and the Connections page asks to reconnect when they grow.
 * Refresh and access tokens are stored encrypted.
 */
final class OAuth {

    const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Base scopes: only identify the connected account. */
    const BASE_SCOPES = ['openid', 'email'];

    private $option_key;

    public function __construct($option_key){
        $this->option_key = $option_key;
    }

    /* ===== Settings ===== */

    public function settings(){
        $s = get_option($this->option_key, []);
        return is_array($s) ? $s : [];
    }

    private function update(array $changes){
        update_option($this->option_key, array_merge($this->settings(), $changes), false);
    }

    public function save_credentials($client_id, $client_secret, $domain){
        $changes = [
            'client_id'        => sanitize_text_field($client_id),
            'workspace_domain' => strtolower(sanitize_text_field($domain)),
        ];
        // Empty secret field = keep the stored one (it is never printed back).
        if ( $client_secret !== '' ) $changes['client_secret'] = Crypto::encrypt(sanitize_text_field($client_secret));
        $this->update($changes);
    }

    public function has_credentials(){
        $s = $this->settings();
        return ! empty($s['client_id']) && ! empty($s['client_secret']);
    }

    public function redirect_uri(){
        return admin_url('admin.php?page=mad-oficina-conexiones&action=google_oauth_callback');
    }

    /** Scopes needed by the enabled modules (filterable so each module adds its own). */
    public function required_scopes(){
        $scopes = apply_filters('madoficina_google_scopes', self::BASE_SCOPES);
        return array_values(array_unique($scopes));
    }

    public function granted_scopes(){
        return $this->settings()['scopes'] ?? [];
    }

    public function missing_scopes(){
        // Google returns openid/email as full URLs in the granted list.
        $granted = array_map(fn($s) => str_replace('https://www.googleapis.com/auth/userinfo.', '', $s), $this->granted_scopes());
        return array_values(array_diff($this->required_scopes(), $granted));
    }

    public function is_connected(){
        return (bool) Crypto::decrypt($this->settings()['refresh_token'] ?? '');
    }

    public function connected_email(){
        return $this->settings()['connected_email'] ?? '';
    }

    /* ===== Flow ===== */

    public function authorization_url(){
        $state = wp_generate_password(32, false);
        set_transient('madof_google_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        $s = $this->settings();
        $params = [
            'client_id'              => $s['client_id'] ?? '',
            'redirect_uri'           => $this->redirect_uri(),
            'response_type'          => 'code',
            'scope'                  => implode(' ', $this->required_scopes()),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ];
        if ( ! empty($s['workspace_domain']) ) $params['hd'] = $s['workspace_domain'];
        return self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return true|\WP_Error */
    public function handle_callback($code, $state){
        $key = 'madof_google_state_' . get_current_user_id();
        $expected = get_transient($key);
        delete_transient($key);
        if ( ! $expected || ! hash_equals($expected, (string) $state) ) {
            return new \WP_Error('madof_google_state', __('La sesión de conexión ha caducado o no es válida. Vuelve a intentarlo.', 'mad-oficina'));
        }

        $s = $this->settings();
        $response = $this->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $s['client_id'] ?? '',
            'client_secret' => Crypto::decrypt($s['client_secret'] ?? '') ?? '',
            'redirect_uri'  => $this->redirect_uri(),
            'grant_type'    => 'authorization_code',
        ]);
        if ( is_wp_error($response) ) return $response;
        if ( empty($response['refresh_token']) ) {
            return new \WP_Error('madof_google_no_refresh', __('Google no ha devuelto un token de actualización. Quita el acceso de la app en tu cuenta de Google y vuelve a conectar.', 'mad-oficina'));
        }

        // The id_token comes straight from Google's token endpoint over TLS, so its claims can be read without re-verifying the signature.
        $claims = self::decode_jwt_payload($response['id_token'] ?? '');
        $email  = strtolower($claims['email'] ?? '');
        $domain = $s['workspace_domain'] ?? '';
        if ( $domain && ( ($claims['hd'] ?? '') !== $domain ) ) {
            $this->revoke($response['refresh_token']);
            return new \WP_Error('madof_google_domain', sprintf(__('Conecta una cuenta de %s (has usado %s).', 'mad-oficina'), $domain, $email ?: '?'));
        }

        $this->update([
            'refresh_token'   => Crypto::encrypt($response['refresh_token']),
            'access_token'    => Crypto::encrypt($response['access_token'] ?? ''),
            'expires_at'      => time() + (int) ($response['expires_in'] ?? 0) - 60,
            'scopes'          => explode(' ', $response['scope'] ?? ''),
            'connected_email' => $email,
            'connected_at'    => time(),
        ]);
        return true;
    }

    /** @return string|\WP_Error a valid access token, refreshed when needed. */
    public function access_token(){
        $s = $this->settings();
        $token = Crypto::decrypt($s['access_token'] ?? '');
        if ( $token && (int) ($s['expires_at'] ?? 0) > time() ) return $token;

        $refresh = Crypto::decrypt($s['refresh_token'] ?? '');
        if ( ! $refresh ) return new \WP_Error('madof_google_disconnected', __('Google no está conectado.', 'mad-oficina'));

        $response = $this->post(self::TOKEN_URL, [
            'client_id'     => $s['client_id'] ?? '',
            'client_secret' => Crypto::decrypt($s['client_secret'] ?? '') ?? '',
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);
        if ( is_wp_error($response) ) return $response;

        $this->update([
            'access_token' => Crypto::encrypt($response['access_token']),
            'expires_at'   => time() + (int) ($response['expires_in'] ?? 0) - 60,
        ]);
        return $response['access_token'];
    }

    public function disconnect(){
        $refresh = Crypto::decrypt($this->settings()['refresh_token'] ?? '');
        if ( $refresh ) $this->revoke($refresh);
        $this->update(['refresh_token' => '', 'access_token' => '', 'expires_at' => 0, 'scopes' => [], 'connected_email' => '', 'connected_at' => 0]);
    }

    private function revoke($token){
        wp_remote_post(self::REVOKE_URL, ['body' => ['token' => $token], 'timeout' => 10]);
    }

    /** @return array|\WP_Error */
    private function post($url, array $body){
        $res = wp_remote_post($url, ['body' => $body, 'timeout' => 15]);
        if ( is_wp_error($res) ) return $res;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $code = wp_remote_retrieve_response_code($res);
        if ( $code !== 200 || ! is_array($data) ) {
            $msg = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : '';
            return new \WP_Error('madof_google_http', sprintf(__('Google respondió %1$d: %2$s', 'mad-oficina'), $code, $msg));
        }
        return $data;
    }

    public static function decode_jwt_payload($jwt){
        $parts = explode('.', (string) $jwt);
        if ( count($parts) !== 3 ) return [];
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $json ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }
}
