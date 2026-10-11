<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Roles and capabilities.
 *
 * - administrator + mad_manage_office: Jessica, sees everything.
 * - mad_team: Liz, sees her tasks, briefs and related files.
 * - mad_client: one or more users per client company, only their own data.
 */
final class Roles {

    const CAP_MANAGE = 'mad_manage_office';
    const CAP_TEAM   = 'mad_team_access';
    const CAP_PORTAL = 'mad_view_portal';

    const ROLE_TEAM   = 'mad_team';
    const ROLE_CLIENT = 'mad_client';

    public static function install(){
        // Role names are stored untranslated (like core roles); this runs before translations load.
        add_role(self::ROLE_TEAM, 'Equipo MAD', [
            'read'           => true,
            self::CAP_TEAM   => true,
            self::CAP_PORTAL => true,
        ]);
        add_role(self::ROLE_CLIENT, 'Cliente MAD', [
            'read'           => true,
            self::CAP_PORTAL => true,
        ]);

        $admin = get_role('administrator');
        if ( $admin ) {
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_TEAM);
            $admin->add_cap(self::CAP_PORTAL);
        }
    }

    public static function uninstall(){
        remove_role(self::ROLE_TEAM);
        remove_role(self::ROLE_CLIENT);
        $admin = get_role('administrator');
        if ( $admin ) {
            foreach ( [self::CAP_MANAGE, self::CAP_TEAM, self::CAP_PORTAL] as $cap ) $admin->remove_cap($cap);
        }
    }
}
