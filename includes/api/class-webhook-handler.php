<?php
/**
 * Webhook handler - receives events from OpenCode.
 *
 * Endpoint: POST /wp-json/mcp/v1/webhook
 * Auth: X-MCP-Signature header (HMAC of body with webhook_secret)
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Webhook_Handler {

    const NONCE_ACTION = 'mcp_webhook';

    public function handle( WP_REST_Request $request ) {
        $settings = MCP_Plugin::get_settings();
        $secret   = isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '';

        $ip_check = MCP_Rate_Limiter::check_ip( 30 );
        if ( is_wp_error( $ip_check ) ) {
            return $ip_check;
        }

        $body      = $request->get_body();
        $signature = $request->get_header( 'x_mcp_signature' );
        if ( ! $signature ) {
            $signature = $request->get_header( 'x-mcp-signature' );
        }

        if ( ! MCP_Auth::verify_webhook_signature( $body, $signature, $secret ) ) {
            MCP_Logger::warning( 'Webhook signature invalid', array( 'ip' => MCP_Rate_Limiter::get_client_ip() ) );
            return new WP_Error( 'mcp_invalid_signature', __( 'Invalid signature.', 'elementor-mcp' ), array( 'status' => 401 ) );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return new WP_Error( 'mcp_invalid_payload', __( 'Invalid JSON body.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $event = isset( $data['event'] ) ? sanitize_key( $data['event'] ) : '';

        if ( ! $event ) {
            return new WP_Error( 'mcp_missing_event', __( 'Event field is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        MCP_Logger::info( 'Webhook received', array( 'event' => $event ) );

        do_action( 'mcp_webhook_received', $event, $data );

        $result = $this->dispatch( $event, $data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'  => 'accepted',
            'event'   => $event,
            'message' => __( 'Webhook processed.', 'elementor-mcp' ),
            'result'  => $result,
        ) );
    }

    private function dispatch( $event, array $data ) {
        switch ( $event ) {
            case 'page.create':
                return $this->handle_page_create( $data );

            case 'page.update':
                return $this->handle_page_update( $data );

            case 'page.delete':
                return $this->handle_page_delete( $data );

            case 'template.create':
                return $this->handle_template_create( $data );

            case 'ping':
                return array( 'pong' => true, 'time' => current_time( 'mysql' ) );

            default:
                return new WP_Error( 'mcp_unknown_event', sprintf( __( 'Unknown event: %s', 'elementor-mcp' ), $event ), array( 'status' => 400 ) );
        }
    }

    private function handle_page_create( $data ) {
        if ( ! current_user_can( 'edit_pages' ) ) {
            return new WP_Error( 'mcp_forbidden', __( 'Cannot create pages.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        $builder = new MCP_Page_Builder();
        return $builder->create( array(
            'post_title'   => isset( $data['title'] ) ? MCP_Validator::sanitize_text( $data['title'] ) : 'Untitled',
            'post_status'  => MCP_Validator::page_status( isset( $data['status'] ) ? $data['status'] : 'draft' ),
            'post_type'    => 'page',
            'post_content' => isset( $data['content'] ) ? MCP_Validator::sanitize_html( $data['content'] ) : '',
        ), $data );
    }

    private function handle_page_update( $data ) {
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id <= 0 ) {
            return new WP_Error( 'mcp_missing_id', __( 'Page ID is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        $builder = new MCP_Page_Builder();
        return $builder->update( $id, $data );
    }

    private function handle_page_delete( $data ) {
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id <= 0 ) {
            return new WP_Error( 'mcp_missing_id', __( 'Page ID is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }
        $deleted = wp_delete_post( $id, true );
        return $deleted ? array( 'deleted' => true ) : new WP_Error( 'mcp_delete_failed', __( 'Delete failed.', 'elementor-mcp' ), array( 'status' => 500 ) );
    }

    private function handle_template_create( $data ) {
        if ( ! current_user_can( 'edit_pages' ) ) {
            return new WP_Error( 'mcp_forbidden', __( 'Cannot create templates.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        $tm = new MCP_Template_Manager();
        return $tm->create( $data );
    }
}
