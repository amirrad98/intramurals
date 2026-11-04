<?php
/** Automatic score entry and atomic result regressions on a disposable site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

$GLOBALS['lfr_checks'] = 0;
$GLOBALS['lfr_posts'] = array();
$lfr_terms = array();
$lfr_original_user = get_current_user_id();
$lfr_original_post = $_POST;
$lfr_original_get = $_GET;
$lfr_admin_id = get_user_by( 'login', 'admin' )->ID;
$lfr_notices = get_transient( 'leagueflow_notices_' . $lfr_admin_id );

function lfr_check( $condition, $message ) {
	++$GLOBALS['lfr_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message . ' Notices: ' . wp_json_encode( get_transient( 'leagueflow_notices_' . get_current_user_id() ) ) ); }
}
function lfr_post( $type, $context, $meta = array(), $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Result regression ' . wp_generate_uuid4() ), $extra ), true );
	lfr_check( ! is_wp_error( $id ) && $id > 0, 'Create result regression record.' );
	$GLOBALS['lfr_posts'][] = (int) $id;
	foreach ( $context as $taxonomy => $term_id ) { wp_set_object_terms( $id, array( (int) $term_id ), $taxonomy ); }
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}

try {
	wp_set_current_user( $lfr_admin_id );
	$_POST = array();
	$_GET = array();
	// Drive the actual registered admin writer, including its title-save guard.
	$modules = new ReflectionProperty( \LeagueFlow\Plugin::class, 'modules' );
	$modules->setAccessible( true );
	$admin = null;
	foreach ( $modules->getValue( \LeagueFlow\Plugin::instance() ) as $module ) {
		if ( $module instanceof \LeagueFlow\Admin ) { $admin = $module; break; }
	}
	lfr_check( $admin instanceof \LeagueFlow\Admin, 'Find registered admin result writer.' );
	$context = array();
	foreach ( array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ) as $taxonomy ) {
		$term = wp_insert_term( 'Result regression ' . $taxonomy . ' ' . wp_generate_uuid4(), $taxonomy );
		lfr_check( ! is_wp_error( $term ), 'Create result context.' );
		$context[ $taxonomy ] = (int) $term['term_id'];
		$lfr_terms[ $taxonomy ][] = (int) $term['term_id'];
	}
	$a = lfr_post( 'lf_team', $context );
	$b = lfr_post( 'lf_team', $context );
	$make = static function( $meta = array(), $extra = array() ) use ( $a, $b, $context ) {
		return lfr_post( 'lf_match', $context, array_merge( array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_status' => 'scheduled' ), $meta ), $extra );
	};
	$save = static function( $id, $fields = array(), $token = null ) use ( $admin ) {
		$values = array( 'post_ID' => (string) $id, 'leagueflow_match_nonce' => wp_create_nonce( 'leagueflow_save_match' ), 'leagueflow_match_state' => null === $token ? \LeagueFlow\Admin::match_form_state( $id ) : $token );
		foreach ( array( 'lf_home_team_id', 'lf_away_team_id', 'lf_home_score', 'lf_away_score', 'lf_status', 'lf_outcome', 'lf_match_datetime', 'lf_venue', 'lf_is_knockout', 'lf_round_order', 'lf_round_label', 'lf_winner_team_id', 'lf_next_match_id', 'lf_next_match_slot' ) as $key ) { $values[ $key ] = get_post_meta( $id, $key, true ); }
		if ( get_post_meta( $id, '_lf_result_keep_status', true ) ) { $values['lf_keep_status'] = '1'; }
		$_POST = wp_slash( array_merge( $values, $fields ) );
		try { $admin->save_match_meta( $id, get_post( $id ) ); }
		finally { $_POST = array(); clean_post_cache( $id ); }
	};
	$transitions = array();
	$observe = static function( $id, $new, $old ) use ( &$transitions ) {
		$transitions[] = array( 'id' => $id, 'new' => $new, 'old' => $old, 'home' => get_post_meta( $id, 'lf_home_score', true ), 'away' => get_post_meta( $id, 'lf_away_score', true ), 'sport' => \LeagueFlow\get_post_primary_term_id( $id, 'lf_sport' ) );
	};
	add_action( 'leagueflow_match_status_changed', $observe, 100, 3 );

	$id = $make();
	$transitions = array();
	$save( $id, array( 'lf_home_score' => '3', 'lf_away_score' => '1' ) );
	lfr_check( 'finished' === get_post_meta( $id, 'lf_status', true ), 'Ordinary Update automatically finalizes a complete scheduled score.' );
	lfr_check( 1 === count( $transitions ) && 'scheduled' === $transitions[0]['old'] && 'finished' === $transitions[0]['new'] && '3' === $transitions[0]['home'] && '1' === $transitions[0]['away'] && $context['lf_sport'] === $transitions[0]['sport'], 'One lifecycle notification sees the complete saved result and context.' );
	$rows = array_column( ( new \LeagueFlow\Standings_Service() )->get_rows( $context['lf_competition'], $context['lf_season'], $context['lf_sport'], $context['lf_league_level'] ), null, 'team_id' );
	lfr_check( 1 === $rows[ $a ]['played'] && 1 === $rows[ $a ]['wins'] && 3 === $rows[ $a ]['goals_for'], 'Auto-finalized result immediately contributes to derived standings.' );
	$transitions = array();
	$save( $id, array( 'lf_home_score' => '0', 'lf_away_score' => '0' ) );
	lfr_check( 'finished' === get_post_meta( $id, 'lf_status', true ) && '0' === get_post_meta( $id, 'lf_home_score', true ) && 0 === count( $transitions ), 'Corrections to a Finished result keep explicit zeros without inventing another status transition.' );
	$rows = array_column( ( new \LeagueFlow\Standings_Service() )->get_rows( $context['lf_competition'], $context['lf_season'], $context['lf_sport'], $context['lf_league_level'] ), null, 'team_id' );
	lfr_check( 1 === $rows[ $a ]['played'] && 1 === $rows[ $a ]['draws'] && 0 === $rows[ $a ]['wins'], 'A correction replaces the calculated outcome, without double-counting a match.' );

	foreach ( array( array( '', '' ), array( '1', '' ), array( '', '0' ) ) as $scores ) {
		$partial = $make();
		$save( $partial, array( 'lf_home_score' => $scores[0], 'lf_away_score' => $scores[1] ) );
		lfr_check( 'scheduled' === get_post_meta( $partial, 'lf_status', true ) && $scores[0] === get_post_meta( $partial, 'lf_home_score', true ) && $scores[1] === get_post_meta( $partial, 'lf_away_score', true ), 'Incomplete scores stay Scheduled and blank stays distinct from zero.' );
	}
	$zero = $make();
	$save( $zero, array( 'lf_home_score' => '000', 'lf_away_score' => '0' ) );
	lfr_check( 'finished' === get_post_meta( $zero, 'lf_status', true ) && '0' === get_post_meta( $zero, 'lf_home_score', true ), 'Two explicit zeros finalize and normalize correctly.' );
	foreach ( array( 'live', 'postponed', 'cancelled' ) as $status ) {
		$manual = $make( array( 'lf_status' => $status ) );
		$save( $manual, array( 'lf_home_score' => '3', 'lf_away_score' => '1' ) );
		lfr_check( $status === get_post_meta( $manual, 'lf_status', true ), 'Complete scores preserve explicit ' . $status . ' status.' );
		$save( $manual, array( 'lf_finalize_result' => '1' ) );
		lfr_check( 'finished' === get_post_meta( $manual, 'lf_status', true ), 'The explicit final button can finish a ' . $status . ' match.' );
	}
	$held = $make();
	$save( $held, array( 'lf_home_score' => '2', 'lf_away_score' => '1', 'lf_keep_status' => '1' ) );
	lfr_check( 'scheduled' === get_post_meta( $held, 'lf_status', true ) && '1' === get_post_meta( $held, '_lf_result_keep_status', true ), 'Staff can deliberately keep preliminary complete scores Scheduled.' );
	$save( $held, array( 'lf_venue' => 'Changed venue' ) );
	lfr_check( 'scheduled' === get_post_meta( $held, 'lf_status', true ), 'An unrelated edit retains the explicitly chosen manual status mode.' );
	$save( $held, array( 'lf_keep_status' => '' ) );
	lfr_check( 'finished' === get_post_meta( $held, 'lf_status', true ) && ! metadata_exists( 'post', $held, '_lf_result_keep_status' ), 'Unchecking manual status restores automatic completion.' );
	$save( $held, array( 'lf_status' => 'scheduled', 'lf_keep_status' => '1' ) );
	lfr_check( 'scheduled' === get_post_meta( $held, 'lf_status', true ), 'A finished result can be deliberately reopened while keeping its scores.' );
	$save( $held, array( 'lf_home_score' => '', 'lf_away_score' => '', 'lf_keep_status' => '' ) );
	lfr_check( 'scheduled' === get_post_meta( $held, 'lf_status', true ) && ! metadata_exists( 'post', $held, 'lf_home_score' ) && ! metadata_exists( 'post', $held, 'lf_away_score' ), 'Clearing both scores while reopening removes the result without making a 0-0 draw.' );
	foreach ( array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ) as $outcome ) {
		$forfeit = $make();
		$save( $forfeit, array( 'lf_outcome' => $outcome ) );
		lfr_check( 'finished' === get_post_meta( $forfeit, 'lf_status', true ) && $outcome === get_post_meta( $forfeit, 'lf_outcome', true ) && ! metadata_exists( 'post', $forfeit, 'lf_home_score' ), 'A deliberate ' . $outcome . ' automatically finishes without invented scores.' );
	}

	foreach ( array( '-1', '2.5', '1e2', 'NaN', array( '3' ), (string) PHP_INT_MAX . '0' ) as $bad ) {
		$invalid = $make( array( 'lf_venue' => 'Original venue' ) );
		$transitions = array();
		$save( $invalid, array( 'lf_home_score' => $bad, 'lf_away_score' => '1', 'lf_venue' => 'Should not save' ) );
		lfr_check( 'scheduled' === get_post_meta( $invalid, 'lf_status', true ) && '' === get_post_meta( $invalid, 'lf_home_score', true ) && 'Original venue' === get_post_meta( $invalid, 'lf_venue', true ) && ! $transitions, 'Invalid scores reject the complete match update before any write.' );
	}
	foreach ( array( 0, $a, 999999999 ) as $away_id ) {
		$invalid = $make();
		$save( $invalid, array( 'lf_away_team_id' => $away_id, 'lf_home_score' => '3', 'lf_away_score' => '1' ) );
		lfr_check( 'scheduled' === get_post_meta( $invalid, 'lf_status', true ) && (string) $b === get_post_meta( $invalid, 'lf_away_team_id', true ) && '' === get_post_meta( $invalid, 'lf_home_score', true ), 'Missing, duplicate or nonexistent participants cannot finalize a result or overwrite its valid teams.' );
	}
	$note_match = $make( array( 'lf_set_scores' => 'Original note' ) );
	$save( $note_match, array( 'lf_home_score' => '2', 'lf_away_score' => '1' ) );
	lfr_check( 'Original note' === get_post_meta( $note_match, 'lf_set_scores', true ), 'Fields not rendered/submitted for another sport are retained.' );
	$multiline = "25–20\n21–25\n15–12\nC:\\Volleyball\\Results";
	$save( $note_match, array( 'lf_set_scores' => $multiline ) );
	lfr_check( $multiline === get_post_meta( $note_match, 'lf_set_scores', true ), 'Set-by-set notes preserve line breaks and literal backslashes through real slashed form input.' );
	$save( $note_match, array( 'lf_set_scores' => '' ) );
	lfr_check( ! metadata_exists( 'post', $note_match, 'lf_set_scores' ), 'An explicitly cleared visible note is deleted.' );
	$wrong_context = $make();
	$competition_sport = get_term_meta( $context['lf_competition'], 'lf_sport_slug', true );
	update_term_meta( $context['lf_competition'], 'lf_sport_slug', 'a-different-sport' );
	$save( $wrong_context, array( 'lf_home_score' => '3', 'lf_away_score' => '1' ) );
	lfr_check( 'scheduled' === get_post_meta( $wrong_context, 'lf_status', true ) && '' === get_post_meta( $wrong_context, 'lf_home_score', true ), 'A competition owned by another sport blocks result entry instead of silently misfiling it.' );
	update_post_meta( $wrong_context, 'lf_home_score', '3' );
	update_post_meta( $wrong_context, 'lf_away_score', '1' );
	$admin->handle_match_bulk_actions( '', 'leagueflow_set_status_finished', array( $wrong_context ) );
	lfr_check( 'scheduled' === get_post_meta( $wrong_context, 'lf_status', true ), 'Bulk finalization cannot bypass a competition sport mismatch.' );
	if ( '' === $competition_sport ) { delete_term_meta( $context['lf_competition'], 'lf_sport_slug' ); }
	else { update_term_meta( $context['lf_competition'], 'lf_sport_slug', $competition_sport ); }
	$stale = $make();
	$old_token = \LeagueFlow\Admin::match_form_state( $stale );
	update_post_meta( $stale, 'lf_home_score', '7' );
	$save( $stale, array( 'lf_home_score' => '1', 'lf_away_score' => '0' ), $old_token );
	lfr_check( '7' === get_post_meta( $stale, 'lf_home_score', true ) && '' === get_post_meta( $stale, 'lf_away_score', true ) && 'scheduled' === get_post_meta( $stale, 'lf_status', true ), 'A stale browser form cannot overwrite another writer or auto-finalize its result.' );

	// Refuse one metadata write after the first score has saved: the transaction
	// must restore all earlier fields and suppress lifecycle observers.
	foreach ( array( 'lf_away_score', 'lf_status' ) as $blocked_key ) {
		$atomic = $make( array( 'lf_home_score' => '1', 'lf_away_score' => '1', 'lf_venue' => 'Before transaction' ) );
		$transitions = array();
		$deny = static function( $check, $object_id, $key ) use ( $atomic, $blocked_key ) { return $atomic === (int) $object_id && $blocked_key === $key ? false : $check; };
		add_filter( 'update_post_metadata', $deny, 10, 3 );
		try { $save( $atomic, array( 'lf_home_score' => '4', 'lf_away_score' => '2', 'lf_venue' => 'Must roll back' ) ); }
		finally { remove_filter( 'update_post_metadata', $deny, 10 ); }
		lfr_check( '1' === get_post_meta( $atomic, 'lf_home_score', true ) && '1' === get_post_meta( $atomic, 'lf_away_score', true ) && 'scheduled' === get_post_meta( $atomic, 'lf_status', true ) && 'Before transaction' === get_post_meta( $atomic, 'lf_venue', true ) && ! $transitions, 'A refused ' . $blocked_key . ' write rolls back every result field and emits no lifecycle transition.' );
	}
	global $wpdb;
	$atomic = $make();
	$transitions = array();
	$fail_commit = static function( $sql ) { return 'COMMIT' === trim( $sql ) ? 'LEAGUEFLOW_TEST_REJECTED_COMMIT' : $sql; };
	$old_suppress = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_commit );
	try { $save( $atomic, array( 'lf_home_score' => '4', 'lf_away_score' => '2' ) ); }
	finally { remove_filter( 'query', $fail_commit ); $wpdb->suppress_errors( $old_suppress ); }
	lfr_check( 'scheduled' === get_post_meta( $atomic, 'lf_status', true ) && '' === get_post_meta( $atomic, 'lf_home_score', true ) && '' === get_post_meta( $atomic, 'lf_away_score', true ) && ! $transitions, 'A COMMIT failure rolls back scores and status without announcing a result that did not save.' );
	$transitions = array();
	$save( $atomic, array( 'lf_home_score' => '4', 'lf_away_score' => '2' ) );
	lfr_check( 'finished' === get_post_meta( $atomic, 'lf_status', true ) && 1 === count( $transitions ), 'The next valid save works after rollback and does not inherit a queued transition.' );
	$unlocked = $make();
	$lock_name = \LeagueFlow\db_lock_name( 'result', (string) $unlocked );
	$deny_lock = static function( $sql ) use ( $lock_name ) { return false !== strpos( $sql, 'GET_LOCK(' ) && false !== strpos( $sql, $lock_name ) ? 'SELECT 0' : $sql; };
	add_filter( 'query', $deny_lock );
	try { $save( $unlocked, array( 'lf_home_score' => '4', 'lf_away_score' => '2' ) ); }
	finally { remove_filter( 'query', $deny_lock ); }
	lfr_check( 'scheduled' === get_post_meta( $unlocked, 'lf_status', true ) && '' === get_post_meta( $unlocked, 'lf_home_score', true ), 'Unavailable result lock prevents any automatic completion or score write.' );
	remove_action( 'leagueflow_match_status_changed', $observe, 100 );
	WP_CLI::success( 'Automatic score entry and atomic results: ' . $GLOBALS['lfr_checks'] . ' assertions passed.' );
} finally {
	if ( isset( $observe ) ) { remove_action( 'leagueflow_match_status_changed', $observe, 100 ); }
	$_POST = array();
	wp_set_current_user( $lfr_admin_id );
	foreach ( array_reverse( $GLOBALS['lfr_posts'] ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfr_terms as $taxonomy => $ids ) { foreach ( $ids as $id ) { wp_delete_term( $id, $taxonomy ); } }
	if ( false === $lfr_notices ) { delete_transient( 'leagueflow_notices_' . $lfr_admin_id ); }
	else { set_transient( 'leagueflow_notices_' . $lfr_admin_id, $lfr_notices, MINUTE_IN_SECONDS ); }
	$_POST = $lfr_original_post;
	$_GET = $lfr_original_get;
	wp_set_current_user( $lfr_original_user );
}
