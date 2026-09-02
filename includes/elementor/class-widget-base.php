<?php
/**
 * Base class for custom Elementor widgets.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class MCP_Widget_Base extends \Elementor\Widget_Base {

    public function get_name() {
        return 'mcp-' . sanitize_key( $this->widget_id() );
    }

    public function get_title() {
        return $this->widget_title();
    }

    public function get_icon() {
        return $this->widget_icon();
    }

    public function get_categories() {
        return array( 'general' );
    }

    abstract protected function widget_id();
    abstract protected function widget_title();
    protected function widget_icon() { return 'eicon-info-box'; }
}
