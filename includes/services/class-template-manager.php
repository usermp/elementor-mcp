<?php
/**
 * Template / Kit manager for Elementor.
 *
 * Handles Elementor Templates and the global Kit (elementor_active_kit).
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Template_Manager {

    const TEMPLATE_TYPE     = 'elementor_library';
    const KIT_OPTION        = 'elementor_active_kit';
    const KIT_META_SETTINGS = 'elementor_page_settings';
    const KIT_META_VERSION  = '_elementor_version';
    const PAGE_SETTINGS_KEY = '_elementor_page_settings';

    public function create( array $data ) {
        $title = isset( $data['name'] ) ? MCP_Validator::sanitize_text( $data['name'] ) : '';
        if ( ! MCP_Validator::is_non_empty_string( $title ) ) {
            return new WP_Error( 'mcp_invalid_template_name', __( 'Template name is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'page';
        $allowed_types = array( 'page', 'section', 'widget', 'header', 'footer', 'single', 'archive', 'product' );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'page';
        }

        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_type'   => self::TEMPLATE_TYPE,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, '_elementor_template_type', $type );

        if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
            update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data['content'] ) ) );
        }

        if ( isset( $data['page_settings'] ) && is_array( $data['page_settings'] ) ) {
            update_post_meta( $post_id, self::PAGE_SETTINGS_KEY, wp_slash( wp_json_encode( $data['page_settings'] ) ) );
        }

        if ( isset( $data['version'] ) ) {
            update_post_meta( $post_id, self::KIT_META_VERSION, MCP_Validator::sanitize_text( $data['version'] ) );
        } elseif ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $post_id, self::KIT_META_VERSION, ELEMENTOR_VERSION );
        }

        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

        do_action( 'mcp_template_created', $post_id, $data );

        return array(
            'id'    => $post_id,
            'type'  => $type,
            'title' => $title,
        );
    }

    public function update( $template_id, array $data ) {
        $post = get_post( $template_id );
        if ( ! $post || self::TEMPLATE_TYPE !== $post->post_type ) {
            return new WP_Error( 'mcp_template_not_found', __( 'Template not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }

        $update = array( 'ID' => $template_id );
        if ( isset( $data['name'] ) ) {
            $update['post_title'] = MCP_Validator::sanitize_text( $data['name'] );
        }
        if ( isset( $data['status'] ) ) {
            $update['post_status'] = MCP_Validator::page_status( $data['status'] );
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $data['type'] ) ) {
            update_post_meta( $template_id, '_elementor_template_type', sanitize_key( $data['type'] ) );
        }
        if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
            update_post_meta( $template_id, '_elementor_data', wp_slash( wp_json_encode( $data['content'] ) ) );
        }
        if ( isset( $data['page_settings'] ) && is_array( $data['page_settings'] ) ) {
            update_post_meta( $template_id, self::PAGE_SETTINGS_KEY, wp_slash( wp_json_encode( $data['page_settings'] ) ) );
        }

        do_action( 'mcp_template_updated', $template_id, $data );

        return true;
    }

    public function delete( $template_id ) {
        $post = get_post( $template_id );
        if ( ! $post || self::TEMPLATE_TYPE !== $post->post_type ) {
            return new WP_Error( 'mcp_template_not_found', __( 'Template not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        return (bool) wp_delete_post( $template_id, true );
    }

    public function get( $template_id ) {
        $post = get_post( $template_id );
        if ( ! $post || self::TEMPLATE_TYPE !== $post->post_type ) {
            return new WP_Error( 'mcp_template_not_found', __( 'Template not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        return $this->prepare_template( $post );
    }

    public function list_all( $args = array() ) {
        $defaults = array(
            'post_type'      => self::TEMPLATE_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'paged'          => 1,
        );
        $query = new WP_Query( wp_parse_args( $args, $defaults ) );

        $items = array();
        foreach ( $query->posts as $post ) {
            $items[] = $this->prepare_template( $post );
        }
        return $items;
    }

    public function get_active_kit() {
        $kit_id = (int) get_option( self::KIT_OPTION );
        if ( $kit_id <= 0 ) {
            return null;
        }
        $post = get_post( $kit_id );
        if ( ! $post ) {
            return null;
        }
        return $this->prepare_template( $post, true );
    }

    public function set_active_kit( $kit_id ) {
        $post = get_post( $kit_id );
        if ( ! $post ) {
            return new WP_Error( 'mcp_kit_not_found', __( 'Kit not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        update_option( self::KIT_OPTION, (int) $kit_id );
        do_action( 'mcp_active_kit_changed', $kit_id );
        return true;
    }

    public function export_to_array( $template_id ) {
        $post = get_post( $template_id );
        if ( ! $post || self::TEMPLATE_TYPE !== $post->post_type ) {
            return new WP_Error( 'mcp_template_not_found', __( 'Template not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }

        $content    = get_post_meta( $template_id, '_elementor_data', true );
        $settings   = get_post_meta( $template_id, self::PAGE_SETTINGS_KEY, true );
        $type       = get_post_meta( $template_id, '_elementor_template_type', true );
        $version    = get_post_meta( $template_id, self::KIT_META_VERSION, true );

        return array(
            'version'       => MCP_VERSION,
            'title'         => $post->post_title,
            'type'          => $type,
            'content'       => $content ? json_decode( $content, true ) : array(),
            'page_settings' => $settings ? json_decode( $settings, true ) : array(),
            'elementor_version' => $version,
            'exported_at'   => current_time( 'mysql' ),
        );
    }

    private function prepare_template( $post, $is_kit = false ) {
        $content  = get_post_meta( $post->ID, '_elementor_data', true );
        $settings = get_post_meta( $post->ID, self::PAGE_SETTINGS_KEY, true );

        return array(
            'id'         => (int) $post->ID,
            'title'      => $post->post_title,
            'status'     => $post->post_status,
            'type'       => get_post_meta( $post->ID, '_elementor_template_type', true ),
            'is_kit'     => $is_kit,
            'content'    => $content ? json_decode( $content, true ) : array(),
            'settings'   => $settings ? json_decode( $settings, true ) : array(),
            'version'    => get_post_meta( $post->ID, self::KIT_META_VERSION, true ),
        );
    }
}
