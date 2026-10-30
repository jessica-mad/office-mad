<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * What goes with a quote for quick access: links (Drive, Canva, a proposal page…) and the
 * quote document itself (PDF or image), kept in a private folder (not web-readable) and
 * opened only through a nonce-protected link for whoever sees Finanzas.
 */
final class QuoteFiles {

    const MAX_BYTES = 15 * MB_IN_BYTES;
    const TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public static function dir(){
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'mad-oficina/quotes';
        if ( ! is_dir($dir) ) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    private static function path($key, $ext){
        // Name derived from a secret: the folder cannot be enumerated even if the server ignores .htaccess.
        return self::dir() . '/' . preg_replace('/[^a-z0-9]/', '', $key) . '-' . substr(hash_hmac('sha256', $key, wp_salt('auth')), 0, 24) . '.' . $ext;
    }

    /** @return array the quote's attachments [{id, type: link|file, name, url|key+ext, at}] */
    public static function of(array $q){
        $a = json_decode((string) ($q['attachments'] ?? ''), true);
        return is_array($a) ? $a : [];
    }

    private static function store_list($quote_id, array $list){
        global $wpdb;
        $wpdb->update(Quotes::table(), ['attachments' => $list ? wp_json_encode(array_values($list)) : null, 'updated_at' => Time::now()], ['id' => (int) $quote_id]);
    }

    public static function add_link($quote_id, $url, $name = ''){
        $q = Quotes::get($quote_id);
        if ( ! $q ) return new \WP_Error('madof_quote', __('Ese presupuesto no existe.', 'mad-oficina'));
        $url = esc_url_raw(trim((string) $url), ['https', 'http']);
        if ( ! $url ) return new \WP_Error('madof_quote_link', __('Pega un enlace que empiece por https://', 'mad-oficina'));
        $list = self::of($q);
        $list[] = ['id' => wp_generate_password(10, false), 'type' => 'link', 'name' => mb_substr(sanitize_text_field($name), 0, 120) ?: (wp_parse_url($url, PHP_URL_HOST) ?: $url), 'url' => $url, 'at' => Time::now()];
        self::store_list($quote_id, $list);
        return true;
    }

    /** @param array $file one entry of $_FILES */
    public static function add_file($quote_id, array $file, $name = ''){
        $q = Quotes::get($quote_id);
        if ( ! $q ) return new \WP_Error('madof_quote', __('Ese presupuesto no existe.', 'mad-oficina'));
        if ( empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name']) ) return new \WP_Error('madof_quote_file', __('Elige el archivo del presupuesto.', 'mad-oficina'));
        if ( (int) $file['size'] > self::MAX_BYTES ) return new \WP_Error('madof_quote_file', __('El archivo es demasiado grande (máximo 15 MB).', 'mad-oficina'));
        $bytes = (string) file_get_contents($file['tmp_name']);
        $ext = self::sniff($bytes);
        if ( ! $ext ) return new \WP_Error('madof_quote_file', __('Solo PDF o imágenes (PNG, JPG, WebP).', 'mad-oficina'));
        $key = strtolower(wp_generate_password(12, false));
        if ( ! file_put_contents(self::path($key, $ext), $bytes) ) return new \WP_Error('madof_quote_file', __('No he podido guardar el archivo.', 'mad-oficina'));
        $list = self::of($q);
        $list[] = ['id' => $key, 'type' => 'file', 'name' => mb_substr(sanitize_text_field($name), 0, 120) ?: sanitize_file_name((string) $file['name']), 'key' => $key, 'ext' => $ext, 'at' => Time::now()];
        self::store_list($quote_id, $list);
        return true;
    }

    /** By its first bytes, never by the name. */
    public static function sniff($bytes){
        if ( strncmp($bytes, '%PDF', 4) === 0 ) return 'pdf';
        if ( strncmp($bytes, "\x89PNG", 4) === 0 ) return 'png';
        if ( strncmp($bytes, "\xFF\xD8\xFF", 3) === 0 ) return 'jpg';
        if ( strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP' ) return 'webp';
        return null;
    }

    public static function remove($quote_id, $att_id){
        $q = Quotes::get($quote_id);
        if ( ! $q ) return false;
        $list = self::of($q);
        foreach ( $list as $i => $a ) {
            if ( $a['id'] !== $att_id ) continue;
            if ( $a['type'] === 'file' && file_exists($p = self::path($a['key'], $a['ext'])) ) @unlink($p);
            unset($list[$i]);
            self::store_list($quote_id, $list);
            return true;
        }
        return false;
    }

    /** @return array{bytes:string, mime:string, name:string}|null */
    public static function read($quote_id, $att_id){
        $q = Quotes::get($quote_id);
        foreach ( $q ? self::of($q) : [] as $a ) {
            if ( $a['id'] !== $att_id || $a['type'] !== 'file' ) continue;
            $p = self::path($a['key'], $a['ext']);
            return is_readable($p) ? ['bytes' => file_get_contents($p), 'mime' => self::TYPES[$a['ext']], 'name' => preg_replace('/\.[a-z0-9]+$/i', '', $a['name']) . '.' . $a['ext']] : null;
        }
        return null;
    }

    public static function url($quote_id, array $a){
        return $a['type'] === 'link' ? $a['url'] : wp_nonce_url(admin_url('admin-post.php?action=madof_billing_quote_file&quote=' . (int) $quote_id . '&att=' . rawurlencode($a['id'])), 'madof_billing_quote_file_' . (int) $quote_id);
    }
}
