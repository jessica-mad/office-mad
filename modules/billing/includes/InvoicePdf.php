<?php
namespace MAD_Oficina\Billing;

if ( ! defined('ABSPATH') ) exit;

/**
 * The invoice as an A4 PDF with MAD's design («Work invoice» header, Montserrat,
 * yellow total), laid out as real text: nothing overlaps whatever the length of
 * names, addresses or concepts, and there can be as many lines as needed.
 * Rendered with dompdf (lib/dompdf, LGPL) on the server.
 */
final class InvoicePdf {

    /** @return string|\WP_Error PDF bytes */
    public static function render(array $inv, array $issuer){
        if ( ! class_exists('\\Dompdf\\Dompdf') ) require_once MAD_OFICINA_DIR . 'lib/dompdf/autoload.inc.php';
        $upload = wp_upload_dir();
        $cache = trailingslashit($upload['basedir']) . 'mad-oficina/dompdf';
        if ( ! wp_mkdir_p($cache) ) return new \WP_Error('madof_pdf', __('No puedo escribir en la carpeta de subidas para preparar el PDF.', 'mad-oficina'));
        if ( ! file_exists($cache . '/index.php') ) @file_put_contents($cache . '/index.php', "<?php // Silence is golden.\n");

        $options = new \Dompdf\Options();
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('chroot', [MAD_OFICINA_DIR, $cache]);
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'Montserrat');
        $options->set('dpi', 96);

        try {
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml(self::html($inv, $issuer), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            return $dompdf->output();
        } catch ( \Throwable $e ) {
            return new \WP_Error('madof_pdf', sprintf(__('No he podido generar el PDF: %s', 'mad-oficina'), $e->getMessage()));
        }
    }

    public static function filename(array $inv){
        $name = sanitize_file_name(remove_accents($inv['client_name']));
        return ($inv['number'] ?: 'borrador-' . $inv['id']) . ' ' . $name . '.pdf';
    }

    private static function money($n){
        return number_format((float) $n, 2, ',', '.') . ' €';
    }

    private static function rate($n){
        return rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',') . '%';
    }

    /**
     * The document exactly as it will go out, to show inside the page (no PDF to open):
     * with the number it will take and no «BORRADOR» mark.
     */
    public static function preview_html(array $inv, array $issuer){
        $inv['number'] = Invoices::preview_number($inv) ?: $inv['number'];
        return self::html($inv, $issuer, true);
    }

    /** That document on an A4 sheet inside the admin page, scaled to the width there is. */
    public static function preview_frame(array $inv, array $issuer){
        static $script = false;
        $html = '<div class="madof-a4-frame" data-a4><iframe class="madof-a4" title="' . esc_attr(sprintf(__('Vista de la factura %s', 'mad-oficina'), Invoices::preview_number($inv) ?: __('borrador', 'mad-oficina'))) . '" srcdoc="' . esc_attr(self::preview_html($inv, $issuer)) . '"></iframe></div>';
        if ( ! $script ) {
            $script = true;
            $html .= "<script>(function(){function fit(){document.querySelectorAll('[data-a4]').forEach(function(box){var f=box.querySelector('iframe'),s=Math.min(1,box.clientWidth/794),h=1123;try{h=Math.max(h,f.contentDocument.documentElement.scrollHeight);}catch(e){}f.style.height=h+'px';f.style.transform='scale('+s+')';box.style.height=Math.ceil(h*s)+'px';});}"
                . "document.addEventListener('load',function(e){if(e.target.tagName==='IFRAME')fit();},true);window.addEventListener('resize',fit);document.addEventListener('DOMContentLoaded',fit);fit();})();</script>";
        }
        return $html;
    }

    /** @param bool $web for the browser (fonts and header by URL) instead of dompdf (by path) */
    public static function html(array $inv, array $issuer, $web = false){
        $e = fn($s) => esc_html((string) $s);
        $root = $web ? MAD_OFICINA_URL : MAD_OFICINA_DIR;
        $font = fn($w, $file) => "@font-face{font-family:'Montserrat';font-weight:{$w};font-style:normal;src:url('" . $root . "assets/billing/fonts/{$file}') format('truetype');}";
        $header = $root . 'assets/billing/invoice-header.png';
        // A rectifying invoice says so, which invoice it corrects and why.
        $original = ! empty($inv['rectifies_id']) ? Invoices::get((int) $inv['rectifies_id']) : null;
        $date = $inv['issue_date'] ? date_i18n('d/m/Y', strtotime($inv['issue_date'])) : '';
        $lines = '';
        foreach ( $inv['lines'] as $l ) {
            $lines .= '<tr><td class="desc">' . nl2br($e($l['description'])) . '</td><td class="amt">' . $e(self::money($l['amount'])) . '</td></tr>';
        }
        $client_rows = '';
        foreach ( [
            __('Cliente:', 'mad-oficina')   => $inv['client_name'],
            __('C.I.F.:', 'mad-oficina')    => $inv['client_tax_id'],
            __('Domicilio:', 'mad-oficina') => $inv['client_address'],
            __('Localidad:', 'mad-oficina') => $inv['client_city'],
        ] as $label => $value ) {
            $client_rows .= '<tr><th>' . $e($label) . '</th><td>' . $e($value) . '</td></tr>';
        }
        $issuer_lines = array_filter([
            $issuer['issuer_tagline'] ?? '',
            ! empty($issuer['issuer_address']) ? __('Domicilio fiscal:', 'mad-oficina') . ' ' . $issuer['issuer_address'] : '',
            ! empty($issuer['issuer_city']) ? __('Localidad:', 'mad-oficina') . ' ' . $issuer['issuer_city'] : '',
            ! empty($issuer['issuer_tax_id']) ? ($issuer['issuer_tax_label'] ?? 'N.I.F.') . ': ' . $issuer['issuer_tax_id'] : '',
        ]);
        $pay = array_filter([$issuer['issuer_bank'] ?? '', ! empty($issuer['issuer_iban']) ? 'IBAN ' . $issuer['issuer_iban'] : '', $issuer['payment_terms'] ?? '']);
        $contact = implode(' / ', array_filter([$issuer['issuer_phone'] ?? '', $issuer['issuer_email'] ?? '']));
        $draft = $inv['number'] ? '' : '<div class="draft">' . $e(__('BORRADOR', 'mad-oficina')) . '</div>';

        ob_start(); ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
<?php echo $font(400, 'Montserrat_400Regular.ttf') . $font(500, 'Montserrat_500Medium.ttf') . $font(600, 'Montserrat_600SemiBold.ttf') . $font(700, 'Montserrat_700Bold.ttf') . $font(800, 'Montserrat_800ExtraBold.ttf'); ?>
@page{margin:14mm 16mm 16mm 16mm}
body{font-family:'Montserrat',sans-serif;font-size:9.5pt;color:#0B0B0B;line-height:1.35}
.header{width:100%;margin:0 0 9mm}
.meta{margin:0 0 4mm;font-size:12pt}
.meta th{font-weight:800;text-align:left;padding:0 4mm 0 0}
.meta td{padding:0}
.rule{border:0;border-top:1px solid #0B0B0B;margin:0}
.rule.thick{border-top:3px solid #0B0B0B}
.parties{width:100%;border-collapse:collapse;margin:4mm 0}
.parties td{vertical-align:top;padding:0}
.issuer-name{font-weight:800;font-size:14pt;margin:0 0 1.5mm}
.issuer p{margin:0 0 .9mm;font-size:9.2pt}
.client{border-collapse:collapse}
.client th{font-weight:700;font-size:10pt;text-align:left;padding:0 3mm .6mm 0;white-space:nowrap;vertical-align:top}
.client td{font-size:10pt;padding:0 0 .6mm 0;vertical-align:top}
.concepts{width:100%;border-collapse:collapse;margin:12mm 0 0}
.concepts thead th{font-weight:800;font-size:13pt;text-align:left;padding:0 2mm 1.5mm;border-bottom:3px solid #0B0B0B}
.concepts thead th.amt{text-align:right}
.concepts td{padding:4mm 2mm;border-bottom:1px solid #0B0B0B;vertical-align:top}
.concepts td.amt{text-align:right;white-space:nowrap;width:35mm}
.concepts tr:last-child td{border-bottom:3px solid #0B0B0B}
.bottom{width:100%;border-collapse:collapse;margin-top:0}
.bottom td{vertical-align:top;padding:0}
.pay h3{font-weight:700;font-size:12pt;margin:4mm 0 2mm}
.pay p{margin:0 0 .6mm;font-size:8.8pt}
.pay .method{font-weight:700}
.totals{width:100%;border-collapse:collapse;margin-top:2mm}
.totals th{font-weight:700;font-size:11pt;text-align:center;padding:2mm 2mm;border-bottom:1px solid #BDBDBD}
.totals td{text-align:right;padding:2mm 2mm;border-bottom:1px solid #BDBDBD;white-space:nowrap}
.totals tr.total th,.totals tr.total td{color:#FFC400;font-weight:800;font-size:13pt;border-bottom:3px solid #0B0B0B}
.contact{position:fixed;bottom:0;right:0;text-align:right;font-size:9pt}
.contact strong{display:block;font-weight:800}
.rectifies{margin:0 0 4mm;font-size:9.5pt}
.draft{position:fixed;top:95mm;left:0;right:0;text-align:center;font-size:80pt;font-weight:800;color:#FFC400;opacity:.18}
<?php if ( $web ) : // a sheet of A4 on screen, with the PDF's margins ?>
html{background:#fff}
body{position:relative;width:210mm;min-height:297mm;margin:0;padding:14mm 16mm 16mm;box-sizing:border-box}
.contact{position:absolute;bottom:16mm;right:16mm}
<?php endif; ?>
</style></head><body>
<?php echo $draft; ?>
<img class="header" src="<?php echo esc_attr($header); ?>" alt="">
<table class="meta"><tr><th><?php echo $e($inv['series'] === 'R' ? __('Factura rectificativa Nº:', 'mad-oficina') : __('Factura Nº:', 'mad-oficina')); ?></th><td><?php echo $e($inv['number'] ?: '—'); ?></td></tr>
<tr><th><?php echo $e(__('Fecha:', 'mad-oficina')); ?></th><td><?php echo $e($date); ?></td></tr></table>
<?php if ( $inv['series'] === 'R' && ($original || ! empty($inv['rectify_reason'])) ) : ?>
<p class="rectifies"><?php if ( $original ) echo $e(sprintf(__('Rectifica la factura %1$s, del %2$s.', 'mad-oficina'), $original['number'], date_i18n('d/m/Y', strtotime($original['issue_date'])))); ?>
<?php if ( ! empty($inv['rectify_reason']) ) echo ' ' . $e(__('Motivo:', 'mad-oficina')) . ' ' . $e($inv['rectify_reason']); ?></p>
<?php endif; ?>
<hr class="rule">
<table class="parties"><tr>
  <td style="width:52%" class="issuer">
    <p class="issuer-name"><?php echo $e($issuer['issuer_name'] ?? ''); ?></p>
    <?php foreach ( $issuer_lines as $l ) echo '<p>' . $e($l) . '</p>'; ?>
  </td>
  <td style="width:48%"><table class="client"><?php echo $client_rows; ?></table></td>
</tr></table>
<hr class="rule">
<table class="concepts">
  <thead><tr><th><?php echo $e(__('Concepto', 'mad-oficina')); ?></th><th class="amt"><?php echo $e(__('Total €', 'mad-oficina')); ?></th></tr></thead>
  <tbody><?php echo $lines; ?></tbody>
</table>
<table class="bottom"><tr>
  <td style="width:30%"></td>
  <td style="width:32%" class="pay">
    <?php if ( $pay ) : ?><h3><?php echo $e(__('Forma de pago', 'mad-oficina')); ?></h3>
    <p class="method"><?php echo $e($issuer['payment_method'] ?? __('Transferencia bancaria', 'mad-oficina')); ?></p>
    <?php foreach ( $pay as $p ) echo '<p>' . nl2br($e($p)) . '</p>'; endif; ?>
  </td>
  <td style="width:38%"><table class="totals">
    <tr><th><?php echo $e(__('Base', 'mad-oficina')); ?></th><td><?php echo $e(self::money($inv['base'])); ?></td></tr>
    <tr><th><?php echo $e(sprintf(__('I.V.A. %s', 'mad-oficina'), self::rate($inv['vat_rate']))); ?></th><td><?php echo $e(self::money($inv['vat'])); ?></td></tr>
    <?php if ( (float) $inv['irpf'] != 0.0 ) : ?><tr><th><?php echo $e(sprintf(__('IRPF %s', 'mad-oficina'), self::rate($inv['irpf_rate']))); ?></th><td><?php echo (float) $inv['irpf'] > 0 ? '−' . $e(self::money($inv['irpf'])) : $e(self::money(-$inv['irpf'])); // a rectifying one gives it back ?></td></tr><?php endif; ?>
    <tr class="total"><th><?php echo $e(__('Total', 'mad-oficina')); ?></th><td><?php echo $e(self::money($inv['total'])); ?></td></tr>
  </table></td>
</tr></table>
<?php if ( $contact ) : ?><div class="contact"><strong><?php echo $e(__('Contacto', 'mad-oficina')); ?></strong><?php echo $e($contact); ?></div><?php endif; ?>
</body></html>
<?php
        return ob_get_clean();
    }
}
