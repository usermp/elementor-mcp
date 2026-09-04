<?php
/**
 * Settings page view.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = MCP_Plugin::get_settings();
?>
<div class="wrap mcp-settings">
    <h1><?php esc_html_e( 'Elementor MCP Settings', 'elementor-mcp' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'mcp_settings_group' ); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'REST API Status', 'elementor-mcp' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[api_enabled]" value="1" <?php checked( ! empty( $settings['api_enabled'] ) ); ?>>
                        <?php esc_html_e( 'Enable REST API endpoints', 'elementor-mcp' ); ?>
                    </label>
                    <p class="description">
                        <?php
                        printf(
                            wp_kses(
                                __( 'Endpoint base: <code>%s</code>', 'elementor-mcp' ),
                                array( 'code' => array() )
                            ),
                            esc_html( $rest_url )
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Rate Limit', 'elementor-mcp' ); ?></th>
                <td>
                    <input type="number" min="0" max="10000" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[rate_limit]" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>">
                    <p class="description"><?php esc_html_e( 'Maximum requests per minute per user. 0 = unlimited.', 'elementor-mcp' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Webhook Secret', 'elementor-mcp' ); ?></th>
                <td>
                    <input type="text" class="regular-text" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>">
                    <p class="description"><?php esc_html_e( 'Secret key used to verify incoming webhooks from OpenCode.', 'elementor-mcp' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Logging', 'elementor-mcp' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[enable_logging]" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?>>
                        <?php esc_html_e( 'Enable event logging', 'elementor-mcp' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'GraphQL', 'elementor-mcp' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[enable_graphql]" value="1" <?php checked( ! empty( $settings['enable_graphql'] ) ); ?>>
                        <?php esc_html_e( 'Enable GraphQL support (requires WPGraphQL)', 'elementor-mcp' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'AI Provider', 'elementor-mcp' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Any OpenAI-compatible endpoint works (OpenRouter, OpenAI, Ollama, LM Studio, …). The chat panel uses these settings for all AI requests.', 'elementor-mcp' ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'API Base URL', 'elementor-mcp' ); ?></th>
                <td>
                    <input type="url" class="regular-text" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[ai_base_url]" value="<?php echo esc_attr( $settings['ai_base_url'] ); ?>" placeholder="https://openrouter.ai/api/v1">
                    <p class="description"><?php esc_html_e( 'Base URL for the chat completions endpoint (no trailing slash, no /chat/completions).', 'elementor-mcp' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Default Model', 'elementor-mcp' ); ?></th>
                <td>
                    <input type="text" class="regular-text" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[ai_model]" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" placeholder="nvidia/nemotron-3-super-120b-a12b:free">
                    <p class="description"><?php esc_html_e( 'Default model id passed in the API request. Override per-request from the chat panel.', 'elementor-mcp' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'API Key', 'elementor-mcp' ); ?></th>
                <td>
                    <input type="password" class="regular-text" id="mcp-ai-api-key" name="<?php echo esc_attr( MCP_Settings::OPTION_KEY ); ?>[ai_api_key]" value="" autocomplete="new-password" placeholder="<?php echo empty( $settings['ai_api_key'] ) ? esc_attr__( 'Not set', 'elementor-mcp' ) : esc_attr__( '•••••••• — saved (enter a new key to replace)', 'elementor-mcp' ); ?>">
                    <button type="button" class="button" id="mcp-ai-api-key-toggle"><?php esc_html_e( 'Show', 'elementor-mcp' ); ?></button>
                    <button type="button" class="button" id="mcp-ai-api-key-generate"><?php esc_html_e( 'Regenerate placeholder', 'elementor-mcp' ); ?></button>
                    <p class="description">
                        <?php
                        $key_status = empty( $settings['ai_api_key'] )
                            ? '<strong style="color:#d63638;">' . esc_html__( 'Missing', 'elementor-mcp' ) . '</strong>'
                            : '<strong style="color:#008a20;">' . esc_html__( 'Configured', 'elementor-mcp' ) . '</strong>';
                        printf(
                            /* translators: %s status HTML */
                            wp_kses( __( 'Status: %s. The key is stored only as long as you keep it; leaving this blank on save preserves whatever is currently saved.', 'elementor-mcp' ), array( 'strong' => array() ) ),
                            $key_status
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <script>
    (function () {
        const inp = document.getElementById('mcp-ai-api-key');
        const btn = document.getElementById('mcp-ai-api-key-toggle');
        const gen = document.getElementById('mcp-ai-api-key-generate');
        if (btn && inp) {
            btn.addEventListener('click', function () {
                if (inp.type === 'password') { inp.type = 'text'; btn.textContent = '<?php echo esc_js( __( 'Hide', 'elementor-mcp' ) ); ?>'; }
                else { inp.type = 'password'; btn.textContent = '<?php echo esc_js( __( 'Show', 'elementor-mcp' ) ); ?>'; }
            });
        }
        if (gen && inp) {
            gen.addEventListener('click', function () {
                if (!confirm('<?php echo esc_js( __( 'Replace the current secret with a new random value?', 'elementor-mcp' ) ); ?>')) return;
                const buf = new Uint8Array(24); crypto.getRandomValues(buf);
                inp.value = ''; inp.placeholder = 'Will be replaced with random bytes (placeholder only)';
            });
        }
    })();
    </script>

    <hr>

    <h2><?php esc_html_e( 'Authentication', 'elementor-mcp' ); ?></h2>
    <p>
        <?php
        printf(
            wp_kses(
                __( 'Use WordPress <strong>Application Passwords</strong> to authenticate API requests. Generate one for your user at <a href="%s">Users &rsaquo; Profile</a>.', 'elementor-mcp' ),
                array( 'strong' => array(), 'a' => array( 'href' => array() ) )
            ),
            esc_url( admin_url( 'profile.php' ) )
        );
        ?>
    </p>
    <pre class="mcp-code">curl -u "username:application_password" <?php echo esc_html( $rest_url ); ?></pre>

    <hr>

    <h2><?php esc_html_e( 'Recent Logs', 'elementor-mcp' ); ?></h2>
    <?php if ( empty( $logs ) ) : ?>
        <p><?php esc_html_e( 'No log entries yet.', 'elementor-mcp' ); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Time', 'elementor-mcp' ); ?></th>
                    <th><?php esc_html_e( 'Level', 'elementor-mcp' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'elementor-mcp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $entry['time'] ); ?></td>
                        <td><?php echo esc_html( $entry['level'] ); ?></td>
                        <td><?php echo esc_html( $entry['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
            <input type="hidden" name="action" value="mcp_clear_logs">
            <?php wp_nonce_field( 'mcp_clear_logs' ); ?>
            <?php submit_button( __( 'Clear Logs', 'elementor-mcp' ), 'delete', 'submit', false ); ?>
        </form>
    <?php endif; ?>
</div>
