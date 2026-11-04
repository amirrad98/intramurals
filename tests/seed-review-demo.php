<?php
/** Synthetic, repeatable visual-review data. Never use on an existing website. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}
foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => array( 'publish', 'draft', 'private' ), 'meta_key' => '_leagueflow_review_demo', 'meta_value' => '1', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
update_option( 'timezone_string', 'America/Vancouver' );
$sports = new \LeagueFlow\Sports_Manager();
$sports->update_enabled_sports( array_unique( array_merge( $sports->get_enabled_sport_slugs(), array( 'soccer', 'volleyball', 'basketball' ) ) ) );
$sports->ensure_enabled_terms();
$review_terms = array();
foreach ( array( 'soccer', 'volleyball', 'basketball' ) as $sport ) {
	$term = get_term_by( 'slug', 'review-' . $sport, 'lf_competition' );
	if ( ! $term ) { $insert = wp_insert_term( ucfirst( $sport ) . ' · Fall 2026 review', 'lf_competition', array( 'slug' => 'review-' . $sport ) ); $term = get_term( $insert['term_id'], 'lf_competition' ); }
	update_term_meta( $term->term_id, 'lf_sport_slug', $sport );
	$review_terms[ $sport ] = $term->term_id;
}
function lf_review_post( $type, $title, $sport = '', $meta = array(), $content = '' ) {
	global $review_terms;
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $content ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	update_post_meta( $id, '_leagueflow_review_demo', '1' );
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	if ( $sport ) {
		wp_set_object_terms( $id, $sport, 'lf_sport' );
		wp_set_object_terms( $id, array( (int) $review_terms[ $sport ] ), 'lf_competition' );
		wp_set_object_terms( $id, 'recreational', 'lf_league_level' );
	}
	return (int) $id;
}
$teams = array();
foreach ( array( 'soccer' => array( 'Campus United', 'Northern Strikers', 'Spruce City FC', 'Timberwolves' ), 'volleyball' => array( 'Northern Lights', 'Block Party', 'Set for Life', 'The Howlers' ), 'basketball' => array( 'Campus Hoops', 'Northern Nets' ) ) as $sport => $names ) {
	foreach ( $names as $name ) { $teams[ $sport ][] = lf_review_post( 'lf_team', $name, $sport ); }
}
$match = static function( $sport, $date, $index = 0, $extra = array() ) use ( $teams ) {
	$home = $teams[ $sport ][ $index % count( $teams[ $sport ] ) ];
	$away = $teams[ $sport ][ ( $index + 1 ) % count( $teams[ $sport ] ) ];
	return lf_review_post( 'lf_match', get_the_title( $home ) . ' vs ' . get_the_title( $away ), $sport, array_merge( array( 'lf_match_datetime' => $date, 'lf_home_team_id' => $home, 'lf_away_team_id' => $away, 'lf_status' => 'scheduled', 'lf_venue' => 'Northern Sport Centre · Court ' . ( 1 + $index % 3 ), 'lf_duration_minutes' => 60 ), $extra ) );
};
for ( $i = 0; $i < 115; $i++ ) { $match( 'soccer', sprintf( '2026-09-%02d %02d:00', 1 + $i % 28, 16 + $i % 5 ), $i, array( 'lf_status' => 'finished', 'lf_home_score' => 2, 'lf_away_score' => 1 ) ); }
foreach ( array( '2026-11-29', '2026-12-01', '2026-12-06', '2026-12-07' ) as $day ) {
	for ( $i = 0; $i < 4; $i++ ) { $match( 'soccer', $day . sprintf( ' %02d:30', 17 + $i ), $i ); }
}
$today = current_datetime()->format( 'Y-m-d' );
foreach ( array( $today, current_datetime()->modify( '+1 day' )->format( 'Y-m-d' ), '2026-11-29', '2026-12-07' ) as $day ) {
	$match( 'volleyball', $day . ' 18:30', 0 );
	$match( 'volleyball', $day . ' 18:30', 2 );
	$match( 'basketball', $day . ' 19:00', 0 );
}
$drop_in = static function( $title, $sport, $start, $end, $kind = 'drop_in' ) {
	return lf_review_post( 'lf_calendar_event', $title, $sport, array( 'lf_event_start_datetime' => $start, 'lf_event_end_datetime' => $end, 'lf_event_type' => $kind, 'lf_event_status' => 'scheduled', 'lf_event_venue' => 'Northern Sport Centre', 'lf_event_cost' => '$5 · free for students', 'lf_event_registration_required' => false ), 'Synthetic review fixture. Bring indoor shoes and a water bottle. All experience levels are welcome.' );
};
$drop_in( 'Open gym · Drop-in basketball', 'basketball', $today . ' 12:00', $today . ' 14:00' );
$drop_in( 'Sunday soccer drop-in', 'soccer', '2026-11-29 13:00', '2026-11-29 15:00' );
$drop_in( 'Campus recreation tournament', 'volleyball', '2026-11-30 16:00', '2026-12-02 18:00', 'tournament' );
$main = lf_review_post( 'page', 'LeagueFlow calendar review', '', array(), '<!-- wp:heading --><h2 class="wp-block-heading">Campus recreation schedule</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Synthetic review data. Use the date picker to inspect November 29 through December 7, 2026. More than 100 earlier matches are present to exercise pagination.</p><!-- /wp:paragraph --><!-- wp:leagueflow/match-calendar {"season":"all","month_display":"sidebar","sidebar_position":"right"} /-->' );
$layouts = lf_review_post( 'page', 'LeagueFlow calendar layouts', '', array(), '<!-- wp:heading --><h2 class="wp-block-heading">Popover details</h2><!-- /wp:heading --><!-- wp:leagueflow/match-calendar {"season":"all","month_display":"popover","show_cost":true} /--><!-- wp:heading --><h2 class="wp-block-heading">Expandable day details</h2><!-- /wp:heading --><!-- wp:leagueflow/match-calendar {"season":"all","month_display":"dropdown","show_cost":false} /--><!-- wp:heading --><h2 class="wp-block-heading">Sidebar on the left</h2><!-- /wp:heading --><!-- wp:leagueflow/match-calendar {"season":"all","month_display":"sidebar","sidebar_position":"left"} /-->' );
$list = lf_review_post( 'page', 'LeagueFlow upcoming events', '', array(), '<!-- wp:leagueflow/match-calendar {"season":"all","view":"list","list_initial":5,"list_more":5,"limit":10,"show_cost":true} /-->' );
WP_CLI::log( wp_json_encode( array( 'calendar_page' => $main, 'layouts_page' => $layouts, 'list_page' => $list, 'teams' => $teams, 'urls' => array( get_permalink( $main ), get_permalink( $layouts ), get_permalink( $list ) ) ), JSON_PRETTY_PRINT ) );
WP_CLI::success( 'Synthetic calendar review data is ready.' );
