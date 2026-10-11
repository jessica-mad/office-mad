<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * Minimal RS256 JWT verification/signing with OpenSSL (no Composer).
 *
 * Google Chat signs every event it sends to the app with a JWT whose issuer is
 * chat@system.gserviceaccount.com and whose audience is the Cloud project number.
 */
final class GoogleJwt {

    const CHAT_ISSUER = 'chat@system.gserviceaccount.com';
    const CHAT_CERTS  = 'https://www.googleapis.com/service_accounts/v1/metadata/x509/chat@system.gserviceaccount.com';

    public static function b64url_decode($s){
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    public static function b64url_encode($s){
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** @return array<string,string> kid => PEM certificate */
    public static function chat_certs(){
        $certs = apply_filters('madoficina_chat_certs', null);
        if ( is_array($certs) ) return $certs;
        $cached = get_transient('madof_chat_certs');
        if ( is_array($cached) ) return $cached;
        $res = wp_remote_get(self::CHAT_CERTS, ['timeout' => 10]);
        $certs = is_wp_error($res) ? [] : (json_decode(wp_remote_retrieve_body($res), true) ?: []);
        if ( $certs ) set_transient('madof_chat_certs', $certs, HOUR_IN_SECONDS);
        return $certs;
    }

    /**
     * @return array|\WP_Error claims
     */
    public static function verify($jwt, $audience, $issuer, array $certs){
        $parts = explode('.', (string) $jwt);
        if ( count($parts) !== 3 ) return new \WP_Error('madof_jwt', 'Malformed token', ['status' => 401]);
        [$h, $p, $sig] = $parts;
        $header = json_decode(self::b64url_decode($h), true);
        $claims = json_decode(self::b64url_decode($p), true);
        if ( ! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' ) {
            return new \WP_Error('madof_jwt', 'Unsupported token', ['status' => 401]);
        }
        $cert = $certs[$header['kid'] ?? ''] ?? null;
        if ( ! $cert ) return new \WP_Error('madof_jwt', 'Unknown signing key', ['status' => 401]);
        $key = openssl_pkey_get_public($cert);
        if ( ! $key || openssl_verify($h . '.' . $p, self::b64url_decode($sig), $key, OPENSSL_ALGO_SHA256) !== 1 ) {
            return new \WP_Error('madof_jwt', 'Bad signature', ['status' => 401]);
        }
        $now = time();
        if ( ($claims['iss'] ?? '') !== $issuer ) return new \WP_Error('madof_jwt', 'Wrong issuer', ['status' => 401]);
        if ( (string) ($claims['aud'] ?? '') !== (string) $audience || $audience === '' ) return new \WP_Error('madof_jwt', 'Wrong audience', ['status' => 401]);
        if ( (int) ($claims['exp'] ?? 0) < $now - 60 || (int) ($claims['iat'] ?? $now) > $now + 300 ) return new \WP_Error('madof_jwt', 'Expired token', ['status' => 401]);
        return $claims;
    }

    public static function sign(array $claims, $private_key_pem, $kid = null){
        $header = ['alg' => 'RS256', 'typ' => 'JWT'] + ($kid ? ['kid' => $kid] : []);
        $input = self::b64url_encode(wp_json_encode($header)) . '.' . self::b64url_encode(wp_json_encode($claims));
        $key = openssl_pkey_get_private($private_key_pem);
        if ( ! $key || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256) ) return null;
        return $input . '.' . self::b64url_encode($signature);
    }
}
