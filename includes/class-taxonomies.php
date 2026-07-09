<?php
/**
 * Taxonomy registrations.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy registration.
 */
class Taxonomies {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'init', 'LeagueFlow\\ensure_default_league_levels', 30 );
		add_action( 'init', 'LeagueFlow\\ensure_default_league_level_assignments', 35 );
	}

	/**
	 * Register sport, league level, competition, and season taxonomies.
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		register_taxonomy(
			'lf_sport',
			array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Sports', 'leagueflow' ),
					'singular_name' => __( 'Sport', 'leagueflow' ),
					'search_items'  => __( 'Search Sports', 'leagueflow' ),
					'all_items'     => __( 'All Sports', 'leagueflow' ),
					'edit_item'     => __( 'Edit Sport', 'leagueflow' ),
					'update_item'   => __( 'Update Sport', 'leagueflow' ),
					'add_new_item'  => __( 'Add New Sport', 'leagueflow' ),
					'new_item_name' => __( 'New Sport Name', 'leagueflow' ),
					'menu_name'     => __( 'Sports', 'leagueflow' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			'lf_league_level',
			array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'League Levels', 'leagueflow' ),
					'singular_name' => __( 'League Level', 'leagueflow' ),
					'search_items'  => __( 'Search League Levels', 'leagueflow' ),
					'all_items'     => __( 'All League Levels', 'leagueflow' ),
					'edit_item'     => __( 'Edit League Level', 'leagueflow' ),
					'update_item'   => __( 'Update League Level', 'leagueflow' ),
					'add_new_item'  => __( 'Add New League Level', 'leagueflow' ),
					'new_item_name' => __( 'New League Level Name', 'leagueflow' ),
					'menu_name'     => __( 'League Levels', 'leagueflow' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => 'league-level' ),
			)
		);

		register_taxonomy(
			'lf_competition',
			array( 'lf_team', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Competitions', 'leagueflow' ),
					'singular_name' => __( 'Competition', 'leagueflow' ),
					'search_items'  => __( 'Search Competitions', 'leagueflow' ),
					'all_items'     => __( 'All Competitions', 'leagueflow' ),
					'edit_item'     => __( 'Edit Competition', 'leagueflow' ),
					'update_item'   => __( 'Update Competition', 'leagueflow' ),
					'add_new_item'  => __( 'Add New Competition', 'leagueflow' ),
					'new_item_name' => __( 'New Competition Name', 'leagueflow' ),
					'menu_name'     => __( 'Competitions', 'leagueflow' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => (string) get_setting( 'competition_slug', 'competition' ) ),
			)
		);

		register_taxonomy(
			'lf_season',
			array( 'lf_team', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Seasons', 'leagueflow' ),
					'singular_name' => __( 'Season', 'leagueflow' ),
					'search_items'  => __( 'Search Seasons', 'leagueflow' ),
					'all_items'     => __( 'All Seasons', 'leagueflow' ),
					'edit_item'     => __( 'Edit Season', 'leagueflow' ),
					'update_item'   => __( 'Update Season', 'leagueflow' ),
					'add_new_item'  => __( 'Add New Season', 'leagueflow' ),
					'new_item_name' => __( 'New Season Name', 'leagueflow' ),
					'menu_name'     => __( 'Seasons', 'leagueflow' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => (string) get_setting( 'season_slug', 'season' ) ),
			)
		);
	}
}
