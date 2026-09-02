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

        <?php submit_button(); ?>
    </form>

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
