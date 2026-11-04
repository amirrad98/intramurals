<?php
/** Native registration acknowledgements and already-placed request regressions. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Run only against the disposable leagueflow.test WordPress installation.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

use LeagueFlow\Registration_Consent as LFR_Consent;

class LFR_Redirect extends RuntimeException {
	public $query;
	public function __construct( $location ) { parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $this->query ); parent::__construct( 'redirect' ); }
}
function lfr_assert( $value, $message ) { ++$GLOBALS['lfr_assertions']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function lfr_post_count( $type ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type=%s", $type ) ); }
function lfr_dispatch( $user_id, $action, $fields, $valid_nonce = true ) {
	wp_set_current_user( $user_id );
	$_POST = wp_slash( array_merge( $fields, array( 'leagueflow_portal_action' => $action, 'leagueflow_portal_nonce' => $valid_nonce ? wp_create_nonce( 'leagueflow_portal_' . $action ) : 'invalid' ) ) );
	$_REQUEST = $_POST;
	$catch = static function( $location ) { throw new LFR_Redirect( $location ); };
	add_filter( 'wp_redirect', $catch, PHP_INT_MIN );
	try { do_action( 'admin_post_leagueflow_portal' ); throw new RuntimeException( 'Portal handler did not redirect.' ); }
	catch ( LFR_Redirect $r ) { return $r->query['leagueflow_notice'] ?? ''; }
	finally { remove_filter( 'wp_redirect', $catch, PHP_INT_MIN ); $_POST = $_REQUEST = array(); }
}
function lfr_accept_fields() {
	$out = array( 'lf_consent' => array(), 'lf_consent_version' => array() );
	foreach ( LFR_Consent::documents() as $key => $document ) { $out['lf_consent'][ $key ] = '1'; $out['lf_consent_version'][ $key ] = $document['hash']; }
	return $out;
}
function lfr_player_for( $user ) {
	$ids = get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => 'lf_user_id', 'meta_value' => $user ) );
	return $ids ? (int) $ids[0] : 0;
}
function lfr_request( $player_id, $sport, $level, $team_id = 0 ) {
	$id = wp_insert_post( array( 'post_type' => 'lf_join_request', 'post_status' => 'private', 'post_title' => 'Registration regression request' ), true );
	lfr_assert( ! is_wp_error( $id ), 'Request fixture failed.' );
	foreach ( array( 'lf_player_id' => $player_id, 'lf_sport_slug' => $sport, 'lf_league_level_id' => $level, 'lf_team_id' => $team_id, 'lf_request_status' => 'pending' ) as $key => $value ) { update_post_meta( $id, $key, $value ); }
	$GLOBALS['lfr_posts'][] = (int) $id;
	return (int) $id;
}
function lfr_reconcile( $user_id, $states, $valid_nonce = true ) {
	wp_set_current_user( $user_id );
	$_POST = wp_slash( array( 'lf_request_states' => $states, 'leagueflow_reconcile_nonce' => $valid_nonce ? wp_create_nonce( 'leagueflow_reconcile_requests' ) : 'invalid' ) );
	$_REQUEST = $_POST;
	$catch = static function( $location ) { throw new LFR_Redirect( $location ); };
	add_filter( 'wp_redirect', $catch, PHP_INT_MIN );
	try { do_action( 'admin_post_leagueflow_reconcile_requests' ); throw new RuntimeException( 'Reconcile handler did not redirect.' ); }
	catch ( LFR_Redirect $r ) { return $r->query; }
	finally { remove_filter( 'wp_redirect', $catch, PHP_INT_MIN ); $_POST = $_REQUEST = array(); }
}

$GLOBALS['lfr_assertions'] = 0;
$GLOBALS['lfr_posts'] = array();
$original_settings = get_option( 'leagueflow_settings', array() );
$original_sports = get_option( 'leagueflow_enabled_sports' );
$users = array();
$mail_guard = static function() { return true; };
$http_guard = static function() { return new WP_Error( 'lfr_offline', 'Registration tests block outbound HTTP.' ); };
add_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
$admin_id = (int) get_user_by( 'login', 'admin' )->ID;
wp_set_current_user( $admin_id );
try {
	\LeagueFlow\ensure_portal_roles();
	update_option( 'leagueflow_enabled_sports', array( 'soccer', 'basketball' ) );
	$sports = new \LeagueFlow\Sports_Manager(); $sports->ensure_enabled_terms();
	\LeagueFlow\ensure_default_league_levels();
	$level_id = (int) get_term_by( 'slug', 'recreational', 'lf_league_level' )->term_id;
	$other_level = (int) get_term_by( 'slug', 'competitive', 'lf_league_level' )->term_id;
	$soccer_id = (int) get_term_by( 'slug', 'soccer', 'lf_sport' )->term_id;
	$settings = array_merge( \LeagueFlow\defaults(), array( 'player_registration_open' => 1, 'captain_registration_open' => 1 ) );
	update_option( 'leagueflow_settings', $settings );
	foreach ( array( 'player', 'captain', 'failure', 'unlinked' ) as $name ) {
		$id = wp_insert_user( array( 'user_login' => 'regression_consent_' . $name, 'user_email' => 'regression_consent_' . $name . '@unbc.ca', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'display_name' => 'Consent ' . ucfirst( $name ) ) );
		lfr_assert( ! is_wp_error( $id ), 'User fixture failed: ' . $name ); $users[ $name ] = (int) $id;
	}
	lfr_assert( array() === LFR_Consent::documents() && LFR_Consent::is_current( 0 ), 'Absent policy configuration must not invent an acceptance requirement.' );

	$docs = array(
		'waiver' => array( 'required' => 1, 'title' => 'Approved waiver', 'version' => '2026-10', 'url' => 'https://example.test/waiver-2026-10.pdf', 'text' => '<p>Approved participant\'s waiver: \\path.</p>' ),
		'conduct' => array( 'required' => 1, 'title' => 'Code of Conduct', 'version' => '2026-10', 'url' => '', 'text' => '<p>Approved conduct document.</p>' ),
	);
	$settings['registration_documents'] = $docs;
	update_option( 'leagueflow_settings', $settings );
	$fields = array( 'lf_player_name' => 'Participant Consent Example', 'lf_player_sports' => array( 'soccer' ), 'lf_player_sport_levels' => array( 'soccer' => $level_id ), 'lf_player_team_choices' => array( 'soccer' => '0' ) );
	$before = array( lfr_post_count( 'lf_player' ), lfr_post_count( 'lf_join_request' ) );
	lfr_assert( 'consent-updated' === lfr_dispatch( $users['player'], 'complete_player_onboarding', $fields ), 'A forged direct onboarding POST bypassed document requirements.' );
	lfr_assert( $before === array( lfr_post_count( 'lf_player' ), lfr_post_count( 'lf_join_request' ) ), 'Consent rejection created partial registration data.' );
	$accept = lfr_accept_fields(); $unchecked = $accept; unset( $unchecked['lf_consent']['conduct'] );
	lfr_assert( 'consent-required' === lfr_dispatch( $users['player'], 'complete_player_onboarding', $fields + $unchecked ), 'One checked document bypassed the other requirement.' );
	$forged = $accept; $forged['lf_consent_version']['waiver'] = str_repeat( '0', 64 );
	lfr_assert( 'consent-updated' === lfr_dispatch( $users['player'], 'complete_player_onboarding', $fields + $forged ), 'A stale document revision was accepted.' );
	$malformed = $accept; $malformed['lf_consent']['waiver'] = array( '1' );
	lfr_assert( 'consent-required' === lfr_dispatch( $users['player'], 'complete_player_onboarding', $fields + $malformed ), 'Array checkbox input was accepted.' );
	$html = LFR_Consent::render_fields( 0, $users['player'] );
	lfr_assert( 2 === substr_count( $html, 'type="checkbox"' ) && false === strpos( $html, ' checked' ) && false !== strpos( $html, 'required' ) && false !== strpos( $html, 'Read document' ), 'Document UI must show readable, individually required unchecked controls.' );

	lfr_assert( 'player-onboarding-complete' === lfr_dispatch( $users['player'], 'complete_player_onboarding', $fields + $accept ), 'Accepted player registration failed.' );
	$player_id = lfr_player_for( $users['player'] ); $GLOBALS['lfr_posts'][] = $player_id;
	$records = LFR_Consent::records( $player_id );
	lfr_assert( $player_id && 1 === count( $records ) && 2 === count( $records[0]['documents'] ), 'A successful registration lacks one complete evidence bundle.' );
	lfr_assert( $users['player'] === $records[0]['user_id'] && $player_id === $records[0]['player_id'] && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $records[0]['accepted_at'] ), 'Evidence did not bind the actual player/account/time.' );
	lfr_assert( $docs['waiver']['text'] === $records[0]['documents'][0]['text'], 'Stored document snapshot lost quotes or backslashes.' );
	lfr_assert( LFR_Consent::is_current( $player_id ) && array() === LFR_Consent::pending( $player_id, $users['player'] ), 'Accepted versions still appear outstanding.' );
	lfr_assert( 'consent-saved' === lfr_dispatch( $users['player'], 'accept_registration_documents', $accept ) && 1 === count( LFR_Consent::records( $player_id ) ), 'Repeated acceptance duplicated or changed evidence.' );
	wp_set_current_user( $users['captain'] );
	lfr_assert( is_wp_error( LFR_Consent::record( $player_id, $users['captain'], LFR_Consent::documents() ) ) && $records === LFR_Consent::records( $player_id ), 'Another account accepted for this player.' );
	lfr_assert( ! isset( get_registered_meta_keys( 'post', 'lf_player' )[ LFR_Consent::META_KEY ] ), 'Private evidence was registered for generic REST exposure.' );

	// Text changes require renewal even if staff forget to bump the friendly version label.
	$settings['registration_documents']['waiver']['text'] = '<p>Revised approved waiver.</p>';
	update_option( 'leagueflow_settings', $settings );
	lfr_assert( array( 'waiver' ) === array_keys( LFR_Consent::pending( $player_id, $users['player'] ) ) && $records === LFR_Consent::records( $player_id ), 'Document change did not invalidate only the changed revision or overwrote history.' );
	wp_set_current_user( $users['player'] );
	$portal = new \LeagueFlow\Portal( leagueflow()->renderer() );
	lfr_assert( false !== strpos( $portal->render_portal(), 'Review your registration documents' ), 'Existing participant is not prompted to renew.' );
	lfr_assert( 'invalid-request' === lfr_dispatch( $users['player'], 'accept_registration_documents', lfr_accept_fields(), false ), 'Missing/invalid CSRF token accepted renewal.' );
	lfr_assert( ! LFR_Consent::is_current( $player_id ), 'Rejected renewal changed acceptance.' );
	lfr_assert( 'consent-updated' === lfr_dispatch( $users['player'], 'accept_registration_documents', $accept ), 'A form for the old version renewed the new document.' );
	lfr_assert( 'consent-saved' === lfr_dispatch( $users['player'], 'accept_registration_documents', lfr_accept_fields() ), 'Current renewal failed.' );
	lfr_assert( 2 === count( LFR_Consent::records( $player_id ) ) && $records[0] === LFR_Consent::records( $player_id )[0] && LFR_Consent::is_current( $player_id ), 'Renewal did not append while preserving old evidence.' );

	// Captain registration is personal; it does not create acknowledgements for team members.
	$captain_fields = array( 'lf_captain_name' => 'Captain Consent Example', 'lf_sport_slug' => 'soccer', 'lf_league_level_id' => $level_id, 'lf_team_name' => 'Registration Consent Team' );
	$teams_before = lfr_post_count( 'lf_team' );
	lfr_assert( 'consent-updated' === lfr_dispatch( $users['captain'], 'complete_captain_onboarding', $captain_fields ) && $teams_before === lfr_post_count( 'lf_team' ), 'Captain registration bypassed consent or created an orphan team.' );
	lfr_assert( 'team-registered' === lfr_dispatch( $users['captain'], 'complete_captain_onboarding', $captain_fields + lfr_accept_fields() ), 'Accepted captain registration failed.' );
	$captain_id = lfr_player_for( $users['captain'] ); $GLOBALS['lfr_posts'][] = $captain_id;
	$team_id = (int) \LeagueFlow\get_player_team_ids( $captain_id )[0]; $GLOBALS['lfr_posts'][] = $team_id;
	lfr_assert( LFR_Consent::is_current( $captain_id ) && 1 === count( LFR_Consent::records( $captain_id ) ), 'Captain personal consent missing.' );

	// Invalid enabled policy fails closed, and a failed evidence write rolls back onboarding.
	$valid_settings = $settings;
	$settings['registration_documents']['waiver']['version'] = '';
	update_option( 'leagueflow_settings', $settings );
	lfr_assert( 'consent-unavailable' === lfr_dispatch( $users['failure'], 'complete_player_onboarding', $fields + lfr_accept_fields() ), 'An incomplete enabled document silently disabled enforcement.' );
	update_option( 'leagueflow_settings', $valid_settings );
	$failure = static function( $check, $id, $key ) { return LFR_Consent::META_KEY === $key ? false : $check; };
	add_filter( 'add_post_metadata', $failure, 10, 3 );
	$before = array( lfr_post_count( 'lf_player' ), lfr_post_count( 'lf_join_request' ) );
	try { lfr_assert( 'consent-save-error' === lfr_dispatch( $users['failure'], 'complete_player_onboarding', $fields + lfr_accept_fields() ), 'Failed evidence write did not stop onboarding.' ); }
	finally { remove_filter( 'add_post_metadata', $failure, 10 ); }
	lfr_assert( $before === array( lfr_post_count( 'lf_player' ), lfr_post_count( 'lf_join_request' ) ), 'Failed evidence write left partial player/request data.' );

	// Requiring a new document version prevents pending requests from being approved first.
	$settings = $valid_settings; $settings['registration_documents']['conduct']['version'] = '2026-11';
	update_option( 'leagueflow_settings', $settings );
	wp_set_current_user( $admin_id );
	$service = \LeagueFlow\Join_Request_Service::shared();
	$request_id = lfr_request( $player_id, 'soccer', $level_id, $team_id );
	$info = $service->inspect( $request_id );
	lfr_assert( in_array( 'consent_pending', $info['flags'], true ) && ! in_array( 'approve', $service->available_actions( $info ), true ), 'Staff approval ignores missing current personal acknowledgement.' );
	lfr_assert( is_wp_error( $service->apply_action( $request_id, 'approve', array( 'state' => $service->state_token( $info ) ) ) ), 'Direct staff approval bypassed current consent.' );
	wp_set_current_user( $users['captain'] );
	lfr_assert( is_wp_error( $service->apply_team_manager_decision( $request_id, 'approve', $team_id, $users['captain'], $service->state_token( $info ), static function( $id ) use ( $team_id ) { return $id === $team_id; } ) ), 'Captain approval bypassed current consent.' );
	lfr_assert( ! \LeagueFlow\player_has_team( $player_id, $team_id ), 'Rejected request approval changed membership.' );
	lfr_assert( 'consent-saved' === lfr_dispatch( $users['player'], 'accept_registration_documents', lfr_accept_fields() ), 'Player could not renew after policy change.' );
	wp_set_current_user( $admin_id );
	$info = $service->inspect( $request_id );
	lfr_assert( true === $service->apply_action( $request_id, 'approve', array( 'state' => $service->state_token( $info ) ) ), 'Eligible approval failed after acknowledgement.' );
	lfr_assert( \LeagueFlow\player_has_team( $player_id, $team_id ), 'Approved request did not add membership.' );

	// Existing memberships from old versions/imports can be cleared from pending without a new roster write.
	$stale_id = lfr_request( $player_id, 'soccer', $level_id );
	$changed_id = lfr_request( $player_id, 'soccer', $level_id );
	$mismatch_id = lfr_request( $player_id, 'soccer', $other_level );
	$state = $service->state_token( $service->inspect( $stale_id ) );
	$changed_state = $service->state_token( $service->inspect( $changed_id ) );
	lfr_assert( ! in_array( 'resolve_member', $service->available_actions( $service->inspect( $mismatch_id ) ), true ), 'Wrong-level membership was offered as resolved placement.' );
	$inbox = new \LeagueFlow\Admin_Requests( $service, $sports );
	ob_start(); $inbox->render_page(); $inbox_html = ob_get_clean();
	lfr_assert( false !== strpos( $inbox_html, 'Close these already-placed requests' ) && 'pending' === get_post_meta( $stale_id, 'lf_request_status', true ), 'Inbox preview missing or GET unexpectedly changed requests.' );
	$result = lfr_reconcile( $admin_id, array( $stale_id => $state ), false );
	lfr_assert( 'invalid' === $result['leagueflow_request_notice'] && 'pending' === get_post_meta( $stale_id, 'lf_request_status', true ), 'Reconcile bypassed CSRF protection.' );
	$result = lfr_reconcile( $admin_id, array( $stale_id => $state, 'bad' => $state ) );
	lfr_assert( 'invalid' === $result['leagueflow_request_notice'] && 'pending' === get_post_meta( $stale_id, 'lf_request_status', true ), 'Malformed batch partially changed requests.' );
	update_post_meta( $changed_id, 'lf_request_status', 'declined' );
	$result = lfr_reconcile( $admin_id, array( $stale_id => $state, $changed_id => $changed_state ) );
	lfr_assert( 1 === (int) $result['lf_closed'] && 1 === (int) $result['lf_skipped'], 'Reconcile did not skip an already-reviewed request.' );
	lfr_assert( 'approved' === get_post_meta( $stale_id, 'lf_request_status', true ) && $team_id === (int) get_post_meta( $stale_id, 'lf_team_id', true ) && 1 === count( $service->get_history( $stale_id ) ), 'Reconcile did not close with matching team/history.' );
	lfr_assert( 'declined' === get_post_meta( $changed_id, 'lf_request_status', true ) && 'pending' === get_post_meta( $mismatch_id, 'lf_request_status', true ) && \LeagueFlow\player_has_team( $player_id, $team_id ), 'Reconcile changed another decision, mismatched request or roster.' );
	$result = lfr_reconcile( $admin_id, array( $stale_id => $state ) );
	lfr_assert( 0 === (int) $result['lf_closed'] && 1 === count( $service->get_history( $stale_id ) ), 'Reconcile replay was not idempotent.' );

	// Re-linked identities must never inherit the earlier person's acknowledgements.
	update_post_meta( $player_id, 'lf_user_id', $users['unlinked'] );
	lfr_assert( ! LFR_Consent::is_current( $player_id ) && 2 === count( LFR_Consent::pending( $player_id, $users['unlinked'] ) ), 'A replacement account inherited another person\'s consent.' );
	update_post_meta( $player_id, 'lf_user_id', $users['player'] );
	wp_set_current_user( $users['player'] );
	$settings['registration_documents']['waiver']['version'] = 'new-between-validation-and-save';
	update_option( 'leagueflow_settings', $settings );
	lfr_assert( is_wp_error( LFR_Consent::record( $player_id, $users['player'], array() ) ), 'A new policy requirement appearing after validation bypassed persistence-time checks.' );

	WP_CLI::success( 'Registration and placement regressions: ' . $GLOBALS['lfr_assertions'] . ' assertions.' );
} finally {
	wp_set_current_user( $admin_id );
	update_option( 'leagueflow_settings', $original_settings );
	update_option( 'leagueflow_enabled_sports', $original_sports );
	foreach ( array_unique( $GLOBALS['lfr_posts'] ) as $id ) {
		if ( 'lf_player' === get_post_type( $id ) ) {
			foreach ( get_posts( array( 'post_type' => 'lf_join_request', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'lf_player_id', 'meta_value' => $id ) ) as $request_id ) { wp_delete_post( $request_id, true ); }
		}
		if ( $id ) { wp_delete_post( $id, true ); }
	}
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	remove_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
}
