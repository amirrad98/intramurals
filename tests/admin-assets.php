<?php
// Standalone harness for F-024: Assets::enqueue_admin_assets() must load leagueflow-admin
// (admin.css) and admin.js on every LeagueFlow admin screen, including Placements, Fixtures,
// Utilities and join requests, and on no unrelated WordPress screen.
// Usage: php tests/admin-assets.php [path-to-repo]
namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['lf_screen'] = null; $GLOBALS['lf_enqueued'] = array();
	function get_current_screen() { return $GLOBALS['lf_screen']; }
	function wp_enqueue_style( $h ) { $GLOBALS['lf_enqueued'][] = $h; }
	function wp_enqueue_script( $h ) { $GLOBALS['lf_scripts'][] = $h; }
	function add_action() {}
	$repo = $argv[1] ?? dirname( __DIR__ );
	require $repo . '/includes/class-assets.php';
	$assets = ( new ReflectionClass( 'LeagueFlow\\Assets' ) )->newInstanceWithoutConstructor();
	$pass = 0; $fail = 0;
	$expect = array(
		'leagueflow_page_leagueflow-placements' => true,
		'leagueflow_page_leagueflow-requests'   => true,
		'leagueflow_page_leagueflow-setup'      => true,
		'leagueflow_page_leagueflow-fixtures'   => true,
		'leagueflow_page_leagueflow-utilities'  => true,
		'leagueflow_page_leagueflow-fields'     => true,
		'leagueflow_page_leagueflow-guide'      => true,
		'toplevel_page_leagueflow'              => true,
		'leagueflow_page_leagueflow-sport-soccer-standings' => true,
		'edit-lf_join_request'                  => true,
		'lf_join_request'                       => true,
		'dashboard'                             => false,
		'edit-post'                             => false,
		'post'                                  => false,
		'edit-page'                             => false,
	);
	foreach ( $expect as $id => $want ) {
		$GLOBALS['lf_screen'] = (object) array( 'id' => $id ); $GLOBALS['lf_enqueued'] = array(); $GLOBALS['lf_scripts'] = array();
		$assets->enqueue_admin_assets();
		$got = in_array( 'leagueflow-admin', $GLOBALS['lf_enqueued'], true );
		$js  = in_array( 'leagueflow-admin-ui', $GLOBALS['lf_scripts'], true );
		if ( $js !== $got ) { $fail++; echo "FAIL $id => admin.js and admin.css must load together\n"; continue; }
		$ok = $got === $want; $ok ? $pass++ : $fail++;
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $id . ' => ' . ( $got ? 'enqueued' : 'not enqueued' ) . "\n";
	}
	echo "$pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );
}
