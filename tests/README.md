# Tests
Elementor MCP ships a self-contained test runner — no PHPUnit dependency, just PHP 7.4+.

## Run

```bash
# from the plugin root
composer test

# or directly
php tests/test-runner.php
```

Expected output:

```
=== test-opencode-client ===
  PASS — class loads
  PASS — default base url
  ...
=== test-importer ===
  PASS — parse bare array
  ...
=== test-diff-engine ===
  PASS — empty diff no ops
  ...
=== test-chat-flow ===
  PASS — generate succeeds
  ...

==================================================
Ran 4 test files · 99 passed · 0 failed
==================================================
```

## Add a test

Drop a file in `tests/unit/test-<name>.php` with this shape:

```php
<?php
$results = array();
$assert = function ( $name, $cond ) use ( &$results ) {
    $results[] = ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $name;
};

$assert( 'my test', true );
```

The runner discovers `test-*.php` files alphabetically.

## What the runner provides

The test runner stubs the WordPress functions that the plugin touches (translation,
sanitization, JSON encoding, HTTP requests, post meta) so the suite runs without a
WordPress installation. Stubs are intentionally minimal — anything richer should
be added explicitly to your test file.

## Stubbing the AI client

The runner catches `wp_remote_post()` and returns `$GLOBALS['mcp_stub_response']`.
Set that variable in your test to feed a canned AI response:

```php
$GLOBALS['mcp_stub_response'] = json_encode( array(
    'choices' => array( array( 'message' => array( 'content' => '```json
[{"elType":"section","settings":{},"elements":[]}]
```' ) ) ),
) );
```

For HTTP error simulation set `$GLOBALS['mcp_stub_code']` (e.g. `429`).
For "no API key" set `$GLOBALS['mcp_stub_api_key'] = '';`.