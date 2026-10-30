<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Quotes. For now they are recorded for the forecast (lines, months, status,
 * probability); the A4 document and sending come with the quotes block.
 * Each line is a monthly fee (from a month, N months or open) or a one-off.
 * Accepting a quote turns its lines into agreements.
 */
final class Quotes {

    const STATUSES = ['draft' => 'Borrador', 'sent' => 'Enviado', 'accepted' => 'Aceptado', 'rejected' => 'Rechazado', 'expired' => 'Caducado'];
    const OPEN = ['draft', 'sent'];

    public static function table(){ return Schema::table('quotes'); }

    public static function get($id){
        global $wpdb;
        $q = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        if ( ! $q ) return null;
        $q = self::hydrate($q);
        $q['lines'] = array_map([self::class, 'hydrate_line'], $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('quote_lines') . ' WHERE quote_id = %d ORDER BY position, id', $id), ARRAY_A));
        return $q;
    }

    /** @param string[] $statuses */
    public static function all(array $statuses = []){
        global $wpdb;
        $sql = 'SELECT id FROM ' . self::table();
        if ( $statuses ) $sql .= " WHERE status IN ('" . implode("','", array_map('esc_sql', $statuses)) . "')";
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 300';
        return array_map([self::class, 'get'], $wpdb->get_col($sql));
    }

    /**
     * @param array $d title, profile_id | lead_id | prospect_name, probability, sent_on, valid_until, notes
     * @param array $lines [{kind, concept, amount, start_month, months}]
     * @return int|\WP_Error
     */
    public static function save(array $d, array $lines, $id = 0){
        global $wpdb;
        if ( $id ) {
            $old = self::get($id);
            if ( ! $old ) return new \WP_Error('madof_quote', __('Ese presupuesto no existe.', 'mad-oficina'));
            if ( ! in_array($old['status'], self::OPEN, true) ) return new \WP_Error('madof_quote_locked', __('Un presupuesto aceptado o rechazado ya no se edita.', 'mad-oficina'));
        }
        $title = mb_substr(sanitize_text_field($d['title'] ?? ''), 0, 255);
        if ( $title === '' ) return new \WP_Error('madof_quote_title', __('Ponle un título al presupuesto.', 'mad-oficina'));

        $profile = ! empty($d['profile_id']) ? Profiles::get((int) $d['profile_id']) : null;
        $lead = null;
        if ( ! $profile && ! empty($d['lead_id']) && class_exists('\\MAD_Oficina\\Visitors\\Leads') ) $lead = \MAD_Oficina\Visitors\Leads::get((int) $d['lead_id']);
        $name = $profile['legal_name'] ?? ( $lead ? ($lead['company'] ?: $lead['name']) : mb_substr(sanitize_text_field($d['prospect_name'] ?? ''), 0, 190) );
        if ( $name === '' ) return new \WP_Error('madof_quote_to', __('¿Para quién es? Elige un cliente, un lead o escribe el nombre.', 'mad-oficina'));

        $clean = [];
        foreach ( $lines as $l ) {
            $concept = mb_substr(sanitize_text_field($l['concept'] ?? ''), 0, 255);
            $amount = Money::parse((string) ($l['amount'] ?? ''));
            if ( $concept === '' && ! $amount ) continue;
            if ( ! $amount ) return new \WP_Error('madof_quote_line', sprintf(__('Falta el importe de «%s».', 'mad-oficina'), $concept));
            $start = Months::valid($l['start_month'] ?? '');
            if ( ! $start ) return new \WP_Error('madof_quote_line', sprintf(__('Falta el mes de «%s».', 'mad-oficina'), $concept ?: Money::format($amount)));
            $kind = ($l['kind'] ?? 'once') === 'monthly' ? 'monthly' : 'once';
            $months = $kind === 'monthly' ? ((int) ($l['months'] ?? 0) ?: null) : 1;
            $clean[] = ['kind' => $kind, 'concept' => $concept ?: __('Sin concepto', 'mad-oficina'), 'amount' => $amount, 'start_month' => $start, 'months' => $months];
        }
        if ( ! $clean ) return new \WP_Error('madof_quote_lines', __('Añade al menos una línea con importe y mes.', 'mad-oficina'));

        $row = [
            'title' => $title, 'profile_id' => $profile['id'] ?? null, 'client_id' => $profile['client_id'] ?? ($lead['client_id'] ?? null),
            'lead_id' => $lead['id'] ?? null, 'prospect_name' => $name,
            'probability' => max(0, min(100, (int) ($d['probability'] ?? 50))),
            'sent_on' => self::date($d['sent_on'] ?? ''), 'valid_until' => self::date($d['valid_until'] ?? ''),
            'notes' => sanitize_textarea_field($d['notes'] ?? '') ?: null, 'updated_at' => Time::now(),
        ];
        if ( $id ) {
            if ( $row['sent_on'] && $old['status'] === 'draft' ) $row['status'] = 'sent';
            $wpdb->update(self::table(), $row, ['id' => $id]);
            $wpdb->delete(Schema::table('quote_lines'), ['quote_id' => $id]);
        } else {
            $year = (int) current_time('Y');
            $seq = 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(seq) FROM ' . self::table() . ' WHERE year = %d', $year));
            $wpdb->insert(self::table(), $row + [
                'year' => $year, 'seq' => $seq, 'number' => sprintf('P-%02d-%03d', $year % 100, $seq),
                'status' => $row['sent_on'] ? 'sent' : 'draft', 'created_at' => Time::now(),
            ]);
            $id = (int) $wpdb->insert_id;
        }
        foreach ( $clean as $i => $l ) $wpdb->insert(Schema::table('quote_lines'), ['quote_id' => $id, 'position' => $i] + $l);
        return (int) $id;
    }

    public static function set_status($id, $status){
        global $wpdb;
        $q = self::get($id);
        if ( ! $q || ! isset(self::STATUSES[$status]) || $status === 'accepted' ) return new \WP_Error('madof_quote', __('No puedo cambiar ese presupuesto así.', 'mad-oficina'));
        if ( $q['status'] === 'accepted' ) return new \WP_Error('madof_quote_locked', __('Ya está aceptado: sus acuerdos están en Acuerdos.', 'mad-oficina'));
        $fields = ['status' => $status, 'updated_at' => Time::now()];
        if ( $status === 'sent' && ! $q['sent_on'] ) $fields['sent_on'] = current_time('Y-m-d');
        if ( in_array($status, ['rejected', 'expired'], true) ) $fields['decided_at'] = Time::now();
        $wpdb->update(self::table(), $fields, ['id' => $id]);
        return true;
    }

    /**
     * Accepted, all of it or the part agreed: each chosen line becomes an agreement for the
     * chosen fiscal profile, with the amount, kind (monthly or once), months and start agreed
     * (they may differ from the quote). $start shifts the whole quote when the work starts
     * later (or earlier) than planned; a line's own start wins over it.
     * @param array|null $picks [line index => {on, concept, amount, kind, months, start_month}]; null = all as quoted
     * @param string     $rest  what is not accepted: 'reject' (noted) or 'new' (a new quote with it, to rethink)
     * @return array{agreements:int[], left:int, new_quote:int}|\WP_Error
     */
    public static function accept($id, $profile_id, $start = null, ?array $picks = null, $rest = 'reject'){
        global $wpdb;
        $q = self::get($id);
        if ( ! $q || ! in_array($q['status'], self::OPEN, true) ) return new \WP_Error('madof_quote', __('Solo se aceptan presupuestos en borrador o enviados.', 'mad-oficina'));
        $profile = Profiles::get((int) ($profile_id ?: $q['profile_id']));
        if ( ! $profile ) return new \WP_Error('madof_quote_profile', __('Para aceptarlo, elige a quién se facturará (sus datos fiscales) o crea el cliente aquí mismo.', 'mad-oficina'));
        $first = min(array_column($q['lines'], 'start_month'));
        $shift = ($start = Months::valid($start)) ? Months::diff($first, $start) : 0;

        $agreed = []; $left = [];
        foreach ( array_values($q['lines']) as $i => $l ) {
            $p = $picks === null ? ['on' => 1] : (array) ($picks[$i] ?? []);
            if ( empty($p['on']) ) { $left[] = $l; continue; }
            $kind = ($p['kind'] ?? $l['kind']) === 'once' ? 'once' : 'monthly';
            $amount = isset($p['amount']) && trim((string) $p['amount']) !== '' ? Money::parse((string) $p['amount']) : $l['amount'];
            if ( ! $amount ) return new \WP_Error('madof_quote_line', sprintf(__('Falta el importe acordado de «%s».', 'mad-oficina'), $l['concept']));
            $from = Months::valid($p['start_month'] ?? '') ?: Months::add($l['start_month'], $shift);
            $months = $kind === 'monthly' ? ( array_key_exists('months', $p) ? ((int) $p['months'] ?: null) : $l['months'] ) : 1;
            $agreed[] = ['kind' => $kind, 'concept' => mb_substr(sanitize_text_field($p['concept'] ?? '') ?: $l['concept'], 0, 255), 'amount' => (float) $amount, 'start_month' => $from, 'months' => $months, 'was' => $l];
        }
        if ( ! $agreed ) return new \WP_Error('madof_quote_none', __('Marca al menos una línea aceptada. Si no aceptan nada, márcalo como rechazado.', 'mad-oficina'));

        $ids = [];
        foreach ( $agreed as $l ) {
            $r = Agreements::create([
                'profile_id' => $profile['id'], 'kind' => $l['kind'], 'concept' => $l['concept'], 'amount' => $l['amount'],
                'start_month' => $l['start_month'], 'end_month' => $l['kind'] === 'monthly' && $l['months'] ? Months::add($l['start_month'], $l['months'] - 1) : null,
                'quote_id' => $q['id'], 'notes' => sprintf(__('Del presupuesto %s', 'mad-oficina'), $q['number']),
            ]);
            if ( is_wp_error($r) ) return $r;
            $ids[] = $r;
        }
        // What was agreed, against what was quoted, stays on the quote.
        $note = $left || array_filter($agreed, fn($l) => (float) $l['amount'] !== (float) $l['was']['amount'] || $l['kind'] !== $l['was']['kind'] || $l['months'] !== $l['was']['months'])
            ? sprintf(__('Aceptado en parte (%1$s): %2$s.', 'mad-oficina'), current_time('d/m/Y'), self::describe(['lines' => $agreed])) . ( $left ? ' ' . sprintf(__('No aceptado: %s.', 'mad-oficina'), implode(', ', array_column($left, 'concept'))) : '' )
            : '';
        $new = 0;
        if ( $left && $rest === 'new' ) {
            $new = self::save(['title' => $q['title'] . ' ' . __('(por replantear)', 'mad-oficina'), 'profile_id' => $profile['id'], 'probability' => $q['probability'], 'notes' => sprintf(__('Lo que no se aceptó del presupuesto %s.', 'mad-oficina'), $q['number'])],
                array_map(fn($l) => ['kind' => $l['kind'], 'concept' => $l['concept'], 'amount' => number_format((float) $l['amount'], 2, ',', ''), 'start_month' => $l['start_month'], 'months' => $l['months']], $left));
            if ( is_wp_error($new) ) $new = 0;
            elseif ( $new ) $note .= ' ' . sprintf(__('Lo demás pasa al presupuesto %s.', 'mad-oficina'), self::get($new)['number']);
        }
        $wpdb->update(self::table(), ['status' => 'accepted', 'decided_at' => Time::now(), 'profile_id' => $profile['id'], 'client_id' => $profile['client_id'],
            'notes' => trim((string) $q['notes'] . "\n" . $note) ?: null, 'updated_at' => Time::now()], ['id' => $id]);
        return ['agreements' => $ids, 'left' => count($left), 'new_quote' => (int) $new];
    }

    /** Base amount of a quote line in a month (0 outside its months). */
    public static function line_in(array $l, $month){
        if ( $month < $l['start_month'] ) return 0.0;
        if ( $l['kind'] === 'once' ) return $month === $l['start_month'] ? $l['amount'] : 0.0;
        if ( $l['months'] && $month > Months::add($l['start_month'], $l['months'] - 1) ) return 0.0;
        return $l['amount'];
    }

    /** «450 €/mes · 6 meses + 1.200 € una vez» */
    public static function describe(array $q){
        $parts = [];
        foreach ( $q['lines'] as $l ) {
            $parts[] = $l['kind'] === 'monthly'
                ? sprintf(__('%1$s/mes %2$s', 'mad-oficina'), Money::format($l['amount']), $l['months'] ? sprintf(_n('· %d mes', '· %d meses', $l['months'], 'mad-oficina'), $l['months']) : __('sin fin', 'mad-oficina'))
                : sprintf(__('%1$s en %2$s', 'mad-oficina'), Money::format($l['amount']), Months::label($l['start_month']));
        }
        return implode(' + ', $parts);
    }

    private static function date($d){
        $d = (string) $d;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    private static function hydrate($r){
        foreach ( ['id', 'year', 'seq', 'profile_id', 'client_id', 'lead_id', 'probability'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        return $r;
    }

    private static function hydrate_line($r){
        $r['amount'] = (float) $r['amount'];
        $r['months'] = $r['months'] === null ? null : (int) $r['months'];
        return $r;
    }
}
