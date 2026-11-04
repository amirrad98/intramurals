<?php
/** Results screen workflow: publish, hold, scheduled publication and public visibility, on a disposable site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

$GLOBALS['lfw_checks'] = 0;
$GLOBALS['lfw_posts'] = array();
$lfw_terms = array();
$lfw_original_user = get_current_user_id();
$lfw_admin_id = get_user_by( 'login', 'admin' )->ID;

function lfw_check( $condition, $message ) {
	++$GLOBALS['lfw_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfw_post( $type, $context, $meta = array(), $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Results workflow ' . wp_generate_uuid4() ), $extra ), true );
	lfw_check( ! is_wp_error( $id ) && $id > 0, 'Create results workflow record.' );
	$GLOBALS['lfw_posts'][] = (int) $id;
	foreach ( $context as $taxonomy => $term_id ) { wp_set_object_terms( $id, array( (int) $term_id ), $taxonomy ); }
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}

try {
	wp_set_current_user( $lfw_admin_id );
	$service = new \LeagueFlow\Result_Service();
	$context = array();
	foreach ( array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ) as $taxonomy ) {
		$term = wp_insert_term( 'Results workflow ' . $taxonomy . ' ' . wp_generate_uuid4(), $taxonomy );
		lfw_check( ! is_wp_error( $term ), 'Create results workflow context.' );
		$context[ $taxonomy ] = (int) $term['term_id'];
		$lfw_terms[ $taxonomy ][] = (int) $term['term_id'];
	}
	$a = lfw_post( 'lf_team', $context );
	$b = lfw_post( 'lf_team', $context );
	$past = wp_date( 'Y-m-d H:i', time() - DAY_IN_SECONDS, wp_timezone() );
	$make = static function( $meta = array(), $extra = array() ) use ( $a, $b, $context, $past ) {
		return lfw_post( 'lf_match', $context, array_merge( array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_status' => 'scheduled', 'lf_match_datetime' => $past ), $meta ), $extra );
	};
	$table = static function() use ( $context ) {
		return array_column( ( new \LeagueFlow\Standings_Service() )->get_rows( $context['lf_competition'], $context['lf_season'], $context['lf_sport'], $context['lf_league_level'] ), null, 'team_id' );
	};
	$renderer = new \LeagueFlow\Renderer( new \LeagueFlow\Standings_Service(), new \LeagueFlow\Knockout_Service(), new \LeagueFlow\Sports_Manager() );
	$public = static function( $id ) use ( $renderer ) {
		$prior = get_current_user_id();
		wp_set_current_user( 0 );
		try { return $renderer->get_match_item( $id ); }
		finally { wp_set_current_user( $prior ); }
	};

	// Stages.
	$id = $make();
	lfw_check( 'needs_score' === \LeagueFlow\Result_Service::stage( $id ), 'A played game without a score needs a score.' );
	$future = $make( array( 'lf_match_datetime' => wp_date( 'Y-m-d H:i', time() + DAY_IN_SECONDS, wp_timezone() ) ) );
	lfw_check( 'upcoming' === \LeagueFlow\Result_Service::stage( $future ), 'A future game is not waiting for a result.' );

	// Validation refuses before any write.
	foreach ( array( array( '3', '' ), array( '-1', '2' ), array( '1.5', '2' ) ) as $scores ) {
		$result = $service->save( $id, array( 'home' => $scores[0], 'away' => $scores[1], 'mode' => 'finish' ) );
		lfw_check( is_wp_error( $result ) && '' === get_post_meta( $id, 'lf_home_score', true ) && 'scheduled' === get_post_meta( $id, 'lf_status', true ), 'Incomplete or invalid scores are refused without a write.' );
	}
	lfw_check( is_wp_error( $service->save( $id, array( 'home' => '1', 'away' => '0', 'mode' => 'finish', 'state' => 'stale' ) ) ), 'A stale form is refused.' );

	// Hold: saved privately, not in standings, not public.
	lfw_check( true === $service->save( $id, array( 'home' => '3', 'away' => '1', 'mode' => 'hold', 'state' => \LeagueFlow\Result_Service::state_token( $id ) ) ), 'Save without publishing.' );
	lfw_check( 'held' === \LeagueFlow\Result_Service::stage( $id ) && 'scheduled' === get_post_meta( $id, 'lf_status', true ) && '1' === get_post_meta( $id, '_lf_result_keep_status', true ), 'A held result keeps its status and the keep flag.' );
	lfw_check( empty( $table()[ $a ]['played'] ), 'A held result does not count in the standings.' );
	$shown = $public( $id );
	lfw_check( '' === $shown['scoreline'] && '' === $shown['home_score'], 'A held score is hidden from public views.' );

	// Publish.
	lfw_check( true === $service->save( $id, array( 'home' => '3', 'away' => '1', 'mode' => 'finish' ) ), 'Publish the held result.' );
	lfw_check( 'published' === \LeagueFlow\Result_Service::stage( $id ) && 'finished' === get_post_meta( $id, 'lf_status', true ) && '' === get_post_meta( $id, '_lf_result_keep_status', true ), 'Publishing finishes the match and clears the keep flag.' );
	lfw_check( 1 === $table()[ $a ]['played'] && 1 === $table()[ $a ]['wins'], 'A published result counts once in the standings.' );
	lfw_check( '3 - 1' === $public( $id )['scoreline'], 'A published score is public.' );
	lfw_check( 2 === count( get_post_meta( $id, \LeagueFlow\Result_Service::LOG_META ) ), 'Each save is recorded.' );

	// Taking a published result off the site.
	lfw_check( true === $service->save( $id, array( 'home' => '3', 'away' => '1', 'mode' => 'hold' ) ), 'Take a published result off the site.' );
	lfw_check( 'scheduled' === get_post_meta( $id, 'lf_status', true ) && empty( $table()[ $a ]['played'] ) && '' === $public( $id )['scoreline'], 'A held former result leaves the standings and public views.' );

	// Scheduled publication.
	$scheduled = $make();
	lfw_check( is_wp_error( $service->save( $scheduled, array( 'home' => '2', 'away' => '2', 'mode' => 'schedule', 'publish_at' => time() - 60 ) ) ), 'A past publication time is refused.' );
	lfw_check( true === $service->save( $scheduled, array( 'home' => '2', 'away' => '2', 'mode' => 'schedule', 'publish_at' => time() + HOUR_IN_SECONDS ) ), 'Schedule a result.' );
	lfw_check( 'scheduled' === \LeagueFlow\Result_Service::stage( $scheduled ) && false !== wp_next_scheduled( \LeagueFlow\Result_Service::CRON_SINGLE, array( $scheduled ) ), 'A scheduled result has a cron event.' );
	lfw_check( null === $service->publish_one( $scheduled ), 'A result is not published before its time.' );
	update_post_meta( $scheduled, \LeagueFlow\Result_Service::PUBLISH_AT_META, (string) ( time() - 1 ) );
	wp_set_current_user( 0 );
	$service->publish_due();
	wp_set_current_user( $lfw_admin_id );
	lfw_check( 'finished' === get_post_meta( $scheduled, 'lf_status', true ) && '' === get_post_meta( $scheduled, \LeagueFlow\Result_Service::PUBLISH_AT_META, true ), 'The sweep publishes a due result as the person who scheduled it.' );
	lfw_check( false === wp_next_scheduled( \LeagueFlow\Result_Service::CRON_SINGLE, array( $scheduled ) ), 'A published result has no leftover cron event.' );

	// A result changed after scheduling is not published.
	$changed = $make();
	$service->save( $changed, array( 'home' => '1', 'away' => '0', 'mode' => 'schedule', 'publish_at' => time() + HOUR_IN_SECONDS ) );
	update_post_meta( $changed, 'lf_home_score', '5' );
	update_post_meta( $changed, \LeagueFlow\Result_Service::PUBLISH_AT_META, (string) ( time() - 1 ) );
	lfw_check( is_wp_error( $service->publish_one( $changed ) ) && 'conflict' === \LeagueFlow\Result_Service::stage( $changed ) && 'scheduled' === get_post_meta( $changed, 'lf_status', true ), 'An edited scheduled result needs review instead of publishing.' );
	lfw_check( true === $service->save( $changed, array( 'home' => '5', 'away' => '0', 'mode' => 'finish' ) ) && 'published' === \LeagueFlow\Result_Service::stage( $changed ), 'Publishing after review clears the conflict.' );

	// Forfeits and unplayed statuses.
	$forfeit = $make();
	lfw_check( true === $service->save( $forfeit, array( 'outcome' => 'forfeit_home', 'mode' => 'finish' ) ) && 'finished' === get_post_meta( $forfeit, 'lf_status', true ), 'A forfeit publishes without scores.' );
	$postponed = $make( array( 'lf_status' => 'postponed' ) );
	lfw_check( is_wp_error( $service->save( $postponed, array( 'home' => '1', 'away' => '0', 'mode' => 'finish' ) ) ) && 'postponed' === \LeagueFlow\Result_Service::stage( $postponed ), 'Postponed games need their status changed first.' );

	// Unpublished fixtures are published with the result when allowed.
	$draft = $make( array(), array( 'post_status' => 'draft' ) );
	$service->save( $draft, array( 'home' => '1', 'away' => '0', 'mode' => 'finish', 'publish_post' => true ) );
	lfw_check( 'publish' === get_post_status( $draft ), 'Publish result also publishes a draft fixture.' );

	// Inconsistent context is refused.
	$other = wp_insert_term( 'Results workflow other sport ' . wp_generate_uuid4(), 'lf_sport' );
	$lfw_terms['lf_sport'][] = (int) $other['term_id'];
	$c = lfw_post( 'lf_team', array_merge( $context, array( 'lf_sport' => (int) $other['term_id'] ) ) );
	$mixed = $make( array( 'lf_away_team_id' => $c ) );
	lfw_check( is_wp_error( $service->save( $mixed, array( 'home' => '1', 'away' => '0', 'mode' => 'finish' ) ) ) && is_wp_error( $service->save( $mixed, array( 'home' => '1', 'away' => '0', 'mode' => 'schedule', 'publish_at' => time() + 60 ) ) ), 'A match with teams from different sports cannot be finished or scheduled.' );

	// Results screen buckets.
	$screen = new \LeagueFlow\Admin_Results( $service, new \LeagueFlow\Sports_Manager() );
	$staged = $screen->load( '' );
	lfw_check( in_array( $id, $staged['held'], true ) && in_array( $scheduled, $staged['published'], true ) && ! in_array( $future, array_merge( ...array_values( $staged ) ), true ), 'The Results screen groups played games by stage and leaves future games out.' );

	WP_CLI::success( 'Results workflow: ' . $GLOBALS['lfw_checks'] . ' assertions passed.' );
} finally {
	wp_set_current_user( $lfw_admin_id );
	foreach ( array_reverse( $GLOBALS['lfw_posts'] ) as $id ) {
		wp_clear_scheduled_hook( \LeagueFlow\Result_Service::CRON_SINGLE, array( $id ) );
		wp_delete_post( $id, true );
	}
	foreach ( $lfw_terms as $taxonomy => $ids ) { foreach ( $ids as $id ) { wp_delete_term( $id, $taxonomy ); } }
	wp_set_current_user( $lfw_original_user );
}
