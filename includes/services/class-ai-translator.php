<?php
/**
 * AI Translator — turn a Site_Fetcher + DOM_Analyzer payload into Elementor JSON.
 *
 * This is the heart of the "clone a site" flow. We send the model a tight,
 * structured brief (cleaned HTML + analyzer map + palette + typography) and
 * the same Elementor system prompt we use for direct chat generation.
 *
 * Token economy: we strip scripts, inline styles, and unused attributes
 * before sending. The analyzer map is JSON-encoded and embedded as a
 * "structural hints" block so the model doesn't have to re-derive the layout.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_AI_Translator {

    const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert Elementor page designer who also reads raw HTML and CSS.
You will receive a TARGET PAGE SUMMARY (URL, title, structural map, palette,
typography, condensed HTML) and must produce a valid Elementor page JSON.

Hard requirements:
- Output ONLY a single fenced JSON code block: ```json ... ```
- The JSON MUST be an array of section objects (the root of Elementor's
  `_elementor_data`).
- Use these element types only:
  - "section" (with "_isInner": false at root, true for nested)
  - "column" (children of sections)
  - "widget" with one of these widgetType values:
    heading, image, text-editor, button, divider, spacer,
    icon, icon-box, image-box, star-rating, basic-gallery, counter, progress,
    alert, testimonial, social-icons, tabs, accordion, toggle
- Every element MUST have a unique id (alphanumeric, 6+ chars).
- Every element MUST have an "elType" key.
- Every widget MUST have a "widgetType" key.
- Use inline-style CSS strings for backgrounds/typography when needed.
- Keep the response under 80 KB. Prefer simple, clean designs.
- Do NOT include any text outside the JSON code block.

Style fidelity rules:
- Match the source palette: backgrounds, text colors, and accent colors.
- Match typography: use the source font family when reasonable, fall back
  to a system font (system-ui, sans-serif) for the first run.
- Honor the structural map: each entry in `structural_hints.sections[]`
  should become one Elementor section, preserving column widths (rounded
  to common Elementor values: 50/50, 33/33/33, 25/25/25/25, 70/30, 30/70).
- For each section, copy: background, padding (40-100px vertical),
  text density (heading + body + CTA pattern), and CTA button text.

Elementor section shape (example):
{
  "id": "abc123",
  "elType": "section",
  "settings": { "background_background": "classic", "background_color": "#0f172a", "padding": { "unit": "px", "top": "80", "bottom": "80" } },
  "elements": [ { "id": "col001", "elType": "column", "settings": { "_column_size": 100 }, "elements": [ ... ] } ]
}

Elementor widget shape (example):
{
  "id": "wid001",
  "elType": "widget",
  "widgetType": "heading",
  "settings": { "title": "Hello world", "header_size": "h1", "align": "center", "title_color": "#FFFFFF" }
}
PROMPT;

    const OPENCODE = null; // forward declaration for type hint

    private $opencode;

    public function __construct( $opencode = null ) {
        $this->opencode = $opencode ? $opencode : new MCP_OpenCode_Client();
    }

    /**
     * Build a single chat payload and ask the model to emit Elementor JSON.
     *
     * @param array $fetched   Output of MCP_Site_Fetcher::fetch()
     * @param array $analysis  Output of MCP_DOM_Analyzer::analyze()
     * @param array $opts {
     *     @type string $model    Override model id.
     *     @type string $prompt   Extra user note to append (e.g. "make it more
     *                            minimal" or "use Farsi copy").
     * }
     *
     * @return array|\WP_Error  Parsed Elementor array on success.
     */
    public function translate( array $fetched, array $analysis, array $opts = array() ) {
        $user_prompt = $this->build_user_prompt( $fetched, $analysis, $opts );

        $opts_opencode = array();
        if ( ! empty( $opts['model'] ) ) {
            $opts_opencode['model'] = (string) $opts['model'];
        }
        $opts_opencode['temperature'] = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.3;
        $opts_opencode['max_tokens']  = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 8000;

        $resp = $this->opencode->chat( $user_prompt, $opts_opencode );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $data = MCP_OpenCode_Client::extract_elementor_json( $resp['content'] );
        if ( is_wp_error( $data ) ) {
            $data = MCP_Output_Repair::repair_json( $resp['content'] );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'mcp_bad_json', __( 'AI returned unparseable JSON.', 'elementor-mcp' ), array( 'status' => 502 ) );
            }
        }

        $data = MCP_Output_Repair::repair_elementor( $data );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        MCP_Logger::info( 'Site cloned via AI translator', array(
            'url'        => $fetched['final_url'] ?? '',
            'bytes'      => strlen( wp_json_encode( $data ) ),
            'model'      => $resp['model'] ?? '',
        ) );

        return $data;
    }

    /**
     * Compose the user prompt. Order:
     *  1) URL + title
     *  2) Structural hints (analyzer map, compacted)
     *  3) Palette + typography
     *  4) Condensed HTML (scripts removed, attrs trimmed)
     *  5) Optional user note
     */
    private function build_user_prompt( array $fetched, array $analysis, array $opts ) {
        $parts = array();
        $parts[] = sprintf( "TARGET: %s\nTITLE: %s", $fetched['final_url'] ?? '?', $fetched['title'] ?? '(none)' );

        $parts[] = "\nSTRUCTURAL HINTS (use these to drive your layout):";
        $parts[] = wp_json_encode( $this->compact_analysis( $analysis ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        $parts[] = sprintf(
            "\nPALETTE (use these colors): %s",
            implode( ', ', array_slice( (array) ( $analysis['palette'] ?? array() ), 0, 6 ) )
        );
        $parts[] = sprintf(
            "TYPOGRAPHY (families, then sizes): %s | %s",
            implode( ', ', array_slice( (array) ( $analysis['typography']['families'] ?? array() ), 0, 3 ) ),
            implode( ', ', array_slice( (array) ( $analysis['typography']['sizes'] ?? array() ), 0, 5 ) )
        );

        $parts[] = sprintf(
            "\nCONDENSED HTML (head only — scripts and styles stripped, %d KB of %d):",
            (int) ( strlen( $fetched['html'] ?? '' ) / 1024 ),
            (int) ( ( $fetched['bytes'] ?? 0 ) / 1024 )
        );
        $parts[] = $this->condense_html( $fetched['html'] ?? '', 12 * 1024 );

        if ( ! empty( $opts['prompt'] ) ) {
            $parts[] = "\nUSER NOTE: " . (string) $opts['prompt'];
        }

        return implode( "\n", $parts );
    }

    /**
     * Reduce the analyzer map to a token-friendly summary.
     */
    private function compact_analysis( array $analysis ) {
        $out = array( 'sections' => array() );
        foreach ( (array) ( $analysis['sections'] ?? array() ) as $s ) {
            $entry = array(
                'tag'        => $s['tag'] ?? 'div',
                'background' => $s['background'] ?? null,
                'heading'    => $s['heading'] ?? null,
                'columns'    => array(),
            );
            foreach ( (array) ( $s['columns'] ?? array() ) as $c ) {
                $entry['columns'][] = array(
                    'width_pct' => $c['width'] ?? 100,
                    'headings'  => array_slice( (array) ( $c['headings'] ?? array() ), 0, 3 ),
                    'paragraphs'=> array_slice( (array) ( $c['paragraphs'] ?? array() ), 0, 2 ),
                    'buttons'   => array_slice( (array) ( $c['buttons'] ?? array() ), 0, 2 ),
                    'images'    => count( (array) ( $c['images'] ?? array() ) ),
                    'list_items'=> array_slice( (array) ( $c['list_items'] ?? array() ), 0, 4 ),
                );
            }
            $out['sections'][] = $entry;
        }
        $out['stats'] = $analysis['stats'] ?? array();
        return $out;
    }

    /**
     * Strip scripts, inline styles, and noisy attributes from the HTML,
     * then cap the output at $max_bytes.
     */
    private function condense_html( $html, $max_bytes = 12000 ) {
        // Strip <script>...</script> entirely.
        $html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
        // Strip <style>...</style> too — palette/typography already captured those.
        $html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
        // Strip <noscript>, comments.
        $html = preg_replace( '#<noscript\b[^>]*>.*?</noscript>#is', '', $html );
        $html = preg_replace( '/<!--.*?-->/s', '', $html );
        // Strip SVG (token-heavy, no real layout info).
        $html = preg_replace( '#<svg\b[^>]*>.*?</svg>#is', '', $html );
        // Drop data-* attributes.
        $html = preg_replace( '/\s+data-[a-z0-9_-]+\s*=\s*"[^"]*"/i', '', $html );
        // Drop aria-* and role (we're not building accessible output here).
        $html = preg_replace( '/\s+(aria-[a-z-]+|role)\s*=\s*"[^"]*"/i', '', $html );
        // Drop inline event handlers.
        $html = preg_replace( '/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html );
        // Collapse long whitespace runs.
        $html = preg_replace( '/\s+/', ' ', $html );

        if ( strlen( $html ) > $max_bytes ) {
            $html = substr( $html, 0, $max_bytes ) . "\n<!-- truncated -->";
        }
        return $html;
    }
}