<?php
/**
 * Tests for the end-to-end chat flow:
 * OpenCode_Client → Chat_REST → Importer.
 *
 * @package ElementorMCP
 */

$results = array();
$assert = function ( $name, $cond, $extra = '' ) use ( &$results ) {
    $results[] = ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $name . ( $extra ? " ({$extra})" : '' );
};

// 1. Stub a real-looking AI response
$GLOBALS['mcp_stub_response'] = json_encode( array(
    'id'      => 'gen_1',
    'model'   => 'test',
    'choices' => array( array(
        'message' => array(
            'role'    => 'assistant',
            'content' => '```json
[
  {
    "id": "sec1",
    "elType": "section",
    "settings": { "background_color": "#0f172a" },
    "elements": [
      {
        "id": "col1",
        "elType": "column",
        "settings": { "_column_size": 100 },
        "elements": [
          {
            "id": "hd1",
            "elType": "widget",
            "widgetType": "heading",
            "settings": { "title": "Welcome", "header_size": "h1" }
          },
          {
            "id": "bt1",
            "elType": "widget",
            "widgetType": "button",
            "settings": { "text": "Get Started" }
          }
        ]
      }
    ]
  }
]
```'
        )
    ) ),
    'usage'   => array( 'prompt_tokens' => 80, 'completion_tokens' => 120, 'total_tokens' => 200 ),
) );

// 2. Generate via OpenCode_Client
$client = new MCP_OpenCode_Client();
$gen = $client->generate_elementor( 'make a hero' );
$assert( 'generate succeeds', is_array( $gen ) );
$assert( 'one section', count( $gen ) === 1 );
$assert( 'section elType', $gen[0]['elType'] === 'section' );
$assert( 'one column', count( $gen[0]['elements'] ) === 1 );
$assert( 'two widgets', count( $gen[0]['elements'][0]['elements'] ) === 2 );

// 3. Diff engine against empty
$diff_engine = new MCP_Diff_Engine();
$diff = $diff_engine->diff( array(), $gen );
$assert( 'diff against empty adds', $diff['summary']['added'] === 4 );
$assert( 'diff has byte_delta', isset( $diff['byte_delta'] ) );

// 4. Importer dry-run
$importer = new MCP_Importer();
$result = $imp_result = $importer->import( $gen, array( 'dry_run' => true ) );
$assert( 'importer dry-run ok', is_array( $result ) );
$assert( 'importer stats sections', $result['stats']['sections'] === 1 );
$assert( 'importer stats columns', $result['stats']['columns'] === 1 );
$assert( 'importer stats widgets', $result['stats']['widgets'] === 2 );

// 5. Chat_REST handle() with empty prompt
$req = new WP_REST_Request();
$req->params = array( 'prompt' => '' );
$err = MCP_Chat_REST::handle( $req );
$assert( 'chat empty rejected', $err instanceof WP_Error );

// 6. Chat_REST handle() with valid prompt
$req->params = array( 'prompt' => 'make a hero', 'post_id' => 0 );
$resp = MCP_Chat_REST::handle( $req );
$assert( 'chat returns WP_REST_Response', $resp instanceof WP_REST_Response );
$assert( 'chat ok=true', $resp->data['ok'] === true );
$assert( 'chat has sections', isset( $resp->data['sections'] ) );
$assert( 'chat has stats', isset( $resp->data['stats'] ) );
$assert( 'chat has stats sections', $resp->data['stats']['sections'] === 1 );
$assert( 'chat has stats widgets', $resp->data['stats']['widgets'] === 2 );
$assert( 'chat has model', '' !== $resp->data['model'] );

// 7. Chat_REST handle() with diff
$req->params = array( 'prompt' => 'make a hero', 'post_id' => 12345 );
$resp2 = MCP_Chat_REST::handle( $req );
$assert( 'chat has diff when post_id set', isset( $resp2->data['diff'] ) );

// 8. Chat_REST apply() — dry-run on new page
$req3 = new WP_REST_Request();
$req3->params = array(
    'sections' => $gen,
    'post_id'  => 0,
    'title'    => 'Test page',
    'status'   => 'draft',
    'dry_run'  => true,
);
$apply = MCP_Chat_REST::handle_apply( $req3 );
$assert( 'apply dry-run returns response', $apply instanceof WP_REST_Response );
$assert( 'apply dry-run has stats', isset( $apply->data['stats'] ) );
$assert( 'apply dry-run post_id null', $apply->data['post_id'] === null );

// 9. Chat_REST apply() — real apply on new page (stubbed wp_insert_post returns 4242)
$req4 = new WP_REST_Request();
$req4->params = array(
    'sections' => $gen,
    'post_id'  => 0,
    'title'    => 'Real test page',
    'status'   => 'draft',
);
$apply_real = MCP_Chat_REST::handle_apply( $req4 );
$assert( 'apply real returns response', $apply_real instanceof WP_REST_Response );
$assert( 'apply real got post_id', $apply_real->data['post_id'] === 4242 );
$assert( 'apply real edit_url set', strpos( $apply_real->data['edit_url'], 'post=4242' ) !== false );

// 10. Chat_REST apply() — empty sections rejected
$req5 = new WP_REST_Request();
$req5->params = array( 'sections' => array() );
$apply_err = MCP_Chat_REST::handle_apply( $req5 );
$assert( 'apply empty sections rejected', $apply_err instanceof WP_Error );

// 11. Permissions check passes for editors
$perm = MCP_Chat_REST::permissions_check( new WP_REST_Request() );
$assert( 'permissions returns true', $perm === true );

// 12. Restore a 200 with malformed JSON content
$GLOBALS['mcp_stub_response'] = json_encode( array(
    'choices' => array( array( 'message' => array( 'content' => 'just text, no json' ) ) ),
) );
$client2 = new MCP_OpenCode_Client();
$err_gen = $client2->generate_elementor( 'whatever' );
$assert( 'generate errors on no-json response', $err_gen instanceof WP_Error );