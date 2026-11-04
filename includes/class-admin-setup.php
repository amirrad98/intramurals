<?php
/**
 * Setup check: read-only league validation and an explicit, reviewed
 * competition/season mapping tool for records that have none.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Admin "Setup check" screen.
 *
 * Nothing on this screen writes on page view. The mapping tool never guesses:
 * a person chooses the sport, the record type, the target competition and/or
 * season (both owned by that sport) and the individual records. Only records
 * that have no term in the target taxonomy are offered, so an existing
 * assignment is never replaced. A preview is required; applying re-checks the
 * capability, nonce, sport, every record and the unchanged state before the
 * first write.
 */
class Admin_Setup {

	const PAGE        = 'leagueflow-setup';
	const CAPABILITY  = 'edit_others_posts';
	const PREVIEW_TTL = 900;

	/**
	 * Sports manager.
	 *
	 * @var Sports_Manager
	 */
	protected $sports_manager;

	/**
	 * Venue availability rules.
	 *
	 * @var Field_Availability_Manager
	 */
	protected $fields;

	/**
	 * Constructor.
	 *
	 * @param Sports_Manager             $sports_manager Sports.
	 * @param Field_Availability_Manager $fields Availability.
	 */
	public function __construct( Sports_Manager $sports_manager, Field_Availability_Manager $fields ) {
		$this->sports_manager = $sports_manager;
		$this->fields         = $fields;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_leagueflow_mapping_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_leagueflow_mapping_apply', array( $this, 'handle_apply' ) );
	}

	/**
	 * Record types the mapping tool can update.
	 *
	 * @return array<string, string>
	 */
	public static function record_types() {
		return array(
			'lf_team'  => __( 'Teams', 'leagueflow' ),
			'lf_match' => __( 'Matches', 'leagueflow' ),
		);
	}

	/**
	 * Statuses that count as real league records (not trash or auto-drafts).
	 *
	 * @return array<int, string>
	 */
	protected function statuses() {
		return array( 'publish', 'private', 'draft', 'pending', 'future' );
	}

	/**
	 * Count readable records of a sport, optionally missing a taxonomy.
	 *
	 * @param string $post_type Post type.
	 * @param string $sport_slug Sport.
	 * @param string $missing_taxonomy Taxonomy the record must not have ('' = any).
	 * @return int
	 */
	public function count_records( $post_type, $sport_slug, $missing_taxonomy = '' ) {
		$query = new \WP_Query( $this->record_query_args( $post_type, $sport_slug, $missing_taxonomy, 1 ) );

		return (int) $query->found_posts;
	}

	/**
	 * Query arguments for records of a sport, optionally without a taxonomy term.
	 *
	 * @param string $post_type Post type.
	 * @param string $sport_slug Sport.
	 * @param string $missing_taxonomy Taxonomy that must be empty.
	 * @param int    $per_page Page size (-1 = all).
	 * @return array<string, mixed>
	 */
	protected function record_query_args( $post_type, $sport_slug, $missing_taxonomy, $per_page ) {
		$tax_query = array(
			array(
				'taxonomy' => 'lf_sport',
				'field'    => 'slug',
				'terms'    => array( $sport_slug ),
			),
		);

		if ( $missing_taxonomy ) {
			$tax_query[] = array(
				'taxonomy' => $missing_taxonomy,
				'operator' => 'NOT EXISTS',
			);
		}

		return array(
			'post_type'              => $post_type,
			'post_status'            => $this->statuses(),
			'perm'                   => 'readable',
			'posts_per_page'         => $per_page,
			'no_found_rows'          => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'tax_query'              => $tax_query,
			'update_post_meta_cache' => false,
		);
	}

	/**
	 * Read-only checks for one sport.
	 *
	 * @param string $sport_slug Sport.
	 * @return array<int, array<string, mixed>> Rows: label, value, state (ok|attention|optional), help, link.
	 */
	public function checks( $sport_slug ) {
		$competitions = get_term_ids_by_sport( 'lf_competition', $sport_slug );
		$seasons      = get_term_ids_by_sport( 'lf_season', $sport_slug );
		$current      = get_current_season_id( $sport_slug );
		$current_term = $current ? get_term( $current, 'lf_season' ) : null;
		$teams        = $this->count_records( 'lf_team', $sport_slug );
		$matches      = $this->count_records( 'lf_match', $sport_slug );
		$levels       = get_terms( array( 'taxonomy' => 'lf_league_level', 'hide_empty' => false, 'fields' => 'ids' ) );
		$rules        = $this->fields->get_availabilities( array( 'sport_slug' => $sport_slug ) );
		$tax_link     = static function ( $taxonomy ) use ( $sport_slug ) {
			return add_query_arg( array( 'taxonomy' => $taxonomy, 'post_type' => 'lf_match', 'lf_sport' => $sport_slug ), admin_url( 'edit-tags.php' ) );
		};
		$rows         = array(
			array(
				'label' => __( 'League levels (shared by all sports)', 'leagueflow' ),
				'value' => is_array( $levels ) ? count( $levels ) : 0,
				'state' => is_array( $levels ) && $levels ? 'ok' : 'attention',
				'help'  => __( 'Teams are grouped into levels (divisions). Fixtures are generated per level.', 'leagueflow' ),
				'link'  => add_query_arg( array( 'taxonomy' => 'lf_league_level', 'post_type' => 'lf_match' ), admin_url( 'edit-tags.php' ) ),
			),
			array(
				'label' => __( 'Competitions for this sport', 'leagueflow' ),
				'value' => count( $competitions ),
				'state' => $competitions ? 'ok' : 'optional',
				'help'  => __( 'Optional. Needed only to filter tables, fixtures and brackets by competition.', 'leagueflow' ),
				'link'  => $tax_link( 'lf_competition' ),
			),
			array(
				'label' => __( 'Seasons for this sport', 'leagueflow' ),
				'value' => count( $seasons ),
				'state' => $seasons ? 'ok' : 'optional',
				'help'  => __( 'Optional. Needed only to separate one season from another.', 'leagueflow' ),
				'link'  => $tax_link( 'lf_season' ),
			),
			array(
				'label' => __( 'Current season', 'leagueflow' ),
				'value' => $current_term instanceof \WP_Term ? $current_term->name : __( 'Not set', 'leagueflow' ),
				'state' => $current_term instanceof \WP_Term ? 'ok' : 'optional',
				'help'  => $current_term instanceof \WP_Term
					? __( 'Blocks and shortcodes without a season show this season for this sport.', 'leagueflow' )
					: __( 'Without a current season, blocks and shortcodes show every season. Mark one season as current in Seasons.', 'leagueflow' ),
				'link'  => $tax_link( 'lf_season' ),
			),
			array(
				'label' => __( 'Teams', 'leagueflow' ),
				'value' => $teams,
				'state' => $teams >= 2 ? 'ok' : 'attention',
				'help'  => __( 'At least two teams in a level are needed to generate fixtures.', 'leagueflow' ),
				'link'  => add_query_arg( array( 'post_type' => 'lf_team', 'lf_sport' => $sport_slug ), admin_url( 'edit.php' ) ),
			),
			array(
				'label' => __( 'Teams without a level', 'leagueflow' ),
				'value' => $this->count_records( 'lf_team', $sport_slug, 'lf_league_level' ),
				'state' => $this->count_records( 'lf_team', $sport_slug, 'lf_league_level' ) ? 'attention' : 'ok',
				'help'  => __( 'These teams are left out of fixture generation and level tables. Set a level on each team.', 'leagueflow' ),
				'link'  => add_query_arg( array( 'post_type' => 'lf_team', 'lf_sport' => $sport_slug ), admin_url( 'edit.php' ) ),
			),
		);

		foreach ( array( 'lf_team' => __( 'Teams', 'leagueflow' ), 'lf_match' => __( 'Matches', 'leagueflow' ) ) as $post_type => $type_label ) {
			foreach ( array( 'lf_competition' => __( 'without a competition', 'leagueflow' ), 'lf_season' => __( 'without a season', 'leagueflow' ) ) as $taxonomy => $suffix ) {
				$count  = $this->count_records( $post_type, $sport_slug, $taxonomy );
				$rows[] = array(
					'label' => $type_label . ' ' . $suffix,
					'value' => $count,
					'state' => $count ? 'optional' : 'ok',
					'help'  => $count
						? __( 'They still appear when no competition or season filter is chosen. To include them in a filtered view, map them explicitly below. Nothing is assigned automatically.', 'leagueflow' )
						: __( 'Every record has one.', 'leagueflow' ),
					'map'   => $count ? add_query_arg( array( 'page' => self::PAGE, 'lf_sport' => $sport_slug, 'map_type' => $post_type, 'map_taxonomy' => $taxonomy ), admin_url( 'admin.php' ) ) . '#leagueflow-mapping' : '',
					'link'  => $count ? add_query_arg( array( 'post_type' => $post_type, 'lf_sport' => $sport_slug, $taxonomy . '_filter' => 'none' ), admin_url( 'edit.php' ) ) : '',
				);
			}
		}

		$rows[] = array(
			'label' => __( 'Matches', 'leagueflow' ),
			'value' => $matches,
			'state' => 'ok',
			'help'  => __( 'Includes drafts and private matches you can read.', 'leagueflow' ),
			'link'  => add_query_arg( array( 'post_type' => 'lf_match', 'lf_sport' => $sport_slug ), admin_url( 'edit.php' ) ),
		);
		$rows[] = array(
			'label' => __( 'Venue availability rules', 'leagueflow' ),
			'value' => count( $rules ),
			'state' => $rules ? 'ok' : 'optional',
			'help'  => $rules
				? __( 'The scheduling assistant can place undated matches into these slots.', 'leagueflow' )
				: __( 'Needed only for automatic scheduling. Matches can always be dated by hand.', 'leagueflow' ),
			'link'  => add_query_arg( array( 'page' => 'leagueflow-fields', 'lf_sport' => $sport_slug ), admin_url( 'admin.php' ) ),
		);

		return $rows;
	}

	/**
	 * Records of a sport that have no term in the taxonomy.
	 *
	 * @param string $post_type Post type.
	 * @param string $sport_slug Sport.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, \WP_Post>
	 */
	public function unassigned_records( $post_type, $sport_slug, $taxonomy ) {
		$posts = get_posts( $this->record_query_args( $post_type, $sport_slug, $taxonomy, -1 ) );

		return array_values(
			array_filter(
				$posts,
				static function ( $post ) {
					return current_user_can( 'edit_post', $post->ID );
				}
			)
		);
	}

	/**
	 * Validate a mapping request against the stored state. No writes.
	 *
	 * @param array<string, mixed> $input sport, type, taxonomy, term_id, record_ids.
	 * @return array<string, mixed>|\WP_Error Plan with rows, conflicts and a state token.
	 */
	public function plan( $input ) {
		$sport_slug = sanitize_key( (string) ( $input['sport'] ?? '' ) );
		$post_type  = sanitize_key( (string) ( $input['type'] ?? '' ) );
		$taxonomy   = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$raw_term   = $input['term_id'] ?? 0;
		$term_id    = Match_Status::is_valid_score( $raw_term ) ? (int) $raw_term : 0;

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new \WP_Error( 'leagueflow_mapping_denied', __( 'You cannot change league records.', 'leagueflow' ) );
		}
		$record_ids = $this->validate_record_ids( $input['record_ids'] ?? array() );
		if ( is_wp_error( $record_ids ) ) { return $record_ids; }

		if ( ! in_array( $sport_slug, $this->sports_manager->get_enabled_sport_slugs(), true ) ) {
			return new \WP_Error( 'leagueflow_mapping_sport', __( 'Choose an enabled sport.', 'leagueflow' ) );
		}

		if ( ! isset( self::record_types()[ $post_type ] ) || ! in_array( $taxonomy, array( 'lf_competition', 'lf_season' ), true ) ) {
			return new \WP_Error( 'leagueflow_mapping_type', __( 'Choose teams or matches, and a competition or season.', 'leagueflow' ) );
		}

		$term = $term_id ? get_term( $term_id, $taxonomy ) : null;

		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'leagueflow_mapping_term', __( 'Choose the competition or season to assign.', 'leagueflow' ) );
		}

		if ( get_term_sport_slug( $term->term_id ) !== $sport_slug ) {
			return new \WP_Error( 'leagueflow_mapping_cross_sport', __( 'That competition or season belongs to another sport (or to none). Nothing was changed.', 'leagueflow' ) );
		}

		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object || ! current_user_can( $taxonomy_object->cap->assign_terms ) ) {
			return new \WP_Error( 'leagueflow_mapping_denied', __( 'You cannot assign these terms.', 'leagueflow' ) );
		}

		if ( ! $record_ids ) {
			return new \WP_Error( 'leagueflow_mapping_records', __( 'Select at least one record.', 'leagueflow' ) );
		}

		$rows      = array();
		$conflicts = array();
		$state     = array( get_current_user_id(), $sport_slug, $post_type, $taxonomy, $term->term_id );

		foreach ( $record_ids as $record_id ) {
			$post   = get_post( $record_id );
			$reason = '';

			if ( ! $post instanceof \WP_Post || $post->post_type !== $post_type ) {
				$reason = __( 'not a record of this type', 'leagueflow' );
			} elseif ( ! in_array( $post->post_status, $this->statuses(), true ) ) {
				$reason = __( 'in the Trash or not a saved record', 'leagueflow' );
			} elseif ( ! current_user_can( 'edit_post', $record_id ) ) {
				$reason = __( 'you cannot edit it', 'leagueflow' );
			} elseif ( get_post_primary_term_slug( $record_id, 'lf_sport' ) !== $sport_slug ) {
				$reason = __( 'it belongs to another sport', 'leagueflow' );
			} elseif ( wp_get_object_terms( $record_id, $taxonomy, array( 'fields' => 'ids' ) ) ) {
				$reason = __( 'it already has one (existing assignments are never replaced)', 'leagueflow' );
			} elseif ( 'lf_match' === $post_type && $this->has_bracket_links( $record_id ) ) {
				$reason = __( 'it is part of a linked knockout bracket. Review the linked matches together; remove bracket links before assigning a competition or season here, then restore compatible links.', 'leagueflow' );
			}

			if ( '' !== $reason ) {
				$conflicts[] = array( 'id' => $record_id, 'reason' => $reason );
				continue;
			}

			$rows[]  = array( 'id' => $record_id, 'title' => $post->post_title, 'status' => $post->post_status );
			// Title, status, sport and modification time: any change makes a preview stale.
			$state[] = $record_id . '@' . $post->post_modified_gmt . '@' . $post->post_status . '@' . md5( $post->post_title ) . '@' . get_post_primary_term_slug( $record_id, 'lf_sport' );
		}

		return array(
			'sport'      => $sport_slug,
			'type'       => $post_type,
			'taxonomy'   => $taxonomy,
			'term_id'    => (int) $term->term_id,
			'term_name'  => $term->name,
			'record_ids' => $record_ids,
			'rows'       => $rows,
			'conflicts'  => $conflicts,
			'token'      => substr( hash( 'sha256', implode( '|', $state ) . '|' . count( $conflicts ) ), 0, 24 ),
		);
	}

	/** Reject a malformed selection instead of silently dropping or changing IDs. */
	protected function validate_record_ids( $raw ) {
		$error = new \WP_Error( 'leagueflow_mapping_invalid_records', __( 'The selected records are invalid. Nothing was changed; select them again and preview.', 'leagueflow' ) );
		if ( ! is_array( $raw ) ) { return $error; }
		foreach ( $raw as $id ) {
			if ( ! Match_Status::is_valid_score( $id ) || (int) $id < 1 ) { return $error; }
		}
		$ids = array_values( array_unique( array_map( 'intval', $raw ) ) );
		sort( $ids );
		return $ids;
	}

	/** Changing one linked match's context would detach it from its bracket. */
	protected function has_bracket_links( $match_id ) {
		if ( (int) get_post_meta( $match_id, 'lf_next_match_id', true ) || (int) get_post_meta( $match_id, '_lf_home_advanced_from', true ) || (int) get_post_meta( $match_id, '_lf_away_advanced_from', true ) ) {
			return true;
		}
		$feeders = get_posts( array(
			'post_type' => 'lf_match',
			'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page' => 1,
			'fields' => 'ids',
			'no_found_rows' => true,
			'meta_query' => array( array( 'key' => 'lf_next_match_id', 'value' => $match_id, 'type' => 'NUMERIC' ) ),
		) );
		return (bool) $feeders;
	}

	/**
	 * Apply a previewed plan.
	 *
	 * Mapping writes are serialized with a site-wide lock. Under the lock the
	 * term caches are cleared and the plan (capability, sport context, records,
	 * existing assignments) is recomputed; it must match the preview token
	 * exactly and have no conflicts, and the user-bound preview is consumed
	 * once. So of two conflicting previews only the first applies; the second
	 * becomes stale. Every check runs before the first write.
	 *
	 * Writes are not all-or-nothing: if WordPress refuses one assignment, the
	 * successful ones are kept and the result lists both, so the remaining
	 * records can be previewed again.
	 *
	 * @param array<string, mixed> $input Same input as plan().
	 * @param string               $token Token from the preview.
	 * @return array<string, mixed>|\WP_Error assigned, applied_ids, failed_ids, term_id, partial.
	 */
	public function apply( $input, $token ) {
		$lock = db_lock_name( 'map', 'mapping' );

		/**
		 * Seconds to wait for another mapping to finish.
		 *
		 * @param int $timeout Seconds.
		 */
		if ( ! acquire_db_lock( $lock, max( 0, (int) apply_filters( 'leagueflow_mapping_lock_timeout', 5 ) ) ) ) {
			return new \WP_Error( 'leagueflow_mapping_busy', __( 'Another mapping is being applied, or the lock is unavailable. Nothing was changed; preview again.', 'leagueflow' ) );
		}

		try {
			$record_ids = $this->validate_record_ids( $input['record_ids'] ?? array() );
			if ( is_wp_error( $record_ids ) ) { return $record_ids; }

			foreach ( $record_ids as $record_id ) {
				clean_post_cache( $record_id );
			}

			// Re-read assignments from the database, not from this request's cache.
			if ( $record_ids && isset( self::record_types()[ sanitize_key( (string) ( $input['type'] ?? '' ) ) ] ) ) {
				clean_object_term_cache( $record_ids, sanitize_key( (string) $input['type'] ) );
			}

			$plan = $this->plan( $input );

			if ( is_wp_error( $plan ) ) {
				return $plan;
			}

			if ( '' === (string) $token || ! hash_equals( $plan['token'], (string) $token ) ) {
				return new \WP_Error( 'leagueflow_mapping_stale', __( 'The records changed since the preview (or the preview expired). Nothing was changed; preview again.', 'leagueflow' ) );
			}

			if ( $plan['conflicts'] ) {
				return new \WP_Error( 'leagueflow_mapping_conflicts', __( 'Some selected records cannot be mapped. Nothing was changed; remove them and preview again.', 'leagueflow' ) );
			}

			$key    = $this->preview_key( $token );
			$stored = get_transient( $key );

			// Single use and bound to the user who previewed it.
			if ( ! is_array( $stored ) || (int) ( $stored['user'] ?? 0 ) !== get_current_user_id() || ! delete_transient( $key ) ) {
				return new \WP_Error( 'leagueflow_mapping_stale', __( 'The records changed since the preview (or the preview expired). Nothing was changed; preview again.', 'leagueflow' ) );
			}

			$applied = array();
			$failed  = array();

			foreach ( $plan['rows'] as $row ) {
				$result = wp_set_object_terms( $row['id'], array( $plan['term_id'] ), $plan['taxonomy'], true );

				if ( is_wp_error( $result ) || ! object_term_saved( $row['id'], $plan['term_id'], $plan['taxonomy'] ) ) {
					$failed[] = (int) $row['id'];
				} else {
					$applied[] = (int) $row['id'];
				}
			}
		} finally {
			release_db_lock( $lock );
		}

		return array(
			'assigned'    => count( $applied ),
			'applied_ids' => $applied,
			'failed_ids'  => $failed,
			'term_id'     => $plan['term_id'],
			'partial'     => (bool) $failed,
		);
	}

	/**
	 * Transient key for a preview.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	protected function preview_key( $token ) {
		return 'leagueflow_map_' . get_current_user_id() . '_' . substr( sanitize_key( $token ), 0, 24 );
	}

	/**
	 * Remember a preview so applying requires one.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return void
	 */
	public function remember_preview( $plan ) {
		set_transient( $this->preview_key( $plan['token'] ), array( 'user' => get_current_user_id() ), self::PREVIEW_TTL );
	}

	/**
	 * Mapping input from a POST.
	 *
	 * @return array<string, mixed>
	 */
	protected function posted_input() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
		return array(
			'sport'      => sanitize_key( wp_unslash( $_POST['lf_map_sport'] ?? '' ) ),
			'type'       => sanitize_key( wp_unslash( $_POST['lf_map_type'] ?? '' ) ),
			'taxonomy'   => sanitize_key( wp_unslash( $_POST['lf_map_taxonomy'] ?? '' ) ),
			'term_id'    => wp_unslash( $_POST['lf_map_term'] ?? 0 ),
			'record_ids' => wp_unslash( $_POST['lf_map_records'] ?? array() ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Preview handler: validates and shows the plan (no writes).
	 *
	 * @return void
	 */
	public function handle_preview() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You cannot change league records.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'leagueflow_mapping_preview', 'leagueflow_mapping_nonce' );

		$input = $this->posted_input();
		$plan  = $this->plan( $input );
		$args  = array( 'page' => self::PAGE, 'lf_sport' => $input['sport'], 'map_type' => $input['type'], 'map_taxonomy' => $input['taxonomy'], 'map_term' => $input['term_id'] );

		if ( is_wp_error( $plan ) ) {
			$args['map_error'] = $plan->get_error_code();
		} else {
			$this->remember_preview( $plan );
			set_transient( 'leagueflow_map_plan_' . get_current_user_id(), $plan, self::PREVIEW_TTL );
			$args['map_preview'] = $plan['token'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#leagueflow-mapping' );
		exit;
	}

	/**
	 * Apply handler.
	 *
	 * @return void
	 */
	public function handle_apply() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You cannot change league records.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'leagueflow_mapping_apply', 'leagueflow_mapping_nonce' );

		$input = $this->posted_input();
		$args  = array( 'page' => self::PAGE, 'lf_sport' => $input['sport'], 'map_type' => $input['type'], 'map_taxonomy' => $input['taxonomy'], 'map_term' => $input['term_id'] );

		if ( empty( $_POST['lf_map_confirm'] ) ) {
			$args['map_error'] = 'leagueflow_mapping_confirm';
		} else {
			$result = $this->apply( $input, sanitize_key( wp_unslash( $_POST['lf_map_token'] ?? '' ) ) );

			if ( is_wp_error( $result ) ) {
				$args['map_error'] = $result->get_error_code();
			} else {
				delete_transient( 'leagueflow_map_plan_' . get_current_user_id() );
				$args['map_done'] = (int) $result['assigned'];

				if ( $result['failed_ids'] ) {
					$args['map_failed'] = implode( ',', array_map( 'absint', $result['failed_ids'] ) );
				}
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#leagueflow-mapping' );
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @param string $sport_slug Sport context ('' = choose).
	 * @return void
	 */
	public function render_page( $sport_slug ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You cannot view the setup check.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap leagueflow-admin-page leagueflow-setup-page">
			<h1><?php esc_html_e( 'Setup check', 'leagueflow' ); ?></h1>
			<p class="leagueflow-page-intro"><?php esc_html_e( 'A read-only check of what each sport needs before fixtures, scheduling, standings and brackets work well. Opening this page never changes anything.', 'leagueflow' ); ?></p>
			<?php if ( '' === $sport_slug ) : ?>
				<?php $this->render_all_sports(); ?>
			<?php else : ?>
				<?php $this->render_sport( $sport_slug ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Summary across sports.
	 *
	 * @return void
	 */
	protected function render_all_sports() {
		$sports = $this->sports_manager->get_enabled_sports();

		if ( ! $sports ) {
			echo '<div class="leagueflow-empty-state"><p>' . esc_html__( 'No sport is enabled yet.', 'leagueflow' ) . '</p></div>';
			return;
		}
		?>
		<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Setup by sport', 'leagueflow' ); ?>">
			<table class="widefat striped leagueflow-setup-summary">
				<caption class="screen-reader-text"><?php esc_html_e( 'Setup status for each enabled sport', 'leagueflow' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Current season', 'leagueflow' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Needs attention', 'leagueflow' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Optional gaps', 'leagueflow' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sports as $slug => $sport ) : ?>
						<?php
						$checks  = $this->checks( $slug );
						$current = get_current_season_id( $slug );
						$term    = $current ? get_term( $current, 'lf_season' ) : null;
						?>
						<tr>
							<th scope="row"><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'lf_sport' => $slug ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $sport['label'] ); ?></a></th>
							<td><?php echo esc_html( $term instanceof \WP_Term ? $term->name : __( 'Not set (blocks show all seasons)', 'leagueflow' ) ); ?></td>
							<td><?php echo esc_html( (string) count( wp_list_filter( $checks, array( 'state' => 'attention' ) ) ) ); ?></td>
							<td><?php echo esc_html( (string) count( wp_list_filter( $checks, array( 'state' => 'optional' ) ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One sport: checks plus the mapping tool.
	 *
	 * @param string $sport_slug Sport.
	 * @return void
	 */
	protected function render_sport( $sport_slug ) {
		$states = array(
			'ok'        => __( 'OK', 'leagueflow' ),
			'attention' => __( 'Needs attention', 'leagueflow' ),
			'optional'  => __( 'Optional', 'leagueflow' ),
		);
		?>
		<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Setup checks', 'leagueflow' ); ?>">
			<table class="widefat striped leagueflow-setup-checks">
				<caption class="screen-reader-text"><?php esc_html_e( 'Setup checks for this sport', 'leagueflow' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Check', 'leagueflow' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Value', 'leagueflow' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'leagueflow' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it means', 'leagueflow' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->checks( $sport_slug ) as $row ) : ?>
						<tr>
							<th scope="row"><?php echo $row['link'] ? '<a href="' . esc_url( $row['link'] ) . '">' . esc_html( $row['label'] ) . '</a>' : esc_html( $row['label'] ); ?></th>
							<td><?php echo esc_html( (string) $row['value'] ); ?></td>
							<td><span class="leagueflow-badge leagueflow-badge--<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( $states[ $row['state'] ] ); ?></span></td>
							<td><?php echo esc_html( $row['help'] ); ?><?php if ( ! empty( $row['map'] ) ) : ?> <a href="<?php echo esc_url( $row['map'] ); ?>"><?php esc_html_e( 'Map them', 'leagueflow' ); ?></a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		$this->render_mapping( $sport_slug );
	}

	/**
	 * The explicit mapping tool.
	 *
	 * @param string $sport_slug Sport.
	 * @return void
	 */
	protected function render_mapping( $sport_slug ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only selection state.
		$type     = sanitize_key( wp_unslash( $_GET['map_type'] ?? 'lf_team' ) );
		$type     = isset( self::record_types()[ $type ] ) ? $type : 'lf_team';
		$taxonomy = 'lf_season' === sanitize_key( wp_unslash( $_GET['map_taxonomy'] ?? '' ) ) ? 'lf_season' : 'lf_competition';
		$term_id  = absint( wp_unslash( $_GET['map_term'] ?? 0 ) );
		$preview  = sanitize_key( wp_unslash( $_GET['map_preview'] ?? '' ) );
		$error    = sanitize_key( wp_unslash( $_GET['map_error'] ?? '' ) );
		$done     = isset( $_GET['map_done'] ) ? absint( wp_unslash( $_GET['map_done'] ) ) : null;
		$failed   = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['map_failed'] ?? '' ) ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$terms    = array();

		foreach ( get_term_ids_by_sport( $taxonomy, $sport_slug ) as $id ) {
			$term = get_term( $id, $taxonomy );

			if ( $term instanceof \WP_Term ) {
				$terms[ $term->term_id ] = $term->name;
			}
		}

		$errors = array(
			'leagueflow_mapping_confirm'     => __( 'Tick the confirmation box to apply the previewed changes. Nothing was changed.', 'leagueflow' ),
			'leagueflow_mapping_stale'       => __( 'The records changed since the preview, the preview expired, or it was already applied. Nothing was changed; preview again.', 'leagueflow' ),
			'leagueflow_mapping_conflicts'   => __( 'Some selected records cannot be mapped. Nothing was changed.', 'leagueflow' ),
			'leagueflow_mapping_cross_sport' => __( 'That competition or season belongs to another sport. Nothing was changed.', 'leagueflow' ),
			'leagueflow_mapping_records'     => __( 'Select at least one record. Nothing was changed.', 'leagueflow' ),
			'leagueflow_mapping_invalid_records' => __( 'The selected records are invalid. Nothing was changed; select them again and preview.', 'leagueflow' ),
			'leagueflow_mapping_term'        => __( 'Choose the competition or season to assign. Nothing was changed.', 'leagueflow' ),
			'leagueflow_mapping_busy'        => __( 'Another mapping was being applied, or the lock was unavailable. Nothing was changed; preview again.', 'leagueflow' ),
		);
		?>
		<section id="leagueflow-mapping" class="leagueflow-admin-card leagueflow-mapping" aria-labelledby="leagueflow-mapping-title" tabindex="-1">
			<h2 id="leagueflow-mapping-title"><?php esc_html_e( 'Assign a competition or season to records that have none', 'leagueflow' ); ?></h2>
			<p><?php esc_html_e( 'LeagueFlow never guesses which competition or season an older team or match belonged to. Choose them here: only records with no value are listed, existing values are never replaced, and you review a preview before anything is saved.', 'leagueflow' ); ?></p>

			<?php if ( null !== $done ) : ?>
				<div class="notice notice-success inline" role="status"><p><?php echo esc_html( sprintf( /* translators: %d: records updated */ _n( '%d record was updated.', '%d records were updated.', $done, 'leagueflow' ), $done ) ); ?></p></div>
				<?php if ( $failed ) : ?>
					<div class="notice notice-warning inline" role="alert"><p><?php echo esc_html( sprintf( /* translators: 1: number, 2: record IDs */ _n( '%1$d record could not be updated and was left unchanged: #%2$s. Preview it again.', '%1$d records could not be updated and were left unchanged: #%2$s. Preview them again.', count( $failed ), 'leagueflow' ), count( $failed ), implode( ', #', $failed ) ) ); ?></p></div>
				<?php endif; ?>
			<?php elseif ( $error ) : ?>
				<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $errors[ $error ] ?? __( 'The request was not valid. Nothing was changed.', 'leagueflow' ) ); ?></p></div>
			<?php endif; ?>

			<form method="get" class="leagueflow-filter-bar" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="hidden" name="lf_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
				<span class="leagueflow-filter-field">
					<label for="leagueflow-map-type"><?php esc_html_e( 'Records', 'leagueflow' ); ?></label>
					<select id="leagueflow-map-type" name="map_type">
						<?php foreach ( self::record_types() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<span class="leagueflow-filter-field">
					<label for="leagueflow-map-taxonomy"><?php esc_html_e( 'Missing', 'leagueflow' ); ?></label>
					<select id="leagueflow-map-taxonomy" name="map_taxonomy">
						<option value="lf_competition" <?php selected( $taxonomy, 'lf_competition' ); ?>><?php esc_html_e( 'Competition', 'leagueflow' ); ?></option>
						<option value="lf_season" <?php selected( $taxonomy, 'lf_season' ); ?>><?php esc_html_e( 'Season', 'leagueflow' ); ?></option>
					</select>
				</span>
				<?php submit_button( __( 'Show records', 'leagueflow' ), 'secondary', '', false ); ?>
			</form>

			<?php
			$plan = $preview ? get_transient( 'leagueflow_map_plan_' . get_current_user_id() ) : null;

			if ( is_array( $plan ) && $preview === $plan['token'] ) {
				$this->render_preview( $plan );
				echo '</section>';
				return;
			}

			if ( ! $terms ) {
				printf(
					'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
					esc_html__( 'This sport has none to assign yet.', 'leagueflow' ),
					esc_url( add_query_arg( array( 'taxonomy' => $taxonomy, 'post_type' => 'lf_match', 'lf_sport' => $sport_slug ), admin_url( 'edit-tags.php' ) ) ),
					esc_html( 'lf_season' === $taxonomy ? __( 'Add a season', 'leagueflow' ) : __( 'Add a competition', 'leagueflow' ) )
				);
				echo '</section>';
				return;
			}

			$records = $this->unassigned_records( $type, $sport_slug, $taxonomy );

			if ( ! $records ) {
				echo '<p>' . esc_html__( 'Every record you can edit already has one. Nothing to map.', 'leagueflow' ) . '</p></section>';
				return;
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-mapping-form">
				<input type="hidden" name="action" value="leagueflow_mapping_preview" />
				<input type="hidden" name="lf_map_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
				<input type="hidden" name="lf_map_type" value="<?php echo esc_attr( $type ); ?>" />
				<input type="hidden" name="lf_map_taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
				<?php wp_nonce_field( 'leagueflow_mapping_preview', 'leagueflow_mapping_nonce' ); ?>
				<p>
					<label for="leagueflow-map-term"><?php echo esc_html( 'lf_season' === $taxonomy ? __( 'Season to assign', 'leagueflow' ) : __( 'Competition to assign', 'leagueflow' ) ); ?></label>
					<select id="leagueflow-map-term" name="lf_map_term" required>
						<option value=""><?php esc_html_e( 'Choose one', 'leagueflow' ); ?></option>
						<?php foreach ( $terms as $id => $name ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $term_id, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<fieldset class="leagueflow-mapping-records">
					<legend><?php echo esc_html( sprintf( /* translators: 1: count, 2: record type */ __( '%1$d %2$s with none. Select the ones that belong to it:', 'leagueflow' ), count( $records ), strtolower( self::record_types()[ $type ] ) ) ); ?></legend>
					<p><label><input type="checkbox" data-leagueflow-select-all="lf_map_records[]" /> <?php esc_html_e( 'Select all listed', 'leagueflow' ); ?></label></p>
					<ul class="leagueflow-check-list">
						<?php foreach ( $records as $record ) : ?>
							<li><label><input type="checkbox" name="lf_map_records[]" value="<?php echo esc_attr( (string) $record->ID ); ?>" /> <?php echo esc_html( $record->post_title . ( 'publish' !== $record->post_status ? ' (' . $record->post_status . ')' : '' ) ); ?></label></li>
						<?php endforeach; ?>
					</ul>
				</fieldset>
				<?php submit_button( __( 'Preview changes', 'leagueflow' ), 'secondary', '', false ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Preview with the confirmation form.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return void
	 */
	protected function render_preview( $plan ) {
		?>
		<div class="leagueflow-mapping-preview">
			<h3><?php echo esc_html( sprintf( /* translators: 1: term name, 2: count */ __( 'Preview: assign "%1$s" to %2$d records', 'leagueflow' ), $plan['term_name'], count( $plan['rows'] ) ) ); ?></h3>
			<?php if ( $plan['rows'] ) : ?>
				<ul class="leagueflow-check-list">
					<?php foreach ( $plan['rows'] as $row ) : ?>
						<li><?php echo esc_html( $row['title'] ); ?> &rarr; <?php echo esc_html( $plan['term_name'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $plan['conflicts'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'These cannot be mapped, so applying is blocked until you preview without them:', 'leagueflow' ); ?></p>
					<ul>
						<?php foreach ( $plan['conflicts'] as $conflict ) : ?>
							<li><?php echo esc_html( sprintf( /* translators: 1: record ID, 2: reason */ __( '#%1$d: %2$s', 'leagueflow' ), $conflict['id'], $conflict['reason'] ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="leagueflow_mapping_apply" />
					<input type="hidden" name="lf_map_sport" value="<?php echo esc_attr( $plan['sport'] ); ?>" />
					<input type="hidden" name="lf_map_type" value="<?php echo esc_attr( $plan['type'] ); ?>" />
					<input type="hidden" name="lf_map_taxonomy" value="<?php echo esc_attr( $plan['taxonomy'] ); ?>" />
					<input type="hidden" name="lf_map_term" value="<?php echo esc_attr( (string) $plan['term_id'] ); ?>" />
					<input type="hidden" name="lf_map_token" value="<?php echo esc_attr( $plan['token'] ); ?>" />
					<?php foreach ( $plan['record_ids'] as $record_id ) : ?>
						<input type="hidden" name="lf_map_records[]" value="<?php echo esc_attr( (string) $record_id ); ?>" />
					<?php endforeach; ?>
					<?php wp_nonce_field( 'leagueflow_mapping_apply', 'leagueflow_mapping_nonce' ); ?>
					<p><label><input type="checkbox" name="lf_map_confirm" value="1" required /> <?php esc_html_e( 'I checked that these records belong to it.', 'leagueflow' ); ?></label></p>
					<?php submit_button( __( 'Apply', 'leagueflow' ), 'primary', '', false ); ?>
				</form>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'lf_sport' => $plan['sport'], 'map_type' => $plan['type'], 'map_taxonomy' => $plan['taxonomy'] ), admin_url( 'admin.php' ) ) . '#leagueflow-mapping' ); ?>"><?php esc_html_e( 'Cancel and choose again', 'leagueflow' ); ?></a></p>
		</div>
		<?php
	}
}
