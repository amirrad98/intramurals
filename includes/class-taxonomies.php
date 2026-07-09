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
		add_action( 'init', array( $this, 'register_term_meta' ) );
		add_action( 'init', 'LeagueFlow\\ensure_default_league_levels', 30 );
		add_action( 'init', 'LeagueFlow\\ensure_default_league_level_assignments', 35 );

		add_action( 'lf_season_add_form_fields', array( $this, 'render_season_current_add_field' ) );
		add_action( 'lf_season_edit_form_fields', array( $this, 'render_season_current_edit_field' ) );
		add_action( 'created_lf_season', array( $this, 'save_season_current_field' ) );
		add_action( 'edited_lf_season', array( $this, 'save_season_current_field' ) );
	}

	/**
	 * Register season term meta.
	 *
	 * @return void
	 */
	public function register_term_meta() {
		register_term_meta(
			'lf_season',
			'lf_is_current',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static function() {
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	/**
	 * Render the "current season" checkbox on the add-season screen.
	 *
	 * @return void
	 */
	public function render_season_current_add_field() {
		?>
		<div class="form-field">
			<label>
				<input type="checkbox" name="lf_is_current" value="1" />
				<?php esc_html_e( 'Set as the current season', 'leagueflow' ); ?>
			</label>
			<p><?php esc_html_e( 'Blocks, shortcodes, and the portal default to the current season when none is specified.', 'leagueflow' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the "current season" checkbox on the edit-season screen.
	 *
	 * @param \WP_Term $term Season term.
	 * @return void
	 */
	public function render_season_current_edit_field( $term ) {
		$is_current = (bool) get_term_meta( $term->term_id, 'lf_is_current', true );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Current season', 'leagueflow' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="lf_is_current" value="1" <?php checked( $is_current ); ?> />
					<?php esc_html_e( 'Set as the current season', 'leagueflow' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Only one season can be current. Setting this clears the flag from any other season.', 'leagueflow' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the "current season" flag, enforcing a single current season.
	 *
	 * @param int $term_id Season term ID.
	 * @return void
	 */
	public function save_season_current_field( $term_id ) {
		// The taxonomy term screens carry WordPress's own nonce, verified by core before these hooks fire.
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( ! empty( $_POST['lf_is_current'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			set_current_season( $term_id );
		} else {
			delete_term_meta( $term_id, 'lf_is_current' );
		}
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
