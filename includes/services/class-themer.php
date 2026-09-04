<?php
/**
 * Themer — minimal Elementor Theme Builder.
 *
 * Allows the user to create reusable Header, Footer, Single, Archive, and
 * 404 templates as Elementor Library entries, and injects them into
 * the corresponding locations of the front-end.
 *
 * Inspired by (but a significant simplification of) the themer module in
 * msrbuilds/elementor-mcp. We only model:
 *  - mcp_template_part custom post type (header / footer / single / archive / 404)
 *  - Elementor JSON stored in _elementor_data
 *  - Conditional rendering via URL match (is_singular / is_archive / is_404)
 *  - Priority ordering so users can have a fallback + an override
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Themer {

    const POST_TYPE = 'mcp_template_part';
    const META_LOCATION = '_mcp_location';
    const META_CONDITIONS = '_mcp_conditions';
    const META_PRIORITY = '_mcp_priority';

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'wp_head', array( $this, 'render_header' ), 1 );
        add_action( 'wp_footer', array( $this, 'render_footer' ), 1 );
        add_filter( 'template_include', array( $this, 'maybe_render_single' ), 99 );
        add_action( 'template_redirect', array( $this, 'maybe_render_404' ) );
    }

    public function register_post_type() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'          => __( 'MCP Theme Parts', 'elementor-mcp' ),
                'singular_name' => __( 'MCP Theme Part', 'elementor-mcp' ),
                'add_new_item'  => __( 'Add New Theme Part', 'elementor-mcp' ),
                'edit_item'     => __( 'Edit Theme Part', 'elementor-mcp' ),
            ),
            'public'       => false,
            'show_ui'      => current_user_can( 'edit_pages' ),
            'show_in_menu' => 'elementor-mcp',
            'supports'     => array( 'title', 'editor' ),
            'capability_type' => 'post',
            'capabilities' => array( 'create_posts' => 'edit_pages' ),
            'map_meta_cap' => true,
        ) );
    }

    /**
     * Create a theme part programmatically.
     *
     * @param array $args {
     *     @type string $title
     *     @type string $location  header | footer | single | archive | 404
     *     @type array  $sections  Elementor sections array
     *     @type array  $conditions  Optional. e.g. ['post_type' => 'page']
     *     @type int    $priority    Higher wins (default 0).
     * }
     */
    public function create( array $args ) {
        $title    = sanitize_text_field( $args['title'] ?? 'Untitled' );
        $location = sanitize_key( $args['location'] ?? 'header' );
        if ( ! in_array( $location, array( 'header', 'footer', 'single', 'archive', '404' ), true ) ) {
            return new WP_Error( 'mcp_themer_bad_location', __( 'Invalid location.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        $id = wp_insert_post( array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => '',
        ), true );
        if ( is_wp_error( $id ) ) return $id;

        update_post_meta( $id, self::META_LOCATION, $location );
        update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $args['sections'] ?? array() ) ) );
        update_post_meta( $id, '_elementor_edit_mode', 'builder' );
        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
        }
        if ( ! empty( $args['conditions'] ) ) {
            update_post_meta( $id, self::META_CONDITIONS, $args['conditions'] );
        }
        update_post_meta( $id, self::META_PRIORITY, (int) ( $args['priority'] ?? 0 ) );
        return (int) $id;
    }

    /**
     * Find the highest-priority part for a location that matches the current
     * request context. Returns the post id or 0.
     */
    public function find_match( $location, array $context = array() ) {
        $q = new WP_Query( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'meta_key'       => self::META_PRIORITY,
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => self::META_LOCATION, 'value' => $location ),
            ),
        ) );
        foreach ( (array) $q->posts as $p ) {
            $conditions = (array) get_post_meta( $p->ID, self::META_CONDITIONS, true );
            if ( $this->conditions_match( $conditions, $context ) ) {
                return (int) $p->ID;
            }
        }
        return 0;
    }

    /**
     * Resolve the current request into a context array for condition matching.
     */
    public function current_context() {
        $ctx = array(
            'is_singular' => is_singular(),
            'is_archive'  => is_archive(),
            'is_404'      => is_404(),
            'is_front'    => is_front_page(),
            'post_type'   => get_post_type() ?: '',
            'post_id'     => (int) get_queried_object_id(),
        );
        return $ctx;
    }

    /**
     * @param array $rules  e.g. ['post_type' => 'page', 'is_singular' => true]
     * @param array $ctx
     * @return bool
     */
    private function conditions_match( $rules, $ctx ) {
        if ( empty( $rules ) ) return true;
        foreach ( $rules as $key => $expected ) {
            $actual = $ctx[ $key ] ?? null;
            // is_* rules compare booleans.
            if ( in_array( $key, array( 'is_singular', 'is_archive', 'is_404', 'is_front' ), true ) ) {
                if ( (bool) $actual !== (bool) $expected ) return false;
                continue;
            }
            // post_type / post_id compare values.
            if ( $actual != $expected ) return false;
        }
        return true;
    }

    /**
     * Render an Elementor template part by post id. Falls back to '' if
     * Elementor isn't active or the post is missing.
     */
    private function render_part( $post_id ) {
        if ( ! $post_id ) return;
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) return;
        if ( get_post_status( $post_id ) !== 'publish' ) return;
        echo '<div class="mcp-theme-part mcp-theme-part-' . esc_attr( get_post_meta( $post_id, self::META_LOCATION, true ) ) . '" data-mcp-part="' . (int) $post_id . '">';
        echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $post_id );
        echo '</div>';
    }

    public function render_header() {
        if ( is_admin() ) return;
        $id = $this->find_match( 'header', $this->current_context() );
        $this->render_part( $id );
    }

    public function render_footer() {
        if ( is_admin() ) return;
        $id = $this->find_match( 'footer', $this->current_context() );
        $this->render_part( $id );
    }

    /**
     * Replace single-post template with a custom Elementor template if one matches.
     */
    public function maybe_render_single( $template ) {
        if ( ! is_singular() ) return $template;
        $id = $this->find_match( 'single', $this->current_context() );
        if ( ! $id ) return $template;
        $this->render_part( $id );
        return __DIR__ . '/themer/blank-template.php';
    }

    public function maybe_render_404() {
        if ( ! is_404() ) return;
        $id = $this->find_match( '404', $this->current_context() );
        if ( ! $id ) return;
        status_header( 200 );
        $this->render_part( $id );
        exit;
    }
}