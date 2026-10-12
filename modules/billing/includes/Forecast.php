<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;

/**
 * Month-by-month income, without VAT. Internal only (never shown to clients).
 *   real      – issued invoices (past months and what is already issued this month);
 *   confirmed – agreements (monthly fees and one-off jobs);
 *   possible  – quotes sent and not decided, raw and weighted by their probability.
 */
final class Forecast {

    /**
     * @return array{months:string[], now:string, rows:array, totals:array, alerts:string[]}
     */
    public static function compute($from, $to, $today = null){
        global $wpdb;
        $today = $today ?: current_time('Y-m-d');
        $now = Months::of($today);
        $months = Months::range($from, $to);
        $rows = [];
        $clients = [];
        foreach ( Clients::all() as $c ) $clients[$c['id']] = $c;
        $profiles = [];
        foreach ( Profiles::all() as $p ) $profiles[$p['id']] = $p;

        $row = function($key, $label, $code = '') use (&$rows, $months){
            if ( ! isset($rows[$key]) ) {
                $zero = array_fill_keys($months, 0.0);
                $rows[$key] = ['key' => $key, 'label' => $label, 'code' => $code, 'real' => $zero, 'confirmed' => $zero, 'possible' => $zero, 'weighted' => $zero, 'quotes' => []];
            }
            return $key;
        };
        $key_for = function($client_id, $profile_id, $fallback) use ($clients, $profiles, $row){
            if ( $client_id && isset($clients[$client_id]) ) return $row('c' . $client_id, $clients[$client_id]['name'], $clients[$client_id]['code']);
            if ( $profile_id && isset($profiles[$profile_id]) ) return $row('p' . $profile_id, $profiles[$profile_id]['legal_name']);
            return $row('n' . md5($fallback), $fallback);
        };

        foreach ( $wpdb->get_results($wpdb->prepare(
            'SELECT client_id, profile_id, MAX(client_name) AS name, LEFT(issue_date, 7) AS m, SUM(base) AS base FROM ' . Invoices::table()
            . " WHERE status IN ('issued','sent') AND issue_date >= %s AND issue_date <= %s GROUP BY client_id, profile_id, m",
            $from . '-01', $to . '-31'
        ), ARRAY_A) as $r ) {
            $k = $key_for((int) $r['client_id'], (int) $r['profile_id'], $r['name']);
            $rows[$k]['real'][$r['m']] += (float) $r['base'];
        }

        $items = Agreements::between($from, $to);
        foreach ( $items as $it ) {
            $k = $key_for($it['client_id'], $it['profile_id'], $it['concept']);
            foreach ( $months as $m ) if ( Agreements::covers($it, $m) && ! Calendar::skipped($it['id'], $m) ) $rows[$k]['confirmed'][$m] += $it['amount'];
        }

        $quotes = Quotes::all(['sent']);
        foreach ( $quotes as $q ) {
            $k = $q['client_id'] || $q['profile_id'] ? $key_for($q['client_id'], $q['profile_id'], $q['prospect_name']) : $row('q' . $q['id'], $q['prospect_name']);
            $rows[$k]['quotes'][] = ['number' => $q['number'], 'title' => $q['title'], 'probability' => $q['probability'], 'id' => $q['id']];
            foreach ( $months as $m ) {
                $v = 0.0;
                foreach ( $q['lines'] as $l ) $v += Quotes::line_in($l, $m);
                $rows[$k]['possible'][$m] += $v;
                $rows[$k]['weighted'][$m] += $v * $q['probability'] / 100;
            }
        }

        // What each row is expected to bring each month: real in the past, the larger of
        // real and confirmed this month (some invoices may already be out), confirmed later.
        $totals = ['expected' => [], 'real' => [], 'confirmed' => [], 'possible' => [], 'weighted' => []];
        foreach ( $rows as &$r ) {
            $r['expected'] = [];
            foreach ( $months as $m ) {
                $r['expected'][$m] = $m < $now ? $r['real'][$m] : ( $m === $now ? max($r['real'][$m], $r['confirmed'][$m]) : $r['confirmed'][$m] );
                foreach ( $totals as $t => $_ ) $totals[$t][$m] = ($totals[$t][$m] ?? 0.0) + $r[$t][$m];
            }
            $r['sum'] = array_sum($r['expected']) + array_sum($r['weighted']);
        }
        unset($r);
        uasort($rows, fn($a, $b) => $b['sum'] <=> $a['sum']);

        return ['months' => $months, 'now' => $now, 'rows' => array_values($rows), 'totals' => $totals,
            'alerts' => self::alerts($items, $rows, $now, $today, $quotes, $months)];
    }

    /** @return string[] */
    private static function alerts(array $items, array $rows, $now, $today, array $quotes, array $months){
        global $wpdb;
        $out = [];
        $profiles = [];
        foreach ( Profiles::all() as $p ) $profiles[$p['id']] = $p;

        // Fees that end soon and are not continued.
        $continued = array_map('intval', $wpdb->get_col('SELECT replaces_id FROM ' . Agreements::table() . " WHERE status = 'active' AND replaces_id IS NOT NULL"));
        foreach ( $items as $it ) {
            if ( $it['kind'] !== 'monthly' || $it['end_month'] === null || in_array($it['id'], $continued, true) ) continue;
            if ( $it['end_month'] < $now || $it['end_month'] > Months::add($now, 3) ) continue;
            $out[] = sprintf(__('%1$s: «%2$s» termina en %3$s. Desde %4$s entran %5$s menos al mes.', 'mad-oficina'),
                $profiles[$it['profile_id']]['legal_name'] ?? '', $it['concept'], Months::label($it['end_month']), Months::label(Months::add($it['end_month'], 1)), Money::format($it['amount']));
        }

        // Agreed but not invoiced in the last three months.
        foreach ( $rows as $r ) {
            foreach ( $r['confirmed'] as $m => $c ) {
                if ( $m >= $now || $m < Months::add($now, -3) || $c - $r['real'][$m] < 1 ) continue;
                $out[] = sprintf(__('%1$s: en %2$s había %3$s acordados y se han facturado %4$s.', 'mad-oficina'), $r['label'], Months::label($m), Money::format($c), Money::format($r['real'][$m]));
            }
        }

        // Months clearly below the average of the last six.
        $past = $wpdb->get_col($wpdb->prepare(
            'SELECT SUM(base) FROM ' . Invoices::table() . " WHERE status IN ('issued','sent') AND issue_date >= %s AND issue_date < %s GROUP BY LEFT(issue_date, 7)",
            Months::add($now, -6) . '-01', $now . '-01'
        ));
        $avg = $past ? array_sum(array_map('floatval', $past)) / 6 : 0;
        if ( $avg > 0 ) {
            foreach ( Months::range(Months::add($now, 1), Months::add($now, 6)) as $m ) {
                $expected = 0.0;
                foreach ( $rows as $r ) if ( isset($r['confirmed'][$m]) ) $expected += $r['confirmed'][$m];
                if ( ! in_array($m, $months, true) ) break; // outside the range on screen
                if ( $expected < 0.75 * $avg ) {
                    $out[] = sprintf(__('%1$s va un %2$d %% por debajo de tu media de los últimos 6 meses (%3$s confirmados frente a %4$s).', 'mad-oficina'),
                        ucfirst(Months::label($m)), (int) round(100 - 100 * $expected / $avg), Money::format($expected), Money::format($avg));
                    break; // the first one is enough
                }
            }
        }

        foreach ( $quotes as $q ) {
            if ( $q['valid_until'] && $q['valid_until'] < $today ) {
                $out[] = sprintf(__('El presupuesto %1$s (%2$s) caducó el %3$s: ¿lo marcas como aceptado, rechazado o caducado?', 'mad-oficina'), $q['number'], $q['prospect_name'], date_i18n('j/m/Y', strtotime($q['valid_until'])));
            }
        }
        return $out;
    }
}
