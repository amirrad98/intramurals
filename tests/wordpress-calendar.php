<?php
/** Calendar SQL, REST and Gutenberg regressions on the disposable test site only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}
$GLOBALS['lfcal_posts'] = array();
$GLOBALS['lfcal_checks'] = 0;
$lfcal_terms = array();
$lfcal_user = get_current_user_id();
$lfcal_zone = get_option( 'timezone_string' );
$lfcal_windows = get_option( \LeagueFlow\Field_Availability_Manager::OPTION );
function lfcal_check( $condition, $message ) {
	++$GLOBALS['lfcal_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfcal_post( $type, $title, $meta = array(), $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish' ), $extra ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$GLOBALS['lfcal_posts'][] = $id;
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}
function lfcal_request( $params ) {
	$request = new WP_REST_Request( 'GET', '/leagueflow/v1/calendar/events' );
	$request->set_query_params( $params );
	return rest_do_request( $request );
}
try {
	update_option( 'timezone_string', 'America/Vancouver' );
	$term = wp_insert_term( 'Calendar review ' . wp_generate_uuid4(), 'lf_competition' );
	lfcal_check( ! is_wp_error( $term ), 'Create scope.' );
	$competition = (int) $term['term_id'];
	$lfcal_terms[] = $competition;
	$home = lfcal_post( 'lf_team', 'Calendar Home' );
	$away = lfcal_post( 'lf_team', 'Calendar Away' );
	$hidden_team = lfcal_post( 'lf_team', 'Hidden Calendar Team', array(), array( 'post_status' => 'private' ) );
	$make_match = static function( $date, $extra_meta = array(), $extra = array() ) use ( $home, $away, $competition ) {
		$id = lfcal_post( 'lf_match', 'Calendar Home vs Calendar Away', array_merge( array( 'lf_match_datetime' => $date, 'lf_home_team_id' => $home, 'lf_away_team_id' => $away, 'lf_status' => 'scheduled' ), $extra_meta ), $extra );
		wp_set_object_terms( $id, array( $competition ), 'lf_competition' );
		return $id;
	};
	for ( $i = 0; $i < 115; $i++ ) { $make_match( '2026-10-15 18:30' ); }
	$november = $make_match( '2026-11-29 18:30' );
	$december = $make_match( '2026-12-07 18:30:45' );
	$invalid = $make_match( '2026-11-31 18:30' );
	$make_match( 'not-a-date' );
	$make_match( '2026-12-07 18:30', array(), array( 'post_password' => 'secret' ) );
	$make_match( '2026-12-07 18:30', array( 'lf_away_team_id' => $hidden_team ) );
	$make_match( '2026-12-07 18:30', array(), array( 'post_status' => 'draft' ) );
	$span = lfcal_post( 'lf_calendar_event', 'Two-day drop-in', array( 'lf_event_start_datetime' => '2026-11-30 22:00', 'lf_event_end_datetime' => '2026-12-02 00:00', 'lf_event_type' => 'drop_in', 'lf_event_status' => 'scheduled', 'lf_event_cost' => '$5' ) );
	wp_set_object_terms( $span, array( $competition ), 'lf_competition' );
	$renderer = leagueflow()->renderer();
	$scope = array( 'competition' => $competition, 'public_only' => true, 'limit' => 100 );
	wp_set_current_user( 0 );
	$initial = $renderer->get_calendar_page( $scope );
	lfcal_check( 100 === count( $initial['items'] ) && 118 === $initial['total'] && 2 === $initial['pages'], 'Unfiltered history proves Nov/Dec lie beyond the first 100, excluding invalid and protected records before counts.' );
	lfcal_check( ! in_array( $november, array_column( $initial['items'], 'postId' ), true ), 'Regression fixture genuinely exceeds original first-page limit.' );
	$nov = $renderer->get_calendar_page( $scope + array( 'start_date' => '2026-11-01', 'end_date' => '2026-11-30' ) );
	lfcal_check( array( $november, $span ) === array_column( $nov['items'], 'postId' ), 'November range reaches Nov 29 directly.' );
	$dec = $renderer->get_calendar_page( $scope + array( 'start_date' => '2026-12-01', 'end_date' => '2026-12-31' ) );
	lfcal_check( array( $span, $december ) === array_column( $dec['items'], 'postId' ) && 2 === $dec['total'], 'December includes the continuing drop-in and Dec 7 match with seconds.' );
	lfcal_check( false !== strpos( $dec['items'][1]['start'], '18:30:45' ), 'Stored seconds are preserved.' );
	lfcal_check( '00' === substr( $dec['items'][0]['start'], 17, 2 ), 'Minute-only times reset seconds to zero.' );
	$day = $renderer->get_calendar_page( $scope + array( 'start_date' => '2026-12-02', 'end_date' => '2026-12-02' ) );
	lfcal_check( 0 === $day['total'], 'Midnight end does not occupy the following day.' );
	$only_events = $renderer->get_calendar_page( $scope + array( 'event_type' => 'drop_in' ) );
	lfcal_check( array( $span ) === array_column( $only_events['items'], 'postId' ), 'Event-type shortcode filtering agrees with REST filtering.' );
	// Posters: the classic editor, the details-box poster field and the calendar data.
	lfcal_check( false === use_block_editor_for_post_type( 'lf_calendar_event' ), 'Calendar events use the classic editor.' );
	lfcal_check( null === $only_events['items'][0]['poster'], 'An event without a poster sends none to the calendar.' );
	$uploads = wp_upload_dir();
	$file = trailingslashit( $uploads['path'] ) . 'lfcal-poster-' . wp_generate_password( 6, false, false ) . '.png';
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
	$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Poster', 'post_status' => 'inherit' ), $file );
	$GLOBALS['lfcal_posts'][] = $attachment;
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $file ) );
	update_post_meta( $attachment, '_wp_attachment_image_alt', 'Drop-in poster' );
	$admin = null;
	$modules = new ReflectionProperty( \LeagueFlow\Plugin::class, 'modules' );
	$modules->setAccessible( true );
	foreach ( $modules->getValue( \LeagueFlow\Plugin::instance() ) as $module ) { if ( $module instanceof \LeagueFlow\Admin ) { $admin = $module; } }
	$prior_post = $_POST;
	wp_set_current_user( (int) get_user_by( 'login', 'admin' )->ID );
	// The details box requires an enabled sport.
	$poster_sport = ( new \LeagueFlow\Sports_Manager() )->get_enabled_sport_slugs()[0];
	wp_set_object_terms( $span, array( $poster_sport ), 'lf_sport' );
	$poster_post = static function ( $value ) use ( $admin, $span ) {
		$_POST = wp_slash( array(
			'leagueflow_calendar_event_nonce' => wp_create_nonce( 'leagueflow_save_calendar_event' ),
			'lf_event_start_datetime' => str_replace( ' ', 'T', substr( (string) get_post_meta( $span, 'lf_event_start_datetime', true ), 0, 16 ) ),
			'lf_event_end_datetime' => str_replace( ' ', 'T', substr( (string) get_post_meta( $span, 'lf_event_end_datetime', true ), 0, 16 ) ),
			'lf_event_type' => 'drop_in', 'lf_event_status' => 'scheduled', 'lf_event_cost' => '$5',
			'lf_sport_choice' => \LeagueFlow\get_post_primary_term_slug( $span, 'lf_sport' ),
			'lf_league_level_id' => (string) \LeagueFlow\get_post_primary_term_id( $span, 'lf_league_level' ),
			'lf_event_poster_id' => (string) $value,
		) );
		try { $admin->save_calendar_event_meta( $span, get_post( $span ) ); } finally { $_POST = array(); }
	};
	$poster_post( $attachment );
	lfcal_check( $attachment === (int) get_post_thumbnail_id( $span ), 'The details box saves the poster as the featured image.' );
	$with_poster = $renderer->get_calendar_page( $scope + array( 'event_type' => 'drop_in' ) );
	$poster = $with_poster['items'][0]['poster'];
	lfcal_check( is_array( $poster ) && wp_get_attachment_url( $attachment ) === $poster['full'] && '' !== $poster['src'] && 'Drop-in poster' === $poster['alt'], 'The calendar receives the poster sizes and its alt text.' );
	wp_set_current_user( 0 );
	$rest_events = array_column( lfcal_request( array( 'competition' => $competition, 'public_only' => true, 'start_date' => '2026-11-30', 'end_date' => '2026-12-01', 'per_page' => 50 ) )->get_data()['events'], null, 'postId' );
	lfcal_check( wp_get_attachment_url( $attachment ) === ( $rest_events[ $span ]['poster']['full'] ?? '' ), 'Calendar REST pages carry the poster too.' );
	wp_set_current_user( (int) get_user_by( 'login', 'admin' )->ID );
	$poster_post( 0 );
	lfcal_check( 0 === (int) get_post_thumbnail_id( $span ), 'Removing the poster clears the featured image.' );
	$poster_post( $span );
	lfcal_check( 0 === (int) get_post_thumbnail_id( $span ), 'A non-image ID is not accepted as a poster.' );
	$_POST = $prior_post;
	wp_set_current_user( 0 );
	@unlink( $file );
	$reverse = $renderer->get_calendar_page( $scope + array( 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'order' => 'desc' ) );
	lfcal_check( array( $december, $span ) === array_column( $reverse['items'], 'postId' ), 'Descending order applies before pagination.' );
	$out = $renderer->get_calendar_page( $scope + array( 'page' => 20 ) );
	lfcal_check( ! $out['items'] && 118 === $out['total'] && 2 === $out['pages'], 'Out-of-range pages retain actual filtered totals.' );
	$rest_scope = array( 'competition' => $competition, 'public_only' => true, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'per_page' => 1 );
	$r = lfcal_request( $rest_scope );
	lfcal_check( 200 === $r->get_status(), 'Calendar REST succeeds.' );
	$data = $r->get_data();
	lfcal_check( 2 === $data['total'] && 2 === $data['pagination']['nextPage'] && 1 === count( $data['events'] ), 'Calendar REST reports bounded page and totals.' );
	lfcal_check( 'no-store' === $r->get_headers()['Cache-Control'] && 2 === (int) $r->get_headers()['X-WP-Total'], 'Freshness and pagination headers.' );
	lfcal_check( $december === lfcal_request( $rest_scope + array( 'page' => 2 ) )->get_data()['events'][0]['postId'], 'Page two reaches Dec 7.' );
	foreach ( array( array( 'start_date' => '2026-02-30' ), array( 'start_date' => '2026-12-31', 'end_date' => '2026-11-01' ), array( 'sport' => 'missing-sport-review' ), array( 'team' => 999999999 ) ) as $bad ) {
		lfcal_check( 400 === lfcal_request( $bad )->get_status(), 'Invalid dates and scopes fail explicitly.' );
	}
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );
	$staff = $renderer->get_calendar_page( $scope + array( 'start_date' => '2026-12-01', 'end_date' => '2026-12-31' ) );
	lfcal_check( 2 === $staff['total'], 'Public calendar preview stays public even for administrators.' );
	lfcal_check( 2 === lfcal_request( $rest_scope )->get_data()['total'], 'Public-only range API agrees in authenticated context.' );
	$html = $renderer->render_match_calendar( array( 'competition' => (string) $competition, 'season' => 'all', 'show_cost' => false, 'event_sort_order' => 'desc', 'month_display' => 'popover', 'sidebar_position' => 'left' ) );
	preg_match( '/<script type="application\/json" data-calendar-data>(.*?)<\/script>/s', $html, $match );
	$payload = json_decode( $match[1] ?? '', true );
	lfcal_check( is_array( $payload ) && true === $payload['config']['rangeLoading'], 'Rendered block carries range-loading configuration.' );
	lfcal_check( current_datetime()->format( 'Y-m-01' ) === $payload['config']['calendarRange']['start_date'], 'Initial month starts from site-local current date.' );
	lfcal_check( false === $payload['config']['showCost'] && 'desc' === $payload['config']['eventSortOrder'] && 'popover' === $payload['config']['monthDisplay'] && 'left' === $payload['config']['sidebarPosition'], 'Block attributes reach runtime behavior.' );
	$second = $renderer->render_match_calendar();
	preg_match( '/aria-labelledby="([^"]+-dialog-title)"/', $html, $first_id );
	preg_match( '/aria-labelledby="([^"]+-dialog-title)"/', $second, $second_id );
	lfcal_check( $first_id[1] !== $second_id[1], 'Multiple blocks use distinct accessible dialog IDs.' );
	$block = WP_Block_Type_Registry::get_instance()->get_registered( 'leagueflow/match-calendar' );
	foreach ( array( 'show_cost', 'month_display', 'sidebar_position', 'event_sort_order', 'limit', 'event_type', 'include_knockout' ) as $attribute ) { lfcal_check( isset( $block->attributes[ $attribute ] ), 'Gutenberg schema registers ' . $attribute ); }
	$aligned = render_block( array( 'blockName' => 'leagueflow/match-calendar', 'attrs' => array( 'align' => 'wide', 'season' => 'all' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	lfcal_check( false !== strpos( $aligned, 'alignwide' ), 'Gutenberg block alignment reaches the frontend wrapper.' );
	$fixed_html = $renderer->render_match_calendar( array( 'sport' => 'soccer', 'season' => 'all' ) );
	preg_match( '/<script type="application\/json" data-calendar-data>(.*?)<\/script>/s', $fixed_html, $fixed_json );
	$fixed_payload = json_decode( $fixed_json[1] ?? '', true );
	lfcal_check( ! array_diff( array_column( $fixed_payload['sports'], 'slug' ), array( 'soccer' ) ), 'A fixed sport block never offers other sports as visitor filters.' );
	update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array( array( 'id' => 'calendar-legacy-duration', 'slot_minutes' => 180 ) ) );
	$saved_duration = $make_match( '2027-01-31 23:00', array( 'lf_duration_minutes' => 120 ) );
	$legacy_duration = $make_match( '2027-01-31 23:00', array( 'lf_field_availability_id' => 'calendar-legacy-duration' ) );
	$overnight = $renderer->get_calendar_page( $scope + array( 'start_date' => '2027-02-01', 'end_date' => '2027-02-01' ) );
	lfcal_check( array( $saved_duration, $legacy_duration ) === array_column( $overnight['items'], 'postId' ), 'Range SQL includes saved and legacy-duration matches that continue after midnight.' );
	// Timezone rules can change between the developer and CI tzdata releases.
	// Assert the exact site-local endpoint and elapsed interval independently,
	// without assuming Vancouver's UTC offset for a future date.
	foreach ( array(
		array( 'end' => '2027-02-01 01:00:00', 'seconds' => 2 * HOUR_IN_SECONDS, 'message' => 'Calendar uses the saved two-hour match duration.' ),
		array( 'end' => '2027-02-01 02:00:00', 'seconds' => 3 * HOUR_IN_SECONDS, 'message' => 'Calendar uses the legacy linked availability duration.' ),
	) as $index => $expected ) {
		$expected_end = ( new DateTimeImmutable( $expected['end'], wp_timezone() ) )->format( DATE_ATOM );
		$actual = $overnight['items'][ $index ];
		$elapsed = ( new DateTimeImmutable( $actual['end'] ) )->getTimestamp() - ( new DateTimeImmutable( $actual['start'] ) )->getTimestamp();
		lfcal_check( $expected_end === $actual['end'] && $expected['seconds'] === $elapsed, sprintf( '%s Expected %s / %d seconds; got %s / %d seconds (timezone %s, tzdata %s).', $expected['message'], $expected_end, $expected['seconds'], $actual['end'], $elapsed, wp_timezone_string(), timezone_version_get() ) );
	}
	WP_CLI::success( 'Calendar ranges, Nov/Dec visibility, seconds, spanning events, paging, public scope and block contracts: ' . $GLOBALS['lfcal_checks'] . ' assertions.' );
} finally {
	wp_set_current_user( $lfcal_user );
	update_option( 'timezone_string', $lfcal_zone );
	if ( false === $lfcal_windows ) { delete_option( \LeagueFlow\Field_Availability_Manager::OPTION ); } else { update_option( \LeagueFlow\Field_Availability_Manager::OPTION, $lfcal_windows ); }
	foreach ( array_reverse( $GLOBALS['lfcal_posts'] ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfcal_terms as $id ) { wp_delete_term( $id, 'lf_competition' ); }
}
