<?php
/** Scheduling occupancy and concurrency checks on a disposable WordPress site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

$GLOBALS['lfsc_checks'] = 0;
$GLOBALS['lfsc_posts'] = array();
$lfsc_terms = array();
$lfsc_user = get_current_user_id();
$lfsc_post = $_POST;
$lfsc_request = $_REQUEST;
$lfsc_settings = get_option( 'leagueflow_settings' );
$lfsc_windows = get_option( \LeagueFlow\Field_Availability_Manager::OPTION );
$lfsc_admin_id = get_user_by( 'login', 'admin' )->ID;
$lfsc_notice = get_transient( 'leagueflow_schedule_result_' . $lfsc_admin_id );

function lfsc_check( $condition, $message ) {
	++$GLOBALS['lfsc_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfsc_post( $type, $meta = array() ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Scheduling regression ' . wp_generate_uuid4() ), true );
	lfsc_check( ! is_wp_error( $id ), 'Create scheduling test post.' );
	$GLOBALS['lfsc_posts'][] = (int) $id;
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}

try {
	wp_set_current_user( $lfsc_admin_id );
	$_POST = array(); $_REQUEST = array();
	update_option( 'leagueflow_settings', \LeagueFlow\defaults() );
	update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array() );
	$service = new \LeagueFlow\Field_Availability_Manager();
	$venue = 'Scheduling test gym ' . wp_generate_uuid4();
	$a = lfsc_post( 'lf_team' );
	$b = lfsc_post( 'lf_team' );
	$match = static function( $meta = array() ) use ( $a, $b ) {
		return lfsc_post( 'lf_match', array_merge( array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_status' => 'scheduled' ), $meta ) );
	};
	$window = static function( $id, $date, $start = '09:00', $end = '12:00', $minutes = 60 ) use ( $service, $venue ) {
		$r = $service->save_availability( array( 'id' => $id, 'name' => 'Scheduling regression window', 'venue' => $venue, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'slot_minutes' => $minutes, 'active' => true ) );
		lfsc_check( ! is_wp_error( $r ), 'Create availability window.' );
		return $r;
	};

	// A closure that began before the query's previous-day boundary still
	// occupies the venue while it overlaps the selected date.
	$window( 'regression-closure', '2033-02-15' );
	$closure = lfsc_post( 'lf_calendar_event', array( 'lf_event_start_datetime' => '2033-02-01 09:00', 'lf_event_end_datetime' => '2033-02-20 23:59', 'lf_event_venue' => $venue, 'lf_event_status' => 'scheduled' ) );
	lfsc_check( array() === $service->get_available_slots( array( 'date' => '2033-02-15' ) ), 'A long-running closure blocks every overlapping availability slot.' );
	$blocked = $match();
	lfsc_check( 0 === $service->schedule_match( $blocked, array( 'date' => '2033-02-15' ) )['scheduled'] && '' === get_post_meta( $blocked, 'lf_match_datetime', true ), 'Scheduling cannot write through a long-running closure.' );
	update_post_meta( $closure, 'lf_event_status', 'cancelled' );
	lfsc_check( 3 === count( $service->get_available_slots( array( 'date' => '2033-02-15' ) ) ), 'A cancelled closure releases its slots.' );

	// Legacy appointments retain their linked rule's 90-minute duration; new
	// appointments persist duration so later rule edits do not resize them.
	$window( 'regression-long', '2033-03-01', '09:00', '10:30', 90 );
	$window( 'regression-short', '2033-03-01', '10:00', '12:00', 30 );
	$existing = $match( array( 'lf_match_datetime' => '2033-03-01 09:00', 'lf_venue' => $venue, \LeagueFlow\Field_Availability_Manager::META_AVAILABILITY_ID => 'regression-long' ) );
	lfsc_check( 90 === \LeagueFlow\Field_Availability_Manager::get_match_duration_minutes( $existing ), 'Legacy match duration comes from its linked availability.' );
	$next = $match();
	$result = $service->schedule_match( $next, array( 'date' => '2033-03-01', 'availability_id' => 'regression-short' ) );
	lfsc_check( 1 === $result['scheduled'] && '2033-03-01 10:30' === get_post_meta( $next, 'lf_match_datetime', true ), 'The first free slot follows the full 90-minute fixture, not a fixed one-hour estimate.' );
	lfsc_check( 30 === (int) get_post_meta( $next, 'lf_duration_minutes', true ), 'Newly scheduled fixtures store their duration.' );
	$window( 'regression-short', '2033-03-01', '10:00', '12:00', 45 );
	lfsc_check( 30 === \LeagueFlow\Field_Availability_Manager::get_match_duration_minutes( $next ), 'Editing the rule does not change a saved fixture duration.' );
	$window( 'regression-short', '2033-03-01', '10:00', '12:00', 30 );
	$third = $match();
	lfsc_check( 1 === $service->schedule_match( $third, array( 'date' => '2033-03-01', 'availability_id' => 'regression-short' ) )['scheduled'] && '2033-03-01 11:00' === get_post_meta( $third, 'lf_match_datetime', true ), 'A separate scheduling run respects the first run\'s saved half-hour reservation.' );

	// Rest/rematch rules need history beyond one day outside the requested
	// range, both before and after a candidate date.
	$past = $match( array( 'lf_match_datetime' => '2033-04-01 09:00', 'lf_venue' => $venue, 'lf_status' => 'finished' ) );
	$window( 'regression-gap-early', '2033-04-04', '09:00', '10:00' );
	$window( 'regression-gap-late', '2033-04-08', '09:00', '10:00' );
	$settings = \LeagueFlow\defaults(); $settings['min_days_between_rematch'] = 7;
	update_option( 'leagueflow_settings', $settings );
	$rematch = $match();
	lfsc_check( 0 === $service->schedule_match( $rematch, array( 'date' => '2033-04-04' ) )['scheduled'], 'A match three days before the requested range enforces a seven-day rematch gap.' );
	lfsc_check( 1 === $service->schedule_match( $rematch, array( 'date' => '2033-04-08' ) )['scheduled'], 'The exact seven-day gap is allowed.' );
	update_post_meta( $rematch, 'lf_status', 'cancelled' );
	$future = $match( array( 'lf_match_datetime' => '2033-04-12 09:00', 'lf_venue' => $venue ) );
	$before_future = $match();
	lfsc_check( 0 === $service->schedule_match( $before_future, array( 'date' => '2033-04-08' ) )['scheduled'], 'A later fixture outside the range also enforces the rematch gap.' );
	update_post_meta( $future, 'lf_status', 'cancelled' );
	$settings['min_days_between_rematch'] = 0; $settings['min_rest_days'] = 7;
	update_option( 'leagueflow_settings', $settings );
	$rested = $match();
	lfsc_check( 1 === $service->schedule_match( $rested, array( 'date_from' => '2033-04-04', 'date_to' => '2033-04-08' ) )['scheduled'] && '2033-04-08 09:00' === get_post_meta( $rested, 'lf_match_datetime', true ), 'Preferred rest days consider earlier matches outside the scheduling range.' );
	update_option( 'leagueflow_settings', \LeagueFlow\defaults() );

	// Neither an overwrite selection nor a mid-run transition can move an
	// active fixture. Existing finished/cancelled protections remain in place.
	$window( 'regression-live', '2033-05-01' );
	$live = $match( array( 'lf_status' => 'live', 'lf_match_datetime' => '2033-05-01 08:00', 'lf_venue' => $venue ) );
	$before = get_post_meta( $live );
	lfsc_check( 0 === $service->schedule_match( $live, array( 'date' => '2033-05-01', 'overwrite' => true ) )['scheduled'] && $before === get_post_meta( $live ), 'Overwrite cannot move a live match.' );

	// An unavailable advisory-lock service must fail closed before any write.
	$locked = $match();
	$fail_lock = static function( $query ) { return 0 === stripos( ltrim( $query ), 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $query; };
	add_filter( 'query', $fail_lock );
	try { $result = $service->schedule_match( $locked, array( 'date' => '2033-05-01' ) ); }
	finally { remove_filter( 'query', $fail_lock ); }
	lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && '' === get_post_meta( $locked, 'lf_match_datetime', true ), 'Unavailable scheduler lock writes nothing.' );
	lfsc_check( false !== strpos( implode( ' ', $result['messages'] ), 'lock' ), 'Lock failure gives staff an actionable explanation.' );

	// A second database session holding the scheduling lock reproduces a
	// simultaneous request without relying on timing or a mocked reservation.
	global $wpdb;
	list( $db_host, $db_port, $db_socket ) = $wpdb->parse_db_host( DB_HOST );
	$other = new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ?: 3306, $db_socket );
	lfsc_check( ! $other->connect_errno, 'Open independent lock holder.' );
	$lock_name = \LeagueFlow\db_lock_name( 'schedule', 'site' );
	$escaped = $other->real_escape_string( $lock_name );
	lfsc_check( '1' === (string) $other->query( "SELECT GET_LOCK('$escaped', 0)" )->fetch_row()[0], 'Independent session holds the scheduler lock.' );
	try {
		$result = $service->schedule_match( $locked, array( 'date' => '2033-05-01' ) );
		lfsc_check( 0 === $result['scheduled'] && '' === get_post_meta( $locked, 'lf_match_datetime', true ), 'A simultaneous scheduling run cannot reserve a slot while another run owns the lock.' );
	} finally { $other->query( "SELECT RELEASE_LOCK('$escaped')" ); $other->close(); }
	lfsc_check( 1 === $service->schedule_match( $locked, array( 'date' => '2033-05-01' ) )['scheduled'], 'Scheduling succeeds after the other run releases its lock.' );

	// Referee finalization can commit after the scope query cached a match. The
	// scheduler must refresh that state inside the shared per-result lock.
	$window( 'regression-finalization', '2033-06-01' );
	$finalizing = $match( array( 'lf_match_datetime' => '2033-06-01 07:00', 'lf_venue' => $venue ) );
	$finalizing_lock = \LeagueFlow\db_lock_name( 'result', (string) $finalizing );
	$changed = false;
	$finish_before_lock = static function( $sql ) use ( $wpdb, $finalizing, $finalizing_lock, &$changed ) {
		if ( ! $changed && false !== strpos( $sql, 'GET_LOCK(' ) && false !== strpos( $sql, $finalizing_lock ) ) {
			$changed = true;
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'finished' ), array( 'post_id' => $finalizing, 'meta_key' => 'lf_status' ) );
		}
		return $sql;
	};
	add_filter( 'query', $finish_before_lock );
	try { $result = $service->schedule_match( $finalizing, array( 'date' => '2033-06-01', 'overwrite' => true ) ); }
	finally { remove_filter( 'query', $finish_before_lock ); }
	lfsc_check( $changed && 0 === $result['scheduled'] && '2033-06-01 07:00' === get_post_meta( $finalizing, 'lf_match_datetime', true ) && 'finished' === get_post_meta( $finalizing, 'lf_status', true ), 'A freshly finalized result cannot be moved using cached Scheduled state.' );

	// A selected existing booking still occupies its slot when its own update
	// is busy. Selection alone must never release that slot to another match.
	$busy = $match( array( 'lf_match_datetime' => '2033-06-01 09:00', 'lf_venue' => $venue ) );
	$other_match = $match();
	$other = new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ?: 3306, $db_socket );
	$result_lock = \LeagueFlow\db_lock_name( 'result', (string) $busy );
	$escaped_result = $other->real_escape_string( $result_lock );
	lfsc_check( '1' === (string) $other->query( "SELECT GET_LOCK('$escaped_result', 0)" )->fetch_row()[0], 'Independent editor holds a selected match result lock.' );
	try {
		$result = $service->schedule_matches( array( 'match_ids' => array( $busy, $other_match ), 'date' => '2033-06-01', 'overwrite' => true ) );
		lfsc_check( 1 === $result['scheduled'] && 1 === $result['failed'] && '2033-06-01 09:00' === get_post_meta( $busy, 'lf_match_datetime', true ) && '2033-06-01 10:00' === get_post_meta( $other_match, 'lf_match_datetime', true ), 'A busy selected fixture retains occupancy while another selected match uses the next free slot.' );
	} finally { $other->query( "SELECT RELEASE_LOCK('$escaped_result')" ); $other->close(); }

	// The staff editor invokes scheduling while already holding this same lock.
	lfsc_check( \LeagueFlow\acquire_db_lock( $result_lock, 0 ), 'Editor can hold its own match result lock.' );
	try {
		$result = $service->schedule_match( $busy, array( 'date' => '2033-06-01', 'overwrite' => true ) );
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $result_lock ) );
		lfsc_check( 1 === $result['scheduled'] && (string) $owner === (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'Nested editor scheduling succeeds and leaves the outer editor lock held.' );
	} finally { \LeagueFlow\release_db_lock( $result_lock ); }
	// Invalid raw identifiers must never be coerced into another real match,
	// or widen a malformed scope into an all-matches scheduling request.
	$invalid_target = $match();
	foreach ( array( -$invalid_target, $invalid_target . 'oops', (float) $invalid_target + 0.5, true, 0, array( $invalid_target ), (string) PHP_INT_MAX . '0' ) as $bad_id ) {
		$result = $service->schedule_match( $bad_id, array( 'date' => '2033-06-01' ) );
		lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && '' === get_post_meta( $invalid_target, 'lf_match_datetime', true ), 'Malformed scheduling target fails without touching its coercible record.' );
	}
	$result = $service->schedule_matches( array( 'match_ids' => array( $invalid_target, -$invalid_target ), 'date' => '2033-06-01' ) );
	lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && '' === get_post_meta( $invalid_target, 'lf_match_datetime', true ), 'One malformed target rejects the whole selected scope.' );
	foreach ( array( null, new stdClass() ) as $bad_scope ) {
		$result = $service->schedule_matches( array( 'match_ids' => $bad_scope, 'date' => '2033-06-01' ) );
		lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && '' === get_post_meta( $invalid_target, 'lf_match_datetime', true ), 'A null or object target list cannot widen into an all-matches request.' );
	}
	foreach ( array( 'competition_id', 'season_id' ) as $scope ) {
		$result = $service->schedule_match( $invalid_target, array( 'date' => '2033-06-01', $scope => '-1' ) );
		lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'], 'Malformed taxonomy scope cannot silently select another term.' );
	}

	// A failed metadata write rolls back every scheduling field and keeps the
	// failed candidate's proposed slot available for the next fixture in-run.
	$window( 'regression-atomic', '2033-07-01' );
	$atomic = $match( array( 'lf_match_datetime' => '2033-07-01 08:00', 'lf_venue' => $venue, 'lf_duration_minutes' => 45, \LeagueFlow\Field_Availability_Manager::META_SCHEDULE_SOURCE => 'manual' ) );
	$following = $match();
	$original_meta = get_post_meta( $atomic );
	$original_title = get_the_title( $atomic );
	$fail_meta = static function( $check, $id, $key ) use ( $atomic ) {
		return $atomic === (int) $id && \LeagueFlow\Field_Availability_Manager::META_SCHEDULE_SOURCE === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $fail_meta, 10, 3 );
	try { $result = $service->schedule_matches( array( 'match_ids' => array( $atomic, $following ), 'date' => '2033-07-01', 'overwrite' => true ) ); }
	finally { remove_filter( 'update_post_metadata', $fail_meta, 10 ); }
	lfsc_check( 1 === $result['scheduled'] && 1 === $result['failed'] && $original_meta === get_post_meta( $atomic ) && $original_title === get_the_title( $atomic ), 'A failed field write reports failure and rolls back date, venue, source, duration and title.' );
	lfsc_check( '2033-07-01 09:00' === get_post_meta( $following, 'lf_match_datetime', true ), 'A failed schedule never reserves its proposed slot.' );

	$fail_title = static function( $data, $postarr ) use ( $atomic ) {
		if ( $atomic === (int) ( $postarr['ID'] ?? 0 ) ) { $data['post_title'] = 'Filtered title failure'; }
		return $data;
	};
	add_filter( 'wp_insert_post_data', $fail_title, 10, 2 );
	try { $result = $service->schedule_match( $atomic, array( 'date' => '2033-07-01', 'overwrite' => true ) ); }
	finally { remove_filter( 'wp_insert_post_data', $fail_title, 10 ); }
	lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && $original_meta === get_post_meta( $atomic ) && $original_title === get_the_title( $atomic ), 'A failed generated-title save rolls back the complete schedule.' );

	// Save hooks cannot replace an earlier verified value, or a venue retained
	// by datetime-only mode, and then reserve the originally selected slot.
	foreach ( array( 'lf_duration_minutes' => 125, 'lf_venue' => 'Altered by a title hook' ) as $changed_key => $changed_value ) {
		$alter_field = static function( $data, $postarr ) use ( $atomic, $changed_key, $changed_value ) {
			if ( $atomic === (int) ( $postarr['ID'] ?? 0 ) ) { update_post_meta( $atomic, $changed_key, $changed_value ); }
			return $data;
		};
		add_filter( 'wp_insert_post_data', $alter_field, 10, 2 );
		try { $result = $service->schedule_match( $atomic, array( 'date' => '2033-07-01', 'mode' => 'datetime', 'overwrite' => true ) ); }
		finally { remove_filter( 'wp_insert_post_data', $alter_field, 10 ); }
		lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && $original_meta === get_post_meta( $atomic ) && $original_title === get_the_title( $atomic ), 'A title hook changing ' . $changed_key . ' rejects and rolls back the complete schedule.' );
	}

	// A caller-owned transaction would hide its booking after the scheduler
	// releases its lock. Refuse the request and leave caller work untouched.
	$wpdb->query( 'START TRANSACTION' );
	try {
		update_post_meta( $atomic, '_lf_schedule_transaction_probe', 'pending' );
		$result = $service->schedule_match( $atomic, array( 'date' => '2033-07-01', 'overwrite' => true ) );
		$pending_meta = get_post_meta( $atomic ); unset( $pending_meta['_lf_schedule_transaction_probe'] );
		lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && $original_meta === $pending_meta && $original_title === get_the_title( $atomic ), 'Scheduling refuses an outer transaction without changing its fixture.' );
		lfsc_check( 'pending' === get_post_meta( $atomic, '_lf_schedule_transaction_probe', true ) && false !== strpos( implode( ' ', $result['messages'] ), 'committing it or rolling it back' ), 'The caller sentinel survives and the failure explains how to finish the transaction before scheduling.' );
	} finally { $wpdb->query( 'ROLLBACK' ); clean_post_cache( $atomic ); }
	lfsc_check( $original_meta === get_post_meta( $atomic ) && $original_title === get_the_title( $atomic ), 'Caller rollback still removes its sentinel; refusal never implicitly commits or rolls back caller work.' );

	// The actual admin request must preserve raw term IDs until strict service
	// validation; absint at the boundary would turn a negative ID into a target.
	$modules = new ReflectionProperty( \LeagueFlow\Plugin::class, 'modules' ); $modules->setAccessible( true );
	$admin = null;
	foreach ( $modules->getValue( \LeagueFlow\Plugin::instance() ) as $module ) { if ( $module instanceof \LeagueFlow\Admin ) { $admin = $module; break; } }
	lfsc_check( $admin instanceof \LeagueFlow\Admin, 'Find registered admin scheduler handler.' );
	$admin_target = $match();
	foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
		$term = wp_insert_term( 'Scheduling raw scope ' . wp_generate_uuid4(), $taxonomy );
		lfsc_check( ! is_wp_error( $term ), 'Create isolated scheduling scope.' );
		$lfsc_terms[ $taxonomy ] = (int) $term['term_id'];
		wp_set_object_terms( $admin_target, array( (int) $term['term_id'] ), $taxonomy );
	}
	$window( 'regression-admin-scope', '2033-08-01' );
	$stop_redirect = static function() { throw new RuntimeException( 'lfsc_expected_redirect' ); };
	foreach ( array( 'competition', 'season' ) as $scope ) {
		$_POST = array(
			'leagueflow_auto_schedule_matches_nonce' => wp_create_nonce( 'leagueflow_auto_schedule_matches' ),
			'leagueflow_schedule_competition' => (string) $lfsc_terms['lf_competition'],
			'leagueflow_schedule_season' => (string) $lfsc_terms['lf_season'],
			'leagueflow_schedule_date' => '2033-08-01',
		);
		$_POST[ 'leagueflow_schedule_' . $scope ] = '-' . $lfsc_terms[ 'lf_' . $scope ]; $_REQUEST = $_POST;
		add_filter( 'wp_redirect', $stop_redirect, PHP_INT_MIN );
		try { $admin->handle_auto_schedule_matches(); }
		catch ( RuntimeException $error ) { if ( 'lfsc_expected_redirect' !== $error->getMessage() ) { throw $error; } }
		finally { remove_filter( 'wp_redirect', $stop_redirect, PHP_INT_MIN ); $_POST = array(); $_REQUEST = array(); }
		$result = get_transient( 'leagueflow_schedule_result_' . $lfsc_admin_id );
		lfsc_check( is_array( $result ) && 0 === $result['scheduled'] && 1 === $result['failed'] && '' === get_post_meta( $admin_target, 'lf_match_datetime', true ), 'Admin scheduling rejects a negative ' . $scope . ' ID before it can select the corresponding real scope.' );
	}

	wp_set_current_user( 0 );
	lfsc_check( 0 === $service->schedule_match( $locked, array( 'date' => '2033-05-01', 'overwrite' => true ) )['scheduled'], 'Anonymous caller cannot schedule.' );

	WP_CLI::success( 'Scheduling occupancy and concurrency: ' . $GLOBALS['lfsc_checks'] . ' assertions passed.' );
} finally {
	wp_set_current_user( $lfsc_admin_id ); $_POST = array(); $_REQUEST = array();
	foreach ( array_reverse( $GLOBALS['lfsc_posts'] ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfsc_terms as $taxonomy => $id ) { wp_delete_term( $id, $taxonomy ); }
	if ( false === $lfsc_settings ) { delete_option( 'leagueflow_settings' ); } else { update_option( 'leagueflow_settings', $lfsc_settings ); }
	if ( false === $lfsc_windows ) { delete_option( \LeagueFlow\Field_Availability_Manager::OPTION ); } else { update_option( \LeagueFlow\Field_Availability_Manager::OPTION, $lfsc_windows ); }
	if ( false === $lfsc_notice ) { delete_transient( 'leagueflow_schedule_result_' . $lfsc_admin_id ); } else { set_transient( 'leagueflow_schedule_result_' . $lfsc_admin_id, $lfsc_notice, MINUTE_IN_SECONDS ); }
	$_POST = $lfsc_post; $_REQUEST = $lfsc_request;
	wp_set_current_user( $lfsc_user );
}
