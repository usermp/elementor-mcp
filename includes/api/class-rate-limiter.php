<?php
/**
 * Rate limiter for REST endpoints and webhooks.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Rate_Limiter {

    public static function check( $bucket, $limit, $window = MINUTE_IN_SECONDS ) {
        $limit = (int) $limit;
        if ( $limit <= 0 ) {
            return true;
        }

        $key   = 'mcp_rl_' . sanitize_key( $bucket );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return new WP_Error( 'mcp_rate_limited', __( 'Rate limit exceeded.', 'elementor-mcp' ), array( 'status' => 429 ) );
        }

        if ( 0 === $count ) {
            set_transient( $key, 1, $window );
        } else {
            set_transient( $key, $count + 1, $window );
        }

        return true;
    }

    public static function check_user( $user_id = 0, $limit = 60 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        return self::check( 'user_' . $user_id, $limit );
    }

    public static function check_ip( $limit = 30 ) {
        $ip = self::get_client_ip();
        return self::check( 'ip_' . md5( $ip ), $limit );
    }

    public static function get_client_ip() {
        $headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
