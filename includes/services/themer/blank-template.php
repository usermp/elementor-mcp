<?php
/**
 * Blank template used by MCP_Themer when a single-post override matches.
 * Just renders the part and exits without any chrome.
 *
 * @package ElementorMCP
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_footer(); ?>
</body>
</html>