<?php
/**
 * Elementor JSON importer.
 *
 * Accepts either:
 *  - a raw Elementor v3 export envelope ({ "version": "...", "content": [...] })
 *  - a bare array of sections (the actual `_elementor_data` shape)
 *
 * Sanitizes IDs, strips unknown widget types, and writes meta into a target
 * post (page, template, or Kit). Also supports dry-run mode for previewing
 * the result before applying it.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Importer {

    /** Widgets that we refuse to import (system-only or unsafe). */
    const BLOCKED_WIDGETS = array(
        'global', // Elementor Pro global widgets can leak design tokens across sites
    );

    /** Hard caps to keep one import from blowing up the DB. */
    const MAX_ELEMENTS      = 5000;
    const MAX_NESTING_DEPTH = 25;
    const MAX_BYTES         = 4 * 1024 * 1024; // 4 MB

    /**
     * Parse an Elementor payload (string or array) into a normalized
     * sections array. Returns ['sections' => [...], 'page_settings' => [...], 'meta' => [...]]
     * or a WP_Error.
     *
     * @param string|array $input
     * @return array|\WP_Error
     */
    public function parse( $input ) {
        if ( is_string( $input ) ) {
            if ( strlen( $input ) > self::MAX_BYTES ) {
                return new WP_Error( 'mcp_import_too_large', __( 'Import payload exceeds 4 MB.', 'elementor-mcp' ), array( 'status' => 413 ) );
            }
            $decoded = json_decode( $input, true );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
                return new WP_Error( 'mcp_import_bad_json', __( 'Could not parse JSON.', 'elementor-mcp' ), array( 'status' => 400 ) );
            }
        } elseif ( is_array( $input ) ) {
            $decoded = $input;
        } else {
            return new WP_Error( 'mcp_import_bad_type', __( 'Import payload must be JSON string or array.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        // Detect Elementor export envelope vs bare sections array.
        if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
            $sections     = $decoded['content'];
            $page_set     = isset( $decoded['page_settings'] ) && is_array( $decoded['page_settings'] ) ? $decoded['page_settings'] : array();
            $meta         = array();
            foreach ( array( 'version', 'title', 'type', 'exported_at' ) as $k ) {
                if ( isset( $decoded[ $k ] ) ) {
                    $meta[ $k ] = $decoded[ $k ];
                }
            }
        } elseif ( array_is_list( $decoded ) ) {
            $sections = $decoded;
            $page_set = array();
            $meta     = array();
        } elseif ( isset( $decoded['elType'] ) ) {
            // Single element wrapped in object — accept as one-section page.
            $sections = array( $decoded );
            $page_set = array();
            $meta     = array();
        } else {
            return new WP_Error( 'mcp_import_unknown_shape', __( 'Unrecognized Elementor payload shape.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $normalized = $this->normalize_tree( $sections );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        return array(
            'sections'      => $normalized,
            'page_settings' => $this->sanitize_settings( $page_set ),
            'meta'          => $meta,
        );
    }

    /**
     * Run parse() and optionally write the result to a post.
     *
     * @param string|array $input
     * @param array $opts {
     *     @type int    $post_id      Target post ID. 0 = don't write, just parse.
     *     @type bool   $dry_run      If true, never touch the DB.
     *     @type string $post_type    'page' (default) | 'elementor_library'
     *     @type string $title        Title to use when creating a new post.
     *     @type string $status       Post status for new post.
     * }
     *
     * @return array|\WP_Error {
     *     'sections'      => array,
     *     'page_settings' => array,
     *     'meta'          => array,
     *     'stats'         => ['sections'=>N,'columns'=>N,'widgets'=>N,'blocked'=>N,'bytes'=>N],
     *     'post_id'       => int|null,    // set when actually written
     * }
     */
    public function import( $input, array $opts = array() ) {
        $parsed = $this->parse( $input );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $stats = $this->tree_stats( $parsed['sections'] );

        $post_id = isset( $opts['post_id'] ) ? (int) $opts['post_id'] : 0;
        $dry_run = ! empty( $opts['dry_run'] );

        $result = array(
            'sections'      => $parsed['sections'],
            'page_settings' => $parsed['page_settings'],
            'meta'          => $parsed['meta'],
            'stats'         => $stats,
            'post_id'       => null,
            'dry_run'       => $dry_run,
        );

        if ( $dry_run || $post_id <= 0 ) {
            return $result;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'mcp_import_post_missing', __( 'Target post does not exist.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }

        $allowed_types = array( 'page', 'elementor_library' );
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return new WP_Error( 'mcp_import_post_type', __( 'Target post type not supported for import.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'mcp_import_forbidden', __( 'You cannot edit this post.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        // Backup the previous _elementor_data so the caller can roll back.
        $previous = get_post_meta( $post_id, '_elementor_data', true );
        update_post_meta( $post_id, '_mcp_import_backup', $previous );
        update_post_meta( $post_id, '_mcp_imported_at', current_time( 'mysql' ) );

        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $parsed['sections'] ) ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

        if ( ! empty( $parsed['page_settings'] ) ) {
            update_post_meta( $post_id, '_elementor_page_settings', wp_slash( wp_json_encode( $parsed['page_settings'] ) ) );
        }

        if ( 'elementor_library' === $post->post_type && ! empty( $parsed['meta']['type'] ) ) {
            update_post_meta( $post_id, '_elementor_template_type', sanitize_key( $parsed['meta']['type'] ) );
        }

        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }

        MCP_Logger::info( 'Elementor import applied', array(
            'post_id' => $post_id,
            'bytes'   => strlen( wp_json_encode( $parsed['sections'] ) ),
            'stats'   => $stats,
        ) );

        do_action( 'mcp_import_applied', $post_id, $parsed );

        $result['post_id'] = $post_id;
        $result['post_type'] = $post->post_type;

        return $result;
    }

    /* ---------- helpers ---------- */

    /**
     * Walk the tree, regenerate stable IDs, drop blocked widgets,
     * enforce depth and element caps.
     */
    private function normalize_tree( array $elements, $depth = 0 ) {
        if ( $depth > self::MAX_NESTING_DEPTH ) {
            return new WP_Error( 'mcp_import_too_deep', __( 'Element tree exceeds maximum nesting depth.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $out = array();
        $counter = 0;

        foreach ( $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }

            $counter++;
            if ( $counter > self::MAX_ELEMENTS ) {
                return new WP_Error( 'mcp_import_too_many', __( 'Import exceeds maximum element count.', 'elementor-mcp' ), array( 'status' => 400 ) );
            }

            if ( empty( $el['elType'] ) ) {
                continue; // drop malformed
            }

            // Block disallowed widgets.
            if ( 'widget' === $el['elType'] ) {
                $wt = isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '';
                if ( in_array( $wt, self::BLOCKED_WIDGETS, true ) ) {
                    continue;
                }
            }

            $clean = array(
                'id'       => $this->new_id( $el['elType'] ),
                'elType'   => (string) $el['elType'],
                'settings' => $this->sanitize_settings( isset( $el['settings'] ) ? $el['settings'] : array() ),
                'elements' => array(),
            );

            // Preserve widgetType for widgets.
            if ( 'widget' === $el['elType'] && ! empty( $el['widgetType'] ) ) {
                $clean['widgetType'] = sanitize_key( $el['widgetType'] );
            }

            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $children = $this->normalize_tree( $el['elements'], $depth + 1 );
                if ( is_wp_error( $children ) ) {
                    return $children;
                }
                $clean['elements'] = $children;
            }

            $out[] = $clean;
        }

        return $out;
    }

    private function sanitize_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return array();
        }
        $clean = array();
        foreach ( $settings as $k => $v ) {
            $key = sanitize_key( $k );
            if ( '' === $key ) {
                continue;
            }
            if ( is_array( $v ) ) {
                $clean[ $key ] = $this->sanitize_settings( $v );
            } elseif ( is_scalar( $v ) ) {
                $clean[ $key ] = is_string( $v ) ? wp_kses_post( $v ) : $v;
            } else {
                // objects/booleans/null — drop
            }
        }
        return $clean;
    }

    private function tree_stats( array $elements, array &$stats = null ) {
        if ( null === $stats ) {
            $stats = array(
                'sections' => 0,
                'columns'  => 0,
                'widgets'  => 0,
                'blocked'  => 0,
                'bytes'    => 0,
            );
        }
        foreach ( $elements as $el ) {
            switch ( $el['elType'] ) {
                case 'section':
                    $stats['sections']++;
                    break;
                case 'column':
                    $stats['columns']++;
                    break;
                case 'widget':
                    $stats['widgets']++;
                    break;
            }
            if ( ! empty( $el['elements'] ) ) {
                $this->tree_stats( $el['elements'], $stats );
            }
        }
        $stats['bytes'] = strlen( wp_json_encode( $elements ) );
        return $stats;
    }

    private function new_id( $el_type ) {
        static $rng;
        if ( null === $rng ) {
            try {
                $rng = new \Random\Randomizer();
            } catch ( \Throwable $e ) {
                $rng = null;
            }
        }
        $prefix = substr( preg_replace( '/[^a-z]/', '', strtolower( (string) $el_type ) ), 0, 3 );
        if ( '' === $prefix ) {
            $prefix = 'el';
        }
        if ( $rng ) {
            $hex = substr( $rng->getBytes( 4 ), 0, 7 );
            return $prefix . bin2hex( random_bytes( 3 ) );
        }
        return $prefix . substr( str_replace( array( '.', '/' ), '', wp_generate_password( 6, false, false ) ), 0, 7 );
    }
}