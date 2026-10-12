<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Finanzas → Resumen: income against expenses, month by month, without VAT.
 *   income   – issued invoices F/R (Spain); Argentina apart;
 *   expenses – registered supplier invoices in euros, or what was paid in euros
 *              for those in another currency (paid_eur); the rest is counted apart;
 *   taxes    – what was paid; only autónomos/other are costs (VAT passes through).
 * Orientative: the gestoría files the real figures.
 */
final class Finance {

    private static function has_expenses(){
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Schema::table('expenses')));
    }

    /** @return array months[1..12] + totals + quarters + extras */
    public static function year($year){
        global $wpdb;
        $zero = ['income' => 0.0, 'income_ar' => 0.0, 'vat_charged' => 0.0, 'irpf' => 0.0, 'expenses' => 0.0, 'vat_paid' => 0.0, 'tax_costs' => 0.0, 'vat_settled' => 0.0];
        $m = array_fill(1, 12, $zero);

        foreach ( $wpdb->get_results($wpdb->prepare('SELECT MONTH(issue_date) AS m, series, SUM(base) AS base, SUM(vat) AS vat, SUM(irpf) AS irpf FROM ' . Invoices::table() . " WHERE status IN ('issued','sent') AND YEAR(issue_date) = %d GROUP BY m, series", $year), ARRAY_A) as $r ) {
            $i = (int) $r['m'];
            if ( $r['series'] === 'AR' ) { $m[$i]['income_ar'] += (float) $r['base']; continue; }
            $m[$i]['income'] += (float) $r['base'];
            $m[$i]['vat_charged'] += (float) $r['vat'];
            $m[$i]['irpf'] += (float) $r['irpf'];
        }

        $foreign_missing = 0; $categories = [];
        if ( self::has_expenses() ) {
            $t = Schema::table('expenses');
            foreach ( $wpdb->get_results($wpdb->prepare("SELECT MONTH(issue_date) AS m, currency, category, base, vat, paid_eur FROM {$t} WHERE status = 'registered' AND YEAR(issue_date) = %d", $year), ARRAY_A) as $r ) {
                $i = (int) $r['m'];
                if ( $r['currency'] === 'EUR' ) { $base = (float) $r['base']; $vat = (float) $r['vat']; }
                elseif ( $r['paid_eur'] !== null ) { $base = (float) $r['paid_eur']; $vat = 0.0; }
                else { $foreign_missing++; continue; }
                $m[$i]['expenses'] += $base;
                $m[$i]['vat_paid'] += $vat;
                $categories[$r['category'] ?: 'other'] = ($categories[$r['category'] ?: 'other'] ?? 0) + $base;
            }
            arsort($categories);
        }

        foreach ( Taxes::year($year) as $tx ) {
            if ( ! $tx['paid_on'] || (int) substr($tx['paid_on'], 0, 4) !== (int) $year ) continue;
            $i = (int) substr($tx['paid_on'], 5, 2);
            if ( in_array($tx['kind'], Taxes::COSTS, true) ) $m[$i]['tax_costs'] += $tx['amount'];
            elseif ( $tx['kind'] === 'vat_303' ) $m[$i]['vat_settled'] += $tx['amount'];
        }

        foreach ( $m as $i => $row ) $m[$i]['result'] = round($row['income'] + $row['income_ar'] - $row['expenses'] - $row['tax_costs'], 2);
        $sum = fn($k, $months = null) => round(array_sum(array_map(fn($r) => $r[$k], $months ?? $m)), 2);
        $quarters = [];
        for ( $q = 1; $q <= 4; $q++ ) {
            $qm = array_slice($m, ($q - 1) * 3, 3);
            $quarters[$q] = [];
            foreach ( array_keys($zero + ['result' => 0]) as $k ) $quarters[$q][$k] = $sum($k, $qm);
        }
        $totals = [];
        foreach ( array_keys($zero + ['result' => 0]) as $k ) $totals[$k] = $sum($k);

        $clients = $wpdb->get_results($wpdb->prepare('SELECT COALESCE(p.legal_name, MAX(i.client_name)) AS name, SUM(i.base) AS base FROM ' . Invoices::table() . ' i LEFT JOIN ' . Schema::table('billing_profiles') . " p ON p.id = i.profile_id WHERE i.status IN ('issued','sent') AND YEAR(i.issue_date) = %d GROUP BY i.profile_id, p.legal_name ORDER BY base DESC LIMIT 8", $year), ARRAY_A);

        return ['months' => $m, 'quarters' => $quarters, 'totals' => $totals, 'categories' => $categories, 'clients' => $clients, 'foreign_missing' => $foreign_missing, 'has_expenses' => self::has_expenses()];
    }
}
