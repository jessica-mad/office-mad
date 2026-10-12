<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/** Fiscal data of whoever is invoiced (one portal client may have one). */
final class Profiles {

    /** Suggestion score from which linking needs no second look (same name, a contact's email…). */
    const SURE = 6;

    public static function table(){ return Schema::table('billing_profiles'); }

    /** «ES-B21507181», «15456125-X» → «B21507181», «15456125X». */
    public static function norm_tax_id($id){
        $id = strtoupper(preg_replace('/[\s.\-]/', '', (string) $id));
        return preg_replace('/^ES(?=[A-Z0-9]{9}$)/', '', $id);
    }

    public static function get($id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    public static function all($status = ''){
        global $wpdb;
        $sql = 'SELECT p.*, (SELECT MAX(issue_date) FROM ' . Schema::table('invoices') . ' i WHERE i.profile_id = p.id) AS last_invoice FROM ' . self::table() . ' p';
        if ( $status ) $sql .= $wpdb->prepare(' WHERE p.status = %s', $status);
        $sql .= ' ORDER BY last_invoice DESC, p.legal_name ASC';
        return array_map([self::class, 'hydrate'], $wpdb->get_results($sql, ARRAY_A));
    }

    public static function find($tax_id, $name){
        global $wpdb;
        $t = self::table();
        $tax = self::norm_tax_id($tax_id);
        if ( $tax !== '' ) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE tax_id = %s ORDER BY id LIMIT 1", $tax), ARRAY_A);
            if ( $row ) return self::hydrate($row);
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE LOWER(legal_name) = %s ORDER BY id LIMIT 1", mb_strtolower(trim((string) $name))), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /** @return int profile id */
    public static function save(array $data, $id = 0){
        global $wpdb;
        $row = [
            'client_id'  => $data['client_id'] ?? null,
            'legal_name' => mb_substr(sanitize_text_field($data['legal_name'] ?? ''), 0, 190),
            'tax_id'     => self::norm_tax_id($data['tax_id'] ?? '') ?: null,
            'address'    => mb_substr(sanitize_text_field($data['address'] ?? ''), 0, 255) ?: null,
            'city'       => mb_substr(sanitize_text_field($data['city'] ?? ''), 0, 190) ?: null,
            'country'    => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $data['country'] ?? 'ES'), 0, 2)) ?: 'ES',
            'email'      => mb_substr(sanitize_text_field($data['email'] ?? ''), 0, 255) ?: null,
            'vat_rate'   => (float) ($data['vat_rate'] ?? 21),
            'irpf_rate'  => (float) ($data['irpf_rate'] ?? 15),
            'series'     => isset(Invoices::SERIES[$data['series'] ?? '']) ? $data['series'] : 'F',
            'status'     => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? ($data['status'] ?? 'active') : 'active',
            'notes'      => sanitize_textarea_field($data['notes'] ?? '') ?: null,
            'updated_at' => Time::now(),
        ];
        if ( $id ) {
            $wpdb->update(self::table(), $row, ['id' => $id]);
            return (int) $id;
        }
        $wpdb->insert(self::table(), $row + ['created_at' => Time::now()]);
        return (int) $wpdb->insert_id;
    }

    /** Fiscal profiles of an office client (usually one; a client may invoice through several companies). */
    public static function for_client($client_id){
        global $wpdb;
        // Current ones first; a client that changed company keeps its previous legal name as «inactive».
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE client_id = %d ORDER BY status = 'active' DESC, legal_name", $client_id), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** The legal name new invoices of a client go to (null when it has none active). */
    public static function current_for_client($client_id){
        foreach ( self::for_client($client_id) as $p ) if ( $p['status'] === 'active' ) return $p;
        return null;
    }

    public static function set_status($profile_id, $status){
        global $wpdb;
        return $wpdb->update(self::table(), ['status' => $status === 'inactive' ? 'inactive' : 'active', 'updated_at' => Time::now()], ['id' => $profile_id]);
    }

    /** Fiscal profiles not yet joined to an office client (Argentina series included: they are clients too). */
    public static function unlinked(){
        global $wpdb;
        return array_map([self::class, 'hydrate'], $wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE client_id IS NULL ORDER BY legal_name', ARRAY_A));
    }

    /**
     * Joins a fiscal profile to an office client (or unlinks it with null). Its
     * invoices follow, so the client's office can show them.
     */
    public static function link($profile_id, $client_id){
        global $wpdb;
        $client_id = $client_id ? (int) $client_id : null;
        $wpdb->update(self::table(), ['client_id' => $client_id, 'updated_at' => Time::now()], ['id' => $profile_id]);
        $wpdb->update(Schema::table('invoices'), ['client_id' => $client_id], ['profile_id' => $profile_id]);
    }

    /** @return array{n:int, total:float, unpaid:int, last:?string} invoices of a profile in the Spanish books and Argentina. */
    public static function stats($profile_id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS n, SUM(total) AS total, SUM(paid = 0) AS unpaid, MAX(issue_date) AS last FROM ' . Schema::table('invoices') . " WHERE profile_id = %d AND status IN ('issued','sent')", $profile_id), ARRAY_A);
        return ['n' => (int) $r['n'], 'total' => (float) $r['total'], 'unpaid' => (int) $r['unpaid'], 'last' => $r['last']];
    }

    /** «Olofane S.L.» → «olofane»; «LA CARNICERÍA DE SALAMANCA S.L» → «la carniceria de salamanca». */
    public static function norm_name($name){
        $n = remove_accents(mb_strtolower((string) $name));
        $n = preg_replace('/\b(s\.?\s?l\.?\s?u?|s\.?\s?a\.?|s\.?\s?c\.?\s?p?|sll|slu|sl|sa|sociedad limitada|ltd|llc|inc)\b\.?/u', ' ', $n);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $n)));
    }

    /**
     * Suggests which office client each unlinked fiscal profile belongs to:
     * same or contained name, the client's code in the name, the email domain
     * among the client's domains, or the email among its contacts.
     * @return array<int,array{profile:array, client:array, score:int, reasons:string[]}>
     */
    public static function suggestions(){
        global $wpdb;
        $clients = \MAD_Oficina\Core\Repo\Clients::all();
        if ( ! $clients ) return [];
        $contact_emails = [];
        foreach ( $wpdb->get_results('SELECT e.email, c.client_id FROM ' . Schema::table('contact_emails') . ' e JOIN ' . Schema::table('client_contacts') . ' c ON c.id = e.contact_id', ARRAY_A) as $r ) {
            $contact_emails[strtolower($r['email'])] = (int) $r['client_id'];
        }
        $out = [];
        foreach ( self::unlinked() as $p ) {
            $emails = array_filter(array_map('strtolower', array_merge(
                preg_split('/[\s,;]+/', (string) $p['email'], -1, PREG_SPLIT_NO_EMPTY),
                $wpdb->get_col($wpdb->prepare('SELECT DISTINCT client_email FROM ' . Schema::table('invoices') . ' WHERE profile_id = %d AND client_email IS NOT NULL', $p['id']))
            )));
            $pn = self::norm_name($p['legal_name']);
            $best = null;
            foreach ( $clients as $c ) {
                $score = 0; $why = [];
                $cn = self::norm_name($c['name']);
                if ( $cn !== '' && $pn === $cn ) { $score += 6; $why[] = __('mismo nombre', 'mad-oficina'); }
                elseif ( $cn !== '' && strlen($cn) >= 4 && strlen($pn) >= 4 && ( str_contains($pn, $cn) || str_contains($cn, $pn) ) ) { $score += 4; $why[] = __('un nombre contiene al otro', 'mad-oficina'); }
                else {
                    $common = array_intersect(array_filter(explode(' ', $pn), fn($w) => strlen($w) >= 5), array_filter(explode(' ', $cn), fn($w) => strlen($w) >= 5));
                    if ( $common ) { $score += 2; $why[] = sprintf(__('comparten «%s»', 'mad-oficina'), reset($common)); }
                }
                if ( preg_match('/\b' . preg_quote(strtolower($c['code']), '/') . '\b/', $pn) && strlen($c['code']) >= 3 ) { $score += 3; $why[] = sprintf(__('lleva el código %s', 'mad-oficina'), $c['code']); }
                foreach ( $emails as $e ) {
                    if ( ($contact_emails[$e] ?? 0) === (int) $c['id'] ) { $score += 6; $why[] = sprintf(__('%s es un contacto', 'mad-oficina'), $e); break; }
                    $d = substr(strrchr($e, '@') ?: '', 1);
                    if ( $d && in_array($d, $c['domains'], true) ) { $score += 5; $why[] = sprintf(__('dominio %s', 'mad-oficina'), $d); break; }
                }
                if ( $score >= 4 && ( ! $best || $score > $best['score'] ) ) $best = ['profile' => $p, 'client' => $c, 'score' => $score, 'reasons' => $why];
            }
            if ( $best ) $out[] = $best;
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private static function hydrate($r){
        foreach ( ['id', 'client_id'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        $r['vat_rate'] = (float) $r['vat_rate'];
        $r['irpf_rate'] = (float) $r['irpf_rate'];
        return $r;
    }
}
