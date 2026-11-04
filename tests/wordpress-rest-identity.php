<?php
/** Native REST identity and request-workflow regressions, including custom-fields integrations. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Run only against the disposable leagueflow.test WordPress installation.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

function lfi_assert( $value, $message ) { ++$GLOBALS['lfi_assertions']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function lfi_request( $method, $route, $data = array() ) {
	$request = new WP_REST_Request( $method, '/wp/v2/' . $route );
	if ( 'GET' === $method ) { $request->set_query_params( $data ); }
	else { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $data ) ); }
	return rest_do_request( $request );
}
function lfi_post( $type, $author, $status = 'publish' ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_author' => $author, 'post_status' => $status, 'post_title' => 'REST identity fixture ' . wp_generate_uuid4() ), true );
	lfi_assert( ! is_wp_error( $id ) && $id, 'Post fixture failed.' );
	$GLOBALS['lfi_posts'][] = (int) $id;
	return (int) $id;
}
function lfi_save_team( $team_id, $fields ) {
	$_POST = wp_slash( $fields + array( 'leagueflow_team_nonce' => wp_create_nonce( 'leagueflow_save_team' ) ) );
	$_REQUEST = $_POST;
	try { do_action( 'save_post_lf_team', $team_id, get_post( $team_id ), true ); }
	finally { $_POST = $_REQUEST = array(); }
}

$GLOBALS['lfi_assertions'] = 0;
$GLOBALS['lfi_posts'] = array();
$users = array();
$supports = array();
$mail_guard = static function() { return true; };
$http_guard = static function() { return new WP_Error( 'lfi_offline', 'REST identity tests block outbound HTTP.' ); };
add_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
$admin_id = (int) get_user_by( 'login', 'admin' )->ID;
wp_set_current_user( $admin_id );

try {
	\LeagueFlow\ensure_portal_roles();
	foreach ( array( 'author' => 'author', 'first' => 'subscriber', 'second' => 'subscriber', 'privileged' => 'subscriber' ) as $label => $role ) {
		$name = 'rest_identity_' . $label . '_' . strtolower( wp_generate_password( 8, false ) );
		$id = wp_insert_user( array( 'user_login' => $name, 'user_email' => $name . '@example.test', 'user_pass' => wp_generate_password(), 'role' => $role ) );
		lfi_assert( ! is_wp_error( $id ), 'User fixture failed.' );
		$users[ $label ] = (int) $id;
	}
	get_userdata( $users['privileged'] )->add_cap( 'manage_options' );
	$player_id = lfi_post( 'lf_player', $users['author'] );
	$team_id = lfi_post( 'lf_team', $users['author'] );
	$request_id = lfi_post( 'lf_join_request', $users['author'], 'draft' );
	update_post_meta( $player_id, 'lf_user_id', $users['first'] );
	update_post_meta( $team_id, 'lf_manager_user_ids', array( $users['first'] ) );
	update_post_meta( $request_id, 'lf_request_status', 'pending' );
	update_post_meta( $request_id, 'lf_player_id', $player_id );
	$evidence = array( 'user_id' => $users['first'], 'player_id' => $player_id, 'accepted_at' => '2026-10-21 10:00:00', 'documents' => array( array( 'version' => 'original' ) ) );
	add_post_meta( $player_id, \LeagueFlow\Registration_Consent::META_KEY, $evidence );
	$player_title = get_the_title( $player_id );
	$team_title = get_the_title( $team_id );
	$request_title = get_the_title( $request_id );

	// Default CPTs do not expose generic REST meta. Exercise the registered-meta
	// contract too, because other integrations may enable custom-fields support.
	foreach ( array( 'lf_player', 'lf_team', 'lf_join_request' ) as $type ) {
		$supports[ $type ] = post_type_supports( $type, 'custom-fields' );
		$schema = ( new WP_REST_Posts_Controller( $type ) )->get_item_schema();
		lfi_assert( ! $supports[ $type ] && ! isset( $schema['properties']['meta'] ), 'Default CPT unexpectedly exposes generic REST metadata.' );
		add_post_type_support( $type, 'custom-fields' );
	}

	wp_set_current_user( $users['author'] );
	lfi_assert( current_user_can( 'edit_post', $player_id ) && ! \LeagueFlow\can_manage_player_accounts(), 'Author fixture does not exercise a permitted post editor without account powers.' );
	lfi_assert( ! current_user_can( 'edit_post_meta', $player_id, 'lf_user_id' ) && ! current_user_can( 'edit_post_meta', $team_id, 'lf_manager_user_ids' ), 'Identity metadata retains generic edit_posts authorization.' );
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'title' => 'Unauthorized identity mutation', 'meta' => array( 'lf_user_id' => $users['second'] ) ) );
	lfi_assert( 403 === $response->get_status() && $users['first'] === (int) get_post_meta( $player_id, 'lf_user_id', true ) && $player_title === get_the_title( $player_id ), 'An author changed a player identity or a rejected request partially saved its title.' );
	$response = lfi_request( 'POST', 'lf_team/' . $team_id, array( 'title' => 'Unauthorized manager mutation', 'meta' => array( 'lf_manager_user_ids' => array( $users['author'] ) ) ) );
	lfi_assert( 403 === $response->get_status() && array( $users['first'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ) && $team_title === get_the_title( $team_id ), 'An author assigned team management or a rejected request partially saved its title.' );
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'title' => 'Permitted player edit', 'meta' => array( 'lf_jersey_number' => 17 ) ) );
	lfi_assert( 200 === $response->get_status() && 'Permitted player edit' === get_the_title( $player_id ) && 17 === (int) get_post_meta( $player_id, 'lf_jersey_number', true ), 'Ordinary non-identity editing was unnecessarily blocked.' );
	$player_title = get_the_title( $player_id );
	global $wpdb;
	$before_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_player'" );
	$response = lfi_request( 'POST', 'lf_player', array( 'title' => 'Forged new identity', 'status' => 'publish', 'meta' => array( 'lf_user_id' => $users['second'] ) ) );
	lfi_assert( 403 === $response->get_status() && $before_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_player'" ), 'Rejected account linking left a newly created player behind.' );

	wp_set_current_user( $admin_id );
	$target_password = get_userdata( $users['second'] )->user_pass;
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'meta' => array( 'lf_user_id' => $users['second'] ) ) );
	lfi_assert( 200 === $response->get_status() && $users['second'] === (int) get_post_meta( $player_id, 'lf_user_id', true ) && $target_password === get_userdata( $users['second'] )->user_pass, 'An authorized account link failed or changed the existing password.' );
	$response = lfi_request( 'GET', 'lf_player/' . $player_id, array( 'context' => 'edit' ) );
	$data = $response->get_data();
	lfi_assert( 200 === $response->get_status() && $users['second'] === (int) ( $data['meta']['lf_user_id'] ?? 0 ) && ! array_key_exists( \LeagueFlow\Registration_Consent::META_KEY, $data['meta'] ), 'Authorized source reads failed or exposed acceptance evidence.' );
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'meta' => $data['meta'] ) );
	lfi_assert( 200 === $response->get_status() && $users['second'] === (int) get_post_meta( $player_id, 'lf_user_id', true ), 'Authorized metadata round-trip failed.' );
	foreach ( array( $admin_id, $users['privileged'] ) as $forbidden_id ) {
		$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'title' => 'Privileged identity mutation', 'meta' => array( 'lf_user_id' => $forbidden_id ) ) );
		lfi_assert( 403 === $response->get_status() && $users['second'] === (int) get_post_meta( $player_id, 'lf_user_id', true ) && $player_title === get_the_title( $player_id ), 'A privileged identity target bypassed participant-account policy.' );
	}
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'meta' => array( 'lf_user_id' => -$users['first'] ) ) );
	lfi_assert( 400 === $response->get_status() && $users['second'] === (int) get_post_meta( $player_id, 'lf_user_id', true ), 'A negative identity was silently converted into another account.' );
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'meta' => array( 'lf_user_id' => null ) ) );
	lfi_assert( 200 === $response->get_status() && ! get_post_meta( $player_id, 'lf_user_id', true ), 'Authorized identity unlink failed.' );
	$response = lfi_request( 'POST', 'lf_player/' . $player_id, array( 'meta' => array( \LeagueFlow\Registration_Consent::META_KEY => array( 'accepted_at' => 'forged' ) ) ) );
	lfi_assert( $response->get_status() >= 400 && array( $evidence ) === get_post_meta( $player_id, \LeagueFlow\Registration_Consent::META_KEY, false ), 'Generic REST writes changed immutable acceptance evidence.' );

	$response = lfi_request( 'POST', 'lf_team/' . $team_id, array( 'meta' => array( 'lf_manager_user_ids' => array( $users['first'], $users['second'] ) ) ) );
	lfi_assert( 200 === $response->get_status() && array( $users['first'], $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'Authorized participant manager assignment failed.' );
	$response = lfi_request( 'POST', 'lf_team/' . $team_id, array( 'meta' => array( 'lf_manager_user_ids' => array( $users['privileged'] ) ) ) );
	lfi_assert( 403 === $response->get_status() && array( $users['first'], $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'A new privileged manager bypassed target eligibility checks.' );
	update_post_meta( $team_id, 'lf_manager_user_ids', array( $admin_id, $users['second'] ) );
	$response = lfi_request( 'POST', 'lf_team/' . $team_id, array( 'meta' => array( 'lf_manager_user_ids' => array( $admin_id, $users['second'] ) ) ) );
	lfi_assert( 200 === $response->get_status() && array( $admin_id, $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'A legacy manager assignment could not round-trip unchanged.' );
	$response = lfi_request( 'POST', 'lf_team/' . $team_id, array( 'meta' => array( 'lf_manager_user_ids' => null ) ) );
	lfi_assert( 200 === $response->get_status() && array() === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'Authorized manager removal failed.' );

	// The classic team editor must enforce the same policy as REST.
	update_post_meta( $team_id, 'lf_manager_user_ids', array( $users['first'] ) );
	wp_set_current_user( $users['author'] );
	$author_roles = get_userdata( $users['author'] )->roles;
	lfi_save_team( $team_id, array( 'lf_manager_user_ids' => array( $users['author'] ) ) );
	lfi_assert( array( $users['first'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ) && $author_roles === get_userdata( $users['author'] )->roles, 'Classic team editing assigned authority or roles to an unauthorized author.' );
	wp_set_current_user( $admin_id );
	lfi_save_team( $team_id, array() );
	lfi_assert( array( $users['first'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'An absent manager field silently removed existing managers.' );
	lfi_save_team( $team_id, array( 'lf_manager_user_ids' => array( $users['first'], $users['second'] ) ) );
	lfi_assert( array( $users['first'], $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ) && in_array( 'leagueflow_team_manager', get_userdata( $users['second'] )->roles, true ), 'Authorized classic editor manager assignment failed.' );
	lfi_save_team( $team_id, array( 'lf_manager_user_ids' => array( $users['privileged'] ) ) );
	lfi_assert( array( $users['first'], $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ) && ! in_array( 'leagueflow_team_manager', get_userdata( $users['privileged'] )->roles, true ), 'Classic editor accepted a new privileged manager.' );
	$deny_old_identity = static function( $caps, $capability, $actor_id, $args ) use ( $users ) {
		if ( in_array( $capability, array( 'edit_user', 'promote_user' ), true ) && (int) ( $args[0] ?? 0 ) === $users['first'] ) { return array( 'do_not_allow' ); }
		return $caps;
	};
	add_filter( 'map_meta_cap', $deny_old_identity, 10, 4 );
	try {
		lfi_save_team( $team_id, array( 'lf_manager_user_ids' => array() ) );
		lfi_assert( array( $users['first'], $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'Classic editor removed a manager whose account the actor cannot administer.' );
		$response = lfi_request( 'POST', 'lf_team/' . $team_id, array( 'meta' => array( 'lf_manager_user_ids' => array() ) ) );
		lfi_assert( 403 === $response->get_status() && array( $users['first'], $users['second'] ) === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'REST removed a manager whose account the actor cannot administer.' );
	} finally { remove_filter( 'map_meta_cap', $deny_old_identity, 10 ); }
	lfi_save_team( $team_id, array( 'lf_manager_user_ids' => array() ) );
	lfi_assert( array() === \LeagueFlow\get_team_manager_user_ids( $team_id ), 'Authorized classic editor manager removal failed.' );

	// The service owns request decisions, membership reconciliation, state checks and audit history.
	foreach ( array( $users['author'], $admin_id ) as $actor_id ) {
		wp_set_current_user( $actor_id );
		$response = lfi_request( 'POST', 'lf_join_request/' . $request_id, array( 'title' => 'Bypassed request', 'meta' => array( 'lf_request_status' => 'approved', 'lf_player_id' => 0 ) ) );
		lfi_assert( 403 === $response->get_status() && 'pending' === get_post_meta( $request_id, 'lf_request_status', true ) && $request_title === get_the_title( $request_id ), 'Core REST bypassed the request decision service.' );
		$response = lfi_request( 'DELETE', 'lf_join_request/' . $request_id, array( 'force' => true ) );
		lfi_assert( 403 === $response->get_status() && get_post( $request_id ) instanceof WP_Post, 'Core REST deleted the request audit trail.' );
	}
	$response = lfi_request( 'GET', 'lf_join_request/' . $request_id, array( 'context' => 'edit' ) );
	lfi_assert( 200 === $response->get_status() && 'pending' === ( $response->get_data()['meta']['lf_request_status'] ?? '' ), 'Authorized read-only request source access regressed.' );
	$response = lfi_request( 'POST', 'lf_join_request', array( 'title' => 'Bypassed request creation' ) );
	lfi_assert( 403 === $response->get_status(), 'Core REST created a request outside the portal.' );
	wp_set_current_user( 0 );
	lfi_assert( 401 === lfi_request( 'GET', 'lf_player' )->get_status(), 'Anonymous access to private player directories regressed.' );
	WP_CLI::success( 'REST identity/workflow regressions: ' . $GLOBALS['lfi_assertions'] . ' assertions passed.' );
} finally {
	wp_set_current_user( $admin_id );
	foreach ( array_unique( $GLOBALS['lfi_posts'] ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	foreach ( $supports as $type => $enabled ) { if ( ! $enabled ) { remove_post_type_support( $type, 'custom-fields' ); } }
	remove_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
}
