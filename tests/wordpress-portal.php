<?php
/**
 * Native WordPress portal write/permission regressions; never run against an existing site.
 *
 * Each case fires the real `admin_post_leagueflow_portal` action with a real nonce for
 * the acting user and records the redirect notice instead of exiting. This exercises
 * the registered handler, nonce, capability and object checks against a real database.
 * It does not send HTTP requests: cookie authentication, admin-post.php routing and
 * multipart uploads through PHP's SAPI remain outside this test.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Set LEAGUEFLOW_DISPOSABLE_WP=1 and use only the disposable leagueflow.test installation.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

class LFP_Redirect extends RuntimeException {
	public $location;
	public function __construct( $location ) { parent::__construct( 'redirect' ); $this->location = (string) $location; }
	public function notice() { parse_str( (string) parse_url( $this->location, PHP_URL_QUERY ), $q ); return $q['leagueflow_notice'] ?? ''; }
}
class LFP_Portal extends \LeagueFlow\Portal {
	public function upload( $field, $post_id ) { return $this->save_uploaded_image( $field, $post_id ); }
}
function lfp_assert( $value, $message ) { global $lfp_assertions; ++$lfp_assertions; if ( ! $value ) { throw new RuntimeException( $message ); } }
function lfp_user( $login, $role ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => $role, 'display_name' => 'Portal ' . $login ) );
	lfp_assert( ! is_wp_error( $id ), 'User fixture failed.' ); return (int) $id;
}
function lfp_post( $type, $title, $status = 'publish', $author = 0 ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'post_author' => $author ?: get_current_user_id() ), true );
	lfp_assert( ! is_wp_error( $id ) && $id > 0, 'Post fixture failed.' ); return (int) $id;
}
/** Dispatch a portal form as $user_id; returns the redirect notice ('login' for the login redirect). */
function lfp_submit( $user_id, $action, $fields = array(), $nonce = 'valid', $files = array() ) {
	wp_set_current_user( $nonce === 'other-user' ? $GLOBALS['lfp_other_nonce_user'] : $user_id );
	$token = 'valid' === $nonce || 'other-user' === $nonce ? wp_create_nonce( 'leagueflow_portal_' . $action ) : ( 'wrong-action' === $nonce ? wp_create_nonce( 'leagueflow_portal_update_team_profile' ) : 'abc123def4' );
	wp_set_current_user( $user_id );
	$_POST = array_merge( array( 'leagueflow_portal_action' => $action ), $fields );
	if ( 'missing' !== $nonce ) { $_POST['leagueflow_portal_nonce'] = $token; }
	$_POST = wp_slash( $_POST ); $_REQUEST = $_POST; $_FILES = $files;
	$catch = static function( $location ) { throw new LFP_Redirect( $location ); };
	add_filter( 'wp_redirect', $catch, PHP_INT_MIN );
	try { do_action( 'admin_post_leagueflow_portal' ); lfp_assert( false, "Portal action $action returned without a redirect." ); }
	catch ( LFP_Redirect $r ) { return false !== strpos( $r->location, 'wp-login.php' ) ? 'login' : $r->notice(); }
	finally { remove_filter( 'wp_redirect', $catch, PHP_INT_MIN ); $_POST = array(); $_REQUEST = array(); $_FILES = array(); }
	return '';
}
function lfp_expect( $expected, $actual, $label ) { lfp_assert( $expected === $actual, "$label: expected notice '$expected', got '$actual'." ); }
function lfp_snapshot( $ids ) {
	$out = array();
	foreach ( $ids as $id ) { clean_post_cache( $id ); $p = get_post( $id ); $out[ $id ] = $p ? array( $p->post_title, $p->post_content, $p->post_status, get_post_meta( $id ) ) : null; }
	return $out;
}
function lfp_requests( $player_id ) {
	return get_posts( array( 'post_type' => 'lf_join_request', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'lf_player_id', 'meta_value' => $player_id ) );
}
function lfp_count( $type ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type=%s", $type ) ); }
function lfp_file( $name, $bytes, $error = UPLOAD_ERR_OK ) {
	$tmp = wp_tempnam( $name ); file_put_contents( $tmp, $bytes );
	return array( 'name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen( $bytes ) );
}

// Isolation: no outbound HTTP or mail; mail is captured for assertions.
$GLOBALS['lfp_assertions'] = 0; $GLOBALS['lfp_mail'] = array();
add_filter( 'pre_http_request', static function() { return new WP_Error( 'lfp_offline', 'Outbound HTTP blocked by the portal test.' ); }, PHP_INT_MAX );
add_filter( 'pre_wp_mail', static function( $pre, $atts ) { $GLOBALS['lfp_mail'][] = $atts; return true; }, PHP_INT_MAX, 2 );
add_filter( 'leagueflow_registration_email_allowed', static function( $allowed, $email ) { return $allowed || '@example.test' === substr( $email, -13 ); }, 10, 2 );

$admin_id = get_user_by( 'login', 'admin' )->ID; wp_set_current_user( $admin_id ); $GLOBALS['lfp_admin'] = $admin_id;
\LeagueFlow\ensure_portal_roles();
update_option( 'leagueflow_enabled_sports', array( 'soccer', 'basketball' ) );
$sport = array(); foreach ( array( 'soccer' => 'Soccer', 'basketball' => 'Basketball' ) as $slug => $name ) { $t = get_term_by( 'slug', $slug, 'lf_sport' ); $sport[ $slug ] = $t ? (int) $t->term_id : (int) wp_insert_term( $name, 'lf_sport', array( 'slug' => $slug ) )['term_id']; }
$level = array(); foreach ( array( 'portal-competitive' => 'Portal Competitive', 'portal-recreational' => 'Portal Recreational' ) as $slug => $name ) { $t = get_term_by( 'slug', $slug, 'lf_league_level' ); $level[ $slug ] = $t ? (int) $t->term_id : (int) wp_insert_term( $name, 'lf_league_level', array( 'slug' => $slug ) )['term_id']; }
$settings = get_option( 'leagueflow_settings', array() ); $settings = is_array( $settings ) ? $settings : array();
update_option( 'leagueflow_settings', array_merge( $settings, array( 'player_registration_open' => 1, 'captain_registration_open' => 1 ) ) );

$u = array();
foreach ( array( 'captain_a' => 'leagueflow_team_manager', 'captain_b' => 'leagueflow_team_manager', 'player_p' => 'leagueflow_player', 'player_q' => 'leagueflow_player', 'joiner' => 'leagueflow_player', 'joiner2' => 'leagueflow_player', 'outsider' => 'subscriber', 'editor_victim' => 'editor', 'invitee' => 'subscriber' ) as $login => $role ) { $u[ $login ] = lfp_user( 'portal_' . $login, $role ); }
$GLOBALS['lfp_other_nonce_user'] = $u['captain_b'];
$team = function( $title, $sport_id, $level_id, $managers, $status = 'publish' ) use ( $admin_id ) {
	$id = lfp_post( 'lf_team', $title, $status, $admin_id );
	wp_set_object_terms( $id, array( $sport_id ), 'lf_sport' ); wp_set_object_terms( $id, array( $level_id ), 'lf_league_level' );
	update_post_meta( $id, 'lf_manager_user_ids', $managers ); return $id;
};
$team_a = $team( 'Portal Team A', $sport['soccer'], $level['portal-competitive'], array( $u['captain_a'] ) );
$team_b = $team( 'Portal Team B', $sport['soccer'], $level['portal-competitive'], array( $u['captain_b'] ) );
$team_c = $team( 'Portal Team C Basketball', $sport['basketball'], $level['portal-competitive'], array( $u['captain_a'] ) );
$team_rec = $team( 'Portal Team Recreational', $sport['soccer'], $level['portal-recreational'], array( $u['captain_b'] ) );
$team_draft = $team( 'Portal Team Draft', $sport['soccer'], $level['portal-competitive'], array( $u['captain_b'] ), 'draft' );
$player = function( $title, $user_key, $team_ids ) use ( $admin_id, $u ) {
	$id = lfp_post( 'lf_player', $title, 'private', $admin_id );
	if ( $user_key ) { update_post_meta( $id, 'lf_user_id', $u[ $user_key ] ); update_post_meta( $id, 'lf_email', 'portal_' . $user_key . '@example.test' ); }
	foreach ( $team_ids as $team_id ) { \LeagueFlow\assign_player_to_team( $id, $team_id ); }
	return $id;
};
$p_ca = $player( 'Portal Captain A', 'captain_a', array( $team_a ) );
$p_cb = $player( 'Portal Captain B', 'captain_b', array( $team_b ) );
$p_p  = $player( 'Portal Player P', 'player_p', array( $team_a ) );
$p_q  = $player( 'Portal Player Q', 'player_q', array( $team_b ) );
$p_j  = $player( 'Portal Joiner', 'joiner', array() );
$p_j2 = $player( 'Portal Joiner Two', 'joiner2', array() );
$match = function( $home, $away, $status = 'publish' ) use ( $admin_id ) { $id = lfp_post( 'lf_match', 'Portal match', $status, $admin_id ); update_post_meta( $id, 'lf_home_team_id', $home ); update_post_meta( $id, 'lf_away_team_id', $away ); update_post_meta( $id, 'lf_match_datetime', '2031-10-15 18:30' ); return $id; };
$m_ab = $match( $team_a, $team_b ); $m_brec = $match( $team_b, $team_rec );
$page = lfp_post( 'page', 'Portal unrelated page', 'publish', $admin_id );
$tests = array();

$tests['POR-01 dispatcher nonce and authentication'] = static function() use ( $u, $team_a, $page ) {
	$fields = array( 'lf_team_id' => $team_a, 'lf_team_name' => 'FORGED NAME', 'lf_team_description' => 'FORGED' );
	$before = lfp_snapshot( array( $team_a ) );
	lfp_expect( 'login', lfp_submit( 0, 'update_team_profile', $fields ), 'Logged-out submission' );
	foreach ( array( 'missing', 'invalid', 'other-user' ) as $mode ) { lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'update_team_profile', $fields, $mode ), "Nonce mode $mode" ); }
	lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'remove_roster_player', $fields, 'wrong-action' ), 'Nonce minted for another portal action' );
	lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'delete_everything', $fields ), 'Unknown action with its own valid nonce' );
	lfp_assert( $before === lfp_snapshot( array( $team_a ) ), 'Rejected dispatcher request changed the team.' );
	$settings = get_option( 'leagueflow_settings' ); update_option( 'leagueflow_settings', array_merge( $settings, array( 'player_registration_open' => 0, 'captain_registration_open' => 0 ) ) );
	try { lfp_expect( 'access-denied', lfp_submit( $u['outsider'], 'update_team_profile', $fields ), 'Account without portal access' ); }
	finally { update_option( 'leagueflow_settings', $settings ); }
	lfp_assert( $before === lfp_snapshot( array( $team_a, ) ) && 'Portal unrelated page' === get_the_title( $page ), 'Denied account changed state.' );
};

$tests['POR-02 own versus other team and player edits'] = static function() use ( $u, $team_a, $team_b, $p_p, $p_q, $page, $level ) {
	$guarded = array( $team_a, $team_b, $p_q, $page );
	foreach ( array( array( 'captain_b', $team_a ), array( 'player_p', $team_a ), array( 'outsider', $team_a ), array( 'captain_a', $team_b ), array( 'captain_a', $page ), array( 'captain_a', 0 ), array( 'captain_a', 999999 ) ) as $case ) {
		$before = lfp_snapshot( $guarded );
		lfp_expect( 'team-denied', lfp_submit( $u[ $case[0] ], 'update_team_profile', array( 'lf_team_id' => $case[1], 'lf_team_name' => 'FORGED', 'lf_team_description' => 'FORGED' ) ), "Team edit {$case[0]} -> {$case[1]}" );
		lfp_assert( $before === lfp_snapshot( $guarded ), 'Denied team edit wrote.' );
	}
	lfp_expect( 'team-saved', lfp_submit( $u['captain_a'], 'update_team_profile', array( 'lf_team_id' => $team_a, 'lf_team_name' => 'Portal Team A', 'lf_team_description' => 'Audit description <script>x</script>', 'lf_short_name' => 'PTA' ) ), 'Own team edit' );
	clean_post_cache( $team_a );
	lfp_assert( false !== strpos( get_post_field( 'post_content', $team_a ), 'Audit description' ) && false === strpos( get_post_field( 'post_content', $team_a ), '<script' ) && 'PTA' === get_post_meta( $team_a, 'lf_short_name', true ), 'Own team edit not saved/sanitized after reload.' );
	lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'update_team_profile', array( 'lf_team_id' => $team_a, 'lf_team_name' => '', 'lf_team_description' => '' ) ), 'Empty team name' );
	lfp_assert( 'Portal Team A' === get_the_title( $team_a ), 'Empty team name was stored.' );

	$before_q = lfp_snapshot( array( $p_q ) );
	$sports = array( 'lf_player_sports' => array( 'soccer' ), 'lf_player_sport_levels' => array( 'soccer' => $level['portal-competitive'] ) );
	lfp_expect( 'profile-saved', lfp_submit( $u['player_p'], 'update_player_profile', $sports + array( 'lf_player_description' => 'Own profile audit text', 'lf_player_id' => $p_q ) ), 'Own profile edit with forged player id' );
	clean_post_cache( $p_p );
	lfp_assert( 'Own profile audit text' === get_post_field( 'post_content', $p_p ) && $before_q === lfp_snapshot( array( $p_q ) ), 'Profile edit ignored the account owner or touched another player.' );
	lfp_expect( 'sport-level-required', lfp_submit( $u['player_p'], 'update_player_profile', array( 'lf_player_description' => 'missing sports' ) ), 'Profile without sport/level' );
	lfp_assert( 'Own profile audit text' === get_post_field( 'post_content', $p_p ), 'Rejected profile edit wrote.' );
	lfp_expect( 'player-missing', lfp_submit( $u['outsider'], 'update_player_profile', $sports + array( 'lf_player_description' => 'x' ) ), 'Account without a player record' );

	foreach ( array( array( 'captain_a', $p_q, $team_b ), array( 'captain_a', $p_q, $team_a ), array( 'player_p', $p_p, $team_a ), array( 'captain_b', $p_p, $team_a ), array( 'captain_a', $page, $team_a ), array( 'captain_a', 0, $team_a ), array( 'captain_a', 999999, $team_a ) ) as $case ) {
		$before = lfp_snapshot( array( $p_p, $p_q ) );
		lfp_expect( 'team-denied', lfp_submit( $u[ $case[0] ], 'update_roster_player', array( 'lf_team_id' => $case[2], 'lf_player_id' => $case[1], 'lf_player_name' => 'FORGED', 'lf_jersey_number' => '66' ) ), "Roster update {$case[0]} on {$case[1]}/{$case[2]}" );
		lfp_assert( $before === lfp_snapshot( array( $p_p, $p_q ) ), 'Denied roster update wrote.' );
	}
	lfp_expect( 'player-identity-protected', lfp_submit( $u['captain_a'], 'update_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_p, 'lf_player_name' => 'Renamed by captain' ) ), 'Captain renaming an account-owned player' );
	lfp_assert( 'Portal Player P' === get_post_field( 'post_title', $p_p ), 'Captain renamed an account-owned player.' );
	lfp_expect( 'roster-saved', lfp_submit( $u['captain_a'], 'update_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_p, 'lf_jersey_number' => '12', 'lf_position' => 'Keeper' ) ), 'Own roster detail edit' );
	$detail = \LeagueFlow\get_player_team_details( $p_p )[ $team_a ] ?? array();
	lfp_assert( '12' === (string) ( $detail['jersey_number'] ?? '' ) && 'Keeper' === ( $detail['position'] ?? '' ), 'Own roster detail not saved.' );
};

$tests['POR-03 roster add and remove'] = static function() use ( $u, $team_a, $team_b, $p_ca, $p_p, $p_q, $page ) {
	$roster = static function( $team ) { return array_map( 'intval', wp_list_pluck( \LeagueFlow\get_team_roster_player_posts( $team ), 'ID' ) ); };
	$b_before = $roster( $team_b ); $players = lfp_count( 'lf_player' );
	foreach ( array( array( 'captain_b', $team_a ), array( 'player_p', $team_a ), array( 'captain_a', $team_b ), array( 'captain_a', $page ), array( 'captain_a', 999999 ) ) as $case ) {
		lfp_expect( 'team-denied', lfp_submit( $u[ $case[0] ], 'add_player', array( 'lf_team_id' => $case[1], 'lf_player_name' => 'Forged Add' ) ), "Add {$case[0]} -> {$case[1]}" );
	}
	lfp_assert( $players === lfp_count( 'lf_player' ) && $b_before === $roster( $team_b ), 'Denied add created or moved a player.' );
	lfp_expect( 'player-identity-protected', lfp_submit( $u['captain_a'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => 'portal_player_q@example.test', 'lf_player_name' => 'Steal Q' ) ), 'Adding another account-owned player by email' );
	lfp_assert( ! \LeagueFlow\player_has_team( $p_q, $team_a ) && 'Portal Player Q' === get_post_field( 'post_title', $p_q ), 'Account-owned player was taken over.' );
	lfp_expect( 'invalid-email', lfp_submit( $u['captain_a'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => 'not-an-email', 'lf_player_name' => 'Bad Email' ) ), 'Invalid email' );
	lfp_expect( 'invalid-email', lfp_submit( $u['captain_a'], 'update_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_p, 'lf_email' => 'still not@an email' ) ), 'Invalid roster email' );
	lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_player_name' => '' ) ), 'Missing name' );
	lfp_assert( $players === lfp_count( 'lf_player' ), 'Rejected add created a player.' );

	$fields = array( 'lf_team_id' => $team_a, 'lf_email' => 'new-roster@example.test', 'lf_player_name' => 'Portal New Roster' );
	lfp_expect( 'player-added', lfp_submit( $u['captain_a'], 'add_player', $fields ), 'Own team add' );
	lfp_expect( 'player-added', lfp_submit( $u['captain_a'], 'add_player', $fields ), 'Duplicate add by email' );
	$new = get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_email', 'meta_value' => 'new-roster@example.test' ) );
	lfp_assert( 1 === count( $new ) && $players + 1 === lfp_count( 'lf_player' ) && in_array( (int) $new[0], $roster( $team_a ), true ) && 'private' === get_post_status( $new[0] ), 'Duplicate email add did not stay idempotent/private.' );

	foreach ( array( array( 'captain_b', $p_p, $team_a ), array( 'player_p', $p_p, $team_a ), array( 'captain_a', $p_q, $team_b ), array( 'captain_a', $p_q, $team_a ), array( 'captain_a', $page, $team_a ), array( 'captain_a', 0, $team_a ), array( 'captain_a', $p_p, 999999 ) ) as $case ) {
		lfp_expect( 'team-denied', lfp_submit( $u[ $case[0] ], 'remove_roster_player', array( 'lf_team_id' => $case[2], 'lf_player_id' => $case[1] ) ), "Remove {$case[0]} on {$case[1]}/{$case[2]}" );
	}
	lfp_assert( \LeagueFlow\player_has_team( $p_p, $team_a ) && \LeagueFlow\player_has_team( $p_q, $team_b ), 'Denied removal changed a roster.' );
	lfp_expect( 'captain-removal-denied', lfp_submit( $u['captain_a'], 'remove_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_ca ) ), 'Captain self-removal' );
	lfp_assert( \LeagueFlow\player_has_team( $p_ca, $team_a ), 'Captain removed themselves.' );
	lfp_expect( 'player-removed', lfp_submit( $u['captain_a'], 'remove_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_p ) ), 'Own roster removal' );
	lfp_assert( ! \LeagueFlow\player_has_team( $p_p, $team_a ) && 'private' === get_post_status( $p_p ) && $u['player_p'] === (int) get_post_meta( $p_p, 'lf_user_id', true ), 'Removal deleted the player or its account link.' );
	lfp_expect( 'team-denied', lfp_submit( $u['captain_a'], 'remove_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_p ) ), 'Duplicate removal' );
	lfp_expect( 'player-identity-protected', lfp_submit( $u['captain_a'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => 'portal_player_p@example.test', 'lf_player_name' => 'Portal Player P' ) ), 'Captain re-adding a departed account-owned player' );
	lfp_assert( ! \LeagueFlow\player_has_team( $p_p, $team_a ), 'Departed account-owned player was re-added by a captain.' );
	lfp_expect( 'player-added', lfp_submit( $GLOBALS['lfp_admin'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => 'portal_player_p@example.test', 'lf_player_name' => 'Portal Player P' ) ), 'Administrator reversing the removal' );
	lfp_assert( \LeagueFlow\player_has_team( $p_p, $team_a ) && 'Portal Player P' === get_post_field( 'post_title', $p_p ) && $u['player_p'] === (int) get_post_meta( $p_p, 'lf_user_id', true ), 'Reversible re-add failed or changed identity.' );
};

$tests['POR-04 join request, approval and rejection'] = static function() use ( $u, $team_a, $team_b, $team_c, $team_rec, $team_draft, $p_p, $p_j, $p_j2, $page, $level, $sport ) {
	$req = static function( $team, $extra = array() ) use ( $level ) { return array_merge( array( 'lf_sport_slug' => 'soccer', 'lf_team_id' => (string) $team, 'lf_league_level_id' => $level['portal-competitive'], 'lf_request_note' => 'Audit request' ), $extra ); };
	$requests = lfp_count( 'lf_join_request' );
	foreach ( array( array( 999999, 'invalid-request' ), array( $page, 'invalid-request' ), array( $team_draft, 'invalid-request' ), array( $team_c, 'invalid-request' ), array( $team_rec, 'team-level-mismatch' ) ) as $case ) {
		lfp_expect( $case[1], lfp_submit( $u['joiner'], 'request_join_team', $req( $case[0] ) ), "Join request target {$case[0]}" );
	}
	lfp_expect( 'invalid-request', lfp_submit( $u['joiner'], 'request_join_team', $req( $team_a, array( 'lf_sport_slug' => 'cricket' ) ) ), 'Disabled sport' );
	lfp_expect( 'invalid-request', lfp_submit( $u['joiner'], 'request_join_team', $req( $team_a, array( 'lf_league_level_id' => 999999 ) ) ), 'Invalid level' );
	lfp_expect( 'join-request-member', lfp_submit( $u['player_p'], 'request_join_team', $req( $team_a ) ), 'Existing member request' );
	lfp_assert( $requests === lfp_count( 'lf_join_request' ), 'Rejected request created a record.' );
	$GLOBALS['lfp_mail'] = array();
	lfp_expect( 'join-request-sent', lfp_submit( $u['joiner'], 'request_join_team', $req( $team_a ) ), 'Valid join request' );
	lfp_expect( 'join-request-exists', lfp_submit( $u['joiner'], 'request_join_team', $req( $team_a ) ), 'Duplicate join request' );
	lfp_expect( 'join-request-exists', lfp_submit( $u['joiner'], 'request_join_team', $req( $team_b ) ), 'Second same-sport team while pending' );
	$mine = lfp_requests( $p_j );
	lfp_assert( 1 === count( $mine ) && 'private' === get_post_status( $mine[0] ) && 'pending' === get_post_meta( $mine[0], 'lf_request_status', true ) && $team_a === (int) get_post_meta( $mine[0], 'lf_team_id', true ), 'Join request not stored exactly once as private pending.' );
	lfp_assert( 1 === count( $GLOBALS['lfp_mail'] ) && in_array( 'portal_captain_a@example.test', (array) $GLOBALS['lfp_mail'][0]['to'], true ), 'Captain notification not captured exactly once.' );
	$request = (int) $mine[0];

	foreach ( array( array( 'captain_b', array() ), array( 'captain_b', array( 'lf_team_id' => $team_b ) ), array( 'captain_a', array( 'lf_team_id' => $team_b ) ), array( 'player_p', array() ), array( 'joiner', array() ) ) as $case ) {
		lfp_expect( 'team-denied', lfp_submit( $u[ $case[0] ], 'review_join_request', array_merge( array( 'lf_join_request_id' => $request, 'lf_join_decision' => 'approve' ), $case[1] ) ), "Review by {$case[0]}" );
	}
	foreach ( array( 0, 999999, $page, $p_p ) as $bad ) { lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $bad, 'lf_join_decision' => 'approve' ) ), "Review invalid id $bad" ); }
	lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request, 'lf_join_decision' => 'maybe' ) ), 'Unknown decision' );
	lfp_assert( 'pending' === get_post_meta( $request, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j, $team_a ) && ! \LeagueFlow\player_has_team( $p_j, $team_b ), 'Denied review changed request or roster.' );
	// The portal form carries the request state (same token the rendered form holds).
	$state = static function( $id ) { clean_post_cache( $id ); $s = \LeagueFlow\Join_Request_Service::shared(); return $s->state_token( $s->inspect( $id ) ); };
	$mail  = count( $GLOBALS['lfp_mail'] );
	lfp_expect( 'join-request-changed', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request, 'lf_join_decision' => 'approve' ) ), 'Owner approval from a form without the request state' );
	$view = $state( $request );
	update_post_meta( $request, 'lf_player_id', $p_j2 );
	lfp_expect( 'join-request-changed', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request, 'lf_join_decision' => 'approve', 'lf_request_state' => $view ) ), 'Owner approval from a form loaded before the request changed' );
	update_post_meta( $request, 'lf_player_id', $p_j );
	clean_post_cache( $request );
	lfp_assert( 'pending' === get_post_meta( $request, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j, $team_a ) && ! \LeagueFlow\player_has_team( $p_j2, $team_a ) && $mail === count( $GLOBALS['lfp_mail'] ) && array() === \LeagueFlow\Join_Request_Service::shared()->get_history( $request ), 'A stale or stateless portal review changed the request, roster, history or mail.' );
	lfp_expect( 'join-request-approved', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request, 'lf_join_decision' => 'approve', 'lf_request_state' => $state( $request ) ) ), 'Owner approval' );
	lfp_assert( 'approved' === get_post_meta( $request, 'lf_request_status', true ) && \LeagueFlow\player_has_team( $p_j, $team_a ), 'Approval did not add the player.' );
	$mail = count( $GLOBALS['lfp_mail'] );
	lfp_expect( 'team-denied', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request, 'lf_join_decision' => 'decline' ) ), 'Duplicate review after approval' );
	lfp_assert( 'approved' === get_post_meta( $request, 'lf_request_status', true ) && $mail === count( $GLOBALS['lfp_mail'] ), 'Duplicate review changed status or re-notified.' );

	lfp_expect( 'join-request-sent', lfp_submit( $u['joiner2'], 'request_join_team', $req( $team_a ) ), 'Second joiner request' );
	$request2 = (int) lfp_requests( $p_j2 )[0];
	lfp_expect( 'join-request-declined', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request2, 'lf_join_decision' => 'decline', 'lf_request_state' => $state( $request2 ) ) ), 'Owner rejection' );
	lfp_expect( 'team-denied', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request2, 'lf_join_decision' => 'approve' ) ), 'Approval after rejection' );
	lfp_assert( 'declined' === get_post_meta( $request2, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j2, $team_a ), 'Rejected request joined the team.' );

	lfp_expect( 'placement-request-sent', lfp_submit( $u['joiner2'], 'request_join_team', $req( 0 ) ), 'Placement request' );
	$placement = (int) array_values( array_diff( lfp_requests( $p_j2 ), array( $request2 ) ) )[0];
	lfp_expect( 'team-denied', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $placement, 'lf_join_decision' => 'approve', 'lf_team_id' => $team_a ) ), 'Captain approving a placement request' );
	lfp_assert( 'pending' === get_post_meta( $placement, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j2, $team_a ), 'Captain placed a player through a placement request.' );

	// Request history and reconciliation from an explicit, authorized portal roster entry point.
	$service = \LeagueFlow\Join_Request_Service::shared();
	lfp_assert( 1 === count( $service->get_history( $request ) ) && 'approved' === $service->get_history( $request )[0]['event'] && 'declined' === $service->get_history( $request2 )[0]['event'], 'Portal review decisions were not recorded once in the request history.' );
	$staff_add = array( 'lf_team_id' => $team_a, 'lf_email' => 'portal_joiner2@example.test', 'lf_player_name' => 'Portal Joiner Two' );
	lfp_expect( 'player-added', lfp_submit( $GLOBALS['lfp_admin'], 'add_player', $staff_add ), 'Staff roster add of a player with a pending placement request' );
	clean_post_cache( $placement );
	lfp_assert( \LeagueFlow\player_has_team( $p_j2, $team_a ) && 'approved' === get_post_meta( $placement, 'lf_request_status', true ) && 1 === count( $service->get_history( $placement ) ) && 'reconciled' === $service->get_history( $placement )[0]['event'], 'Authorized portal roster add did not close the matching pending request exactly once.' );
	lfp_assert( 'declined' === get_post_meta( $request2, 'lf_request_status', true ) && 1 === count( $service->get_history( $request2 ) ), 'Roster add changed an already declined request.' );
	lfp_expect( 'player-added', lfp_submit( $GLOBALS['lfp_admin'], 'add_player', $staff_add ), 'Repeated staff roster add' );
	lfp_assert( 1 === count( $service->get_history( $placement ) ), 'Repeated roster add recorded the reconciliation twice.' );
	\LeagueFlow\remove_player_team_id( $p_j2, $team_a );

	// Staff and the captain race: staff decide between the captain's page load and submit.
	lfp_expect( 'join-request-sent', lfp_submit( $u['joiner2'], 'request_join_team', $req( $team_a ) ), 'Joiner two asks again' );
	$request3 = (int) array_values( array_diff( lfp_requests( $p_j2 ), array( $request2, $placement ) ) )[0];
	$captain_view = $state( $request3 );
	wp_set_current_user( $GLOBALS['lfp_admin'] );
	lfp_assert( true === $service->apply_action( $request3, 'decline', array( 'note' => 'Staff decided first', 'state' => $state( $request3 ) ) ), 'Staff decline failed.' );
	$mail = count( $GLOBALS['lfp_mail'] );
	lfp_expect( 'team-denied', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request3, 'lf_join_decision' => 'approve', 'lf_request_state' => $captain_view ) ), 'Captain approval after a staff decision' );
	clean_post_cache( $request3 );
	lfp_assert( 'declined' === get_post_meta( $request3, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j2, $team_a ) && 1 === count( $service->get_history( $request3 ) ) && $mail === count( $GLOBALS['lfp_mail'] ), 'A captain approval overwrote a staff decision, or recorded/mailed twice.' );
	// The decision changed between the handler's first read and the lock: the locked re-read wins.
	lfp_expect( 'join-request-sent', lfp_submit( $u['joiner2'], 'request_join_team', $req( $team_a ) ), 'Joiner two asks a third time' );
	$request4 = (int) array_values( array_diff( lfp_requests( $p_j2 ), array( $request2, $placement, $request3 ) ) )[0];
	$view4    = $state( $request4 );
	$fired    = false;
	$race     = static function ( $query ) use ( &$fired, $request4 ) {
		global $wpdb;
		if ( ! $fired && 0 === stripos( ltrim( $query ), 'SELECT GET_LOCK(' ) ) { $fired = true; $wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'declined' ), array( 'post_id' => $request4, 'meta_key' => 'lf_request_status' ) ); }
		return $query;
	};
	add_filter( 'query', $race );
	$mail = count( $GLOBALS['lfp_mail'] );
	try { lfp_expect( 'join-request-changed', lfp_submit( $u['captain_a'], 'review_join_request', array( 'lf_join_request_id' => $request4, 'lf_join_decision' => 'approve', 'lf_request_state' => $view4 ) ), 'Captain approval racing a decision stored while it waited for the lock' ); }
	finally { remove_filter( 'query', $race ); }
	clean_post_cache( $request4 );
	lfp_assert( $fired && 'declined' === get_post_meta( $request4, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j2, $team_a ) && array() === $service->get_history( $request4 ) && $mail === count( $GLOBALS['lfp_mail'] ), 'The locked re-read did not protect the stored decision.' );

	$settings = get_option( 'leagueflow_settings' ); update_option( 'leagueflow_settings', array_merge( $settings, array( 'player_registration_open' => 0 ) ) );
	try { lfp_expect( 'registration-closed', lfp_submit( $u['player_p'], 'request_join_team', $req( $team_b ) ), 'Request while registration closed' ); }
	finally { update_option( 'leagueflow_settings', $settings ); }
};

$tests['POR-05 match availability'] = static function() use ( $u, $m_ab, $m_brec, $page, $p_p ) {
	lfp_expect( 'availability-saved', lfp_submit( $u['player_p'], 'set_availability', array( 'lf_match_id' => $m_ab, 'lf_availability_status' => 'available' ) ), 'Own match availability' );
	lfp_expect( 'availability-saved', lfp_submit( $u['player_p'], 'set_availability', array( 'lf_match_id' => $m_ab, 'lf_availability_status' => 'available' ) ), 'Duplicate availability' );
	lfp_assert( 'available' === \LeagueFlow\Availability::get_status( $p_p, $m_ab ) && 1 === \LeagueFlow\Availability::counts( $m_ab )['total'], 'Duplicate availability created more than one response.' );
	lfp_expect( 'availability-saved', lfp_submit( $u['player_p'], 'set_availability', array( 'lf_match_id' => $m_ab, 'lf_availability_status' => 'out' ) ), 'Availability update' );
	lfp_assert( 'out' === \LeagueFlow\Availability::get_status( $p_p, $m_ab ), 'Availability update not stored.' );
	lfp_expect( 'invalid-request', lfp_submit( $u['player_p'], 'set_availability', array( 'lf_match_id' => $m_ab, 'lf_availability_status' => 'injured' ) ), 'Invalid availability status' );
	lfp_expect( 'invalid-request', lfp_submit( $u['player_p'], 'set_availability', array( 'lf_match_id' => 0, 'lf_availability_status' => 'available' ) ), 'Missing match id' );
	foreach ( array( $m_brec, $page, 999999 ) as $other ) { lfp_expect( 'access-denied', lfp_submit( $u['player_p'], 'set_availability', array( 'lf_match_id' => $other, 'lf_availability_status' => 'available' ) ), "Availability for non-member match $other" ); lfp_assert( '' === \LeagueFlow\Availability::get_status( $p_p, $other ), 'Foreign availability stored.' ); }
	lfp_expect( 'player-missing', lfp_submit( $u['outsider'], 'set_availability', array( 'lf_match_id' => $m_ab, 'lf_availability_status' => 'available' ) ), 'Availability without a player' );
	lfp_assert( 'out' === \LeagueFlow\Availability::get_status( $p_p, $m_ab ) && 1 === \LeagueFlow\Availability::counts( $m_ab )['total'], 'Rejected availability changed responses.' );
};

$tests['POR-06 upload validation leaves profile and team unchanged'] = static function() use ( $u, $team_a, $p_p, $level ) {
	$portal = new LFP_Portal( new \LeagueFlow\Renderer( new \LeagueFlow\Standings_Service(), new \LeagueFlow\Knockout_Service(), new \LeagueFlow\Sports_Manager() ) );
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==' );
	$attachments = lfp_count( 'attachment' ); $thumb = get_post_thumbnail_id( $p_p );
	$sports = array( 'lf_player_sports' => array( 'soccer' ), 'lf_player_sport_levels' => array( 'soccer' => $level['portal-competitive'] ), 'lf_player_description' => 'Own profile audit text' );
	$profile = static function() use ( $p_p ) { return array( lfp_snapshot( array( $p_p ) ), wp_get_object_terms( $p_p, array( 'lf_sport', 'lf_league_level' ), array( 'fields' => 'ids' ) ) ); };
	$profile_before = $profile();
	foreach ( array( 'php-as-jpg' => lfp_file( 'avatar.jpg', '<?php echo "x"; ?>' ), 'php' => lfp_file( 'avatar.php', '<?php echo "x"; ?>' ), 'svg' => lfp_file( 'avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ), 'html' => lfp_file( 'avatar.html', '<html></html>' ), 'ini-size' => lfp_file( 'avatar.png', '', UPLOAD_ERR_INI_SIZE ), 'partial' => lfp_file( 'avatar.png', $png, UPLOAD_ERR_PARTIAL ) ) as $label => $file ) {
		lfp_expect( 'upload-error', lfp_submit( $u['player_p'], 'update_player_profile', array_merge( $sports, array( 'lf_player_description' => "Rejected upload text $label", 'lf_player_sport_levels' => array( 'soccer' => $level['portal-recreational'] ) ) ), 'valid', array( 'lf_player_photo' => $file ) ), "Player photo $label" );
		lfp_assert( $profile_before === $profile(), "Rejected player photo $label still saved profile text, metadata or terms." );
		wp_set_current_user( $u['player_p'] ); $_FILES = array( 'lf_player_photo' => $file );
		$result = $portal->upload( 'lf_player_photo', $p_p ); $_FILES = array();
		lfp_assert( is_wp_error( $result ), "Upload $label accepted." );
	}
	wp_set_current_user( $u['player_p'] ); $_FILES = array( 'lf_player_photo' => lfp_file( 'avatar.png', $png ) );
	$result = $portal->upload( 'lf_player_photo', $p_p ); $_FILES = array();
	lfp_assert( is_wp_error( $result ) && false !== stripos( $result->get_error_message(), 'upload test' ), 'Valid PNG should pass type validation and stop only at the SAPI is_uploaded_file test.' );
	lfp_expect( 'team-denied', lfp_submit( $u['captain_b'], 'update_team_profile', array( 'lf_team_id' => $team_a, 'lf_team_name' => 'X' ), 'valid', array( 'lf_team_logo' => lfp_file( 'logo.png', $png ) ) ), 'Logo upload for another team' );
	$team_before = lfp_snapshot( array( $team_a ) );
	lfp_expect( 'upload-error', lfp_submit( $u['captain_a'], 'update_team_profile', array( 'lf_team_id' => $team_a, 'lf_team_name' => 'Rejected logo rename', 'lf_team_description' => 'Rejected logo text', 'lf_short_name' => 'RLX' ), 'valid', array( 'lf_team_logo' => lfp_file( 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ) ) ), 'Own team logo SVG' );
	lfp_assert( $team_before === lfp_snapshot( array( $team_a ) ), 'Rejected team logo still saved the team name, description or short name.' );
	lfp_assert( $attachments === lfp_count( 'attachment' ) && $thumb === get_post_thumbnail_id( $p_p ) && ! get_post_thumbnail_id( $team_a ), 'Rejected upload created an attachment or thumbnail.' );
	$uploads = wp_upload_dir(); $stray = array();
	foreach ( array( 'php', 'svg', 'html' ) as $ext ) { $stray = array_merge( $stray, (array) glob( trailingslashit( $uploads['path'] ) . 'avatar*.' . $ext ) ); }
	lfp_assert( empty( $stray ), 'Rejected executable/markup upload reached the uploads directory.' );
};

$tests['POR-07 captain cannot bind or promote other accounts'] = static function() use ( $u, $team_a, $admin_id ) {
	foreach ( array( 'administrator' => array( $admin_id, 'test@example.test' ), 'editor' => array( $u['editor_victim'], 'portal_editor_victim@example.test' ), 'subscriber' => array( $u['invitee'], 'portal_invitee@example.test' ) ) as $kind => $target ) {
		$roles = get_userdata( $target[0] )->roles;
		lfp_expect( 'player-added', lfp_submit( $u['captain_a'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => $target[1], 'lf_player_name' => "Invited $kind" ) ), "Captain invites existing $kind email" );
		clean_user_cache( $target[0] );
		lfp_assert( $roles === get_userdata( $target[0] )->roles, "Captain changed the roles of an existing $kind account." );
		$linked = get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_user_id', 'meta_value' => $target[0] ) );
		lfp_assert( empty( $linked ), "Captain bound an existing $kind account to a captain-authored player." );
	}
	// Account managers keep the guarded link path; an unchanged existing link is preserved.
	$admin_player = lfp_post( 'lf_player', 'Portal Admin Player', 'private', $admin_id );
	update_post_meta( $admin_player, 'lf_user_id', $admin_id ); update_post_meta( $admin_player, 'lf_email', 'test@example.test' ); \LeagueFlow\assign_player_to_team( $admin_player, $team_a );
	lfp_expect( 'roster-saved', lfp_submit( $admin_id, 'update_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $admin_player, 'lf_email' => 'test@example.test', 'lf_jersey_number' => '1' ) ), 'Administrator re-saving own linked entry' );
	lfp_assert( $admin_id === (int) get_post_meta( $admin_player, 'lf_user_id', true ), 'Unchanged administrator link was removed.' );
	$managed = lfp_user( 'portal_managed', 'subscriber' );
	lfp_expect( 'player-added', lfp_submit( $admin_id, 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => 'portal_managed@example.test', 'lf_player_name' => 'Portal Managed' ) ), 'Administrator linking an ordinary account' );
	$linked = get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_user_id', 'meta_value' => $managed ) );
	clean_user_cache( $managed );
	lfp_assert( 1 === count( $linked ) && in_array( 'leagueflow_player', get_userdata( $managed )->roles, true ), 'Account manager could not link an eligible account.' );
	// The invited account still claims its own roster entry when it signs in to the portal.
	wp_set_current_user( $u['invitee'] );
	$portal = new LFP_Portal( new \LeagueFlow\Renderer( new \LeagueFlow\Standings_Service(), new \LeagueFlow\Knockout_Service(), new \LeagueFlow\Sports_Manager() ) );
	$html = $portal->render_portal();
	$claimed = get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_user_id', 'meta_value' => $u['invitee'] ) );
	lfp_assert( 1 === count( $claimed ) && \LeagueFlow\player_has_team( $claimed[0], $team_a ) && in_array( 'leagueflow_player', get_userdata( $u['invitee'] )->roles, true ) && '' !== $html, 'Invited account could not claim its own roster entry.' );
};

$tests['POR-08 captain cannot learn names or hand entries to privileged accounts'] = static function() use ( $u, $team_a, $admin_id ) {
	$editor = get_userdata( $u['editor_victim'] ); $players = lfp_count( 'lf_player' );
	lfp_expect( 'invalid-request', lfp_submit( $u['captain_a'], 'add_player', array( 'lf_team_id' => $team_a, 'lf_email' => $editor->user_email, 'lf_player_name' => '' ) ), 'Captain adding an existing account email without a name' );
	lfp_assert( $players === lfp_count( 'lf_player' ) && ! get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'title' => $editor->display_name, 'fields' => 'ids' ) ), 'Captain stored another account display name.' );
	$portal = new LFP_Portal( new \LeagueFlow\Renderer( new \LeagueFlow\Standings_Service(), new \LeagueFlow\Knockout_Service(), new \LeagueFlow\Sports_Manager() ) );
	$claims = static function( $user_id ) { return get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_user_id', 'meta_value' => $user_id ) ); };
	// Entry for the editor email created earlier by captain A (POR-07): the editor must not claim it.
	wp_set_current_user( $editor->ID ); $portal->render_portal(); clean_user_cache( $editor->ID );
	lfp_assert( ! $claims( $editor->ID ) && array( 'editor' ) === get_userdata( $editor->ID )->roles, 'Editor claimed a captain-created entry at portal sign-in.' );
	// An entry authored by an account manager is still claimed by the matching privileged account.
	wp_set_current_user( $admin_id );
	$entry = lfp_post( 'lf_player', 'Portal Admin-made Editor Entry', 'private', $admin_id );
	foreach ( get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_email', 'meta_value' => $editor->user_email ) ) as $old ) { delete_post_meta( $old, 'lf_email' ); }
	update_post_meta( $entry, 'lf_email', $editor->user_email );
	wp_set_current_user( $editor->ID ); $portal->render_portal();
	lfp_assert( array( $entry ) === array_map( 'intval', $claims( $editor->ID ) ), 'Editor could not claim an entry created by an account manager.' );
	wp_set_current_user( $admin_id ); delete_post_meta( $entry, 'lf_user_id' ); delete_post_meta( $entry, 'lf_email' );
};

$tests['POR-09 team creation and onboarding permission/validation'] = static function() use ( $u, $sport, $level, $team_a, $team_b, $team_c, $team_rec, $team_draft, $page ) {
	$creator = lfp_user( 'portal_creator', 'subscriber' ); $newbie = lfp_user( 'portal_newbie', 'subscriber' );
	$blocked = wp_insert_user( array( 'user_login' => 'portal_blocked', 'user_pass' => wp_generate_password(), 'user_email' => 'portal_blocked@blocked.invalid', 'role' => 'subscriber' ) );
	$state = static function() use ( $creator, $newbie ) { clean_user_cache( $creator ); clean_user_cache( $newbie ); return array( lfp_count( 'lf_team' ), lfp_count( 'lf_player' ), lfp_count( 'lf_join_request' ), lfp_count( 'attachment' ), get_userdata( $creator )->roles, get_userdata( $creator )->display_name, get_userdata( $newbie )->roles, get_userdata( $newbie )->display_name, count( $GLOBALS['lfp_mail'] ) ); };
	$team_fields = array( 'lf_sport_slug' => 'soccer', 'lf_league_level_id' => $level['portal-competitive'], 'lf_team_name' => 'Portal Created Team', 'lf_captain_name' => 'Portal Creator Person', 'lf_short_name' => 'PCT' );
	$before = $state();
	$settings = get_option( 'leagueflow_settings' );
	update_option( 'leagueflow_settings', array_merge( $settings, array( 'captain_registration_open' => 0 ) ) );
	try { foreach ( array( 'create_team', 'complete_captain_onboarding' ) as $action ) { lfp_expect( 'registration-closed', lfp_submit( $creator, $action, $team_fields ), "$action while captain registration is closed" ); } }
	finally { update_option( 'leagueflow_settings', $settings ); }
	lfp_expect( 'access-denied', lfp_submit( $blocked, 'create_team', $team_fields ), 'Team creation by a non-registration email' );
	foreach ( array(
		'unknown sport' => array( 'lf_sport_slug' => 'quidditch' ), 'disabled sport' => array( 'lf_sport_slug' => 'hockey' ), 'empty team name' => array( 'lf_team_name' => '  ' ),
		'empty captain name' => array( 'lf_captain_name' => '' ), 'login-code captain name' => array( 'lf_captain_name' => 'portal_creator' ), 'missing level' => array( 'lf_league_level_id' => 0 ),
		'nonexistent level' => array( 'lf_league_level_id' => 999999 ), 'sport term as level' => array( 'lf_league_level_id' => $sport['soccer'] ),
	) as $label => $override ) { lfp_expect( 'invalid-request', lfp_submit( $creator, 'create_team', array_merge( $team_fields, $override ) ), "Team creation with $label" ); }
	lfp_expect( 'team-exists', lfp_submit( $creator, 'create_team', array_merge( $team_fields, array( 'lf_team_name' => 'Portal Team A' ) ) ), 'Duplicate team name in sport' );
	lfp_expect( 'captain-sport-exists', lfp_submit( $u['captain_a'], 'create_team', array_merge( $team_fields, array( 'lf_team_name' => 'Portal Second Soccer Team' ) ) ), 'Captain creating a second team in a managed sport' );
	lfp_expect( 'player-sport-exists', lfp_submit( $u['player_q'], 'create_team', array_merge( $team_fields, array( 'lf_team_name' => 'Portal Player Q Team', 'lf_captain_name' => 'Portal Player Q' ) ) ), 'Rostered player creating a team in the same sport' );
	lfp_expect( 'upload-error', lfp_submit( $creator, 'create_team', $team_fields, 'valid', array( 'lf_team_logo' => lfp_file( 'logo.jpg', '<?php echo 1; ?>' ) ) ), 'Team creation with a rejected logo' );
	lfp_assert( $before === $state() && ! get_posts( array( 'post_type' => 'lf_team', 'post_status' => 'any', 'title' => 'Portal Created Team', 'fields' => 'ids' ) ), 'Rejected team creation left a team, player, role, display name, attachment or mail.' );
	foreach ( array( 'missing', 'invalid', 'wrong-action' ) as $mode ) { lfp_expect( 'invalid-request', lfp_submit( $creator, 'create_team', $team_fields, $mode ), "Team creation nonce mode $mode" ); }

	// Player onboarding: validation and rollback before any join request or notification exists.
	$join = array( 'lf_player_name' => 'Portal Newbie Person', 'lf_player_sports' => array( 'soccer' ), 'lf_player_sport_levels' => array( 'soccer' => $level['portal-competitive'] ), 'lf_player_team_choices' => array( 'soccer' => $team_a ) );
	update_option( 'leagueflow_settings', array_merge( $settings, array( 'player_registration_open' => 0 ) ) );
	try { lfp_expect( 'registration-closed', lfp_submit( $newbie, 'complete_player_onboarding', $join ), 'Player onboarding while registration is closed' ); }
	finally { update_option( 'leagueflow_settings', $settings ); }
	foreach ( array(
		'name-setup-required' => array( 'empty name' => array( 'lf_player_name' => '' ), 'login-code name' => array( 'lf_player_name' => 'portal_newbie' ) ),
		'sport-level-required' => array( 'no sport' => array( 'lf_player_sports' => array() ), 'disabled sport' => array( 'lf_player_sports' => array( 'hockey' ) ) ),
		'invalid-request' => array( 'level outside the taxonomy' => array( 'lf_player_sport_levels' => array( 'soccer' => 999999 ) ) ),
		'sport-request-required' => array( 'no team choice' => array( 'lf_player_team_choices' => array() ) ),
		'team-level-mismatch' => array( 'team at another level' => array( 'lf_player_team_choices' => array( 'soccer' => $team_rec ) ), 'team in another sport' => array( 'lf_player_team_choices' => array( 'soccer' => $team_c ) ), 'draft team' => array( 'lf_player_team_choices' => array( 'soccer' => $team_draft ) ), 'page ID as team' => array( 'lf_player_team_choices' => array( 'soccer' => $page ) ), 'nonexistent team' => array( 'lf_player_team_choices' => array( 'soccer' => 999999 ) ) ),
	) as $notice => $cases ) { foreach ( $cases as $label => $override ) { lfp_expect( $notice, lfp_submit( $newbie, 'complete_player_onboarding', array_merge( $join, $override ) ), "Player onboarding with $label" ); } }
	lfp_expect( 'upload-error', lfp_submit( $newbie, 'complete_player_onboarding', $join, 'valid', array( 'lf_player_photo' => lfp_file( 'avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ) ) ), 'Player onboarding with a rejected photo' );
	lfp_assert( $before === $state(), 'Rejected player onboarding left a player, join request, role, display name, attachment or mail.' );

	// Positive anchor: the same valid team submission without a file creates exactly one owned team.
	lfp_expect( 'team-registered', lfp_submit( $creator, 'create_team', $team_fields ), 'Valid team creation' );
	$created = get_posts( array( 'post_type' => 'lf_team', 'post_status' => 'publish', 'title' => 'Portal Created Team', 'fields' => 'ids' ) ); clean_user_cache( $creator );
	lfp_assert( 1 === count( $created ) && array( $creator ) === array_map( 'intval', (array) get_post_meta( $created[0], 'lf_manager_user_ids', true ) ) && in_array( 'leagueflow_team_manager', get_userdata( $creator )->roles, true ) && 'soccer' === \LeagueFlow\get_post_primary_term_slug( $created[0], 'lf_sport' ), 'Valid team creation did not produce one owned soccer team.' );
	lfp_expect( 'team-denied', lfp_submit( $creator, 'update_team_profile', array( 'lf_team_id' => $team_b, 'lf_team_name' => 'Taken over' ) ), 'New captain editing another team' );
	lfp_expect( 'captain-sport-exists', lfp_submit( $creator, 'create_team', array_merge( $team_fields, array( 'lf_team_name' => 'Portal Created Team Two' ) ) ), 'New captain repeating the same sport' );
};

$failures = array();
foreach ( $tests as $name => $test ) {
	$_POST = array(); $_REQUEST = array(); $_FILES = array(); wp_set_current_user( $admin_id );
	try { $test(); WP_CLI::log( 'PASS ' . $name ); }
	catch ( Throwable $error ) { $failures[] = $name . ': ' . $error->getMessage(); WP_CLI::warning( end( $failures ) ); }
}
wp_set_current_user( $admin_id ); $_POST = array(); $_REQUEST = array(); $_FILES = array();
if ( $failures ) { WP_CLI::error( implode( "\n", $failures ) ); }
lfp_assert( $GLOBALS['lfp_assertions'] > 150, 'Portal regression assertions were unexpectedly skipped.' );
WP_CLI::success( count( $tests ) . ' native portal groups pass; ' . $GLOBALS['lfp_assertions'] . ' assertions. WordPress ' . get_bloginfo( 'version' ) );
