<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Billing calendar: from the agreements, a few days before each client's billing
 * day (the 28th, or its own) the office prepares the draft with that month's fees
 * and one-off jobs; on the day it asks Jessica (inbox + Chat): send / edit /
 * postpone / not this month. Every line remembers which agreement and month it
 * bills, so nothing is billed twice or forgotten. Nothing is sent without approval.
 */
final class Calendar {

    const SOURCE = 'calendar';
    const SKIPS_OPTION = 'madoficina_billing_skips';
    const RUN_OPTION = 'madoficina_billing_calendar_day';
    const DEFAULT_LEAD_DAYS = 3;
    const DEFAULT_HOUR = 9;

    private $settings;
    private $issuing;

    public function __construct(array $settings){
        $this->settings = $settings;
        $this->issuing = new Issuing($settings);
    }

    public function enabled(){
        return ! empty($this->settings['calendar_enabled']) && Months::valid($this->settings['calendar_from'] ?? '');
    }

    public function lead_days(){
        return max(0, min(15, (int) ($this->settings['calendar_lead_days'] ?? self::DEFAULT_LEAD_DAYS)));
    }

    /** Once a day, after the configured hour. @return array what it did */
    public function run($now = null){
        if ( ! $this->enabled() ) return [];
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(wp_timezone());
        $today = $local->format('Y-m-d');
        if ( (int) $local->format('G') < (int) ($this->settings['calendar_hour'] ?? self::DEFAULT_HOUR) ) return [];
        if ( get_option(self::RUN_OPTION) === $today ) return [];
        update_option(self::RUN_OPTION, $today, false); // first, so a slow run is never repeated
        return ['prepared' => $this->prepare($today), 'asked' => $this->ask($today)];
    }

    /* ==== What is due ==== */

    /** Billing date of a profile in a month (its day; the last day in shorter months). */
    public function date_for(array $profile, $month){
        return $this->issuing->default_issue_date($profile, $month . '-01');
    }

    public static function skipped($item_id, $month){
        $s = get_option(self::SKIPS_OPTION, []);
        return isset($s[(int) $item_id . '|' . $month]);
    }

    public static function skip($item_id, $month){
        $s = get_option(self::SKIPS_OPTION, []);
        $s[(int) $item_id . '|' . $month] = current_time('Y-m-d');
        update_option(self::SKIPS_OPTION, $s, false);
    }

    /** Invoice line (any invoice but cancelled) that already bills this agreement in this month. */
    public static function billed_in($item_id, $month){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT l.invoice_id, i.status, i.number FROM ' . Schema::table('invoice_lines') . ' l JOIN ' . Invoices::table() . " i ON i.id = l.invoice_id WHERE l.item_id = %d AND l.item_month = %s AND i.status <> 'cancelled' LIMIT 1",
            $item_id, $month
        ), ARRAY_A);
    }

    /**
     * The month as a list per client: date, agreements and where each one is.
     * @return array<int, array{profile:array, date:string, items:array, total:float, invoice:?array, state:string}>
     */
    public function month($month){
        $by = [];
        foreach ( Agreements::between($month, $month) as $it ) {
            if ( ! Agreements::covers($it, $month) ) continue;
            $by[$it['profile_id']][] = $it;
        }
        $out = [];
        foreach ( $by as $pid => $items ) {
            $profile = Profiles::get($pid);
            if ( ! $profile ) continue;
            $invoice = null; $pending = 0; $total = 0.0; $rows = [];
            foreach ( $items as $it ) {
                $billed = self::billed_in($it['id'], $month);
                $skipped = ! $billed && self::skipped($it['id'], $month);
                if ( $billed && ! $invoice ) $invoice = Invoices::get((int) $billed['invoice_id']);
                if ( ! $billed && ! $skipped ) $pending++;
                if ( ! $skipped ) $total += $it['amount'];
                $rows[] = $it + ['billed' => $billed, 'skipped' => $skipped];
            }
            $date = $invoice['issue_date'] ?? $this->date_for($profile, $month);
            if ( ! $invoice && ! $pending ) $state = 'skipped';
            elseif ( ! $invoice ) $state = 'to_prepare';
            elseif ( $invoice['status'] === 'draft' ) {
                $state = 'draft';
                foreach ( Approvals::for_object('invoice', $invoice['id']) as $a ) if ( in_array($a['status'], [Approvals::PENDING, Approvals::PROCESSING], true) ) $state = 'asked';
            } else $state = $invoice['status']; // issued | sent
            $out[] = ['profile' => $profile, 'date' => $date, 'items' => $rows, 'total' => $total, 'invoice' => $invoice, 'state' => $state, 'pending' => $pending];
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcasecmp($a['profile']['legal_name'], $b['profile']['legal_name']));
        return $out;
    }

    /* ==== Prepare ==== */

    /** Drafts whose date is within the lead days (this month and the next, for early days). @return int */
    public function prepare($today){
        $n = 0;
        $from = $this->settings['calendar_from'];
        foreach ( [Months::of($today), Months::add(Months::of($today), 1)] as $month ) {
            if ( $month < $from ) continue;
            foreach ( $this->month($month) as $row ) {
                if ( ! $row['pending'] ) continue;
                if ( gmdate('Y-m-d', strtotime($row['date'] . ' -' . $this->lead_days() . ' days')) > $today ) continue;
                $r = $this->prepare_one($row['profile'], $month);
                if ( ! is_wp_error($r) && $r ) $n++;
            }
        }
        return $n;
    }

    /**
     * The client's draft for the month: the lines still missing are added to its
     * calendar draft, or a new draft is created. @return int|\WP_Error invoice id (0 = nothing to add)
     */
    public function prepare_one(array $profile, $month){
        global $wpdb;
        $lines = [];
        foreach ( Agreements::between($month, $month) as $it ) {
            if ( (int) $it['profile_id'] !== (int) $profile['id'] || ! Agreements::covers($it, $month) ) continue;
            if ( self::billed_in($it['id'], $month) || self::skipped($it['id'], $month) ) continue;
            $lines[] = ['description' => $it['kind'] === 'monthly' ? $it['concept'] . ' · ' . Issuing::month($month . '-01') . ' ' . substr($month, 0, 4) : $it['concept'],
                'quantity' => '1', 'unit_price' => number_format($it['amount'], 2, ',', ''), 'item_id' => $it['id'], 'item_month' => $month];
        }
        if ( ! $lines ) return 0;

        // Its calendar draft for this month, if there is one already.
        $draft = $wpdb->get_var($wpdb->prepare(
            'SELECT DISTINCT i.id FROM ' . Invoices::table() . ' i JOIN ' . Schema::table('invoice_lines') . " l ON l.invoice_id = i.id WHERE i.profile_id = %d AND i.status = 'draft' AND l.item_month = %s LIMIT 1",
            $profile['id'], $month
        ));
        if ( $draft ) {
            $inv = Invoices::get((int) $draft);
            $keep = array_map(fn($l) => ['description' => $l['description'], 'quantity' => (string) $l['quantity'], 'unit_price' => number_format((float) $l['unit_price'], 2, ',', ''), 'item_id' => $l['item_id'], 'item_month' => $l['item_month'], 'expense_id' => $l['expense_id']], $inv['lines']);
            return $this->issuing->save_draft(['profile_id' => $profile['id'], 'issue_date' => $inv['issue_date'], 'due_date' => $inv['due_date'], 'vat_rate' => $inv['vat_rate'], 'irpf_rate' => $inv['irpf_rate'], 'notes' => $inv['notes']], array_merge($keep, $lines), $inv['id']);
        }

        // Another invoice for this client this month (made by hand): warn, do not guess.
        $date = $this->date_for($profile, $month);
        $other = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(number, ' . "'borrador'" . ') FROM ' . Invoices::table() . " WHERE profile_id = %d AND status <> 'cancelled' AND LEFT(issue_date, 7) = %s LIMIT 1",
            $profile['id'], $month
        ));
        $id = $this->issuing->save_draft([
            'profile_id' => $profile['id'], 'issue_date' => $date,
            'notes' => $other ? sprintf(__('Ojo: este mes ya hay otra factura para este cliente (%s). Revisa que no se cobre dos veces.', 'mad-oficina'), $other) : '',
        ], $lines);
        if ( ! is_wp_error($id) ) $wpdb->update(Invoices::table(), ['source' => self::SOURCE], ['id' => $id]);
        return $id;
    }

    /* ==== Ask on the day ==== */

    /** Calendar drafts whose day has come and that were not asked yet. @return int */
    public function ask($today){
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Invoices::table() . " WHERE status = 'draft' AND source = %s AND asked_at IS NULL AND issue_date <= %s ORDER BY issue_date, id",
            self::SOURCE, $today
        ));
        $n = 0;
        foreach ( $ids as $id ) {
            $inv = Invoices::get((int) $id);
            $wpdb->update(Invoices::table(), ['asked_at' => Time::now()], ['id' => $id]);
            if ( $inv['series'] === 'AR' ) {
                // Issued on the Argentine platform: only a reminder.
                do_action('madoficina_notify_manager', sprintf(__('Hoy toca la factura de %1$s (%2$s) en la plataforma argentina. Cuando la hagas, márcala como emitida en la oficina: %3$s', 'mad-oficina'),
                    $inv['client_name'], Money::format($inv['total']), admin_url('admin.php?page=mad-oficina-facturacion&invoice=' . $id)));
                $n++;
                continue;
            }
            $r = $this->issuing->request((int) $id);
            if ( is_wp_error($r) ) {
                do_action('madoficina_notify_manager', sprintf(__('Quería preguntarte por la factura de %1$s, pero %2$s', 'mad-oficina'), $inv['client_name'], lcfirst($r->get_error_message())));
            }
            $n++;
        }
        return $n;
    }

    /* ==== Answers ==== */

    /** «Posponer N días»: the invoice date moves and it is asked again that day. */
    public static function postpone($invoice_id, $days){
        global $wpdb;
        $inv = Invoices::get($invoice_id);
        if ( ! $inv || $inv['status'] !== 'draft' ) return new \WP_Error('madof_inv', __('Esa factura ya no está en borrador.', 'mad-oficina'));
        foreach ( Approvals::for_object('invoice', $invoice_id) as $a ) {
            if ( $a['status'] === Approvals::PENDING ) Approvals::resolve($a['id'], 'reject', [], get_current_user_id());
        }
        $date = gmdate('Y-m-d', strtotime(max($inv['issue_date'], current_time('Y-m-d')) . ' +' . max(1, (int) $days) . ' days'));
        $wpdb->update(Invoices::table(), [
            'issue_date' => $date, 'year' => (int) substr($date, 0, 4),
            'due_date' => $inv['due_date'] ? gmdate('Y-m-d', strtotime($inv['due_date'] . ' +' . (strtotime($date) - strtotime($inv['issue_date'])) / DAY_IN_SECONDS . ' days')) : null,
            'asked_at' => null, 'source' => self::SOURCE, 'updated_at' => Time::now(),
        ], ['id' => $invoice_id]);
        return ['message' => sprintf(__('Pospuesta al %s: ese día te vuelvo a preguntar.', 'mad-oficina'), date_i18n('j/m', strtotime($date))), 'date' => $date];
    }

    /** «Este mes no»: the draft goes and its agreements are skipped this month (also in the forecast). */
    public static function skip_invoice($invoice_id){
        $inv = Invoices::get($invoice_id);
        if ( ! $inv || $inv['status'] !== 'draft' ) return new \WP_Error('madof_inv', __('Esa factura ya no está en borrador.', 'mad-oficina'));
        foreach ( $inv['lines'] as $l ) if ( $l['item_id'] && $l['item_month'] ) self::skip((int) $l['item_id'], $l['item_month']);
        Issuing::delete_draft($invoice_id);
        return ['message' => sprintf(__('Este mes no se factura a %s. Queda apuntado (también en la previsión).', 'mad-oficina'), $inv['client_name'])];
    }

    /** Extra buttons on the invoice card in Chat. */
    public static function chat_buttons(array $buttons, array $approval){
        if ( $approval['action_type'] !== Issuing::ACTION ) return $buttons;
        $id = (int) ($approval['payload']['invoice_id'] ?? 0);
        $edit = admin_url('admin.php?page=mad-oficina-facturacion&edit=' . $id);
        return [
            \MAD_Oficina\Chat\Cards::button(__('Enviar', 'mad-oficina'), 'approve', ['approval_id' => $approval['id']]),
            \MAD_Oficina\Chat\Cards::link(__('Editar', 'mad-oficina'), $edit),
            \MAD_Oficina\Chat\Cards::button(__('Posponer 7 días', 'mad-oficina'), 'invoice_postpone', ['invoice_id' => $id, 'days' => 7]),
            \MAD_Oficina\Chat\Cards::button(__('Este mes no', 'mad-oficina'), 'invoice_skip', ['invoice_id' => $id]),
        ];
    }

    public static function chat_action($out, $fn, array $params, $email, $is_manager){
        if ( ! in_array($fn, ['invoice_postpone', 'invoice_skip'], true) ) return $out;
        if ( ! $is_manager ) return __('Solo Jessica puede decidir sobre las facturas.', 'mad-oficina');
        $id = (int) ($params['invoice_id'] ?? 0);
        $inv = Invoices::get($id);
        $r = $fn === 'invoice_skip' ? self::skip_invoice($id) : self::postpone($id, (int) ($params['days'] ?? 7));
        if ( is_wp_error($r) ) return $r->get_error_message();
        return \MAD_Oficina\Chat\Cards::card('invoice-' . $id, __('Facturación', 'mad-oficina'), $inv['client_name'] ?? '', [
            ['decoratedText' => ['topLabel' => __('Hecho', 'mad-oficina'), 'text' => esc_html($r['message']), 'wrapText' => true]],
        ]);
    }
}
