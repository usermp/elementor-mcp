<?php
/**
 * Tests for MCP_Diff_Engine.
 *
 * @package ElementorMCP
 */

$results = array();
$assert = function ( $name, $cond, $extra = '' ) use ( &$results ) {
    $results[] = ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $name . ( $extra ? " ({$extra})" : '' );
};

$eng = new MCP_Diff_Engine();

// 1. Empty vs empty
$d = $eng->diff( array(), array() );
$assert( 'empty diff no ops', count( $d['ops'] ) === 0 );
$assert( 'empty diff sizes equal', $d['before_size'] === 0 && $d['after_size'] === 0 );

// 2. Section added
$d = $eng->diff( array(), array(
    array( 'id' => 'sec1', 'elType' => 'section', 'settings' => array( 'background_color' => '#fff' ), 'elements' => array() ),
) );
$assert( 'add: one op', $d['summary']['added'] === 1 );
$assert( 'add: op kind added', $d['ops'][0]['op'] === 'added' );
$assert( 'add: op id matches', $d['ops'][0]['id'] === 'sec1' );

// 3. Section removed
$d = $eng->diff( array(
    array( 'id' => 'sec1', 'elType' => 'section', 'settings' => array(), 'elements' => array() ),
), array() );
$assert( 'remove: one op', $d['summary']['removed'] === 1 );

// 4. Settings modified
$d = $eng->diff( array(
    array( 'id' => 'sec1', 'elType' => 'section', 'settings' => array( 'background_color' => '#fff' ), 'elements' => array() ),
), array(
    array( 'id' => 'sec1', 'elType' => 'section', 'settings' => array( 'background_color' => '#000' ), 'elements' => array() ),
) );
$assert( 'modify: one op', $d['summary']['modified'] === 1 );
$assert( 'modify: changed_keys lists bg', in_array( 'background_color', $d['ops'][0]['meta']['changed_keys'], true ) );

// 5. Multiple keys changed
$d = $eng->diff( array(
    array( 'id' => 's', 'elType' => 'section', 'settings' => array( 'a' => '1', 'b' => '2' ), 'elements' => array() ),
), array(
    array( 'id' => 's', 'elType' => 'section', 'settings' => array( 'a' => '1', 'b' => '3' ), 'elements' => array() ),
) );
$assert( 'modify: only changed key listed', $d['ops'][0]['meta']['changed_keys'] === array( 'b' ) );

// 6. Nested widget added
$before = array(
    array(
        'id' => 'sec1', 'elType' => 'section', 'settings' => array(),
        'elements' => array(
            array( 'id' => 'col1', 'elType' => 'column', 'settings' => array(), 'elements' => array() ),
        ),
    ),
);
$after = array(
    array(
        'id' => 'sec1', 'elType' => 'section', 'settings' => array(),
        'elements' => array(
            array(
                'id' => 'col1', 'elType' => 'column', 'settings' => array(),
                'elements' => array(
                    array('id' => 'hd1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Hi' )),
                ),
            ),
        ),
    ),
);
$d = $eng->diff( $before, $after );
$assert( 'nested: one add op', $d['summary']['added'] === 1 );
$assert( 'nested: path breadcrumb', strpos( $d['ops'][0]['path'], 'sections[0]' ) === 0 );

// 7. elType change counts as modify
$before = array( array( 'id' => 'x', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) );
$after  = array( array( 'id' => 'x', 'elType' => 'column',  'settings' => array(), 'elements' => array() ) );
$d = $eng->diff( $before, $after );
$assert( 'elType change = modify', $d['summary']['modified'] === 1 );

// 8. Identical trees produce no ops by default
$tree = array(
    array( 'id' => 's1', 'elType' => 'section', 'settings' => array(), 'elements' => array(
        array( 'id' => 'c1', 'elType' => 'column', 'settings' => array(), 'elements' => array() ),
    ) ),
);
$d = $eng->diff( $tree, $tree );
$assert( 'identical = no ops', count( $d['ops'] ) === 0 );

// 9. include_unchanged
$d2 = $eng->diff( $tree, $tree, array( 'include_unchanged' => true ) );
$assert( 'include_unchanged counts nodes', $d2['summary']['unchanged'] === 2 );

// 10. to_text summary
$text = $eng->to_text( $d );
$assert( 'to_text non-empty', is_string( $text ) && strlen( $text ) > 5 );
$assert( 'to_text mentions added', strpos( $text, 'added' ) !== false );

// 11. byte_delta positive on growth
$small = array( array( 'id' => 's', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) );
$big = array(
    array( 'id' => 's', 'elType' => 'section', 'settings' => array( 'padding' => array( 'top' => '40' ) ), 'elements' => array(
        array( 'id' => 'c', 'elType' => 'column', 'settings' => array(), 'elements' => array(
            array( 'id' => 'w', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Big title here' ) ),
        ) ),
    ) ),
);
$d = $eng->diff( $small, $big );
$assert( 'byte_delta positive on growth', $d['byte_delta'] > 0 );

// 12. max_ops limit
$many_before = array();
$many_after  = array();
for ( $i = 0; $i < 100; $i++ ) {
    $many_before[] = array( 'id' => "b$i", 'elType' => 'section', 'settings' => array(), 'elements' => array() );
    $many_after[]  = array( 'id' => "a$i", 'elType' => 'section', 'settings' => array(), 'elements' => array() );
}
$d = $eng->diff( $many_before, $many_after, array( 'max_ops' => 10 ) );
$assert( 'max_ops respected', count( $d['ops'] ) === 10 );
$assert( 'truncated flag set', $d['truncated'] === true );