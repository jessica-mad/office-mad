<?php
namespace MAD_Oficina\Clients;

use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Roles;
use MAD_Oficina\Core\Seeder;

if ( ! defined('ABSPATH') ) exit;

/**
 * Form handlers for the Clients and Team screens. Kept apart from the module
 * so they can be tested without redirects: each returns
 * ['url' => ..., 'notice' => ..., 'type' => 'success'|'error'].
 */
final class Actions {

    const ACTIONS = [
        'client_save', 'client_delete', 'contact_save', 'contact_delete',
        'user_add', 'user_remove', 'user_send_access',
        'team_save', 'team_delete', 'demo_seed', 'demo_purge',
    ];

    const CLIENTS_PAGE = 'mad-oficina-clientes';
    const TEAM_PAGE    = 'mad-oficina-equipo';

    /** Capability + nonce check, then runs the action. */
    public static function dispatch($action, array $post){
        if ( ! in_array($action, self::ACTIONS, true) ) wp_die(esc_html__('Acción no válida.', 'mad-oficina'), 400);
        if ( ! current_user_can(Roles::CAP_MANAGE) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        if ( ! wp_verify_nonce($post['_wpnonce'] ?? '', 'madof_' . $action) ) wp_die(esc_html__('El enlace ha caducado. Vuelve a intentarlo.', 'mad-oficina'), 403);
        return self::$action(wp_unslash($post));
    }

    public static function client_url($id = 0, $extra = []){
        $args = ['page' => self::CLIENTS_PAGE] + ($id ? ['client' => (int) $id] : []) + $extra;
        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function ok($url, $notice){ return ['url' => $url, 'notice' => $notice, 'type' => 'success']; }
    private static function fail($url, $notice){ return ['url' => $url, 'notice' => $notice, 'type' => 'error']; }

    /* ===== Clients ===== */

    private static function client_save($p){
        $id = (int) ($p['id'] ?? 0);
        if ( $id && ! Clients::get($id) ) return self::fail(self::client_url(), __('Cliente no encontrado.', 'mad-oficina'));
        $result = Clients::save($p, $id);
        if ( is_wp_error($result) ) return self::fail(self::client_url($id, $id ? [] : ['new' => 1]), $result->get_error_message());
        return self::ok(self::client_url($result), $id ? __('Cliente guardado.', 'mad-oficina') : __('Cliente creado. Ahora añade sus contactos.', 'mad-oficina'));
    }

    private static function client_delete($p){
        $client = Clients::get((int) ($p['id'] ?? 0));
        if ( ! $client ) return self::fail(self::client_url(), __('Cliente no encontrado.', 'mad-oficina'));
        if ( Clients::normalize_code($p['confirm_code'] ?? '') !== $client['code'] ) {
            return self::fail(self::client_url($client['id']), __('Escribe el código del cliente para confirmar el borrado.', 'mad-oficina'));
        }
        foreach ( Clients::users($client['id']) as $user ) self::revoke_portal($user);
        Clients::delete($client['id']);
        return self::ok(self::client_url(), sprintf(__('%s y todos sus datos se han borrado.', 'mad-oficina'), $client['name']));
    }

    /* ===== Contacts ===== */

    private static function contact_save($p){
        $client_id = (int) ($p['client_id'] ?? 0);
        if ( ! Clients::get($client_id) ) return self::fail(self::client_url(), __('Cliente no encontrado.', 'mad-oficina'));
        $emails = [];
        foreach ( ['work', 'personal'] as $kind ){
            foreach ( preg_split('/[\s,;]+/', (string) ($p[$kind . '_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $email ){
                if ( ! is_email($email) ) return self::fail(self::client_url($client_id), sprintf(__('Email no válido: %s', 'mad-oficina'), $email));
                $emails[] = ['email' => $email, 'kind' => $kind];
            }
        }
        $contact_id = (int) ($p['contact_id'] ?? 0);
        if ( $contact_id && ! in_array($contact_id, array_column(Clients::contacts($client_id), 'id'), true) ) {
            return self::fail(self::client_url($client_id), __('Contacto no encontrado.', 'mad-oficina'));
        }
        $result = Clients::save_contact($client_id, $p, $emails, $contact_id);
        if ( is_wp_error($result) ) return self::fail(self::client_url($client_id), $result->get_error_message());
        return self::ok(self::client_url($client_id), __('Contacto guardado.', 'mad-oficina'));
    }

    private static function contact_delete($p){
        $client_id = (int) ($p['client_id'] ?? 0);
        Clients::delete_contact($client_id, (int) ($p['contact_id'] ?? 0));
        return self::ok(self::client_url($client_id), __('Contacto eliminado.', 'mad-oficina'));
    }

    /* ===== Portal users ===== */

    private static function user_add($p){
        $client_id = (int) ($p['client_id'] ?? 0);
        if ( ! Clients::get($client_id) ) return self::fail(self::client_url(), __('Cliente no encontrado.', 'mad-oficina'));
        $email = sanitize_email($p['email'] ?? '');
        if ( ! is_email($email) ) return self::fail(self::client_url($client_id), __('Escribe un email válido.', 'mad-oficina'));

        $user = get_user_by('email', $email);
        if ( $user ) {
            // Staff accounts must never become client accounts (they would leak the internal view).
            if ( user_can($user, Roles::CAP_MANAGE) || user_can($user, Roles::CAP_TEAM) || user_can($user, 'edit_posts') ) {
                return self::fail(self::client_url($client_id), __('Ese usuario es del equipo o administrador; no puede ser usuario de cliente.', 'mad-oficina'));
            }
        } else {
            // No email is sent: the welcome email is a separate, explicit action.
            $user_id = self::create_user($email, sanitize_text_field($p['name'] ?? ''), Roles::ROLE_CLIENT);
            if ( is_wp_error($user_id) ) return self::fail(self::client_url($client_id), $user_id->get_error_message());
            $user = get_user_by('id', $user_id);
        }

        $linked = Clients::link_user($client_id, $user->ID);
        if ( is_wp_error($linked) ) return self::fail(self::client_url($client_id), $linked->get_error_message());
        if ( ! in_array(Roles::ROLE_CLIENT, (array) $user->roles, true) ) $user->add_role(Roles::ROLE_CLIENT);

        return self::ok(self::client_url($client_id), sprintf(__('%s ya tiene acceso al portal. No se ha enviado ningún email.', 'mad-oficina'), $email));
    }

    private static function user_remove($p){
        $client_id = (int) ($p['client_id'] ?? 0);
        $user = get_user_by('id', (int) ($p['user_id'] ?? 0));
        if ( $user && \MAD_Oficina\Core\ClientContext::client_for_user($user->ID) === $client_id ) {
            Clients::unlink_user($client_id, $user->ID);
            self::revoke_portal($user);
        }
        return self::ok(self::client_url($client_id), __('Acceso al portal retirado.', 'mad-oficina'));
    }

    private static function user_send_access($p){
        $client_id = (int) ($p['client_id'] ?? 0);
        $user = get_user_by('id', (int) ($p['user_id'] ?? 0));
        if ( ! $user || \MAD_Oficina\Core\ClientContext::client_for_user($user->ID) !== $client_id ) {
            return self::fail(self::client_url($client_id), __('Usuario no encontrado.', 'mad-oficina'));
        }
        $sent = retrieve_password($user->user_login);
        if ( is_wp_error($sent) ) return self::fail(self::client_url($client_id), $sent->get_error_message());
        return self::ok(self::client_url($client_id), sprintf(__('Enviado a %s el enlace para crear su contraseña.', 'mad-oficina'), $user->user_email));
    }

    /** Creates a user without sending any email. @return int|\WP_Error */
    private static function create_user($email, $name, $role){
        $base = sanitize_user(strtok($email, '@'), true) ?: 'usuario';
        $login = $base; $i = 2;
        while ( username_exists($login) ) $login = $base . $i++;
        return wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(24),
            'display_name' => $name ?: $email,
            'first_name'   => $name,
            'role'         => $role,
        ]);
    }

    private static function revoke_portal(\WP_User $user){
        $user->remove_role(Roles::ROLE_CLIENT);
    }

    /* ===== Team ===== */

    private static function team_save($p){
        $url = add_query_arg(['page' => self::TEAM_PAGE], admin_url('admin.php'));
        $id = (int) ($p['id'] ?? 0);
        if ( $id && ! Team::get($id) ) return self::fail($url, __('Persona no encontrada.', 'mad-oficina'));

        $data = $p;
        $data['active'] = ! empty($p['active']);
        $email = sanitize_email($p['workspace_email'] ?? '');
        if ( $email && ! empty($p['portal_access']) ) {
            $user = get_user_by('email', $email);
            if ( $user && \MAD_Oficina\Core\ClientContext::client_for_user($user->ID) ) {
                return self::fail($url, __('Ese email pertenece a un usuario de cliente.', 'mad-oficina'));
            }
            if ( ! $user ) {
                $user_id = self::create_user($email, sanitize_text_field($p['name'] ?? ''), Roles::ROLE_TEAM);
                if ( is_wp_error($user_id) ) return self::fail($url, $user_id->get_error_message());
                $user = get_user_by('id', $user_id);
            } elseif ( ! user_can($user, Roles::CAP_MANAGE) ) {
                $user->add_role(Roles::ROLE_TEAM);
            }
            $data['wp_user_id'] = $user->ID;
        } elseif ( $id ) {
            $data['wp_user_id'] = Team::get($id)['wp_user_id'];
        }

        $result = Team::save($data, $id);
        if ( is_wp_error($result) ) return self::fail($url, $result->get_error_message());
        return self::ok($url, __('Equipo actualizado.', 'mad-oficina'));
    }

    private static function team_delete($p){
        Team::delete((int) ($p['id'] ?? 0));
        return self::ok(add_query_arg(['page' => self::TEAM_PAGE], admin_url('admin.php')), __('Persona eliminada del equipo. Su usuario de WordPress se mantiene.', 'mad-oficina'));
    }

    /* ===== Demo data ===== */

    private static function demo_seed($p){
        $r = Seeder::seed();
        return self::ok(self::client_url(), sprintf(__('Datos demo creados: %d clientes y %d usuarios. Puedes borrarlos cuando quieras.', 'mad-oficina'), $r['clients'], $r['users']));
    }

    private static function demo_purge($p){
        Seeder::purge();
        return self::ok(self::client_url(), __('Datos demo eliminados. Tus datos reales no se han tocado.', 'mad-oficina'));
    }
}
