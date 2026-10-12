<?php
namespace MAD_Oficina\Suppliers;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Received invoices.
 *   registered – MAD's expense (counts in the books and the gestoría package);
 *   review     – Jessica decides (new supplier, or unclear who it is addressed to);
 *   not_mine   – addressed to a client or someone else: kept aside, never counted;
 *   ignored    – not an invoice, or a duplicate.
 */
final class Expenses {

    const STATUSES = ['queued' => 'Leyendo…', 'registered' => 'Registrada', 'review' => 'Por revisar', 'not_mine' => 'No es mía', 'ignored' => 'Descartada'];

    public static function table(){ return Schema::table('expenses'); }

    public static function get($id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    /** @param array $f status, year, quarter (1-4), month (YYYY-MM), supplier_id, client_id */
    public static function all(array $f = [], $limit = 500){
        global $wpdb;
        $w = ['1=1']; $a = [];
        if ( ! empty($f['status']) ) { $w[] = 'status = %s'; $a[] = $f['status']; }
        if ( ! empty($f['year']) ) { $w[] = 'year = %d'; $a[] = $f['year']; }
        if ( ! empty($f['quarter']) ) { $w[] = 'QUARTER(issue_date) = %d'; $a[] = $f['quarter']; }
        if ( ! empty($f['month']) ) { $w[] = 'LEFT(issue_date, 7) = %s'; $a[] = $f['month']; }
        if ( ! empty($f['supplier_id']) ) { $w[] = 'supplier_id = %d'; $a[] = $f['supplier_id']; }
        if ( ! empty($f['client_id']) ) { $w[] = 'client_id = %d'; $a[] = $f['client_id']; }
        $a[] = $limit;
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY issue_date DESC, id DESC LIMIT %d';
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare($sql, ...$a), ARRAY_A));
    }

    public static function insert(array $row){
        global $wpdb;
        if ( ! empty($row['issue_date']) ) $row['year'] = (int) substr($row['issue_date'], 0, 4);
        $wpdb->insert(self::table(), $row + ['created_at' => Time::now(), 'updated_at' => Time::now()]);
        return (int) $wpdb->insert_id;
    }

    public static function update($id, array $fields){
        global $wpdb;
        if ( ! empty($fields['issue_date']) ) $fields['year'] = (int) substr($fields['issue_date'], 0, 4);
        return $wpdb->update(self::table(), $fields + ['updated_at' => Time::now()], ['id' => $id]);
    }

    public static function by_hash($hash){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE file_hash = %s', $hash), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    /** @param string[] $hashes @return string[] the ones never seen */
    public static function unknown_hashes(array $hashes){
        global $wpdb;
        $hashes = array_values(array_filter(array_map('strtolower', $hashes), fn($h) => preg_match('/^[a-f0-9]{64}$/', $h)));
        if ( ! $hashes ) return [];
        $known = $wpdb->get_col('SELECT file_hash FROM ' . self::table() . " WHERE file_hash IN ('" . implode("','", $hashes) . "')");
        return array_values(array_diff($hashes, $known));
    }

    /** Same invoice already registered (same supplier and number, or same supplier, date and total). */
    public static function duplicate_of(array $x, $supplier_id){
        global $wpdb;
        $t = self::table();
        if ( $supplier_id && ! empty($x['number']) ) {
            $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE supplier_id = %d AND number = %s AND status = 'registered' LIMIT 1", $supplier_id, $x['number']));
            if ( $id ) return (int) $id;
        }
        if ( $supplier_id && ! empty($x['issue_date']) && isset($x['total']) ) {
            $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE supplier_id = %d AND issue_date = %s AND total = %f AND status = 'registered' LIMIT 1", $supplier_id, $x['issue_date'], (float) $x['total']));
            if ( $id ) return (int) $id;
        }
        return 0;
    }

    /** «2026-10-03_Google_12,10.pdf» */
    public static function filename(array $e){
        $name = sanitize_file_name(remove_accents($e['supplier_name'] ?: 'Proveedor'));
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-') ?: 'Proveedor';
        return ($e['issue_date'] ?: current_time('Y-m-d')) . '_' . mb_substr($name, 0, 40) . '_' . number_format((float) $e['total'], 2, ',', '') . '.pdf';
    }

    /** Folder in the shared drive: «Recibidas/AAAA/Tn» or «Recibidas/No son mías/AAAA». */
    public static function folder(array $e){
        $date = $e['issue_date'] ?: current_time('Y-m-d');
        $y = substr($date, 0, 4);
        return $e['status'] === 'not_mine' ? ['No son mías', $y] : [$y, 'T' . (int) ceil((int) substr($date, 5, 2) / 3)];
    }

    /** Re-billable expenses of a client not yet on any (non-cancelled) invoice line. */
    public static function pending_rebill($client_id, $until = null){
        global $wpdb;
        $sql = 'SELECT e.* FROM ' . self::table() . ' e WHERE e.client_id = %d AND e.status = %s'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('invoice_lines') . ' l JOIN ' . Schema::table('invoices') . " i ON i.id = l.invoice_id WHERE l.expense_id = e.id AND i.status <> 'cancelled')";
        $args = [$client_id, 'registered'];
        if ( $until ) { $sql .= ' AND e.issue_date <= %s'; $args[] = $until; }
        $sql .= ' ORDER BY e.issue_date, e.id';
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A));
    }

    public static function rebilled_in($expense_id){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SELECT COALESCE(i.number, %s) FROM ' . Schema::table('invoice_lines') . ' l JOIN ' . Schema::table('invoices') . " i ON i.id = l.invoice_id WHERE l.expense_id = %d AND i.status <> 'cancelled' LIMIT 1", __('borrador', 'mad-oficina'), $expense_id));
    }

    /** Totals of registered expenses by month of a year (base, VAT, total). */
    public static function monthly($year){
        global $wpdb;
        $out = [];
        for ( $m = 1; $m <= 12; $m++ ) $out[$m] = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'n' => 0];
        foreach ( $wpdb->get_results($wpdb->prepare('SELECT MONTH(issue_date) AS m, SUM(base) AS base, SUM(vat) AS vat, SUM(total) AS total, COUNT(*) AS n FROM ' . self::table() . " WHERE status = 'registered' AND year = %d AND currency = 'EUR' GROUP BY m", $year), ARRAY_A) as $r ) {
            $out[(int) $r['m']] = ['base' => (float) $r['base'], 'vat' => (float) $r['vat'], 'total' => (float) $r['total'], 'n' => (int) $r['n']];
        }
        return $out;
    }

    private static function hydrate($r){
        foreach ( ['id', 'supplier_id', 'client_id', 'year'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        foreach ( ['base', 'vat', 'irpf', 'total'] as $k ) $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        $r['extraction'] = $r['extraction'] ? (json_decode($r['extraction'], true) ?: []) : [];
        return $r;
    }
}
