<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * WP-CLI commands: wp mad-oficina <command>
 */
final class Cli {

    /**
     * Creates demo clients, contacts, users, tasks, ideas and meetings.
     * Replaces any previous demo data. Real data is never touched.
     *
     * ## EXAMPLES
     *     wp mad-oficina seed
     */
    public function seed($args, $assoc){
        Installer::maybe_upgrade();
        $result = Seeder::seed();
        \WP_CLI::success(sprintf('Datos demo creados: %d clientes, %d usuarios.', $result['clients'], $result['users']));
        if ( class_exists('MAD_Oficina\\Ads\\Demo') ) \WP_CLI::success(sprintf('Campañas demo: %d cambios inventados.', \MAD_Oficina\Ads\Demo::seed()));
    }

    /**
     * Removes all demo data.
     *
     * @subcommand purge-demo
     */
    public function purge_demo($args, $assoc){
        if ( class_exists('MAD_Oficina\\Ads\\Demo') ) \MAD_Oficina\Ads\Demo::purge();
        Seeder::purge();
        \WP_CLI::success('Datos demo eliminados.');
    }
}
