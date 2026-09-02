<?php
/**
 * Simple logger for MCP events.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Logger {

    const OPTION_KEY = 'mcp_logs';
    const MAX_LOGS   = 200;

    public static function log( $level, $message, $context = array() ) {
        $settings = MCP_Plugin::get_settings();
        if ( empty( $settings['enable_logging'] ) ) {
            return;
        }

        $logs   = get_option( self::OPTION_KEY, array() );
        $entry  = array(
            'time'    => current_time( 'mysql' ),
            'level'   => sanitize_text_field( $level ),
            'message' => sanitize_text_field( $message ),
            'context' => self::sanitize_context( $context ),
        );

        array_unshift( $logs, $entry );
        if ( count( $logs ) > self::MAX_LOGS ) {
            $logs = array_slice( $logs, 0, self::MAX_LOGS );
        }

        update_option( self::OPTION_KEY, $logs, false );
    }

    public static function info( $message, $context = array() ) {
        self::log( 'info', $message, $context );
    }

    public static function warning( $message, $context = array() ) {
        self::log( 'warning', $message, $context );
    }

    public static function error( $message, $context = array() ) {
        self::log( 'error', $message, $context );
    }

    public static function get_recent( $limit = 50 ) {
        $logs  = get_option( self::OPTION_KEY, array() );
        $limit = max( 1, (int) $limit );
        return array_slice( $logs, 0, $limit );
    }

    public static function clear() {
        update_option( self::OPTION_KEY, array(), false );
    }

    private static function sanitize_context( $context ) {
        if ( ! is_array( $context ) ) {
            return array();
        }
        $clean = array();
        foreach ( $context as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $clean[ sanitize_key( $key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
            }
        }
        return $clean;
    }
}
