<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Resolves which client a request acts on. The client_id always comes from the
 * server (the logged-in user's link in client_users); input is only honoured
 * for managers, who may look at any client.
 */
final class ClientContext {

    /** @return int|null */
    public static function client_for_user($user_id){
        global $wpdb;
        if ( ! $user_id ) return null;
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT client_id FROM ' . Schema::table('client_users') . ' WHERE wp_user_id = %d',
            $user_id
        ));
        return $id ? (int) $id : null;
    }

    /**
     * @param int|null $requested client_id the caller asked for (query param), honoured only for managers.
     * @return int|null
     */
    public static function resolve($requested = null, $user_id = null){
        $user_id = $user_id ?? get_current_user_id();
        if ( ! $user_id ) return null;
        if ( user_can($user_id, Roles::CAP_MANAGE) ) {
            return $requested ? (int) $requested : null;
        }
        return self::client_for_user($user_id);
    }
}
