<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Taxes and social security paid. Entered by hand (IVA, others) or once as a
 * fixed monthly amount (cuota de autónomos). The VAT figure per quarter is only
 * an orientative estimate: the gestoría calculates and files the real one.
 */
final class Taxes {

    const KINDS = [
        'vat_303'   => 'IVA (modelo 303)',
        'autonomos' => 'Cuota de autónomos',
        'irpf_130'  => 'IRPF pagos fraccionados (130)',
        'other'     => 'Otros (tasas, multas, IBI…)',
    ];
    /** Costs of the business (they lower «lo que te queda»). VAT is not: it is the clients' money passing through. */
    const COSTS = ['autonomos', 'other'];

    public static function table(){ return Schema::table('taxes'); }

    public static function get($id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    public static function year($year){
        global $wpdb;
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE period LIKE %s OR YEAR(paid_on) = %d ORDER BY COALESCE(paid_on, period) DESC, id DESC', $wpdb->esc_like((string) $year) . '%', $year
        ), ARRAY_A));
    }

    public static function find($kind, $period){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE kind = %s AND period = %s', $kind, $period), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    /** @return int|\WP_Error */
    public static function save(array $d, $id = 0){
        global $wpdb;
        $kind = isset(self::KINDS[$d['kind'] ?? '']) ? $d['kind'] : null;
        if ( ! $kind ) return new \WP_Error('madof_tax', __('Elige qué impuesto es.', 'mad-oficina'));
        $period = strtoupper(trim((string) ($d['period'] ?? '')));
        if ( ! preg_match('/^\d{4}(-T[1-4]|-\d{2})?$/', $period) ) return new \WP_Error('madof_tax_period', __('El periodo es un año (2026), un trimestre (2026-T3) o un mes (2026-10).', 'mad-oficina'));
        $amount = Money::parse((string) ($d['amount'] ?? ''));
        if ( $amount === null ) return new \WP_Error('madof_tax_amount', __('Pon el importe.', 'mad-oficina'));
        $paid = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['paid_on'] ?? '')) ? $d['paid_on'] : null;
        $row = [
            'kind' => $kind, 'period' => $period, 'paid_on' => $paid, 'amount' => $amount,
            'status' => $paid ? 'paid' : 'pending', 'source' => in_array($d['source'] ?? '', ['manual', 'monthly'], true) ? $d['source'] : 'manual',
            'file_url' => esc_url_raw($d['file_url'] ?? '') ?: null, 'notes' => sanitize_textarea_field($d['notes'] ?? '') ?: null,
            'updated_at' => Time::now(),
        ];
        if ( ! $id && $kind !== 'other' && ($old = self::find($kind, $period)) ) $id = $old['id']; // one per period
        if ( $id ) { $wpdb->update(self::table(), $row, ['id' => $id]); return (int) $id; }
        $wpdb->insert(self::table(), $row + ['created_at' => Time::now()]);
        return (int) $wpdb->insert_id;
    }

    public static function delete($id){
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => $id]);
    }

    /**
     * The fixed monthly fee (cuota de autónomos) is noted on its own each month,
     * from the month it was set, on its day. Changing the amount applies from then on.
     * @return int entries created
     */
    public static function apply_monthly(array $settings, $today = null){
        $amount = (float) ($settings['autonomos_amount'] ?? 0);
        $from = Months::valid($settings['autonomos_from'] ?? '');
        if ( $amount <= 0 || ! $from ) return 0;
        $today = $today ?: current_time('Y-m-d');
        $day = max(1, min(31, (int) ($settings['autonomos_day'] ?? 0) ?: 28));
        $n = 0;
        foreach ( Months::range($from, Months::of($today)) as $m ) {
            $date = $m . '-' . str_pad((string) min($day, (int) gmdate('t', strtotime($m . '-01'))), 2, '0', STR_PAD_LEFT);
            if ( $date > $today || self::find('autonomos', $m) ) continue;
            self::save(['kind' => 'autonomos', 'period' => $m, 'paid_on' => $date, 'amount' => (string) $amount, 'source' => 'monthly']);
            $n++;
        }
        return $n;
    }

    /**
     * Orientative VAT of a quarter: charged on issued invoices (F/R) − paid on registered
     * expenses in euros. @return array{charged:float, paid:float, result:float}
     */
    public static function vat_estimate($year, $q){
        global $wpdb;
        $from = sprintf('%04d-%02d-01', $year, ($q - 1) * 3 + 1);
        $to = gmdate('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $q * 3)));
        $charged = (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(vat) FROM ' . Invoices::table() . " WHERE series IN ('F','R') AND status IN ('issued','sent') AND issue_date BETWEEN %s AND %s", $from, $to));
        $paid = 0.0;
        if ( $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Schema::table('expenses'))) ) {
            $paid = (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(vat) FROM ' . Schema::table('expenses') . " WHERE status = 'registered' AND currency = 'EUR' AND issue_date BETWEEN %s AND %s", $from, $to));
        }
        return ['charged' => round($charged, 2), 'paid' => round($paid, 2), 'result' => round($charged - $paid, 2)];
    }

    /** «2026-T3» → «3.er trimestre 2026», «2026-10» → «oct 2026». */
    public static function period_label($p){
        if ( preg_match('/^(\d{4})-T([1-4])$/', $p, $m) ) return sprintf(__('T%1$d %2$s', 'mad-oficina'), $m[2], $m[1]);
        if ( Months::valid($p) ) return Months::label($p);
        return $p;
    }

    private static function hydrate($r){
        $r['id'] = (int) $r['id'];
        $r['amount'] = (float) $r['amount'];
        return $r;
    }
}
