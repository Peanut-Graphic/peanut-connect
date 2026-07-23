<?php
/**
 * The scoped "UTM Builder" role + capability.
 *
 * @package Peanut_Connect
 */

class Peanut_Connect_Roles {
    const BUILDER_CAP = 'peanut_connect_build_utms';
    const BUILDER_ROLE = 'peanut_utm_builder';
    const VERSION_OPTION = 'peanut_connect_roles_version';
    const VERSION = 1;

    /** Wire runtime hooks. Called once during plugin load. */
    public static function boot(): void {
        // Admins satisfy the builder cap at RUNTIME — never a stored grant, so a
        // plugin update can't leave admins without the menu capability.
        add_filter('user_has_cap', [self::class, 'grant_builder_cap_to_admins']);
        // Keep the role in sync on upgrade (activation does not fire on update).
        add_action('admin_init', [self::class, 'maybe_install']);
    }

    /** @param array<string,bool> $allcaps */
    public static function grant_builder_cap_to_admins($allcaps) {
        if (!empty($allcaps['manage_options'])) {
            $allcaps[self::BUILDER_CAP] = true;
        }
        return $allcaps;
    }

    /** Idempotent: (re)create the role with exactly read + the builder cap. */
    public static function install(): void {
        remove_role(self::BUILDER_ROLE);
        add_role(self::BUILDER_ROLE, 'UTM Builder', [
            'read' => true,
            self::BUILDER_CAP => true,
        ]);
        update_option(self::VERSION_OPTION, self::VERSION);
    }

    public static function maybe_install(): void {
        if ((int) get_option(self::VERSION_OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function uninstall(): void {
        remove_role(self::BUILDER_ROLE);
        delete_option(self::VERSION_OPTION);
    }
}
