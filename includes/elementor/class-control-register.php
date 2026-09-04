<?php
/**
 * Registers custom Elementor controls and widgets.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Control_Register {

    public static function register() {
        add_action( 'elementor/controls/controls_registered', array( __CLASS__, 'register_controls' ) );
        add_action( 'elementor/widgets/widgets_registered', array( __CLASS__, 'register_widgets' ) );
    }

    public static function register_controls( $controls_manager ) {
        if ( ! class_exists( '\\Elementor\\Controls_Manager' ) ) {
            return;
        }
        require_once MCP_PATH . 'includes/elementor/widgets/class-widget-info-card.php';
    }

    public static function register_widgets( $widgets_manager ) {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
            return;
        }
        if ( ! class_exists( 'MCP_Widget_Base' ) ) {
            require_once MCP_PATH . 'includes/elementor/class-widget-base.php';
        }
        if ( class_exists( 'MCP_Widget_Info_Card' ) ) {
            $widgets_manager->register( new MCP_Widget_Info_Card() );
        }
    }
}
