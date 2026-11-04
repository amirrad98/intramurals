<?php
/** Native REST/SQL contract regressions, exclusively on the disposable CI site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

$GLOBALS['lfapi_posts'] = array();
$lfapi_posts =& $GLOBALS['lfapi_posts'];
$lfapi_terms = array();
$GLOBALS['lfapi_checks'] = 0;
$lfapi_checks =& $GLOBALS['lfapi_checks'];
$lfapi_zone = get_option( 'timezone_string' );
$lfapi_user = get_current_user_id();
function lfapi_check( $condition, $message ) {
	++$GLOBALS['lfapi_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfapi_post( $type, $name, $meta = array(), $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_title' => $name, 'post_status' => 'publish' ), $extra ), true );
	lfapi_check( ! is_wp_error( $id ), 'Create test post.' );
	$GLOBALS['lfapi_posts'][] = $id;
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}
function lfapi_request( $route = 'match-events', $params = array(), $method = 'GET' ) {
	$r = new WP_REST_Request( $method, '/leagueflow/v1/' . $route );
	$r->set_query_params( $params );
	return rest_do_request( $r );
}
function lfapi_ids( $response, $key = 'events' ) {
	lfapi_check( 200 === $response->get_status(), 'Feed must return 200: ' . wp_json_encode( $response->get_data() ) );
	return array_column( $response->get_data()[ $key ], 'matchId' );
}

try {
	update_option( 'timezone_string', 'America/Vancouver' );
	$ctx = array();
	foreach ( array( 'lf_competition', 'lf_sport', 'lf_season', 'lf_league_level' ) as $tax ) {
		$created = wp_insert_term( 'Public API ' . $tax . ' ' . wp_generate_uuid4(), $tax );
		lfapi_check( ! is_wp_error( $created ), 'Create test taxonomy.' );
		$ctx[ $tax ] = (int) $created['term_id'];
		$lfapi_terms[ $tax ] = $ctx[ $tax ];
	}
	$home = lfapi_post( 'lf_team', 'API Home', array( 'lf_coach_email' => 'PRIVATE-DO-NOT-EXPOSE@example.test' ) );
	$away = lfapi_post( 'lf_team', 'API Away' );
	$private = lfapi_post( 'lf_team', 'PRIVATE-TEAM', array(), array( 'post_status' => 'private' ) );
	$protected = lfapi_post( 'lf_team', 'PROTECTED-TEAM', array(), array( 'post_password' => 'test-only' ) );
	$match = static function( $date, $status = 'scheduled', $meta = array(), $extra = array() ) use ( $home, $away, $ctx ) {
		$id = lfapi_post( 'lf_match', 'API Home vs API Away', array_merge( array(
			'lf_match_datetime' => $date, 'lf_home_team_id' => $home, 'lf_away_team_id' => $away,
			'lf_status' => $status, 'lf_venue' => 'Test Gym 3', 'lf_private_note' => 'PRIVATE-NOTE-DO-NOT-EXPOSE',
		), $meta ), $extra );
		foreach ( $ctx as $tax => $term ) { wp_set_object_terms( $id, array( $term ), $tax ); }
		return $id;
	};
	$a = $match( '2030-10-15 18:30', 'finished', array( 'lf_home_score' => '0', 'lf_away_score' => '0' ) );
	$b = $match( '2030-10-15 18:30', 'finished', array( 'lf_home_score' => '3', 'lf_away_score' => '1' ) );
	$c = $match( '2030-10-16 19:30', 'finished', array( 'lf_outcome' => 'forfeit_home' ) );
	$d = $match( '2030-10-17 20:30', 'finished' );
	$scheduled = $match( '2030-10-18 18:00' );
	$winter = $match( '2030-12-15 18:30:45' );
	$hidden = array(
		$match( '2030-10-15 17:00', 'finished', array(), array( 'post_status' => 'draft' ) ),
		$match( '2030-10-15 17:00', 'finished', array(), array( 'post_status' => 'private' ) ),
		$match( '2030-10-15 17:01', 'finished', array(), array( 'post_password' => 'match-secret' ) ),
		$match( '2030-10-15 17:02', 'finished', array( 'lf_away_team_id' => $private ) ),
		$match( '2030-10-15 17:03', 'finished', array( 'lf_away_team_id' => $protected ) ),
		$match( '2030-10-15 17:04', 'finished', array( 'lf_away_team_id' => 999999999 ) ),
		$match( '2030-02-30 18:00' ), $match( '' ), $match( 'not-a-date' ),
	);
	$scope = array( 'competition' => $ctx['lf_competition'], 'view' => 'all' );
	wp_set_current_user( 0 );
	$r = lfapi_request( 'match-events', $scope + array( 'per_page' => 2 ) );
	lfapi_check( array( $a, $b ) === lfapi_ids( $r ), 'Stable kickoff/ID ordering, with visibility applied before pagination.' );
	$data = $r->get_data();
	lfapi_check( 6 === $data['total'] && 3 === $data['pages'] && 2 === $data['pagination']['nextPage'], 'Exact public totals and next page.' );
	lfapi_check( 6 === (int) $r->get_headers()['X-WP-Total'] && isset( $r->get_headers()['Link'] ), 'WordPress total and navigation headers.' );
	lfapi_check( array( $c, $d ) === lfapi_ids( lfapi_request( 'match-events', $scope + array( 'per_page' => 2, 'page' => 2 ) ) ), 'Second page has no duplicate or missing matches.' );
	$item = $data['events'][0];
	lfapi_check( '2030-10-15T18:30:00-07:00' === $item['startDate'] && 'America/Vancouver' === $item['timezone'], 'Site timezone and summer UTC offset.' );
	lfapi_check( $home === $item['teams']['home']['id'] && 'API Away' === $item['teams']['away']['name'] && 'Test Gym 3' === $item['location'], 'Public team and venue metadata.' );
	foreach ( array( 'sports', 'leagueLevels', 'competitions', 'seasons' ) as $key ) { lfapi_check( 1 === count( $item[ $key ] ) && isset( $item[ $key ][0]['id'], $item[ $key ][0]['slug'], $item[ $key ][0]['name'] ), 'Term identities: ' . $key ); }
	lfapi_check( isset( $data['eventMetadata']->{$item['id']} ) && null === $item['endDate'], 'CampusManager metadata map, without an invented end time.' );
	lfapi_check( false === strpos( wp_json_encode( $data ), 'PRIVATE-' ), 'Only allowlisted public metadata is serialized.' );
	$r = lfapi_request( 'match-results', $scope + array( 'end_date' => '2030-10-16', 'per_page' => 10 ) );
	lfapi_check( array( $c, $b, $a ) === lfapi_ids( $r, 'results' ), 'Results sort latest first and include the end date.' );
	$rows = $r->get_data()['results'];
	lfapi_check( null === $rows[0]['result']['homeScore'] && $away === $rows[0]['result']['winnerTeamId'] && $rows[0]['result']['complete'], 'Forfeit outcome without fabricated scores.' );
	lfapi_check( $home === $rows[1]['result']['winnerTeamId'] && false === $rows[1]['result']['isDraw'], 'Winning team derived from scores.' );
	lfapi_check( 0 === $rows[2]['result']['homeScore'] && true === $rows[2]['result']['isDraw'], 'Explicit 0–0 draw retained.' );
	$r = lfapi_request( 'match-results/', $scope + array( 'per_page' => 1 ) );
	lfapi_check( array( $d ) === lfapi_ids( $r, 'results' ) && false === $r->get_data()['results'][0]['result']['complete'], 'Trailing slash and missing-score results remain honest.' );
	lfapi_check( array( $c, $b ) === lfapi_ids( lfapi_request( 'match-results', array( 'competition' => $ctx['lf_competition'], 'date' => '2030-10-16', 'limit' => 2 ) ), 'results' ), 'Latest N before a chosen inclusive date.' );
	$r = lfapi_request( 'match-events', array( 'competition' => $ctx['lf_competition'], 'date' => '2030-10-16', 'limit' => 1 ) );
	lfapi_check( array( $c ) === lfapi_ids( $r ) && false !== strpos( $r->get_data()['pagination']['nextUrl'], 'date=2030-10-16' ), 'Upcoming date anchor is preserved in pagination URLs.' );
	$r = lfapi_request( 'match-events', array( 'competition' => $ctx['lf_competition'], 'start_date' => '2030-12-15', 'end_date' => '2030-12-15' ) );
	lfapi_check( array( $winter ) === lfapi_ids( $r ) && 0 === strpos( $r->get_data()['events'][0]['startDate'], '2030-12-15T18:30:45' ), 'Explicit ranges preserve the stored local date and seconds: ' . wp_json_encode( $r->get_data() ) );
	add_post_meta( $winter, 'lf_match_datetime', '2020-01-01 01:00' );
	lfapi_check( array( $winter ) === lfapi_ids( lfapi_request( 'match-events', $scope + array( 'start_date' => '2030-12-15', 'end_date' => '2030-12-15' ) ) ), 'Duplicate legacy date metadata follows the same first-value rule as serialization.' );
	foreach ( array( 'sport' => 'lf_sport', 'league_level' => 'lf_league_level', 'season' => 'lf_season' ) as $param => $tax ) {
		$slug = get_term( $ctx[ $tax ], $tax )->slug;
		lfapi_check( 6 === lfapi_request( 'match-events', $scope + array( $param => $slug ) )->get_data()['total'], 'Slug filter: ' . $param );
	}
	lfapi_check( 6 === lfapi_request( 'match-events', $scope + array( 'team' => $home ) )->get_data()['total'], 'Team filter searches home and away.' );
	update_post_meta( $scheduled, 'lf_is_knockout', 1 );
	lfapi_check( array( $scheduled ) === lfapi_ids( lfapi_request( 'match-events', $scope + array( 'include_knockout' => true ) ) ), 'Knockout selection before totals.' );
	lfapi_check( 5 === lfapi_request( 'match-events', $scope + array( 'include_knockout' => false ) )->get_data()['total'], 'Non-knockout includes records with no flag.' );
	foreach ( array(
		array( 'per_page' => 101 ), array( 'per_page' => 0 ), array( 'limit' => -1 ), array( 'page' => 0 ), array( 'page' => 10001 ), array( 'per_page' => 10, 'limit' => 20 ),
		array( 'start_date' => '2030-02-30' ), array( 'date' => 'garbage' ), array( 'start_date' => '2030-10-20', 'end_date' => '2030-10-01' ),
		array( 'view' => 'other' ), array( 'order' => 'DESC; DROP TABLE' ), array( 'status' => 'draft' ), array( 'sport' => 'missing-sport' ), array( 'competition' => array( 1, 2 ) ), array( 'team' => $private ),
	) as $bad ) { lfapi_check( 400 === lfapi_request( 'match-events', array_merge( $scope, $bad ) )->get_status(), 'Invalid filter fails closed: ' . wp_json_encode( $bad ) ); }
	lfapi_check( 400 === lfapi_request( 'match-results', $scope + array( 'status' => 'scheduled' ) )->get_status(), 'Results cannot be widened to non-final statuses.' );
	foreach ( array( 0, get_user_by( 'login', 'admin' )->ID ) as $viewer ) {
		wp_set_current_user( $viewer );
		lfapi_check( 6 === lfapi_request( 'match-events', $scope )->get_data()['total'], 'Public-only scope is identical for anonymous and administrator sessions.' );
	}
	wp_set_current_user( 0 );
	$_COOKIE['wp-postpass_' . COOKIEHASH] = 'irrelevant-test-cookie';
	lfapi_check( 400 === lfapi_request( 'match-events', $scope + array( 'team' => $protected ) )->get_status(), 'A password cookie does not unlock public feed data.' );
	unset( $_COOKIE['wp-postpass_' . COOKIEHASH] );
	$r = lfapi_request( 'match-results', $scope );
	lfapi_check( 4 === $r->get_data()['total'], 'Finished total before metadata edit.' );
	update_post_meta( $d, 'lf_status', 'cancelled' );
	lfapi_check( 3 === lfapi_request( 'match-results', $scope )->get_data()['total'], 'Polling reflects metadata-only status changes immediately.' );
	update_post_meta( $b, 'lf_winner_team_id', $away );
	$r = lfapi_request( 'match-results', $scope );
	lfapi_check( $away === $r->get_data()['results'][1]['result']['winnerOverride'], 'Explicit winner override preserved.' );
	update_post_meta( $c, 'lf_outcome', 'double_forfeit' );
	lfapi_check( null === lfapi_request( 'match-results', $scope )->get_data()['results'][0]['result']['winnerTeamId'], 'Double forfeit has no winner.' );
	$r = lfapi_request( 'match-events', $scope + array( 'page' => 99 ) );
	lfapi_check( array() === $r->get_data()['events'] && 6 === $r->get_data()['total'] && 1 === $r->get_data()['pages'] && 1 === $r->get_data()['pagination']['previousPage'], 'Out-of-range pages are empty without losing the real total or previous link.' );
	lfapi_check( lfapi_request( 'match-events', array(), 'POST' )->get_status() >= 400 && lfapi_request( 'match-results', array(), 'DELETE' )->get_status() >= 400, 'Public feeds offer no mutation route.' );
	lfapi_check( is_array( lfapi_request( 'matches', array( 'competition' => $ctx['lf_competition'], 'limit' => 1 ) )->get_data() ), 'Legacy matches response remains available.' );
	$routes = rest_get_server()->get_routes();
	lfapi_check( isset( $routes['/leagueflow/v1/match-events/?'][0]['args']['per_page'], $routes['/leagueflow/v1/match-results/?'][0]['args']['date'] ), 'Request contract is discoverable through native REST route metadata.' );
	$many = array();
	for ( $day = 1; $day <= 24; ++$day ) { $many[] = $match( sprintf( '2031-01-%02d 18:30', $day ), 'finished', array( 'lf_home_score' => '1', 'lf_away_score' => '0' ) ); }
	$range = array( 'competition' => $ctx['lf_competition'], 'start_date' => '2031-01-01', 'end_date' => '2031-01-31' );
	foreach ( array( 10, 20 ) as $count ) {
		$r = lfapi_request( 'match-results', $range + array( 'per_page' => $count ) );
		lfapi_check( array_slice( array_reverse( $many ), 0, $count ) === lfapi_ids( $r, 'results' ) && 24 === $r->get_data()['total'], 'Latest ' . $count . ' results uses the filtered full total.' );
	}
	$r = lfapi_request( 'match-events', $scope + array( 'start_date' => '2099-01-01' ) );
	lfapi_check( 0 === $r->get_data()['total'] && false !== strpos( wp_json_encode( $r->get_data() ), '"eventMetadata":{}' ), 'Empty feeds retain an object metadata map.' );
	// Historical dates avoid assuming that a jurisdiction will keep seasonal DST.
	$summer_2025 = $match( '2025-07-15 18:30' );
	$winter_2025 = $match( '2025-12-15 18:30' );
	$r = lfapi_request( 'match-events', $scope + array( 'start_date' => '2025-07-15', 'end_date' => '2025-12-15' ) );
	lfapi_check( array( $summer_2025, $winter_2025 ) === lfapi_ids( $r ) && '2025-07-15T18:30:00-07:00' === $r->get_data()['events'][0]['startDate'] && '2025-12-15T18:30:00-08:00' === $r->get_data()['events'][1]['startDate'], 'Historical summer/winter offsets follow the date, not the server default timezone.' );
	$gap = $match( '2025-03-09 02:30' );
	$r = lfapi_request( 'match-events', $scope + array( 'start_date' => '2025-03-09', 'end_date' => '2025-03-09' ) );
	lfapi_check( array( $gap ) === lfapi_ids( $r ) && null === $r->get_data()['events'][0]['startDate'] && 'invalid_local_time' === $r->get_data()['events'][0]['timeStatus'], 'Nonexistent DST wall time is flagged without inventing a shifted kickoff.' );
	WP_CLI::success( 'Public match API: ' . $lfapi_checks . ' assertions passed.' );
} finally {
	wp_set_current_user( $lfapi_user );
	update_option( 'timezone_string', $lfapi_zone );
	foreach ( array_reverse( $lfapi_posts ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfapi_terms as $tax => $id ) { wp_delete_term( $id, $tax ); }
}
