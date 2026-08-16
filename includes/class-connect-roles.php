<?php
/**
 * The scoped "UTM Builder" role + capability.
 *
 * @package Peanut_Connect
 */

class Peanut_Connect_Roles {
    const BUILDER_CAP = 'peanut_connect_build_utms';
    const BUILDER_ROLE = 'peanut_utm_builder';
    const BUILDER_PAGE = 'peanut-connect-app';
    const VERSION_OPTION = 'peanut_connect_roles_version';
    const VERSION = 1;

    /** Wire runtime hooks. Called once during plugin load. */
    public static function boot(): void {
        // Admins satisfy the builder cap at RUNTIME — never a stored grant, so a
        // plugin update can't leave admins without the menu capability.
        add_filter('user_has_cap', [self::class, 'grant_builder_cap_to_admins']);
        // Keep the role in sync on upgrade (activation does not fire on update).
        add_action('admin_init', [self::class, 'maybe_install']);

        // Confine builder-only users to the campaign wizard: straight there on
        // login, no Dashboard menu, and any other admin screen bounces to it.
        add_filter('login_redirect', [self::class, 'builder_login_redirect'], 10, 3);
        add_action('admin_menu', [self::class, 'trim_builder_menu'], 999);
        add_action('admin_init', [self::class, 'confine_builder_to_wizard']);
    }

    /**
     * A user is "confined" to the wizard when they hold the builder cap but are
     * NOT a full administrator. Pure decision logic — unit-testable without WP.
     */
    public static function is_builder_confined(bool $has_builder_cap, bool $is_admin): bool {
        return $has_builder_cap && ! $is_admin;
    }

    private static function current_user_is_confined(): bool {
        return self::is_builder_confined(
            current_user_can(self::BUILDER_CAP),
            current_user_can('manage_options')
        );
    }

    private static function builder_url(): string {
        return admin_url('admin.php?page=' . self::BUILDER_PAGE);
    }

    /** After login, send builder-only users straight to the wizard. */
    public static function builder_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (
            $user instanceof WP_User
            && self::is_builder_confined(
                user_can($user, self::BUILDER_CAP),
                user_can($user, 'manage_options')
            )
        ) {
            return self::builder_url();
        }

        return $redirect_to;
    }

    /** Remove the Dashboard menu for builder-only users. */
    public static function trim_builder_menu(): void {
        if (self::current_user_is_confined()) {
            remove_menu_page('index.php');
        }
    }

    /**
     * Bounce builder-only users off any admin screen that isn't the wizard (or
     * the account/AJAX essentials) so /wp-admin lands on the wizard, never the
     * dashboard.
     */
    public static function confine_builder_to_wizard(): void {
        if (! self::current_user_is_confined()) {
            return;
        }
        if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        global $pagenow;
        $on_wizard = ($pagenow === 'admin.php' && (($_GET['page'] ?? '') === self::BUILDER_PAGE));
        // Keep account management + form/AJAX handlers reachable; bounce the rest.
        $allowed = in_array($pagenow, ['profile.php', 'admin-post.php', 'admin-ajax.php'], true);

        if (! $on_wizard && ! $allowed) {
            wp_safe_redirect(self::builder_url());
            exit;
        }
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
