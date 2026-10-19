<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * «Deshacer» for what Amparo changes in Finanzas when Jessica asks her (collected or
 * not, bank links, categories): the touched rows are kept as they were for 14 days.
 * Tokens start with «f:» so the Chat undo button knows they are Finanzas'.
 */
final class FinanceUndo {

    const FIELDS = [
        'invoices'       => ['paid', 'paid_at'],
        'bank_movements' => ['status', 'match_type', 'match_ids', 'note'],
        'expenses'       => ['category', 'client_id', 'notes'],
        'ad_changes'     => ['reason', 'reason_source', 'reason_by', 'reason_at', 'visibility'], // Campañas: the why
    ];
    /** Rows created by the change: «Deshacer» deletes them. */
    const CREATED = ['ad_changes'];

    /** @param array<string,int[]> $rows table => ids @param array<string,int[]> $created rows to delete on undo @return string token */
    public static function snapshot(array $rows, array $created = []){
        global $wpdb;
        $data = [];
        foreach ( $rows as $table => $ids ) {
            if ( ! isset(self::FIELDS[$table]) ) continue;
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ( ! $ids ) continue;
            $cols = implode(', ', array_merge(['id'], self::FIELDS[$table]));
            foreach ( $wpdb->get_results("SELECT {$cols} FROM " . Schema::table($table) . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $r ) $data[$table][(int) $r['id']] = $r;
        }
        foreach ( $created as $table => $ids ) if ( in_array($table, self::CREATED, true) ) $data['_created'][$table] = array_values(array_filter(array_map('intval', $ids)));
        $token = 'f:' . wp_generate_password(14, false);
        set_transient('madof_fin_undo_' . substr($token, 2), $data, 14 * DAY_IN_SECONDS);
        return $token;
    }

    /** @return int|\WP_Error rows restored */
    public static function restore($token){
        global $wpdb;
        $key = preg_replace('/[^A-Za-z0-9]/', '', substr((string) $token, 2));
        $data = $key !== '' ? get_transient('madof_fin_undo_' . $key) : false;
        if ( ! is_array($data) ) return new \WP_Error('madof_undo', __('Ya no puedo deshacerlo (ha pasado mucho tiempo).', 'mad-oficina'));
        $n = 0;
        foreach ( $data['_created'] ?? [] as $table => $ids ) {
            foreach ( $ids as $id ) $n += (int) $wpdb->delete(Schema::table($table), ['id' => (int) $id]);
        }
        unset($data['_created']);
        foreach ( $data as $table => $rows ) {
            foreach ( $rows as $id => $r ) {
                $fields = array_intersect_key($r, array_flip(self::FIELDS[$table] ?? []));
                $fields['updated_at'] = Time::now();
                $wpdb->update(Schema::table($table), $fields, ['id' => (int) $id]);
                $n++;
            }
        }
        if ( ! empty($data['invoices']) ) Issuing::queue_libro(array_keys($data['invoices']));
        delete_transient('madof_fin_undo_' . $key);
        return $n;
    }
}
