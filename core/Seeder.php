<?php
namespace MAD_Oficina\Core;

use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;

if ( ! defined('ABSPATH') ) exit;

/**
 * Demo data. Everything it creates is flagged (is_demo / user meta) so it can
 * be wiped in one go without touching real data — we work on the live site.
 * All names are fictional.
 */
final class Seeder {

    const USER_META = 'madof_demo';

    public static function has_demo(){
        return (bool) Clients::all(['is_demo' => true]);
    }

    /** @return array{clients:int,users:int} */
    public static function seed(){
        global $wpdb;
        if ( self::has_demo() ) self::purge();

        $team_id = Team::save([
            'name'            => 'Liz (demo)',
            'slug'            => 'demo-liz',
            'workspace_email' => 'liz.demo@example.com',
            'skills'          => 'diseño, vídeo',
            'is_demo'         => true,
        ]);

        $today = current_time('timestamp');
        $date  = fn($days) => gmdate('Y-m-d', $today + $days * DAY_IN_SECONDS);
        $dt    = fn($days, $hour = 10) => gmdate('Y-m-d', $today + $days * DAY_IN_SECONDS) . sprintf(' %02d:00:00', $hour);

        $clients = [
            [
                'name' => 'Panadería Lúa (demo)', 'code' => 'LUA', 'domains' => 'panaderialua.example',
                'contacts' => [
                    ['name' => 'Marta Rial', 'role' => 'Gerente', 'aliases' => 'Marta', 'emails' => [['email' => 'marta@panaderialua.example', 'kind' => 'work'], ['email' => 'marta.rial.demo@gmail.example', 'kind' => 'personal']]],
                    ['name' => 'Iago Souto', 'role' => 'Redes sociales', 'aliases' => 'Iago', 'emails' => [['email' => 'iago@panaderialua.example', 'kind' => 'work']]],
                ],
                'processes' => [
                    ['area' => 'web', 'title' => 'Nueva tienda online', 'status' => 'in_progress', 'starts_at' => $date(-30), 'ends_at' => $date(20)],
                    ['area' => 'ads', 'title' => 'Campaña de Navidad', 'status' => 'planned', 'starts_at' => $date(25), 'ends_at' => $date(70)],
                    ['area' => 'diseno', 'title' => 'Packaging de roscón', 'status' => 'done', 'starts_at' => $date(-60), 'ends_at' => $date(-35)],
                ],
            ],
            [
                'name' => 'Estudio Cobalto (demo)', 'code' => 'COB', 'domains' => 'estudiocobalto.example',
                'contacts' => [
                    ['name' => 'Nerea Pazos', 'role' => 'Directora', 'aliases' => 'Nerea', 'emails' => [['email' => 'nerea@estudiocobalto.example', 'kind' => 'work']]],
                ],
                'processes' => [
                    ['area' => 'contenidos', 'title' => 'Calendario editorial T4', 'status' => 'in_progress', 'starts_at' => $date(-10), 'ends_at' => $date(80)],
                    ['area' => 'ads', 'title' => 'Captación de leads', 'status' => 'in_progress', 'starts_at' => $date(-20), 'ends_at' => $date(40)],
                ],
            ],
            [
                'name' => 'Viveros Ortiga (demo)', 'code' => 'ORT', 'domains' => 'viverosortiga.example',
                'contacts' => [
                    ['name' => 'Xoán Lema', 'role' => 'Propietario', 'aliases' => 'Xoán, Xoan', 'emails' => [['email' => 'xoan@viverosortiga.example', 'kind' => 'work']]],
                ],
                'processes' => [
                    ['area' => 'web', 'title' => 'Auditoría SEO', 'status' => 'planned', 'starts_at' => $date(5), 'ends_at' => $date(35)],
                ],
            ],
        ];

        $users = 0;
        foreach ( $clients as $c ){
            $client_id = Clients::save(['name' => $c['name'], 'code' => $c['code'], 'domains' => $c['domains'], 'is_demo' => true]);
            if ( is_wp_error($client_id) ) continue;

            foreach ( $c['contacts'] as $contact ){
                Clients::save_contact($client_id, $contact + ['receives_summaries' => 1], $contact['emails']);
            }

            $login = 'demo_' . strtolower($c['code']);
            $existing = username_exists($login);
            // Never adopt a real account that happens to share the demo login.
            if ( ! $existing || get_user_meta($existing, self::USER_META, true) ) {
                $user_id = $existing ?: wp_insert_user([
                    'user_login'   => $login,
                    'user_pass'    => wp_generate_password(24),
                    'user_email'   => $login . '@demo.invalid',
                    'display_name' => $c['name'],
                    'role'         => Roles::ROLE_CLIENT,
                ]);
                if ( ! is_wp_error($user_id) ) {
                    update_user_meta($user_id, self::USER_META, 1);
                    Clients::link_user($client_id, $user_id);
                    $users++;
                }
            }

            foreach ( $c['processes'] as $i => $p ){
                $wpdb->insert(Schema::table('processes'), $p + ['client_id' => $client_id, 'sort' => $i, 'visibility' => 'client', 'created_at' => Time::now(), 'updated_at' => Time::now()]);
            }

            $meeting = [
                'client_id'    => $client_id,
                'title'        => $c['code'] . ' · Seguimiento mensual',
                'starts_at'    => $dt(-7),
                'ends_at'      => $dt(-7, 11),
                'summary'      => 'Repasamos el estado de los proyectos y acordamos próximos pasos.',
                'extraction'   => wp_json_encode(['client_decisions' => ['Lanzamos la tienda antes de Black Friday', 'Las fotos de producto las hace el cliente']]),
                'next_meeting' => $date(21),
                'status'       => 'approved',
                'visibility'   => 'client',
                'created_at'   => Time::now(),
                'updated_at'   => Time::now(),
            ];
            $wpdb->insert(Schema::table('meetings'), $meeting);
            $meeting_id = (int) $wpdb->insert_id;

            $items = [
                ['title' => 'Enviar fotos de producto', 'owner' => 'cliente', 'due_date' => $date(3), 'visibility' => 'client'],
                ['title' => 'Maquetar propuesta de home', 'owner' => 'liz', 'team_member_id' => $team_id, 'due_date' => $date(5), 'visibility' => 'client'],
                ['title' => 'Revisar presupuesto de ads', 'owner' => 'jessica', 'due_date' => $date(-1), 'visibility' => 'client'],
                ['title' => 'Nota interna: margen del proyecto ajustado', 'owner' => 'jessica', 'visibility' => 'internal'],
                ['title' => 'Exportar piezas en formato vertical', 'owner' => 'liz', 'team_member_id' => $team_id, 'due_date' => $date(8), 'visibility' => 'team'],
            ];
            foreach ( $items as $item ){
                $wpdb->insert(Schema::table('action_items'), $item + ['client_id' => $client_id, 'meeting_id' => $meeting_id, 'source' => 'meeting', 'status' => 'open', 'created_at' => Time::now(), 'updated_at' => Time::now()]);
            }

            $wpdb->insert(Schema::table('ideas'), ['client_id' => $client_id, 'meeting_id' => $meeting_id, 'title' => 'Activación en tienda física', 'description' => 'Retomar cuando pase la campaña principal.', 'resurface_at' => $date(90), 'visibility' => 'client', 'created_at' => Time::now(), 'updated_at' => Time::now()]);
            $wpdb->insert(Schema::table('ideas'), ['client_id' => $client_id, 'meeting_id' => $meeting_id, 'title' => 'Idea interna: proponer subir el fee', 'resurface_at' => $date(30), 'visibility' => 'internal', 'created_at' => Time::now(), 'updated_at' => Time::now()]);
            $wpdb->insert(Schema::table('pending_topics'), ['client_id' => $client_id, 'meeting_id' => $meeting_id, 'text' => 'Confirmar presupuesto mensual de anuncios', 'visibility' => 'client', 'created_at' => Time::now(), 'updated_at' => Time::now()]);
        }

        return ['clients' => count($clients), 'users' => $users];
    }

    /** Removes demo clients (with all their content), demo team members and demo users. */
    public static function purge(){
        global $wpdb;
        foreach ( Clients::all(['is_demo' => true]) as $client ){
            Clients::delete($client['id']);
        }
        $wpdb->delete(Schema::table('team_members'), ['is_demo' => 1]);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( get_users(['meta_key' => self::USER_META, 'meta_value' => 1, 'fields' => 'ID']) as $user_id ){
            wp_delete_user((int) $user_id);
        }
    }
}
