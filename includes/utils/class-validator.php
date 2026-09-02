<?php
/**
 * Input validator and sanitizer.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Validator {

    public static function sanitize_text( $value ) {
        if ( is_null( $value ) ) {
            return '';
        }
        return sanitize_text_field( (string) $value );
    }

    public static function sanitize_int( $value ) {
        return (int) $value;
    }

    public static function sanitize_bool( $value ) {
        return (bool) $value;
    }

    public static function sanitize_html( $value ) {
        if ( is_null( $value ) ) {
            return '';
        }
        return wp_kses_post( (string) $value );
    }

    public static function sanitize_json( $value ) {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return null;
            }
            return $decoded;
        }
        if ( is_array( $value ) ) {
            return $value;
        }
        return null;
    }

    public static function sanitize_slug( $value ) {
        return sanitize_title( (string) $value );
    }

    public static function is_non_empty_string( $value ) {
        return is_string( $value ) && '' !== trim( $value );
    }

    public static function is_positive_int( $value ) {
        return is_numeric( $value ) && (int) $value > 0;
    }

    public static function page_status( $value ) {
        $allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
        $value   = is_string( $value ) ? $value : 'draft';
        return in_array( $value, $allowed, true ) ? $value : 'draft';
    }
}
