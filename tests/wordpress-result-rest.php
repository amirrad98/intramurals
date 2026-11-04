<?php
/** Generic REST writes cannot bypass the protected match-result workflow. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Run only on the disposable leagueflow.test site.' );
}
$GLOBALS['lfrr_checks'] = 0;
function lfrr_check( $ok, $message ) { ++$GLOBALS['lfrr_checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
$old_user = get_current_user_id(); $supports = post_type_supports( 'lf_match', 'custom-fields' ); $id = 0;
add_filter( 'pre_http_request', static function() { return new WP_Error( 'offline', 'Test blocks outbound HTTP.' ); }, PHP_INT_MAX );
try {
	wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
	$id = wp_insert_post( array( 'post_type' => 'lf_match', 'post_title' => 'Protected result', 'post_status' => 'publish' ) );
	update_post_meta( $id, 'lf_status', 'scheduled' );
	add_post_type_support( 'lf_match', 'custom-fields' );
	$request = function( $data, $target = null ) use ( $id ) {
		$req = new WP_REST_Request( 'POST', '/wp/v2/lf_match' . ( false === $target ? '' : '/' . $id ) );
		$req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( $data ) );
		return rest_do_request( $req );
	};
	foreach ( array( array( 'meta' => array( 'lf_home_score' => '1', 'lf_away_score' => '0' ) ), array( 'meta' => array( 'lf_status' => 'finished' ) ), array( 'meta' => array( 'lf_home_team_id' => 0 ) ), array( 'meta' => array( 'lf_next_match_id' => 0 ) ), array( 'meta' => array( 'lf_match_datetime' => '2031-01-01 18:00' ) ), array( 'meta' => array( 'lf_duration_minutes' => 240 ) ), array( 'lf_sport' => array() ), array( 'lf_competition' => array() ), array( 'lf_season' => array() ), array( 'lf_league_level' => array() ) ) as $data ) {
		$response = $request( $data + array( 'title' => 'Must not partially save' ) );
		lfrr_check( 400 === $response->get_status() && 'leagueflow_match_workflow_required' === $response->get_data()['code'], 'Unsafe generic result/context write is refused clearly.' );
		lfrr_check( 'Protected result' === get_the_title( $id ) && 'scheduled' === get_post_meta( $id, 'lf_status', true ) && ! metadata_exists( 'post', $id, 'lf_home_score' ), 'Rejected REST request leaves title, status and scores unchanged.' );
	}
	global $wpdb;
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_match'" );
	$response = $request( array( 'title' => 'Orphan result', 'meta' => array( 'lf_status' => 'finished' ) ), false );
	lfrr_check( 400 === $response->get_status() && $count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='lf_match'" ), 'Rejected result creation leaves no orphan match.' );
	$response = $request( array( 'title' => 'Permitted match description' ) );
	lfrr_check( 200 === $response->get_status() && 'Permitted match description' === get_the_title( $id ), 'Ordinary permitted post editing remains available.' );
	WP_CLI::success( 'REST result workflow: ' . $GLOBALS['lfrr_checks'] . ' assertions passed.' );
} finally {
	if ( $id ) { wp_delete_post( $id, true ); }
	if ( ! $supports ) { remove_post_type_support( 'lf_match', 'custom-fields' ); }
	wp_set_current_user( $old_user );
}
