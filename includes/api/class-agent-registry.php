<?php
/**
 * Agent Tool Registry — catalogue of capabilities the agent surface exposes.
 *
 * Each tool is a { name, description, args (JSON schema), capability, handler }.
 * The handler receives validated args and returns an array or WP_Error.
 *
 * Tools are intentionally flat: no chaining, no state. If a tool needs to
 * read site state, it asks WordPress directly via the existing service
 * classes (MCP_Page_Builder, MCP_AI_Translator, etc.).
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Agent_Registry {

    /** @var array<string, array> */
    private $tools = array();

    public function __construct() {
        $this->register_defaults();
    }

    /**
     * Register the built-in tools. Each one is a thin wrapper around an
     * existing service class. Adding a new tool is a single register() call.
     */
    private function register_defaults() {
        $this->register( 'page_list', 'List WordPress pages with Elementor data.', 'edit_pages', array(
            'properties' => array(
                'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
                'page'     => array( 'type' => 'integer', 'default' => 1,  'minimum' => 1 ),
            ),
        ), array( $this, 'tool_page_list' ) );

        $this->register( 'page_get', 'Get a single page with its Elementor data decoded.', 'edit_pages', array(
            'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
            'required'   => array( 'id' ),
        ), array( $this, 'tool_page_get' ) );

        $this->register( 'page_create', 'Create a new page (without Elementor).', 'publish_pages', array(
            'properties' => array(
                'title'  => array( 'type' => 'string' ),
                'status' => array( 'type' => 'string', 'default' => 'draft', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
            ),
            'required'   => array( 'title' ),
        ), array( $this, 'tool_page_create' ) );

        $this->register( 'page_apply_elementor', 'Write an Elementor JSON payload to a page. Returns the page id.', 'edit_pages', array(
            'properties' => array(
                'id'       => array( 'type' => 'integer', 'minimum' => 1 ),
                'sections' => array( 'type' => 'array' ),
            ),
            'required'   => array( 'id', 'sections' ),
        ), array( $this, 'tool_page_apply_elementor' ) );

        $this->register( 'ai_generate_elementor', 'Ask the AI to produce Elementor JSON from a prompt.', 'edit_pages', array(
            'properties' => array(
                'prompt'     => array( 'type' => 'string', 'minLength' => 3 ),
                'post_id'    => array( 'type' => 'integer', 'minimum' => 0 ),
                'history'    => array( 'type' => 'array' ),
                'model'      => array( 'type' => 'string' ),
            ),
            'required'   => array( 'prompt' ),
        ), array( $this, 'tool_ai_generate_elementor' ) );

        $this->register( 'site_context', 'Return site context: theme, active plugins, active kit, post counts.', 'edit_pages', array(
            'properties' => array(),
        ), array( $this, 'tool_site_context' ) );

        $this->register( 'template_list', 'List Elementor templates by type.', 'edit_posts', array(
            'properties' => array(
                'type' => array( 'type' => 'string', 'default' => '' ),
            ),
        ), array( $this, 'tool_template_list' ) );

        $this->register( 'snapshot_create', 'Take a snapshot of a page\'s Elementor data and meta.', 'edit_pages', array(
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                'label'   => array( 'type' => 'string', 'default' => '' ),
            ),
            'required'   => array( 'post_id' ),
        ), array( $this, 'tool_snapshot_create' ) );

        $this->register( 'snapshot_list', 'List recent snapshots for a page.', 'edit_pages', array(
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                'limit'   => array( 'type' => 'integer', 'default' => 20 ),
            ),
            'required'   => array( 'post_id' ),
        ), array( $this, 'tool_snapshot_list' ) );

        $this->register( 'snapshot_restore', 'Restore a page from a snapshot.', 'edit_pages', array(
            'properties' => array(
                'snapshot_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            ),
            'required'   => array( 'snapshot_id' ),
        ), array( $this, 'tool_snapshot_restore' ) );

        $this->register( 'media_upload_url', 'Download a remote image into the WordPress media library.', 'upload_files', array(
            'properties' => array(
                'url'            => array( 'type' => 'string' ),
                'title'          => array( 'type' => 'string' ),
                'alt'            => array( 'type' => 'string' ),
                'parent_post_id' => array( 'type' => 'integer' ),
            ),
            'required'   => array( 'url' ),
        ), array( $this, 'tool_media_upload_url' ) );

        $this->register( 'block_repair', 'Audit or auto-fix an Elementor page\'s data.', 'edit_pages', array(
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                'mode'    => array( 'type' => 'string', 'enum' => array( 'scan', 'audit', 'auto_fix' ), 'default' => 'scan' ),
            ),
            'required'   => array( 'post_id' ),
        ), array( $this, 'tool_block_repair' ) );

        $this->register( 'content_search', 'Search post and template titles, with Elementor data presence flag.', 'edit_pages', array(
            'properties' => array(
                'q'    => array( 'type' => 'string', 'minLength' => 2 ),
                'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post', 'template', 'any' ), 'default' => 'any' ),
                'limit' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
            ),
            'required'   => array( 'q' ),
        ), array( $this, 'tool_content_search' ) );
    }

    public function register( $name, $description, $capability, $schema, $handler ) {
        $this->tools[ $name ] = compact( 'name', 'description', 'capability', 'schema', 'handler' );
    }

    public function all() {
        return array_values( $this->tools );
    }

    public function get( $name ) {
        return $this->tools[ $name ] ?? null;
    }

    /**
     * Validate $args against a tool's schema. Lightweight validator: checks
     * required keys, primitive types, and enum membership. No recursion
     * (we keep all top-level values primitive or flat arrays of strings).
     *
     * @return true|\WP_Error
     */
    public function validate_args( array $tool, array $args ) {
        $required = $tool['schema']['required'] ?? array();
        foreach ( $required as $key ) {
            if ( ! array_key_exists( $key, $args ) ) {
                return new WP_Error( 'mcp_tool_missing_arg', sprintf( 'Missing required arg: %s', $key ), array( 'status' => 400 ) );
            }
        }
        $props = $tool['schema']['properties'] ?? array();
        foreach ( $args as $key => $value ) {
            if ( ! isset( $props[ $key ] ) ) {
                continue; // extra args are tolerated
            }
            $type = $props[ $key ]['type'] ?? 'string';
            $ok = $this->type_matches( $type, $value );
            if ( ! $ok ) {
                return new WP_Error( 'mcp_tool_bad_type', sprintf( 'Arg "%s" expected %s', $key, $type ), array( 'status' => 400 ) );
            }
            if ( isset( $props[ $key ]['enum'] ) && ! in_array( $value, $props[ $key ]['enum'], true ) ) {
                return new WP_Error( 'mcp_tool_bad_enum', sprintf( 'Arg "%s" not in enum', $key ), array( 'status' => 400 ) );
            }
        }
        return true;
    }

    private function type_matches( $type, $value ) {
        if ( null === $value ) return true;
        switch ( $type ) {
            case 'string':  return is_string( $value ) || is_numeric( $value );
            case 'integer': return is_int( $value ) || ( is_string( $value ) && ctype_digit( (string) $value ) );
            case 'number':  return is_numeric( $value );
            case 'boolean': return is_bool( $value ) || in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true );
            case 'array':   return is_array( $value );
            case 'object':  return is_array( $value ) || is_object( $value );
        }
        return true;
    }

    /* ---------- tool handlers ---------- */

    public function tool_page_list( $args ) {
        $q = new WP_Query( array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => (int) ( $args['per_page'] ?? 20 ),
            'paged'          => (int) ( $args['page'] ?? 1 ),
            'no_found_rows'  => true,
        ) );
        $items = array();
        foreach ( $q->posts as $p ) {
            $items[] = array(
                'id'        => (int) $p->ID,
                'title'     => $p->post_title,
                'status'    => $p->post_status,
                'slug'      => $p->post_name,
                'has_elementor' => (bool) get_post_meta( $p->ID, '_elementor_data', true ),
            );
        }
        return array( 'items' => $items, 'total' => (int) $q->found_posts );
    }

    public function tool_page_get( $args ) {
        $post = get_post( (int) $args['id'] );
        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'mcp_tool_not_found', __( 'Page not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        $raw = get_post_meta( $post->ID, '_elementor_data', true );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
        return array(
            'id'      => (int) $post->ID,
            'title'   => $post->post_title,
            'status'  => $post->post_status,
            'sections'=> $data,
            'page_settings' => json_decode( (string) get_post_meta( $post->ID, '_elementor_page_settings', true ), true ),
        );
    }

    public function tool_page_create( $args ) {
        $id = wp_insert_post( array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_name'    => sanitize_title( $args['title'] ),
            'post_status'  => MCP_Validator::page_status( $args['status'] ?? 'draft' ),
            'post_type'    => 'page',
            'post_content' => '',
        ), true );
        if ( is_wp_error( $id ) ) return $id;
        return array( 'id' => (int) $id );
    }

    public function tool_page_apply_elementor( $args ) {
        $post_id = (int) $args['id'];
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'mcp_tool_forbidden', __( 'You cannot edit this page.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        $importer = new MCP_Importer();
        $result = $importer->import( $args['sections'], array( 'post_id' => $post_id ) );
        if ( is_wp_error( $result ) ) return $result;
        return array( 'id' => $post_id, 'stats' => $result['stats'] );
    }

    public function tool_ai_generate_elementor( $args ) {
        $client = new MCP_OpenCode_Client();
        $opts = array( 'history' => (array) ( $args['history'] ?? array() ) );
        if ( ! empty( $args['model'] ) ) $opts['model'] = $args['model'];

        $result = $client->generate_elementor( (string) $args['prompt'], $opts );
        if ( is_wp_error( $result ) ) return $result;
        $stats = array( 'sections' => count( $result ), 'widgets' => 0, 'bytes' => strlen( wp_json_encode( $result ) ) );
        return array( 'sections' => $result, 'stats' => $stats );
    }

    public function tool_site_context( $args ) {
        $theme = wp_get_theme();
        $active_plugins = get_option( 'active_plugins', array() );
        $kit_id = (int) get_option( \Elementor\Core\Kits\Documents\Kit::OPTION_ACTIVE );
        return array(
            'wp_version'    => get_bloginfo( 'version' ),
            'php_version'   => PHP_VERSION,
            'theme'         => $theme->get( 'Name' ),
            'theme_version' => $theme->get( 'Version' ),
            'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
            'active_kit_id' => $kit_id ?: null,
            'active_plugins'=> array_values( array_map( function ( $p ) {
                $parts = explode( '/', $p );
                return $parts[0];
            }, $active_plugins ) ),
            'post_counts'   => array(
                'pages'      => (int) wp_count_posts( 'page' )->publish,
                'templates'  => (int) wp_count_posts( 'elementor_library' )->publish,
            ),
        );
    }

    public function tool_template_list( $args ) {
        $query = array(
            'post_type'      => 'elementor_library',
            'post_status'    => 'any',
            'posts_per_page' => 50,
        );
        if ( ! empty( $args['type'] ) ) {
            $query['meta_query'] = array(
                array( 'key' => '_elementor_template_type', 'value' => sanitize_key( $args['type'] ) ),
            );
        }
        $q = new WP_Query( $query );
        $items = array();
        foreach ( $q->posts as $p ) {
            $items[] = array(
                'id'    => (int) $p->ID,
                'title' => $p->post_title,
                'type'  => get_post_meta( $p->ID, '_elementor_template_type', true ),
            );
        }
        return array( 'items' => $items );
    }

    public function tool_snapshot_create( $args ) {
        $snap = new MCP_Snapshot();
        return $snap->take( (int) $args['post_id'], (string) ( $args['label'] ?? '' ) );
    }

    public function tool_snapshot_list( $args ) {
        $snap = new MCP_Snapshot();
        return $snap->list_for( (int) $args['post_id'], (int) ( $args['limit'] ?? 20 ) );
    }

    public function tool_snapshot_restore( $args ) {
        $snap = new MCP_Snapshot();
        return $snap->restore( (int) $args['snapshot_id'] );
    }

    public function tool_media_upload_url( $args ) {
        $uploader = new MCP_Media_Uploader();
        return $uploader->upload_from_url( (string) $args['url'], array(
            'title'          => (string) ( $args['title'] ?? '' ),
            'alt'            => (string) ( $args['alt'] ?? '' ),
            'parent_post_id' => (int) ( $args['parent_post_id'] ?? 0 ),
        ) );
    }

    public function tool_block_repair( $args ) {
        $r = new MCP_Block_Repair();
        return $r->run( (int) $args['post_id'], (string) ( $args['mode'] ?? 'scan' ) );
    }

    public function tool_content_search( $args ) {
        $q = sanitize_text_field( (string) $args['q'] );
        $type = (string) ( $args['type'] ?? 'any' );
        $limit = (int) ( $args['limit'] ?? 20 );

        $post_types = ( 'any' === $type )
            ? array( 'page', 'post', 'elementor_library' )
            : array( $type );
        if ( 'template' === $type ) {
            $post_types = array( 'elementor_library' );
        }

        $query = new WP_Query( array(
            'post_type'      => $post_types,
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            's'              => $q,
            'no_found_rows'  => true,
        ) );

        $items = array();
        foreach ( $query->posts as $p ) {
            $items[] = array(
                'id'        => (int) $p->ID,
                'title'     => $p->post_title,
                'type'      => $p->post_type,
                'status'    => $p->post_status,
                'has_elementor' => (bool) get_post_meta( $p->ID, '_elementor_data', true ),
            );
        }
        return array( 'items' => $items, 'total' => (int) $query->found_posts );
    }
}