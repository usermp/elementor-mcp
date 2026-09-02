<?php
/**
 * AI client for generating Elementor JSON.
 *
 * Talks to any OpenAI-compatible chat completions endpoint
 * (OpenRouter, OpenCode gateway, Ollama, LM Studio, etc.).
 * The client is intentionally free-model friendly: it points at
 * OpenRouter's free tier by default but every URL/model is overridable.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_OpenCode_Client {

    const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';
    const DEFAULT_MODEL    = 'meta-llama/llama-3.3-70b-instruct:free';

    /**
     * Elementor system prompt — anchors the model so it only emits
     * valid Elementor v3 element JSON, wrapped in a fenced code block.
     */
    const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert Elementor page designer. Your job is to translate the user's request into a valid Elementor page JSON document.

Hard requirements:
- Output ONLY a single fenced JSON code block: ```json ... ```
- The JSON MUST be an array of section objects (the root of Elementor's `_elementor_data`).
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
- Keep the response under 60 KB. Prefer simple, clean designs.
- Do NOT include any text outside the JSON code block.

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

    /**
     * Settings cache to keep prompt size manageable.
     * @var array|null
     */
    private $settings;

    public function __construct() {
        $this->settings = MCP_Plugin::get_settings();
    }

    /**
     * Send a chat request and return the assistant message.
     *
     * @param string $user_prompt  Plain-text request, e.g. "a hero section with CTA".
     * @param array  $opts {
     *     @type string $model        Override model id (e.g. "anthropic/claude-3-haiku").
     *     @type string $base_url     Override endpoint base URL.
     *     @type string $api_key      Override API key (else read from settings).
     *     @type array  $history      Previous messages [['role'=>'user','content'=>'...'], ...].
     *     @type float  $temperature  0.0–1.0 (default 0.7).
     *     @type int    $max_tokens   Output cap (default 4000).
     * }
     *
     * @return array|\WP_Error
     *   On success: { 'content' => string, 'model' => string, 'usage' => array }
     *   On failure: WP_Error.
     */
    public function chat( $user_prompt, array $opts = array() ) {
        $user_prompt = trim( (string) $user_prompt );
        if ( '' === $user_prompt ) {
            return new WP_Error( 'mcp_empty_prompt', __( 'Prompt is empty.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $base_url  = rtrim( $opts['base_url']  ?? $this->get_base_url(),  '/' );
        $model     = $opts['model']            ?? $this->get_model();
        $api_key   = $opts['api_key']          ?? $this->get_api_key();
        $temp      = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7;
        $max_tok   = isset( $opts['max_tokens'] )  ? (int)   $opts['max_tokens']  : 4000;
        $history   = isset( $opts['history'] ) && is_array( $opts['history'] ) ? $opts['history'] : array();

        if ( '' === $api_key ) {
            return new WP_Error(
                'mcp_missing_api_key',
                __( 'No AI API key configured. Set one under MCP → Settings → AI Provider.', 'elementor-mcp' ),
                array( 'status' => 500 )
            );
        }

        $messages = array_merge(
            array( array( 'role' => 'system', 'content' => self::SYSTEM_PROMPT ) ),
            $history,
            array( array( 'role' => 'user', 'content' => $user_prompt ) )
        );

        $endpoint = $base_url . '/chat/completions';

        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => max( 0.0, min( 1.5, $temp ) ),
            'max_tokens'  => max( 64, min( 8000, $max_tok ) ),
        );

        $args = array(
            'timeout' => 90,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => home_url(),
                'X-Title'       => 'Elementor MCP',
            ),
            'body'    => wp_json_encode( $body ),
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            MCP_Logger::error( 'AI request failed', array( 'error' => $response->get_error_message() ) );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            MCP_Logger::error( 'AI non-2xx response', array( 'code' => $code, 'body' => substr( $raw, 0, 500 ) ) );
            return new WP_Error(
                'mcp_ai_http_' . $code,
                sprintf( __( 'AI provider returned HTTP %d.', 'elementor-mcp' ), $code ),
                array( 'status' => 502, 'body' => $raw )
            );
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || empty( $decoded['choices'][0]['message']['content'] ) ) {
            return new WP_Error(
                'mcp_ai_malformed',
                __( 'AI provider returned malformed JSON.', 'elementor-mcp' ),
                array( 'status' => 502, 'body' => $raw )
            );
        }

        $content = (string) $decoded['choices'][0]['message']['content'];

        return array(
            'content' => $content,
            'model'   => isset( $decoded['model'] ) ? $decoded['model'] : $model,
            'usage'   => isset( $decoded['usage'] ) ? $decoded['usage'] : array(),
            'raw'     => $decoded,
        );
    }

    /**
     * Pull the JSON element array out of a chat response.
     *
     * Looks for the first ```json ... ``` fenced block; falls back to the
     * outermost [ ... ] in the raw content.
     *
     * @param string $content Raw assistant message.
     * @return array|\WP_Error Parsed array on success, WP_Error on failure.
     */
    public static function extract_elementor_json( $content ) {
        $content = (string) $content;

        // Strategy 1: fenced json block.
        if ( preg_match( '/```json\s*(\[.*?\])\s*```/s', $content, $m ) ) {
            $candidate = $m[1];
        } elseif ( preg_match( '/```\s*(\[.*?\])\s*```/s', $content, $m ) ) {
            $candidate = $m[1];
        } else {
            // Strategy 2: find the outermost array. We can't just take the first
            // '[' and last ']' — bracket nesting matters. Scan from each
            // candidate '[' to its matching ']'.
            $candidate = self::find_outermost_array( $content );
            if ( null === $candidate ) {
                return new WP_Error( 'mcp_no_json_found', __( 'No JSON array found in AI response.', 'elementor-mcp' ) );
            }
        }

        $decoded = json_decode( $candidate, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'mcp_invalid_json', sprintf( __( 'Invalid JSON: %s', 'elementor-mcp' ), json_last_error_msg() ) );
        }

        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'mcp_not_array', __( 'AI output was not a JSON array.', 'elementor-mcp' ) );
        }

        return $decoded;
    }

    /**
     * Convenience: ask for Elementor JSON in one call.
     *
     * @param string $user_prompt
     * @param array  $opts        Same as chat().
     * @return array|\WP_Error     Either the parsed Elementor array, or a WP_Error.
     */
    public function generate_elementor( $user_prompt, array $opts = array() ) {
        $response = $this->chat( $user_prompt, $opts );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = self::extract_elementor_json( $response['content'] );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        MCP_Logger::info( 'AI generated Elementor JSON', array(
            'bytes' => strlen( wp_json_encode( $data ) ),
            'model' => $response['model'],
        ) );

        return $data;
    }

    /* ---------- settings ---------- */

    private function get_api_key() {
        return isset( $this->settings['ai_api_key'] ) ? trim( (string) $this->settings['ai_api_key'] ) : '';
    }

    private function get_base_url() {
        return isset( $this->settings['ai_base_url'] ) && '' !== $this->settings['ai_base_url']
            ? (string) $this->settings['ai_base_url']
            : self::DEFAULT_BASE_URL;
    }

    private function get_model() {
        return isset( $this->settings['ai_model'] ) && '' !== $this->settings['ai_model']
            ? (string) $this->settings['ai_model']
            : self::DEFAULT_MODEL;
    }

    /**
     * Find a well-balanced top-level JSON array in a string.
     *
     * Returns the first balanced array whose contents decode as valid JSON,
     * so we skip over short bracket pairs (e.g. "[text]") that look like
     * arrays but aren't valid Elementor payloads.
     *
     * @param string $content
     * @return string|null Substring from the matching opening '[' to its ']',
     *                     or null if none could be located.
     */
    private static function find_outermost_array( $content ) {
        $len = strlen( $content );
        $depth = 0;
        $in_string = false;
        $escape = false;
        $starts = array();

        for ( $i = 0; $i < $len; $i++ ) {
            $c = $content[ $i ];

            if ( $in_string ) {
                if ( $escape ) {
                    $escape = false;
                } elseif ( '\\' === $c ) {
                    $escape = true;
                } elseif ( '"' === $c ) {
                    $in_string = false;
                }
                continue;
            }

            if ( '"' === $c ) {
                $in_string = true;
                continue;
            }

            if ( '[' === $c ) {
                if ( 0 === $depth ) {
                    $starts[] = $i;
                }
                $depth++;
            } elseif ( ']' === $c ) {
                if ( $depth > 0 ) {
                    $depth--;
                    if ( 0 === $depth && ! empty( $starts ) ) {
                        $start = array_pop( $starts );
                        $candidate = substr( $content, $start, $i - $start + 1 );
                        // Keep scanning for other candidates in case a later one parses.
                        // We stash candidates and return the first one that decodes.
                        if ( null !== json_decode( $candidate, true ) ) {
                            return $candidate;
                        }
                    }
                }
            }
        }
        return null;
    }
}