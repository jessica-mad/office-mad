<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/** Fiscal data of whoever is invoiced (one portal client may have one). */
final class Profiles {

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

    private static function hydrate($r){
        foreach ( ['id', 'client_id'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        $r['vat_rate'] = (float) $r['vat_rate'];
        $r['irpf_rate'] = (float) $r['irpf_rate'];
        return $r;
    }
}
