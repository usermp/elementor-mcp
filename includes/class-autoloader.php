<?php
/**
 * Simple PSR-4 style autoloader.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Autoloader {

    /**
     * Active class -> file map. Classes without a backing file are listed in
     * $pending_classes so they are silently skipped instead of triggering
     * "file not found" warnings when referenced before they ship.
     */
    private static $class_map = array(
        'REST_Controller'   => 'api/class-rest-controller.php',
        'Webhook_Handler'   => 'api/class-webhook-handler.php',
        'Auth'              => 'api/class-auth.php',
        'Rate_Limiter'      => 'api/class-rate-limiter.php',
        'Chat_REST'         => 'api/class-chat-rest.php',
        'Page_Builder'      => 'services/class-page-builder.php',
        'Template_Manager'  => 'services/class-template-manager.php',
        'OpenCode_Client'   => 'services/class-opencode-client.php',
        'Importer'          => 'services/class-importer.php',
        'Diff_Engine'       => 'services/class-diff-engine.php',
        'Queue'             => 'jobs/class-queue.php',
        'Widget_Base'       => 'elementor/class-widget-base.php',
        'Control_Register'  => 'elementor/class-control-register.php',
        'Editor_Hooks'      => 'elementor/class-editor-hooks.php',
        'Settings'          => 'admin/class-settings.php',
        'Chat_Page'         => 'admin/class-chat-page.php',
        'Validator'         => 'utils/class-validator.php',
        'Logger'            => 'utils/class-logger.php',
        'Activator'         => 'class-activator.php',
        'Deactivator'       => 'class-deactivator.php',
        'Plugin'            => 'class-plugin.php',
    );

    /**
     * Classes referenced by code but not yet shipped. Listed here so the
     * autoloader knows to ignore them gracefully instead of emitting
     * require_once warnings during page loads.
     */
    private static $pending_classes = array(
        'MCP_History_Page'    => 'admin/class-history-page.php',
        'MCP_GDPR'            => 'privacy/class-gdpr.php',
    );

    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    public static function autoload( $class ) {
        if ( strpos( $class, 'MCP_' ) !== 0 ) {
            return;
        }

        if ( isset( self::$pending_classes[ $class ] ) ) {
            // Class planned but not shipped yet — fail soft.
            return;
        }

        $file = self::class_to_file( $class );
        if ( $file && file_exists( $file ) ) {
            require_once $file;
        }
    }

    private static function class_to_file( $class ) {
        $relative = str_replace( 'MCP_', '', $class );

        if ( isset( self::$class_map[ $relative ] ) ) {
            return MCP_PATH . 'includes/' . self::$class_map[ $relative ];
        }

        return null;
    }

    /**
     * Diagnostic helper: returns classes in $pending_classes that have an
     * existing file on disk. Used by tests and a future admin status page.
     */
    public static function discover_ready_pending() {
        $ready = array();
        foreach ( self::$pending_classes as $class => $relative ) {
            $path = MCP_PATH . 'includes/' . $relative;
            if ( file_exists( $path ) ) {
                $ready[ $class ] = $path;
            }
        }
        return $ready;
    }
}
