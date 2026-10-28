<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Invoices. Series:
 *   F  – facturas (España), number «AA-NNN», correlative per year, no gaps.
 *   R  – rectificativas, «R-AA-NN».
 *   AR – Argentina: issued on the Argentine tax platform; kept here only to
 *        have everything together. Never numbered here, never in the Spanish books.
 */
final class Invoices {

    const SERIES = ['F' => 'Facturas', 'R' => 'Rectificativas', 'AR' => 'Argentina (fuera de los libros)'];
    const FISCAL = ['F', 'R'];
    const STATUSES = ['draft' => 'Borrador', 'issued' => 'Emitida', 'sent' => 'Enviada', 'cancelled' => 'Anulada'];

    public static function table(){ return Schema::table('invoices'); }

    public static function get($id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        if ( ! $row ) return null;
        $row = self::hydrate($row);
        $row['lines'] = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('invoice_lines') . ' WHERE invoice_id = %d ORDER BY position, id', $id), ARRAY_A);
        return $row;
    }

    /**
     * @param array $filters year, status, series, profile_id, q
     */
    public static function all(array $filters = [], $limit = 500){
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        foreach ( ['year' => '%d', 'status' => '%s', 'series' => '%s', 'profile_id' => '%d', 'client_id' => '%d'] as $k => $f ) {
            if ( isset($filters[$k]) && $filters[$k] !== '' && $filters[$k] !== null ) { $where[] = "{$k} = {$f}"; $args[] = $filters[$k]; }
        }
        if ( ! empty($filters['q']) ) {
            $where[] = '(client_name LIKE %s OR number LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['q']) . '%';
            $args[] = $like; $args[] = $like;
        }
        $args[] = $limit;
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY issue_date DESC, seq DESC, id DESC LIMIT %d';
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A));
    }

    public static function years(){
        global $wpdb;
        return array_map('intval', $wpdb->get_col('SELECT DISTINCT year FROM ' . self::table() . ' ORDER BY year DESC'));
    }

    /** «26-024» for series F, «R-26-02» for R. */
    public static function format_number($series, $year, $seq){
        $yy = str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT);
        return $series === 'R' ? sprintf('R-%s-%02d', $yy, $seq) : sprintf('%s-%03d', $yy, $seq);
    }

    /**
     * The number a draft will take if it is issued now (on its date): shown wherever it is
     * about to be sent, so what goes out is never a surprise. Issuing checks it again.
     */
    public static function preview_number(array $inv, $issue_date = null){
        if ( $inv['number'] ) return $inv['number'];
        if ( ! in_array($inv['series'], self::FISCAL, true) ) return '';
        $year = (int) substr($issue_date ?: $inv['issue_date'] ?: current_time('Y-m-d'), 0, 4);
        return self::format_number($inv['series'], $year, self::next_seq($inv['series'], $year));
    }

    /** Next free sequence (no gaps, never reused). */
    public static function next_seq($series, $year){
        global $wpdb;
        return 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(seq) FROM ' . self::table() . ' WHERE series = %s AND year = %d', $series, $year));
    }

    /**
     * Gives a draft its number, atomically (a MySQL lock, so two approvals at the
     * same time can never take the same number).
     * @return array|\WP_Error the issued invoice
     */
    public static function assign_number($id, $issue_date = null){
        global $wpdb;
        $inv = self::get($id);
        if ( ! $inv ) return new \WP_Error('madof_inv', __('Esa factura no existe.', 'mad-oficina'));
        if ( $inv['number'] ) return $inv;
        if ( ! in_array($inv['series'], self::FISCAL, true) ) return new \WP_Error('madof_inv_series', __('Las facturas de la serie Argentina no se numeran aquí.', 'mad-oficina'));
        if ( ! $wpdb->get_var("SELECT GET_LOCK('madof_invoice_number', 10)") ) return new \WP_Error('madof_inv_lock', __('No he podido reservar el número. Inténtalo otra vez.', 'mad-oficina'));
        try {
            $date = $issue_date ?: current_time('Y-m-d');
            $year = (int) substr($date, 0, 4);
            $seq = self::next_seq($inv['series'], $year);
            $number = self::format_number($inv['series'], $year, $seq);
            // number IS NULL in the WHERE: a draft that someone else just numbered is left alone.
            $wpdb->update(self::table(), [
                'number' => $number, 'seq' => $seq, 'year' => $year, 'issue_date' => $date,
                'status' => 'issued', 'updated_at' => Time::now(),
            ], ['id' => $id, 'number' => null]);
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('madof_invoice_number')");
        }
        return self::get($id);
    }

    /**
     * Totals from lines and rates, rounded per amount as on paper.
     * @return array base, vat, irpf, total
     */
    public static function totals(array $lines, $vat_rate, $irpf_rate){
        $base = 0.0;
        foreach ( $lines as $l ) $base += Money::round((float) ($l['amount'] ?? ((float) ($l['quantity'] ?? 1) * (float) ($l['unit_price'] ?? 0))));
        $base = Money::round($base);
        $vat = Money::round($base * (float) $vat_rate / 100);
        $irpf = Money::round($base * (float) $irpf_rate / 100);
        return ['base' => $base, 'vat' => $vat, 'irpf' => $irpf, 'total' => Money::round($base + $vat - $irpf)];
    }

    /** @return int invoice id */
    public static function insert(array $inv, array $lines){
        global $wpdb;
        $now = Time::now();
        if ( empty($inv['client_id']) && ! empty($inv['profile_id']) ) {
            // The invoice belongs to whichever office client its fiscal profile is linked to.
            $inv['client_id'] = $wpdb->get_var($wpdb->prepare('SELECT client_id FROM ' . Schema::table('billing_profiles') . ' WHERE id = %d', $inv['profile_id'])) ?: null;
        }
        $wpdb->insert(self::table(), $inv + ['created_at' => $now, 'updated_at' => $now]);
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 0;
        foreach ( array_values($lines) as $i => $l ) {
            $wpdb->insert(Schema::table('invoice_lines'), [
                'invoice_id' => $id, 'position' => $i,
                'description' => (string) $l['description'],
                'quantity' => $l['quantity'] ?? 1,
                'unit_price' => $l['unit_price'] ?? $l['amount'],
                'amount' => $l['amount'],
                'expense_id' => $l['expense_id'] ?? null,
                'item_id' => $l['item_id'] ?? null,
                'item_month' => $l['item_month'] ?? null,
            ]);
        }
        return $id;
    }

    public static function set_paid($id, $paid){
        global $wpdb;
        return $wpdb->update(self::table(), ['paid' => $paid ? 1 : 0, 'paid_at' => $paid ? Time::now() : null, 'updated_at' => Time::now()], ['id' => $id]);
    }

    /**
     * Figures for the summary, like the «Facturación mensual» tab: only Spanish
     * books (F and R), only issued invoices.
     * @return array month (1-12) => [base, vat, irpf, total, count]
     */
    public static function monthly($year){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT MONTH(issue_date) AS m, SUM(base) AS base, SUM(vat) AS vat, SUM(irpf) AS irpf, SUM(total) AS total, COUNT(*) AS n FROM ' . self::table()
            . " WHERE year = %d AND series IN ('F','R') AND status IN ('issued','sent') GROUP BY MONTH(issue_date)", $year
        ), ARRAY_A);
        $out = array_fill(1, 12, ['base' => 0.0, 'vat' => 0.0, 'irpf' => 0.0, 'total' => 0.0, 'n' => 0]);
        foreach ( $rows as $r ) $out[(int) $r['m']] = ['base' => (float) $r['base'], 'vat' => (float) $r['vat'], 'irpf' => (float) $r['irpf'], 'total' => (float) $r['total'], 'n' => (int) $r['n']];
        return $out;
    }

    /** Per client for one year (Spanish books), like the «Por clientes» tab. */
    public static function by_client($year){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT COALESCE(p.legal_name, MAX(i.client_name)) AS client_name, i.profile_id, SUM(i.base) AS base, SUM(i.vat) AS vat, SUM(i.irpf) AS irpf, SUM(i.total) AS total, COUNT(*) AS n FROM ' . self::table() . ' i LEFT JOIN ' . Schema::table('billing_profiles') . ' p ON p.id = i.profile_id'
            . " WHERE i.year = %d AND i.series IN ('F','R') AND i.status IN ('issued','sent') GROUP BY i.profile_id, p.legal_name ORDER BY base DESC", $year
        ), ARRAY_A);
    }

    /** Argentina series totals, shown apart. */
    public static function external_total($year){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT SUM(total) AS total, COUNT(*) AS n FROM ' . self::table() . " WHERE year = %d AND series = 'AR'", $year), ARRAY_A);
    }

    /** Unpaid invoices of the last N months (older years of the old sheet often lack the «PAGO» mark). */
    public static function unpaid($months = 12){
        global $wpdb;
        $since = gmdate('Y-m-d', strtotime('-' . (int) $months . ' months'));
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE series IN ('F','R') AND status IN ('issued','sent') AND paid = 0 AND issue_date >= %s ORDER BY issue_date ASC", $since), ARRAY_A));
    }

    private static function hydrate($row){
        foreach ( ['id', 'year', 'seq', 'profile_id', 'client_id', 'rectifies_id'] as $k ) $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        foreach ( ['base', 'vat', 'irpf', 'total', 'vat_rate', 'irpf_rate'] as $k ) $row[$k] = (float) $row[$k];
        $row['paid'] = (bool) $row['paid'];
        $row['legacy'] = $row['legacy'] ? (json_decode($row['legacy'], true) ?: []) : [];
        return $row;
    }
}
