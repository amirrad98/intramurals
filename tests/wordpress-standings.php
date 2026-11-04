<?php
/** Standings and knockout lifecycle regressions on a disposable WordPress site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

$GLOBALS['lfst_checks'] = 0;
$GLOBALS['lfst_posts']  = array();
$lfst_terms            = array();
$lfst_original_user    = get_current_user_id();
$lfst_admin            = get_user_by( 'login', 'admin' )->ID;
$lfst_settings         = get_option( 'leagueflow_settings' );
$lfst_overrides        = get_option( \LeagueFlow\RULE_OVERRIDES_OPTION );
$lfst_notices          = get_transient( 'leagueflow_notices_' . $lfst_admin );

function lfst_check( $condition, $message ) {
	++$GLOBALS['lfst_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfst_post( $type, $title, $context, $meta = array(), $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ), $extra ), true );
	lfst_check( ! is_wp_error( $id ) && $id > 0, 'Create standings fixture.' );
	$GLOBALS['lfst_posts'][] = (int) $id;
	foreach ( $context as $tax => $term ) { wp_set_object_terms( $id, array( (int) $term ), $tax ); }
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}
function lfst_rows( $service, $context ) {
	return array_column( $service->get_rows( $context['lf_competition'], $context['lf_season'], $context['lf_sport'], $context['lf_league_level'] ), null, 'team_id' );
}

try {
	wp_set_current_user( $lfst_admin );
	update_option( 'leagueflow_settings', \LeagueFlow\defaults() );
	delete_option( \LeagueFlow\RULE_OVERRIDES_OPTION );
	$ctx = array();
	foreach ( array( 'lf_competition', 'lf_season', 'lf_sport', 'lf_league_level' ) as $taxonomy ) {
		$term = wp_insert_term( 'Standings ' . $taxonomy . ' ' . wp_generate_uuid4(), $taxonomy );
		lfst_check( ! is_wp_error( $term ), 'Create standings context.' );
		$ctx[ $taxonomy ] = (int) $term['term_id'];
		$lfst_terms[ $taxonomy ][] = (int) $term['term_id'];
	}
	$a = lfst_post( 'lf_team', 'Standings Falcons', $ctx );
	$b = lfst_post( 'lf_team', 'Standings Wolves', $ctx );
	$service = new \LeagueFlow\Standings_Service();
	$knockout = new \LeagueFlow\Knockout_Service();
	$match = static function( $meta = array(), $extra = array() ) use ( $ctx, $a, $b ) {
		return lfst_post( 'lf_match', 'Standings Falcons vs Wolves', $ctx, array_merge( array(
			'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_home_score' => '3', 'lf_away_score' => '1', 'lf_status' => 'finished',
		), $meta ), $extra );
	};

	foreach ( array( '0', 0, '000', '12', PHP_INT_MAX, (string) PHP_INT_MAX ) as $value ) {
		lfst_check( \LeagueFlow\Match_Status::is_valid_score( $value ), 'Valid explicit whole-number score.' );
	}
	foreach ( array( '', null, false, true, array(), -1, '-1', '1.5', 1.5, '1e2', 'NaN', ' 1', "1\n", (string) PHP_INT_MAX . '0' ) as $value ) {
		lfst_check( ! \LeagueFlow\Match_Status::is_valid_score( $value ), 'Malformed score is rejected before calculation.' );
	}

	$undated = $match();
	lfst_check( ! metadata_exists( 'post', $undated, 'lf_match_datetime' ), 'Undated result really lacks the datetime meta row.' );
	wp_set_current_user( 0 );
	$rows = lfst_rows( $service, $ctx );
	lfst_check( 1 === $rows[ $a ]['played'] && 3 === $rows[ $a ]['points'] && 3 === $rows[ $a ]['goals_for'] && 1 === $rows[ $b ]['losses'], 'A finished result counts without a schedule date.' );
	wp_set_current_user( $lfst_admin );
	$scored_scheduled = $match( array( 'lf_status' => 'scheduled' ) );
	$live = $match( array( 'lf_status' => 'live' ) );
	$no_scores = $match( array( 'lf_status' => 'scheduled', 'lf_home_score' => '', 'lf_away_score' => '' ) );
	$draft = $match( array(), array( 'post_status' => 'draft' ) );
	$ko = $match( array( 'lf_is_knockout' => 1 ) );
	$incomplete = $match( array( 'lf_away_score' => '' ) );
	$malformed = array();
	foreach ( array( 'two', '-1', '1.5', (string) PHP_INT_MAX . '0' ) as $score ) {
		$malformed[] = $match( array( 'lf_home_score' => $score ) );
	}
	$same_team = $match( array( 'lf_away_team_id' => $a ) );
	$missing_team = $match( array( 'lf_away_team_id' => 0 ) );
	$draft_team = lfst_post( 'lf_team', 'Unpublished team', $ctx, array(), array( 'post_status' => 'draft' ) );
	$unpublished_team_match = $match( array( 'lf_away_team_id' => $draft_team ) );
	$rows = lfst_rows( $service, $ctx );
	lfst_check( 2 === count( $rows ) && 1 === $rows[ $a ]['played'] && 3 === $rows[ $a ]['points'], 'Draft, knockout, live, invalid score and invalid team fixtures never affect the official table, including for administrators.' );
	$diagnostics = $service->get_diagnostics( $ctx['lf_competition'], $ctx['lf_season'], $ctx['lf_sport'], $ctx['lf_league_level'] );
	lfst_check( array( 'counted' => 1, 'scored_not_finished' => 2, 'unfinished' => 1, 'incomplete' => 5, 'unpublished' => 1, 'knockout' => 1, 'invalid_teams' => 3 ) === $diagnostics['counts'], 'Staff diagnostics explain every excluded fixture with matching totals.' );
	lfst_check( array( $scored_scheduled, $live ) === $diagnostics['examples']['scored_not_finished'], 'Scored-but-unfinished diagnostics point to the affected records.' );
	wp_set_current_user( 0 );
	lfst_check( 0 === array_sum( $service->get_diagnostics( $ctx['lf_competition'] )['counts'] ), 'Public callers cannot obtain staff diagnostic counts or record IDs.' );
	wp_set_current_user( $lfst_admin );

	$draw = $match( array( 'lf_home_score' => '0', 'lf_away_score' => '0' ) );
	$forfeit = $match( array( 'lf_home_score' => '', 'lf_away_score' => '', 'lf_outcome' => 'forfeit_home' ) );
	$double = $match( array( 'lf_home_score' => '', 'lf_away_score' => '', 'lf_outcome' => 'double_forfeit' ) );
	$rows = lfst_rows( $service, $ctx );
	lfst_check( 4 === $rows[ $a ]['played'] && 1 === $rows[ $a ]['wins'] && 1 === $rows[ $a ]['draws'] && 2 === $rows[ $a ]['losses'] && 4 === $rows[ $a ]['points'], 'Zero-zero draws and scoreless forfeits produce correct played/W/D/L and points.' );
	lfst_check( 3 === $rows[ $a ]['goals_for'] && 4 === $rows[ $a ]['goals_against'] && -1 === $rows[ $a ]['goal_difference'], 'Forfeit walkover score affects totals once and a double forfeit adds no invented score.' );
	update_post_meta( $a, 'lf_points_adjustment', -2 );
	$rows = lfst_rows( $service, $ctx );
	lfst_check( 4 === $rows[ $a ]['points_base'] && -2 === $rows[ $a ]['adjustment'] && 2 === $rows[ $a ]['points'], 'Manual adjustment stays separate from derived points.' );
	delete_post_meta( $a, 'lf_points_adjustment' );

	// A hidden result remains hidden when the public table is calculated.
	$protected = $match( array( 'lf_home_score' => '99' ), array( 'post_password' => 'test-only' ) );
	wp_set_current_user( 0 );
	$rows = lfst_rows( $service, $ctx );
	lfst_check( 4 === $rows[ $a ]['played'] && 3 === $rows[ $a ]['goals_for'], 'Password-protected fixtures do not leak into public totals.' );
	wp_set_current_user( $lfst_admin );
	wp_delete_post( $protected, true );

	// The same published match supplies its teams even when their season tags
	// are incomplete; diagnosis must not require staff to re-enter results.
	wp_set_object_terms( $a, array(), 'lf_season' );
	lfst_check( isset( lfst_rows( $service, $ctx )[ $a ] ), 'Eligible match supplies its participant when a team season tag is missing.' );
	wp_set_object_terms( $a, array( $ctx['lf_season'] ), 'lf_season' );

	// Omitting the competition filter must not discard rules on the fixtures
	// being combined into a sport-wide standings table.
	$second_cup = wp_insert_term( 'Standings second cup ' . wp_generate_uuid4(), 'lf_competition' );
	lfst_check( ! is_wp_error( $second_cup ), 'Create second competition.' );
	$second_cup = (int) $second_cup['term_id'];
	$lfst_terms['lf_competition'][] = $second_cup;
	$second_match = $match();
	wp_set_object_terms( $second_match, array( $second_cup ), 'lf_competition' );
	\LeagueFlow\set_rule_override( 'competition', $ctx['lf_competition'], array( 'points_win' => 5 ) );
	\LeagueFlow\set_rule_override( 'competition', $second_cup, array( 'points_win' => 7 ) );
	$combined = array_column( $service->get_rows( 0, $ctx['lf_season'], $ctx['lf_sport'], $ctx['lf_league_level'] ), null, 'team_id' );
	lfst_check( 13 === $combined[ $a ]['points'] && 6 === $combined[ $b ]['points'], 'A sport-wide table applies each competition\'s win points, including forfeit wins, before aggregation.' );
	lfst_check( 6 === lfst_rows( $service, $ctx )[ $a ]['points'], 'An explicitly filtered competition keeps its own points.' );
	delete_option( \LeagueFlow\RULE_OVERRIDES_OPTION );

	// Knockout overrides and forfeits are final only after the status is final.
	$source = $match( array( 'lf_status' => 'scheduled', 'lf_is_knockout' => 1, 'lf_winner_team_id' => $a, 'lf_round_order' => 1 ) );
	$target = $match( array( 'lf_status' => 'scheduled', 'lf_is_knockout' => 1, 'lf_home_team_id' => 0, 'lf_away_team_id' => 0, 'lf_home_score' => '', 'lf_away_score' => '', 'lf_round_order' => 2 ) );
	update_post_meta( $source, 'lf_next_match_id', $target );
	update_post_meta( $source, 'lf_next_match_slot', 'home' );
	lfst_check( 0 === $knockout->determine_winner_team_id( $source ) && 0 === $knockout->advance_winner( $source ), 'Unfinished override cannot declare or advance a winner.' );
	delete_post_meta( $source, 'lf_winner_team_id' );
	update_post_meta( $source, 'lf_outcome', 'forfeit_away' );
	lfst_check( 0 === $knockout->determine_winner_team_id( $source ), 'Unfinished forfeit cannot declare a winner.' );
	update_post_meta( $source, 'lf_status', 'finished' );
	lfst_check( $a === $knockout->advance_winner( $source ) && $a === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Finished forfeit advances to its unplayed target.' );
	lfst_check( $source === (int) get_post_meta( $target, '_lf_home_advanced_from', true ), 'Advancement records which match owns the slot.' );
	update_post_meta( $source, 'lf_outcome', 'forfeit_home' );
	lfst_check( $b === $knockout->advance_winner( $source ) && $b === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Corrected final result updates its own still-unplayed next-round slot.' );
	update_post_meta( $target, 'lf_home_score', '0' );
	update_post_meta( $source, 'lf_outcome', 'forfeit_away' );
	lfst_check( 0 === $knockout->advance_winner( $source ) && $b === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Even an explicit zero score protects a downstream fixture from participant replacement.' );
	delete_post_meta( $target, 'lf_home_score' );
	foreach ( array( 'live', 'finished', 'cancelled' ) as $state ) {
		update_post_meta( $target, 'lf_status', $state );
		lfst_check( 0 === $knockout->advance_winner( $source ) && $b === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Downstream ' . $state . ' fixture is not overwritten.' );
	}
	update_post_meta( $target, 'lf_status', 'scheduled' );
	delete_post_meta( $target, '_lf_home_advanced_from' );
	lfst_check( 0 === $knockout->advance_winner( $source ) && $b === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'A manually populated slot cannot be overwritten by automatic advancement.' );
	update_post_meta( $target, 'lf_home_team_id', 0 );
	update_post_meta( $target, 'lf_away_team_id', $a );
	lfst_check( 0 === $knockout->advance_winner( $source ), 'Advancement cannot make a team play itself.' );
	update_post_meta( $target, 'lf_away_team_id', 0 );
	update_post_meta( $target, 'lf_is_knockout', 0 );
	lfst_check( ! $knockout->can_advance_to( $source, $target ), 'A regular league fixture is not a valid bracket target.' );
	update_post_meta( $target, 'lf_is_knockout', 1 );

	// Model another request committing between the pre-lock graph read and lock
	// acquisition. A direct DB write deliberately leaves this request's cache
	// stale, as it would be when another PHP process saves the match.
	global $wpdb;
	$target_lock = \LeagueFlow\db_lock_name( 'result', (string) $target );
	foreach ( array( $target => 'live', $source => 'scheduled' ) as $changed_id => $changed_state ) {
		$changed = false;
		$change_before_lock = static function( $sql ) use ( $wpdb, $target_lock, $changed_id, $changed_state, &$changed ) {
			if ( ! $changed && false !== strpos( $sql, 'GET_LOCK(' ) && false !== strpos( $sql, $target_lock ) ) {
				$changed = true;
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $changed_state ), array( 'post_id' => $changed_id, 'meta_key' => 'lf_status' ) );
			}
			return $sql;
		};
		add_filter( 'query', $change_before_lock );
		try {
			lfst_check( 0 === $knockout->advance_winner( $source ) && $changed && 0 === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Advancement refreshes a concurrently changed source or target after taking the result lock.' );
		} finally {
			remove_filter( 'query', $change_before_lock );
			update_post_meta( $source, 'lf_status', 'finished' );
			update_post_meta( $target, 'lf_status', 'scheduled' );
		}
	}
	delete_post_meta( $source, 'lf_outcome' );
	update_post_meta( $source, 'lf_home_score', 'bad' );
	lfst_check( 0 === $knockout->determine_winner_team_id( $source ), 'Malformed imported score cannot decide a knockout winner.' );
	update_post_meta( $source, 'lf_home_score', '3' );
	wp_set_current_user( 0 );
	lfst_check( 0 === $knockout->advance_winner( $source ), 'Anonymous result reads cannot advance a bracket.' );

	// Bulk finalization is another result writer: it must use the same locks,
	// respect late permission changes and refuse incomplete played scores.
	wp_set_current_user( $lfst_admin );
	$sports = new \LeagueFlow\Sports_Manager();
	$fields = new \LeagueFlow\Field_Availability_Manager();
	$renderer = new \LeagueFlow\Renderer( $service, $knockout, $sports );
	$admin = new \LeagueFlow\Admin( $service, $knockout, $renderer, new \LeagueFlow\Seeder(), $sports, new \LeagueFlow\Exporter( $sports ), $fields, new \LeagueFlow\Fixture_Generator( $fields ) );
	$bulk_valid = $match( array( 'lf_status' => 'scheduled', 'lf_home_score' => '0', 'lf_away_score' => '0' ) );
	$bulk_incomplete = $match( array( 'lf_status' => 'scheduled', 'lf_away_score' => '' ) );
	$bulk_forfeit = $match( array( 'lf_status' => 'scheduled', 'lf_home_score' => '', 'lf_away_score' => '', 'lf_outcome' => 'double_forfeit' ) );
	$url = $admin->handle_match_bulk_actions( admin_url( 'edit.php?post_type=lf_match' ), 'leagueflow_set_status_finished', array( $bulk_valid, $bulk_incomplete, $bulk_forfeit ) );
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $bulk_params );
	lfst_check( 'finished' === get_post_meta( $bulk_valid, 'lf_status', true ) && 'finished' === get_post_meta( $bulk_forfeit, 'lf_status', true ) && 'scheduled' === get_post_meta( $bulk_incomplete, 'lf_status', true ) && '2' === $bulk_params['leagueflow_bulk_updated'], 'Bulk finalization accepts zero scores and forfeits, skips incomplete scores, and reports only saved results.' );
	$bulk_lock = \LeagueFlow\db_lock_name( 'result', (string) $bulk_valid );
	$deny_bulk_lock = static function( $sql ) use ( $bulk_lock ) { return false !== strpos( $sql, 'GET_LOCK(' ) && false !== strpos( $sql, $bulk_lock ) ? 'SELECT 0' : $sql; };
	add_filter( 'query', $deny_bulk_lock );
	try { $admin->handle_match_bulk_actions( '', 'leagueflow_set_status_live', array( $bulk_valid ) ); }
	finally { remove_filter( 'query', $deny_bulk_lock ); }
	lfst_check( 'finished' === get_post_meta( $bulk_valid, 'lf_status', true ), 'Bulk status action cannot bypass another result writer\'s lock.' );
	$bulk_calls = 0;
	$revoke_bulk = static function( $caps, $required, $context ) use ( $bulk_valid, &$bulk_calls ) {
		if ( 'edit_post' === $context[0] && (int) $context[2] === $bulk_valid && ++$bulk_calls >= 2 ) { foreach ( $required as $cap ) { $caps[ $cap ] = false; } }
		return $caps;
	};
	add_filter( 'user_has_cap', $revoke_bulk, 10, 3 );
	try { $admin->handle_match_bulk_actions( '', 'leagueflow_set_status_live', array( $bulk_valid ) ); }
	finally { remove_filter( 'user_has_cap', $revoke_bulk ); }
	lfst_check( 2 === $bulk_calls && 'finished' === get_post_meta( $bulk_valid, 'lf_status', true ), 'Bulk status action rechecks object authority after locking.' );
	update_post_meta( $source, 'lf_status', 'scheduled' );
	$admin->handle_match_bulk_actions( '', 'leagueflow_set_status_finished', array( $source ) );
	lfst_check( 'finished' === get_post_meta( $source, 'lf_status', true ) && $a === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'A valid bulk-finalized knockout advances after its result lock is released.' );

	WP_CLI::success( 'Standings and knockout lifecycle: ' . $GLOBALS['lfst_checks'] . ' assertions passed.' );
} finally {
	wp_set_current_user( $lfst_admin );
	foreach ( array_reverse( $GLOBALS['lfst_posts'] ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfst_terms as $taxonomy => $ids ) { foreach ( $ids as $id ) { wp_delete_term( $id, $taxonomy ); } }
	if ( false === $lfst_settings ) { delete_option( 'leagueflow_settings' ); } else { update_option( 'leagueflow_settings', $lfst_settings ); }
	if ( false === $lfst_overrides ) { delete_option( \LeagueFlow\RULE_OVERRIDES_OPTION ); } else { update_option( \LeagueFlow\RULE_OVERRIDES_OPTION, $lfst_overrides ); }
	if ( false === $lfst_notices ) { delete_transient( 'leagueflow_notices_' . $lfst_admin ); } else { set_transient( 'leagueflow_notices_' . $lfst_admin, $lfst_notices, MINUTE_IN_SECONDS ); }
	wp_set_current_user( $lfst_original_user );
}
