<?php
/**
 * Sport registry and setup management.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Manages built-in sports, enabled modules, admin icons, and sport metadata.
 */
class Sports_Manager {

	/**
	 * Enabled sports option.
	 *
	 * @var string
	 */
	const ENABLED_SPORTS_OPTION = 'leagueflow_enabled_sports';

	/**
	 * Setup-required option.
	 *
	 * @var string
	 */
	const SETUP_REQUIRED_OPTION = 'leagueflow_sport_setup_required';

	/**
	 * Migration option.
	 *
	 * @var string
	 */
	const MIGRATION_OPTION = 'leagueflow_sport_migration_complete';

	/**
	 * Available sport definitions cache.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	protected static $definitions = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_term_meta' ) );
		add_action( 'init', array( $this, 'ensure_enabled_terms' ), 30 );
		add_action( 'admin_init', array( $this, 'maybe_migrate_legacy_data' ), 5 );
		add_action( 'admin_notices', array( $this, 'render_term_sport_notices' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'validate_rest_term_sport' ), 10, 3 );

		$taxonomies = array( 'lf_competition', 'lf_season' );

		foreach ( $taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_term_sport_field' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_term_sport_field_edit' ) );
			add_action( 'created_' . $taxonomy, array( $this, 'save_term_sport_field' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_term_sport_field' ) );
			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'add_term_sport_column' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'render_term_sport_column' ), 10, 3 );
		}

		add_filter( 'pre_insert_term', array( $this, 'require_term_sport_on_add' ), 10, 3 );
	}

	/**
	 * Get all built-in sports.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_definitions() {
		if ( null !== static::$definitions ) {
			return static::$definitions;
		}

		static::$definitions = array(
			'soccer'            => array(
				'slug'             => 'soccer',
				'label'            => __( 'Soccer', 'leagueflow' ),
				'menu_label'       => __( 'Soccer', 'leagueflow' ),
				'description'      => __( 'Two halves, low-scoring matches, draws, and discipline events like yellow and red cards.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'GF', 'leagueflow' ),
					'against'    => __( 'GA', 'leagueflow' ),
					'difference' => __( 'GD', 'leagueflow' ),
				),
				'score_label'      => __( 'Goals', 'leagueflow' ),
				'match_structure'  => __( '2 halves', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_scorers',
						'label'       => __( 'Scorers', 'leagueflow' ),
						'description' => __( 'Goal summary with minutes and player names.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_yellow_cards',
						'label'       => __( 'Yellow Cards', 'leagueflow' ),
						'description' => __( 'Cautions and minutes.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_red_cards',
						'label'       => __( 'Red Cards', 'leagueflow' ),
						'description' => __( 'Sending-off summary and reasons when needed.', 'leagueflow' ),
					),
				),
			),
			'basketball'        => array(
				'slug'             => 'basketball',
				'label'            => __( 'Basketball', 'leagueflow' ),
				'menu_label'       => __( 'Basketball', 'leagueflow' ),
				'description'      => __( 'Quarter-based scoring, higher totals, team fouls, timeouts, and overtime periods.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'PF', 'leagueflow' ),
					'against'    => __( 'PA', 'leagueflow' ),
					'difference' => __( 'Diff', 'leagueflow' ),
				),
				'score_label'      => __( 'Points', 'leagueflow' ),
				'match_structure'  => __( '4 quarters', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_period_scores',
						'label'       => __( 'Quarter Scores', 'leagueflow' ),
						'description' => __( 'Break down scoring by quarter and overtime.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_fouls_summary',
						'label'       => __( 'Fouls / Fouled Out', 'leagueflow' ),
						'description' => __( 'Track team fouls, player foul-outs, or technicals.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_timeout_summary',
						'label'       => __( 'Timeouts / Coach Notes', 'leagueflow' ),
						'description' => __( 'Optional timeout usage or coaching notes.', 'leagueflow' ),
					),
				),
			),
			'american-football' => array(
				'slug'             => 'american-football',
				'label'            => __( 'American Football', 'leagueflow' ),
				'menu_label'       => __( 'Football', 'leagueflow' ),
				'description'      => __( 'Quarter-based scoring with possession-driven drives, touchdowns, field goals, and overtime possession rules.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'PF', 'leagueflow' ),
					'against'    => __( 'PA', 'leagueflow' ),
					'difference' => __( 'Diff', 'leagueflow' ),
				),
				'score_label'      => __( 'Points', 'leagueflow' ),
				'match_structure'  => __( '4 quarters', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_period_scores',
						'label'       => __( 'Quarter Scores', 'leagueflow' ),
						'description' => __( 'Break down scoring by quarter and overtime.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_scoring_summary',
						'label'       => __( 'Scoring Summary', 'leagueflow' ),
						'description' => __( 'Touchdowns, field goals, safeties, and conversion notes.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_turnover_summary',
						'label'       => __( 'Turnovers / Penalties', 'leagueflow' ),
						'description' => __( 'Key turnovers, sacks, or penalty summaries.', 'leagueflow' ),
					),
				),
			),
			'baseball'          => array(
				'slug'             => 'baseball',
				'label'            => __( 'Baseball', 'leagueflow' ),
				'menu_label'       => __( 'Baseball', 'leagueflow' ),
				'description'      => __( 'Nine-inning contests focused on runs, hits, errors, innings, and pitching results.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'RF', 'leagueflow' ),
					'against'    => __( 'RA', 'leagueflow' ),
					'difference' => __( 'Diff', 'leagueflow' ),
				),
				'score_label'      => __( 'Runs', 'leagueflow' ),
				'match_structure'  => __( '9 innings', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_inning_log',
						'label'       => __( 'Line Score by Inning', 'leagueflow' ),
						'description' => __( 'Record inning-by-inning scoring.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_hits_errors',
						'label'       => __( 'Hits / Errors / Left on Base', 'leagueflow' ),
						'description' => __( 'Summarize the team line score details.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_pitching_summary',
						'label'       => __( 'Pitching Summary', 'leagueflow' ),
						'description' => __( 'Winning, losing, or save pitcher notes.', 'leagueflow' ),
					),
				),
			),
			'hockey'            => array(
				'slug'             => 'hockey',
				'label'            => __( 'Hockey', 'leagueflow' ),
				'menu_label'       => __( 'Hockey', 'leagueflow' ),
				'description'      => __( 'Three periods, penalties, power plays, and sport-specific overtime / shootout handling.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'GF', 'leagueflow' ),
					'against'    => __( 'GA', 'leagueflow' ),
					'difference' => __( 'GD', 'leagueflow' ),
				),
				'score_label'      => __( 'Goals', 'leagueflow' ),
				'match_structure'  => __( '3 periods', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_period_scores',
						'label'       => __( 'Period Scores', 'leagueflow' ),
						'description' => __( 'Break down goals by period and overtime.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_penalty_summary',
						'label'       => __( 'Penalties', 'leagueflow' ),
						'description' => __( 'Minor, major, or misconduct penalties.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_shot_summary',
						'label'       => __( 'Shots / Power Plays', 'leagueflow' ),
						'description' => __( 'Shots on goal, power plays, and special teams notes.', 'leagueflow' ),
					),
				),
			),
			'volleyball'        => array(
				'slug'             => 'volleyball',
				'label'            => __( 'Volleyball', 'leagueflow' ),
				'menu_label'       => __( 'Volleyball', 'leagueflow' ),
				'description'      => __( 'Set-based results. The number of sets and scoring format follow your league rules.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'Sets For', 'leagueflow' ),
					'against'    => __( 'Sets Against', 'leagueflow' ),
					'difference' => __( 'Set Diff', 'leagueflow' ),
				),
				'score_label'      => __( 'Sets won', 'leagueflow' ),
				'match_structure'  => __( 'Set-based scoring', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_set_scores',
						'label'       => __( 'Set Scores', 'leagueflow' ),
						'description' => __( 'Optional set-by-set notes (for example 25-18, 21-25, 15-11). Enter sets won in Home Score and Away Score to calculate standings.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_match_stats_summary',
						'label'       => __( 'Aces / Blocks / Errors', 'leagueflow' ),
						'description' => __( 'Optional box-score style summary.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_rotation_notes',
						'label'       => __( 'Rotation / Libero Notes', 'leagueflow' ),
						'description' => __( 'Specialist rotation or libero notes.', 'leagueflow' ),
					),
				),
			),
			'cricket'           => array(
				'slug'             => 'cricket',
				'label'            => __( 'Cricket', 'leagueflow' ),
				'menu_label'       => __( 'Cricket', 'leagueflow' ),
				'description'      => __( 'Run-scoring innings built from overs, wickets, batting summaries, and bowling summaries.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'Runs For', 'leagueflow' ),
					'against'    => __( 'Runs Against', 'leagueflow' ),
					'difference' => __( 'Run Diff', 'leagueflow' ),
				),
				'score_label'      => __( 'Runs', 'leagueflow' ),
				'match_structure'  => __( 'Innings / overs', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_innings_summary',
						'label'       => __( 'Overs / Wickets / Innings', 'leagueflow' ),
						'description' => __( 'Summarize innings totals and overs faced.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_batting_summary',
						'label'       => __( 'Batting Summary', 'leagueflow' ),
						'description' => __( 'Top scorers, extras, and partnerships.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_bowling_summary',
						'label'       => __( 'Bowling Summary', 'leagueflow' ),
						'description' => __( 'Wickets, economy, maidens, or overs.', 'leagueflow' ),
					),
				),
			),
			'rugby'             => array(
				'slug'             => 'rugby',
				'label'            => __( 'Rugby', 'leagueflow' ),
				'menu_label'       => __( 'Rugby', 'leagueflow' ),
				'description'      => __( 'Two halves with tries, conversions, penalties, and discipline or replacement events.', 'leagueflow' ),
				'table_labels'     => array(
					'for'        => __( 'PF', 'leagueflow' ),
					'against'    => __( 'PA', 'leagueflow' ),
					'difference' => __( 'Diff', 'leagueflow' ),
				),
				'score_label'      => __( 'Points', 'leagueflow' ),
				'match_structure'  => __( '2 halves', 'leagueflow' ),
				'match_fields'     => array(
					array(
						'key'         => 'lf_scoring_summary',
						'label'       => __( 'Tries / Conversions / Penalties', 'leagueflow' ),
						'description' => __( 'Scoring breakdown by method.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_card_summary',
						'label'       => __( 'Cards / Discipline', 'leagueflow' ),
						'description' => __( 'Yellow cards, reds, or foul play notes.', 'leagueflow' ),
					),
					array(
						'key'         => 'lf_substitution_log',
						'label'       => __( 'Replacements / HIA Notes', 'leagueflow' ),
						'description' => __( 'Replacement reasons or HIA/blood/substitution notes.', 'leagueflow' ),
					),
				),
			),
		);

		return static::$definitions;
	}

	/**
	 * Get a single sport definition.
	 *
	 * @param string $slug Sport slug.
	 * @return array<string, mixed>
	 */
	public function get_definition( $slug ) {
		$definitions = static::get_definitions();
		$slug        = sanitize_key( (string) $slug );

		if ( isset( $definitions[ $slug ] ) ) {
			return $definitions[ $slug ];
		}

		return $definitions['soccer'];
	}

	/**
	 * Get enabled sport slugs.
	 *
	 * @return array<int, string>
	 */
	public function get_enabled_sport_slugs() {
		$definitions = array_keys( static::get_definitions() );
		$enabled     = get_option( self::ENABLED_SPORTS_OPTION, array( 'soccer' ) );

		if ( ! is_array( $enabled ) ) {
			$enabled = array( 'soccer' );
		}

		$enabled = array_values(
			array_filter(
				array_unique(
					array_map( 'sanitize_key', $enabled )
				),
				static function( $slug ) use ( $definitions ) {
					return in_array( $slug, $definitions, true );
				}
			)
		);

		if ( empty( $enabled ) ) {
			$enabled = array( 'soccer' );
		}

		return $enabled;
	}

	/**
	 * Get enabled sport definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_enabled_sports() {
		$definitions = static::get_definitions();
		$enabled     = array();

		foreach ( $this->get_enabled_sport_slugs() as $slug ) {
			$enabled[ $slug ] = $definitions[ $slug ];
		}

		return $enabled;
	}

	/**
	 * Check whether the plugin still expects initial sport setup.
	 *
	 * @return bool
	 */
	public function is_setup_required() {
		return (bool) get_option( self::SETUP_REQUIRED_OPTION, 1 );
	}

	/**
	 * Update enabled sports.
	 *
	 * @param array<int, string> $slugs Enabled sport slugs.
	 * @return true|\WP_Error True on success; invalid selections leave setup unchanged.
	 */
	public function update_enabled_sports( $slugs ) {
		$definitions = array_keys( static::get_definitions() );
		if ( ! is_array( $slugs ) || ! $slugs ) {
			return new \WP_Error( 'leagueflow_sports_required', __( 'Choose at least one sport. Your saved sports have not changed.', 'leagueflow' ) );
		}
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || ! in_array( $slug, $definitions, true ) ) {
				return new \WP_Error( 'leagueflow_sports_invalid', __( 'Choose sports from the available list. Your saved sports have not changed.', 'leagueflow' ) );
			}
		}
		$slugs = array_values( array_unique( $slugs ) );

		update_option( self::ENABLED_SPORTS_OPTION, $slugs, false );
		update_option( self::SETUP_REQUIRED_OPTION, 0, false );
		$this->ensure_enabled_terms();
		return true;
	}

	/**
	 * Create enabled sport terms that do not exist yet. Existing terms (and any
	 * name or description an editor gave them) are left exactly as they are.
	 *
	 * @return void
	 */
	public function ensure_missing_enabled_terms() {
		if ( ! taxonomy_exists( 'lf_sport' ) ) {
			return;
		}

		foreach ( $this->get_enabled_sports() as $slug => $sport ) {
			$term = get_term_by( 'slug', $slug, 'lf_sport' );

			if ( $term instanceof \WP_Term ) {
				continue;
			}

			wp_insert_term(
				$sport['label'],
				'lf_sport',
				array(
					'slug'        => $slug,
					'description' => $sport['description'],
				)
			);
		}
	}

	/**
	 * Ensure sport terms exist without rewriting staff-edited names/descriptions.
	 *
	 * @return void
	 */
	public function ensure_enabled_terms() {
		$this->ensure_missing_enabled_terms();
	}

	/**
	 * Register sport term meta on competition and season taxonomies.
	 *
	 * @return void
	 */
	public function register_term_meta() {
		$args = array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_key',
			'auth_callback'     => static function() {
				return current_user_can( 'manage_categories' );
			},
		);

		register_term_meta( 'lf_competition', 'lf_sport_slug', $args );
		register_term_meta( 'lf_season', 'lf_sport_slug', $args );
	}

	/**
	 * One-time migration for existing soccer-only content.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_data() {
		if ( get_option( self::MIGRATION_OPTION ) ) {
			return;
		}

		$this->ensure_enabled_terms();

		$soccer_term = get_term_by( 'slug', 'soccer', 'lf_sport' );

		if ( ! $soccer_term || is_wp_error( $soccer_term ) ) {
			update_option( self::MIGRATION_OPTION, 1, false );
			return;
		}

		$post_types = array( 'lf_team', 'lf_player', 'lf_match' );

		foreach ( $post_types as $post_type ) {
			$post_ids = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $post_ids as $post_id ) {
				$existing_terms = wp_get_object_terms( $post_id, 'lf_sport', array( 'fields' => 'ids' ) );

				if ( empty( $existing_terms ) || is_wp_error( $existing_terms ) ) {
					wp_set_object_terms( $post_id, array( (int) $soccer_term->term_id ), 'lf_sport', false );
				}
			}
		}

		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! get_term_meta( $term->term_id, 'lf_sport_slug', true ) ) {
					update_term_meta( $term->term_id, 'lf_sport_slug', 'soccer' );
				}
			}
		}

		update_option( self::MIGRATION_OPTION, 1, false );
	}

	/**
	 * Render the add-term sport selector.
	 *
	 * @return void
	 */
	public function render_term_sport_field() {
		$current = sanitize_key( (string) wp_unslash( $_GET['lf_sport'] ?? $_GET['sport'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$enabled = $this->get_enabled_sport_slugs();

		if ( ! $current && 1 === count( $enabled ) ) {
			$current = $enabled[0];
		}
		?>
		<div class="form-field term-group form-required">
			<label for="lf_sport_slug"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
			<select name="lf_sport_slug" id="lf_sport_slug" required aria-required="true">
				<option value=""><?php esc_html_e( 'Select a sport', 'leagueflow' ); ?></option>
				<?php foreach ( $this->get_enabled_sports() as $sport ) : ?>
					<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $current, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<p><?php esc_html_e( 'Required. Competitions and seasons belong to one sport, so sport pages and filters stay consistent.', 'leagueflow' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the edit-term sport selector.
	 *
	 * @param \WP_Term $term Term.
	 * @return void
	 */
	public function render_term_sport_field_edit( $term ) {
		$current = get_term_meta( $term->term_id, 'lf_sport_slug', true );
		?>
		<tr class="form-field term-group-wrap">
			<th scope="row"><label for="lf_sport_slug"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label></th>
			<td>
				<select name="lf_sport_slug" id="lf_sport_slug" required aria-required="true">
					<option value=""><?php esc_html_e( 'Select a sport', 'leagueflow' ); ?></option>
					<?php foreach ( $this->get_enabled_sports() as $sport ) : ?>
						<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $current, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
					<?php endforeach; ?>
					<?php if ( $current && ! in_array( $current, $this->get_enabled_sport_slugs(), true ) && isset( static::get_definitions()[ $current ] ) ) : ?>
						<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: sport */ __( '%s (disabled)', 'leagueflow' ), static::get_definitions()[ $current ]['label'] ) ); ?></option>
					<?php endif; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Use the same sport across competitions, teams, and matches for consistent filtering.', 'leagueflow' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save term sport metadata.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_term_sport_field( $term_id ) {
		// Only the add/edit term forms carry the field. Quick Edit and programmatic
		// updates must leave the stored sport alone (they used to reset it).
		if ( ! current_user_can( 'manage_categories' ) || ! isset( $_POST['lf_sport_slug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$raw = wp_unslash( $_POST['lf_sport_slug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slug = is_string( $raw ) ? sanitize_key( $raw ) : '';

		// A blank choice never falls back to the first enabled sport.
		if ( '' === $slug || ! isset( static::get_definitions()[ $slug ] ) ) {
			return;
		}
		$error = $this->validate_term_sport( $term_id, $slug );
		if ( is_wp_error( $error ) ) {
			set_transient( 'leagueflow_term_sport_error_' . get_current_user_id(), $error->get_error_message(), 120 );
			return;
		}

		update_term_meta( $term_id, 'lf_sport_slug', $slug );
		flush_current_season_cache();
	}

	/**
	 * A competition/season cannot move away from the sport of its records.
	 * Disabled sports may be retained on existing terms, never newly assigned.
	 *
	 * @param int    $term_id Existing term, or 0 for a new term.
	 * @param string $slug Proposed sport slug.
	 * @return true|\WP_Error Validation result.
	 */
	public function validate_term_sport( $term_id, $slug ) {
		if ( ! is_string( $slug ) || ! isset( static::get_definitions()[ $slug ] ) ) {
			return new \WP_Error( 'leagueflow_sport_required', __( 'Choose the sport this competition or season belongs to.', 'leagueflow' ), array( 'status' => 400 ) );
		}
		$current = $term_id ? get_term_sport_slug( $term_id ) : '';
		if ( $current === $slug ) {
			return true;
		}
		if ( ! in_array( $slug, $this->get_enabled_sport_slugs(), true ) ) {
			return new \WP_Error( 'leagueflow_sport_disabled', __( 'Enable this sport in Sports Setup before assigning a competition or season to it.', 'leagueflow' ), array( 'status' => 400 ) );
		}
		$term = $term_id ? get_term( $term_id ) : null;
		if ( $term instanceof \WP_Term && in_array( $term->taxonomy, array( 'lf_competition', 'lf_season' ), true ) ) {
			$ids = get_objects_in_term( $term_id, $term->taxonomy );
			if ( is_wp_error( $ids ) ) { return $ids; }
			foreach ( $ids as $post_id ) {
				$slugs = wp_get_object_terms( $post_id, 'lf_sport', array( 'fields' => 'slugs' ) );
				if ( is_wp_error( $slugs ) || ( $slugs && ! in_array( $slug, $slugs, true ) ) ) {
					return new \WP_Error( 'leagueflow_term_sport_in_use', __( 'This competition or season is assigned to records in another sport. Its sport was kept. Create a separate competition or season for the new sport, or correct those record assignments first.', 'leagueflow' ), array( 'status' => 400 ) );
				}
			}
		}
		return true;
	}

	/**
	 * Validate native term REST writes before WordPress updates the term.
	 * WordPress 6.5's term controller does not honour WP_Error from its
	 * rest_pre_insert filter, so rejection must happen before the callback.
	 */
	public function validate_rest_term_sport( $response, $handler, $request ) {
		unset( $handler );
		if ( null !== $response || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true )
			|| ! preg_match( '#^/wp/v2/(lf_competition|lf_season)(?:/([0-9]+))?/?$#', $request->get_route(), $matches ) ) {
			return $response;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) || ! array_key_exists( 'lf_sport_slug', $meta ) ) {
			return $response;
		}
		$result = $this->validate_term_sport( isset( $matches[2] ) ? (int) $matches[2] : 0, $meta['lf_sport_slug'] );
		return is_wp_error( $result ) ? $result : $response;
	}

	/** Show a refused taxonomy sport change on the returning term screen. */
	public function render_term_sport_notices() {
		$key = 'leagueflow_term_sport_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Refuse to create a competition or season from the admin form without a sport.
	 *
	 * @param string|\WP_Error $term Term name or error.
	 * @param string           $taxonomy Taxonomy.
	 * @param array<mixed>     $args Insert args.
	 * @return string|\WP_Error
	 */
	public function require_term_sport_on_add( $term, $taxonomy, $args = array() ) {
		unset( $args );

		if ( is_wp_error( $term ) || ! in_array( $taxonomy, array( 'lf_competition', 'lf_season' ), true ) ) {
			return $term;
		}

		// Only the add-term form; programmatic inserts keep their own contract.
		if ( 'add-tag' !== sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) || ! isset( $_POST['lf_sport_slug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $term;
		}

		$raw = wp_unslash( $_POST['lf_sport_slug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slug = is_string( $raw ) ? sanitize_key( $raw ) : '';
		$result = $this->validate_term_sport( 0, $slug );
		return is_wp_error( $result ) ? $result : $term;
	}

	/**
	 * Add the sport column to taxonomy tables.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_term_sport_column( $columns ) {
		$columns['lf_sport_slug'] = __( 'Sport', 'leagueflow' );
		return $columns;
	}

	/**
	 * Render the sport column value.
	 *
	 * @param string $value Existing value.
	 * @param string $column Column name.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function render_term_sport_column( $value, $column, $term_id ) {
		if ( 'lf_sport_slug' !== $column ) {
			return $value;
		}

		$slug = get_term_meta( $term_id, 'lf_sport_slug', true );

		if ( ! $slug ) {
			return '&mdash;';
		}

		$sport = $this->get_definition( $slug );
		return esc_html( $sport['label'] );
	}

	/**
	 * Get match meta fields for a sport.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return array<int, array<string, string>>
	 */
	public function get_match_fields( $sport_slug ) {
		$definition = $this->get_definition( $sport_slug );
		$fields     = $definition['match_fields'];

		$fields[] = array(
			'key'         => 'lf_notes',
			'label'       => __( 'Notes', 'leagueflow' ),
			'description' => __( 'General match notes, incidents, or admin comments.', 'leagueflow' ),
		);

		return $fields;
	}

	/**
	 * Get all unique sport-specific match meta keys.
	 *
	 * @return array<int, string>
	 */
	public static function get_all_match_meta_keys() {
		$keys = array( 'lf_notes' );

		foreach ( static::get_definitions() as $sport ) {
			foreach ( $sport['match_fields'] as $field ) {
				$keys[] = $field['key'];
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Build admin menu icon data URI.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return string
	 */
	public function get_menu_icon_data_uri( $sport_slug ) {
		$svg = $this->get_icon_svg( $sport_slug );

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Get the current sport slug for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_post_sport_slug( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'lf_sport' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return 'soccer';
		}

		return $terms[0]->slug;
	}

	/**
	 * Get the sport label for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_post_sport_label( $post_id ) {
		$definition = $this->get_definition( $this->get_post_sport_slug( $post_id ) );
		return $definition['label'];
	}

	/**
	 * Render the icon SVG string for a sport.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return string
	 */
	protected function get_icon_svg( $sport_slug ) {
		return sport_icon_svg(
			$sport_slug,
			array(
				'class'  => 'leagueflow-menu-sport-icon',
				'width'  => '20',
				'height' => '20',
			)
		);
	}
}
