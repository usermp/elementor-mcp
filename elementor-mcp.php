<?php
/**
 * Plugin Name: Elementor MCP
 * Plugin URI: https://github.com/usermp/elementor-mcp
 * Description: Machine Content Producer - bridge between OpenCode external service and WordPress/Elementor for automated page creation via REST API.
 * Version:           1.8.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Mohammad Yeganeh
 * Author URI: https://github.com/usermp
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: elementor-mcp
 * Domain Path: /languages
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MCP_VERSION', '1.7.0' );
define( 'MCP_FILE', __FILE__ );
define( 'MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'MCP_REST_NAMESPACE', 'mcp/v1' );

require_once MCP_PATH . 'includes/class-autoloader.php';
MCP_Autoloader::register();

register_activation_hook( __FILE__, array( 'MCP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MCP_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
    MCP_Plugin::instance()->boot();
} );
