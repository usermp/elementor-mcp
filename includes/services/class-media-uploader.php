<?php
/**
 * Media Upload from URL.
 *
 * Fetches a remote image, saves it to the WordPress media library, and
 * returns the new attachment id. Used by Site_Cloner to copy a page's
 * hero image and by the chat panel's "upload by URL" feature.
 *
 * @package ElementorMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCP_Media_Uploader {

    const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml' );

    /**
     * @param string $url         Remote image URL
     * @param array  $opts {
     *     @type string $title      Override attachment title
     *     @type string $alt        Alt text
     *     @type int    $parent_post_id  Optional, for organizing uploads
     * }
     * @return array|\WP_Error { 'id' => int, 'url' => string, 'width' => int, 'height' => int }
     */
    public function upload_from_url( $url, array $opts = array() ) {
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( ! $url ) {
            return new WP_Error( 'mcp_media_bad_url', __( 'Invalid URL.', 'elementor-mcp' ), array( 'status' => 400 ) );
        }

        $tmp = download_url( $url, 20 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }
        if ( filesize( $tmp ) > self::MAX_BYTES ) {
            @unlink( $tmp );
            return new WP_Error( 'mcp_media_too_large', __( 'Remote file exceeds 10 MB.', 'elementor-mcp' ), array( 'status' => 413 ) );
        }

        $file_array = array(
            'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'upload' ),
            'tmp_name' => $tmp,
        );

        $attach_id = media_handle_sideload( $file_array, (int) ( $opts['parent_post_id'] ?? 0 ) );
        if ( is_wp_error( $attach_id ) ) {
            @unlink( $tmp );
            return $attach_id;
        }

        if ( ! empty( $opts['alt'] ) ) {
            update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $opts['alt'] ) );
        }
        if ( ! empty( $opts['title'] ) ) {
            wp_update_post( array(
                'ID'         => $attach_id,
                'post_title' => sanitize_text_field( $opts['title'] ),
            ) );
        }

        $src = wp_get_attachment_url( $attach_id );
        $meta = wp_get_attachment_metadata( $attach_id );

        MCP_Logger::info( 'Media uploaded from URL', array(
            'attachment_id' => $attach_id,
            'source_url'    => $url,
            'bytes'         => filesize( $tmp ) + 0,
        ) );

        return array(
            'id'     => (int) $attach_id,
            'url'    => $src,
            'width'  => (int) ( $meta['width'] ?? 0 ),
            'height' => (int) ( $meta['height'] ?? 0 ),
            'mime'   => get_post_mime_type( $attach_id ),
        );
    }
}