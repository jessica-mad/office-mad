<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * What each person of a client company sees in the office. A company may have
 * several portal users, and not all of them should see everything (e.g. only
 * whoever pays sees invoices and quotes). Stored per user in client_users.areas;
 * enforced on the server (REST and the receptionist's tools), the menu only
 * mirrors it.
 */
final class PortalAreas {

    const AREAS = [
        'timeline'  => 'Cómo va el proyecto',
        'meetings'  => 'Reuniones',
        'tasks'     => 'Tareas',
        'ideas'     => 'Ideas',
        'documents' => 'Documentos',
        'billing'   => 'Facturas y presupuestos',
    ];

    /** Areas a person gets unless you say otherwise: money stays with whoever you choose. */
    const DEFAULT = ['timeline', 'meetings', 'tasks', 'ideas', 'documents'];

    /** @return string[] */
    public static function for_user($user_id = null){
        $user_id = $user_id ?? get_current_user_id();
        if ( ! $user_id ) return [];
        if ( user_can($user_id, Roles::CAP_MANAGE) ) return array_keys(self::AREAS);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT areas FROM ' . Schema::table('client_users') . ' WHERE wp_user_id = %d', $user_id), ARRAY_A);
        if ( ! $row ) return [];
        return $row['areas'] === null ? self::DEFAULT : self::clean(explode(',', $row['areas']));
    }

    public static function can($area, $user_id = null){
        return in_array($area, self::for_user($user_id), true);
    }

    /** @param string[] $areas */
    public static function set($client_id, $user_id, array $areas){
        global $wpdb;
        return $wpdb->update(Schema::table('client_users'), ['areas' => implode(',', self::clean($areas))], ['client_id' => (int) $client_id, 'wp_user_id' => (int) $user_id]);
    }

    /** @return string[] known areas only, in the canonical order */
    public static function clean(array $areas){
        $areas = array_map('trim', array_map('strval', $areas));
        return array_values(array_filter(array_keys(self::AREAS), fn($a) => in_array($a, $areas, true)));
    }

    public static function denied(){
        return new \WP_Error('madof_area', __('Esta parte de la oficina no está abierta para tu usuario. Si la necesitas, pídeselo a Jessica.', 'mad-oficina'), ['status' => 403]);
    }
}
