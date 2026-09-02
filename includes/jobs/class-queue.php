<?php
/**
 * Job queue - uses Action Scheduler if available, falls back to WP-Cron.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Queue {

    const HOOK_SUFFIX = 'mcp_job_';

    public static function enqueue( $job_name, array $args = array(), $when = 'now' ) {
        $hook = self::HOOK_SUFFIX . sanitize_key( $job_name );

        $payload = array(
            'job'      => sanitize_key( $job_name ),
            'args'     => $args,
            'enqueued' => time(),
        );

        if ( self::action_scheduler_available() ) {
            $timestamp = ( 'now' === $when ) ? time() : (int) $when;
            as_schedule_single_action( $timestamp, $hook, array( $payload ) );
            return true;
        }

        $transient_key = 'mcp_job_' . md5( $hook . wp_json_encode( $payload ) );
        set_transient( $transient_key, $payload, DAY_IN_SECONDS );

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time() + 30, 'hourly', $hook );
        }
        return true;
    }

    public static function register_handler( $job_name, $callback, $accepted_args = 1 ) {
        if ( ! is_callable( $callback ) ) {
            return false;
        }
        $hook = self::HOOK_SUFFIX . sanitize_key( $job_name );
        add_action( $hook, $callback, 10, $accepted_args );
        return true;
    }

    public static function action_scheduler_available() {
        return function_exists( 'as_schedule_single_action' );
    }

    public static function stats() {
        if ( self::action_scheduler_available() ) {
            return array(
                'engine' => 'action-scheduler',
                'pending' => self::as_count( 'pending' ),
                'running' => self::as_count( 'in-progress' ),
                'complete' => self::as_count( 'complete' ),
                'failed' => self::as_count( 'failed' ),
            );
        }
        return array(
            'engine' => 'wp-cron',
            'note'   => __( 'Action Scheduler not active. Install or activate WooCommerce / Action Scheduler for advanced queue features.', 'elementor-mcp' ),
        );
    }

    private static function as_count( $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_actions';
        $like  = $wpdb->esc_like( 'mcp_job_' ) . '%';
        $sql   = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND hook LIKE %s",
            $status,
            $like
        );
        return (int) $wpdb->get_var( $sql );
    }
}
