<?php
/**
 * Main plugin orchestrator.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Plugin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot() {
        $this->load_textdomain();

        if ( is_admin() ) {
            MCP_Settings::instance()->register();
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        }

        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        if ( self::elementor_active() ) {
            MCP_Editor_Hooks::register();
            add_action( 'elementor/init', array( $this, 'register_elementor_components' ) );
        } else {
            add_action( 'plugins_loaded', array( $this, 'register_elementor_components' ), 20 );
        }

        do_action( 'mcp_loaded', $this );
    }

    public function register_elementor_components() {
        MCP_Control_Register::register();
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( (string) $hook, MCP_Settings::MENU_SLUG ) === false ) {
            return;
        }
        wp_enqueue_style(
            'mcp-admin',
            MCP_URL . 'assets/css/admin.css',
            array(),
            MCP_VERSION
        );
        wp_enqueue_script(
            'mcp-admin',
            MCP_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            MCP_VERSION,
            true
        );
        wp_localize_script( 'mcp-admin', 'mcpAdmin', array(
            'i18n' => array(
                'regenerate'        => __( 'Regenerate', 'elementor-mcp' ),
                'confirmRegenerate' => __( 'Replace the current secret with a new random value?', 'elementor-mcp' ),
            ),
        ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'elementor-mcp', false, dirname( plugin_basename( MCP_FILE ) ) . '/languages' );
    }

    public function register_rest_routes() {
        $controller = new MCP_REST_Controller();
        $controller->register_routes();
    }

    public static function elementor_active() {
        return defined( 'ELEMENTOR_VERSION' );
    }

    public static function get_settings() {
        $defaults = array(
            'api_enabled'    => 1,
            'webhook_secret' => '',
            'rate_limit'     => 60,
            'enable_logging' => 1,
            'enable_graphql' => 0,
        );
        $saved = get_option( 'mcp_settings', array() );
        return wp_parse_args( $saved, $defaults );
    }
}
