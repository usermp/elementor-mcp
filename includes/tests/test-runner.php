<?php
/**
 * MCP Plugin Test Runner - exercises the plugin end-to-end.
 *
 * Run with: wp eval-file includes/tests/test-runner.php
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Test_Runner {

    private $pass    = 0;
    private $fail    = 0;
    private $created_ids = array();

    public function run_all() {
        echo "=== MCP Plugin Test Runner ===\n\n";

        $this->test_constants();
        $this->test_settings();
        $this->test_validator();
        $this->test_logger();
        $this->test_rest_routes_registered();
        $this->test_page_builder_create();
        $this->test_page_builder_update();
        $this->test_page_builder_read();
        $this->test_page_builder_delete();
        $this->test_template_manager();
        $this->test_rate_limiter();
        $this->test_auth_helpers();
        $this->test_queue();
        $this->test_event_hooks();

        $this->cleanup();

        echo "\n=== Summary ===\n";
        echo "Passed: {$this->pass}\n";
        echo "Failed: {$this->fail}\n";
        echo "Total:  " . ( $this->pass + $this->fail ) . "\n";

        return $this->fail === 0;
    }

    private function test_constants() {
        $this->assert( defined( 'MCP_VERSION' ),     'MCP_VERSION defined' );
        $this->assert( defined( 'MCP_PATH' ),        'MCP_PATH defined' );
        $this->assert( defined( 'MCP_URL' ),         'MCP_URL defined' );
        $this->assert( defined( 'MCP_REST_NAMESPACE' ), 'MCP_REST_NAMESPACE defined' );
        $this->assert( MCP_REST_NAMESPACE === 'mcp/v1', 'MCP_REST_NAMESPACE is mcp/v1' );
    }

    private function test_settings() {
        $settings = MCP_Plugin::get_settings();
        $this->assert( is_array( $settings ), 'Settings is array' );
        $this->assert( isset( $settings['api_enabled'] ), 'Settings has api_enabled' );
        $this->assert( isset( $settings['webhook_secret'] ), 'Settings has webhook_secret' );
        $this->assert( isset( $settings['rate_limit'] ), 'Settings has rate_limit' );
    }

    private function test_validator() {
        $this->assert( MCP_Validator::sanitize_text( '<b>x</b>' ) === 'x', 'sanitize_text strips tags' );
        $this->assert( MCP_Validator::sanitize_int( '42' ) === 42, 'sanitize_int casts' );
        $this->assert( MCP_Validator::sanitize_bool( 0 ) === false, 'sanitize_bool false' );
        $this->assert( MCP_Validator::sanitize_bool( 1 ) === true, 'sanitize_bool true' );
        $this->assert( MCP_Validator::is_non_empty_string( 'x' ) === true, 'non-empty string true' );
        $this->assert( MCP_Validator::is_non_empty_string( ' ' ) === false, 'whitespace false' );
        $this->assert( MCP_Validator::is_positive_int( 5 ) === true, '5 is positive' );
        $this->assert( MCP_Validator::is_positive_int( 0 ) === false, '0 not positive' );
        $this->assert( MCP_Validator::page_status( 'publish' ) === 'publish', 'valid status' );
        $this->assert( MCP_Validator::page_status( 'garbage' ) === 'draft', 'invalid status defaults' );
        $this->assert( is_array( MCP_Validator::sanitize_json( '{"a":1}' ) ), 'sanitize_json decodes' );
        $this->assert( MCP_Validator::sanitize_json( 'bad' ) === null, 'sanitize_json null on bad' );
    }

    private function test_logger() {
        MCP_Logger::clear();
        MCP_Logger::info( 'test message' );
        $logs = MCP_Logger::get_recent( 5 );
        $this->assert( count( $logs ) === 1, 'Logger records one entry' );
        $this->assert( $logs[0]['level'] === 'info', 'Logger level correct' );
        $this->assert( $logs[0]['message'] === 'test message', 'Logger message correct' );
        MCP_Logger::clear();
        $this->assert( count( MCP_Logger::get_recent( 5 ) ) === 0, 'Logger clears' );
    }

    private function test_rest_routes_registered() {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $mcp    = array_filter( array_keys( $routes ), function( $r ) {
            return strpos( $r, 'mcp/v1' ) !== false;
        });
        $this->assert( count( $mcp ) >= 2, 'At least 2 MCP routes registered' );
    }

    private function test_page_builder_create() {
        $builder = new MCP_Page_Builder();
        $result  = $builder->create(
            array(
                'post_title'   => 'Test Page Runner',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '<p>Hello</p>',
            ),
            array( 'meta' => array( 'source' => 'test-runner' ) )
        );
        $this->assert( ! is_wp_error( $result ), 'Page created without error' );
        $this->assert( isset( $result['id'] ) && $result['id'] > 0, 'Page has positive ID' );
        $this->assert( $result['status'] === 'publish', 'Page status is publish' );
        $this->created_ids[] = $result['id'];
    }

    private function test_page_builder_update() {
        $id = $this->created_ids[0] ?? 0;
        if ( ! $id ) {
            $this->assert( false, 'Update skipped: no page id' );
            return;
        }
        $builder = new MCP_Page_Builder();
        $result  = $builder->update( $id, array(
            'title'  => 'Updated Title',
            'status' => 'draft',
            'elementor' => array( 'data' => array( array( 'id' => 'abc', 'elType' => 'widget' ) ), 'version' => '3.20.0' ),
        ) );
        $this->assert( ! is_wp_error( $result ), 'Page updated without error' );

        $post = get_post( $id );
        $this->assert( $post->post_title === 'Updated Title', 'Page title updated' );
        $this->assert( $post->post_status === 'draft', 'Page status updated' );

        $data = get_post_meta( $id, '_elementor_data', true );
        $this->assert( ! empty( $data ), 'Elementor data saved' );
        $this->assert( get_post_meta( $id, '_elementor_edit_mode', true ) === 'builder', 'Edit mode set' );
    }

    private function test_page_builder_read() {
        $id = $this->created_ids[0] ?? 0;
        if ( ! $id ) {
            return;
        }
        $post = get_post( $id );
        $this->assert( $post && $post->ID === $id, 'Page retrievable by ID' );
        $this->assert( get_post_meta( $id, '_source', true ) === 'test-runner', 'Custom meta saved (key prefixed with _)' );
    }

    private function test_page_builder_delete() {
        $id = $this->created_ids[0] ?? 0;
        if ( ! $id ) {
            return;
        }
        $deleted = wp_delete_post( $id, true );
        $this->assert( $deleted && ! is_wp_error( $deleted ), 'Page deleted' );
        $this->assert( get_post( $id ) === null, 'Page no longer exists' );
    }

    private function test_template_manager() {
        if ( ! class_exists( 'MCP_Template_Manager' ) ) {
            echo "  -- Template Manager not yet loaded (Phase 2) --\n";
            return;
        }
        $tm   = new MCP_Template_Manager();
        $made = $tm->create( array(
            'name'    => 'Test Template',
            'type'    => 'section',
            'content' => array( array( 'id' => 't1', 'elType' => 'section' ) ),
        ) );
        $this->assert( ! is_wp_error( $made ), 'Template created' );
        if ( is_wp_error( $made ) ) {
            return;
        }
        $this->created_ids[] = $made['id'];

        $template = $tm->get( $made['id'] );
        $this->assert( ! is_wp_error( $template ), 'Template retrieved' );

        $exported = $tm->export_to_array( $made['id'] );
        $this->assert( ! is_wp_error( $exported ), 'Template exportable' );
    }

    private function test_rate_limiter() {
        if ( ! class_exists( 'MCP_Rate_Limiter' ) ) {
            echo "  -- Rate Limiter not yet loaded (Phase 2) --\n";
            return;
        }
        $key = 'mcp_test_rl_' . uniqid();
        $r1  = MCP_Rate_Limiter::check( $key, 2, 60 );
        $this->assert( $r1 === true, 'Rate limit allows first' );

        $r2 = MCP_Rate_Limiter::check( $key, 2, 60 );
        $this->assert( $r2 === true, 'Rate limit allows second' );

        $r3 = MCP_Rate_Limiter::check( $key, 2, 60 );
        $this->assert( is_wp_error( $r3 ), 'Rate limit blocks third' );
    }

    private function test_auth_helpers() {
        if ( ! class_exists( 'MCP_Auth' ) ) {
            echo "  -- Auth helpers not yet loaded (Phase 2) --\n";
            return;
        }
        $payload = '{"event":"page.created","id":1}';
        $secret  = 'test-secret';
        $sig     = hash_hmac( 'sha256', $payload, $secret );
        $this->assert( MCP_Auth::verify_webhook_signature( $payload, $sig, $secret ) === true, 'Valid signature accepted' );
        $this->assert( MCP_Auth::verify_webhook_signature( $payload, 'bad', $secret ) === false, 'Invalid signature rejected' );
    }

    private function test_queue() {
        if ( ! class_exists( 'MCP_Queue' ) ) {
            echo "  -- Queue not yet loaded (Phase 2) --\n";
            return;
        }
        $stats = MCP_Queue::stats();
        $this->assert( is_array( $stats ), 'Queue stats is array' );
        $this->assert( isset( $stats['engine'] ), 'Queue stats has engine' );
    }

    private function test_event_hooks() {
        $fired  = false;
        $tester = function( $post_id ) use ( &$fired ) {
            $fired = $post_id;
        };
        add_action( 'mcp_page_created', $tester );

        $builder = new MCP_Page_Builder();
        $made    = $builder->create(
            array( 'post_title' => 'Hook Test', 'post_status' => 'draft', 'post_type' => 'page' ),
            array()
        );
        $this->assert( $fired == $made['id'], 'mcp_page_created hook fired with id' );
        $this->created_ids[] = $made['id'];

        remove_action( 'mcp_page_created', $tester );
    }

    private function cleanup() {
        foreach ( $this->created_ids as $id ) {
            wp_delete_post( $id, true );
        }
    }

    private function assert( $cond, $msg ) {
        if ( $cond ) {
            $this->pass++;
            echo "  PASS  $msg\n";
        } else {
            $this->fail++;
            echo "  FAIL  $msg\n";
        }
    }
}

add_action( 'rest_api_init', function() {
    ( new MCP_REST_Controller() )->register_routes();
    if ( class_exists( 'MCP_Template_Manager' ) ) {
        new MCP_Template_Manager();
    }
}, 1 );

$runner = new MCP_Test_Runner();
exit( $runner->run_all() ? 0 : 1 );
