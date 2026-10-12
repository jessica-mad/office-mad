<?php
namespace MAD_Oficina\Visitors;

if ( ! defined('ABSPATH') ) exit;

/**
 * Protection for the public endpoints: Cloudflare Turnstile, per-IP rate
 * limits (IP stored only as a salted hash) and a honeypot field.
 */
final class Guard {

    const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const HONEYPOT = 'fax'; // hidden field: people leave it empty, bots fill it

    /** The site sits behind Cloudflare, which sends the real visitor IP. */
    public static function ip(){
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function ip_hash(){
        return hash('sha256', self::ip() . '|' . wp_salt('nonce'));
    }

    /**
     * Counts one hit and says whether it is still within the limit.
     * @param string $bucket e.g. "brief"
     */
    public static function allow($bucket, $limit, $window = DAY_IN_SECONDS, $key = null){
        $key = 'madof_rl_' . md5($bucket . '|' . ($key ?? self::ip_hash()));
        $hits = (int) get_transient($key);
        if ( $hits >= $limit ) return false;
        set_transient($key, $hits + 1, $window);
        return true;
    }

    public static function is_bot(array $params){
        return trim((string) ($params[self::HONEYPOT] ?? '')) !== '';
    }

    /**
     * @param string $secret Turnstile secret (empty = Turnstile not configured, accepted)
     * @return true|\WP_Error
     */
    public static function turnstile($secret, $token){
        if ( $secret === '' ) return true;
        if ( ! is_string($token) || $token === '' ) {
            return new \WP_Error('madof_turnstile', __('Confirma que no eres un robot y vuelve a enviarlo.', 'mad-oficina'), ['status' => 400]);
        }
        $res = wp_remote_post(self::TURNSTILE_VERIFY, ['timeout' => 10, 'body' => ['secret' => $secret, 'response' => $token, 'remoteip' => self::ip()]]);
        $data = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
        if ( empty($data['success']) ) {
            return new \WP_Error('madof_turnstile', __('No hemos podido comprobar que no eres un robot. Recarga la página e inténtalo otra vez.', 'mad-oficina'), ['status' => 400]);
        }
        return true;
    }
}
