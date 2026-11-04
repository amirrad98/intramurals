<?php
/** Native admin navigation, scoped counts and visibility regressions. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only a disposable leagueflow.test site with LEAGUEFLOW_DISPOSABLE_WP=1.' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';

class LFAW_Admin extends \LeagueFlow\Admin {
	public function count_for( $type, $status ) { return $this->count_list_posts( $type, $status ); }
	public function dashboard_counts( $sport_id ) { return $this->get_sport_content_counts( $sport_id ); }
	public function team_options( $sport ) { return $this->get_team_options( $sport ); }
	public function bracket_options( $match, $sport ) { return $this->get_knockout_target_options( $match, $sport ); }
}
class LFAW_Assets extends \LeagueFlow\Assets {
	public function posts( $type ) { return $this->get_post_dataset( $type ); }
	public function terms( $type ) { return $this->get_term_dataset( $type ); }
}
class LFAW_Setup extends \LeagueFlow\Admin_Setup {
	public function posted_for_test() { return $this->posted_input(); }
}
$GLOBALS['lfaw_assertions'] = 0;
function lfaw_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['lfaw_assertions'];
}
function lfaw_screen( $file, $args ) {
	$_GET = $args;
	$_REQUEST = $args;
	$GLOBALS['pagenow'] = $file;
	$GLOBALS['typenow'] = $args['post_type'] ?? '';
	$GLOBALS['post_type'] = $args['post_type'] ?? '';
	$GLOBALS['taxnow'] = $args['taxonomy'] ?? '';
	$GLOBALS['taxonomy'] = $args['taxonomy'] ?? '';
	$_SERVER['HTTP_HOST'] = 'leagueflow.test';
	$_SERVER['REQUEST_URI'] = '/wp-admin/' . $file . '?' . http_build_query( $args );
	set_current_screen( 'edit.php' === $file ? 'edit-' . $args['post_type'] : $file );
}
function lfaw_render( $callback ) {
	ob_start();
	$callback();
	return (string) ob_get_clean();
}

$sports = new \LeagueFlow\Sports_Manager();
$fields = new \LeagueFlow\Field_Availability_Manager();
$standings = new \LeagueFlow\Standings_Service();
$knockout = new \LeagueFlow\Knockout_Service();
$renderer = new \LeagueFlow\Renderer( $standings, $knockout, $sports );
$admin = new LFAW_Admin( $standings, $knockout, $renderer, new \LeagueFlow\Seeder(), $sports, new \LeagueFlow\Exporter( $sports ), $fields, new \LeagueFlow\Fixture_Generator( $fields ) );
$assets = new LFAW_Assets();
$old_enabled = get_option( \LeagueFlow\Sports_Manager::ENABLED_SPORTS_OPTION );
$old_user = get_current_user_id();
$admin_id = (int) get_user_by( 'login', 'admin' )->ID;
$created_posts = array();
$created_terms = array();
$created_users = array();
$prefix = 'lfaw-' . strtolower( wp_generate_password( 8, false, false ) );
$post = static function ( $title, $status, $terms, $meta = array(), $type = 'lf_match', $author = 0 ) use ( &$created_posts, $prefix, $admin_id ) {
	$id = wp_insert_post( array( 'post_title' => $prefix . ' ' . $title, 'post_type' => $type, 'post_status' => $status, 'post_author' => $author ?: $admin_id ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$created_posts[] = (int) $id;
	foreach ( $terms as $taxonomy => $ids ) { wp_set_object_terms( $id, $ids, $taxonomy ); }
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
};
$term = static function ( $name, $taxonomy, $sport = '' ) use ( &$created_terms, $prefix ) {
	$result = wp_insert_term( $prefix . ' ' . $name, $taxonomy );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	$id = (int) $result['term_id'];
	$created_terms[] = array( $id, $taxonomy );
	if ( $sport ) { update_term_meta( $id, 'lf_sport_slug', $sport ); }
	return $id;
};

try {
	wp_set_current_user( $admin_id );
	update_option( \LeagueFlow\Sports_Manager::ENABLED_SPORTS_OPTION, array( 'soccer', 'volleyball' ) );
	$sports->ensure_missing_enabled_terms();
	$soccer = get_term_by( 'slug', 'soccer', 'lf_sport' );
	$volleyball = get_term_by( 'slug', 'volleyball', 'lf_sport' );
	lfaw_check( $soccer instanceof WP_Term && $volleyball instanceof WP_Term, 'Enabled sports have terms.' );
	$competition = $term( 'Competition A', 'lf_competition', 'soccer' );
	$other_competition = $term( 'Competition B', 'lf_competition', 'soccer' );
	$season = $term( 'Season A', 'lf_season', 'soccer' );
	$other_season = $term( 'Season B', 'lf_season', 'soccer' );
	$context_terms = array( 'lf_sport' => array( $soccer->term_id ), 'lf_competition' => array( $competition ), 'lf_season' => array( $season ) );
	$meta = array( 'lf_status' => 'finished', 'lf_home_score' => '3', 'lf_away_score' => '0', \LeagueFlow\Fixture_Generator::META_BATCH => $prefix );
	$published = $post( 'selected published', 'publish', $context_terms, $meta );
	$draft = $post( 'selected draft', 'draft', $context_terms, $meta );
	$post( 'selected other competition', 'publish', array_replace( $context_terms, array( 'lf_competition' => array( $other_competition ) ) ), $meta );
	$post( 'selected other season', 'publish', array_replace( $context_terms, array( 'lf_season' => array( $other_season ) ) ), $meta );
	$post( 'selected live', 'publish', $context_terms, array_replace( $meta, array( 'lf_status' => 'live' ) ) );
	$post( 'selected other batch', 'publish', $context_terms, array_replace( $meta, array( \LeagueFlow\Fixture_Generator::META_BATCH => $prefix . '-other' ) ) );
	$post( 'excluded search', 'publish', $context_terms, $meta );
	$filters = array( 'post_type' => 'lf_match', 'lf_sport' => 'soccer', 'lf_competition_filter' => $competition, 'lf_season_filter' => $season, 'lf_match_status' => 'finished', 'lf_batch' => $prefix, 's' => $prefix . ' selected' );
	lfaw_screen( 'edit.php', $filters );
	wp_edit_posts_query( $_GET );
	$listed = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	sort( $listed );
	$expected = array( $published, $draft );
	sort( $expected );
	lfaw_check( $listed === $expected, 'Real admin query combines sport, competition, season, match status, batch and search.' );
	lfaw_check( 2 === $admin->count_for( 'lf_match', 'all' ), 'All-view count uses all visible filters.' );
	lfaw_check( 1 === $admin->count_for( 'lf_match', 'publish' ), 'Published-view count uses all visible filters.' );
	lfaw_check( 1 === $admin->count_for( 'lf_match', 'draft' ), 'Draft-view count uses all visible filters.' );
	$views = $admin->filter_list_views( array( 'all' => 'core' ) );
	foreach ( array( 'all', 'publish', 'draft', 'lf_unscheduled' ) as $view ) {
		lfaw_check( isset( $views[ $view ] ), 'Scoped status view exists: ' . $view );
		preg_match( '/href="([^"]+)"/', $views[ $view ], $match );
		parse_str( wp_parse_url( html_entity_decode( $match[1], ENT_QUOTES ), PHP_URL_QUERY ), $query );
		foreach ( $filters as $key => $value ) { lfaw_check( (string) $value === (string) ( $query[ $key ] ?? '' ), $view . ' preserves ' . $key ); }
	}
	$filter_markup = lfaw_render( static function () use ( $admin ) { $admin->render_admin_sport_filter( 'lf_match' ); } );
	lfaw_check( false !== strpos( $filter_markup, 'id="leagueflow-filter-match-status"' ), 'Match lifecycle filter is rendered.' );
	lfaw_check( '' === lfaw_render( static function () use ( $admin ) { $admin->render_admin_sport_filter( 'lf_match', 'bottom' ); } ), 'Only one named filter set is submitted, avoiding conflicting duplicate values.' );
	update_post_meta( $published, 'lf_outcome', 'forfeit_home' );
	lfaw_check( 'Home team forfeited' === lfaw_render( static function () use ( $admin, $published ) { $admin->render_match_column( 'score', $published ); } ), 'Forfeit outcomes remain visible when no played score is meaningful.' );
	delete_post_meta( $published, 'lf_outcome' );
	update_post_meta( $published, 'lf_home_score', '-1' );
	lfaw_check( '&mdash;' === lfaw_render( static function () use ( $admin, $published ) { $admin->render_match_column( 'score', $published ); } ), 'Malformed legacy scores are not displayed as valid result totals.' );

	$without = $post( 'selected no season', 'publish', array( 'lf_sport' => array( $soccer->term_id ), 'lf_competition' => array( $competition ) ), $meta );
	lfaw_screen( 'edit.php', array_replace( $filters, array( 'lf_season_filter' => 'none' ) ) );
	wp_edit_posts_query( $_GET );
	lfaw_check( array( $without ) === array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ), 'No-season filter selects records without a season.' );
	lfaw_check( 1 === $admin->count_for( 'lf_match', 'all' ), 'No-season counts match the list.' );

	lfaw_screen( 'post.php', array( 'post_type' => 'lf_match', 'post' => $published, 'lf_sport' => 'volleyball' ) );
	lfaw_check( 'soccer' === $admin->get_context_sport_slug(), 'An edited record supplies its own sport despite a stale URL.' );
	lfaw_check( 'leagueflow-sport-soccer' === $admin->filter_admin_menu_parent_file( '' ), 'Record sidebar follows stored sport.' );
	lfaw_screen( 'term.php', array( 'taxonomy' => 'lf_season', 'post_type' => 'lf_match', 'tag_ID' => $season, 'lf_sport' => 'volleyball' ) );
	lfaw_check( 'soccer' === $admin->get_context_sport_slug(), 'An edited season supplies its stored sport.' );
	$bar = lfaw_render( array( $admin, 'render_context_bar' ) );
	lfaw_check( false === strpos( $bar, 'leagueflow-sport-switch' ), 'Term edit does not expose a switcher that drops its term ID.' );
	lfaw_check( false !== strpos( $bar, 'edit-tags.php?' ) && false !== strpos( $bar, 'Back to Seasons' ), 'Term edit has a working back-to-list link.' );
	lfaw_screen( 'admin.php', array( 'page' => 'leagueflow-guide', 'lf_sport' => 'soccer' ) );
	$bar = lfaw_render( array( $admin, 'render_context_bar' ) );
	lfaw_check( false !== strpos( $bar, 'Staff guide' ), 'Staff guide has a breadcrumb title.' );
	lfaw_check( 'leagueflow' === $admin->filter_admin_menu_parent_file( '' ), 'Global staff guide stays under the shared menu.' );

	$setup = new LFAW_Setup( $sports, $fields );
	$unassigned = $post( 'mapping unassigned', 'draft', array( 'lf_sport' => array( $soccer->term_id ) ), array( 'lf_status' => 'scheduled' ) );
	$mapping = array( 'sport' => 'soccer', 'type' => 'lf_match', 'taxonomy' => 'lf_season', 'term_id' => $season, 'record_ids' => array( $unassigned ) );
	$plan = $setup->plan( $mapping );
	lfaw_check( ! is_wp_error( $plan ) && 1 === count( $plan['rows'] ) && ! $plan['conflicts'], 'A valid explicit mapping selection remains available.' );
	foreach ( array( 0, -$unassigned, 'not-an-id', '1.5', true, array(), str_repeat( '9', 30 ) ) as $invalid ) {
		$result = $setup->plan( array_replace( $mapping, array( 'record_ids' => array( $unassigned, $invalid ) ) ) );
		lfaw_check( is_wp_error( $result ) && 'leagueflow_mapping_invalid_records' === $result->get_error_code(), 'Malformed mixed mappings are rejected without dropping or converting an ID.' );
	}
	$_POST = array( 'lf_map_sport' => 'soccer', 'lf_map_type' => 'lf_match', 'lf_map_taxonomy' => 'lf_season', 'lf_map_term' => $season, 'lf_map_records' => array( (string) $unassigned, '-' . $unassigned ) );
	$posted = $setup->posted_for_test();
	lfaw_check( '-' . $unassigned === $posted['record_ids'][1], 'The HTTP mapping parser preserves raw IDs until validation.' );
	lfaw_check( is_wp_error( $setup->plan( $posted ) ), 'Malformed IDs cannot bypass validation through the form parser.' );
	$_POST = array();
	lfaw_check( array() === wp_get_object_terms( $unassigned, 'lf_season', array( 'fields' => 'ids' ) ), 'Mapping previews and malformed requests leave assignments untouched.' );
	$target = $post( 'mapping bracket target', 'draft', array( 'lf_sport' => array( $soccer->term_id ) ), array( 'lf_is_knockout' => 1, 'lf_status' => 'scheduled' ) );
	update_post_meta( $unassigned, 'lf_is_knockout', 1 );
	update_post_meta( $unassigned, 'lf_next_match_id', $target );
	$plan = $setup->plan( $mapping );
	lfaw_check( ! is_wp_error( $plan ) && 1 === count( $plan['conflicts'] ) && false !== strpos( $plan['conflicts'][0]['reason'], 'linked knockout bracket' ), 'Mapping refuses to change only one linked source’s competition/season.' );
	$plan = $setup->plan( array_replace( $mapping, array( 'record_ids' => array( $target ) ) ) );
	lfaw_check( ! is_wp_error( $plan ) && 1 === count( $plan['conflicts'] ), 'Incoming feeder links also protect bracket target context.' );

	// Dashboard and selectors must use the same per-record visibility as editing.
	$private_sport_id = $term( 'Visibility sport', 'lf_sport' );
	$private_sport = get_term( $private_sport_id, 'lf_sport' );
	$author = wp_insert_user( array( 'user_login' => $prefix . '-author', 'user_pass' => wp_generate_password(), 'user_email' => $prefix . '@example.test', 'role' => 'author' ) );
	if ( is_wp_error( $author ) ) { throw new RuntimeException( $author->get_error_message() ); }
	$created_users[] = (int) $author;
	$team_terms = array( 'lf_sport' => array( $private_sport_id ) );
	$public_team = $post( 'visible public team', 'publish', $team_terms, array(), 'lf_team' );
	$private_team = $post( 'hidden private team', 'private', $team_terms, array(), 'lf_team' );
	$other_draft = $post( 'hidden draft team', 'draft', $team_terms, array(), 'lf_team' );
	$own_private = $post( 'own private team', 'private', $team_terms, array(), 'lf_team', $author );
	$hidden_match = $post( 'private bracket title', 'private', $team_terms, array( 'lf_is_knockout' => 1 ), 'lf_match' );
	$owned_source = $post( 'owned bracket source', 'publish', $team_terms, array( 'lf_is_knockout' => 1, 'lf_next_match_id' => $hidden_match ), 'lf_match', $author );
	wp_set_current_user( $author );
	lfaw_screen( 'admin.php', array( 'page' => 'leagueflow' ) );
	$counts = $admin->dashboard_counts( $private_sport_id );
	lfaw_check( 2 === $counts['teams'], 'Dashboard excludes another author’s private teams and retains the user’s own.' );
	$options = $admin->team_options( $private_sport->slug );
	lfaw_check( isset( $options[ $public_team ], $options[ $own_private ] ), 'Readable teams remain selectable.' );
	lfaw_check( ! isset( $options[ $private_team ], $options[ $other_draft ] ), 'Team selector excludes unreadable private and draft names.' );
	$block_options = array_column( $assets->posts( 'lf_team' ), 'value' );
	lfaw_check( ! in_array( (string) $private_team, $block_options, true ) && ! in_array( (string) $other_draft, $block_options, true ), 'Block inspector excludes unreadable teams.' );
	$bracket_options = $admin->bracket_options( $owned_source, $private_sport->slug );
	lfaw_check( isset( $bracket_options[ $hidden_match ] ) && false === strpos( $bracket_options[ $hidden_match ], 'private bracket title' ), 'An unreadable current bracket target keeps its ID without exposing its title.' );
	wp_set_current_user( $admin_id );
	update_option( \LeagueFlow\Sports_Manager::ENABLED_SPORTS_OPTION, array() );
	lfaw_check( $sports->get_enabled_sport_slugs() === array_column( $assets->terms( 'lf_sport' ), 'value' ), 'Empty legacy settings follow the enabled-sport fallback instead of listing every disabled sport in the inspector.' );
	WP_CLI::success( sprintf( 'Admin workflow regressions passed (%d assertions).', $GLOBALS['lfaw_assertions'] ) );
} finally {
	wp_set_current_user( $admin_id );
	$_POST = array();
	$_GET = array();
	$_REQUEST = array();
	foreach ( $created_posts as $id ) { wp_delete_post( $id, true ); }
	foreach ( $created_terms as $created ) { wp_delete_term( $created[0], $created[1] ); }
	foreach ( $created_users as $id ) { wp_delete_user( $id ); }
	update_option( \LeagueFlow\Sports_Manager::ENABLED_SPORTS_OPTION, $old_enabled );
	wp_set_current_user( $old_user );
}
