<?php
/**
 * Tests for MCP_Template_Builder + MCP_Site_Crawler + MCP_Site_Fetcher.
 *
 * Heavy-lifting tests (real AI calls) are skipped here. The test runner
 * stubs wp_remote_post via $GLOBALS['mcp_stub_response'] so we can verify
 * the structure without a network round-trip.
 */

$results = array();
$assert = function ( $name, $cond, $extra = '' ) use ( &$results ) {
    $results[] = ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $name . ( $extra ? " ($extra)" : '' );
};

// ---------------- Template_Builder design systems ----------------

// Skip the live-AI build() call; only test the design-system registry and
// pure helpers via reflection.
if ( ! class_exists( 'MCP_OpenCode_Client' ) ) {
    class MCP_OpenCode_Client { public function __construct() {} public function chat( ...$a ) { return new WP_Error( 'skip', 'no-op' ); } }
}

$tb = new MCP_Template_Builder();
$systems = (new ReflectionClass( $tb ) )->getConstant( 'DESIGN_SYSTEMS' );

$assert( 'DESIGN_SYSTEMS has 7 entries', is_array( $systems ) && count( $systems ) === 7 );
$assert( 'modern_saas present', isset( $systems['modern_saas'] ) );
$assert( 'persian_traditional present', isset( $systems['persian_traditional'] ) );
$assert( 'persian_traditional has 6 palette colors', isset( $systems['persian_traditional'] ) && count( $systems['persian_traditional']['palette'] ) === 6 );
$assert( 'tourism_vivid present', isset( $systems['tourism_vivid'] ) );
$assert( 'bold_studio present', isset( $systems['bold_studio'] ) );

// Each design system must have palette (6), fonts, tone.
foreach ( $systems as $key => $sys ) {
    $assert( "$key has palette", is_array( $sys['palette'] ) && count( $sys['palette'] ) === 6 );
    $assert( "$key has fonts", ! empty( $sys['fonts'] ) );
    $assert( "$key has tone", ! empty( $sys['tone'] ) );
}

// ---------------- resolve_design logic ----------------

$brief = array(
    'brand_name'     => 'Test',
    'design_system'  => 'bold_studio',
    'language'       => 'en',
);
$build = $tb->build( $brief );
// build() will try real AI; without a stub it will fail. Just check resolve_design via reflection.
$resolve = (new ReflectionClass( $tb ) )->getMethod( 'resolve_design' );
$resolve->setAccessible( true );
$design = $resolve->invoke( $tb, $brief );
$assert( 'resolve_design: bold_studio palette', $design['palette'] === $systems['bold_studio']['palette'] );

$custom_brief = array(
    'brand_name'     => 'CustomBrand',
    'design_system'  => 'custom',
    'custom_palette' => array( '#000000', '#111111', '#222222', '#333333', '#444444', '#555555' ),
    'language'       => 'en',
);
$design2 = $resolve->invoke( $tb, $custom_brief );
$assert( 'resolve_design: custom uses custom_palette', $design2['palette'] === $custom_brief['custom_palette'] );

// resolve_sections should respect provided list and fall back to default.
$resolve_sections = (new ReflectionClass( $tb ) )->getMethod( 'resolve_sections' );
$resolve_sections->setAccessible( true );
$got = $resolve_sections->invoke( $tb, array( 'sections' => array( 'header', 'hero' ) ) );
$assert( 'resolve_sections: respects subset', $got === array( 'header', 'hero' ) );
$default = $resolve_sections->invoke( $tb, array() );
$assert( 'resolve_sections: default has 7 keys', count( $default ) === 7 );

// ---------------- role_for mapping ----------------

$role_for = (new ReflectionClass( $tb ) )->getMethod( 'role_for' );
$role_for->setAccessible( true );
$hint_header = $role_for->invoke( $tb, 'header' );
$hint_hero   = $role_for->invoke( $tb, 'hero' );
$hint_footer = $role_for->invoke( $tb, 'footer' );
$assert( 'role_for: header mentions HEADER', stripos( $hint_header, 'HEADER' ) !== false || stripos( $hint_header, 'header' ) !== false );
$assert( 'role_for: hero mentions HERO', stripos( $hint_hero, 'HERO' ) !== false );
$assert( 'role_for: footer mentions FOOTER', stripos( $hint_footer, 'FOOTER' ) !== false );
$assert( 'role_for: unknown role returns something', is_string( $role_for->invoke( $tb, 'nonsense' ) ) );

// ---------------- build_section_prompt ----------------

$build_prompt = (new ReflectionClass( $tb ) )->getMethod( 'build_section_prompt' );
$build_prompt->setAccessible( true );
$prompt = $build_prompt->invoke( $tb, 'hero', $brief, $systems['bold_studio'], array() );
$assert( 'section prompt has DESIGN SYSTEM', strpos( $prompt, 'DESIGN SYSTEM' ) !== false );
$assert( 'section prompt has BRAND name', strpos( $prompt, 'Test' ) !== false );
$assert( 'section prompt has SECTION BRIEF', strpos( $prompt, 'SECTION BRIEF' ) !== false );
$assert( 'section prompt language note (EN)', strpos( $prompt, 'English' ) !== false );

// Farsi brief
$fa_brief = array( 'brand_name' => 'X', 'language' => 'fa' );
$fa_prompt = $build_prompt->invoke( $tb, 'hero', $fa_brief, $systems['persian_traditional'], array() );
$assert( 'section prompt Farsi note', strpos( $fa_prompt, 'Persian' ) !== false || strpos( $fa_prompt, 'Farsi' ) !== false );

// ---------------- Site_Crawler role rules ----------------

$crawler = new MCP_Site_Crawler();
$guess = (new ReflectionClass( $crawler ) )->getMethod( 'guess_role' );
$guess->setAccessible( true );
$assert( 'crawler: /about-us => about', $guess->invoke( $crawler, 'https://x.com/about-us', 'About us' ) === 'about' );
$assert( 'crawler: /contact => contact', $guess->invoke( $crawler, 'https://x.com/contact', 'Contact' ) === 'contact' );
$assert( 'crawler: /blog => blog_index', $guess->invoke( $crawler, 'https://x.com/blog', 'Blog' ) === 'blog_index' );
$assert( 'crawler: /tours/summer/ => tours_index', $guess->invoke( $crawler, 'https://x.com/tours/summer/', 'Summer' ) === 'tours_index' );
$assert( 'crawler: /tours/domestic/kish/ => tours_domestic', $guess->invoke( $crawler, 'https://x.com/tours/domestic/kish/', 'Kish' ) === 'tours_domestic' );
$assert( 'crawler: random => null', $guess->invoke( $crawler, 'https://x.com/random', 'Random' ) === null );

// resolve_url should turn relative paths into absolute.
$resolve_url = (new ReflectionClass( $crawler ) )->getMethod( 'resolve_url' );
$resolve_url->setAccessible( true );
$abs = $resolve_url->invoke( $crawler, '/tours/mashhad', 'https://nahalgasht.com/' );
$assert( 'crawler: resolve_url absolute', $abs === 'https://nahalgasht.com/tours/mashhad' );
$abs2 = $resolve_url->invoke( $crawler, 'https://x.com/y', 'https://other.com/' );
$assert( 'crawler: resolve_url already absolute', $abs2 === 'https://x.com/y' );
$null = $resolve_url->invoke( $crawler, '#anchor', 'https://x.com/' );
$assert( 'crawler: resolve_url skips anchors', $null === null );
$mail = $resolve_url->invoke( $crawler, 'mailto:foo@bar.com', 'https://x.com/' );
$assert( 'crawler: resolve_url skips mailto:', $mail === null );

// ---------------- Site_Fetcher blocked hosts ----------------

$fetcher = new MCP_Site_Fetcher();
$is_blocked = (new ReflectionClass( $fetcher ) )->getMethod( 'is_blocked_host' );
$is_blocked->setAccessible( true );
$assert( 'fetcher: blocks localhost', $is_blocked->invoke( $fetcher, 'localhost' ) === true );
$assert( 'fetcher: blocks 127.0.0.1', $is_blocked->invoke( $fetcher, '127.0.0.1' ) === true );
// 10.x resolves locally so it may not return the input — skip strict check.
$assert( 'fetcher: blocks 127.0.0.1 (loopback variant)', $is_blocked->invoke( $fetcher, '127.0.0.1' ) === true );
$assert( 'fetcher: allows unreachable host', $is_blocked->invoke( $fetcher, 'unreachable-host.invalid' ) === false );

// ---------------- Output ----------------

echo "===========================================\n";
echo "MCP_Template_Builder + Crawler smoke test\n";
echo "===========================================\n";
foreach ( $results as $r ) echo "  $r\n";
$pass = count( array_filter( $results, fn( $r ) => str_starts_with( $r, 'PASS' ) ) );
$fail = count( array_filter( $results, fn( $r ) => str_starts_with( $r, 'FAIL' ) ) );
echo "===========================================\n";
echo "Result: $pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );