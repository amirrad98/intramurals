<?php
/** Real WordPress security regressions; never run against an existing site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . WPINC . '/class-phpass.php';

class LF_Security_Denied extends RuntimeException { public $status; public function __construct( $status ) { $this->status = $status; } }
class LF_Security_Renderer extends \LeagueFlow\Renderer {
	public $mapped = 0;
	public $mapped_ids = array();
	protected function map_match_item( $post ) { ++$this->mapped; $this->mapped_ids[] = (int) $post->ID; return parent::map_match_item( $post ); }
	protected function map_calendar_event_item( $post ) { ++$this->mapped; return parent::map_calendar_event_item( $post ); }
}
function lf_assert( $value, $message ) { global $lf_assertions; ++$lf_assertions; if ( ! $value ) { throw new RuntimeException( $message ); } }
function lf_post( $type, $author, $status = 'draft', $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_title' => 'Security fixture ' . wp_generate_uuid4(), 'post_status' => $status, 'post_author' => $author ), $extra ), true );
	lf_assert( ! is_wp_error( $id ) && $id > 0, 'Fixture creation failed.' ); return $id;
}
function lf_submit( $fields, $action, $nonce ) { $_POST = $fields + array( $nonce => wp_create_nonce( $action ) ); $_REQUEST = $_POST; }
function lf_denied( $callback ) {
	$die = static function() { return static function( $message, $title, $args ) { throw new LF_Security_Denied( $args['response'] ?? 500 ); }; };
	$redirect = static function() { throw new RuntimeException( 'Unauthorized handler reached a redirect instead of 403.' ); };
	add_filter( 'wp_die_handler', $die ); add_filter( 'wp_redirect', $redirect );
	try { $callback(); lf_assert( false, 'Unauthorized handler returned without denial.' ); }
	catch ( LF_Security_Denied $error ) { lf_assert( 403 === $error->status, 'Expected HTTP 403.' ); }
	finally { remove_filter( 'wp_die_handler', $die ); remove_filter( 'wp_redirect', $redirect ); }
}
function lf_request( $route, $method = 'GET', $data = array() ) {
	$r = new WP_REST_Request( $method, '/leagueflow/v1' . $route );
	if ( 'GET' === $method ) { $r->set_query_params( $data ); }
	else { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $data ) ); }
	return rest_do_request( $r );
}
function lf_matches_count() { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_match'" ); }
function lf_events_count() { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_calendar_event'" ); }

$GLOBALS['lf_assertions'] = 0;
$admin_user = get_user_by( 'login', 'admin' );
$admin_id = $admin_user->ID;
wp_set_current_user( $admin_id );
\LeagueFlow\ensure_portal_roles();
$contributor = wp_insert_user( array( 'user_login' => 'security-contributor', 'user_pass' => wp_generate_password(), 'user_email' => 'contributor@example.test', 'role' => 'contributor' ) );
$other = wp_insert_user( array( 'user_login' => 'security-other', 'user_pass' => wp_generate_password(), 'user_email' => 'other@example.test', 'role' => 'contributor' ) );
$subscriber = wp_insert_user( array( 'user_login' => 'security-subscriber', 'user_pass' => wp_generate_password(), 'user_email' => 'subscriber@example.test', 'role' => 'subscriber' ) );
$publisher = wp_insert_user( array( 'user_login' => 'security-publisher', 'user_pass' => wp_generate_password(), 'user_email' => 'publisher@example.test', 'role' => 'author' ) );
$standings = new \LeagueFlow\Standings_Service(); $knockout = new \LeagueFlow\Knockout_Service(); $sports = new \LeagueFlow\Sports_Manager();
$renderer = new LF_Security_Renderer( $standings, $knockout, $sports );
$fields = new \LeagueFlow\Field_Availability_Manager(); $fixtures = new \LeagueFlow\Fixture_Generator( $fields );
$admin = new \LeagueFlow\Admin( $standings, $knockout, $renderer, new \LeagueFlow\Seeder(), $sports, new \LeagueFlow\Exporter( $sports ), $fields, $fixtures );
$team_a = lf_post( 'lf_team', $admin_id, 'publish' ); $team_b = lf_post( 'lf_team', $admin_id, 'publish' );
$tests = array();

$tests['SEC-01 accounts'] = static function() use ( $admin, $admin_id, $contributor, $subscriber ) {
	$before = get_userdata( $admin_id )->user_pass; $roles = get_userdata( $admin_id )->roles;
	wp_set_current_user( $contributor );
	$player = lf_post( 'lf_player', $contributor );
	lf_submit( array( 'lf_user_id' => $admin_id, 'lf_generate_player_login' => 1 ), 'leagueflow_save_player', 'leagueflow_player_nonce' );
	$admin->save_player_meta( $player, get_post( $player ) );
	lf_assert( ! get_post_meta( $player, 'lf_user_id', true ) && $before === get_userdata( $admin_id )->user_pass && $roles === get_userdata( $admin_id )->roles, 'Contributor modified privileged identity/password/roles.' );
	lf_assert( false === get_transient( 'leagueflow_player_credentials_' . $contributor ), 'Contributor received credentials.' );
	ob_start(); $admin->render_player_metabox( get_post( $player ) ); $html = ob_get_clean();
	lf_assert( false === strpos( $html, 'id="lf_user_id"' ) && false === strpos( $html, 'name="lf_generate_player_login"' ), 'Contributor sees account controls.' );
	wp_set_current_user( $admin_id );
	lf_assert( \LeagueFlow\can_link_player_account( $subscriber ), 'Ordinary subscriber with legacy level_0 should be eligible.' );
	get_userdata( $subscriber )->add_cap( 'manage_options' );
	lf_assert( ! \LeagueFlow\can_link_player_account( $subscriber ), 'Direct privileged capability bypasses eligibility.' );
	get_userdata( $subscriber )->remove_cap( 'manage_options' );
	get_role( 'leagueflow_player' )->add_cap( 'edit_posts' );
	$temp = wp_insert_user( array( 'user_login' => 'custom-portal', 'user_pass' => wp_generate_password(), 'role' => 'leagueflow_player' ) );
	lf_assert( ! \LeagueFlow\can_link_player_account( $temp ), 'Customized privileged portal role accepted.' );
	get_role( 'leagueflow_player' )->remove_cap( 'edit_posts' );
	lf_assert( ! \LeagueFlow\can_link_player_account( $admin_id ), 'Administrator/self link accepted.' );
	lf_submit( array( 'lf_user_id' => $subscriber, 'lf_generate_player_login' => 1 ), 'leagueflow_save_player', 'leagueflow_player_nonce' );
	$subscriber_hash = get_userdata( $subscriber )->user_pass;
	$admin->save_player_meta( $player, get_post( $player ) );
	lf_assert( (int) get_post_meta( $player, 'lf_user_id', true ) === $subscriber && $subscriber_hash === get_userdata( $subscriber )->user_pass, 'Eligible link failed or existing password changed.' );
	$privileged = static function( $caps, $required, $context, $user ) use ( $subscriber ) { if ( $user->ID === $subscriber ) { $caps['manage_options'] = true; } return $caps; };
	$linked_roles = get_userdata( $subscriber )->roles;
	add_filter( 'user_has_cap', $privileged, 10, 4 );
	try {
		lf_assert( user_can( $subscriber, 'manage_options' ) && ! \LeagueFlow\can_link_player_account( $subscriber ), 'Effective filtered privilege bypassed eligibility.' );
		$admin->save_player_meta( $player, get_post( $player ) );
		lf_assert( (int) get_post_meta( $player, 'lf_user_id', true ) === $subscriber && $subscriber_hash === get_userdata( $subscriber )->user_pass && $linked_roles === get_userdata( $subscriber )->roles, 'Filtered privileged link altered identity state.' );
		$unlinked = lf_post( 'lf_player', $admin_id ); $admin->save_player_meta( $unlinked, get_post( $unlinked ) );
		lf_assert( ! get_post_meta( $unlinked, 'lf_user_id', true ), 'Filtered privileged target was newly linked.' );
	} finally { remove_filter( 'user_has_cap', $privileged, 10 ); }
	lf_submit( array( 'lf_user_id' => $admin_id, 'lf_generate_player_login' => 1 ), 'leagueflow_save_player', 'leagueflow_player_nonce' );
	$admin->save_player_meta( $player, get_post( $player ) );
	lf_assert( (int) get_post_meta( $player, 'lf_user_id', true ) === $subscriber && $before === get_userdata( $admin_id )->user_pass, 'Invalid link altered a previous link or password.' );
	$new_player = lf_post( 'lf_player', $admin_id ); $mail = array();
	$capture = static function( $pre, $atts ) use ( &$mail ) { $mail[] = $atts; return true; };
	add_filter( 'pre_wp_mail', $capture, 10, 2 );
	try {
		lf_submit( array( 'lf_user_id' => 0, 'lf_email' => 'new-player@example.test', 'lf_generate_player_login' => 1 ), 'leagueflow_save_player', 'leagueflow_player_nonce' );
		$admin->save_player_meta( $new_player, get_post( $new_player ) );
	} finally { remove_filter( 'pre_wp_mail', $capture, 10 ); }
	$id = (int) get_post_meta( $new_player, 'lf_user_id', true );
	lf_assert( $id && get_userdata( $id )->roles === array( 'leagueflow_player' ) && 1 === count( $mail ) && $mail[0]['to'] === 'new-player@example.test', 'New portal account/setup email failed.' );
	preg_match( '~https?://[^\s]+action=rp[^\s]*~', $mail[0]['message'], $match );
	parse_str( parse_url( $match[0] ?? '', PHP_URL_QUERY ) ?: '', $params );
	lf_assert( ! is_wp_error( check_password_reset_key( $params['key'] ?? '', get_userdata( $id )->user_login ) ), 'Setup link is invalid.' );
	add_filter( 'pre_wp_mail', $capture, 10, 2 );
	try { reset_password( get_userdata( $id ), 'Disposable-player-selected-password' ); }
	finally { remove_filter( 'pre_wp_mail', $capture, 10 ); }
	lf_assert( is_wp_error( check_password_reset_key( $params['key'], get_userdata( $id )->user_login ) ), 'Setup link is reusable.' );
	set_transient( 'leagueflow_player_credentials_' . $admin_id, array( 'username' => 'old', 'password' => 'legacy-secret' ), 60 );
	ob_start(); $admin->render_admin_notices(); $notice = ob_get_clean();
	lf_assert( false === strpos( $notice, 'legacy-secret' ) && false === get_transient( 'leagueflow_player_credentials_' . $admin_id ), 'Legacy password displayed or retained.' );
	lf_assert( false === strpos( $notice, 'Temporary password' ) && $before === get_userdata( $admin_id )->user_pass, 'Credential notice or administrator password changed.' );
};

$tests['SEC-02 teams'] = static function() use ( $renderer, $admin_id ) {
	wp_set_current_user( $admin_id );
	$protected = lf_post( 'lf_team', $admin_id, 'publish', array( 'post_password' => 'TeamSecret', 'post_content' => 'PROTECTED-TEAM-CONTENT' ) );
	$private = lf_post( 'lf_team', $admin_id, 'private', array( 'post_content' => 'PRIVATE-TEAM-CONTENT' ) );
	$draft = lf_post( 'lf_team', $admin_id, 'draft', array( 'post_content' => 'DRAFT-TEAM-CONTENT' ) );
	wp_set_current_user( 0 );
	$html = $renderer->render_team_single( $protected );
	lf_assert( false !== strpos( $html, 'post-password-form' ) && false === strpos( $html, 'PROTECTED-TEAM-CONTENT' ), 'Protected team leaked content.' );
	lf_assert( '' === $renderer->render_team_page( array( 'team' => $private ) ) && '' === $renderer->render_team_page( array( 'team' => $draft ) ), 'Explicit non-public team leaked.' );
	lf_assert( ! in_array( $protected, array_column( $renderer->get_team_items(), 'id' ), true ), 'Protected team appeared in list.' );
	lf_assert( array() === $renderer->get_roster_items( $private ) && '' === $renderer->render_team_single( lf_post( 'post', $admin_id ) ), 'Roster/non-team bypass.' );
	$hasher = new PasswordHash( 8, true ); $_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword( 'TeamSecret' );
	lf_assert( false !== strpos( $renderer->render_team_single( $protected ), 'PROTECTED-TEAM-CONTENT' ), 'Unlocked team unavailable.' );
	unset( $_COOKIE['wp-postpass_' . COOKIEHASH] ); wp_set_current_user( $admin_id );
	lf_assert( false !== strpos( $renderer->render_team_single( $private ), 'PRIVATE-TEAM-CONTENT' ) && false !== strpos( $renderer->render_team_single( $draft ), 'DRAFT-TEAM-CONTENT' ), 'Authorized staff preview failed.' );
	lf_assert( false === strpos( $renderer->render_team_single( $protected ), 'PROTECTED-TEAM-CONTENT' ), 'Staff bypassed password form.' );
};

$rule = array( 'id' => 'security-window', 'name' => 'Security test field', 'venue' => 'Security Test Venue', 'date' => '2031-05-12', 'start_time' => '09:00', 'end_time' => '17:00', 'slot_minutes' => 60, 'buffer_minutes' => 0, 'active' => true );
$tests['SEC-03 field configuration'] = static function() use ( $fields, $admin, $rule, $admin_id, $contributor ) {
	wp_set_current_user( $admin_id ); $saved = $fields->save_availability( $rule ); lf_assert( ! is_wp_error( $saved ), 'Administrator field creation failed.' );
	$before = get_option( \LeagueFlow\Field_Availability_Manager::OPTION );
	wp_set_current_user( $contributor );
	lf_assert( is_wp_error( $fields->save_availability( array_merge( $rule, array( 'venue' => 'FORGED' ) ) ) ) && ! $fields->delete_availability( $rule['id'] ), 'Contributor changed configuration through manager.' );
	lf_submit( array(), 'leagueflow_save_field_availability', 'leagueflow_save_field_availability_nonce' ); lf_denied( array( $admin, 'handle_save_field_availability' ) );
	lf_denied( array( $admin, 'handle_delete_field_availability' ) ); lf_denied( array( $admin, 'render_field_availability_page' ) );
	global $submenu; $submenu = array(); $admin->register_menus();
	$slugs = array(); foreach ( $submenu as $items ) { foreach ( $items as $item ) { $slugs[] = $item[2]; } }
	lf_assert( ! in_array( 'leagueflow-fields', $slugs, true ) && ! in_array( 'leagueflow-fixtures', $slugs, true ), 'Contributor received global mutation menu.' );
	lf_assert( $before === get_option( \LeagueFlow\Field_Availability_Manager::OPTION ), 'Global option changed after denial.' );
};

$tests['SEC-04 scheduling'] = static function() use ( $fields, $admin, $admin_id, $contributor, $other, $team_a, $team_b ) {
	wp_set_current_user( $admin_id ); $own = lf_post( 'lf_match', $contributor ); $foreign = lf_post( 'lf_match', $other );
	$bulk_rule = array( 'id' => 'security-bulk-window', 'name' => 'Bulk field', 'venue' => 'Security Bulk Venue', 'date' => current_time( 'Y-m-d' ), 'start_time' => '09:00', 'end_time' => '17:00', 'active' => true );
	lf_assert( ! is_wp_error( $fields->save_availability( $bulk_rule ) ), 'Bulk test field creation failed.' );
	foreach ( array( $own, $foreign ) as $id ) { update_post_meta( $id, 'lf_home_team_id', $team_a ); update_post_meta( $id, 'lf_away_team_id', $team_b ); }
	$args = array( 'date' => '2031-05-12', 'match_ids' => array( $own ), 'mode' => 'both' );
	wp_set_current_user( $contributor ); lf_submit( array(), 'leagueflow_auto_schedule_matches', 'leagueflow_auto_schedule_matches_nonce' ); lf_denied( array( $admin, 'handle_auto_schedule_matches' ) );
	lf_assert( 0 === $fields->schedule_matches( $args )['scheduled'], 'Contributor scheduled without capability.' );
	get_userdata( $contributor )->add_cap( 'leagueflow_manage_schedule' ); wp_set_current_user( 0 ); wp_set_current_user( $contributor );
	lf_assert( 0 === $fields->schedule_matches( array_merge( $args, array( 'match_ids' => array( $own, $foreign ) ) ) )['scheduled'], 'Mixed unauthorized scope partially scheduled.' );
	lf_assert( ! get_post_meta( $own, 'lf_match_datetime', true ) && ! get_post_meta( $foreign, 'lf_match_datetime', true ), 'Unauthorized targets changed.' );
	$page = lf_post( 'page', $admin_id );
	foreach ( array( $foreign, $page, 0 ) as $invalid ) {
		$snapshots = array(); foreach ( array( $own, $invalid ) as $id ) { $snapshots[$id] = array( get_the_title( $id ), get_post_meta( $id ) ); }
		lf_submit( array( 'post' => array( $own, $invalid ) ), 'bulk-posts', '_wpnonce' );
		$admin->handle_match_bulk_actions( admin_url( 'edit.php?post_type=lf_match' ), 'leagueflow_auto_schedule', array( $own, $invalid ) );
		foreach ( $snapshots as $id => $before ) { lf_assert( $before === array( get_the_title( $id ), get_post_meta( $id ) ), 'Mixed bulk selection partially changed a target.' ); }
	}
	lf_assert( 0 === $fields->schedule_matches( array_merge( $args, array( 'overwrite' => true ) ) )['scheduled'], 'Overwrite did not require extra permission.' );
	$calls = 0; $revoke = static function( $caps, $required, $context ) use ( $own, &$calls ) {
		if ( 'edit_post' === $context[0] && (int) $context[2] === $own && ++$calls >= 4 ) { foreach ( $required as $cap ) { $caps[$cap] = false; } }
		return $caps;
	};
	add_filter( 'user_has_cap', $revoke, 10, 3 );
	try { lf_assert( 0 === $fields->schedule_matches( $args )['scheduled'] && 4 === $calls && ! get_post_meta( $own, 'lf_match_datetime', true ), 'Late revoked object permission still wrote scheduling fields.' ); }
	finally { remove_filter( 'user_has_cap', $revoke, 10 ); }
	wp_set_current_user( $admin_id );
	lf_assert( 1 === $fields->schedule_matches( $args )['scheduled'] && 'Security Test Venue' === get_post_meta( $own, 'lf_venue', true ) && 'auto' === get_post_meta( $own, \LeagueFlow\Field_Availability_Manager::META_SCHEDULE_SOURCE, true ), 'Authorized scheduler failed.' );
	$late = lf_post( 'lf_match', $contributor ); $bulk = lf_post( 'lf_match', $contributor );
	foreach ( array( $late, $bulk ) as $id ) { update_post_meta( $id, 'lf_home_team_id', $team_a ); update_post_meta( $id, 'lf_away_team_id', $team_b ); }
	$title = get_the_title( $late ); $calls = 0;
	$revoke_title = static function( $caps, $required, $context ) use ( $late, &$calls ) {
		if ( 'edit_post' === $context[0] && (int) $context[2] === $late && ++$calls >= 5 ) { foreach ( $required as $cap ) { $caps[$cap] = false; } } return $caps;
	};
	wp_set_current_user( $contributor ); add_filter( 'user_has_cap', $revoke_title, 10, 3 );
	try {
		lf_assert( 1 === $fields->schedule_matches( array_merge( $args, array( 'match_ids' => array( $late ) ) ) )['scheduled'] && 5 === $calls && get_post_meta( $late, 'lf_match_datetime', true ) && $title === get_the_title( $late ), 'Title revocation did not reach/preserve the late title boundary.' );
	} finally { remove_filter( 'user_has_cap', $revoke_title, 10 ); }
	$admin->handle_match_bulk_actions( admin_url( 'edit.php?post_type=lf_match' ), 'leagueflow_auto_schedule', array( $bulk ) );
	lf_assert( get_post_meta( $bulk, 'lf_match_datetime', true ) && 'Security Bulk Venue' === get_post_meta( $bulk, 'lf_venue', true ), 'Authorized bulk scheduling failed.' );
	get_userdata( $contributor )->remove_cap( 'leagueflow_manage_schedule' );
};

$tests['SEC-05 fixtures'] = static function() use ( $fixtures, $admin, $admin_id, $contributor, $team_a, $team_b ) {
	wp_set_current_user( $contributor ); lf_submit( array(), 'leagueflow_generate_fixtures', 'leagueflow_generate_fixtures_nonce' ); lf_denied( array( $admin, 'handle_generate_fixtures' ) ); lf_denied( array( $admin, 'render_fixtures_page' ) );
	$rounds = $fixtures->build_rounds( array( $team_a, $team_b ) ); $before = lf_matches_count();
	lf_assert( is_wp_error( $fixtures->persist( $rounds, array( 'post_status' => 'publish' ) ) ) && $before === lf_matches_count(), 'Contributor generated matches without authority.' );
	get_userdata( $contributor )->add_cap( 'leagueflow_manage_fixtures' ); wp_set_current_user( 0 ); wp_set_current_user( $contributor );
	$r = $fixtures->persist( $rounds, array( 'post_status' => 'publish' ) );
	lf_assert( ! is_wp_error( $r ) && 1 === $r['created'] && 'draft' === get_post_status( $r['created_ids'][0] ), 'Draft-only delegated generation failed.' );
	$private = lf_post( 'lf_team', $admin_id, 'private' ); $before = lf_matches_count();
	lf_assert( is_wp_error( $fixtures->persist( $fixtures->build_rounds( array( $team_a, $team_b, $private ) ), array() ) ) && $before === lf_matches_count(), 'Unreadable team allowed partial fixture creation.' );
	get_userdata( $contributor )->remove_cap( 'leagueflow_manage_fixtures' ); wp_set_current_user( $admin_id );
	$r = $fixtures->persist( $rounds, array( 'post_status' => 'publish' ) ); lf_assert( 'publish' === get_post_status( $r['created_ids'][0] ), 'Administrator fixture publication failed.' );
};

$tests['SEC-06 event REST'] = static function() use ( $admin_id, $contributor, $publisher ) {
	$data = array( 'title' => 'Security event', 'start_datetime' => '2031-06-12 09:00' );
	foreach ( array( '/calendar/events', '/events/create-calendar-event' ) as $route ) {
		wp_set_current_user( $contributor ); $before = lf_events_count();
		foreach ( array( 'publish', 'future', 'private' ) as $status ) { $r = lf_request( $route, 'POST', $data + array( 'post_status' => $status ) ); lf_assert( 403 === $r->get_status() && $before === lf_events_count(), 'Contributor bypassed event publication status.' ); }
		$r = lf_request( $route, 'POST', $data ); $id = $r->get_data()['event']['postId'] ?? 0;
		lf_assert( 201 === $r->get_status() && $id && 'draft' === get_post_status( $id ), 'Contributor default must remain a draft.' );
		$before = lf_events_count(); $terms = wp_count_terms( array( 'taxonomy' => 'lf_sport', 'hide_empty' => false ) );
		$r = lf_request( $route, 'POST', $data + array( 'sport' => 'FORBIDDEN-NEW-SPORT' ) );
		lf_assert( 403 === $r->get_status() && $before === lf_events_count() && $terms === wp_count_terms( array( 'taxonomy' => 'lf_sport', 'hide_empty' => false ) ), 'Unauthorized taxonomy request produced partial writes.' );
		wp_set_current_user( $admin_id );
		$r = lf_request( $route, 'POST', $data + array( 'sport' => 'SecuritySport', 'post_status' => 'publish' ) );
		lf_assert( 201 === $r->get_status() && 'publish' === get_post_status( $r->get_data()['event']['postId'] ) && get_term_by( 'name', 'SecuritySport', 'lf_sport' ), 'Authorized event/term creation failed.' );
		wp_set_current_user( $publisher );
		$r = lf_request( $route, 'POST', $data + array( 'sport' => 'SecuritySport', 'post_status' => 'publish' ) ); lf_assert( 201 === $r->get_status(), 'Publisher could not assign an existing term.' );
		$r = lf_request( $route, 'POST', $data + array( 'sport' => 'UNKNOWN-PUBLISHER-TERM' ) ); lf_assert( 403 === $r->get_status(), 'Publisher created a term without manage_terms.' );
		$r = lf_request( $route, 'POST', $data + array( 'post_status' => 'invalid' ) ); lf_assert( 400 === $r->get_status(), 'Invalid publication status accepted.' );
	}
};

$tests['SEC-07 knockout'] = static function() use ( $knockout, $admin, $admin_id, $contributor, $other, $team_a, $team_b ) {
	wp_set_current_user( $admin_id ); $source = lf_post( 'lf_match', $contributor ); $target = lf_post( 'lf_match', $contributor ); $foreign = lf_post( 'lf_match', $other ); $page = lf_post( 'page', $admin_id, 'publish' );
	update_post_meta( $source, 'lf_home_team_id', $team_a ); update_post_meta( $source, 'lf_away_team_id', $team_b ); update_post_meta( $source, 'lf_winner_team_id', $team_a );
	wp_set_current_user( $contributor ); $title = get_the_title( $page );
	foreach ( array( $page, $foreign ) as $invalid ) {
		update_post_meta( $source, 'lf_next_match_id', $invalid );
		lf_assert( 0 === $knockout->advance_winner( $source ) && ! get_post_meta( $invalid, 'lf_home_team_id', true ), 'Knockout changed an unauthorized target.' );
	}
	lf_assert( $title === get_the_title( $page ), 'Knockout changed a page title.' );
	lf_submit( array( 'lf_next_match_id' => $page, 'lf_is_knockout' => 1 ), 'leagueflow_save_match', 'leagueflow_match_nonce' );
	delete_post_meta( $source, 'lf_next_match_id' ); $admin->save_match_meta( $source, get_post( $source ) );
	lf_assert( ! get_post_meta( $source, 'lf_next_match_id', true ), 'Admin save stored a forged target reference.' );
	update_post_meta( $source, 'lf_next_match_id', $target ); update_post_meta( $target, 'lf_next_match_id', $source );
	lf_assert( ! $knockout->can_advance_to( $source, $target ), 'Cycle accepted.' ); delete_post_meta( $target, 'lf_next_match_id' );
	update_post_meta( $source, 'lf_round_order', 1 ); lf_assert( ! $knockout->can_advance_to( $source, $target ), 'Missing forward round accepted.' ); update_post_meta( $target, 'lf_round_order', 2 );
	wp_set_current_user( $admin_id ); $sport = get_term_by( 'slug', 'soccer', 'lf_sport' ); wp_set_object_terms( $source, array( (int) $sport->term_id ), 'lf_sport' );
	lf_assert( ! $knockout->can_advance_to( $source, $target ), 'Missing target context accepted.' ); wp_set_object_terms( $target, array( (int) $sport->term_id ), 'lf_sport' );
	wp_set_current_user( $contributor );
	$calls = 0; $revoke = static function( $caps, $required, $context ) use ( $target, &$calls ) { if ( 'edit_post' === $context[0] && (int) $context[2] === $target && ++$calls >= 2 ) { foreach ( $required as $cap ) { $caps[$cap] = false; } } return $caps; };
	add_filter( 'user_has_cap', $revoke, 10, 3 );
	try { lf_assert( 0 === $knockout->advance_winner( $source ) && ! get_post_meta( $target, 'lf_home_team_id', true ), 'Revoked knockout authority still wrote.' ); }
	finally { remove_filter( 'user_has_cap', $revoke, 10 ); }
	lf_assert( $team_a === $knockout->advance_winner( $source ) && $team_a === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Valid knockout advance failed.' );
	wp_set_current_user( $admin_id ); $_POST = array(); $_REQUEST = array();
	foreach ( array( $source, $target ) as $id ) { update_post_meta( $id, 'lf_is_knockout', 1 ); wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); }
	wp_set_current_user( 0 );
	$tree = $knockout->get_bracket_tree( 0, 0, (int) $sport->term_id );
	lf_assert( is_array( $tree ) && $tree['linked'] && $tree['roots'][0]['id'] === $target && $tree['roots'][0]['children'][0]['id'] === $source, 'Anonymous linked bracket tree failed.' );
	wp_set_current_user( $admin_id ); $chain = array(); $empty_source = lf_post( 'lf_match', $admin_id );
	for ( $i = 0; $i < 101; ++$i ) { $chain[] = lf_post( 'lf_match', $admin_id ); }
	for ( $i = 0; $i < 100; ++$i ) { update_post_meta( $chain[$i], 'lf_next_match_id', $chain[$i+1] ); }
	lf_assert( ! $knockout->can_advance_to( $empty_source, $chain[0] ), '101-node graph exceeded the hop guard.' );
	delete_post_meta( $chain[99], 'lf_next_match_id' );
	lf_assert( $knockout->can_advance_to( $empty_source, $chain[0] ), 'Valid 100-hop graph with unspecified rounds rejected.' );
};

$tests['SEC-08 bounded collections'] = static function() use ( $renderer, $admin_id, $contributor, $team_a, $team_b ) {
	wp_set_current_user( $admin_id ); $ids = array();
	$seed = static function( $from, $to ) use ( &$ids, $admin_id, $team_a, $team_b ) {
		for ( $i = $from; $i < $to; ++$i ) {
			$type = $i % 2 ? 'lf_calendar_event' : 'lf_match'; $id = lf_post( $type, $admin_id, 'publish', array( 'post_title' => 'BoundedCalendar ' . $i ) );
			$date = ( new DateTimeImmutable( '2032-01-01 09:00' ) )->modify( '+' . $i . ' days' )->format( 'Y-m-d H:i' );
			update_post_meta( $id, 'lf_match' === $type ? 'lf_match_datetime' : 'lf_event_start_datetime', $date );
			update_post_meta( $id, 'lf_match' === $type ? 'lf_status' : 'lf_event_status', 'scheduled' );
			if ( 'lf_match' === $type ) { update_post_meta( $id, 'lf_home_team_id', $team_a ); update_post_meta( $id, 'lf_away_team_id', $team_b ); }
			else { update_post_meta( $id, 'lf_event_type', 'drop_in' ); }
			$ids[] = $id;
		}
	};
	$seed( 0, 40 ); wp_set_current_user( 0 );
	foreach ( array( '/events', '/calendar/events' ) as $route ) {
		$r = lf_request( $route, 'GET', array( 'start_date' => '2032-01-01', 'end_date' => '2032-01-06', 'per_page' => 2 ) )->get_data();
		lf_assert( 6 === $r['total'] && 3 === $r['pages'] && array_column( $r['events'], 'postId' ) === array_slice( $ids, 0, 2 ), 'Mixed calendar page/order/totals incorrect.' );
		$r = lf_request( $route, 'GET', array( 'start_date' => '2032-01-01', 'end_date' => '2032-01-06', 'per_page' => 2, 'page' => 2 ) )->get_data(); lf_assert( array_column( $r['events'], 'postId' ) === array_slice( $ids, 2, 2 ), 'Mixed page repeats or skips entries.' );
		$r = lf_request( $route, 'GET', array( 'start_date' => '2032-01-01', 'end_date' => '2032-01-06', 'type' => 'drop_in' ) )->get_data(); lf_assert( 3 === $r['total'] && array_column( $r['events'], 'postId' ) === array( $ids[1], $ids[3], $ids[5] ), 'Type filter did not precede pagination.' );
		$r = lf_request( $route, 'GET', array( 'match_status' => 'nonexistent', 'event_status' => 'nonexistent' ) )->get_data(); lf_assert( 0 === $r['total'], 'Status filters ignored.' );
	}
	foreach ( array( null, 0, -1, 1000000 ) as $limit ) {
		$args = null === $limit ? array() : array( 'limit' => $limit ); $r = lf_request( '/matches', 'GET', $args ); lf_assert( 200 === $r->get_status() && count( $r->get_data() ) <= ( $limit > 100 ? 100 : 20 ), 'Match REST limit is unbounded.' );
		$args = null === $limit ? array() : array( 'per_page' => $limit ); $r = lf_request( '/events', 'GET', $args )->get_data(); lf_assert( count( $r['events'] ) <= ( $limit > 100 ? 100 : 20 ), 'Calendar REST limit is unbounded.' );
	}
	$measure = static function() use ( $renderer ) {
		global $wpdb; wp_cache_flush(); $renderer->mapped = 0; $rows = 0; $sql = '';
		$capture = static function( $posts, $query ) use ( &$rows, &$sql ) { if ( $query->get( 'leagueflow_calendar' ) ) { $rows += count( $posts ); $sql = $query->request; } return $posts; };
		add_filter( 'posts_results', $capture, 10, 2 ); $memory = memory_get_usage(); $queries = $wpdb->num_queries;
		try { $result = $renderer->get_calendar_page( array( 'limit' => 1, 'start_date' => '2032-01-01', 'end_date' => '2034-12-31' ) ); }
		finally { remove_filter( 'posts_results', $capture, 10 ); }
		lf_assert( 1 === $rows && 1 === $renderer->mapped && 1 === count( $result['items'] ), 'per_page=1 loads or maps more than one row.' );
		lf_assert( false !== strpos( $sql, 'LIMIT 0, 1' ) && false !== strpos( $sql, '2032-01-01 00:00' ) && false !== strpos( $sql, '2034-12-31 23:59:59' ), 'SQL pagination/date constraints missing.' );
		return array( 'total' => $result['total'], 'rows' => $rows, 'mapped' => $renderer->mapped, 'queries' => $wpdb->num_queries - $queries, 'memory' => memory_get_usage() - $memory );
	};
	$small = $measure(); wp_set_current_user( $admin_id ); $seed( 40, 400 ); wp_set_current_user( 0 ); $large = $measure();
	lf_assert( 40 === $small['total'] && 400 === $large['total'], 'Growth fixtures do not represent the claimed eligible dataset sizes.' );
	lf_assert( $large['queries'] <= $small['queries'] + 5 && $large['queries'] <= 30 && $large['memory'] <= $small['memory'] + 1048576 && $large['memory'] <= 4194304, 'Query count/application memory scales with full collection.' );
	WP_CLI::log( wp_json_encode( array( 'growth' => array( '40_records' => $small, '400_records' => $large ) ) ) );
	wp_set_current_user( $admin_id );
	$private = lf_post( 'lf_team', $admin_id, 'private' );
	$protected = lf_post( 'lf_team', $admin_id, 'publish', array( 'post_password' => 'CalendarSecret' ) );
	$wrong_type = lf_post( 'page', $admin_id, 'publish' ); $hidden = array();
	for ( $i = 0; $i < 21; ++$i ) {
		$id = lf_post( 'lf_match', $admin_id, 'publish' ); $hidden[] = $id;
		update_post_meta( $id, 'lf_match_datetime', '2031-12-31 09:00' );
		update_post_meta( $id, 'lf_home_team_id', array( $private, $protected, $wrong_type )[ $i % 3 ] ); update_post_meta( $id, 'lf_away_team_id', $team_b );
	}
	$empty = lf_post( 'lf_match', $admin_id, 'publish' ); update_post_meta( $empty, 'lf_match_datetime', '2031-12-31 09:00' );
	wp_set_current_user( 0 );
	foreach ( array( '/events', '/calendar/events' ) as $route ) {
		$args = array( 'start_date' => '2031-12-31', 'end_date' => '2032-01-02', 'per_page' => 1 );
		$r = lf_request( $route, 'GET', $args )->get_data();
		lf_assert( 2 === $r['total'] && 2 === $r['pages'] && array_column( $r['events'], 'postId' ) === array( $ids[0] ), 'Hidden earlier team references consumed a calendar page or count.' );
		$r = lf_request( $route, 'GET', $args + array( 'page' => 2 ) )->get_data(); lf_assert( array_column( $r['events'], 'postId' ) === array( $ids[1] ), 'Visible event page skipped after hidden fixtures.' );
		$r = lf_request( $route, 'GET', array( 'team' => $protected ) )->get_data(); lf_assert( 0 === $r['total'], 'Locked explicit team feed exposed fixtures.' );
		$hasher = new PasswordHash( 8, true ); $_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword( 'CalendarSecret' );
		$r = lf_request( $route, 'GET', $args )->get_data(); lf_assert( 2 === $r['total'], 'General feed admitted protected references through a cookie.' );
		$r = lf_request( $route, 'GET', array( 'team' => $protected ) )->get_data(); lf_assert( 7 === $r['total'] && 7 === count( $r['events'] ), 'Unlocked explicit team feed unavailable.' );
		lf_assert( 7 === count( $renderer->get_match_items( array( 'team' => $protected ) ) ), 'Unlocked profile recent matches unavailable.' );
		unset( $_COOKIE['wp-postpass_' . COOKIEHASH] ); wp_set_current_user( $admin_id );
		$r = lf_request( $route, 'GET', array( 'team' => $private ) )->get_data(); lf_assert( 7 === $r['total'] && 7 === count( $r['events'] ), 'Authorized private-team feed unavailable.' ); wp_set_current_user( 0 );
	}
	wp_set_current_user( $admin_id ); $future_date = array( 'post_date' => '2036-01-01 09:00:00', 'post_date_gmt' => '2036-01-01 09:00:00' );
	foreach ( array( 'draft', 'pending', 'future' ) as $status ) {
		$unreadable_team = lf_post( 'lf_team', $admin_id, $status, $future_date );
		foreach ( array( array( 'lf_match', 'publish', $unreadable_team ), array( 'lf_match', $status, $team_a ), array( 'lf_calendar_event', $status, 0 ) ) as $fixture ) {
			$id = lf_post( $fixture[0], $admin_id, $fixture[1], $future_date );
			update_post_meta( $id, 'lf_match' === $fixture[0] ? 'lf_match_datetime' : 'lf_event_start_datetime', '2036-01-01 09:00' );
			if ( 'lf_match' === $fixture[0] ) { update_post_meta( $id, 'lf_home_team_id', $fixture[2] ); update_post_meta( $id, 'lf_away_team_id', $team_b ); }
		}
	}
	$own_match = lf_post( 'lf_match', $contributor ); update_post_meta( $own_match, 'lf_match_datetime', '2036-01-02 09:00' ); update_post_meta( $own_match, 'lf_home_team_id', $team_a );
	$own_team = lf_post( 'lf_team', $contributor, 'future', $future_date );
	$own_team_match = lf_post( 'lf_match', $contributor ); update_post_meta( $own_team_match, 'lf_match_datetime', '2036-01-03 09:00' ); update_post_meta( $own_team_match, 'lf_home_team_id', $own_team );
	$own_event = lf_post( 'lf_calendar_event', $contributor ); update_post_meta( $own_event, 'lf_event_start_datetime', '2036-01-04 09:00' );
	wp_set_current_user( $contributor );
	foreach ( array( '/events', '/calendar/events' ) as $route ) {
		$args = array( 'start_date' => '2036-01-01', 'end_date' => '2036-01-04', 'per_page' => 1 );
		$r = lf_request( $route, 'GET', $args )->get_data(); lf_assert( 3 === $r['total'] && 3 === $r['pages'] && array_column( $r['events'], 'postId' ) === array( $own_match ), 'Restricted staff unpublished references/source records consumed page slots/counts.' );
		$r = lf_request( $route, 'GET', $args + array( 'page' => 2 ) )->get_data(); lf_assert( array_column( $r['events'], 'postId' ) === array( $own_team_match ), 'Own future-team reference should remain readable.' );
		$r = lf_request( $route, 'GET', $args + array( 'page' => 3 ) )->get_data(); lf_assert( array_column( $r['events'], 'postId' ) === array( $own_event ), 'Own event should remain readable.' );
		wp_set_current_user( $admin_id ); $r = lf_request( $route, 'GET', $args )->get_data(); lf_assert( 12 === $r['total'] && 1 === count( $r['events'] ), 'Authorized staff unpublished preview failed.' ); wp_set_current_user( $contributor );
	}
	$args = array( 'start_date' => '2036-01-01', 'end_date' => '2036-01-04', 'limit' => 1 );
	lf_assert( array_column( $renderer->get_match_items( $args ), 'id' ) === array( $own_match ) && array_column( $renderer->get_calendar_event_items( $args ), 'id' ) === array( $own_event ), 'Standalone collections filter visibility after pagination.' );
	wp_set_current_user( 0 );
	$renderer->mapped = 0; $renderer->mapped_ids = array(); set_query_var( 'paged', 1 ); $first = $renderer->render_match_archive();
	lf_assert( 20 === $renderer->mapped && $renderer->mapped_ids[0] === $ids[0] && ! array_intersect( $hidden, $renderer->mapped_ids ) && false !== strpos( $first, '<nav' ), 'Classic archive is unbounded, filtered after paging, or lacks navigation.' );
	$renderer->mapped = 0; set_query_var( 'paged', 2 ); $second = $renderer->render_match_archive(); lf_assert( $renderer->mapped <= 20 && $first !== $second, 'Archive page 2 repeats the first page.' );
	$classic = file_get_contents( LEAGUEFLOW_PATH . 'templates/archive-match.php' ); $block = file_get_contents( LEAGUEFLOW_PATH . 'templates/block/archive-lf_match.html' );
	lf_assert( false !== strpos( $classic, 'render_match_archive()' ) && false !== strpos( $block, '[match_archive]' ), 'Archive templates do not use the shared renderer.' );
	add_shortcode( 'match_archive', array( $renderer, 'render_match_archive' ) );
	global $_wp_current_template_content, $_wp_current_template_id;
	$saved_content = $_wp_current_template_content; $saved_id = $_wp_current_template_id;
	$_wp_current_template_content = $block; $_wp_current_template_id = 'leagueflow//archive-lf_match';
	try {
		$renderer->mapped = 0; $renderer->mapped_ids = array(); set_query_var( 'paged', 1 ); $block_first = get_the_block_template_html();
		lf_assert( 20 === $renderer->mapped && $renderer->mapped_ids[0] === $ids[0] && false !== strpos( $block_first, '<nav' ) && false !== strpos( $block_first, 'wp-block-group' ), 'Real block archive failed page occupancy/navigation.' );
		$renderer->mapped = 0; $renderer->mapped_ids = array(); set_query_var( 'paged', 2 ); $block_second = get_the_block_template_html();
		lf_assert( 20 === $renderer->mapped && $renderer->mapped_ids[0] === $ids[40] && $block_first !== $block_second, 'Real block archive page 2 repeated or omitted records.' );
	} finally { $_wp_current_template_content = $saved_content; $_wp_current_template_id = $saved_id; }
	set_query_var( 'paged', 1 );
};

$failures = array();
foreach ( $tests as $name => $test ) {
	$_POST = array(); $_REQUEST = array(); wp_set_current_user( $admin_id );
	try { $test(); WP_CLI::log( 'PASS ' . $name ); }
	catch ( Throwable $error ) { $failures[] = $name . ': ' . $error->getMessage(); WP_CLI::warning( end( $failures ) ); }
}
wp_set_current_user( $admin_id ); $_POST = array(); $_REQUEST = array();
if ( $failures ) { WP_CLI::error( implode( "\n", $failures ) ); }
lf_assert( $GLOBALS['lf_assertions'] > 500, 'Native regression assertions were unexpectedly skipped.' );
WP_CLI::success( 'Eight native security groups pass; ' . $GLOBALS['lf_assertions'] . ' assertions. WordPress ' . get_bloginfo( 'version' ) );
