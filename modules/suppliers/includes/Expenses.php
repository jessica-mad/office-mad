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
 *   ignored    – not an invoice, or a duplicate;
 *   payment    – proof of a payment (card receipt, transfer, Western Union): joined to the
 *                invoice it pays (payment_for), never counted on its own;
 *   tax        – a payment to Hacienda or the Seguridad Social: noted in Impuestos.
 */
final class Expenses {

    const STATUSES = ['queued' => 'Leyendo…', 'registered' => 'Registrada', 'review' => 'Por revisar', 'not_mine' => 'No es mía', 'ignored' => 'Descartada', 'payment' => 'Justificante de pago', 'tax' => 'Impuesto'];

    public static function table(){ return Schema::table('expenses'); }

    public static function get($id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    /** @param array $f status, year, quarter (1-4), month (YYYY-MM), supplier_id, client_id, around (a date: 120 days before, 15 after) */
    public static function all(array $f = [], $limit = 500){
        global $wpdb;
        $w = ['1=1']; $a = [];
        if ( ! empty($f['status']) ) { $w[] = 'status = %s'; $a[] = $f['status']; }
        if ( ! empty($f['year']) ) { $w[] = 'year = %d'; $a[] = $f['year']; }
        if ( ! empty($f['quarter']) ) { $w[] = 'QUARTER(issue_date) = %d'; $a[] = $f['quarter']; }
        if ( ! empty($f['month']) ) { $w[] = 'LEFT(issue_date, 7) = %s'; $a[] = $f['month']; }
        if ( ! empty($f['supplier_id']) ) { $w[] = 'supplier_id = %d'; $a[] = $f['supplier_id']; }
        if ( ! empty($f['client_id']) ) { $w[] = 'client_id = %d'; $a[] = $f['client_id']; }
        if ( isset($f['q']) && trim((string) $f['q']) !== '' ) {
            // Every word somewhere: supplier (as read or as registered), concept, number, NIF, email, file, notes…
            foreach ( preg_split('/\s+/', trim((string) $f['q'])) as $word ) {
                $like = '%' . $wpdb->esc_like($word) . '%';
                $cols = ['supplier_name', 'supplier_tax_id', 'concept', 'number', 'category', 'sender', 'subject', 'filename', 'notes', 'recipient_name'];
                $or = array_map(fn($c) => "{$c} LIKE %s", $cols);
                $or[] = 'supplier_id IN (SELECT id FROM ' . Schema::table('suppliers') . ' WHERE name LIKE %s)';
                $args = array_fill(0, count($or), $like);
                // An amount («49,90» or «49.90»): that total or base.
                $n = str_replace(',', '.', preg_replace('/[€\s]/u', '', $word));
                if ( is_numeric($n) && str_contains($n, '.') ) { $or[] = 'total = %f OR base = %f'; $args[] = (float) $n; $args[] = (float) $n; }
                $w[] = '(' . implode(' OR ', $or) . ')';
                array_push($a, ...$args);
            }
        }
        if ( ! empty($f['around']) ) { $w[] = 'issue_date BETWEEN %s AND %s'; $a[] = gmdate('Y-m-d', strtotime($f['around'] . ' -120 days')); $a[] = gmdate('Y-m-d', strtotime($f['around'] . ' +15 days')); }
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

    /** Extension of the original file (photos and screenshots keep theirs). */
    public static function ext(array $e){
        return preg_match('/\.(png|jpe?g|webp)$/i', (string) $e['filename'], $m) ? strtolower(str_replace('jpeg', 'jpg', $m[1])) : 'pdf';
    }

    /**
     * «2026-10-03_Google_12,10.pdf». A payment proof takes the name of the invoice it pays
     * plus «_pago» (so they sit together); a tax payment, «_Impuesto-autonomos_».
     */
    public static function filename(array $e){
        if ( $e['status'] === 'payment' && ! empty($e['payment_for']) && ($inv = self::get($e['payment_for'])) ) {
            return preg_replace('/\.[a-z]+$/', '', self::filename($inv)) . '_pago.' . self::ext($e);
        }
        $who = $e['status'] === 'tax' ? 'Impuesto ' . (($e['extraction']['tax_kind'] ?? '') ?: 'otros') : ($e['supplier_name'] ?: 'Proveedor');
        $name = sanitize_file_name(remove_accents($who));
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-') ?: 'Proveedor';
        return ($e['issue_date'] ?: current_time('Y-m-d')) . '_' . mb_substr($name, 0, 40) . '_' . number_format((float) $e['total'], 2, ',', '') . '.' . self::ext($e);
    }

    /**
     * Folder in the archive («Facturas para MAD»): «AAAA/AAAA-Tn» (a payment, next to its
     * invoice), «No son mías/AAAA» or «Duplicados».
     */
    public static function folder(array $e){
        if ( $e['status'] === 'payment' && ! empty($e['payment_for']) && ($inv = self::get($e['payment_for'])) ) return self::folder($inv);
        if ( $e['status'] === 'ignored' ) return ['Duplicados'];
        $date = $e['issue_date'] ?: current_time('Y-m-d');
        $y = substr($date, 0, 4);
        return $e['status'] === 'not_mine' ? ['No son mías', $y] : [$y, $y . '-T' . (int) ceil((int) substr($date, 5, 2) / 3)];
    }

    /** Files already known by where they came from (a Drive file id, «drive:…») or where they are. @return string[] the unknown ones */
    public static function unknown_refs(array $refs){
        global $wpdb;
        $refs = array_values(array_unique(array_filter(array_map(fn($r) => preg_replace('/[^A-Za-z0-9_\-:]/', '', (string) $r), $refs))));
        if ( ! $refs ) return [];
        $in = implode(',', array_fill(0, count($refs), '%s'));
        $ids = array_map(fn($r) => preg_replace('/^drive:/', '', $r), $refs);
        $known = $wpdb->get_col($wpdb->prepare('SELECT source_ref FROM ' . self::table() . " WHERE source_ref IN ({$in})", ...$refs));
        $known_files = $wpdb->get_col($wpdb->prepare('SELECT drive_file_id FROM ' . self::table() . " WHERE drive_file_id IN ({$in})", ...$ids));
        $copies = get_option('madoficina_drive_seen', []); // copies of files already known
        $known = array_merge($known, is_array($copies) ? $copies : []);
        return array_values(array_filter($refs, fn($r) => ! in_array($r, $known, true) && ! in_array(preg_replace('/^drive:/', '', $r), $known_files, true)));
    }

    /** Payment proofs joined to an invoice. */
    public static function payments_for($id){
        global $wpdb;
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE status = 'payment' AND payment_for = %d ORDER BY issue_date, id", $id), ARRAY_A));
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
        foreach ( ['id', 'supplier_id', 'client_id', 'year', 'payment_for'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        foreach ( ['base', 'vat', 'irpf', 'total', 'paid_eur'] as $k ) $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        $r['extraction'] = $r['extraction'] ? (json_decode($r['extraction'], true) ?: []) : [];
        return $r;
    }
}
