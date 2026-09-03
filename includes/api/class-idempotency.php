<?php
/**
 * Idempotency + response cache.
 *
 * Clients can send X-Idempotency-Key (or include "idempotency_key" in the body).
 * For write endpoints (chat/apply, clone, agent/tools/call) the server stores
 * the response and replays it if the same key arrives again, so retries are
 * safe and the AI isn't billed twice for the same request.
 *
 * Cache TTL is 1 hour by default. Reads pass through untouched.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Idempotency {

    const TTL = HOUR_IN_SECONDS;
    const HEADER = 'X-Idempotency-Key';

    /**
     * @return string|null  The idempotency key (or null if not provided).
     */
    public static function key_from_request( $request ) {
        $k = $request->get_header( 'x_idempotency_key' );
        if ( ! $k ) {
            $k = $request->get_header( 'x-idempotency-key' );
        }
        if ( ! $k ) {
            $body = $request->get_json_params();
            $k = is_array( $body ) ? ( $body['idempotency_key'] ?? null ) : null;
        }
        if ( ! is_string( $k ) || '' === trim( $k ) ) return null;
        // Bound length to keep transient keys reasonable.
        $k = substr( trim( $k ), 0, 128 );
        if ( '' === $k ) return null;
        return $k;
    }

    /**
     * Look up a previous response by idempotency key. Returns null on miss.
     */
    public static function recall( $key ) {
        return get_transient( 'mcp_idem_' . md5( $key ) );
    }

    /**
     * Persist a response under an idempotency key.
     */
    public static function remember( $key, $response ) {
        if ( ! $key ) return;
        set_transient( 'mcp_idem_' . md5( $key ), $response, self::TTL );
    }

    /**
     * Hash of the request payload (body + endpoint), used to detect
     * "same key, different request" mismatches.
     */
    public static function request_signature( $request ) {
        $body = (string) $request->get_body();
        $route = $request->get_route();
        return md5( $route . '|' . $body );
    }
}