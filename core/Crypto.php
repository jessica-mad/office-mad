<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Authenticated encryption (libsodium secretbox) for tokens and secrets.
 *
 * Key: MAD_OFICINA_SECRET from wp-config.php. Without it, a key derived from the
 * WordPress salts is used (also outside the database, but rotating the salts
 * means reconnecting Google). There is never a hardcoded fallback key.
 */
final class Crypto {

    const PREFIX = 'mo1:';

    public static function has_dedicated_key(){
        return defined('MAD_OFICINA_SECRET') && strlen((string) MAD_OFICINA_SECRET) >= 32;
    }

    private static function key(){
        $material = self::has_dedicated_key() ? (string) MAD_OFICINA_SECRET : wp_salt('secure_auth') . wp_salt('logged_in');
        return sodium_crypto_generichash('mad-oficina|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt($plaintext){
        if ( $plaintext === null || $plaintext === '' ) return '';
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox((string) $plaintext, $nonce, self::key());
        return self::PREFIX . base64_encode($nonce . $box);
    }

    /** @return string|null null when the value is missing, tampered with or encrypted with another key. */
    public static function decrypt($ciphertext){
        if ( ! is_string($ciphertext) || strpos($ciphertext, self::PREFIX) !== 0 ) return null;
        $raw = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);
        if ( $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) return null;
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }
}
