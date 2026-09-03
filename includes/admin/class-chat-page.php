<?php
/**
 * In-admin chat panel for designing Elementor pages with AI.
 *
 * Renders a chat-style UI under MCP → Chat. The browser posts prompts to
 * /wp-json/mcp/v1/chat, which proxies through MCP_OpenCode_Client and
 * returns parsed Elementor JSON plus a diff against the currently selected
 * page (when one is chosen).
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Chat_Page {

    const MENU_SLUG  = 'elementor-mcp-chat';
    const NONCE_ACT  = 'mcp_chat';
    const OPT_HISTORY = 'mcp_chat_history';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function add_menu() {
        add_submenu_page(
                MCP_Settings::MENU_SLUG,
                __( 'MCP Chat', 'elementor-mcp' ),
                __( 'Chat', 'elementor-mcp' ),
                'edit_pages',
                self::MENU_SLUG,
                array( __CLASS__, 'render' )
            );
    }

    public static function enqueue( $hook ) {
        if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
            return;
        }
        wp_enqueue_style(
            'mcp-chat',
            MCP_URL . 'assets/css/chat.css',
            array(),
            MCP_VERSION
        );
        wp_enqueue_script(
            'mcp-chat',
            MCP_URL . 'assets/js/chat.js',
            array( 'wp-api-fetch' ),
            MCP_VERSION,
            true
        );

        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        $page_list = array();
        foreach ( (array) $pages as $p ) {
            $page_list[] = array(
                'id'     => (int) $p->ID,
                'title'  => get_the_title( $p ),
                'status' => $p->post_status,
            );
        }

        wp_localize_script( 'mcp-chat', 'mcpChat', array(
            'restUrl'   => esc_url_raw( rest_url( MCP_REST_NAMESPACE . '/chat' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'pages'     => $page_list,
            'i18n'      => array(
                'placeholder' => __( 'Describe the page you want — e.g. "a hero with dark background, big heading, and a CTA button"', 'elementor-mcp' ),
                'send'        => __( 'Send', 'elementor-mcp' ),
                'sending'     => __( 'Thinking…', 'elementor-mcp' ),
                'apply'       => __( 'Apply to page', 'elementor-mcp' ),
                'preview'     => __( 'Preview diff', 'elementor-mcp' ),
                'copied'      => __( 'Copied', 'elementor-mcp' ),
                'noKey'       => __( 'No AI API key configured. Open MCP → Settings → AI Provider.', 'elementor-mcp' ),
                'error'       => __( 'Error', 'elementor-mcp' ),
                'pageLabel'   => __( 'Target page (optional)', 'elementor-mcp' ),
            ),
            'hasKey'    => '' !== trim( (string) MCP_Plugin::get_settings()['ai_api_key'] ),
        ) );
    }

    public static function render() {
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_die( esc_html__( 'You do not have permission to use MCP Chat.', 'elementor-mcp' ) );
        }

        $settings = MCP_Plugin::get_settings();
        $history  = get_user_meta( get_current_user_id(), self::OPT_HISTORY, true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        ?>
        <div class="wrap mcp-chat-wrap">
            <h1 class="mcp-chat-title"><?php esc_html_e( 'MCP Chat — design with AI', 'elementor-mcp' ); ?></h1>
            <p class="description">
                <?php
                printf(
                    /* translators: %s model name */
                    esc_html__( 'Model: %s · Base URL: %s', 'elementor-mcp' ),
                    '<code>' . esc_html( $settings['ai_model'] ) . '</code>',
                    '<code>' . esc_html( $settings['ai_base_url'] ) . '</code>'
                );
                ?>
            </p>

            <div class="mcp-chat-shell">
                <aside class="mcp-chat-side">
                    <label for="mcp-page-select" class="mcp-chat-label">
                        <?php esc_html_e( 'Target page (optional)', 'elementor-mcp' ); ?>
                    </label>
                    <select id="mcp-page-select" class="mcp-chat-select">
                        <option value=""><?php esc_html_e( '— generate a new page from scratch —', 'elementor-mcp' ); ?></option>
                    </select>

                    <button type="button" class="button button-secondary mcp-chat-clear">
                        <?php esc_html_e( 'Clear conversation', 'elementor-mcp' ); ?>
                    </button>

                    <details class="mcp-chat-tips">
                        <summary><?php esc_html_e( 'Prompting tips', 'elementor-mcp' ); ?></summary>
                        <ul>
                            <li><?php esc_html_e( 'Be specific about sections, colors, and CTAs.', 'elementor-mcp' ); ?></li>
                            <li><?php esc_html_e( 'Mention widget types you want: heading, button, image, text-editor, icon-box.', 'elementor-mcp' ); ?></li>
                            <li><?php esc_html_e( 'Ask follow-ups to refine: "make the heading larger and the background darker".', 'elementor-mcp' ); ?></li>
                            <li><?php esc_html_e( 'After you apply, open the page in Elementor to fine-tune.', 'elementor-mcp' ); ?></li>
                        </ul>
                    </details>
                </aside>

                <main class="mcp-chat-main">
                    <div id="mcp-chat-log" class="mcp-chat-log" aria-live="polite"></div>

                    <form id="mcp-chat-form" class="mcp-chat-form">
                        <textarea
                            id="mcp-chat-input"
                            class="mcp-chat-input"
                            rows="3"
                            placeholder=""
                            required></textarea>
                        <div class="mcp-chat-toolbar">
                            <span id="mcp-chat-status" class="mcp-chat-status"></span>
                            <button type="submit" class="button button-primary" id="mcp-chat-send">
                                <?php esc_html_e( 'Send', 'elementor-mcp' ); ?>
                            </button>
                        </div>
                        <div class="mcp-chat-toolbar-extra">
                            <button type="button" class="button" id="mcp-chat-clone-site">
                                🌐 <?php esc_html_e( 'Clone a site', 'elementor-mcp' ); ?>
                            </button>
                            <button type="button" class="button" id="mcp-chat-template">
                                ✨ <?php esc_html_e( 'Template Builder', 'elementor-mcp' ); ?>
                            </button>
                            <button type="button" class="button" id="mcp-chat-prompts">
                                📚 <?php esc_html_e( 'Prompts', 'elementor-mcp' ); ?>
                            </button>
                            <button type="button" class="button" id="mcp-chat-history">
                                🕐 <?php esc_html_e( 'History', 'elementor-mcp' ); ?>
                            </button>
                        </div>
                    </form>
                </main>

                <aside class="mcp-chat-result">
                    <header>
                        <h2><?php esc_html_e( 'Last result', 'elementor-mcp' ); ?></h2>
                        <span id="mcp-chat-result-stats" class="mcp-chat-stat"></span>
                    </header>
                    <div id="mcp-chat-json" class="mcp-chat-json" tabindex="0"></div>
                    <div id="mcp-chat-diff" class="mcp-chat-diff"></div>
                    <div class="mcp-chat-actions">
                        <button type="button" class="button" id="mcp-chat-copy"><?php esc_html_e( 'Copy JSON', 'elementor-mcp' ); ?></button>
                        <button type="button" class="button" id="mcp-chat-apply"><?php esc_html_e( 'Apply to page', 'elementor-mcp' ); ?></button>
                    </div>
                </aside>
            </div>
        </div>

        <?php // Clone site modal ?>
        <div id="mcp-clone-modal" class="mcp-modal" role="dialog" aria-modal="true">
            <div class="mcp-modal-card">
                <h2>🌐 <?php esc_html_e( 'Clone a public site', 'elementor-mcp' ); ?>
                    <button type="button" class="mcp-modal-close" aria-label="Close">&times;</button>
                </h2>
                <p><?php esc_html_e( "Enter a URL. We'll crawl it, fetch each page, and generate Elementor pages in your drafts.", 'elementor-mcp' ); ?></p>
                <label for="mcp-clone-url"><?php esc_html_e( 'Site URL', 'elementor-mcp' ); ?></label>
                <input type="url" id="mcp-clone-url" placeholder="https://example.com" />
                <label for="mcp-clone-pages"><?php esc_html_e( 'Max pages to clone (1-6)', 'elementor-mcp' ); ?></label>
                <input type="number" id="mcp-clone-pages" min="1" max="6" value="4" />
                <p>
                    <button type="button" class="button button-primary" id="mcp-clone-run">
                        <?php esc_html_e( 'Start cloning', 'elementor-mcp' ); ?>
                    </button>
                </p>
                <div id="mcp-clone-status" class="mcp-modal-status"></div>
                <div id="mcp-clone-result" class="mcp-modal-result"></div>
            </div>
        </div>

        <?php // Template builder modal ?>
        <div id="mcp-template-modal" class="mcp-modal" role="dialog" aria-modal="true">
            <div class="mcp-modal-card">
                <h2>✨ <?php esc_html_e( 'Template Builder', 'elementor-mcp' ); ?>
                    <button type="button" class="mcp-modal-close" aria-label="Close">&times;</button>
                </h2>
                <p><?php esc_html_e( 'Generate a complete Elementor site from a brand brief. No URL needed.', 'elementor-mcp' ); ?></p>
                <form>
                    <label for="mcp-tpl-industry"><?php esc_html_e( 'Industry', 'elementor-mcp' ); ?></label>
                    <input type="text" name="industry" id="mcp-tpl-industry" placeholder="e.g. SaaS, restaurant, tourism" />

                    <label for="mcp-tpl-brand"><?php esc_html_e( 'Brand name', 'elementor-mcp' ); ?></label>
                    <input type="text" name="brand_name" id="mcp-tpl-brand" placeholder="Acme Studio" />

                    <label for="mcp-tpl-tagline"><?php esc_html_e( 'Tagline', 'elementor-mcp' ); ?></label>
                    <input type="text" name="tagline" id="mcp-tpl-tagline" placeholder="One-line value proposition" />

                    <label for="mcp-tpl-desc"><?php esc_html_e( 'Description (1-2 sentences)', 'elementor-mcp' ); ?></label>
                    <textarea name="description" id="mcp-tpl-desc" placeholder="What you do, who for, why different."></textarea>

                    <label for="mcp-tpl-lang"><?php esc_html_e( 'Language', 'elementor-mcp' ); ?></label>
                    <select name="language" id="mcp-tpl-lang">
                        <option value="en">English</option>
                        <option value="fa">Farsi (فارسی)</option>
                    </select>

                    <label for="mcp-tpl-design"><?php esc_html_e( 'Design system', 'elementor-mcp' ); ?></label>
                    <select name="design_system" id="mcp-tpl-design">
                        <option value="modern_saas">Modern SaaS</option>
                        <option value="warm_editorial">Warm Editorial</option>
                        <option value="bold_studio">Bold Studio</option>
                        <option value="calm_spa">Calm Spa</option>
                        <option value="restaurant_warm">Restaurant Warm</option>
                        <option value="tourism_vivid">Tourism Vivid</option>
                        <option value="persian_traditional">Persian Traditional</option>
                    </select>

                    <p>
                        <button type="button" class="button button-primary" id="mcp-template-run">
                            <?php esc_html_e( 'Build site', 'elementor-mcp' ); ?>
                        </button>
                    </p>
                    <div id="mcp-template-status" class="mcp-modal-status"></div>
                    <div id="mcp-template-result" class="mcp-modal-result"></div>
                </form>
            </div>
        </div>

        <?php // History side panel ?>
        <aside id="mcp-history-panel" class="mcp-side-panel" aria-label="Snapshots">
            <header>
                <h2>🕐 <?php esc_html_e( 'Snapshots', 'elementor-mcp' ); ?></h2>
                <button type="button" class="mcp-modal-close" aria-label="Close">&times;</button>
            </header>
            <div class="mcp-side-panel-body">
                <p><?php esc_html_e( 'Snapshots of the currently selected target page.', 'elementor-mcp' ); ?></p>
                <ul id="mcp-history-list"></ul>
            </div>
        </aside>

        <?php // Prompts side panel ?>
        <aside id="mcp-prompts-panel" class="mcp-side-panel" aria-label="Prompt library">
            <header>
                <h2>📚 <?php esc_html_e( 'Prompt library', 'elementor-mcp' ); ?></h2>
                <button type="button" class="mcp-modal-close" aria-label="Close">&times;</button>
            </header>
            <div class="mcp-side-panel-body">
                <p><?php esc_html_e( 'Click a template to load it into Template Builder.', 'elementor-mcp' ); ?></p>
                <div id="mcp-prompts-grid"></div>
            </div>
        </aside>
        <?php
    }
}