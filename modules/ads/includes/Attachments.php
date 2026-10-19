<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Images attached to a change in the log (screenshots of the campaign before and after,
 * the client's message…). Private folder, not web-readable: they are served only to the
 * office, through a signed link. Images only.
 */
final class Attachments {

    const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    const MAX_BYTES = 8 * MB_IN_BYTES;
    const MAX_PER_CHANGE = 12;

    public static function dir(){
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'mad-oficina/ads';
        if ( ! is_dir($dir) ) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    /** @return array[] [f, name, type, at] */
    public static function of($change_id){
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare('SELECT attachments FROM ' . Changes::table() . ' WHERE id = %d', $change_id));
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    private static function save($change_id, array $list){
        global $wpdb;
        $wpdb->update(Changes::table(), ['attachments' => $list ? wp_json_encode(array_values($list)) : null, 'updated_at' => Time::now()], ['id' => (int) $change_id]);
    }

    /** @return string|\WP_Error the image type, after looking at the bytes (never the name). */
    public static function check($bytes){
        if ( $bytes === '' || strlen($bytes) > self::MAX_BYTES ) return new \WP_Error('madof_ads_img', __('La imagen está vacía o pesa más de 8 MB.', 'mad-oficina'));
        $info = @getimagesizefromstring($bytes);
        $type = $info['mime'] ?? '';
        if ( ! isset(self::TYPES[$type]) ) return new \WP_Error('madof_ads_img', __('Solo imágenes (JPG, PNG, GIF o WebP).', 'mad-oficina'));
        return $type;
    }

    /** @return array|\WP_Error the attachment */
    public static function add($change_id, $bytes, $name = '', $user_id = 0){
        if ( ! Changes::get((int) $change_id) ) return new \WP_Error('madof_ads_img', __('Ese cambio ya no existe.', 'mad-oficina'));
        $type = self::check((string) $bytes);
        if ( is_wp_error($type) ) return $type;
        $list = self::of($change_id);
        if ( count($list) >= self::MAX_PER_CHANGE ) return new \WP_Error('madof_ads_img', sprintf(__('Como mucho %d imágenes por cambio.', 'mad-oficina'), self::MAX_PER_CHANGE));
        $f = (int) $change_id . '-' . wp_generate_password(20, false) . '.' . self::TYPES[$type];
        if ( ! file_put_contents(self::dir() . '/' . $f, $bytes) ) return new \WP_Error('madof_ads_img', __('No he podido guardar la imagen.', 'mad-oficina'));
        $a = ['f' => $f, 'name' => mb_substr(sanitize_file_name((string) $name) ?: 'imagen', 0, 120), 'type' => $type, 'at' => Time::now(), 'by' => (int) $user_id];
        $list[] = $a;
        self::save($change_id, $list);
        return $a;
    }

    public static function remove($change_id, $f){
        $list = self::of($change_id);
        $keep = array_values(array_filter($list, fn($a) => $a['f'] !== $f));
        if ( count($keep) === count($list) ) return false;
        @unlink(self::dir() . '/' . basename((string) $f));
        self::save($change_id, $keep);
        return true;
    }

    /** All the files of some changes (before deleting the rows). */
    public static function purge(array $change_ids){
        foreach ( $change_ids as $id ) foreach ( self::of((int) $id) as $a ) @unlink(self::dir() . '/' . basename($a['f']));
    }

    public static function url($change_id, $f){
        // Plain «&» (wp_nonce_url gives «&amp;», which breaks the link once it goes through JSON).
        return add_query_arg(['action' => 'madof_ads_file', 'change' => (int) $change_id, 'f' => rawurlencode($f), '_wpnonce' => wp_create_nonce('madof_ads_file_' . (int) $change_id)], admin_url('admin-post.php'));
    }

    /** admin-post: the image, only for the office and only if it belongs to that change. */
    public static function serve(){
        $id = absint($_GET['change'] ?? 0);
        if ( ! current_user_can(\MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_ads_file_' . $id);
        $f = basename((string) wp_unslash($_GET['f'] ?? ''));
        $a = array_values(array_filter(self::of($id), fn($x) => $x['f'] === $f))[0] ?? null;
        $path = self::dir() . '/' . $f;
        if ( ! $a || ! is_readable($path) ) wp_die(esc_html__('Esa imagen ya no está.', 'mad-oficina'), 404);
        nocache_headers();
        header('Content-Type: ' . $a['type']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . rawurlencode($a['name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /* ───────── images sent by Chat, waiting for the change they belong to ───────── */

    /** @return string|\WP_Error a token for the stashed image */
    public static function stash($bytes, $name = ''){
        $type = self::check((string) $bytes);
        if ( is_wp_error($type) ) return $type;
        $token = 'tmp-' . wp_generate_password(20, false) . '.' . self::TYPES[$type];
        if ( ! file_put_contents(self::dir() . '/' . $token, $bytes) ) return new \WP_Error('madof_ads_img', __('No he podido guardar la imagen.', 'mad-oficina'));
        set_transient('madof_ads_stash_' . md5($token), ['name' => (string) $name], DAY_IN_SECONDS);
        return $token;
    }

    /** Moves a stashed image to a change. @return array|\WP_Error */
    public static function attach_stashed($change_id, $token, $user_id = 0){
        $token = basename((string) $token);
        $path = self::dir() . '/' . $token;
        if ( ! str_starts_with($token, 'tmp-') || ! is_readable($path) ) return new \WP_Error('madof_ads_img', __('La imagen ya no está.', 'mad-oficina'));
        $meta = get_transient('madof_ads_stash_' . md5($token)) ?: [];
        $r = self::add($change_id, (string) file_get_contents($path), $meta['name'] ?? '', $user_id);
        return $r;
    }

    /** Stashed images nobody used, after a day. */
    public static function clean_stash(){
        foreach ( glob(self::dir() . '/tmp-*') ?: [] as $p ) if ( filemtime($p) < time() - DAY_IN_SECONDS ) @unlink($p);
    }
}
