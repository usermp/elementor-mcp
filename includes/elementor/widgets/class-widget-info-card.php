<?php
/**
 * Sample Elementor widget: Info Card.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Widget_Info_Card extends MCP_Widget_Base {

    protected function widget_id()    { return 'info-card'; }
    protected function widget_title() { return __( 'MCP Info Card', 'elementor-mcp' ); }
    protected function widget_icon()  { return 'eicon-info-box'; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', array(
            'label' => __( 'Content', 'elementor-mcp' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ) );

        $this->add_control( 'title', array(
            'label'   => __( 'Title', 'elementor-mcp' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => __( 'Info Card Title', 'elementor-mcp' ),
        ) );

        $this->add_control( 'description', array(
            'label'   => __( 'Description', 'elementor-mcp' ),
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'default' => __( 'Description text here.', 'elementor-mcp' ),
        ) );

        $this->add_control( 'badge_text', array(
            'label'   => __( 'Badge Text', 'elementor-mcp' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'NEW',
        ) );

        $this->end_controls_section();

        $this->start_controls_section( 'style_section', array(
            'label' => __( 'Style', 'elementor-mcp' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ) );

        $this->add_control( 'badge_color', array(
            'label'   => __( 'Badge Color', 'elementor-mcp' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#0073aa',
        ) );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $title    = isset( $settings['title'] ) ? esc_html( $settings['title'] ) : '';
        $desc     = isset( $settings['description'] ) ? esc_html( $settings['description'] ) : '';
        $badge    = isset( $settings['badge_text'] ) ? esc_html( $settings['badge_text'] ) : '';
        $color    = isset( $settings['badge_color'] ) ? esc_attr( $settings['badge_color'] ) : '#0073aa';
        ?>
        <div class="mcp-info-card" style="border:1px solid #ddd;padding:20px;border-radius:6px;position:relative;">
            <?php if ( $badge ) : ?>
                <span style="position:absolute;top:-10px;right:10px;background:<?php echo $color; ?>;color:#fff;padding:4px 10px;border-radius:12px;font-size:12px;"><?php echo $badge; ?></span>
            <?php endif; ?>
            <h3 style="margin-top:0;"><?php echo $title; ?></h3>
            <p><?php echo $desc; ?></p>
        </div>
        <?php
    }
}
