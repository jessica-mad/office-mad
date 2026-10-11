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
    }

    /**
     * Removes all demo data.
     *
     * @subcommand purge-demo
     */
    public function purge_demo($args, $assoc){
        Seeder::purge();
        \WP_CLI::success('Datos demo eliminados.');
    }
}
