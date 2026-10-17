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

    /**
     * How much of each invoice / expense / tax the bank already covers. One movement can
     * cover several (a card charge for three receipts: each counts as paid) and several
     * movements can cover one (paid in instalments: their amounts add up).
     * @return array<int,float> id => amount covered
     */
    public static function coverage($type){
        global $wpdb;
        $out = [];
        foreach ( $wpdb->get_results($wpdb->prepare('SELECT amount, match_ids FROM ' . self::table() . " WHERE match_type = %s AND status = 'matched'", $type), ARRAY_A) as $r ) {
            $ids = array_filter(array_map('intval', explode(',', (string) $r['match_ids'])));
            if ( count($ids) === 1 ) { $id = reset($ids); $out[$id] = ($out[$id] ?? 0) + abs((float) $r['amount']); }
            else foreach ( $ids as $id ) $out[$id] = INF;
        }
        return $out;
    }

    private static function remaining($total, array $cov, $id){
        return round((float) $total - (float) ($cov[(int) $id] ?? 0), 2);
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
        $cov = ['invoice' => self::coverage('invoice'), 'expense' => self::coverage('expense'), 'tax' => self::coverage('tax')];
        $eur = fn($e) => $e['currency'] === 'EUR' ? (float) $e['total'] : (float) $e['paid_eur'];
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
                // 2. Money in: an issued invoice for what is left of it (or two of the same client that add up).
                $cands = [];
                foreach ( $invoices as $inv ) {
                    $left = self::remaining($inv['total'], $cov['invoice'], $inv['id']);
                    if ( $left <= 0.01 || abs($left - $amount) > 0.01 ) continue;
                    $gap = ($day - strtotime($inv['issue_date'])) / DAY_IN_SECONDS;
                    if ( $gap < -10 || $gap > 240 ) continue;
                    $score = 0;
                    if ( $inv['number'] && strpos($concept, self::norm($inv['number'])) !== false ) $score += 3;
                    if ( self::names($m['concept'], $inv) ) $score += 2;
                    if ( ! $inv['paid'] ) $score += 1;
                    $cands[] = ['inv' => $inv, 'score' => $score, 'gap' => abs($gap)];
                }
                usort($cands, fn($a, $b) => [$b['score'], $a['gap']] <=> [$a['score'], $b['gap']]);
                $best = $cands[0] ?? null;
                $clear = $best && ($best['score'] >= 2 && (! isset($cands[1]) || $cands[1]['score'] < $best['score']));
                $clear = $clear || ($best && count($cands) === 1 && ! $best['inv']['paid']);
                if ( $clear ) {
                    self::match_invoices($m, [$best['inv']]);
                    $cov['invoice'] = self::coverage('invoice');
                    $out['matched']++;
                    continue;
                }
                $pair = self::pair($m, $invoices, $cov['invoice']);
                if ( $pair ) {
                    self::match_invoices($m, $pair);
                    $cov['invoice'] = self::coverage('invoice');
                    $out['matched']++;
                    continue;
                }
                // Paid in instalments: the client is named and has exactly one invoice with more left than this.
                $open = array_values(array_filter($invoices, fn($i) => ! $i['paid'] && self::names($m['concept'], $i) && self::remaining($i['total'], $cov['invoice'], $i['id']) > $amount + 0.01
                    && strtotime($i['issue_date']) <= $day + 10 * DAY_IN_SECONDS));
                if ( count($open) === 1 ) {
                    self::match_invoices($m, $open);
                    $cov['invoice'] = self::coverage('invoice');
                    $out['matched']++;
                    continue;
                }
                $who = null;
                foreach ( $invoices as $inv ) if ( self::mentions($m['concept'], $inv['client_name']) ) { $who = $inv['office_name'] ?: $inv['client_name']; break; }
                self::set($m, 'unmatched', null, [], $who ? sprintf(__('Parece de %s, pero ninguna factura suya es de este importe. ¿Falta emitirla, paga varias o es un pago parcial?', 'mad-oficina'), $who) : __('Entrada sin factura que coincida.', 'mad-oficina'));
                $out['unmatched']++;
                continue;
            }

            // 3. Money out: a supplier invoice (what is left of it), several receipts of one supplier, or a tax.
            $abs = -$amount;
            $cands = [];
            foreach ( $expenses as $e ) {
                $left = self::remaining($eur($e), $cov['expense'], $e['id']);
                if ( $left <= 0.01 || abs($left - $abs) > 0.01 ) continue;
                $gap = ($day - strtotime((string) $e['issue_date'])) / DAY_IN_SECONDS;
                if ( $gap < -20 || $gap > 90 ) continue;
                $cands[] = ['e' => $e, 'score' => self::mentions($m['concept'], $e['supplier_name']) ? 2 : 0, 'gap' => abs($gap)];
            }
            usort($cands, fn($a, $b) => [$b['score'], $a['gap']] <=> [$a['score'], $b['gap']]);
            $best = $cands[0] ?? null;
            if ( $best && ($best['score'] >= 2 || count($cands) === 1) && (! isset($cands[1]) || $cands[1]['score'] < $best['score'] || count($cands) === 1) ) {
                self::link_expenses($m, [$best['e']]);
                $cov['expense'] = self::coverage('expense');
                $out['matched']++;
                continue;
            }
            // One charge, several receipts of the same supplier (named in the concept) that add up.
            $pool = array_values(array_filter($expenses, function($e) use ($m, $day, $cov, $eur){
                $gap = ($day - strtotime((string) $e['issue_date'])) / DAY_IN_SECONDS;
                return $gap >= -20 && $gap <= 60 && self::remaining($eur($e), $cov['expense'], $e['id']) > 0.01 && ! isset($cov['expense'][$e['id']]) && self::mentions($m['concept'], $e['supplier_name']);
            }));
            if ( $set = self::subset($pool, $abs, $eur) ) {
                self::link_expenses($m, $set);
                $cov['expense'] = self::coverage('expense');
                $out['matched']++;
                continue;
            }
            // An instalment of a bigger supplier invoice (the supplier named, only one with more left).
            $open = array_values(array_filter($expenses, fn($e) => self::mentions($m['concept'], $e['supplier_name']) && self::remaining($eur($e), $cov['expense'], $e['id']) > $abs + 0.01
                && ($day - strtotime((string) $e['issue_date'])) / DAY_IN_SECONDS >= -20));
            if ( count($open) === 1 ) {
                self::link_expenses($m, $open);
                $cov['expense'] = self::coverage('expense');
                $out['matched']++;
                continue;
            }
            if ( preg_match(self::TAX_WORDS, $concept) ) {
                foreach ( $taxes as $t ) {
                    if ( abs(self::remaining($t['amount'], $cov['tax'], $t['id']) - $abs) > 0.01 ) continue;
                    if ( $t['paid_on'] && abs($day - strtotime($t['paid_on'])) > 25 * DAY_IN_SECONDS ) continue;
                    self::set($m, 'matched', 'tax', [$t['id']], (Taxes::KINDS[$t['kind']] ?? $t['kind']) . ' · ' . $t['period']);
                    $cov['tax'] = self::coverage('tax');
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

    /** The client of an invoice is named in the concept (legal name or name in the office). */
    private static function names($concept, array $inv){
        return self::mentions($concept, $inv['client_name']) || (! empty($inv['office_name']) && self::mentions($concept, $inv['office_name']));
    }

    /** One transfer paying two or three invoices of the same client (named in the concept). */
    private static function pair(array $m, array $invoices, array $cov){
        $amount = (float) $m['amount'];
        $pool = array_values(array_filter($invoices, fn($i) => ! $i['paid'] && ! isset($cov[$i['id']]) && strtotime($i['issue_date']) <= strtotime($m['date']) + 10 * DAY_IN_SECONDS && self::names($m['concept'], $i)));
        $set = self::subset($pool, $amount, fn($i) => (float) $i['total'], 3);
        if ( ! $set ) return null;
        return count(array_unique(array_column($set, 'profile_id'))) === 1 ? $set : null;
    }

    /** 2–4 items whose amounts add up exactly (first found, by date). @return array[]|null */
    private static function subset(array $pool, $target, callable $value, $max = 4){
        $pool = array_slice($pool, 0, 14);
        $n = count($pool);
        $best = null;
        $walk = function($start, array $chosen, $sum) use (&$walk, &$best, $pool, $n, $target, $value, $max){
            if ( $best ) return;
            if ( count($chosen) >= 2 && abs($sum - $target) <= 0.01 ) { $best = $chosen; return; }
            if ( count($chosen) >= $max || $sum > $target + 0.01 ) return;
            for ( $i = $start; $i < $n; $i++ ) $walk($i + 1, array_merge($chosen, [$pool[$i]]), $sum + $value($pool[$i]));
        };
        $walk(0, [], 0.0);
        return $best;
    }

    /**
     * Linked to invoices: an invoice is marked collected (on the bank's date) when the bank
     * covers all of it; until then the movement says what is left.
     */
    public static function match_invoices(array $m, array $invoices){
        global $wpdb;
        $ids = array_map(fn($i) => (int) $i['id'], $invoices);
        self::set($m, 'matched', 'invoice', $ids, null);
        $cov = self::coverage('invoice');
        $marked = []; $notes = [];
        foreach ( $ids as $id ) {
            $inv = $wpdb->get_row($wpdb->prepare('SELECT id, number, total, paid FROM ' . Invoices::table() . ' WHERE id = %d', $id), ARRAY_A);
            if ( ! $inv ) continue;
            $left = self::remaining($inv['total'], $cov, $id);
            if ( $left <= 0.01 ) {
                if ( ! $inv['paid'] ) {
                    $wpdb->update(Invoices::table(), ['paid' => 1, 'paid_at' => $m['date'] . ' 12:00:00', 'updated_at' => Time::now()], ['id' => $id]);
                    $marked[] = $id;
                }
                $notes[] = $inv['number'];
            } else {
                $notes[] = sprintf(__('%1$s (pago parcial: faltan %2$s)', 'mad-oficina'), $inv['number'], Money::format($left));
            }
        }
        self::set($m, 'matched', 'invoice', $ids, implode(' + ', $notes) . ($marked ? ' · ' . __('marcada cobrada por el banco', 'mad-oficina') . ' [' . implode(',', $marked) . ']' : ''));
        if ( $marked ) Issuing::queue_libro($marked);
    }

    /** Linked to supplier invoices (one or several receipts); says what is left when it is an instalment. */
    private static function link_expenses(array $m, array $expenses){
        $ids = array_map(fn($e) => (int) $e['id'], $expenses);
        self::set($m, 'matched', 'expense', $ids, null);
        $cov = self::coverage('expense');
        $notes = [];
        foreach ( $expenses as $e ) {
            $total = ($e['currency'] ?? 'EUR') === 'EUR' ? (float) $e['total'] : (float) $e['paid_eur'];
            $left = self::remaining($total, $cov, $e['id']);
            $notes[] = $e['supplier_name'] . (! empty($e['number']) ? ' · ' . $e['number'] : '') . ($left > 0.01 ? ' ' . sprintf(__('(pago parcial: faltan %s)', 'mad-oficina'), Money::format($left)) : '');
        }
        self::set($m, 'matched', 'expense', $ids, implode(' + ', $notes));
    }

    /* ───────── Jessica's decisions ───────── */

    public static function get($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
    }

    /** One movement with one or several invoices / receipts / taxes (same kind). @return true|\WP_Error */
    public static function link($id, $type, array $ids){
        global $wpdb;
        $m = self::get($id);
        if ( ! $m ) return new \WP_Error('madof_bank', __('Ese movimiento no existe.', 'mad-oficina'));
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids || ! in_array($type, ['invoice', 'expense', 'tax'], true) ) return new \WP_Error('madof_bank', __('Elige con qué va.', 'mad-oficina'));
        $in = implode(',', $ids);
        if ( $type === 'invoice' ) {
            $rows = $wpdb->get_results('SELECT id, number, paid, total FROM ' . Invoices::table() . " WHERE id IN ({$in})", ARRAY_A);
            if ( ! $rows ) return new \WP_Error('madof_bank', __('Esa factura no existe.', 'mad-oficina'));
            self::match_invoices($m, $rows);
            return true;
        }
        if ( $type === 'expense' ) {
            $rows = $wpdb->get_results('SELECT id, supplier_name, number, total, paid_eur, currency FROM ' . Schema::table('expenses') . " WHERE id IN ({$in})", ARRAY_A);
            if ( ! $rows ) return new \WP_Error('madof_bank', __('Eso ya no existe.', 'mad-oficina'));
            self::link_expenses($m, $rows);
            return true;
        }
        $names = $wpdb->get_col('SELECT CONCAT(kind, " · ", period) FROM ' . Schema::table('taxes') . " WHERE id IN ({$in})");
        if ( ! $names ) return new \WP_Error('madof_bank', __('Eso ya no existe.', 'mad-oficina'));
        self::set($m, 'matched', 'tax', $ids, implode(' + ', array_map(fn($n) => (Taxes::KINDS[strtok($n, ' ')] ?? strtok($n, ' ')) . substr($n, strpos($n, ' ')), $names)));
        return true;
    }

    /** Several movements with one invoice / receipt (paid in instalments). @return int|\WP_Error how many linked */
    public static function link_many(array $movement_ids, $type, $item_id){
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $movement_ids))));
        if ( ! $ids ) return new \WP_Error('madof_bank', __('Marca primero los movimientos.', 'mad-oficina'));
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE id IN (' . implode(',', $ids) . ") AND status = 'unmatched' ORDER BY date, id", ARRAY_A);
        $n = 0;
        foreach ( $rows as $m ) {
            $r = self::link((int) $m['id'], $type, [(int) $item_id]);
            if ( is_wp_error($r) ) return $r;
            $n++;
        }
        return $n;
    }

    public static function ignore($id, $kind, $remember = '', $label = ''){
        $m = self::get($id);
        if ( ! $m || ! isset(self::KINDS[$kind]) ) return new \WP_Error('madof_bank', __('No sé qué hacer con eso.', 'mad-oficina'));
        self::set($m, 'ignored', 'manual', [], self::KINDS[$kind] . ($label ? ' · ' . sanitize_text_field($label) : ''));
        if ( trim((string) $remember) !== '' && self::add_rule($remember, $kind, $label) ) self::match(); // settles the rest like it
        return true;
    }

    /** Back to «sin cuadrar»; an invoice the bank had marked as collected goes back to unpaid if it is no longer fully covered. */
    public static function unlink($id){
        global $wpdb;
        $m = self::get($id);
        if ( ! $m ) return;
        $was = $m['match_type'] === 'invoice' ? array_filter(array_map('intval', explode(',', (string) $m['match_ids']))) : [];
        $by_bank = [];
        if ( $was ) foreach ( $wpdb->get_col('SELECT note FROM ' . self::table() . " WHERE match_type = 'invoice' AND note LIKE '%[%'") as $note ) {
            if ( preg_match('/\[([\d,]+)\]/', (string) $note, $x) ) foreach ( explode(',', $x[1]) as $i ) $by_bank[(int) $i] = true;
        }
        self::set($m, 'unmatched', null, [], null);
        if ( ! $was ) return;
        $cov = self::coverage('invoice');
        $undo = [];
        foreach ( $was as $iid ) {
            $inv = $wpdb->get_row($wpdb->prepare('SELECT total, paid FROM ' . Invoices::table() . ' WHERE id = %d', $iid), ARRAY_A);
            if ( $inv && $inv['paid'] && isset($by_bank[$iid]) && self::remaining($inv['total'], $cov, $iid) > 0.01 ) $undo[] = $iid;
        }
        if ( $undo ) {
            $wpdb->query('UPDATE ' . Invoices::table() . ' SET paid = 0, paid_at = NULL WHERE id IN (' . implode(',', $undo) . ')');
            Issuing::queue_libro($undo);
        }
    }

    /* ───────── what she sees ───────── */

    /** @param string $q words of the concept (all of them), $sign 'in'|'out'|'' */
    public static function movements($status, $limit = 300, $q = '', $sign = ''){
        global $wpdb;
        $where = $wpdb->prepare('status = %s', $status);
        if ( $sign === 'in' ) $where .= ' AND amount > 0';
        if ( $sign === 'out' ) $where .= ' AND amount < 0';
        $words = array_filter(explode(' ', self::norm($q)));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY date DESC, id DESC LIMIT %d", $words ? 5000 : $limit), ARRAY_A);
        if ( ! $words ) return $rows;
        // Compared without accents or case («comisión» = «COMISION»).
        $rows = array_filter($rows, function($r) use ($words){ $c = self::norm($r['concept']); foreach ( $words as $w ) if ( strpos($c, $w) === false ) return false; return true; });
        return array_slice(array_values($rows), 0, $limit);
    }

    /** Several at once (e.g. all the transfers between her own accounts). @return int how many */
    public static function ignore_many(array $ids, $kind, $label = '', $remember = ''){
        $n = 0;
        foreach ( array_unique(array_map('intval', $ids)) as $id ) {
            $m = self::get($id);
            if ( ! $m || $m['status'] !== 'unmatched' || ! isset(self::KINDS[$kind]) ) continue;
            self::set($m, 'ignored', 'manual', [], self::KINDS[$kind] . ($label ? ' · ' . sanitize_text_field($label) : ''));
            $n++;
        }
        if ( trim((string) $remember) !== '' && self::add_rule($remember, $kind, $label) ) $n += self::match()['ignored'];
        return $n;
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

    /** Issued (Spanish) invoices of the period the statements cover, not collected: what is left of each. */
    public static function uncollected(){
        global $wpdb;
        [$from, $to] = self::covered();
        if ( ! $to ) return [];
        $cov = self::coverage('invoice');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, number, issue_date, due_date, client_name, total FROM ' . Invoices::table()
            . " WHERE number IS NOT NULL AND series <> 'AR' AND status <> 'cancelled' AND paid = 0 AND issue_date BETWEEN %s AND %s ORDER BY issue_date", gmdate('Y-m-d', strtotime($from . ' -60 days')), $to), ARRAY_A);
        foreach ( $rows as &$r ) $r['left'] = self::remaining($r['total'], $cov, $r['id']);
        return $rows;
    }

    /** Supplier invoices of the covered period not (fully) paid in the bank (maybe paid from another account or by card). */
    public static function unpaid_expenses(){
        global $wpdb;
        [$from, $to] = self::covered();
        if ( ! $from ) return [];
        $cov = self::coverage('expense');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, issue_date, supplier_name, number, total, paid_eur, currency, drive_url FROM ' . Schema::table('expenses')
            . " WHERE status = 'registered' AND issue_date BETWEEN %s AND %s ORDER BY issue_date", $from, gmdate('Y-m-d', strtotime($to . ' -10 days'))), ARRAY_A);
        $out = [];
        foreach ( $rows as $r ) {
            $total = $r['currency'] === 'EUR' ? (float) $r['total'] : (float) $r['paid_eur'];
            $r['left'] = $total ? self::remaining($total, $cov, $r['id']) : (float) $r['total'];
            if ( $r['left'] > 0.01 ) $out[] = $r;
        }
        return $out;
    }

    /** What a movement could go with (what is still open), for «Vincular». @return array[] */
    public static function candidates(array $m, $limit = 15){
        $items = self::open_items((float) $m['amount'] > 0 ? 'in' : 'out', abs((float) $m['amount']), $m['date'], 400);
        // Whoever the concept names goes first: its brand (the first word of the name) before a
        // generic word («Nube Hosting» for «HOSTINGER* HOSTING»); within each, closest amount.
        $c = ' ' . self::norm($m['concept']) . ' ';
        $tier = function($i) use ($c){
            $words = self::tokens($i['name']);
            if ( ! $words ) return 0;
            if ( strpos($c, ' ' . $words[0] . ' ') !== false ) return 2;
            foreach ( $words as $w ) if ( strpos($c, ' ' . $w . ' ') !== false ) return 1;
            return 0;
        };
        $ranked = [];
        foreach ( $items as $n => $i ) $ranked[] = [$tier($i), $n, $i];
        usort($ranked, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        return array_slice(array_column($ranked, 2), 0, $limit);
    }

    /** Every open item of both sides, for the search box in «Vincular». @return array{in:array[], out:array[]} */
    public static function searchable(){
        $pack = fn($i) => ['v' => $i['type'] . ':' . $i['id'], 'l' => $i['label'], 's' => self::norm($i['label'] . ' ' . $i['name']) . ' ' . strtolower(remove_accents($i['label'])) . ' ' . number_format($i['left'], 2, '.', '') . ' ' . number_format($i['left'], 2, ',', '')];
        return ['in' => array_map($pack, self::open_items('in', 0, null, 1000)), 'out' => array_map($pack, self::open_items('out', 0, null, 1000))];
    }

    /**
     * Invoices (money in) or receipts and taxes (money out) still open, closest amount first.
     * @return array[] [type, id, label, left]
     */
    public static function open_items($side, $amount = 0.0, $date = null, $limit = 40){
        global $wpdb;
        $date = $date ?: current_time('Y-m-d');
        if ( $side === 'in' ) {
            $cov = self::coverage('invoice');
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, number, issue_date, client_name, total, paid FROM ' . Invoices::table()
                . " WHERE number IS NOT NULL AND series <> 'AR' AND status <> 'cancelled' AND issue_date BETWEEN %s AND %s ORDER BY paid, issue_date DESC LIMIT 1000",
                gmdate('Y-m-d', strtotime($date . ' -18 months')), gmdate('Y-m-d', strtotime($date . ' +10 days'))), ARRAY_A);
            $out = [];
            foreach ( $rows as $r ) {
                $left = self::remaining($r['total'], $cov, $r['id']);
                if ( $left <= 0.01 ) continue;
                $out[] = ['type' => 'invoice', 'id' => (int) $r['id'], 'left' => $left, 'name' => (string) $r['client_name'], 'label' => $r['number'] . ' · ' . $r['client_name'] . ' · ' . Money::format($r['total'])
                    . ($left < (float) $r['total'] - 0.01 ? ' · ' . sprintf(__('faltan %s', 'mad-oficina'), Money::format($left)) : '') . ($r['paid'] ? ' · ' . __('ya cobrada', 'mad-oficina') : '')];
            }
        } else {
            $cov = self::coverage('expense');
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, issue_date, supplier_name, number, concept, total, paid_eur, currency FROM ' . Schema::table('expenses')
                . " WHERE status = 'registered' AND issue_date BETWEEN %s AND %s ORDER BY issue_date DESC LIMIT 1000", gmdate('Y-m-d', strtotime($date . ' -12 months')), gmdate('Y-m-d', strtotime($date . ' +20 days'))), ARRAY_A);
            $out = [];
            foreach ( $rows as $r ) {
                $total = $r['currency'] === 'EUR' ? (float) $r['total'] : (float) $r['paid_eur'];
                $left = $total ? self::remaining($total, $cov, $r['id']) : (float) $r['total'];
                if ( $left <= 0.01 ) continue;
                $out[] = ['type' => 'expense', 'id' => (int) $r['id'], 'left' => $left, 'name' => (string) $r['supplier_name'], 'label' => $r['supplier_name'] . ($r['number'] ? ' · ' . $r['number'] : '') . ' · ' . mysql2date('j/m/y', $r['issue_date']) . ' · ' . Money::format($r['total'])
                    . ($r['concept'] ? ' · ' . wp_html_excerpt($r['concept'], 40, '…') : '')
                    . ($total && $left < $total - 0.01 ? ' · ' . sprintf(__('faltan %s', 'mad-oficina'), Money::format($left)) : '')];
            }
            $tcov = self::coverage('tax');
            foreach ( $wpdb->get_results('SELECT id, kind, period, amount FROM ' . Schema::table('taxes') . ' ORDER BY paid_on DESC LIMIT 40', ARRAY_A) as $t ) {
                $left = self::remaining($t['amount'], $tcov, $t['id']);
                if ( $left > 0.01 ) $out[] = ['type' => 'tax', 'id' => (int) $t['id'], 'left' => $left, 'name' => '', 'label' => (Taxes::KINDS[$t['kind']] ?? $t['kind']) . ' · ' . $t['period'] . ' · ' . Money::format($t['amount'])];
            }
        }
        if ( $amount > 0 ) usort($out, fn($a, $b) => abs($a['left'] - $amount) <=> abs($b['left'] - $amount));
        return array_slice($out, 0, $limit);
    }
}
