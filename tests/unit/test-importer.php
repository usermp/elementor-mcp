<?php
/**
 * Tests for MCP_Importer.
 *
 * @package ElementorMCP
 */

$results = array();
$assert = function ( $name, $cond, $extra = '' ) use ( &$results ) {
    $results[] = ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $name . ( $extra ? " ({$extra})" : '' );
};

$imp = new MCP_Importer();

// 1. parse bare sections array
$bare = array(
    array(
        'id'       => 's1',
        'elType'   => 'section',
        'settings' => array( 'background_color' => '#fff' ),
        'elements' => array(
            array(
                'id'       => 'c1',
                'elType'   => 'column',
                'settings' => array( '_column_size' => 50 ),
                'elements' => array(
                    array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Hi' ) ),
                ),
            ),
        ),
    ),
);
$parsed = $imp->parse( $bare );
$assert( 'parse bare array', is_array( $parsed ) );
$assert( 'sections preserved', count( $parsed['sections'] ) === 1 );
$assert( 'columns preserved', $parsed['sections'][0]['elements'][0]['elType'] === 'column' );
$assert( 'widget preserved', $parsed['sections'][0]['elements'][0]['elements'][0]['widgetType'] === 'heading' );

// 2. ID regeneration
$assert( 'section id regenerated', $parsed['sections'][0]['id'] !== 's1' );
$assert( 'column id regenerated', $parsed['sections'][0]['elements'][0]['id'] !== 'c1' );
$assert( 'widget id regenerated', $parsed['sections'][0]['elements'][0]['elements'][0]['id'] !== 'w1' );

// 3. ID prefix by elType
$assert( 'section id prefixed', strpos( $parsed['sections'][0]['id'], 'sec' ) === 0 );
$assert( 'column id prefixed', strpos( $parsed['sections'][0]['elements'][0]['id'], 'col' ) === 0 );
$assert( 'widget id prefixed', strpos( $parsed['sections'][0]['elements'][0]['elements'][0]['id'], 'wid' ) === 0 );

// 4. Envelope
$envelope = array(
    'version'       => '0.4',
    'title'         => 'My Page',
    'type'          => 'page',
    'content'       => array( array( 'id' => 'x', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) ),
    'page_settings' => array( 'hide_title' => 'yes' ),
);
$parsed2 = $imp->parse( $envelope );
$assert( 'envelope parses', is_array( $parsed2 ) );
$assert( 'envelope meta kept', ( $parsed2['meta']['title'] ?? null ) === 'My Page' );
$assert( 'envelope type kept', ( $parsed2['meta']['type'] ?? null ) === 'page' );
$assert( 'envelope page_settings', isset( $parsed2['page_settings']['hide_title'] ) );

// 5. Single element
$single = array( 'id' => 'x', 'elType' => 'section', 'settings' => array(), 'elements' => array() );
$parsed3 = $imp->parse( $single );
$assert( 'single object parses', is_array( $parsed3 ) && count( $parsed3['sections'] ) === 1 );

// 6. JSON string input
$json = json_encode( $bare );
$parsed4 = $imp->parse( $json );
$assert( 'json string parses', is_array( $parsed4 ) && count( $parsed4['sections'] ) === 1 );

// 7. Invalid JSON
$bad = $imp->parse( '{not json' );
$assert( 'invalid json rejected', $bad instanceof WP_Error );

// 8. Unknown shape
$bad2 = $imp->parse( array( 'foo' => 'bar' ) );
$assert( 'unknown shape rejected', $bad2 instanceof WP_Error );

// 9. Blocked widget
$with_blocked = array(
    array(
        'id' => 's', 'elType' => 'section', 'settings' => array(),
        'elements' => array(
            array( 'id' => 'g', 'elType' => 'widget', 'widgetType' => 'global', 'settings' => array() ),
            array( 'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'OK' ) ),
        ),
    ),
);
$parsed5 = $imp->parse( $with_blocked );
$assert( 'blocked widget dropped', count( $parsed5['sections'][0]['elements'] ) === 1 );
$assert( 'heading survived block', $parsed5['sections'][0]['elements'][0]['widgetType'] === 'heading' );

// 10. Dry-run import
$result = $imp->import( $bare, array( 'dry_run' => true ) );
$assert( 'dry-run success', is_array( $result ) );
$assert( 'dry-run has stats', isset( $result['stats']['sections'] ) );
$assert( 'dry-run sections count', $result['stats']['sections'] === 1 );
$assert( 'dry-run columns count', $result['stats']['columns'] === 1 );
$assert( 'dry-run widgets count', $result['stats']['widgets'] === 1 );
$assert( 'dry-run no post_id', $result['post_id'] === null );

// 11. Deep nesting rejected
$deep = array( 'id' => 'r', 'elType' => 'section', 'settings' => array() );
$cur = &$deep;
for ( $i = 0; $i < 30; $i++ ) {
    $child = array( 'id' => "c$i", 'elType' => 'section', 'settings' => array(), 'elements' => array() );
    $cur['elements'][] = &$child;
    $cur = &$child;
}
unset( $cur );
$deep_err = $imp->parse( array( $deep ) );
$assert( 'deep nesting rejected', $deep_err instanceof WP_Error );

// 12. Settings sanitization
$xss = array(
    'id' => 's', 'elType' => 'section',
    'settings' => array( 'title' => '<script>alert(1)</script>safe' ),
    'elements' => array(),
);
$parsed6 = $imp->parse( $xss );
$assert( 'xss stripped from settings', strpos( $parsed6['sections'][0]['settings']['title'], '<script>' ) === false );