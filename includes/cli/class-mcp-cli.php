<?php
/**
 * WP-CLI commands for Elementor MCP.
 *
 * Add under the existing `wp mcp` namespace:
 *   wp mcp status                      — show plugin status + key counters
 *   wp mcp build-template --brand="..."  — build a template via Template_Builder
 *   wp mcp clone <url> --max=4         — clone a public site
 *   wp mcp tools                       — list available agent tools
 *   wp mcp errors --limit=20           — show recent errors
 *   wp mcp api-key create <label>      — create a new agent API key
 *   wp mcp api-key list                — list keys
 *   wp mcp api-key revoke <label>      — revoke a key
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_CLI {

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        WP_CLI::add_command( 'mcp status', array( __CLASS__, 'status' ) );
        WP_CLI::add_command( 'mcp build-template', array( __CLASS__, 'build_template' ) );
        WP_CLI::add_command( 'mcp clone', array( __CLASS__, 'clone_site' ) );
        WP_CLI::add_command( 'mcp tools', array( __CLASS__, 'tools' ) );
        WP_CLI::add_command( 'mcp errors', array( __CLASS__, 'errors' ) );
        WP_CLI::add_command( 'mcp api-key', array( __CLASS__, 'api_key' ) );
    }

    public static function status() {
        $settings = MCP_Plugin::get_settings();
        WP_CLI::log( sprintf( 'Elementor MCP %s', MCP_VERSION ) );
        WP_CLI::log( sprintf( 'WP %s · PHP %s · Elementor %s', get_bloginfo( 'version' ), PHP_VERSION, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'inactive' ) );
        WP_CLI::log( sprintf( 'Model: %s', $settings['ai_model'] ) );
        WP_CLI::log( sprintf( 'API key: %s', '' !== $settings['ai_api_key'] ? 'set' : 'MISSING' ) );
        $stats = MCP_Error_Tracker::stats();
        WP_CLI::log( sprintf( 'Errors tracked: %d total', $stats['total'] ) );
        if ( $stats['total'] > 0 ) {
            foreach ( $stats['by_code'] as $code => $count ) {
                WP_CLI::log( sprintf( '  %s: %d', $code, $count ) );
            }
        }
    }

    /**
     * ## OPTIONS
     * [--brand=<brand>]
     * [--tagline=<tagline>]
     * [--description=<description>]
     * [--industry=<industry>]
     * [--design=<design>]
     * [--language=<language>]
     * [--status=<status>]
     */
    public static function build_template( $args, $assoc ) {
        $brand = $assoc['brand'] ?? 'Brand';
        $brief = array(
            'industry'      => $assoc['industry'] ?? 'general',
            'brand_name'    => $brand,
            'tagline'       => $assoc['tagline'] ?? '',
            'description'   => $assoc['description'] ?? '',
            'language'      => $assoc['language'] ?? 'en',
            'design_system' => $assoc['design'] ?? 'modern_saas',
            'sections'      => array( 'header', 'hero', 'features', 'about', 'testimonials', 'cta', 'footer' ),
        );
        $status = $assoc['status'] ?? 'draft';

        WP_CLI::log( sprintf( 'Building template for "%s" with %s design…', $brand, $brief['design_system'] ) );

        $builder = new MCP_Template_Builder();
        $built = $builder->build( $brief );
        if ( is_wp_error( $built ) ) {
            WP_CLI::error( $built->get_error_message() );
        }
        $page = $builder->create_page( $built, array( 'status' => $status ) );
        if ( is_wp_error( $page ) ) {
            WP_CLI::error( $page->get_error_message() );
        }
        WP_CLI::success( sprintf( 'Page #%d created: %s (%d sections, %d widgets, %d bytes)',
            $page['post_id'], get_permalink( $page['post_id'] ),
            $built['stats']['sections'], $built['stats']['widgets'], $built['stats']['bytes'] ) );
    }

    /**
     * ## OPTIONS
     * <url>
     * [--max=<n>]
     * [--status=<status>]
     */
    public static function clone_site( $args, $assoc ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'URL is required.' );
        }
        $url = $args[0];
        $opts = array(
            'status'    => $assoc['status'] ?? 'draft',
            'max_pages' => (int) ( $assoc['max'] ?? 4 ),
        );
        WP_CLI::log( sprintf( 'Cloning %s (max %d pages)…', $url, $opts['max_pages'] ) );

        $cloner = new MCP_Site_Cloner();
        $result = $cloner->clone_site( $url, $opts );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        foreach ( $result['pages'] as $p ) {
            if ( isset( $p['error'] ) ) {
                WP_CLI::warning( sprintf( '%s: %s', $p['role'], $p['error'] ) );
            } else {
                WP_CLI::log( sprintf( '%s → post #%d (%d sections, %d widgets)',
                    $p['role'], $p['post_id'], $p['stats']['sections'], $p['stats']['widgets'] ) );
            }
        }
        WP_CLI::success( sprintf( 'Done in %ss.', $result['duration'] ) );
    }

    public static function tools() {
        $reg = new MCP_Agent_Registry();
        foreach ( $reg->all() as $t ) {
            WP_CLI::log( sprintf( '%-25s [%s] %s', $t['name'], $t['capability'], $t['description'] ) );
        }
    }

    /**
     * ## OPTIONS
     * [--limit=<n>]
     */
    public static function errors( $args, $assoc ) {
        $limit = (int) ( $assoc['limit'] ?? 20 );
        foreach ( MCP_Error_Tracker::recent( $limit ) as $e ) {
            WP_CLI::log( sprintf( '[%s] %s · %s', $e['level'], $e['code'], $e['time'] ) );
            if ( ! empty( $e['context'] ) ) {
                WP_CLI::log( '  ' . wp_json_encode( $e['context'] ) );
            }
        }
    }

    public static function api_key( $args, $assoc ) {
        $sub = $args[0] ?? '';
        if ( 'create' === $sub ) {
            $label = $args[1] ?? 'Unnamed';
            $rate = (int) ( $assoc['rate'] ?? 60 );
            $result = MCP_Api_Key::create( $label, $rate );
            WP_CLI::success( sprintf( 'Created key for "%s" (rate=%d):', $label, $rate ) );
            WP_CLI::log( '  ' . $result['key'] );
            WP_CLI::warning( 'Save this now — it will not be shown again.' );
            return;
        }
        if ( 'list' === $sub ) {
            foreach ( MCP_Api_Key::all() as $k ) {
                WP_CLI::log( sprintf( '%-20s  %s', $k['label'], $k['key_preview'] ) );
            }
            return;
        }
        if ( 'revoke' === $sub ) {
            $label = $args[1] ?? '';
            if ( ! $label ) WP_CLI::error( 'Label is required.' );
            $ok = MCP_Api_Key::revoke( $label );
            if ( $ok ) {
                WP_CLI::success( "Revoked: $label" );
            } else {
                WP_CLI::warning( "No key matching: $label" );
            }
            return;
        }
        WP_CLI::error( 'Use: create | list | revoke' );
    }
}