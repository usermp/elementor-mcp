<?php
/**
 * Site Cloner — orchestrator for the fetch → analyze → translate → import flow.
 *
 * Public surface:
 *  - clone_url($url, $opts): end-to-end, returns Elementor array + diagnostics.
 *  - registered as REST endpoint POST /wp-json/mcp/v1/clone
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Site_Cloner {

    /**
     * Clone a whole site: discover pages, fetch + analyze + translate each
     * one, then create a matching WordPress page for each.
     *
     * @param string $home_url
     * @param array  $opts {
     *     @type string $status    Post status for new pages (default 'draft').
     *     @type string $note      Per-page extra note appended to AI prompt.
     *     @type string $model     Override OpenCode model id.
     *     @type bool   $dry_run   If true, never write to the DB.
     *     @type int    $max_pages Cap on pages to clone (default 6).
     * }
     *
     * @return array|\WP_Error {
     *     'site'      => [ 'home', 'pages'[] ],
     *     'pages'     => [ 'role' => ..., 'post_id' => ..., 'edit_url' => ..., 'view_url' => ..., 'stats' => ... ],
     *     'duration'  => float seconds,
     * }
     */
    public function clone_site( $home_url, array $opts = array() ) {
        $start = microtime( true );

        $crawler = new MCP_Site_Crawler();
        $map = $crawler->crawl( $home_url );
        if ( is_wp_error( $map ) ) {
            return $map;
        }

        $max = isset( $opts['max_pages'] ) ? max( 1, (int) $opts['max_pages'] ) : 6;
        $pages = array_slice( $map['pages'], 0, $max );

        $results = array();
        foreach ( $pages as $page_meta ) {
            $result = $this->clone_one( $page_meta, $map, $opts );
            if ( is_wp_error( $result ) ) {
                $results[] = array(
                    'role'   => $page_meta['role'],
                    'url'    => $page_meta['url'],
                    'error'  => $result->get_error_message(),
                );
                continue;
            }
            $results[] = $result;
        }

        return array(
            'site'     => array(
                'home'    => $map['home'],
                'brand'   => $map['brand'],
                'palette' => $map['palette'],
                'total_discovered' => count( $map['pages'] ),
            ),
            'pages'    => $results,
            'duration' => round( microtime( true ) - $start, 3 ),
        );
    }

    /**
     * Run the per-page pipeline for one entry in the site map.
     */
    private function clone_one( array $page_meta, array $map, array $opts ) {
        $fetcher = new MCP_Site_Fetcher();
        $fetched = $fetcher->fetch( $page_meta['url'] );
        if ( is_wp_error( $fetched ) ) {
            return $fetched;
        }

        $analyzer = new MCP_DOM_Analyzer();
        $analysis = $analyzer->analyze( $fetched['html'], array( 'max_sections' => 8 ) );

        $translator = new MCP_AI_Translator();
        $prompt = sprintf(
            "%s\n\nCross-page brand context:\n- Brand name: %s\n- Brand tagline: %s\n- Brand palette: %s",
            $page_meta['hint'],
            $map['brand']['name'] ?? '',
            $map['brand']['tagline'] ?? '',
            implode( ', ', array_slice( (array) ( $map['palette'] ?? array() ), 0, 6 ) )
        );
        if ( ! empty( $opts['note'] ) ) {
            $prompt .= "\n\nUser note: " . $opts['note'];
        }
        $sections = $translator->translate( $fetched, $analysis, array(
            'model'       => $opts['model'] ?? '',
            'prompt'      => $prompt,
            'temperature' => 0.3,
            'max_tokens'  => 8000,
        ) );
        if ( is_wp_error( $sections ) ) {
            return $sections;
        }

        $counts = array( 'sections' => 0, 'columns' => 0, 'widgets' => 0, 'bytes' => 0 );
        $walker = function ( $elements ) use ( &$walker, &$counts ) {
            foreach ( (array) $elements as $el ) {
                if ( ! is_array( $el ) ) continue;
                $t = isset( $el['elType'] ) ? $el['elType'] . 's' : '';
                if ( isset( $counts[ $t ] ) ) $counts[ $t ]++;
                if ( ! empty( $el['elements'] ) ) $walker( $el['elements'] );
            }
        };
        $walker( $sections );
        $counts['bytes'] = strlen( wp_json_encode( $sections ) );

        $result = array(
            'role'    => $page_meta['role'],
            'url'     => $page_meta['url'],
            'title'   => $fetched['title'] ?: $page_meta['title'],
            'post_id' => null,
            'edit_url'=> null,
            'view_url'=> null,
            'stats'   => $counts,
            'dry_run' => ! empty( $opts['dry_run'] ),
        );

        if ( ! empty( $opts['dry_run'] ) ) {
            return $result;
        }

        $title = $result['title'] ?: ucwords( $page_meta['role'] );
        $status = MCP_Validator::page_status( $opts['status'] ?? 'draft' );

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_name'    => sanitize_title( $title ),
            'post_status'  => $status,
            'post_type'    => 'page',
            'post_content' => '',
        ), true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $importer = new MCP_Importer();
        $imp = $importer->import( $sections, array( 'post_id' => (int) $post_id ) );
        if ( is_wp_error( $imp ) ) {
            return $imp;
        }

        $result['post_id']  = (int) $post_id;
        $result['edit_url'] = get_edit_post_link( $post_id, 'raw' );
        $result['view_url'] = get_permalink( $post_id );

        MCP_Logger::info( 'Site page cloned', array(
            'role' => $page_meta['role'],
            'url'  => $page_meta['url'],
            'post_id' => $post_id,
        ) );

        return $result;
    }

    /**
     * @return array|\WP_Error  Output of MCP_Site_Crawler::crawl()
     */
    public function discover( $home_url ) {
        $crawler = new MCP_Site_Crawler();
        return $crawler->crawl( $home_url );
    }

    /**
     * Convenience: clone just the homepage.
     *
     * @return array|\WP_Error
     */
    public function clone_url( $url, array $opts = array() ) {
        $fetcher = new MCP_Site_Fetcher();
        $fetched = $fetcher->fetch( $url );
        if ( is_wp_error( $fetched ) ) {
            return $fetched;
        }

        $analyzer = new MCP_DOM_Analyzer();
        $analysis = $analyzer->analyze( $fetched['html'], array( 'max_sections' => 8 ) );

        $translator = new MCP_AI_Translator();
        $sections   = $translator->translate( $fetched, $analysis, array(
            'model' => $opts['model'] ?? '',
            'prompt'=> $opts['note'] ?? '',
        ) );
        if ( is_wp_error( $sections ) ) {
            return $sections;
        }

        // Build the same stats shape MCP_Importer returns so the chat UI can
        // show it the same way.
        $counts = array( 'sections' => 0, 'columns' => 0, 'widgets' => 0, 'bytes' => 0 );
        $walker = function ( $elements ) use ( &$walker, &$counts ) {
            foreach ( (array) $elements as $el ) {
                if ( ! is_array( $el ) ) continue;
                $t = isset( $el['elType'] ) ? $el['elType'] . 's' : '';
                if ( isset( $counts[ $t ] ) ) $counts[ $t ]++;
                if ( ! empty( $el['elements'] ) ) $walker( $el['elements'] );
            }
        };
        $walker( $sections );
        $counts['bytes'] = strlen( wp_json_encode( $sections ) );

        $post_id = isset( $opts['post_id'] ) ? (int) $opts['post_id'] : 0;
        $dry_run = ! empty( $opts['dry_run'] );

        $result = array(
            'sections' => $sections,
            'stats'    => $counts,
            'source'   => array(
                'url'       => $fetched['final_url'] ?? $url,
                'http_code' => $fetched['http_code'] ?? 0,
                'title'     => $fetched['title'] ?? '',
                'elapsed'   => $fetched['elapsed'] ?? 0,
                'bytes'     => $fetched['bytes'] ?? 0,
            ),
            'analysis' => array(
                'sections'   => count( $analysis['sections'] ?? array() ),
                'palette'    => array_slice( (array) ( $analysis['palette'] ?? array() ), 0, 6 ),
                'typography' => $analysis['typography'] ?? array(),
                'stats'      => $analysis['stats'] ?? array(),
            ),
            'post_id'  => null,
            'edit_url' => null,
            'view_url' => null,
            'dry_run'  => $dry_run,
        );

        if ( $dry_run ) {
            return $result;
        }

        // Create a new page if no post_id was supplied.
        if ( $post_id <= 0 ) {
            $title = ! empty( $opts['title'] ) ? sanitize_text_field( $opts['title'] ) : ( $fetched['title'] ?: __( 'Cloned page', 'elementor-mcp' ) );
            $status = MCP_Validator::page_status( $opts['status'] ?? 'draft' );
            $created = wp_insert_post( array(
                'post_title'  => $title,
                'post_status' => $status,
                'post_type'   => 'page',
                'post_content' => '',
            ), true );
            if ( is_wp_error( $created ) ) {
                return $created;
            }
            $post_id = (int) $created;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'mcp_clone_no_post', __( 'Target post does not exist.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'mcp_clone_forbidden', __( 'You cannot edit this page.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        $importer = new MCP_Importer();
        $imp = $importer->import( $sections, array( 'post_id' => $post_id ) );
        if ( is_wp_error( $imp ) ) {
            return $imp;
        }

        $result['post_id']  = $post_id;
        $result['edit_url'] = get_edit_post_link( $post_id, 'raw' );
        $result['view_url'] = get_permalink( $post_id );
        return $result;
    }

    /* ---------- REST handler ---------- */

    /**
     * POST /wp-json/mcp/v1/clone
     */
    public static function handle_rest( WP_REST_Request $request ) {
        $url = trim( (string) $request->get_param( 'url' ) );
        if ( '' === $url ) {
            return new WP_Error( 'mcp_clone_no_url', __( 'URL is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $opts = array(
            'post_id' => (int) $request->get_param( 'post_id' ),
            'title'   => (string) $request->get_param( 'title' ),
            'status'  => (string) $request->get_param( 'status' ),
            'dry_run' => (bool) $request->get_param( 'dry_run' ),
            'model'   => (string) $request->get_param( 'model' ),
            'note'    => (string) $request->get_param( 'note' ),
        );

        // Permission gate.
        if ( ! current_user_can( 'edit_pages' ) ) {
            return new WP_Error( 'mcp_clone_forbidden', __( 'You cannot clone sites.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        $cloner = new self();
        $result = $cloner->clone_url( $url, $opts );
        if ( is_wp_error( $result ) ) {
            $code = (int) ( $result->get_error_data()['status'] ?? 502 );
            $result->add_data( array( 'status' => $code ) );
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public static function permissions_check( $request ) {
        if ( ! current_user_can( 'edit_pages' ) ) {
            return new WP_Error( 'mcp_clone_forbidden', __( 'You cannot clone sites.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        $settings = MCP_Plugin::get_settings();
        $limit = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl = MCP_Rate_Limiter::check_user( 0, $limit );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }
        return true;
    }
}