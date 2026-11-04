<?php
/** Public result display and feed consistency, on a disposable native site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

class LFRD_Renderer extends \LeagueFlow\Renderer {
	public function template_for_test( $file, $data ) { return $this->render_template( $file, $data ); }
}
class LFRD_Knockout extends \LeagueFlow\Knockout_Service {
	public function display_for_test( $id ) { return $this->format_bracket_match( get_post( $id ) ); }
}
$GLOBALS['lfrd_checks'] = 0;
function lfrd_check( $condition, $message ) {
	++$GLOBALS['lfrd_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfrd_feed( $competition, $route = 'match-results' ) {
	$request = new WP_REST_Request( 'GET', '/leagueflow/v1/' . $route );
	$request->set_query_params( array( 'competition' => $competition, 'per_page' => 100, 'view' => 'all' ) );
	$response = rest_do_request( $request );
	lfrd_check( 200 === $response->get_status(), 'Public result display fixtures are available through the native REST route.' );
	return array_column( $response->get_data()[ 'match-results' === $route ? 'results' : 'events' ], null, 'matchId' );
}

$old_user = get_current_user_id();
$old_post = $_POST;
$old_get = $_GET;
$posts = array();
$terms = array();
$admin_id = (int) get_user_by( 'login', 'admin' )->ID;
$sports = new \LeagueFlow\Sports_Manager();
$knockout = new LFRD_Knockout();
$renderer = new LFRD_Renderer( new \LeagueFlow\Standings_Service(), $knockout, $sports );
$prefix = 'lfrd-' . strtolower( wp_generate_password( 8, false, false ) );
$post = static function( $type, $title, $context, $meta = array() ) use ( &$posts, $admin_id ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $admin_id ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$posts[] = (int) $id;
	foreach ( $context as $taxonomy => $term_id ) { wp_set_object_terms( $id, array( $term_id ), $taxonomy ); }
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
};

try {
	wp_set_current_user( $admin_id );
	$_POST = array();
	$_GET = array();
	$context = array();
	foreach ( array( 'lf_sport', 'lf_competition', 'lf_season', 'lf_league_level' ) as $taxonomy ) {
		$term = wp_insert_term( $prefix . ' ' . $taxonomy, $taxonomy );
		if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
		$context[ $taxonomy ] = (int) $term['term_id'];
		$terms[] = array( (int) $term['term_id'], $taxonomy );
	}
	$home = $post( 'lf_team', 'Display Home', $context );
	$away = $post( 'lf_team', 'Display Away', $context );
	$make = static function( $meta = array() ) use ( $post, $context, $home, $away ) {
		return $post( 'lf_match', 'Display Home vs Display Away', $context, array_merge( array(
			'lf_home_team_id' => $home, 'lf_away_team_id' => $away, 'lf_status' => 'finished', 'lf_match_datetime' => '2038-04-20 18:30',
		), $meta ) );
	};
	$zero = $make( array( 'lf_home_score' => '000', 'lf_away_score' => '0' ) );
	$bad = $make( array( 'lf_is_knockout' => 1, 'lf_home_score' => '-1', 'lf_away_score' => '0' ) );
	$forfeits = array();
	foreach ( array( 'forfeit_home' => 'Home team forfeited', 'forfeit_away' => 'Away team forfeited', 'double_forfeit' => 'Double forfeit' ) as $outcome => $label ) {
		$forfeits[ $outcome ] = array( 'id' => $make( array( 'lf_is_knockout' => 1, 'lf_home_score' => '91', 'lf_away_score' => '88', 'lf_outcome' => $outcome, 'lf_winner_team_id' => 'forfeit_away' === $outcome ? $away : $home ) ), 'label' => $label );
	}
	$tied = $make( array( 'lf_is_knockout' => 1, 'lf_home_score' => '2', 'lf_away_score' => '2', 'lf_winner_team_id' => $away ) );
	$unknown = $make( array( 'lf_is_knockout' => 1, 'lf_home_score' => '12', 'lf_away_score' => '4', 'lf_outcome' => 'invalid-import', 'lf_winner_team_id' => $home ) );
	$duplicate_team = $make( array( 'lf_home_score' => '3', 'lf_away_score' => '0', 'lf_away_team_id' => $home, 'lf_winner_team_id' => $home ) );
	$waiting = $make( array( 'lf_is_knockout' => 1, 'lf_home_team_id' => 0, 'lf_away_team_id' => $away, 'lf_status' => 'scheduled', 'lf_round_label' => 'Final' ) );
	$bye = $make( array( 'lf_is_knockout' => 1, 'lf_home_team_id' => 0, 'lf_away_team_id' => $away, 'lf_winner_team_id' => $away ) );
	update_post_meta( $tied, 'lf_next_match_id', $waiting );
	update_post_meta( $tied, 'lf_next_match_slot', 'home' );

	// Render as an anonymous visitor, using the same mapping and templates as
	// shortcodes, blocks, archives and the match detail page.
	wp_set_current_user( 0 );
	$item = $renderer->get_match_item( $zero );
	lfrd_check( '0 - 0' === $item['scoreline'] && '0' === $item['home_score'] && '' === $item['result_label'], 'Explicit zero scores stay a played 0-0, not a blank or unavailable result.' );
	lfrd_check( false !== strpos( $renderer->render_match_card( array( 'match' => $zero ) ), '<strong>0 - 0</strong>' ), 'The public match card displays an explicit 0-0.' );
	$items = $renderer->get_calendar_items( array( 'post_id' => $zero, 'include_events' => false ) );
	lfrd_check( 1 === count( $items ) && '0 - 0' === $items[0]['scoreline'], 'Calendar payload retains the scoreless draw.' );

	foreach ( array( '-1', 'bad', '1.5', (string) PHP_INT_MAX . '0', '' ) as $invalid ) {
		update_post_meta( $bad, 'lf_home_score', $invalid );
		$item = $renderer->get_match_item( $bad );
		lfrd_check( '' === $item['scoreline'] && '' === $item['home_score'] && 'Score unavailable' === $item['result_label'], 'Malformed or missing legacy score is unavailable instead of an invented score: ' . $invalid );
		$node = $knockout->display_for_test( $bad );
		lfrd_check( '' === $node['home_score'] && '0' === $node['away_score'] && 0 === $node['winner_team_id'], 'Bracket display uses the same score validity and keeps the opponent\'s explicit zero.' );
	}
	lfrd_check( false !== strpos( $renderer->render_match_card( array( 'match' => $bad ) ), 'Score unavailable' ), 'A finished card with no usable score explains the missing result.' );

	foreach ( $forfeits as $outcome => $fixture ) {
		$id = $fixture['id'];
		$label = $fixture['label'];
		$winner = 'forfeit_home' === $outcome ? $away : ( 'forfeit_away' === $outcome ? $home : 0 );
		$item = $renderer->get_match_item( $id );
		lfrd_check( $outcome === $item['outcome'] && $label === $item['outcome_label'] && $winner === $item['winner_team_id'], 'Forfeit type and winner agree with the bracket: ' . $outcome );
		lfrd_check( '' === $item['scoreline'] && '' === $item['home_score'] && '' === $item['away_score'], 'Stale played scores do not represent a forfeit: ' . $outcome );
		foreach ( array( 'match-card.php' => array( 'match' => $item ), 'match-list.php' => array( 'matches' => array( $item ) ) ) as $template => $data ) {
			$html = $renderer->template_for_test( $template, $data );
			lfrd_check( false !== strpos( $html, $label ) && false === strpos( $html, '91 - 88' ), $template . ' explains ' . $outcome . ' without a made-up played result.' );
		}
		$events = $renderer->get_calendar_items( array( 'post_id' => $id, 'include_events' => false ) );
		lfrd_check( 1 === count( $events ) && $label === $events[0]['outcomeLabel'] && '' === $events[0]['scoreline'] && false !== strpos( $events[0]['description'], $label ), 'Calendar cards, detail and export payload retain the forfeit explanation.' );
	}

	$items = lfrd_feed( $context['lf_competition'] );
	lfrd_check( 0 === $items[ $zero ]['result']['homeScore'] && true === $items[ $zero ]['result']['isDraw'], 'Public feed retains numeric zeros and the played draw.' );
	foreach ( $forfeits as $outcome => $fixture ) {
		$result = $items[ $fixture['id'] ]['result'];
		$winner = 'forfeit_home' === $outcome ? $away : ( 'forfeit_away' === $outcome ? $home : null );
		lfrd_check( true === $result['complete'] && $winner === $result['winnerTeamId'] && null === $result['winnerOverride'], 'Feed outcome outranks a conflicting older winner override: ' . $outcome );
		lfrd_check( null === $result['homeScore'] && null === $result['awayScore'] && $fixture['label'] === $result['outcomeLabel'] && false === $result['isDraw'], 'Forfeit feed numbers and labels match the public page.' );
	}
	lfrd_check( false === $items[ $bad ]['result']['complete'] && null === $items[ $bad ]['result']['winnerTeamId'], 'Incomplete legacy scores do not create a complete feed result.' );
	$result = $items[ $unknown ]['result'];
	lfrd_check( 'unknown' === $result['outcome'] && false === $result['complete'] && null === $result['winnerTeamId'] && null === $result['homeScore'] && null === $result['winnerOverride'], 'Unknown imported outcomes do not silently become normal played results.' );
	$result = $items[ $duplicate_team ]['result'];
	lfrd_check( false === $result['complete'] && null === $result['winnerTeamId'] && null === $result['winnerOverride'] && null === $result['homeScore'] && false === $result['isDraw'], 'A legacy fixture with the same team on both sides has no valid played result.' );
	$item = $renderer->get_match_item( $duplicate_team );
	$node = $knockout->display_for_test( $duplicate_team );
	lfrd_check( '' === $item['scoreline'] && 'Score unavailable' === $item['result_label'] && '' === $node['home_score'] && '' === $node['away_score'], 'Public display agrees that a duplicate-team fixture has no usable played score.' );
	$item = $renderer->get_match_item( $unknown );
	lfrd_check( '' === $item['scoreline'] && 0 === $item['winner_team_id'] && 'Score unavailable' === $item['result_label'], 'The page agrees with the feed for an unknown outcome.' );

	$item = $renderer->get_match_item( $tied );
	lfrd_check( '2 - 2' === $item['scoreline'] && $away === $item['winner_team_id'] && 'Winner: Display Away' === $item['winner_label'], 'A decided tied knockout match names the advancing winner.' );
	lfrd_check( false !== strpos( $renderer->render_match_card( array( 'match' => $tied ) ), 'Winner: Display Away' ), 'The tied result winner is visible on the match detail page.' );
	lfrd_check( $away === $items[ $tied ]['result']['winnerTeamId'] && false === $items[ $tied ]['result']['isDraw'], 'The tied knockout feed agrees with its visible winner.' );
	$events = $renderer->get_calendar_items( array( 'post_id' => $tied, 'include_events' => false ) );
	lfrd_check( 'Winner: Display Away' === $events[0]['resultLabel'], 'The calendar explains a tied knockout decision.' );

	$rounds = $knockout->get_bracket( $context['lf_competition'], $context['lf_season'], $context['lf_sport'], $context['lf_league_level'] );
	$fallback = $renderer->template_for_test( 'knockout-bracket.php', array( 'rounds' => $rounds, 'tree' => array( 'linked' => false, 'roots' => array() ) ) );
	foreach ( $forfeits as $fixture ) { lfrd_check( false !== strpos( $fallback, $fixture['label'] ), 'Fallback bracket gives the result type: ' . $fixture['label'] ); }
	lfrd_check( false === strpos( $fallback, '<strong>91</strong>' ) && false === strpos( $fallback, '<strong>88</strong>' ), 'Bracket fallback suppresses stale played forfeit totals.' );
	$tree = $knockout->get_bracket_tree( $context['lf_competition'], $context['lf_season'], $context['lf_sport'], $context['lf_league_level'] );
	$roots = array_column( $tree['roots'], null, 'id' );
	lfrd_check( $tree['linked'] && isset( $roots[ $waiting ] ) && false === $roots[ $waiting ]['is_bye'], 'A linked one-sided final waits for the other feeder instead of claiming a bye.' );
	$html = $renderer->template_for_test( 'knockout-bracket.php', array( 'rounds' => $rounds, 'tree' => array( 'linked' => true, 'roots' => array( $roots[ $waiting ] ) ) ) );
	preg_match( '~<article class="leagueflow-bracket__match leagueflow-bracket-tree__match">(.*?)</article>~s', $html, $article );
	lfrd_check( false !== strpos( $article[1], 'Display Away' ) && false !== strpos( $article[1], 'TBD' ) && false === strpos( $article[1], '>Bye<' ), 'The waiting final shows its actual away-side participant and TBD for the missing side.' );
	$node = $knockout->display_for_test( $bye );
	$node['children'] = array();
	lfrd_check( true === $node['is_bye'] && $away === $node['winner_team_id'], 'An explicitly decided legacy one-team fixture is identified as a bye.' );
	$html = $renderer->template_for_test( 'knockout-bracket.php', array( 'rounds' => array(), 'tree' => array( 'linked' => true, 'roots' => array( $node ) ) ) );
	lfrd_check( false !== strpos( $html, '>Bye<' ) && false !== strpos( $html, 'Display Away' ) && false === strpos( $html, '>TBD<' ), 'An away-only bye preserves the winning away team in the tree.' );
	$node = $knockout->display_for_test( $forfeits['forfeit_home']['id'] );
	$node['children'] = array();
	$html = $renderer->template_for_test( 'knockout-bracket.php', array( 'rounds' => array(), 'tree' => array( 'linked' => true, 'roots' => array( $node ) ) ) );
	lfrd_check( false !== strpos( $html, 'Home team forfeited' ) && false === strpos( $html, '<strong>91</strong>' ), 'Connected bracket trees explain forfeits without stale totals.' );

	update_post_meta( $tied, 'lf_status', 'live' );
	$item = $renderer->get_match_item( $tied );
	lfrd_check( 'Live' === $item['status_label'] && '2 - 2' === $item['scoreline'] && 0 === $item['winner_team_id'] && '' === $item['winner_label'], 'A reopened live match may show current scores but no final winner.' );
	$items = lfrd_feed( $context['lf_competition'], 'match-events' );
	lfrd_check( null === $items[ $tied ]['result'], 'The event feed likewise does not present a live score as a final result.' );

	WP_CLI::success( 'Public result display: ' . $GLOBALS['lfrd_checks'] . ' assertions passed.' );
} finally {
	wp_set_current_user( $admin_id );
	$_POST = array();
	$_GET = array();
	foreach ( array_reverse( $posts ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( array_reverse( $terms ) as $term ) { wp_delete_term( $term[0], $term[1] ); }
	$_POST = $old_post;
	$_GET = $old_get;
	wp_set_current_user( $old_user );
}
