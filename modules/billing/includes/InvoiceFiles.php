<?php
namespace MAD_Oficina\Billing;

if ( ! defined('ABSPATH') ) exit;

/**
 * An invoice made elsewhere (Argentina: ARCA gives the official number and PDF): its PDF,
 * kept in a private folder (not web-readable) so the cartero archives and sends that one
 * instead of drawing ours.
 */
final class InvoiceFiles {

    const MAX_BYTES = 10 * MB_IN_BYTES;

    public static function dir(){
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'mad-oficina/invoices';
        if ( ! is_dir($dir) ) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    private static function path($invoice_id){
        // Name derived from a secret: the folder cannot be enumerated even if the server ignores .htaccess.
        return self::dir() . '/' . (int) $invoice_id . '-' . substr(hash_hmac('sha256', 'inv' . (int) $invoice_id, wp_salt('auth')), 0, 24) . '.pdf';
    }

    /** @param array $file one entry of $_FILES @return true|\WP_Error */
    public static function store($invoice_id, array $file){
        if ( empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name']) ) return new \WP_Error('madof_inv_file', __('Sube el PDF de la factura que te dio ARCA.', 'mad-oficina'));
        if ( (int) $file['size'] > self::MAX_BYTES ) return new \WP_Error('madof_inv_file', __('El PDF es demasiado grande (máximo 10 MB).', 'mad-oficina'));
        $bytes = (string) file_get_contents($file['tmp_name']);
        if ( strncmp($bytes, '%PDF', 4) !== 0 ) return new \WP_Error('madof_inv_file', __('Tiene que ser el PDF de la factura.', 'mad-oficina'));
        return file_put_contents(self::path($invoice_id), $bytes) ? true : new \WP_Error('madof_inv_file', __('No he podido guardar el PDF.', 'mad-oficina'));
    }

    public static function exists($invoice_id){
        return is_readable(self::path($invoice_id));
    }

    public static function read($invoice_id){
        return self::exists($invoice_id) ? file_get_contents(self::path($invoice_id)) : null;
    }
}
