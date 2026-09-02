<?php
/**
 * Settings page for the plugin.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Settings {

    const OPTION_KEY = 'mcp_settings';
    const MENU_SLUG  = 'elementor-mcp';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_mcp_clear_logs', array( $this, 'handle_clear_logs' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'MCP', 'elementor-mcp' ),
            __( 'MCP', 'elementor-mcp' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' ),
            'dashicons-admin-generic',
            81
        );
    }

    public function register_settings() {
        register_setting( 'mcp_settings_group', self::OPTION_KEY, array(
            'sanitize_callback' => array( $this, 'sanitize' ),
        ) );
    }

    public function sanitize( $input ) {
        $output = MCP_Plugin::get_settings();

        if ( isset( $input['api_enabled'] ) ) {
            $output['api_enabled'] = 1;
        } else {
            $output['api_enabled'] = 0;
        }

        if ( isset( $input['enable_logging'] ) ) {
            $output['enable_logging'] = 1;
        } else {
            $output['enable_logging'] = 0;
        }

        if ( isset( $input['enable_graphql'] ) ) {
            $output['enable_graphql'] = 1;
        } else {
            $output['enable_graphql'] = 0;
        }

        if ( isset( $input['webhook_secret'] ) ) {
            $output['webhook_secret'] = sanitize_text_field( $input['webhook_secret'] );
        }

        if ( isset( $input['rate_limit'] ) ) {
            $limit = (int) $input['rate_limit'];
            $output['rate_limit'] = max( 0, min( 10000, $limit ) );
        }

        if ( isset( $input['ai_base_url'] ) ) {
            $url = trim( (string) $input['ai_base_url'] );
            if ( '' === $url || filter_var( $url, FILTER_VALIDATE_URL ) ) {
                $output['ai_base_url'] = esc_url_raw( $url );
            }
        }

        if ( isset( $input['ai_model'] ) ) {
            $output['ai_model'] = sanitize_text_field( $input['ai_model'] );
        }

        // API key is treated as a secret — only overwrite if a non-empty value was submitted.
        if ( isset( $input['ai_api_key'] ) && '' !== trim( (string) $input['ai_api_key'] ) ) {
            $output['ai_api_key'] = sanitize_text_field( $input['ai_api_key'] );
        }

        return $output;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'elementor-mcp' ) );
        }

        $settings = MCP_Plugin::get_settings();
        $rest_url = rest_url( MCP_REST_NAMESPACE . '/pages' );
        $logs     = MCP_Logger::get_recent( 20 );

        require_once MCP_PATH . 'views/admin-settings.php';
    }

    public function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden.', 'elementor-mcp' ) );
        }
        check_admin_referer( 'mcp_clear_logs' );
        MCP_Logger::clear();
        wp_safe_redirect( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) );
        exit;
    }
}
