<?php
/**
 * Activation and uninstall behavior.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activator.
 */
class Activator {

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		$current = get_option( 'leagueflow_settings', array() );
		$merged  = wp_parse_args( is_array( $current ) ? $current : array(), defaults() );

		update_option( 'leagueflow_settings', $merged );
		add_option( Sports_Manager::ENABLED_SPORTS_OPTION, array( 'soccer' ) );
		add_option( Sports_Manager::SETUP_REQUIRED_OPTION, 1 );

		// Flag the first admin request so the user is taken straight to sport setup.
		set_transient( 'leagueflow_activation_redirect', 1, 30 );

		ensure_portal_roles();

		Availability::create_table();

		$post_types = new Post_Types();
		$taxonomies = new Taxonomies();
		$sports     = new Sports_Manager();

		$post_types->register_post_types();
		$post_types->register_meta();
		$taxonomies->register_taxonomies();
		$sports->register_term_meta();
		$sports->ensure_enabled_terms();
		ensure_default_league_levels();
		ensure_default_league_level_assignments();

		flush_rewrite_rules();
	}

	/**
	 * Cleanup data on uninstall if requested.
	 *
	 * @return void
	 */
	public static function cleanup() {
		$settings = get_settings();

		if ( empty( $settings['cleanup_on_uninstall'] ) ) {
			delete_option( 'leagueflow_flush_rewrite' );
			return;
		}

		$post_types = array( 'lf_team', 'lf_player', 'lf_match' );

		foreach ( $post_types as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}

		$taxonomies = array( 'lf_competition', 'lf_season', 'lf_league_level' );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}

		global $wpdb;

		$availability_table = Availability::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$availability_table}" );

		delete_transient( 'leagueflow_activation_redirect' );
		delete_option( 'leagueflow_settings' );
		delete_option( 'leagueflow_flush_rewrite' );
		delete_option( 'leagueflow_league_level_migration_complete' );
		delete_option( 'leagueflow_player_team_details_migration_complete' );
		delete_option( Availability::DB_VERSION_OPTION );
		delete_option( Sports_Manager::ENABLED_SPORTS_OPTION );
		delete_option( Sports_Manager::SETUP_REQUIRED_OPTION );
		delete_option( Sports_Manager::MIGRATION_OPTION );
	}
}
