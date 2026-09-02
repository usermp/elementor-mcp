<?php
/**
 * REST handlers for the in-admin chat panel.
 *
 * Loaded by MCP_REST_Controller when the chat endpoint is registered.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Chat_REST {

    /**
     * POST /wp-json/mcp/v1/chat
     *
     * Body:
     *   prompt     string  required  user prompt
     *   post_id    int     optional  page to diff against
     *   history    array   optional  previous messages [{role, content}]
     *   model      string  optional  override model id
     *
     * Returns:
     *   { ok: true, sections: [...], stats: {...}, model: string, usage: {...}, diff?: {...} }
     *   { ok: false, error: string }
     */
    public static function handle( WP_REST_Request $request ) {
        $prompt = trim( (string) $request->get_param( 'prompt' ) );
        if ( '' === $prompt ) {
            return new WP_Error( 'mcp_chat_empty', __( 'Prompt is required.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $post_id = (int) $request->get_param( 'post_id' );
        $history = $request->get_param( 'history' );
        $model   = $request->get_param( 'model' );

        if ( ! is_array( $history ) ) {
            $history = array();
        }

        $opts = array();
        if ( is_string( $model ) && '' !== $model ) {
            $opts['model'] = sanitize_text_field( $model );
        }

        $client = new MCP_OpenCode_Client();
        $result = $client->generate_elementor( $prompt, array_merge( $opts, array( 'history' => $history ) ) );

        if ( is_wp_error( $result ) ) {
            MCP_Logger::warning( 'Chat failed', array( 'error' => $result->get_error_message() ) );
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => $result->get_error_message(),
            ), 200 );
        }

        $payload = array(
            'ok'       => true,
            'sections' => $result,
            'stats'    => self::stats( $result ),
            'model'    => self::extract_model( $result ),
            'prompt'   => $prompt,
        );

        if ( $post_id > 0 ) {
            $current = get_post_meta( $post_id, '_elementor_data', true );
            $current_arr = is_string( $current ) ? json_decode( $current, true ) : ( is_array( $current ) ? $current : array() );
            if ( ! is_array( $current_arr ) ) {
                $current_arr = array();
            }
            $engine = new MCP_Diff_Engine();
            $payload['diff'] = $engine->diff( $current_arr, $result );
        }

        return rest_ensure_response( $payload );
    }

    /**
     * POST /wp-json/mcp/v1/chat/apply
     *
     * Body:
     *   sections   array   required  Elementor sections to write
     *   post_id    int     optional  existing page to update; if 0 a new page is created
     *   title      string  optional  title for new page
     *   status     string  optional  post status for new page (default draft)
     *   dry_run    bool    optional  if true, parse + stats only, no DB writes
     */
    public static function handle_apply( WP_REST_Request $request ) {
        $sections = $request->get_param( 'sections' );
        if ( ! is_array( $sections ) || empty( $sections ) ) {
            return new WP_Error( 'mcp_apply_empty', __( 'Sections payload is missing.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $post_id = (int) $request->get_param( 'post_id' );
        $dry_run = (bool) $request->get_param( 'dry_run' );

        $importer = new MCP_Importer();

        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'mcp_apply_missing', __( 'Target page does not exist.', 'elementor-mcp' ), array( 'status' => 404 ) );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return new WP_Error( 'mcp_apply_forbidden', __( 'You cannot edit this page.', 'elementor-mcp' ), array( 'status' => 403 ) );
            }

            $result = $importer->import( $sections, array(
                'post_id' => $post_id,
                'dry_run' => $dry_run,
            ) );
        } else {
            $title  = trim( (string) $request->get_param( 'title' ) );
            if ( '' === $title ) {
                $title = __( 'AI-generated page', 'elementor-mcp' );
            }
            $status = MCP_Validator::page_status( $request->get_param( 'status' ) );

            if ( ! $dry_run ) {
                $created = wp_insert_post( array(
                    'post_title'  => $title,
                    'post_status' => $status,
                    'post_type'   => 'page',
                    'post_content' => '',
                ), true );
                if ( is_wp_error( $created ) ) {
                    return $created;
                }
                $post_id = (int) $created;
            }

            $result = $importer->import( $sections, array(
                'post_id' => $dry_run ? 0 : $post_id,
                'dry_run' => $dry_run,
            ) );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $result['ok']      = true;
        $result['post_id'] = $dry_run ? null : $post_id;
        $result['edit_url'] = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : null;
        $result['view_url'] = $post_id > 0 ? get_permalink( $post_id ) : null;

        return rest_ensure_response( $result );
    }

    public static function permissions_check( $request ) {
        if ( ! current_user_can( 'edit_pages' ) ) {
            return new WP_Error( 'mcp_chat_forbidden', __( 'You cannot use MCP Chat.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }
        $settings = MCP_Plugin::get_settings();
        $limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
        $rl       = MCP_Rate_Limiter::check_user( 0, $limit );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }
        return true;
    }

    /* ---------- helpers ---------- */

    private static function stats( array $sections ) {
        $counts = array( 'sections' => 0, 'columns' => 0, 'widgets' => 0 );
        $walker = function ( $elements ) use ( &$walker, &$counts ) {
            foreach ( $elements as $el ) {
                if ( ! is_array( $el ) ) continue;
                $t = $el['elType'] ?? '';
                $bucket = $t . 's'; // section -> sections, column -> columns, widget -> widgets
                if ( isset( $counts[ $bucket ] ) ) $counts[ $bucket ]++;
                if ( ! empty( $el['elements'] ) ) $walker( $el['elements'] );
            }
        };
        $walker( $sections );
        $counts['bytes'] = strlen( wp_json_encode( $sections ) );
        return $counts;
    }

    private static function extract_model( $sections ) {
        // sections array doesn't carry model name; rely on global setting.
        $s = MCP_Plugin::get_settings();
        return isset( $s['ai_model'] ) ? $s['ai_model'] : '';
    }
}