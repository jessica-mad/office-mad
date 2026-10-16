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

    public static function exists($expense_id){
        return is_readable(self::path($expense_id));
    }

    /**
     * The invoice itself, to look at it before deciding: the kept file (through a
     * nonce-protected link, only for whoever sees Finanzas) or, once archived, Drive's preview.
     */
    public static function preview(array $e, $open = true){
        if ( self::exists($e['id']) ) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=madof_expense_file&expense=' . (int) $e['id']), 'madof_expense_file_' . (int) $e['id']);
        } elseif ( ! empty($e['drive_file_id']) ) {
            $url = 'https://drive.google.com/file/d/' . rawurlencode($e['drive_file_id']) . '/preview';
        } else {
            return '<p class="madof-muted">' . esc_html__('El archivo ya no está aquí y todavía no está en Drive.', 'mad-oficina') . '</p>';
        }
        $link = self::exists($e['id']) ? $url : ($e['drive_url'] ?: $url);
        return '<details class="madof-invoice-view"' . ($open ? ' open' : '') . '><summary>' . esc_html__('Ver la factura', 'mad-oficina') . '</summary>'
            . '<iframe src="' . esc_url($url) . '" title="' . esc_attr__('Factura', 'mad-oficina') . '" loading="lazy"></iframe>'
            . '<p><a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html__('Abrirla en otra pestaña', 'mad-oficina') . '</a></p></details>';
    }

    public static function delete($expense_id){
        $p = self::path($expense_id);
        if ( file_exists($p) ) @unlink($p);
    }
}
