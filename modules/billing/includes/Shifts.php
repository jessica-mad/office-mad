<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Amparo's daily shifts, quiet and without AI, and her Friday report:
 *  · Conciliación: the bank is matched again with whatever arrived since the statement.
 *  · Facturas de proveedor: the ones waiting are registered when the checks pass (the
 *    supplier is now known; or it always invoices her and the amount is the usual one).
 *  · Fridays at 12:00, one Chat message: what got done this week, what is missing (with
 *    links) and how the quarter looks for the gestoría. Nothing missing → one line.
 */
final class Shifts {

    const DAY = 'madoficina_fin_shift_day';
    const WEEK = 'madoficina_fin_week';
    const REPORT_DAY = 'madoficina_fin_report_day';
    const REPORT_HOUR = 12;
    const USUAL_MARGIN = 1.25;

    private $settings;

    public function __construct(array $settings){ $this->settings = $settings; }

    private static function week(){ $w = get_option(self::WEEK, []); return is_array($w) ? $w + ['bank' => 0, 'registered' => []] : ['bank' => 0, 'registered' => []]; }

    private static function note($key, $value){
        $w = self::week();
        if ( $key === 'registered' ) $w['registered'] = array_slice(array_merge($w['registered'], (array) $value), -50);
        else $w[$key] = ($w[$key] ?? 0) + (int) $value;
        update_option(self::WEEK, $w, false);
    }

    /** @return array what ran */
    public function run($now = null){
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(wp_timezone());
        $today = $local->format('Y-m-d');
        $ran = [];
        if ( (int) $local->format('G') >= 8 && get_option(self::DAY) !== $today ) {
            update_option(self::DAY, $today, false); // first, so a slow run is never repeated
            $ran['bank'] = Bank::match()['matched'];
            self::note('bank', $ran['bank']);
            $ran['registered'] = $this->review_supplier_invoices();
        }
        if ( $local->format('N') === '5' && (int) $local->format('G') >= self::REPORT_HOUR && get_option(self::REPORT_DAY) !== $today ) {
            update_option(self::REPORT_DAY, $today, false);
            $ran['report'] = $this->report($today);
        }
        return $ran;
    }

    /** Waiting supplier invoices that pass the checks are registered. @return int */
    public function review_supplier_invoices(){
        global $wpdb;
        if ( ! class_exists('MAD_Oficina\\Suppliers\\Suppliers') ) return 0;
        $done = [];
        foreach ( Approvals::pending(300) as $a ) {
            if ( ! in_array($a['action_type'], ['supplier_new', 'expense_review'], true) ) continue;
            $e = \MAD_Oficina\Suppliers\Expenses::get((int) ($a['payload']['expense_id'] ?? 0));
            if ( ! $e || $e['status'] !== 'review' ) continue;
            $supplier = $e['supplier_id'] ? \MAD_Oficina\Suppliers\Suppliers::get((int) $e['supplier_id'])
                : \MAD_Oficina\Suppliers\Suppliers::match((string) $e['supplier_tax_id'], \MAD_Oficina\Suppliers\Intake::address((string) $e['sender']), (string) $e['supplier_name']);
            if ( ! $supplier ) continue; // still unknown: Jessica decides

            if ( $a['action_type'] === 'supplier_new' ) {
                // She gave this supplier its card meanwhile: it is no longer new.
                $r = Approvals::resolve($a['id'], 'approve', ['mode' => 'existing', 'existing' => $supplier['id']], 0, 'auto');
            } else {
                // «¿Es tuya?» (it does not say whose it is): yes if this supplier always invoices her and the amount is the usual one.
                $before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('expenses') . " WHERE supplier_id = %d AND status = 'registered'", $supplier['id']));
                $usual = (float) $supplier['usual_amount'];
                if ( $before < 2 || ($usual > 0 && (float) $e['total'] > $usual * self::USUAL_MARGIN) ) continue;
                $r = Approvals::resolve($a['id'], 'approve', [], 0, 'auto');
            }
            if ( ! is_wp_error($r) ) $done[] = $supplier['name'] . ' · ' . Money::format((float) $e['total']);
        }
        if ( $done ) self::note('registered', $done);
        return count($done);
    }

    /* ───────── Friday's report ───────── */

    /** @return array{done:string[], missing:string[], quarter:string} */
    public function facts($today){
        global $wpdb;
        $w = self::week();
        $since = gmdate('Y-m-d', strtotime($today . ' -7 days'));
        $done = [];
        if ( $w['bank'] ) $done[] = sprintf(_n('%d movimiento del banco cuadrado', '%d movimientos del banco cuadrados', $w['bank'], 'mad-oficina'), $w['bank']);
        if ( $w['registered'] ) $done[] = sprintf(_n('%1$d factura de proveedor registrada sola (%2$s)', '%1$d facturas de proveedor registradas solas (%2$s)', count($w['registered']), 'mad-oficina'), count($w['registered']), implode(', ', array_slice($w['registered'], 0, 5)));
        $collected = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) n, SUM(total) t FROM ' . Invoices::table() . " WHERE paid = 1 AND paid_at >= %s AND series <> 'AR'", $since . ' 00:00:00'), ARRAY_A);
        if ( (int) $collected['n'] ) $done[] = sprintf(_n('%1$d cobro (%2$s)', '%1$d cobros (%2$s)', (int) $collected['n'], 'mad-oficina'), (int) $collected['n'], Money::format((float) $collected['t']));

        $missing = [];
        $bank_url = admin_url('admin.php?page=mad-oficina-facturacion&tab=bank');
        $counts = Bank::counts();
        if ( $counts['unmatched'] ) $missing[] = sprintf(_n('%1$d movimiento del banco sin cuadrar: %2$s', '%1$d movimientos del banco sin cuadrar: %2$s', $counts['unmatched'], 'mad-oficina'), $counts['unmatched'], $bank_url);
        [, $last] = Bank::covered();
        if ( ! $last || strtotime($last) < strtotime($today . ' -35 days') ) $missing[] = $last ? sprintf(__('El último extracto del banco llega al %s: súbeme el nuevo.', 'mad-oficina'), date_i18n('j/m', strtotime($last))) : __('Todavía no me has subido ningún extracto del banco.', 'mad-oficina');
        $overdue = $wpdb->get_results($wpdb->prepare('SELECT number, client_name, total, currency, due_date FROM ' . Invoices::table() . " WHERE status IN ('issued','sent') AND paid = 0 AND total > 0 AND due_date IS NOT NULL AND due_date < %s ORDER BY due_date LIMIT 6", $today), ARRAY_A);
        foreach ( $overdue as $o ) $missing[] = sprintf(__('%1$s debe %2$s desde el %3$s (%4$s)', 'mad-oficina'), $o['client_name'], Money::format((float) $o['total']) . ($o['currency'] !== 'EUR' ? ' ' . $o['currency'] : ''), date_i18n('j/m', strtotime($o['due_date'])), $o['number'] ?: __('Argentina', 'mad-oficina'));
        $pending = 0;
        foreach ( \MAD_Oficina\Core\FinanceAccess::types() as $type ) $pending += count(Approvals::pending(500, $type));
        if ( $pending ) $missing[] = sprintf(_n('%1$d cosa de Finanzas esperando en tu bandeja: %2$s', '%1$d cosas de Finanzas esperando en tu bandeja: %2$s', $pending, 'mad-oficina'), $pending, admin_url('admin.php?page=mad-oficina-bandeja'));
        $nopdf = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Invoices::table() . " WHERE number IS NOT NULL AND series <> 'AR' AND status IN ('issued','sent') AND pdf_url IS NULL");
        if ( $nopdf ) $missing[] = sprintf(_n('%d factura emitida sin su PDF en Drive (¿el cartero está al día?)', '%d facturas emitidas sin su PDF en Drive (¿el cartero está al día?)', $nopdf, 'mad-oficina'), $nopdf);
        $unread = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('expenses') . " WHERE status IN ('queued') OR (status = 'review' AND reason IN ('unreadable','long'))");
        if ( $unread ) $missing[] = sprintf(_n('%d factura de proveedor que no he podido leer: %s', '%d facturas de proveedor que no he podido leer: %s', $unread, 'mad-oficina'), $unread, admin_url('admin.php?page=mad-oficina-gastos'));

        $q = (int) ceil((int) substr($today, 5, 2) / 3);
        $end = gmdate('Y-m-t', strtotime(substr($today, 0, 4) . '-' . sprintf('%02d', $q * 3) . '-01'));
        $days = (int) round((strtotime($end) - strtotime($today)) / DAY_IN_SECONDS);
        $quarter = $missing
            ? sprintf(__('Trimestre %1$d (cierra en %2$d días): falta lo de arriba para tenerlo listo para la gestoría.', 'mad-oficina'), $q, $days)
            : sprintf(__('Trimestre %1$d (cierra en %2$d días): todo al día para la gestoría.', 'mad-oficina'), $q, $days);
        return ['done' => $done, 'missing' => $missing, 'quarter' => $quarter];
    }

    /** @return bool sent */
    public function report($today = null){
        $today = $today ?: current_time('Y-m-d');
        $f = $this->facts($today);
        $name = \MAD_Oficina\Core\Agents\Characters::get('contable')['name'] ?? 'Amparo';
        $text = '*' . sprintf(__('%s · tu semana en Finanzas', 'mad-oficina'), $name) . "*\n";
        if ( ! $f['missing'] ) {
            $text .= __('Todo en orden: no te falta nada.', 'mad-oficina') . ($f['done'] ? ' (' . implode(' · ', $f['done']) . ')' : '');
        } else {
            if ( $f['done'] ) $text .= "\n_" . __('Hecho esta semana', 'mad-oficina') . "_\n• " . implode("\n• ", $f['done']) . "\n";
            $text .= "\n_" . __('Lo que te falta', 'mad-oficina') . "_\n• " . implode("\n• ", $f['missing']) . "\n";
        }
        $text .= "\n" . $f['quarter'];
        $chat = \MAD_Oficina_Core::instance()->get_module('chat');
        if ( ! $chat ) return false;
        $r = $chat->messenger()->to_manager(['text' => $text]);
        if ( is_wp_error($r) ) return false;
        delete_option(self::WEEK); // a new week starts
        return true;
    }
}
