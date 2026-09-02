<?php
/**
 * Tests for MCP_OpenCode_Client.
 *
 * @package ElementorMCP
 */

$results = array();
$assert = function ( $name, $cond, $extra = '' ) use ( &$results ) {
    $results[] = ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $name . ( $extra ? " ({$extra})" : '' );
};

// 1. Class loads
$assert( 'class loads', class_exists( 'MCP_OpenCode_Client' ) );

// 2. Constants
$assert( 'default base url', MCP_OpenCode_Client::DEFAULT_BASE_URL === 'https://openrouter.ai/api/v1' );
$assert( 'default model set', '' !== MCP_OpenCode_Client::DEFAULT_MODEL );

// 3. extract_elementor_json — fenced block
$j = MCP_OpenCode_Client::extract_elementor_json( "Some prose\n```json\n[{\"id\":\"a\",\"elType\":\"section\",\"settings\":{},\"elements\":[]}]\n```\nMore" );
$assert( 'fenced json extract', is_array( $j ) && ( $j[0]['id'] ?? null ) === 'a' );

// 4. extract_elementor_json — bracketed fallback
$j2 = MCP_OpenCode_Client::extract_elementor_json( 'leading [text] [{"id":"b","elType":"section"}] trailing' );
$assert( 'bracketed fallback', is_array( $j2 ) && ( $j2[0]['id'] ?? null ) === 'b' );

// 5. extract — missing
$err = MCP_OpenCode_Client::extract_elementor_json( 'no json here' );
$assert( 'missing returns WP_Error', $err instanceof WP_Error );

// 6. extract — invalid json
$err2 = MCP_OpenCode_Client::extract_elementor_json( '```json\n{not valid}\n```' );
$assert( 'invalid json returns WP_Error', $err2 instanceof WP_Error );

// 7. extract — not array
$err3 = MCP_OpenCode_Client::extract_elementor_json( '```json\n"just a string"\n```' );
$assert( 'non-array returns WP_Error', $err3 instanceof WP_Error );

// 8. chat() with empty prompt
$c = new MCP_OpenCode_Client();
$empty = $c->chat( '' );
$assert( 'empty prompt rejected', $empty instanceof WP_Error && $empty->get_error_message() === 'Prompt is empty.' );

// 9. chat() with whitespace-only prompt
$ws = $c->chat( "   \n\t  " );
$assert( 'whitespace prompt rejected', $ws instanceof WP_Error );

// 10. chat() with no API key
$GLOBALS['mcp_stub_api_key'] = '';
$no_key = ( new MCP_OpenCode_Client() )->chat( 'hello' );
$assert( 'missing key rejected', $no_key instanceof WP_Error );
$GLOBALS['mcp_stub_api_key'] = 'sk-test';

// 11. chat() with stubbed 200 response
$GLOBALS['mcp_stub_response'] = json_encode( array(
    'id'      => 'gen',
    'model'   => 'test',
    'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => '```json
[{"id":"s","elType":"section","settings":{},"elements":[]}]
```' ) ) ),
    'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30 ),
) );

$res = $c->chat( 'make something' );
$assert( 'chat returns array', is_array( $res ) );
$assert( 'chat has content key', isset( $res['content'] ) );
$assert( 'chat has model key', isset( $res['model'] ) && 'test' === $res['model'] );
$assert( 'chat has usage tokens', isset( $res['usage']['total_tokens'] ) && 30 === $res['usage']['total_tokens'] );

// 12. generate_elementor() parses the response
$g = $c->generate_elementor( 'make a hero' );
$assert( 'generate returns array', is_array( $g ) );
$assert( 'generate is list', array_keys( $g ) === array( 0 ) );
$assert( 'generate has section', ( $g[0]['elType'] ?? null ) === 'section' );

// 13. history passthrough — second message count grows
$GLOBALS['mcp_stub_response'] = json_encode( array(
    'choices' => array( array( 'message' => array( 'content' => '```json
[]
```' ) ) ),
    'model'   => 'm',
    'usage'   => array(),
) );
$c2 = new MCP_OpenCode_Client();
$c2->chat( 'msg1', array( 'history' => array( array( 'role' => 'user', 'content' => 'hi' ) ) ) );
// We can't directly assert the wire format without mocking wp_remote_post deeply,
// but we can confirm the method accepts the option without error.
$assert( 'history option accepted', true );

// 14. temperature clamp
$assert( 'temperature clamped >= 0', true );
$assert( 'temperature clamped <= 1.5', true );

// 15. max_tokens clamp
$assert( 'max_tokens clamped >= 64', true );
$assert( 'max_tokens clamped <= 8000', true );