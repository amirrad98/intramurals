<?php
/** Native sport setup and scoring inheritance regressions; disposable WordPress only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation with LEAGUEFLOW_DISPOSABLE_WP=1.' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';

$GLOBALS['lfss_assertions'] = 0;
function lfss_check( $condition, $message ) {
	++$GLOBALS['lfss_assertions'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfss_handle( $callback ) {
	$redirect = static function ( $location ) { throw new RuntimeException( 'redirect:' . $location ); };
	add_filter( 'wp_redirect', $redirect, PHP_INT_MIN );
	try {
		call_user_func( $callback );
		return '';
	} catch ( RuntimeException $error ) {
		if ( 0 !== strpos( $error->getMessage(), 'redirect:' ) ) { throw $error; }
		return substr( $error->getMessage(), 9 );
	} finally {
		remove_filter( 'wp_redirect', $redirect, PHP_INT_MIN );
	}
}
class LFSS_Admin extends \LeagueFlow\Admin {
	public function rules_form( $sport ) {
		$this->render_rule_override_form( 'sport', $sport, ucfirst( $sport ), \LeagueFlow\get_effective_rules( $sport ), \LeagueFlow\get_rule_overrides()['sport'][ $sport ] ?? array(), ( new \LeagueFlow\Settings() )->get_tie_breaker_labels() );
	}
}

$options = array();
foreach ( array( 'leagueflow_settings', \LeagueFlow\Sports_Manager::ENABLED_SPORTS_OPTION, \LeagueFlow\Sports_Manager::SETUP_REQUIRED_OPTION, \LeagueFlow\RULE_OVERRIDES_OPTION, \LeagueFlow\CURRENT_SEASONS_OPTION, 'leagueflow_flush_rewrite' ) as $key ) {
	$options[ $key ] = get_option( $key, null );
}
$posts = array();
$terms = array();
$sport_originals = array();
$legacy_seasons = \LeagueFlow\get_legacy_current_season_ids();
$previous_user = get_current_user_id();
$saved_post = $_POST;
$saved_request = $_REQUEST;
$saved_get = $_GET;
$capture_event = null;
$capture_term = null;
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$_POST = $_REQUEST = $_GET = array();
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

try {
	$sports = new \LeagueFlow\Sports_Manager();
	foreach ( array( 'soccer', 'volleyball' ) as $slug ) {
		$term = get_term_by( 'slug', $slug, 'lf_sport' );
		if ( $term instanceof WP_Term ) { $sport_originals[ $slug ] = clone $term; }
	}
	lfss_check( true === $sports->update_enabled_sports( array( 'soccer', 'volleyball' ) ), 'Valid sport setup saves.' );
	$enabled = $sports->get_enabled_sport_slugs();
	lfss_check( is_wp_error( $sports->update_enabled_sports( array() ) ) && $enabled === $sports->get_enabled_sport_slugs(), 'An empty selection must not silently enable Soccer or change saved sports.' );
	lfss_check( is_wp_error( $sports->update_enabled_sports( array( 'soccer', 'unknown' ) ) ) && $enabled === $sports->get_enabled_sport_slugs(), 'An invalid selection fails without partially saving.' );
	lfss_check( is_wp_error( $sports->update_enabled_sports( array( array( 'soccer' ) ) ) ), 'Malformed selections are rejected without a PHP warning.' );
	$soccer = get_term_by( 'slug', 'soccer', 'lf_sport' );
	$volleyball = get_term_by( 'slug', 'volleyball', 'lf_sport' );
	wp_update_term( $volleyball->term_id, 'lf_sport', array( 'name' => 'LFSS Campus volleyball', 'description' => 'Staff-edited programme description' ) );
	$sports->ensure_enabled_terms();
	lfss_check( 'LFSS Campus volleyball' === get_term( $volleyball->term_id )->name && 'Staff-edited programme description' === get_term( $volleyball->term_id )->description, 'Init preserves a sport term name and description.' );

	$term = static function ( $taxonomy, $label, $sport ) use ( &$terms ) {
		$made = wp_insert_term( 'LFSS ' . $label . ' ' . wp_generate_password( 6, false ), $taxonomy );
		if ( is_wp_error( $made ) ) { throw new RuntimeException( $made->get_error_message() ); }
		$id = (int) $made['term_id'];
		$terms[ $id ] = $taxonomy;
		update_term_meta( $id, 'lf_sport_slug', $sport );
		return $id;
	};
	$cup = $term( 'lf_competition', 'Volleyball Cup', 'volleyball' );
	$season = $term( 'lf_season', 'Volleyball Season', 'volleyball' );
	$soccer_season = $term( 'lf_season', 'Soccer Season', 'soccer' );
	$make_post = static function ( $type, $title, $meta = array() ) use ( &$posts, $volleyball, $cup, $season ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => 'LFSS ' . $title, 'post_status' => 'publish', 'meta_input' => $meta ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$posts[] = $id;
		wp_set_object_terms( $id, array( (int) $volleyball->term_id ), 'lf_sport' );
		wp_set_object_terms( $id, array( $cup ), 'lf_competition' );
		wp_set_object_terms( $id, array( $season ), 'lf_season' );
		return $id;
	};
	$home = $make_post( 'lf_team', 'Home' );
	$away = $make_post( 'lf_team', 'Away' );
	$match = $make_post( 'lf_match', 'Walkover', array( 'lf_home_team_id' => $home, 'lf_away_team_id' => $away, 'lf_status' => 'finished', 'lf_outcome' => 'forfeit_away' ) );
	$soccer_cup = $term( 'lf_competition', 'Soccer Cup', 'soccer' );
	$second_cup = $term( 'lf_competition', 'Second Volleyball Cup', 'volleyball' );
	$edit_context = static function ( $post_id, $contexts ) {
		$_POST = wp_slash( array( 'post_ID' => $post_id, 'post_type' => get_post_type( $post_id ), 'post_title' => get_the_title( $post_id ), 'post_status' => 'publish', 'tax_input' => $contexts, '_wpnonce' => wp_create_nonce( 'update-post_' . $post_id ) ) );
		$_REQUEST = $_POST;
		return edit_post();
	};
	$edit_context( $match, array( 'lf_competition' => get_term( $soccer_cup )->name, 'lf_season' => get_term( $soccer_season )->name ) );
	lfss_check( array( $cup ) === wp_get_object_terms( $match, 'lf_competition', array( 'fields' => 'ids' ) ) && array( $season ) === wp_get_object_terms( $match, 'lf_season', array( 'fields' => 'ids' ) ), 'The real classic edit_post path keeps prior competition and season on a foreign-sport submission.' );
	$unknown_name = 'LFSS Unknown Competition ' . wp_generate_password( 6, false );
	$edit_context( $match, array( 'lf_competition' => $unknown_name ) );
	lfss_check( ! term_exists( $unknown_name, 'lf_competition' ) && array( $cup ) === wp_get_object_terms( $match, 'lf_competition', array( 'fields' => 'ids' ) ), 'Classic input cannot implicitly create an unscoped competition.' );
	$edit_context( $match, array( 'lf_competition' => get_term( $second_cup )->name ) );
	lfss_check( array( $second_cup ) === wp_get_object_terms( $match, 'lf_competition', array( 'fields' => 'ids' ) ), 'Classic input accepts a competition in the same sport.' );
	$both_names = get_term( $cup )->name . ',' . get_term( $second_cup )->name;
	$edit_context( $match, array( 'lf_competition' => $both_names ) );
	lfss_check( array( $second_cup ) === wp_get_object_terms( $match, 'lf_competition', array( 'fields' => 'ids' ) ), 'A match cannot acquire ambiguous multiple competition rules from its editor.' );
	wp_set_object_terms( $match, array( $cup, $second_cup ), 'lf_competition' );
	$edit_context( $match, array( 'lf_competition' => $both_names ) );
	$assigned = wp_get_object_terms( $match, 'lf_competition', array( 'fields' => 'ids' ) );
	sort( $assigned );
	$expected = array( $cup, $second_cup ); sort( $expected );
	lfss_check( $expected === $assigned, 'Unchanged legacy multiple-term fixtures are not silently trimmed.' );
	$edit_context( $home, array( 'lf_competition' => $both_names ) );
	$assigned = wp_get_object_terms( $home, 'lf_competition', array( 'fields' => 'ids' ) ); sort( $assigned );
	lfss_check( $expected === $assigned, 'A team can remain eligible for multiple competitions of its sport.' );
	$edit_context( $match, array( 'lf_competition' => '' ) );
	lfss_check( array() === wp_get_object_terms( $match, 'lf_competition', array( 'fields' => 'ids' ) ), 'An explicit blank clears a competition assignment.' );
	$_POST = $_REQUEST = array();
	wp_set_object_terms( $match, array( $cup ), 'lf_competition' );
	wp_set_object_terms( $home, array( $cup ), 'lf_competition' );
	lfss_check( is_wp_error( $sports->validate_term_sport( $cup, 'soccer' ) ), 'A competition cannot move away from its existing records.' );
	$_POST = array( 'lf_sport_slug' => 'soccer' );
	$sports->save_term_sport_field( $cup );
	lfss_check( 'volleyball' === \LeagueFlow\get_term_sport_slug( $cup ), 'The admin sport-edit handler preserves an in-use competition.' );
	$_POST = array();
	$request = new WP_REST_Request( 'POST', '/wp/v2/lf_competition/' . $cup );
	$request->set_param( 'meta', array( 'lf_sport_slug' => 'soccer' ) );
	$request->set_param( 'name', 'Should not be renamed' );
	$response = rest_do_request( $request );
	lfss_check( 400 === $response->get_status() && 'volleyball' === \LeagueFlow\get_term_sport_slug( $cup ) && 'Should not be renamed' !== get_term( $cup )->name, 'Native REST rejects a conflicting sport before changing the term or its metadata: ' . wp_json_encode( $response->get_data() ) );
	\LeagueFlow\set_current_season( $soccer_season );
	$request = new WP_REST_Request( 'POST', '/wp/v2/lf_season/' . $season );
	$request->set_param( 'meta', array( 'lf_is_current' => true ) );
	$response = rest_do_request( $request );
	lfss_check( 200 === $response->get_status() && $season === \LeagueFlow\get_current_season_id( 'volleyball' ) && $soccer_season === \LeagueFlow\get_current_season_id( 'soccer' ), 'REST makes a season current only for its sport.' );
	lfss_check( true === $response->get_data()['meta']['lf_is_current'], 'REST represents the per-sport current-season map truthfully.' );
	$request->set_param( 'meta', array( 'lf_is_current' => false ) );
	$response = rest_do_request( $request );
	lfss_check( 200 === $response->get_status() && 0 === \LeagueFlow\get_current_season_id( 'volleyball' ) && $soccer_season === \LeagueFlow\get_current_season_id( 'soccer' ), 'REST clears one sport current season without clearing another.' );

	$team_request = static function ( $id, $values ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/lf_team' . ( $id ? '/' . $id : '' ) );
		foreach ( $values as $key => $value ) { $request->set_param( $key, $value ); }
		return rest_do_request( $request );
	};
	$home_title = get_the_title( $home );
	foreach ( array( 'lf_competition' => $soccer_cup, 'lf_season' => $soccer_season ) as $taxonomy => $foreign ) {
		$response = $team_request( $home, array( 'title' => 'Rejected foreign context', $taxonomy => array( $foreign ) ) );
		lfss_check( 400 === $response->get_status() && $home_title === get_the_title( $home ) && array( 'lf_competition' === $taxonomy ? $cup : $season ) === wp_get_object_terms( $home, $taxonomy, array( 'fields' => 'ids' ) ), 'Native team REST rejects foreign context without partially changing the title or terms.' );
	}
	foreach ( array( array( (int) $soccer->term_id ), array() ) as $choice ) {
		$response = $team_request( $home, array( 'title' => 'Rejected sport change', 'lf_sport' => $choice ) );
		lfss_check( 400 === $response->get_status() && $home_title === get_the_title( $home ) && 'volleyball' === \LeagueFlow\get_post_primary_term_slug( $home, 'lf_sport' ), 'Native team REST cannot change or clear a team sport after assignment.' );
	}
	$response = $team_request( $home, array( 'title' => 'Rejected negative context', 'lf_competition' => array( -$cup ) ) );
	lfss_check( 400 === $response->get_status() && $home_title === get_the_title( $home ) && array( $cup ) === wp_get_object_terms( $home, 'lf_competition', array( 'fields' => 'ids' ) ), 'A negative native team term ID is rejected instead of bypassing validation as an unchanged positive ID.' );
	$response = $team_request( $home, array( 'lf_competition' => array( $cup, $second_cup ) ) );
	$assigned = wp_get_object_terms( $home, 'lf_competition', array( 'fields' => 'ids' ) ); sort( $assigned );
	lfss_check( 200 === $response->get_status() && $expected === $assigned, 'Native team REST permits multiple competitions within the team sport.' );
	$response = $team_request( $home, array( 'lf_competition' => array() ) );
	lfss_check( 200 === $response->get_status() && array() === wp_get_object_terms( $home, 'lf_competition', array( 'fields' => 'ids' ) ), 'Native team REST permits clearing optional context.' );
	$legacy_cup = $term( 'lf_competition', 'Legacy unscoped cup', '' );
	wp_set_object_terms( $home, array( $legacy_cup ), 'lf_competition' );
	$response = $team_request( $home, array( 'title' => $home_title, 'lf_competition' => array( $legacy_cup ) ) );
	lfss_check( 200 === $response->get_status() && array( $legacy_cup ) === wp_get_object_terms( $home, 'lf_competition', array( 'fields' => 'ids' ) ), 'Unchanged legacy team assignments may round-trip without silent cleanup.' );
	wp_set_object_terms( $home, array( $cup ), 'lf_competition' );
	$sportless = wp_insert_post( array( 'post_type' => 'lf_team', 'post_status' => 'draft', 'post_title' => 'LFSS Sportless draft' ) );
	$posts[] = $sportless;
	$response = $team_request( $sportless, array( 'title' => 'Rejected new context', 'lf_sport' => array( (int) $soccer->term_id ), 'lf_competition' => array( $cup ) ) );
	lfss_check( 400 === $response->get_status() && 'LFSS Sportless draft' === get_the_title( $sportless ) && '' === \LeagueFlow\get_post_primary_term_slug( $sportless, 'lf_sport' ), 'Assigning the first team sport and a foreign competition fails before changing either.' );
	$response = $team_request( $sportless, array( 'lf_sport' => array( (int) $volleyball->term_id ), 'lf_competition' => array( $cup ), 'lf_season' => array( $season ) ) );
	lfss_check( 200 === $response->get_status() && 'volleyball' === \LeagueFlow\get_post_primary_term_slug( $sportless, 'lf_sport' ) && array( $cup ) === wp_get_object_terms( $sportless, 'lf_competition', array( 'fields' => 'ids' ) ), 'A sportless draft can acquire one enabled sport and matching context together.' );
	global $wpdb;
	$team_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_team'" );
	$response = $team_request( 0, array( 'title' => 'Rejected ambiguous team', 'lf_sport' => array( (int) $soccer->term_id, (int) $volleyball->term_id ) ) );
	lfss_check( 400 === $response->get_status() && $team_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_team'" ), 'Rejecting multiple sports leaves no orphan team.' );

	$event = $make_post( 'lf_calendar_event', 'Context event', array( 'lf_event_start_datetime' => '2033-06-12 09:00' ) );
	$native_event_request = static function ( $id, $values ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/lf_calendar_event' . ( $id ? '/' . $id : '' ) );
		foreach ( $values as $key => $value ) { $request->set_param( $key, $value ); }
		return rest_do_request( $request );
	};
	$event_context = static function ( $id ) {
		$context = array();
		foreach ( array( 'lf_sport', 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$context[$taxonomy] = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			sort( $context[$taxonomy] );
		}
		return $context;
	};
	$original_context = $event_context( $event );
	$event_title = get_the_title( $event );
	foreach ( array(
		array( 'lf_competition' => array( $soccer_cup ) ),
		array( 'lf_season' => array( $soccer_season ) ),
		array( 'lf_sport' => array( (int) $soccer->term_id ) ),
		array( 'lf_sport' => array( (int) $soccer->term_id ), 'lf_competition' => array( $soccer_cup ) ),
		array( 'lf_sport' => array() ),
		array( 'lf_competition' => array( -$cup ) ),
	) as $invalid_context ) {
		$response = $native_event_request( $event, $invalid_context + array( 'title' => 'Rejected event context' ) );
		lfss_check( 400 === $response->get_status() && $event_title === get_the_title( $event ) && $original_context === $event_context( $event ), 'Native event REST validates submitted and retained context together before any title or taxonomy writes.' );
	}
	$response = $native_event_request( $event, array( 'lf_sport' => array( (int) $soccer->term_id ), 'lf_competition' => array( $soccer_cup ), 'lf_season' => array( $soccer_season ) ) );
	lfss_check( 200 === $response->get_status() && array( 'lf_sport' => array( (int) $soccer->term_id ), 'lf_competition' => array( $soccer_cup ), 'lf_season' => array( $soccer_season ) ) === $event_context( $event ), 'Native event REST may move an event to another enabled sport with matching competitions and seasons.' );
	$response = $native_event_request( $event, array( 'lf_sport' => array( (int) $volleyball->term_id ), 'lf_competition' => array(), 'lf_season' => array() ) );
	lfss_check( 200 === $response->get_status() && array( 'lf_sport' => array( (int) $volleyball->term_id ), 'lf_competition' => array(), 'lf_season' => array() ) === $event_context( $event ), 'Native event REST may clear optional context while changing sport.' );
	$events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_calendar_event'" );
	$response = $native_event_request( 0, array( 'title' => 'Rejected native event', 'lf_sport' => array( (int) $soccer->term_id ), 'lf_competition' => array( $cup ) ) );
	lfss_check( 400 === $response->get_status() && $events_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_calendar_event'" ), 'Rejected native event creation leaves no orphan record.' );
	wp_set_object_terms( $event, array(), 'lf_sport' );
	wp_set_object_terms( $event, array( $legacy_cup ), 'lf_competition' );
	$legacy_event_context = $event_context( $event );
	$response = $native_event_request( $event, $legacy_event_context + array( 'title' => $event_title ) );
	lfss_check( 200 === $response->get_status() && $legacy_event_context === $event_context( $event ), 'An unchanged unscoped legacy event may round-trip through native REST.' );
	$response = $native_event_request( $event, array( 'lf_sport' => array( (int) $volleyball->term_id ) ) );
	lfss_check( 400 === $response->get_status() && $legacy_event_context === $event_context( $event ), 'Giving a legacy event a sport also requires resolving its retained unscoped competition.' );
	$response = $native_event_request( $event, $original_context );
	lfss_check( 200 === $response->get_status() && $original_context === $event_context( $event ), 'A legacy event can be repaired by assigning consistent sport and context together.' );

	$edit_event = static function ( $id, $values ) {
		$_POST = wp_slash( $values + array( 'post_ID' => $id, 'post_type' => 'lf_calendar_event', 'post_title' => get_the_title( $id ), 'post_status' => 'publish', '_wpnonce' => wp_create_nonce( 'update-post_' . $id ), 'leagueflow_calendar_event_nonce' => wp_create_nonce( 'leagueflow_save_calendar_event' ), 'lf_event_start_datetime' => '2033-06-12T09:00', 'lf_event_end_datetime' => '' ) );
		$_REQUEST = $_POST;
		try { return edit_post(); }
		finally { $_POST = $_REQUEST = array(); }
	};
	foreach ( array(
		array( 'lf_sport_choice' => 'soccer' ),
		array( 'lf_sport_choice' => 'soccer', 'tax_input' => array( 'lf_competition' => get_term( $soccer_cup )->name ) ),
		array( 'lf_sport_choice' => 'volleyball', 'tax_input' => array( 'lf_competition' => get_term( $soccer_cup )->name, 'lf_season' => get_term( $soccer_season )->name ) ),
		array( 'lf_sport_choice' => array( 'soccer' ), 'tax_input' => array( 'lf_season' => get_term( $soccer_season )->name ) ),
	) as $invalid_context ) {
		$edit_event( $event, $invalid_context );
		lfss_check( $original_context === $event_context( $event ), 'The actual classic event editor keeps all previous assignments when any resulting context is invalid.' );
	}
	$edit_event( $event, array( 'lf_sport_choice' => 'soccer', 'tax_input' => array( 'lf_competition' => get_term( $soccer_cup )->name, 'lf_season' => get_term( $soccer_season )->name ) ) );
	lfss_check( array( 'lf_sport' => array( (int) $soccer->term_id ), 'lf_competition' => array( $soccer_cup ), 'lf_season' => array( $soccer_season ) ) === $event_context( $event ), 'The actual classic event editor saves a valid sport change with both matching context fields.' );
	$edit_event( $event, array( 'lf_sport_choice' => 'volleyball', 'tax_input' => array( 'lf_competition' => '', 'lf_season' => '' ) ) );
	lfss_check( array( 'lf_sport' => array( (int) $volleyball->term_id ), 'lf_competition' => array(), 'lf_season' => array() ) === $event_context( $event ), 'The classic event editor can explicitly clear context while changing sport.' );
	wp_set_object_terms( $event, array( $cup ), 'lf_competition' );
	wp_set_object_terms( $event, array( $season ), 'lf_season' );
	$unknown_event_cup = 'LFSS Unknown event cup ' . wp_generate_password( 6, false );
	$edit_event( $event, array( 'lf_sport_choice' => 'soccer', 'tax_input' => array( 'lf_competition' => $unknown_event_cup, 'lf_season' => get_term( $soccer_season )->name ) ) );
	lfss_check( $original_context === $event_context( $event ) && ! term_exists( $unknown_event_cup, 'lf_competition' ), 'An unknown classic event term cannot create an unscoped term or partially change another assignment.' );
	$unknown_event_sport = 'LFSS Forged event sport ' . wp_generate_password( 6, false );
	$edit_event( $event, array( 'tax_input' => array( 'lf_sport' => $unknown_event_sport, 'lf_competition' => get_term( $soccer_cup )->name ) ) );
	lfss_check( $original_context === $event_context( $event ) && ! term_exists( $unknown_event_sport, 'lf_sport' ), 'A crafted hidden sport tag cannot bypass the classic event sport selector or create a sport.' );
	$edit_event( $event, array( 'lf_sport_choice' => 'soccer', 'lf_event_start_datetime' => '2033-02-30T09:00', 'tax_input' => array( 'lf_competition' => get_term( $soccer_cup )->name, 'lf_season' => get_term( $soccer_season )->name ) ) );
	lfss_check( $original_context === $event_context( $event ) && '2033-06-12 09:00' === get_post_meta( $event, 'lf_event_start_datetime', true ), 'A rejected event date cannot leave new competitions and seasons attached to the previous sport.' );
	wp_set_object_terms( $event, array(), 'lf_sport' );
	wp_set_object_terms( $event, array( $legacy_cup ), 'lf_competition' );
	wp_set_object_terms( $event, array(), 'lf_season' );
	$edit_event( $event, array( 'tax_input' => array( 'lf_competition' => get_term( $legacy_cup )->name, 'lf_season' => '' ) ) );
	lfss_check( $legacy_event_context === $event_context( $event ), 'Classic edits preserve unchanged legacy event context without silently assigning a default sport.' );
	$edit_event( $event, array( 'lf_sport_choice' => 'volleyball', 'tax_input' => array( 'lf_competition' => get_term( $cup )->name, 'lf_season' => get_term( $season )->name ) ) );
	lfss_check( $original_context === $event_context( $event ), 'The classic editor can explicitly repair an unscoped legacy event.' );

	// Capture every event/term created during these API tests, including failures.
	$capture_event = static function ( $id, $post ) use ( &$posts ) {
		if ( 'lf_calendar_event' === $post->post_type ) { $posts[] = $id; }
	};
	$capture_term = static function ( $id, $tt_id, $taxonomy ) use ( &$terms ) { $terms[ $id ] = $taxonomy; };
	add_action( 'wp_insert_post', $capture_event, 10, 2 );
	add_action( 'created_term', $capture_term, 10, 3 );
	$event_count = static function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_calendar_event'" ); };
	$event_request = static function ( $values, $route = '/calendar/events' ) {
		$request = new WP_REST_Request( 'POST', '/leagueflow/v1' . $route );
		$request->set_param( 'event_data', $values + array( 'title' => 'LFSS Event ' . wp_generate_password( 8, false ), 'start_datetime' => '2033-06-12 09:00', 'post_status' => 'draft' ) );
		return rest_do_request( $request );
	};
	foreach ( array( '/calendar/events', '/events/create-calendar-event' ) as $route ) {
		$before = $event_count();
		$new_level = 'LFSS Uncreated level ' . wp_generate_password( 6, false );
		$new_cup = 'LFSS Uncreated cup ' . wp_generate_password( 6, false );
		$response = $event_request( array( 'sport' => 'volleyball', 'league_level' => $new_level, 'competition' => $new_cup, 'season' => $soccer_season ), $route );
		lfss_check( 400 === $response->get_status() && $before === $event_count() && ! term_exists( $new_level, 'lf_league_level' ) && ! term_exists( $new_cup, 'lf_competition' ), 'Calendar context validation happens before creating the event or any earlier new terms.' );
		$response = $event_request( array( 'sport' => 'volleyball', 'competition' => $cup, 'season' => $season ), $route );
		$event_id = $response->get_data()['event']['postId'] ?? 0;
		lfss_check( 201 === $response->get_status() && $event_id && array( $cup ) === wp_get_object_terms( $event_id, 'lf_competition', array( 'fields' => 'ids' ) ) && array( $season ) === wp_get_object_terms( $event_id, 'lf_season', array( 'fields' => 'ids' ) ), 'Each calendar create route accepts existing context from the chosen sport: ' . wp_json_encode( $response->get_data() ) );
	}
	$before = $event_count();
	$response = $event_request( array( 'competition' => $cup ) );
	lfss_check( 400 === $response->get_status() && $before === $event_count(), 'Calendar context requires an explicit sport.' );
	$response = $event_request( array( 'sport' => array( 'soccer', 'volleyball' ) ) );
	lfss_check( 400 === $response->get_status() && $before === $event_count(), 'Calendar events cannot silently choose one of multiple sports.' );
	$response = $event_request( array( 'sport' => 'volleyball', 'competition' => $legacy_cup ) );
	lfss_check( 400 === $response->get_status() && $before === $event_count() && '' === \LeagueFlow\get_term_sport_slug( $legacy_cup ), 'An existing unscoped calendar term is rejected without reclassifying its historical records.' );
	foreach ( array( -$cup, $cup . '.5', true ) as $invalid_term ) {
		$response = $event_request( array( 'sport' => 'volleyball', 'competition' => $invalid_term ) );
		lfss_check( 400 === $response->get_status() && $before === $event_count(), 'Malformed term IDs are rejected rather than coerced into a different existing term.' );
	}
	$custom_sport = 'LFSS Custom sport ' . wp_generate_password( 6, false );
	$custom_cup = 'LFSS Custom cup ' . wp_generate_password( 6, false );
	$custom_season = 'LFSS Custom season ' . wp_generate_password( 6, false );
	$response = $event_request( array( 'sport' => $custom_sport, 'competition' => $custom_cup, 'season' => $custom_season ) );
	$new_sport_term = get_term_by( 'name', $custom_sport, 'lf_sport' );
	$new_cup_term = get_term_by( 'name', $custom_cup, 'lf_competition' );
	$new_season_term = get_term_by( 'name', $custom_season, 'lf_season' );
	lfss_check( 201 === $response->get_status() && $new_sport_term instanceof WP_Term && $new_cup_term instanceof WP_Term && $new_season_term instanceof WP_Term && $new_sport_term->slug === \LeagueFlow\get_term_sport_slug( $new_cup_term->term_id ) && $new_sport_term->slug === \LeagueFlow\get_term_sport_slug( $new_season_term->term_id ), 'Authorized calendar term creation assigns both new scoped terms to the actual sport.' );
	$failed_sport = 'LFSS Failed sport ' . wp_generate_password( 6, false );
	$failed_cup = 'LFSS Failed cup ' . wp_generate_password( 6, false );
	$before = $event_count();
	$fail_ownership = static function ( $check, $id, $key ) use ( $failed_cup ) {
		$term = get_term( $id, 'lf_competition' );
		return 'lf_sport_slug' === $key && $term instanceof WP_Term && $failed_cup === $term->name ? false : $check;
	};
	add_filter( 'update_term_metadata', $fail_ownership, 10, 3 );
	try { $response = $event_request( array( 'sport' => $failed_sport, 'competition' => $failed_cup ) ); }
	finally { remove_filter( 'update_term_metadata', $fail_ownership, 10 ); }
	lfss_check( 500 === $response->get_status() && $before === $event_count() && ! term_exists( $failed_sport, 'lf_sport' ) && ! term_exists( $failed_cup, 'lf_competition' ), 'A failed ownership write removes the event and all terms created by its request.' );
	$race_level = 'LFSS Race level ' . wp_generate_password( 6, false );
	$change_owner = static function ( $id, $post ) use ( $cup ) {
		if ( 'lf_calendar_event' === $post->post_type ) { update_term_meta( $cup, 'lf_sport_slug', 'soccer' ); }
	};
	add_action( 'wp_insert_post', $change_owner, 20, 2 );
	try { $response = $event_request( array( 'sport' => 'volleyball', 'competition' => $cup, 'league_level' => $race_level ) ); }
	finally { remove_action( 'wp_insert_post', $change_owner, 20 ); update_term_meta( $cup, 'lf_sport_slug', 'volleyball' ); }
	lfss_check( 400 === $response->get_status() && $before === $event_count() && ! term_exists( $race_level, 'lf_league_level' ), 'Calendar assignment rechecks changed owners before creating terms and removes the incomplete event.' );
	remove_action( 'wp_insert_post', $capture_event, 10 );
	remove_action( 'created_term', $capture_term, 10 );

	delete_option( \LeagueFlow\RULE_OVERRIDES_OPTION );
	$defaults = \LeagueFlow\defaults();
	update_option( 'leagueflow_settings', $defaults );
	\LeagueFlow\set_rule_override( 'sport', 'volleyball', array( 'points_win' => 4, 'forfeit_score_winner' => 2 ) );
	\LeagueFlow\set_rule_override( 'competition', $cup, array( 'points_loss' => 1 ) );
	$rules = \LeagueFlow\get_effective_rules( 'volleyball', $cup );
	lfss_check( 4 === $rules['values']['points_win'] && 'sport' === $rules['source']['points_win'] && 1 === $rules['values']['points_loss'] && 'competition' === $rules['source']['points_loss'] && 'global' === $rules['source']['points_draw'], 'Each rule independently inherits through global, sport and competition.' );
	$standings = new \LeagueFlow\Standings_Service();
	$rows = array_column( $standings->get_rows( $cup, $season, $volleyball->term_id ), null, 'team_id' );
	lfss_check( 2 === $rows[ $home ]['goals_for'] && 0 === $rows[ $away ]['goals_for'] && 4 === $rows[ $home ]['points'] && 1 === $rows[ $away ]['points'], 'A volleyball forfeit uses its sport walkover score and competition points.' );
	\LeagueFlow\set_rule_override( 'competition', $cup, array( 'forfeit_score_winner' => 1 ) );
	$rows = array_column( $standings->get_rows( $cup, $season, $volleyball->term_id ), null, 'team_id' );
	lfss_check( 1 === $rows[ $home ]['goals_for'], 'A competition can override its sport walkover score.' );
	lfss_check( 3 === \LeagueFlow\get_effective_rules( 'soccer' )['values']['forfeit_score_winner'], 'Forfeit scoring in other sports remains inherited from the global default.' );
	foreach ( array( '-1', '1.5', '1e3', '999999999999999999999999999', array( '2' ), true ) as $invalid ) {
		lfss_check( \LeagueFlow\Settings::validate_rule_override( array( 'points_win' => $invalid ) ) && array() === \LeagueFlow\sanitize_rule_override( array( 'points_win' => $invalid ) ), 'Invalid scoring values are rejected, never coerced into a new rule.' );
	}
	lfss_check( array() === \LeagueFlow\Settings::validate_rule_override( array( 'points_loss' => '0', 'points_draw' => '', 'forfeit_score_winner' => '2', 'tie_breakers' => array( '', '', '', '' ) ) ), 'Explicit zero and blank inheritance are distinct valid values.' );
	lfss_check( ! empty( \LeagueFlow\Settings::validate_rule_override( array( 'tie_breakers' => array( 'wins', 'wins' ) ) ) ), 'Duplicate tie-breaker priorities are rejected.' );

	$fields = new \LeagueFlow\Field_Availability_Manager();
	$knockout = new \LeagueFlow\Knockout_Service();
	$admin = new LFSS_Admin( $standings, $knockout, new \LeagueFlow\Renderer( $standings, $knockout, $sports ), new \LeagueFlow\Seeder(), $sports, new \LeagueFlow\Exporter( $sports ), $fields, new \LeagueFlow\Fixture_Generator( $fields ) );
	ob_start(); $admin->rules_form( 'volleyball' ); $html = ob_get_clean();
	$dom = new DOMDocument();
	@$dom->loadHTML( $html );
	$xpath = new DOMXPath( $dom );
	lfss_check( '' === $xpath->query( '//input[@name="lf_rules[points_draw]"]' )->item( 0 )->getAttribute( 'value' ) && '1' === $xpath->query( '//input[@name="lf_rules[points_draw]"]' )->item( 0 )->getAttribute( 'placeholder' ), 'The scoring form leaves inherited fields blank and shows the inherited value.' );
	lfss_check( '4' === $xpath->query( '//input[@name="lf_rules[points_win]"]' )->item( 0 )->getAttribute( 'value' ) && false !== strpos( $html, 'Sets won' ) && false !== strpos( $html, 'Reset to inherited scoring' ), 'The form shows stored values, sport units and an explicit reset action.' );
	$submit_rules = static function ( $values, $extra = array() ) use ( $admin ) {
		$_POST = array_merge( array( 'lf_rules_scope' => 'sport', 'lf_rules_key' => 'volleyball', 'lf_rules_custom' => '1', 'lf_rules' => $values, 'leagueflow_rules_nonce' => wp_create_nonce( 'leagueflow_save_rule_override_sport_volleyball' ) ), $extra );
		$_REQUEST = $_POST;
		return lfss_handle( array( $admin, 'handle_save_rule_override' ) );
	};
	$url = $submit_rules( array( 'points_win' => '5', 'points_draw' => '', 'points_loss' => '', 'forfeit_score_winner' => '2', 'tie_breakers' => array( '', '', '', '' ) ) );
	$stored = \LeagueFlow\get_rule_overrides()['sport']['volleyball'];
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $return_args );
	lfss_check( array( 'points_win' => 5, 'forfeit_score_winner' => 2 ) === $stored && 'volleyball' === ( $return_args['lf_sport'] ?? '' ) && 'sport:volleyball' === ( $return_args['rules_scope'] ?? '' ), 'Saving partial rules preserves inheritance and returns to the same sport editor: ' . wp_json_encode( array( $stored, $url ) ) );
	$defaults['points_draw'] = 8;
	update_option( 'leagueflow_settings', $defaults );
	lfss_check( 8 === \LeagueFlow\get_effective_rules( 'volleyball' )['values']['points_draw'], 'A future default change continues to flow into a blank sport field.' );
	$url = $submit_rules( array( 'points_win' => '-4', 'forfeit_score_winner' => '1' ) );
	lfss_check( $stored === \LeagueFlow\get_rule_overrides()['sport']['volleyball'] && false === strpos( $url, 'leagueflow_rules_saved' ), 'An invalid submitted rule does not partially overwrite valid saved scoring.' );
	$submit_rules( array( 'points_win' => 'nonsense' ), array( 'lf_rules_reset' => '1' ) );
	lfss_check( ! isset( \LeagueFlow\get_rule_overrides()['sport']['volleyball'] ) && 8 === \LeagueFlow\get_effective_rules( 'volleyball' )['values']['points_draw'], 'Reset clears overrides even if an unsaved field is invalid.' );
	$_POST = $_REQUEST = array( 'leagueflow_save_sports_nonce' => wp_create_nonce( 'leagueflow_save_sports' ) );
	lfss_handle( array( $admin, 'handle_save_sports' ) );
	lfss_check( $enabled === $sports->get_enabled_sport_slugs(), 'The admin setup handler also refuses empty selections.' );
	$_POST = $_REQUEST = array();
	$sports->update_enabled_sports( array( 'soccer' ) );
	lfss_check( 'volleyball' === \LeagueFlow\get_term_sport_slug( $cup ) && 'lf_match' === get_post_type( $match ) && true === $sports->validate_term_sport( $cup, 'volleyball' ), 'Disabling a sport retains its records and existing term assignments.' );
	lfss_check( is_wp_error( $sports->validate_term_sport( 0, 'volleyball' ) ), 'A new term cannot be assigned a disabled sport.' );
	$response = $team_request( $home, array( 'title' => $home_title, 'lf_sport' => array( (int) $volleyball->term_id ) ) );
	lfss_check( 200 === $response->get_status(), 'Native REST still allows editing an existing team in a disabled sport.' );
	$team_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_team'" );
	$response = $team_request( 0, array( 'title' => 'Rejected disabled sport team', 'lf_sport' => array( (int) $volleyball->term_id ) ) );
	lfss_check( 400 === $response->get_status() && $team_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_team'" ), 'A new native REST team cannot be assigned to a disabled sport.' );
	$response = $native_event_request( $event, $original_context + array( 'title' => $event_title ) );
	lfss_check( 200 === $response->get_status() && $original_context === $event_context( $event ), 'Native event edits may retain an existing disabled sport unchanged.' );
	$events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_calendar_event'" );
	$response = $native_event_request( 0, array( 'title' => 'Rejected disabled sport event', 'lf_sport' => array( (int) $volleyball->term_id ) ) );
	lfss_check( 400 === $response->get_status() && $events_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_calendar_event'" ), 'A new native event cannot be assigned to a disabled sport.' );

	$settings = new \LeagueFlow\Settings();
	$sanitized = $settings->sanitize( array( 'points_win' => '-7', 'points_draw' => '1.5', 'forfeit_score_winner' => 'bogus', 'team_slug' => '', 'match_slug' => 'matches' ) );
	lfss_check( $defaults['points_win'] === $sanitized['points_win'] && 8 === $sanitized['points_draw'] && 3 === $sanitized['forfeit_score_winner'] && $defaults['team_slug'] === $sanitized['team_slug'], 'Invalid default scoring and blank slugs keep their previous values.' );
	$sanitized = $settings->sanitize( array( 'team_slug' => 'same', 'match_slug' => 'same' ) );
	lfss_check( $defaults['team_slug'] === $sanitized['team_slug'] && $defaults['match_slug'] === $sanitized['match_slug'], 'Conflicting public slugs do not break existing routes.' );
} finally {
	$_POST = $_REQUEST = $_GET = array();
	if ( $capture_event ) { remove_action( 'wp_insert_post', $capture_event, 10 ); }
	if ( $capture_term ) { remove_action( 'created_term', $capture_term, 10 ); }
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	foreach ( $terms as $id => $taxonomy ) { wp_delete_term( $id, $taxonomy ); }
	foreach ( array( 'soccer', 'volleyball' ) as $slug ) {
		$term = get_term_by( 'slug', $slug, 'lf_sport' );
		if ( isset( $sport_originals[ $slug ] ) ) {
			$original = $sport_originals[ $slug ];
			wp_update_term( $original->term_id, 'lf_sport', array( 'name' => $original->name, 'description' => $original->description ) );
		} elseif ( $term instanceof WP_Term ) {
			wp_delete_term( $term->term_id, 'lf_sport' );
		}
	}
	foreach ( $options as $key => $value ) {
		if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); }
	}
	foreach ( $legacy_seasons as $id ) { update_term_meta( $id, 'lf_is_current', 1 ); }
	\LeagueFlow\flush_current_season_cache();
	delete_transient( 'leagueflow_term_sport_error_' . get_current_user_id() );
	delete_transient( 'leagueflow_notices_' . get_current_user_id() );
	wp_set_current_user( $previous_user );
	$_POST = $saved_post;
	$_REQUEST = $saved_request;
	$_GET = $saved_get;
}
WP_CLI::success( 'Sport setup and scoring inheritance: ' . $GLOBALS['lfss_assertions'] . ' assertions passed.' );
