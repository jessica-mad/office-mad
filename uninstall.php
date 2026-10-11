<?php
/**
 * Runs when the plugin is deleted from Plugins → Delete.
 * Data is only removed if "madoficina_delete_data_on_uninstall" is set, so a
 * reinstall after a bad deploy never loses clients, meetings or tasks.
 */
if ( ! defined('WP_UNINSTALL_PLUGIN') ) exit;

if ( ! get_option('madoficina_delete_data_on_uninstall') ) return;

require_once __DIR__ . '/core/Schema.php';
require_once __DIR__ . '/core/Roles.php';

global $wpdb;
foreach ( MAD_Oficina\Core\Schema::names() as $name ){
    $wpdb->query('DROP TABLE IF EXISTS ' . MAD_Oficina\Core\Schema::table($name));
}
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'madoficina\\_%'");
MAD_Oficina\Core\Roles::uninstall();
