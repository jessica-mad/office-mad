<?php
namespace MAD_Oficina\Billing;

if ( ! defined('ABSPATH') ) exit;

/**
 * Reads a bank statement into movements: Excel (.xlsx, and the «.xls» that some banks
 * export as an HTML table), CSV, or Norma 43 (the Spanish banks' standard text format).
 * Columns are recognised by their header (Fecha, Concepto, Importe / Cargo y Abono, Saldo…).
 *
 * @return array{account:string, rows:array<array{date:string, value_date:?string, concept:string, amount:float, balance:?float}>}|\WP_Error
 */
final class BankStatement {

    public static function read($path, $filename){
        $raw = (string) @file_get_contents($path);
        if ( $raw === '' ) return new \WP_Error('madof_bank_empty', __('El archivo está vacío.', 'mad-oficina'));
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        if ( substr($raw, 0, 2) === 'PK' ) return self::table(self::xlsx($path));
        if ( preg_match('/^11\d{18}/', $raw) || in_array($ext, ['n43', 'aeb', 'q43'], true) ) return self::norma43($raw);
        if ( substr($raw, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ) return self::table(XlsReader::rows($raw)); // old Excel (.xls), e.g. Santander
        $text = self::utf8($raw);
        if ( stripos(ltrim($text), '<') === 0 || stripos($text, '<table') !== false ) return self::table(self::html($text));
        return self::table(self::csv($text));
    }

    /**
     * Any spreadsheet (xlsx, old xls, csv, html) as rows of cells, for other importers
     * (e.g. Meta Ads Manager reports). @return array<array<int,string|float>>|\WP_Error
     */
    public static function grid($path){
        $raw = (string) @file_get_contents($path);
        if ( $raw === '' ) return new \WP_Error('madof_grid_empty', __('El archivo está vacío.', 'mad-oficina'));
        if ( substr($raw, 0, 2) === 'PK' ) return self::xlsx($path);
        if ( substr($raw, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ) return XlsReader::rows($raw);
        if ( substr($raw, 0, 2) === "\xFF\xFE" ) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'); // Excel «Unicode text»
        $text = self::utf8($raw);
        if ( stripos(ltrim($text), '<') === 0 || stripos($text, '<table') !== false ) return self::html($text);
        return self::csv($text);
    }

    private static function utf8($s){
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /* ───────── formats → rows of cells ───────── */

    /** @return array<array<int,string|float>>|\WP_Error */
    private static function xlsx($path){
        if ( ! class_exists('ZipArchive') ) return new \WP_Error('madof_bank_zip', __('El servidor no puede abrir Excel: súbelo como CSV.', 'mad-oficina'));
        $zip = new \ZipArchive();
        if ( $zip->open($path) !== true ) return new \WP_Error('madof_bank_xlsx', __('No puedo abrir el Excel.', 'mad-oficina'));
        $strings = [];
        if ( ($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false ) {
            $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            foreach ( $sx ? $sx->si : [] as $si ) {
                $t = isset($si->t) ? (string) $si->t : '';
                foreach ( $si->r ?? [] as $r ) $t .= (string) $r->t;
                $strings[] = $t;
            }
        }
        $sheet = 'xl/worksheets/sheet1.xml';
        if ( ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false && ($wb = $zip->getFromName('xl/workbook.xml')) !== false ) {
            if ( preg_match('/<sheet[^>]*r:id="([^"]+)"/', $wb, $m) && preg_match('/<Relationship[^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $t) ) {
                $sheet = 'xl/' . ltrim(str_replace('/xl/', '', $t[1]), '/');
            }
        }
        $xml = $zip->getFromName($sheet);
        $zip->close();
        if ( $xml === false ) return new \WP_Error('madof_bank_xlsx', __('El Excel no tiene hojas que pueda leer.', 'mad-oficina'));
        $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if ( ! $sx ) return new \WP_Error('madof_bank_xlsx', __('No puedo leer la hoja del Excel.', 'mad-oficina'));
        $rows = [];
        foreach ( $sx->sheetData->row ?? [] as $row ) {
            $cells = [];
            foreach ( $row->c as $c ) {
                $ref = (string) $c['r'];
                $col = 0;
                foreach ( str_split(preg_replace('/\d+/', '', $ref)) as $ch ) $col = $col * 26 + (ord($ch) - 64);
                $type = (string) $c['t'];
                if ( $type === 's' ) $v = $strings[(int) $c->v] ?? '';
                elseif ( $type === 'inlineStr' ) $v = (string) ($c->is->t ?? '');
                elseif ( $type === 'str' || $type === 'b' ) $v = (string) $c->v;
                else $v = isset($c->v) && (string) $c->v !== '' ? (float) (string) $c->v : '';
                $cells[max(0, $col - 1)] = $v;
            }
            if ( $cells ) { ksort($cells); $rows[] = $cells + array_fill(0, (int) max(array_keys($cells)), ''); }
        }
        return array_map(function($r){ ksort($r); return array_values($r); }, $rows);
    }

    private static function html($text){
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $text, LIBXML_NONET);
        $rows = [];
        foreach ( $doc->getElementsByTagName('tr') as $tr ) {
            $cells = [];
            foreach ( $tr->childNodes as $td ) if ( in_array($td->nodeName, ['td', 'th'], true) ) $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent));
            if ( array_filter($cells, fn($c) => $c !== '') ) $rows[] = $cells;
        }
        return $rows;
    }

    private static function csv($text){
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $sample = implode("\n", array_slice($lines, 0, 30));
        $counts = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($counts);
        $sep = key($counts);
        $rows = [];
        foreach ( $lines as $line ) if ( trim($line) !== '' ) $rows[] = array_map('trim', str_getcsv($line, $sep));
        return $rows;
    }

    /* ───────── table → movements ───────── */

    private static function norm($s){
        return trim(preg_replace('/[^a-z ]+/', ' ', strtolower(remove_accents((string) $s))));
    }

    /** Finds the header row and which column is what. */
    public static function columns(array $rows){
        foreach ( array_slice($rows, 0, 40, true) as $i => $r ) {
            $h = array_map([self::class, 'norm'], $r);
            $find = function(array $words, array $not = []) use ($h){
                foreach ( $h as $k => $v ) {
                    foreach ( $words as $w ) if ( $v !== '' && preg_match('/\b' . $w . '\b/', $v) ) {
                        foreach ( $not as $n ) if ( preg_match('/\b' . $n . '\b/', $v) ) continue 3;
                        return $k;
                    }
                }
                return null;
            };
            $date = $find(['fecha operacion', 'f operacion', 'fecha contable', 'fecha de operacion'], []) ?? $find(['fecha', 'date', 'f'], ['valor']);
            $amount = $find(['importe', 'cantidad', 'amount', 'importe eur'], ['saldo']);
            $debit = $find(['cargo', 'cargos', 'debe', 'debito', 'salidas', 'pagos']);
            $credit = $find(['abono', 'abonos', 'haber', 'credito', 'entradas', 'ingresos', 'cobros']);
            if ( $date === null || ($amount === null && ($debit === null || $credit === null)) ) continue;
            $concepts = [];
            foreach ( $h as $k => $v ) if ( preg_match('/\b(concepto|descripcion|movimiento|detalle|observaciones|beneficiario|ordenante|mas datos|referencia|remitente|destinatario|comercio)\b/', $v) ) $concepts[] = $k;
            return ['header' => $i, 'date' => $date, 'value_date' => $find(['fecha valor', 'f valor', 'valor']), 'amount' => $amount, 'debit' => $debit, 'credit' => $credit,
                'balance' => $find(['saldo', 'balance']), 'concepts' => $concepts, 'headers' => $r];
        }
        return null;
    }

    private static function table($rows){
        if ( is_wp_error($rows) ) return $rows;
        $map = self::columns($rows);
        if ( ! $map ) {
            $first = array_values(array_filter(array_slice($rows, 0, 8), fn($r) => array_filter($r, fn($c) => $c !== '')));
            return new \WP_Error('madof_bank_columns', sprintf(__('No reconozco las columnas (busco Fecha, Concepto e Importe, o Cargo y Abono). Las primeras filas son: %s. Pásame el archivo o descarga Norma 43.', 'mad-oficina'),
                implode(' | ', array_map(fn($r) => implode(', ', array_filter(array_map('strval', $r))), array_slice($first, 0, 3)))));
        }
        $out = [];
        foreach ( array_slice($rows, $map['header'] + 1) as $r ) {
            $date = self::date($r[$map['date']] ?? '');
            if ( ! $date ) continue; // totals, blank lines, notes
            if ( $map['amount'] !== null ) $amount = self::amount($r[$map['amount']] ?? '');
            else {
                $d = self::amount($r[$map['debit']] ?? ''); $c = self::amount($r[$map['credit']] ?? '');
                $amount = ($c ?? 0) - abs($d ?? 0);
                if ( $d === null && $c === null ) $amount = null;
            }
            if ( $amount === null || abs($amount) < 0.005 ) continue;
            $concept = implode(' · ', array_filter(array_map(fn($k) => trim((string) ($r[$k] ?? '')), $map['concepts'])));
            $out[] = [
                'date' => $date, 'value_date' => $map['value_date'] !== null ? self::date($r[$map['value_date']] ?? '') : null,
                'concept' => mb_substr($concept, 0, 500), 'amount' => round($amount, 2),
                'balance' => $map['balance'] !== null ? self::amount($r[$map['balance']] ?? '') : null,
            ];
        }
        if ( ! $out ) return new \WP_Error('madof_bank_rows', __('He encontrado las columnas pero ningún movimiento con fecha e importe.', 'mad-oficina'));
        return ['account' => self::account(array_slice($rows, 0, $map['header'])), 'rows' => $out];
    }

    /** «Santander …6227» from the lines above the table (bank name and IBAN), if they are there. */
    private static function account(array $top){
        $text = implode(' ', array_map(fn($r) => implode(' ', array_map('strval', $r)), $top));
        $iban = preg_match('/\b[A-Z]{2}\d{2}[ ]?(?:\d{4}[ ]?){4,5}\d{0,4}\b/', $text, $m) ? '…' . substr(preg_replace('/\s+/', '', $m[0]), -4) : '';
        $bank = '';
        foreach ( ['Santander', 'BBVA', 'CaixaBank', 'Sabadell', 'ING', 'Bankinter', 'Unicaja', 'Kutxabank', 'Abanca', 'Ibercaja', 'Openbank', 'Revolut', 'Wise', 'N26', 'Cajamar', 'Triodos'] as $b ) {
            if ( stripos($text, $b) !== false ) { $bank = $b; break; }
        }
        return trim($bank . ' ' . $iban);
    }

    public static function date($v){
        if ( is_float($v) || (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000 && strpos((string) $v, '/') === false) ) {
            $n = (float) $v;
            return ($n > 20000 && $n < 80000) ? gmdate('Y-m-d', (int) round(($n - 25569) * DAY_IN_SECONDS)) : null; // Excel serial
        }
        $v = trim((string) $v);
        if ( preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m) ) [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        elseif ( preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $v, $m) ) [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]];
        else return null;
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    public static function amount($v){
        if ( is_float($v) || is_int($v) ) return (float) $v;
        $s = preg_replace('/[^\d,.\-+]/', '', str_replace(['−', '–'], '-', (string) $v));
        if ( $s === '' || ! preg_match('/\d/', $s) ) return null;
        $neg = strpos($s, '-') !== false;
        $s = str_replace(['-', '+'], '', $s);
        $lc = strrpos($s, ','); $ld = strrpos($s, '.');
        if ( $lc !== false && $ld !== false ) $s = $lc > $ld ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);
        elseif ( $lc !== false ) $s = str_replace(',', '.', $s);
        elseif ( $ld !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $s) ) $s = str_replace('.', '', $s);
        return ($neg ? -1 : 1) * (float) $s;
    }

    /** Norma 43 (cuaderno 43 AEB): records 11 (account), 22 (movement), 23 (more concept). */
    private static function norma43($raw){
        $text = self::utf8($raw);
        $rows = []; $account = '';
        foreach ( preg_split('/\r\n|\r|\n/', $text) as $line ) {
            $type = substr($line, 0, 2);
            if ( $type === '11' ) $account = '…' . substr(trim(substr($line, 10, 10)), -4);
            elseif ( $type === '22' ) {
                $date = self::yymmdd(substr($line, 10, 6));
                if ( ! $date ) continue;
                $amount = (int) ltrim(substr($line, 28, 14), '0') / 100;
                $rows[] = ['date' => $date, 'value_date' => self::yymmdd(substr($line, 16, 6)), 'amount' => round(substr($line, 27, 1) === '1' ? -$amount : $amount, 2),
                    'concept' => trim(substr($line, 52, 12) . ' ' . substr($line, 64, 16)), 'balance' => null];
            } elseif ( $type === '23' && $rows ) {
                $last = count($rows) - 1;
                $rows[$last]['concept'] = trim(trim(substr($line, 4, 38)) . ' ' . trim(substr($line, 42, 38)) . ' · ' . $rows[$last]['concept'], ' ·');
            }
        }
        foreach ( $rows as &$r ) $r['concept'] = mb_substr(preg_replace('/\s+/', ' ', $r['concept']), 0, 500);
        return $rows ? ['account' => $account, 'rows' => $rows] : new \WP_Error('madof_bank_n43', __('No encuentro movimientos en el archivo Norma 43.', 'mad-oficina'));
    }

    private static function yymmdd($s){
        if ( ! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $s, $m) ) return null;
        return checkdate((int) $m[2], (int) $m[3], 2000 + (int) $m[1]) ? sprintf('20%02d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
}
