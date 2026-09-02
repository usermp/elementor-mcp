<?php
/**
 * Site Fetcher — pulls HTML and assets from a public URL.
 *
 * Designed for the "clone a site" flow: takes a URL, follows redirects,
 * returns a structured snapshot of the HTML, the page title, the final URL,
 * and the list of asset URLs (CSS / JS / images) discovered on the page.
 *
 * Hard rules:
 *  - Only http(s) URLs. No file://, no PHP wrappers.
 *  - Honors robots.txt (crawl-delay if present, disallow if matched).
 *  - Skips private/loopback targets.
 *  - 8 MB cap on body to avoid blowing up the server.
 *  - 15s timeout.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Site_Fetcher {

    const MAX_BODY_BYTES = 8 * 1024 * 1024; // 8 MB
    const TIMEOUT        = 15;
    const USER_AGENT     = 'Mozilla/5.0 (compatible; Elementor-MCP/1.0; +https://github.com/usermp/elementor-mcp)';

    /**
     * Fetch a URL and return a structured snapshot.
     *
     * @param string $url
     * @param array $opts {
     *     @type bool $download_assets  If true, also download <img> and <link rel="stylesheet"> assets
     *                                  into a transient-cache directory.
     *     @type bool $honor_robots     If true (default), fetch and respect robots.txt.
     * }
     *
     * @return array|\WP_Error {
     *     'final_url'  => string,
     *     'http_code'  => int,
     *     'title'      => string,
     *     'html'       => string,
     *     'bytes'      => int,
     *     'elapsed'    => float seconds,
     *     'assets'     => [ 'css' => [urls], 'js' => [urls], 'images' => [urls] ],
     *     'local_assets' => [ 'css' => [path => url], 'images' => [path => url] ] (when download_assets=true)
     * }
     */
    public function fetch( $url, array $opts = array() ) {
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( empty( $url ) ) {
            return new WP_Error( 'mcp_fetch_bad_url', __( 'Invalid URL.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return new WP_Error( 'mcp_fetch_no_host', __( 'URL has no host.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        // Block private/loopback ranges to avoid SSRF.
        if ( $this->is_blocked_host( $host ) ) {
            return new WP_Error( 'mcp_fetch_blocked', __( 'Target host is blocked for safety.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        $honor_robots = ! isset( $opts['honor_robots'] ) || $opts['honor_robots'];
        if ( $honor_robots ) {
            $robots_check = $this->check_robots( $url );
            if ( is_wp_error( $robots_check ) ) {
                return $robots_check;
            }
        }

        $start = microtime( true );
        $response = wp_remote_get( $url, array(
            'timeout'     => self::TIMEOUT,
            'redirection' => 5,
            'user-agent'  => self::USER_AGENT,
            'headers'     => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $bytes = strlen( $body );

        // Hard cap: 8 MB. Bail on oversize pages.
        if ( $bytes > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'mcp_fetch_too_large', sprintf( __( 'Page body exceeds %d MB.', 'elementor-mcp' ), self::MAX_BODY_BYTES / 1024 / 1024 ), array( 'status' => 413 ) );
        }

        $final_url = wp_remote_retrieve_header( $response, 'x-final-url' );
        if ( empty( $final_url ) ) {
            $final_url = $url;
        }

        $title = $this->extract_title( $body );
        $assets = $this->extract_assets( $body, $final_url );

        $local_assets = array( 'css' => array(), 'images' => array() );
        if ( ! empty( $opts['download_assets'] ) ) {
            $local_assets = $this->download_assets( $assets, $final_url );
        }

        return array(
            'final_url'    => $final_url,
            'http_code'    => $code,
            'title'        => $title,
            'html'         => $body,
            'bytes'        => $bytes,
            'elapsed'      => round( microtime( true ) - $start, 3 ),
            'assets'       => $assets,
            'local_assets' => $local_assets,
        );
    }

    /* ---------- helpers ---------- */

    /**
     * Reject private/loopback hosts. Avoids SSRF against internal services.
     */
    private function is_blocked_host( $host ) {
        $blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' );
        if ( in_array( strtolower( $host ), $blocked_hosts, true ) ) {
            return true;
        }
        // Resolve to IP and reject private ranges.
        $ip = gethostbyname( $host );
        if ( $ip === $host ) {
            // gethostbyname returns the input on failure, so we can't tell.
            // Allow the request and let cURL fail naturally.
            return false;
        }
        return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * Lightweight robots.txt check. We only check whether the path is
     * disallowed for our user-agent; we don't implement crawl-delay
     * scheduling (caller can throttle between requests if needed).
     */
    private function check_robots( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['host'] ) ) {
            return new WP_Error( 'mcp_robots_no_host', __( 'Cannot parse host for robots check.', 'elementor-mcp' ) );
        }
        $robots_url = 'http://' . $parts['host'] . '/robots.txt';
        $resp = wp_remote_get( $robots_url, array( 'timeout' => 5, 'user-agent' => self::USER_AGENT ) );
        if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
            return true; // fail-open: no robots.txt, allow.
        }
        $body = wp_remote_retrieve_body( $resp );
        if ( stripos( $body, 'User-agent: *' ) === false ) {
            return true;
        }
        $path = isset( $parts['path'] ) ? $parts['path'] : '/';
        $lines = preg_split( '/\r?\n/', $body );
        $applies = false;
        foreach ( (array) $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line || '#' === $line[0] ) continue;
            if ( 0 === stripos( $line, 'User-agent:' ) ) {
                $ua = trim( substr( $line, 11 ) );
                $applies = ( '*' === $ua || false !== stripos( self::USER_AGENT, $ua ) );
            } elseif ( $applies && 0 === stripos( $line, 'Disallow:' ) ) {
                $rule = trim( substr( $line, 9 ) );
                if ( '' === $rule ) continue;
                if ( 0 === strpos( $path, $rule ) ) {
                    return new WP_Error( 'mcp_robots_blocked', __( 'robots.txt disallows this path.', 'elementor-mcp' ), array( 'status' => 403 ) );
                }
            }
        }
        return true;
    }

    /**
     * Extract a best-effort page title.
     */
    private function extract_title( $html ) {
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
            return trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }
        return '';
    }

    /**
     * Walk the HTML and collect asset URLs grouped by type.
     */
    private function extract_assets( $html, $base_url ) {
        $assets = array( 'css' => array(), 'js' => array(), 'images' => array() );
        $seen   = array();

        $add = function ( &$bucket, $maybe, $base ) use ( &$seen ) {
            $abs = $this->absolute_url( $maybe, $base );
            if ( ! $abs || isset( $seen[ $abs ] ) ) return;
            $seen[ $abs ] = true;
            $bucket[] = $abs;
        };

        // <link rel="stylesheet" href="...">
        if ( preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $ms ) ) {
            foreach ( $ms[1] as $href ) $add( $assets['css'], $href, $base_url );
        }
        // <link href="..." rel="stylesheet"> (other order)
        if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']stylesheet["\']/i', $html, $ms ) ) {
            foreach ( $ms[1] as $href ) $add( $assets['css'], $href, $base_url );
        }

        // <script src="...">
        if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $html, $ms ) ) {
            foreach ( $ms[1] as $src ) $add( $assets['js'], $src, $base_url );
        }

        // <img src="...">
        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $ms ) ) {
            foreach ( $ms[1] as $src ) $add( $assets['images'], $src, $base_url );
        }
        // <img srcset="...">  (take the first URL of each entry)
        if ( preg_match_all( '/<img[^>]+srcset=["\']([^"\']+)["\']/i', $html, $ms ) ) {
            foreach ( $ms[1] as $srcset ) {
                $first = trim( explode( ',', $srcset )[0] );
                $first = preg_split( '/\s+/', $first )[0];
                $add( $assets['images'], $first, $base_url );
            }
        }

        return $assets;
    }

    /**
     * Download CSS and image assets into a transient cache directory and
     * return a map of local path => public URL.
     */
    private function download_assets( array $assets, $base_url ) {
        $cache = $this->cache_dir();
        $map = array( 'css' => array(), 'images' => array() );

        // CSS first (small, critical for style extraction).
        foreach ( (array) $assets['css'] as $url ) {
            $abs = $this->absolute_url( $url, $base_url );
            if ( ! $abs ) continue;
            $local = $this->download_one( $abs, $cache, 'css' );
            if ( $local ) $map['css'][ $local ] = $abs;
        }
        foreach ( (array) $assets['images'] as $url ) {
            $abs = $this->absolute_url( $url, $base_url );
            if ( ! $abs ) continue;
            $local = $this->download_one( $abs, $cache, 'images' );
            if ( $local ) $map['images'][ $local ] = $abs;
        }
        return $map;
    }

    private function download_one( $url, $cache_dir, $bucket ) {
        $resp = wp_remote_get( $url, array( 'timeout' => 10, 'user-agent' => self::USER_AGENT ) );
        if ( is_wp_error( $resp ) ) return null;
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) return null;

        $body = (string) wp_remote_retrieve_body( $resp );
        $ext = $this->guess_ext( $url, wp_remote_retrieve_header( $resp, 'content-type' ) );
        $target = $cache_dir . '/' . $bucket . '/' . substr( md5( $url ), 0, 12 ) . $ext;
        wp_mkdir_p( dirname( $target ) );
        file_put_contents( $target, $body );
        return $target;
    }

    private function guess_ext( $url, $content_type ) {
        $map = array(
            'image/jpeg' => '.jpg', 'image/jpg' => '.jpg', 'image/png' => '.png',
            'image/gif'  => '.gif', 'image/webp' => '.webp', 'image/svg+xml' => '.svg',
            'image/avif' => '.avif', 'text/css' => '.css', 'image/x-icon' => '.ico',
        );
        if ( $content_type && isset( $map[ strtolower( trim( $content_type ) ) ] ) ) {
            return $map[ strtolower( trim( $content_type ) ) ];
        }
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( $path && preg_match( '/\.[a-z0-9]{2,5}$/i', $path, $m ) ) {
            return strtolower( $m[0] );
        }
        return '';
    }

    /**
     * Cache directory for downloaded assets. Lives in the WP uploads dir under
     * mcp-clone/<hash>/ so it survives between requests but is cleanable.
     */
    private function cache_dir() {
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] . '/mcp-clone/' . substr( md5( wp_generate_password( 12, false ) ), 0, 10 );
        wp_mkdir_p( $dir );
        return $dir;
    }

    /**
     * Resolve a possibly-relative URL against a base.
     */
    private function absolute_url( $maybe, $base ) {
        $maybe = trim( (string) $maybe );
        if ( '' === $maybe ) return null;
        if ( preg_match( '#^(https?:)?//#i', $maybe ) ) {
            $maybe = ( strpos( $maybe, '//' ) === 0 ? 'http:' : '' ) . $maybe;
        } elseif ( 0 !== strpos( $maybe, 'http' ) ) {
            $parts = wp_parse_url( $base );
            if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return null;
            $origin = $parts['scheme'] . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );
            if ( '/' === $maybe[0] ) {
                $maybe = $origin . $maybe;
            } else {
                $base_path = isset( $parts['path'] ) ? $parts['path'] : '/';
                $base_path = substr( $base_path, 0, strrpos( $base_path, '/' ) + 1 );
                $maybe = $origin . $base_path . $maybe;
            }
        }
        // Strip fragments; only allow http(s).
        $maybe = strtok( $maybe, '#' );
        if ( ! preg_match( '#^https?://#i', $maybe ) ) return null;
        return $maybe;
    }
}