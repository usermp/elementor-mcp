<?php
/**
 * DOM Analyzer — turns raw HTML into a structured Elementor-shaped map.
 *
 * Goal: not to be a perfect converter (we let the AI do the visual judgment),
 * but to extract enough structural and stylistic signal that the AI prompt
 * stays under the token budget and doesn't have to re-parse the page.
 *
 * Outputs:
 *  - sections[]: a list of detected top-level sections, each with
 *      { background, padding, layout, columns[], heading?, cta? }
 *  - palette: dominant colors and their frequency
 *  - typography: detected font families and font sizes
 *  - assets: pass-through from MCP_Site_Fetcher
 *
 * The analyzer is best-effort. If the page is a SPA, the body is mostly
 * empty and the AI prompt will need to lean on the assets list / URL alone.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_DOM_Analyzer {

    /**
     * Analyze HTML and return a structured map.
     *
     * @param string $html
     * @param array  $opts {
     *     @type int   $max_sections   Hard cap on output sections (default 20).
     *     @type array $inline_styles  Pass-through from MCP_Style_Extractor (best-effort).
     * }
     *
     * @return array
     */
    public function analyze( $html, array $opts = array() ) {
        $max_sections = isset( $opts['max_sections'] ) ? max( 1, (int) $opts['max_sections'] ) : 20;
        $inline_styles = isset( $opts['inline_styles'] ) && is_array( $opts['inline_styles'] ) ? $opts['inline_styles'] : array();

        if ( ! class_exists( 'DOMDocument' ) ) {
            return array( 'sections' => array(), 'palette' => array(), 'typography' => array(), 'error' => 'DOMDocument unavailable' );
        }

        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        // Suppress the HTML5 warning; DOMDocument is strict but we want best-effort.
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $xpath = new DOMXPath( $dom );

        $sections = $this->detect_sections( $xpath, $max_sections, $inline_styles );
        $palette  = $this->extract_palette( $html );
        $type     = $this->extract_typography( $html );

        return array(
            'sections'   => $sections,
            'palette'    => $palette,
            'typography' => $type,
            'stats'      => array(
                'h1'        => $xpath->query( '//h1' )->length,
                'h2'        => $xpath->query( '//h2' )->length,
                'h3'        => $xpath->query( '//h3' )->length,
                'paragraphs'=> $xpath->query( '//p' )->length,
                'links'     => $xpath->query( '//a' )->length,
                'images'    => $xpath->query( '//img' )->length,
                'forms'     => $xpath->query( '//form' )->length,
            ),
        );
    }

    /* ---------- detection ---------- */

    /**
     * Find top-level "section-like" blocks. Heuristic: a direct child of <body>
     * that is one of <section>, <div class="container">, <header>, <footer>,
     * or has a substantial block-level structure inside.
     */
    private function detect_sections( DOMXPath $xpath, $max, array $inline_styles ) {
        $out = array();
        $body = $xpath->query( '//body' )->item( 0 );
        if ( ! $body ) return $out;

        // Candidate selectors, in priority order. We cast a wide net then
        // dedupe by walking up the tree to skip nodes that are already inside
        // a detected ancestor.
        $xpath_query = '//body//section
                       | //body//article
                       | //body//header
                       | //body//footer
                       | //body//main
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " section ")]
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " hero ")]
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " container ")]
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " wrapper ")]
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " row ")]
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " panel ")]
                       | //body//*[contains(concat(" ", normalize-space(@class), " "), " block ")]';

        $candidates = $this->x( $xpath, $xpath_query );

        $index = 0;
        $seen_signatures = array();
        foreach ( $candidates as $node ) {
            if ( $index >= $max ) break;

            // Skip if this node is inside a previously kept ancestor.
            if ( $this->has_kept_ancestor( $node, $seen_signatures ) ) continue;

            $struct = $this->section_structure( $xpath, $node, $index );
            if ( ! $struct ) continue;

            $sig = $this->node_signature( $node );
            $seen_signatures[ $sig ] = true;

            $out[] = $struct;
            $index++;
        }

        return $out;
    }

    /**
     * Build a simple signature for a DOMNode to dedupe overlapping candidates.
     */
    private function node_signature( $node ) {
        return $node->getNodePath();
    }

    /**
     * Walk up the DOM looking for any ancestor already kept.
     */
    private function has_kept_ancestor( $node, array $sigs ) {
        $parent = $node->parentNode;
        while ( $parent && $parent->nodeType === XML_ELEMENT_NODE ) {
            if ( isset( $sigs[ $parent->getNodePath() ] ) ) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    /**
     * Build a structured representation of one section.
     */
    private function section_structure( DOMXPath $xpath, $node, $index ) {
        $classes = (string) $node->getAttribute( 'class' );
        $style   = (string) $node->getAttribute( 'style' );
        $tag     = strtolower( $node->nodeName );

        // Background: prefer inline style, fall back to data-attrs.
        $bg = $this->bg_from_style( $style );

        // Column split: count direct children that are <div> or <section> and have block-level kids.
        $columns = $this->detect_columns( $xpath, $node );
        if ( empty( $columns ) ) {
            // Flat section with no columns: treat the whole node as one column.
            $columns = array( $this->column_structure( $xpath, $node, 0, 100 ) );
        }

        // Find the first heading as a section label.
        $first_heading = $xpath->evaluate( 'string(.//h1[1] | .//h2[1] | .//h3[1])', $node );
        $first_heading = trim( $first_heading );

        $text = $this->text_density( $xpath, $node );
        $images = $xpath->evaluate( 'count(.//img)', $node );

        return array(
            'index'     => $index,
            'tag'       => $tag,
            'classes'   => $this->tokenize_classes( $classes ),
            'style'     => $style,
            'background'=> $bg,
            'padding'   => $this->padding_from_style( $style ),
            'heading'   => $first_heading ?: null,
            'text_chars'=> $text,
            'image_count' => (int) $images,
            'columns'   => $columns,
        );
    }

    /**
     * Treat the section as a row of columns. Look at direct child <div>s that
     * contain block-level content.
     */
    private function detect_columns( DOMXPath $xpath, $section ) {
        $out = array();
        $children = $xpath->query( './div | ./section | ./article | ./ul/li', $section );
        if ( ! $children || $children->length === 0 ) return $out;

        $total = 0;
        $weights = array();
        foreach ( $children as $i => $child ) {
            $w = (int) $xpath->evaluate( 'count(.//p|.//li|.//h1|.//h2|.//h3|.//h4|.//img|.//a)', $child );
            if ( $w <= 0 ) continue;
            $weights[ $i ] = $w;
            $total += $w;
        }
        if ( 0 === $total || count( $weights ) < 1 ) return $out;

        $idx = 0;
        foreach ( $children as $i => $child ) {
            if ( ! isset( $weights[ $i ] ) ) continue;
            $pct = (int) round( $weights[ $i ] * 100 / $total );
            if ( $pct < 5 ) continue; // ignore thin sidebar
            $out[] = $this->column_structure( $xpath, $child, $idx, $pct );
            $idx++;
        }
        return $out;
    }

    private function column_structure( DOMXPath $xpath, $node, $index, $width_pct ) {
        $headings = array();
        foreach ( $xpath->query( './/h1 | .//h2 | .//h3 | .//h4', $node ) as $h ) {
            $headings[] = trim( $h->textContent );
        }
        $paragraphs = array();
        foreach ( $xpath->query( './/p', $node ) as $p ) {
            $t = trim( $p->textContent );
            if ( '' !== $t ) $paragraphs[] = mb_substr( $t, 0, 200 );
        }
        $buttons = array();
        foreach ( $xpath->query( './/a[.//button] | .//button | .//a[contains(@class,"btn") or contains(@class,"button")]', $node ) as $b ) {
            $text = trim( $b->textContent );
            $href = (string) $b->getAttribute( 'href' );
            if ( '' !== $text ) $buttons[] = array( 'text' => mb_substr( $text, 0, 80 ), 'href' => $href );
        }
        $images = array();
        foreach ( $xpath->query( './/img', $node ) as $img ) {
            $images[] = array(
                'src'   => (string) $img->getAttribute( 'src' ),
                'alt'   => (string) $img->getAttribute( 'alt' ),
            );
        }
        $list_items = array();
        foreach ( $xpath->query( './/ul/li | .//ol/li', $node ) as $li ) {
            $list_items[] = trim( $li->textContent );
        }

        return array(
            'index'   => $index,
            'width'   => $width_pct,
            'headings'=> array_slice( $headings, 0, 6 ),
            'paragraphs' => array_slice( $paragraphs, 0, 4 ),
            'buttons' => array_slice( $buttons, 0, 4 ),
            'images'  => array_slice( $images, 0, 4 ),
            'list_items' => array_slice( $list_items, 0, 6 ),
        );
    }

    private function text_density( DOMXPath $xpath, $node ) {
        $t = (string) $xpath->evaluate( 'string(.)', $node );
        return mb_strlen( preg_replace( '/\s+/', '', $t ) );
    }

    /* ---------- styling heuristics ---------- */

    private function bg_from_style( $style ) {
        if ( '' === $style ) return null;
        if ( preg_match( '/background(?:-color)?\s*:\s*([^;]+)/i', $style, $m ) ) {
            return trim( $m[1] );
        }
        return null;
    }

    private function padding_from_style( $style ) {
        if ( '' === $style ) return null;
        if ( preg_match( '/padding(?:-top)?\s*:\s*([^;]+)/i', $style, $m ) ) {
            return trim( $m[1] );
        }
        return null;
    }

    /**
     * Split a class attribute into useful tokens (skip our own internal markers).
     */
    private function tokenize_classes( $classes ) {
        $parts = preg_split( '/\s+/', trim( $classes ) );
        $clean = array();
        foreach ( (array) $parts as $p ) {
            if ( '' === $p ) continue;
            if ( strlen( $p ) > 60 ) continue; // skip weird obfuscated class names
            $clean[] = $p;
        }
        return array_slice( $clean, 0, 12 );
    }

    /* ---------- color + typography extraction ---------- */

    /**
     * Scan inline style blocks (and style="" attrs) for color and background-color
     * values, then rank by frequency.
     */
    private function extract_palette( $html ) {
        $freq = array();
        $patterns = array(
            '/color\s*:\s*(#[0-9a-f]{3,8}|rgb\([^)]+\)|rgba\([^)]+\)|[a-z]+)/i',
            '/background(?:-color)?\s*:\s*(#[0-9a-f]{3,8}|rgb\([^)]+\)|rgba\([^)]+\)|[a-z]+)/i',
            '/border-color\s*:\s*(#[0-9a-f]{3,8}|rgb\([^)]+\)|[a-z]+)/i',
        );
        foreach ( $patterns as $p ) {
            if ( preg_match_all( $p, $html, $ms ) ) {
                foreach ( $ms[1] as $c ) {
                    $c = strtolower( trim( $c ) );
                    if ( 'transparent' === $c || 'inherit' === $c || 'initial' === $c ) continue;
                    $freq[ $c ] = ( $freq[ $c ] ?? 0 ) + 1;
                }
            }
        }
        arsort( $freq );
        return array_slice( array_keys( $freq ), 0, 10 );
    }

    /**
     * Detect font families and sizes from CSS.
     */
    private function extract_typography( $html ) {
        $families = array();
        $sizes    = array();
        if ( preg_match_all( '/font-family\s*:\s*([^;}{]+)/i', $html, $ms ) ) {
            foreach ( $ms[1] as $raw ) {
                $parts = array_map( 'trim', explode( ',', $raw ) );
                foreach ( $parts as $p ) {
                    $p = trim( $p, " '\"" );
                    if ( '' === $p || 'inherit' === strtolower( $p ) ) continue;
                    $families[ $p ] = ( $families[ $p ] ?? 0 ) + 1;
                }
            }
        }
        if ( preg_match_all( '/font-size\s*:\s*([^;}{]+)/i', $html, $ms ) ) {
            foreach ( $ms[1] as $s ) {
                $s = trim( $s );
                $sizes[ $s ] = ( $sizes[ $s ] ?? 0 ) + 1;
            }
        }
        arsort( $families );
        arsort( $sizes );
        return array(
            'families' => array_slice( array_keys( $families ), 0, 5 ),
            'sizes'    => array_slice( array_keys( $sizes ), 0, 8 ),
        );
    }

    /* ---------- xpath helper ---------- */

    private function x( DOMXPath $xpath, $q ) {
        $list = array();
        foreach ( $xpath->query( $q ) as $n ) $list[] = $n;
        return $list;
    }
}