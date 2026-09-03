<?php
/**
 * Snapshot + rollback for Elementor data.
 *
 * Every time MCP touches a page (chat/apply, agent/tool calls, importer,
 * template builder) we can take a snapshot first. The snapshot stores the
 * pre-change _elementor_data, _elementor_page_settings, _elementor_version,
 * post title, slug, and status under a custom post type so it's queryable
 * and reviewable from the admin.
 *
 * Restoring is a single op: write the stored meta back and update the post.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Snapshot {

    const POST_TYPE = 'mcp_snapshot';
    const META_ORIG_POST_ID = '_mcp_orig_post_id';

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
    }

    /**
     * Register the post type at init so it's always available, not only
     * when Elementor is active.
     */
    public function register_hooks() {
        add_action( 'init', array( $this, 'register_post_type' ) );
    }

    public function register_post_type() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'          => __( 'MCP Snapshots', 'elementor-mcp' ),
                'singular_name' => __( 'MCP Snapshot', 'elementor-mcp' ),
            ),
            'public'       => false,
            'show_ui'      => current_user_can( 'manage_options' ),
            'show_in_menu' => 'elementor-mcp',
            'supports'     => array( 'title', 'editor', 'author' ),
            'capability_type' => 'post',
            'capabilities' => array(
                'create_posts' => 'edit_pages',
            ),
            'map_meta_cap' => true,
        ) );
    }

    /**
     * Take a snapshot of a page's current Elementor data + meta.
     *
     * @param int    $post_id
     * @param string $label  Optional human label
     * @return array|\WP_Error
     */
    public function take( $post_id, $label = '' ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'mcp_snap_no_post', __( 'Source post not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'mcp_snap_forbidden', __( 'Cannot snapshot this post.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        $meta = array(
            '_elementor_data'         => get_post_meta( $post_id, '_elementor_data', true ),
            '_elementor_page_settings'=> get_post_meta( $post_id, '_elementor_page_settings', true ),
            '_elementor_version'      => get_post_meta( $post_id, '_elementor_version', true ),
        );

        $snapshot_id = wp_insert_post( array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $label ?: sprintf( __( 'Snapshot of #%1$d taken %2$s', 'elementor-mcp' ), $post_id, current_time( 'mysql' ) ),
            'post_content' => wp_json_encode( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'slug'    => $post->post_name,
                'status'  => $post->post_status,
                'meta'    => $meta,
                'taken_at' => current_time( 'mysql' ),
            ) ),
        ), true );
        if ( is_wp_error( $snapshot_id ) ) {
            return $snapshot_id;
        }

        update_post_meta( $snapshot_id, self::META_ORIG_POST_ID, $post_id );
        MCP_Logger::info( 'Snapshot taken', array( 'post_id' => $post_id, 'snapshot_id' => $snapshot_id ) );

        return array(
            'snapshot_id' => (int) $snapshot_id,
            'post_id'     => $post_id,
            'label'       => $label,
            'taken_at'    => current_time( 'mysql' ),
        );
    }

    /**
     * Restore a page to a previously-taken snapshot.
     */
    public function restore( $snapshot_id ) {
        $snapshot = get_post( $snapshot_id );
        if ( ! $snapshot || self::POST_TYPE !== $snapshot->post_type ) {
            return new WP_Error( 'mcp_snap_not_found', __( 'Snapshot not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        $payload = json_decode( $snapshot->post_content, true );
        if ( ! is_array( $payload ) || empty( $payload['post_id'] ) ) {
            return new WP_Error( 'mcp_snap_corrupt', __( 'Snapshot data is corrupt.', 'elementor-mcp' ), array( 'status' => 500 ) );
        }
        $post_id = (int) $payload['post_id'];
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'mcp_snap_forbidden', __( 'Cannot restore this page.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        $meta = $payload['meta'] ?? array();

        if ( array_key_exists( '_elementor_data', $meta ) ) {
            update_post_meta( $post_id, '_elementor_data', wp_slash( $meta['_elementor_data'] ) );
        }
        if ( array_key_exists( '_elementor_page_settings', $meta ) ) {
            update_post_meta( $post_id, '_elementor_page_settings', wp_slash( $meta['_elementor_page_settings'] ) );
        }
        if ( ! empty( $meta['_elementor_version'] ) ) {
            update_post_meta( $post_id, '_elementor_version', $meta['_elementor_version'] );
        }

        // Bump modified so Elementor invalidates its cache.
        wp_update_post( array(
            'ID'             => $post_id,
            'post_modified'  => current_time( 'mysql' ),
            'post_modified_gmt' => current_time( 'mysql', 1 ),
        ) );

        if ( class_exists( '\\Elementor\\Plugin' ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        MCP_Logger::info( 'Snapshot restored', array( 'post_id' => $post_id, 'snapshot_id' => $snapshot_id ) );

        return array(
            'restored_to' => $post_id,
            'snapshot_id' => $snapshot_id,
            'restored_at' => current_time( 'mysql' ),
        );
    }

    /**
     * List recent snapshots for a page.
     */
    public function list_for( $post_id, $limit = 20 ) {
        $q = new WP_Query( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, (int) $limit ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array( 'key' => self::META_ORIG_POST_ID, 'value' => (int) $post_id, 'compare' => '=' ),
            ),
        ) );
        $items = array();
        foreach ( $q->posts as $p ) {
            $payload = json_decode( $p->post_content, true );
            $items[] = array(
                'id'         => (int) $p->ID,
                'label'      => $p->post_title,
                'taken_at'   => $p->post_date,
                'post_id'    => (int) ( $payload['post_id'] ?? 0 ),
                'title'      => $payload['title'] ?? '',
                'has_elementor' => ! empty( $payload['meta']['_elementor_data'] ),
            );
        }
        return array( 'items' => $items, 'total' => (int) $q->found_posts );
    }

    /**
     * Auto-snapshot helper: take a snapshot before a destructive op, but
     * only if there isn't already one taken in the last $ttl seconds for
     * this post. Prevents the table from filling with duplicates.
     */
    public function auto_snapshot( $post_id, $ttl = 60 ) {
        $existing = $this->list_for( $post_id, 1 );
        if ( ! empty( $existing['items'] ) ) {
            $last = $existing['items'][0];
            $last_ts = strtotime( $last['taken_at'] . ' UTC' );
            if ( $last_ts && ( time() - $last_ts ) < $ttl ) {
                return array( 'snapshot_id' => $last['id'], 'reused' => true );
            }
        }
        return $this->take( $post_id, 'Auto: ' . current_time( 'mysql' ) );
    }
}