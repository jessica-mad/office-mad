<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Imports the old invoices sheet (tab «facturacion», downloaded as CSV) as it
 * is. Idempotent: every row has a content key, so importing twice adds nothing.
 *
 *  - Numbers «AA-NNN» keep their number; «R-AA-NN…» go to the R series.
 *  - Rows without a number: Argentina clients (chosen in the preview) go to the
 *    AR series; empty rows (0 €) are skipped; the rest become drafts.
 *  - A number already used by an earlier row: if that row was never sent, it
 *    becomes a draft without number (it gets the next free one when issued).
 */
final class LegacyImport {

    const COLUMNS = ['fecha', 'n_factura', 'cliente', 'cif', 'localidad', 'domicilio', 'concepto_1', 'valor_1', 'concepto_2', 'valor_2', 'base', 'iva', 'irpf', 'total', 'email'];

    /** @return array|\WP_Error ['rows' => [...], 'issues' => [...], 'stats' => [...], 'ar_candidates' => [name => [n, suggested]]] */
    public static function analyze($csv){
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', (string) $csv);
        $lines = array_map(fn($l) => str_getcsv($l, ',', '"', ''), self::split_lines($csv));
        if ( count($lines) < 2 ) return new \WP_Error('madof_import', __('El archivo está vacío.', 'mad-oficina'));
        $header = array_map(fn($h) => mb_strtolower(trim($h)), $lines[0]);
        foreach ( self::COLUMNS as $col ) {
            if ( ! in_array($col, $header, true) ) return new \WP_Error('madof_import', sprintf(__('Falta la columna «%s». Descarga la pestaña «facturacion» como CSV.', 'mad-oficina'), $col));
        }

        $rows = [];
        $issues = [];
        foreach ( array_slice($lines, 1) as $i => $cells ) {
            if ( ! array_filter($cells, fn($c) => trim((string) $c) !== '') ) continue;
            $r = [];
            foreach ( $header as $k => $h ) $r[$h] = trim((string) ($cells[$k] ?? ''));
            // Separator rows (just «2023», or nothing but a year): not invoices.
            if ( $r['cliente'] === '' && $r['total'] === '' && preg_match('/^(\d{4})?$/', $r['fecha']) ) continue;
            $row = self::parse_row($r, $i + 2);
            if ( is_wp_error($row) ) { $issues[] = $row->get_error_message(); continue; }
            $rows[] = $row;
        }
        // Chronological order decides which of two equal numbers is the real one.
        usort($rows, fn($a, $b) => [$a['date'], $a['line']] <=> [$b['date'], $b['line']]);

        // NIFs shared by different names (usually a copy-paste error).
        $by_tax = [];
        foreach ( $rows as $r ) if ( $r['tax_id'] !== '' ) $by_tax[$r['tax_id']][mb_strtolower($r['client'])] = $r['client'];
        $shared = array_filter($by_tax, fn($names) => self::different_names(array_values($names)));
        foreach ( $shared as $tax => $names ) $issues[] = sprintf(__('El NIF %1$s aparece con varios nombres: %2$s. Revísalo.', 'mad-oficina'), $tax, implode(', ', $names));

        // Clients whose rows have no number: candidates for the Argentina series.
        $ar = [];
        foreach ( $rows as $r ) {
            if ( $r['number'] !== null || ( ! $r['base'] && ! $r['total'] ) ) continue;
            $k = $r['client'];
            $ar[$k] ??= ['n' => 0, 'suggested' => true];
            $ar[$k]['n']++;
            if ( $r['vat'] != 0 || $r['irpf'] != 0 || $r['tax_id'] !== '' ) $ar[$k]['suggested'] = false;
        }
        $ar = array_filter($ar, fn($c) => $c['n'] > 0);
        return ['rows' => $rows, 'issues' => $issues, 'shared_tax_ids' => array_keys($shared), 'ar_candidates' => $ar, 'stats' => self::stats($rows)];
    }

    /** «Olofane S.L.» and «Olofane» are the same; «Museum of mini» and «Ainara…» are not. */
    private static function different_names(array $names){
        $norm = array_map(fn($n) => trim(preg_replace('/\b(s\.?l\.?u?|s\.?a\.?|sll|sl)\b\.?|[^a-z0-9 ]/u', ' ', remove_accents(mb_strtolower($n)))), $names);
        foreach ( $norm as $a ) foreach ( $norm as $b ) {
            $a2 = preg_replace('/\s+/', ' ', $a); $b2 = preg_replace('/\s+/', ' ', $b);
            if ( $a2 !== $b2 && ! str_contains($a2, $b2) && ! str_contains($b2, $a2) ) return true;
        }
        return false;
    }

    private static function split_lines($csv){
        // Split on newlines that are not inside quotes (concepts can span lines).
        $out = []; $buf = ''; $q = false;
        foreach ( preg_split('//u', $csv, -1, PREG_SPLIT_NO_EMPTY) as $ch ) {
            if ( $ch === '"' ) $q = ! $q;
            if ( ( $ch === "\n" ) && ! $q ) { $out[] = rtrim($buf, "\r"); $buf = ''; continue; }
            $buf .= $ch;
        }
        if ( trim($buf) !== '' ) $out[] = rtrim($buf, "\r");
        return $out;
    }

    private static function stats(array $rows){
        $s = ['total' => count($rows), 'numbered' => 0, 'without_number' => 0, 'years' => []];
        foreach ( $rows as $r ) {
            $r['number'] ? $s['numbered']++ : $s['without_number']++;
            $s['years'][$r['year']] = ($s['years'][$r['year']] ?? 0) + 1;
        }
        krsort($s['years']);
        return $s;
    }

    /** @return array|\WP_Error */
    private static function parse_row(array $r, $line){
        if ( ! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $r['fecha'], $m) ) {
            return new \WP_Error('madof_import_row', sprintf(__('Fila %1$d: fecha «%2$s» no válida, no se importa.', 'mad-oficina'), $line, $r['fecha']));
        }
        $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        $raw_number = $r['n_factura'];
        $series = 'F'; $number = null; $seq = null;
        if ( preg_match('/^(\d{2})-(\d{1,3})$/', $raw_number, $n) ) {
            $seq = (int) $n[2];
            $number = Invoices::format_number('F', 2000 + (int) $n[1], $seq);
            $year = 2000 + (int) $n[1];
        } elseif ( preg_match('/^R-(\d{2})-(\d{1,3})/i', $raw_number, $n) ) {
            $series = 'R'; $seq = (int) $n[2];
            $number = Invoices::format_number('R', 2000 + (int) $n[1], $seq);
            $year = 2000 + (int) $n[1];
        } else {
            $year = (int) $m[3];
        }
        $amount = fn($k) => Money::parse($r[$k] ?? '');
        $lines = [];
        foreach ( [1, 2] as $k ) {
            $desc = trim($r['concepto_' . $k] ?? '');
            $val = $amount('valor_' . $k);
            if ( $desc !== '' || $val ) $lines[] = ['description' => $desc !== '' ? $desc : __('(sin concepto)', 'mad-oficina'), 'amount' => (float) $val];
        }
        $base = (float) $amount('base');
        $total = (float) $amount('total');
        if ( ! $base && $total && ! $amount('iva') && ! $amount('irpf') ) $base = $total; // only the total was filled in
        if ( count($lines) === 1 && ! $lines[0]['amount'] ) $lines[0]['amount'] = $base;
        $email = strtolower(trim($r['email'] ?? ''));
        return [
            'line'    => $line,
            'date'    => $date,
            'year'    => $year,
            'raw_number' => $raw_number,
            'series'  => $series,
            'number'  => $number,
            'seq'     => $seq,
            'client'  => trim($r['cliente']) !== '' ? trim($r['cliente']) : __('(sin cliente)', 'mad-oficina'),
            'tax_id'  => Profiles::norm_tax_id($r['cif']),
            'city'    => $r['localidad'],
            'address' => $r['domicilio'],
            'email'   => ($email !== '' && ! str_ends_with($email, '@imad.pro')) ? $email : '', // her own address was a placeholder
            'lines'   => $lines,
            'base'    => $base,
            'vat'     => (float) $amount('iva'),
            'irpf'    => abs((float) $amount('irpf')),
            'total'   => $total,
            'sent'    => strcasecmp($r['enviar?'] ?? '', 'Enviado') === 0 || ! empty($r['pdf']),
            'pdf'     => $r['pdf'] ?? '',
            'paid'    => strtoupper($r['pago'] ?? '') === 'TRUE',
            'debt'    => strtoupper($r['deuda'] ?? '') === 'TRUE',
            'legacy'  => $r,
            'key'     => sha1(implode('|', [$date, $raw_number, mb_strtolower(trim($r['cliente'])), $r['total'], $r['concepto_1']])),
        ];
    }

    /**
     * @param string[] $argentina client names that go to the AR series
     * @return array counts: imported, skipped_existing, skipped_empty, drafts, argentina, renumbered
     */
    public static function apply(array $analysis, array $argentina){
        global $wpdb;
        $ar = array_flip($argentina);
        $used = [];
        foreach ( $wpdb->get_results('SELECT series, number FROM ' . Invoices::table() . ' WHERE number IS NOT NULL', ARRAY_A) as $u ) $used[$u['series'] . '|' . $u['number']] = true;
        $shared = array_flip($analysis['shared_tax_ids'] ?? []);
        $count = ['imported' => 0, 'skipped_existing' => 0, 'skipped_empty' => 0, 'drafts' => 0, 'argentina' => 0, 'renumbered' => 0];

        // Numbered rows never sent and dated after the last sent invoice of their
        // year are drafts that were pre-filled (e.g. next month's): no number yet.
        // Only in the latest year of the sheet: in closed years an invoice not marked
        // as sent is still a real, issued invoice.
        $latest = max(array_map(fn($r) => $r['year'], $analysis['rows']) ?: [0]);
        $last_sent = [];
        foreach ( $analysis['rows'] as $r ) if ( $r['sent'] && $r['number'] && $r['year'] === $latest ) $last_sent[$r['series'] . $r['year']] = max($last_sent[$r['series'] . $r['year']] ?? '', $r['date']);

        foreach ( $analysis['rows'] as $r ) {
            if ( $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Invoices::table() . ' WHERE legacy_key = %s', $r['key'])) ) { $count['skipped_existing']++; continue; }
            $notes = [];
            $series = $r['series'];
            $number = $r['number'];
            $seq = $r['seq'];
            $is_ar = isset($ar[$r['client']]);

            if ( $number !== null && ! $r['sent'] && $r['year'] === $latest && $r['date'] > ($last_sent[$series . $r['year']] ?? '9999') ) {
                $notes[] = sprintf(__('Borrador de la hoja antigua (llevaba el número %s sin haberse enviado): al emitirla recibirá el siguiente número libre.', 'mad-oficina'), $number);
                $number = null; $seq = null;
                $count['renumbered']++;
            } elseif ( $number !== null && isset($used[$series . '|' . $number]) ) {
                $notes[] = sprintf(__('En la hoja antigua llevaba el número %s, que ya se había usado antes: al emitirla recibirá el siguiente libre.', 'mad-oficina'), $number);
                $number = null; $seq = null;
                $count['renumbered']++;
            }
            if ( $number === null && $is_ar ) {
                $series = 'AR';
                $status = $r['sent'] ? 'sent' : 'draft';
                $count['argentina']++;
            } elseif ( $number === null ) {
                if ( ! $r['base'] && ! $r['total'] && ! $r['sent'] ) { $count['skipped_empty']++; continue; }
                $status = 'draft';
                $count['drafts']++;
            } else {
                $status = $r['sent'] ? 'sent' : 'issued';
                $used[$series . '|' . $number] = true;
            }
            $tax_id = $r['tax_id'];
            if ( $status === 'draft' && isset($shared[$tax_id]) ) {
                $notes[] = sprintf(__('El NIF %s estaba repetido con otro cliente en la hoja antigua: revísalo antes de emitir.', 'mad-oficina'), $tax_id);
                $tax_id = '';
            }
            if ( $r['debt'] && ! $r['paid'] ) $notes[] = __('Marcada como deuda en la hoja antigua.', 'mad-oficina');

            $profile = Profiles::find($tax_id, $r['client']);
            $profile_id = $profile ? $profile['id'] : Profiles::save([
                'legal_name' => $r['client'], 'tax_id' => $tax_id, 'address' => $r['address'], 'city' => $r['city'], 'email' => $r['email'],
                'vat_rate' => $r['base'] ? round($r['vat'] / $r['base'] * 100, 2) : 21,
                'irpf_rate' => $r['base'] ? round($r['irpf'] / $r['base'] * 100, 2) : 15,
                'series' => $is_ar ? 'AR' : 'F',
                'country' => $is_ar ? 'AR' : 'ES',
            ]);
            if ( $profile && $r['email'] && ! $profile['email'] ) Profiles::save(['email' => $r['email']] + $profile, $profile['id']);

            Invoices::insert([
                'series' => $series, 'number' => $number, 'year' => $number ? $r['year'] : (int) substr($r['date'], 0, 4), 'seq' => $seq,
                'issue_date' => $r['date'], 'profile_id' => $profile_id,
                'client_name' => $r['client'], 'client_tax_id' => $tax_id ?: null, 'client_address' => $r['address'] ?: null,
                'client_city' => $r['city'] ?: null, 'client_email' => $r['email'] ?: null,
                'base' => $r['base'], 'vat' => $r['vat'], 'irpf' => $r['irpf'], 'total' => $r['total'],
                'vat_rate' => $r['base'] ? round($r['vat'] / $r['base'] * 100, 2) : 0,
                'irpf_rate' => $r['base'] ? round($r['irpf'] / $r['base'] * 100, 2) : 0,
                'status' => $status, 'sent_at' => $r['sent'] ? $r['date'] . ' 12:00:00' : null,
                'paid' => $r['paid'] ? 1 : 0, 'paid_at' => $r['paid'] ? $r['date'] . ' 12:00:00' : null,
                'pdf_url' => $r['pdf'] ? esc_url_raw($r['pdf']) : null,
                'source' => 'import', 'legacy_key' => $r['key'], 'legacy' => wp_json_encode($r['legacy']),
                'notes' => $notes ? implode("\n", $notes) : null,
            ], $r['lines']);
            $count['imported']++;
        }
        update_option('madoficina_billing_last_import', ['at' => Time::now(), 'count' => $count], false);
        return $count;
    }
}
