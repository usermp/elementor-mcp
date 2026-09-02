<?php
/**
 * Authentication helpers - App Password, signature verification.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Auth {

    public static function verify_webhook_signature( $payload, $signature, $secret, $algo = 'sha256' ) {
        if ( empty( $signature ) || empty( $secret ) ) {
            return false;
        }
        $expected = hash_hmac( $algo, $payload, $secret );
        return hash_equals( $expected, (string) $signature );
    }

    public static function verify_nonce( $nonce, $action = 'mcp_rest' ) {
        if ( empty( $nonce ) ) {
            return false;
        }
        return (bool) wp_verify_nonce( $nonce, $action );
    }

    public static function current_user_can_edit_pages() {
        return current_user_can( 'edit_pages' );
    }

    public static function current_user_can_manage() {
        return current_user_can( 'manage_options' );
    }

    public static function get_idempotency_key( $request ) {
        $header = $request->get_header( 'x-idempotency-key' );
        if ( $header ) {
            return sanitize_text_field( $header );
        }
        $body = $request->get_json_params();
        return isset( $body['idempotency_key'] ) ? sanitize_text_field( $body['idempotency_key'] ) : null;
    }

    public static function remember_response( $key, $response, $ttl = HOUR_IN_SECONDS ) {
        if ( ! $key ) {
            return;
        }
        set_transient( 'mcp_idem_' . md5( $key ), $response, $ttl );
    }

    public static function recall_response( $key ) {
        if ( ! $key ) {
            return null;
        }
        return get_transient( 'mcp_idem_' . md5( $key ) );
    }
}
