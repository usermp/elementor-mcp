<?php
/**
 * Plugin activator - runs on activation.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Activator {

    public static function activate() {
        self::set_defaults();
        self::set_db_version();
        flush_rewrite_rules();
    }

    private static function set_defaults() {
        if ( false === get_option( 'mcp_settings' ) ) {
            $defaults = array(
                'api_enabled'      => 1,
                'webhook_secret'   => wp_generate_password( 32, false ),
                'rate_limit'       => 60,
                'enable_logging'   => 1,
                'enable_graphql'   => 0,
            );
            add_option( 'mcp_settings', $defaults );
        }
    }

    private static function set_db_version() {
        add_option( 'mcp_db_version', '1.0.0' );
        add_option( 'mcp_version', MCP_VERSION );
    }
}
