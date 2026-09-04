<?php
/**
 * Performance Analyzer — full site audit with caching and grading.
 *
 * Each check returns a finding:
 *   { id, severity, score, title, detail, fix }
 *
 * Severity scale: critical = 25 points, warning = 10, info = 0.
 * Overall score is 100 minus the sum of severity weights.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Performance_Analyzer {

    const CACHE_KEY   = 'mcp_perf_last_run';
    const CACHE_GROUP = 'mcp_perf';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    /** @var array<string, array{label:string, weight:int, run:callable}> */
    private $checks = array();

    public function __construct() {
        $this->register_checks();
    }

    public function analyze( $force = false ) {
        $cached = get_transient( self::CACHE_KEY );
        if ( ! $force && false !== $cached ) {
            return $cached;
        }
        $findings = array();
        $score    = 100;
        foreach ( $this->checks as $id => $cfg ) {
            $result = call_user_func( $cfg['run'] );
            if ( ! is_array( $result ) ) {
                continue;
            }
            $findings[] = $result;
            $score    -= (int) ( $result['weight'] ?? 0 );
        }
        $score = max( 0, min( 100, $score ) );
        $report = array(
            'score'     => $score,
            'grade'     => $this->grade( $score ),
            'findings'  => $findings,
            'count'     => count( $findings ),
            'ran_at'    => current_time( 'mysql' ),
            'site_url'  => home_url(),
            'wp_version'=> get_bloginfo( 'version' ),
            'php_version'=> PHP_VERSION,
            'db_version'=> $this->db_version(),
        );
        set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
        update_option( 'mcp_perf_last_run', $report, false );
        return $report;
    }

    public function last_run() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }
        return $this->analyze( true );
    }

    public function clear_cache() {
        delete_transient( self::CACHE_KEY );
    }

    public function grade( $score ) {
        if ( $score >= 90 ) return 'A';
        if ( $score >= 80 ) return 'B';
        if ( $score >= 70 ) return 'C';
        if ( $score >= 60 ) return 'D';
        return 'F';
    }

    /** Helpers */
    private function finding( $id, $severity, $title, $detail, $fix = '' ) {
        $weights = array( 'critical' => 25, 'warning' => 10, 'info' => 0 );
        return array(
            'id'       => $id,
            'severity' => $severity,
            'weight'   => $weights[ $severity ] ?? 0,
            'title'    => $title,
            'detail'   => $detail,
            'fix'      => $fix,
        );
    }

    private function db_version() {
        global $wpdb;
        return $wpdb->db_version();
    }

    private function count_query( $sql ) {
        global $wpdb;
        return (int) $wpdb->get_var( $sql );
    }

    /** Checks */
    private function register_checks() {
        $this->checks['wp_cron'] = array(
            'label'  => 'WP-Cron is scheduled and reachable',
            'weight' => 10,
            'run'    => array( $this, 'check_wp_cron' ),
        );
        $this->checks['object_cache'] = array(
            'label'  => 'External object cache in use',
            'weight' => 10,
            'run'    => array( $this, 'check_object_cache' ),
        );
        $this->checks['autoload'] = array(
            'label'  => 'autoloaded options size',
            'weight' => 10,
            'run'    => array( $this, 'check_autoload' ),
        );
        $this->checks['revisions'] = array(
            'label'  => 'post revisions',
            'weight' => 10,
            'run'    => array( $this, 'check_revisions' ),
        );
        $this->checks['transients'] = array(
            'label'  => 'expired transients',
            'weight' => 10,
            'run'    => array( $this, 'check_transients' ),
        );
        $this->checks['plugins'] = array(
            'label'  => 'inactive plugins',
            'weight' => 0,
            'run'    => array( $this, 'check_plugins' ),
        );
        $this->checks['themes'] = array(
            'label'  => 'unused themes',
            'weight' => 0,
            'run'    => array( $this, 'check_themes' ),
        );
        $this->checks['php_version'] = array(
            'label'  => 'PHP version',
            'weight' => 25,
            'run'    => array( $this, 'check_php_version' ),
        );
        $this->checks['wp_version'] = array(
            'label'  => 'WordPress version',
            'weight' => 10,
            'run'    => array( $this, 'check_wp_version' ),
        );
        $this->checks['memory_limit'] = array(
            'label'  => 'PHP memory_limit',
            'weight' => 10,
            'run'    => array( $this, 'check_memory' ),
        );
        $this->checks['uploads_writable'] = array(
            'label'  => 'uploads directory writable',
            'weight' => 25,
            'run'    => array( $this, 'check_uploads_writable' ),
        );
        $this->checks['cron_overdue'] = array(
            'label'  => 'overdue cron events',
            'weight' => 10,
            'run'    => array( $this, 'check_cron_overdue' ),
        );
    }

    public function check_wp_cron() {
        $crons = _get_cron_array();
        $next = false;
        foreach ( $crons as $time => $hooks ) {
            if ( $next === false || $time < $next ) {
                $next = $time;
            }
        }
        if ( $next && $next < ( time() - 600 ) ) {
            return $this->finding(
                'wp_cron',
                'critical',
                'WP-Cron is overdue by ' . human_time_diff( $next, time() ),
                'Scheduled events have not run in over 10 minutes. WP-Cron relies on page loads to fire; if traffic is low, tasks pile up.',
                'Switch to a real cron via system crontab. wp-config.php: define("DISABLE_WP_CRON", true); system: */5 * * * * curl -s https://example.com/wp-cron.php > /dev/null 2>&1'
            );
        }
        if ( ! wp_next_scheduled( 'wp_version_check' ) ) {
            return $this->finding(
                'wp_cron',
                'warning',
                'wp_version_check is not scheduled',
                'WordPress core update check is not scheduled. Auto-updates may silently fail.',
                'Visit Updates screen to re-arm the schedule, or define ALTERNATE_WP_CRON.'
            );
        }
        return $this->finding( 'wp_cron', 'info', 'WP-Cron is healthy', 'No issues detected.' );
    }

    public function check_object_cache() {
        $external = wp_using_ext_object_cache();
        if ( ! $external ) {
            return $this->finding(
                'object_cache',
                'warning',
                'No external object cache in use',
                'WP defaults to the database for transients. Redis or Memcached cut DB load by 30-60% on busy sites.',
                'Install the Redis Object Cache plugin (free) or Memcached drop-in.'
            );
        }
        return $this->finding( 'object_cache', 'info', 'External object cache detected', 'Good — reduces DB load.' );
    }

    public function check_autoload() {
        global $wpdb;
        $size = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on')"
        );
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes','on')"
        );
        if ( $size > 1048576 ) {
            return $this->finding(
                'autoload',
                'critical',
                sprintf( '%d autoloaded options, %.1f MB', $count, $size / 1048576 ),
                'Options with autoload=yes load on every request. Over 1 MB is a serious bottleneck.',
                'wp option list --autoload=on --format=table to find big options. Move large transients or per-request caches to a no-autoload value.'
            );
        }
        if ( $count > 800 ) {
            return $this->finding(
                'autoload',
                'warning',
                sprintf( '%d autoloaded options (size is fine but count is high)', $count ),
                'Hundreds of autoloaded options slow every page load even if each is small.',
                'Audit which plugins add autoload=yes rows; many can be set to on-demand instead.'
            );
        }
        return $this->finding( 'autoload', 'info', 'autoload looks fine', sprintf( '%d options, %d KB', $count, $size / 1024 ) );
    }

    public function check_revisions() {
        $count = $this->count_query(
            "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'revision' AND post_parent > 0"
        );
        if ( $count > 500 ) {
            return $this->finding(
                'revisions',
                'warning',
                sprintf( '%d post revisions stored', $count ),
                'A high revision count slows the posts query, bloats the DB, and bogs down autosave.',
                "wp post delete --all --force --post_type=revision  or  wp config: define('WP_POST_REVISIONS', 5);"
            );
        }
        return $this->finding( 'revisions', 'info', sprintf( '%d revisions', $count ), 'Healthy count.' );
    }

    public function check_transients() {
        global $wpdb;
        $expired = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
                $wpdb->esc_like( '_transient_timeout_' ) . '%',
                time()
            )
        );
        if ( $expired > 200 ) {
            return $this->finding(
                'transients',
                'warning',
                sprintf( '%d expired transients', $expired ),
                'Expired transients linger in the options table until a real cleanup runs. Each row is autoloaded, slowing every page load.',
                'wp transient delete --all  or  install a cleanup cron:  wp_schedule_event(time(), "daily", "mcp_transient_sweep");'
            );
        }
        return $this->finding( 'transients', 'info', sprintf( '%d expired transients', $expired ), 'Healthy.' );
    }

    public function check_plugins() {
        $active   = (array) get_option( 'active_plugins', array() );
        $all      = get_plugins();
        $inactive = array_diff( array_keys( $all ), $active );
        return $this->finding(
            'plugins',
            'info',
            sprintf( '%d active, %d inactive', count( $active ), count( $inactive ) ),
            'Inactive plugins cost disk only; not a perf concern.'
        );
    }

    public function check_themes() {
        $current = get_stylesheet();
        $all     = wp_get_themes();
        $unused  = array_filter( array_keys( $all ), function( $slug ) use ( $current ) {
            return $slug !== $current;
        } );
        return $this->finding(
            'themes',
            'info',
            sprintf( '%d themes installed, %d unused', count( $all ), count( $unused ) ),
            'Old themes do not affect front-end performance; remove for hygiene only.'
        );
    }

    public function check_php_version() {
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            return $this->finding(
                'php_version',
                'critical',
                sprintf( 'PHP %s', PHP_VERSION ),
                'PHP 7.4 is end-of-life. Performance gains on 8.x are substantial (often 2x for WordPress).',
                'Ask your host to upgrade to PHP 8.1 or 8.2.'
            );
        }
        if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
            return $this->finding(
                'php_version',
                'warning',
                sprintf( 'PHP %s', PHP_VERSION ),
                'PHP 8.0 lacks several performance improvements in 8.1+.',
                'Upgrade to PHP 8.1 or 8.2.'
            );
        }
        return $this->finding( 'php_version', 'info', sprintf( 'PHP %s is current', PHP_VERSION ), 'Good.' );
    }

    public function check_wp_version() {
        $current = get_bloginfo( 'version' );
        $latest  = $this->get_latest_wp();
        if ( ! $latest ) {
            return $this->finding( 'wp_version', 'info', sprintf( 'WordPress %s (could not check for updates)', $current ), 'Manual upgrade check failed.' );
        }
        if ( version_compare( $current, $latest, '<' ) ) {
            return $this->finding(
                'wp_version',
                'warning',
                sprintf( 'WordPress %s (latest is %s)', $current, $latest ),
                'Patches and performance improvements are missing.',
                'wp core update (test on staging first)'
            );
        }
        return $this->finding( 'wp_version', 'info', sprintf( 'WordPress %s is current', $current ), 'Up to date.' );
    }

    public function check_memory() {
        $m = (int) ini_get( 'memory_limit' );
        if ( $m > 0 && $m < 256 ) {
            return $this->finding(
                'memory_limit',
                'warning',
                sprintf( 'memory_limit = %dM', $m ),
                'Elementor + WooCommerce + modern themes benefit from 256M or more.',
                'wp-config.php: define("WP_MEMORY_LIMIT", "256M");'
            );
        }
        return $this->finding( 'memory_limit', 'info', sprintf( 'memory_limit = %dM', $m ), 'Adequate.' );
    }

    public function check_uploads_writable() {
        $dir = wp_upload_dir();
        if ( ! empty( $dir['error'] ) ) {
            return $this->finding(
                'uploads_writable',
                'critical',
                'uploads directory not writable',
                'Media uploads will fail. Elementor saves JSON to uploads/elementor/css/.',
                'chown -R www-data:www-data wp-content/uploads; chmod -R 755 wp-content/uploads'
            );
        }
        $path = $dir['path'];
        if ( ! is_writable( $path ) ) {
            return $this->finding(
                'uploads_writable',
                'critical',
                'uploads directory is not writable by the web server',
                'Same fix as above.',
                'chown -R www-data:www-data wp-content/uploads; chmod -R 755 wp-content/uploads'
            );
        }
        return $this->finding( 'uploads_writable', 'info', 'uploads directory writable', 'Good.' );
    }

    public function check_cron_overdue() {
        $crons = _get_cron_array();
        $now   = time();
        $overdue = 0;
        $list  = array();
        foreach ( $crons as $time => $hooks ) {
            if ( $time > $now ) continue;
            $age = $now - $time;
            if ( $age < 60 ) continue;
            foreach ( (array) $hooks as $hook => $events ) {
                $overdue += count( $events );
                $list[]  = sprintf( '%s (%s old)', $hook, human_time_diff( $time, $now ) );
                if ( count( $list ) >= 5 ) break;
            }
            if ( count( $list ) >= 5 ) break;
        }
        if ( $overdue > 0 ) {
            return $this->finding(
                'cron_overdue',
                'warning',
                sprintf( '%d cron event(s) overdue', $overdue ),
                'Some scheduled tasks are more than 1 minute late.',
                'Trigger WP-Cron manually: wp cron event run <hook>; or switch to a real cron. Examples: ' . implode( '; ', $list )
            );
        }
        return $this->finding( 'cron_overdue', 'info', 'No overdue cron events', 'Healthy.' );
    }

    private function get_latest_wp() {
        $cache = get_transient( 'mcp_wp_latest' );
        if ( false !== $cache ) {
            return $cache;
        }
        $resp = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 5 ) );
        if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        $ver  = $body['offers'][0]['current'] ?? false;
        if ( $ver ) {
            set_transient( 'mcp_wp_latest', $ver, DAY_IN_SECONDS );
        }
        return $ver;
    }
}