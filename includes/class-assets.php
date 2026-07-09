<?php
/**
 * Asset registration.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Register admin, frontend, and block assets.
 */
class Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register reusable styles and scripts.
	 *
	 * @return void
	 */
	public function register_assets() {
		$portal_script_path   = LEAGUEFLOW_PATH . 'assets/js/portal.js';
		$calendar_script_path = LEAGUEFLOW_PATH . 'assets/js/calendar.js';

		wp_register_style(
			'leagueflow-frontend',
			LEAGUEFLOW_URL . 'assets/css/frontend.css',
			array(),
			(string) filemtime( LEAGUEFLOW_PATH . 'assets/css/frontend.css' )
		);

		wp_register_style(
			'leagueflow-admin',
			LEAGUEFLOW_URL . 'assets/css/admin.css',
			array(),
			LEAGUEFLOW_VERSION
		);

		wp_register_style(
			'leagueflow-editor',
			LEAGUEFLOW_URL . 'assets/css/editor.css',
			array( 'wp-edit-blocks' ),
			LEAGUEFLOW_VERSION
		);

		wp_register_script(
			'leagueflow-blocks',
			LEAGUEFLOW_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ),
			LEAGUEFLOW_VERSION,
			true
		);

		wp_register_script(
			'leagueflow-portal',
			LEAGUEFLOW_URL . 'assets/js/portal.js',
			array(),
			file_exists( $portal_script_path ) ? (string) filemtime( $portal_script_path ) : LEAGUEFLOW_VERSION,
			true
		);

		wp_register_script(
			'leagueflow-calendar',
			LEAGUEFLOW_URL . 'assets/js/calendar.js',
			array(),
			file_exists( $calendar_script_path ) ? (string) filemtime( $calendar_script_path ) : LEAGUEFLOW_VERSION,
			true
		);
	}

	/**
	 * Enqueue admin assets on LeagueFlow screens.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'lf_team',
			'edit-lf_team',
			'lf_player',
			'edit-lf_player',
			'lf_match',
			'edit-lf_match',
			'lf_calendar_event',
			'edit-lf_calendar_event',
			'toplevel_page_leagueflow',
			'leagueflow_page_leagueflow-sports',
			'leagueflow_page_leagueflow-fields',
			'leagueflow_page_leagueflow-standings',
			'leagueflow_page_leagueflow-brackets',
			'leagueflow_page_leagueflow-settings',
			'edit-lf_league_level',
			'edit-lf_competition',
			'edit-lf_season',
		);

		if (
			in_array( $screen->id, $allowed_screens, true ) ||
			false !== strpos( $screen->id, 'lf_league_level' ) ||
			false !== strpos( $screen->id, 'lf_competition' ) ||
			false !== strpos( $screen->id, 'lf_season' ) ||
			false !== strpos( $screen->id, 'leagueflow-sport-' )
		) {
			wp_enqueue_style( 'leagueflow-admin' );
		}
	}

	/**
	 * Enqueue block editor assets and preload options.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		wp_enqueue_script( 'leagueflow-blocks' );
		wp_enqueue_style( 'leagueflow-editor' );

		wp_localize_script(
			'leagueflow-blocks',
			'LeagueFlowBlocksData',
			array(
				'sports'       => $this->get_term_dataset( 'lf_sport' ),
				'leagueLevels' => $this->get_term_dataset( 'lf_league_level' ),
				'competitions' => $this->get_term_dataset( 'lf_competition' ),
				'seasons'      => $this->get_term_dataset( 'lf_season' ),
				'teams'        => $this->get_post_dataset( 'lf_team' ),
				'matches'      => $this->get_post_dataset( 'lf_match' ),
				'defaults'     => array(
					'showLogos'        => (bool) get_setting( 'show_logos', 1 ),
					'showPlayerPhotos' => (bool) get_setting( 'show_player_photos', 1 ),
				),
			)
		);
	}

	/**
	 * Build taxonomy options for block controls.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, array<string, string>>
	 */
	protected function get_term_dataset( $taxonomy ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( 'lf_sport' === $taxonomy ) {
			$args['slug'] = get_option( Sports_Manager::ENABLED_SPORTS_OPTION, array( 'soccer' ) );
		}

		$terms = 'lf_league_level' === $taxonomy ? get_league_level_terms() : get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_map(
			static function( $term ) {
				$sport_slug = 'lf_sport' === $term->taxonomy ? $term->slug : (string) get_term_meta( $term->term_id, 'lf_sport_slug', true );

				return array(
					'label' => $term->name,
					'value' => $term->slug,
					'sport' => sanitize_key( $sport_slug ),
				);
			},
			$terms
		);
	}

	/**
	 * Build post options for block controls.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, array<string, string>>
	 */
	protected function get_post_dataset( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_map(
			static function( $post ) {
				return array(
					'label' => $post->post_title,
					'value' => (string) $post->ID,
					'sport' => get_post_primary_term_slug( $post->ID, 'lf_sport' ),
				);
			},
			$posts
		);
	}
}
