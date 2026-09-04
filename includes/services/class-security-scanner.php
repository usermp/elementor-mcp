<?php
/**
 * Security Scanner — full site audit with caching and grading.
 *
 * Each check returns a finding:
 *   { id, severity, score, title, detail, fix }
 *
 * Severity scale: critical = 25, warning = 10, info = 0.
 * Overall score is 100 minus the sum of severity weights.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Security_Scanner {

    const CACHE_KEY   = 'mcp_sec_last_run';
    const CACHE_GROUP = 'mcp_sec';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    /** @var array<string, array{label:string, run:callable}> */
    private $checks = array();

    public function __construct() {
        $this->register_checks();
    }

    public function scan( $force = false ) {
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
        );
        set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
        update_option( 'mcp_sec_last_run', $report, false );
        return $report;
    }

    public function last_run() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }
        return $this->scan( true );
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

    private function db_query_val( $sql ) {
        global $wpdb;
        return $wpdb->get_var( $sql );
    }

    private function count_query( $sql ) {
        return (int) $this->db_query_val( $sql );
    }

    private function register_checks() {
        $this->checks['debug_enabled']      = array( 'label' => 'WP_DEBUG / display_errors', 'run' => array( $this, 'check_debug' ) );
        $this->checks['file_editor']        = array( 'label' => 'File editor disabled',       'run' => array( $this, 'check_file_editor' ) );
        $this->checks['admin_user']          = array( 'label' => 'admin username',            'run' => array( $this, 'check_admin_user' ) );
        $this->checks['db_prefix']           = array( 'label' => 'database prefix',           'run' => array( $this, 'check_db_prefix' ) );
        $this->checks['wp_version']          = array( 'label' => 'WordPress version',         'run' => array( $this, 'check_wp_version' ) );
        $this->checks['php_version']         = array( 'label' => 'PHP version',               'run' => array( $this, 'check_php_version' ) );
        $this->checks['salts']               = array( 'label' => 'unique security salts',     'run' => array( $this, 'check_salts' ) );
        $this->checks['readme']              = array( 'label' => 'readme.html exposed',       'run' => array( $this, 'check_readme' ) );
        $this->checks['xmlrpc']              = array( 'label' => 'xmlrpc.php reachable',      'run' => array( $this, 'check_xmlrpc' ) );
        $this->checks['file_editor_perms']   = array( 'label' => 'wp-config.php permissions','run' => array( $this, 'check_file_editor_perms' ) );
        $this->checks['db_table_prefix']     = array( 'label' => 'db table prefix',          'run' => array( $this, 'check_db_table_prefix' ) );
        $this->checks['login_attempts']      = array( 'label' => 'login attempts',           'run' => array( $this, 'check_login_attempts' ) );
    }

    public function check_debug() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $detail = 'On a production site this can leak stack traces and file paths.';
            $fix    = 'wp-config.php: define("WP_DEBUG", false);  (or define("WP_DEBUG_LOG", true); define("WP_DEBUG_DISPLAY", false);)';
            return $this->finding( 'debug_enabled', 'warning', 'WP_DEBUG is enabled', $detail, $fix );
        }
        return $this->finding( 'debug_enabled', 'info', 'WP_DEBUG is disabled', 'Good.' );
    }

    public function check_file_editor() {
        if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
            return $this->finding( 'file_editor', 'info', 'File editor is disabled', 'Good.' );
        }
        return $this->finding(
            'file_editor',
            'warning',
            'Plugin/Theme file editor is enabled',
            'If an admin account is compromised, the attacker can edit PHP files directly from the dashboard.',
            'wp-config.php: define("DISALLOW_FILE_EDIT", true);'
        );
    }

    public function check_admin_user() {
        $user = get_user_by( 'login', 'admin' );
        if ( ! $user ) {
            return $this->finding( 'admin_user', 'info', 'No "admin" user', 'Good.' );
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return $this->finding(
                'admin_user',
                'critical',
                'Default "admin" user still exists',
                'Half of all WordPress brute-force attacks target "admin". Rename the account to something unique.',
                'wp user update admin --user_pass=<new-strong-pass>  +  wp user delete admin  then promote a new admin user.'
            );
        }
        return $this->finding( 'admin_user', 'info', 'admin user is not an admin', 'Good.' );
    }

    public function check_db_prefix() {
        global $wpdb;
        if ( 'wp_' === $wpdb->prefix ) {
            return $this->finding(
                'db_prefix',
                'warning',
                'Default "wp_" database prefix in use',
                'SQL-injection payloads that target wp_users, wp_posts, etc. are more likely to land. A non-default prefix reduces automated tooling success.',
                'wp-config.php: $table_prefix = "abc_"; then run a prefix-rename SQL on the live DB (BEFORE switching!) — see https://wordpress.org/plugins/broken-link-checker/'
            );
        }
        return $this->finding( 'db_prefix', 'info', sprintf( 'Custom prefix "%s"', $wpdb->prefix ), 'Good.' );
    }

    public function check_wp_version() {
        $current = get_bloginfo( 'version' );
        if ( version_compare( $current, '6.5', '<' ) ) {
            return $this->finding(
                'wp_version',
                'critical',
                sprintf( 'WordPress %s (very old)', $current ),
                'WP < 6.5 has multiple known security issues. Updates include CVE patches.',
                'wp core update  (test on staging first)'
            );
        }
        if ( version_compare( $current, '6.8', '<' ) ) {
            return $this->finding(
                'wp_version',
                'warning',
                sprintf( 'WordPress %s', $current ),
                'Newer versions include security hardening; 6.8+ recommended.',
                'wp core update'
            );
        }
        return $this->finding( 'wp_version', 'info', sprintf( 'WordPress %s is current', $current ), 'Up to date.' );
    }

    public function check_php_version() {
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            return $this->finding(
                'php_version',
                'warning',
                sprintf( 'PHP %s is EOL', PHP_VERSION ),
                'Unsupported PHP versions stop receiving security fixes.',
                'Upgrade to PHP 8.1+ via your host control panel.'
            );
        }
        return $this->finding( 'php_version', 'info', sprintf( 'PHP %s is supported', PHP_VERSION ), 'Good.' );
    }

    public function check_salts() {
        $default = 'put your unique phrase here';
        foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
            if ( defined( $k ) && false !== strpos( constant( $k ), $default ) ) {
                return $this->finding(
                    'salts',
                    'critical',
                    sprintf( 'Default %s salt', $k ),
                    'WordPress salts in wp-config.php are unchanged from the default placeholder. Anyone with the public wp-config-sample.php reference can forge sessions.',
                    'Visit https://api.wordpress.org/secret-key/1.1/salt/ and replace all 8 salt constants.'
                );
            }
        }
        return $this->finding( 'salts', 'info', 'Salts are unique', 'Good.' );
    }

    public function check_readme() {
        $path = ABSPATH . 'readme.html';
        if ( ! file_exists( $path ) ) {
            return $this->finding( 'readme', 'info', 'readme.html not found', 'Good.' );
        }
        return $this->finding(
            'readme',
            'info',
            'readme.html is publicly accessible',
            'It discloses the exact WordPress version, which helps attackers correlate CVEs.',
            'Block via .htaccess/nginx, or delete it. Element of surprise = small but real security benefit.'
        );
    }

    public function check_xmlrpc() {
        $resp = wp_remote_head( home_url( 'xmlrpc.php' ) );
        if ( is_wp_error( $resp ) ) {
            return $this->finding( 'xmlrpc', 'info', 'xmlrpc.php unreachable', 'Good.' );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( 200 === $code ) {
            return $this->finding(
                'xmlrpc',
                'info',
                'xmlrpc.php is reachable',
                'Brute-force attackers often target xmlrpc.php to amplify credential-stuffing attacks.',
                'Disable with: add_filter("xmlrpc_enabled", "__return_false"); in a small mu-plugin.'
            );
        }
        return $this->finding( 'xmlrpc', 'info', sprintf( 'xmlrpc.php returned HTTP %d', $code ), 'OK.' );
    }

    public function check_file_editor_perms() {
        $path = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $path ) ) {
            return $this->finding( 'file_editor_perms', 'info', 'wp-config.php not found', 'Skipped.' );
        }
        $perms = fileperms( $path ) & 0777;
        if ( $perms > 0644 ) {
            return $this->finding(
                'file_editor_perms',
                'warning',
                sprintf( 'wp-config.php permissions are %s (loose)', substr( sprintf( '%o', $perms ), -4 ) ),
                'If the web server is compromised, loose permissions make it easy to read the database password.',
                'chmod 640 wp-config.php; chown www-data:www-data wp-config.php'
            );
        }
        return $this->finding( 'file_editor_perms', 'info', sprintf( 'wp-config.php permissions are %s', substr( sprintf( '%o', $perms ), -4 ) ), 'Good.' );
    }

    public function check_db_table_prefix() {
        global $wpdb;
        $default = 'wp_';
        if ( $default === $wpdb->prefix ) {
            return $this->finding(
                'db_table_prefix',
                'info',
                'Default table prefix in use (covered by db_prefix check)',
                'See the db_prefix finding for fix details.'
            );
        }
        return $this->finding( 'db_table_prefix', 'info', sprintf( 'Custom table prefix "%s"', $wpdb->prefix ), 'Good.' );
    }

    public function check_login_attempts() {
        global $wpdb;
        $table = $wpdb->prefix . 'loginizer_lockout';
        $table2 = $wpdb->prefix . 'wfls_lockouts';
        $table3 = $wpdb->prefix . 'limit_login_attempts';
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) )
            || $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table2 ) )
            || $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table3 ) );
        if ( ! $exists ) {
            return $this->finding(
                'login_attempts',
                'info',
                'No rate-limiting plugin detected for login',
                'A brute-force attacker can try thousands of passwords per minute without throttling.',
                'Install a free rate-limiting plugin (e.g. Limit Login Attempts Reloaded).'
            );
        }
        return $this->finding( 'login_attempts', 'info', 'Rate-limiting plugin detected', 'Good.' );
    }
}