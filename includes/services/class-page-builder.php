<?php
/**
 * Page builder service - creates and updates Elementor pages.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Page_Builder {

    public function create( array $args, array $data ) {
        $post_id = wp_insert_post( $args, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $this->apply_elementor_data( $post_id, $data );
        $this->apply_page_settings( $post_id, $data );
        $this->apply_meta( $post_id, $data );

        do_action( 'mcp_page_created', $post_id, $data );

        return array(
            'id'     => $post_id,
            'status' => get_post_status( $post_id ),
        );
    }

    public function update( $post_id, array $data ) {
        $update = array( 'ID' => $post_id );

        if ( isset( $data['title'] ) ) {
            $update['post_title'] = MCP_Validator::sanitize_text( $data['title'] );
        }
        if ( isset( $data['content'] ) ) {
            $update['post_content'] = MCP_Validator::sanitize_html( $data['content'] );
        }
        if ( isset( $data['status'] ) ) {
            $update['post_status'] = MCP_Validator::page_status( $data['status'] );
        }
        if ( isset( $data['slug'] ) ) {
            $update['post_name'] = MCP_Validator::sanitize_slug( $data['slug'] );
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->apply_elementor_data( $post_id, $data );
        $this->apply_page_settings( $post_id, $data );
        $this->apply_meta( $post_id, $data );

        do_action( 'mcp_page_updated', $post_id, $data );

        return true;
    }

    private function apply_elementor_data( $post_id, array $data ) {
        if ( ! isset( $data['elementor'] ) ) {
            return;
        }

        $elementor = $data['elementor'];
        if ( ! is_array( $elementor ) ) {
            return;
        }

        if ( isset( $elementor['data'] ) ) {
            $raw = $elementor['data'];
            if ( is_array( $raw ) ) {
                $raw = wp_json_encode( $raw );
            }
            $raw = wp_slash( $raw );
            update_post_meta( $post_id, '_elementor_data', $raw );
        }

        if ( isset( $elementor['version'] ) ) {
            update_post_meta( $post_id, '_elementor_version', MCP_Validator::sanitize_text( $elementor['version'] ) );
        } elseif ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }

        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
    }

    private function apply_page_settings( $post_id, array $data ) {
        if ( ! isset( $data['page_settings'] ) ) {
            return;
        }
        $settings = $data['page_settings'];
        if ( ! is_array( $settings ) ) {
            return;
        }
        update_post_meta( $post_id, '_elementor_page_settings', wp_slash( wp_json_encode( $settings ) ) );
    }

    private function apply_meta( $post_id, array $data ) {
        if ( ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
            return;
        }
        foreach ( $data['meta'] as $key => $value ) {
            $clean_key = sanitize_key( $key );
            if ( strpos( $clean_key, '_' ) !== 0 ) {
                $clean_key = '_' . $clean_key;
            }
            if ( is_array( $value ) ) {
                $value = wp_json_encode( $value );
            }
            update_post_meta( $post_id, $clean_key, MCP_Validator::sanitize_text( $value ) );
        }
    }
}
