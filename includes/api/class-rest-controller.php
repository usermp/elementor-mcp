<?php
/**
 * REST API controller for MCP endpoints.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_REST_Controller extends WP_REST_Controller {

    public function __construct() {
        $this->namespace = MCP_REST_NAMESPACE;
        $this->rest_base = 'pages';
    }

    public function register_routes() {
        $settings = MCP_Plugin::get_settings();
        if ( empty( $settings['api_enabled'] ) ) {
            return;
        }

        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_items' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_item' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_item' ),
                'permission_callback' => array( $this, 'update_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_item' ),
                'permission_callback' => array( $this, 'delete_item_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/pages/batch', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'batch_create_items' ),
            'permission_callback' => array( $this, 'create_item_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/templates', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_template' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_templates' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/templates/(?P<id>[\d]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_template' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_template' ),
                'permission_callback' => array( $this, 'update_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_template' ),
                'permission_callback' => array( $this, 'delete_item_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/kit', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_active_kit' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'set_active_kit' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/webhook', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/chat', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_chat' ),
            'permission_callback' => array( $this, 'chat_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/chat/apply', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_chat_apply' ),
            'permission_callback' => array( $this, 'chat_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/clone', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_clone' ),
            'permission_callback' => array( $this, 'clone_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/template', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_template_build' ),
            'permission_callback' => array( $this, 'chat_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/audit/performance', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_audit_perf' ),
            'permission_callback' => array( $this, 'admin_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/audit/security', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_audit_sec' ),
            'permission_callback' => array( $this, 'admin_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/themer/create', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_themer_create' ),
            'permission_callback' => array( $this, 'agent_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/agent/tools/list', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_agent_list' ),
            'permission_callback' => array( $this, 'agent_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/agent/tools/call', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_agent_call' ),
            'permission_callback' => array( $this, 'agent_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/agent/snapshot/create', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_snapshot_create' ),
            'permission_callback' => array( $this, 'agent_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/agent/snapshot/list', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_snapshot_list' ),
            'permission_callback' => array( $this, 'agent_permissions_check' ),
        ) );

        register_rest_route( $this->namespace, '/agent/snapshot/restore', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_snapshot_restore' ),
            'permission_callback' => array( $this, 'agent_permissions_check' ),
        ) );
    }

    public function create_item_permissions_check( $request ) {
        $settings = MCP_Plugin::get_settings();
        $limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl       = MCP_Rate_Limiter::check_user( 0, $limit );
        return ( true === $rl ) && current_user_can( 'edit_pages' );
    }

    public function get_items_permissions_check( $request ) {
        $settings = MCP_Plugin::get_settings();
        $limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl       = MCP_Rate_Limiter::check_user( 0, $limit );
        return ( true === $rl ) && current_user_can( 'edit_pages' );
    }

    public function get_item_permissions_check( $request ) {
        $settings = MCP_Plugin::get_settings();
        $limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl       = MCP_Rate_Limiter::check_user( 0, $limit );
        return ( true === $rl ) && current_user_can( 'edit_pages' );
    }

    public function update_item_permissions_check( $request ) {
        $settings = MCP_Plugin::get_settings();
        $limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl       = MCP_Rate_Limiter::check_user( 0, $limit );
        return ( true === $rl ) && current_user_can( 'edit_pages' );
    }

    public function delete_item_permissions_check( $request ) {
        $settings = MCP_Plugin::get_settings();
        $limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl       = MCP_Rate_Limiter::check_user( 0, $limit );
        return ( true === $rl ) && current_user_can( 'delete_pages' );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }

        $title = isset( $data['title'] ) ? MCP_Validator::sanitize_text( $data['title'] ) : '';
        if ( ! MCP_Validator::is_non_empty_string( $title ) ) {
            return new WP_Error( 'mcp_invalid_title', __( 'Title is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $args = array(
            'post_title'   => $title,
            'post_status'  => MCP_Validator::page_status( isset( $data['status'] ) ? $data['status'] : 'draft' ),
            'post_type'    => 'page',
            'post_content' => isset( $data['content'] ) ? MCP_Validator::sanitize_html( $data['content'] ) : '',
        );

        if ( ! empty( $data['post_author'] ) ) {
            $args['post_author'] = MCP_Validator::sanitize_int( $data['post_author'] );
        }

        $builder = new MCP_Page_Builder();
        $result  = $builder->create( $args, $data );

        if ( is_wp_error( $result ) ) {
            MCP_Logger::error( 'Create page failed', array( 'error' => $result->get_error_message() ) );
            return $result;
        }

        MCP_Logger::info( 'Page created', array( 'id' => $result['id'] ) );

        $response = new WP_REST_Response( array(
            'id'      => $result['id'],
            'status'  => $result['status'],
            'message' => __( 'Page created successfully.', 'elementor-mcp' ),
        ), 201 );
        $response->set_headers( array( 'Location' => rest_url( $this->namespace . '/' . $this->rest_base . '/' . $result['id'] ) ) );
        return $response;
    }

    public function get_items( $request ) {
        $query = new WP_Query( array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => MCP_Validator::sanitize_int( $request->get_param( 'per_page' ) ?: 20 ),
            'paged'          => MCP_Validator::sanitize_int( $request->get_param( 'page' ) ?: 1 ),
        ) );

        $items = array();
        foreach ( $query->posts as $post ) {
            $items[] = $this->prepare_item_for_response( $post, $request );
        }

        return rest_ensure_response( $items );
    }

    public function get_item( $request ) {
        $id   = MCP_Validator::sanitize_int( $request['id'] );
        $post = get_post( $id );

        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'mcp_not_found', __( 'Page not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
    }

    public function update_item( $request ) {
        $id   = MCP_Validator::sanitize_int( $request['id'] );
        $post = get_post( $id );

        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'mcp_not_found', __( 'Page not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }

        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }

        $builder = new MCP_Page_Builder();
        $result  = $builder->update( $id, $data );

        if ( is_wp_error( $result ) ) {
            MCP_Logger::error( 'Update page failed', array( 'id' => $id, 'error' => $result->get_error_message() ) );
            return $result;
        }

        MCP_Logger::info( 'Page updated', array( 'id' => $id ) );

        return rest_ensure_response( array(
            'id'      => $id,
            'status'  => 'updated',
            'message' => __( 'Page updated successfully.', 'elementor-mcp' ),
        ) );
    }

    public function delete_item( $request ) {
        $id   = MCP_Validator::sanitize_int( $request['id'] );
        $post = get_post( $id );

        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'mcp_not_found', __( 'Page not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }

        $deleted = wp_delete_post( $id, true );
        if ( ! $deleted ) {
            return new WP_Error( 'mcp_delete_failed', __( 'Could not delete page.', 'elementor-mcp' ), array( 'status' => 500 ) );
        }

        MCP_Logger::info( 'Page deleted', array( 'id' => $id ) );

        return rest_ensure_response( array(
            'status'  => 'deleted',
            'message' => __( 'Page deleted.', 'elementor-mcp' ),
        ) );
    }

    public function batch_create_items( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }

        $items = isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array();
        if ( empty( $items ) ) {
            return new WP_Error( 'mcp_empty_batch', __( 'No pages provided.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        if ( count( $items ) > 50 ) {
            return new WP_Error( 'mcp_batch_too_large', __( 'Batch max 50 items.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $results = array();
        foreach ( $items as $i => $item ) {
            $title = isset( $item['title'] ) ? MCP_Validator::sanitize_text( $item['title'] ) : '';
            if ( ! MCP_Validator::is_non_empty_string( $title ) ) {
                $results[] = array( 'index' => $i, 'status' => 400, 'error' => 'invalid_title' );
                continue;
            }
            $builder = new MCP_Page_Builder();
            $res     = $builder->create( array(
                'post_title'   => $title,
                'post_status'  => MCP_Validator::page_status( isset( $item['status'] ) ? $item['status'] : 'draft' ),
                'post_type'    => 'page',
                'post_content' => isset( $item['content'] ) ? MCP_Validator::sanitize_html( $item['content'] ) : '',
            ), $item );

            if ( is_wp_error( $res ) ) {
                $results[] = array( 'index' => $i, 'status' => 500, 'error' => $res->get_error_message() );
            } else {
                $results[] = array( 'index' => $i, 'status' => 201, 'id' => $res['id'] );
            }
        }

        return rest_ensure_response( array(
            'status'   => 'completed',
            'count'    => count( $results ),
            'results'  => $results,
        ) );
    }

    public function create_template( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $tm     = new MCP_Template_Manager();
        $result = $tm->create( $data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        MCP_Logger::info( 'Template created via REST', array( 'id' => $result['id'] ) );
        $response = new WP_REST_Response( $result, 201 );
        $response->set_headers( array( 'Location' => rest_url( $this->namespace . '/templates/' . $result['id'] ) ) );
        return $response;
    }

    public function list_templates( $request ) {
        $tm    = new MCP_Template_Manager();
        $items = $tm->list_all( array(
            'posts_per_page' => MCP_Validator::sanitize_int( $request->get_param( 'per_page' ) ?: 20 ),
            'paged'          => MCP_Validator::sanitize_int( $request->get_param( 'page' ) ?: 1 ),
        ) );
        return rest_ensure_response( $items );
    }

    public function get_template( $request ) {
        $tm  = new MCP_Template_Manager();
        $res = $tm->get( MCP_Validator::sanitize_int( $request['id'] ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return rest_ensure_response( $res );
    }

    public function update_template( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $tm     = new MCP_Template_Manager();
        $result = $tm->update( MCP_Validator::sanitize_int( $request['id'] ), $data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'updated' ) );
    }

    public function delete_template( $request ) {
        $tm  = new MCP_Template_Manager();
        $res = $tm->delete( MCP_Validator::sanitize_int( $request['id'] ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return rest_ensure_response( array( 'status' => 'deleted' ) );
    }

    public function get_active_kit( $request ) {
        $tm  = new MCP_Template_Manager();
        $kit = $tm->get_active_kit();
        if ( ! $kit ) {
            return new WP_Error( 'mcp_no_kit', __( 'No active kit set.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( $kit );
    }

    public function set_active_kit( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $kit_id = isset( $data['id'] ) ? MCP_Validator::sanitize_int( $data['id'] ) : 0;
        if ( $kit_id <= 0 ) {
            return new WP_Error( 'mcp_invalid_kit', __( 'Kit id required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        $tm  = new MCP_Template_Manager();
        $res = $tm->set_active_kit( $kit_id );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return rest_ensure_response( array( 'status' => 'updated', 'id' => $kit_id ) );
    }

    public function handle_webhook( $request ) {
        $handler = new MCP_Webhook_Handler();
        return $handler->handle( $request );
    }

    public function chat_permissions_check( $request ) {
        return MCP_Chat_REST::permissions_check( $request );
    }

    public function handle_chat( $request ) {
        return MCP_Chat_REST::handle( $request );
    }

    public function handle_chat_apply( $request ) {
        $key = MCP_Idempotency::key_from_request( $request );
        if ( $key ) {
            $cached = MCP_Idempotency::recall( $key );
            if ( $cached ) return rest_ensure_response( $cached );
        }
        $resp = MCP_Chat_REST::handle_apply( $request );
        if ( ! is_wp_error( $resp ) && $key ) {
            MCP_Idempotency::remember( $key, $resp instanceof WP_REST_Response ? $resp->get_data() : $resp );
        }
        return $resp;
    }

    public function clone_permissions_check( $request ) {
        return MCP_Site_Cloner::permissions_check( $request );
    }

    public function handle_clone( $request ) {
        $key = MCP_Idempotency::key_from_request( $request );
        if ( $key ) {
            $cached = MCP_Idempotency::recall( $key );
            if ( $cached ) return rest_ensure_response( $cached );
        }
        $resp = MCP_Site_Cloner::handle_rest( $request );
        if ( ! is_wp_error( $resp ) && $key ) {
            MCP_Idempotency::remember( $key, $resp instanceof WP_REST_Response ? $resp->get_data() : $resp );
        }
        return $resp;
    }

    public function handle_template_build( $request ) {
        $key = MCP_Idempotency::key_from_request( $request );
        if ( $key ) {
            $cached = MCP_Idempotency::recall( $key );
            if ( $cached ) return rest_ensure_response( $cached );
        }

        $brief = (array) $request->get_param( 'brief' );
        if ( empty( $brief['brand_name'] ) ) {
            return new WP_Error( 'mcp_template_no_brand', __( 'brand_name is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        $status = MCP_Validator::page_status( (string) ( $request->get_param( 'status' ) ?? 'draft' ) );

        $builder = new MCP_Template_Builder();
        $built = $builder->build( $brief );
        if ( is_wp_error( $built ) ) {
            return $built;
        }
        $page = $builder->create_page( $built, array( 'status' => $status ) );
        if ( is_wp_error( $page ) ) {
            return $page;
        }
        $payload = array_merge( $page, array( 'stats' => $built['stats'] ) );

        if ( $key ) {
            MCP_Idempotency::remember( $key, $payload );
        }
        return rest_ensure_response( $payload );
    }

    /* ---------- agent surface ---------- */

    public function agent_permissions_check( $request ) {
        // API key path: trust the key if it resolves and isn't rate-limited.
        $key_row = MCP_Api_Key::from_request( $request );
        if ( $key_row ) {
            $rl = MCP_Api_Key::check_rate( $key_row );
            if ( is_wp_error( $rl ) ) return $rl;
            MCP_Api_Key::touch( $key_row['label'] );
            return true;
        }
        if ( ! current_user_can( 'edit_pages' ) ) {
            return new WP_Error( 'mcp_agent_forbidden', __( 'You cannot use the agent surface.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        return true;
    }

    public function handle_agent_list( $request ) {
        $reg = new MCP_Agent_Registry();
        return rest_ensure_response( array( 'tools' => $reg->all() ) );
    }

    public function handle_agent_call( $request ) {
        $name = (string) $request->get_param( 'name' );
        if ( '' === $name ) {
            return new WP_Error( 'mcp_agent_no_name', __( 'Tool name is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        $reg = new MCP_Agent_Registry();
        $tool = $reg->get( $name );
        if ( ! $tool ) {
            return new WP_Error( 'mcp_agent_unknown', sprintf( __( 'Unknown tool: %s', 'elementor-mcp' ), $name ), array( 'status' => 404 ) );
        }
        if ( ! current_user_can( $tool['capability'] ) ) {
            return new WP_Error( 'mcp_agent_forbidden', __( 'Insufficient capability for this tool.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        $args = (array) $request->get_param( 'args' );
        $valid = $reg->validate_args( $tool, $args );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        $result = call_user_func( $tool['handler'], $args );
        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 502 ) );
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function handle_snapshot_create( $request ) {
        $reg = new MCP_Agent_Registry();
        $result = $reg->tool_snapshot_create( array(
            'post_id' => (int) $request->get_param( 'post_id' ),
            'label'   => (string) $request->get_param( 'label' ),
        ) );
        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 502 ) );
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function handle_snapshot_list( $request ) {
        $reg = new MCP_Agent_Registry();
        return rest_ensure_response( $reg->tool_snapshot_list( array(
            'post_id' => (int) $request->get_param( 'post_id' ),
            'limit'   => (int) $request->get_param( 'limit' ),
        ) ) );
    }

    public function handle_snapshot_restore( $request ) {
        $reg = new MCP_Agent_Registry();
        $result = $reg->tool_snapshot_restore( array(
            'snapshot_id' => (int) $request->get_param( 'snapshot_id' ),
        ) );
        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 502 ) );
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function admin_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'mcp_admin_forbidden', __( 'Admin only.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        return true;
    }

    public function handle_audit_perf( $request ) {
        $force = (bool) $request->get_param( 'force' );
        $a = new MCP_Performance_Analyzer();
        $result = $force ? $a->analyze() : $a->last_run();
        return rest_ensure_response( $result );
    }

    public function handle_audit_sec( $request ) {
        $force = (bool) $request->get_param( 'force' );
        $s = new MCP_Security_Scanner();
        $result = $force ? $s->scan() : $s->last_run();
        return rest_ensure_response( $result );
    }

    public function handle_themer_create( $request ) {
        $reg = new MCP_Agent_Registry();
        $result = $reg->tool_themer_create( array(
            'title'      => (string) $request->get_param( 'title' ),
            'location'   => (string) $request->get_param( 'location' ),
            'sections'   => (array) $request->get_param( 'sections' ),
            'conditions' => (array) $request->get_param( 'conditions' ),
            'priority'   => (int) $request->get_param( 'priority' ),
        ) );
        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 502 ) );
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function prepare_item_for_response( $post, $request ) {
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
        $page_settings  = get_post_meta( $post->ID, '_elementor_page_settings', true );

        $data = array(
            'id'        => (int) $post->ID,
            'title'     => array(
                'raw'      => $post->post_title,
                'rendered' => get_the_title( $post ),
            ),
            'status'    => $post->post_status,
            'slug'      => $post->post_name,
            'content'   => array(
                'raw'      => $post->post_content,
                'rendered' => apply_filters( 'the_content', $post->post_content ),
            ),
            'link'      => get_permalink( $post ),
            'author'    => (int) $post->post_author,
            'date'      => mysql2date( 'c', $post->post_date_gmt, false ),
            'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
        );

        if ( ! empty( $elementor_data ) ) {
            $decoded = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
            $data['elementor'] = array(
                'data'    => $decoded,
                'version' => get_post_meta( $post->ID, '_elementor_version', true ),
            );
        }

        if ( ! empty( $page_settings ) ) {
            $data['page_settings'] = is_string( $page_settings ) ? json_decode( $page_settings, true ) : $page_settings;
        }

        return $data;
    }
}
