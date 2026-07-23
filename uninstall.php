<?php
/**
 * Peanut Connect uninstall — runs when the plugin is deleted via WP admin.
 *
 * Drops all tracking tables and removes every peanut_connect_* option from
 * the options table. Cron events are already cleared by the deactivation
 * hook, but we clear them here too in case the user uninstalls without
 * deactivating first.
 *
 * @package Peanut_Connect
 * @since 3.7.21
 */

// WordPress sets this constant when invoking uninstall.php.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Drop tracking tables.
require_once plugin_dir_path(__FILE__) . 'includes/class-connect-database.php';
if (class_exists('Peanut_Connect_Database')) {
    Peanut_Connect_Database::drop_tables();
}

// Clear cron events that may still be scheduled.
$cron_hooks = [
    'peanut_connect_hub_sync',
    'peanut_connect_hub_heartbeat',
    'peanut_connect_cleanup',
    'peanut_ml_connect_train',
    'peanut_connect_sync_requested',
    'peanut_connect_sync_to_hub', // legacy
];
foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Delete every peanut_connect_* option.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'peanut_connect\\_%'"
);

// Delete the slug cache transient (and its timeout sibling).
delete_transient('peanut_connect_short_link_slugs');

// Remove the scoped "UTM Builder" role.
require_once plugin_dir_path(__FILE__) . 'includes/class-connect-roles.php';
if (class_exists('Peanut_Connect_Roles')) {
    Peanut_Connect_Roles::uninstall();
}
