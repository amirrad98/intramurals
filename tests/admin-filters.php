<?php
// Standalone harness for F-019: handle_admin_sorting() on lf_join_request must clear the
// lf_sport / lf_league_level taxonomy query vars and filter by meta instead.
// Usage: php tests/admin-filters.php [path-to-repo]
namespace { 
	define( 'ABSPATH', __DIR__ );
	function is_admin() { return true; }
	function wp_unslash( $v ) { return $v; }
	function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
	function absint( $v ) { return abs( (int) $v ); }
	function get_term_by( $f, $v, $t ) { return 'competitive' === $v ? new WP_Term( 18 ) : false; }
	function add_action() {} function add_filter() {}
	class WP_Term { public $term_id; function __construct( $id ) { $this->term_id = $id; } }
	class WP_Query {
		public $vars; function __construct( $v ) { $this->vars = $v; }
		function is_main_query() { return true; }
		function get( $k ) { return $this->vars[ $k ] ?? ''; }
		function set( $k, $v ) { $this->vars[ $k ] = $v; }
	}
}
namespace LeagueFlow {
	// Silence helper includes by declaring only what's needed.
}
namespace {
	$repo = $argv[1] ?? dirname( __DIR__ );
	require $repo . '/includes/class-admin.php';
	$ref   = new ReflectionClass( 'LeagueFlow\\Admin' );
	$admin = $ref->newInstanceWithoutConstructor();
	$pass = 0; $fail = 0;
	$check = function ( $label, $ok ) use ( &$pass, &$fail ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; $ok ? $pass++ : $fail++; };

	$_GET = array( 'post_type' => 'lf_join_request', 'lf_sport' => 'soccer', 'lf_league_level' => 'competitive' );
	$q = new WP_Query( array( 'post_type' => 'lf_join_request', 'lf_sport' => 'soccer', 'lf_league_level' => 'competitive' ) );
	$admin->handle_admin_sorting( $q );
	$check( 'lf_sport query var cleared', '' === $q->get( 'lf_sport' ) );
	$check( 'lf_league_level query var cleared', '' === $q->get( 'lf_league_level' ) );
	$mq = $q->get( 'meta_query' );
	$check( 'sport meta clause added', is_array( $mq ) && in_array( array( 'key' => 'lf_sport_slug', 'value' => 'soccer' ), $mq, true ) );
	$check( 'level meta clause uses term id 18', is_array( $mq ) && (bool) array_filter( $mq, fn( $c ) => 'lf_league_level_id' === ( $c['key'] ?? '' ) && 18 === $c['value'] ) );
	$check( 'no tax_query added for join requests', '' === $q->get( 'tax_query' ) );

	$_GET = array( 'post_type' => 'lf_team', 'lf_sport' => 'soccer' );
	$q2 = new WP_Query( array( 'post_type' => 'lf_team', 'lf_sport' => 'soccer' ) );
	$admin->handle_admin_sorting( $q2 );
	$check( 'teams keep lf_sport query var', 'soccer' === $q2->get( 'lf_sport' ) );
	$check( 'teams get lf_sport tax clause', is_array( $q2->get( 'tax_query' ) ) );
	// Sport-scoped pages must retain their sport, including fixture generators.
	$context = $ref->getMethod( 'get_current_requested_sport_slug' );
	$context->setAccessible( true );
	foreach ( array( 'leagueflow-sport-soccer-fixtures'=>'soccer', 'leagueflow-sport-soccer-standings'=>'soccer', 'leagueflow-sport-american-football-fixtures'=>'american-football', 'leagueflow-sport-volleyball'=>'volleyball' ) as $page=>$expected ) {
		$_GET = array( 'page'=>$page );
		$check( $page . ' preserves sport context', $expected === $context->invoke( $admin ) );
	}
	echo "$pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}
