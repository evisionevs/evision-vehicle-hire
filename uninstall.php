<?php
/**
 * EVision Vehicle Hire Uninstall
 *
 * Deletes custom tables and options when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGINS') && !defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop the custom reservations table
$table_name = $wpdb->prefix . 'evh_reservations';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete plugin options
delete_option('evh_schema_version');
