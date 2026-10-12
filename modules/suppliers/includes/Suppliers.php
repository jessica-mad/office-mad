<?php
namespace MAD_Oficina\Suppliers;

use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/** Suppliers MAD pays: who they are, how and when their invoices arrive. */
final class Suppliers {

    const CATEGORIES = [
        'software'   => 'Software y suscripciones',
        'hosting'    => 'Hosting y dominios',
        'ads'        => 'Publicidad',
        'training'   => 'Formación',
        'equipment'  => 'Material y equipos',
        'freelance'  => 'Colaboradores',
        'advisory'   => 'Gestoría y bancos',
        'other'      => 'Otros',
    ];
    const PERIODICITIES = ['monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'yearly' => 'Anual', 'once' => 'Puntual', 'irregular' => 'Irregular'];
    const DELIVERIES = ['email' => 'Llega por email', 'panel' => 'Hay que descargarla de su panel', 'ask' => 'Me la pide a mí'];

    public static function table(){ return Schema::table('suppliers'); }

    public static function get($id){
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    public static function all($status = ''){
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table() . ($status ? $wpdb->prepare(' WHERE status = %s', $status) : '') . ' ORDER BY name';
        return array_map([self::class, 'hydrate'], $wpdb->get_results($sql, ARRAY_A));
    }

    /** @return int|\WP_Error */
    public static function save(array $d, $id = 0){
        global $wpdb;
        $name = mb_substr(sanitize_text_field($d['name'] ?? ''), 0, 190);
        if ( $name === '' ) return new \WP_Error('madof_supplier', __('El proveedor necesita un nombre.', 'mad-oficina'));
        $emails = [];
        foreach ( preg_split('/[\s,;]+/', (string) ($d['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e ) if ( is_email($e) ) $emails[] = strtolower($e);
        $row = [
            'name' => $name,
            'tax_id' => Profiles::norm_tax_id($d['tax_id'] ?? '') ?: null,
            'emails' => $emails ? implode(',', array_unique($emails)) : null,
            'service' => mb_substr(sanitize_text_field($d['service'] ?? ''), 0, 255) ?: null,
            'category' => isset(self::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'other',
            'periodicity' => isset(self::PERIODICITIES[$d['periodicity'] ?? '']) ? $d['periodicity'] : 'irregular',
            'expected_day' => ($v = (int) ($d['expected_day'] ?? 0)) >= 1 && $v <= 31 ? $v : null,
            'expected_month' => ($v = (int) ($d['expected_month'] ?? 0)) >= 1 && $v <= 12 ? $v : null,
            'usual_amount' => ($v = \MAD_Oficina\Billing\Money::parse((string) ($d['usual_amount'] ?? ''))) ? $v : null,
            'delivery' => isset(self::DELIVERIES[$d['delivery'] ?? '']) ? $d['delivery'] : 'email',
            'panel_url' => esc_url_raw($d['panel_url'] ?? '') ?: null,
            'client_id' => ! empty($d['client_id']) ? (int) $d['client_id'] : null,
            'status' => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'notes' => sanitize_textarea_field($d['notes'] ?? '') ?: null,
            'updated_at' => Time::now(),
        ];
        if ( $id ) {
            $wpdb->update(self::table(), $row, ['id' => $id]);
            return (int) $id;
        }
        $wpdb->insert(self::table(), $row + ['created_at' => Time::now()]);
        return (int) $wpdb->insert_id;
    }

    /** Adds a sender address to a supplier (learned when Jessica confirms an invoice). */
    public static function add_email($id, $email){
        $s = self::get($id);
        $email = strtolower(sanitize_email($email));
        if ( ! $s || ! is_email($email) || in_array($email, $s['emails'], true) ) return;
        global $wpdb;
        $wpdb->update(self::table(), ['emails' => implode(',', array_merge($s['emails'], [$email])), 'updated_at' => Time::now()], ['id' => $id]);
    }

    /** Known supplier by tax id, then sender address, then name. */
    public static function match($tax_id, $email, $name){
        global $wpdb;
        $t = self::table();
        $tax = Profiles::norm_tax_id($tax_id);
        if ( $tax !== '' ) {
            $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE tax_id = %s ORDER BY status = 'active' DESC, id LIMIT 1", $tax), ARRAY_A);
            if ( $r ) return self::hydrate($r);
        }
        $email = strtolower(trim((string) $email));
        if ( is_email($email) ) {
            foreach ( $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE emails LIKE %s", '%' . $wpdb->esc_like($email) . '%'), ARRAY_A) as $r ) {
                if ( in_array($email, explode(',', (string) $r['emails']), true) ) return self::hydrate($r);
            }
        }
        $n = Profiles::norm_name($name);
        if ( strlen($n) >= 3 ) {
            foreach ( self::all() as $s ) if ( Profiles::norm_name($s['name']) === $n ) return $s;
        }
        return null;
    }

    /** Every address the cartero should treat as an invoice sender. */
    public static function known_emails(){
        $out = [];
        foreach ( self::all('active') as $s ) $out = array_merge($out, $s['emails']);
        return array_values(array_unique($out));
    }

    private static function hydrate($r){
        foreach ( ['id', 'expected_day', 'expected_month', 'client_id'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        $r['usual_amount'] = $r['usual_amount'] === null ? null : (float) $r['usual_amount'];
        $r['emails'] = $r['emails'] ? explode(',', $r['emails']) : [];
        return $r;
    }
}
