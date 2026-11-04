<?php
/**
 * Custom post type registrations.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Post type registration.
 */
class Post_Types {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'protect_private_rest_directories' ), 10, 3 );
		add_filter( 'rest_post_search_query', array( $this, 'filter_rest_directory_search' ) );
		add_filter( 'rest_pre_insert_lf_player', array( $this, 'validate_rest_identity' ), 10, 2 );
		add_filter( 'rest_pre_insert_lf_team', array( $this, 'validate_rest_identity' ), 10, 2 );
		add_filter( 'rest_pre_insert_lf_match', array( $this, 'protect_rest_match_workflow' ), 10, 2 );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', __NAMESPACE__ . '\\ensure_player_team_details_migration', 20 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'use_classic_editor_for_supported_types' ), 10, 2 );
	}

	/**
	 * Generic post metadata writes are sequential and bypass result locks, stale
	 * form checks and participant validation. Keep match workflow fields on the
	 * protected editor/referee paths, even if an integration enables custom-fields.
	 * Ordinary post content and publication edits remain supported.
	 */
	public function protect_rest_match_workflow( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) ) { return $prepared_post; }
		$meta = $request->get_param( 'meta' );
		$protected = array( 'lf_home_team_id', 'lf_away_team_id', 'lf_home_score', 'lf_away_score', 'lf_status', 'lf_status_changed_at', 'lf_outcome', 'lf_is_knockout', 'lf_winner_team_id', 'lf_next_match_id', 'lf_next_match_slot', 'lf_round_order', 'lf_round_label', 'lf_match_datetime', 'lf_venue', 'lf_duration_minutes', Field_Availability_Manager::META_AVAILABILITY_ID, Field_Availability_Manager::META_SCHEDULE_SOURCE, Field_Availability_Manager::META_SCHEDULED_AT );
		$blocked = is_array( $meta ) && (bool) array_intersect( array_keys( $meta ), $protected );
		foreach ( array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ) as $name ) {
			$taxonomy = get_taxonomy( $name );
			$param = $taxonomy && $taxonomy->rest_base ? $taxonomy->rest_base : $name;
			if ( $request->has_param( $param ) ) { $blocked = true; }
		}
		if ( $blocked ) {
			return new \WP_Error( 'leagueflow_match_workflow_required', __( 'Use the LeagueFlow match editor or assigned referee result form to change scores, participants, match status, scheduling or bracket context. Generic WordPress REST writes cannot safely save these fields.', 'leagueflow' ), array( 'status' => 400 ) );
		}
		return $prepared_post;
	}

	/** Keep the REST directory consistent with the restricted portal/team pages. */
	public function protect_private_rest_directories( $result, $server, $request ) {
		if ( ! current_user_can( 'edit_posts' ) && preg_match( '#^/wp/v2/(lf_team|lf_player|lf_join_request)(?:/|$)#', $request->get_route() ) ) {
			return new \WP_Error( 'leagueflow_directory_forbidden', __( 'Directory access is restricted.', 'leagueflow' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( preg_match( '#^/wp/v2/lf_join_request(?:/|$)#', $request->get_route() ) && ! in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return new \WP_Error( 'leagueflow_request_workflow_required', __( 'Use the LeagueFlow request workflow to make request changes.', 'leagueflow' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * Account links carry portal authority, not just editable profile content.
	 *
	 * Validate before WordPress writes the post itself so a rejected identity
	 * payload cannot partially update its title or create an orphan profile.
	 * Generic metadata is currently absent from the CPT schemas, but integrations
	 * can enable custom-fields support; that must not weaken account permissions.
	 *
	 * @param object|\WP_Error $prepared_post Prepared post.
	 * @param \WP_REST_Request $request REST request.
	 * @return object|\WP_Error
	 */
	public function validate_rest_identity( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $prepared_post;
		}
		$type = $prepared_post->post_type ?? '';
		if ( 'lf_player' === $type && array_key_exists( Registration_Consent::META_KEY, $meta ) ) {
			return new \WP_Error( 'leagueflow_consent_readonly', __( 'Registration acknowledgements can only be recorded by the participant registration workflow.', 'leagueflow' ), array( 'status' => 403 ) );
		}
		$key = 'lf_player' === $type ? 'lf_user_id' : 'lf_manager_user_ids';
		if ( ! array_key_exists( $key, $meta ) ) {
			return $prepared_post;
		}
		$forbidden = new \WP_Error( 'leagueflow_identity_forbidden', __( 'Only authorized staff may assign eligible portal accounts.', 'leagueflow' ), array( 'status' => 403 ) );
		if ( ! can_manage_player_accounts() ) {
			return $forbidden;
		}
		$post_id = absint( $prepared_post->ID ?? 0 );
		if ( 'lf_player' === $type ) {
			if ( null !== $meta[ $key ] && ! $this->is_rest_user_id( $meta[ $key ] ) ) {
				return new \WP_Error( 'leagueflow_invalid_identity', __( 'An account ID must be a non-negative integer.', 'leagueflow' ), array( 'status' => 400 ) );
			}
			$new_id = null === $meta[ $key ] ? 0 : absint( $meta[ $key ] );
			$old_id = (int) get_post_meta( $post_id, $key, true );
			if ( $new_id !== $old_id && ( ( $new_id && ! can_link_player_account( $new_id ) ) || ( $old_id && ( ! current_user_can( 'edit_user', $old_id ) || ! current_user_can( 'promote_user', $old_id ) ) ) ) ) {
				return $forbidden;
			}
		} else {
			$raw_ids = null === $meta[ $key ] ? array() : $meta[ $key ];
			if ( ! is_array( $raw_ids ) ) {
				return new \WP_Error( 'leagueflow_invalid_identity', __( 'Team managers must be a list of account IDs.', 'leagueflow' ), array( 'status' => 400 ) );
			}
			foreach ( $raw_ids as $id ) {
				if ( ! $this->is_rest_user_id( $id ) ) {
					return new \WP_Error( 'leagueflow_invalid_identity', __( 'An account ID must be a non-negative integer.', 'leagueflow' ), array( 'status' => 400 ) );
				}
			}
			$new_ids = sanitize_user_id_list( $raw_ids );
			$old_ids = get_team_manager_user_ids( $post_id );
			foreach ( array_diff( $new_ids, $old_ids ) as $id ) {
				if ( ! can_link_player_account( $id ) ) { return $forbidden; }
			}
			foreach ( array_diff( $old_ids, $new_ids ) as $id ) {
				if ( ! current_user_can( 'edit_user', $id ) || ! current_user_can( 'promote_user', $id ) ) { return $forbidden; }
			}
		}
		return $prepared_post;
	}

	/** Do not let absint() turn a negative or malformed raw identity into a valid link. */
	protected function is_rest_user_id( $value ) {
		return ( is_int( $value ) && $value >= 0 ) || ( is_string( $value ) && (bool) preg_match( '/^\d+$/D', $value ) );
	}

	/** Exclude private directories from the general REST search endpoint as well. */
	public function filter_rest_directory_search( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			$args['post_type'] = array_values( array_diff( (array) $args['post_type'], array( 'lf_team', 'lf_player', 'lf_join_request' ) ) );
			if ( empty( $args['post_type'] ) ) {
				$args['post__in'] = array( 0 );
			}
		}
		return $args;
	}

	/**
	 * Register all CPTs.
	 *
	 * @return void
	 */
	public function register_post_types() {
		register_post_type(
			'lf_team',
			array(
				'labels'             => array(
					'name'               => __( 'Teams', 'leagueflow' ),
					'singular_name'      => __( 'Team', 'leagueflow' ),
					'add_new_item'       => __( 'Add New Team', 'leagueflow' ),
					'edit_item'          => __( 'Edit Team', 'leagueflow' ),
					'new_item'           => __( 'New Team', 'leagueflow' ),
					'view_item'          => __( 'View Team', 'leagueflow' ),
					'search_items'       => __( 'Search Teams', 'leagueflow' ),
					'not_found'          => __( 'No teams here yet. Add a team, or choose All sports above to see every team.', 'leagueflow' ),
					'not_found_in_trash' => __( 'No teams found in Trash.', 'leagueflow' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				// No archive: the team index is a curated page sharing the
				// same base slug, which the archive route would shadow.
				'has_archive'        => false,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'            => array( 'slug' => (string) get_setting( 'team_slug', 'teams' ) ),
				'menu_icon'          => 'dashicons-groups',
				'menu_position'      => 26,
				'taxonomies'         => array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		register_post_type(
			'lf_player',
			array(
				'labels'             => array(
					'name'               => __( 'Players', 'leagueflow' ),
					'singular_name'      => __( 'Player', 'leagueflow' ),
					'add_new_item'       => __( 'Add New Player', 'leagueflow' ),
					'edit_item'          => __( 'Edit Player', 'leagueflow' ),
					'new_item'           => __( 'New Player', 'leagueflow' ),
					'view_item'          => __( 'View Player', 'leagueflow' ),
					'search_items'       => __( 'Search Players', 'leagueflow' ),
					'not_found'          => __( 'No players here yet. Players appear when they register in the portal, when a captain adds them, or when you add one.', 'leagueflow' ),
					'not_found_in_trash' => __( 'No players found in Trash.', 'leagueflow' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'supports'           => array( 'title', 'thumbnail' ),
				'menu_icon'          => 'dashicons-id',
				'menu_position'      => 27,
				'taxonomies'         => array( 'lf_sport', 'lf_league_level' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		register_post_type(
			'lf_match',
			array(
				'labels'             => array(
					'name'               => __( 'Matches', 'leagueflow' ),
					'singular_name'      => __( 'Match', 'leagueflow' ),
					'add_new_item'       => __( 'Add New Match', 'leagueflow' ),
					'edit_item'          => __( 'Edit Match', 'leagueflow' ),
					'new_item'           => __( 'New Match', 'leagueflow' ),
					'view_item'          => __( 'View Match', 'leagueflow' ),
					'search_items'       => __( 'Search Matches', 'leagueflow' ),
					'not_found'          => __( 'No matches here yet. Generate fixtures for a sport and level, or add a match by hand.', 'leagueflow' ),
					'not_found_in_trash' => __( 'No matches found in Trash.', 'leagueflow' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'has_archive'        => true,
				'supports'           => array( 'title' ),
				'rewrite'            => array( 'slug' => (string) get_setting( 'match_slug', 'matches' ) ),
				'menu_icon'          => 'dashicons-calendar-alt',
				'menu_position'      => 28,
				'taxonomies'         => array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		register_post_type(
			'lf_calendar_event',
			array(
				'labels'             => array(
					'name'               => __( 'Calendar Events', 'leagueflow' ),
					'singular_name'      => __( 'Calendar Event', 'leagueflow' ),
					'add_new_item'       => __( 'Add New Calendar Event', 'leagueflow' ),
					'edit_item'          => __( 'Edit Calendar Event', 'leagueflow' ),
					'new_item'           => __( 'New Calendar Event', 'leagueflow' ),
					'view_item'          => __( 'View Calendar Event', 'leagueflow' ),
					'search_items'       => __( 'Search Calendar Events', 'leagueflow' ),
					'not_found'          => __( 'No calendar events here yet. Calendar events are non-match items such as drop-ins, tournaments and deadlines.', 'leagueflow' ),
					'not_found_in_trash' => __( 'No calendar events found in Trash.', 'leagueflow' ),
					'featured_image'        => __( 'Poster image', 'leagueflow' ),
					'set_featured_image'    => __( 'Set poster image', 'leagueflow' ),
					'remove_featured_image' => __( 'Remove poster image', 'leagueflow' ),
					'use_featured_image'    => __( 'Use as poster image', 'leagueflow' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'            => array( 'slug' => 'calendar-events' ),
				'menu_icon'          => 'dashicons-calendar',
				'menu_position'      => 29,
				'taxonomies'         => array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		register_post_type(
			'lf_join_request',
			array(
				'labels'             => array(
					'name'               => __( 'Join Requests', 'leagueflow' ),
					'singular_name'      => __( 'Join Request', 'leagueflow' ),
					'add_new_item'       => __( 'Add New Join Request', 'leagueflow' ),
					'edit_item'          => __( 'Edit Join Request', 'leagueflow' ),
					'new_item'           => __( 'New Join Request', 'leagueflow' ),
					'view_item'          => __( 'View Join Request', 'leagueflow' ),
					'search_items'       => __( 'Search Join Requests', 'leagueflow' ),
					'not_found'          => __( 'No requests here. Review requests in LeagueFlow > Requests.', 'leagueflow' ),
					'not_found_in_trash' => __( 'No join requests found in Trash.', 'leagueflow' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'supports'           => array( 'title' ),
				'menu_icon'          => 'dashicons-yes-alt',
				// Requests come from the portal; an empty hand-made record has no player or team.
				'capabilities'       => array( 'create_posts' => 'do_not_allow' ),
				'menu_position'      => 29,
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * Register post meta.
	 *
	 * @return void
	 */
	public function register_meta() {
		$shared_args = array(
			'single'       => true,
			'show_in_rest' => true,
			'auth_callback' => static function() {
				return current_user_can( 'edit_posts' );
			},
		);
		$account_args = array_merge(
			$shared_args,
			array( 'auth_callback' => static function() { return can_manage_player_accounts(); } )
		);

		$this->register_string_meta( 'lf_team', 'lf_short_name', $shared_args );
		$this->register_string_meta( 'lf_team', 'lf_city', $shared_args );
		$this->register_string_meta( 'lf_team', 'lf_coach', $shared_args );
		$this->register_integer_meta( 'lf_team', 'lf_founded_year', $shared_args );
		$this->register_user_ids_meta( 'lf_team', 'lf_manager_user_ids', $account_args );
		$this->register_signed_integer_meta( 'lf_team', 'lf_points_adjustment', $shared_args );
		$this->register_string_meta( 'lf_team', 'lf_adjustment_note', $shared_args );

		$this->register_email_meta( 'lf_player', 'lf_email', $shared_args );
		$this->register_integer_meta( 'lf_player', 'lf_user_id', $account_args );
		$this->register_integer_meta( 'lf_player', 'lf_jersey_number', $shared_args );
		$this->register_string_meta( 'lf_player', 'lf_position', $shared_args );
		$this->register_integer_meta( 'lf_player', 'lf_age', $shared_args );
		$this->register_string_meta( 'lf_player', 'lf_nationality', $shared_args );
		$this->register_integer_meta( 'lf_player', 'lf_team_id', $shared_args );
		$this->register_user_ids_meta( 'lf_player', 'lf_team_ids', $shared_args );
		$this->register_boolean_meta( 'lf_player', 'lf_is_captain', $shared_args );
		register_post_meta(
			'lf_player',
			'lf_player_team_details',
			array(
				'single'            => true,
				'type'              => 'object',
				'show_in_rest'      => false,
				'sanitize_callback' => __NAMESPACE__ . '\\sanitize_player_team_details',
				'auth_callback'     => static function() {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		$this->register_string_meta( 'lf_match', 'lf_match_datetime', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_venue', $shared_args );
		$this->register_integer_meta( 'lf_match', 'lf_home_team_id', $shared_args );
		$this->register_integer_meta( 'lf_match', 'lf_away_team_id', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_home_score', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_away_score', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_status', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_status_changed_at', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_outcome', $shared_args );
		$this->register_integer_meta( 'lf_match', 'lf_matchday', $shared_args );
		$this->register_boolean_meta( 'lf_match', 'lf_is_knockout', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_round_label', $shared_args );
		$this->register_integer_meta( 'lf_match', 'lf_round_order', $shared_args );
		$this->register_integer_meta( 'lf_match', 'lf_next_match_id', $shared_args );
		$this->register_string_meta( 'lf_match', 'lf_next_match_slot', $shared_args );
		$this->register_integer_meta( 'lf_match', 'lf_winner_team_id', $shared_args );
		$this->register_string_meta( 'lf_match', Field_Availability_Manager::META_AVAILABILITY_ID, $shared_args );
		$this->register_string_meta( 'lf_match', Field_Availability_Manager::META_SCHEDULE_SOURCE, $shared_args );
		$this->register_string_meta( 'lf_match', Field_Availability_Manager::META_SCHEDULED_AT, $shared_args );

		foreach ( Sports_Manager::get_all_match_meta_keys() as $meta_key ) {
			register_post_meta( 'lf_match', $meta_key, array_merge( $shared_args, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) ) );
		}

		$this->register_string_meta( 'lf_calendar_event', 'lf_event_start_datetime', $shared_args );
		$this->register_string_meta( 'lf_calendar_event', 'lf_event_end_datetime', $shared_args );
		$this->register_string_meta( 'lf_calendar_event', 'lf_event_venue', $shared_args );
		$this->register_string_meta( 'lf_calendar_event', 'lf_event_type', $shared_args );
		$this->register_string_meta( 'lf_calendar_event', 'lf_event_status', $shared_args );
		$this->register_string_meta( 'lf_calendar_event', 'lf_event_cost', $shared_args );
		$this->register_url_meta( 'lf_calendar_event', 'lf_event_registration_url', $shared_args );
		$this->register_boolean_meta( 'lf_calendar_event', 'lf_event_registration_required', $shared_args );

		$this->register_integer_meta( 'lf_join_request', 'lf_player_id', $shared_args );
		$this->register_integer_meta( 'lf_join_request', 'lf_user_id', $shared_args );
		$this->register_integer_meta( 'lf_join_request', 'lf_team_id', $shared_args );
		$this->register_integer_meta( 'lf_join_request', 'lf_league_level_id', $shared_args );
		$this->register_string_meta( 'lf_join_request', 'lf_sport_slug', $shared_args );
		$this->register_string_meta( 'lf_join_request', 'lf_request_status', $shared_args );
		$this->register_string_meta( 'lf_join_request', 'lf_request_note', $shared_args );
		$this->register_string_meta( 'lf_join_request', 'lf_request_type', $shared_args );
	}

	/**
	 * Register string meta field.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_string_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				)
			)
		);
	}

	/**
	 * Register an email meta field.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_email_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				)
			)
		);
	}

	/**
	 * Register a URL meta field.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_url_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				)
			)
		);
	}

	/**
	 * Register a user ID list meta field.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_user_ids_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'array',
					'sanitize_callback' => __NAMESPACE__ . '\\sanitize_user_id_list',
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'integer',
							),
						),
					),
				)
			)
		);
	}

	/**
	 * Register integer meta field.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_integer_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				)
			)
		);
	}

	/**
	 * Register a signed integer meta field.
	 *
	 * Unlike register_integer_meta this preserves negative values, which is
	 * required for standings point deductions.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_signed_integer_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'integer',
					'sanitize_callback' => static function( $value ) {
						return (int) $value;
					},
				)
			)
		);
	}

	/**
	 * Register boolean meta field.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $meta_key Meta key.
	 * @param array<string, mixed> $args Base args.
	 * @return void
	 */
	protected function register_boolean_meta( $post_type, $meta_key, $args ) {
		register_post_meta(
			$post_type,
			$meta_key,
			array_merge(
				$args,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => array( $this, 'sanitize_boolean' ),
				)
			)
		);
	}

	/**
	 * Sanitize a boolean meta field.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function sanitize_boolean( $value ) {
		return ! empty( $value );
	}

	/**
	 * Keep selected LeagueFlow post types on the classic editing screen.
	 *
	 * Team entries still need a description editor, but the user wants the
	 * add/edit experience to match the classic player workflow rather than
	 * the Gutenberg post editor.
	 *
	 * @param bool   $use_block_editor Whether the block editor should be used.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function use_classic_editor_for_supported_types( $use_block_editor, $post_type ) {
		// Calendar events use the classic screen so the details box, with its
		// dates, sport and poster, is the form staff fill in.
		if ( in_array( $post_type, array( 'lf_team', 'lf_calendar_event' ), true ) ) {
			return false;
		}

		return $use_block_editor;
	}
}
