<?php
/**
 * Site Crawler — discovers internal pages from a homepage.
 *
 * Given a starting URL, walks the navigation (header nav, footer nav, in-content
 * links) and returns a structured site map: { home, about, services, contact,
 * blog_index, blog_single, ... } keyed by category.
 *
 * Heuristics, not magic: we look at the URL slug, the link's surrounding
 * context, and the link's anchor text to guess what each link represents.
 *
 * Capped at 12 pages per run to keep AI cost reasonable.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Site_Crawler {

    const MAX_PAGES = 12;

    /**
     * @param string $home_url
     * @return array|\WP_Error {
     *     'home'    => string,
     *     'pages'   => [ ['url' => string, 'role' => string, 'title' => string, 'hint' => string], ... ],
     *     'palette' => [string],
     *     'brand'   => [ 'name' => string, 'tagline' => string ],
     * }
     */
    public function crawl( $home_url ) {
        $fetcher = new MCP_Site_Fetcher();
        $home = $fetcher->fetch( $home_url );
        if ( is_wp_error( $home ) ) {
            return $home;
        }

        $analyzer = new MCP_DOM_Analyzer();
        $analysis = $analyzer->analyze( $home['html'], array( 'max_sections' => 12 ) );

        $home_host = strtolower( (string) wp_parse_url( $home['final_url'], PHP_URL_HOST ) );

        $links = $this->extract_internal_links( $home['html'], $home['final_url'] );
        $pages = $this->classify_links( $links, $home_host, $home['final_url'] );

        // Always include the homepage itself first.
        $pages = array_merge(
            array( array(
                'url'   => $home['final_url'],
                'role'  => 'home',
                'title' => $home['title'] ?: 'Home',
                'hint'  => 'homepage, hero + main landing',
            ) ),
            $pages
        );

        // Infer missing standard pages (about/services/contact/blog) by URL probing
        // common paths. Cheap because we HEAD them; skip if not 200.
        $pages = $this->probe_common_paths( $pages, $home['final_url'] );

        // Dedupe by URL, keeping the first occurrence.
        $seen = array();
        $unique = array();
        foreach ( $pages as $p ) {
            $norm = $this->normalize_url( $p['url'] );
            if ( isset( $seen[ $norm ] ) ) continue;
            $seen[ $norm ] = true;
            $unique[] = $p;
            if ( count( $unique ) >= self::MAX_PAGES ) break;
        }

        return array(
            'home'    => $home['final_url'],
            'pages'   => $unique,
            'palette' => array_slice( (array) ( $analysis['palette'] ?? array() ), 0, 8 ),
            'brand'   => array(
                'name'    => $home['title'] ?: '',
                'tagline' => $this->extract_tagline( $home['html'] ),
            ),
        );
    }

    /**
     * For roles we still don't have a URL for, try common slug patterns via
     * HEAD requests. Anything that returns 200 is added to the page list.
     * This handles sites where nav only contains product/service links and
     * the about/contact pages aren't in the menu.
     */
    private function probe_common_paths( array $pages, $base_url ) {
        $existing_roles = array();
        foreach ( $pages as $p ) {
            $existing_roles[ $p['role'] ] = true;
        }

        $probes = array(
            'about'         => array( '/about', '/about-us', '/درباره-ما', '/درباره', '/درباره_ما', '/company', '/our-story' ),
            'services'      => array( '/services', '/products', '/solutions', '/what-we-do' ),
            'contact'       => array( '/contact', '/contact-us', '/تماس', '/تماس-با-ما', '/get-in-touch' ),
            'blog_index'    => array( '/blog', '/news', '/مجله', '/مقالات', '/بلاگ', '/articles', '/mag' ),
            'faq'           => array( '/faq', '/questions', '/سوالات-متداول', '/help' ),
            'team'          => array( '/team', '/about/team', '/staff', '/our-team' ),
        );

        foreach ( $probes as $role => $paths ) {
            if ( isset( $existing_roles[ $role ] ) ) continue;
            foreach ( $paths as $path ) {
                $url = rtrim( $base_url, '/' ) . $path;
                if ( $this->head_ok( $url ) ) {
                    $pages[] = array(
                        'url'   => $url,
                        'role'  => $role,
                        'title' => ucwords( str_replace( array( '-', '_' ), ' ', $role ) ),
                        'hint'  => $this->hint_for_role( $role ),
                    );
                    break;
                }
            }
        }
        return $pages;
    }

    /**
     * Quick HEAD request to check if a path exists on the same host.
     * Respects 5-second timeout, doesn't follow >3 redirects.
     */
    private function head_ok( $url ) {
        $resp = wp_remote_head( $url, array(
            'timeout'     => 5,
            'redirection' => 3,
            'user-agent'  => MCP_Site_Fetcher::USER_AGENT,
        ) );
        if ( is_wp_error( $resp ) ) return false;
        $code = (int) wp_remote_retrieve_response_code( $resp );
        return $code >= 200 && $code < 400;
    }

    /* ---------- extraction ---------- */

    private function extract_internal_links( $html, $base_url ) {
        $links = array();

        $priority_patterns = array(
            '#<nav\b[^>]*>(.*?)</nav>#is',
            '#<header\b[^>]*>(.*?)</header>#is',
            '#<footer\b[^>]*>(.*?)</footer>#is',
        );
        $priority_html = '';
        foreach ( $priority_patterns as $p ) {
            if ( preg_match_all( $p, $html, $ms ) ) {
                foreach ( $ms[1] as $chunk ) $priority_html .= $chunk;
            }
        }

        $link_pattern = '/<a\b[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is';

        if ( preg_match_all( $link_pattern, $priority_html, $ms ) ) {
            foreach ( $ms[1] as $i => $href ) {
                $anchor = trim( strip_tags( $ms[2][ $i ] ) );
                if ( '' === $anchor ) continue;
                $links[] = array( 'href' => $href, 'anchor' => $anchor, 'priority' => 1 );
            }
        }
        if ( preg_match_all( $link_pattern, $html, $ms ) ) {
            foreach ( $ms[1] as $i => $href ) {
                $anchor = trim( strip_tags( $ms[2][ $i ] ) );
                if ( '' === $anchor ) continue;
                $links[] = array( 'href' => $href, 'anchor' => $anchor, 'priority' => 0 );
            }
        }
        return $links;
    }

    /**
     * Classify a list of (href, anchor) pairs into page roles.
     * Resolves relative URLs against $base_url, then filters by host.
     */
    private function classify_links( array $links, $home_host, $base_url ) {
        $out = array();
        $best = array();

        foreach ( $links as $l ) {
            $abs = $this->resolve_url( $l['href'], $base_url );
            if ( ! $abs ) continue;
            $host = strtolower( (string) wp_parse_url( $abs, PHP_URL_HOST ) );
            if ( $host !== $home_host ) continue;
            $key = $this->normalize_url( $abs );
            if ( ! isset( $best[ $key ] ) || $best[ $key ]['priority'] < $l['priority'] ) {
                $best[ $key ] = array( 'url' => $abs, 'anchor' => $l['anchor'], 'priority' => $l['priority'] );
            }
        }

        foreach ( $best as $entry ) {
            $role = $this->guess_role( $entry['url'], $entry['anchor'] );
            if ( ! $role ) continue;
            $out[] = array(
                'url'   => $entry['url'],
                'role'  => $role,
                'title' => ucwords( str_replace( array( '-', '_' ), ' ', $role ) ),
                'hint'  => $this->hint_for_role( $role ),
            );
        }
        return $out;
    }

    /**
     * Match a URL+anchor against a known set of role keywords.
     * First-match wins; more specific rules should come first.
     */
    private function guess_role( $url, $anchor ) {
        $haystack = ' ' . strtolower( $url ) . ' ' . strtolower( $anchor ) . ' ';

        // Specific destination rules first.
        $rules = array(
            'contact'      => array( 'contact-us', 'تماس-با-ما', 'تماس با ما', 'contact_us' ),
            'about'        => array( '/about', '/درباره-ما', '/درباره ما', '/about-us', 'about-us', 'درباره ما' ),
            'blog_index'   => array( '/blog', '/news', '/مجله', '/مقالات', '/بلاگ' ),
            'blog_single'  => array( '/post/', '/article/', '?p=', '?id=' ),
            'tours_international' => array( '/tours/turkey', '/tours/europe', '/tours/asia', 'تور-خارجی', 'تور خارجی' ),
            'tours_domestic' => array( '/tours/domestic', '/تور-داخلی', 'تور داخلی', '/tours/mashhad', '/mashhad-air-tour', '/tour-of-mashhad' ),
            'tours_special' => array( '/tours/special', 'تور-ویژه', 'تورهای ویژه' ),
            'tours_index'  => array( '/tours/' ),
            'contact'      => array( '/contact', '/تماس' ),
            'faq'          => array( '/faq', 'سوالات-متداول' ),
            'team'         => array( '/team', '/staff' ),
            'portfolio'    => array( '/portfolio', '/work' ),
            'services'     => array( '/services', '/products' ),
        );

        foreach ( $rules as $role => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( false !== strpos( $haystack, $kw ) ) {
                    if ( 'blog_single' === $role ) {
                        if ( preg_match( '#/(\d{4}/\d{2}/|/post/|/article/|/blog/[^/]+/?(\?|$))#', $url ) ) {
                            return $role;
                        }
                        continue;
                    }
                    return $role;
                }
            }
        }
        return null;
    }

    private function hint_for_role( $role ) {
        $hints = array(
            'about'        => 'about-us page with company story, mission/values, team grid',
            'about_us'     => 'about-us page with company story, mission/values, team grid',
            'services'     => 'services page with a hero, 3-6 service cards, and a CTA',
            'tours'        => 'tours listing page with featured tour hero and 6-card tour grid',
            'tours_index'  => 'tours index with a hero, filter sidebar, and 6-card tour grid',
            'tours_international' => 'international tours page with hero and 6 tour cards',
            'tours_domestic' => 'domestic tours page with hero and 6 tour cards',
            'tours_special' => 'special tours page with hero and featured tours',
            'pricing'      => 'pricing page with 3 tier cards, a comparison table, and a CTA',
            'contact'      => 'contact page with a form, address/phone/email block, and a map placeholder',
            'blog_index'   => 'blog index with a featured-post hero and a 6-card grid of recent posts',
            'blog_single'  => 'blog single post with a hero image, byline, body text, and a related-posts strip',
            'portfolio'    => 'portfolio page with a filterable grid of 6-9 case studies',
            'team'         => 'team page with a hero, leadership grid (4-6 cards), and a culture section',
            'faq'          => 'FAQ page with an accordion of 6-10 questions',
            'testimonials' => 'testimonials page with a hero, 6 quote cards, and a CTA',
        );
        return $hints[ $role ] ?? $role;
    }

    /**
     * Pull the first <p> after the first <h1> as a tagline candidate.
     */
    private function extract_tagline( $html ) {
        if ( preg_match( '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        if ( preg_match( '#<header\b[^>]*>(.*?)</header>#is', $html, $m ) ) {
            if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $m[1], $p ) ) {
                return trim( strip_tags( $p[1] ) );
            }
        }
        if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $html, $p ) ) {
            return trim( strip_tags( $p[1] ) );
        }
        return '';
    }

    /* ---------- URL helpers ---------- */

    /**
     * Resolve any href to an absolute URL. Returns null for non-page schemes.
     */
    private function resolve_url( $href, $base_url ) {
        $href = trim( (string) $href );
        if ( '' === $href ) return null;
        if ( '#' === $href[0] ) return null;
        $skip = array( 'mailto:', 'tel:', 'javascript:', 'whatsapp:' );
        $lower = strtolower( $href );
        foreach ( $skip as $s ) {
            if ( 0 === strpos( $lower, $s ) ) return null;
        }
        if ( preg_match( '#^https?://#i', $href ) ) return $href;
        if ( 0 === strpos( $href, '//' ) ) return 'http:' . $href;

        $base = wp_parse_url( $base_url );
        if ( ! $base || empty( $base['host'] ) ) return null;
        $origin = $base['scheme'] . '://' . $base['host'] . ( ! empty( $base['port'] ) ? ':' . $base['port'] : '' );
        if ( '/' === $href[0] ) {
            return $origin . $href;
        }
        $base_path = isset( $base['path'] ) ? $base['path'] : '/';
        $base_path = substr( $base_path, 0, strrpos( $base_path, '/' ) + 1 );
        return $origin . $base_path . $href;
    }

    private function normalize_url( $url ) {
        $url = strtok( $url, '#' );
        $url = rtrim( $url, '/' );
        $parts = wp_parse_url( $url );
        if ( $parts ) {
            $host = strtolower( $parts['host'] ?? '' );
            $path = $parts['path'] ?? '';
            $url = ( $parts['scheme'] ?? 'https' ) . '://' . $host . $path;
        }
        return $url;
    }
}