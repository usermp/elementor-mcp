<?php
/**
 * Block Repair — scan and fix common Elementor data issues.
 *
 * Three repair modes:
 *  - "scan"     report only
 *  - "audit"    report + suggest
 *  - "auto_fix" apply the safe fixes (missing widgetType, orphan columns,
 *                 empty sections) and leave the rest as audit items
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Block_Repair {

    const ALLOWED_WIDGETS = array(
        'heading', 'image', 'text-editor', 'button', 'divider', 'spacer',
        'icon', 'icon-box', 'image-box', 'star-rating', 'basic-gallery',
        'counter', 'progress', 'alert', 'testimonial', 'social-icons',
        'tabs', 'accordion', 'toggle', 'video', 'shortcode', 'html',
        'global', 'form', 'login',
    );

    /**
     * @param int    $post_id
     * @param string $mode  'scan' | 'audit' | 'auto_fix'
     * @return array|\WP_Error
     */
    public function run( $post_id, $mode = 'scan' ) {
        $post = get_post( $post_id );
        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'mcp_repair_no_post', __( 'Page not found.', 'elementor-mcp' ), array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'mcp_repair_forbidden', __( 'Cannot edit this page.', 'elementor-mcp' ), array( 'status' => 403 ) );
        }

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'mcp_repair_bad_data', __( 'Elementor data is corrupt JSON.', 'elementor-mcp' ), array( 'status' => 500 ) );
        }

        $issues = array();
        $fixed   = 0;
        foreach ( $data as $section_idx => $section ) {
            $section_issues = $this->audit_section( $section );
            if ( ! empty( $section_issues ) ) {
                $issues = array_merge( $issues, array_map( function ( $i ) use ( $section_idx ) {
                    return array_merge( $i, array( 'section_index' => $section_idx ) );
                }, $section_issues ) );
            }

            if ( 'auto_fix' === $mode ) {
                $data[ $section_idx ] = $this->auto_fix_section( $section, $fixed );
            }
        }

        if ( 'auto_fix' === $mode && $fixed > 0 ) {
            update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
            wp_update_post( array(
                'ID' => $post_id,
                'post_modified' => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', 1 ),
            ) );
        }

        return array(
            'mode'      => $mode,
            'post_id'   => $post_id,
            'issues'    => $issues,
            'fixed'     => $fixed,
            'issue_count' => count( $issues ),
            'section_count' => count( $data ),
        );
    }

    private function audit_section( $section ) {
        $issues = array();
        if ( ! is_array( $section ) ) {
            $issues[] = array( 'type' => 'not_array', 'message' => 'Section is not an array' );
            return $issues;
        }
        if ( ( $section['elType'] ?? '' ) !== 'section' ) {
            $issues[] = array( 'type' => 'bad_elType', 'message' => 'Section elType is not "section"' );
        }
        if ( empty( $section['id'] ) ) {
            $issues[] = array( 'type' => 'missing_id', 'message' => 'Section has no id' );
        }
        if ( empty( $section['elements'] ) || ! is_array( $section['elements'] ) ) {
            $issues[] = array( 'type' => 'no_columns', 'message' => 'Section has no columns' );
            return $issues;
        }

        foreach ( $section['elements'] as $col_idx => $column ) {
            if ( ! is_array( $column ) || ( $column['elType'] ?? '' ) !== 'column' ) {
                $issues[] = array(
                    'type'    => 'bad_column',
                    'message' => "Column #$col_idx is not a column",
                    'col_idx' => $col_idx,
                );
                continue;
            }
            if ( empty( $column['elements'] ) ) {
                $issues[] = array(
                    'type'    => 'empty_column',
                    'message' => "Column #$col_idx has no widgets",
                    'col_idx' => $col_idx,
                );
                continue;
            }
            foreach ( $column['elements'] as $w_idx => $widget ) {
                if ( ! is_array( $widget ) ) {
                    $issues[] = array(
                        'type'    => 'not_array_widget',
                        'message' => "Widget at col=$col_idx, idx=$w_idx is not an array",
                    );
                    continue;
                }
                if ( ( $widget['elType'] ?? '' ) !== 'widget' ) {
                    $issues[] = array(
                        'type'    => 'bad_widget_elType',
                        'message' => "Widget at col=$col_idx, idx=$w_idx elType is not 'widget'",
                    );
                }
                if ( empty( $widget['widgetType'] ) ) {
                    $issues[] = array(
                        'type'    => 'missing_widgetType',
                        'message' => "Widget at col=$col_idx, idx=$w_idx has no widgetType",
                    );
                } elseif ( ! in_array( (string) $widget['widgetType'], self::ALLOWED_WIDGETS, true ) ) {
                    $issues[] = array(
                        'type'    => 'unknown_widgetType',
                        'message' => sprintf( 'Widget "%s" not in known list', $widget['widgetType'] ),
                    );
                }
            }
        }
        return $issues;
    }

    private function auto_fix_section( $section, &$fixed ) {
        if ( ! is_array( $section ) || ( $section['elType'] ?? '' ) !== 'section' ) {
            return $section;
        }
        if ( empty( $section['id'] ) ) {
            $section['id'] = 'sec' . substr( md5( wp_json_encode( $section ) ), 0, 7 );
            $fixed++;
        }
        if ( empty( $section['elements'] ) || ! is_array( $section['elements'] ) ) {
            $section['elements'] = array();
            $fixed++;
            return $section;
        }
        foreach ( $section['elements'] as $col_idx => $column ) {
            if ( ! is_array( $column ) || ( $column['elType'] ?? '' ) !== 'column' ) {
                unset( $section['elements'][ $col_idx ] );
                $fixed++;
                continue;
            }
            if ( empty( $column['id'] ) ) {
                $column['id'] = 'col' . substr( md5( wp_json_encode( $column ) ), 0, 7 );
                $fixed++;
            }
            if ( ! empty( $column['elements'] ) && is_array( $column['elements'] ) ) {
                foreach ( $column['elements'] as $w_idx => $widget ) {
                    if ( ! is_array( $widget ) ) {
                        unset( $column['elements'][ $w_idx ] );
                        $fixed++;
                        continue;
                    }
                    if ( empty( $widget['id'] ) ) {
                        $widget['id'] = 'wid' . substr( md5( wp_json_encode( $widget ) ), 0, 7 );
                        $fixed++;
                    }
                    if ( empty( $widget['settings'] ) || ! is_array( $widget['settings'] ) ) {
                        $widget['settings'] = array();
                        $fixed++;
                    }
                    if ( ( $widget['elType'] ?? '' ) === 'widget' && empty( $widget['widgetType'] ) ) {
                        $widget['widgetType'] = 'shortcode';
                        $fixed++;
                    }
                    $column['elements'][ $w_idx ] = $widget;
                }
            }
            $section['elements'][ $col_idx ] = $column;
        }
        $section['elements'] = array_values( $section['elements'] );
        return $section;
    }
}