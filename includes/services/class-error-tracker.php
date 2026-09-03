<?php
/**
 * Error tracker — keeps a rolling window of recent errors per category
 * so the admin can see what's failing without spelunking through logs.
 *
 * Errors are bucketed by error code (e.g. "mcp_chat_no_key") and stored in
 * a single option as a small array. The window is capped at 200 entries.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Error_Tracker {

    const OPTION_KEY = 'mcp_error_log';
    const MAX_ENTRIES = 200;

    /**
     * @param string $code   Error code, e.g. "mcp_chat_no_key"
     * @param string $level  'error' | 'warning' | 'info'
     * @param array  $context  Free-form context (route, model, user, ...)
     */
    public static function log( $code, $level = 'error', array $context = array() ) {
        $entries = get_option( self::OPTION_KEY, array() );
        $entries[] = array(
            'time'    => current_time( 'mysql' ),
            'ts'      => time(),
            'code'    => sanitize_key( $code ),
            'level'   => sanitize_key( $level ),
            'context' => self::scrub( $context ),
        );
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES );
        }
        update_option( self::OPTION_KEY, $entries, false );
    }

    /**
     * Drop any secrets (api_key, password, *_token) from context before logging.
     */
    private static function scrub( array $ctx ) {
        $safe = array();
        foreach ( $ctx as $k => $v ) {
            $lk = strtolower( (string) $k );
            if ( false !== strpos( $lk, 'key' ) || false !== strpos( $lk, 'token' ) || false !== strpos( $lk, 'pass' ) || false !== strpos( $lk, 'secret' ) ) {
                $safe[ $k ] = '***';
                continue;
            }
            $safe[ $k ] = is_scalar( $v ) ? $v : wp_json_encode( $v );
        }
        return $safe;
    }

    /**
     * @return array  Last $limit entries, newest first.
     */
    public static function recent( $limit = 50 ) {
        $entries = (array) get_option( self::OPTION_KEY, array() );
        $entries = array_reverse( $entries );
        return array_slice( $entries, 0, max( 1, (int) $limit ) );
    }

    /**
     * @return array  { total, by_code: {code: count}, by_level: {level: count} }
     */
    public static function stats() {
        $entries = (array) get_option( self::OPTION_KEY, array() );
        $by_code = array();
        $by_level = array();
        foreach ( $entries as $e ) {
            $by_code[ $e['code'] ] = ( $by_code[ $e['code'] ] ?? 0 ) + 1;
            $by_level[ $e['level'] ] = ( $by_level[ $e['level'] ] ?? 0 ) + 1;
        }
        arsort( $by_code );
        return array(
            'total'    => count( $entries ),
            'by_code'  => $by_code,
            'by_level' => $by_level,
        );
    }

    public static function clear() {
        update_option( self::OPTION_KEY, array() );
    }
}