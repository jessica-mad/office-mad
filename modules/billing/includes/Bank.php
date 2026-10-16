<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Finanzas → Banco: the statement's movements, matched with what the office knows.
 *  · Money in  ↔ issued invoices (amount, client's name or invoice number in the concept);
 *    a match marks the invoice as collected on the bank's date.
 *  · Money out ↔ supplier invoices (Gastos) and payments to Hacienda / Seguridad Social (Impuestos).
 *  · Rules Jessica teaches («comisión», «traspaso») settle the next ones by themselves.
 * Matching is deterministic (no AI): what does not match clearly waits for her.
 */
final class Bank {

    const RULES = 'madoficina_bank_rules';
    const KINDS = ['no_invoice' => 'No lleva factura', 'own' => 'Traspaso entre mis cuentas', 'personal' => 'Personal, no es del negocio'];
    const STOP = ['sl', 'sa', 'slu', 'sll', 'scp', 'cb', 'sociedad', 'limitada', 'anonima', 'the', 'and', 'del', 'las', 'los', 'para', 'con', 'por', 'inc', 'ltd', 'llc', 'gmbh', 'bv', 'com', 'www', 'espana', 'spain'];
    const TAX_WORDS = '/\b(aeat|agencia tributaria|hacienda|tgss|seguridad social|tesoreria general|impuesto|modelo ?\d{3}|autonomo|reta)\b/';

    public static function table(){ return Schema::table('bank_movements'); }

    public static function norm($s){
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(remove_accents((string) $s)))));
    }

    /** Significant words of a name (no legal forms, no short words). */
    public static function tokens($name){
        return array_values(array_filter(explode(' ', self::norm($name)), fn($w) => strlen($w) >= 4 && ! in_array($w, self::STOP, true) && ! ctype_digit($w)));
    }

    private static function mentions($concept, $name){
        $c = ' ' . self::norm($concept) . ' ';
        foreach ( self::tokens($name) as $w ) if ( strpos($c, ' ' . $w . ' ') !== false ) return true;
        return false;
    }

    /* ───────── import ───────── */

    /** @return array{new:int, repeated:int, ids:int[]} */
    public static function import(array $statement, $account = ''){
        global $wpdb;
        $account = mb_substr(sanitize_text_field($account ?: ($statement['account'] ?? '')), 0, 60);
        $import = wp_generate_password(12, false);
        $seen = []; $new = 0; $repeated = 0; $ids = [];
        foreach ( $statement['rows'] as $r ) {
            $key = $account . '|' . $r['date'] . '|' . number_format($r['amount'], 2, '.', '') . '|' . self::norm($r['concept']);
            $seen[$key] = ($seen[$key] ?? 0) + 1; // two identical movements the same day stay two
            $hash = sha1($key . '|' . $seen[$key]);
            if ( $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE hash = %s', $hash)) ) { $repeated++; continue; }
            $wpdb->insert(self::table(), [
                'account' => $account, 'date' => $r['date'], 'value_date' => $r['value_date'] ?? null, 'amount' => $r['amount'], 'currency' => 'EUR',
                'concept' => (string) $r['concept'], 'balance' => $r['balance'] ?? null, 'hash' => $hash, 'status' => 'unmatched', 'import_id' => $import,
                'created_at' => Time::now(), 'updated_at' => Time::now(),
            ]);
            $ids[] = (int) $wpdb->insert_id; $new++;
        }
        return ['new' => $new, 'repeated' => $repeated, 'ids' => $ids];
    }

    /* ───────── matching ───────── */

    private static function linked($type){
        global $wpdb;
        $out = [];
        foreach ( $wpdb->get_col($wpdb->prepare('SELECT match_ids FROM ' . self::table() . " WHERE match_type = %s AND status = 'matched'", $type)) as $csv ) {
            foreach ( explode(',', (string) $csv) as $id ) if ( (int) $id ) $out[(int) $id] = true;
        }
        return $out;
    }

    public static function rules(){ $r = get_option(self::RULES, []); return is_array($r) ? $r : []; }

    public static function add_rule($contains, $kind, $label = ''){
        $contains = self::norm($contains);
        if ( strlen($contains) < 3 || ! isset(self::KINDS[$kind]) ) return false;
        $rules = array_values(array_filter(self::rules(), fn($r) => $r['contains'] !== $contains));
        $rules[] = ['contains' => $contains, 'kind' => $kind, 'label' => mb_substr(sanitize_text_field($label), 0, 80)];
        update_option(self::RULES, $rules, false);
        return true;
    }

    public static function delete_rule($contains){
        update_option(self::RULES, array_values(array_filter(self::rules(), fn($r) => $r['contains'] !== self::norm($contains))), false);
    }

    private static function set(array $m, $status, $type = null, array $ids = [], $note = null){
        global $wpdb;
        $wpdb->update(self::table(), ['status' => $status, 'match_type' => $type, 'match_ids' => $ids ? implode(',', array_map('intval', $ids)) : null, 'note' => $note, 'updated_at' => Time::now()], ['id' => $m['id']]);
    }

    /** Tries every unmatched movement (or the given ones). @return array{matched:int, ignored:int, unmatched:int} */
    public static function match(array $ids = []){
        global $wpdb;
        $where = $ids ? 'id IN (' . implode(',', array_map('intval', $ids)) . ')' : "status = 'unmatched'";
        $movements = $wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE {$where} AND status = 'unmatched' ORDER BY date, id", ARRAY_A);
        $out = ['matched' => 0, 'ignored' => 0, 'unmatched' => 0];
        if ( ! $movements ) return $out;

        $invoices = $wpdb->get_results('SELECT i.id, i.number, i.issue_date, i.total, i.paid, i.client_id, i.client_name, i.profile_id, c.name AS office_name FROM ' . Invoices::table() . ' i LEFT JOIN '
            . Schema::table('clients') . " c ON c.id = i.client_id WHERE i.number IS NOT NULL AND i.series <> 'AR' AND i.status <> 'cancelled'", ARRAY_A);
        $expenses = $wpdb->get_results('SELECT id, issue_date, total, paid_eur, currency, supplier_name, number FROM ' . Schema::table('expenses') . " WHERE status = 'registered'", ARRAY_A);
        $taxes = $wpdb->get_results('SELECT id, kind, period, paid_on, amount FROM ' . Schema::table('taxes'), ARRAY_A);
        $suppliers = $wpdb->get_col('SELECT name FROM ' . Schema::table('suppliers') . " WHERE status = 'active'");
        $used = ['invoice' => self::linked('invoice'), 'expense' => self::linked('expense'), 'tax' => self::linked('tax')];
        $rules = self::rules();

        foreach ( $movements as $m ) {
            $concept = self::norm($m['concept']);
            $amount = (float) $m['amount'];
            $day = strtotime($m['date']);

            // 1. What she already told us about this kind of movement.
            foreach ( $rules as $r ) {
                if ( $r['contains'] !== '' && strpos($concept, $r['contains']) !== false ) {
                    self::set($m, 'ignored', 'rule', [], (self::KINDS[$r['kind']] ?? $r['kind']) . ($r['label'] ? ' · ' . $r['label'] : ''));
                    $out['ignored']++;
                    continue 2;
                }
            }

            if ( $amount > 0 ) {
                // 2. Money in: an issued invoice for that amount (or two of the same client that add up).
                $cands = [];
                foreach ( $invoices as $inv ) {
                    if ( isset($used['invoice'][$inv['id']]) || abs((float) $inv['total'] - $amount) > 0.01 ) continue;
                    $gap = ($day - strtotime($inv['issue_date'])) / DAY_IN_SECONDS;
                    if ( $gap < -10 || $gap > 240 ) continue;
                    $score = 0;
                    if ( $inv['number'] && strpos($concept, self::norm($inv['number'])) !== false ) $score += 3;
                    if ( self::mentions($m['concept'], $inv['client_name']) || ($inv['office_name'] && self::mentions($m['concept'], $inv['office_name'])) ) $score += 2;
                    if ( ! $inv['paid'] ) $score += 1;
                    $cands[] = ['inv' => $inv, 'score' => $score, 'gap' => abs($gap)];
                }
                usort($cands, fn($a, $b) => [$b['score'], $a['gap']] <=> [$a['score'], $b['gap']]);
                $best = $cands[0] ?? null;
                $clear = $best && ($best['score'] >= 2 && (! isset($cands[1]) || $cands[1]['score'] < $best['score']));
                $clear = $clear || ($best && count($cands) === 1 && ! $best['inv']['paid']);
                if ( $clear ) {
                    self::match_invoices($m, [$best['inv']]);
                    $used['invoice'][$best['inv']['id']] = true;
                    $out['matched']++;
                    continue;
                }
                $pair = self::pair($m, $invoices, $used['invoice']);
                if ( $pair ) {
                    self::match_invoices($m, $pair);
                    foreach ( $pair as $p ) $used['invoice'][$p['id']] = true;
                    $out['matched']++;
                    continue;
                }
                $who = null;
                foreach ( $invoices as $inv ) if ( self::mentions($m['concept'], $inv['client_name']) ) { $who = $inv['office_name'] ?: $inv['client_name']; break; }
                self::set($m, 'unmatched', null, [], $who ? sprintf(__('Parece de %s, pero ninguna factura suya es de este importe. ¿Falta emitirla o paga varias?', 'mad-oficina'), $who) : __('Entrada sin factura que coincida.', 'mad-oficina'));
                $out['unmatched']++;
                continue;
            }

            // 3. Money out: a supplier invoice, or a tax payment.
            $abs = -$amount;
            $cands = [];
            foreach ( $expenses as $e ) {
                if ( isset($used['expense'][$e['id']]) ) continue;
                $eur = $e['currency'] === 'EUR' ? (float) $e['total'] : (float) $e['paid_eur'];
                if ( ! $eur || abs($eur - $abs) > 0.01 ) continue;
                $gap = ($day - strtotime((string) $e['issue_date'])) / DAY_IN_SECONDS;
                if ( $gap < -20 || $gap > 90 ) continue;
                $cands[] = ['e' => $e, 'score' => self::mentions($m['concept'], $e['supplier_name']) ? 2 : 0, 'gap' => abs($gap)];
            }
            usort($cands, fn($a, $b) => [$b['score'], $a['gap']] <=> [$a['score'], $b['gap']]);
            $best = $cands[0] ?? null;
            if ( $best && ($best['score'] >= 2 || count($cands) === 1) && (! isset($cands[1]) || $cands[1]['score'] < $best['score'] || count($cands) === 1) ) {
                self::set($m, 'matched', 'expense', [$best['e']['id']], $best['e']['supplier_name'] . ($best['e']['number'] ? ' · ' . $best['e']['number'] : ''));
                $used['expense'][$best['e']['id']] = true;
                $out['matched']++;
                continue;
            }
            if ( preg_match(self::TAX_WORDS, $concept) ) {
                foreach ( $taxes as $t ) {
                    if ( isset($used['tax'][$t['id']]) || abs((float) $t['amount'] - $abs) > 0.01 ) continue;
                    if ( $t['paid_on'] && abs($day - strtotime($t['paid_on'])) > 25 * DAY_IN_SECONDS ) continue;
                    self::set($m, 'matched', 'tax', [$t['id']], (Taxes::KINDS[$t['kind']] ?? $t['kind']) . ' · ' . $t['period']);
                    $used['tax'][$t['id']] = true;
                    $out['matched']++;
                    continue 2;
                }
                self::set($m, 'unmatched', null, [], __('Parece un pago a Hacienda o a la Seguridad Social que no está apuntado en Impuestos.', 'mad-oficina'));
                $out['unmatched']++;
                continue;
            }
            $who = null;
            foreach ( array_merge($suppliers, array_column($expenses, 'supplier_name')) as $s ) if ( $s && self::mentions($m['concept'], $s) ) { $who = $s; break; }
            self::set($m, 'unmatched', null, [], $who ? sprintf(__('Parece de %s: falta su factura (o es de otro importe).', 'mad-oficina'), $who) : __('Salida sin factura que coincida.', 'mad-oficina'));
            $out['unmatched']++;
        }
        return $out;
    }

    /** One transfer paying two invoices of the same client (named in the concept). */
    private static function pair(array $m, array $invoices, array $used){
        $amount = (float) $m['amount'];
        $pool = array_values(array_filter($invoices, fn($i) => ! $i['paid'] && ! isset($used[$i['id']]) && strtotime($i['issue_date']) <= strtotime($m['date']) + 10 * DAY_IN_SECONDS
            && (self::mentions($m['concept'], $i['client_name']) || ($i['office_name'] && self::mentions($m['concept'], $i['office_name'])))));
        $n = count($pool);
        for ( $a = 0; $a < $n; $a++ ) for ( $b = $a + 1; $b < $n; $b++ ) {
            if ( $pool[$a]['profile_id'] === $pool[$b]['profile_id'] && abs((float) $pool[$a]['total'] + (float) $pool[$b]['total'] - $amount) <= 0.01 ) return [$pool[$a], $pool[$b]];
        }
        return null;
    }

    /** Linked to invoices: those not yet collected are marked collected on the bank's date. */
    public static function match_invoices(array $m, array $invoices){
        global $wpdb;
        $marked = [];
        foreach ( $invoices as $inv ) {
            if ( empty($inv['paid']) ) {
                $wpdb->update(Invoices::table(), ['paid' => 1, 'paid_at' => $m['date'] . ' 12:00:00', 'updated_at' => Time::now()], ['id' => $inv['id']]);
                $marked[] = (int) $inv['id'];
            }
        }
        self::set($m, 'matched', 'invoice', array_column($invoices, 'id'), implode(' + ', array_column($invoices, 'number')) . ($marked ? ' · ' . __('marcada cobrada por el banco', 'mad-oficina') . ' [' . implode(',', $marked) . ']' : ''));
        if ( $marked ) Issuing::queue_libro($marked);
    }

    /* ───────── Jessica's decisions ───────── */

    public static function get($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
    }

    /** @return true|\WP_Error */
    public static function link($id, $type, array $ids){
        global $wpdb;
        $m = self::get($id);
        if ( ! $m ) return new \WP_Error('madof_bank', __('Ese movimiento no existe.', 'mad-oficina'));
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ( ! $ids || ! in_array($type, ['invoice', 'expense', 'tax'], true) ) return new \WP_Error('madof_bank', __('Elige con qué va.', 'mad-oficina'));
        if ( $type === 'invoice' ) {
            $rows = $wpdb->get_results('SELECT id, number, paid FROM ' . Invoices::table() . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A);
            if ( ! $rows ) return new \WP_Error('madof_bank', __('Esa factura no existe.', 'mad-oficina'));
            self::match_invoices($m, $rows);
            return true;
        }
        $table = $type === 'expense' ? Schema::table('expenses') : Schema::table('taxes');
        $label = $type === 'expense' ? 'supplier_name' : 'kind';
        $names = $wpdb->get_col('SELECT ' . $label . ' FROM ' . $table . ' WHERE id IN (' . implode(',', $ids) . ')');
        if ( ! $names ) return new \WP_Error('madof_bank', __('Eso ya no existe.', 'mad-oficina'));
        self::set($m, 'matched', $type, $ids, implode(' + ', $names));
        return true;
    }

    public static function ignore($id, $kind, $remember = '', $label = ''){
        $m = self::get($id);
        if ( ! $m || ! isset(self::KINDS[$kind]) ) return new \WP_Error('madof_bank', __('No sé qué hacer con eso.', 'mad-oficina'));
        self::set($m, 'ignored', 'manual', [], self::KINDS[$kind] . ($label ? ' · ' . sanitize_text_field($label) : ''));
        if ( trim((string) $remember) !== '' && self::add_rule($remember, $kind, $label) ) self::match(); // settles the rest like it
        return true;
    }

    /** Back to «sin cuadrar»; an invoice the bank marked as collected goes back to unpaid. */
    public static function unlink($id){
        global $wpdb;
        $m = self::get($id);
        if ( ! $m ) return;
        if ( $m['match_type'] === 'invoice' && preg_match('/\[([\d,]+)\]/', (string) $m['note'], $x) ) {
            $marked = array_filter(array_map('intval', explode(',', $x[1])));
            if ( $marked ) {
                $wpdb->query('UPDATE ' . Invoices::table() . ' SET paid = 0, paid_at = NULL WHERE id IN (' . implode(',', $marked) . ')');
                Issuing::queue_libro($marked);
            }
        }
        self::set($m, 'unmatched', null, [], null);
    }

    /* ───────── what she sees ───────── */

    public static function movements($status, $limit = 300){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY date DESC, id DESC LIMIT %d', $status, $limit), ARRAY_A);
    }

    public static function counts(){
        global $wpdb;
        return array_map('intval', array_column($wpdb->get_results('SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status', ARRAY_A), 'n', 'status')) + ['matched' => 0, 'unmatched' => 0, 'ignored' => 0];
    }

    /** First and last day the statements cover. @return array{0:?string,1:?string} */
    public static function covered(){
        global $wpdb;
        $r = $wpdb->get_row('SELECT MIN(date) a, MAX(date) b FROM ' . self::table(), ARRAY_A);
        return [$r['a'] ?? null, $r['b'] ?? null];
    }

    /** Issued (Spanish) invoices of the period the statements cover, not collected: no money arrived for them. */
    public static function uncollected(){
        global $wpdb;
        [$from, $to] = self::covered();
        if ( ! $to ) return [];
        return $wpdb->get_results($wpdb->prepare('SELECT id, number, issue_date, due_date, client_name, total FROM ' . Invoices::table()
            . " WHERE number IS NOT NULL AND series <> 'AR' AND status <> 'cancelled' AND paid = 0 AND issue_date BETWEEN %s AND %s ORDER BY issue_date", gmdate('Y-m-d', strtotime($from . ' -60 days')), $to), ARRAY_A);
    }

    /** Supplier invoices of the covered period with no payment found (maybe paid from another account or by card). */
    public static function unpaid_expenses(){
        global $wpdb;
        [$from, $to] = self::covered();
        if ( ! $from ) return [];
        $used = self::linked('expense');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, issue_date, supplier_name, number, total, currency, drive_url FROM ' . Schema::table('expenses')
            . " WHERE status = 'registered' AND issue_date BETWEEN %s AND %s ORDER BY issue_date", $from, gmdate('Y-m-d', strtotime($to . ' -10 days'))), ARRAY_A);
        return array_values(array_filter($rows, fn($r) => ! isset($used[$r['id']])));
    }

    /** What a movement could go with, for the «Vincular» menu. @return array[] */
    public static function candidates(array $m){
        global $wpdb;
        $abs = abs((float) $m['amount']);
        if ( (float) $m['amount'] > 0 ) {
            $used = self::linked('invoice');
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, number, issue_date, client_name, total, paid FROM ' . Invoices::table()
                . " WHERE number IS NOT NULL AND series <> 'AR' AND status <> 'cancelled' AND issue_date <= %s ORDER BY ABS(total - %f), paid, issue_date DESC LIMIT 15", gmdate('Y-m-d', strtotime($m['date'] . ' +10 days')), $abs), ARRAY_A);
            return array_map(fn($r) => ['type' => 'invoice', 'id' => (int) $r['id'], 'label' => $r['number'] . ' · ' . $r['client_name'] . ' · ' . Money::format($r['total']) . ($r['paid'] ? ' · ' . __('ya cobrada', 'mad-oficina') : '')],
                array_filter($rows, fn($r) => ! isset($used[$r['id']])));
        }
        $used = self::linked('expense');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, issue_date, supplier_name, number, total FROM ' . Schema::table('expenses')
            . " WHERE status = 'registered' AND issue_date BETWEEN %s AND %s ORDER BY ABS(total - %f) LIMIT 15", gmdate('Y-m-d', strtotime($m['date'] . ' -120 days')), gmdate('Y-m-d', strtotime($m['date'] . ' +20 days')), $abs), ARRAY_A);
        $out = array_map(fn($r) => ['type' => 'expense', 'id' => (int) $r['id'], 'label' => $r['supplier_name'] . ' · ' . mysql2date('j/m', $r['issue_date']) . ' · ' . Money::format($r['total'])], array_filter($rows, fn($r) => ! isset($used[$r['id']])));
        foreach ( $wpdb->get_results($wpdb->prepare('SELECT id, kind, period, amount FROM ' . Schema::table('taxes') . ' ORDER BY ABS(amount - %f) LIMIT 5', $abs), ARRAY_A) as $t ) {
            $out[] = ['type' => 'tax', 'id' => (int) $t['id'], 'label' => (Taxes::KINDS[$t['kind']] ?? $t['kind']) . ' · ' . $t['period'] . ' · ' . Money::format($t['amount'])];
        }
        return $out;
    }
}
