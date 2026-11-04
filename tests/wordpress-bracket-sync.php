<?php
/** Committed result propagation and protected bracket corrections on disposable WordPress. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Run only on the disposable leagueflow.test site.' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'pre_http_request', static function() { return new WP_Error( 'offline', 'Tests block outbound HTTP.' ); }, PHP_INT_MAX );
$GLOBALS['lfbs_checks'] = 0;
function lfbs_check( $condition, $message ) {
	++$GLOBALS['lfbs_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$lfbs_posts = array(); $lfbs_users = array(); $lfbs_terms = array(); $lfbs_other_terms = array();
$lfbs_old_user = get_current_user_id(); $lfbs_old_post = $_POST;
$lfbs_admin = get_user_by( 'login', 'admin' )->ID;
try {
	wp_set_current_user( $lfbs_admin ); $_POST = array();
	$term = wp_insert_term( 'Bracket sync ' . wp_generate_uuid4(), 'lf_sport' );
	lfbs_check( ! is_wp_error( $term ), 'Create isolated bracket sport.' );
	$sport = (int) $term['term_id']; $lfbs_terms[] = $sport;
	$post = function( $type, $title, $meta = array() ) use ( &$lfbs_posts, $sport ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish' ), true );
		lfbs_check( ! is_wp_error( $id ), 'Create bracket fixture.' ); $lfbs_posts[] = $id;
		wp_set_object_terms( $id, array( $sport ), 'lf_sport' );
		foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
		return $id;
	};
	$a = $post( 'lf_team', 'Bracket Falcons' ); $b = $post( 'lf_team', 'Bracket Bears' );
	$c = $post( 'lf_team', 'Bracket Ravens' );
	$target_meta = array( 'lf_is_knockout' => 1, 'lf_status' => 'scheduled', 'lf_round_order' => 2, 'lf_home_team_id' => 0, 'lf_away_team_id' => $c );
	$target = $post( 'lf_match', 'Final awaiting winner', $target_meta );
	$source = $post( 'lf_match', 'Semifinal', array( 'lf_is_knockout' => 1, 'lf_status' => 'scheduled', 'lf_round_order' => 1, 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_next_match_id' => $target, 'lf_next_match_slot' => 'home' ) );
	$service = new \LeagueFlow\Knockout_Service();

	// wp_after_insert_post sees all meta_input fields, even when status is first.
	wp_update_post( array( 'ID' => $source, 'meta_input' => array( 'lf_status' => 'finished', 'lf_home_score' => '3', 'lf_away_score' => '1' ) ) );
	lfbs_check( $a === (int) get_post_meta( $target, 'lf_home_team_id', true ) && $source === (int) get_post_meta( $target, '_lf_home_advanced_from', true ), 'Complete native post save advances the actual winner.' );
	lfbs_check( false !== strpos( get_the_title( $target ), 'Bracket Falcons' ), 'Destination title follows its participants.' );
	update_post_meta( $target, 'lf_away_team_id', $a );
	do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( $a === (int) get_post_meta( $target, 'lf_home_team_id', true ) && $a === (int) get_post_meta( $target, 'lf_away_team_id', true ) && '' !== get_post_meta( $source, '_lf_bracket_sync_notice', true ), 'An already-advanced winner cannot silently validate a target where both participants are the same team.' );
	update_post_meta( $target, 'lf_away_team_id', $c );
	update_post_meta( $target, 'lf_status', 'live' );
	do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( $a === (int) get_post_meta( $target, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $source, '_lf_bracket_sync_notice' ), 'An unchanged, correctly advanced winner in a started target causes no false conflict warning.' );
	update_post_meta( $target, 'lf_status', 'scheduled' );
	update_post_meta( $source, 'lf_winner_team_id', $a );
	update_post_meta( $source, 'lf_outcome', 'double_forfeit' );
	lfbs_check( 0 === $service->determine_winner_team_id( $source ), 'An explicit double forfeit takes precedence over a stale winner override.' );
	do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( 0 === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'A double forfeit retracts its previous unstarted advancement.' );
	update_post_meta( $source, 'lf_outcome', 'unrecognized_result' );
	lfbs_check( 0 === $service->determine_winner_team_id( $source ) && 0 === $service->advance_winner( $source ), 'An unknown imported outcome never supplies a winner through an override or scores.' );
	delete_post_meta( $source, 'lf_outcome' ); delete_post_meta( $source, 'lf_winner_team_id' );
	do_action( 'leagueflow_match_result_saved', $source );
	update_post_meta( $source, 'lf_home_score', '1' ); update_post_meta( $source, 'lf_away_score', '4' );
	$source_form = array( 'leagueflow_match_nonce' => wp_create_nonce( 'leagueflow_save_match' ), 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_home_score' => '1', 'lf_away_score' => '4', 'lf_status' => 'finished', 'lf_is_knockout' => '1', 'lf_round_order' => '1' );
	$_POST = $source_form;
	try { do_action( 'leagueflow_match_result_saved', $source ); }
	finally { lfbs_check( $source_form === $_POST, 'Destination title refresh restores the source request body.' ); $_POST = array(); }
	lfbs_check( $b === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Correcting Finished scores updates an owned unstarted slot.' );
	lfbs_check( 'scheduled' === get_post_meta( $target, 'lf_status', true ) && '' === get_post_meta( $target, 'lf_home_score', true ) && '' === get_post_meta( $target, 'lf_away_score', true ) && $c === (int) get_post_meta( $target, 'lf_away_team_id', true ) && 2 === (int) get_post_meta( $target, 'lf_round_order', true ), 'A source score form without post_ID is never replayed onto the target during title refresh.' );
	update_post_meta( $source, 'lf_status', 'scheduled' );
	do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( 0 === (int) get_post_meta( $target, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $target, '_lf_home_advanced_from' ), 'Reopening retracts the previous winner and its provenance.' );
	lfbs_check( $c === (int) get_post_meta( $target, 'lf_away_team_id', true ), 'Retraction preserves the other participant.' );
	update_post_meta( $source, 'lf_status', 'finished' ); do_action( 'leagueflow_match_result_saved', $source );
	wp_trash_post( $source );
	lfbs_check( 0 === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Trashing a result retracts its unplayed advancement.' );
	wp_untrash_post( $source ); do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( $b === (int) get_post_meta( $target, 'lf_home_team_id', true ), 'Restoring an editable result restores a compatible empty slot.' );

	// A linked destination change cleans up the old slot first.
	$replacement = $post( 'lf_match', 'Replacement final', $target_meta );
	update_post_meta( $source, 'lf_next_match_id', $replacement ); do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( 0 === (int) get_post_meta( $target, 'lf_home_team_id', true ) && $b === (int) get_post_meta( $replacement, 'lf_home_team_id', true ), 'Changing the link removes the obsolete participant and fills the new target.' );
	update_post_meta( $replacement, 'lf_home_score', '0' );
	update_post_meta( $source, 'lf_status', 'cancelled' ); do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( $b === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && '' !== get_post_meta( $source, '_lf_bracket_sync_notice', true ), 'A started downstream fixture is retained with a staff-review notice.' );
	delete_post_meta( $replacement, 'lf_home_score' ); do_action( 'leagueflow_match_result_saved', $source );
	lfbs_check( 0 === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $source, '_lf_bracket_sync_notice' ), 'A reviewed, unstarted target can be synchronized and clears its warning.' );

	// Participant + provenance writes are one transaction, including title failure.
	update_post_meta( $source, 'lf_status', 'finished' );
	$reject_origin = static function( $check, $id, $key ) use ( $replacement ) { return $id === $replacement && '_lf_home_advanced_from' === $key ? false : $check; };
	add_filter( 'add_post_metadata', $reject_origin, 10, 3 );
	try { lfbs_check( 0 === $service->advance_winner( $source ), 'A refused provenance write reports no advancement.' ); }
	finally { remove_filter( 'add_post_metadata', $reject_origin, 10 ); }
	lfbs_check( 0 === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $replacement, '_lf_home_advanced_from' ), 'Failed provenance rolls back the participant.' );
	$original_title = get_the_title( $replacement );
	$reject_title = static function( $data, $postarr ) use ( $replacement ) { if ( (int) ( $postarr['ID'] ?? 0 ) === $replacement ) { $data['post_title'] = 'Rejected title'; } return $data; };
	add_filter( 'wp_insert_post_data', $reject_title, 10, 2 );
	try { lfbs_check( 0 === $service->advance_winner( $source ), 'An altered destination title rejects the complete bracket write.' ); }
	finally { remove_filter( 'wp_insert_post_data', $reject_title, 10 ); }
	lfbs_check( $original_title === get_the_title( $replacement ) && 0 === (int) get_post_meta( $replacement, 'lf_home_team_id', true ), 'Title failure rolls back both post and slot metadata.' );
	$permission_revoked = false;
	$revoke_after_title = static function( $data, $postarr ) use ( $replacement, &$permission_revoked ) { if ( (int) ( $postarr['ID'] ?? 0 ) === $replacement ) { $permission_revoked = true; } return $data; };
	$deny_changed_target = static function( $caps, $required, $args ) use ( $replacement, &$permission_revoked ) {
		if ( $permission_revoked && 'edit_post' === $args[0] && $replacement === (int) ( $args[2] ?? 0 ) ) { foreach ( $required as $cap ) { $caps[ $cap ] = false; } }
		return $caps;
	};
	add_filter( 'wp_insert_post_data', $revoke_after_title, 10, 2 ); add_filter( 'user_has_cap', $deny_changed_target, 10, 3 );
	try { lfbs_check( 0 === $service->advance_winner( $source ), 'Permission revoked during the destination title write prevents a committed advancement.' ); }
	finally { remove_filter( 'wp_insert_post_data', $revoke_after_title, 10 ); remove_filter( 'user_has_cap', $deny_changed_target, 10 ); }
	lfbs_check( $permission_revoked && 0 === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $replacement, '_lf_home_advanced_from' ) && $original_title === get_the_title( $replacement ), 'Late permission revocation rolls back participant, provenance and title together.' );

	// Bracket service must not implicitly commit a transaction owned by its caller.
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		lfbs_check( $b === $service->advance_winner( $source ), 'Advancement works within an enclosing transaction.' );
	} finally { $wpdb->query( 'ROLLBACK' ); clean_post_cache( $replacement ); }
	lfbs_check( 0 === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $replacement, '_lf_home_advanced_from' ), 'Caller rollback still restores slot, provenance and title.' );

	// A referee publishes a source from the desk; the trusted committed action
	// fills only its configured matching public target, with no general edit grants.
	\LeagueFlow\Referee::ensure_roles();
	$ref = wp_insert_user( array( 'user_login' => 'lfbs_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'role' => 'leagueflow_referee' ) );
	lfbs_check( ! is_wp_error( $ref ), 'Create isolated referee.' ); $lfbs_users[] = $ref;
	update_post_meta( $source, 'lf_status', 'scheduled' );
	delete_post_meta( $source, 'lf_home_score' ); delete_post_meta( $source, 'lf_away_score' );
	add_post_meta( $source, \LeagueFlow\Referee::ASSIGNMENT_META, $ref ); update_post_meta( $source, \LeagueFlow\Referee::SCORE_META, 1 );
	wp_update_post( array( 'ID' => $source, 'post_status' => 'publish' ) );
	wp_set_current_user( $ref );
	lfbs_check( ! current_user_can( 'edit_post', $source ) && ! current_user_can( 'edit_post', $replacement ), 'Referee cannot edit either post directly.' );
	$referee = new \LeagueFlow\Referee();
	wp_set_current_user( $lfbs_admin );
	$wrong_competition = wp_insert_term( 'Referee wrong sport ' . wp_generate_uuid4(), 'lf_competition' );
	lfbs_check( ! is_wp_error( $wrong_competition ), 'Create a competition owned by another sport.' );
	$wrong_competition = (int) $wrong_competition['term_id']; $lfbs_other_terms['lf_competition'][] = $wrong_competition;
	update_term_meta( $wrong_competition, 'lf_sport_slug', 'different-sport' );
	wp_set_object_terms( $source, array( $wrong_competition ), 'lf_competition' );
	wp_set_current_user( $ref );
	lfbs_check( ! \LeagueFlow\Referee::can_access( $source, true ) && is_wp_error( $referee->submit_result( $source, '2', '1', \LeagueFlow\Result_Service::state_token( $source ) ) ), 'A competition owned by another sport blocks assigned referee finalization.' );
	wp_set_current_user( $lfbs_admin ); wp_set_object_terms( $source, array(), 'lf_competition' ); wp_set_current_user( $ref );
	lfbs_check( true === $referee->submit_result( $source, '2', '1', \LeagueFlow\Result_Service::state_token( $source ) ), 'Referee final submission succeeds.' );
	lfbs_check( $a === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && $source === (int) get_post_meta( $replacement, '_lf_home_advanced_from', true ), 'Committed referee result advances its winner automatically.' );
	lfbs_check( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_post', $replacement ) && 0 === $service->advance_winner( $source ), 'Automatic advancement does not grant reusable post-edit or service authority.' );
	$audits = get_post_meta( $source, \LeagueFlow\Referee::AUDIT_META, false ); $committed_audit = end( $audits );
	lfbs_check( isset( $committed_audit['result_state'] ) && hash_equals( \LeagueFlow\Referee::state_token( $source ), $committed_audit['result_state'] ), 'The referee audit binds the complete committed Finished state, including the configured bracket link.' );

	// A committed callback cannot be replayed after later edits with unchanged scores.
	wp_set_current_user( $lfbs_admin );
	$d = $post( 'lf_team', 'Bracket Wolves' );
	update_post_meta( $source, 'lf_home_team_id', $d );
	wp_set_current_user( $ref ); $service->handle_referee_result( $source, $ref );
	lfbs_check( $a === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $source, '_lf_bracket_sync_notice' ), 'An old audit cannot authorize a new participant with the same final scores.' );
	wp_set_current_user( $lfbs_admin ); update_post_meta( $source, 'lf_home_team_id', $a );
	$replay_target = $post( 'lf_match', 'Unrelated replay target', $target_meta );
	update_post_meta( $source, 'lf_next_match_id', $replay_target );
	wp_set_current_user( $ref ); $service->handle_referee_result( $source, $ref );
	lfbs_check( 0 === (int) get_post_meta( $replay_target, 'lf_home_team_id', true ) && $a === (int) get_post_meta( $replacement, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $source, '_lf_bracket_sync_notice' ), 'An old audit cannot authorize a different next match or retract the original slot.' );
	wp_set_current_user( $lfbs_admin ); update_post_meta( $source, 'lf_next_match_id', $replacement );

	// Team terms live outside the source state token, so validate their context again.
	$other_sport = wp_insert_term( 'Other bracket ' . wp_generate_uuid4(), 'lf_sport' );
	lfbs_check( ! is_wp_error( $other_sport ), 'Create a second sport for context checks.' ); $lfbs_terms[] = (int) $other_sport['term_id'];
	update_post_meta( $replacement, 'lf_home_team_id', 0 ); delete_post_meta( $replacement, '_lf_home_advanced_from' );
	wp_set_object_terms( $b, array( (int) $other_sport['term_id'] ), 'lf_sport' );
	wp_set_current_user( $ref ); $service->handle_referee_result( $source, $ref );
	lfbs_check( hash_equals( \LeagueFlow\Referee::state_token( $source ), $committed_audit['result_state'] ) && 0 === (int) get_post_meta( $replacement, 'lf_home_team_id', true ), 'A team moving to a different sport invalidates callback authority even when the source token is unchanged.' );
	wp_set_current_user( $lfbs_admin ); wp_set_object_terms( $b, array( $sport ), 'lf_sport' );
	wp_set_current_user( $ref ); $service->handle_referee_result( $source, $ref );
	lfbs_check( $a === (int) get_post_meta( $replacement, 'lf_home_team_id', true ), 'An unchanged committed result can safely retry its originally configured slot.' );

	$assigned_source = function( $title, $next, $slot = 'home' ) use ( $post, $a, $b, $ref ) {
		$id = $post( 'lf_match', $title, array( 'lf_is_knockout' => 1, 'lf_status' => 'scheduled', 'lf_round_order' => 1, 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_next_match_id' => $next, 'lf_next_match_slot' => $slot ) );
		add_post_meta( $id, \LeagueFlow\Referee::ASSIGNMENT_META, $ref ); update_post_meta( $id, \LeagueFlow\Referee::SCORE_META, 1 );
		return $id;
	};

	// Old provenance is not a grant for referees to edit unrelated historical matches.
	wp_set_current_user( $lfbs_admin );
	$current_target = $post( 'lf_match', 'Current assigned target', $target_meta );
	$historical_target = $post( 'lf_match', 'Former assigned target', $target_meta );
	$historical_source = $assigned_source( 'Retargeted assigned source', $current_target );
	update_post_meta( $historical_target, 'lf_home_team_id', $a ); update_post_meta( $historical_target, '_lf_home_advanced_from', $historical_source );
	wp_set_current_user( $ref );
	lfbs_check( true === $referee->submit_result( $historical_source, '2', '1', \LeagueFlow\Result_Service::state_token( $historical_source ) ), 'A valid assigned source still saves its final result when historical provenance needs review.' );
	lfbs_check( $a === (int) get_post_meta( $historical_target, 'lf_home_team_id', true ) && $historical_source === (int) get_post_meta( $historical_target, '_lf_home_advanced_from', true ) && 0 === (int) get_post_meta( $current_target, 'lf_home_team_id', true ) && '' !== get_post_meta( $historical_source, '_lf_bracket_sync_notice', true ), 'Referee synchronization preserves a former target and reports the conflict for staff.' );
	wp_set_current_user( $lfbs_admin ); $service->synchronize_result( $historical_source );
	lfbs_check( 0 === (int) get_post_meta( $historical_target, 'lf_home_team_id', true ) && $a === (int) get_post_meta( $current_target, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $historical_source, '_lf_bracket_sync_notice' ), 'Authorized staff can review and repair the obsolete feeder link.' );

	$changed_slot_target = $post( 'lf_match', 'Changed target slot', array_merge( $target_meta, array( 'lf_home_team_id' => $a, 'lf_away_team_id' => 0 ) ) );
	$changed_slot_source = $assigned_source( 'Assigned source with changed slot', $changed_slot_target, 'away' );
	update_post_meta( $changed_slot_target, '_lf_home_advanced_from', $changed_slot_source );
	wp_set_current_user( $ref );
	lfbs_check( true === $referee->submit_result( $changed_slot_source, '1', '2', \LeagueFlow\Result_Service::state_token( $changed_slot_source ) ) && $a === (int) get_post_meta( $changed_slot_target, 'lf_home_team_id', true ) && $changed_slot_source === (int) get_post_meta( $changed_slot_target, '_lf_home_advanced_from', true ) && 0 === (int) get_post_meta( $changed_slot_target, 'lf_away_team_id', true ) && '' !== get_post_meta( $changed_slot_source, '_lf_bracket_sync_notice', true ), 'A referee cannot retract a historical slot even when it belongs to the current target match.' );

	// Retraction also needs current matching context, even for the configured slot.
	wp_set_current_user( $lfbs_admin );
	$context_target = $post( 'lf_match', 'Target context changed', $target_meta );
	$context_source = $assigned_source( 'Assigned draw with changed target context', $context_target );
	update_post_meta( $context_target, 'lf_home_team_id', $a ); update_post_meta( $context_target, '_lf_home_advanced_from', $context_source );
	wp_set_object_terms( $context_target, array( (int) $other_sport['term_id'] ), 'lf_sport' );
	wp_set_current_user( $ref );
	lfbs_check( true === $referee->submit_result( $context_source, '1', '1', \LeagueFlow\Result_Service::state_token( $context_source ) ) && $a === (int) get_post_meta( $context_target, 'lf_home_team_id', true ) && $context_source === (int) get_post_meta( $context_target, '_lf_home_advanced_from', true ) && '' !== get_post_meta( $context_source, '_lf_bracket_sync_notice', true ), 'A tied referee result preserves prior provenance when the configured target context no longer matches.' );
	wp_set_current_user( $lfbs_admin ); wp_set_object_terms( $context_target, array( $sport ), 'lf_sport' );
	update_post_meta( $context_source, 'lf_status', 'scheduled' ); delete_post_meta( $context_source, 'lf_home_score' ); delete_post_meta( $context_source, 'lf_away_score' );
	wp_set_current_user( $ref );
	lfbs_check( true === $referee->submit_result( $context_source, '1', '1', \LeagueFlow\Result_Service::state_token( $context_source ) ) && 0 === (int) get_post_meta( $context_target, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $context_target, '_lf_home_advanced_from' ), 'A tied referee result can retract its configured unstarted slot once context matches.' );

	// Read-only graph validation may follow later rounds, without mutating them.
	wp_set_current_user( $lfbs_admin );
	$grand_final = $post( 'lf_match', 'Later final', array_merge( $target_meta, array( 'lf_round_order' => 3 ) ) );
	$semi_final = $post( 'lf_match', 'Direct semifinal', array_merge( $target_meta, array( 'lf_next_match_id' => $grand_final, 'lf_next_match_slot' => 'home' ) ) );
	$quarter_final = $assigned_source( 'Assigned quarterfinal', $semi_final );
	wp_set_current_user( $ref );
	lfbs_check( true === $referee->submit_result( $quarter_final, '3', '0', \LeagueFlow\Result_Service::state_token( $quarter_final ) ) && $a === (int) get_post_meta( $semi_final, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $quarter_final, '_lf_bracket_sync_notice' ), 'Referee advancement validates an entire multi-round graph and fills the direct next match.' );
	lfbs_check( 0 === (int) get_post_meta( $grand_final, 'lf_home_team_id', true ) && ! metadata_exists( 'post', $grand_final, '_lf_home_advanced_from' ) && ! current_user_can( 'edit_post', $semi_final ) && ! current_user_can( 'edit_post', $grand_final ), 'Forward graph inspection does not grant edits or propagate into an unplayed later round.' );

	wp_set_current_user( $lfbs_admin );
	wp_set_object_terms( $b, array( (int) $other_sport['term_id'] ), 'lf_sport' );
	update_post_meta( $source, 'lf_status', 'scheduled' );
	wp_set_current_user( $ref );
	lfbs_check( ! \LeagueFlow\Referee::can_access( $source, true ) && is_wp_error( $referee->submit_result( $source, '7', '0', \LeagueFlow\Result_Service::state_token( $source ) ) ), 'Mismatched participant sport blocks referee scoring.' );

	WP_CLI::success( 'Bracket synchronization: ' . $GLOBALS['lfbs_checks'] . ' assertions passed.' );
} finally {
	wp_set_current_user( $lfbs_admin ); $_POST = array();
	foreach ( array_reverse( $lfbs_posts ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfbs_users as $id ) { wp_delete_user( $id ); }
	foreach ( $lfbs_terms as $id ) { wp_delete_term( $id, 'lf_sport' ); }
	foreach ( $lfbs_other_terms as $taxonomy => $ids ) { foreach ( $ids as $id ) { wp_delete_term( $id, $taxonomy ); } }
	$_POST = $lfbs_old_post; wp_set_current_user( $lfbs_old_user );
}
