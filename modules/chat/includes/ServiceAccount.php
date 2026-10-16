<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Crypto;

if ( ! defined('ABSPATH') ) exit;

/**
 * The bot's own identity (Google Cloud service account). The JSON key is stored
 * encrypted; access tokens are cached encrypted for their lifetime.
 */
final class ServiceAccount {

    const SCOPE = 'https://www.googleapis.com/auth/chat.bot';
    /** Speech-to-Text (voice messages). Needs the API enabled and the «Cloud Speech Client» role. */
    const SCOPE_CLOUD = 'https://www.googleapis.com/auth/cloud-platform';

    private $key;

    /** @param array $key decoded service-account JSON */
    public function __construct(array $key){
        $this->key = $key;
    }

    public static function from_settings(array $settings){
        $json = Crypto::decrypt($settings['service_account'] ?? '');
        $key = $json ? json_decode($json, true) : null;
        return is_array($key) && ! empty($key['client_email']) && ! empty($key['private_key']) ? new self($key) : null;
    }

    /** @return true|\WP_Error */
    public static function validate_json($json){
        $key = json_decode((string) $json, true);
        if ( ! is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['private_key']) || empty($key['client_email']) ) {
            return new \WP_Error('madof_sa', __('No es una clave JSON de cuenta de servicio válida.', 'mad-oficina'));
        }
        if ( ! openssl_pkey_get_private($key['private_key']) ) {
            return new \WP_Error('madof_sa', __('La clave privada de la cuenta de servicio no se puede leer.', 'mad-oficina'));
        }
        return true;
    }

    public function email(){ return $this->key['client_email']; }

    public function project_id(){ return (string) ($this->key['project_id'] ?? ''); }

    /** @return string|\WP_Error */
    public function access_token($scope = self::SCOPE){
        $cache_key = 'madof_chat_token_' . md5($this->key['client_email'] . ($scope === self::SCOPE ? '' : '|' . $scope));
        $cached = Crypto::decrypt(get_transient($cache_key) ?: '');
        if ( $cached ) return $cached;

        $now = time();
        $assertion = GoogleJwt::sign([
            'iss'   => $this->key['client_email'],
            'scope' => $scope,
            'aud'   => $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $this->key['private_key'], $this->key['private_key_id'] ?? null);
        if ( ! $assertion ) return new \WP_Error('madof_sa', __('No se pudo firmar con la clave de la cuenta de servicio.', 'mad-oficina'));

        $res = wp_remote_post($this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
        ]);
        if ( is_wp_error($res) ) return $res;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ( empty($data['access_token']) ) {
            return new \WP_Error('madof_sa', sprintf(__('Google no ha dado acceso al bot: %s', 'mad-oficina'), $data['error_description'] ?? ($data['error'] ?? wp_remote_retrieve_response_code($res))));
        }
        set_transient($cache_key, Crypto::encrypt($data['access_token']), max(60, (int) ($data['expires_in'] ?? 3600) - 120));
        return $data['access_token'];
    }
}
