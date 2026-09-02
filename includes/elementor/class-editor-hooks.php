<?php
/**
 * Hooks into Elementor editor and frontend events.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Editor_Hooks {

    public static function register() {
        add_action( 'elementor/save_post', array( __CLASS__, 'on_elementor_save' ), 10, 2 );
        add_action( 'elementor/editor/after_save', array( __CLASS__, 'on_editor_after_save' ), 10, 2 );
        add_action( 'elementor/frontend/after_enqueue_scripts', array( __CLASS__, 'enqueue_widget_assets' ) );
    }

    public static function on_elementor_save( $post_id, $editor_data ) {
        update_post_meta( $post_id, '_mcp_last_saved', current_time( 'mysql' ) );
        do_action( 'mcp_elementor_saved', $post_id, $editor_data );
    }

    public static function on_editor_after_save( $post_id, $editor_data ) {
        MCP_Logger::info( 'Elementor page saved', array( 'id' => $post_id ) );
        do_action( 'mcp_elementor_after_save', $post_id, $editor_data );
    }

    public static function enqueue_widget_assets() {
        wp_enqueue_style(
            'mcp-widgets',
            MCP_URL . 'assets/css/widgets.css',
            array(),
            MCP_VERSION
        );
    }
}
