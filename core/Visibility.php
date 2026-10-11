<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Which visibility levels a user may read. Every query that returns content to
 * the portal must filter by these values (see Visibility::sql_in()).
 */
final class Visibility {

    const ALL = [Schema::VISIBILITY_INTERNAL, Schema::VISIBILITY_TEAM, Schema::VISIBILITY_CLIENT];

    /** @return string[] */
    public static function allowed_for_user($user_id = null){
        $user_id = $user_id ?? get_current_user_id();
        if ( ! $user_id ) return [];
        if ( user_can($user_id, Roles::CAP_MANAGE) ) return self::ALL;
        if ( user_can($user_id, Roles::CAP_TEAM) ) return [Schema::VISIBILITY_TEAM, Schema::VISIBILITY_CLIENT];
        if ( user_can($user_id, Roles::CAP_PORTAL) ) return [Schema::VISIBILITY_CLIENT];
        return [];
    }

    public static function sanitize($value, $default = Schema::VISIBILITY_INTERNAL){
        return in_array($value, self::ALL, true) ? $value : $default;
    }

    /** SQL fragment "visibility IN ('client')" built from a whitelist, never from input. */
    public static function sql_in(array $allowed, $column = 'visibility'){
        $allowed = array_values(array_intersect($allowed, self::ALL));
        if ( ! $allowed ) return '1=0';
        return $column . " IN ('" . implode("','", $allowed) . "')";
    }
}
