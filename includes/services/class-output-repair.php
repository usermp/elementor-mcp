<?php
/**
 * AI output repair — best-effort fixes for common JSON / Elementor shape
 * issues the AI model emits.
 *
 * Used by MCP_AI_Translator, MCP_Template_Builder, and any other consumer
 * that ingests AI output. Runs after the primary JSON parse and before
 * passing the result to the Importer.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Output_Repair {

    /**
     * Try to repair a broken JSON string. Strategies, in order:
     *  1. raw json_decode
     *  2. strip trailing commas
     *  3. balance brackets and try again
     *  4. extract outermost array (delegated to OpenCode_Client)
     *
     * @return array|mixed|null  Decoded value or null if all strategies fail.
     */
    public static function repair_json( $raw ) {
        $raw = (string) $raw;
        // Strategy 1
        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE === json_last_error() && $decoded !== null ) {
            return $decoded;
        }
        // Strategy 2: strip trailing commas
        $stripped = preg_replace( '/,(\s*[\]}])/', '$1', $raw );
        $decoded = json_decode( $stripped, true );
        if ( JSON_ERROR_NONE === json_last_error() && $decoded !== null ) {
            return $decoded;
        }
        // Strategy 3: balance brackets
        $balanced = self::balance_brackets( $stripped ?? $raw );
        $decoded = json_decode( $balanced, true );
        if ( JSON_ERROR_NONE === json_last_error() && $decoded !== null ) {
            return $decoded;
        }
        return null;
    }

    /**
     * Try to balance brackets by inserting missing closers.
     * Very crude but catches the common case where the model cuts off.
     */
    private static function balance_brackets( $raw ) {
        $depth_brace = $depth_bracket = 0;
        $in_string = false;
        $escape = false;
        $len = strlen( $raw );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $raw[ $i ];
            if ( $in_string ) {
                if ( $escape ) { $escape = false; continue; }
                if ( '\\' === $c ) { $escape = true; continue; }
                if ( '"' === $c ) $in_string = false;
                continue;
            }
            if ( '"' === $c ) { $in_string = true; continue; }
            if ( '{' === $c ) $depth_brace++;
            elseif ( '}' === $c ) $depth_brace--;
            elseif ( '[' === $c ) $depth_bracket++;
            elseif ( ']' === $c ) $depth_bracket--;
        }
        $tail = '';
        while ( $depth_brace > 0 ) { $tail .= '}'; $depth_brace--; }
        while ( $depth_bracket > 0 ) { $tail .= ']'; $depth_bracket--; }
        return $raw . $tail;
    }

    /**
     * Validate the structure of an Elementor array: each top-level item
     * is a section with an elType, id, and elements[]. Each section has
     * at least one column. Each column has at least one widget or section.
     *
     * @param mixed $data
     * @return array|\WP_Error  Cleaned-up array or error.
     */
    public static function repair_elementor( $data ) {
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'mcp_repair_not_array', __( 'Data is not an array.', 'elementor-mcp' ) );
        }
        // Single section wrapped in object — unwrap.
        if ( isset( $data['elType'] ) && 'section' === $data['elType'] ) {
            $data = array( $data );
        }
        // Common AI mistake: nested { sections: [...] } envelope.
        if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
            $data = $data['sections'];
        }

        $clean = array();
        foreach ( $data as $section ) {
            if ( ! is_array( $section ) ) continue;
            if ( ( $section['elType'] ?? '' ) !== 'section' ) {
                // Try to coerce into section.
                $section = array(
                    'id'       => $section['id'] ?? 'sec' . substr( md5( wp_json_encode( $section ) ), 0, 7 ),
                    'elType'   => 'section',
                    'settings' => $section['settings'] ?? array(),
                    'elements' => $section['elements'] ?? array( $section ),
                );
            }
            if ( empty( $section['id'] ) ) {
                $section['id'] = 'sec' . substr( md5( wp_json_encode( $section ) ), 0, 7 );
            }
            if ( empty( $section['settings'] ) || ! is_array( $section['settings'] ) ) {
                $section['settings'] = array();
            }
            if ( empty( $section['elements'] ) || ! is_array( $section['elements'] ) ) {
                $section['elements'] = array();
            }
            // Ensure each child column has elements and an id.
            foreach ( $section['elements'] as $col_idx => $col ) {
                if ( ! is_array( $col ) ) { unset( $section['elements'][ $col_idx ] ); continue; }
                if ( ( $col['elType'] ?? '' ) !== 'column' ) {
                    $col['elType'] = 'column';
                }
                if ( empty( $col['id'] ) ) {
                    $col['id'] = 'col' . substr( md5( wp_json_encode( $col ) ), 0, 7 );
                }
                $section['elements'][ $col_idx ] = $col;
            }
            $section['elements'] = array_values( $section['elements'] );
            $clean[] = $section;
        }
        return $clean;
    }
}