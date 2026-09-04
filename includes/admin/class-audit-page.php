<?php
/**
 * Admin page: Performance + Security audit dashboard.
 *
 * Shows two cards (perf + sec) with score, grade, and findings table.
 * Each finding has a "fix" line that the admin can copy to their runbook.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Audit_Page {

    const MENU_SLUG = 'elementor-mcp-audit';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
    }

    public static function add_menu() {
        add_submenu_page(
            MCP_Settings::MENU_SLUG,
            __( 'Audit', 'elementor-mcp' ),
            __( 'Audit', 'elementor-mcp' ),
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'elementor-mcp' ) );
        }

        // Force refresh if requested.
        if ( isset( $_GET['refresh'] ) && check_admin_referer( 'mcp_audit_refresh' ) ) {
            ( new MCP_Performance_Analyzer() )->clear_cache();
            ( new MCP_Security_Scanner() )->clear_cache();
        }

        $perf = ( new MCP_Performance_Analyzer() )->last_run();
        $sec  = ( new MCP_Security_Scanner() )->last_run();

        $refresh_url = wp_nonce_url( add_query_arg( 'refresh', '1' ), 'mcp_audit_refresh' );
        ?>
        <div class="wrap mcp-audit-wrap">
            <h1><?php esc_html_e( 'MCP Audit', 'elementor-mcp' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Read-only checks against your WordPress install. Nothing is modified.', 'elementor-mcp' ); ?>
                <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" style="margin-left:8px;">
                    <?php esc_html_e( 'Refresh now', 'elementor-mcp' ); ?>
                </a>
            </p>

            <div class="mcp-audit-grid">
                <?php self::render_card( __( 'Performance', 'elementor-mcp' ), $perf ); ?>
                <?php self::render_card( __( 'Security', 'elementor-mcp' ), $sec ); ?>
            </div>
        </div>

        <style>
            .mcp-audit-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                max-width: 1400px;
            }
            @media (max-width: 1100px) {
                .mcp-audit-grid { grid-template-columns: 1fr; }
            }
            .mcp-audit-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 16px 20px;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .mcp-audit-card h2 {
                margin: 0 0 8px;
                font-size: 16px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .mcp-audit-score {
                font-size: 36px;
                font-weight: 700;
                line-height: 1;
            }
            .mcp-audit-meta {
                color: #50575e;
                font-size: 12px;
                margin-bottom: 12px;
            }
            .mcp-audit-grade {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 12px;
                font-weight: 700;
                font-size: 13px;
            }
            .grade-A { background: #d4edda; color: #155724; }
            .grade-B { background: #d1ecf1; color: #0c5460; }
            .grade-C { background: #fff3cd; color: #856404; }
            .grade-D { background: #f8d7da; color: #721c24; }
            .grade-F { background: #f5c6cb; color: #491217; }
            .mcp-audit-finding {
                padding: 10px 0;
                border-top: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .mcp-audit-finding:first-of-type { border-top: 0; }
            .mcp-audit-sev {
                display: inline-block;
                padding: 1px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                margin-right: 6px;
                text-transform: uppercase;
            }
            .sev-critical { background: #f8d7da; color: #721c24; }
            .sev-warning  { background: #fff3cd; color: #856404; }
            .sev-info     { background: #d1ecf1; color: #0c5460; }
            .mcp-audit-title { font-weight: 600; }
            .mcp-audit-detail { color: #50575e; margin-top: 2px; }
            .mcp-audit-fix {
                background: #f6f7f7;
                border-left: 3px solid #2271b1;
                padding: 6px 10px;
                margin-top: 4px;
                font-family: ui-monospace, monospace;
                font-size: 12px;
                white-space: pre-wrap;
            }
            .mcp-audit-empty {
                color: #50575e;
                font-style: italic;
            }
        </style>
        <?php
    }

    private static function render_card( $title, $report ) {
        $grade_class = 'grade-' . ( $report['grade'] ?? 'F' );
        $count = (int) ( $report['count'] ?? 0 );
        ?>
        <div class="mcp-audit-card">
            <h2>
                <?php echo esc_html( $title ); ?>
                <span class="mcp-audit-grade <?php echo esc_attr( $grade_class ); ?>">
                    <?php echo esc_html( $report['grade'] ?? 'F' ); ?>
                </span>
            </h2>
            <div>
                <span class="mcp-audit-score"><?php echo (int) ( $report['score'] ?? 0 ); ?></span>
                <span style="color:#50575e">/100</span>
            </div>
            <p class="mcp-audit-meta">
                <?php
                printf(
                    /* translators: 1: number of findings, 2: when the audit ran */
                    esc_html( _n( '%d finding', '%d findings', $count, 'elementor-mcp' ) ),
                    $count
                );
                ?>
                &middot; <?php echo esc_html( sprintf( __( 'ran %s', 'elementor-mcp' ), $report['ran_at'] ?? '' ) ); ?>
            </p>
            <?php if ( empty( $report['findings'] ) ) : ?>
                <p class="mcp-audit-empty"><?php esc_html_e( 'No issues. Nice work!', 'elementor-mcp' ); ?></p>
            <?php else : ?>
                <?php foreach ( (array) $report['findings'] as $f ) : ?>
                    <div class="mcp-audit-finding">
                        <span class="mcp-audit-sev sev-<?php echo esc_attr( $f['severity'] ); ?>"><?php echo esc_html( $f['severity'] ); ?></span>
                        <span class="mcp-audit-title"><?php echo esc_html( $f['title'] ); ?></span>
                        <div class="mcp-audit-detail"><?php echo esc_html( $f['detail'] ); ?></div>
                        <?php if ( ! empty( $f['fix'] ) ) : ?>
                            <div class="mcp-audit-fix"><?php echo esc_html( $f['fix'] ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}