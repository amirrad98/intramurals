<?php
// Standalone harness: every plugin asset's cache key follows file
// content, not modification time. Packages stamp every entry 2020-01-01, so an
// mtime key would not change if an installer kept those stamps.
// Usage: php tests/asset-versions.php [path-to-repo]
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'LEAGUEFLOW_VERSION', '1.0.3' );
	define( 'LEAGUEFLOW_URL', 'https://example.test/wp-content/plugins/leagueflow/' );
	$GLOBALS['lf_assets'] = array();
	function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['lf_assets'][ $h ] = $ver; }
	function wp_register_script( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['lf_assets'][ $h ] = $ver; }
	function add_action() {}
	$repo = $argv[1] ?? dirname( __DIR__ );
	// Work on a disposable copy of the three assets so the repository is never touched.
	$tmp = sys_get_temp_dir() . '/lf-asset-versions-' . getmypid() . '/';
	$all = array( 'assets/css/design-tokens.css', 'assets/css/frontend.css', 'assets/js/calendar.js', 'assets/js/portal.js', 'assets/css/admin.css', 'assets/css/editor.css', 'assets/js/blocks.js', 'assets/js/admin.js' );
	foreach ( $all as $rel ) {
		@mkdir( dirname( $tmp . $rel ), 0700, true );
		copy( $repo . '/' . $rel, $tmp . $rel );
		touch( $tmp . $rel, 1577836800 ); // 2020-01-01, the package entry date.
	}
	define( 'LEAGUEFLOW_PATH', $tmp );
	require $repo . '/includes/class-assets.php';
	$assets = ( new ReflectionClass( 'LeagueFlow\\Assets' ) )->newInstanceWithoutConstructor();
	$pass = 0; $fail = 0;
	$check = function ( $ok, $msg ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; };
	$versions = function () use ( $assets ) { $GLOBALS['lf_assets'] = array(); $assets->register_assets(); clearstatcache(); return $GLOBALS['lf_assets']; };

	$before = $versions();
	$handles = array( 'leagueflow-tokens' => 'assets/css/design-tokens.css', 'leagueflow-frontend' => 'assets/css/frontend.css', 'leagueflow-calendar' => 'assets/js/calendar.js', 'leagueflow-portal' => 'assets/js/portal.js', 'leagueflow-admin' => 'assets/css/admin.css', 'leagueflow-editor' => 'assets/css/editor.css', 'leagueflow-blocks' => 'assets/js/blocks.js', 'leagueflow-admin-ui' => 'assets/js/admin.js' );
	foreach ( $handles as $h => $rel ) {
		$check( 0 === strpos( (string) $before[ $h ], LEAGUEFLOW_VERSION . '-' ), "$h key starts with the plugin version: " . $before[ $h ] );
		$check( false !== strpos( (string) $before[ $h ], substr( md5_file( $tmp . $rel ), 0, 12 ) ), "$h key carries the content hash" );
	}
	// Same bytes, different mtime: key unchanged. Different bytes, same mtime: key changes.
	touch( $tmp . 'assets/js/calendar.js', 1893456000 );
	$touched = $versions();
	$check( $touched['leagueflow-calendar'] === $before['leagueflow-calendar'], 'calendar key ignores modification time' );
	file_put_contents( $tmp . 'assets/js/calendar.js', "\n// changed", FILE_APPEND );
	touch( $tmp . 'assets/js/calendar.js', 1577836800 );
	$edited = $versions();
	$check( $edited['leagueflow-calendar'] !== $before['leagueflow-calendar'], 'calendar key changes when the file changes with the package date kept' );
	$check( $edited['leagueflow-frontend'] === $before['leagueflow-frontend'], 'unchanged frontend.css keeps its key' );
	$check( $edited['leagueflow-admin'] === $before['leagueflow-admin'] && $edited['leagueflow-blocks'] === $before['leagueflow-blocks'], 'unchanged admin.css and blocks.js keep their keys' );
	// Admin-side assets also follow content, so a same-version development update is fetched fresh.
	file_put_contents( $tmp . 'assets/css/admin.css', "\n/* changed */", FILE_APPEND );
	touch( $tmp . 'assets/css/admin.css', 1577836800 );
	$admin_edit = $versions();
	$check( $admin_edit['leagueflow-admin'] !== $before['leagueflow-admin'], 'admin.css key changes when its bytes change with the package date kept' );
	foreach ( $all as $rel ) { @unlink( $tmp . $rel ); }
	echo "$pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );
}
