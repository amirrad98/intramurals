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
	/** Original event assignments while core saves a classic editor request. */
	protected static $editor_event_context = array();

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
		// After the sport field (priority 10) so the season's sport is already saved.
		add_action( 'created_lf_season', array( $this, 'save_season_current_field' ), 20 );
		add_action( 'edited_lf_season', array( $this, 'save_season_current_field' ), 20 );
		add_action( 'delete_lf_season', 'LeagueFlow\\clear_current_season' );
		add_filter( 'manage_edit-lf_season_columns', array( $this, 'add_season_current_column' ) );
		add_filter( 'manage_lf_season_custom_column', array( $this, 'render_season_current_column' ), 10, 3 );
		add_action( 'rest_after_insert_lf_season', array( $this, 'save_rest_season_current' ), 10, 2 );
		add_filter( 'rest_prepare_lf_season', array( $this, 'prepare_rest_season_current' ), 10, 2 );
		add_filter( 'rest_pre_insert_lf_team', array( $this, 'validate_rest_team_context' ), 10, 2 );
		add_filter( 'rest_pre_insert_lf_calendar_event', array( $this, 'validate_rest_event_context' ), 10, 2 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_season_meta_cache' ), 10, 3 );
		}
	}

	/**
	 * Validate native team REST taxonomy edits before core changes the record.
	 * A team's sport is fixed after assignment, matching the classic team editor.
	 * Unrelated edits and unchanged legacy assignments remain available.
	 *
	 * @param object|\WP_Error $prepared_post Prepared team post.
	 * @param \WP_REST_Request $request REST request.
	 * @return object|\WP_Error
	 */
	public function validate_rest_team_context( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) ) { return $prepared_post; }
		$post_id = absint( $request->get_param( 'id' ) );
		$existing = array();
		$submitted = array();
		foreach ( array( 'lf_sport', 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			$param = $tax && $tax->rest_base ? $tax->rest_base : $taxonomy;
			$old_ids = $post_id ? wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ) : array();
			if ( is_wp_error( $old_ids ) ) { return $old_ids; }
			$existing[$taxonomy] = array_map( 'intval', $old_ids );
			sort( $existing[$taxonomy] );
			if ( ! $request->has_param( $param ) ) { continue; }
			$values = $request->get_param( $param );
			if ( ! is_array( $values ) ) {
				return new \WP_Error( 'leagueflow_team_context_invalid', __( 'Invalid team taxonomy selection.', 'leagueflow' ), array( 'status' => 400 ) );
			}
			foreach ( $values as $value ) {
				if ( ! Match_Status::is_valid_score( $value ) || (int) $value < 1 ) {
					return new \WP_Error( 'leagueflow_team_context_invalid', __( 'Invalid team taxonomy term ID.', 'leagueflow' ), array( 'status' => 400 ) );
				}
			}
			$submitted[$taxonomy] = array_values( array_unique( array_map( 'intval', $values ) ) );
			sort( $submitted[$taxonomy] );
		}
		if ( ! $submitted ) { return $prepared_post; }
		$sport_ids = $submitted['lf_sport'] ?? $existing['lf_sport'];
		$sport_changed = $sport_ids !== $existing['lf_sport'];
		if ( $sport_changed && $existing['lf_sport'] ) {
			return new \WP_Error( 'leagueflow_team_sport_locked', __( 'A team’s sport cannot be changed or cleared after it is assigned. Create a team in the other sport instead.', 'leagueflow' ), array( 'status' => 400 ) );
		}
		$sport = 1 === count( $sport_ids ) ? get_term( $sport_ids[0], 'lf_sport' ) : null;
		if ( $sport_changed && ( ! $sport instanceof \WP_Term || ! in_array( $sport->slug, ( new Sports_Manager() )->get_enabled_sport_slugs(), true ) ) ) {
			return new \WP_Error( 'leagueflow_team_sport_invalid', __( 'Choose one enabled sport for the team.', 'leagueflow' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$ids = $submitted[$taxonomy] ?? $existing[$taxonomy];
			if ( ! $sport_changed && $ids === $existing[$taxonomy] ) { continue; }
			foreach ( $ids as $id ) {
				$term = get_term( $id, $taxonomy );
				if ( ! $term instanceof \WP_Term || ! $sport instanceof \WP_Term || get_term_sport_slug( $id ) !== $sport->slug ) {
					return new \WP_Error( 'leagueflow_team_context_invalid', __( 'Choose existing competitions and seasons assigned to this team’s sport.', 'leagueflow' ), array( 'status' => 400 ) );
				}
			}
		}
		return $prepared_post;
	}

	/** Read the complete context so omitted fields keep their saved assignments. */
	protected static function get_event_context( $post_id ) {
		$context = array();
		foreach ( array( 'lf_sport', 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$ids = $post_id ? wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ) : array();
			$context[$taxonomy] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $context[$taxonomy] );
		}
		return $context;
	}

	/** Validate the resulting event context, preserving untouched legacy values. */
	protected static function validate_event_context( $candidate, $existing ) {
		$sport_changed = $candidate['lf_sport'] !== $existing['lf_sport'];
		$sport = 1 === count( $candidate['lf_sport'] ) ? get_term( $candidate['lf_sport'][0], 'lf_sport' ) : null;
		if ( $sport_changed && ( ! $sport instanceof \WP_Term || ! in_array( $sport->slug, ( new Sports_Manager() )->get_enabled_sport_slugs(), true ) ) ) {
			return new \WP_Error( 'leagueflow_event_sport_invalid', __( 'That sport is not enabled. Choose one enabled sport; the event’s sport and context were not changed.', 'leagueflow' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			if ( ! $sport_changed && $candidate[$taxonomy] === $existing[$taxonomy] ) { continue; }
			foreach ( $candidate[$taxonomy] as $id ) {
				$term = is_int( $id ) ? get_term( $id, $taxonomy ) : null;
				if ( ! $term instanceof \WP_Term || ! $sport instanceof \WP_Term || get_term_sport_slug( $id ) !== $sport->slug ) {
					return new \WP_Error( 'leagueflow_event_context_invalid', __( 'Choose existing competitions and seasons for the selected sport, or clear those selections. The event’s previous sport and context were kept.', 'leagueflow' ), array( 'status' => 400 ) );
				}
			}
		}
		return true;
	}

	/** Native REST rejects a mismatched resulting context before core writes. */
	public function validate_rest_event_context( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) ) { return $prepared_post; }
		$existing = self::get_event_context( absint( $request->get_param( 'id' ) ) );
		$candidate = $existing;
		foreach ( array_keys( $existing ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			$param = $tax && $tax->rest_base ? $tax->rest_base : $taxonomy;
			if ( ! $request->has_param( $param ) ) { continue; }
			$values = $request->get_param( $param );
			if ( ! is_array( $values ) ) { return new \WP_Error( 'leagueflow_event_context_invalid', __( 'Invalid event taxonomy selection.', 'leagueflow' ), array( 'status' => 400 ) ); }
			foreach ( $values as $value ) {
				if ( ! Match_Status::is_valid_score( $value ) || (int) $value < 1 ) {
					return new \WP_Error( 'leagueflow_event_context_invalid', __( 'Invalid event taxonomy term ID.', 'leagueflow' ), array( 'status' => 400 ) );
				}
			}
			$candidate[$taxonomy] = array_values( array_unique( array_map( 'intval', $values ) ) );
			sort( $candidate[$taxonomy] );
		}
		$valid = self::validate_event_context( $candidate, $existing );
		return is_wp_error( $valid ) ? $valid : $prepared_post;
	}

	/**
	 * Plan all classic event assignments together before the first taxonomy write.
	 * The request-local snapshot also lets the details handler preserve the original
	 * assignments if its date validation prevents the sport change from being saved.
	 */
	public static function prepare_editor_event_context( $post_id ) {
		$input = isset( $_POST['tax_input'] ) && is_array( $_POST['tax_input'] ) ? $_POST['tax_input'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies taxonomy edits.
		$choice = $_POST['lf_sport_choice'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = $_POST['leagueflow_calendar_event_nonce'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$signature = md5( wp_json_encode( array( $input, $choice, $nonce ) ) );
		if ( isset( self::$editor_event_context[$post_id] ) && self::$editor_event_context[$post_id]['signature'] === $signature ) {
			return self::$editor_event_context[$post_id];
		}
		$existing = self::get_event_context( $post_id );
		$candidate = $existing;
		$error = null;
		$has_choice = is_string( $nonce ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'leagueflow_save_calendar_event' ) && null !== $choice;
		if ( $has_choice ) {
			if ( ! is_string( $choice ) ) {
				$error = new \WP_Error( 'leagueflow_event_sport_invalid', __( 'Invalid event sport selection. The previous sport and context were kept.', 'leagueflow' ) );
			} elseif ( '' !== trim( $choice ) ) {
				$sport = get_term_by( 'slug', sanitize_key( wp_unslash( $choice ) ), 'lf_sport' );
				$candidate['lf_sport'] = $sport instanceof \WP_Term ? array( (int) $sport->term_id ) : array( 0 );
			}
		}
		foreach ( array( 'lf_sport', 'lf_competition', 'lf_season' ) as $taxonomy ) {
			if ( ! array_key_exists( $taxonomy, $input ) ) { continue; }
			$values = $input[$taxonomy];
			$malformed = false;
			foreach ( is_array( $values ) ? $values : array( $values ) as $value ) {
				if ( ! is_string( $value ) && ! is_int( $value ) ) { $malformed = true; }
			}
			if ( $malformed ) {
				$error = new \WP_Error( 'leagueflow_event_context_invalid', __( 'Invalid event taxonomy selection. The previous sport and context were kept.', 'leagueflow' ) );
				continue;
			}
			$ids = array_values( array_unique( taxonomy_meta_box_sanitize_cb_input( $taxonomy, $values ) ) );
			sort( $ids );
			if ( 'lf_sport' === $taxonomy ) {
				// The hidden sport taxonomy cannot bypass the details selector.
				if ( $ids !== $existing['lf_sport'] && ( ! $has_choice || $ids !== $candidate['lf_sport'] ) ) {
					$error = new \WP_Error( 'leagueflow_event_sport_invalid', __( 'Choose the sport in Calendar Event Details. The previous sport and context were kept.', 'leagueflow' ) );
				}
			} else {
				$candidate[$taxonomy] = $ids;
			}
		}
		$valid = $error ?: self::validate_event_context( $candidate, $existing );
		$plan = array( 'signature' => $signature, 'existing' => $existing, 'candidate' => $candidate, 'error' => is_wp_error( $valid ) ? $valid : null );
		self::$editor_event_context[$post_id] = $plan;
		return $plan;
	}

	/** Keep the snapshot until the event details handler has finished. */
	public static function clear_editor_event_context( $post_id ) {
		unset( self::$editor_event_context[$post_id] );
	}

	/** The same rejected form may pass through several taxonomy callbacks. */
	public static function notice_editor_event_context( $error ) {
		$key = 'leagueflow_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notice = array( 'type' => 'error', 'message' => $error->get_error_message(), 'link' => '', 'link_text' => '' );
		if ( ! in_array( $notice, $notices, true ) ) { $notices[] = $notice; }
		set_transient( $key, $notices, MINUTE_IN_SECONDS );
	}

	/** Revert only event context when its details prevent a pending sport change. */
	public static function restore_editor_event_context( $post_id, $plan ) {
		foreach ( $plan['existing'] as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );
		}
	}

	/** Calendar sport changes come from the validated details selector. */
	public function sanitize_editor_sport_terms( $taxonomy, $terms ) {
		$post_id = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'lf_calendar_event' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return taxonomy_meta_box_sanitize_cb_input( $taxonomy, $terms );
		}
		$plan = self::prepare_editor_event_context( $post_id );
		if ( $plan['error'] ) { self::notice_editor_event_context( $plan['error'] ); }
		return $plan['existing']['lf_sport'];
	}

	/** Keep current-season lookups fresh after native term or REST meta changes. */
	public function invalidate_season_meta_cache( $meta_id, $term_id, $meta_key ) {
		unset( $meta_id );
		if ( in_array( $meta_key, array( 'lf_sport_slug', 'lf_is_current' ), true ) && get_term( $term_id, 'lf_season' ) instanceof \WP_Term ) {
			flush_current_season_cache();
		}
	}

	/** Apply the same per-sport current-season semantics to native REST edits. */
	public function save_rest_season_current( $term, $request ) {
		$meta = $request->get_param( 'meta' );
		if ( ! current_user_can( 'manage_categories' ) || ! is_array( $meta ) || ! array_key_exists( 'lf_is_current', $meta ) ) {
			return;
		}
		if ( rest_sanitize_boolean( $meta['lf_is_current'] ) ) {
			set_current_season( $term->term_id );
		} else {
			clear_current_season( $term->term_id );
		}
	}

	/** The REST flag reflects the canonical per-sport map, not only legacy meta. */
	public function prepare_rest_season_current( $response, $term ) {
		if ( isset( $response->data['meta'] ) && is_array( $response->data['meta'] ) ) {
			$response->data['meta']['lf_is_current'] = (bool) is_current_season( $term->term_id );
		}
		return $response;
	}

	/**
	 * Validate classic editor context before core writes taxonomy assignments.
	 * WordPress saves these before save_post, outside the protected result save.
	 * Rejected assignments keep their old IDs; the result handler separately
	 * checks that its teams and the resulting context still agree.
	 *
	 * @param string       $taxonomy Competition or season taxonomy.
	 * @param array|string $terms Submitted tag-box values (usually names).
	 * @return array<int, int|string> Terms WordPress may assign.
	 */
	public function sanitize_editor_context_terms( $taxonomy, $terms ) {
		$post_id = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the post edit request.
		$type = $post_id ? get_post_type( $post_id ) : '';
		if ( 'lf_calendar_event' === $type && current_user_can( 'edit_post', $post_id ) ) {
			$plan = self::prepare_editor_event_context( $post_id );
			if ( $plan['error'] ) {
				self::notice_editor_event_context( $plan['error'] );
				return $plan['existing'][$taxonomy];
			}
			return $plan['candidate'][$taxonomy];
		}
		if ( ! in_array( $type, array( 'lf_team', 'lf_match' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return taxonomy_meta_box_sanitize_cb_input( $taxonomy, $terms );
		}
		$existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$existing = is_wp_error( $existing ) ? array() : array_map( 'intval', $existing );
		$reject = function ( $message ) use ( $existing ) {
			$key = 'leagueflow_notices_' . get_current_user_id();
			$notices = get_transient( $key );
			$notices = is_array( $notices ) ? $notices : array();
			$notices[] = array( 'type' => 'error', 'message' => $message, 'link' => '', 'link_text' => '' );
			set_transient( $key, $notices, MINUTE_IN_SECONDS );
			return $existing;
		};
		foreach ( is_array( $terms ) ? $terms : array( $terms ) as $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return $reject( __( 'The competition or season selection was invalid. Its previous assignment was kept.', 'leagueflow' ) );
			}
		}
		$clean = taxonomy_meta_box_sanitize_cb_input( $taxonomy, $terms );
		$clean = array_values( array_unique( $clean ) );
		$old_sorted = $existing;
		$new_sorted = $clean;
		sort( $old_sorted );
		sort( $new_sorted );
		// Historical multiple-term assignments must remain reviewable, not be
		// trimmed silently when somebody edits the title or notes.
		if ( $old_sorted === $new_sorted ) { return $existing; }
		if ( 'lf_match' === $type && count( $clean ) > 1 ) {
			return $reject( __( 'Choose at most one competition and one season for a match so its scoring rules are unambiguous. The previous assignment was kept.', 'leagueflow' ) );
		}
		$sport = get_post_primary_term_slug( $post_id, 'lf_sport' );
		if ( 'lf_match' === $type && isset( $_POST['leagueflow_match_nonce'] ) && is_string( $_POST['leagueflow_match_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['leagueflow_match_nonce'] ) ), 'leagueflow_save_match' ) ) {
			$home = isset( $_POST['lf_home_team_id'] ) && is_scalar( $_POST['lf_home_team_id'] ) ? absint( $_POST['lf_home_team_id'] ) : 0;
			$away = isset( $_POST['lf_away_team_id'] ) && is_scalar( $_POST['lf_away_team_id'] ) ? absint( $_POST['lf_away_team_id'] ) : 0;
			$home_sport = 'lf_team' === get_post_type( $home ) ? get_post_primary_term_slug( $home, 'lf_sport' ) : '';
			$away_sport = 'lf_team' === get_post_type( $away ) ? get_post_primary_term_slug( $away, 'lf_sport' ) : '';
			if ( $home_sport && $away_sport && $home_sport === $away_sport ) { $sport = $home_sport; }
		} elseif ( ! $sport && 'lf_team' === $type && isset( $_POST['leagueflow_team_nonce'] ) && is_string( $_POST['leagueflow_team_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['leagueflow_team_nonce'] ) ), 'leagueflow_save_team' ) ) {
			$choice = isset( $_POST['lf_sport_choice'] ) && is_string( $_POST['lf_sport_choice'] ) ? sanitize_key( wp_unslash( $_POST['lf_sport_choice'] ) ) : '';
			if ( in_array( $choice, ( new Sports_Manager() )->get_enabled_sport_slugs(), true ) ) { $sport = $choice; }
		}
		foreach ( $clean as $term_id ) {
			$term = is_int( $term_id ) ? get_term( $term_id, $taxonomy ) : null;
			if ( ! $term instanceof \WP_Term || ! $sport || get_term_sport_slug( $term_id ) !== $sport ) {
				return $reject( __( 'Choose an existing competition or season for this record’s sport. Create new ones from LeagueFlow → Competitions or Seasons. The previous assignment was kept.', 'leagueflow' ) );
			}
		}
		return $clean;
	}

	/**
	 * Add a "Current" column to the Seasons list.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_season_current_column( $columns ) {
		$columns['lf_current'] = __( 'Current', 'leagueflow' );

		return $columns;
	}

	/**
	 * Show whether a season is its sport's current season.
	 *
	 * @param string $content Column content.
	 * @param string $column Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function render_season_current_column( $content, $column, $term_id ) {
		if ( 'lf_current' !== $column ) {
			return $content;
		}

		if ( ! is_current_season( $term_id ) ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not current', 'leagueflow' ) . '</span>';
		}

		return '' === get_term_sport_slug( $term_id )
			? esc_html__( 'Current (all sports, legacy)', 'leagueflow' )
			: esc_html__( 'Current for its sport', 'leagueflow' );
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
				<input type="hidden" name="leagueflow_season_current_present" value="1" />
				<input type="checkbox" name="lf_is_current" value="1" />
				<?php esc_html_e( 'Make this the current season for its sport', 'leagueflow' ); ?>
			</label>
			<p><?php esc_html_e( 'Each sport has its own current season; setting one never changes another sport. Blocks and shortcodes without a season use the current season of their sport. Admin screens show all seasons unless you pick Current.', 'leagueflow' ); ?></p>
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
		$is_current = is_current_season( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Current season', 'leagueflow' ); ?></th>
			<td>
				<label>
					<input type="hidden" name="leagueflow_season_current_present" value="1" />
					<input type="checkbox" name="lf_is_current" value="1" <?php checked( $is_current ); ?> />
					<?php esc_html_e( 'Make this the current season for its sport', 'leagueflow' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Each sport has one current season. Setting this replaces the current season of this sport only; other sports keep theirs. A season without a sport uses the older site-wide setting.', 'leagueflow' ); ?></p>
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

		// REST, imports and programmatic renames do not submit the season form.
		// An absent checkbox only means "clear" when that form was actually shown.
		if ( ! isset( $_POST['leagueflow_season_current_present'] ) && ! isset( $_POST['lf_is_current'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			flush_current_season_cache();
			return;
		}
		// Quick Edit does not post this field; leave the current season alone there.
		if ( ! isset( $_POST['lf_is_current'] ) && ( isset( $_POST['action'] ) && 'inline-save-tax' === $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			flush_current_season_cache();
			return;
		}

		if ( ! empty( $_POST['lf_is_current'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			set_current_season( $term_id );
		} elseif ( is_current_season( $term_id ) || get_term_meta( $term_id, 'lf_is_current', true ) ) {
			clear_current_season( $term_id );
		}

		flush_current_season_cache();
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
				'meta_box_sanitize_cb' => array( $this, 'sanitize_editor_sport_terms' ),
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
					'not_found'     => __( 'No sports yet. Enable sports in LeagueFlow > Sports.', 'leagueflow' ),
				),
				// Sport terms are owned by LeagueFlow > Sports (manage_options), which
				// creates and names them. Creating, renaming or deleting one elsewhere
				// (the generic term screen, a tag box) needs that same authority;
				// assigning an existing sport keeps WordPress's default (edit_posts).
				'capabilities'      => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				),
				'public'            => false,
				// No generic term screen, tag box, Quick Edit field or block-editor
				// panel: Team and Calendar Event details choose an enabled sport,
				// matches and players take theirs from teams. The list column stays.
				'show_ui'           => false,
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
					'not_found'     => __( 'No league levels yet. Levels (divisions) are shared by every sport; add one with the form on this page.', 'leagueflow' ),
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
				'meta_box_sanitize_cb' => array( $this, 'sanitize_editor_context_terms' ),
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
					'not_found'     => __( 'No competitions for this sport yet. Add one with the form on this page and choose its sport.', 'leagueflow' ),
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
				'meta_box_sanitize_cb' => array( $this, 'sanitize_editor_context_terms' ),
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
					'not_found'     => __( 'No seasons for this sport yet. Add one with the form on this page and choose its sport.', 'leagueflow' ),
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
