<?php
/**
 * Native WordPress admin workflow regressions; never run against an existing site.
 *
 * Runs inside a disposable `http://leagueflow.test` installation with
 * LEAGUEFLOW_DISPOSABLE_WP=1 (wp eval-file). It builds its own `lfa-` fixtures in
 * the Cricket sport, drives the real list-table query (wp_edit_posts_query), the
 * registered admin hooks and the admin handlers, and inspects rendered markup.
 * Destructive demo-seeding cases run inside a database transaction that is rolled
 * back. It does not send HTTP requests or render CSS; phone layout is checked
 * separately in a browser.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Set LEAGUEFLOW_DISPOSABLE_WP=1 and use only the disposable leagueflow.test installation.' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
$_SERVER['HTTP_HOST']   = 'leagueflow.test';
$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';

class LFA_Admin extends \LeagueFlow\Admin {
	public function breakdown( $sport_id ) { $this->render_sport_level_breakdown( $sport_id ); }
	public function requests_page() { $this->requests_admin->render_page(); }
	public function requests() { return $this->requests_admin; }
	public function count_views( $post_type, $status ) { return $this->count_list_posts( $post_type, $status ); }
	public function ko_targets( $match_id, $sport_slug ) { return $this->get_knockout_target_options( $match_id, $sport_slug ); }
}

$GLOBALS['lfa_pass'] = 0;
$GLOBALS['lfa_fail'] = array();
function lfa_check( $ok, $message ) {
	if ( $ok ) {
		++$GLOBALS['lfa_pass'];
		return;
	}
	$GLOBALS['lfa_fail'][] = $message;
	WP_CLI::warning( 'FAIL ' . $message );
}
function lfa_post( $type, $title, $status = 'publish', $meta = array(), $terms = array() ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture failed: ' . $id->get_error_message() );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	foreach ( $terms as $taxonomy => $term_ids ) {
		wp_set_object_terms( $id, $term_ids, $taxonomy );
	}
	return (int) $id;
}
/** Run the real edit.php main query for $args and return the listed IDs. */
function lfa_list( $post_type, $args = array() ) {
	$_GET     = array_merge( array( 'post_type' => $post_type ), $args );
	$_REQUEST = $_GET;
	$GLOBALS['typenow']   = $post_type;
	$GLOBALS['post_type'] = $post_type;
	set_current_screen( 'edit-' . $post_type );
	wp_edit_posts_query( $_GET );
	$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
	return array_map( 'intval', $ids );
}
function lfa_notices() {
	$key     = 'leagueflow_notices_' . get_current_user_id();
	$notices = get_transient( $key );
	delete_transient( $key );
	return is_array( $notices ) ? wp_list_pluck( $notices, 'message' ) : array();
}
function lfa_views( $admin, $views ) {
	if ( ! method_exists( $admin, 'filter_list_views' ) ) {
		lfa_check( false, 'ADM-02 sport-scoped list views are implemented' );
		return $views;
	}
	return $admin->filter_list_views( $views );
}
/** Run a handler; return 'redirect:<url>' or 'die:<status>' instead of exiting. */
function lfa_handle( $callback ) {
	$redirect = static function ( $location ) { throw new RuntimeException( 'redirect:' . $location ); };
	$die      = static function () { return static function ( $message, $title, $args ) { throw new RuntimeException( 'die:' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : 500 ) ); }; };
	add_filter( 'wp_redirect', $redirect, PHP_INT_MIN );
	add_filter( 'wp_die_handler', $die );
	try {
		call_user_func( $callback );
		return 'returned';
	} catch ( RuntimeException $stop ) {
		return $stop->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $redirect, PHP_INT_MIN );
		remove_filter( 'wp_die_handler', $die );
	}
}
function lfa_render( $callback ) {
	ob_start();
	call_user_func( $callback );
	return (string) ob_get_clean();
}

/** Make every GET_LOCK report an unavailable lock service (NULL) until removed. */
function lfa_null_locks( $query ) {
	return 0 === stripos( ltrim( $query ), 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $query;
}
/**
 * Run $callback once, just before the next GET_LOCK query: the moment a
 * concurrent process could change things while this one waits for the lock.
 */
function lfa_on_next_lock( callable $callback ) {
	$fired  = false;
	$filter = null;
	$filter = static function ( $query ) use ( &$fired, &$filter, $callback ) {
		if ( ! $fired && 0 === stripos( ltrim( $query ), 'SELECT GET_LOCK(' ) ) {
			$fired = true;
			remove_filter( 'query', $filter );
			$callback();
		}
		return $query;
	};
	add_filter( 'query', $filter );
	return $filter;
}
/** A second database connection holding a named lock (a concurrent process). */
function lfa_hold_lock( $name ) {
	global $wpdb;
	list( $host, $port, $socket ) = $wpdb->parse_db_host( DB_HOST );
	$other = mysqli_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, (int) ( $port ?: 3306 ), $socket ?: null );
	if ( ! $other ) {
		throw new RuntimeException( 'Second database connection failed.' );
	}
	$got = mysqli_query( $other, "SELECT GET_LOCK('" . mysqli_real_escape_string( $other, $name ) . "', 0)" )->fetch_row()[0];
	if ( '1' !== (string) $got ) {
		throw new RuntimeException( 'Could not take the test lock.' );
	}
	return $other;
}

add_filter( 'pre_http_request', static function () { return new WP_Error( 'lfa_offline', 'Outbound HTTP blocked by the admin test.' ); }, PHP_INT_MAX );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

$admin_id = get_user_by( 'login', 'admin' )->ID;
wp_set_current_user( $admin_id );
update_user_option( $admin_id, 'edit_lf_match_per_page', 200 );
update_user_option( $admin_id, 'edit_lf_team_per_page', 200 );
update_user_option( $admin_id, 'edit_lf_calendar_event_per_page', 200 );
\LeagueFlow\ensure_portal_roles();
\LeagueFlow\ensure_default_league_levels();

$sports    = new \LeagueFlow\Sports_Manager();
$fields    = new \LeagueFlow\Field_Availability_Manager();
$standings = new \LeagueFlow\Standings_Service();
$knockout  = new \LeagueFlow\Knockout_Service();
$renderer  = new \LeagueFlow\Renderer( $standings, $knockout, $sports );
$generator = new \LeagueFlow\Fixture_Generator( $fields );
$seeder    = new \LeagueFlow\Seeder();
$admin     = new LFA_Admin( $standings, $knockout, $renderer, $seeder, $sports, new \LeagueFlow\Exporter( $sports ), $fields, $generator );

// Remove leftovers of an interrupted earlier run of this suite (only its own `lfa ` fixtures).
global $wpdb;
foreach ( $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_title LIKE 'lfa %' OR post_title LIKE 'lfa\\_%'" ) as $stale_id ) {
	wp_delete_post( (int) $stale_id, true );
}
foreach ( array( 'lf_season', 'lf_competition' ) as $stale_taxonomy ) {
	foreach ( get_terms( array( 'taxonomy' => $stale_taxonomy, 'hide_empty' => false, 'name__like' => 'lfa ' ) ) as $stale_term ) {
		wp_delete_term( $stale_term->term_id, $stale_taxonomy );
	}
}

$enabled = (array) get_option( 'leagueflow_enabled_sports', array() );
update_option( 'leagueflow_enabled_sports', array_values( array_unique( array_merge( $enabled, array( 'cricket', 'hockey' ) ) ) ) );
$sport = array();
foreach ( array( 'cricket' => 'Cricket', 'hockey' => 'Hockey' ) as $slug => $name ) {
	$term           = get_term_by( 'slug', $slug, 'lf_sport' );
	$sport[ $slug ] = $term ? (int) $term->term_id : (int) wp_insert_term( $name, 'lf_sport', array( 'slug' => $slug ) )['term_id'];
}
$level = (int) get_term_by( 'slug', 'recreational', 'lf_league_level' )->term_id;
$ctx   = array( 'lf_sport' => array( $sport['cricket'] ), 'lf_league_level' => array( $level ) );

// ---------------------------------------------------------------------------
// ADM-01 · Matches and events without a date stay listed (C-01, N-05).
// ---------------------------------------------------------------------------
$teams = array();
foreach ( array( 'A', 'B', 'C', 'D' ) as $letter ) {
	$teams[] = lfa_post( 'lf_team', 'lfa Cricket ' . $letter, 'publish', 'A' === $letter ? array( 'lf_short_name' => 'LFA' ) : array(), $ctx );
}
$dated_new  = lfa_post( 'lf_match', 'lfa dated new', 'publish', array( 'lf_match_datetime' => '2026-11-02 18:30', 'lf_home_team_id' => $teams[0], 'lf_away_team_id' => $teams[1], 'lf_status' => 'scheduled' ), $ctx );
$dated_old  = lfa_post( 'lf_match', 'lfa dated old', 'publish', array( 'lf_match_datetime' => '2026-10-01 18:30', 'lf_home_team_id' => $teams[2], 'lf_away_team_id' => $teams[3], 'lf_status' => 'scheduled' ), $ctx );
$blank_date = lfa_post( 'lf_match', 'lfa blank date', 'publish', array( 'lf_match_datetime' => '', 'lf_home_team_id' => $teams[0], 'lf_away_team_id' => $teams[2] ), $ctx );
$generated  = $generator->persist( $generator->build_rounds( array( $teams[0], $teams[1], $teams[2], $teams[3] ) ), array( 'sport_id' => $sport['cricket'], 'league_level_id' => $level ) );
lfa_check( ! is_wp_error( $generated ) && 6 === $generated['created'], 'ADM-01 fixture generator created 6 undated drafts' );
$drafts = is_wp_error( $generated ) ? array() : $generated['created_ids'];
foreach ( $drafts as $draft_id ) {
	lfa_check( '' === (string) get_post_meta( $draft_id, 'lf_match_datetime', true ), 'ADM-01 generated draft has no date yet' );
}
$trashed = lfa_post( 'lf_match', 'lfa trashed undated', 'publish', array( 'lf_home_team_id' => $teams[1], 'lf_away_team_id' => $teams[3] ), $ctx );
wp_trash_post( $trashed );

$default = lfa_list( 'lf_match', array( 'lf_sport' => 'cricket' ) );
foreach ( array_merge( array( $dated_new, $dated_old, $blank_date ), $drafts ) as $id ) {
	lfa_check( in_array( $id, $default, true ), "ADM-01 default Matches list shows #$id" );
}
lfa_check( ! in_array( $trashed, $default, true ), 'ADM-01 default list excludes trash' );
lfa_check( count( $default ) === 3 + count( $drafts ), 'ADM-01 default list has every non-trashed cricket match exactly once' );
$first_dated = array_values( array_intersect( $default, array( $dated_new, $dated_old ) ) );
lfa_check( array( $dated_new, $dated_old ) === $first_dated, 'ADM-01 dated matches are newest first' );
lfa_check( array_search( $blank_date, $default, true ) < array_search( $dated_new, $default, true ), 'ADM-01 unscheduled matches are listed before dated ones by default' );

$trash = lfa_list( 'lf_match', array( 'post_status' => 'trash', 'lf_sport' => 'cricket' ) );
lfa_check( array( $trashed ) === $trash, 'ADM-01 Trash view lists the undated trashed match' );
$trash_all = lfa_list( 'lf_match', array( 'post_status' => 'trash' ) );
lfa_check( in_array( $trashed, $trash_all, true ), 'ADM-01 Trash view across all sports lists it too' );

foreach ( array( 'asc', 'desc' ) as $order ) {
	$sorted = lfa_list( 'lf_match', array( 'lf_sport' => 'cricket', 'orderby' => 'match_time', 'order' => $order ) );
	lfa_check( count( $sorted ) === count( $default ), "ADM-01 explicit date sort ($order) keeps undated matches" );
	$dated = array_values( array_intersect( $sorted, array( $dated_new, $dated_old ) ) );
	lfa_check( ( 'asc' === $order ? array( $dated_old, $dated_new ) : array( $dated_new, $dated_old ) ) === $dated, "ADM-01 explicit date sort ($order) orders dated matches" );
	lfa_check( array_search( $blank_date, $sorted, true ) > array_search( $dated_new, $sorted, true ), "ADM-01 explicit date sort ($order) puts undated matches last" );
}
$status_sort = lfa_list( 'lf_match', array( 'lf_sport' => 'cricket', 'orderby' => 'status', 'order' => 'asc' ) );
lfa_check( count( $status_sort ) === count( $default ), 'ADM-01 status sort keeps matches without a status value' );

$unscheduled = lfa_list( 'lf_match', array( 'lf_sport' => 'cricket', 'lf_schedule' => 'unscheduled' ) );
sort( $unscheduled );
$expect_unscheduled = array_merge( array( $blank_date ), $drafts );
sort( $expect_unscheduled );
lfa_check( $expect_unscheduled === $unscheduled, 'ADM-01 Unscheduled view lists exactly the undated matches' );

$short_sort = lfa_list( 'lf_team', array( 'lf_sport' => 'cricket', 'orderby' => 'short_name', 'order' => 'asc' ) );
lfa_check( 4 === count( $short_sort ), 'ADM-01 team short-name sort keeps teams without a short name' );

$event_dated   = lfa_post( 'lf_calendar_event', 'lfa event dated', 'publish', array( 'lf_event_start_datetime' => '2026-11-03 12:00' ), $ctx );
$event_undated = lfa_post( 'lf_calendar_event', 'lfa event undated', 'draft', array(), $ctx );
$events        = lfa_list( 'lf_calendar_event', array( 'lf_sport' => 'cricket' ) );
lfa_check( in_array( $event_dated, $events, true ) && in_array( $event_undated, $events, true ), 'ADM-01 Calendar Events list keeps undated events' );

$cell = lfa_render( static function () use ( $admin, $blank_date ) { $admin->render_match_column( 'match_time', $blank_date ); } );
lfa_check( false !== strpos( $cell, 'Unscheduled' ), 'ADM-01 undated match shows an Unscheduled label' );

$_GET = array( 'post_type' => 'lf_match' );
set_current_screen( 'edit-lf_match' );
$table   = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => 'edit-lf_match' ) );
$headers = lfa_render( static function () use ( $table ) { $table->print_column_headers(); } );
lfa_check( (bool) preg_match( '/id=[\'"]match_time[\'"][^>]*class=[\'"][^\'"]*sorted desc/', $headers ) || (bool) preg_match( '/class=[\'"][^\'"]*column-match_time[^\'"]*sorted desc/', $headers ), 'ADM-01 Date / Time header shows the initial descending sort' );
lfa_check( ! preg_match( '/class=[\'"][^\'"]*column-date[^\'"]*sorted/', $headers ), 'ADM-01 publish Date header does not claim the initial sort' );

// ---------------------------------------------------------------------------
// ADM-02 · Status views keep the sport context and count only that sport (C-04).
// ---------------------------------------------------------------------------
$hockey_team = lfa_post( 'lf_team', 'lfa Hockey team', 'publish', array(), array( 'lf_sport' => array( $sport['hockey'] ), 'lf_league_level' => array( $level ) ) );
$cricket_trash_team = lfa_post( 'lf_team', 'lfa Cricket trashed', 'publish', array(), $ctx );
wp_trash_post( $cricket_trash_team );
$_GET = array( 'post_type' => 'lf_team', 'lf_sport' => 'cricket' );
$GLOBALS['typenow'] = 'lf_team';
$GLOBALS['post_type'] = 'lf_team';
$views = lfa_views( $admin,  array( 'all' => '<a href="edit.php?post_type=lf_team">All <span class="count">(999)</span></a>' ) );
lfa_check( isset( $views['all'] ) && false !== strpos( $views['all'], '(4)' ), 'ADM-02 All counts only cricket teams' );
lfa_check( isset( $views['trash'] ) && false !== strpos( $views['trash'], '(1)' ), 'ADM-02 Trash counts only cricket teams' );
lfa_check( isset( $views['publish'] ) && false !== strpos( $views['publish'], 'lf_sport=cricket' ) && false !== strpos( $views['trash'], 'post_status=trash' ), 'ADM-02 view links keep lf_sport and status' );
lfa_check( false === strpos( implode( '', $views ), '999' ), 'ADM-02 core all-sport counts are replaced' );
$_GET = array( 'post_type' => 'lf_match', 'lf_sport' => 'cricket' );
$GLOBALS['typenow'] = 'lf_match';
$GLOBALS['post_type'] = 'lf_match';
$views = lfa_views( $admin,  array() );
lfa_check( isset( $views['lf_unscheduled'] ) && false !== strpos( $views['lf_unscheduled'], '(' . ( 1 + count( $drafts ) ) . ')' ) && false !== strpos( $views['lf_unscheduled'], 'lf_schedule=unscheduled' ), 'ADM-02 Matches has an Unscheduled view with its count' );
lfa_check( isset( $views['trash'] ) && false !== strpos( $views['trash'], '(1)' ), 'ADM-02 Matches Trash count matches the listed undated trash' );

// ---------------------------------------------------------------------------
// ADM-03 · Finished matches need explicit valid scores (N-01).
// ---------------------------------------------------------------------------
$save = static function ( $match_id, $fields ) use ( $admin ) {
	$_POST = wp_slash( array_merge( array( 'leagueflow_match_nonce' => wp_create_nonce( 'leagueflow_save_match' ), 'lf_home_team_id' => 0, 'lf_away_team_id' => 0, 'lf_match_datetime' => '2026-11-04T18:30' ), $fields ) );
	$admin->save_match_meta( $match_id, get_post( $match_id ) );
	$_POST = array();
	clean_post_cache( $match_id );
	return lfa_notices();
};
$score_match = lfa_post( 'lf_match', 'lfa score', 'publish', array( 'lf_status' => 'scheduled', 'lf_match_datetime' => '2026-11-04 18:30', 'lf_home_team_id' => $teams[0], 'lf_away_team_id' => $teams[1] ), $ctx );
$base = array( 'lf_home_team_id' => $teams[0], 'lf_away_team_id' => $teams[1] );
$notices = $save( $score_match, $base + array( 'lf_status' => 'finished', 'lf_home_score' => '', 'lf_away_score' => '' ) );
lfa_check( 'scheduled' === get_post_meta( $score_match, 'lf_status', true ) && '' === get_post_meta( $score_match, 'lf_home_score', true ), 'ADM-03 finished + blank scores is refused without writing' );
lfa_check( (bool) preg_grep( '/needs both scores/', $notices ), 'ADM-03 refusal explains that both scores are needed' );
$save( $score_match, $base + array( 'lf_status' => 'finished', 'lf_home_score' => '3', 'lf_away_score' => '' ) );
lfa_check( 'scheduled' === get_post_meta( $score_match, 'lf_status', true ), 'ADM-03 one blank score is refused' );
foreach ( array( '-1', '2.5', 'abc', '1e2' ) as $bad ) {
	$notices = $save( $score_match, $base + array( 'lf_status' => 'scheduled', 'lf_home_score' => $bad, 'lf_away_score' => '1' ) );
	lfa_check( '' === get_post_meta( $score_match, 'lf_home_score', true ) && (bool) preg_grep( '/whole number/', $notices ), "ADM-03 invalid score '$bad' is refused" );
}
$notices = $save( $score_match, $base + array( 'lf_status' => 'scheduled', 'lf_home_score' => (string) PHP_INT_MAX . '0', 'lf_away_score' => '1' ) );
lfa_check( '' === get_post_meta( $score_match, 'lf_home_score', true ) && (bool) preg_grep( '/largest whole number/', $notices ), 'ADM-03 a score above the storable integer range is refused with the limit named' );
$save( $score_match, $base + array( 'lf_status' => 'finished', 'lf_home_score' => '12345', 'lf_away_score' => (string) PHP_INT_MAX ) );
lfa_check( '12345' === get_post_meta( $score_match, 'lf_home_score', true ) && (string) PHP_INT_MAX === get_post_meta( $score_match, 'lf_away_score', true ), 'ADM-03 large whole-number scores are kept (no invented sport cap)' );
$save( $score_match, $base + array( 'lf_status' => 'finished', 'lf_home_score' => '0', 'lf_away_score' => '0' ) );
lfa_check( 'finished' === get_post_meta( $score_match, 'lf_status', true ) && '0' === get_post_meta( $score_match, 'lf_home_score', true ) && '0' === get_post_meta( $score_match, 'lf_away_score', true ), 'ADM-03 an explicit 0-0 is kept' );
$forfeit = lfa_post( 'lf_match', 'lfa forfeit', 'publish', array( 'lf_status' => 'scheduled', 'lf_home_team_id' => $teams[2], 'lf_away_team_id' => $teams[3] ), $ctx );
$save( $forfeit, array( 'lf_home_team_id' => $teams[2], 'lf_away_team_id' => $teams[3], 'lf_status' => 'finished', 'lf_outcome' => 'forfeit_home', 'lf_home_score' => '', 'lf_away_score' => '' ) );
lfa_check( 'finished' === get_post_meta( $forfeit, 'lf_status', true ) && 'forfeit_home' === get_post_meta( $forfeit, 'lf_outcome', true ) && '' === get_post_meta( $forfeit, 'lf_home_score', true ), 'ADM-03 a forfeit may finish without a score' );
$save( $score_match, $base + array( 'lf_status' => 'scheduled', 'lf_home_score' => '', 'lf_away_score' => '' ) );
lfa_check( 'scheduled' === get_post_meta( $score_match, 'lf_status', true ) && '' === get_post_meta( $score_match, 'lf_home_score', true ), 'ADM-03 a scheduled match may have no score' );
$_POST = wp_slash( array( 'leagueflow_match_nonce' => 'forged0000', 'lf_status' => 'finished', 'lf_home_score' => '9', 'lf_away_score' => '9' ) );
$admin->save_match_meta( $score_match, get_post( $score_match ) );
$_POST = array();
clean_post_cache( $score_match );
lfa_check( '' === get_post_meta( $score_match, 'lf_home_score', true ), 'ADM-03 a forged nonce still writes nothing' );

// ---------------------------------------------------------------------------
// ADM-04 · A blank sport never becomes the first enabled sport (N-03).
// ---------------------------------------------------------------------------
$_POST = array( 'action' => 'add-tag', 'lf_sport_slug' => '' );
$blank = wp_insert_term( 'lfa season without sport', 'lf_season' );
lfa_check( is_wp_error( $blank ) && 'leagueflow_sport_required' === $blank->get_error_code(), 'ADM-04 the add form refuses a season without a sport' );
lfa_check( ! term_exists( 'lfa season without sport', 'lf_season' ), 'ADM-04 no season term was created' );
$_POST = array( 'action' => 'add-tag', 'lf_sport_slug' => 'hockey' );
$made = wp_insert_term( 'lfa hockey season', 'lf_season' );
lfa_check( ! is_wp_error( $made ) && 'hockey' === get_term_meta( $made['term_id'], 'lf_sport_slug', true ), 'ADM-04 a chosen sport is stored' );
$_POST = array( 'lf_sport_slug' => '' );
wp_update_term( $made['term_id'], 'lf_season', array( 'description' => 'edited' ) );
lfa_check( 'hockey' === get_term_meta( $made['term_id'], 'lf_sport_slug', true ), 'ADM-04 a blank edit keeps the stored sport' );
$_POST = array();
wp_update_term( $made['term_id'], 'lf_season', array( 'name' => 'lfa hockey season renamed' ) );
lfa_check( 'hockey' === get_term_meta( $made['term_id'], 'lf_sport_slug', true ), 'ADM-04 Quick Edit / programmatic updates keep the stored sport' );
$programmatic = wp_insert_term( 'lfa programmatic competition', 'lf_competition' );
lfa_check( ! is_wp_error( $programmatic ), 'ADM-04 programmatic inserts are not blocked by the form rule' );
$_POST = array();

// ---------------------------------------------------------------------------
// ADM-05 · Unambiguous selects and accessible names (B-02, N-04, D-02).
// ---------------------------------------------------------------------------
$player = lfa_post( 'lf_player', 'lfa Player One', 'publish', array( 'lf_email' => 'lfa-player@example.test' ), $ctx );
\LeagueFlow\assign_player_to_team( $player, $teams[0], array( 'jersey_number' => 7, 'position' => 'Bowler' ) );
$html = lfa_render( static function () use ( $admin, $player ) { $admin->render_player_metabox( get_post( $player ) ); } );
lfa_check( false !== strpos( $html, 'aria-label="Jersey number for lfa Cricket A"' ) && false !== strpos( $html, 'aria-label="Position for lfa Cricket A"' ), 'ADM-05 membership number and position have accessible names' );
lfa_check( false !== strpos( $html, 'data-leagueflow-searchable' ), 'ADM-05 long user/team selects are searchable' );
$html = lfa_render( static function () use ( $admin, $teams ) { $admin->render_team_metabox( get_post( $teams[0] ) ); } );
lfa_check( (bool) preg_match( '/<select id="lf_league_level_id" name="lf_league_level_id">(?!<option value="">)/', $html ), 'ADM-05 team level select has no blank duplicate first option' );
lfa_check( 1 === substr_count( $html, '>Recreational</option>' ), 'ADM-05 Recreational appears once' );
$html = lfa_render( static function () use ( $admin, $score_match ) { $admin->render_match_metabox( get_post( $score_match ) ); } );
lfa_check( 1 === substr_count( $html, '>Home</option>' ) && false === strpos( $html, '<select id="lf_next_match_slot" name="lf_next_match_slot"><option value="">' ), 'ADM-05 next-match slot shows Home once with no blank option' );
// The scheduling form only renders with an availability rule; use an own rule so the
// check does not depend on another suite having run first on this site.
$adm05_fields = get_option( \LeagueFlow\Field_Availability_Manager::OPTION, null );
update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array( array( 'id' => 'lfa-adm05', 'name' => 'lfa adm05 rule', 'venue' => 'lfa venue', 'sport_slug' => '', 'date' => '', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '20:00', 'slot_minutes' => 60, 'buffer_minutes' => 0, 'active' => true, 'notes' => '' ) ), false );
$html = lfa_render( static function () use ( $admin ) { $_GET = array( 'page' => 'leagueflow-fields' ); $admin->render_field_availability_page(); $_GET = array(); } );
null === $adm05_fields ? delete_option( \LeagueFlow\Field_Availability_Manager::OPTION ) : update_option( \LeagueFlow\Field_Availability_Manager::OPTION, $adm05_fields, false );
lfa_check( 1 === substr_count( $html, '>Set date/time and venue</option>' ), 'ADM-05 schedule mode caption appears once' );
$request = lfa_post( 'lf_join_request', 'lfa placement', 'private', array( 'lf_player_id' => $player, 'lf_team_id' => 0, 'lf_sport_slug' => 'hockey', 'lf_league_level_id' => $level, 'lf_request_status' => 'pending' ) );
$html = lfa_render( static function () use ( $admin, $request ) { $_GET = array( 'page' => 'leagueflow-requests', 'request_id' => $request ); $admin->requests_page(); $_GET = array(); } );
lfa_check( (bool) preg_match( '/<label class="leagueflow-placement-label" for="leagueflow-request-assign-' . $request . '-team">Team<span class="screen-reader-text"> for lfa Player One \(Needs placement · Hockey · Recreational\)<\/span><\/label>\s*<select id="leagueflow-request-assign-' . $request . '-team"/', $html ), 'ADM-05 placement assign select has a visible label with context' );
$html = lfa_render( static function () use ( $admin, $sport ) { $admin->breakdown( $sport['cricket'] ); } );
lfa_check( (bool) preg_match( '/^<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="League level breakdown">/', $html ), 'ADM-05 sport overview level table is in a scroll region' );

// ---------------------------------------------------------------------------
// ADM-06 · Demo data is guarded, previewed and never overwrites (R-01).
// ---------------------------------------------------------------------------
$league_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'league-table', 'post_title' => 'lfa Edited League Table', 'post_content' => '<!-- wp:paragraph --><p>lfa edited content</p><!-- /wp:paragraph -->' ) );
$snapshot    = static function () use ( $league_page ) {
	global $wpdb;
	clean_post_cache( $league_page );
	return array( $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts" ), $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->terms" ), get_post_field( 'post_content', $league_page ), get_post_field( 'post_title', $league_page ) );
};
$before = $snapshot();
add_filter( 'leagueflow_allow_demo_data', '__return_false', 99 );
$result = $seeder->seed_demo_data();
lfa_check( is_wp_error( $result ) && 'leagueflow_demo_environment' === $result->get_error_code(), 'ADM-06 production-like environment refuses demo data' );
lfa_check( $before === $snapshot(), 'ADM-06 refused run writes nothing' );
$html = lfa_render( static function () use ( $admin ) { $admin->render_settings_page(); } );
lfa_check( false === strpos( $html, 'leagueflow_seed_demo' ) && false !== strpos( $html, 'Demo data is turned off on this site' ), 'ADM-06 Settings shows no seeding form outside development' );
remove_filter( 'leagueflow_allow_demo_data', '__return_false', 99 );
add_filter( 'leagueflow_allow_demo_data', '__return_true', 99 );
$plan = $seeder->plan_demo_data();
lfa_check( in_array( 'page:league-table', $plan['conflicts'], true ) && in_array( 'lf_team:existing-league-records', $plan['conflicts'], true ), 'ADM-06 preview lists the edited page and existing league records as conflicts' );
lfa_check( is_wp_error( $seeder->seed_demo_data() ) && $before === $snapshot(), 'ADM-06 conflicts block the run before any write' );
$html = lfa_render( static function () use ( $admin ) { $admin->render_settings_page(); } );
lfa_check( false !== strpos( $html, 'Blocked: this site already has content' ) && false === strpos( $html, 'id="leagueflow-create-demo-data"' ), 'ADM-06 Settings shows the conflicts and no create button' );
lfa_check( 1 === substr_count( $html, 'id="submit"' ), 'ADM-06 Settings has one id="submit" button' );

// Successful run on an otherwise empty league, inside a transaction that is rolled back.
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type IN ('lf_team','lf_player','lf_match','lf_join_request','lf_calendar_event')" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE ID = %d", $league_page ) );
	wp_cache_flush();
	$recreational = get_term_by( 'slug', 'recreational', 'lf_league_level' );
	wp_update_term( $recreational->term_id, 'lf_league_level', array( 'name' => 'lfa Rec renamed' ) );
	// Shared sport terms an editor renamed must survive a permitted seed too.
	$renamed_sports = array();
	foreach ( ( new \LeagueFlow\Sports_Manager() )->get_enabled_sport_slugs() as $enabled_slug ) {
		$sport_term = get_term_by( 'slug', $enabled_slug, 'lf_sport' );
		if ( $sport_term instanceof WP_Term ) {
			wp_update_term( $sport_term->term_id, 'lf_sport', array( 'name' => 'lfa renamed ' . $enabled_slug, 'description' => 'lfa edited description' ) );
			$renamed_sports[ $sport_term->term_id ] = 'lfa renamed ' . $enabled_slug;
		}
	}
	$spring = wp_insert_term( 'lfa Spring League (real)', 'lf_competition', array( 'slug' => 'spring-league' ) );
	$plan   = $seeder->plan_demo_data();
	lfa_check( in_array( 'lf_competition:spring-league', $plan['conflicts'], true ), 'ADM-06 a real same-slug competition is a conflict' );
	wp_delete_term( $spring['term_id'], 'lf_competition' );
	$plan = $seeder->plan_demo_data();
	lfa_check( $plan['ready'] && ! $plan['conflicts'], 'ADM-06 empty development league is ready' );
	lfa_check( is_wp_error( $seeder->seed_demo_data( 'stale-token' ) ), 'ADM-06 a stale preview token is refused' );
	$seeded = $seeder->seed_demo_data( $plan['token'] );
	lfa_check( is_array( $seeded ) && 4 === count( $seeded['team_ids'] ), 'ADM-06 confirmed run creates demo teams' );
	clean_term_cache( $recreational->term_id, 'lf_league_level' );
	lfa_check( 'lfa Rec renamed' === get_term( $recreational->term_id, 'lf_league_level' )->name, 'ADM-06 existing league level is not renamed' );
	$sport_names_kept = (bool) $renamed_sports;
	foreach ( $renamed_sports as $sport_term_id => $renamed_name ) {
		clean_term_cache( $sport_term_id, 'lf_sport' );
		$sport_term       = get_term( $sport_term_id, 'lf_sport' );
		$sport_names_kept = $sport_names_kept && $renamed_name === $sport_term->name && 'lfa edited description' === $sport_term->description;
	}
	lfa_check( $sport_names_kept, 'ADM-06 existing renamed sport terms keep their name and description after seeding' );
	$page = get_page_by_path( 'league-table', OBJECT, 'page' );
	lfa_check( $page && get_post_meta( $page->ID, \LeagueFlow\Seeder::DEMO_META, true ), 'ADM-06 demo page is marked as demo content' );
	// Parent review A: edits to earlier demo objects must survive any rerun.
	$demo_team   = (int) $seeded['team_ids']['northern-wolves'];
	$demo_player = (int) get_page_by_path( 'ethan-cole-northern-wolves', OBJECT, 'lf_player' )->ID;
	$demo_comp   = (int) $seeded['competition_ids']['spring-league'];
	wp_update_post( array( 'ID' => $page->ID, 'post_content' => 'lfa edited demo page' ) );
	wp_update_post( array( 'ID' => $demo_team, 'post_title' => 'lfa Wolves edited' ) );
	update_post_meta( $demo_team, 'lf_coach', 'lfa coach edited' );
	update_post_meta( $demo_player, 'lf_nationality', 'lfa nationality edited' );
	\LeagueFlow\set_player_team_detail( $demo_player, $demo_team, array( 'jersey_number' => 77 ) );
	wp_update_term( $demo_comp, 'lf_competition', array( 'name' => 'lfa Spring edited' ) );
	update_term_meta( $demo_comp, 'lf_sport_slug', 'hockey' );
	$edited = static function () use ( $page, $demo_team, $demo_player, $demo_comp ) {
		clean_post_cache( $page->ID );
		clean_post_cache( $demo_team );
		clean_post_cache( $demo_player );
		clean_term_cache( $demo_comp, 'lf_competition' );
		return array(
			get_post_field( 'post_content', $page->ID ),
			get_post_field( 'post_title', $demo_team ),
			get_post_meta( $demo_team, 'lf_coach', true ),
			get_post_meta( $demo_player, 'lf_nationality', true ),
			\LeagueFlow\get_player_team_detail( $demo_player, $demo_team )['jersey_number'],
			get_term( $demo_comp, 'lf_competition' )->name,
			get_term_meta( $demo_comp, 'lf_sport_slug', true ),
		);
	};
	$edits = $edited();
	lfa_check( array( 'lfa edited demo page', 'lfa Wolves edited', 'lfa coach edited', 'lfa nationality edited', 77, 'lfa Spring edited', 'hockey' ) == $edits, 'ADM-06 fixture edits to demo page/team/player/membership/term applied' );
	$again = $seeder->plan_demo_data();
	lfa_check( ! $again['ready'] && in_array( 'page:league-table (earlier demo item)', $again['conflicts'], true ) && in_array( 'lf_team:existing-league-records', $again['conflicts'], true ), 'ADM-06 earlier demo items block a rerun (no marker-based overwrite)' );
	$teams_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'lf_team'" );
	$rerun        = $seeder->seed_demo_data( $again['token'] );
	lfa_check( is_wp_error( $rerun ) && 'leagueflow_demo_conflicts' === $rerun->get_error_code(), 'ADM-06 rerun with a fresh token is refused' );
	lfa_check( $edits === $edited(), 'ADM-06 every edit to earlier demo objects survives the rerun' );
	lfa_check( $teams_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'lf_team'" ), 'ADM-06 rerun creates nothing' );

	// A collision that appears after the preview stops the run; the real record is never adopted.
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type IN ('lf_team','lf_player','lf_match','lf_join_request','lf_calendar_event') OR ID IN (SELECT post_id FROM (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_leagueflow_demo_seed') AS demo)" );
	foreach ( array( 'lf_competition', 'lf_season' ) as $demo_taxonomy ) {
		foreach ( get_terms( array( 'taxonomy' => $demo_taxonomy, 'hide_empty' => false ) ) as $demo_term ) {
			wp_delete_term( $demo_term->term_id, $demo_taxonomy );
		}
	}
	wp_cache_flush();
	$fresh = $seeder->plan_demo_data();
	lfa_check( $fresh['ready'], 'ADM-06 cleaned development league is ready again' );
	$real_player = 0;
	$intruder    = static function () use ( &$real_player ) {
		if ( ! $real_player ) {
			$real_player = (int) wp_insert_post( array( 'post_type' => 'lf_player', 'post_status' => 'publish', 'post_title' => 'lfa real late player', 'post_name' => 'ethan-cole-northern-wolves' ) );
			update_post_meta( $real_player, 'lf_position', 'lfa real position' );
		}
	};
	add_action( 'save_post_lf_team', $intruder );
	$raced = $seeder->seed_demo_data( $fresh['token'] );
	remove_action( 'save_post_lf_team', $intruder );
	clean_post_cache( $real_player );
	lfa_check( is_wp_error( $raced ) && 'leagueflow_demo_collision' === $raced->get_error_code() && 'lf_player:ethan-cole-northern-wolves' === $raced->get_error_data()['collision'], 'ADM-06 a record arriving after the preview stops the run with a collision' );
	lfa_check( array() === \LeagueFlow\get_player_team_ids( $real_player ) && 'lfa real late player' === get_the_title( $real_player ) && 'lfa real position' === get_post_meta( $real_player, 'lf_position', true ) && ! get_post_meta( $real_player, \LeagueFlow\Seeder::DEMO_META, true ), 'ADM-06 the colliding real player is not assigned, renamed, marked or edited' );
	lfa_check( is_wp_error( $raced ) && (bool) preg_grep( '/^lf_team:\d+$/', $raced->get_error_data()['created'] ), 'ADM-06 the collision report lists what was created (all marked demo)' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	remove_filter( 'leagueflow_allow_demo_data', '__return_true', 99 );
}
lfa_check( $before === $snapshot(), 'ADM-06 rollback restored the edited page and counts' );

// Handler: missing confirmation is refused before seeding.
add_filter( 'leagueflow_allow_demo_data', '__return_true', 99 );
$_POST = $_REQUEST = array( 'action' => 'leagueflow_seed_demo', 'leagueflow_seed_demo_nonce' => wp_create_nonce( 'leagueflow_seed_demo' ), 'leagueflow_demo_token' => $plan['token'] );
$catch = static function ( $location ) { throw new RuntimeException( 'redirect:' . $location ); };
add_filter( 'wp_redirect', $catch, PHP_INT_MIN );
try {
	$admin->handle_seed_demo();
	lfa_check( false, 'ADM-06 handler redirects' );
} catch ( RuntimeException $redirect ) {
	lfa_check( false !== strpos( $redirect->getMessage(), 'leagueflow_seed_refused=confirm' ), 'ADM-06 handler refuses without the confirmation box' );
}
remove_filter( 'wp_redirect', $catch, PHP_INT_MIN );
remove_filter( 'leagueflow_allow_demo_data', '__return_true', 99 );
$_POST = $_REQUEST = array();
lfa_check( $before === $snapshot(), 'ADM-06 refused handler writes nothing' );

// ---------------------------------------------------------------------------
// ADM-07 · Context counts disclose only what the same user sees in the core list (review B).
// ---------------------------------------------------------------------------
$stale_author = get_user_by( 'login', 'lfa_author' );
if ( $stale_author ) {
	wp_delete_user( $stale_author->ID );
}
$author_id = (int) wp_insert_user( array( 'user_login' => 'lfa_author', 'user_pass' => wp_generate_password(), 'user_email' => 'lfa-author@example.test', 'role' => 'author' ) );
$private_players = array();
foreach ( array( 1, 2, 3 ) as $n ) {
	$private_players[] = lfa_post( 'lf_player', 'lfa private player ' . $n, 'private', array(), $ctx );
}
wp_set_current_user( $author_id );
$own_private = lfa_post( 'lf_player', 'lfa author private', 'private', array(), $ctx );
$_GET = array( 'post_type' => 'lf_player', 'lf_sport' => 'cricket' );
$visible = lfa_list( 'lf_player', array( 'lf_sport' => 'cricket' ) );
$_GET = array( 'post_type' => 'lf_player', 'lf_sport' => 'cricket' );
lfa_check( $admin->count_views( 'lf_player', 'all' ) === count( $visible ), 'ADM-07 author All count equals the rows the author can list' );
lfa_check( 1 === $admin->count_views( 'lf_player', 'private' ), 'ADM-07 author Private count includes only their own private player' );
$GLOBALS['typenow'] = 'lf_player';
$views = lfa_views( $admin, array( 'all' => 'x', 'mine' => 'y' ) );
lfa_check( isset( $views['private'] ) && false !== strpos( $views['private'], '(1)' ), 'ADM-07 author Private view shows (1), not other users\' private records' );
lfa_check( isset( $views['mine'] ) && false !== strpos( $views['mine'], 'author=' . $author_id ) && false !== strpos( $views['mine'], 'lf_sport=cricket' ), 'ADM-07 Mine view is kept, counted in context' );
lfa_check( ! in_array( $private_players[0], $visible, true ), 'ADM-07 core list hides other users\' private players from the author' );
wp_set_current_user( $admin_id );
$_GET = array( 'post_type' => 'lf_player', 'lf_sport' => 'cricket' );
lfa_check( $admin->count_views( 'lf_player', 'private' ) >= 4, 'ADM-07 administrator still counts every private player' );
$_GET = array();

// ---------------------------------------------------------------------------
// ADM-08 · Shared screens, enabled sport menus, context and legacy aliases (B-01, C-05, C-06, N-06, C-10, C-14, U-06).
// ---------------------------------------------------------------------------
global $menu, $submenu, $_registered_pages, $pagenow, $typenow, $taxnow;
$menu = array(); $submenu = array();
$admin->register_menus();
$slugs = wp_list_pluck( $submenu['leagueflow'] ?? array(), 2 );
lfa_check( array( 'leagueflow', 'leagueflow-setup', 'edit.php?post_type=lf_team', 'edit.php?post_type=lf_player', 'leagueflow-requests', 'edit.php?post_type=lf_match', 'leagueflow-results', 'leagueflow-fixtures', 'leagueflow-fields', 'edit.php?post_type=lf_calendar_event', 'leagueflow-standings', 'leagueflow-brackets', 'edit-tags.php?taxonomy=lf_competition&post_type=lf_match', 'edit-tags.php?taxonomy=lf_season&post_type=lf_match', 'edit-tags.php?taxonomy=lf_league_level&post_type=lf_match', 'leagueflow-sports', 'leagueflow-utilities', 'leagueflow-settings' ) === array_values( $slugs ), 'ADM-08 one LeagueFlow menu with 18 screens in workflow order' );
lfa_check( 1 + count( $sports->get_enabled_sport_slugs() ) === count( array_filter( wp_list_pluck( $menu, 2 ), static function ( $slug ) { return 0 === strpos( (string) $slug, 'leagueflow' ); } ) ), 'ADM-08 each enabled sport has a top-level menu' );
foreach ( $sports->get_enabled_sport_slugs() as $sport_slug ) {
	$sport_links = wp_list_pluck( $submenu[ 'leagueflow-sport-' . $sport_slug ] ?? array(), 2 );
	lfa_check( in_array( 'edit.php?post_type=lf_match&lf_sport=' . $sport_slug, $sport_links, true ), 'ADM-08 sport menu opens its filtered Matches list' );
	lfa_check( false === strpos( implode( '', wp_list_pluck( $submenu[ 'leagueflow-sport-' . $sport_slug ], 0 ) ), 'awaiting-mod' ), 'ADM-08 sport menus do not repeat the global pending badge' );
}
lfa_check( ! in_array( 'leagueflow-sport-rugby', wp_list_pluck( $menu, 2 ), true ), 'ADM-08 a disabled sport has no sidebar entry' );
$_GET = array( 'page' => 'leagueflow-sport-cricket' );
$overview_bar = lfa_render( array( $admin, 'render_context_bar' ) );
lfa_check( false !== strpos( $overview_bar, 'aria-current="page">Overview' ) && false !== strpos( $overview_bar, 'leagueflow-sport-switch' ), 'ADM-08 sport overview has its breadcrumb label and switcher' );
$_GET = array();
lfa_check( ! preg_grep( '/^leagueflow-sport-|^post-new\.php|^leagueflow-placements$/', $slugs ), 'ADM-08 no per-sport, Add or Placements clutter in the sidebar' );
lfa_check( false !== strpos( implode( '', wp_list_pluck( $submenu['leagueflow'], 0 ) ), 'League Levels (all sports)' ), 'ADM-08 shared League Levels are labelled global' );
$legacy_hook = get_plugin_page_hookname( 'leagueflow-sport-cricket-fixtures', '' );
lfa_check( isset( $_registered_pages[ $legacy_hook ] ) && has_action( 'load-' . $legacy_hook ), 'ADM-08 legacy per-sport page slug stays registered as a redirecting alias' );
$_GET = array( 'page' => 'leagueflow-sport-cricket-fixtures', 'competition' => '5', 'season' => '7' );
$target = $admin->get_legacy_alias_target( 'leagueflow-fixtures', array( 'lf_sport' => 'cricket' ) );
lfa_check( false !== strpos( $target, 'page=leagueflow-fixtures' ) && false !== strpos( $target, 'lf_sport=cricket' ) && false !== strpos( $target, 'competition=5' ) && false !== strpos( $target, 'season=7' ), 'ADM-08 legacy alias keeps sport and filters' );
$_GET = array();
wp_set_current_user( $author_id );
$menu = array(); $submenu = array();
$admin->register_menus();
$author_slugs = wp_list_pluck( $submenu['leagueflow'] ?? array(), 2 );
lfa_check( ! in_array( 'leagueflow-requests', $author_slugs, true ) && ! in_array( 'leagueflow-settings', $author_slugs, true ) && in_array( 'edit.php?post_type=lf_team', $author_slugs, true ), 'ADM-08 menu items keep their capabilities' );
wp_set_current_user( $admin_id );

// Edit screen keeps the record's sport (B-01).
$pagenow = 'post.php'; $typenow = 'lf_team'; $GLOBALS['post_type'] = 'lf_team';
$_GET = array( 'post' => $teams[0], 'action' => 'edit' ); $_REQUEST = $_GET;
lfa_check( 'leagueflow-sport-cricket' === $admin->filter_admin_menu_parent_file( 'edit.php' ) && 'edit.php?post_type=lf_team&lf_sport=cricket' === $admin->filter_admin_menu_submenu_file( '', 'leagueflow-sport-cricket' ), 'ADM-08 team edit screen highlights its sport > Teams' );
$bar = lfa_render( array( $admin, 'render_context_bar' ) );
lfa_check( false !== strpos( $bar, 'Back to Teams (Cricket)' ) && false !== strpos( $bar, 'post_type=lf_team&#038;lf_sport=cricket' ), 'ADM-08 edit screen links back to the list in the record\'s sport' );
lfa_check( false !== strpos( $admin->filter_add_new_url( admin_url( 'post-new.php?post_type=lf_team' ), 'post-new.php?post_type=lf_team' ), 'lf_sport=cricket' ), 'ADM-08 Add New keeps the record\'s sport' );
lfa_check( false === strpos( $admin->filter_add_new_url( admin_url( 'post-new.php?post_type=post' ), 'post-new.php?post_type=post' ), 'lf_sport' ), 'ADM-08 unrelated Add New links are untouched' );
$typenow = 'lf_join_request'; $GLOBALS['post_type'] = 'lf_join_request';
$_GET = array( 'post' => $request, 'action' => 'edit' ); $_REQUEST = $_GET;
lfa_check( 'leagueflow-requests' === $admin->filter_admin_menu_submenu_file( '', 'leagueflow' ), 'ADM-08 a request record belongs to LeagueFlow > Requests' );
$pagenow = 'edit-tags.php'; $typenow = 'lf_match'; $GLOBALS['post_type'] = 'lf_match'; $taxnow = 'lf_league_level'; $GLOBALS['taxonomy'] = 'lf_league_level';
$_GET = array( 'taxonomy' => 'lf_league_level', 'post_type' => 'lf_match', 'lf_sport' => 'cricket' ); $_REQUEST = $_GET;
$bar = lfa_render( array( $admin, 'render_context_bar' ) );
lfa_check( false !== strpos( $bar, 'shared by every sport' ) && false === strpos( $bar, 'leagueflow-sport-switch' ), 'ADM-08 League Levels screen says the list is shared and offers no sport filter' );
$taxnow = ''; $GLOBALS['taxonomy'] = '';
$pagenow = 'edit.php'; $typenow = 'lf_player'; $GLOBALS['post_type'] = 'lf_player';
$_GET = array( 'post_type' => 'lf_player', 'lf_sport' => 'cricket', 'paged' => '3' ); $_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=lf_player&lf_sport=cricket&paged=3';
$bar = lfa_render( array( $admin, 'render_context_bar' ) );
lfa_check( (bool) preg_match( '/<a href="[^"]*post_type=lf_player"[^>]*>All sports<\/a>/', $bar ) && (bool) preg_match( '/<a href="[^"]*lf_sport=hockey"[^>]*>Hockey<\/a>/', $bar ) && (bool) preg_match( '/class="current" aria-current="true">Cricket</', $bar ) && false === strpos( $bar, 'paged=3' ), 'ADM-08 sport switcher keeps the screen, marks the current sport and resets paging' );
$hockey_member = lfa_post( 'lf_player', 'lfa two sports', 'publish', array(), $ctx );
\LeagueFlow\assign_player_to_team( $hockey_member, $hockey_team, array( 'jersey_number' => 9 ) );
\LeagueFlow\assign_player_to_team( $hockey_member, $teams[3], array( 'jersey_number' => 4 ) );
$cells = array();
foreach ( array( 'sport', 'team', 'jersey_number' ) as $column ) {
	$cells[ $column ] = lfa_render( static function () use ( $admin, $column, $hockey_member ) { $admin->render_player_column( $column, $hockey_member ); } );
}
lfa_check( 'lfa Cricket D' === $cells['team'] && '4' === $cells['jersey_number'] && 0 === strpos( $cells['sport'], 'Cricket' ) && false !== strpos( $cells['sport'], 'also Hockey' ), 'ADM-08 Players in a sport show that sport\'s team and number only (C-06)' );
$_GET = array( 'post_type' => 'lf_player' );
$cell = lfa_render( static function () use ( $admin, $hockey_member ) { $admin->render_player_column( 'team', $hockey_member ); } );
lfa_check( false !== strpos( $cell, 'lfa Cricket D' ) && false !== strpos( $cell, 'lfa Hockey team' ), 'ADM-08 All sports shows every membership' );
$hidden = $admin->filter_default_hidden_columns( array(), convert_to_screen( 'edit-lf_match' ) );
lfa_check( array( 'competition', 'season', 'knockout' ) === $hidden, 'ADM-08 secondary Matches columns hidden by default (Screen Options can show them)' );
global $wp_meta_boxes;
foreach ( array( 'lf_team', 'lf_match', 'lf_calendar_event' ) as $box_type ) {
	add_meta_box( 'tagsdiv-lf_league_level', 'Levels', '__return_null', $box_type, 'side' );
}
add_meta_box( 'tagsdiv-lf_sport', 'Sports', '__return_null', 'lf_match', 'side' );
add_meta_box( 'tagsdiv-lf_sport', 'Sports', '__return_null', 'lf_team', 'side' );
$admin->remove_duplicate_taxonomy_boxes();
lfa_check( false === $wp_meta_boxes['lf_match']['side']['core']['tagsdiv-lf_league_level'] && false === $wp_meta_boxes['lf_match']['side']['core']['tagsdiv-lf_sport'] && false === $wp_meta_boxes['lf_team']['side']['core']['tagsdiv-lf_league_level'], 'ADM-08 duplicate level/sport boxes removed from match/team edit (U-06)' );
lfa_check( false === $wp_meta_boxes['lf_team']['side']['default']['tagsdiv-lf_sport'], 'ADM-08 the team sport tag box is replaced by the Team Details sport control (ADM-15)' );
lfa_check( 0 === strpos( get_taxonomy( 'lf_season' )->labels->not_found, 'No seasons for this sport yet' ), 'ADM-08 empty Seasons screen explains the next step (C-10)' );
lfa_check( ! current_user_can( get_post_type_object( 'lf_join_request' )->cap->create_posts ), 'ADM-08 empty request records cannot be created by hand (U-01)' );
$pagenow = 'admin.php'; $typenow = ''; $GLOBALS['post_type'] = '';
$_GET = array( 'page' => 'leagueflow-fixtures' );
$html = lfa_render( array( $admin, 'render_fixtures_page' ) );
lfa_check( false === strpos( $html, 'leagueflow_generate_fixtures' ) && false !== strpos( $html, 'page=leagueflow-fixtures&#038;lf_sport=cricket' ), 'ADM-08 Generate fixtures asks for a sport instead of defaulting to the first one' );
$_GET = array( 'page' => 'leagueflow-fixtures', 'lf_sport' => 'cricket' );
$html = lfa_render( array( $admin, 'render_fixtures_page' ) );
lfa_check( false !== strpos( $html, 'Generate Cricket fixtures' ) && false !== strpos( $html, 'name="lf_sport" value="cricket"' ), 'ADM-08 Generate fixtures in a sport context' );
$_GET = array( 'page' => 'leagueflow-utilities' );
$html = lfa_render( array( $admin, 'render_utilities_page' ) );
lfa_check( false === strpos( $html, 'Ready for future utilities' ), 'ADM-08 Utilities has no placeholder row (C-14)' );
$_GET = array( 'page' => 'leagueflow', 'lf_sport' => 'cricket' );
$html = lfa_render( array( $admin, 'render_dashboard_page' ) );
lfa_check( false !== strpos( $html, 'Cricket overview' ) && false !== strpos( $html, 'Needs attention' ) && false !== strpos( $html, 'lf_schedule=unscheduled' ) && false !== strpos( $html, 'post-new.php?post_type=lf_team&#038;lf_sport=cricket' ), 'ADM-08 sport Overview has the attention queue and in-context Add links (C-11)' );
$setup_required = get_option( 'leagueflow_sport_setup_required', 1 );
update_option( 'leagueflow_sport_setup_required', 0 );
$_GET = array( 'page' => 'leagueflow' );
$html = lfa_render( array( $admin, 'render_dashboard_page' ) );
update_option( 'leagueflow_sport_setup_required', $setup_required );
lfa_check( false !== strpos( $html, 'LeagueFlow overview' ) && false !== strpos( $html, 'page=leagueflow&#038;lf_sport=cricket' ), 'ADM-08 global Overview links each sport' );
$_GET = array();

// ---------------------------------------------------------------------------
// ADM-09 · Requests inbox: guarded review, flags, reconciliation (C-02, C-03, U-01, U-02, C-12).
// ---------------------------------------------------------------------------
$service   = $admin->requests()->service();
$competitive = (int) get_term_by( 'slug', 'competitive', 'lf_league_level' )->term_id;
$team_comp   = lfa_post( 'lf_team', 'lfa Cricket Competitive', 'publish', array(), array( 'lf_sport' => array( $sport['cricket'] ), 'lf_league_level' => array( $competitive ) ) );
$team_trash  = lfa_post( 'lf_team', 'lfa Cricket Trashed Target', 'publish', array(), $ctx );
wp_trash_post( $team_trash );
$team_draft  = lfa_post( 'lf_team', 'lfa Cricket Draft Target', 'draft', array(), $ctx );
$team_priv   = lfa_post( 'lf_team', 'lfa Cricket Private Team', 'private', array(), $ctx );
$players     = array();
foreach ( range( 1, 10 ) as $n ) {
	$players[ $n ] = lfa_post( 'lf_player', 'lfa req player ' . $n, 'publish', array( 'lf_email' => "lfa-req-$n@example.test" ), $ctx );
}
$req = static function ( $player_id, $team_id, $sport_slug = 'cricket', $level_id = 0, $status = 'pending' ) use ( $level ) {
	return lfa_post( 'lf_join_request', 'lfa request', 'private', array( 'lf_player_id' => $player_id, 'lf_team_id' => $team_id, 'lf_sport_slug' => $sport_slug, 'lf_league_level_id' => $level_id ? $level_id : $level, 'lf_request_status' => $status ) );
};
$r_join    = $req( $players[1], $teams[1] );
$r_cross   = $req( $players[2], $hockey_team );
$r_trash   = $req( $players[3], $team_trash );
$r_missing = $req( $players[4], 999999 );
$r_ghost   = $req( 999998, 0 );
\LeagueFlow\assign_player_to_team( $players[5], $teams[2] );
$r_member  = $req( $players[5], $teams[2] );
$r_place   = $req( $players[6], 0 );
\LeagueFlow\assign_player_to_team( $players[7], $hockey_team );
$r_decline = $req( $players[7], $teams[0] );
$r_draft   = $req( $players[9], $team_draft );
$info = $service->inspect( $r_cross );
lfa_check( in_array( 'sport_mismatch', $info['flags'], true ) && ! in_array( 'approve', $service->available_actions( $info ), true ), 'ADM-09 cross-sport target is flagged and cannot be approved' );
lfa_check( array( 'team_trashed' ) === $service->inspect( $r_trash )['flags'] && array( 'team_missing' ) === $service->inspect( $r_missing )['flags'] && array( 'team_not_public' ) === $service->inspect( $r_draft )['flags'], 'ADM-09 trashed, missing and draft targets are flagged distinctly' );
lfa_check( in_array( 'approve', $service->available_actions( $service->inspect( $r_draft ) ), true ) && in_array( 'team_not_public', \LeagueFlow\Join_Request_Service::warning_flags(), true ), 'ADM-09 F4: a draft team the reviewer can edit stays approvable, with a warning (not treated as nonexistent)' );
$r_trash_member = $req( $players[3], $team_trash );
\LeagueFlow\set_player_team_ids( $players[3], array( $team_trash ) );
$trash_info = $service->inspect( $r_trash_member );
lfa_check( in_array( 'already_member', $trash_info['flags'], true ) && ! in_array( 'resolve_member', $service->available_actions( $trash_info ), true ) && in_array( 'move_to_placement', $service->available_actions( $trash_info ), true ), 'ADM-09 F3: membership meta pointing at a trashed target is not treated as satisfied' );
\LeagueFlow\set_player_team_ids( $players[3], array() );
lfa_check( array( 'dismiss' ) === $service->available_actions( $service->inspect( $r_ghost ) ), 'ADM-09 a request without a player can only be dismissed' );
lfa_check( in_array( 'already_member', $service->inspect( $r_member )['flags'], true ) && in_array( 'resolve_member', $service->available_actions( $service->inspect( $r_member ) ), true ) && ! in_array( 'approve', $service->available_actions( $service->inspect( $r_member ) ), true ), 'ADM-09 already-member request offers a reviewed close, not approve' );
lfa_check( 'pending' === get_post_meta( $r_member, 'lf_request_status', true ), 'ADM-09 inspecting (page view) never rewrites a stale request' );

// Handler guards.
$post_action = static function ( $request_id, $action, $nonce, $extra = array() ) use ( $admin ) {
	$_POST    = wp_slash( array_merge( array( 'action' => 'leagueflow_request_action', 'lf_join_request_id' => $request_id, 'lf_request_action' => $action, 'leagueflow_request_nonce' => $nonce ), $extra ) );
	$_REQUEST = $_POST;
	$result   = lfa_handle( array( $admin->requests(), 'handle_action' ) );
	$_POST    = array();
	$_REQUEST = array();
	clean_post_cache( $request_id );
	return $result;
};
lfa_check( false !== strpos( $post_action( $r_join, 'approve', '' ), 'leagueflow_request_notice=invalid' ) && 'pending' === get_post_meta( $r_join, 'lf_request_status', true ), 'ADM-09 missing nonce is refused without change' );
lfa_check( false !== strpos( $post_action( $r_join, 'approve', 'forged0000' ), 'notice=invalid' ), 'ADM-09 forged nonce is refused' );
lfa_check( false !== strpos( $post_action( $r_join, 'approve', wp_create_nonce( 'leagueflow_request_decline_' . $r_join ) ), 'notice=invalid' ), 'ADM-09 a nonce for another action is refused' );
lfa_check( false !== strpos( $post_action( $r_join, 'approve', wp_create_nonce( 'leagueflow_request_approve_' . $r_cross ) ), 'notice=invalid' ), 'ADM-09 a nonce for another request is refused' );
wp_set_current_user( $author_id );
$other_user_nonce = wp_create_nonce( 'leagueflow_request_approve_' . $r_join );
wp_set_current_user( $admin_id );
lfa_check( false !== strpos( $post_action( $r_join, 'approve', $other_user_nonce ), 'notice=invalid' ), 'ADM-09 another user\'s nonce is refused' );
lfa_check( false !== strpos( $post_action( $teams[0], 'approve', wp_create_nonce( 'leagueflow_request_approve_' . $teams[0] ) ), 'notice=denied' ), 'ADM-09 a forged non-request ID is refused' );
wp_set_current_user( $author_id );
lfa_check( 'die:403' === $post_action( $r_join, 'approve', wp_create_nonce( 'leagueflow_request_approve_' . $r_join ) ), 'ADM-09 a role without placement management is refused with 403' );
wp_set_current_user( $admin_id );
lfa_check( 'pending' === get_post_meta( $r_join, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $players[1], $teams[1] ), 'ADM-09 refused attempts changed nothing' );
$approve_nonce = wp_create_nonce( 'leagueflow_request_approve_' . $r_join );
$join_state    = $service->state_token( $service->inspect( $r_join ) );
$done = $post_action( $r_join, 'approve', $approve_nonce, array( 'lf_notify' => '1', 'lf_request_state' => $join_state ) );
lfa_check( false !== strpos( $done, 'notice=assigned' ) && 'approved' === get_post_meta( $r_join, 'lf_request_status', true ) && \LeagueFlow\player_has_team( $players[1], $teams[1] ), 'ADM-09 a valid approval adds the player and closes the request' );
$history = $service->get_history( $r_join );
lfa_check( 1 === count( $history ) && 'approved' === $history[0]['event'] && $admin_id === $history[0]['user_id'], 'ADM-09 approval is recorded once in the history (no duplicate reconcile entry)' );
$again = $post_action( $r_join, 'approve', $approve_nonce, array( 'lf_request_state' => $join_state ) );
lfa_check( false !== strpos( $again, 'leagueflow_request_error=leagueflow_request_state' ) && 1 === count( $service->get_history( $r_join ) ), 'ADM-09 a double submission is refused and records nothing' );

// Service rules.
lfa_check( 'leagueflow_request_reason' === $service->apply_action( $r_ghost, 'dismiss' )->get_error_code(), 'ADM-09 dismiss needs a reason' );
lfa_check( true === $service->apply_action( $r_ghost, 'dismiss', array( 'note' => 'lfa no player record' ) ) && 'dismissed' === get_post_meta( $r_ghost, 'lf_request_status', true ), 'ADM-09 missing-player request is dismissed; no fake player created' );
lfa_check( 0 === (int) get_post_meta( $r_ghost, 'lf_player_id', true ) - 999998 && ! get_post( 999998 ), 'ADM-09 dismissing does not create a player' );
lfa_check( is_wp_error( $service->apply_action( $r_cross, 'approve' ) ), 'ADM-09 cross-sport approve is refused' );
lfa_check( true === $service->apply_action( $r_trash, 'move_to_placement', array( 'note' => 'lfa team was trashed' ) ) && 0 === (int) get_post_meta( $r_trash, 'lf_team_id', true ) && $team_trash === (int) get_post_meta( $r_trash, 'lf_original_team_id', true ) && 'pending' === get_post_meta( $r_trash, 'lf_request_status', true ), 'ADM-09 a trashed target can be moved to Needs placement, keeping the original team' );
lfa_check( 'publish' !== get_post_status( $team_trash ) && 'trash' === get_post_status( $team_trash ), 'ADM-09 the trashed team itself is untouched' );
lfa_check( true === $service->apply_action( $r_member, 'resolve_member' ) && 'approved' === get_post_meta( $r_member, 'lf_request_status', true ) && array( $teams[2] ) === \LeagueFlow\get_player_team_ids( $players[5] ), 'ADM-09 resolving an already-member request leaves the roster unchanged' );
lfa_check( is_wp_error( $service->apply_action( $r_place, 'assign', array( 'team_id' => $team_comp ) ) ) && is_wp_error( $service->apply_action( $r_place, 'assign', array( 'team_id' => $hockey_team ) ) ) && is_wp_error( $service->apply_action( $r_place, 'assign', array( 'team_id' => $team_trash ) ) ), 'ADM-09 placement refuses another level, another sport and a trashed team' );
$assignable = wp_list_pluck( $service->get_assignable_teams( $service->inspect( $r_place ) ), 'ID' );
lfa_check( in_array( $team_priv, $assignable, true ) && in_array( $team_draft, $assignable, true ) && ! in_array( $team_trash, $assignable, true ), 'ADM-09 F4: private and editable draft teams are placement targets; trashed teams are not' );
lfa_check( true === $service->apply_action( $r_place, 'assign', array( 'team_id' => $teams[3] ) ) && \LeagueFlow\player_has_team( $players[6], $teams[3] ) && $teams[3] === (int) get_post_meta( $r_place, 'lf_team_id', true ), 'ADM-09 placement assigns an eligible team' );
lfa_check( true === $service->apply_action( $r_decline, 'decline', array( 'note' => 'lfa roster full' ) ) && 'declined' === get_post_meta( $r_decline, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $players[7], $teams[0] ) && \LeagueFlow\player_has_team( $players[7], $hockey_team ), 'ADM-09 declining adds nobody and removes no existing membership' );
lfa_check( 'lfa roster full' === array_slice( $service->get_history( $r_decline ), -1 )[0]['note'], 'ADM-09 decline reason is kept in the history' );

// Reconciliation only from explicit, authorized roster entry points (F1).
$r_rec       = $req( $players[8], $teams[0] );
$r_unrelated = $req( $players[8], 0, 'hockey' );
$before_rec  = \LeagueFlow\get_player_team_ids( $players[8] );
\LeagueFlow\assign_player_to_team( $players[8], $teams[0] );
\LeagueFlow\set_player_team_ids( $players[8], \LeagueFlow\get_player_team_ids( $players[8] ) );
\LeagueFlow\ensure_player_team_details_migration();
clean_post_cache( $r_rec );
lfa_check( 'pending' === get_post_meta( $r_rec, 'lf_request_status', true ) && array() === $service->get_history( $r_rec ), 'ADM-09 F1: generic/migration/import-style membership setters leave a matching pending request unchanged' );
\LeagueFlow\reconcile_roster_additions( $players[8], $before_rec, 'admin_player' );
clean_post_cache( $r_rec );
lfa_check( 'approved' === get_post_meta( $r_rec, 'lf_request_status', true ) && 1 === count( $service->get_history( $r_rec ) ) && 'reconciled' === $service->get_history( $r_rec )[0]['event'], 'ADM-09 an authorized roster entry point closes the matching pending request once (C-02)' );
lfa_check( 'pending' === get_post_meta( $r_unrelated, 'lf_request_status', true ), 'ADM-09 the player\'s request in another sport stays pending' );
\LeagueFlow\reconcile_roster_additions( $players[8], $before_rec, 'admin_player' );
lfa_check( 1 === count( $service->get_history( $r_rec ) ), 'ADM-09 reconciliation is idempotent' );

// Admin player save is an explicit entry point.
$p_save  = lfa_post( 'lf_player', 'lfa save player', 'publish', array(), $ctx );
$r_save  = $req( $p_save, $teams[2] );
$_POST   = wp_slash( array( 'leagueflow_player_nonce' => wp_create_nonce( 'leagueflow_save_player' ), 'lf_new_team_id' => $teams[2], 'lf_player_memberships' => array() ) );
$admin->save_player_meta( $p_save, get_post( $p_save ) );
$_POST   = array();
clean_post_cache( $r_save );
lfa_check( \LeagueFlow\player_has_team( $p_save, $teams[2] ) && 'approved' === get_post_meta( $r_save, 'lf_request_status', true ) && 1 === count( $service->get_history( $r_save ) ), 'ADM-09 F1: adding a team on the player edit screen reconciles the matching request once' );

// F2: every reconciliation needs matching type/status/sport/level.
$p_f2      = lfa_post( 'lf_player', 'lfa f2 player', 'publish', array(), $ctx );
$r_f2_sp   = $req( $p_f2, $teams[3], 'hockey' );
$r_f2_lvl  = $req( $p_f2, $teams[3], 'cricket', $competitive );
$r_f2_odd  = $req( $p_f2, $teams[3], 'cricket', 0, 'archived' );
$r_f2_none = $req( $p_f2, 0 );
delete_post_meta( $r_f2_none, 'lf_request_status' );
$before_f2 = \LeagueFlow\get_player_team_ids( $p_f2 );
\LeagueFlow\assign_player_to_team( $p_f2, $teams[3] );
\LeagueFlow\reconcile_roster_additions( $p_f2, $before_f2, 'admin_player' );
foreach ( array( $r_f2_sp, $r_f2_lvl, $r_f2_odd, $r_f2_none ) as $f2_id ) {
	clean_post_cache( $f2_id );
}
lfa_check( 'pending' === get_post_meta( $r_f2_sp, 'lf_request_status', true ) && 'pending' === get_post_meta( $r_f2_lvl, 'lf_request_status', true ), 'ADM-09 F2: a named request for the same team ID but a conflicting sport or level is not reconciled' );
lfa_check( 'archived' === get_post_meta( $r_f2_odd, 'lf_request_status', true ) && '' === get_post_meta( $r_f2_none, 'lf_request_status', true ), 'ADM-09 F2: unknown and absent legacy statuses are never reconciled' );
$odd_info = $service->inspect( $r_f2_odd );
lfa_check( 'unknown' === $odd_info['status'] && in_array( 'status_unknown', $odd_info['flags'], true ) && array( 'reopen', 'dismiss' ) === $service->available_actions( $odd_info ), 'ADM-09 F2: an unknown status is flagged for review, not offered as a normal pending approval' );
lfa_check( 'unknown' === $service->inspect( $r_f2_none )['status'] && is_wp_error( $service->apply_action( $r_f2_none, 'assign', array( 'team_id' => $teams[0] ) ) ), 'ADM-09 F2: an absent status cannot be approved or assigned' );
lfa_check( 'leagueflow_request_reason' === $service->apply_action( $r_f2_odd, 'reopen' )->get_error_code() && true === $service->apply_action( $r_f2_odd, 'reopen', array( 'note' => 'lfa checked' ) ) && 'pending' === get_post_meta( $r_f2_odd, 'lf_request_status', true ) && 'archived' === get_post_meta( $r_f2_odd, 'lf_previous_request_status', true ) && 'reopened' === $service->get_history( $r_f2_odd )[0]['event'], 'ADM-09 F2: reopening needs a reason and keeps the old value' );

// F3: placement resolve needs one unambiguous membership in the requested sport and level.
$p_f3      = lfa_post( 'lf_player', 'lfa f3 player', 'publish', array(), $ctx );
\LeagueFlow\assign_player_to_team( $p_f3, $team_comp );
$r_f3      = $req( $p_f3, 0 );
$f3_info   = $service->inspect( $r_f3 );
lfa_check( in_array( 'membership_conflict', $f3_info['flags'], true ) && ! in_array( 'resolve_member', $service->available_actions( $f3_info ), true ) && is_wp_error( $service->apply_action( $r_f3, 'resolve_member' ) ), 'ADM-09 F3: a team in another division does not close a placement request' );
$p_f3b     = lfa_post( 'lf_player', 'lfa f3b player', 'publish', array(), $ctx );
\LeagueFlow\set_player_team_ids( $p_f3b, array( $teams[0], $teams[1] ) );
$r_f3b     = $req( $p_f3b, 0 );
lfa_check( in_array( 'membership_conflict', $service->inspect( $r_f3b )['flags'], true ) && is_wp_error( $service->apply_action( $r_f3b, 'resolve_member' ) ), 'ADM-09 F3: two memberships in the requested division are ambiguous and need a choice' );
$p_f3c     = lfa_post( 'lf_player', 'lfa f3c player', 'publish', array(), $ctx );
\LeagueFlow\assign_player_to_team( $p_f3c, $teams[1] );
$r_f3c     = $req( $p_f3c, 0 );
lfa_check( true === $service->apply_action( $r_f3c, 'resolve_member' ) && $teams[1] === (int) get_post_meta( $r_f3c, 'lf_team_id', true ), 'ADM-09 F3: exactly one matching membership closes the placement request' );

// F5: authority is enforced by the shared service, not only the screen handler.
$r_f5 = $req( $players[9], 0 );
wp_set_current_user( $author_id );
lfa_check( 'leagueflow_request_denied' === $service->apply_action( $r_f5, 'dismiss', array( 'note' => 'x' ) )->get_error_code() && 'leagueflow_request_denied' === $service->apply_action( $r_f5, 'assign', array( 'team_id' => $teams[0] ) )->get_error_code(), 'ADM-09 F5: an unauthorized user calling the service directly is refused' );
$author_user = wp_get_current_user();
$author_user->add_cap( 'leagueflow_manage_placements' );
lfa_check( 'leagueflow_request_denied' === $service->apply_action( $r_f5, 'decline', array( 'note' => 'x' ) )->get_error_code(), 'ADM-09 F5: the placement capability alone is not enough without edit rights on the request' );
lfa_check( ! in_array( $team_priv, wp_list_pluck( $service->get_assignable_teams( $service->inspect( $r_f5 ) ), 'ID' ), true ), 'ADM-09 F4: teams the user cannot edit are not offered (no private title leak)' );
$author_user->remove_cap( 'leagueflow_manage_placements' );
wp_set_current_user( $admin_id );
clean_post_cache( $r_f5 );
lfa_check( 'pending' === get_post_meta( $r_f5, 'lf_request_status', true ) && array() === $service->get_history( $r_f5 ), 'ADM-09 F5: refused service calls wrote nothing' );

// F6: one shared instance, exact history, stale forms and a concurrent reviewer.
global $wp_filter;
$roster_callbacks = isset( $wp_filter['leagueflow_roster_memberships_added'] ) ? count( $wp_filter['leagueflow_roster_memberships_added']->callbacks[10] ?? array() ) : 0;
( new \LeagueFlow\Join_Request_Service() )->register();
$roster_callbacks_after = isset( $wp_filter['leagueflow_roster_memberships_added'] ) ? count( $wp_filter['leagueflow_roster_memberships_added']->callbacks[10] ?? array() ) : 0;
lfa_check( $service === \LeagueFlow\Join_Request_Service::shared() && 1 === $roster_callbacks && 1 === $roster_callbacks_after, 'ADM-09 F6: admin and hooks share one service instance; a second instance cannot register a duplicate reconciliation' );
$p_f6   = lfa_post( 'lf_player', 'lfa f6 player', 'publish', array(), $ctx );
$r_f6a  = $req( $p_f6, $teams[0] );
$r_f6b  = $req( $p_f6, 0 );
$token  = $service->state_token( $service->inspect( $r_f6a ) );
lfa_check( true === $service->apply_action( $r_f6a, 'approve', array( 'state' => $token ) ), 'ADM-09 F6: approval with the current state token succeeds' );
clean_post_cache( $r_f6b );
lfa_check( 1 === count( $service->get_history( $r_f6a ) ) && 'approved' === $service->get_history( $r_f6a )[0]['event'] && 1 === count( $service->get_history( $r_f6b ) ) && 'reconciled' === $service->get_history( $r_f6b )[0]['event'], 'ADM-09 F6: approval records one entry and closes the matching placement request once' );
lfa_check( is_wp_error( $service->apply_action( $r_f6a, 'approve', array( 'state' => $token ) ) ) && 1 === count( $service->get_history( $r_f6a ) ), 'ADM-09 F6: a repeated submission is refused and loses no history' );
$p_f6s  = lfa_post( 'lf_player', 'lfa f6 stale', 'publish', array(), $ctx );
$r_f6s  = $req( $p_f6s, $teams[1] );
$stale  = $service->state_token( $service->inspect( $r_f6s ) );
$service->add_history( $r_f6s, 'note', 'another reviewer commented', 0, $admin_id );
lfa_check( 'leagueflow_request_state' === $service->apply_action( $r_f6s, 'approve', array( 'state' => $stale ) )->get_error_code() && ! \LeagueFlow\player_has_team( $p_f6s, $teams[1] ), 'ADM-09 F6: a form opened before another change is refused (stale state)' );
$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->postmeta SET meta_value = 'declined' WHERE post_id = %d AND meta_key = 'lf_request_status'", $r_f6s ) );
lfa_check( is_wp_error( $service->apply_action( $r_f6s, 'approve' ) ) && ! \LeagueFlow\player_has_team( $p_f6s, $teams[1] ), 'ADM-09 F6: a decision stored by another process (stale cache) is re-read and not overridden' );
$r_f6c = $req( $p_f6s, $teams[1] );
list( $db_host, $db_port, $db_socket ) = $wpdb->parse_db_host( DB_HOST );
$other = mysqli_connect( $db_host, DB_USER, DB_PASSWORD, DB_NAME, (int) ( $db_port ?: 3306 ), $db_socket ?: null );
if ( $other ) {
	mysqli_query( $other, "SELECT GET_LOCK('" . mysqli_real_escape_string( $other, $service->lock_name( $r_f6c ) ) . "', 0)" );
	add_filter( 'leagueflow_request_lock_timeout', '__return_zero' );
	$busy = $service->apply_action( $r_f6c, 'approve' );
	remove_filter( 'leagueflow_request_lock_timeout', '__return_zero' );
	mysqli_close( $other );
	clean_post_cache( $r_f6c );
	lfa_check( is_wp_error( $busy ) && 'leagueflow_request_busy' === $busy->get_error_code() && ! \LeagueFlow\player_has_team( $p_f6s, $teams[1] ) && 'pending' === get_post_meta( $r_f6c, 'lf_request_status', true ), 'ADM-09 F6: a request locked by a concurrent reviewer is refused without any write' );
	lfa_check( true === $service->apply_action( $r_f6c, 'approve' ), 'ADM-09 F6: once the other reviewer finished, the action proceeds' );
} else {
	lfa_check( false, 'ADM-09 F6: second database connection for the concurrency case' );
}

// ---------------------------------------------------------------------------
// ADM-09 (final review G3/H1-H4) · fail-closed locks, required HTTP state,
// locked rechecks and the shared captain/staff review path.
// ---------------------------------------------------------------------------
$h_ids  = array();
$h_post = static function ( $title ) use ( $ctx, &$h_ids ) { $id = lfa_post( 'lf_player', 'lfa h ' . $title, 'publish', array(), $ctx ); $h_ids[] = $id; return $id; };
$h_req  = static function ( $player_id, $team_id, $status = 'pending' ) use ( $req, &$h_ids ) { $id = $req( $player_id, $team_id, 'cricket', 0, $status ); $h_ids[] = $id; return $id; };
$token  = static function ( $request_id ) use ( $service ) { clean_post_cache( $request_id ); return $service->state_token( $service->inspect( $request_id ) ); };
$untouched = static function ( $request_id, $player_id, $team_id, $history = 0 ) use ( $service ) {
	clean_post_cache( $request_id );
	return 'pending' === get_post_meta( $request_id, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $player_id, $team_id ) && $history === count( $service->get_history( $request_id ) );
};

// H2: the HTTP handler needs a valid, current state token.
$p_h2  = $h_post( 'h2' );
$r_h2  = $h_req( $p_h2, $teams[2] );
$n_h2  = wp_create_nonce( 'leagueflow_request_approve_' . $r_h2 );
lfa_check( false !== strpos( $post_action( $r_h2, 'approve', $n_h2 ), 'leagueflow_request_error=leagueflow_request_stale' ) && $untouched( $r_h2, $p_h2, $teams[2] ), 'ADM-09 H2: a valid nonce with the state token omitted is sent to a fresh review; nothing changes' );
lfa_check( false !== strpos( $post_action( $r_h2, 'approve', $n_h2, array( 'lf_request_state' => '' ) ), 'leagueflow_request_error=leagueflow_request_stale' ) && $untouched( $r_h2, $p_h2, $teams[2] ), 'ADM-09 H2: an empty state token is refused' );
lfa_check( false !== strpos( $post_action( $r_h2, 'approve', $n_h2, array( 'lf_request_state' => 'deadbeefdeadbeef' ) ), 'leagueflow_request_error=leagueflow_request_state' ) && $untouched( $r_h2, $p_h2, $teams[2] ), 'ADM-09 H2: a forged well-formed state token is refused' );
$t_h2  = $token( $r_h2 );
$p_h2b = $h_post( 'h2 other' );
update_post_meta( $r_h2, 'lf_player_id', $p_h2b );
lfa_check( false !== strpos( $post_action( $r_h2, 'approve', $n_h2, array( 'lf_request_state' => $t_h2 ) ), 'leagueflow_request_state' ) && ! \LeagueFlow\player_has_team( $p_h2b, $teams[2] ) && $untouched( $r_h2, $p_h2, $teams[2] ), 'ADM-09 H2: a token from before the player changed is stale' );
update_post_meta( $r_h2, 'lf_player_id', $p_h2 );
$t_h2  = $token( $r_h2 );
update_post_meta( $r_h2, 'lf_team_id', $teams[3] );
lfa_check( false !== strpos( $post_action( $r_h2, 'approve', $n_h2, array( 'lf_request_state' => $t_h2 ) ), 'leagueflow_request_state' ) && ! \LeagueFlow\player_has_team( $p_h2, $teams[3] ) && $untouched( $r_h2, $p_h2, $teams[2] ), 'ADM-09 H2: a token from before the target team changed is stale' );
update_post_meta( $r_h2, 'lf_team_id', $teams[2] );
lfa_check( false !== strpos( $post_action( $r_h2, 'approve', $n_h2, array( 'lf_request_state' => $token( $r_h2 ) ) ), 'notice=assigned' ) && 1 === count( $service->get_history( $r_h2 ) ), 'ADM-09 H2: the current token approves once' );
$p_h2p = $h_post( 'h2 legacy' );
$r_h2p = $h_req( $p_h2p, 0 );
$_POST = wp_slash( array( 'action' => 'leagueflow_assign_placement', 'lf_join_request_id' => $r_h2p, 'lf_team_id' => $teams[0], 'leagueflow_placement_nonce' => wp_create_nonce( 'leagueflow_assign_placement_' . $r_h2p ) ) );
$_REQUEST = $_POST;
$legacy = lfa_handle( array( $admin, 'handle_assign_placement' ) );
$_POST = array(); $_REQUEST = array();
lfa_check( false !== strpos( $legacy, 'leagueflow_request_stale' ) && $untouched( $r_h2p, $p_h2p, $teams[0] ), 'ADM-09 H2: an old Placements form cannot bypass freshness; it opens a fresh review and writes nothing' );

// H1: an unavailable lock service (GET_LOCK returns NULL) fails closed.
$p_h1 = $h_post( 'h1' );
$r_h1 = $h_req( $p_h1, $teams[2] );
$r_h1p = $h_req( $p_h1, 0 );
add_filter( 'query', 'lfa_null_locks' );
$h1 = $service->apply_action( $r_h1, 'approve', array( 'state' => $token( $r_h1 ) ) );
$h1_cap = $service->apply_team_manager_decision( $r_h1, 'approve', $teams[2], $admin_id, $token( $r_h1 ), '__return_true' );
$before_h1 = \LeagueFlow\get_player_team_ids( $p_h1 );
\LeagueFlow\assign_player_to_team( $p_h1, $teams[2] );
$h1_closed = \LeagueFlow\reconcile_roster_additions( $p_h1, $before_h1, 'admin_player' );
remove_filter( 'query', 'lfa_null_locks' );
\LeagueFlow\set_player_team_ids( $p_h1, $before_h1 );
lfa_check( is_wp_error( $h1 ) && 'leagueflow_request_busy' === $h1->get_error_code() && is_wp_error( $h1_cap ) && 'leagueflow_request_busy' === $h1_cap->get_error_code() && $untouched( $r_h1, $p_h1, $teams[2] ), 'ADM-09 H1: with GET_LOCK unavailable (NULL) staff and captain decisions change nothing' );
clean_post_cache( $r_h1p );
lfa_check( 'pending' === get_post_meta( $r_h1p, 'lf_request_status', true ) && array() === $service->get_history( $r_h1p ), 'ADM-09 H1: reconciliation without a lock leaves requests pending' );

// G3/H3: rechecks after the lock and cache clear.
$p_h3 = $h_post( 'h3' );
$r_h3t = $h_req( $p_h3, $teams[2] );
wp_trash_post( $r_h3t );
$h3_info = $service->inspect( $r_h3t );
lfa_check( in_array( 'request_invalid', $h3_info['flags'], true ) && array() === $service->available_actions( $h3_info ) && 'leagueflow_request_missing' === $service->apply_action( $r_h3t, 'dismiss', array( 'note' => 'lfa' ) )->get_error_code() && 'pending' === get_post_meta( $r_h3t, 'lf_request_status', true ), 'ADM-09 G3: a trashed request record cannot be reviewed' );
$r_h3a = $h_req( $p_h3, $teams[2] );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'auto-draft' ), array( 'ID' => $r_h3a ) );
clean_post_cache( $r_h3a );
lfa_check( 'leagueflow_request_missing' === $service->apply_action( $r_h3a, 'dismiss', array( 'note' => 'lfa' ) )->get_error_code() && 'leagueflow_request_missing' === $service->apply_team_manager_decision( $r_h3a, 'decline', $teams[2], $admin_id, 'x', '__return_true' )->get_error_code(), 'ADM-09 G3: an auto-draft request record is refused by staff and captain paths' );
$r_h3w = $h_req( $p_h3, $teams[2] );
$t_h3w = $token( $r_h3w );
lfa_on_next_lock( static function () use ( $wpdb, $r_h3w ) { $wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $r_h3w ) ); } );
$h3w = $service->apply_action( $r_h3w, 'approve', array( 'state' => $t_h3w ) );
lfa_check( is_wp_error( $h3w ) && 'leagueflow_request_missing' === $h3w->get_error_code() && $untouched( $r_h3w, $p_h3, $teams[2] ), 'ADM-09 G3/H3: a request trashed while waiting for the lock (cache still warm) is re-read and refused' );
$r_h3s = $h_req( $p_h3, $teams[2] );
$t_h3s = $token( $r_h3s );
lfa_on_next_lock( static function () use ( $wpdb, $r_h3s ) { $wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'declined' ), array( 'post_id' => $r_h3s, 'meta_key' => 'lf_request_status' ) ); } );
lfa_check( is_wp_error( $service->apply_action( $r_h3s, 'approve', array( 'state' => $t_h3s ) ) ) && ! \LeagueFlow\player_has_team( $p_h3, $teams[2] ) && 'declined' === get_post_meta( $r_h3s, 'lf_request_status', true ), 'ADM-09 H3: a decision stored while waiting for the lock is not overwritten' );
$r_h3c = $h_req( $p_h3, $teams[2] );
$t_h3c = $token( $r_h3c );
$deny  = static function ( $caps, $cap, $user_id, $args ) use ( $r_h3c ) { return ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $r_h3c ) ? array( 'do_not_allow' ) : $caps; };
lfa_on_next_lock( static function () use ( $deny ) { add_filter( 'map_meta_cap', $deny, 10, 4 ); } );
$h3c = $service->apply_action( $r_h3c, 'approve', array( 'state' => $t_h3c ) );
remove_filter( 'map_meta_cap', $deny, 10 );
lfa_check( is_wp_error( $h3c ) && 'leagueflow_request_denied' === $h3c->get_error_code() && $untouched( $r_h3c, $p_h3, $teams[2] ), 'ADM-09 H3: authority revoked while waiting for the lock is rechecked; nothing written' );
$r_h3u = $h_req( 999996, 0, 'archived' );
$h3u   = $service->inspect( $r_h3u );
lfa_check( array( 'dismiss' ) === $service->available_actions( $h3u ) && is_wp_error( $service->apply_action( $r_h3u, 'reopen', array( 'note' => 'lfa' ) ) ), 'ADM-09 H3: unknown status plus a missing player can only be dismissed' );
lfa_check( true === $service->apply_action( $r_h3u, 'dismiss', array( 'note' => 'lfa stale import' ) ) && 'dismissed' === get_post_meta( $r_h3u, 'lf_request_status', true ) && 'archived' === get_post_meta( $r_h3u, 'lf_previous_request_status', true ) && false !== strpos( $service->get_history( $r_h3u )[0]['note'], '"archived"' ) && ! get_post( 999996 ), 'ADM-09 H3: dismissing keeps the raw unknown status in the record and the history; no player created' );

// H4: captain portal decisions share the request lock and fresh state with staff.
$captain_a = static function ( $team_id ) use ( $teams ) { return (int) $team_id === (int) $teams[2]; };
$p_h4 = $h_post( 'h4' );
$r_h4 = $h_req( $p_h4, $teams[2] );
lfa_check( 'leagueflow_request_state' === $service->apply_team_manager_decision( $r_h4, 'approve', $teams[2], $admin_id, '', $captain_a )->get_error_code() && $untouched( $r_h4, $p_h4, $teams[2] ), 'ADM-09 H4: a captain decision without the form state is refused' );
lfa_check( 'leagueflow_request_denied' === $service->apply_team_manager_decision( $r_h4, 'approve', $teams[2], $admin_id, $token( $r_h4 ), '__return_false' )->get_error_code() && 'leagueflow_request_state' === $service->apply_team_manager_decision( $r_h4, 'approve', $teams[3], $admin_id, $token( $r_h4 ), '__return_true' )->get_error_code() && $untouched( $r_h4, $p_h4, $teams[2] ), 'ADM-09 H4: a captain who does not manage the team, or acts for another team, is refused' );
$captain_view = $token( $r_h4 );
lfa_check( true === $service->apply_action( $r_h4, 'decline', array( 'note' => 'lfa staff decided first', 'state' => $token( $r_h4 ) ) ), 'ADM-09 H4: staff decline first' );
$late = $service->apply_team_manager_decision( $r_h4, 'approve', $teams[2], $admin_id, $captain_view, $captain_a );
clean_post_cache( $r_h4 );
lfa_check( is_wp_error( $late ) && 'declined' === get_post_meta( $r_h4, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_h4, $teams[2] ) && 1 === count( $service->get_history( $r_h4 ) ), 'ADM-09 H4: a captain approval from a page loaded before the staff decision cannot overwrite it' );
$p_h4b = $h_post( 'h4b' );
$r_h4b = $h_req( $p_h4b, $teams[2] );
$r_h4p = $h_req( $p_h4b, 0 );
$view  = $token( $r_h4b );
$other = lfa_hold_lock( $service->lock_name( $r_h4b ) );
add_filter( 'leagueflow_request_lock_timeout', '__return_zero' );
$busy_cap   = $service->apply_team_manager_decision( $r_h4b, 'approve', $teams[2], $admin_id, $view, $captain_a );
$busy_staff = $service->apply_action( $r_h4b, 'approve', array( 'state' => $view ) );
remove_filter( 'leagueflow_request_lock_timeout', '__return_zero' );
mysqli_close( $other );
lfa_check( is_wp_error( $busy_cap ) && 'leagueflow_request_busy' === $busy_cap->get_error_code() && is_wp_error( $busy_staff ) && 'leagueflow_request_busy' === $busy_staff->get_error_code() && $untouched( $r_h4b, $p_h4b, $teams[2] ), 'ADM-09 H4: while another process holds the request, captain and staff are both refused without writes' );
lfa_check( true === $service->apply_team_manager_decision( $r_h4b, 'approve', $teams[2], $admin_id, $view, $captain_a ), 'ADM-09 H4: the captain decision proceeds once the lock is free' );
$staff_after = $service->apply_action( $r_h4b, 'approve', array( 'state' => $view ) );
clean_post_cache( $r_h4p );
lfa_check( is_wp_error( $staff_after ) && 'approved' === get_post_meta( $r_h4b, 'lf_request_status', true ) && 1 === count( $service->get_history( $r_h4b ) ) && 'approved' === $service->get_history( $r_h4b )[0]['event'] && 1 === count( $service->get_history( $r_h4p ) ) && 'reconciled' === $service->get_history( $r_h4p )[0]['event'], 'ADM-09 H4: the losing staff approval is refused; exactly one approval and one reconciliation entry are recorded' );
\LeagueFlow\assign_player_to_team( $players[10], $teams[1] );
$r_late = $req( $players[10], $teams[1] );
\LeagueFlow\set_player_team_detail( $players[10], $teams[1], array( 'jersey_number' => 3 ) );
lfa_check( 'pending' === get_post_meta( $r_late, 'lf_request_status', true ), 'ADM-09 an existing stale request is not rewritten by an unrelated roster edit' );

// Inbox rendering.
$_GET = array( 'page' => 'leagueflow-requests', 'lf_sport' => 'cricket', 'issues' => '1' );
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( false !== strpos( $html, 'lfa req player 4' ) && false !== strpos( $html, 'Requested team no longer exists' ) && false === strpos( $html, 'lfa req player 1<' ), 'ADM-09 Needs-attention filter lists flagged pending requests only' );
lfa_check( false !== strpos( $html, 'Join a team' ) && false !== strpos( $html, 'Needs placement' ) && false !== strpos( $html, 'Dismissed' ), 'ADM-09 type tabs and business statuses are shown' );
$_GET = array( 'page' => 'leagueflow-requests', 'status' => 'all', 's' => 'lfa req player 7' );
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( 1 === substr_count( $html, 'lfa req player 7</a>' ) && false === strpos( $html, 'lfa req player 3</a>' ), 'ADM-09 search finds the player' );
$_GET = array( 'page' => 'leagueflow-requests', 'status' => 'all', 'request_id' => $r_decline );
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( false !== strpos( $html, 'id="leagueflow-request-detail"' ) && false !== strpos( $html, 'lfa roster full' ) && false !== strpos( $html, 'This request is closed' ), 'ADM-09 detail panel shows the decision history' );
$_GET = array( 'page' => 'leagueflow-requests', 'request_id' => $r_late );
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( false !== strpos( $html, 'Close: already on the team' ) && false === strpos( $html, 'Approve and add to team' ), 'ADM-09 detail offers only the actions that fit' );
// G2: the division (level) filter is kept on tabs, counts, paging, details, actions and Back.
$_GET = array( 'page' => 'leagueflow-requests', 'lf_sport' => 'cricket', 'lf_league_level' => 'competitive', 'status' => 'all' );
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( false !== strpos( $html, 'lfa f2 player' ) && false === strpos( $html, 'lfa req player 1</a>' ) && false !== strpos( $html, 'lf_league_level=competitive' ) && (bool) preg_match( '/value="competitive"\s+selected/', $html ) && false === strpos( $html, 'lf_season_filter' ), 'ADM-09 G2: a level filter lists only that division and stays in every inbox link (no fake season filter)' );
$_GET['request_id'] = $r_f2_lvl;
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( (bool) preg_match( '/name="lf_return" value="[^"]*lf_league_level=competitive/', $html ) && (bool) preg_match( '/href="[^"]*lf_league_level=competitive[^"]*">&larr; Back to the request list/', $html ), 'ADM-09 G2: the detail panel\'s action return and Back link keep the division' );
// G4: primary decision visible; secondary decisions in a native disclosure (open when there is no primary).
$_GET = array( 'page' => 'leagueflow-requests', 'request_id' => $r_late );
$html = lfa_render( array( $admin, 'requests_page' ) );
$more = strpos( $html, '<details class="leagueflow-request-more">' );
lfa_check( false !== $more && strpos( $html, 'Close: already on the team</button>' ) < $more && strpos( $html, '>Decline</button>' ) > $more && strpos( $html, 'name="leagueflow_request_nonce"', $more ) > $more && false !== strpos( $html, 'textarea id="leagueflow-request-decline-' . $r_late . '-note" name="lf_request_decision_note" rows="2" class="large-text" required' ), 'ADM-09 G4: Close stays primary; Decline/Dismiss sit in a native <details> with their own nonce and required reason' );
$_GET = array( 'page' => 'leagueflow-requests', 'request_id' => $r_f3 );
$html = lfa_render( array( $admin, 'requests_page' ) );
lfa_check( false !== strpos( $html, '<details class="leagueflow-request-more" open>' ), 'ADM-09 G4: with no primary decision the disclosure starts open' );
$html = lfa_render( static function () use ( $admin ) { $admin->render_admin_sport_filter( 'lf_player', 'top' ); } );
lfa_check( false !== strpos( $html, 'Players have no competition or season of their own' ) && false === strpos( $html, 'lf_season_filter' ), 'ADM-09 G2: the Players list explains its scope instead of offering an always-empty season filter' );
$_GET = array();
$row = $admin->add_join_request_row_actions( array( 'edit' => 'x' ), get_post( $r_late ) );
lfa_check( 'leagueflow_review' === array_key_first( $row ) && false !== strpos( $row['leagueflow_review'], 'request_id=' . $r_late ), 'ADM-09 classic request rows link to the inbox review' );
$request_ids = array_merge( $h_ids, array( $r_join, $r_cross, $r_trash, $r_missing, $r_ghost, $r_member, $r_place, $r_decline, $r_draft, $r_rec, $r_unrelated, $r_late, $r_trash_member, $r_save, $r_f2_sp, $r_f2_lvl, $r_f2_odd, $r_f2_none, $r_f3, $r_f3b, $r_f3c, $r_f5, $r_f6a, $r_f6b, $r_f6s, $r_f6c, $p_save, $p_f2, $p_f3, $p_f3b, $p_f3c, $p_f6, $p_f6s ) );

// ---------------------------------------------------------------------------
// ADM-10 · Current season per sport; explicit All / Current / selected (C-07, N-02).
// ---------------------------------------------------------------------------
$extra_posts   = array();
$extra_terms   = array();
$saved_map     = get_option( \LeagueFlow\CURRENT_SEASONS_OPTION, null );
$saved_legacy  = \LeagueFlow\get_legacy_current_season_ids();
$saved_rules   = get_option( \LeagueFlow\RULE_OVERRIDES_OPTION, null );
$saved_fields  = get_option( \LeagueFlow\Field_Availability_Manager::OPTION, null );
$lfa_term      = static function ( $taxonomy, $name, $sport_slug ) use ( &$extra_terms ) {
	$made = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $made ) ) {
		throw new RuntimeException( 'Term fixture failed: ' . $made->get_error_message() );
	}
	if ( $sport_slug ) {
		update_term_meta( $made['term_id'], 'lf_sport_slug', $sport_slug );
	}
	$extra_terms[] = array( $taxonomy, (int) $made['term_id'] );
	return (int) $made['term_id'];
};
$s_c1   = $lfa_term( 'lf_season', 'lfa Cricket S1', 'cricket' );
$s_c2   = $lfa_term( 'lf_season', 'lfa Cricket S2', 'cricket' );
$s_h1   = $lfa_term( 'lf_season', 'lfa Hockey S1', 'hockey' );
$s_none = $lfa_term( 'lf_season', 'lfa Sportless S', '' );
\LeagueFlow\set_current_season( 0 );
lfa_check( 0 === \LeagueFlow\get_current_season_id( 'cricket' ) && 0 === \LeagueFlow\resolve_season_context( '', 'cricket' ), 'ADM-10 with no current season, blocks without a season keep showing every season' );
\LeagueFlow\set_current_season( $s_c1 );
\LeagueFlow\set_current_season( $s_h1 );
lfa_check( $s_c1 === \LeagueFlow\get_current_season_id( 'cricket' ) && $s_h1 === \LeagueFlow\get_current_season_id( 'hockey' ), 'ADM-10 setting one sport\'s current season does not clear another sport\'s (C-07)' );
\LeagueFlow\set_current_season( $s_c2 );
lfa_check( $s_c2 === \LeagueFlow\get_current_season_id( 'cricket' ) && $s_h1 === \LeagueFlow\get_current_season_id( 'hockey' ) && ! \LeagueFlow\is_current_season( $s_c1 ), 'ADM-10 replacing a sport\'s current season is immediate (cache invalidated) and scoped' );
lfa_check( 0 === \LeagueFlow\resolve_season_context( 'all', 'cricket' ) && $s_c2 === \LeagueFlow\resolve_season_context( 'current', 'cricket' ) && $s_c2 === \LeagueFlow\resolve_season_context( '', 'cricket' ) && $s_c1 === \LeagueFlow\resolve_season_context( $s_c1, 'cricket' ), 'ADM-10 "all", "current", empty and an explicit season resolve distinctly' );
lfa_check( array( 'mode' => 'all', 'id' => 0 ) === \LeagueFlow\resolve_admin_season_choice( '', 'cricket' ) && array( 'mode' => 'current', 'id' => $s_c2 ) === \LeagueFlow\resolve_admin_season_choice( 'current', 'cricket' ), 'ADM-10 admin season filter: blank is All seasons, never the current season' );
\LeagueFlow\clear_current_season( $s_c2 );
lfa_check( 0 === \LeagueFlow\get_current_season_id( 'cricket' ) && $s_h1 === \LeagueFlow\get_current_season_id( 'hockey' ), 'ADM-10 clearing one sport leaves the other' );
// Legacy site-wide flag, as stored before this version.
\LeagueFlow\set_current_season( 0 );
update_term_meta( $s_c1, 'lf_is_current', 1 );
\LeagueFlow\flush_current_season_cache();
lfa_check( $s_c1 === \LeagueFlow\get_current_season_id() && $s_c1 === \LeagueFlow\get_current_season_id( 'cricket' ) && $s_c1 === \LeagueFlow\get_current_season_id( 'hockey' ), 'ADM-10 legacy flag behaves exactly as before while no sport has its own current season' );
\LeagueFlow\set_current_season( $s_h1 );
lfa_check( $s_h1 === \LeagueFlow\get_current_season_id( 'hockey' ) && $s_c1 === \LeagueFlow\get_current_season_id( 'cricket' ) && '1' === (string) get_term_meta( $s_c1, 'lf_is_current', true ), 'ADM-10 a per-sport season does not clear another sport\'s legacy flag' );
\LeagueFlow\set_current_season( $s_c2 );
lfa_check( '' === (string) get_term_meta( $s_c1, 'lf_is_current', true ) && $s_c2 === \LeagueFlow\get_current_season_id( 'cricket' ), 'ADM-10 the same sport\'s legacy flag is superseded by its own current season' );
\LeagueFlow\set_current_season( $s_none );
lfa_check( '1' === (string) get_term_meta( $s_none, 'lf_is_current', true ) && $s_c2 === \LeagueFlow\get_current_season_id( 'cricket' ), 'ADM-10 a season without a sport keeps the legacy site-wide flag and does not override sports with their own' );
wp_delete_term( $s_h1, 'lf_season' );
lfa_check( 0 === \LeagueFlow\get_current_season_id( 'hockey' ) || $s_none === \LeagueFlow\get_current_season_id( 'hockey' ), 'ADM-10 deleting a sport\'s current season removes it from that sport' );
lfa_check( ! isset( \LeagueFlow\get_current_season_map()['hockey'] ), 'ADM-10 deleted season is dropped from the per-sport map' );
// N-02: Brackets "All seasons" must show every season's knockout matches.
$ko_home = lfa_post( 'lf_team', 'lfa KO North', 'publish', array(), $ctx );
$ko_away = lfa_post( 'lf_team', 'lfa KO South', 'publish', array(), $ctx );
$ko_old  = lfa_post( 'lf_match', 'lfa KO old season', 'publish', array( 'lf_home_team_id' => $ko_home, 'lf_away_team_id' => $ko_away, 'lf_is_knockout' => 1, 'lf_round_label' => 'lfa Final', 'lf_round_order' => 1, 'lf_status' => 'scheduled', 'lf_match_datetime' => '2026-05-01 18:00' ), $ctx + array( 'lf_season' => array( $s_c1 ) ) );
array_push( $extra_posts, $ko_home, $ko_away, $ko_old );
$_GET = array( 'page' => 'leagueflow-brackets', 'lf_sport' => 'cricket' );
$html = lfa_render( array( $admin, 'render_brackets_page' ) );
lfa_check( false !== strpos( $html, 'lfa KO North' ), 'ADM-10 Brackets "All seasons" includes a knockout match from a non-current season (N-02)' );
lfa_check( false !== strpos( $html, 'Current season (lfa Cricket S2)' ), 'ADM-10 the season filter offers the sport\'s current season explicitly' );
$_GET = array( 'page' => 'leagueflow-brackets', 'lf_sport' => 'cricket', 'season' => 'current' );
$html = lfa_render( array( $admin, 'render_brackets_page' ) );
lfa_check( false === strpos( $html, 'lfa KO North' ), 'ADM-10 Brackets "Current season" excludes the other season' );
$_GET = array();
$tax = new \LeagueFlow\Taxonomies();
lfa_check( false !== strpos( $tax->render_season_current_column( '', 'lf_current', $s_c2 ), 'Current for its sport' ) && false === strpos( $tax->render_season_current_column( '', 'lf_current', $s_c1 ), 'Current' ), 'ADM-10 Seasons list shows which season is current for its sport' );

// ---------------------------------------------------------------------------
// ADM-11 · Setup check and explicit competition/season mapping (C-16).
// ---------------------------------------------------------------------------
$setup     = $admin->setup_admin();
$map_cup   = $lfa_term( 'lf_competition', 'lfa Map Cup', 'cricket' );
$other_cup = $lfa_term( 'lf_competition', 'lfa Other Cup', 'cricket' );
$hock_cup  = $lfa_term( 'lf_competition', 'lfa Hockey Cup', 'hockey' );
$m1 = lfa_post( 'lf_team', 'lfa map one', 'publish', array(), $ctx );
$m2 = lfa_post( 'lf_team', 'lfa map two', 'private', array(), $ctx );
$m3 = lfa_post( 'lf_team', 'lfa map three', 'publish', array(), $ctx + array( 'lf_competition' => array( $other_cup ) ) );
$mh = lfa_post( 'lf_team', 'lfa map hockey', 'publish', array(), array( 'lf_sport' => array( $sport['hockey'] ), 'lf_league_level' => array( $level ) ) );
array_push( $extra_posts, $m1, $m2, $m3, $mh );
$no_cup = lfa_list( 'lf_team', array( 'lf_sport' => 'cricket', 'lf_competition_filter' => 'none' ) );
$in_cup = lfa_list( 'lf_team', array( 'lf_sport' => 'cricket', 'lf_competition_filter' => (string) $other_cup ) );
lfa_check( in_array( $m1, $no_cup, true ) && ! in_array( $m3, $no_cup, true ) && array( $m3 ) === $in_cup, 'ADM-11 Teams list filters by competition, including "No competition"' );
$terms_of = static function ( $id ) { clean_object_term_cache( $id, 'lf_team' ); return wp_get_object_terms( $id, 'lf_competition', array( 'fields' => 'ids' ) ); };
$checks   = wp_list_pluck( $setup->checks( 'cricket' ), 'value', 'label' );
lfa_check( isset( $checks['Teams without a competition'] ) && $checks['Teams without a competition'] >= 2 && isset( $checks['Current season'] ), 'ADM-11 Setup check counts teams without a competition and shows the current season' );
$_GET = array( 'page' => 'leagueflow-setup', 'lf_sport' => 'cricket', 'map_type' => 'lf_team', 'map_taxonomy' => 'lf_competition' );
$html = lfa_render( static function () use ( $setup ) { $setup->render_page( 'cricket' ); } );
$_GET = array();
lfa_check( false !== strpos( $html, 'lfa map one' ) && false === strpos( $html, 'lfa map three' ) && false === strpos( $html, 'lfa map hockey' ) && false === strpos( $html, 'checked' ), 'ADM-11 the mapping list offers only this sport\'s unassigned records, none preselected' );
lfa_check( array() === $terms_of( $m1 ), 'ADM-11 viewing the setup check writes nothing' );
$input = array( 'sport' => 'cricket', 'type' => 'lf_team', 'taxonomy' => 'lf_competition', 'term_id' => $map_cup, 'record_ids' => array( $m1, $m2 ) );
$plan  = $setup->plan( $input );
lfa_check( ! is_wp_error( $plan ) && 2 === count( $plan['rows'] ) && ! $plan['conflicts'], 'ADM-11 preview lists exactly the selected records' );
lfa_check( 'leagueflow_mapping_cross_sport' === $setup->plan( array_merge( $input, array( 'term_id' => $hock_cup ) ) )->get_error_code(), 'ADM-11 a competition from another sport is refused' );
$bad = $setup->plan( array_merge( $input, array( 'record_ids' => array( $m1, $m3, $mh ) ) ) );
lfa_check( 2 === count( $bad['conflicts'] ) && is_wp_error( $setup->apply( array_merge( $input, array( 'record_ids' => array( $m1, $m3, $mh ) ) ), $bad['token'] ) ) && array() === $terms_of( $m1 ) && array( $other_cup ) === $terms_of( $m3 ), 'ADM-11 an existing assignment or another sport\'s record blocks the whole apply; nothing is replaced' );
lfa_check( 'leagueflow_mapping_stale' === $setup->apply( $input, $plan['token'] )->get_error_code() && array() === $terms_of( $m1 ), 'ADM-11 applying without a stored preview is refused' );
$setup->remember_preview( $plan );
wp_update_post( array( 'ID' => $m2, 'post_title' => 'lfa map two renamed' ) );
lfa_check( 'leagueflow_mapping_stale' === $setup->apply( $input, $plan['token'] )->get_error_code() && array() === $terms_of( $m1 ) && array() === $terms_of( $m2 ), 'ADM-11 a record changed after the preview makes the preview stale; nothing written' );
$plan = $setup->plan( $input );
$setup->remember_preview( $plan );
$done = $setup->apply( $input, $plan['token'] );
lfa_check( is_array( $done ) && 2 === $done['assigned'] && array( $map_cup ) === $terms_of( $m1 ) && array( $map_cup ) === $terms_of( $m2 ) && array( $other_cup ) === $terms_of( $m3 ), 'ADM-11 an explicit, previewed apply assigns only the selected unassigned records' );
lfa_check( is_wp_error( $setup->apply( $input, $plan['token'] ) ), 'ADM-11 the same preview cannot be applied twice' );
wp_set_current_user( $author_id );
lfa_check( 'leagueflow_mapping_denied' === $setup->plan( $input )->get_error_code(), 'ADM-11 a user without edit_others_posts cannot plan or apply a mapping' );
wp_set_current_user( $admin_id );
$_POST = array( 'action' => 'leagueflow_mapping_apply', 'lf_map_sport' => 'cricket' );
$_REQUEST = $_POST;
lfa_check( 'die:403' === lfa_handle( array( $setup, 'handle_apply' ) ), 'ADM-11 the apply handler refuses a missing nonce' );
$_POST = array(); $_REQUEST = array();

// H5: mapping writes are serialized, recomputed under the lock and report failures truthfully.
$mx = array();
foreach ( array( 4, 5, 6, 7, 8 ) as $n ) {
	$mx[ $n ] = lfa_post( 'lf_team', 'lfa map ' . $n, 'publish', array(), $ctx );
	$extra_posts[] = $mx[ $n ];
}
$in_a  = array_merge( $input, array( 'record_ids' => array( $mx[4], $mx[5] ) ) );
$in_b  = array_merge( $input, array( 'term_id' => $other_cup, 'record_ids' => array( $mx[5], $mx[6] ) ) );
$pl_a  = $setup->plan( $in_a );
$pl_b  = $setup->plan( $in_b );
$setup->remember_preview( $pl_a );
$setup->remember_preview( $pl_b );
$won   = $setup->apply( $in_a, $pl_a['token'] );
$lost  = $setup->apply( $in_b, $pl_b['token'] );
lfa_check( is_array( $won ) && 2 === $won['assigned'] && array() === $won['failed_ids'] && is_wp_error( $lost ) && 'leagueflow_mapping_stale' === $lost->get_error_code() && array( $map_cup ) === $terms_of( $mx[5] ) && array() === $terms_of( $mx[6] ), 'ADM-11 H5: of two conflicting previews only the first applies; the second is stale and writes nothing (not even its unaffected record)' );
$in_c  = array_merge( $input, array( 'record_ids' => array( $mx[7], $mx[8] ) ) );
$pl_c  = $setup->plan( $in_c );
$setup->remember_preview( $pl_c );
$other = lfa_hold_lock( \LeagueFlow\db_lock_name( 'map', 'mapping' ) );
add_filter( 'leagueflow_mapping_lock_timeout', '__return_zero' );
$busy = $setup->apply( $in_c, $pl_c['token'] );
remove_filter( 'leagueflow_mapping_lock_timeout', '__return_zero' );
mysqli_close( $other );
add_filter( 'query', 'lfa_null_locks' );
$nul = $setup->apply( $in_c, $pl_c['token'] );
remove_filter( 'query', 'lfa_null_locks' );
lfa_check( is_wp_error( $busy ) && 'leagueflow_mapping_busy' === $busy->get_error_code() && is_wp_error( $nul ) && 'leagueflow_mapping_busy' === $nul->get_error_code() && array() === $terms_of( $mx[7] ) && false !== get_transient( 'leagueflow_map_' . $admin_id . '_' . substr( $pl_c['token'], 0, 24 ) ), 'ADM-11 H5: a held or unavailable mapping lock writes nothing and keeps the preview' );
$editor_id = wp_insert_user( array( 'user_login' => 'lfa_map_editor', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'lfa-map-editor@example.test', 'role' => 'editor' ) );
wp_set_current_user( $editor_id );
$foreign = $setup->apply( $in_c, $pl_c['token'] );
wp_set_current_user( $admin_id );
lfa_check( is_wp_error( $foreign ) && array() === $terms_of( $mx[7] ), 'ADM-11 H5: another user cannot apply a preview they did not make' );
$ins_fail = static function ( $query ) use ( $wpdb, $mx ) {
	return preg_match( '/^INSERT INTO `?' . preg_quote( $wpdb->term_relationships, '/' ) . '`?.*VALUES \(' . (int) $mx[8] . ',/is', ltrim( $query ) ) ? 'SELECT * FROM lfa_injected_missing_table' : $query;
};
add_filter( 'query', $ins_fail );
$suppress = $wpdb->suppress_errors( true );
$part = $setup->apply( $in_c, $pl_c['token'] );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $ins_fail );
lfa_check( is_array( $part ) && 1 === $part['assigned'] && array( $mx[7] ) === $part['applied_ids'] && array( $mx[8] ) === $part['failed_ids'] && $part['partial'] && array( $map_cup ) === $terms_of( $mx[7] ) && array() === $terms_of( $mx[8] ), 'ADM-11 H5: a refused term write is reported (applied and failed IDs); the successful write is kept' );
lfa_check( is_wp_error( $setup->apply( $in_c, $pl_c['token'] ) ), 'ADM-11 H5: the consumed preview cannot be reused; the failed record needs a fresh preview' );
wp_delete_user( $editor_id );

// ---------------------------------------------------------------------------
// ADM-12 · Generate fixtures: division scope, preview, duplicates, stale and double submit (U-05).
// ---------------------------------------------------------------------------
$fix_cup     = $lfa_term( 'lf_competition', 'lfa Fix Cup', 'cricket' );
$fx          = array();
foreach ( array( 'r1', 'r2', 'r3' ) as $name ) {
	$fx[ $name ] = lfa_post( 'lf_team', 'lfa fix ' . $name, 'publish', array(), $ctx + array( 'lf_competition' => array( $fix_cup ) ) );
}
foreach ( array( 'c1', 'c2' ) as $name ) {
	$fx[ $name ] = lfa_post( 'lf_team', 'lfa fix ' . $name, 'publish', array(), array( 'lf_sport' => array( $sport['cricket'] ), 'lf_league_level' => array( $competitive ), 'lf_competition' => array( $fix_cup ) ) );
}
$fx['none'] = lfa_post( 'lf_team', 'lfa fix nolevel', 'publish', array(), array( 'lf_sport' => array( $sport['cricket'] ), 'lf_competition' => array( $fix_cup ) ) );
wp_set_object_terms( $fx['none'], array(), 'lf_league_level' );
$extra_posts = array_merge( $extra_posts, array_values( $fx ) );
$cup_matches = static function () use ( $fix_cup ) {
	return get_posts( array( 'post_type' => 'lf_match', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'lf_competition', 'field' => 'term_id', 'terms' => array( $fix_cup ) ) ) ) );
};
$fix_ctx = array( 'sport_id' => $sport['cricket'], 'competition_id' => $fix_cup );
lfa_check( 'leagueflow_fixtures_sport' === $generator->preview( array( 'competition_id' => $fix_cup ) )->get_error_code(), 'ADM-12 no sport, no preview' );
$pre = $generator->preview( $fix_ctx );
lfa_check( 2 === count( $pre['groups'] ) && $pre['requires_level_choice'] && array( $fx['none'] ) === $pre['no_level_team_ids'] && 4 === $pre['new'], 'ADM-12 preview groups teams by level, lists teams without a level, and needs a level choice' );
$err = $generator->generate( $fix_ctx + array( 'token' => $pre['token'] ) );
lfa_check( is_wp_error( $err ) && 'leagueflow_fixtures_level' === $err->get_error_code() && array() === $cup_matches(), 'ADM-12 mixed levels without an explicit choice create nothing' );
$rec_ctx = $fix_ctx + array( 'league_level_id' => $level );
$pre     = $generator->preview( $rec_ctx );
$fix_made    = $generator->generate( $rec_ctx + array( 'token' => $pre['token'] ) );
$cross   = false;
foreach ( is_wp_error( $fix_made ) ? array() : $fix_made['created_ids'] as $made_id ) {
	$cross = $cross || $level !== \LeagueFlow\get_post_primary_term_id( $made_id, 'lf_league_level' ) || 'draft' !== get_post_status( $made_id ) || $fix_made['batch'] !== get_post_meta( $made_id, \LeagueFlow\Fixture_Generator::META_BATCH, true );
}
lfa_check( ! is_wp_error( $fix_made ) && 3 === $fix_made['created'] && ! $cross && 3 === count( $cup_matches() ), 'ADM-12 one level: 3 draft fixtures, all in that level, tagged with the run' );
$extra_posts = array_merge( $extra_posts, is_wp_error( $fix_made ) ? array() : $fix_made['created_ids'] );
$batch_ids = is_wp_error( $fix_made ) ? array( -1 ) : lfa_list( 'lf_match', array( 'lf_batch' => $fix_made['batch'], 'post_status' => 'all' ) );
$made_ids  = is_wp_error( $fix_made ) ? array() : array_map( 'intval', $fix_made['created_ids'] );
sort( $batch_ids ); sort( $made_ids );
lfa_check( $made_ids === $batch_ids, 'ADM-12 the result link lists exactly the new drafts' );
lfa_check( $made_ids && '' === get_post_field( 'post_name', $made_ids[0] ), 'ADM-12 generated drafts have no slug until first publication (P-01: the address follows the title then)' );
$again = $generator->generate( $rec_ctx + array( 'token' => $pre['token'] ) );
lfa_check( is_wp_error( $again ) && 'leagueflow_fixtures_stale' === $again->get_error_code() && 3 === count( $cup_matches() ), 'ADM-12 a repeated submission of the same preview is refused (stale) and creates nothing' );
$pre2 = $generator->preview( $rec_ctx );
lfa_check( 0 === $pre2['new'] && 3 === $pre2['duplicates'] && 'leagueflow_fixtures_duplicates' === $generator->generate( $rec_ctx + array( 'token' => $pre2['token'] ) )->get_error_code(), 'ADM-12 existing pairings are detected as duplicates and never recreated' );
$per     = $generator->preview( $fix_ctx + array( 'per_level' => true ) );
$per_run = $generator->generate( $fix_ctx + array( 'per_level' => true, 'token' => $per['token'] ) );
lfa_check( ! $per['requires_level_choice'] && ! is_wp_error( $per_run ) && 1 === $per_run['created'] && 3 === $per_run['duplicates'] && 4 === count( $cup_matches() ), 'ADM-12 "each level separately" creates only the missing level\'s fixtures' );
$extra_posts = array_merge( $extra_posts, is_wp_error( $per_run ) ? array() : $per_run['created_ids'] );
$stale_pre = $generator->preview( $rec_ctx + array( 'legs' => 2 ) );
$fx['r4']  = lfa_post( 'lf_team', 'lfa fix r4', 'publish', array(), $ctx + array( 'lf_competition' => array( $fix_cup ) ) );
$extra_posts[] = $fx['r4'];
lfa_check( 'leagueflow_fixtures_stale' === $generator->generate( $rec_ctx + array( 'legs' => 2, 'token' => $stale_pre['token'] ) )->get_error_code() && 4 === count( $cup_matches() ), 'ADM-12 a team added after the preview makes it stale; nothing created' );
// Handler: forged scope from another sport.
$_POST = wp_slash( array( 'action' => 'leagueflow_generate_fixtures', 'leagueflow_generate_fixtures_nonce' => wp_create_nonce( 'leagueflow_generate_fixtures' ), 'leagueflow_fixtures_sport' => 'cricket', 'leagueflow_fixtures_competition' => $hock_cup, 'leagueflow_fixtures_league_level' => $level, 'leagueflow_fixtures_token_1' => 'x', 'leagueflow_fixtures_legs' => 1 ) );
$_REQUEST = $_POST;
lfa_handle( array( $admin, 'handle_generate_fixtures' ) );
$_POST = array(); $_REQUEST = array();
lfa_check( 4 === count( $cup_matches() ) && (bool) preg_grep( '/scope was not valid/', lfa_notices() ), 'ADM-12 a forged cross-sport competition is refused by the handler before any write' );
update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array(), false );
$_GET = array( 'page' => 'leagueflow-fixtures', 'lf_sport' => 'cricket', 'competition' => $fix_cup );
$html = lfa_render( array( $admin, 'render_fixtures_page' ) );
lfa_check( false !== strpos( $html, 'Generate each level separately' ) && false === strpos( $html, 'name="leagueflow_fixtures_legs"' ) && false !== strpos( $html, 'lfa fix nolevel' ), 'ADM-12 the page asks for a level choice and names teams without a level' );
$_GET = array( 'page' => 'leagueflow-fixtures', 'lf_sport' => 'cricket', 'competition' => $fix_cup, 'league_level' => $level );
$html = lfa_render( array( $admin, 'render_fixtures_page' ) );
lfa_check( false !== strpos( $html, 'name="leagueflow_fixtures_token_1"' ) && false !== strpos( $html, 'Automatic dates need venue availability' ) && false === strpos( $html, 'name="leagueflow_fixtures_schedule"' ), 'ADM-12 with no venue availability the scheduling option is replaced by the prerequisite (C-09)' );
$_GET = array();

// H5: generation lock fails closed; insert/term failures and an early stop are reported with a complete result.
$fault_cup = $lfa_term( 'lf_competition', 'lfa Fault Cup', 'cricket' );
$ft = array();
foreach ( array( 'f1', 'f2', 'f3' ) as $name ) {
	$ft[ $name ] = lfa_post( 'lf_team', 'lfa fault ' . $name, 'publish', array(), $ctx + array( 'lf_competition' => array( $fault_cup ) ) );
	$extra_posts[] = $ft[ $name ];
}
$fault_ctx = array( 'sport_id' => $sport['cricket'], 'competition_id' => $fault_cup, 'league_level_id' => $level );
$count_fault = static function () use ( $ft ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = 'lf_home_team_id' WHERE p.post_type = 'lf_match' AND m.meta_value IN (" . implode( ',', array_map( 'intval', $ft ) ) . ')' );
};
$f_pre = $generator->preview( $fault_ctx );
$other = lfa_hold_lock( \LeagueFlow\db_lock_name( 'fix', (string) $sport['cricket'] ) );
$held  = $generator->generate( $fault_ctx + array( 'token' => $f_pre['token'] ) );
mysqli_close( $other );
add_filter( 'query', 'lfa_null_locks' );
$nul   = $generator->generate( $fault_ctx + array( 'token' => $f_pre['token'] ) );
remove_filter( 'query', 'lfa_null_locks' );
lfa_check( is_wp_error( $held ) && 'leagueflow_fixtures_busy' === $held->get_error_code() && is_wp_error( $nul ) && 'leagueflow_fixtures_busy' === $nul->get_error_code() && 0 === $count_fault(), 'ADM-12 H5: a concurrent run or an unavailable lock creates nothing' );
$fault_tt = (int) get_term( $fault_cup, 'lf_competition' )->term_taxonomy_id;
$insert_fail = static function ( $maybe_empty, $postarr ) use ( $ft ) {
	$title = (string) ( $postarr['post_title'] ?? '' );
	return ( false !== strpos( $title, 'lfa fault f1' ) && false !== strpos( $title, 'lfa fault f2' ) ) ? true : $maybe_empty;
};
$term_fired = false;
$term_fail  = static function ( $query ) use ( $wpdb, $fault_tt, &$term_fired ) {
	if ( ! $term_fired && preg_match( '/^INSERT INTO `?' . preg_quote( $wpdb->term_relationships, '/' ) . '`?.*VALUES \(\d+, ?' . $fault_tt . '\)/is', ltrim( $query ) ) ) {
		$term_fired = true;
		return 'SELECT * FROM lfa_injected_missing_table';
	}
	return $query;
};
add_filter( 'wp_insert_post_empty_content', $insert_fail, 10, 2 );
add_filter( 'query', $term_fail );
$suppress = $wpdb->suppress_errors( true );
$f_run = $generator->generate( $fault_ctx + array( 'token' => $f_pre['token'] ) );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $term_fail );
remove_filter( 'wp_insert_post_empty_content', $insert_fail, 10 );
$f_ok = ! is_wp_error( $f_run );
if ( $f_ok ) {
	$extra_posts = array_merge( $extra_posts, $f_run['created_ids'], $f_run['incomplete_ids'] );
}
$shape = array( 'created', 'created_ids', 'failed', 'failed_pairings', 'incomplete_ids', 'incomplete', 'duplicates', 'teams', 'groups', 'batch', 'post_status', 'partial', 'partial_error', 'scheduled', 'unscheduled', 'schedule_messages' );
lfa_check( $f_ok && 1 === $f_run['created'] && 1 === $f_run['failed'] && 1 === count( $f_run['incomplete_ids'] ) && array( 'lf_competition' ) === $f_run['incomplete'][0]['missing'] && $f_run['partial'] && 2 === $count_fault(), 'ADM-12 H5: one refused insert and one refused term write are reported (1 created, 1 failed, 1 incomplete kept for review); nothing is hidden or deleted' );
lfa_check( $f_ok && array() === array_diff( $shape, array_keys( $f_run ) ) && 1 === $f_run['unscheduled'], 'ADM-12 H5: a partial result has the complete shape, including scheduled and unscheduled counts' );
$f_pre2 = $generator->preview( $fault_ctx );
lfa_check( ! is_wp_error( $f_pre2 ) && 1 === $f_pre2['new'], 'ADM-12 H5: a fresh preview offers exactly the pairing that failed (the incomplete match still counts as existing)' );
// Early stop: the second level becomes unreadable after the first level was written.
$stop_cup = $lfa_term( 'lf_competition', 'lfa Stop Cup', 'cricket' );
$st = array();
foreach ( array( 'r1' => $level, 'r2' => $level, 'c1' => $competitive, 'c2' => $competitive ) as $name => $lvl ) {
	$st[ $name ] = lfa_post( 'lf_team', 'lfa stop ' . $name, 'publish', array(), array( 'lf_sport' => array( $sport['cricket'] ), 'lf_league_level' => array( $lvl ), 'lf_competition' => array( $stop_cup ) ) );
	$extra_posts[] = $st[ $name ];
}
$stop_ctx   = array( 'sport_id' => $sport['cricket'], 'competition_id' => $stop_cup, 'per_level' => true );
$stop_pre   = $generator->preview( $stop_ctx );
// Once the first fixture of one level is written, the other level's teams become unreadable.
$hide       = static function ( $caps, $cap, $user_id, $args ) use ( $st ) {
	if ( $GLOBALS['lfa_stop_written_level'] && 'read_post' === $cap && isset( $args[0] ) && in_array( (int) $args[0], array_map( 'intval', $st ), true ) ) {
		return \LeagueFlow\get_post_primary_term_id( (int) $args[0], 'lf_league_level' ) === $GLOBALS['lfa_stop_written_level'] ? $caps : array( 'do_not_allow' );
	}
	return $caps;
};
$record_lvl = static function ( $meta_id, $object_id, $meta_key, $value ) {
	if ( 'lf_home_team_id' === $meta_key && empty( $GLOBALS['lfa_stop_written_level'] ) ) {
		$GLOBALS['lfa_stop_written_level'] = \LeagueFlow\get_post_primary_term_id( (int) $value, 'lf_league_level' );
	}
};
$GLOBALS['lfa_stop_written_level'] = 0;
add_action( 'added_post_meta', $record_lvl, 10, 4 );
add_filter( 'map_meta_cap', $hide, 10, 4 );
$stop_run = $generator->generate( $stop_ctx + array( 'token' => $stop_pre['token'] ) );
remove_filter( 'map_meta_cap', $hide, 10 );
remove_action( 'added_post_meta', $record_lvl, 10 );
if ( ! is_wp_error( $stop_run ) ) {
	$extra_posts = array_merge( $extra_posts, $stop_run['created_ids'], $stop_run['incomplete_ids'] );
}
lfa_check( ! is_wp_error( $stop_run ) && 1 === $stop_run['created'] && $stop_run['partial'] && '' !== $stop_run['partial_error'] && array() === array_diff( $shape, array_keys( $stop_run ) ) && 1 === $stop_run['unscheduled'] && 1 === count( $stop_run['groups'] ), 'ADM-12 H5: an early stop keeps the first level\'s fixture and returns a complete, truthful partial result' );

// ---------------------------------------------------------------------------
// ADM-13 · Scoring overrides inherit until configured (C-08); standings per level.
// ---------------------------------------------------------------------------
$rules_cup = $lfa_term( 'lf_competition', 'lfa Rules Cup', 'cricket' );
$t_a = lfa_post( 'lf_team', 'lfa rules A', 'publish', array(), $ctx + array( 'lf_competition' => array( $rules_cup ) ) );
$t_b = lfa_post( 'lf_team', 'lfa rules B', 'publish', array(), $ctx + array( 'lf_competition' => array( $rules_cup ) ) );
$g1  = lfa_post( 'lf_match', 'lfa rules draw', 'publish', array( 'lf_home_team_id' => $t_a, 'lf_away_team_id' => $t_b, 'lf_status' => 'finished', 'lf_home_score' => 2, 'lf_away_score' => 2, 'lf_match_datetime' => '2026-04-01 18:00' ), $ctx + array( 'lf_competition' => array( $rules_cup ) ) );
$g2  = lfa_post( 'lf_match', 'lfa rules win', 'publish', array( 'lf_home_team_id' => $t_a, 'lf_away_team_id' => $t_b, 'lf_status' => 'finished', 'lf_home_score' => 3, 'lf_away_score' => 1, 'lf_match_datetime' => '2026-04-08 18:00' ), $ctx + array( 'lf_competition' => array( $rules_cup ) ) );
array_push( $extra_posts, $t_a, $t_b, $g1, $g2 );
delete_option( \LeagueFlow\RULE_OVERRIDES_OPTION );
$points  = static function () use ( $standings, $rules_cup, $sport, $t_a ) {
	foreach ( $standings->get_rows( $rules_cup, 0, $sport['cricket'] ) as $row ) {
		if ( $t_a === $row['team_id'] ) {
			return $row['points'];
		}
	}
	return null;
};
$baseline = $standings->get_rows( $rules_cup, 0, $sport['cricket'] );
$global   = \LeagueFlow\get_effective_rules( 'cricket', $rules_cup );
lfa_check( array( 'global' ) === array_values( array_unique( $global['source'] ) ) && (int) \LeagueFlow\get_setting( 'points_win', 3 ) + (int) \LeagueFlow\get_setting( 'points_draw', 1 ) === $points(), 'ADM-13 without overrides every value comes from the global settings' );
\LeagueFlow\set_rule_override( 'sport', 'cricket', array( 'points_win' => 7, 'points_draw' => 2, 'points_loss' => 0, 'tie_breakers' => array( 'wins', 'name' ) ) );
lfa_check( 9 === $points() && 'sport' === \LeagueFlow\get_effective_rules( 'cricket' )['source']['points_win'] && array( 'global' ) === array_values( array_unique( \LeagueFlow\get_effective_rules( 'hockey' )['source'] ) ), 'ADM-13 a sport override changes only that sport' );
\LeagueFlow\set_rule_override( 'competition', $rules_cup, array( 'points_win' => 10 ) );
lfa_check( 12 === $points() && 'competition' === \LeagueFlow\get_effective_rules( 'cricket', $rules_cup )['source']['points_win'] && 'sport' === \LeagueFlow\get_effective_rules( 'cricket', $rules_cup )['source']['points_draw'] && 7 === \LeagueFlow\get_effective_rules( 'cricket', $fix_cup )['values']['points_win'], 'ADM-13 a competition override wins for its own values only and not for another competition' );
\LeagueFlow\set_rule_override( 'competition', $rules_cup, array( 'points_win' => 10 ) );
lfa_check( 1 === count( \LeagueFlow\get_rule_overrides()['competition'] ), 'ADM-13 overrides are stored once per scope' );
\LeagueFlow\set_rule_override( 'competition', $rules_cup, array() );
\LeagueFlow\set_rule_override( 'sport', 'cricket', array() );
lfa_check( false === get_option( \LeagueFlow\RULE_OVERRIDES_OPTION ) && $baseline === $standings->get_rows( $rules_cup, 0, $sport['cricket'] ), 'ADM-13 clearing overrides restores exactly the global results' );
$_POST = wp_slash( array( 'action' => 'leagueflow_save_rule_override', 'lf_rules_scope' => 'sport', 'lf_rules_key' => 'cricket', 'lf_rules_custom' => '1', 'lf_rules' => array( 'points_win' => 4 ) ) );
$_REQUEST = $_POST;
lfa_check( 'die:403' === lfa_handle( array( $admin, 'handle_save_rule_override' ) ) && false === get_option( \LeagueFlow\RULE_OVERRIDES_OPTION ), 'ADM-13 saving scoring without a valid nonce changes nothing' );
wp_set_current_user( $author_id );
$_POST['leagueflow_rules_nonce'] = wp_create_nonce( 'leagueflow_save_rule_override_sport_cricket' );
$_REQUEST = $_POST;
lfa_check( 'die:403' === lfa_handle( array( $admin, 'handle_save_rule_override' ) ) && false === get_option( \LeagueFlow\RULE_OVERRIDES_OPTION ), 'ADM-13 a non-administrator cannot change scoring' );
wp_set_current_user( $admin_id );
$_POST['leagueflow_rules_nonce'] = wp_create_nonce( 'leagueflow_save_rule_override_sport_cricket' );
$_REQUEST = $_POST;
lfa_check( false !== strpos( lfa_handle( array( $admin, 'handle_save_rule_override' ) ), 'leagueflow_rules_saved=1' ) && 4 === \LeagueFlow\get_effective_rules( 'cricket' )['values']['points_win'], 'ADM-13 an administrator saves a sport override through the form' );
unset( $_POST['lf_rules_custom'] );
$_POST['leagueflow_rules_nonce'] = wp_create_nonce( 'leagueflow_save_rule_override_sport_cricket' );
$_REQUEST = $_POST;
lfa_handle( array( $admin, 'handle_save_rule_override' ) );
lfa_check( false === get_option( \LeagueFlow\RULE_OVERRIDES_OPTION ), 'ADM-13 unticking the override makes the sport inherit again' );
$_POST = array(); $_REQUEST = array();
$_GET = array( 'page' => 'leagueflow-standings', 'lf_sport' => 'cricket', 'competition' => $rules_cup );
$html = lfa_render( array( $admin, 'render_standings_page' ) );
lfa_check( false !== strpos( $html, 'Each level (division) has its own table' ) && false !== strpos( $html, '<h2>Competitive</h2>' ) && false !== strpos( $html, 'Drawn (tied)' ), 'ADM-13 standings default to one table per level; D appears where there are draws' );
$_GET = array( 'page' => 'leagueflow-standings', 'lf_sport' => 'cricket', 'competition' => $fix_cup, 'league_level' => $level );
$html = lfa_render( array( $admin, 'render_standings_page' ) );
lfa_check( false === strpos( $html, 'Drawn (tied)' ), 'ADM-13 the D column is hidden when no team in the table has a draw' );
$_GET = array();
$labels = ( new \LeagueFlow\Settings() )->get_tie_breaker_labels();
lfa_check( ! array_intersect( array( 'Goal difference', 'Goals for', 'Fewest goals against' ), $labels ), 'ADM-13 tie-breaker labels are sport-neutral' );

// ---------------------------------------------------------------------------
// ADM-14 · Scheduling prerequisites and scope (C-09, C-13); knockout targets; C-15.
// ---------------------------------------------------------------------------
$_GET = array( 'page' => 'leagueflow-fields', 'lf_sport' => 'cricket' );
$html = lfa_render( array( $admin, 'render_field_availability_page' ) );
lfa_check( false !== strpos( $html, 'Add venue availability first.' ) && false === strpos( $html, 'value="leagueflow_auto_schedule_matches"' ), 'ADM-14 with no availability rules the assistant form is replaced by the prerequisite (C-09)' );
$_GET = array( 'page' => 'leagueflow-fields' );
update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array( array( 'id' => 'lfa-rule', 'name' => 'lfa rule', 'venue' => 'lfa venue', 'sport_slug' => '', 'date' => '', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '20:00', 'slot_minutes' => 60, 'buffer_minutes' => 0, 'active' => true, 'notes' => '' ) ), false );
$html = lfa_render( array( $admin, 'render_field_availability_page' ) );
lfa_check( false !== strpos( $html, 'Cricket: lfa Fix Cup' ) && false !== strpos( $html, 'Hockey: lfa Hockey Cup' ), 'ADM-14 with All sports, competition choices name their sport (C-13)' );
$_GET = array();
$before_sched = get_post_meta( $fix_made['created_ids'][0] ?? 0, 'lf_match_datetime', true );
$_POST = wp_slash( array( 'action' => 'leagueflow_auto_schedule_matches', 'leagueflow_auto_schedule_matches_nonce' => wp_create_nonce( 'leagueflow_auto_schedule_matches' ), 'leagueflow_schedule_sport' => 'cricket', 'leagueflow_schedule_competition' => $hock_cup ) );
$_REQUEST = $_POST;
lfa_handle( array( $admin, 'handle_auto_schedule_matches' ) );
$_POST = array(); $_REQUEST = array();
$sched = get_transient( 'leagueflow_schedule_result_' . $admin_id );
lfa_check( is_array( $sched ) && 0 === $sched['scheduled'] && false !== strpos( implode( ' ', $sched['messages'] ), 'another sport' ) && $before_sched === get_post_meta( $fix_made['created_ids'][0] ?? 0, 'lf_match_datetime', true ), 'ADM-14 a competition from another sport is refused before scheduling anything' );
delete_transient( 'leagueflow_schedule_result_' . $admin_id );
$ko1  = lfa_post( 'lf_match', 'lfa ko semi', 'publish', array( 'lf_is_knockout' => 1, 'lf_round_order' => 1, 'lf_round_label' => 'lfa Semi' ), $ctx );
$ko2  = lfa_post( 'lf_match', 'lfa ko final', 'publish', array( 'lf_is_knockout' => 1, 'lf_round_order' => 2, 'lf_round_label' => 'lfa Final' ), $ctx );
$ko0  = lfa_post( 'lf_match', 'lfa ko earlier', 'publish', array( 'lf_is_knockout' => 1, 'lf_round_order' => 1 ), $ctx );
$kolg = lfa_post( 'lf_match', 'lfa ko league', 'publish', array( 'lf_is_knockout' => 0 ), $ctx );
$koh  = lfa_post( 'lf_match', 'lfa ko hockey', 'publish', array( 'lf_is_knockout' => 1, 'lf_round_order' => 2 ), array( 'lf_sport' => array( $sport['hockey'] ), 'lf_league_level' => array( $level ) ) );
array_push( $extra_posts, $ko1, $ko2, $ko0, $kolg, $koh );
$targets = $admin->ko_targets( $ko1, 'cricket' );
lfa_check( isset( $targets[ $ko2 ] ) && false !== strpos( $targets[ $ko2 ], 'lfa Final' ) && ! isset( $targets[ $ko1 ] ) && ! isset( $targets[ $ko0 ] ) && ! isset( $targets[ $kolg ] ) && ! isset( $targets[ $koh ] ), 'ADM-14 "Advance winner to" lists only valid later knockout matches, labelled with their round' );
update_post_meta( $ko1, 'lf_next_match_id', $koh );
$targets = $admin->ko_targets( $ko1, 'cricket' );
lfa_check( isset( $targets[ $koh ] ) && false !== strpos( $targets[ $koh ], 'no longer valid' ), 'ADM-14 an existing invalid link stays visible and marked instead of being dropped' );
$members = array( lfa_post( 'lf_player', 'lfa member pub', 'publish', array(), $ctx ), lfa_post( 'lf_player', 'lfa member private', 'private', array(), $ctx ), lfa_post( 'lf_player', 'lfa member draft', 'draft', array(), $ctx ) );
$stranger = lfa_post( 'lf_player', 'lfa stranger', 'publish', array( 'lf_team_ids' => array( (int) ( $teams[0] . '0' ) ) ), $ctx );
\LeagueFlow\set_player_team_ids( $members[0], array( $teams[1], $teams[0] ) );
update_post_meta( $members[1], 'lf_team_id', $teams[0] );
// Legacy rows stored team IDs as strings; registered meta sanitising would convert them, so write the old form directly.
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $members[2], 'meta_key' => 'lf_team_ids', 'meta_value' => serialize( array( (string) $teams[0] ) ) ) );
wp_cache_delete( $members[2], 'post_meta' );
$extra_posts = array_merge( $extra_posts, $members, array( $stranger ) );
$scoped  = \LeagueFlow\get_team_member_player_ids( $teams[0], 'any' );
$brute   = array_values( array_filter( get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), static function ( $id ) use ( $teams ) { return \LeagueFlow\player_has_team( $id, $teams[0] ); } ) );
sort( $scoped ); sort( $brute );
lfa_check( $scoped === $brute && in_array( $members[1], $scoped, true ) && in_array( $members[2], $scoped, true ) && ! in_array( $stranger, $scoped, true ), 'ADM-14 team save scans only the team\'s own members, including private/draft and legacy string IDs (C-15)' );

// Restore options this suite changed.
foreach ( array( \LeagueFlow\CURRENT_SEASONS_OPTION => $saved_map, \LeagueFlow\RULE_OVERRIDES_OPTION => $saved_rules, \LeagueFlow\Field_Availability_Manager::OPTION => $saved_fields ) as $option => $value ) {
	null === $value ? delete_option( $option ) : update_option( $option, $value, false );
}
foreach ( $extra_terms as $extra_term ) {
	wp_delete_term( $extra_term[1], $extra_term[0] );
}
foreach ( $saved_legacy as $legacy_id ) {
	update_term_meta( $legacy_id, 'lf_is_current', 1 );
}
\LeagueFlow\flush_current_season_cache();
foreach ( $extra_posts as $extra_id ) {
	wp_delete_post( $extra_id, true );
}


// ---------------------------------------------------------------------------
// ADM-15 · Safe sport controls, settings labels, roster links (final UI batch).
// ---------------------------------------------------------------------------
$t13 = array();
$t13_terms = array();
$sport_select = static function ( $html ) {
	return preg_match( '/<select id="lf_sport_choice"[^>]*>(.*?)<\/select>/s', $html, $m ) ? $m : null;
};
$save_team = static function ( $team_id, $fields, $with_nonce = true ) use ( $admin ) {
	$_POST = wp_slash( array_merge( $with_nonce ? array( 'leagueflow_team_nonce' => wp_create_nonce( 'leagueflow_save_team' ) ) : array(), array( 'lf_league_level_id' => 0 ), $fields ) );
	$admin->save_team_meta( $team_id, get_post( $team_id ) );
	$_POST = array();
	clean_post_cache( $team_id );
	wp_cache_delete( $team_id, 'lf_sport_relationships' );
	return lfa_notices();
};
$team_sports = static function ( $team_id ) {
	clean_object_term_cache( $team_id, get_post_type( $team_id ) );
	$slugs = wp_get_post_terms( $team_id, 'lf_sport', array( 'fields' => 'slugs' ) );
	sort( $slugs );
	return $slugs;
};
$disabled_slug = 'rugby';
lfa_check( ! in_array( $disabled_slug, $sports->get_enabled_sport_slugs(), true ), 'ADM-15 fixture: rugby is not enabled' );
$disabled_term = get_term_by( 'slug', $disabled_slug, 'lf_sport' );
if ( ! $disabled_term ) {
	$made_disabled = wp_insert_term( 'Rugby', 'lf_sport', array( 'slug' => $disabled_slug ) );
	$t13_terms[]   = (int) $made_disabled['term_id'];
	$disabled_term = get_term( (int) $made_disabled['term_id'], 'lf_sport' );
}

// Tag boxes: Sport is removed wherever a safe control or derivation exists.
global $wp_meta_boxes;
foreach ( array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ) as $type ) {
	add_meta_box( 'tagsdiv-lf_sport', 'Sports', '__return_null', $type, 'side' );
	add_meta_box( 'tagsdiv-lf_league_level', 'League Levels', '__return_null', $type, 'side' );
}
$admin->remove_duplicate_taxonomy_boxes();
foreach ( array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ) as $type ) {
	lfa_check( false === ( $wp_meta_boxes[ $type ]['side']['default']['tagsdiv-lf_sport'] ?? null ), "ADM-15 free-form Sports tag box removed for $type" );
}
foreach ( array( 'lf_team', 'lf_match', 'lf_calendar_event' ) as $type ) {
	lfa_check( false === ( $wp_meta_boxes[ $type ]['side']['default']['tagsdiv-lf_league_level'] ?? null ), "ADM-15 League Levels tag box removed for $type" );
}
lfa_check( is_array( $wp_meta_boxes['lf_player']['side']['default']['tagsdiv-lf_league_level'] ?? null ), 'ADM-15 player League Levels box kept (no other control for players without a team)' );

// Other free-form paths: generic sport term UI, Quick Edit, block-editor panels.
$sport_tax = get_taxonomy( 'lf_sport' );
lfa_check( false === $sport_tax->show_ui && false === $sport_tax->show_in_quick_edit && true === $sport_tax->show_admin_column && true === $sport_tax->show_in_rest, 'ADM-15 sport has no generic term screen, Quick Edit field or editor panel; list column and REST kept' );
lfa_check( true === get_taxonomy( 'lf_league_level' )->show_ui, 'ADM-15 League Levels keep their admin screen' );
lfa_check( ! use_block_editor_for_post_type( 'lf_calendar_event' ) && ! use_block_editor_for_post_type( 'lf_team' ) && ! use_block_editor_for_post_type( 'lf_match' ) && ! use_block_editor_for_post_type( 'lf_player' ), 'ADM-15 fixture: every LeagueFlow record uses the classic editor' );
foreach ( array( 'lf_team', 'lf_match', 'lf_calendar_event' ) as $type ) {
	lfa_check( false === apply_filters( 'quick_edit_show_taxonomy', true, 'lf_league_level', $type ), "ADM-15 Quick Edit has no free-form League Levels field for $type" );
}
lfa_check( true === apply_filters( 'quick_edit_show_taxonomy', true, 'lf_league_level', 'lf_player' ) && true === apply_filters( 'quick_edit_show_taxonomy', true, 'lf_season', 'lf_team' ), 'ADM-15 Quick Edit keeps player levels and team seasons' );
foreach ( array( 'lf_calendar_event' => true, 'lf_match' => false, 'lf_player' => false ) as $type => $expected ) {
	wp_dequeue_script( 'leagueflow-editor-panels' );
	wp_deregister_script( 'leagueflow-editor-panels' );
	set_current_screen( $type );
	do_action( 'enqueue_block_editor_assets' );
	$after = (array) wp_scripts()->get_data( 'leagueflow-editor-panels', 'after' );
	lfa_check( $expected === ( wp_script_is( 'leagueflow-editor-panels', 'enqueued' ) && false !== strpos( implode( '', $after ), "removeEditorPanel('taxonomy-panel-lf_league_level')" ) ), "ADM-15 block-editor League Levels panel " . ( $expected ? 'removed' : 'kept' ) . " for $type" );
}
wp_dequeue_script( 'leagueflow-editor-panels' );
set_current_screen( 'edit-lf_team' );

// New team: explicit enabled-sport selector; context preselects, All sports does not.
$t13['new'] = (int) wp_insert_post( array( 'post_type' => 'lf_team', 'post_title' => 'lfa new team', 'post_status' => 'auto-draft' ) );
$_GET = array( 'post_type' => 'lf_team', 'lf_sport' => 'hockey' );
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_metabox( get_post( $t13['new'] ) ); } );
$sel  = $sport_select( $html );
lfa_check( $sel && false !== strpos( $sel[0], ' required' ) && (bool) preg_match( '/<option value="">Choose a sport<\/option>/', $sel[1] ), 'ADM-15 new team has a required sport selector with a neutral placeholder' );
lfa_check( $sel && (bool) preg_match( '/value="hockey"\s+selected/', $sel[1] ) && false !== strpos( $sel[1], 'value="cricket"' ), 'ADM-15 sport context preselects that sport' );
lfa_check( $sel && false === strpos( $sel[1], 'value="' . $disabled_slug . '"' ), 'ADM-15 disabled sports are not offered for a new team' );
lfa_check( false !== strpos( $html, '<label for="lf_sport_choice">Sport' ), 'ADM-15 sport selector has a visible label' );
$_GET = array( 'post_type' => 'lf_team' );
$sel  = $sport_select( lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_metabox( get_post( $t13['new'] ) ); } ) );
lfa_check( $sel && false === strpos( $sel[1], 'selected' ), 'ADM-15 All sports preselects nothing (no silent first sport)' );
$_GET = array();
$roster_html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_roster_metabox( get_post( $t13['new'] ) ); } );
lfa_check( false !== strpos( $roster_html, 'Save the team with its sport first' ) && false === strpos( $roster_html, 'lf_add_to_team' ), 'ADM-15 unsaved team explains roster prerequisite and offers no add link' );

// Publish guard: a first publication without a valid sport stays a draft.
$guard = static function ( $team_id, $fields, $status = 'publish' ) use ( $admin ) {
	$_POST = wp_slash( $fields );
	$data  = $admin->hold_sportless_team_as_draft( array( 'post_type' => 'lf_team', 'post_status' => $status ), array( 'ID' => $team_id ) );
	$_POST = array();
	return $data['post_status'];
};
$nonce = wp_create_nonce( 'leagueflow_save_team' );
lfa_check( 'draft' === $guard( $t13['new'], array( 'leagueflow_team_nonce' => $nonce, 'lf_sport_choice' => '' ) ), 'ADM-15 publishing a team with no sport keeps it a draft' );
lfa_check( 'draft' === $guard( $t13['new'], array( 'leagueflow_team_nonce' => $nonce, 'lf_sport_choice' => $disabled_slug ) ), 'ADM-15 publishing with a disabled sport keeps it a draft' );
lfa_check( 'draft' === $guard( $t13['new'], array( 'leagueflow_team_nonce' => $nonce, 'lf_sport_choice' => 'lfa-invented' ) ), 'ADM-15 publishing with an unknown sport keeps it a draft' );
lfa_check( 'publish' === $guard( $t13['new'], array( 'leagueflow_team_nonce' => $nonce, 'lf_sport_choice' => 'hockey' ) ), 'ADM-15 publishing with an enabled sport is allowed' );
lfa_check( 'publish' === $guard( $t13['new'], array( 'lf_sport_choice' => '' ) ), 'ADM-15 guard only acts on a verified Team Details form' );

// Save: blank / disabled / invented sport writes no sport; an enabled one is set once.
$notices = $save_team( $t13['new'], array( 'lf_sport_choice' => '' ) );
lfa_check( array() === $team_sports( $t13['new'] ) && (bool) preg_grep( '/Choose a sport for this team/', $notices ), 'ADM-15 blank sport: saved without a sport, with an explanation' );
$notices = $save_team( $t13['new'], array( 'lf_sport_choice' => $disabled_slug ) );
lfa_check( array() === $team_sports( $t13['new'] ) && (bool) preg_grep( '/not enabled/', $notices ), 'ADM-15 disabled sport is refused' );
$save_team( $t13['new'], array( 'lf_sport_choice' => 'lfa-invented' ) );
lfa_check( array() === $team_sports( $t13['new'] ) && ! get_term_by( 'slug', 'lfa-invented', 'lf_sport' ), 'ADM-15 an invented sport creates no term and assigns nothing' );
$save_team( $t13['new'], array( 'lf_sport_choice' => 'hockey' ), false );
lfa_check( array() === $team_sports( $t13['new'] ), 'ADM-15 missing nonce writes no sport' );
wp_set_current_user( $author_id );
$save_team( $t13['new'], array( 'lf_sport_choice' => 'hockey' ) );
wp_set_current_user( $admin_id );
lfa_check( array() === $team_sports( $t13['new'] ), 'ADM-15 a user who cannot edit the team writes no sport' );
$save_team( $t13['new'], array( 'lf_sport_choice' => 'hockey' ) );
lfa_check( array( 'hockey' ) === $team_sports( $t13['new'] ), 'ADM-15 an enabled sport is assigned' );

// Existing team: sport is read-only; forged choices never change it; memberships kept.
$t13['set'] = lfa_post( 'lf_team', 'lfa set team', 'publish', array(), $ctx );
$t13['member'] = lfa_post( 'lf_player', 'lfa set member', 'private', array(), $ctx );
\LeagueFlow\assign_player_to_team( $t13['member'], $t13['set'], array( 'jersey_number' => 4 ) );
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_metabox( get_post( $t13['set'] ) ); } );
lfa_check( ! $sport_select( $html ) && false !== strpos( $html, 'id="lf_team_sport_value">' ) && (bool) preg_match( '/id="lf_team_sport_value">\s*Cricket\s*</', $html ), 'ADM-15 existing team shows its sport read-only' );
$save_team( $t13['set'], array( 'lf_sport_choice' => 'hockey', 'lf_league_level_id' => $level ) );
lfa_check( array( 'cricket' ) === $team_sports( $t13['set'] ) && in_array( $t13['set'], \LeagueFlow\get_player_team_ids( $t13['member'] ), true ), 'ADM-15 a forged sport change is ignored and the roster membership is kept' );
lfa_check( 'publish' === $guard( $t13['set'], array( 'leagueflow_team_nonce' => $nonce, 'lf_sport_choice' => '' ) ), 'ADM-15 an existing team with a sport is never demoted' );

// Legacy data: multi-sport, disabled-sport and published sportless teams are preserved.
$t13['multi'] = lfa_post( 'lf_team', 'lfa multi team', 'publish', array(), array( 'lf_sport' => array( $sport['cricket'], $sport['hockey'] ) ) );
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_metabox( get_post( $t13['multi'] ) ); } );
lfa_check( false !== strpos( $html, 'Cricket, Hockey' ), 'ADM-15 legacy multi-sport team lists every sport' );
$save_team( $t13['multi'], array( 'lf_sport_choice' => 'cricket', 'lf_league_level_id' => $level ) );
lfa_check( array( 'cricket', 'hockey' ) === $team_sports( $t13['multi'] ), 'ADM-15 legacy multi-sport associations are not dropped on save' );
$t13['disabled'] = lfa_post( 'lf_team', 'lfa disabled team', 'private', array(), array( 'lf_sport' => array( (int) $disabled_term->term_id ) ) );
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_metabox( get_post( $t13['disabled'] ) ); } );
lfa_check( false !== strpos( $html, 'Rugby (not enabled)' ), 'ADM-15 a team in a disabled sport says so' );
$save_team( $t13['disabled'], array( 'lf_league_level_id' => $level ) );
lfa_check( array( $disabled_slug ) === $team_sports( $t13['disabled'] ), 'ADM-15 a disabled-sport team keeps its sport on save' );
$t13['legacy'] = lfa_post( 'lf_team', 'lfa legacy sportless', 'publish' );
lfa_check( 'publish' === $guard( $t13['legacy'], array( 'leagueflow_team_nonce' => $nonce, 'lf_sport_choice' => '' ) ), 'ADM-15 an already published sportless team keeps its status' );
lfa_check( (bool) $sport_select( lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_metabox( get_post( $t13['legacy'] ) ); } ) ), 'ADM-15 an older sportless team gets the selector to fix it' );
$save_team( $t13['legacy'], array( 'leagueflow_requested_sport' => 'hockey' ) );
lfa_check( array( 'hockey' ) === $team_sports( $t13['legacy'] ), 'ADM-15 an older form without the selector uses only its explicit enabled context sport' );

// No silent default: player without team, and no context, gets no sport.
$t13['loner'] = lfa_post( 'lf_player', 'lfa loner', 'private' );
$_POST = wp_slash( array( 'leagueflow_player_nonce' => wp_create_nonce( 'leagueflow_save_player' ), 'leagueflow_requested_sport' => '' ) );
$admin->save_player_meta( $t13['loner'], get_post( $t13['loner'] ) );
$_POST = array();
lfa_check( array() === $team_sports( $t13['loner'] ), 'ADM-15 a player without teams or context gets no first/soccer sport' );
$_POST = wp_slash( array( 'leagueflow_player_nonce' => wp_create_nonce( 'leagueflow_save_player' ), 'leagueflow_requested_sport' => $disabled_slug ) );
$admin->save_player_meta( $t13['loner'], get_post( $t13['loner'] ) );
$_POST = array();
lfa_check( array() === $team_sports( $t13['loner'] ), 'ADM-15 a disabled context sport is never assigned' );
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_player_metabox( get_post( $t13['loner'] ) ); } );
lfa_check( false !== strpos( $html, 'None yet' ) && false !== strpos( $html, 'come from their team memberships' ), 'ADM-15 player shows derived sports read-only' );

// Calendar events: usable sport selector; only enabled sports; current disabled sport kept.
$save_event = static function ( $event_id, $fields ) use ( $admin ) {
	$_POST = wp_slash( array_merge( array( 'leagueflow_calendar_event_nonce' => wp_create_nonce( 'leagueflow_save_calendar_event' ), 'lf_league_level_id' => 0 ), $fields ) );
	$admin->save_calendar_event_meta( $event_id, get_post( $event_id ) );
	$_POST = array();
	return lfa_notices();
};
$t13['event'] = lfa_post( 'lf_calendar_event', 'lfa sport event', 'publish', array(), $ctx );
$sel = $sport_select( lfa_render( static function () use ( $admin, $t13 ) { $admin->render_calendar_event_metabox( get_post( $t13['event'] ) ); } ) );
lfa_check( $sel && (bool) preg_match( '/value="cricket"\s+selected/', $sel[1] ) && false !== strpos( $sel[1], 'value="hockey"' ), 'ADM-15 event sport selector shows the current sport and other enabled sports' );
$save_event( $t13['event'], array( 'lf_sport_choice' => 'hockey' ) );
lfa_check( array( 'hockey' ) === $team_sports( $t13['event'] ), 'ADM-15 an event can move to another enabled sport' );
$notices = $save_event( $t13['event'], array( 'lf_sport_choice' => $disabled_slug ) );
lfa_check( array( 'hockey' ) === $team_sports( $t13['event'] ) && (bool) preg_grep( '/not enabled/', $notices ), 'ADM-15 a disabled sport is refused for events' );
$save_event( $t13['event'], array( 'lf_sport_choice' => '' ) );
lfa_check( array( 'hockey' ) === $team_sports( $t13['event'] ), 'ADM-15 a blank choice keeps the event sport' );
$t13['event_off'] = lfa_post( 'lf_calendar_event', 'lfa disabled event', 'publish', array(), array( 'lf_sport' => array( (int) $disabled_term->term_id ) ) );
$sel = $sport_select( lfa_render( static function () use ( $admin, $t13 ) { $admin->render_calendar_event_metabox( get_post( $t13['event_off'] ) ); } ) );
lfa_check( $sel && (bool) preg_match( '/value="' . $disabled_slug . '"\s+selected=.selected.>Rugby \(not enabled\)/', $sel[1] ), 'ADM-15 an event in a disabled sport keeps it listed and selected' );
$save_event( $t13['event_off'], array( 'lf_sport_choice' => $disabled_slug ) );
lfa_check( array( $disabled_slug ) === $team_sports( $t13['event_off'] ), 'ADM-15 saving an event unchanged keeps its disabled sport' );
$_POST = wp_slash( array( 'leagueflow_calendar_event_nonce' => 'forged', 'lf_sport_choice' => 'cricket' ) );
$admin->save_calendar_event_meta( $t13['event'], get_post( $t13['event'] ) );
$_POST = array();
lfa_check( array( 'hockey' ) === $team_sports( $t13['event'] ), 'ADM-15 a forged event nonce writes nothing' );

// Term authority: sport terms follow the Sports page (manage_options); levels unchanged.
$t13_editor = (int) wp_insert_user( array( 'user_login' => 'lfa_terms_editor', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'lfa-terms-editor@example.test', 'role' => 'editor' ) );
wp_set_current_user( $t13_editor );
lfa_check( ! current_user_can( $sport_tax->cap->manage_terms ) && ! current_user_can( $sport_tax->cap->edit_terms ) && ! current_user_can( $sport_tax->cap->delete_terms ), 'ADM-15 editors cannot create, rename or delete sport terms' );
lfa_check( current_user_can( $sport_tax->cap->assign_terms ), 'ADM-15 editors can still assign existing sports' );
lfa_check( current_user_can( get_taxonomy( 'lf_league_level' )->cap->manage_terms ) && current_user_can( get_taxonomy( 'lf_competition' )->cap->manage_terms ) && current_user_can( get_taxonomy( 'lf_season' )->cap->manage_terms ), 'ADM-15 level/competition/season authority is unchanged (manage_categories)' );
wp_set_current_user( $admin_id );
lfa_check( current_user_can( $sport_tax->cap->manage_terms ), 'ADM-15 administrators keep sport term administration' );
wp_delete_user( $t13_editor );

// Settings: every checkbox has an id, a <label for> row title and a named purpose.
$settings_obj = new \LeagueFlow\Settings();
$settings_obj->register_settings();
$html = lfa_render( static function () { do_settings_sections( 'leagueflow-settings' ); } );
foreach ( array( 'captain_registration_open', 'player_registration_open', 'show_logos', 'show_player_photos', 'cleanup_on_uninstall' ) as $key ) {
	lfa_check( false !== strpos( $html, 'id="leagueflow_setting_' . $key . '"' ) && false !== strpos( $html, '<label for="leagueflow_setting_' . $key . '">' ), "ADM-15 settings checkbox $key has id and linked row label" );
}
lfa_check( ! preg_match( '/\/>\s*Enabled<\/label>/', $html ), 'ADM-15 no settings checkbox is labelled only "Enabled"' );
lfa_check( false !== strpos( $html, 'When the plugin is deleted, permanently delete' ), 'ADM-15 the uninstall toggle names its effect' );

// Team Roster: contextual manage/add links; add link preselects (never saves) the team.
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_roster_metabox( get_post( $t13['set'] ) ); } );
lfa_check( (bool) preg_match( '/<a href="[^"]*post\.php\?post=' . $t13['member'] . '&(amp;|#038;)action=edit" aria-label="Manage player lfa set member">Manage player<\/a>/', $html ) && false !== strpos( $html, 'Roster changes are made on each player record' ), 'ADM-15 roster rows link to Manage player with an accessible name' );
wp_set_current_user( $author_id );
$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_team_roster_metabox( get_post( $t13['set'] ) ); } );
wp_set_current_user( $admin_id );
lfa_check( false === strpos( $html, 'Manage player lfa set member' ), 'ADM-15 no Manage link for a player the user cannot edit' );
lfa_check( (bool) preg_match( '/post-new\.php\?post_type=lf_player&(amp;|#038;)lf_sport=cricket&(amp;|#038;)lf_add_to_team=' . $t13['set'] . '/', $html ), 'ADM-15 roster offers Add a new player with sport and team' );
lfa_check( false !== strpos( $html, 'role="region" tabindex="0" aria-label="Team roster"' ), 'ADM-15 roster table is a keyboard-scrollable region' );
$t13['newplayer'] = (int) wp_insert_post( array( 'post_type' => 'lf_player', 'post_title' => 'lfa new player', 'post_status' => 'auto-draft' ) );
$player_add = static function ( $team_id ) use ( $admin, $t13 ) {
	$_GET = array( 'post_type' => 'lf_player', 'lf_sport' => 'cricket', 'lf_add_to_team' => $team_id );
	$html = lfa_render( static function () use ( $admin, $t13 ) { $admin->render_player_metabox( get_post( $t13['newplayer'] ) ); } );
	$_GET = array();
	return $html;
};
lfa_check( (bool) preg_match( '/value="' . $t13['set'] . '"\s+selected/', $player_add( $t13['set'] ) ), 'ADM-15 Add player preselects the roster team' );
lfa_check( false === strpos( $player_add( 999999 ), 'selected>' ) || ! preg_match( '/name="lf_new_team_id".*?selected/s', $player_add( 999999 ) ), 'ADM-15 an unknown team ID preselects nothing' );
lfa_check( ! preg_match( '/value="' . $t13['set'] . '"\s+selected/', $player_add( $t13['member'] ) ), 'ADM-15 a non-team ID preselects nothing' );
wp_set_current_user( $author_id );
lfa_check( ! preg_match( '/value="' . $t13['set'] . '"\s+selected/', $player_add( $t13['set'] ) ), 'ADM-15 a team the user cannot edit is not preselected' );
wp_set_current_user( $admin_id );
lfa_check( array() === \LeagueFlow\get_player_team_ids( $t13['newplayer'] ), 'ADM-15 preselecting writes no membership' );

foreach ( $t13 as $t13_id ) {
	wp_delete_post( $t13_id, true );
}
foreach ( $t13_terms as $t13_term ) {
	wp_delete_term( $t13_term, 'lf_sport' );
}


// ---------------------------------------------------------------------------
// ADM-16 · Final UI polish: honest match Sport help, unique Venues submit IDs,
// filter labels grouped with their own control (layout is checked in a browser).
// ---------------------------------------------------------------------------
$lfa_dom = static function ( $html ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
	libxml_clear_errors();
	return new DOMXPath( $dom );
};
$lfa_filter_groups_ok = static function ( $html ) use ( $lfa_dom ) {
	$xpath  = $lfa_dom( $html );
	$labels = $xpath->query( '//form[contains(concat(" ", normalize-space(@class), " "), " leagueflow-filter-bar ")]//label[@for]' );
	if ( 0 === $labels->length ) {
		return false;
	}
	foreach ( $labels as $label ) {
		$parent = $label->parentNode;
		$for    = $label->getAttribute( 'for' );
		if ( ! $parent instanceof DOMElement || false === strpos( ' ' . $parent->getAttribute( 'class' ) . ' ', ' leagueflow-filter-field ' ) ) {
			return false;
		}
		if ( 1 !== $xpath->query( './/*[@id="' . $for . '"]', $parent )->length ) {
			return false;
		}
	}
	return true;
};
$lfa_ids_unique = static function ( $html ) use ( $lfa_dom ) {
	$ids = array();
	foreach ( $lfa_dom( $html )->query( '//*[@id]' ) as $node ) {
		$ids[] = $node->getAttribute( 'id' );
	}
	return count( $ids ) === count( array_unique( $ids ) );
};

$adm16_blank = lfa_post( 'lf_match', 'lfa adm16 no sport', 'draft' );
wp_set_object_terms( $adm16_blank, array(), 'lf_sport', false );
$_GET = array();
$html = lfa_render( static function () use ( $admin, $adm16_blank ) { $admin->render_match_metabox( get_post( $adm16_blank ) ); } );
lfa_check( false === strpos( $html, 'first enabled sport' ), 'ADM-16 match Sport help no longer promises a first-enabled-sport fallback' );
lfa_check( (bool) preg_match( '/<th scope="row">Sport<\/th>\s*<td>\s*<strong>Not set yet<\/strong>/', $html ), 'ADM-16 a match with no sport and no context shows "Not set yet", not Soccer' );
$_GET = array( 'post_type' => 'lf_match', 'lf_sport' => 'cricket' );
$html = lfa_render( static function () use ( $admin, $adm16_blank ) { $admin->render_match_metabox( get_post( $adm16_blank ) ); } );
$_GET = array();
lfa_check( (bool) preg_match( '/<th scope="row">Sport<\/th>\s*<td>\s*<strong>Cricket<\/strong>/', $html ), 'ADM-16 opened from a sport, the match shows that sport' );
$html = lfa_render( static function () use ( $admin, $score_match ) { $admin->render_match_metabox( get_post( $score_match ) ); } );
lfa_check( (bool) preg_match( '/<th scope="row">Sport<\/th>\s*<td>\s*<strong>Cricket<\/strong>/', $html ), 'ADM-16 an existing match keeps showing its own sport' );
clean_object_term_cache( $adm16_blank, 'lf_match' );
lfa_check( array() === wp_get_object_terms( $adm16_blank, 'lf_sport', array( 'fields' => 'ids' ) ), 'ADM-16 rendering assigns no sport' );

$adm16_fields = get_option( \LeagueFlow\Field_Availability_Manager::OPTION, null );
update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array( array( 'id' => 'lfa-adm16', 'name' => 'lfa adm16 rule', 'venue' => 'lfa venue', 'sport_slug' => 'cricket', 'date' => '', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '20:00', 'slot_minutes' => 60, 'buffer_minutes' => 0, 'active' => true, 'notes' => '' ) ), false );
$html = lfa_render( static function () use ( $admin ) { $_GET = array( 'page' => 'leagueflow-fields', 'lf_sport' => 'cricket' ); $admin->render_field_availability_page(); $_GET = array(); } );
null === $adm16_fields ? delete_option( \LeagueFlow\Field_Availability_Manager::OPTION ) : update_option( \LeagueFlow\Field_Availability_Manager::OPTION, $adm16_fields, false );
lfa_check( 0 === substr_count( $html, 'id="submit"' ), 'ADM-16 Venues & availability has no generic id="submit" button' );
lfa_check( 1 === substr_count( $html, 'id="leagueflow-availability-submit"' ) && 1 === substr_count( $html, 'id="leagueflow-schedule-submit"' ) && 1 === substr_count( $html, 'id="leagueflow-preview-slots-submit"' ), 'ADM-16 the three Venues buttons have their own IDs' );
lfa_check( 3 === substr_count( $html, 'name="submit"' ), 'ADM-16 Venues buttons keep their submitted name' );
lfa_check( $lfa_ids_unique( $html ), 'ADM-16 every id on Venues & availability is unique' );
lfa_check( $lfa_filter_groups_ok( $html ), 'ADM-16 slot-preview filter labels are grouped with their own control' );
lfa_check( (bool) preg_match( '/<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="Venue availability">\s*<table class="widefat striped leagueflow-availability-table">/', $html ), 'ADM-16 the availability list scrolls inside its own labelled region on phones' );

$html = lfa_render( static function () use ( $admin ) { $_GET = array( 'page' => 'leagueflow-brackets', 'lf_sport' => 'cricket' ); $admin->render_brackets_page(); $_GET = array(); } );
lfa_check( false !== strpos( $html, 'id="leagueflow-season-filter"' ) && $lfa_filter_groups_ok( $html ), 'ADM-16 Brackets Level/Competition/Season labels are grouped with their own select' );
$html = lfa_render( static function () use ( $admin ) { $_GET = array( 'page' => 'leagueflow-standings', 'lf_sport' => 'cricket' ); $admin->render_standings_page(); $_GET = array(); } );
lfa_check( false !== strpos( $html, 'id="leagueflow-level-filter"' ) && $lfa_filter_groups_ok( $html ), 'ADM-16 Standings filter labels are grouped with their own select' );
$html = lfa_render( static function () use ( $admin ) { $_GET = array( 'page' => 'leagueflow-requests' ); $admin->requests_page(); $_GET = array(); } );
lfa_check( false !== strpos( $html, 'id="leagueflow-request-search"' ) && $lfa_filter_groups_ok( $html ), 'ADM-16 Requests filter labels are grouped with their own control' );
$_GET = array( 'page' => 'leagueflow-setup', 'lf_sport' => 'cricket' );
$html = lfa_render( static function () use ( $setup ) { $setup->render_page( 'cricket' ); } );
$_GET = array();
lfa_check( false !== strpos( $html, 'id="leagueflow-map-type"' ) && $lfa_filter_groups_ok( $html ), 'ADM-16 Setup mapping filter labels are grouped with their own select' );
$html = lfa_render( static function () use ( $admin ) { $admin->render_settings_page(); } );
lfa_check( 3 === preg_match_all( '/<tr class="leagueflow-setting-row--points"><th scope="row"><label for="leagueflow_setting_points_(win|draw|loss)"/', $html ), 'ADM-16 the three points rows keep the row class used for bottom alignment' );
wp_delete_post( $adm16_blank, true );

// ---------------------------------------------------------------------------
// ADM-17 · Request detail: current team and resolved-placement origin (live L04).
// Display only; inspect() keeps excluding the requested team for conflict checks.
// ---------------------------------------------------------------------------
$adm17_detail = static function ( $request_id ) use ( $admin ) {
	clean_post_cache( $request_id );
	$html = lfa_render( static function () use ( $admin, $request_id ) { $admin->requests()->render_detail( $request_id ); } );
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
};
$adm17_p = array();
foreach ( range( 1, 5 ) as $n ) {
	$adm17_p[ $n ] = lfa_post( 'lf_player', 'lfa adm17 player ' . $n, 'publish', array( 'lf_email' => "lfa-adm17-$n@example.test" ), $ctx );
}
$a17_named = $req( $adm17_p[1], $teams[1] );
lfa_check( true === $service->apply_action( $a17_named, 'approve' ), 'ADM-17 fixture: named request approved' );
$text = $adm17_detail( $a17_named );
lfa_check( false !== strpos( $text, 'Request Join a team' ) && false !== strpos( $text, 'Requested team lfa Cricket B' ), 'ADM-17 approved named request still reads as Join a team with its requested team' );
lfa_check( false !== strpos( $text, 'Current team in this sport lfa Cricket B' ) && false === strpos( $text, 'Current team in this sport None' ), 'ADM-17 approved named request lists the requested team as the current team (not None)' );
lfa_check( array() === $service->inspect( $a17_named )['sport_team_ids'], 'ADM-17 service conflict list still excludes the requested team' );

\LeagueFlow\assign_player_to_team( $adm17_p[2], $teams[2] );
$a17_member = $req( $adm17_p[2], $teams[2] );
lfa_check( true === $service->apply_action( $a17_member, 'resolve_member' ), 'ADM-17 fixture: already-member request resolved' );
$text = $adm17_detail( $a17_member );
lfa_check( false !== strpos( $text, 'Current team in this sport lfa Cricket C' ) && false !== strpos( $text, 'Requested team lfa Cricket C' ), 'ADM-17 resolved already-member request shows that team as current' );

$a17_place = $req( $adm17_p[3], 0 );
$text = $adm17_detail( $a17_place );
lfa_check( false !== strpos( $text, 'Request Needs placement' ) && false !== strpos( $text, 'Current team in this sport None' ) && false === strpos( $text, 'Assigned team' ), 'ADM-17 pending placement reads Needs placement with no current team' );
lfa_check( true === $service->apply_action( $a17_place, 'assign', array( 'team_id' => $teams[3] ) ), 'ADM-17 fixture: placement assigned' );
$history_before = count( $service->get_history( $a17_place ) );
$text = $adm17_detail( $a17_place );
lfa_check( false !== strpos( $text, 'Request Placement request' ) && false === strpos( $text, 'Request Join a team' ), 'ADM-17 assigned placement keeps reading as a placement request' );
lfa_check( false !== strpos( $text, 'Assigned team lfa Cricket D' ) && false === strpos( $text, 'Requested team' ), 'ADM-17 assigned placement labels the team as Assigned team' );
lfa_check( false !== strpos( $text, 'Current team in this sport lfa Cricket D' ), 'ADM-17 assigned placement lists the assigned team as current' );
lfa_check( 'team' === $service->inspect( $a17_place )['type'] && '1' === (string) get_post_meta( $a17_place, 'lf_placed_from_queue', true ) && $history_before === count( $service->get_history( $a17_place ) ), 'ADM-17 stored request data and history are unchanged by the display' );
$html = lfa_render( static function () use ( $admin ) { $_GET = array( 'page' => 'leagueflow-requests', 'status' => 'approved', 's' => 'lfa adm17 player 3' ); $admin->requests_page(); $_GET = array(); } );
lfa_check( false !== strpos( wp_strip_all_tags( $html ), 'Placement request: placed on lfa Cricket D' ), 'ADM-17 the request list summary shows the placement origin' );

\LeagueFlow\assign_player_to_team( $adm17_p[4], $teams[0] );
$a17_other = $req( $adm17_p[4], $teams[2] );
$text = $adm17_detail( $a17_other );
lfa_check( false !== strpos( $text, 'Current team in this sport lfa Cricket A' ) && false === strpos( $text, 'Current team in this sport lfa Cricket C' ) && false === strpos( $text, 'lfa Cricket A, lfa Cricket C' ), 'ADM-17 a pending request to another team lists only the player\'s actual team' );

$a17_private = $req( $adm17_p[5], 0 );
lfa_check( true === $service->apply_action( $a17_private, 'assign', array( 'team_id' => $team_priv ) ), 'ADM-17 fixture: placement on a private team' );
wp_set_current_user( 0 );
$anon = $adm17_detail( $a17_private );
lfa_check( false !== strpos( $anon, 'That request could not be found.' ) && false === strpos( $anon, 'lfa Cricket Private Team' ) && false === strpos( $anon, 'lfa-adm17-5@example.test' ) && false === strpos( $anon, 'Assigned team' ), 'ADM-17 anonymous output is unchanged: no detail, team or e-mail' );
wp_set_current_user( $author_id );
$author_view = $adm17_detail( $a17_private );
lfa_check( false !== strpos( $author_view, 'That request could not be found.' ) && false === strpos( $author_view, 'lfa Cricket Private Team' ), 'ADM-17 a role that cannot read the request sees no detail' );
wp_set_current_user( $admin_id );
lfa_check( false !== strpos( $adm17_detail( $a17_private ), 'Assigned team lfa Cricket Private Team' ), 'ADM-17 a reviewer who can read the private team sees it' );
$request_ids = array_merge( $request_ids, array( $a17_named, $a17_member, $a17_place, $a17_other, $a17_private ) );
$players     = array_merge( $players, array_values( $adm17_p ) );

// Cleanup of this suite's own fixtures.
foreach ( array_merge( $teams, array( $hockey_team, $cricket_trash_team, $dated_new, $dated_old, $blank_date, $trashed, $event_dated, $event_undated, $score_match, $forfeit, $player, $request, $league_page, $own_private, $hockey_member, $team_comp, $team_trash, $team_draft, $team_priv ), $drafts, $private_players, $players, $request_ids ) as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_user( $author_id );
foreach ( array( 'lf_season' => $made['term_id'], 'lf_competition' => is_wp_error( $programmatic ) ? 0 : $programmatic['term_id'] ) as $taxonomy => $term_id ) {
	if ( $term_id ) {
		wp_delete_term( $term_id, $taxonomy );
	}
}
update_option( 'leagueflow_enabled_sports', $enabled );

$failures = count( $GLOBALS['lfa_fail'] );
WP_CLI::log( sprintf( 'wordpress-admin: %d passed, %d failed', $GLOBALS['lfa_pass'], $failures ) );
if ( $failures ) {
	WP_CLI::error( implode( "\n", $GLOBALS['lfa_fail'] ) );
}
WP_CLI::success( 'LeagueFlow admin workflow regressions passed.' );
