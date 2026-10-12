<?php
namespace MAD_Oficina\Suppliers;

if ( ! defined('ABSPATH') ) exit;

/**
 * PDFs Jessica uploads by hand wait here (private folder, not web-readable)
 * until the cartero copies them to the shared drive; then they are deleted.
 */
final class Uploads {

    public static function dir(){
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'mad-oficina/expenses';
        if ( ! is_dir($dir) ) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    private static function path($expense_id){
        // Name derived from a secret: the folder cannot be enumerated even if the server ignores .htaccess.
        return self::dir() . '/' . (int) $expense_id . '-' . substr(hash_hmac('sha256', (string) $expense_id, wp_salt('auth')), 0, 24) . '.pdf';
    }

    public static function store($expense_id, $bytes){
        return (bool) file_put_contents(self::path($expense_id), $bytes);
    }

    public static function read($expense_id){
        $p = self::path($expense_id);
        return is_readable($p) ? file_get_contents($p) : null;
    }

    public static function delete($expense_id){
        $p = self::path($expense_id);
        if ( file_exists($p) ) @unlink($p);
    }
}
