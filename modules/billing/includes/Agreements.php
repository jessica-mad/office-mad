<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Agreements (billing_items): what each client pays and when.
 *   monthly – a fee every month from start_month to end_month (open when null);
 *   once    – a one-off job billed in start_month.
 * Base amounts, without VAT. Changing an amount closes the old agreement and opens
 * a new one (replaces_id), so the history stays. «cancelled» = entered by mistake.
 */
final class Agreements {

    const KINDS = ['monthly' => 'Fee mensual', 'once' => 'Encargo puntual'];

    public static function table(){ return Schema::table('billing_items'); }

    public static function get($id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    /** Active agreements (optionally of one profile / client), newest start first. */
    public static function all(array $f = []){
        global $wpdb;
        $where = ["status = 'active'"]; $args = [];
        if ( ! empty($f['profile_id']) ) { $where[] = 'profile_id = %d'; $args[] = $f['profile_id']; }
        if ( ! empty($f['client_id']) ) { $where[] = 'client_id = %d'; $args[] = $f['client_id']; }
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY start_month DESC, id DESC';
        return array_map([self::class, 'hydrate'], $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A));
    }

    /** Agreements touching the months $from..$to. */
    public static function between($from, $to){
        global $wpdb;
        return array_map([self::class, 'hydrate'], $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE status = 'active' AND start_month <= %s AND (end_month IS NULL OR end_month >= %s)", $to, $from
        ), ARRAY_A));
    }

    public static function covers(array $item, $month){
        return $item['start_month'] <= $month && ( $item['end_month'] === null || $item['end_month'] >= $month );
    }

    /** @return int|\WP_Error */
    public static function create(array $d){
        global $wpdb;
        $profile = Profiles::get((int) ($d['profile_id'] ?? 0));
        if ( ! $profile ) return new \WP_Error('madof_item_profile', __('Elige el cliente (sus datos fiscales).', 'mad-oficina'));
        $kind = ($d['kind'] ?? 'monthly') === 'once' ? 'once' : 'monthly';
        $concept = mb_substr(sanitize_text_field($d['concept'] ?? ''), 0, 255);
        $amount = Money::parse((string) ($d['amount'] ?? ''));
        $start = Months::valid($d['start_month'] ?? '');
        $end = $kind === 'once' ? $start : Months::valid($d['end_month'] ?? '');
        if ( $concept === '' || ! $amount ) return new \WP_Error('madof_item', __('Pon un concepto y un importe.', 'mad-oficina'));
        if ( ! $start ) return new \WP_Error('madof_item_month', __('Elige el mes en que empieza.', 'mad-oficina'));
        if ( $end && $end < $start ) return new \WP_Error('madof_item_month', __('El mes final no puede ser anterior al inicial.', 'mad-oficina'));
        $wpdb->insert(self::table(), [
            'profile_id' => $profile['id'], 'client_id' => $profile['client_id'], 'kind' => $kind,
            'concept' => $concept, 'amount' => $amount, 'start_month' => $start, 'end_month' => $end,
            'quote_id' => ! empty($d['quote_id']) ? (int) $d['quote_id'] : null,
            'replaces_id' => ! empty($d['replaces_id']) ? (int) $d['replaces_id'] : null,
            'status' => 'active', 'notes' => sanitize_textarea_field($d['notes'] ?? '') ?: null,
            'created_at' => Time::now(), 'updated_at' => Time::now(),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Last month of a monthly fee (the client leaves or the project ends); null reopens it. */
    public static function end($id, $month){
        global $wpdb;
        $item = self::get($id);
        if ( ! $item || $item['kind'] !== 'monthly' ) return new \WP_Error('madof_item', __('Ese fee no existe.', 'mad-oficina'));
        $month = $month === null ? null : Months::valid($month);
        if ( $month && $month < $item['start_month'] ) return new \WP_Error('madof_item_month', __('Termina antes de empezar: si fue un error, bórralo.', 'mad-oficina'));
        $wpdb->update(self::table(), ['end_month' => $month, 'updated_at' => Time::now()], ['id' => $id]);
        return true;
    }

    /** New amount from a month on: the old agreement ends the month before, a new one starts. */
    public static function change_amount($id, $from_month, $amount){
        $item = self::get($id);
        if ( ! $item || $item['kind'] !== 'monthly' ) return new \WP_Error('madof_item', __('Ese fee no existe.', 'mad-oficina'));
        $from = Months::valid($from_month);
        if ( ! $from || $from <= $item['start_month'] ) return new \WP_Error('madof_item_month', __('El cambio tiene que ser a partir de un mes posterior al inicio. Si el importe estaba mal desde el principio, bórralo y créalo de nuevo.', 'mad-oficina'));
        if ( $item['end_month'] !== null && $from > $item['end_month'] ) return new \WP_Error('madof_item_month', __('Ese fee ya habrá terminado ese mes.', 'mad-oficina'));
        $new = self::create(['profile_id' => $item['profile_id'], 'kind' => 'monthly', 'concept' => $item['concept'], 'amount' => $amount,
            'start_month' => $from, 'end_month' => $item['end_month'], 'replaces_id' => $item['id'], 'quote_id' => $item['quote_id'], 'notes' => $item['notes']]);
        if ( is_wp_error($new) ) return $new;
        self::end($id, Months::add($from, -1));
        return $new;
    }

    /** Entered by mistake: out of the forecast, kept in the table. */
    public static function cancel($id){
        global $wpdb;
        return (bool) $wpdb->update(self::table(), ['status' => 'cancelled', 'updated_at' => Time::now()], ['id' => $id]);
    }

    /** «desde mar 2024», «nov 2026 → ene 2027», «nov 2026». */
    public static function period(array $item){
        if ( $item['kind'] === 'once' ) return Months::label($item['start_month']);
        return $item['end_month'] === null
            ? sprintf(__('desde %s', 'mad-oficina'), Months::label($item['start_month']))
            : Months::label($item['start_month']) . ' → ' . Months::label($item['end_month']);
    }

    /**
     * Fees hidden in the history: profiles billed (F or AR, issued) in at least 3 of
     * the last 4 full months, with no monthly agreement yet. Proposes the latest
     * base as the fee and the start of that streak as the first month.
     * @return array<int, array{profile:array, amount:float, concept:string, start_month:string, months:int, last:string}>
     */
    public static function suggestions($today = null){
        global $wpdb;
        $now = Months::of($today ?: current_time('Y-m-d'));
        $window = Months::range(Months::add($now, -4), Months::add($now, -1));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT profile_id, LEFT(issue_date, 7) AS m, SUM(base) AS base, MAX(id) AS last_id FROM ' . Invoices::table()
            . " WHERE profile_id IS NOT NULL AND series IN ('F','AR') AND status IN ('issued','sent') AND issue_date >= %s GROUP BY profile_id, m",
            Months::add($now, -24) . '-01'
        ), ARRAY_A);
        $by = [];
        foreach ( $rows as $r ) $by[(int) $r['profile_id']][$r['m']] = $r;
        $have = array_map('intval', $wpdb->get_col('SELECT DISTINCT profile_id FROM ' . self::table() . " WHERE status = 'active' AND kind = 'monthly'"));

        $out = [];
        foreach ( $by as $pid => $months ) {
            if ( in_array($pid, $have, true) ) continue;
            $hits = array_values(array_filter($window, fn($m) => isset($months[$m])));
            if ( count($hits) < 3 ) continue;
            $profile = Profiles::get($pid);
            if ( ! $profile || $profile['status'] !== 'active' ) continue;
            // Walk back while the client kept being billed the same amount (one missing
            // month allowed): the fee starts when its current amount started.
            $latest = $months[end($hits)];
            $start = end($hits); $gap = 0;
            for ( $m = $start; $gap < 2; $m = Months::add($m, -1) ) {
                if ( ! isset($months[$m]) ) { $gap++; continue; }
                if ( abs((float) $months[$m]['base'] - (float) $latest['base']) >= 1 ) break;
                $start = $m; $gap = 0;
            }
            $inv = Invoices::get((int) $latest['last_id']);
            $concept = $inv && $inv['lines'] ? self::strip_month($inv['lines'][0]['description']) : '';
            $out[] = ['profile' => $profile, 'amount' => (float) $latest['base'], 'concept' => $concept ?: __('Fee mensual', 'mad-oficina'),
                'start_month' => $start, 'months' => count($hits), 'last' => end($hits)];
        }
        usort($out, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return $out;
    }

    /** «Gestión de redes · octubre 2026» → «Gestión de redes». */
    public static function strip_month($text){
        $months = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre';
        $t = preg_replace('/[\s·\-–:,(]*\b(mes de\s+)?(' . $months . ')\b(\s+(de\s+)?\d{4})?\)?/iu', '', (string) $text);
        $t = preg_replace('/[\s·\-–:,]*\b\d{4}-\d{2}(-\d{2})?\b/', '', $t);
        return mb_substr(trim($t, " \t\n\r\0\x0B·-–:,"), 0, 255);
    }

    private static function hydrate($r){
        foreach ( ['id', 'profile_id', 'client_id', 'quote_id', 'replaces_id'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        $r['amount'] = (float) $r['amount'];
        return $r;
    }
}
