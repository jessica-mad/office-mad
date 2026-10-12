<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Creates/updates tables and roles. Idempotent: safe to run on every deploy.
 */
final class Installer {

    /** Bump whenever Schema or Roles change. */
    const DB_VERSION        = 3; // 2: meetings.source/source_key, approvals.object_type/object_id · 3: leads
    const DB_VERSION_OPTION = 'madoficina_db_version';

    public static function activate(){
        self::install();
    }

    public static function maybe_upgrade(){
        if ( (int) get_option(self::DB_VERSION_OPTION, 0) < self::DB_VERSION ) {
            self::install();
        }
    }

    public static function install(){
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        foreach ( Schema::definitions() as $name => $body ){
            dbDelta('CREATE TABLE ' . Schema::table($name) . " ({$body}\n) {$charset};");
        }

        $from = (int) get_option(self::DB_VERSION_OPTION, 0);
        foreach ( self::migrations() as $version => $callback ){
            if ( $version > $from && $version <= self::DB_VERSION ) $callback();
        }

        Roles::install();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Data migrations that dbDelta cannot express (renames, backfills…).
     * Keyed by the DB version that introduces them. Must never delete data.
     *
     * @return array<int,callable>
     */
    private static function migrations(){
        return [];
    }
}
