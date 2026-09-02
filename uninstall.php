<?php
/**
 * Uninstall script - runs when the plugin is deleted from WordPress.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

delete_option( 'mcp_settings' );
delete_option( 'mcp_db_version' );
delete_option( 'mcp_version' );

$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mcp\\_%'"
);

$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mcp_revision'"
);

$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mcp_locked'"
);
