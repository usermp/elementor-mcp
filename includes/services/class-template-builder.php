<?php
/**
 * Template Builder — generate a complete Elementor site from a brand brief.
 *
 * Unlike MCP_Site_Cloner (which scrapes a URL), this class takes a pure
 * brand brief (industry, name, audience, palette, style) and asks the AI
 * to compose a full multi-section page with header, hero, content, footer.
 *
 * Sections are generated one at a time to keep each AI response under the
 * token limit and produce reliably valid JSON.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Template_Builder {

    /**
     * A library of design systems the user can pick from. Each one defines
     * palette, fonts, spacing, and tone for the AI to anchor on.
     */
    const DESIGN_SYSTEMS = array(
        'modern_saas' => array(
            'label'   => 'Modern SaaS',
            'palette' => array( '#0F172A', '#1E293B', '#3B82F6', '#10B981', '#F8FAFC', '#E2E8F0' ),
            'fonts'   => array( 'Inter', 'system-ui' ),
            'tone'    => 'clean, technical, confident',
        ),
        'warm_editorial' => array(
            'label'   => 'Warm Editorial',
            'palette' => array( '#1C1917', '#44403C', '#A16207', '#FCD34D', '#FAFAF9', '#E7E5E4' ),
            'fonts'   => array( 'Source Serif Pro', 'Georgia' ),
            'tone'    => 'thoughtful, human, story-driven',
        ),
        'bold_studio' => array(
            'label'   => 'Bold Studio',
            'palette' => array( '#000000', '#18181B', '#F97316', '#FBBF24', '#FFFFFF', '#F4F4F5' ),
            'fonts'   => array( 'Space Grotesk', 'Inter' ),
            'tone'    => 'edgy, confident, big type',
        ),
        'calm_spa' => array(
            'label'   => 'Calm Spa',
            'palette' => array( '#1F2937', '#6B7280', '#A7F3D0', '#D1FAE5', '#FAFAF9', '#F3F4F6' ),
            'fonts'   => array( 'Cormorant Garamond', 'Inter' ),
            'tone'    => 'serene, soft, premium',
        ),
        'restaurant_warm' => array(
            'label'   => 'Restaurant Warm',
            'palette' => array( '#1A0F0A', '#3F2A1A', '#B45309', '#D97706', '#FFFBEB', '#FEF3C7' ),
            'fonts'   => array( 'Playfair Display', 'Inter' ),
            'tone'    => 'warm, inviting, food-forward',
        ),
        'tourism_vivid' => array(
            'label'   => 'Tourism Vivid',
            'palette' => array( '#0C4A6E', '#0EA5E9', '#06B6D4', '#F59E0B', '#FAFAF9', '#E0F2FE' ),
            'fonts'   => array( 'Manrope', 'Inter' ),
            'tone'    => 'energetic, aspirational, travel-magazine',
        ),
        'persian_traditional' => array(
            'label'   => 'Persian Traditional',
            'palette' => array( '#1F2937', '#991B1B', '#B91C1C', '#D97706', '#FAFAF9', '#FEF3C7' ),
            'fonts'   => array( 'Vazirmatn', 'Tahoma' ),
            'tone'    => 'elegant, Farsi-first, cultural motifs',
        ),
    );

    /**
     * Build a complete Elementor page from a brand brief.
     *
     * @param array $brief {
     *     @type string $industry         e.g. "tourism", "saas", "restaurant"
     *     @type string $brand_name       e.g. "نهال گشت"
     *     @type string $tagline          hero headline
     *     @type string $description      1-2 sentence brand description
     *     @type string $language         "fa" | "en"
     *     @type string $design_system    key of self::DESIGN_SYSTEMS, or 'custom'
     *     @type array  $custom_palette   6 hex strings, used if design_system = 'custom'
     *     @type array  $sections         list of section keys to include,
     *                                    default: header, hero, features, about,
     *                                    testimonials, pricing/cta, footer
     *     @type string $extra_note       extra user instruction
     * }
     *
     * @return array|\WP_Error  ['sections' => [...], 'design' => [...], 'stats' => [...]]
     */
    public function build( array $brief ) {
        $design = $this->resolve_design( $brief );
        $sections_to_build = $this->resolve_sections( $brief );

        $translator = new MCP_AI_Translator();
        $built = array();

        foreach ( $sections_to_build as $key ) {
            $section_prompt = $this->build_section_prompt( $key, $brief, $design, $built );
            $resp = $this->call_translator( $translator, $section_prompt, $brief );

            if ( is_wp_error( $resp ) ) {
                MCP_Logger::warning( 'Template section failed', array( 'key' => $key, 'error' => $resp->get_error_message() ) );
                echo "  [SKIP $key] " . $resp->get_error_message() . "\n";
                continue;
            }
            echo "  [OK $key]\n";
            $built[] = $resp;
            // Longer delay to avoid OpenRouter rate limits on free models.
            sleep( 3 );
        }

        if ( empty( $built ) ) {
            return new WP_Error( 'mcp_template_no_sections', __( 'No sections could be generated.', 'elementor-mcp' ) );
        }

        $counts = array( 'sections' => 0, 'columns' => 0, 'widgets' => 0, 'bytes' => 0 );
        $walker = function ( $elements ) use ( &$walker, &$counts ) {
            foreach ( (array) $elements as $el ) {
                if ( ! is_array( $el ) ) continue;
                $t = isset( $el['elType'] ) ? $el['elType'] . 's' : '';
                if ( isset( $counts[ $t ] ) ) $counts[ $t ]++;
                if ( ! empty( $el['elements'] ) ) $walker( $el['elements'] );
            }
        };
        $walker( $built );
        $counts['bytes'] = strlen( wp_json_encode( $built ) );

        return array(
            'sections' => $built,
            'design'   => $design,
            'stats'    => $counts,
            'brief'    => $brief,
        );
    }

    /**
     * Persist a built template to a WordPress page.
     */
    public function create_page( array $build, array $page_args = array() ) {
        $title = ! empty( $page_args['title'] ) ? sanitize_text_field( $page_args['title'] ) : ( $build['brief']['brand_name'] ?? __( 'New page', 'elementor-mcp' ) );
        $status = MCP_Validator::page_status( $page_args['status'] ?? 'draft' );

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_name'    => sanitize_title( $title ),
            'post_status'  => $status,
            'post_type'    => 'page',
            'post_content' => '',
        ), true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $importer = new MCP_Importer();
        $imp = $importer->import( $build['sections'], array( 'post_id' => (int) $post_id ) );
        if ( is_wp_error( $imp ) ) {
            return $imp;
        }

        return array(
            'post_id'  => (int) $post_id,
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
            'view_url' => get_permalink( $post_id ),
            'stats'    => $build['stats'],
        );
    }

    /* ---------- internals ---------- */

    private function resolve_design( array $brief ) {
        $key = $brief['design_system'] ?? 'modern_saas';
        if ( 'custom' === $key && ! empty( $brief['custom_palette'] ) ) {
            return array(
                'label'   => 'Custom',
                'palette' => array_values( array_slice( $brief['custom_palette'], 0, 6 ) ),
                'fonts'   => array( 'Inter', 'system-ui' ),
                'tone'    => $brief['tone'] ?? 'modern, clean',
            );
        }
        return self::DESIGN_SYSTEMS[ $key ] ?? self::DESIGN_SYSTEMS['modern_saas'];
    }

    private function resolve_sections( array $brief ) {
        $default = array( 'header', 'hero', 'features', 'about', 'testimonials', 'cta', 'footer' );
        if ( empty( $brief['sections'] ) ) {
            return $default;
        }
        return array_values( array_intersect( $brief['sections'], $default ) );
    }

    /**
     * Build a focused prompt for one section, with prior sections
     * summarised for visual continuity.
     */
    private function build_section_prompt( $key, array $brief, array $design, array $prior ) {
        $prior_summary = '';
        if ( ! empty( $prior ) ) {
            $titles = array();
            foreach ( $prior as $i => $sec ) {
                $h = $this->first_heading( $sec );
                if ( $h ) $titles[] = $h;
            }
            if ( $titles ) {
                $prior_summary = "\n\nPrevious sections (maintain visual rhythm): " . implode( ' | ', $titles );
            }
        }

        $role_instructions = $this->role_for( $key );
        $language = strtolower( $brief['language'] ?? 'en' );
        $lang_note = ( 'fa' === $language ) ? 'Write ALL text in Persian (Farsi). Use natural Farsi phrasing.' : 'Write all text in English.';

        return sprintf(
            "%s\n\nDESIGN SYSTEM\n- Palette: %s\n- Fonts: %s\n- Tone: %s\n\nBRAND\n- Name: %s\n- Tagline: %s\n- Description: %s\n- Industry: %s\n- Language: %s — %s\n\nSECTION BRIEF: %s\n\nOutput exactly one Elementor section as a single JSON array. The array contains exactly ONE section object (not a page). Use the design system palette and fonts. Make it look professionally designed, not generic. Modern spacing (80-120px vertical padding for hero/cta, 40-60px for content sections).%s",
            MCP_AI_Translator::class,
            implode( ', ', $design['palette'] ),
            implode( ', ', $design['fonts'] ),
            $design['tone'],
            $brief['brand_name'] ?? 'Brand',
            $brief['tagline'] ?? '',
            $brief['description'] ?? '',
            $brief['industry'] ?? 'general',
            strtoupper( $language ),
            $lang_note,
            $role_instructions,
            $prior_summary
        );
    }

    /**
     * Each section role gets explicit, opinionated instructions.
     */
    private function role_for( $key ) {
        $roles = array(
            'header' => <<<'TEXT'
HEADER. Build a single sticky-style header section. One column with a horizontal row:
- A "heading" widget on the left with the brand name as title (header_size=h3, title_color from palette[2] accent)
- A "text-editor" widget in the middle with HTML for nav links: <a href="#" style="margin-right:24px;color:#1F2937;text-decoration:none;font-weight:500">درباره</a> <a href="#" style="margin-right:24px;color:#1F2937;text-decoration:none;font-weight:500">خدمات</a> <a href="#" style="margin-right:24px;color:#1F2937;text-decoration:none;font-weight:500">نمونه‌کار</a> <a href="#" style="color:#1F2937;text-decoration:none;font-weight:500">تماس</a>
- A "button" widget on the right with text="شروع کنید", button_background from palette[2], text_color=#FFFFFF, border_radius=8
Section settings: background_color from palette[4] (lightest), padding top=16 bottom=16
DO NOT use shape dividers.
TEXT,
            'hero' => <<<'TEXT'
HERO. Single column, full width, dark background (use palette[0] darkest). section settings: background_color from palette[0], padding top=160 bottom=160.
- A "heading" widget: title=brand tagline (large h1, h1 size, font_size=72), title_color=#FFFFFF, align=center, text_shadow blur=10 color=rgba(0,0,0,0.3)
- A "text-editor" widget: HTML for a subheading: <p style="text-align:center;color:#E5E7EB;font-size:20px;line-height:1.6;max-width:680px;margin:24px auto 0">short subtitle text in the brand language</p>
- A "button" widget: text="شروع کنید" OR "رزرو کنید" (matching industry), size=lg, button_background from palette[2] (accent), text_color=#FFFFFF, border_radius=10, padding top=18 bottom=18 left=36 right=36
DO NOT use shape dividers.
TEXT,
            'features' => <<<'TEXT'
FEATURES. Section: background_color from palette[4] (lightest), padding top=100 bottom=100.
- A "heading" widget h2 centered: title=feature section heading (e.g. "چرا ما؟"), title_color from palette[0], align=center, font_size=44
- A "text-editor" widget centered: <p style="text-align:center;color:#6B7280;font-size:18px;max-width:600px;margin:12px auto 60px">short tagline</p>
- 3 nested inner sections side by side, each _column_size=33, padding top=20 bottom=20 left=20 right=20. Each contains one "icon-box" widget with:
  * view="default"
  * title in brand language (e.g. "تجربه", "کیفیت", "پشتیبانی")
  * title_color from palette[0]
  * description_color=#6B7280
  * icon_color from palette[2] (accent)
TEXT,
            'about' => <<<'TEXT'
ABOUT. Light background. One column with:
- Heading widget h2 left-aligned "About us"
- Text-editor widget: 2 paragraphs of brand description (the description provided)
- Stats row: 3 inner sections (3 columns) each with a counter widget (large number) and a heading widget (small label: "Years", "Projects", "Clients" — pick whatever fits the industry)
TEXT,
            'testimonials' => <<<'TEXT'
TESTIMONIALS. White background. One column with:
- Heading widget h2 centered "What clients say"
- 3 inner sections (3 columns) each with a testimonial widget (content 1-2 lines, name "First Last", title "Role / Company")
Use realistic names that fit the brand culture.
TEXT,
            'cta' => <<<'TEXT'
CTA. Section: background_color from palette[2] (accent color), padding top=100 bottom=100.
- A "heading" widget h2 centered: title=action-oriented call to action (e.g. "آماده شروع هستید؟"), title_color=#FFFFFF, align=center, font_size=44
- A "text-editor" widget: <p style="text-align:center;color:rgba(255,255,255,0.9);font-size:18px;max-width:580px;margin:16px auto 36px">supporting line</p>
- A "button" widget: text="تماس با ما" OR "شروع کنید", size=lg, button_background=#FFFFFF, text_color from palette[2] (accent), border_radius=10
TEXT,
            'footer' => <<<'TEXT'
FOOTER. Section: background_color from palette[0] (darkest), padding top=80 bottom=40.
- One main column with these stacked widgets:
  - 3 nested inner sections side by side, each _column_size=33, padding top=20 bottom=20. Each contains:
    1. First column: A "heading" widget h4 with brand name as title, title_color=#FFFFFF. Then a "text-editor" widget: <p style="color:rgba(255,255,255,0.7);font-size:14px;line-height:1.7">short brand description</p>
    2. Second column: A "heading" widget h5: title="لینک‌های سریع", title_color=#FFFFFF. Then a "text-editor" widget: <p style="line-height:2"><a href="#" style="color:rgba(255,255,255,0.7);text-decoration:none">درباره</a><br><a href="#" style="color:rgba(255,255,255,0.7);text-decoration:none">خدمات</a><br><a href="#" style="color:rgba(255,255,255,0.7);text-decoration:none">تماس</a></p>
    3. Third column: A "heading" widget h5: title="تماس", title_color=#FFFFFF. Then a "text-editor" widget: <p style="color:rgba(255,255,255,0.7);font-size:14px;line-height:1.8">info@brand.ir<br>+98 21 1234 5678<br>تهران، خیابان آزادی</p>
  - A "divider" widget with color=rgba(255,255,255,0.2), weight=1, gap top=40 bottom=24
  - A "text-editor" widget: <p style="text-align:center;color:rgba(255,255,255,0.5);font-size:13px">© 2025 [brand name]. تمامی حقوق محفوظ است.</p>
TEXT,
        );
        return $roles[ $key ] ?? $roles['hero'];
    }

    private function first_heading( $section ) {
        if ( isset( $section['elements'][0]['elements'] ) ) {
            foreach ( $section['elements'][0]['elements'] as $w ) {
                if ( isset( $w['widgetType'] ) && 'heading' === $w['widgetType'] ) {
                    return $w['settings']['title'] ?? null;
                }
            }
        }
        return null;
    }

    private function call_translator( $translator, $prompt, $brief ) {
        // Build our own OpenCode_Client so we can pass a custom system prompt
        // for each section role.
        $opencode = new MCP_OpenCode_Client();
        $opts = array(
            'temperature' => 0.4,
            'max_tokens'  => 6000,
        );
        if ( ! empty( $brief['model'] ) ) {
            $opts['model'] = $brief['model'];
        }

        $resp = $opencode->chat( $prompt, $opts );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $data = MCP_OpenCode_Client::extract_elementor_json( $resp['content'] );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( ! is_array( $data ) || empty( $data[0]['elType'] ) || 'section' !== $data[0]['elType'] ) {
            return new WP_Error( 'mcp_bad_section', __( 'AI did not return a single section.', 'elementor-mcp' ) );
        }
        return $data[0];
    }

    private function fallback_call( $prompt, $brief ) {
        return new WP_Error( 'mcp_no_client', __( 'OpenCode client not attached to translator.', 'elementor-mcp' ) );
    }
}