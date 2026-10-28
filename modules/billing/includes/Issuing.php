<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;
use MAD_Oficina\Meetings\Orders;

if ( ! defined('ABSPATH') ) exit;

/**
 * Draft → approval in the inbox → number → the cartero archives the PDF in the
 * shared drive («Emitidas/AAAA/Tn/»), sends it from Jessica's Gmail and updates
 * the «Libro MAD» sheet. Nothing leaves without approval; the number is only
 * taken when the approval runs, so discarded drafts leave no gaps.
 */
final class Issuing {

    const ACTION = 'invoice_issue';
    const DEFAULT_BILLING_DAY = 28;  // invoices are issued at the end of the month
    const DEFAULT_PAYMENT_DAYS = 15;

    const DEFAULT_SUBJECT = 'Factura {numero} · MAD';
    const DEFAULT_BODY = "Hola,\n\nTe adjunto la factura {numero} de {mes}, por {total}, con vencimiento el {vencimiento}.\n\nCualquier cosa, me dices.\n\nUn abrazo,\nJessica\nMAD · Marketing & Ads";

    const RECTIFY_SUBJECT = 'Factura rectificativa {numero} · MAD';
    const RECTIFY_BODY = "Hola,\n\nTe adjunto la factura rectificativa {numero}, que rectifica la factura {rectifica}.\nMotivo: {motivo}\n\nCualquier cosa, me dices.\n\nUn abrazo,\nJessica\nMAD · Marketing & Ads";

    private $settings;

    public function __construct(array $settings){
        $this->settings = $settings;
    }

    public function billing_day(?array $profile){
        $d = (int) ($profile['billing_day'] ?? 0) ?: (int) ($this->settings['billing_day'] ?? 0);
        return $d >= 1 && $d <= 31 ? $d : self::DEFAULT_BILLING_DAY;
    }

    public function payment_days(?array $profile){
        $d = $profile['payment_days'] ?? null;
        if ( $d === null || $d === '' ) $d = $this->settings['payment_days'] ?? self::DEFAULT_PAYMENT_DAYS;
        return max(0, min(365, (int) $d));
    }

    /** This month's billing day (the 28th, or the client's own day; the last day when the month is shorter). */
    public function default_issue_date(?array $profile, $today = null){
        $today = $today ?: current_time('Y-m-d');
        $first = substr($today, 0, 8) . '01';
        $last = (int) gmdate('t', strtotime($first));
        return substr($today, 0, 8) . str_pad((string) min($this->billing_day($profile), $last), 2, '0', STR_PAD_LEFT);
    }

    public function due_date($issue_date, ?array $profile){
        return gmdate('Y-m-d', strtotime($issue_date . ' +' . $this->payment_days($profile) . ' days'));
    }

    /* ==== Drafts ==== */

    /**
     * Creates or updates a draft from the editor. Client data is copied from the
     * fiscal profile (the invoice keeps its own snapshot).
     * @param array $data profile_id, issue_date, due_date, vat_rate, irpf_rate, notes, series
     * @param array $lines [{description, quantity, unit_price}]
     * @return int|\WP_Error invoice id
     */
    public function save_draft(array $data, array $lines, $id = 0){
        global $wpdb;
        if ( $id ) {
            $inv = Invoices::get($id);
            if ( ! $inv ) return new \WP_Error('madof_inv', __('Esa factura no existe.', 'mad-oficina'));
            if ( $inv['status'] !== 'draft' || $inv['number'] ) return new \WP_Error('madof_inv_locked', __('Una factura emitida no se puede editar. Si hay que corregirla, haz una rectificativa.', 'mad-oficina'));
        }
        $profile = Profiles::get((int) ($data['profile_id'] ?? 0));
        if ( ! $profile ) return new \WP_Error('madof_inv_profile', __('Elige a quién va la factura (sus datos fiscales).', 'mad-oficina'));
        // A draft goes to the client's current legal name, never to one marked «anterior» (rectifying ones keep the original's).
        $rectifying = ! empty($data['rectifies_id']) || ( $id && ! empty(Invoices::get($id)['rectifies_id']) );
        if ( ! $rectifying && $profile['status'] === 'inactive' && $profile['client_id'] ) {
            $current = array_values(array_filter(Profiles::for_client((int) $profile['client_id']), fn($p) => $p['status'] === 'active' && $p['series'] === $profile['series']));
            if ( count($current) === 1 ) $profile = $current[0];
        }

        $clean = [];
        foreach ( $lines as $l ) {
            $desc = trim(sanitize_textarea_field((string) ($l['description'] ?? '')));
            $qty = Money::parse((string) ($l['quantity'] ?? '1'));
            $price = Money::parse((string) ($l['unit_price'] ?? ''));
            if ( $desc === '' && ! $price ) continue;
            $qty = $qty === null ? 1.0 : $qty;
            $clean[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => (float) $price, 'amount' => Money::round($qty * (float) $price),
                'expense_id' => ! empty($l['expense_id']) ? (int) $l['expense_id'] : null,
                // The agreement and month this line bills (calendar drafts): kept so it is never billed twice.
                'item_id' => ! empty($l['item_id']) ? (int) $l['item_id'] : null,
                'item_month' => ! empty($l['item_id']) ? Months::valid($l['item_month'] ?? '') : null];
        }
        if ( ! $clean ) return new \WP_Error('madof_inv_lines', __('Añade al menos un concepto con su importe.', 'mad-oficina'));

        $series = ($data['series'] ?? '') === 'R' ? 'R' : ($profile['series'] === 'AR' ? 'AR' : 'F');
        if ( $id && $inv['series'] === 'R' ) $series = 'R';
        $issue = self::valid_date($data['issue_date'] ?? '') ?: $this->default_issue_date($profile);
        $due = self::valid_date($data['due_date'] ?? '') ?: $this->due_date($issue, $profile);
        $vat = isset($data['vat_rate']) && $data['vat_rate'] !== '' ? (float) str_replace(',', '.', $data['vat_rate']) : $profile['vat_rate'];
        $irpf = isset($data['irpf_rate']) && $data['irpf_rate'] !== '' ? (float) str_replace(',', '.', $data['irpf_rate']) : $profile['irpf_rate'];
        $t = Invoices::totals($clean, $vat, $irpf);
        // A rectifying invoice: «difference» carries only what changes; «substitution» prints the
        // whole corrected invoice (new recipient details too) but counts only what changes.
        $original = ! empty($data['rectifies_id']) ? Invoices::get((int) $data['rectifies_id']) : ( $id && ! empty($inv['rectifies_id']) ? Invoices::get((int) $inv['rectifies_id']) : null );
        $mode = null;
        if ( $series === 'R' ) {
            $mode = in_array($data['rectify_mode'] ?? '', self::MODES, true) ? $data['rectify_mode'] : ( $id && $inv['rectify_mode'] ? $inv['rectify_mode'] : 'difference' );
            if ( $mode === 'substitution' && $original ) {
                [$ov, $oi] = self::rates_of($original);
                $was = Invoices::totals(self::lines_of($original), $ov, $oi);
                foreach ( $t as $k => $v ) $t[$k] = Money::round($v - $was[$k]);
            }
        }

        $row = [
            'series' => $series, 'year' => (int) substr($issue, 0, 4), 'issue_date' => $issue, 'due_date' => $due,
            'profile_id' => $profile['id'], 'client_id' => $profile['client_id'],
            'client_name' => $profile['legal_name'], 'client_tax_id' => $profile['tax_id'],
            'client_address' => $profile['address'], 'client_city' => $profile['city'], 'client_email' => $profile['email'],
            'vat_rate' => $vat, 'irpf_rate' => $irpf, 'status' => 'draft', 'source' => 'plugin',
            'notes' => sanitize_textarea_field($data['notes'] ?? '') ?: null,
            // Why a rectifying invoice exists: printed on it.
            'rectify_reason' => $series !== 'R' ? null : (array_key_exists('rectify_reason', $data)
                ? (mb_substr(sanitize_text_field((string) $data['rectify_reason']), 0, 500) ?: null)
                : ($id ? $inv['rectify_reason'] : null)),
            'rectify_mode' => $mode,
        ] + $t;
        // By differences it goes to whoever the original went to; by substitution, to the corrected details.
        $keep = array_key_exists('keep_recipient', $data) ? ! empty($data['keep_recipient']) : ( $id && $mode === 'substitution' ? self::kept_recipient($inv, $original) : true );
        if ( $original && ($mode !== 'substitution' || $keep) ) foreach ( self::RECIPIENT as $k ) $row[$k] = $original[$k];

        if ( ! $id ) {
            if ( ! empty($data['rectifies_id']) ) $row['rectifies_id'] = (int) $data['rectifies_id'];
            return Invoices::insert($row, $clean);
        }
        $wpdb->update(Invoices::table(), $row + ['updated_at' => Time::now()], ['id' => $id, 'status' => 'draft']);
        $wpdb->delete(Schema::table('invoice_lines'), ['invoice_id' => $id]);
        foreach ( $clean as $i => $l ) $wpdb->insert(Schema::table('invoice_lines'), ['invoice_id' => $id, 'position' => $i] + $l);
        return (int) $id;
    }

    /** Drafts can go (they never had a number); issued invoices are kept forever. */
    public static function delete_draft($id){
        global $wpdb;
        $inv = Invoices::get($id);
        if ( ! $inv || $inv['status'] !== 'draft' || $inv['number'] ) return false;
        // A draft the calendar made would come back the next morning: it is not prepared again on its own.
        $months = array_values(array_unique(array_filter(array_column($inv['lines'], 'item_month'))));
        if ( $months && $inv['profile_id'] ) Calendar::dismiss((int) $inv['profile_id'], $months);
        foreach ( Approvals::for_object('invoice', $id) as $a ) {
            if ( $a['status'] === Approvals::PENDING ) Approvals::resolve($a['id'], 'reject', [], get_current_user_id());
        }
        $wpdb->delete(Schema::table('invoice_lines'), ['invoice_id' => $id]);
        return (bool) $wpdb->delete(Invoices::table(), ['id' => $id, 'status' => 'draft']);
    }

    /** The recipient's details an invoice keeps as they were when it was made. */
    /** How a rectifying invoice corrects (RD 1619/2012, art. 15): only what changes, or the whole invoice again. */
    const MODES = ['difference', 'substitution'];

    const RECIPIENT = ['client_name', 'client_tax_id', 'client_address', 'client_city', 'client_email'];

    /**
     * The client changed its fiscal details: drafts still to issue take the new ones.
     * @return int drafts updated
     */
    public static function refresh_drafts($profile_id){
        global $wpdb;
        $p = Profiles::get((int) $profile_id);
        if ( ! $p ) return 0;
        return (int) $wpdb->query($wpdb->prepare('UPDATE ' . Invoices::table() . " SET client_name = %s, client_tax_id = %s, client_address = %s, client_city = %s, client_email = %s, updated_at = %s
            WHERE profile_id = %d AND status = 'draft' AND number IS NULL AND rectifies_id IS NULL",
            $p['legal_name'], $p['tax_id'], $p['address'], $p['city'], $p['email'], Time::now(), $p['id']));
    }

    /**
     * Drafts and running agreements always hang from the client's current legal name: what is
     * still under one marked «anterior» goes to the current one (when there is exactly one).
     * Safe to call any time; issued invoices never move. @return array{drafts:int, agreements:int}
     */
    public static function follow_current($client_id = null){
        global $wpdb;
        $moved = ['drafts' => 0, 'agreements' => 0];
        $where = $client_id ? $wpdb->prepare(' AND client_id = %d', (int) $client_id) : ' AND client_id IS NOT NULL';
        foreach ( $wpdb->get_col("SELECT DISTINCT client_id FROM " . Profiles::table() . " WHERE status = 'inactive'" . $where) as $cid ) {
            $all = Profiles::for_client((int) $cid);
            foreach ( array_unique(array_column($all, 'series')) as $series ) {
                $current = array_values(array_filter($all, fn($p) => $p['status'] === 'active' && $p['series'] === $series));
                if ( count($current) !== 1 ) continue; // none, or two companies at once: she decides in each draft
                foreach ( array_filter($all, fn($p) => $p['status'] === 'inactive' && $p['series'] === $series) as $old ) {
                    foreach ( self::move_to_profile($old['id'], $current[0]['id']) as $k => $n ) $moved[$k] += $n;
                }
            }
        }
        return $moved;
    }

    /**
     * The client now invoices under another legal name: what was still to come under the old
     * one moves to the new one — its pending drafts (with the new details) and its running
     * agreements (monthly fees…), plus its billing day and payment terms if the new one has none.
     * Issued invoices never move.
     * @return array{drafts:int, agreements:int}
     */
    public static function move_to_profile($from_id, $to_id){
        global $wpdb;
        $from = Profiles::get((int) $from_id); $to = Profiles::get((int) $to_id);
        if ( ! $from || ! $to || $from['id'] === $to['id'] ) return ['drafts' => 0, 'agreements' => 0];
        $drafts = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Invoices::table() . ' SET profile_id = %d' . ($to['client_id'] ? ', client_id = ' . (int) $to['client_id'] : '') . ", updated_at = %s WHERE profile_id = %d AND status = 'draft' AND number IS NULL AND rectifies_id IS NULL",
            $to['id'], Time::now(), $from['id']));
        $items = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Agreements::table() . " SET profile_id = %d, updated_at = %s WHERE profile_id = %d AND status = 'active' AND (end_month IS NULL OR end_month >= %s)",
            $to['id'], Time::now(), $from['id'], current_time('Y-m')));
        $terms = array_filter(['billing_day' => $to['billing_day'] ? null : $from['billing_day'], 'payment_days' => $to['payment_days'] !== null ? null : $from['payment_days']], fn($v) => $v !== null);
        if ( $terms ) $wpdb->update(Profiles::table(), $terms + ['updated_at' => Time::now()], ['id' => $to['id']]);
        self::refresh_drafts($to['id']);
        return ['drafts' => $drafts, 'agreements' => $items];
    }

    /**
     * An issued invoice made out with the client's old fiscal details: corrected with the
     * details now in their profile, same concepts and amounts. See correct().
     */
    public function replace_for_new_details($id, $reason = '', $target = 'same', $new_date = null){
        $inv = Invoices::get($id);
        if ( ! $inv ) return new \WP_Error('madof_inv', __('Esa factura no existe.', 'mad-oficina'));
        return $this->correct($id, $inv && $inv['number'] ? self::lines_of($inv) : [], $reason, $target, $new_date, true);
    }

    /**
     * An issued invoice corrected, as Jessica chooses:
     *  · «same»: it keeps its number and date and takes the right details and/or concepts once
     *    the rectifying invoice (by substitution) that documents it is issued.
     *  · «new»: a rectifying invoice that cancels it whole and a new invoice of series F, with
     *    the next number and the date she picks.
     * Nothing is issued until her «Emitir y enviar».
     * @param array  $lines       the corrected concepts, whole [{description, quantity, unit_price}]
     * @param bool   $new_details to the client's current fiscal details (else: the original's)
     * @return array{rectify:int, new:int, same:bool}|\WP_Error
     */
    public function correct($id, array $lines, $reason = '', $target = 'same', $new_date = null, $new_details = false){
        $inv = Invoices::get($id);
        if ( ! $inv ) return new \WP_Error('madof_inv', __('Esa factura no existe.', 'mad-oficina'));
        if ( ! $inv['number'] ) return new \WP_Error('madof_inv_rect', __('Esta factura todavía es un borrador: cámbiale los datos editándola, no hace falta rectificarla.', 'mad-oficina'));
        if ( $inv['series'] !== 'F' ) return new \WP_Error('madof_inv_rect', sprintf(__('Solo se corrigen así facturas de la serie F; esta es de la serie %s.', 'mad-oficina'), $inv['series']));
        $target = $target === 'new' ? 'new' : 'same';
        $reason = trim((string) $reason);
        // Pressed again: while all is still a draft, start over with what she chose now.
        $done = self::replacement_of($id);
        if ( $done && (int) $done['original']['id'] === (int) $id ) {
            $drafts = array_filter([$done['rectify'], $done['new']]);
            if ( array_filter($drafts, fn($d) => $d['status'] !== 'draft' || $d['number']) ) return ['rectify' => (int) ($done['rectify']['id'] ?? 0), 'new' => (int) ($done['new']['id'] ?? 0), 'same' => $done['same'], 'existing' => true];
            if ( $reason === '' && $done['rectify'] ) $reason = (string) $done['rectify']['rectify_reason'];
            foreach ( $drafts as $d ) self::delete_draft((int) $d['id']);
            self::forget_replacement($id);
        }
        if ( self::corrected_of($id) ) return new \WP_Error('madof_inv_done', __('Esta factura ya se corrigió con una rectificativa.', 'mad-oficina'));
        [$original, $profile] = self::profiles_for_new_details($inv);
        if ( $new_details ) {
            if ( ! $profile ) return new \WP_Error('madof_inv_profile', sprintf(__('No encuentro los datos fiscales de «%1$s» (NIF %2$s) en Clientes de facturación. Créalos o únelos allí y vuelve a pulsar el botón.', 'mad-oficina'), $inv['client_name'], $inv['client_tax_id'] ?: '—'));
            if ( ! self::details_differ($inv, $profile) ) return new \WP_Error('madof_inv_same', sprintf(__('La ficha fiscal «%s» tiene los mismos datos que la factura (nombre, NIF, dirección y localidad). Actualízala primero en Facturación → Clientes de facturación, o crea la nueva razón social unida al mismo cliente.', 'mad-oficina'), $profile['legal_name']));
        } else {
            $profile = $original ?: $profile;
            if ( ! $profile ) return new \WP_Error('madof_inv_profile', __('No encuentro sus datos fiscales en Clientes de facturación.', 'mad-oficina'));
            if ( self::same_lines(self::lines_of($inv), $lines) ) return new \WP_Error('madof_inv_same', __('No has cambiado nada: escribe el importe o el texto correcto de algún concepto, o añade uno.', 'mad-oficina'));
        }
        if ( ! $lines ) return new \WP_Error('madof_inv_lines', __('Esta factura no tiene conceptos ni base imponible guardados, así que no sé qué rectificar. Revisa la fila original de la hoja antigua (abajo) y prepara la rectificativa a mano.', 'mad-oficina'));
        [$vat, $irpf] = self::rates_of($inv);
        // Today, or later if an invoice of the series already carries a later date (they go in order).
        $date_for = function($series) use ($inv){
            $last = $this->last_issued($series, (int) current_time('Y'));
            return max(current_time('Y-m-d'), (string) $inv['issue_date'], $last ? (string) $last['issue_date'] : '');
        };
        $default_reason = $new_details ? ($target === 'same' ? self::DEFAULT_DETAILS_REASON : self::DEFAULT_NEW_REASON) : '';
        if ( $reason === '' && $default_reason === '' ) return new \WP_Error('madof_inv_reason', __('Escribe el motivo de la rectificación: va impreso en la factura.', 'mad-oficina'));

        if ( $target === 'same' ) {
            $r = $this->save_draft([
                'profile_id' => $profile['id'], 'series' => 'R', 'rectifies_id' => $inv['id'], 'rectify_mode' => 'substitution',
                'keep_recipient' => ! $new_details, 'issue_date' => $date_for('R'), 'vat_rate' => $vat, 'irpf_rate' => $irpf,
                'rectify_reason' => $reason !== '' ? $reason : $default_reason,
            ], $lines);
            if ( is_wp_error($r) ) return $r;
            $new = 0;
        } else {
            $r = $this->rectify($id, '', $date_for('R'), ($original ?: $profile)['id'], $reason !== '' ? $reason : $default_reason);
            if ( is_wp_error($r) ) return $r;
            $new = $this->save_draft([
                'profile_id' => $profile['id'], 'issue_date' => self::valid_date((string) $new_date) ?: $date_for('F'), 'vat_rate' => $vat, 'irpf_rate' => $irpf,
                'notes' => trim(sprintf(__('Sustituye a la factura %s, anulada por la rectificativa.', 'mad-oficina'), $inv['number']) . ($inv['source'] === 'import' ? '' : "\n" . (string) $inv['notes'])),
            ], $lines);
            if ( is_wp_error($new) ) { self::delete_draft($r); return $new; }
            if ( ! $new_details ) $this->keep_recipient_of($new, $inv);
        }
        $map = get_option(self::REPLACED, []);
        $map = is_array($map) ? $map : [];
        $map[(int) $id] = ['rectify' => (int) $r, 'new' => (int) $new, 'same' => $target === 'same', 'details' => (bool) $new_details];
        update_option(self::REPLACED, $map, false);
        return ['rectify' => (int) $r, 'new' => (int) $new, 'same' => $target === 'same'];
    }

    /** Re-saving a substitution draft keeps whoever it went to: the original's details if it had them. */
    private static function kept_recipient(array $draft, ?array $original){
        if ( ! $original ) return false;
        foreach ( self::RECIPIENT as $k ) if ( (string) $draft[$k] !== (string) $original[$k] ) return false;
        return true;
    }

    /** A new invoice for a concepts correction goes to the same details as the one it replaces. */
    private function keep_recipient_of($draft_id, array $inv){
        global $wpdb;
        $wpdb->update(Invoices::table(), array_intersect_key($inv, array_flip(self::RECIPIENT)), ['id' => (int) $draft_id, 'status' => 'draft']);
    }

    private static function same_lines(array $a, array $b){
        $norm = fn($ls) => array_map(fn($l) => [trim((string) $l['description']), Money::round((float) ($l['quantity'] ?? 1) * (float) Money::parse((string) ($l['unit_price'] ?? 0)))], array_values($ls));
        return $norm($a) === $norm($b);
    }

    /**
     * The corrected concepts from the rectify form: each original concept with the text and
     * amount she wrote (as it was when untouched), plus the ones that were missing.
     */
    public static function corrected_lines(array $inv, array $changes, array $added){
        $out = [];
        foreach ( self::lines_of($inv) as $i => $l ) {
            $c = (array) ($changes[$i] ?? []);
            $was = Money::round($l['quantity'] * $l['unit_price']);
            $now = isset($c['amount']) && trim((string) $c['amount']) !== '' ? Money::round((float) Money::parse((string) $c['amount'])) : $was;
            $desc = trim(sanitize_textarea_field((string) ($c['description'] ?? ''))) ?: $l['description'];
            if ( ! $now && $desc === '' ) continue;
            $out[] = $now === $was ? ['description' => $desc, 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']] : ['description' => $desc, 'quantity' => 1, 'unit_price' => $now];
        }
        foreach ( $added as $a ) {
            $desc = trim(sanitize_textarea_field((string) ($a['description'] ?? '')));
            $amt = Money::parse((string) ($a['amount'] ?? ''));
            if ( $desc !== '' && $amt ) $out[] = ['description' => $desc, 'quantity' => 1, 'unit_price' => Money::round((float) $amt)];
        }
        return array_values(array_filter($out, fn($l) => (float) $l['unit_price'] != 0.0));
    }

    const DEFAULT_NEW_REASON = 'Error en los datos de identificación del destinatario. Se anula la factura y se sustituye por una nueva con los datos correctos.';

    public static function forget($id){ self::forget_replacement($id); }

    private static function forget_replacement($id){
        $map = get_option(self::REPLACED, []);
        if ( is_array($map) ) { unset($map[(int) $id]); update_option(self::REPLACED, $map, false); }
    }

    /** Invoices corrected in place («same»): original id => {from: old recipient, lines and totals, by: rectifying id, at}. */
    const CORRECTED = 'madoficina_invoice_corrected';
    const TOTALS = ['base', 'vat', 'irpf', 'total'];

    public static function corrected_of($id){
        $m = get_option(self::CORRECTED, []);
        return is_array($m) && isset($m[(int) $id]) ? $m[(int) $id] : null;
    }

    /** The original as it will look once corrected: its number and date, the rectifying one's recipient and concepts. */
    public static function corrected_preview(array $original, array $rect){
        foreach ( self::RECIPIENT as $k ) $original[$k] = $rect[$k];
        $original['lines'] = $rect['lines'];
        foreach ( self::TOTALS as $k ) $original[$k] = Money::round((float) $original[$k] + (float) $rect[$k]);
        $original['corrected_by'] = $rect;
        return $original;
    }

    /**
     * A substitution rectifying invoice of a «same» correction was issued: the original takes
     * the right details and concepts, keeping its number and date. From then on the original
     * carries the amounts and the rectifying one counts zero (never twice in the books); what
     * it was is kept here, and the PDF goes to Drive again as «(v2)», saying which rectifying
     * invoice corrected it.
     */
    public function apply_correction(array $rect){
        global $wpdb;
        $rep = self::replacement_of($rect['id']);
        if ( ! $rep || empty($rep['same']) || ! $rep['original'] || (int) $rep['rectify']['id'] !== (int) $rect['id'] || self::corrected_of($rep['original']['id']) ) return null;
        $orig = $rep['original'];
        $fixed = self::corrected_preview($orig, $rect);
        $m = get_option(self::CORRECTED, []);
        $m = is_array($m) ? $m : [];
        $m[(int) $orig['id']] = [
            'from' => array_intersect_key($orig, array_flip(self::RECIPIENT)),
            'lines' => self::lines_of($orig), 'totals' => array_intersect_key($orig, array_flip(self::TOTALS)), 'profile_id' => $orig['profile_id'],
            'diff' => array_intersect_key($rect, array_flip(self::TOTALS)),
            'by' => (int) $rect['id'], 'at' => Time::now(),
        ];
        update_option(self::CORRECTED, $m, false);
        $wpdb->update(Invoices::table(), array_intersect_key($fixed, array_flip(array_merge(self::RECIPIENT, self::TOTALS))) + ['profile_id' => $rect['profile_id'], 'pdf_url' => null, 'pdf_file_id' => null, 'updated_at' => Time::now()], ['id' => (int) $orig['id']]);
        self::replace_lines((int) $orig['id'], $rect['lines']);
        $wpdb->update(Invoices::table(), array_fill_keys(self::TOTALS, 0) + ['updated_at' => Time::now()], ['id' => (int) $rect['id']]);
        Orders::queue('libro_upsert', ['rows' => [self::libro_row(Invoices::get($rect['id']))]]);
        $v = get_option(self::UNDONE, []);
        $v = is_array($v) ? $v : [];
        $v[(int) $orig['id']] = ($v[(int) $orig['id']] ?? 0) + 1;
        update_option(self::UNDONE, $v, false);
        $fixed = Invoices::get($orig['id']);
        $this->queue_archive($fixed, null);
        return $fixed;
    }

    /** Undoing the rectifying one: the original takes back what it was, the rectifying one its difference. */
    private static function revert_correction($orig_id){
        global $wpdb;
        $c = self::corrected_of($orig_id);
        if ( ! $c ) return;
        $wpdb->update(Invoices::table(), $c['from'] + ($c['totals'] ?? []) + (isset($c['profile_id']) ? ['profile_id' => $c['profile_id']] : []) + ['pdf_url' => null, 'pdf_file_id' => null, 'updated_at' => Time::now()], ['id' => (int) $orig_id]);
        if ( isset($c['lines']) ) self::replace_lines((int) $orig_id, $c['lines']);
        if ( isset($c['diff']) ) $wpdb->update(Invoices::table(), $c['diff'], ['id' => (int) $c['by']]);
        $m = get_option(self::CORRECTED, []);
        unset($m[(int) $orig_id]);
        update_option(self::CORRECTED, $m, false);
        Orders::queue('libro_upsert', ['rows' => [self::libro_row(Invoices::get((int) $orig_id))]]);
    }

    private static function replace_lines($invoice_id, array $lines){
        global $wpdb;
        $wpdb->delete(Schema::table('invoice_lines'), ['invoice_id' => $invoice_id]);
        foreach ( array_values($lines) as $i => $l ) {
            $qty = (float) ($l['quantity'] ?? 1);
            $wpdb->insert(Schema::table('invoice_lines'), ['invoice_id' => $invoice_id, 'position' => $i, 'description' => (string) $l['description'], 'quantity' => $qty, 'unit_price' => (float) $l['unit_price'], 'amount' => Money::round($qty * (float) $l['unit_price'])]);
        }
    }

    /** The reason she typed on the review page, on a rectifying draft. */
    public static function set_reason($id, $reason){
        global $wpdb;
        $reason = mb_substr(trim(sanitize_text_field((string) $reason)), 0, 500);
        if ( $reason === '' ) return false;
        return (bool) $wpdb->update(Invoices::table(), ['rectify_reason' => $reason, 'updated_at' => Time::now()], ['id' => (int) $id, 'series' => 'R', 'status' => 'draft']);
    }

    const DEFAULT_DETAILS_REASON = 'Error en los datos de identificación del destinatario (razón social, NIF y domicilio). Se sustituyen por los correctos; importes, conceptos y fecha de la operación no cambian.';

    /**
     * [the fiscal profile the invoice was issued to, the one with the client's current details].
     * An invoice imported from the old sheet may not be joined to a profile: found by its NIF
     * (or legal name). If the client changed company, its current details may be another
     * active profile of the same office client.
     * @return array{0:?array, 1:?array}
     */
    public static function profiles_for_new_details(array $inv){
        $original = $inv['profile_id'] ? Profiles::get((int) $inv['profile_id']) : null;
        if ( ! $original ) $original = Profiles::find((string) $inv['client_tax_id'], (string) $inv['client_name']);
        $client_id = $inv['client_id'] ?: ($original['client_id'] ?? null);
        $current = $original;
        if ( $client_id && ( ! $original || ! self::details_differ($inv, $original) ) ) {
            foreach ( Profiles::for_client((int) $client_id) as $p ) if ( $p['status'] === 'active' && self::details_differ($inv, $p) ) { $current = $p; break; }
        }
        return [$original, $current];
    }

    public static function details_differ(array $inv, array $profile){
        $norm = fn($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));
        foreach ( ['client_name' => 'legal_name', 'client_address' => 'address', 'client_city' => 'city'] as $k => $pk ) if ( $norm($inv[$k]) !== $norm($profile[$pk]) ) return true;
        return Profiles::norm_tax_id($inv['client_tax_id']) !== Profiles::norm_tax_id($profile['tax_id']);
    }

    /**
     * An invoice's concepts. One imported from the old sheet may have none saved: then its
     * concepts and amounts from the sheet's row, or a single line with its base.
     * @return array[] description, quantity, unit_price
     */
    public static function lines_of(array $inv){
        if ( ! empty($inv['lines']) ) return array_map(fn($l) => ['description' => $l['description'], 'quantity' => (float) $l['quantity'], 'unit_price' => (float) $l['unit_price']], $inv['lines']);
        $legacy = is_array($inv['legacy'] ?? null) ? $inv['legacy'] : [];
        $base = (float) $inv['base'];
        $out = [];
        foreach ( [1, 2] as $k ) {
            $d = trim((string) ($legacy['concepto_' . $k] ?? ''));
            $v = Money::parse((string) ($legacy['valor_' . $k] ?? ''));
            if ( $d !== '' && $v ) $out[] = ['description' => $d, 'quantity' => 1.0, 'unit_price' => (float) $v];
        }
        if ( $out && abs(array_sum(array_column($out, 'unit_price')) - $base) < 0.01 ) return $out;
        if ( ! $base ) return [];
        $concepts = array_filter([trim((string) ($legacy['concepto_1'] ?? $legacy['concepto'] ?? '')), trim((string) ($legacy['concepto_2'] ?? ''))]);
        return [['description' => $concepts ? implode(' · ', $concepts) : sprintf(__('Servicios de la factura %s', 'mad-oficina'), $inv['number']), 'quantity' => 1.0, 'unit_price' => $base]];
    }

    /** VAT and IRPF %, from the invoice (or from its amounts when an import left them at 0). @return float[] */
    public static function rates_of(array $inv){
        $base = (float) $inv['base'];
        $vat = (float) $inv['vat_rate'] ?: ($base ? round((float) $inv['vat'] / $base * 100, 2) : 0.0);
        $irpf = (float) $inv['irpf_rate'] ?: ($base ? round((float) $inv['irpf'] / $base * 100, 2) : 0.0);
        return [$vat, $irpf];
    }

    /** Which invoices a «Rehacer con los datos nuevos» links: original id => rectify, new. */
    const REPLACED = 'madoficina_invoice_replaced';

    /**
     * The three invoices of a change of fiscal details, seen from any of them.
     * @return array{original:?array, rectify:?array, new:?array}|null
     */
    public static function replacement_of($id){
        $id = (int) $id;
        $map = get_option(self::REPLACED, []);
        foreach ( is_array($map) ? $map : [] as $orig => $pair ) {
            if ( in_array($id, array_filter([(int) $orig, (int) $pair['rectify'], (int) $pair['new']]), true) ) {
                return ['original' => Invoices::get((int) $orig), 'rectify' => Invoices::get((int) $pair['rectify']), 'new' => $pair['new'] ? Invoices::get((int) $pair['new']) : null,
                    'same' => ! $pair['new'] && ($pair['same'] ?? true), 'details' => (bool) ($pair['details'] ?? true)];
            }
        }
        return null;
    }

    /** A rectifying draft (series R) that cancels the original: same lines, negative. */
    public function rectify($id, $notes = '', $date = null, $profile_id = 0, $reason = '', $draft_id = 0){
        $inv = Invoices::get($id);
        if ( ! $inv || ! $inv['number'] || $inv['series'] !== 'F' ) return new \WP_Error('madof_inv_rect', __('Solo se rectifican facturas emitidas de la serie F.', 'mad-oficina'));
        $lines = array_map(fn($l) => ['description' => sprintf(__('Rectifica %1$s: %2$s', 'mad-oficina'), $inv['number'], $l['description']), 'quantity' => $l['quantity'], 'unit_price' => -1 * $l['unit_price']], self::lines_of($inv));
        if ( ! $lines ) return new \WP_Error('madof_inv_lines', __('Esta factura no tiene conceptos ni base imponible guardados: no sé qué rectificar.', 'mad-oficina'));
        if ( ! $profile_id ) $profile_id = (int) $inv['profile_id'] ?: (int) (self::profiles_for_new_details($inv)[0]['id'] ?? 0);
        [$vat, $irpf] = self::rates_of($inv);
        return $this->save_draft([
            'profile_id' => $profile_id, 'series' => 'R', 'rectifies_id' => $inv['id'],
            'issue_date' => $date ?: current_time('Y-m-d'), 'vat_rate' => $vat, 'irpf_rate' => $irpf, 'notes' => $notes, 'rectify_reason' => $reason, 'rectify_mode' => 'difference',
        ], $lines, $draft_id && (int) (Invoices::get($draft_id)['rectifies_id'] ?? 0) === (int) $id ? (int) $draft_id : 0);
    }

    /**
     * A rectifying draft for part of an invoice: Jessica writes what each concept should have
     * been (amount and text) and what was missing; the draft carries only the differences.
     * @param array $changes [index => {description, amount}] for the original lines, in order
     * @param array $added   [{description, amount}] concepts that were missing
     * @return int|\WP_Error draft id
     */
    public function rectify_lines($id, array $changes, array $added, $reason, $date = null, $draft_id = 0){
        $inv = Invoices::get($id);
        if ( ! $inv || ! $inv['number'] || $inv['series'] !== 'F' ) return new \WP_Error('madof_inv_rect', __('Solo se rectifican facturas emitidas de la serie F.', 'mad-oficina'));
        $reason = trim(sanitize_text_field((string) $reason));
        if ( $reason === '' ) return new \WP_Error('madof_inv_reason', __('Escribe el motivo de la rectificación: va impreso en la factura.', 'mad-oficina'));
        $old = self::lines_of($inv);
        if ( ! $old ) return new \WP_Error('madof_inv_lines', __('Esta factura no tiene conceptos ni base imponible guardados: no sé qué rectificar.', 'mad-oficina'));
        $n = $inv['number'];
        $lines = [];
        foreach ( $old as $i => $l ) {
            $was = Money::round($l['quantity'] * $l['unit_price']);
            $c = (array) ($changes[$i] ?? []);
            $now = isset($c['amount']) && trim((string) $c['amount']) !== '' ? Money::round((float) Money::parse((string) $c['amount'])) : $was;
            $desc = trim(sanitize_textarea_field((string) ($c['description'] ?? ''))) ?: $l['description'];
            if ( $desc !== $l['description'] ) {
                // A different concept: the old one out, the right one in.
                $lines[] = ['description' => sprintf(__('Anula %1$s: %2$s', 'mad-oficina'), $n, $l['description']), 'quantity' => 1, 'unit_price' => -$was];
                if ( $now ) $lines[] = ['description' => $desc, 'quantity' => 1, 'unit_price' => $now];
            } elseif ( $now !== $was ) {
                $lines[] = ['description' => sprintf(__('Rectifica %1$s: %2$s (antes %3$s, ahora %4$s)', 'mad-oficina'), $n, $desc, Money::format($was), Money::format($now)), 'quantity' => 1, 'unit_price' => Money::round($now - $was)];
            }
        }
        foreach ( $added as $a ) {
            $desc = trim(sanitize_textarea_field((string) ($a['description'] ?? '')));
            $amt = Money::parse((string) ($a['amount'] ?? ''));
            if ( $desc !== '' && $amt ) $lines[] = ['description' => $desc, 'quantity' => 1, 'unit_price' => Money::round((float) $amt)];
        }
        if ( ! $lines ) return new \WP_Error('madof_inv_same', __('No has cambiado nada: escribe el importe o el texto correcto de algún concepto, o añade uno.', 'mad-oficina'));
        [$vat, $irpf] = self::rates_of($inv);
        $last = $this->last_issued('R', (int) current_time('Y'));
        $date = self::valid_date((string) $date) ?: max(current_time('Y-m-d'), (string) $inv['issue_date'], $last ? (string) $last['issue_date'] : '');
        return $this->save_draft([
            'profile_id' => (int) $inv['profile_id'] ?: (int) (self::profiles_for_new_details($inv)[0]['id'] ?? 0), 'series' => 'R', 'rectifies_id' => $inv['id'],
            'issue_date' => $date, 'vat_rate' => $vat, 'irpf_rate' => $irpf, 'rectify_reason' => $reason, 'rectify_mode' => 'difference',
        ], $lines, $draft_id && (int) (Invoices::get($draft_id)['rectifies_id'] ?? 0) === (int) $id ? (int) $draft_id : 0);
    }

    /**
     * «Emitir y enviar» from the review page, without the inbox: each draft is checked against
     * the number she saw (if another invoice took it meanwhile, nothing goes out), then approved
     * as she would in the inbox. A rectifying one goes before the invoice that replaces it.
     * @param array $items [invoice id => {expected, send, to, subject, body, issue_date}]
     * @return array{done: string[], error: ?string}
     */
    public function send_now(array $items, $user_id = 0, array $corrected = []){
        $invs = [];
        foreach ( $items as $id => $in ) {
            $inv = Invoices::get((int) $id);
            if ( ! $inv || $inv['status'] !== 'draft' || $inv['number'] ) return ['done' => [], 'error' => __('Una de las facturas ya no es un borrador: vuelve a abrirla para ver cómo ha quedado.', 'mad-oficina')];
            $invs[] = [$inv, (array) $in];
        }
        usort($invs, fn($a, $b) => ($b[0]['series'] === 'R') <=> ($a[0]['series'] === 'R'));
        // All numbers checked before anything is issued.
        foreach ( $invs as [$inv, $in] ) {
            if ( $inv['series'] === 'R' && trim((string) $inv['rectify_reason']) === '' ) return ['done' => [], 'error' => __('Falta el motivo de la rectificativa: va impreso en ella.', 'mad-oficina')];
            $date = self::valid_date($in['issue_date'] ?? '') ?: $inv['issue_date'];
            $will = Invoices::preview_number($inv, $date);
            if ( $will !== (string) ($in['expected'] ?? '') ) {
                return ['done' => [], 'error' => sprintf(__('El número ha cambiado: ahora le tocaría %1$s (antes te enseñaba %2$s). Revísalo y vuelve a pulsar.', 'mad-oficina'), $will ?: '—', $in['expected'] ?? '—')];
            }
        }
        $done = [];
        foreach ( $invs as [$inv, $in] ) {
            $approval = $this->request($inv['id']);
            if ( is_wp_error($approval) ) return ['done' => $done, 'error' => $approval->get_error_message()];
            $input = ['send' => ! empty($in['send']) ? '1' : '', 'to' => (string) ($in['to'] ?? ''), 'subject' => (string) ($in['subject'] ?? ''), 'body' => (string) ($in['body'] ?? ''), 'issue_date' => (string) ($in['issue_date'] ?? '')];
            $r = Approvals::resolve((int) $approval, 'approve', $input, $user_id, 'web');
            if ( is_wp_error($r) ) return ['done' => $done, 'error' => $r->get_error_message()];
            $done[] = (string) $r['message'];
        }
        // The original corrected in place (same number and date) goes to the client too, if she said so.
        foreach ( $corrected as $orig_id => $in ) {
            $c = self::corrected_of($orig_id);
            if ( ! $c || empty($in['send']) ) continue;
            $q = $this->queue_resend((int) $orig_id, (array) $in);
            $o = Invoices::get((int) $orig_id);
            $done[] = is_wp_error($q) ? $q->get_error_message() : sprintf(__('Factura %1$s corregida (mismo número y fecha): el cartero la guarda y la envía a %2$s en unos minutos.', 'mad-oficina'), $o['number'], sanitize_text_field($in['to'] ?? ''));
        }
        return ['done' => $done, 'error' => null];
    }

    /* ==== Approval ==== */

    /** @return int|\WP_Error approval id */
    public function request($id){
        $inv = Invoices::get($id);
        if ( ! $inv || $inv['status'] !== 'draft' ) return new \WP_Error('madof_inv', __('Solo se pueden emitir borradores.', 'mad-oficina'));
        if ( $inv['series'] === 'AR' ) return new \WP_Error('madof_inv_series', __('Las facturas de Argentina se emiten en la plataforma argentina. Márcala como emitida allí.', 'mad-oficina'));
        // (a rectifying one may come to zero when it only swaps a concept for another)
        if ( ! $inv['lines'] || ( (float) $inv['total'] == 0.0 && $inv['series'] !== 'R' ) ) return new \WP_Error('madof_inv_lines', __('La factura no tiene importe.', 'mad-oficina'));
        if ( ! $inv['client_tax_id'] ) return new \WP_Error('madof_inv_tax', __('Falta el NIF del cliente. Complétalo en sus datos fiscales y vuelve a guardar el borrador.', 'mad-oficina'));
        foreach ( Approvals::for_object('invoice', $id) as $a ) {
            if ( in_array($a['status'], [Approvals::PENDING, Approvals::PROCESSING], true) ) return (int) $a['id'];
        }
        return Approvals::create('accountant', self::ACTION,
            sprintf(__('¿Emito la factura de %1$s por %2$s?', 'mad-oficina'), $inv['client_name'], Money::format($inv['total'])),
            ['invoice_id' => (int) $id],
            [
                'client_id' => $inv['client_id'], 'object_type' => 'invoice', 'object_id' => $id,
                'summary' => implode("\n", array_map(fn($l) => '· ' . wp_trim_words($l['description'], 14) . ' — ' . Money::format($l['amount']), $inv['lines'])),
            ]);
    }

    /** What the Chat button «Aprobar» uses: send by email with the default text. */
    public function default_input(array $approval){
        $inv = Invoices::get((int) ($approval['payload']['invoice_id'] ?? 0));
        if ( ! $inv ) return [];
        $preview = $this->email_for($inv, $inv['issue_date'], __('(número al emitir)', 'mad-oficina'));
        return ['send' => $preview['to'] ? '1' : '', 'issue_date' => $inv['issue_date'], 'to' => $preview['to'], 'subject' => '', 'body' => ''];
    }

    /** @return array{to:string, subject:string, body:string} */
    public function email_for(array $inv, $issue_date, $number){
        $due = $inv['due_date'] ?: $this->due_date($issue_date, Profiles::get((int) $inv['profile_id']));
        $vars = [
            '{numero}' => $number, '{cliente}' => $inv['client_name'], '{total}' => Money::format($inv['total']),
            '{vencimiento}' => (int) substr($due, 8, 2) . ' de ' . self::month($due), '{mes}' => self::month($issue_date),
        ];
        $to = '';
        foreach ( preg_split('/[\s,;]+/', (string) $inv['client_email'], -1, PREG_SPLIT_NO_EMPTY) as $e ) if ( is_email($e) ) { $to = strtolower($e); break; }
        // A rectifying one says what it rectifies and why, not an amount to pay.
        if ( $inv['series'] === 'R' && ($orig = Invoices::get((int) $inv['rectifies_id'])) ) {
            $vars += ['{rectifica}' => $orig['number'] . ' ' . sprintf(__('del %s', 'mad-oficina'), date_i18n('d/m/Y', strtotime($orig['issue_date']))), '{motivo}' => (string) $inv['rectify_reason']];
            return ['to' => $to, 'subject' => strtr(self::RECTIFY_SUBJECT, $vars), 'body' => strtr(self::RECTIFY_BODY, $vars)];
        }
        return [
            'to' => $to,
            'subject' => strtr(trim((string) ($this->settings['email_subject'] ?? '')) ?: self::DEFAULT_SUBJECT, $vars),
            'body' => strtr(trim((string) ($this->settings['email_body'] ?? '')) ?: self::DEFAULT_BODY, $vars),
        ];
    }

    /**
     * Approval handler: number, issuer snapshot, due date, then the cartero's order.
     * @return array|\WP_Error
     */
    public function approve(array $approval, array $input){
        $id = (int) ($approval['payload']['invoice_id'] ?? 0);
        $inv = Invoices::get($id);
        if ( ! $inv ) return new \WP_Error('madof_inv', __('Esa factura ya no existe.', 'mad-oficina'));
        if ( $inv['number'] ) return ['message' => sprintf(__('La factura ya estaba emitida (%s).', 'mad-oficina'), $inv['number'])];

        $date = self::valid_date($input['issue_date'] ?? '') ?: $inv['issue_date'];
        $last = $this->last_issued($inv['series'], (int) substr($date, 0, 4));
        if ( $last && $last['issue_date'] > $date ) {
            return new \WP_Error('madof_inv_date', sprintf(__('La fecha (%1$s) es anterior a la última factura emitida, %2$s del %3$s. Las facturas tienen que ir en orden: elige una fecha igual o posterior.', 'mad-oficina'), date_i18n('j/m/Y', strtotime($date)), $last['number'], date_i18n('j/m/Y', strtotime($last['issue_date']))));
        }

        $send = ! empty($input['send']);
        $email = null;
        if ( $send ) {
            $to = [];
            foreach ( preg_split('/[\s,;]+/', (string) ($input['to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e ) if ( is_email($e) ) $to[] = strtolower($e);
            if ( ! $to ) return new \WP_Error('madof_inv_to', __('Escribe el email del cliente o elige «Solo emitir».', 'mad-oficina'));
        }

        $issued = Invoices::assign_number($id, $date);
        if ( is_wp_error($issued) ) return $issued;

        global $wpdb;
        $profile = Profiles::get((int) $issued['profile_id']);
        $due = $inv['due_date'] && $inv['issue_date'] === $date ? $inv['due_date'] : $this->due_date($date, $profile);
        $wpdb->update(Invoices::table(), [
            'due_date' => $due, 'issued_at' => Time::now(), 'issuer' => wp_json_encode(self::issuer_snapshot($this->settings)), 'updated_at' => Time::now(),
        ], ['id' => $id]);
        $issued = Invoices::get($id);

        if ( $send ) {
            $defaults = $this->email_for($issued, $date, $issued['number']);
            $n = ['{numero}' => $issued['number']]; // the inbox form shows the placeholder: the number did not exist yet
            $email = [
                'to' => implode(',', $to),
                'subject' => strtr(sanitize_text_field($input['subject'] ?? '') ?: $defaults['subject'], $n),
                'body' => strtr(sanitize_textarea_field($input['body'] ?? '') ?: $defaults['body'], $n),
            ];
        }
        $this->queue_archive($issued, $email);
        if ( $issued['series'] === 'R' && $issued['rectify_mode'] === 'substitution' ) $this->apply_correction($issued);

        return ['message' => $send
            ? sprintf(__('Factura %1$s emitida. El cartero la archiva y la envía a %2$s en unos minutos.', 'mad-oficina'), $issued['number'], $email['to'])
            : sprintf(__('Factura %s emitida. El cartero la archiva en Emitidas; no se envía.', 'mad-oficina'), $issued['number']),
            'number' => $issued['number']];
    }

    private function last_issued($series, $year){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT number, issue_date FROM ' . Invoices::table() . ' WHERE series = %s AND year = %d AND number IS NOT NULL ORDER BY seq DESC LIMIT 1', $series, $year), ARRAY_A);
    }

    /** The issuer data printed on the invoice, frozen at issue time. */
    public static function issuer_snapshot(array $settings){
        $keys = ['issuer_name', 'issuer_tagline', 'issuer_tax_label', 'issuer_tax_id', 'issuer_address', 'issuer_city', 'issuer_email', 'issuer_phone', 'payment_method', 'issuer_bank', 'issuer_iban', 'payment_terms'];
        return array_intersect_key($settings, array_flip($keys));
    }

    /** Issuer for a PDF: the frozen copy for issued invoices, the current settings for drafts. */
    public static function issuer_for(array $inv, array $settings){
        $frozen = $inv['issuer'] ? json_decode($inv['issuer'], true) : null;
        // (a copy frozen without the issuer's name is no copy: the current details instead)
        return is_array($frozen) && ! empty($frozen['issuer_name']) ? $frozen : self::issuer_snapshot($settings);
    }

    /* ==== Cartero orders ==== */

    public static function quarter($date){
        return 'T' . (int) ceil((int) substr($date, 5, 2) / 3);
    }

    public function queue_archive(array $inv, ?array $email){
        if ( $email && ($copy = \MAD_Oficina\Core\ClientCopy::for_invoice($inv)) && stripos($email['to'], $copy) === false ) $email['bcc'] = $copy;
        if ( $email && self::times_undone((int) $inv['id']) ) {
            // The cartero sends each invoice once: an invoice issued again goes as a resend, after the archive.
            $archived = Orders::queue('invoice_archive', ['invoice_id' => (int) $inv['id'], 'number' => $inv['number'], 'filename' => InvoicePdf::filename($inv),
                'folder' => [(string) $inv['year'], self::quarter($inv['issue_date'])], 'email' => null, 'row' => self::libro_row($inv)], $inv['client_id']);
            Orders::queue(Collections::ACTION, ['key' => 'reissue-' . (int) $inv['id'] . '-' . wp_generate_password(8, false), 'invoice_id' => (int) $inv['id'], 'resend' => true, 'email' => $email], $inv['client_id']);
            return $archived;
        }
        return Orders::queue('invoice_archive', [
            'invoice_id' => (int) $inv['id'],
            'number' => $inv['number'],
            'filename' => InvoicePdf::filename($inv),
            'folder' => [(string) $inv['year'], self::quarter($inv['issue_date'])],
            'email' => $email,
            'row' => self::libro_row($inv),
        ], $inv['client_id']);
    }

    /**
     * «Reenviar»: an issued invoice by email again (or for the first time if it was only issued),
     * with its PDF. The cartero sends it as it sends payment reminders: once per press.
     */
    public function queue_resend($id, array $input){
        $inv = Invoices::get($id);
        if ( ! $inv || ! $inv['number'] || $inv['series'] === 'AR' ) return new \WP_Error('madof_inv', __('Solo se reenvían facturas emitidas.', 'mad-oficina'));
        $to = [];
        foreach ( preg_split('/[\s,;]+/', (string) ($input['to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e ) if ( is_email($e) ) $to[] = strtolower($e);
        if ( ! $to ) return new \WP_Error('madof_inv_to', __('Escribe a qué email la reenvío.', 'mad-oficina'));
        $defaults = $this->email_for($inv, $inv['issue_date'], $inv['number']);
        $email = [
            'to' => implode(',', $to),
            'subject' => sanitize_text_field($input['subject'] ?? '') ?: $defaults['subject'],
            'body' => sanitize_textarea_field($input['body'] ?? '') ?: $defaults['body'],
        ];
        if ( ($copy = \MAD_Oficina\Core\ClientCopy::for_invoice($inv)) && stripos($email['to'], $copy) === false ) $email['bcc'] = $copy;
        return Orders::queue(Collections::ACTION, ['key' => 'resend-' . (int) $id . '-' . wp_generate_password(8, false), 'invoice_id' => (int) $id, 'resend' => true, 'email' => $email], $inv['client_id']);
    }

    /** How many times an invoice's issue was undone (its later PDFs carry «(v2)», «(v3)»…). */
    const UNDONE = 'madoficina_invoice_undone';

    public static function times_undone($id){
        $m = get_option(self::UNDONE, []);
        return is_array($m) ? (int) ($m[(int) $id] ?? 0) : 0;
    }

    /**
     * «Deshacer la emisión»: an invoice issued by mistake back to draft, only while it is the
     * last one of its series (so no number is ever left missing) and not collected. The same
     * number goes to the corrected one. What the cartero had not done yet is cancelled; what
     * it did (the PDF in Drive, an email) stays, and Jessica is told.
     * @return array{message:string, draft:int}|\WP_Error
     */
    public static function undo_issue($id){
        global $wpdb;
        $inv = Invoices::get($id);
        if ( ! $inv || ! $inv['number'] || ! in_array($inv['series'], Invoices::FISCAL, true) ) return new \WP_Error('madof_inv', __('Solo se deshace la emisión de facturas emitidas de las series F y R.', 'mad-oficina'));
        if ( $inv['paid'] ) return new \WP_Error('madof_inv_paid', __('Está marcada como cobrada: desmárcala primero o haz una rectificativa.', 'mad-oficina'));
        $last = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(seq) FROM ' . Invoices::table() . ' WHERE series = %s AND year = %d AND number IS NOT NULL', $inv['series'], $inv['year']));
        if ( (int) $inv['seq'] !== $last ) return new \WP_Error('madof_inv_not_last', sprintf(__('Ya hay facturas posteriores en la serie %s: deshacerla dejaría un hueco en la numeración. Corrígela con una rectificativa.', 'mad-oficina'), $inv['series']));
        if ( $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Invoices::table() . ' WHERE rectifies_id = %d AND number IS NOT NULL', $id)) ) return new \WP_Error('madof_inv_rectified', __('Ya tiene una rectificativa: corrígela desde ahí.', 'mad-oficina'));
        $was_sent = $inv['status'] === 'sent' || $inv['sent_at'];
        // A rectifying one that had corrected its original in place: the original takes back what it was.
        if ( $inv['series'] === 'R' && ($c = self::corrected_of((int) $inv['rectifies_id'])) && (int) $c['by'] === (int) $id ) self::revert_correction((int) $inv['rectifies_id']);
        $number = $inv['number'];
        $wpdb->update(Invoices::table(), [
            'number' => null, 'seq' => null, 'status' => 'draft', 'issued_at' => null, 'sent_at' => null, 'issuer' => null,
            'pdf_url' => null, 'pdf_file_id' => null, 'updated_at' => Time::now(),
        ], ['id' => (int) $id, 'number' => $number]);
        // Orders the cartero has not picked up yet: not any more.
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('tasks') . " SET status = 'cancelled', updated_at = %s WHERE agent = %s AND status = 'queued' AND type IN ('invoice_archive', %s) AND payload LIKE %s",
            Time::now(), Orders::AGENT, Collections::ACTION, '%"invoice_id":' . (int) $id . ',%'));
        $m = get_option(self::UNDONE, []);
        $m = is_array($m) ? $m : [];
        $m[(int) $id] = ($m[(int) $id] ?? 0) + 1;
        update_option(self::UNDONE, $m, false);
        // Its row in «Libro MAD» says so until it is issued again.
        $row = self::libro_row(Invoices::get($id));
        $row['Estado'] = sprintf(__('Emisión deshecha (era %s)', 'mad-oficina'), $number);
        Orders::queue('libro_upsert', ['rows' => [$row]]);
        $wpdb->insert(Schema::table('agent_log'), ['agent' => 'accountant', 'event' => 'invoice_undo', 'client_id' => $inv['client_id'], 'data' => wp_json_encode(['invoice_id' => (int) $id, 'number' => $number, 'was_sent' => (bool) $was_sent, 'user' => get_current_user_id()]), 'created_at' => Time::now()]);
        return ['draft' => (int) $id, 'message' => sprintf(__('Emisión deshecha: vuelve a ser un borrador y, al emitirla otra vez, tomará el mismo número %s.', 'mad-oficina'), $number)
            . ' ' . ($was_sent ? __('Ya se había enviado: avisa al cliente de que descarte la que le llegó.', 'mad-oficina') : '')
            . ' ' . __('Si el cartero ya la había guardado en Drive, borra ese PDF de Emitidas; la nueva se guarda como «(v2)».', 'mad-oficina')];
    }

    /** «Enviar prueba a mí»: the draft PDF (with its watermark) to Jessica only. */
    public function queue_test($id, $to){
        $inv = Invoices::get($id);
        if ( ! $inv || ! is_email($to) ) return new \WP_Error('madof_inv', __('No puedo preparar la prueba.', 'mad-oficina'));
        $mail = $this->email_for($inv, $inv['issue_date'], __('BORRADOR', 'mad-oficina'));
        return Orders::queue('invoice_test', [
            'invoice_id' => (int) $id, 'filename' => InvoicePdf::filename($inv),
            'email' => ['to' => $to, 'subject' => '[Prueba] ' . $mail['subject'], 'body' => __("Esto es una prueba: así le llegaría la factura al cliente (a ti te llega con la marca BORRADOR).\nPara: ", 'mad-oficina') . ($mail['to'] ?: '—') . "\n\n" . $mail['body']],
        ]);
    }

    /** Payment status or any later change → the cartero updates its row in «Libro MAD». */
    public static function queue_libro($ids){
        $rows = [];
        foreach ( (array) $ids as $id ) { $inv = Invoices::get($id); if ( $inv && ($inv['number'] || $inv['series'] === 'AR') ) $rows[] = self::libro_row($inv); }
        return $rows ? Orders::queue('libro_upsert', ['rows' => $rows]) : 0;
    }

    /** Whole book (issued invoices of every year) in one go. */
    public static function queue_libro_sync(){
        global $wpdb;
        $ids = $wpdb->get_col('SELECT id FROM ' . Invoices::table() . " WHERE status IN ('issued','sent') ORDER BY issue_date, id");
        $rows = [];
        foreach ( $ids as $id ) $rows[] = self::libro_row(Invoices::get($id));
        return Orders::queue('libro_sync', ['rows' => $rows]);
    }

    /** One row of the «Emitidas» tab of «Libro MAD»; the last column is the key. */
    public static function libro_row(array $inv){
        $client = $inv['client_id'] ? Clients::get($inv['client_id']) : null;
        return [
            'Número' => $inv['number'] ?: ($inv['series'] === 'AR' ? 'AR' : ''),
            'Fecha' => $inv['issue_date'],
            'Vencimiento' => $inv['due_date'] ?: '',
            'Cliente' => $inv['client_name'],
            'NIF' => (string) $inv['client_tax_id'],
            'Código' => $client['code'] ?? '',
            'Concepto' => wp_trim_words(implode(' · ', array_column($inv['lines'], 'description')), 30),
            'Base' => $inv['base'], 'IVA %' => $inv['vat_rate'], 'IVA' => $inv['vat'],
            'IRPF %' => $inv['irpf_rate'], 'IRPF' => $inv['irpf'], 'Total' => $inv['total'],
            'Serie' => $inv['series'],
            'Estado' => __(Invoices::STATUSES[$inv['status']] ?? $inv['status'], 'mad-oficina'),
            'Cobrada' => $inv['paid'] ? 'Sí' : 'No',
            'Fecha de cobro' => $inv['paid_at'] ? substr($inv['paid_at'], 0, 10) : '',
            'PDF' => (string) $inv['pdf_url'],
            'ID' => (int) $inv['id'],
        ];
    }

    /** The cartero finished an order. */
    public static function order_done($type, array $payload, $ok, array $result){
        global $wpdb;
        if ( $ok && $type === Collections::ACTION && ! empty($payload['resend']) && ! empty($result['sent']) ) {
            $wpdb->update(Invoices::table(), ['status' => 'sent', 'sent_at' => Time::now(), 'updated_at' => Time::now()], ['id' => (int) $payload['invoice_id'], 'status' => 'issued']);
            return;
        }
        if ( ! $ok || $type !== 'invoice_archive' ) return;
        $fields = ['updated_at' => Time::now()];
        if ( ! empty($result['file_id']) ) $fields['pdf_file_id'] = preg_replace('/[^A-Za-z0-9_\-]/', '', $result['file_id']);
        if ( ! empty($result['url']) && preg_match('#^https://(drive|docs)\.google\.com/#', $result['url']) ) $fields['pdf_url'] = esc_url_raw($result['url']);
        if ( ! empty($result['sent']) ) { $fields['status'] = 'sent'; $fields['sent_at'] = Time::now(); }
        $wpdb->update(Invoices::table(), $fields, ['id' => (int) $payload['invoice_id']]);
    }

    /** Spanish month name whatever the site language (the email is in Spanish). */
    public static function month($date){
        $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $m[(int) substr((string) $date, 5, 2) - 1] ?? '';
    }

    private static function valid_date($d){
        $d = (string) $d;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : null;
    }

    /* ==== Inbox form ==== */

    public function render_form($html, array $approval){
        $inv = Invoices::get((int) ($approval['payload']['invoice_id'] ?? 0));
        if ( ! $inv ) return '<p class="madof-warn">' . esc_html__('El borrador ya no existe. Descarta esta petición.', 'mad-oficina') . '</p>';
        $d = $this->default_input($approval);
        $mail = $this->email_for($inv, $inv['issue_date'], '{numero}');
        $pdf = wp_nonce_url(admin_url('admin-post.php?action=madof_billing_pdf&invoice=' . $inv['id']), 'madof_billing_pdf_' . $inv['id']);
        $will = Invoices::preview_number($inv);
        ob_start(); ?>
        <?php if ( $will ) : ?><p class="madof-next-number"><span class="madof-kicker"><?php esc_html_e('Número', 'mad-oficina'); ?></span> <?php echo esc_html(sprintf(__('Si la apruebas ahora será la %1$s %2$s.', 'mad-oficina'), $inv['series'] === 'R' ? __('rectificativa', 'mad-oficina') : __('factura', 'mad-oficina'), $will)); ?></p><?php endif; ?>
        <details class="madof-more" open><summary><?php esc_html_e('Cómo queda', 'mad-oficina'); ?></summary><?php echo InvoicePdf::preview_frame($inv, self::issuer_for($inv, $this->settings)); // escaped inside ?></details>
        <p><strong><?php echo esc_html($inv['client_name']); ?></strong> · <?php echo esc_html((string) $inv['client_tax_id']); ?> · <?php esc_html_e('Total', 'mad-oficina'); ?> <strong><?php echo esc_html(Money::format($inv['total'])); ?></strong>
            · <a href="<?php echo esc_url($pdf); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ver el PDF', 'mad-oficina'); ?></a>
            · <a href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-facturacion&edit=' . $inv['id'])); ?>"><?php esc_html_e('Editar el borrador', 'mad-oficina'); ?></a></p>
        <div class="madof-cols">
            <label><?php esc_html_e('Fecha de la factura', 'mad-oficina'); ?><input type="date" name="input[issue_date]" value="<?php echo esc_attr($d['issue_date']); ?>"></label>
            <label><?php esc_html_e('Para', 'mad-oficina'); ?><input type="text" name="input[to]" value="<?php echo esc_attr($d['to']); ?>"></label>
        </div>
        <label><?php esc_html_e('Asunto', 'mad-oficina'); ?><input type="text" name="input[subject]" value="<?php echo esc_attr($mail['subject']); ?>"></label>
        <label><?php esc_html_e('Mensaje', 'mad-oficina'); ?><textarea name="input[body]" rows="7"><?php echo esc_textarea($mail['body']); ?></textarea>
            <small><?php esc_html_e('{numero} se cambia por el número que le toque al emitir.', 'mad-oficina'); ?></small></label>
        <label class="madof-check"><input type="radio" name="input[send]" value="1" <?php checked($d['send'], '1'); ?>> <?php esc_html_e('Emitir y enviar desde tu Gmail', 'mad-oficina'); ?></label>
        <label class="madof-check"><input type="radio" name="input[send]" value="" <?php checked($d['send'], ''); ?>> <?php esc_html_e('Solo emitir (la envío yo)', 'mad-oficina'); ?></label>
        <p class="description"><?php esc_html_e('Al aprobar le doy el siguiente número, la guardo en Emitidas de la unidad compartida, la apunto en el Libro MAD y, si lo has elegido, la envío desde tu Gmail con el PDF.', 'mad-oficina'); ?></p>
        <?php
        return ob_get_clean();
    }
}
