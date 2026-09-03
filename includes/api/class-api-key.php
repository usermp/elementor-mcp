<?php
/**
 * API Key authentication for the agent surface.
 *
 * Keys are stored in option 'mcp_api_keys' as an array of:
 *   [ 'key' => 'mcp_xxx...', 'label' => 'Claude Code', 'created' => ts,
 *     'last_used' => ts, 'rate_limit' => 60, 'scopes' => ['read','write'] ]
 *
 * Send via header X-MCP-Key. The header is checked AFTER Application
 * Password auth, so any existing client keeps working.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Api_Key {

    const OPTION_KEYS = 'mcp_api_keys';
    const HEADER = 'X-MCP-Key';

    /**
     * @return array  List of registered keys (without the secret).
     */
    public static function all() {
        $keys = get_option( self::OPTION_KEYS, array() );
        return array_map( function ( $k ) {
            unset( $k['key'] );
            $k['key_preview'] = substr( $k['full_key'] ?? '', 0, 12 ) . '…';
            unset( $k['full_key'] );
            return $k;
        }, $keys );
    }

    /**
     * Generate a new key, persist, and return its plaintext exactly once.
     *
     * @param string $label
     * @param int    $rate_limit
     * @return array  ['key' => plaintext, 'label' => ..., 'rate_limit' => ...]
     */
    public static function create( $label, $rate_limit = 60 ) {
        $plaintext = 'mcp_' . bin2hex( random_bytes( 16 ) );
        $keys = get_option( self::OPTION_KEYS, array() );
        $keys[] = array(
            'full_key'   => wp_hash( $plaintext ),
            'label'      => sanitize_text_field( $label ),
            'created'    => time(),
            'last_used'  => 0,
            'rate_limit' => max( 1, (int) $rate_limit ),
            'scopes'     => array( 'read', 'write' ),
        );
        update_option( self::OPTION_KEYS, $keys );
        return array(
            'key'        => $plaintext,
            'label'      => $label,
            'rate_limit' => (int) $rate_limit,
        );
    }

    /**
     * Revoke a key by its preview or label.
     */
    public static function revoke( $label_or_preview ) {
        $keys = get_option( self::OPTION_KEYS, array() );
        $kept = array_values( array_filter( $keys, function ( $k ) use ( $label_or_preview ) {
            return $k['label'] !== $label_or_preview
                && ( substr( $k['full_key'] ?? '', 0, 12 ) . '…' ) !== $label_or_preview;
        } ) );
        update_option( self::OPTION_KEYS, $kept );
        return count( $kept ) < count( $keys );
    }

    /**
     * Look up a key by its plaintext. Returns the row or null.
     */
    public static function lookup( $plaintext ) {
        $hash = wp_hash( $plaintext );
        foreach ( (array) get_option( self::OPTION_KEYS, array() ) as $k ) {
            if ( isset( $k['full_key'] ) && hash_equals( $k['full_key'], $hash ) ) {
                return $k;
            }
        }
        return null;
    }

    /**
     * Mark a key as recently used. Throttled to once per minute to avoid
     * hammering the options table.
     */
    public static function touch( $label ) {
        $now = time();
        $keys = get_option( self::OPTION_KEYS, array() );
        foreach ( $keys as $i => $k ) {
            if ( $k['label'] === $label ) {
                if ( ( $now - (int) $k['last_used'] ) < 60 ) {
                    return;
                }
                $keys[ $i ]['last_used'] = $now;
                update_option( self::OPTION_KEYS, $keys );
                return;
            }
        }
    }

    /**
     * Resolve a request to a key row, or null if no API key auth applies.
     */
    public static function from_request( $request ) {
        $plaintext = $request->get_header( 'x_mcp_key' );
        if ( ! $plaintext ) {
            $plaintext = $request->get_header( 'x-mcp-key' );
        }
        if ( ! $plaintext ) {
            return null;
        }
        return self::lookup( $plaintext );
    }

    /**
     * Per-key rate-limit check. Reuses MCP_Rate_Limiter's transient-based bucket
     * but with a key-specific prefix so user and key quotas don't collide.
     */
    public static function check_rate( $key_row ) {
        $limit = (int) ( $key_row['rate_limit'] ?? 60 );
        if ( $limit <= 0 ) return true;
        $bucket = 'mcp_apikey_' . md5( $key_row['label'] ?? '' );
        return MCP_Rate_Limiter::check( $bucket, $limit );
    }
}