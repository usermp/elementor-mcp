<?php
/**
 * Standalone test runner for Elementor MCP.
 *
 * No PHPUnit dependency. Run from the plugin root:
 *   php tests/test-runner.php
 *
 * Exits 0 on success, 1 on any failure. Output is plain text so it
 * works in any CI pipeline.
 *
 * Each test file in tests/unit/ is a free-standing PHP file that:
 *  - defines functions named test_*
 *  - uses the global $results array to record PASS/FAIL lines
 *  - returns true on success
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

define( 'MCP_PATH', dirname( __DIR__ ) . '/' );

// Minimal stubs ---------------------------------------------------------
if ( ! function_exists( '__' ) ) {
    function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '_', strtolower( (string) $s ) ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $s ) {
        if ( ! is_string( $s ) ) return '';
        // Strip script/style tags and event handlers — this is the test stub,
        // real WP runs the full KSES allowlist.
        $s = preg_replace( '#<script(.*?)>(.*?)</script>#is', '', $s );
        $s = preg_replace( '#<style(.*?)>(.*?)</style>#is', '', $s );
        $s = preg_replace( '#\son\w+\s*=\s*"[^"]*"#i', '', $s );
        $s = preg_replace( "#\son\w+\s*=\s*'[^']*'#i", '', $s );
        return $s;
    }
}
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $s ) { return is_string( $s ) ? addslashes( $s ) : $s; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $x ) { return json_encode( $x, JSON_UNESCAPED_UNICODE ); }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $n = 12, $special = true, $extra = true ) {
        return substr( str_replace( array( '.', '/', '=' ), '', base64_encode( random_bytes( 8 ) ) ), 0, $n );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url() { return 'http://localhost'; }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap, $id = null ) { return true; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action() {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $u ) { return $u; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
    function number_format_i18n( $n ) { return number_format( $n ); }
}
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'page' ); }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $k, $single = false ) { return ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $id, $k, $v ) { return true; }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $args, $err = false ) { return 4242; }
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
    function get_edit_post_link( $id ) { return 'http://localhost/wp-admin/post.php?post=' . $id; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return 'http://localhost/?p=' . $id; }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
}
if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $p = '' ) { return 'http://localhost/wp-json/' . $p; }
}

// AI stub: configurable per-test via global.
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args ) {
        $stub = isset( $GLOBALS['mcp_stub_response'] ) ? $GLOBALS['mcp_stub_response'] : '{"choices":[{"message":{"content":"```json\n[]\n```"}}],"model":"m","usage":{"total_tokens":0}}';
        return array( 'body' => $stub );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return isset( $GLOBALS['mcp_stub_code'] ) ? (int) $GLOBALS['mcp_stub_code'] : 200; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return $r['body']; }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code, $msg;
        public function __construct( $code, $msg, $data = array() ) {
            $this->code = $code; $this->msg = $msg;
        }
        public function get_error_message() { return $this->msg; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        public $params = array();
        public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public function __construct( $d, $status = 200 ) { $this->data = $d; $this->status = $status; }
    }
}

// Test plugin/settings classes for the OpenCode_Client.
if ( ! class_exists( 'MCP_Plugin' ) ) {
    class MCP_Plugin {
        public static function get_settings() {
            return array(
                'ai_api_key'  => isset( $GLOBALS['mcp_stub_api_key'] ) ? $GLOBALS['mcp_stub_api_key'] : 'sk-test',
                'ai_model'    => 'test-model',
                'ai_base_url' => 'http://test.local',
            );
        }
    }
}
if ( ! class_exists( 'MCP_Logger' ) ) {
    class MCP_Logger { public static function info() {} public static function warning() {} public static function error() {} }
}
if ( ! class_exists( 'MCP_Validator' ) ) {
    class MCP_Validator {
        public static function page_status( $v ) {
            return in_array( $v, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $v : 'draft';
        }
    }
}
if ( ! class_exists( 'MCP_Rate_Limiter' ) ) {
    class MCP_Rate_Limiter { public static function check_user( $id, $limit ) { return true; } }
}

// Load plugin autoloader -----------------------------------------------
require_once MCP_PATH . 'includes/class-autoloader.php';
MCP_Autoloader::register();

// Discover & run test files --------------------------------------------
$tests_dir = __DIR__ . '/unit';
if ( ! is_dir( $tests_dir ) ) {
    fwrite( STDERR, "No tests/unit directory found.\n" );
    exit( 2 );
}

$files = glob( $tests_dir . '/test-*.php' );
sort( $files );

if ( empty( $files ) ) {
    fwrite( STDERR, "No test files found in {$tests_dir}\n" );
    exit( 2 );
}

$total_pass = 0;
$total_fail = 0;
$total_files = 0;

foreach ( $files as $file ) {
    $total_files++;
    $name = basename( $file, '.php' );
    echo "\n=== {$name} ===\n";

    $results = array();
    require $file;

    foreach ( $results as $line ) {
        echo "  {$line}\n";
        if ( strpos( $line, 'PASS' ) === 0 ) {
            $total_pass++;
        } else {
            $total_fail++;
        }
    }
}

echo "\n";
echo str_repeat( '=', 50 ) . "\n";
echo sprintf( "Ran %d test files · %d passed · %d failed\n", $total_files, $total_pass, $total_fail );
echo str_repeat( '=', 50 ) . "\n";

exit( $total_fail === 0 ? 0 : 1 );