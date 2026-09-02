<?php
/**
 * Plugin deactivator - runs on deactivation.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Deactivator {

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
