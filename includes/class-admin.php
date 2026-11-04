<?php
/**
 * WordPress admin integration.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controllers and metaboxes.
 */
class Admin {

	/**
	 * Match statuses.
	 *
	 * @var array<string, string>
	 */
	protected $statuses = array(
		'scheduled' => 'Scheduled',
		'live'      => 'Live',
		'finished'  => 'Finished',
		'postponed' => 'Postponed',
		'cancelled' => 'Cancelled',
	);

	/**
	 * Match result types (forfeit handling).
	 *
	 * @var array<string, string>
	 */
	protected $match_outcomes = array(
		'forfeit_home'   => 'Home team forfeited',
		'forfeit_away'   => 'Away team forfeited',
		'double_forfeit' => 'Double forfeit',
	);

	/**
	 * Calendar event types.
	 *
	 * @var array<string, string>
	 */
	protected $calendar_event_types = array(
		'drop_in'    => 'Drop-in',
		'practice'   => 'Practice',
		'clinic'     => 'Clinic',
		'tournament' => 'Tournament',
		'meeting'    => 'Meeting',
		'other'      => 'Other',
	);

	/**
	 * Standings service.
	 *
	 * @var Standings_Service
	 */
	protected $standings_service;

	/**
	 * Knockout service.
	 *
	 * @var Knockout_Service
	 */
	protected $knockout_service;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	protected $renderer;

	/**
	 * Sports manager.
	 *
	 * @var Sports_Manager
	 */
	protected $sports_manager;

	/**
	 * Exporter.
	 *
	 * @var Exporter
	 */
	protected $exporter;

	/**
	 * Field availability manager.
	 *
	 * @var Field_Availability_Manager
	 */
	protected $field_availability_manager;

	/**
	 * Fixture generator.
	 *
	 * @var Fixture_Generator
	 */
	protected $fixture_generator;

	/**
	 * Seeder.
	 *
	 * @var Seeder
	 */
	protected $seeder;

	/**
	 * Requests inbox screen.
	 *
	 * @var Admin_Requests
	 */
	protected $requests_admin;

	/**
	 * Results screen.
	 *
	 * @var Admin_Results
	 */
	protected $results_admin;

	/**
	 * Setup check screen.
	 *
	 * @var Admin_Setup
	 */
	protected $setup_admin;

	/**
	 * Prevent recursive title updates.
	 *
	 * @var bool
	 */
	protected $syncing_match_title = false;

	/**
	 * Constructor.
	 *
	 * @param Standings_Service $standings_service Standings.
	 * @param Knockout_Service  $knockout_service Knockout.
	 * @param Renderer          $renderer Renderer.
	 * @param Seeder            $seeder Seeder.
	 * @param Sports_Manager    $sports_manager Sports.
	 * @param Exporter          $exporter Exporter.
	 * @param Field_Availability_Manager $field_availability_manager Field availability manager.
	 * @param Fixture_Generator          $fixture_generator Fixture generator.
	 * @param Join_Request_Service|null  $join_requests Join request rules.
	 * @param Result_Service|null        $results Result writer.
	 */
	public function __construct( Standings_Service $standings_service, Knockout_Service $knockout_service, Renderer $renderer, Seeder $seeder, Sports_Manager $sports_manager, Exporter $exporter, Field_Availability_Manager $field_availability_manager, Fixture_Generator $fixture_generator, $join_requests = null, $results = null ) {
		$this->standings_service          = $standings_service;
		$this->knockout_service           = $knockout_service;
		$this->renderer                   = $renderer;
		$this->seeder                     = $seeder;
		$this->sports_manager             = $sports_manager;
		$this->exporter                   = $exporter;
		$this->field_availability_manager = $field_availability_manager;
		$this->fixture_generator          = $fixture_generator;
		$this->requests_admin             = new Admin_Requests( $join_requests instanceof Join_Request_Service ? $join_requests : Join_Request_Service::shared(), $sports_manager );
		$this->setup_admin                = new Admin_Setup( $sports_manager, $field_availability_manager );
		$this->results_admin              = new Admin_Results( $results instanceof Result_Service ? $results : new Result_Service(), $sports_manager );
	}

	/**
	 * Setup check screen (read-only checks and explicit mapping).
	 *
	 * @return Admin_Setup
	 */
	public function setup_admin() {
		return $this->setup_admin;
	}

	/**
	 * Render the Setup check screen in the current sport context.
	 *
	 * @return void
	 */
	public function render_setup_page() {
		$this->setup_admin->render_page( $this->get_context_sport_slug() );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		$this->requests_admin->register();
		$this->results_admin->register();
		$this->setup_admin->register();
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'all_admin_notices', array( $this, 'render_context_bar' ), 1 );
		add_filter( 'admin_url', array( $this, 'filter_add_new_url' ), 10, 2 );
		add_filter( 'default_hidden_columns', array( $this, 'filter_default_hidden_columns' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'remove_duplicate_taxonomy_boxes' ), 99 );
		add_filter( 'wp_insert_post_data', array( $this, 'hold_sportless_team_as_draft' ), 10, 2 );
		add_filter( 'quick_edit_show_taxonomy', array( $this, 'filter_quick_edit_taxonomies' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'remove_duplicate_block_editor_panels' ) );
		add_action( 'admin_init', array( $this, 'maybe_force_sport_setup' ) );
		add_filter( 'parent_file', array( $this, 'filter_admin_menu_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_admin_menu_submenu_file' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_lf_team', array( $this, 'save_team_meta' ), 10, 2 );
		add_action( 'save_post_lf_player', array( $this, 'save_player_meta' ), 10, 2 );
		add_action( 'save_post_lf_match', array( $this, 'save_match_meta' ), 10, 2 );
		add_action( 'save_post_lf_calendar_event', array( $this, 'save_calendar_event_meta' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_post_leagueflow_seed_demo', array( $this, 'handle_seed_demo' ) );
		add_action( 'admin_post_leagueflow_save_rule_override', array( $this, 'handle_save_rule_override' ) );
		add_action( 'admin_post_leagueflow_save_sports', array( $this, 'handle_save_sports' ) );
		add_action( 'admin_post_leagueflow_export_players', array( $this, 'handle_export_players' ) );
		add_action( 'admin_post_leagueflow_save_field_availability', array( $this, 'handle_save_field_availability' ) );
		add_action( 'admin_post_leagueflow_delete_field_availability', array( $this, 'handle_delete_field_availability' ) );
		add_action( 'admin_post_leagueflow_auto_schedule_matches', array( $this, 'handle_auto_schedule_matches' ) );
		add_action( 'admin_post_leagueflow_generate_fixtures', array( $this, 'handle_generate_fixtures' ) );
		add_action( 'admin_post_leagueflow_assign_placement', array( $this, 'handle_assign_placement' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_admin_sorting' ) );
		add_filter( 'posts_clauses', array( $this, 'filter_admin_sort_clauses' ), 10, 2 );
		add_filter( 'views_edit-lf_team', array( $this, 'filter_list_views' ) );
		add_filter( 'views_edit-lf_player', array( $this, 'filter_list_views' ) );
		add_filter( 'views_edit-lf_match', array( $this, 'filter_list_views' ) );
		add_filter( 'views_edit-lf_calendar_event', array( $this, 'filter_list_views' ) );
		add_filter( 'views_edit-lf_join_request', array( $this, 'filter_list_views' ) );
		add_filter( 'get_terms_args', array( $this, 'filter_admin_terms_by_sport' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_admin_sport_filter' ), 10, 2 );

		add_filter( 'manage_lf_team_posts_columns', array( $this, 'team_columns' ) );
		add_filter( 'manage_lf_player_posts_columns', array( $this, 'player_columns' ) );
		add_filter( 'manage_lf_match_posts_columns', array( $this, 'match_columns' ) );
		add_filter( 'manage_lf_calendar_event_posts_columns', array( $this, 'calendar_event_columns' ) );
		add_filter( 'manage_lf_join_request_posts_columns', array( $this, 'join_request_columns' ) );

		add_action( 'manage_lf_team_posts_custom_column', array( $this, 'render_team_column' ), 10, 2 );
		add_action( 'manage_lf_player_posts_custom_column', array( $this, 'render_player_column' ), 10, 2 );
		add_action( 'manage_lf_match_posts_custom_column', array( $this, 'render_match_column' ), 10, 2 );
		add_action( 'manage_lf_calendar_event_posts_custom_column', array( $this, 'render_calendar_event_column' ), 10, 2 );
		add_action( 'manage_lf_join_request_posts_custom_column', array( $this, 'render_join_request_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_join_request_row_actions' ), 10, 2 );

		add_filter( 'manage_edit-lf_team_sortable_columns', array( $this, 'team_sortable_columns' ) );
		add_filter( 'manage_edit-lf_player_sortable_columns', array( $this, 'player_sortable_columns' ) );
		add_filter( 'manage_edit-lf_match_sortable_columns', array( $this, 'match_sortable_columns' ) );
		add_filter( 'manage_edit-lf_calendar_event_sortable_columns', array( $this, 'calendar_event_sortable_columns' ) );

		add_filter( 'bulk_actions-edit-lf_match', array( $this, 'register_match_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-lf_match', array( $this, 'handle_match_bulk_actions' ), 10, 3 );
	}

	/**
	 * Point request rows at the Requests inbox review.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post Current post.
	 * @return array<string, string>
	 */
	public function add_join_request_row_actions( $actions, $post ) {
		if ( $post instanceof \WP_Post && 'lf_join_request' === $post->post_type && current_user_can( 'leagueflow_manage_placements' ) ) {
			$actions = array(
				'leagueflow_review' => sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( add_query_arg( array( 'page' => Admin_Requests::PAGE, 'status' => 'all', 'request_id' => $post->ID ), admin_url( 'admin.php' ) ) . '#leagueflow-request-detail' ),
					esc_html__( 'Review', 'leagueflow' )
				),
			) + $actions;
		}

		return $actions;
	}

	/**
	 * Register the single LeagueFlow admin menu.
	 *
	 * The main menu holds every screen. Enabled sports link to these shared
	 * screens with explicit sport context, rather than registering competing
	 * copies of each page. Older per-sport page slugs remain hidden aliases.
	 *
	 * @return void
	 */
	public function register_menus() {
		add_menu_page(
			__( 'LeagueFlow', 'leagueflow' ),
			__( 'LeagueFlow', 'leagueflow' ),
			'edit_posts',
			'leagueflow',
			array( $this, 'render_dashboard_page' ),
			'dashicons-awards',
			25
		);

		$pending        = current_user_can( 'leagueflow_manage_placements' ) ? $this->count_pending_requests() : 0;
		$requests_label = __( 'Requests', 'leagueflow' );

		if ( $pending ) {
			$requests_label .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$pending,
				esc_html( number_format_i18n( $pending ) ),
				/* translators: %s: number of pending requests */
				esc_html( sprintf( _n( '%s pending request', '%s pending requests', $pending, 'leagueflow' ), number_format_i18n( $pending ) ) )
			);
		}

		$waiting       = $this->results_admin->count_waiting();
		$results_label = __( 'Results', 'leagueflow' );
		if ( $waiting ) {
			$results_label .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$waiting,
				esc_html( number_format_i18n( $waiting ) ),
				/* translators: %s: number of played games waiting for a published result */
				esc_html( sprintf( _n( '%s result to publish', '%s results to publish', $waiting, 'leagueflow' ), number_format_i18n( $waiting ) ) )
			);
		}

		$items = array(
			array( __( 'Overview', 'leagueflow' ), __( 'Overview', 'leagueflow' ), 'edit_posts', 'leagueflow', array( $this, 'render_dashboard_page' ) ),
			array( __( 'Setup check', 'leagueflow' ), __( 'Setup check', 'leagueflow' ), Admin_Setup::CAPABILITY, Admin_Setup::PAGE, array( $this, 'render_setup_page' ) ),
			array( __( 'Teams', 'leagueflow' ), __( 'Teams', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_team', null ),
			array( __( 'Players', 'leagueflow' ), __( 'Players', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_player', null ),
			array( __( 'Requests', 'leagueflow' ), $requests_label, 'leagueflow_manage_placements', 'leagueflow-requests', array( $this->requests_admin, 'render_page' ) ),
			array( __( 'Matches', 'leagueflow' ), __( 'Matches', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_match', null ),
			array( __( 'Results', 'leagueflow' ), $results_label, 'edit_posts', Admin_Results::PAGE, array( $this->results_admin, 'render_page' ) ),
			array( __( 'Generate fixtures', 'leagueflow' ), __( 'Generate fixtures', 'leagueflow' ), 'leagueflow_manage_fixtures', 'leagueflow-fixtures', array( $this, 'render_fixtures_page' ) ),
			array( __( 'Venues & availability', 'leagueflow' ), __( 'Venues & availability', 'leagueflow' ), 'leagueflow_manage_field_availability', 'leagueflow-fields', array( $this, 'render_field_availability_page' ) ),
			array( __( 'Calendar Events', 'leagueflow' ), __( 'Calendar Events', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_calendar_event', null ),
			array( __( 'Standings', 'leagueflow' ), __( 'Standings', 'leagueflow' ), 'edit_posts', 'leagueflow-standings', array( $this, 'render_standings_page' ) ),
			array( __( 'Knockout Brackets', 'leagueflow' ), __( 'Knockout Brackets', 'leagueflow' ), 'edit_posts', 'leagueflow-brackets', array( $this, 'render_brackets_page' ) ),
			array( __( 'Competitions', 'leagueflow' ), __( 'Competitions', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_competition&post_type=lf_match', null ),
			array( __( 'Seasons', 'leagueflow' ), __( 'Seasons', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_season&post_type=lf_match', null ),
			array( __( 'League Levels', 'leagueflow' ), __( 'League Levels (all sports)', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_league_level&post_type=lf_match', null ),
			array( __( 'Sports', 'leagueflow' ), __( 'Sports', 'leagueflow' ), 'manage_options', 'leagueflow-sports', array( $this, 'render_sports_page' ) ),
			array( __( 'Utilities', 'leagueflow' ), __( 'Utilities', 'leagueflow' ), 'manage_options', 'leagueflow-utilities', array( $this, 'render_utilities_page' ) ),
			array( __( 'Settings', 'leagueflow' ), __( 'Settings', 'leagueflow' ), 'manage_options', 'leagueflow-settings', array( $this, 'render_settings_page' ) ),
		);

		foreach ( $items as $item ) {
			add_submenu_page( 'leagueflow', $item[0], $item[1], $item[2], $item[3], $item[4] );
		}

		// Choose sport shortcuts by identity, not by array offsets: inserting a
		// shared menu item must not silently remove Seasons or another shortcut.
		$sport_screens = array(
			Admin_Setup::PAGE, 'edit.php?post_type=lf_team', 'edit.php?post_type=lf_player',
			'leagueflow-requests', 'edit.php?post_type=lf_match', 'leagueflow-fixtures',
			'leagueflow-fields', 'edit.php?post_type=lf_calendar_event',
			'leagueflow-standings', 'leagueflow-brackets',
			'edit-tags.php?taxonomy=lf_competition&post_type=lf_match',
			'edit-tags.php?taxonomy=lf_season&post_type=lf_match',
		);
		$position = 26;
		foreach ( $this->sports_manager->get_enabled_sports() as $sport_slug => $sport ) {
			$parent = 'leagueflow-sport-' . $sport_slug;
			add_menu_page( $sport['label'], $sport['menu_label'], 'edit_posts', $parent, array( $this, 'render_sport_dashboard_page' ), $this->sports_manager->get_menu_icon_data_uri( $sport_slug ), $position++ );
			add_submenu_page( $parent, __( 'Overview', 'leagueflow' ), __( 'Overview', 'leagueflow' ), 'edit_posts', $parent, array( $this, 'render_sport_dashboard_page' ) );
			foreach ( $items as $item ) {
				if ( ! in_array( $item[3], $sport_screens, true ) ) {
					continue;
				}
				$url = false !== strpos( $item[3], '.php?' ) ? $item[3] : 'admin.php?page=' . $item[3];
				$url = add_query_arg( 'lf_sport', $sport_slug, $url );
				// The shared inbox owns the global pending badge; sport links must not repeat it.
				add_submenu_page( $parent, $item[0], $item[0], $item[2], $url );
			}
		}

		$this->register_legacy_page_aliases();
	}

	/**
	 * Keep old page slugs working as hidden redirects to the unified screens.
	 *
	 * @return void
	 */
	protected function register_legacy_page_aliases() {
		$aliases = array(
			'leagueflow-placements' => array( 'leagueflow_manage_placements', 'leagueflow-requests', array( 'type' => 'placement' ) ),
		);

		foreach ( $this->sports_manager->get_enabled_sport_slugs() as $sport_slug ) {
			$context = array( 'lf_sport' => $sport_slug );

			$aliases[ 'leagueflow-sport-' . $sport_slug . '-fixtures' ]  = array( 'leagueflow_manage_fixtures', 'leagueflow-fixtures', $context );
			$aliases[ 'leagueflow-sport-' . $sport_slug . '-standings' ] = array( 'edit_posts', 'leagueflow-standings', $context );
			$aliases[ 'leagueflow-sport-' . $sport_slug . '-brackets' ]  = array( 'edit_posts', 'leagueflow-brackets', $context );
			$aliases[ 'leagueflow-sport-' . $sport_slug . '-fields' ]    = array( 'leagueflow_manage_field_availability', 'leagueflow-fields', $context );
		}

		foreach ( $aliases as $slug => $alias ) {
			// Parent '' registers a page WordPress can load but never lists in a menu.
			$hook = add_submenu_page( '', __( 'LeagueFlow', 'leagueflow' ), '', $alias[0], $slug, '__return_null' );

			if ( $hook ) {
				add_action(
					'load-' . $hook,
					function () use ( $alias ) {
						wp_safe_redirect( $this->get_legacy_alias_target( $alias[1], $alias[2] ) );
						exit;
					}
				);
			}
		}
	}

	/**
	 * Target URL for a legacy page alias, keeping its other query arguments.
	 *
	 * @param string               $page New page slug.
	 * @param array<string, mixed> $context Context arguments.
	 * @return string
	 */
	public function get_legacy_alias_target( $page, $context ) {
		$keep = array();

		foreach ( array( 'competition', 'season', 'league_level', 'request_id', 'availability_id', 'date' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$keep[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		return add_query_arg( array_merge( $keep, $context, array( 'page' => $page ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Enabled sport in the current context: the request's lf_sport/sport, or
	 * the sport of the record being edited. Empty means "all sports".
	 *
	 * @return string
	 */
	public function get_context_sport_slug() {
		$slug = $this->get_requested_sport_slug();

		if ( 'post.php' === $this->get_current_admin_file() ) {
			$post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stored  = $post_id ? get_post_primary_term_slug( $post_id, 'lf_sport' ) : '';

			if ( '' === $stored && 'lf_join_request' === get_post_type( $post_id ) ) {
				$stored = sanitize_key( (string) get_post_meta( $post_id, 'lf_sport_slug', true ) );
			}

			if ( $stored ) {
				$slug = $stored;
			}
		} elseif ( 'term.php' === $this->get_current_admin_file() && in_array( $this->get_current_admin_taxonomy(), array( 'lf_competition', 'lf_season' ), true ) ) {
			$term_id = absint( $_GET['tag_ID'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $term_id ) {
				$slug = sanitize_key( (string) get_term_meta( $term_id, 'lf_sport_slug', true ) );
			}
		}

		return $this->is_enabled_sport_slug( $slug ) ? $slug : '';
	}

	/**
	 * Name of the current LeagueFlow screen, for the breadcrumb.
	 *
	 * @return string
	 */
	protected function get_context_screen_label() {
		$page   = $this->get_current_admin_plugin_page_slug();
		if ( 0 === strpos( $page, 'leagueflow-sport-' ) && 'leagueflow-sport-' . $this->get_context_sport_slug() === $page ) {
			$page = 'leagueflow';
		}
		$titles = array(
			'leagueflow'           => __( 'Overview', 'leagueflow' ),
			'leagueflow-setup'     => __( 'Setup check', 'leagueflow' ),
			'leagueflow-requests'  => __( 'Requests', 'leagueflow' ),
			'leagueflow-fixtures'  => __( 'Generate fixtures', 'leagueflow' ),
			'leagueflow-fields'    => __( 'Venues & availability', 'leagueflow' ),
			'leagueflow-standings' => __( 'Standings', 'leagueflow' ),
			'leagueflow-brackets'  => __( 'Knockout Brackets', 'leagueflow' ),
			'leagueflow-sports'    => __( 'Sports', 'leagueflow' ),
			'leagueflow-utilities' => __( 'Utilities', 'leagueflow' ),
			'leagueflow-settings'  => __( 'Settings', 'leagueflow' ),
			'leagueflow-guide'     => __( 'Staff guide', 'leagueflow' ),
		);

		if ( $page ) {
			return $titles[ $page ] ?? '';
		}

		$taxonomy = get_taxonomy( $this->get_current_admin_taxonomy() );

		if ( $taxonomy && isset( $this->get_leagueflow_taxonomy_submenu_files()[ $taxonomy->name ] ) ) {
			return $taxonomy->labels->name;
		}

		$type = get_post_type_object( $this->get_current_admin_post_type() );

		if ( ! $type ) {
			return '';
		}

		if ( 'post-new.php' === $this->get_current_admin_file() ) {
			return $type->labels->add_new_item;
		}

		return 'post.php' === $this->get_current_admin_file() ? $type->labels->edit_item : $type->labels->name;
	}

	/**
	 * Breadcrumb and sport switcher shown above every LeagueFlow screen.
	 *
	 * Shows where you are (LeagueFlow > sport > screen), which sport the screen
	 * is limited to, and links to switch sport while staying on the same screen.
	 * Edit screens instead link back to the list in the record's sport.
	 *
	 * @return void
	 */
	public function render_context_bar() {
		if ( ! $this->get_current_leagueflow_admin_menu_slug() ) {
			return;
		}

		$file      = $this->get_current_admin_file();
		$page      = $this->get_current_admin_plugin_page_slug();
		$taxonomy  = $this->get_current_admin_taxonomy();
		$post_type = $this->get_current_admin_post_type();
		$sport     = $this->get_context_sport_slug();
		$label     = $sport ? $this->sports_manager->get_definition( $sport )['label'] : __( 'All sports', 'leagueflow' );
		$level     = $this->get_requested_league_level_slug();
		$level     = $level ? get_term_by( 'slug', $level, 'lf_league_level' ) : null;
		$editing   = in_array( $file, array( 'post.php', 'post-new.php', 'term.php' ), true );
		$global    = in_array( $page, array( 'leagueflow-sports', 'leagueflow-utilities', 'leagueflow-settings', 'leagueflow-guide' ), true ) || 'lf_league_level' === $taxonomy;
		$overview  = add_query_arg( array_filter( array( 'page' => 'leagueflow', 'lf_sport' => $sport ) ), admin_url( 'admin.php' ) );
		?>
		<nav class="leagueflow-context" aria-label="<?php esc_attr_e( 'LeagueFlow location and sport', 'leagueflow' ); ?>">
			<ol class="leagueflow-breadcrumbs">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow' ) ); ?>"><?php esc_html_e( 'LeagueFlow', 'leagueflow' ); ?></a></li>
				<?php if ( ! $global ) : ?>
					<li><?php echo $sport ? '<a href="' . esc_url( $overview ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ); ?></li>
				<?php endif; ?>
				<?php if ( $level instanceof \WP_Term && ! $global ) : ?>
					<li><?php echo esc_html( $level->name ); ?></li>
				<?php endif; ?>
				<li aria-current="page"><?php echo esc_html( $this->get_context_screen_label() ); ?></li>
			</ol>
			<?php if ( 'lf_league_level' === $taxonomy ) : ?>
				<p class="leagueflow-context__note"><?php esc_html_e( 'League levels are shared by every sport. Renaming or deleting one changes it for all sports, and the Count column includes records from every sport.', 'leagueflow' ); ?></p>
			<?php elseif ( 'term.php' === $file && isset( $this->get_leagueflow_taxonomy_submenu_files()[ $taxonomy ] ) ) : ?>
				<?php
				$tax_object = get_taxonomy( $taxonomy );
				$back = add_query_arg( array_filter( array( 'taxonomy' => $taxonomy, 'post_type' => 'lf_match', 'lf_sport' => $sport ) ), admin_url( 'edit-tags.php' ) );
				?>
				<p class="leagueflow-context__back"><a href="<?php echo esc_url( $back ); ?>">&larr; <?php echo esc_html( sprintf( __( 'Back to %1$s (%2$s)', 'leagueflow' ), $tax_object->labels->name, $label ) ); ?></a></p>
			<?php elseif ( $editing && in_array( $post_type, $this->get_leagueflow_admin_post_types(), true ) ) : ?>
				<?php
				$type = get_post_type_object( $post_type );
				$back = 'lf_join_request' === $post_type
					? add_query_arg( array_filter( array( 'page' => Admin_Requests::PAGE, 'lf_sport' => $sport ) ), admin_url( 'admin.php' ) )
					: add_query_arg( array_filter( array( 'post_type' => $post_type, 'lf_sport' => $sport ) ), admin_url( 'edit.php' ) );
				?>
				<p class="leagueflow-context__back"><a href="<?php echo esc_url( $back ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: 1: list name, 2: sport or "All sports" */ __( 'Back to %1$s (%2$s)', 'leagueflow' ), $type ? $type->labels->name : '', $label ) ); ?></a></p>
			<?php elseif ( ! $global && $this->sports_manager->get_enabled_sports() ) : ?>
				<ul class="leagueflow-sport-switch" aria-label="<?php esc_attr_e( 'Show this screen for', 'leagueflow' ); ?>">
					<?php
					$drop    = array( 'lf_sport', 'sport', 'paged', 'competition', 'season', 'request_id', 'availability_id', 'leagueflow_request_notice', 'leagueflow_request_error', 'leagueflow_placement_notice', 'tag_ID', 'lf_batch', 'per_level', 'map_term', 'map_preview', 'map_error', 'map_done', 'map_failed', 'lf_competition_filter', 'lf_season_filter' );
					$choices = array( '' => __( 'All sports', 'leagueflow' ) );

					foreach ( $this->sports_manager->get_enabled_sports() as $slug => $definition ) {
						$choices[ $slug ] = $definition['label'];
					}

					foreach ( $choices as $slug => $choice ) {
						$url = remove_query_arg( $drop );
						if ( 0 === strpos( $page, 'leagueflow-sport-' ) ) {
							$url = add_query_arg( 'page', 'leagueflow', $url );
						}
						$url = $slug ? add_query_arg( 'lf_sport', $slug, $url ) : $url;
						printf(
							'<li><a href="%1$s"%2$s>%3$s</a></li>',
							esc_url( $url ),
							$slug === $sport ? ' class="current" aria-current="true"' : '',
							esc_html( $choice )
						);
					}
					?>
				</ul>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Carry the sport context into core "Add New" links on LeagueFlow screens.
	 *
	 * @param string $url URL.
	 * @param string $path Requested admin path.
	 * @return string
	 */
	public function filter_add_new_url( $url, $path ) {
		if ( ! is_admin() || ! is_string( $path ) || ! preg_match( '/^post-new\.php\?post_type=(lf_team|lf_player|lf_match|lf_calendar_event)$/', $path ) ) {
			return $url;
		}

		$sport = $this->get_context_sport_slug();

		return $sport ? add_query_arg( 'lf_sport', $sport, $url ) : $url;
	}

	/**
	 * Hide secondary list columns by default (Screen Options can show them).
	 *
	 * @param array<int, string> $hidden Hidden columns.
	 * @param \WP_Screen         $screen Screen.
	 * @return array<int, string>
	 */
	public function filter_default_hidden_columns( $hidden, $screen ) {
		$defaults = array(
			'edit-lf_match'  => array( 'competition', 'season', 'knockout' ),
			'edit-lf_team'   => array( 'coach', 'founded_year' ),
			'edit-lf_player' => array( 'nationality' ),
		);

		if ( $screen instanceof \WP_Screen && isset( $defaults[ $screen->id ] ) ) {
			$hidden = array_merge( (array) $hidden, $defaults[ $screen->id ] );
		}

		return $hidden;
	}

	/**
	 * Remove core taxonomy boxes that repeat a field in the details box.
	 *
	 * League Level is chosen in the Team, Match and Calendar Event details.
	 * Sport is chosen in the Team and Calendar Event details, a match's sport
	 * comes from its teams and a player's sports come from team memberships.
	 * The free-form tag boxes could save a different value from the details
	 * box, or create a new "sport" that is not one of LeagueFlow's sports.
	 * Player League Level stays a tag box: players without a team have no
	 * other level control.
	 *
	 * @return void
	 */
	public function remove_duplicate_taxonomy_boxes() {
		foreach ( array( 'lf_team', 'lf_match', 'lf_calendar_event' ) as $post_type ) {
			remove_meta_box( 'tagsdiv-lf_league_level', $post_type, 'side' );
		}

		foreach ( array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ) as $post_type ) {
			remove_meta_box( 'tagsdiv-lf_sport', $post_type, 'side' );
		}

		// The poster is chosen in the Calendar Event details box.
		remove_meta_box( 'postimagediv', 'lf_calendar_event', 'side' );
	}

	/**
	 * Hide Quick Edit's free-form League Levels field where the details box owns the level.
	 *
	 * Quick Edit saves terms without the details box, so it skipped the
	 * team-to-player level sync and the match level rules.
	 *
	 * @param bool   $show Whether to show the taxonomy.
	 * @param string $taxonomy Taxonomy.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function filter_quick_edit_taxonomies( $show, $taxonomy, $post_type ) {
		if ( 'lf_league_level' === $taxonomy && in_array( $post_type, array( 'lf_team', 'lf_match', 'lf_calendar_event' ), true ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Remove the block-editor League Levels panel; Calendar Event Details sets the level.
	 *
	 * Calendar events are the only LeagueFlow type edited in the block editor
	 * (teams, players and matches use the classic screen), and its taxonomy
	 * panels are not affected by remove_meta_box().
	 *
	 * @return void
	 */
	public function remove_duplicate_block_editor_panels() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'lf_calendar_event' !== $screen->post_type ) {
			return;
		}

		wp_register_script( 'leagueflow-editor-panels', '', array( 'wp-data', 'wp-dom-ready', 'wp-editor' ), LEAGUEFLOW_VERSION, true );
		wp_enqueue_script( 'leagueflow-editor-panels' );
		wp_add_inline_script(
			'leagueflow-editor-panels',
			"wp.domReady(function(){var s=wp.data.dispatch('core/editor');if(!s||!s.removeEditorPanel){s=wp.data.dispatch('core/edit-post');}if(s&&s.removeEditorPanel){s.removeEditorPanel('taxonomy-panel-lf_league_level');}});"
		);
	}

	/**
	 * Count pending requests (both kinds) for the menu badge.
	 *
	 * @return int
	 */
	protected function count_pending_requests() {
		$query = new \WP_Query(
			array(
				'post_type'              => 'lf_join_request',
				'post_status'            => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Keep LeagueFlow's admin parent menu open on custom post type and taxonomy screens.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function filter_admin_menu_parent_file( $parent_file ) {
		$leagueflow_parent = $this->get_current_leagueflow_admin_menu_slug();

		return $leagueflow_parent ? $leagueflow_parent : $parent_file;
	}

	/**
	 * Highlight the correct LeagueFlow submenu item on custom post type and taxonomy screens.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function filter_admin_menu_submenu_file( $submenu_file, $parent_file ) {
		if ( 0 !== strpos( $parent_file, 'leagueflow' ) ) {
			return $submenu_file;
		}

		$leagueflow_submenu = $this->get_current_leagueflow_admin_submenu_file( $parent_file );

		return $leagueflow_submenu ? $leagueflow_submenu : $submenu_file;
	}

	/**
	 * Register metaboxes.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box( 'leagueflow-team-details', __( 'Team Details', 'leagueflow' ), array( $this, 'render_team_metabox' ), 'lf_team', 'normal', 'high' );
		add_meta_box( 'leagueflow-team-roster', __( 'Team Roster', 'leagueflow' ), array( $this, 'render_team_roster_metabox' ), 'lf_team', 'normal', 'default' );
		add_meta_box( 'leagueflow-player-details', __( 'Player Details', 'leagueflow' ), array( $this, 'render_player_metabox' ), 'lf_player', 'normal', 'high' );
		add_meta_box( 'leagueflow-match-details', __( 'Match Details', 'leagueflow' ), array( $this, 'render_match_metabox' ), 'lf_match', 'normal', 'high' );
		add_meta_box( 'leagueflow-match-events', __( 'Match Events', 'leagueflow' ), array( $this, 'render_match_events_metabox' ), 'lf_match', 'normal', 'default' );
		add_meta_box( 'leagueflow-calendar-event-details', __( 'Calendar Event Details', 'leagueflow' ), array( $this, 'render_calendar_event_metabox' ), 'lf_calendar_event', 'normal', 'high' );
	}

	/**
	 * Legacy Placements form handler (pages opened before the Requests inbox).
	 *
	 * Those forms carry no review state, so they never write: after the same
	 * capability and record-bound nonce checks as before, the reviewer is sent
	 * to a fresh review of the request in the inbox.
	 *
	 * @return void
	 */
	public function handle_assign_placement() {
		if ( ! current_user_can( 'leagueflow_manage_placements' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage placements.', 'leagueflow' ) );
		}

		$request_id = absint( wp_unslash( $_POST['lf_join_request_id'] ?? 0 ) );
		$nonce      = sanitize_text_field( wp_unslash( $_POST['leagueflow_placement_nonce'] ?? '' ) );
		$args       = array( 'page' => Admin_Requests::PAGE, 'type' => 'placement', 'request_id' => $request_id );

		if ( ! $request_id || ! wp_verify_nonce( $nonce, 'leagueflow_assign_placement_' . $request_id ) ) {
			$args['leagueflow_request_notice'] = 'invalid';
		} elseif ( 'lf_join_request' !== get_post_type( $request_id ) || ! current_user_can( 'edit_post', $request_id ) ) {
			$args['leagueflow_request_notice'] = 'denied';
		} else {
			$args['leagueflow_request_error'] = 'leagueflow_request_stale';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#leagueflow-request-detail' );
		exit;
	}

	/**
	 * Render the sports setup and management page.
	 *
	 * @return void
	 */
	public function render_sports_page() {
		$enabled = $this->sports_manager->get_enabled_sport_slugs();
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php esc_html_e( 'Sports Setup', 'leagueflow' ); ?></h1>
			<p><?php esc_html_e( 'Choose the sports you run, then configure each sport below. Enabled sports appear in the admin shortcuts and sport selector. Disabling a sport keeps its teams, players, fixtures, results and scoring rules.', 'leagueflow' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leagueflow_save_sports" />
				<?php wp_nonce_field( 'leagueflow_save_sports', 'leagueflow_save_sports_nonce' ); ?>

				<div class="leagueflow-sport-grid">
					<?php foreach ( Sports_Manager::get_definitions() as $sport_slug => $sport ) : ?>
						<label class="leagueflow-sport-card">
							<input type="checkbox" name="leagueflow_enabled_sports[]" value="<?php echo esc_attr( $sport_slug ); ?>" <?php checked( in_array( $sport_slug, $enabled, true ) ); ?> />
							<span class="leagueflow-sport-card__icon" aria-hidden="true">
								<?php
								echo sport_icon_svg( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Outputs trusted bundled SVG markup with escaped attributes.
									$sport_slug,
									array(
										'class'  => 'leagueflow-sport-icon leagueflow-sport-card__svg',
										'width'  => '24',
										'height' => '24',
									)
								);
								?>
							</span>
							<span class="leagueflow-sport-card__title"><?php echo esc_html( $sport['label'] ); ?></span>
							<span class="leagueflow-sport-card__description"><?php echo esc_html( $sport['description'] ); ?></span>
							<span class="leagueflow-sport-card__meta"><?php echo esc_html( $sport['match_structure'] ); ?> · <?php echo esc_html( $sport['score_label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<p class="description"><?php esc_html_e( 'Keep at least one sport enabled. These modules provide score labels and optional match notes; your league decides points, match format and tie-breakers.', 'leagueflow' ); ?></p>
				<p><?php submit_button( __( 'Save Sports', 'leagueflow' ), 'primary', 'submit', false ); ?></p>
			</form>
			<section class="leagueflow-admin-card" aria-labelledby="leagueflow-sport-setup-title">
				<h2 id="leagueflow-sport-setup-title"><?php esc_html_e( 'Set up each enabled sport', 'leagueflow' ); ?></h2>
				<p><?php esc_html_e( 'Review scoring, create competitions and seasons, then add teams in a league level. Setup check links to any missing assignments and venue availability.', 'leagueflow' ); ?></p>
				<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Sport setup summary', 'leagueflow' ); ?>">
					<table class="widefat striped">
						<thead><tr><th scope="col"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Current season', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Scoring', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Next steps', 'leagueflow' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $this->sports_manager->get_enabled_sports() as $slug => $sport ) : ?>
							<?php
							$season_id = get_current_season_id( $slug );
							$season = $season_id ? get_term( $season_id, 'lf_season' ) : null;
							$rules = get_effective_rules( $slug )['values'];
							$setup_url = add_query_arg( array( 'page' => 'leagueflow-setup', 'lf_sport' => $slug ), admin_url( 'admin.php' ) );
							$rules_url = add_query_arg( array( 'page' => 'leagueflow-settings', 'lf_sport' => $slug, 'rules_scope' => 'sport:' . $slug ), admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides';
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $sport['label'] ); ?><br /><span class="description"><?php echo esc_html( $sport['score_label'] ); ?></span></th>
								<td><?php echo esc_html( $season instanceof \WP_Term ? $season->name : __( 'Not set — all seasons', 'leagueflow' ) ); ?><br /><a href="<?php echo esc_url( add_query_arg( array( 'taxonomy' => 'lf_season', 'post_type' => 'lf_match', 'lf_sport' => $slug ), admin_url( 'edit-tags.php' ) ) ); ?>"><?php esc_html_e( 'Manage seasons', 'leagueflow' ); ?></a></td>
								<td><?php echo esc_html( sprintf( /* translators: 1: win points, 2: draw points, 3: loss points */ __( 'Win %1$d · Draw %2$d · Loss %3$d', 'leagueflow' ), $rules['points_win'], $rules['points_draw'], $rules['points_loss'] ) ); ?><br /><a href="<?php echo esc_url( $rules_url ); ?>"><?php esc_html_e( 'Review scoring rules', 'leagueflow' ); ?></a></td>
								<td><a class="button" href="<?php echo esc_url( $setup_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s: sport */ __( 'Set up %s', 'leagueflow' ), $sport['label'] ) ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render a sport overview (kept for callers of the pre-1.0.4 per-sport page).
	 *
	 * @return void
	 */
	public function render_sport_dashboard_page() {
		$this->render_dashboard_page();
	}

	/**
	 * Overview: what needs attention, then the sports and the setup steps.
	 *
	 * With a sport in context the counts, queue and links are limited to it.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		$sport_slug = $this->get_context_sport_slug();
		$sport      = $sport_slug ? $this->sports_manager->get_definition( $sport_slug ) : null;
		$sport_term = $sport_slug ? get_term_by( 'slug', $sport_slug, 'lf_sport' ) : null;
		$sport_id   = $sport_term instanceof \WP_Term ? (int) $sport_term->term_id : 0;
		$link       = static function ( $path, $args = array() ) use ( $sport_slug ) {
			return add_query_arg( array_filter( array_merge( $args, array( 'lf_sport' => $sport_slug ) ) ), admin_url( $path ) );
		};
		?>
		<div class="wrap leagueflow-admin-page leagueflow-overview">
			<h1><?php echo esc_html( $sport ? sprintf( /* translators: %s: sport */ __( '%s overview', 'leagueflow' ), $sport['label'] ) : __( 'LeagueFlow overview', 'leagueflow' ) ); ?></h1>
			<p class="leagueflow-page-intro"><?php echo esc_html( $sport ? $sport['description'] : __( 'Run leagues in this order: set up sports, levels, competitions and seasons; build rosters and review requests; generate fixtures; schedule them; record results; then publish standings and brackets.', 'leagueflow' ) ); ?></p>

			<?php if ( $this->sports_manager->is_setup_required() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Choose the sports you run before adding teams and fixtures.', 'leagueflow' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ); ?>"><?php esc_html_e( 'Set up sports', 'leagueflow' ); ?></a></p></div>
			<?php endif; ?>

			<div class="leagueflow-admin-grid">
				<section class="leagueflow-admin-card" aria-labelledby="leagueflow-attention-title">
					<h2 id="leagueflow-attention-title"><?php esc_html_e( 'Needs attention', 'leagueflow' ); ?></h2>
					<?php $this->render_attention_queue( $sport_slug ); ?>
				</section>

				<section class="leagueflow-admin-card" aria-labelledby="leagueflow-shortcuts-title">
					<h2 id="leagueflow-shortcuts-title"><?php esc_html_e( 'Work on', 'leagueflow' ); ?></h2>
					<ul class="leagueflow-link-list">
						<?php if ( current_user_can( Admin_Setup::CAPABILITY ) ) : ?>
							<li><a href="<?php echo esc_url( $link( 'admin.php', array( 'page' => Admin_Setup::PAGE ) ) ); ?>"><?php esc_html_e( 'Setup check', 'leagueflow' ); ?></a> <span class="description"><?php esc_html_e( '(levels, competitions, seasons, current season, venues)', 'leagueflow' ); ?></span></li>
						<?php endif; ?>
						<li><a href="<?php echo esc_url( $link( 'edit.php', array( 'post_type' => 'lf_team' ) ) ); ?>"><?php esc_html_e( 'Teams', 'leagueflow' ); ?></a> · <a href="<?php echo esc_url( $link( 'post-new.php', array( 'post_type' => 'lf_team' ) ) ); ?>"><?php esc_html_e( 'Add team', 'leagueflow' ); ?></a></li>
						<li><a href="<?php echo esc_url( $link( 'edit.php', array( 'post_type' => 'lf_player' ) ) ); ?>"><?php esc_html_e( 'Players', 'leagueflow' ); ?></a> · <a href="<?php echo esc_url( $link( 'post-new.php', array( 'post_type' => 'lf_player' ) ) ); ?>"><?php esc_html_e( 'Add player', 'leagueflow' ); ?></a></li>
						<?php if ( current_user_can( 'leagueflow_manage_placements' ) ) : ?>
							<li><a href="<?php echo esc_url( $link( 'admin.php', array( 'page' => 'leagueflow-requests' ) ) ); ?>"><?php esc_html_e( 'Requests', 'leagueflow' ); ?></a></li>
						<?php endif; ?>
						<li><a href="<?php echo esc_url( $link( 'edit.php', array( 'post_type' => 'lf_match' ) ) ); ?>"><?php esc_html_e( 'Matches', 'leagueflow' ); ?></a> · <a href="<?php echo esc_url( $link( 'post-new.php', array( 'post_type' => 'lf_match' ) ) ); ?>"><?php esc_html_e( 'Add match', 'leagueflow' ); ?></a></li>
						<?php if ( current_user_can( 'leagueflow_manage_fixtures' ) ) : ?>
							<li><a href="<?php echo esc_url( $link( 'admin.php', array( 'page' => 'leagueflow-fixtures' ) ) ); ?>"><?php esc_html_e( 'Generate fixtures', 'leagueflow' ); ?></a></li>
						<?php endif; ?>
						<?php if ( current_user_can( 'leagueflow_manage_field_availability' ) ) : ?>
							<li><a href="<?php echo esc_url( $link( 'admin.php', array( 'page' => 'leagueflow-fields' ) ) ); ?>"><?php esc_html_e( 'Venues & availability', 'leagueflow' ); ?></a></li>
						<?php endif; ?>
						<li><a href="<?php echo esc_url( $link( 'edit.php', array( 'post_type' => 'lf_calendar_event' ) ) ); ?>"><?php esc_html_e( 'Calendar Events', 'leagueflow' ); ?></a> <span class="description"><?php esc_html_e( '(drop-ins, tournaments and other non-match items)', 'leagueflow' ); ?></span></li>
						<li><a href="<?php echo esc_url( $link( 'admin.php', array( 'page' => 'leagueflow-standings' ) ) ); ?>"><?php esc_html_e( 'Standings', 'leagueflow' ); ?></a> · <a href="<?php echo esc_url( $link( 'admin.php', array( 'page' => 'leagueflow-brackets' ) ) ); ?>"><?php esc_html_e( 'Knockout Brackets', 'leagueflow' ); ?></a></li>
					</ul>
				</section>
			</div>

			<?php if ( $sport_slug ) : ?>
				<section class="leagueflow-admin-card" aria-labelledby="leagueflow-sport-counts-title">
					<h2 id="leagueflow-sport-counts-title"><?php esc_html_e( 'Content', 'leagueflow' ); ?></h2>
					<?php $counts = $this->get_sport_content_counts( $sport_id ); ?>
					<table class="widefat striped leagueflow-content-counts">
						<caption class="screen-reader-text"><?php esc_html_e( 'Published and private records in this sport', 'leagueflow' ); ?></caption>
						<tbody>
							<tr><th scope="row"><?php esc_html_e( 'Teams', 'leagueflow' ); ?></th><td><?php echo esc_html( (string) $counts['teams'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Players', 'leagueflow' ); ?></th><td><?php echo esc_html( (string) $counts['players'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Matches', 'leagueflow' ); ?></th><td><?php echo esc_html( (string) $counts['matches'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Calendar Events', 'leagueflow' ); ?></th><td><?php echo esc_html( (string) $counts['calendar_events'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Competitions', 'leagueflow' ); ?></th><td><?php echo esc_html( (string) $counts['competitions'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Seasons', 'leagueflow' ); ?></th><td><?php echo esc_html( (string) $counts['seasons'] ); ?></td></tr>
							<?php $current_season = get_current_season_id( $sport_slug ); ?>
							<tr><th scope="row"><?php esc_html_e( 'Current season', 'leagueflow' ); ?></th><td><?php echo esc_html( $current_season ? get_term_field( 'name', $current_season, 'lf_season' ) : __( 'Not set: blocks show every season', 'leagueflow' ) ); ?></td></tr>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Counts include published and private records. League levels are shared by every sport.', 'leagueflow' ); ?></p>
					<h3><?php esc_html_e( 'By league level', 'leagueflow' ); ?></h3>
					<?php $this->render_sport_level_breakdown( $sport_id ); ?>
				</section>
			<?php elseif ( ! $this->sports_manager->is_setup_required() ) : ?>
				<section class="leagueflow-admin-card" aria-labelledby="leagueflow-sports-title">
					<h2 id="leagueflow-sports-title"><?php esc_html_e( 'Sports', 'leagueflow' ); ?></h2>
					<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Sports', 'leagueflow' ); ?>">
					<table class="widefat striped leagueflow-sport-summary">
						<caption class="screen-reader-text"><?php esc_html_e( 'Published and private records per sport', 'leagueflow' ); ?></caption>
						<thead><tr><th scope="col"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Teams', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Players', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Matches', 'leagueflow' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $this->sports_manager->get_enabled_sports() as $slug => $definition ) : ?>
								<?php
								$term   = get_term_by( 'slug', $slug, 'lf_sport' );
								$counts = $this->get_sport_content_counts( $term instanceof \WP_Term ? (int) $term->term_id : 0 );
								?>
								<tr>
									<th scope="row"><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'leagueflow', 'lf_sport' => $slug ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $definition['label'] ); ?></a></th>
									<td><?php echo esc_html( (string) $counts['teams'] ); ?></td>
									<td><?php echo esc_html( (string) $counts['players'] ); ?></td>
									<td><?php echo esc_html( (string) $counts['matches'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<p class="description"><?php esc_html_e( 'Counts include published and private records. A player who plays several sports is counted in each.', 'leagueflow' ); ?></p>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ); ?>"><?php esc_html_e( 'Enable or disable sports', 'leagueflow' ); ?></a></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Items that need a decision, each linking to the filtered screen.
	 *
	 * @param string $sport_slug Sport context, or empty for all sports.
	 * @return void
	 */
	protected function render_attention_queue( $sport_slug ) {
		$items = array();

		if ( current_user_can( 'leagueflow_manage_placements' ) ) {
			$requests = $this->requests_admin->load(
				array(
					'lf_sport' => $sport_slug,
					's'        => '',
				)
			);
			$pending  = wp_list_filter( $requests, array( 'status' => 'pending' ) );
			$base     = array( 'page' => Admin_Requests::PAGE, 'lf_sport' => $sport_slug );

			$items[] = array( count( wp_list_filter( $pending, array( 'type' => 'team' ) ) ), __( 'Join-a-team requests to review', 'leagueflow' ), add_query_arg( array_filter( $base + array( 'type' => 'team' ) ), admin_url( 'admin.php' ) ) );
			$items[] = array( count( wp_list_filter( $pending, array( 'type' => 'placement' ) ) ), __( 'Players who need a team (placement)', 'leagueflow' ), add_query_arg( array_filter( $base + array( 'type' => 'placement' ) ), admin_url( 'admin.php' ) ) );
			$items[] = array(
				count( array_filter( $pending, static function ( $info ) { return (bool) array_diff( $info['flags'], Join_Request_Service::warning_flags() ); } ) ),
				__( 'Pending requests with a problem (missing player, trashed team, already on a team…)', 'leagueflow' ),
				add_query_arg( array_filter( $base + array( 'issues' => 1 ) ), admin_url( 'admin.php' ) ),
			);
			$items[] = array(
				count( wp_list_filter( $requests, array( 'status' => 'unknown' ) ) ),
				__( 'Requests with a missing or unrecognised status (review before deciding)', 'leagueflow' ),
				add_query_arg( array_filter( $base + array( 'status' => 'unknown' ) ), admin_url( 'admin.php' ) ),
			);
		}

		$match_base = array_filter( array( 'post_type' => 'lf_match', 'lf_sport' => $sport_slug ) );
		$items[]    = array( $this->count_list_posts( 'lf_match', 'all', $this->get_unscheduled_meta_clause( 'lf_match_datetime' ) ), __( 'Matches without a date (Unscheduled)', 'leagueflow' ), add_query_arg( $match_base + array( 'lf_schedule' => 'unscheduled' ), admin_url( 'edit.php' ) ) );
		$items[]    = array( $this->count_list_posts( 'lf_match', 'draft' ), __( 'Draft matches to review and publish', 'leagueflow' ), add_query_arg( $match_base + array( 'post_status' => 'draft' ), admin_url( 'edit.php' ) ) );

		if ( current_user_can( Admin_Setup::CAPABILITY ) ) {
			$missing = 0;

			foreach ( $sport_slug ? array( $sport_slug ) : $this->sports_manager->get_enabled_sport_slugs() as $slug ) {
				foreach ( array( 'lf_team', 'lf_match' ) as $post_type ) {
					$missing += $this->setup_admin->count_records( $post_type, $slug, 'lf_season' );
				}
			}

			$items[] = array( $missing, __( 'Teams and matches without a season (optional: review and map them in Setup check)', 'leagueflow' ), add_query_arg( array_filter( array( 'page' => Admin_Setup::PAGE, 'lf_sport' => $sport_slug ) ), admin_url( 'admin.php' ) ) );
		}

		if ( current_user_can( 'leagueflow_manage_field_availability' ) ) {
			$rules   = $this->field_availability_manager->get_availabilities( array( 'sport_slug' => $sport_slug ) );
			$items[] = array( $rules ? 0 : 1, __( 'No venue availability yet, so automatic scheduling has nothing to place matches into', 'leagueflow' ), add_query_arg( array_filter( array( 'page' => 'leagueflow-fields', 'lf_sport' => $sport_slug ) ), admin_url( 'admin.php' ) ), true );
		}

		$open = array_filter( $items, static function ( $item ) { return $item[0] > 0; } );

		if ( ! $open ) {
			echo '<p>' . esc_html__( 'Nothing is waiting. New requests, undated matches and drafts will appear here.', 'leagueflow' ) . '</p>';
			return;
		}

		echo '<ul class="leagueflow-attention">';

		foreach ( $open as $item ) {
			printf(
				'<li><a href="%1$s">%2$s%3$s</a></li>',
				esc_url( $item[2] ),
				empty( $item[3] ) ? '<span class="leagueflow-attention__count">' . esc_html( number_format_i18n( $item[0] ) ) . '</span> ' : '',
				esc_html( $item[1] )
			);
		}

		echo '</ul>';
	}

	/**
	 * Render utilities admin page.
	 *
	 * @return void
	 */
	public function render_utilities_page() {
		$enabled_sports = $this->sports_manager->get_enabled_sports();
		$season_terms   = get_terms(
			array(
				'taxonomy'   => 'lf_season',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $season_terms ) ) {
			$season_terms = array();
		}
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php esc_html_e( 'LeagueFlow Utilities', 'leagueflow' ); ?></h1>
			<p><?php esc_html_e( 'Run administrative tools for audits, data movement, and operational reporting.', 'leagueflow' ); ?></p>

			<div class="leagueflow-admin-grid">
				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Player Roster Exporter', 'leagueflow' ); ?></h2>
					<p><?php esc_html_e( 'Export player roster data by sport, with optional season and date filters. ZIP bundles create one file per sport.', 'leagueflow' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="leagueflow_export_players" />
						<?php wp_nonce_field( 'leagueflow_export_players', 'leagueflow_export_players_nonce' ); ?>

						<table class="form-table leagueflow-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="leagueflow_export_file_format"><?php esc_html_e( 'File Format', 'leagueflow' ); ?></label></th>
								<td>
									<select id="leagueflow_export_file_format" name="leagueflow_export_file_format">
										<option value="xlsx"><?php esc_html_e( 'Excel workbook (.xlsx)', 'leagueflow' ); ?></option>
										<option value="csv"><?php esc_html_e( 'CSV (.csv)', 'leagueflow' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="leagueflow_export_delivery"><?php esc_html_e( 'Delivery', 'leagueflow' ); ?></label></th>
								<td>
									<select id="leagueflow_export_delivery" name="leagueflow_export_delivery">
										<option value="single"><?php esc_html_e( 'Single file', 'leagueflow' ); ?></option>
										<option value="bundle"><?php esc_html_e( 'ZIP bundle, one file per sport', 'leagueflow' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="leagueflow_export_sport"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label></th>
								<td>
									<select id="leagueflow_export_sport" name="leagueflow_export_sport">
										<option value="all"><?php esc_html_e( 'All enabled sports', 'leagueflow' ); ?></option>
										<?php foreach ( $enabled_sports as $sport ) : ?>
											<option value="<?php echo esc_attr( $sport['slug'] ); ?>"><?php echo esc_html( $sport['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="leagueflow_export_season"><?php esc_html_e( 'Season', 'leagueflow' ); ?></label></th>
								<td>
									<select id="leagueflow_export_season" name="leagueflow_export_season">
										<option value="0"><?php esc_html_e( 'All seasons / all time', 'leagueflow' ); ?></option>
										<?php foreach ( $season_terms as $season ) : ?>
											<option value="<?php echo esc_attr( (string) $season->term_id ); ?>"><?php echo esc_html( $season->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Season filtering uses the seasons assigned to each exported team row.', 'leagueflow' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Player Created Dates', 'leagueflow' ); ?></th>
								<td>
									<label for="leagueflow_export_date_from"><?php esc_html_e( 'From', 'leagueflow' ); ?></label>
									<input type="date" id="leagueflow_export_date_from" name="leagueflow_export_date_from" />
									<label for="leagueflow_export_date_to"><?php esc_html_e( 'To', 'leagueflow' ); ?></label>
									<input type="date" id="leagueflow_export_date_to" name="leagueflow_export_date_to" />
									<p class="description"><?php esc_html_e( 'Leave both blank for all player records regardless of creation date.', 'leagueflow' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="leagueflow_export_status_scope"><?php esc_html_e( 'Team / Player Status', 'leagueflow' ); ?></label></th>
								<td>
									<select id="leagueflow_export_status_scope" name="leagueflow_export_status_scope">
										<option value="active"><?php esc_html_e( 'Active published records only', 'leagueflow' ); ?></option>
										<option value="historical"><?php esc_html_e( 'All historical statuses', 'leagueflow' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'leagueflow' ); ?></th>
								<td>
									<p><label><input type="checkbox" name="leagueflow_export_include_unassigned" value="1" checked="checked" /> <?php esc_html_e( 'Include players without a matching team in the selected sport', 'leagueflow' ); ?></label></p>
									<p><label><input type="checkbox" name="leagueflow_export_include_user_accounts" value="1" /> <?php esc_html_e( 'Include linked WordPress user account columns', 'leagueflow' ); ?></label></p>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Download Export', 'leagueflow' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render field availability and scheduling assistant page.
	 *
	 * @return void
	 */
	public function render_field_availability_page() {
		if ( ! current_user_can( 'leagueflow_manage_field_availability' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		$page            = 'leagueflow-fields';
		$sport_slug      = $this->get_context_sport_slug();
		$is_sport_locked = '' !== $sport_slug;
		$edit_id         = sanitize_key( wp_unslash( $_GET['availability_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing         = $edit_id ? $this->field_availability_manager->get_availability( $edit_id ) : null;
		$availabilities  = $this->field_availability_manager->get_availabilities(
			array(
				'sport_slug' => $sport_slug,
			)
		);
		?>
		<div class="wrap leagueflow-admin-page leagueflow-field-page">
			<h1><?php esc_html_e( 'Venues & availability', 'leagueflow' ); ?></h1>
			<p class="leagueflow-page-intro"><?php esc_html_e( 'Step 4 of the season: say when each venue is free, then let the scheduling assistant give undated matches a date, time and venue. You can still edit any match by hand, and the assistant only replaces existing values when you turn on overwrite.', 'leagueflow' ); ?></p>
			<?php if ( ! $availabilities ) : ?>
				<div class="leagueflow-empty-state">
					<p><strong><?php esc_html_e( 'No venue availability yet.', 'leagueflow' ); ?></strong> <?php esc_html_e( 'Automatic scheduling needs at least one availability rule. Add one with the form below, or schedule matches by hand from Matches.', 'leagueflow' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="leagueflow-admin-grid leagueflow-admin-grid--wide">
				<div class="leagueflow-admin-card">
					<h2><?php echo $editing ? esc_html__( 'Edit Availability', 'leagueflow' ) : esc_html__( 'Add Availability', 'leagueflow' ); ?></h2>
					<?php $this->render_field_availability_form( $editing, $sport_slug, $page ); ?>
				</div>

				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Auto Schedule Matches', 'leagueflow' ); ?></h2>
					<p><?php esc_html_e( 'Fill empty match dates, venues, or both from the availability rules. Turn on overwrite only when you intentionally want to replace manual scheduling.', 'leagueflow' ); ?></p>
					<?php $this->render_auto_schedule_form( $sport_slug, $page, $is_sport_locked ); ?>
				</div>
			</div>

			<div class="leagueflow-admin-card">
				<h2><?php esc_html_e( 'Availability Rules', 'leagueflow' ); ?></h2>
				<?php $this->render_field_availability_table( $availabilities, $sport_slug, $page ); ?>
			</div>

			<div class="leagueflow-admin-card">
				<h2><?php esc_html_e( 'Available Slots Preview', 'leagueflow' ); ?></h2>
				<p><?php esc_html_e( 'Pick a date to see open slots after existing matches and calendar events at the same venue are accounted for.', 'leagueflow' ); ?></p>
				<?php $this->render_available_slots_preview( $sport_slug, $page, $is_sport_locked ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render add/edit availability form.
	 *
	 * @param array<string, mixed>|null $availability Availability.
	 * @param string                    $sport_slug Current sport slug.
	 * @param string                    $page Page slug.
	 * @return void
	 */
	protected function render_field_availability_form( $availability, $sport_slug, $page ) {
		if ( ! current_user_can( 'leagueflow_manage_field_availability' ) ) { return; }

		$availability = wp_parse_args(
			is_array( $availability ) ? $availability : array(),
			array(
				'id'             => '',
				'name'           => '',
				'venue'          => '',
				'sport_slug'     => $sport_slug,
				'date'           => '',
				'weekday'        => (int) wp_date( 'w' ),
				'start_time'     => '18:00',
				'end_time'       => '22:00',
				'slot_minutes'   => 60,
				'buffer_minutes' => 0,
				'active'         => true,
				'notes'          => '',
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leagueflow_save_field_availability" />
			<input type="hidden" name="leagueflow_availability_page" value="<?php echo esc_attr( $page ); ?>" />
			<input type="hidden" name="leagueflow_availability_id" value="<?php echo esc_attr( (string) $availability['id'] ); ?>" />
			<input type="hidden" name="leagueflow_current_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
			<?php wp_nonce_field( 'leagueflow_save_field_availability', 'leagueflow_save_field_availability_nonce' ); ?>

			<table class="form-table leagueflow-form-table" role="presentation">
				<tr>
					<th scope="row"><label for="leagueflow_availability_name"><?php esc_html_e( 'Name', 'leagueflow' ); ?></label></th>
					<td><input type="text" id="leagueflow_availability_name" name="leagueflow_availability_name" class="regular-text" value="<?php echo esc_attr( (string) $availability['name'] ); ?>" placeholder="<?php esc_attr_e( 'North Field evenings', 'leagueflow' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_venue"><?php esc_html_e( 'Field / Venue', 'leagueflow' ); ?></label></th>
					<td><input type="text" id="leagueflow_availability_venue" name="leagueflow_availability_venue" class="regular-text" value="<?php echo esc_attr( (string) $availability['venue'] ); ?>" placeholder="<?php esc_attr_e( 'North Field', 'leagueflow' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_sport"><?php esc_html_e( 'Sport Scope', 'leagueflow' ); ?></label></th>
					<td>
						<select id="leagueflow_availability_sport" name="leagueflow_availability_sport">
							<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
							<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
								<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( (string) $availability['sport_slug'], $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Use all sports for shared fields, or lock the window to one sport.', 'leagueflow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_date"><?php esc_html_e( 'Specific Date', 'leagueflow' ); ?></label></th>
					<td>
						<input type="date" id="leagueflow_availability_date" name="leagueflow_availability_date" value="<?php echo esc_attr( (string) $availability['date'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. A specific date overrides the recurring weekday below.', 'leagueflow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_weekday"><?php esc_html_e( 'Recurring Weekday', 'leagueflow' ); ?></label></th>
					<td>
						<select id="leagueflow_availability_weekday" name="leagueflow_availability_weekday">
							<?php foreach ( $this->field_availability_manager->get_weekday_options() as $weekday => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $weekday ); ?>" <?php selected( (string) $availability['weekday'], (string) $weekday ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Available Time', 'leagueflow' ); ?></th>
					<td>
						<label for="leagueflow_availability_start"><?php esc_html_e( 'From', 'leagueflow' ); ?></label>
						<input type="time" id="leagueflow_availability_start" name="leagueflow_availability_start" value="<?php echo esc_attr( (string) $availability['start_time'] ); ?>" />
						<label for="leagueflow_availability_end"><?php esc_html_e( 'To', 'leagueflow' ); ?></label>
						<input type="time" id="leagueflow_availability_end" name="leagueflow_availability_end" value="<?php echo esc_attr( (string) $availability['end_time'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_slot_minutes"><?php esc_html_e( 'Match Length', 'leagueflow' ); ?></label></th>
					<td>
						<input type="number" min="1" id="leagueflow_availability_slot_minutes" name="leagueflow_availability_slot_minutes" class="small-text" value="<?php echo esc_attr( (string) $availability['slot_minutes'] ); ?>" />
						<span><?php esc_html_e( 'minutes', 'leagueflow' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_buffer_minutes"><?php esc_html_e( 'Buffer', 'leagueflow' ); ?></label></th>
					<td>
						<input type="number" min="0" id="leagueflow_availability_buffer_minutes" name="leagueflow_availability_buffer_minutes" class="small-text" value="<?php echo esc_attr( (string) $availability['buffer_minutes'] ); ?>" />
						<span><?php esc_html_e( 'minutes between matches', 'leagueflow' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'leagueflow' ); ?></th>
					<td><label><input type="checkbox" name="leagueflow_availability_active" value="1" <?php checked( ! empty( $availability['active'] ) ); ?> /> <?php esc_html_e( 'Available for auto-scheduling', 'leagueflow' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_availability_notes"><?php esc_html_e( 'Notes', 'leagueflow' ); ?></label></th>
					<td><textarea id="leagueflow_availability_notes" name="leagueflow_availability_notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $availability['notes'] ); ?></textarea></td>
				</tr>
			</table>

			<?php submit_button( $availability['id'] ? __( 'Update Availability', 'leagueflow' ) : __( 'Add Availability', 'leagueflow' ), 'primary', 'submit', false, array( 'id' => 'leagueflow-availability-submit' ) ); ?>
			<?php if ( $availability['id'] ) : ?>
				<a class="button" href="<?php echo esc_url( $this->get_field_page_url( $page, $sport_slug ) ); ?>"><?php esc_html_e( 'Cancel Edit', 'leagueflow' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render saved availability table.
	 *
	 * @param array<int, array<string, mixed>> $availabilities Availability rows.
	 * @param string                           $sport_slug Current sport slug.
	 * @param string                           $page Page slug.
	 * @return void
	 */
	protected function render_field_availability_table( $availabilities, $sport_slug, $page ) {
		if ( empty( $availabilities ) ) {
			echo '<p>' . esc_html__( 'No field availability has been defined for this scope yet.', 'leagueflow' ) . '</p>';
			return;
		}

		$weekday_options = $this->field_availability_manager->get_weekday_options();
		?>
		<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Venue availability', 'leagueflow' ); ?>">
		<table class="widefat striped leagueflow-availability-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Field', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'When', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Slot', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Status', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'leagueflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $availabilities as $availability ) : ?>
					<?php
					$when = ! empty( $availability['date'] ) ? $availability['date'] : ( $weekday_options[ (int) $availability['weekday'] ] ?? __( 'Weekly', 'leagueflow' ) );
					$edit_url = add_query_arg(
						array_filter(
							array(
								'page'            => $page,
								'sport'           => $sport_slug,
								'availability_id' => $availability['id'],
							)
						),
						admin_url( 'admin.php' )
					);
					$delete_url = wp_nonce_url(
						add_query_arg(
							array_filter(
								array(
									'action'          => 'leagueflow_delete_field_availability',
									'availability_id' => $availability['id'],
									'page'            => $page,
									'sport'           => $sport_slug,
								)
							),
							admin_url( 'admin-post.php' )
						),
						'leagueflow_delete_field_availability_' . $availability['id'],
						'leagueflow_delete_field_availability_nonce'
					);
					?>
					<tr>
						<td><strong><?php echo esc_html( (string) $availability['name'] ); ?></strong></td>
						<td><?php echo esc_html( (string) $availability['venue'] ); ?></td>
						<td><?php echo esc_html( $this->get_sport_scope_label( (string) $availability['sport_slug'] ) ); ?></td>
						<td><?php echo esc_html( $when . ' ' . $availability['start_time'] . '-' . $availability['end_time'] ); ?></td>
						<td><?php echo esc_html( sprintf( '%d min + %d min', (int) $availability['slot_minutes'], (int) $availability['buffer_minutes'] ) ); ?></td>
						<td><?php echo ! empty( $availability['active'] ) ? esc_html__( 'Active', 'leagueflow' ) : esc_html__( 'Inactive', 'leagueflow' ); ?></td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'leagueflow' ); ?></a>
							<span aria-hidden="true"> | </span>
							<a class="submitdelete" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'leagueflow' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render auto schedule form.
	 *
	 * @param string $sport_slug Current sport slug.
	 * @param string $page Page slug.
	 * @param bool   $is_sport_locked Whether a single sport is selected.
	 * @return void
	 */
	protected function render_auto_schedule_form( $sport_slug, $page, $is_sport_locked ) {
		if ( ! current_user_can( 'leagueflow_manage_schedule' ) ) { return; }

		$today          = current_time( 'Y-m-d' );
		$default_to     = wp_date( 'Y-m-d', strtotime( '+90 days', current_time( 'timestamp' ) ) );
		$competitions   = $this->get_sport_labelled_term_options( 'lf_competition', $sport_slug );
		$seasons        = $this->get_sport_labelled_term_options( 'lf_season', $sport_slug );
		$availabilities = $this->field_availability_manager->get_availability_options( $sport_slug );

		if ( ! $availabilities ) {
			?>
			<div class="leagueflow-empty-state">
				<p><strong><?php esc_html_e( 'Add venue availability first.', 'leagueflow' ); ?></strong> <?php esc_html_e( 'The assistant places undated matches into open availability slots. With no availability rules for this sport it has nowhere to put them, so it is turned off here. You can still date any match by hand from Matches.', 'leagueflow' ); ?></p>
				<p><a href="#leagueflow_availability_name"><?php esc_html_e( 'Go to the availability form', 'leagueflow' ); ?></a> · <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'post_type' => 'lf_match', 'lf_sport' => $sport_slug, 'lf_schedule' => 'unscheduled' ) ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Unscheduled matches', 'leagueflow' ); ?></a></p>
			</div>
			<?php
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leagueflow_auto_schedule_matches" />
			<input type="hidden" name="leagueflow_schedule_page" value="<?php echo esc_attr( $page ); ?>" />
			<?php wp_nonce_field( 'leagueflow_auto_schedule_matches', 'leagueflow_auto_schedule_matches_nonce' ); ?>

			<table class="form-table leagueflow-form-table" role="presentation">
				<?php if ( $is_sport_locked ) : ?>
					<input type="hidden" name="leagueflow_schedule_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
				<?php else : ?>
					<tr>
						<th scope="row"><label for="leagueflow_schedule_sport"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label></th>
						<td>
							<select id="leagueflow_schedule_sport" name="leagueflow_schedule_sport">
								<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
								<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
									<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $sport_slug, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="leagueflow_schedule_competition"><?php esc_html_e( 'Competition', 'leagueflow' ); ?></label></th>
					<td><?php $this->render_select( 'leagueflow_schedule_competition', $competitions, 0, __( 'All competitions', 'leagueflow' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_schedule_season"><?php esc_html_e( 'Season', 'leagueflow' ); ?></label></th>
					<td><?php $this->render_select( 'leagueflow_schedule_season', $seasons, 0, __( 'All seasons', 'leagueflow' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_schedule_availability"><?php esc_html_e( 'Availability', 'leagueflow' ); ?></label></th>
					<td><?php $this->render_select( 'leagueflow_schedule_availability', $availabilities, '', __( 'Any matching availability', 'leagueflow' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_schedule_mode"><?php esc_html_e( 'Update', 'leagueflow' ); ?></label></th>
					<td><?php $this->render_select( 'leagueflow_schedule_mode', $this->field_availability_manager->get_update_modes(), 'both', null ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Date Range', 'leagueflow' ); ?></th>
					<td>
						<label for="leagueflow_schedule_date_from"><?php esc_html_e( 'From', 'leagueflow' ); ?></label>
						<input type="date" id="leagueflow_schedule_date_from" name="leagueflow_schedule_date_from" value="<?php echo esc_attr( $today ); ?>" />
						<label for="leagueflow_schedule_date_to"><?php esc_html_e( 'To', 'leagueflow' ); ?></label>
						<input type="date" id="leagueflow_schedule_date_to" name="leagueflow_schedule_date_to" value="<?php echo esc_attr( $default_to ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="leagueflow_schedule_date"><?php esc_html_e( 'Specific Date Override', 'leagueflow' ); ?></label></th>
					<td>
						<input type="date" id="leagueflow_schedule_date" name="leagueflow_schedule_date" />
						<p class="description"><?php esc_html_e( 'Optional. When set, the assistant only uses availability on this date.', 'leagueflow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Manual Overrides', 'leagueflow' ); ?></th>
					<td>
						<label><input type="checkbox" name="leagueflow_schedule_overwrite" value="1" /> <?php esc_html_e( 'Replace existing match date/time or venue values in the selected update mode', 'leagueflow' ); ?></label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Auto Schedule Matches', 'leagueflow' ), 'primary', 'submit', false, array( 'id' => 'leagueflow-schedule-submit' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render open slot preview.
	 *
	 * @param string $sport_slug Current sport slug.
	 * @param string $page Page slug.
	 * @param bool   $is_sport_locked Whether a single sport is selected.
	 * @return void
	 */
	protected function render_available_slots_preview( $sport_slug, $page, $is_sport_locked ) {
		$preview_date    = $this->sanitize_admin_date( wp_unslash( $_GET['preview_date'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview_date    = $preview_date ? $preview_date : current_time( 'Y-m-d' );
		$availability_id = sanitize_key( wp_unslash( $_GET['preview_availability'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slots           = $this->field_availability_manager->get_available_slots(
			array(
				'sport_slug'      => $sport_slug,
				'date'            => $preview_date,
				'availability_id' => $availability_id,
			)
		);
		?>
		<form class="leagueflow-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<?php if ( $is_sport_locked ) : ?>
				<input type="hidden" name="sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
			<?php else : ?>
				<span class="leagueflow-filter-field">
					<label for="leagueflow-preview-sport"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
					<select id="leagueflow-preview-sport" name="sport">
						<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
						<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
							<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $sport_slug, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			<?php endif; ?>
			<span class="leagueflow-filter-field">
				<label for="leagueflow-preview-date"><?php esc_html_e( 'Date', 'leagueflow' ); ?></label>
				<input type="date" id="leagueflow-preview-date" name="preview_date" value="<?php echo esc_attr( $preview_date ); ?>" />
			</span>
			<span class="leagueflow-filter-field">
				<label for="leagueflow-preview-availability"><?php esc_html_e( 'Availability', 'leagueflow' ); ?></label>
				<select id="leagueflow-preview-availability" name="preview_availability">
					<option value=""><?php esc_html_e( 'Any matching availability', 'leagueflow' ); ?></option>
					<?php foreach ( $this->field_availability_manager->get_availability_options( $sport_slug ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $availability_id, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>
			<?php submit_button( __( 'Preview Slots', 'leagueflow' ), 'secondary', 'submit', false, array( 'id' => 'leagueflow-preview-slots-submit' ) ); ?>
		</form>

		<?php if ( empty( $slots ) ) : ?>
			<p><?php esc_html_e( 'No open slots are available for the selected date.', 'leagueflow' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped leagueflow-slots-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Field', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Availability', 'leagueflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $slots, 0, 80 ) as $slot ) : ?>
					<tr>
						<td><?php echo esc_html( $slot['start_time'] . '-' . $slot['end_time'] ); ?></td>
						<td><?php echo esc_html( (string) $slot['venue'] ); ?></td>
						<td><?php echo esc_html( (string) $slot['availability_label'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $slots ) > 80 ) : ?>
			<p class="description"><?php esc_html_e( 'Showing the first 80 open slots for this date.', 'leagueflow' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render standings admin page.
	 *
	 * @return void
	 */
	public function render_standings_page() {
		$sport_slug      = $this->get_context_sport_slug();
		$competition_id  = resolve_term_id( $_GET['competition'] ?? '', 'lf_competition' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$season          = $this->get_admin_season_choice( $sport_slug );
		$season_id       = $season['id'];
		$league_level_id = resolve_term_id( $_GET['league_level'] ?? '', 'lf_league_level' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page            = 'leagueflow-standings';

		if ( '' === $sport_slug ) {
			?>
			<div class="wrap leagueflow-admin-page">
				<h1><?php esc_html_e( 'Standings', 'leagueflow' ); ?></h1>
				<p class="leagueflow-page-intro"><?php esc_html_e( 'Standings are calculated from finished, published matches. Pick a sport in the sport switcher for its full table and filters. This page shows every sport, grouped by league level.', 'leagueflow' ); ?></p>
				<?php $this->render_standings_diagnostics( $competition_id, $season_id, 0, $league_level_id ); ?>
				<?php $this->render_admin_sport_standings_overview( $competition_id, $season_id, $league_level_id ); ?>
			</div>
			<?php
			return;
		}

		$sport_id     = resolve_term_id( $sport_slug, 'lf_sport' );
		$sport        = $this->sports_manager->get_definition( $sport_slug );
		$table_labels = $sport['table_labels'];
		$levels       = $league_level_id ? array( get_term( $league_level_id, 'lf_league_level' ) ) : $this->get_league_level_terms();
		$levels       = array_values( array_filter( $levels, static function ( $level ) { return $level instanceof \WP_Term; } ) );
		$rules        = get_effective_rules( $sport_slug, $competition_id );
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php echo esc_html( sprintf( /* translators: %s: sport */ __( '%s standings', 'leagueflow' ), $sport['label'] ) ); ?></h1>
			<?php $this->render_context_filters( $page, $competition_id, $season_id, $sport_slug, $league_level_id, $season['mode'] ); ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: win, 2: draw, 3: loss points */
						__( 'Calculated from finished, published matches. Table points: win %1$d, draw (tie) %2$d, loss %3$d.', 'leagueflow' ),
						$rules['values']['points_win'],
						$rules['values']['points_draw'],
						$rules['values']['points_loss']
					)
				);
				?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'leagueflow-settings', 'rules_scope' => 'sport:' . $sport_slug ), admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides' ); ?>"><?php esc_html_e( 'Scoring for this sport', 'leagueflow' ); ?></a>
				<?php endif; ?>
			</p>
			<?php $this->render_standings_diagnostics( $competition_id, $season_id, $sport_id, $league_level_id ); ?>
			<?php if ( ! $league_level_id && count( $levels ) > 1 ) : ?>
				<p class="description"><?php esc_html_e( 'Each level (division) has its own table. Choose a level to see one table.', 'leagueflow' ); ?></p>
			<?php endif; ?>
			<?php if ( ! $levels ) : ?>
				<?php $this->render_admin_standings_table( $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, 0 ), $table_labels, $sport['label'] ); ?>
			<?php endif; ?>
			<?php foreach ( $levels as $level ) : ?>
				<?php if ( count( $levels ) > 1 || ! $league_level_id ) : ?>
					<h2><?php echo esc_html( $level->name ); ?></h2>
				<?php endif; ?>
				<?php $this->render_admin_standings_table( $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, (int) $level->term_id ), $table_labels, $sport['label'] . ' - ' . $level->name ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** Explain excluded results in the same context as the visible standings. */
	protected function render_standings_diagnostics( $competition_id, $season_id, $sport_id, $league_level_id ) {
		$diagnostics = $this->standings_service->get_diagnostics( $competition_id, $season_id, $sport_id, $league_level_id );
		$labels = array(
			'counted' => __( 'Finished league results counted', 'leagueflow' ),
			'scored_not_finished' => __( 'Scores entered, but not marked Finished', 'leagueflow' ),
			'incomplete' => __( 'Finished results with missing or invalid scores', 'leagueflow' ),
			'unpublished' => __( 'Not published on the website', 'leagueflow' ),
			'invalid_teams' => __( 'Missing, inaccessible, or invalid teams', 'leagueflow' ),
			'knockout' => __( 'Knockout fixtures excluded from league standings', 'leagueflow' ),
			'unfinished' => __( 'Fixtures awaiting a final result', 'leagueflow' ),
		);
		echo '<details class="leagueflow-standings-diagnostics"><summary>' . esc_html__( 'Why a result is missing from standings', 'leagueflow' ) . '</summary><ul>';
		foreach ( $labels as $key => $label ) {
			$count = (int) ( $diagnostics['counts'][ $key ] ?? 0 );
			if ( ! $count && 'counted' !== $key ) { continue; }
			echo '<li><strong>' . esc_html( number_format_i18n( $count ) . ' — ' . $label ) . '</strong>';
			$links = array();
			foreach ( $diagnostics['examples'][ $key ] ?? array() as $id ) {
				if ( current_user_can( 'edit_post', $id ) ) { $links[] = '<a href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>'; }
			}
			if ( $links ) { echo ': ' . implode( ', ', $links ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links individually escaped.
			if ( 'scored_not_finished' === $key && $count ) {
				echo '<p class="description">' . esc_html__( 'Review older scored matches before including them. Save a Scheduled match with both final scores to finish it automatically, or select the reviewed matches in Matches and use Set status to Finished. Live, postponed and cancelled matches require an explicit decision.', 'leagueflow' ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ul><p>' . esc_html__( 'If a match is not listed, check the selected sport, season, competition and level. Teams and matches must share the intended context.', 'leagueflow' ) . '</p></details>';
	}

	/**
	 * One admin standings table. The D (draw) column appears only when a team
	 * in the table has a draw, so sports that rarely tie are not shown an
	 * always-empty column; points always include draw points when there are any.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @param array<string, string>            $table_labels Sport column labels.
	 * @param string                           $label Accessible table name.
	 * @return void
	 */
	protected function render_admin_standings_table( $rows, $table_labels, $label ) {
		$has_draws = (bool) array_filter( wp_list_pluck( $rows, 'draws' ) );
		$columns   = $has_draws ? 10 : 9;
		?>
		<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: sport and level */ __( '%s standings table', 'leagueflow' ), $label ) ); ?>">
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><abbr title="<?php esc_attr_e( 'Position', 'leagueflow' ); ?>"><?php esc_html_e( '#', 'leagueflow' ); ?></abbr></th>
					<th scope="col"><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
					<th scope="col"><abbr title="<?php esc_attr_e( 'Played', 'leagueflow' ); ?>"><?php esc_html_e( 'P', 'leagueflow' ); ?></abbr></th>
					<th scope="col"><abbr title="<?php esc_attr_e( 'Won', 'leagueflow' ); ?>"><?php esc_html_e( 'W', 'leagueflow' ); ?></abbr></th>
					<?php if ( $has_draws ) : ?>
						<th scope="col"><abbr title="<?php esc_attr_e( 'Drawn (tied)', 'leagueflow' ); ?>"><?php esc_html_e( 'D', 'leagueflow' ); ?></abbr></th>
					<?php endif; ?>
					<th scope="col"><abbr title="<?php esc_attr_e( 'Lost', 'leagueflow' ); ?>"><?php esc_html_e( 'L', 'leagueflow' ); ?></abbr></th>
					<th scope="col"><?php echo esc_html( $table_labels['for'] ); ?></th>
					<th scope="col"><?php echo esc_html( $table_labels['against'] ); ?></th>
					<th scope="col"><?php echo esc_html( $table_labels['difference'] ); ?></th>
					<th scope="col"><abbr title="<?php esc_attr_e( 'Table points', 'leagueflow' ); ?>"><?php esc_html_e( 'Pts', 'leagueflow' ); ?></abbr></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="<?php echo esc_attr( (string) $columns ); ?>"><?php esc_html_e( 'No standings yet: no team or finished match matches these filters.', 'leagueflow' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['position'] ); ?></td>
						<th scope="row"><a href="<?php echo esc_url( get_edit_post_link( $row['team_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></th>
						<td><?php echo esc_html( (string) $row['played'] ); ?></td>
						<td><?php echo esc_html( (string) $row['wins'] ); ?></td>
						<?php if ( $has_draws ) : ?>
							<td><?php echo esc_html( (string) $row['draws'] ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( (string) $row['losses'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goals_for'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goals_against'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goal_difference'] ); ?></td>
						<td><strong><?php echo esc_html( (string) $row['points'] ); ?></strong><?php echo ! empty( $row['adjustment'] ) ? ' <span class="leagueflow-standings-adjustment">' . esc_html( sprintf( '%+d', (int) $row['adjustment'] ) ) . '</span>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render global backend standings grouped by enabled sport.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $league_level_id League level term ID.
	 * @return void
	 */
	protected function render_admin_sport_standings_overview( $competition_id, $season_id, $league_level_id = 0 ) {
		$league_levels = $league_level_id ? array( get_term( $league_level_id, 'lf_league_level' ) ) : $this->get_league_level_terms();
		?>
		<div class="leagueflow-admin-sport-standings">
			<h2><?php esc_html_e( 'Standings by Sport and Level', 'leagueflow' ); ?></h2>
			<p><?php esc_html_e( 'Each table is one sport and one league level. Pick a sport in the sport switcher for a single table with competition and season filters.', 'leagueflow' ); ?></p>
			<div class="leagueflow-admin-sport-standings__grid">
				<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport_slug => $sport ) : ?>
					<?php
					$sport_id     = resolve_term_id( $sport_slug, 'lf_sport' );
					$table_labels = $sport['table_labels'];
					?>
					<?php foreach ( $league_levels as $league_level ) : ?>
						<?php
						if ( ! $league_level || is_wp_error( $league_level ) ) {
							continue;
						}

						$rows = $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, (int) $league_level->term_id );
						?>
						<section class="leagueflow-admin-sport-standings__panel">
							<header class="leagueflow-admin-sport-standings__header">
								<span><?php echo esc_html( $sport['label'] . ' - ' . $league_level->name ); ?></span>
								<span>
									<?php
									printf(
										/* translators: %d: number of teams. */
										esc_html( _n( '%d team', '%d teams', count( $rows ), 'leagueflow' ) ),
										absint( count( $rows ) )
									);
									?>
								</span>
							</header>
							<div class="leagueflow-admin-sport-standings__body" role="region" tabindex="0" aria-label="<?php echo esc_attr( $sport['label'] . ' - ' . $league_level->name ); ?>">
								<?php if ( empty( $rows ) ) : ?>
									<p><?php esc_html_e( 'No standings are available for this sport and level yet.', 'leagueflow' ); ?></p>
								<?php else : ?>
									<?php $this->render_admin_standings_table( $rows, $table_labels, $sport['label'] . ' - ' . $league_level->name ); ?>
								<?php endif; ?>
							</div>
						</section>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render bracket admin page.
	 *
	 * @return void
	 */
	public function render_brackets_page() {
		$sport_slug      = $this->get_context_sport_slug();
		$competition_id  = resolve_term_id( $_GET['competition'] ?? '', 'lf_competition' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$season_choice   = $this->get_admin_season_choice( $sport_slug );
		$season_id       = $season_choice['id'];
		$league_level_id = resolve_term_id( $_GET['league_level'] ?? '', 'lf_league_level' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$competition     = $competition_id ? get_term( $competition_id, 'lf_competition' ) : null;
		$season          = $season_id ? get_term( $season_id, 'lf_season' ) : null;
		$league_level    = $league_level_id ? get_term( $league_level_id, 'lf_league_level' ) : null;

		if ( '' === $sport_slug ) {
			$this->render_choose_sport_page( __( 'Knockout Brackets', 'leagueflow' ), __( 'Brackets are built per sport from matches marked as knockout. Choose the sport to view.', 'leagueflow' ), 'leagueflow-brackets' );
			return;
		}
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php echo esc_html( sprintf( /* translators: %s: sport */ __( '%s knockout brackets', 'leagueflow' ), $this->sports_manager->get_definition( $sport_slug )['label'] ) ); ?></h1>
			<?php $this->render_context_filters( 'leagueflow-brackets', $competition_id, $season_id, $sport_slug, $league_level_id, $season_choice['mode'] ); ?>
			<p><?php esc_html_e( 'Mark a match as knockout, define its round label and order, and optionally set the next match and home or away slot for automatic winner advancement.', 'leagueflow' ); ?></p>
			<?php
			echo $this->renderer->render_knockout_bracket(
				array(
					'competition'  => $competition ? $competition->slug : '',
					// "All seasons" is passed explicitly so it never falls back to the current season.
					'season'       => ( $season && ! is_wp_error( $season ) ) ? (string) $season->term_id : 'all',
					'sport'        => $sport_slug,
					'league_level' => ( $league_level && ! is_wp_error( $league_level ) ) ? $league_level->slug : '',
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Page that asks for a sport before showing a per-sport tool.
	 *
	 * @param string $title Page title.
	 * @param string $intro Explanation.
	 * @param string $page Page slug to return to.
	 * @return void
	 */
	protected function render_choose_sport_page( $title, $intro, $page ) {
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php echo esc_html( $title ); ?></h1>
			<div class="leagueflow-empty-state">
				<p><?php echo esc_html( $intro ); ?></p>
				<?php if ( $this->sports_manager->get_enabled_sports() ) : ?>
					<ul class="leagueflow-link-list">
						<?php foreach ( $this->sports_manager->get_enabled_sports() as $slug => $definition ) : ?>
							<li><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => $page, 'lf_sport' => $slug ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $definition['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ); ?>"><?php esc_html_e( 'Enable a sport first', 'leagueflow' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the fixtures generator admin page.
	 *
	 * @return void
	 */
	public function render_fixtures_page() {
		if ( ! current_user_can( 'leagueflow_manage_fixtures' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		$sport_slug      = $this->get_context_sport_slug();
		$competition_id  = resolve_term_id( $_GET['competition'] ?? '', 'lf_competition' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$season_choice   = $this->get_admin_season_choice( $sport_slug );
		$season_id       = $season_choice['id'];
		$league_level_id = resolve_term_id( $_GET['league_level'] ?? '', 'lf_league_level' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sport_id        = resolve_term_id( $sport_slug, 'lf_sport' );
		$page            = 'leagueflow-fixtures';

		if ( '' === $sport_slug ) {
			$this->render_choose_sport_page( __( 'Generate fixtures', 'leagueflow' ), __( 'Fixtures are generated for one sport at a time, so teams from different sports are never paired. Choose the sport first.', 'leagueflow' ), $page );
			return;
		}

		$per_level = ! empty( $_GET['per_level'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context   = array(
			'competition_id'  => $competition_id,
			'season_id'       => $season_id,
			'sport_id'        => $sport_id,
			'league_level_id' => $league_level_id,
			'per_level'       => $per_level,
		);
		$previews  = array(
			1 => $this->fixture_generator->preview( array_merge( $context, array( 'legs' => 1 ) ) ),
			2 => $this->fixture_generator->preview( array_merge( $context, array( 'legs' => 2 ) ) ),
		);
		$preview   = $previews[1];
		$has_rules = (bool) $this->field_availability_manager->get_availabilities( array( 'sport_slug' => $sport_slug ) );
		$level_url = static function ( $args ) use ( $page, $sport_slug, $competition_id, $season_id ) {
			return add_query_arg( array_filter( array_merge( array( 'page' => $page, 'lf_sport' => $sport_slug, 'competition' => $competition_id, 'season' => $season_id ), $args ) ), admin_url( 'admin.php' ) );
		};
		?>
		<div class="wrap leagueflow-admin-page leagueflow-fixtures-page">
			<h1><?php echo esc_html( sprintf( /* translators: %s: sport */ __( 'Generate %s fixtures', 'leagueflow' ), $this->sports_manager->get_definition( $sport_slug )['label'] ) ); ?></h1>
			<p class="leagueflow-page-intro"><?php esc_html_e( 'Creates a round-robin between the teams of one level (division). Teams only play teams in their own level. Pairings that already exist in the same sport, level, competition and season are skipped, and existing matches are never changed. New fixtures are drafts by default so you can review them before publishing.', 'leagueflow' ); ?></p>
			<?php $this->render_context_filters( $page, $competition_id, $season_id, $sport_slug, $league_level_id, $season_choice['mode'] ); ?>

			<?php if ( is_wp_error( $preview ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $preview->get_error_message() ); ?></p></div>
			<?php else : ?>
				<h2><?php esc_html_e( 'Scope', 'leagueflow' ); ?></h2>
				<?php if ( ! $preview['groups'] ) : ?>
					<div class="leagueflow-empty-state"><p><?php esc_html_e( 'No team with a level matches these filters. Add teams to this sport and give each a level, or clear the competition and season filters.', 'leagueflow' ); ?></p></div>
				<?php endif; ?>
				<?php foreach ( $preview['groups'] as $index => $group ) : ?>
					<section class="leagueflow-fixture-group" aria-labelledby="leagueflow-fixture-group-<?php echo esc_attr( (string) $group['level_id'] ); ?>">
						<h3 id="leagueflow-fixture-group-<?php echo esc_attr( (string) $group['level_id'] ); ?>">
							<?php echo esc_html( sprintf( /* translators: 1: level, 2: team count */ _n( '%1$s: %2$d team', '%1$s: %2$d teams', count( $group['team_ids'] ), 'leagueflow' ), $group['level_name'], count( $group['team_ids'] ) ) ); ?>
							<?php if ( ! $league_level_id && count( $preview['groups'] ) > 1 ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $level_url( array( 'league_level' => $group['level_id'] ) ) ); ?>"><?php esc_html_e( 'Only this level', 'leagueflow' ); ?></a>
							<?php endif; ?>
						</h3>
						<ul class="leagueflow-fixture-teams">
							<?php foreach ( $group['team_ids'] as $team_id ) : ?>
								<li><?php echo esc_html( get_the_title( $team_id ) . ( 'publish' !== get_post_status( $team_id ) ? ' (' . get_post_status( $team_id ) . ')' : '' ) ); ?></li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							<?php
							$double = is_wp_error( $previews[2] ) ? array( 'new' => 0, 'duplicates' => 0 ) : ( $previews[2]['groups'][ $index ] ?? array( 'new' => 0, 'duplicates' => 0 ) );
							echo esc_html(
								count( $group['team_ids'] ) < 2
									? __( 'Fewer than two teams: no fixtures for this level.', 'leagueflow' )
									: sprintf(
										/* translators: 1: new single, 2: existing single, 3: new double, 4: existing double */
										__( 'Single round-robin: %1$d new, %2$d already exist. Home and away: %3$d new, %4$d already exist.', 'leagueflow' ),
										$group['new'],
										$group['duplicates'],
										$double['new'],
										$double['duplicates']
									)
							);
							?>
						</p>
					</section>
				<?php endforeach; ?>
				<?php if ( $preview['no_level_team_ids'] ) : ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html( sprintf( /* translators: %d: team count */ _n( '%d team has no level and is left out:', '%d teams have no level and are left out:', count( $preview['no_level_team_ids'] ), 'leagueflow' ), count( $preview['no_level_team_ids'] ) ) ); ?>
						<?php echo esc_html( implode( ', ', array_map( 'get_the_title', $preview['no_level_team_ids'] ) ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $preview['groups'] && ! $league_level_id && count( $preview['groups'] ) > 1 ) : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'Teams from more than one level are in scope. Choose one level above, or generate each level separately (teams still only play their own level).', 'leagueflow' ); ?></p>
						<p>
							<?php if ( $per_level ) : ?>
								<strong><?php esc_html_e( 'Each level will be generated separately.', 'leagueflow' ); ?></strong>
								<a href="<?php echo esc_url( $level_url( array() ) ); ?>"><?php esc_html_e( 'Undo', 'leagueflow' ); ?></a>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( $level_url( array( 'per_level' => 1 ) ) ); ?>"><?php esc_html_e( 'Generate each level separately', 'leagueflow' ); ?></a>
							<?php endif; ?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( $preview['groups'] && ! $preview['requires_level_choice'] && ( $preview['new'] || ( ! is_wp_error( $previews[2] ) && $previews[2]['new'] ) ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-fixture-form">
						<?php wp_nonce_field( 'leagueflow_generate_fixtures', 'leagueflow_generate_fixtures_nonce' ); ?>
						<input type="hidden" name="action" value="leagueflow_generate_fixtures" />
						<input type="hidden" name="leagueflow_fixtures_page" value="<?php echo esc_attr( $page ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_competition" value="<?php echo esc_attr( (string) $competition_id ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_season" value="<?php echo esc_attr( (string) $season_id ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_league_level" value="<?php echo esc_attr( (string) $league_level_id ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_per_level" value="<?php echo esc_attr( $per_level ? '1' : '' ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_token_1" value="<?php echo esc_attr( $preview['token'] ); ?>" />
						<input type="hidden" name="leagueflow_fixtures_token_2" value="<?php echo esc_attr( is_wp_error( $previews[2] ) ? '' : $previews[2]['token'] ); ?>" />

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Format', 'leagueflow' ); ?></th>
								<td>
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Format', 'leagueflow' ); ?></legend>
										<label><input type="radio" name="leagueflow_fixtures_legs" value="1" checked /> <?php echo esc_html( sprintf( /* translators: %d: matches */ __( 'Single round-robin, each pair plays once (%d new matches)', 'leagueflow' ), $preview['new'] ) ); ?></label><br />
										<label><input type="radio" name="leagueflow_fixtures_legs" value="2" /> <?php echo esc_html( sprintf( /* translators: %d: matches */ __( 'Home and away, each pair plays twice (%d new matches)', 'leagueflow' ), is_wp_error( $previews[2] ) ? 0 : $previews[2]['new'] ) ); ?></label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Save as', 'leagueflow' ); ?></th>
								<td>
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Save as', 'leagueflow' ); ?></legend>
										<label><input type="radio" name="leagueflow_fixtures_status" value="draft" checked /> <?php esc_html_e( 'Drafts (hidden from public pages until published)', 'leagueflow' ); ?></label><br />
										<label><input type="radio" name="leagueflow_fixtures_status" value="publish" /> <?php esc_html_e( 'Published immediately', 'leagueflow' ); ?></label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Scheduling', 'leagueflow' ); ?></th>
								<td>
									<?php if ( $has_rules && current_user_can( 'leagueflow_manage_schedule' ) ) : ?>
										<label><input type="checkbox" name="leagueflow_fixtures_schedule" value="1" /> <?php esc_html_e( 'Give the new fixtures dates and venues from venue availability', 'leagueflow' ); ?></label>
										<p class="description"><?php esc_html_e( 'Fixtures that do not fit an open slot stay Unscheduled; the result says how many.', 'leagueflow' ); ?></p>
									<?php else : ?>
										<p class="description">
											<?php esc_html_e( 'Automatic dates need venue availability for this sport, and there is none yet. The fixtures will be created Unscheduled; date them by hand or add availability first.', 'leagueflow' ); ?>
											<?php if ( current_user_can( 'leagueflow_manage_field_availability' ) ) : ?>
												<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'leagueflow-fields', 'lf_sport' => $sport_slug ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Add venue availability', 'leagueflow' ); ?></a>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Generate fixtures', 'leagueflow' ) ); ?>
					</form>
				<?php elseif ( $preview['groups'] && ! $preview['requires_level_choice'] ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'Every pairing in this scope already exists. Nothing to generate.', 'leagueflow' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the fixture generation request.
	 *
	 * @return void
	 */
	public function handle_generate_fixtures() {
		if ( ! current_user_can( 'leagueflow_manage_fixtures' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'leagueflow_generate_fixtures', 'leagueflow_generate_fixtures_nonce' );

		$page            = sanitize_key( wp_unslash( $_POST['leagueflow_fixtures_page'] ?? 'leagueflow-fixtures' ) );
		$page            = $page ? $page : 'leagueflow-fixtures';
		$sport_slug      = sanitize_key( wp_unslash( $_POST['leagueflow_fixtures_sport'] ?? '' ) );
		$sport_slug      = in_array( $sport_slug, $this->sports_manager->get_enabled_sport_slugs(), true ) ? $sport_slug : '';
		$competition_id  = absint( wp_unslash( $_POST['leagueflow_fixtures_competition'] ?? 0 ) );
		$season_id       = absint( wp_unslash( $_POST['leagueflow_fixtures_season'] ?? 0 ) );
		$league_level_id = absint( wp_unslash( $_POST['leagueflow_fixtures_league_level'] ?? 0 ) );
		$per_level       = ! empty( $_POST['leagueflow_fixtures_per_level'] );
		$legs            = 2 === absint( wp_unslash( $_POST['leagueflow_fixtures_legs'] ?? 1 ) ) ? 2 : 1;
		$status          = 'publish' === sanitize_key( wp_unslash( $_POST['leagueflow_fixtures_status'] ?? 'draft' ) ) ? 'publish' : 'draft';
		$token           = sanitize_key( wp_unslash( $_POST[ 'leagueflow_fixtures_token_' . $legs ] ?? '' ) );
		$redirect        = add_query_arg(
			array_filter(
				array(
					'page'         => $page,
					'lf_sport'     => $sport_slug,
					'competition'  => $competition_id,
					'season'       => $season_id,
					'league_level' => $league_level_id,
					'per_level'    => $per_level ? 1 : 0,
				)
			),
			admin_url( 'admin.php' )
		);

		// The term filters must belong to the chosen sport; a forged ID is refused before any write.
		$wrong_sport = ( $competition_id && get_term_sport_slug( $competition_id ) !== $sport_slug ) || ( $season_id && get_term_sport_slug( $season_id ) !== $sport_slug ) || ( $league_level_id && ! get_term( $league_level_id, 'lf_league_level' ) instanceof \WP_Term );

		if ( '' === $sport_slug || '' === $token || $wrong_sport ) {
			$result = new \WP_Error( 'leagueflow_fixtures_scope', __( 'The fixture scope was not valid (sport, competition, season or level). Nothing was created; review the preview again.', 'leagueflow' ) );
		} else {
			$result = $this->fixture_generator->generate(
				array(
					'competition_id'  => $competition_id,
					'season_id'       => $season_id,
					'sport_id'        => resolve_term_id( $sport_slug, 'lf_sport' ),
					'league_level_id' => $league_level_id,
					'per_level'       => $per_level,
					'legs'            => $legs,
					'post_status'     => $status,
					'auto_schedule'   => ! empty( $_POST['leagueflow_fixtures_schedule'] ),
					'token'           => $token,
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			$this->queue_notice( $result->get_error_message(), 'error' );
		} else {
			$review  = add_query_arg( array( 'post_type' => 'lf_match', 'lf_sport' => $sport_slug, 'lf_batch' => $result['batch'] ), admin_url( 'edit.php' ) );
			$message = sprintf(
				/* translators: 1: matches created, 2: number of teams, 3: existing pairings skipped. */
				_n( 'Created %1$d fixture for %2$d teams (%3$d existing pairings skipped).', 'Created %1$d fixtures for %2$d teams (%3$d existing pairings skipped).', (int) $result['created'], 'leagueflow' ),
				absint( $result['created'] ),
				absint( $result['teams'] ),
				absint( $result['duplicates'] )
			);

			if ( ! empty( $_POST['leagueflow_fixtures_schedule'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: 1: scheduled, 2: still unscheduled. */
					__( '%1$d received a date and venue; %2$d are still Unscheduled.', 'leagueflow' ),
					absint( $result['scheduled'] ),
					absint( $result['unscheduled'] )
				);
			} else {
				$message .= ' ' . __( 'They are Unscheduled until they get a date.', 'leagueflow' );
			}

			if ( ! empty( $result['failed'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: pairings that could not be saved. */
					_n( '%d pairing could not be saved and was not created; preview again to create it.', '%d pairings could not be saved and were not created; preview again to create them.', (int) $result['failed'], 'leagueflow' ),
					absint( $result['failed'] )
				);
			}

			if ( ! empty( $result['incomplete_ids'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: 1: number of matches, 2: match IDs. */
					_n( '%1$d match was saved without part of its scope (sport, level, competition, season or team details) and needs review: #%2$s.', '%1$d matches were saved without part of their scope (sport, level, competition, season or team details) and need review: #%2$s.', count( $result['incomplete_ids'] ), 'leagueflow' ),
					count( $result['incomplete_ids'] ),
					implode( ', #', array_map( 'absint', $result['incomplete_ids'] ) )
				);
			}

			if ( ! empty( $result['partial_error'] ) ) {
				$message .= ' ' . __( 'Generation stopped early:', 'leagueflow' ) . ' ' . $result['partial_error'] . ' ' . __( 'The fixtures listed above were kept; preview again for the rest.', 'leagueflow' );
			}

			$this->queue_notice( $message, empty( $result['partial'] ) ? 'success' : 'warning', $review, __( 'Review the new fixtures', 'leagueflow' ) );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		?>
		<div class="wrap leagueflow-admin-page leagueflow-settings-page">
			<h1><?php esc_html_e( 'LeagueFlow Settings', 'leagueflow' ); ?></h1>
			<p><?php esc_html_e( 'The defaults below apply across the site. Use Scoring by sport and competition to change one sport or competition while keeping the others on their own rules.', 'leagueflow' ); ?></p>
			<form class="leagueflow-settings-form" method="post" action="options.php">
				<?php
				settings_fields( 'leagueflow_settings' );
				?>
				<div class="leagueflow-settings-grid">
					<?php
					do_settings_sections( 'leagueflow-settings' );
					?>
				</div>
				<?php submit_button(); ?>
			</form>

			<?php $this->render_rule_overrides_card(); ?>

			<div class="leagueflow-settings-secondary">
				<?php $this->render_demo_data_card(); ?>

				<div class="leagueflow-admin-card leagueflow-admin-card--compact">
					<h2><?php esc_html_e( 'Sports Modules', 'leagueflow' ); ?></h2>
					<p><?php esc_html_e( 'Enable, disable, and review built-in sports from the Sports setup page.', 'leagueflow' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ); ?>"><?php esc_html_e( 'Manage Sports', 'leagueflow' ); ?></a></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Scoring by sport and competition: effective values with their source,
	 * and an editor for one scope at a time. Nothing changes until a person
	 * saves an override; clearing it makes the scope inherit again.
	 *
	 * @return void
	 */
	protected function render_rule_overrides_card() {
		$labels    = ( new Settings() )->get_tie_breaker_labels();
		$overrides = get_rule_overrides();
		$scope     = sanitize_text_field( wp_unslash( $_GET['rules_scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context_sport = $this->get_context_sport_slug();
		$sources   = array(
			'global'      => __( 'default', 'leagueflow' ),
			'sport'       => __( 'sport', 'leagueflow' ),
			'competition' => __( 'competition', 'leagueflow' ),
		);
		$describe  = static function ( $rules ) use ( $labels, $sources ) {
			$values = $rules['values'];
			$source = $rules['source'];

			$with_source = static function ( $key ) use ( $values, $source, $sources ) {
				return $values[ $key ] . ' (' . $sources[ $source[ $key ] ] . ')';
			};
			return sprintf(
				/* translators: 1: win and source, 2: draw and source, 3: loss and source, 4: walkover and source, 5: tie-breakers and source */
				__( 'Win %1$s · Draw %2$s · Loss %3$s · Forfeit score %4$s · Ties: %5$s', 'leagueflow' ),
				$with_source( 'points_win' ), $with_source( 'points_draw' ), $with_source( 'points_loss' ), $with_source( 'forfeit_score_winner' ),
				implode( ', ', array_map( static function ( $key ) use ( $labels ) { return $labels[ $key ] ?? $key; }, (array) $values['tie_breakers'] ) ) . ' (' . $sources[ $source['tie_breakers'] ] . ')'
			);
		};
		?>
		<section class="leagueflow-admin-card leagueflow-rule-overrides" id="leagueflow-rule-overrides" aria-labelledby="leagueflow-rule-overrides-title">
			<h2 id="leagueflow-rule-overrides-title"><?php esc_html_e( 'Scoring by sport and competition', 'leagueflow' ); ?></h2>
			<p><?php esc_html_e( 'Rules follow this order: site defaults → sport → competition. Override only the values that differ; blank values continue to inherit future changes from their parent. Saved changes apply to existing results in that scope as well as new results.', 'leagueflow' ); ?></p>
			<?php if ( ! empty( $_GET['leagueflow_rules_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Scoring saved.', 'leagueflow' ); ?></p></div>
			<?php endif; ?>
			<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Effective scoring', 'leagueflow' ); ?>">
				<table class="widefat striped">
					<caption class="screen-reader-text"><?php esc_html_e( 'Scoring used by each sport and customized competition', 'leagueflow' ); ?></caption>
					<thead><tr><th scope="col"><?php esc_html_e( 'Scope', 'leagueflow' ); ?></th><th scope="col"><?php esc_html_e( 'Scoring used (where each value comes from)', 'leagueflow' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'leagueflow' ); ?></span></th></tr></thead>
					<tbody>
						<?php foreach ( $this->sports_manager->get_enabled_sports() as $slug => $sport ) : ?>
							<?php if ( $context_sport && $context_sport !== $slug ) { continue; } ?>
							<tr>
								<th scope="row"><?php echo esc_html( $sport['label'] ); ?></th>
								<td><?php echo esc_html( $describe( get_effective_rules( $slug ) ) ); ?></td>
								<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'leagueflow-settings', 'lf_sport' => $slug, 'rules_scope' => 'sport:' . $slug ), admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides' ); ?>"><?php echo esc_html( isset( $overrides['sport'][ $slug ] ) ? __( 'Edit', 'leagueflow' ) : __( 'Customize', 'leagueflow' ) ); ?><span class="screen-reader-text"> <?php echo esc_html( $sport['label'] ); ?></span></a></td>
							</tr>
							<?php foreach ( get_term_ids_by_sport( 'lf_competition', $slug ) as $competition_id ) : ?>
								<?php $competition = get_term( $competition_id, 'lf_competition' ); ?>
								<?php if ( $competition instanceof \WP_Term ) : ?>
									<tr>
										<th scope="row">&nbsp;&nbsp;<?php echo esc_html( $sport['label'] . ' › ' . $competition->name ); ?></th>
										<td><?php echo esc_html( isset( $overrides['competition'][ $competition_id ] ) ? $describe( get_effective_rules( $slug, $competition_id ) ) : __( 'Same as the sport', 'leagueflow' ) ); ?></td>
										<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'leagueflow-settings', 'lf_sport' => $slug, 'rules_scope' => 'competition:' . $competition_id ), admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides' ); ?>"><?php echo esc_html( isset( $overrides['competition'][ $competition_id ] ) ? __( 'Edit', 'leagueflow' ) : __( 'Customize', 'leagueflow' ) ); ?><span class="screen-reader-text"> <?php echo esc_html( $competition->name ); ?></span></a></td>
									</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
			list( $type, $key ) = array_pad( explode( ':', $scope, 2 ), 2, '' );

			if ( 'sport' === $type && in_array( $key, $this->sports_manager->get_enabled_sport_slugs(), true ) ) {
				$this->render_rule_override_form( 'sport', $key, $this->sports_manager->get_definition( $key )['label'], get_effective_rules( $key ), $overrides['sport'][ $key ] ?? array(), $labels );
			} elseif ( 'competition' === $type && get_term( absint( $key ), 'lf_competition' ) instanceof \WP_Term ) {
				$this->render_rule_override_form( 'competition', absint( $key ), get_term( absint( $key ), 'lf_competition' )->name, get_effective_rules( get_term_sport_slug( absint( $key ) ), absint( $key ) ), $overrides['competition'][ absint( $key ) ] ?? array(), $labels );
			}
			?>
		</section>
		<?php
	}

	/**
	 * Edit form for one scoring override.
	 *
	 * @param string               $scope sport or competition.
	 * @param string|int           $key Sport slug or competition ID.
	 * @param string               $name Scope name.
	 * @param array<string, mixed> $effective Effective rules.
	 * @param array<string, mixed> $stored Stored override.
	 * @param array<string, string> $labels Tie-breaker labels.
	 * @return void
	 */
	protected function render_rule_override_form( $scope, $key, $name, $effective, $stored, $labels ) {
		$values = $effective['values'];
		$sport_slug = 'sport' === $scope ? $key : get_term_sport_slug( $key );
		$inherited = get_effective_rules( 'sport' === $scope ? '' : $sport_slug )['values'];
		$score_label = $this->sports_manager->get_definition( $sport_slug )['score_label'];
		$id     = 'leagueflow-rules-' . sanitize_html_class( $scope . '-' . $key );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-rule-override-form" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
			<h3 id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( sprintf( /* translators: %s: sport or competition */ __( 'Scoring for %s', 'leagueflow' ), $name ) ); ?></h3>
			<input type="hidden" name="action" value="leagueflow_save_rule_override" />
			<input type="hidden" name="lf_rules_scope" value="<?php echo esc_attr( $scope ); ?>" />
			<input type="hidden" name="lf_rules_key" value="<?php echo esc_attr( (string) $key ); ?>" />
			<input type="hidden" name="lf_rules_custom" value="1" />
			<?php wp_nonce_field( 'leagueflow_save_rule_override_' . $scope . '_' . $key, 'leagueflow_rules_nonce' ); ?>
			<p><?php esc_html_e( 'Enter only the values this scope should override. Leave a value blank to inherit; enter 0 when zero is the intended rule.', 'leagueflow' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( Settings::scoring_number_labels() as $field => $label ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id . '-' . $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="number" min="0" step="1" class="small-text" id="<?php echo esc_attr( $id . '-' . $field ); ?>" name="lf_rules[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( isset( $stored[ $field ] ) ? (string) $stored[ $field ] : '' ); ?>" placeholder="<?php echo esc_attr( (string) $inherited[ $field ] ); ?>" aria-describedby="<?php echo esc_attr( $id . '-' . $field . '-help' ); ?>" />
						<p class="description" id="<?php echo esc_attr( $id . '-' . $field . '-help' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: inherited scoring value */ __( 'Blank inherits %d.', 'leagueflow' ), $inherited[ $field ] ) ); ?> <?php if ( 'forfeit_score_winner' === $field ) { echo esc_html( sprintf( /* translators: %s: scoring unit */ __( 'Winning score in %s; the forfeiting team receives 0.', 'leagueflow' ), $score_label ) ); } ?></p></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tie-breaker priority', 'leagueflow' ); ?></th>
					<td>
						<?php for ( $index = 0; $index < 4; $index++ ) : ?>
							<label for="<?php echo esc_attr( $id . '-tie-' . $index ); ?>"><?php echo esc_html( sprintf( /* translators: %d: priority */ __( 'Priority %d', 'leagueflow' ), $index + 1 ) ); ?></label>
							<select id="<?php echo esc_attr( $id . '-tie-' . $index ); ?>" name="lf_rules[tie_breakers][<?php echo esc_attr( (string) $index ); ?>]">
								<option value=""><?php echo esc_html( 0 === $index ? __( 'Use inherited order', 'leagueflow' ) : __( 'No additional rule', 'leagueflow' ) ); ?></option>
								<?php foreach ( $labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $stored['tie_breakers'][ $index ] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endfor; ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: inherited tie-breaker order */ __( 'Leave every priority blank to inherit: %s. Choose each rule at most once.', 'leagueflow' ), implode( ', ', array_map( static function ( $field ) use ( $labels ) { return $labels[ $field ] ?? $field; }, $inherited['tie_breakers'] ) ) ) ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save scoring', 'leagueflow' ), 'secondary', '', false ); ?>
			<?php if ( $stored ) : ?><button type="submit" class="button" name="lf_rules_reset" value="1" formnovalidate><?php esc_html_e( 'Reset to inherited scoring', 'leagueflow' ); ?></button><?php endif; ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'leagueflow-settings', 'lf_sport' => $sport_slug ), admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides' ); ?>"><?php esc_html_e( 'Cancel', 'leagueflow' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Save or clear one scoring override.
	 *
	 * @return void
	 */
	public function handle_save_rule_override() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		$scope = sanitize_key( wp_unslash( $_POST['lf_rules_scope'] ?? '' ) );
		$key   = 'competition' === $scope ? absint( wp_unslash( $_POST['lf_rules_key'] ?? 0 ) ) : sanitize_key( wp_unslash( $_POST['lf_rules_key'] ?? '' ) );

		check_admin_referer( 'leagueflow_save_rule_override_' . $scope . '_' . $key, 'leagueflow_rules_nonce' );

		$valid = ( 'sport' === $scope && in_array( $key, $this->sports_manager->get_enabled_sport_slugs(), true ) )
			|| ( 'competition' === $scope && get_term( $key, 'lf_competition' ) instanceof \WP_Term );

		if ( ! $valid ) {
			wp_die( esc_html__( 'That sport or competition does not exist.', 'leagueflow' ), '', array( 'response' => 400 ) );
		}

		$values = ! empty( $_POST['lf_rules_custom'] ) && empty( $_POST['lf_rules_reset'] ) && isset( $_POST['lf_rules'] )
			? wp_unslash( $_POST['lf_rules'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_rule_override() keeps only known keys and integers.
			: array();
		$sport_slug = 'sport' === $scope ? $key : get_term_sport_slug( $key );
		$redirect_args = array( 'page' => 'leagueflow-settings', 'lf_sport' => $sport_slug, 'rules_scope' => $scope . ':' . $key );
		$errors = Settings::validate_rule_override( $values );
		if ( $errors ) {
			$this->queue_notice( implode( ' ', $errors ) . ' ' . __( 'Scoring was not changed.', 'leagueflow' ), 'error' );
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides' );
			exit;
		}

		set_rule_override( $scope, $key, $values );

		wp_safe_redirect( add_query_arg( $redirect_args + array( 'leagueflow_rules_saved' => 1 ), admin_url( 'admin.php' ) ) . '#leagueflow-rule-overrides' );
		exit;
	}

	/**
	 * Development-only demo data card with a read-only preview and explicit confirmation.
	 *
	 * @return void
	 */
	protected function render_demo_data_card() {
		$plan = $this->seeder->plan_demo_data();
		?>
		<div class="leagueflow-admin-card leagueflow-admin-card--compact leagueflow-demo-card">
			<h2><?php esc_html_e( 'Demo data (development sites only)', 'leagueflow' ); ?></h2>
			<?php if ( ! $plan['allowed'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: WordPress environment type */
						esc_html__( 'Demo data is turned off on this site (environment: %s). It is only available when WP_ENVIRONMENT_TYPE is local or development.', 'leagueflow' ),
						esc_html( (string) $plan['environment'] )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Creates sample competitions, teams, players, matches and four shortcode pages on an empty development site. It only creates new items: it never edits, adopts or renames existing pages, terms or league records, including earlier demo items.', 'leagueflow' ); ?></p>
				<p>
					<?php
					printf(
						/* translators: %d: items to create */
						esc_html__( 'Preview: %d items would be created.', 'leagueflow' ),
						count( $plan['creates'] )
					);
					?>
				</p>
				<?php if ( $plan['conflicts'] ) : ?>
					<div class="notice notice-warning inline">
						<p><strong><?php esc_html_e( 'Blocked: this site already has content the demo would collide with.', 'leagueflow' ); ?></strong></p>
						<ul class="ul-disc">
							<?php foreach ( array_slice( $plan['conflicts'], 0, 12 ) as $conflict ) : ?>
								<li><code><?php echo esc_html( $conflict ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="leagueflow_seed_demo" />
						<input type="hidden" name="leagueflow_demo_token" value="<?php echo esc_attr( $plan['token'] ); ?>" />
						<?php wp_nonce_field( 'leagueflow_seed_demo', 'leagueflow_seed_demo_nonce' ); ?>
						<p>
							<label>
								<input type="checkbox" name="leagueflow_demo_confirm" value="1" required />
								<?php esc_html_e( 'I understand this adds sample records and pages to this development site.', 'leagueflow' ); ?>
							</label>
						</p>
						<p><button type="submit" id="leagueflow-create-demo-data" class="button button-secondary"><?php esc_html_e( 'Create demo data', 'leagueflow' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render team details metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_team_metabox( $post ) {
		$sport_slug      = $this->get_editor_sport_slug( $post->ID );
		$league_level_id = $this->get_editor_league_level_id( $post->ID );
		$managers        = get_team_manager_user_ids( $post->ID );
		wp_nonce_field( 'leagueflow_save_team', 'leagueflow_team_nonce' );
		?>
		<input type="hidden" name="leagueflow_requested_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
		<table class="form-table leagueflow-form-table" role="presentation">
			<?php $this->render_team_sport_row( $post ); ?>
			<tr>
				<th scope="row"><label for="lf_short_name"><?php esc_html_e( 'Short Name', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_short_name" name="lf_short_name" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_short_name', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_city"><?php esc_html_e( 'City', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_city" name="lf_city" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_city', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_coach"><?php esc_html_e( 'Coach', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_coach" name="lf_coach" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_coach', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_founded_year"><?php esc_html_e( 'Founded Year', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="1800" max="2100" id="lf_founded_year" name="lf_founded_year" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_founded_year', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_manager_user_ids"><?php esc_html_e( 'Team Managers', 'leagueflow' ); ?></label></th>
				<td>
					<?php if ( can_manage_player_accounts() ) : ?>
					<input type="hidden" name="lf_manager_user_ids_present" value="1" />
					<?php $this->render_user_select( 'lf_manager_user_ids[]', 'lf_manager_user_ids', $managers, true, __( 'Select managers', 'leagueflow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Managers can edit this team profile and roster from the front-end portal.', 'leagueflow' ); ?></p>
					<?php else : ?>
					<p><?php esc_html_e( 'Ask an administrator to assign or remove team managers.', 'leagueflow' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_league_level_id"><?php esc_html_e( 'League Level', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_league_level_id', $this->get_league_level_options(), $league_level_id ? $league_level_id : $this->find_default_league_level_id(), null ); ?>
					<p class="description"><?php esc_html_e( 'Teams, players, matches, standings, and schedules can be separated by league level inside each sport.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_points_adjustment"><?php esc_html_e( 'Points Adjustment', 'leagueflow' ); ?></label></th>
				<td>
					<input type="number" step="1" id="lf_points_adjustment" name="lf_points_adjustment" class="small-text" value="<?php echo esc_attr( (string) (int) get_post_meta( $post->ID, 'lf_points_adjustment', true ) ); ?>" />
					<input type="text" id="lf_adjustment_note" name="lf_adjustment_note" class="regular-text" aria-label="<?php esc_attr_e( 'Reason for the points adjustment', 'leagueflow' ); ?>" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_adjustment_note', true ) ); ?>" placeholder="<?php esc_attr_e( 'Reason (e.g. late-registration deduction)', 'leagueflow' ); ?>" />
					<p class="description"><?php esc_html_e( 'Signed points offset applied on top of the derived standings. Use a negative number for a deduction. Standings still compute from matches; this only adjusts the total.', 'leagueflow' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Use the featured image as the team logo. Add the team to a competition or season in the Competitions and Seasons boxes in the sidebar; pick names that already exist for this team\'s sport. To create one with its sport set, use LeagueFlow > Competitions or Seasons.', 'leagueflow' ); ?></p>
		<?php
	}

	/**
	 * Render the Team Details sport row.
	 *
	 * A new team (or an older team without a sport) chooses one enabled sport.
	 * Once set, the sport is shown read-only: player memberships, requests and
	 * matches are checked against it, so changing it here could break them.
	 *
	 * @param \WP_Post $post Team post.
	 * @return void
	 */
	protected function render_team_sport_row( $post ) {
		$terms = wp_get_post_terms( $post->ID, 'lf_sport' );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		?>
		<tr class="leagueflow-team-sport-row">
			<?php if ( ! empty( $terms ) ) : ?>
				<th scope="row"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th>
				<td>
					<strong id="lf_team_sport_value">
						<?php
						$labels = array();
						foreach ( $terms as $term ) {
							$labels[] = $this->is_assignable_sport_slug( $term->slug )
								? $term->name
								/* translators: %s: sport name */
								: sprintf( __( '%s (not enabled)', 'leagueflow' ), $term->name );
						}
						echo esc_html( implode( ', ', $labels ) );
						?>
					</strong>
					<p class="description"><?php esc_html_e( 'A team\'s sport cannot be changed here: its players, requests and matches belong to that sport. To run a team in another sport, add a new team.', 'leagueflow' ); ?></p>
				</td>
			<?php else : ?>
				<?php $options = $this->get_sport_choice_options(); ?>
				<th scope="row"><label for="lf_sport_choice"><?php esc_html_e( 'Sport', 'leagueflow' ); ?> <span aria-hidden="true">*</span></label></th>
				<td>
					<?php if ( empty( $options ) ) : ?>
						<?php $this->render_no_enabled_sports_notice(); ?>
					<?php else : ?>
						<select id="lf_sport_choice" name="lf_sport_choice" required aria-describedby="lf_sport_choice_help">
							<option value=""><?php esc_html_e( 'Choose a sport', 'leagueflow' ); ?></option>
							<?php foreach ( $options as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $this->get_context_sport_slug(), $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="lf_sport_choice_help"><?php esc_html_e( 'Required. The sport is fixed after the team is saved. A team without a sport is kept as a draft.', 'leagueflow' ); ?></p>
						<?php if ( $this->sports_manager->is_setup_required() && current_user_can( 'manage_options' ) ) : ?>
							<p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ); ?>"><?php esc_html_e( 'Sport setup is not finished: confirm which sports this site runs in LeagueFlow > Sports.', 'leagueflow' ); ?></a></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * Apply the Team Details sport choice.
	 *
	 * @param int $post_id Team ID.
	 * @return void
	 */
	protected function save_team_sport_choice( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'lf_sport', array( 'fields' => 'ids' ) );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			// Read-only once set; a submitted value (forged or stale form) never changes it.
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by can_save().
		if ( ! array_key_exists( 'lf_sport_choice', $_POST ) ) {
			// A form from before the selector existed: only an explicit enabled context sport.
			$this->assign_default_sport_if_missing( $post_id );
			return;
		}

		$choice = $this->get_submitted_sport_choice();

		if ( '' === $choice ) {
			$this->queue_notice( __( 'Choose a sport for this team. It was saved without a sport and stays a draft until one is chosen.', 'leagueflow' ), 'error' );
			return;
		}

		if ( ! $this->is_assignable_sport_slug( $choice ) ) {
			$this->queue_notice( __( 'That sport is not enabled, so the team was saved without a sport. Choose an enabled sport.', 'leagueflow' ), 'error' );
			return;
		}

		$this->assign_default_sport_if_missing( $post_id, $choice );
	}

	/**
	 * Keep a team without a valid sport from being published from the editor.
	 *
	 * Only a first publication is held back; an existing published team
	 * without a sport (older data) keeps its status.
	 *
	 * @param array<string, mixed> $data Sanitized post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function hold_sportless_team_as_draft( $data, $postarr ) {
		if ( 'lf_team' !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future', 'private' ), true ) ) {
			return $data;
		}

		$nonce = isset( $_POST['leagueflow_team_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['leagueflow_team_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'leagueflow_save_team' ) ) {
			return $data;
		}

		$post_id  = absint( $postarr['ID'] ?? 0 );
		$previous = $post_id ? get_post_status( $post_id ) : '';

		if ( in_array( $previous, array( 'publish', 'future', 'private' ), true ) ) {
			return $data;
		}

		$terms = $post_id ? wp_get_post_terms( $post_id, 'lf_sport', array( 'fields' => 'ids' ) ) : array();

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return $data;
		}

		$choice = $this->get_submitted_sport_choice();

		if ( array_key_exists( 'lf_sport_choice', $_POST ) ? $this->is_assignable_sport_slug( $choice ) : '' !== $this->get_context_sport_slug() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $data;
		}

		$data['post_status'] = 'draft';

		return $data;
	}

	/**
	 * Render player details metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_player_metabox( $post ) {
		wp_nonce_field( 'leagueflow_save_player', 'leagueflow_player_nonce' );
		$sport_slug  = $this->get_editor_sport_slug( $post->ID );
		$teams       = $this->get_team_options();
		$team_ids    = get_player_team_ids( $post->ID );
		$primary_id  = absint( get_post_meta( $post->ID, 'lf_team_id', true ) );
		$user_id     = (int) get_post_meta( $post->ID, 'lf_user_id', true );
		$linked_user = $user_id ? get_user_by( 'id', $user_id ) : false;
		$used_sports = array();

		// "Add a new player to this team" from Team Roster preselects (never saves) that team.
		$preselect_team = 0;
		if ( 'auto-draft' === $post->post_status && isset( $_GET['lf_add_to_team'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate      = get_post( absint( wp_unslash( $_GET['lf_add_to_team'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preselect_team = ( $candidate instanceof \WP_Post && 'lf_team' === $candidate->post_type && 'trash' !== $candidate->post_status && current_user_can( 'edit_post', $candidate->ID ) ) ? (int) $candidate->ID : 0;
		}

		foreach ( $team_ids as $team_id ) {
			$team_sport = get_post_primary_term_slug( $team_id, 'lf_sport' );
			if ( $team_sport ) {
				$used_sports[ $team_sport ] = true;
			}
		}
		?>
		<input type="hidden" name="leagueflow_requested_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
		<table class="form-table leagueflow-form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Sports', 'leagueflow' ); ?></th>
				<td>
					<?php
					$player_sports = wp_get_post_terms( $post->ID, 'lf_sport', array( 'fields' => 'names' ) );
					$player_sports = is_wp_error( $player_sports ) ? array() : $player_sports;
					?>
					<strong><?php echo esc_html( $player_sports ? implode( ', ', $player_sports ) : __( 'None yet', 'leagueflow' ) ); ?></strong>
					<p class="description"><?php esc_html_e( 'A player\'s sports come from their team memberships below; adding the player to a team adds that team\'s sport.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_email"><?php esc_html_e( 'Player Email', 'leagueflow' ); ?></label></th>
				<td>
					<input type="email" id="lf_email" name="lf_email" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_email', true ) ); ?>" />
					<p class="description"><?php esc_html_e( 'A valid, unused email address is required when creating a new portal account.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<?php if ( can_manage_player_accounts() ) : ?>
			<tr>
				<th scope="row"><label for="lf_user_id"><?php esc_html_e( 'Linked User', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_user_select( 'lf_user_id', 'lf_user_id', array( $user_id ), false, __( 'No linked user', 'leagueflow' ) ); ?>
					<?php if ( $linked_user instanceof \WP_User ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: username */
								esc_html__( 'Current portal username: %s', 'leagueflow' ),
								esc_html( $linked_user->user_login )
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Existing account passwords are managed through WordPress, not player saves.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Player Login', 'leagueflow' ); ?></th>
				<td>
					<label><input type="checkbox" name="lf_generate_player_login" value="1" /> <?php esc_html_e( 'Create a new portal account on save', 'leagueflow' ); ?></label>
					<p class="description"><?php esc_html_e( 'WordPress emails the new player a single-use link to set their password. Existing passwords are never reset here.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Team Memberships', 'leagueflow' ); ?></th>
				<td>
					<?php if ( ! empty( $team_ids ) ) : ?>
						<div class="leagueflow-player-memberships-wrap">
							<table class="widefat striped leagueflow-player-memberships">
								<thead><tr>
									<th><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
									<th><?php esc_html_e( 'Primary', 'leagueflow' ); ?></th>
									<th><?php esc_html_e( 'No.', 'leagueflow' ); ?></th>
									<th><?php esc_html_e( 'Position', 'leagueflow' ); ?></th>
									<th><?php esc_html_e( 'Captain', 'leagueflow' ); ?></th>
									<th><?php esc_html_e( 'Remove', 'leagueflow' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $team_ids as $team_id ) : ?>
										<?php
										$detail = get_player_team_detail( $post->ID, $team_id );
										$sport  = $this->sports_manager->get_post_sport_label( $team_id );
										$level  = get_post_league_level_label( $team_id );
										?>
										<tr>
											<td data-label="<?php esc_attr_e( 'Team', 'leagueflow' ); ?>">
												<input type="hidden" name="lf_player_memberships[<?php echo esc_attr( (string) $team_id ); ?>][present]" value="1" />
												<strong><?php echo esc_html( get_the_title( $team_id ) ); ?></strong>
												<small><?php echo esc_html( implode( ' / ', array_filter( array( $sport, $level ) ) ) ); ?></small>
											</td>
											<td data-label="<?php esc_attr_e( 'Primary', 'leagueflow' ); ?>"><input type="radio" name="lf_primary_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" <?php checked( $primary_id, $team_id ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Use %s as primary team', 'leagueflow' ), get_the_title( $team_id ) ) ); ?>" /></td>
											<td data-label="<?php esc_attr_e( 'No.', 'leagueflow' ); ?>"><input type="number" min="0" name="lf_player_memberships[<?php echo esc_attr( (string) $team_id ); ?>][jersey_number]" class="small-text" value="<?php echo esc_attr( (string) $detail['jersey_number'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: team name */ __( 'Jersey number for %s', 'leagueflow' ), get_the_title( $team_id ) ) ); ?>" /></td>
											<td data-label="<?php esc_attr_e( 'Position', 'leagueflow' ); ?>"><input type="text" name="lf_player_memberships[<?php echo esc_attr( (string) $team_id ); ?>][position]" value="<?php echo esc_attr( $detail['position'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: team name */ __( 'Position for %s', 'leagueflow' ), get_the_title( $team_id ) ) ); ?>" /></td>
											<td data-label="<?php esc_attr_e( 'Captain', 'leagueflow' ); ?>"><input type="checkbox" name="lf_player_memberships[<?php echo esc_attr( (string) $team_id ); ?>][is_captain]" value="1" <?php checked( ! empty( $detail['is_captain'] ) ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Captain for %s', 'leagueflow' ), get_the_title( $team_id ) ) ); ?>" /></td>
											<td data-label="<?php esc_attr_e( 'Remove', 'leagueflow' ); ?>"><input type="checkbox" name="lf_player_memberships[<?php echo esc_attr( (string) $team_id ); ?>][remove]" value="1" aria-label="<?php echo esc_attr( sprintf( __( 'Remove from %s', 'leagueflow' ), get_the_title( $team_id ) ) ); ?>" /></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'This player is not assigned to a team.', 'leagueflow' ); ?></p>
					<?php endif; ?>

					<label class="leagueflow-player-memberships__add" for="lf_new_team_id">
						<span><?php esc_html_e( 'Add to team', 'leagueflow' ); ?></span>
						<select id="lf_new_team_id" name="lf_new_team_id"<?php echo $this->get_searchable_select_attributes( __( 'Filter teams', 'leagueflow' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<option value="0"><?php esc_html_e( 'Select an eligible team', 'leagueflow' ); ?></option>
							<?php foreach ( $teams as $team_id => $team_name ) : ?>
								<?php
								$team_sport = get_post_primary_term_slug( $team_id, 'lf_sport' );
								if ( in_array( (int) $team_id, $team_ids, true ) || ( $team_sport && isset( $used_sports[ $team_sport ] ) ) ) {
									continue;
								}
								$option_parts = array_filter( array( $team_name, $this->sports_manager->get_post_sport_label( $team_id ), get_post_league_level_label( $team_id ) ) );
								?>
								<option value="<?php echo esc_attr( (string) $team_id ); ?>" <?php selected( $preselect_team, (int) $team_id ); ?>><?php echo esc_html( implode( ' / ', $option_parts ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<p class="description"><?php esc_html_e( 'Roster role, number, and position are saved independently for each team. A player can belong to only one team per sport.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_age"><?php esc_html_e( 'Age', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="0" id="lf_age" name="lf_age" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_age', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_nationality"><?php esc_html_e( 'Nationality', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_nationality" name="lf_nationality" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_nationality', true ) ); ?>" /></td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Use the featured image as the player photo.', 'leagueflow' ); ?></p>
		<?php
	}

	/**
	 * Render the team roster metabox.
	 *
	 * @param \WP_Post $post Team post.
	 * @return void
	 */
	public function render_team_roster_metabox( $post ) {
		$players     = get_team_roster_player_posts( $post->ID );
		$sport_slug  = get_post_primary_term_slug( $post->ID, 'lf_sport' );
		$player_type = get_post_type_object( 'lf_player' );
		$can_add     = $player_type && current_user_can( $player_type->cap->create_posts );

		echo '<p class="description">' . esc_html__( 'Roster changes are made on each player record: open a player to change their number, position or captain role, or to remove them from this team. To add an existing player, open their record and use "Add to team".', 'leagueflow' ) . '</p>';

		if ( empty( $players ) ) {
			echo '<p>' . esc_html__( 'This team has no players assigned yet.', 'leagueflow' ) . '</p>';
		} else {
			echo '<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="' . esc_attr__( 'Team roster', 'leagueflow' ) . '"><table class="widefat striped leagueflow-team-roster"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Player', 'leagueflow' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'No.', 'leagueflow' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Position', 'leagueflow' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Login', 'leagueflow' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Actions', 'leagueflow' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $players as $player ) {
				$user_id   = (int) get_post_meta( $player->ID, 'lf_user_id', true );
				$user      = $user_id ? get_user_by( 'id', $user_id ) : false;
				$detail    = get_player_team_detail( $player->ID, $post->ID );
				$edit_link = current_user_can( 'edit_post', $player->ID ) ? get_edit_post_link( $player->ID ) : '';
				$name      = esc_html( $player->post_title );

				printf(
					'<tr><td>%1$s%5$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%6$s</td></tr>',
					$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					esc_html( (string) $detail['jersey_number'] ),
					esc_html( (string) $detail['position'] ),
					$user instanceof \WP_User ? esc_html( $user->user_login ) : esc_html__( 'Not generated', 'leagueflow' ),
					! empty( $detail['is_captain'] ) ? ' <strong>' . esc_html__( '(Captain)', 'leagueflow' ) . '</strong>' : '',
					$edit_link
						? sprintf(
							'<a href="%1$s" aria-label="%2$s">%3$s</a>',
							esc_url( $edit_link ),
							/* translators: %s: player name */
							esc_attr( sprintf( __( 'Manage player %s', 'leagueflow' ), $player->post_title ) ),
							esc_html__( 'Manage player', 'leagueflow' )
						)
						: '&mdash;'
				);
			}

			echo '</tbody></table></div>';
		}

		$links = array();

		if ( $can_add && $sport_slug && 'auto-draft' !== $post->post_status ) {
			$links[] = sprintf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( add_query_arg( array( 'post_type' => 'lf_player', 'lf_sport' => $sport_slug, 'lf_add_to_team' => $post->ID ), admin_url( 'post-new.php' ) ) ),
				esc_html__( 'Add a new player to this team', 'leagueflow' )
			);
		}

		if ( $sport_slug ) {
			$links[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( add_query_arg( array( 'post_type' => 'lf_player', 'lf_sport' => $sport_slug ), admin_url( 'edit.php' ) ) ),
				/* translators: %s: sport name */
				esc_html( sprintf( __( 'All %s players', 'leagueflow' ), $this->sports_manager->get_post_sport_label( $post->ID ) ) )
			);
		} elseif ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the team with its sport first; then you can add players to it.', 'leagueflow' ) . '</p>';
		}

		if ( $links ) {
			echo '<p class="leagueflow-admin-actions">' . implode( ' ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * Render match details metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_match_metabox( $post ) {
		wp_nonce_field( 'leagueflow_save_match', 'leagueflow_match_nonce' );
		printf( '<input type="hidden" name="leagueflow_match_state" value="%s" />', esc_attr( self::match_form_state( $post->ID ) ) );
		$bracket_notice = get_post_meta( $post->ID, '_lf_bracket_sync_notice', true );
		if ( is_string( $bracket_notice ) && '' !== $bracket_notice ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $bracket_notice ) );
		}

		$sport_slug      = $this->get_editor_sport_slug( $post->ID );
		$league_level_id = $this->get_editor_league_level_id( $post->ID );
		$teams           = $this->get_team_options( $sport_slug );
		$matches         = $this->get_knockout_target_options( $post->ID, $sport_slug );
		$home_team       = (int) get_post_meta( $post->ID, 'lf_home_team_id', true );
		$away_team       = (int) get_post_meta( $post->ID, 'lf_away_team_id', true );
		$winner_team     = (int) get_post_meta( $post->ID, 'lf_winner_team_id', true );
		$datetime        = (string) get_post_meta( $post->ID, 'lf_match_datetime', true );
		$datetime    = $datetime ? substr( str_replace( ' ', 'T', $datetime ), 0, 16 ) : '';
		$availability_options = $this->field_availability_manager->get_availability_options( $sport_slug );
		$availability_id      = (string) get_post_meta( $post->ID, Field_Availability_Manager::META_AVAILABILITY_ID, true );
		$schedule_source      = (string) get_post_meta( $post->ID, Field_Availability_Manager::META_SCHEDULE_SOURCE, true );
		$scheduled_at         = (string) get_post_meta( $post->ID, Field_Availability_Manager::META_SCHEDULED_AT, true );
		$score_label          = $sport_slug ? ( $this->sports_manager->get_definition( $sport_slug )['score_label'] ?? __( 'Score', 'leagueflow' ) ) : __( 'Score', 'leagueflow' );
		?>
		<input type="hidden" name="leagueflow_requested_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
		<table class="form-table leagueflow-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lf_match_datetime"><?php esc_html_e( 'Date and Time', 'leagueflow' ); ?></label></th>
				<td><input type="datetime-local" id="lf_match_datetime" name="lf_match_datetime" value="<?php echo esc_attr( $datetime ); ?>" /><p class="description"><?php echo esc_html( sprintf( __( 'Match time in %s. Publish the post now so this future fixture appears on the website; the WordPress Publish date controls when the listing becomes visible.', 'leagueflow' ), wp_timezone_string() ) ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_venue"><?php esc_html_e( 'Venue', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_venue" name="lf_venue" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_venue', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scheduling Assistant', 'leagueflow' ); ?></th>
				<td>
					<p>
						<label for="lf_auto_availability_id"><?php esc_html_e( 'Availability', 'leagueflow' ); ?></label><br />
						<?php $this->render_select( 'lf_auto_availability_id', $availability_options, $availability_id, __( 'Any matching availability', 'leagueflow' ) ); ?>
					</p>
					<p>
						<label for="lf_auto_schedule_date"><?php esc_html_e( 'Specific date', 'leagueflow' ); ?></label><br />
						<input type="date" id="lf_auto_schedule_date" name="lf_auto_schedule_date" />
					</p>
					<p><label><input type="checkbox" name="lf_auto_schedule_match" value="1" /> <?php esc_html_e( 'Auto set this match date/time and venue when saving', 'leagueflow' ); ?></label></p>
					<p><label><input type="checkbox" name="lf_auto_schedule_overwrite" value="1" /> <?php esc_html_e( 'Replace existing date/time and venue values', 'leagueflow' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'Leave unchecked to use the manual Date and Time and Venue fields above. Manual saves disconnect the match from automatic scheduling unless you run the assistant again.', 'leagueflow' ); ?></p>
					<?php if ( 'auto' === $schedule_source && $scheduled_at ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: datetime */
								esc_html__( 'Last auto-scheduled on %s.', 'leagueflow' ),
								esc_html( format_match_datetime( $scheduled_at ) )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_home_team_id"><?php esc_html_e( 'Home Team', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_home_team_id', $teams, $home_team, __( 'Select home team', 'leagueflow' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_away_team_id"><?php esc_html_e( 'Away Team', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_away_team_id', $teams, $away_team, __( 'Select away team', 'leagueflow' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_home_score"><?php echo esc_html( sprintf( __( 'Home %s', 'leagueflow' ), $score_label ) ); ?></label></th>
				<td><input type="number" min="0" step="1" inputmode="numeric" id="lf_home_score" name="lf_home_score" class="small-text" aria-describedby="leagueflow-result-preview" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_home_score', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_away_score"><?php echo esc_html( sprintf( __( 'Away %s', 'leagueflow' ), $score_label ) ); ?></label></th>
				<td><input type="number" min="0" step="1" inputmode="numeric" id="lf_away_score" name="lf_away_score" class="small-text" aria-describedby="leagueflow-result-preview" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_away_score', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_status"><?php esc_html_e( 'Status', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_status', $this->statuses, (string) get_post_meta( $post->ID, 'lf_status', true ) ?: 'scheduled', null ); ?>
					<p class="description"><?php esc_html_e( 'Saving both scores or a forfeit automatically marks a Scheduled match Finished. Live, Postponed and Cancelled stay unchanged. Standings count published, Finished league results.', 'leagueflow' ); ?></p>
					<p><label for="lf_keep_status"><input type="checkbox" name="lf_keep_status" id="lf_keep_status" value="1" <?php checked( '1' === (string) get_post_meta( $post->ID, '_lf_result_keep_status', true ) ); ?> /> <?php esc_html_e( 'Keep the selected status when saving scores', 'leagueflow' ); ?></label><br /><span class="description"><?php esc_html_e( 'Use this to keep preliminary scores or reopen a result. Uncheck it to restore automatic completion.', 'leagueflow' ); ?></span></p>
					<p id="leagueflow-result-preview" class="description" role="status" aria-live="polite" data-leagueflow-result-preview data-final-text="<?php esc_attr_e( 'Will save as Finished. Published league results count toward standings.', 'leagueflow' ); ?>" data-status-text="<?php esc_attr_e( 'Will save as %s.', 'leagueflow' ); ?>" data-incomplete-text="<?php esc_attr_e( 'Enter both scores, including 0, to save a final result.', 'leagueflow' ); ?>" data-invalid-text="<?php esc_attr_e( 'Enter non-negative whole-number scores.', 'leagueflow' ); ?>" data-score-max="<?php echo esc_attr( (string) PHP_INT_MAX ); ?>"><?php esc_html_e( 'Blank scores are not zero. Enter 0 for a scoreless team.', 'leagueflow' ); ?></p>
					<?php if ( 'volleyball' === $sport_slug ) : ?><p class="description"><strong><?php esc_html_e( 'Volleyball: enter sets won (for example 2–1). Set Scores below records the optional rally-point breakdown.', 'leagueflow' ); ?></strong></p><?php endif; ?>
					<p><button type="submit" name="lf_finalize_result" value="1" class="button button-secondary"><?php esc_html_e( 'Save match as final result', 'leagueflow' ); ?></button></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_outcome"><?php esc_html_e( 'Result Type', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_outcome', $this->match_outcomes, (string) get_post_meta( $post->ID, 'lf_outcome', true ), __( 'Played as normal', 'leagueflow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Mark a forfeit to decide a finished match without a played score. The non-forfeiting team is credited a walkover win in the standings.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th>
				<td>
					<?php if ( $sport_slug ) : ?>
						<strong><?php echo esc_html( $this->sports_manager->get_definition( $sport_slug )['label'] ); ?></strong>
					<?php else : ?>
						<strong><?php esc_html_e( 'Not set yet', 'leagueflow' ); ?></strong>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'A match takes its sport from the selected teams. Until teams are chosen, it uses the sport you opened this screen from; when you opened it from All sports, no sport is set until you choose the teams.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_league_level_id"><?php esc_html_e( 'League Level', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_league_level_id', $this->get_league_level_options(), $league_level_id ? $league_level_id : $this->find_default_league_level_id(), null ); ?>
					<p class="description"><?php esc_html_e( 'Matches inherit their level from the selected teams when possible. Home and away teams must be in the same level.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Knockout Match', 'leagueflow' ); ?></th>
				<td><label><input type="checkbox" name="lf_is_knockout" value="1" <?php checked( (bool) get_post_meta( $post->ID, 'lf_is_knockout', true ) ); ?> /> <?php esc_html_e( 'Include this fixture in the knockout bracket.', 'leagueflow' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_round_label"><?php esc_html_e( 'Round Label', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_round_label" name="lf_round_label" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_round_label', true ) ); ?>" placeholder="<?php esc_attr_e( 'Quarterfinals, Semifinals, Final', 'leagueflow' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_round_order"><?php esc_html_e( 'Round Order', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="0" id="lf_round_order" name="lf_round_order" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_round_order', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_winner_team_id"><?php esc_html_e( 'Winner Override', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_winner_team_id', $teams, $winner_team, __( 'Use match score unless overridden', 'leagueflow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Useful for matches decided on penalties or administrative rulings.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_next_match_id"><?php esc_html_e( 'Advance Winner To', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_next_match_id', $matches, (int) get_post_meta( $post->ID, 'lf_next_match_id', true ), __( 'Do not advance automatically', 'leagueflow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Only later-round knockout matches in the same sport, level, competition and season are listed. Save the round order first if a match is missing.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_next_match_slot"><?php esc_html_e( 'Advance Into Slot', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_next_match_slot', array( 'home' => __( 'Home', 'leagueflow' ), 'away' => __( 'Away', 'leagueflow' ) ), in_array( (string) get_post_meta( $post->ID, 'lf_next_match_slot', true ), array( 'home', 'away' ), true ) ? (string) get_post_meta( $post->ID, 'lf_next_match_slot', true ) : 'home', null ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render match events metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_match_events_metabox( $post ) {
		// Which sport's note fields to show; display only, never assigned.
		$sport_slug = $this->get_editor_sport_slug( $post->ID );
		$sport_slug = $sport_slug ? $sport_slug : $this->get_current_requested_sport_slug();
		$fields     = $this->sports_manager->get_match_fields( $sport_slug );
		?>
		<p><?php esc_html_e( 'Enter optional match details in a simple line-by-line format. LeagueFlow changes these fields by sport so the data model stays shared while the match notes stay sport-specific.', 'leagueflow' ); ?></p>
		<?php foreach ( $fields as $field ) : ?>
			<p>
				<label for="<?php echo esc_attr( $field['key'] ); ?>"><strong><?php echo esc_html( $field['label'] ); ?></strong></label><br />
				<textarea id="<?php echo esc_attr( $field['key'] ); ?>" name="<?php echo esc_attr( $field['key'] ); ?>" rows="4" class="large-text"><?php echo esc_textarea( (string) get_post_meta( $post->ID, $field['key'], true ) ); ?></textarea>
				<?php if ( ! empty( $field['description'] ) ) : ?>
					<span class="description"><?php echo esc_html( $field['description'] ); ?></span>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Render calendar event details metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_calendar_event_metabox( $post ) {
		wp_nonce_field( 'leagueflow_save_calendar_event', 'leagueflow_calendar_event_nonce' );

		$sport_slug      = $this->get_editor_sport_slug( $post->ID );
		$league_level_id = $this->get_editor_league_level_id( $post->ID );
		$start           = (string) get_post_meta( $post->ID, 'lf_event_start_datetime', true );
		$end             = (string) get_post_meta( $post->ID, 'lf_event_end_datetime', true );
		$start      = $start ? substr( str_replace( ' ', 'T', $start ), 0, 16 ) : '';
		$end        = $end ? substr( str_replace( ' ', 'T', $end ), 0, 16 ) : '';
		?>
		<input type="hidden" name="leagueflow_requested_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
		<table class="form-table leagueflow-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lf_event_start_datetime"><?php esc_html_e( 'Start Date and Time', 'leagueflow' ); ?></label></th>
				<td><input type="datetime-local" id="lf_event_start_datetime" name="lf_event_start_datetime" value="<?php echo esc_attr( $start ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_event_end_datetime"><?php esc_html_e( 'End Date and Time', 'leagueflow' ); ?></label></th>
				<td>
					<input type="datetime-local" id="lf_event_end_datetime" name="lf_event_end_datetime" value="<?php echo esc_attr( $end ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. If blank, the public calendar treats the event as one hour long.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_event_venue"><?php esc_html_e( 'Venue', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_event_venue" name="lf_event_venue" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_event_venue', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_event_type"><?php esc_html_e( 'Event Type', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_event_type', $this->calendar_event_types, (string) get_post_meta( $post->ID, 'lf_event_type', true ) ? (string) get_post_meta( $post->ID, 'lf_event_type', true ) : 'drop_in', null ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_event_status"><?php esc_html_e( 'Status', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_event_status', $this->statuses, (string) get_post_meta( $post->ID, 'lf_event_status', true ) ? (string) get_post_meta( $post->ID, 'lf_event_status', true ) : 'scheduled', null ); ?></td>
			</tr>
			<tr>
				<?php
				$current_sport = get_post_primary_term_slug( $post->ID, 'lf_sport' );
				$sport_options = $this->get_sport_choice_options( $current_sport );
				?>
				<th scope="row"><label for="lf_sport_choice"><?php esc_html_e( 'Sport', 'leagueflow' ); ?> <span aria-hidden="true">*</span></label></th>
				<td>
					<?php if ( empty( $sport_options ) ) : ?>
						<?php $this->render_no_enabled_sports_notice(); ?>
					<?php else : ?>
						<select id="lf_sport_choice" name="lf_sport_choice" required aria-describedby="lf_event_sport_help">
							<option value=""><?php esc_html_e( 'Choose a sport', 'leagueflow' ); ?></option>
							<?php foreach ( $sport_options as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_sport ? $current_sport : $sport_slug, $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="lf_event_sport_help"><?php esc_html_e( 'The sport whose calendar shows this event. Only enabled sports can be chosen. When changing sport, also choose its competitions and seasons or clear those selections.', 'leagueflow' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_league_level_id"><?php esc_html_e( 'League Level', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_league_level_id', $this->get_league_level_options(), $league_level_id ? $league_level_id : $this->find_default_league_level_id(), null ); ?></td>
			</tr>
			<tr>
				<?php
				$poster_id  = (int) get_post_thumbnail_id( $post );
				$poster_src = $poster_id ? wp_get_attachment_image_url( $poster_id, 'medium' ) : '';
				?>
				<th scope="row"><span id="lf_event_poster_label"><?php esc_html_e( 'Poster', 'leagueflow' ); ?></span></th>
				<td>
					<div class="leagueflow-poster-field" data-leagueflow-poster role="group" aria-labelledby="lf_event_poster_label"
						data-title="<?php esc_attr_e( 'Choose a poster image', 'leagueflow' ); ?>"
						data-button="<?php esc_attr_e( 'Use this poster', 'leagueflow' ); ?>"
						data-change="<?php esc_attr_e( 'Change poster', 'leagueflow' ); ?>"
						data-upload="<?php esc_attr_e( 'Add poster', 'leagueflow' ); ?>">
						<input type="hidden" name="lf_event_poster_id" value="<?php echo esc_attr( (string) $poster_id ); ?>" data-leagueflow-poster-id />
						<img class="leagueflow-poster-field__preview" src="<?php echo esc_url( (string) $poster_src ); ?>" alt="" data-leagueflow-poster-preview<?php echo $poster_src ? '' : ' hidden'; ?> />
						<p>
							<button type="button" class="button" data-leagueflow-poster-choose><?php echo esc_html( $poster_id ? __( 'Change poster', 'leagueflow' ) : __( 'Add poster', 'leagueflow' ) ); ?></button>
							<button type="button" class="button-link button-link-delete" data-leagueflow-poster-remove<?php echo $poster_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove poster', 'leagueflow' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'Optional. Shown with the event on the calendar; visitors can open it full size.', 'leagueflow' ); ?></p>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_event_cost"><?php esc_html_e( 'Cost', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_event_cost" name="lf_event_cost" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_event_cost', true ) ); ?>" placeholder="<?php esc_attr_e( 'Free, $5, included with membership', 'leagueflow' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Registration', 'leagueflow' ); ?></th>
				<td>
					<label><input type="checkbox" name="lf_event_registration_required" value="1" <?php checked( (bool) get_post_meta( $post->ID, 'lf_event_registration_required', true ) ); ?> /> <?php esc_html_e( 'Registration required', 'leagueflow' ); ?></label>
					<p><input type="url" id="lf_event_registration_url" name="lf_event_registration_url" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_event_registration_url', true ) ); ?>" placeholder="https://" /></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Use this for drop-ins, skills sessions, open gym times, and other calendar items that are not team-vs-team matches.', 'leagueflow' ); ?></p>
		<?php
	}

	/**
	 * Save team metadata.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function save_team_meta( $post_id, $post ) {
		unset( $post );

		if ( ! $this->can_save( $post_id, 'leagueflow_team_nonce', 'leagueflow_save_team' ) ) {
			return;
		}

		update_post_meta( $post_id, 'lf_short_name', sanitize_text_field( wp_unslash( $_POST['lf_short_name'] ?? '' ) ) );
		update_post_meta( $post_id, 'lf_city', sanitize_text_field( wp_unslash( $_POST['lf_city'] ?? '' ) ) );
		update_post_meta( $post_id, 'lf_coach', sanitize_text_field( wp_unslash( $_POST['lf_coach'] ?? '' ) ) );

		$founded = isset( $_POST['lf_founded_year'] ) ? absint( wp_unslash( $_POST['lf_founded_year'] ) ) : 0;
		if ( $founded ) {
			update_post_meta( $post_id, 'lf_founded_year', $founded );
		} else {
			delete_post_meta( $post_id, 'lf_founded_year' );
		}

		$this->save_team_manager_accounts( $post_id );

		$adjustment = isset( $_POST['lf_points_adjustment'] ) ? (int) wp_unslash( $_POST['lf_points_adjustment'] ) : 0;
		if ( 0 === $adjustment ) {
			delete_post_meta( $post_id, 'lf_points_adjustment' );
		} else {
			update_post_meta( $post_id, 'lf_points_adjustment', $adjustment );
		}
		$this->update_or_delete_meta( $post_id, 'lf_adjustment_note', sanitize_text_field( wp_unslash( $_POST['lf_adjustment_note'] ?? '' ) ) );

		$this->save_team_sport_choice( $post_id );
		$league_level_id = $this->assign_league_level_from_request( $post_id );

		$sport_id = get_post_primary_term_id( $post_id, 'lf_sport' );

		if ( $sport_id ) {
			// Only this team's members (any status), not every player on the site (C-15).
			foreach ( get_team_member_player_ids( $post_id, 'any' ) as $player_id ) {
				assign_player_to_team( $player_id, $post_id, get_player_team_detail( $player_id, $post_id ) );
			}
		}
	}

	/**
	 * Save player metadata.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function save_player_meta( $post_id, $post ) {
		unset( $post );

		if ( ! $this->can_save( $post_id, 'leagueflow_player_nonce', 'leagueflow_save_player' ) ) {
			return;
		}

		if ( $this->save_player_identity_meta( $post_id ) ) {
			$this->maybe_generate_player_login( $post_id );
		}

		$current_team_ids = get_player_team_ids( $post_id );
		$submitted        = isset( $_POST['lf_player_memberships'] ) && is_array( $_POST['lf_player_memberships'] )
			? wp_unslash( $_POST['lf_player_memberships'] )
			: array();
		$team_ids         = array();
		$details          = array();
		$used_sports      = array();

		foreach ( $current_team_ids as $team_id ) {
			$row = isset( $submitted[ $team_id ] ) && is_array( $submitted[ $team_id ] ) ? $submitted[ $team_id ] : array();

			if ( ! empty( $row['remove'] ) ) {
				continue;
			}

			$team_ids[]          = $team_id;
			$details[ $team_id ] = empty( $row )
				? get_player_team_detail( $post_id, $team_id )
				: sanitize_player_team_detail(
					array(
						'jersey_number' => $row['jersey_number'] ?? '',
						'position'      => $row['position'] ?? '',
						'is_captain'    => ! empty( $row['is_captain'] ),
					)
				);

			$team_sport = get_post_primary_term_slug( $team_id, 'lf_sport' );
			if ( $team_sport ) {
				$used_sports[ $team_sport ] = true;
			}
		}

		$new_team_id = absint( wp_unslash( $_POST['lf_new_team_id'] ?? 0 ) );
		if ( $new_team_id ) {
			$new_team   = get_post( $new_team_id );
			$new_sport  = get_post_primary_term_slug( $new_team_id, 'lf_sport' );
			$can_assign = $new_team instanceof \WP_Post && 'lf_team' === $new_team->post_type && $new_sport && ! isset( $used_sports[ $new_sport ] );

			if ( $can_assign ) {
				$team_ids[]              = $new_team_id;
				$details[ $new_team_id ] = sanitize_player_team_detail( array() );
			} else {
				$this->queue_notice( __( 'The selected team was not added because the player already has a team in that sport.', 'leagueflow' ), 'error' );
			}
		}

		$primary_id = absint( wp_unslash( $_POST['lf_primary_team_id'] ?? 0 ) );
		if ( ! in_array( $primary_id, $team_ids, true ) ) {
			$existing_primary = absint( get_post_meta( $post_id, 'lf_team_id', true ) );
			$primary_id       = in_array( $existing_primary, $team_ids, true ) ? $existing_primary : ( $team_ids[0] ?? 0 );
		}

		if ( $primary_id ) {
			$team_ids = array_values( array_unique( array_merge( array( $primary_id ), $team_ids ) ) );
		}

		set_player_team_ids( $post_id, $team_ids );

		foreach ( $team_ids as $team_id ) {
			assign_player_to_team( $post_id, $team_id, $details[ $team_id ] ?? array() );
		}

		// A team added here by an authorized editor closes matching pending requests.
		reconcile_roster_additions( $post_id, $current_team_ids, 'admin_player' );

		update_post_meta( $post_id, 'lf_age', isset( $_POST['lf_age'] ) ? absint( wp_unslash( $_POST['lf_age'] ) ) : 0 );
		update_post_meta( $post_id, 'lf_nationality', sanitize_text_field( wp_unslash( $_POST['lf_nationality'] ?? '' ) ) );

		if ( empty( $team_ids ) ) {
			$this->assign_default_sport_if_missing( $post_id );
			$this->assign_default_league_level_if_missing( $post_id );
		}
	}

	/**
	 * Save match metadata.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function save_match_meta( $post_id, $post ) {
		// Updating a linked knockout fixture title can trigger this hook again
		// while the source editor's POST body is still present.
		if ( isset( $_POST['post_ID'] ) && ( ! is_scalar( $_POST['post_ID'] ) || absint( $_POST['post_ID'] ) !== (int) $post_id ) ) { return; }
		if ( $this->syncing_match_title || ! $this->can_save( $post_id, 'leagueflow_match_nonce', 'leagueflow_save_match' ) ) {
			return;
		}
		$lock = db_lock_name( 'result', (string) $post_id );
		if ( ! acquire_db_lock( $lock, 1 ) ) {
			$this->queue_notice( __( 'Another person is updating this match. Reload and try again. Match details were not changed.', 'leagueflow' ), 'error' );
			return;
		}
		$saved = false;
		try {
			clean_post_cache( $post_id );
			if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
			if ( isset( $_POST['leagueflow_match_state'] ) && ( ! is_string( $_POST['leagueflow_match_state'] ) || ! hash_equals( self::match_form_state( $post_id ), wp_unslash( $_POST['leagueflow_match_state'] ) ) ) ) {
				$this->queue_notice( __( 'The match changed after you opened this form. Reload to review the latest result before saving. Match details were not changed.', 'leagueflow' ), 'error' );
				return;
			}
			$saved = true === $this->save_match_meta_locked( $post_id, $post );
		} finally {
			release_db_lock( $lock );
		}
		if ( $saved ) { do_action( 'leagueflow_match_result_saved', $post_id ); }
	}

	/**
	 * Detect stale result forms without rejecting the current WordPress save.
	 * Core applies publication and taxonomy edits before save_post_lf_match, so
	 * this token covers match metadata; the referee token also covers visibility.
	 */
	public static function match_form_state( $post_id ) {
		$state = array();
		foreach ( array_merge( array( 'lf_home_team_id', 'lf_away_team_id', 'lf_home_score', 'lf_away_score', 'lf_status', 'lf_outcome', 'lf_is_knockout', 'lf_winner_team_id', 'lf_match_datetime', 'lf_venue', 'lf_next_match_id', 'lf_next_match_slot', 'lf_round_order', 'lf_round_label', '_lf_result_keep_status' ), Sports_Manager::get_all_match_meta_keys() ) as $key ) {
			$state[ $key ] = get_post_meta( $post_id, $key, true );
		}
		return hash_hmac( 'sha256', wp_json_encode( $state ), wp_salt( 'nonce' ) );
	}

	/** Save validated match details while holding the shared result lock. */
	protected function save_match_meta_locked( $post_id, $post ) {
		global $wpdb;
		unset( $post );

		if ( $this->syncing_match_title ) {
			return;
		}

		if ( ! $this->can_save( $post_id, 'leagueflow_match_nonce', 'leagueflow_save_match' ) ) {
			return;
		}
		foreach ( array_merge( array( 'lf_home_team_id', 'lf_away_team_id', 'lf_home_score', 'lf_away_score', 'lf_status', 'lf_outcome', 'lf_match_datetime', 'lf_venue', 'lf_is_knockout', 'lf_winner_team_id', 'lf_next_match_id', 'lf_next_match_slot', 'lf_round_order', 'lf_round_label', 'lf_league_level_id', 'lf_keep_status', 'lf_finalize_result', 'leagueflow_requested_sport', 'lf_auto_schedule_match', 'lf_auto_schedule_overwrite', 'lf_auto_availability_id', 'lf_auto_schedule_date' ), Sports_Manager::get_all_match_meta_keys() ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				$this->queue_notice( __( 'The submitted match details are invalid. Reload the form and try again; no match details were changed.', 'leagueflow' ), 'error' );
				return;
			}
		}

		$previous_datetime = (string) get_post_meta( $post_id, 'lf_match_datetime', true );
		$previous_venue    = (string) get_post_meta( $post_id, 'lf_venue', true );
		$datetime = sanitize_text_field( wp_unslash( $_POST['lf_match_datetime'] ?? '' ) );
		$datetime = $datetime ? str_replace( 'T', ' ', $datetime ) : '';
		if ( '' !== $datetime && ! $this->is_valid_local_datetime( $datetime ) ) {
			$this->queue_notice( __( 'Enter a valid match date and time. Match details were not changed.', 'leagueflow' ), 'error' );
			return;
		}
		$venue    = sanitize_text_field( wp_unslash( $_POST['lf_venue'] ?? '' ) );

		$home_team_id = isset( $_POST['lf_home_team_id'] ) ? absint( wp_unslash( $_POST['lf_home_team_id'] ) ) : 0;
		$away_team_id = isset( $_POST['lf_away_team_id'] ) ? absint( wp_unslash( $_POST['lf_away_team_id'] ) ) : 0;
		foreach ( array_filter( array( $home_team_id, $away_team_id ) ) as $team_id ) {
			$team = get_post( $team_id );
			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || ! in_array( $team->post_status, array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) || ! can_view_league_post( $team ) ) {
				$this->queue_notice( __( 'Choose existing teams that you can access. Match details were not changed.', 'leagueflow' ), 'error' );
				return;
			}
		}

		if ( $home_team_id && $home_team_id === $away_team_id ) {
			$this->queue_notice( __( 'Home team and away team must be different.', 'leagueflow' ), 'error' );
			return;
		}

		$home_sport_id = $home_team_id ? get_post_primary_term_id( $home_team_id, 'lf_sport' ) : 0;
		$away_sport_id = $away_team_id ? get_post_primary_term_id( $away_team_id, 'lf_sport' ) : 0;
		$home_level_id = $home_team_id ? get_post_primary_term_id( $home_team_id, 'lf_league_level' ) : 0;
		$away_level_id = $away_team_id ? get_post_primary_term_id( $away_team_id, 'lf_league_level' ) : 0;

		if ( $home_sport_id && $away_sport_id && $home_sport_id !== $away_sport_id ) {
			$this->queue_notice( __( 'Home and away teams must belong to the same sport.', 'leagueflow' ), 'error' );
			return;
		}

		if ( $home_level_id && $away_level_id && $home_level_id !== $away_level_id ) {
			$this->queue_notice( __( 'Home and away teams must belong to the same league level.', 'leagueflow' ), 'error' );
			return;
		}

		$match_sport_id = $home_sport_id ? $home_sport_id : $away_sport_id;
		$match_level_id = $home_level_id ? $home_level_id : $away_level_id;
		$match_sport = $match_sport_id ? get_term( $match_sport_id, 'lf_sport' ) : null;
		if ( $match_sport instanceof \WP_Term ) {
			foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) ) {
					$this->queue_notice( __( 'The match context could not be checked. Reload before saving the result.', 'leagueflow' ), 'error' );
					return;
				}
				foreach ( $terms as $term_id ) {
					$owner = (string) get_term_meta( $term_id, 'lf_sport_slug', true );
					if ( '' !== $owner && $owner !== $match_sport->slug ) {
						$this->queue_notice( __( 'The selected competition and season must belong to the teams’ sport. Correct the context before saving the result.', 'leagueflow' ), 'error' );
						return;
					}
				}
			}
		}

		if ( ! $match_level_id && isset( $_POST['lf_league_level_id'] ) ) {
			$match_level_id = absint( wp_unslash( $_POST['lf_league_level_id'] ) );
		}

		if ( ! $match_level_id ) {
			$match_level_id = get_post_primary_term_id( $post_id, 'lf_league_level' );
		}

		if ( ! $match_level_id ) {
			$match_level_id = $this->find_default_league_level_id();
		}

		$status = sanitize_key( wp_unslash( $_POST['lf_status'] ?? 'scheduled' ) );

		if ( '' !== $status && ! isset( $this->statuses[ $status ] ) ) {
			$this->queue_notice( __( 'Choose a valid match status. Match details were not changed.', 'leagueflow' ), 'error' );
			return;
		}

		$home_score = trim( sanitize_text_field( wp_unslash( $_POST['lf_home_score'] ?? '' ) ) );
		$away_score = trim( sanitize_text_field( wp_unslash( $_POST['lf_away_score'] ?? '' ) ) );
		$outcome    = sanitize_key( wp_unslash( $_POST['lf_outcome'] ?? '' ) );
		if ( '' !== $outcome && ! isset( $this->match_outcomes[ $outcome ] ) ) {
			$this->queue_notice( __( 'Choose a valid result type. Match details were not changed.', 'leagueflow' ), 'error' );
			return;
		}
		$keep_status = isset( $_POST['lf_keep_status'] ) && '1' === (string) $_POST['lf_keep_status'];
		$status = Match_Status::status_for_score_entry( $status, $home_score, $away_score, $outcome, $keep_status, isset( $_POST['lf_finalize_result'] ) && '1' === (string) $_POST['lf_finalize_result'] );

		// Validate before any write. Scores are whole numbers of zero or more; a
		// finished match played as normal needs both, so a blank box is never
		// silently recorded as 0 (an explicit 0-0 is kept). Forfeits are decided
		// by the outcome and may leave the score blank.
		$score_errors = $this->validate_match_scores( $status, $outcome, $home_score, $away_score );

		if ( $score_errors ) {
			foreach ( $score_errors as $error ) {
				$this->queue_notice( $error, 'error' );
			}
			$this->queue_notice( __( 'Match details were not saved. Correct the score and update again.', 'leagueflow' ), 'error' );
			return;
		}
		if ( 'finished' === $status && ( ! $home_team_id || ! $away_team_id || ! $home_sport_id || ! $away_sport_id ) ) {
			$this->queue_notice( __( 'A final result needs two different teams with the same sport. Select both teams before saving the result.', 'leagueflow' ), 'error' );
			return;
		}
		if ( $match_level_id && ! term_exists( $match_level_id, 'lf_league_level' ) ) {
			$this->queue_notice( __( 'Choose an existing league level. Match details were not changed.', 'leagueflow' ), 'error' );
			return;
		}

		$is_knockout   = ! empty( $_POST['lf_is_knockout'] ) ? 1 : 0;
		$winner_team   = isset( $_POST['lf_winner_team_id'] ) ? absint( wp_unslash( $_POST['lf_winner_team_id'] ) ) : 0;
		$next_match_id = isset( $_POST['lf_next_match_id'] ) ? absint( wp_unslash( $_POST['lf_next_match_id'] ) ) : 0;
		$next_slot     = sanitize_key( wp_unslash( $_POST['lf_next_match_slot'] ?? 'home' ) );

		if ( $next_match_id && ! $this->knockout_service->can_advance_to( $post_id, $next_match_id, array( 'lf_sport' => $match_sport_id ? array( $match_sport_id ) : array(), 'lf_league_level' => $match_level_id ? array( $match_level_id ) : array() ), absint( $_POST['lf_round_order'] ?? 0 ) ) ) {
			$this->queue_notice( __( 'Choose an editable next match in the same bracket context with a forward, acyclic path.', 'leagueflow' ), 'error' );
			return;
		}

		if ( $winner_team && ! in_array( $winner_team, array( $home_team_id, $away_team_id ), true ) ) {
			$this->queue_notice( __( 'Winner override must match the selected home or away team.', 'leagueflow' ), 'error' );
			return;
		}

		if ( $next_match_id && $next_match_id === $post_id ) {
			$next_match_id = 0;
			$this->queue_notice( __( 'A match cannot advance into itself.', 'leagueflow' ), 'error' );
		}

		if ( $next_match_id && $match_sport_id ) {
			$next_match_sport_id = get_post_primary_term_id( $next_match_id, 'lf_sport' );

			if ( $next_match_sport_id && $next_match_sport_id !== $match_sport_id ) {
				$this->queue_notice( __( 'Knockout advancement targets must belong to the same sport.', 'leagueflow' ), 'error' );
				return;
			}
		}

		if ( $next_match_id && $match_level_id ) {
			$next_match_level_id = get_post_primary_term_id( $next_match_id, 'lf_league_level' );

			if ( $next_match_level_id && $next_match_level_id !== $match_level_id ) {
				$this->queue_notice( __( 'Knockout advancement targets must belong to the same league level.', 'leagueflow' ), 'error' );
				return;
			}
		}

		// A result is one write: a failed score, context or status update must not
		// leave standings using half the old result and half the new result.
		$meta = array(
			'lf_match_datetime' => '' === $datetime ? null : $datetime,
			'lf_venue' => '' === $venue ? null : $venue,
			'lf_home_team_id' => (string) $home_team_id,
			'lf_away_team_id' => (string) $away_team_id,
			'lf_home_score' => '' === $home_score ? null : (string) (int) $home_score,
			'lf_away_score' => '' === $away_score ? null : (string) (int) $away_score,
			'lf_outcome' => '' === $outcome ? null : $outcome,
			'lf_is_knockout' => $is_knockout ? '1' : '',
			'_lf_result_keep_status' => $keep_status ? '1' : null,
			'lf_round_label' => $is_knockout ? sanitize_text_field( wp_unslash( $_POST['lf_round_label'] ?? '' ) ) : null,
			'lf_round_order' => $is_knockout ? (string) absint( $_POST['lf_round_order'] ?? 0 ) : null,
			'lf_winner_team_id' => $is_knockout ? (string) $winner_team : null,
			'lf_next_match_id' => $is_knockout ? (string) $next_match_id : null,
			'lf_next_match_slot' => $is_knockout ? ( in_array( $next_slot, array( 'home', 'away' ), true ) ? $next_slot : 'home' ) : null,
		);
		foreach ( array( 'home' => $home_team_id, 'away' => $away_team_id ) as $slot => $team_id ) {
			if ( $team_id !== (int) get_post_meta( $post_id, 'lf_' . $slot . '_team_id', true ) ) { $meta[ '_lf_' . $slot . '_advanced_from' ] = null; }
		}
		foreach ( Sports_Manager::get_all_match_meta_keys() as $meta_key ) {
			// Other sports' hidden note fields are not a request to delete them.
			if ( array_key_exists( $meta_key, $_POST ) ) {
				$note = sanitize_textarea_field( wp_unslash( $_POST[ $meta_key ] ) );
				$meta[ $meta_key ] = '' === $note ? null : $note;
			}
		}
		if ( empty( $_POST['lf_auto_schedule_match'] ) && ( $datetime !== $previous_datetime || $venue !== $previous_venue ) ) {
			$meta[ Field_Availability_Manager::META_SCHEDULE_SOURCE ] = '' !== $datetime || '' !== $venue ? 'manual' : null;
			$meta[ Field_Availability_Manager::META_AVAILABILITY_ID ] = null;
			$meta[ Field_Availability_Manager::META_SCHEDULED_AT ] = null;
		}
		if ( ! $match_sport_id ) {
			$match_sport_id = get_post_primary_term_id( $post_id, 'lf_sport' );
			if ( ! $match_sport_id && $this->is_assignable_sport_slug( $this->get_context_sport_slug() ) ) {
				$context_sport = get_term_by( 'slug', $this->get_context_sport_slug(), 'lf_sport' );
				$match_sport_id = $context_sport instanceof \WP_Term ? (int) $context_sport->term_id : 0;
			}
		}
		$context = array_filter( array( 'lf_sport' => $match_sport_id, 'lf_league_level' => $match_level_id ) );
		$prior_context = array();
		foreach ( $context as $taxonomy => $term_id ) { $prior_context[ $taxonomy ] = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ); }
		foreach ( array( $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy ) as $table_name ) {
			$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $table || 'innodb' !== strtolower( (string) $table->Engine ) ) {
				$this->queue_notice( __( 'The match could not be safely saved. Ask your administrator to check that the WordPress match and taxonomy tables support transactions.', 'leagueflow' ), 'error' );
				return;
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->queue_notice( __( 'The match could not be saved. No match details were changed; please try again.', 'leagueflow' ), 'error' );
			return;
		}
		$committed = false;
		Match_Status::defer_transitions( $post_id );
		try {
			foreach ( $meta as $key => $value ) {
				if ( null === $value ) { delete_post_meta( $post_id, $key ); }
				else { update_post_meta( $post_id, $key, wp_slash( $value ) ); }
				if ( null === $value ? metadata_exists( 'post', $post_id, $key ) : (string) get_post_meta( $post_id, $key, true ) !== $value ) {
					throw new \RuntimeException( 'Match metadata was not saved completely: ' . $key );
				}
			}
			foreach ( $context as $taxonomy => $term_id ) {
				$result = wp_set_object_terms( $post_id, array( $term_id ), $taxonomy, false );
				if ( is_wp_error( $result ) || array( (int) $term_id ) !== array_map( 'intval', (array) wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ) ) ) {
					throw new \RuntimeException( 'Match context was not saved completely.' );
				}
			}
			// Status is the last field written; observers run only after COMMIT.
			update_post_meta( $post_id, 'lf_status', $status );
			if ( $status !== get_post_meta( $post_id, 'lf_status', true ) || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				throw new \RuntimeException( 'Match status could not be committed.' );
			}
			$committed = true;
		} catch ( \Throwable $error ) {
			// Keep database errors and third-party hook details out of staff notices.
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $post_id );
				foreach ( $context as $taxonomy => $term_id ) { clean_term_cache( array_merge( array( $term_id ), is_array( $prior_context[ $taxonomy ] ) ? $prior_context[ $taxonomy ] : array() ), $taxonomy ); }
			}
			Match_Status::flush_transitions( $post_id, $committed );
		}
		if ( ! $committed ) {
			$this->queue_notice( __( 'The complete match result could not be saved. No match details were changed; reload and try again.', 'leagueflow' ), 'error' );
			return;
		}
		$this->sync_match_title( $post_id, $home_team_id, $away_team_id, $datetime );
		if ( 'finished' !== $status && '' !== $home_score && '' !== $away_score ) {
			$this->queue_notice( __( 'Scores saved with the selected status. Only Finished results contribute to standings. Use Save match as final result when play is complete.', 'leagueflow' ), 'info' );
		} elseif ( 'finished' === $status && 'publish' !== get_post_status( $post_id ) ) {
			$this->queue_notice( __( 'Final result saved. Publish this match to include it in public standings.', 'leagueflow' ), 'warning' );
		} elseif ( 'finished' === $status && ! $is_knockout && ( 'publish' !== get_post_status( $home_team_id ) || 'publish' !== get_post_status( $away_team_id ) ) ) {
			$this->queue_notice( __( 'Final result saved. Publish both teams before this result can count in standings.', 'leagueflow' ), 'warning' );
		} elseif ( 'finished' === $status ) {
			$this->queue_notice( $is_knockout ? __( 'Final result saved. Bracket advancement uses this result when the next round is available.', 'leagueflow' ) : __( 'Final result saved and marked Finished. Check Standings in this match’s sport, competition, season and level.', 'leagueflow' ), 'success' );
		}

		if ( ! empty( $_POST['lf_auto_schedule_match'] ) ) {
			$sport_slug = $match_sport_id ? get_term( $match_sport_id, 'lf_sport' ) : null;
			$sport_slug = ( $sport_slug && ! is_wp_error( $sport_slug ) ) ? $sport_slug->slug : $this->get_editor_sport_slug( $post_id );

			$result = $this->field_availability_manager->schedule_match(
				$post_id,
				array(
					'sport_slug'          => $sport_slug,
					'availability_id'     => sanitize_key( wp_unslash( $_POST['lf_auto_availability_id'] ?? '' ) ),
					'date'                => $this->sanitize_admin_date( wp_unslash( $_POST['lf_auto_schedule_date'] ?? '' ) ),
					'mode'                => 'both',
					'overwrite'           => ! empty( $_POST['lf_auto_schedule_overwrite'] ),
					'suppress_title_sync' => true,
				)
			);

			if ( ! empty( $result['scheduled'] ) ) {
				$this->sync_match_title(
					$post_id,
					$home_team_id,
					$away_team_id,
					(string) get_post_meta( $post_id, 'lf_match_datetime', true )
				);
				$this->queue_notice( __( 'Scheduling assistant updated this match.', 'leagueflow' ), 'success' );
			} else {
				$message = ! empty( $result['messages'][0] ) ? $result['messages'][0] : __( 'Scheduling assistant could not find an open field slot for this match.', 'leagueflow' );
				$this->queue_notice( $message, 'warning' );
			}
		}
		return true;
	}

	/**
	 * Validate submitted match scores.
	 *
	 * @param string $status Match status.
	 * @param string $outcome Forfeit outcome, or '' when played as normal.
	 * @param string $home_score Submitted home score.
	 * @param string $away_score Submitted away score.
	 * @return array<int, string> Error messages; empty when valid.
	 */
	public function validate_match_scores( $status, $outcome, $home_score, $away_score ) {
		$errors = array();
		$labels = array(
			'home' => __( 'Home score', 'leagueflow' ),
			'away' => __( 'Away score', 'leagueflow' ),
		);

		foreach ( array( 'home' => $home_score, 'away' => $away_score ) as $side => $score ) {
			if ( '' === $score ) {
				continue;
			}

			if ( ( ! is_string( $score ) && ! is_int( $score ) ) || ! preg_match( '/^[0-9]+$/D', (string) $score ) ) {
				/* translators: %s: Home score or Away score */
				$errors[] = sprintf( __( '%s must be a whole number of 0 or more.', 'leagueflow' ), $labels[ $side ] );
				continue;
			}

			// No sport-specific cap: only the largest integer PHP can store and compare exactly.
			$digits = ltrim( (string) $score, '0' );
			$max    = (string) PHP_INT_MAX;

			if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
				/* translators: 1: Home score or Away score, 2: largest storable whole number */
				$errors[] = sprintf( __( '%1$s is larger than the largest whole number that can be stored (%2$s).', 'leagueflow' ), $labels[ $side ], number_format_i18n( PHP_INT_MAX ) );
			}
		}

		if ( ! $errors && 'finished' === $status && '' === $outcome && ( '' === $home_score || '' === $away_score ) ) {
			$errors[] = __( 'A finished match needs both scores. Enter 0 for a scoreless side, or choose a forfeit result.', 'leagueflow' );
		}

		return $errors;
	}

	/**
	 * Save calendar event metadata.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function save_calendar_event_meta( $post_id, $post ) {
		unset( $post );

		if ( ! $this->can_save( $post_id, 'leagueflow_calendar_event_nonce', 'leagueflow_save_calendar_event' ) ) {
			Taxonomies::clear_editor_event_context( $post_id );
			return;
		}
		$context = Taxonomies::prepare_editor_event_context( $post_id );
		if ( $context['error'] ) {
			Taxonomies::notice_editor_event_context( $context['error'] );
			Taxonomies::clear_editor_event_context( $post_id );
			return;
		}

		$start = sanitize_text_field( wp_unslash( $_POST['lf_event_start_datetime'] ?? '' ) );
		$end   = sanitize_text_field( wp_unslash( $_POST['lf_event_end_datetime'] ?? '' ) );
		$start = $start ? str_replace( 'T', ' ', $start ) : '';
		$end   = $end ? str_replace( 'T', ' ', $end ) : '';

		if ( ( $start && ! $this->is_valid_local_datetime( $start ) ) || ( $end && ! $this->is_valid_local_datetime( $end ) ) || ( $end && ( ! $start || $end <= $start ) ) ) {
			Taxonomies::restore_editor_event_context( $post_id, $context );
			Taxonomies::clear_editor_event_context( $post_id );
			$this->queue_notice( __( 'Use valid event dates and an end time after the start time. Event details were not changed.', 'leagueflow' ), 'error' );
			return;
		}
		$saved_context = $this->save_calendar_event_sport_choice( $post_id, $context );
		if ( is_wp_error( $saved_context ) ) {
			Taxonomies::restore_editor_event_context( $post_id, $context );
			Taxonomies::clear_editor_event_context( $post_id );
			$this->queue_notice( $saved_context->get_error_message(), 'error' );
			return;
		}

		$type = sanitize_key( wp_unslash( $_POST['lf_event_type'] ?? 'drop_in' ) );

		if ( ! isset( $this->calendar_event_types[ $type ] ) ) {
			$type = 'drop_in';
		}

		$status = sanitize_key( wp_unslash( $_POST['lf_event_status'] ?? 'scheduled' ) );

		if ( ! isset( $this->statuses[ $status ] ) ) {
			$status = 'scheduled';
		}

		$this->update_or_delete_meta( $post_id, 'lf_event_start_datetime', $start );
		$this->update_or_delete_meta( $post_id, 'lf_event_end_datetime', $end );
		$this->update_or_delete_meta( $post_id, 'lf_event_venue', sanitize_text_field( wp_unslash( $_POST['lf_event_venue'] ?? '' ) ) );
		update_post_meta( $post_id, 'lf_event_type', $type );
		update_post_meta( $post_id, 'lf_event_status', $status );
		$this->update_or_delete_meta( $post_id, 'lf_event_cost', sanitize_text_field( wp_unslash( $_POST['lf_event_cost'] ?? '' ) ) );
		$this->update_or_delete_meta( $post_id, 'lf_event_registration_url', esc_url_raw( wp_unslash( $_POST['lf_event_registration_url'] ?? '' ) ) );
		update_post_meta( $post_id, 'lf_event_registration_required', ! empty( $_POST['lf_event_registration_required'] ) ? 1 : 0 );
		if ( isset( $_POST['lf_event_poster_id'] ) && is_scalar( $_POST['lf_event_poster_id'] ) ) {
			$poster_id = absint( $_POST['lf_event_poster_id'] );
			if ( ! $poster_id ) {
				delete_post_thumbnail( $post_id );
			} elseif ( wp_attachment_is_image( $poster_id ) && current_user_can( 'read_post', $poster_id ) ) {
				set_post_thumbnail( $post_id, $poster_id );
			}
		}

		$this->assign_league_level_from_request( $post_id );
		Taxonomies::clear_editor_event_context( $post_id );
	}

	/** Strict site-local calendar input; rejects impossible dates and DST gaps. */
	protected function is_valid_local_datetime( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $value ) ) { return false; }
		$format = strlen( $value ) > 16 ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
		$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date && ( ! $errors || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $date->format( $format ) === $value;
	}

	/**
	 * Apply the Calendar Event Details sport choice.
	 *
	 * An event may move to another enabled sport. Its current sport is kept
	 * when nothing valid is chosen, even if that sport is no longer enabled.
	 *
	 * @param int   $post_id Event ID.
	 * @param array $context Validated original and candidate assignments.
	 * @return true|\WP_Error
	 */
	protected function save_calendar_event_sport_choice( $post_id, $context ) {
		$save_error = new \WP_Error( 'leagueflow_event_context_not_saved', __( 'The event sport and context could not be saved together. Their previous assignments were kept; please try again.', 'leagueflow' ) );
		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) { return $save_error; }
			$ids = array_map( 'intval', $ids );
			sort( $ids );
			if ( $ids !== $context['candidate'][$taxonomy] ) { return $save_error; }
		}
		$sport_ids = $context['candidate']['lf_sport'];
		if ( $sport_ids !== $context['existing']['lf_sport'] ) {
			$result = wp_set_object_terms( $post_id, $sport_ids, 'lf_sport', false );
			if ( is_wp_error( $result ) || ! object_term_saved( $post_id, $sport_ids[0], 'lf_sport' ) ) { return $save_error; }
		} elseif ( ! $sport_ids && isset( $_POST['lf_sport_choice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by can_save().
			$this->queue_notice( __( 'Choose a sport for this calendar event so it appears in that sport\'s calendar.', 'leagueflow' ), 'error' );
		}
		return true;
	}

	/**
	 * Register custom list table columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function team_columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => __( 'Team', 'leagueflow' ),
			'sport'        => __( 'Sport', 'leagueflow' ),
			'league_level' => __( 'Level', 'leagueflow' ),
			'short_name'   => __( 'Short Name', 'leagueflow' ),
			'city'         => __( 'City', 'leagueflow' ),
			'coach'        => __( 'Coach', 'leagueflow' ),
			'founded_year' => __( 'Founded', 'leagueflow' ),
			'date'         => $columns['date'],
		);
	}

	/**
	 * Player list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function player_columns( $columns ) {
		return array(
			'cb'            => $columns['cb'],
			'title'         => __( 'Player', 'leagueflow' ),
			'sport'         => __( 'Sport', 'leagueflow' ),
			'league_level'  => __( 'Level', 'leagueflow' ),
			'team'          => __( 'Team', 'leagueflow' ),
			'jersey_number' => __( 'No.', 'leagueflow' ),
			'position'      => __( 'Position', 'leagueflow' ),
			'nationality'   => __( 'Nationality', 'leagueflow' ),
			'captain'       => __( 'Captain', 'leagueflow' ),
			'date'          => $columns['date'],
		);
	}

	/**
	 * Match list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function match_columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => __( 'Fixture', 'leagueflow' ),
			'sport'       => __( 'Sport', 'leagueflow' ),
			'league_level' => __( 'Level', 'leagueflow' ),
			'competition' => __( 'Competition', 'leagueflow' ),
			'season'      => __( 'Season', 'leagueflow' ),
			'match_time'  => __( 'Date / Time', 'leagueflow' ),
			'venue'       => __( 'Venue', 'leagueflow' ),
			'score'       => __( 'Score', 'leagueflow' ),
			'status'      => __( 'Status', 'leagueflow' ),
			'knockout'    => __( 'Knockout', 'leagueflow' ),
			'date'        => $columns['date'],
		);
	}

	/**
	 * Calendar event list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function calendar_event_columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => __( 'Event', 'leagueflow' ),
			'sport'       => __( 'Sport', 'leagueflow' ),
			'league_level' => __( 'Level', 'leagueflow' ),
			'event_type'  => __( 'Type', 'leagueflow' ),
			'event_time'  => __( 'Date / Time', 'leagueflow' ),
			'venue'       => __( 'Venue', 'leagueflow' ),
			'status'      => __( 'Status', 'leagueflow' ),
			'registration' => __( 'Registration', 'leagueflow' ),
			'date'        => $columns['date'],
		);
	}

	/**
	 * Join request list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function join_request_columns( $columns ) {
		return array(
			'cb'     => $columns['cb'],
			'title'  => __( 'Request', 'leagueflow' ),
			'player' => __( 'Player', 'leagueflow' ),
			'team'   => __( 'Team', 'leagueflow' ),
			'sport'  => __( 'Sport', 'leagueflow' ),
			'level'  => __( 'Level', 'leagueflow' ),
			'status' => __( 'Status', 'leagueflow' ),
			'date'   => $columns['date'],
		);
	}

	/**
	 * Render team custom column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_team_column( $column, $post_id ) {
		switch ( $column ) {
			case 'sport':
				echo esc_html( $this->sports_manager->get_post_sport_label( $post_id ) );
				break;
			case 'league_level':
				echo esc_html( get_post_league_level_label( $post_id ) );
				break;
			case 'short_name':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_short_name', true ) );
				break;
			case 'city':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_city', true ) );
				break;
			case 'coach':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_coach', true ) );
				break;
			case 'founded_year':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_founded_year', true ) );
				break;
		}
	}

	/**
	 * Render player custom column.
	 *
	 * With a sport selected, team, number, position and captain cells show only
	 * that sport's memberships, and Sport/Level show that sport's values, so a
	 * multi-sport player never shows another sport's team in this list.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_player_column( $column, $post_id ) {
		$context  = $this->get_context_sport_slug();
		$team_ids = get_player_team_ids( $post_id );

		if ( $context ) {
			$team_ids = array_values(
				array_filter(
					$team_ids,
					static function ( $team_id ) use ( $context ) {
						return get_post_primary_term_slug( $team_id, 'lf_sport' ) === $context;
					}
				)
			);
		}

		$prefix = static function ( $team_id, $value ) use ( $team_ids ) {
			return count( $team_ids ) > 1 ? get_the_title( $team_id ) . ': ' . $value : (string) $value;
		};

		switch ( $column ) {
			case 'sport':
				if ( ! $context ) {
					echo esc_html( implode( ', ', wp_get_post_terms( $post_id, 'lf_sport', array( 'fields' => 'names' ) ) ) );
					break;
				}

				$others = array_diff( wp_get_post_terms( $post_id, 'lf_sport', array( 'fields' => 'slugs' ) ), array( $context ) );
				echo esc_html( $this->sports_manager->get_definition( $context )['label'] );

				if ( $others ) {
					/* translators: %s: other sports */
					echo ' <span class="description">' . esc_html( sprintf( __( '(also %s)', 'leagueflow' ), implode( ', ', array_map( array( $this, 'get_sport_label_for_slug' ), $others ) ) ) ) . '</span>';
				}
				break;
			case 'league_level':
				$levels = get_post_meta( $post_id, 'lf_player_sport_levels', true );
				$level  = $context && is_array( $levels ) && ! empty( $levels[ $context ] ) ? get_term( absint( $levels[ $context ] ), 'lf_league_level' ) : null;
				echo esc_html( $level instanceof \WP_Term ? $level->name : get_post_league_level_label( $post_id ) );
				break;
			case 'team':
				$team_names = array_filter( array_map( 'get_the_title', $team_ids ) );
				echo ! empty( $team_names ) ? esc_html( implode( ', ', $team_names ) ) : '&mdash;';
				break;
			case 'jersey_number':
			case 'position':
				$values = array();
				$key    = 'jersey_number' === $column ? 'jersey_number' : 'position';
				foreach ( $team_ids as $team_id ) {
					$detail = get_player_team_detail( $post_id, $team_id );
					if ( '' !== (string) $detail[ $key ] ) {
						$values[] = $prefix( $team_id, $detail[ $key ] );
					}
				}
				echo ! empty( $values ) ? esc_html( implode( ', ', $values ) ) : '&mdash;';
				break;
			case 'nationality':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_nationality', true ) );
				break;
			case 'captain':
				$captain_teams = array();
				foreach ( $team_ids as $team_id ) {
					if ( ! empty( get_player_team_detail( $post_id, $team_id )['is_captain'] ) ) {
						$captain_teams[] = get_the_title( $team_id );
					}
				}
				echo ! empty( $captain_teams ) ? esc_html( implode( ', ', $captain_teams ) ) : '&mdash;';
				break;
		}
	}

	/**
	 * Sport label for a slug.
	 *
	 * @param string $slug Sport slug.
	 * @return string
	 */
	public function get_sport_label_for_slug( $slug ) {
		$definitions = Sports_Manager::get_definitions();

		return isset( $definitions[ $slug ] ) ? $definitions[ $slug ]['label'] : $slug;
	}

	/**
	 * Render join request custom column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_join_request_column( $column, $post_id ) {
		switch ( $column ) {
			case 'player':
				$player_id = (int) get_post_meta( $post_id, 'lf_player_id', true );
				echo $player_id ? esc_html( get_the_title( $player_id ) ) : '&mdash;';
				break;
			case 'team':
				$team_id = (int) get_post_meta( $post_id, 'lf_team_id', true );
				echo $team_id ? esc_html( get_the_title( $team_id ) ) : esc_html__( 'Needs placement', 'leagueflow' );
				break;
			case 'sport':
				$sport_slug = sanitize_key( (string) get_post_meta( $post_id, 'lf_sport_slug', true ) );
				$sport      = $sport_slug ? $this->sports_manager->get_definition( $sport_slug ) : array();
				echo ! empty( $sport['label'] ) ? esc_html( $sport['label'] ) : '&mdash;';
				break;
			case 'level':
				$level_id = get_join_request_level_id( $post_id );
				$level    = $level_id ? get_term( $level_id, 'lf_league_level' ) : null;
				echo $level && ! is_wp_error( $level ) ? esc_html( $level->name ) : esc_html__( 'Not specified', 'leagueflow' );
				break;
			case 'status':
				$status = sanitize_key( (string) get_post_meta( $post_id, 'lf_request_status', true ) );
				echo esc_html( $status ? ucfirst( $status ) : __( 'Pending', 'leagueflow' ) );
				break;
		}
	}

	/**
	 * Render match custom column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_match_column( $column, $post_id ) {
		switch ( $column ) {
			case 'sport':
				echo esc_html( $this->sports_manager->get_post_sport_label( $post_id ) );
				break;
			case 'league_level':
				echo esc_html( get_post_league_level_label( $post_id ) );
				break;
			case 'competition':
				echo esc_html( implode( ', ', wp_get_post_terms( $post_id, 'lf_competition', array( 'fields' => 'names' ) ) ) );
				break;
			case 'season':
				echo esc_html( implode( ', ', wp_get_post_terms( $post_id, 'lf_season', array( 'fields' => 'names' ) ) ) );
				break;
			case 'match_time':
				$this->render_schedule_cell( (string) get_post_meta( $post_id, 'lf_match_datetime', true ) );
				break;
			case 'venue':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_venue', true ) );
				break;
			case 'score':
				$outcome = (string) get_post_meta( $post_id, 'lf_outcome', true );
				if ( '' !== $outcome ) {
					$label = Match_Status::outcome_label( $outcome );
					echo $label ? esc_html( $label ) : '&mdash;';
					break;
				}
				$home = get_post_meta( $post_id, 'lf_home_score', true );
				$away = get_post_meta( $post_id, 'lf_away_score', true );
				echo Match_Status::is_valid_score( $home ) && Match_Status::is_valid_score( $away ) ? esc_html( score_to_int( $home ) . ' - ' . score_to_int( $away ) ) : '&mdash;';
				break;
			case 'status':
				$status = (string) get_post_meta( $post_id, 'lf_status', true );
				echo esc_html( $this->statuses[ $status ] ?? ucfirst( $status ) );
				break;
			case 'knockout':
				echo (bool) get_post_meta( $post_id, 'lf_is_knockout', true ) ? esc_html__( 'Yes', 'leagueflow' ) : '&mdash;';
				break;
		}
	}

	/**
	 * Render a date cell, labelling items that have no date yet.
	 *
	 * @param string $datetime Saved wall-clock date.
	 * @return void
	 */
	protected function render_schedule_cell( $datetime ) {
		$label = '' !== trim( $datetime ) ? format_match_datetime( $datetime ) : '';

		if ( '' === $label ) {
			echo '<span class="leagueflow-unscheduled">' . esc_html__( 'Unscheduled', 'leagueflow' ) . '</span>';
			return;
		}

		echo esc_html( $label );
	}

	/**
	 * Render calendar event custom column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_calendar_event_column( $column, $post_id ) {
		switch ( $column ) {
			case 'sport':
				echo esc_html( $this->sports_manager->get_post_sport_label( $post_id ) );
				break;
			case 'league_level':
				echo esc_html( get_post_league_level_label( $post_id ) );
				break;
			case 'event_type':
				$type = (string) get_post_meta( $post_id, 'lf_event_type', true );
				echo esc_html( $this->calendar_event_types[ $type ] ?? __( 'Drop-in', 'leagueflow' ) );
				break;
			case 'event_time':
				$this->render_schedule_cell( (string) get_post_meta( $post_id, 'lf_event_start_datetime', true ) );
				break;
			case 'venue':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_event_venue', true ) );
				break;
			case 'status':
				$status = (string) get_post_meta( $post_id, 'lf_event_status', true );
				echo esc_html( $this->statuses[ $status ] ?? ucfirst( $status ) );
				break;
			case 'registration':
				$required = (bool) get_post_meta( $post_id, 'lf_event_registration_required', true );
				$url      = (string) get_post_meta( $post_id, 'lf_event_registration_url', true );

				if ( $url ) {
					printf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $url ), esc_html__( 'Link', 'leagueflow' ) );
				} elseif ( $required ) {
					esc_html_e( 'Required', 'leagueflow' );
				} else {
					echo '&mdash;';
				}
				break;
		}
	}

	/**
	 * Team sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function team_sortable_columns( $columns ) {
		$columns['short_name']   = 'short_name';
		$columns['city']         = 'city';
		$columns['coach']        = 'coach';
		$columns['founded_year'] = 'founded_year';
		return $columns;
	}

	/**
	 * Player sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function player_sortable_columns( $columns ) {
		$columns['jersey_number'] = 'jersey_number';
		$columns['position']      = 'position';
		return $columns;
	}

	/**
	 * Match sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function match_sortable_columns( $columns ) {
		$columns['match_time'] = array( 'match_time', true, __( 'Date / Time', 'leagueflow' ), __( 'Table ordered by match date, unscheduled first.', 'leagueflow' ), 'desc' );
		$columns['status']     = 'status';

		return $this->drop_initial_publish_date_sort( $columns );
	}

	/**
	 * Lists default to the schedule date, so the publish Date column must not
	 * also claim to be the initial sort.
	 *
	 * @param array<string, mixed> $columns Sortable columns.
	 * @return array<string, mixed>
	 */
	protected function drop_initial_publish_date_sort( $columns ) {
		if ( isset( $columns['date'] ) && is_array( $columns['date'] ) ) {
			$columns['date'] = array_slice( array_values( $columns['date'] ), 0, 4 );
		}

		return $columns;
	}

	/**
	 * Calendar event sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function calendar_event_sortable_columns( $columns ) {
		$columns['event_time'] = array( 'event_time', true, __( 'Date / Time', 'leagueflow' ), __( 'Table ordered by event date, unscheduled first.', 'leagueflow' ), 'desc' );
		$columns['event_type'] = 'event_type';
		$columns['status']     = 'status';

		return $this->drop_initial_publish_date_sort( $columns );
	}

	/**
	 * Render sport filter dropdown on LeagueFlow list tables.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $which Filter location.
	 * @return void
	 */
	public function render_admin_sport_filter( $post_type, $which = 'top' ) {
		if ( 'top' !== $which || ! is_string( $post_type ) || ! in_array( $post_type, $this->get_sport_filterable_post_types(), true ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'lf_sport',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}

		$selected       = $this->get_requested_sport_slug();
		$field_id       = 'leagueflow-filter-by-sport-' . sanitize_key( (string) $which );
		$level_selected = $this->get_requested_league_level_slug();
		$level_field_id = 'leagueflow-filter-by-level-' . sanitize_key( (string) $which );
		?>
		<label for="<?php echo esc_attr( $field_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Filter by sport', 'leagueflow' ); ?></label>
		<select name="lf_sport" id="<?php echo esc_attr( $field_id ); ?>">
			<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $selected, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="<?php echo esc_attr( $level_field_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Filter by league level', 'leagueflow' ); ?></label>
		<select name="lf_league_level" id="<?php echo esc_attr( $level_field_id ); ?>">
			<option value=""><?php esc_html_e( 'All levels', 'leagueflow' ); ?></option>
			<?php foreach ( $this->get_league_level_terms() as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $level_selected, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		if ( 'lf_match' === $post_type ) {
			$match_status = $this->get_requested_match_status();
			?>
			<label for="leagueflow-filter-match-status" class="screen-reader-text"><?php esc_html_e( 'Filter by match status', 'leagueflow' ); ?></label>
			<select name="lf_match_status" id="leagueflow-filter-match-status">
				<option value=""><?php esc_html_e( 'All match statuses', 'leagueflow' ); ?></option>
				<?php foreach ( Match_Status::statuses() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $match_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		}
		// Teams and matches can also be filtered by competition and season (players have neither:
		// they belong to teams, so their list keeps sport and level filters only).
		if ( ! in_array( $post_type, array( 'lf_team', 'lf_match' ), true ) ) {
			if ( 'lf_player' === $post_type && 'top' === $which ) {
				// Say why there is no competition/season filter instead of offering one that would always be empty.
				echo '<span class="description leagueflow-scope-note">' . esc_html__( 'Players have no competition or season of their own; they belong to teams. Filter Teams by competition or season instead.', 'leagueflow' ) . '</span>';
			}

			return;
		}

		$labels = array(
			'lf_competition' => array( __( 'Filter by competition', 'leagueflow' ), __( 'All competitions', 'leagueflow' ), __( 'No competition', 'leagueflow' ) ),
			'lf_season'      => array( __( 'Filter by season', 'leagueflow' ), __( 'All seasons', 'leagueflow' ), __( 'No season', 'leagueflow' ) ),
		);

		foreach ( $labels as $taxonomy => $text ) {
			$param    = $taxonomy . '_filter';
			$current  = $this->get_list_term_filter( $taxonomy );
			$options  = $this->get_sport_labelled_term_options( $taxonomy, $selected );
			$field_id = 'leagueflow-filter-' . $taxonomy . '-' . sanitize_key( (string) $which );
			?>
			<label for="<?php echo esc_attr( $field_id ); ?>" class="screen-reader-text"><?php echo esc_html( $text[0] ); ?></label>
			<select name="<?php echo esc_attr( $param ); ?>" id="<?php echo esc_attr( $field_id ); ?>">
				<option value=""><?php echo esc_html( $text[1] ); ?></option>
				<option value="none" <?php selected( $current, 'none' ); ?>><?php echo esc_html( $text[2] ); ?></option>
				<?php foreach ( $options as $term_id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $term_id ); ?>" <?php selected( $current, (string) $term_id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		}
	}

	/**
	 * Requested competition/season list filter: '' (all), 'none', or a term ID.
	 *
	 * @param string $taxonomy lf_competition or lf_season.
	 * @return string
	 */
	protected function get_list_term_filter( $taxonomy ) {
		$input = wp_unslash( $_GET[ $taxonomy . '_filter' ] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw   = is_scalar( $input ) ? sanitize_key( (string) $input ) : '';

		if ( 'none' === $raw ) {
			return 'none';
		}

		return absint( $raw ) && get_term( absint( $raw ), $taxonomy ) instanceof \WP_Term ? (string) absint( $raw ) : '';
	}

	/** Match lifecycle filter, separate from WordPress publication status. */
	protected function get_requested_match_status() {
		$input = wp_unslash( $_GET['lf_match_status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = is_scalar( $input ) ? sanitize_key( (string) $input ) : '';

		return isset( Match_Status::statuses()[ $value ] ) ? $value : '';
	}

	/** Filters shared by the visible list, its status counts and view links. */
	protected function get_list_filter_context( $post_type ) {
		$context = array_filter( array(
			'lf_sport' => $this->get_requested_sport_slug(),
			'lf_league_level' => $this->get_requested_league_level_slug(),
		) );
		if ( in_array( $post_type, array( 'lf_team', 'lf_match' ), true ) ) {
			foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
				$value = $this->get_list_term_filter( $taxonomy );
				if ( '' !== $value ) { $context[ $taxonomy . '_filter' ] = $value; }
			}
		}
		if ( 'lf_match' === $post_type ) {
			if ( $this->get_requested_match_status() ) { $context['lf_match_status'] = $this->get_requested_match_status(); }
			$batch = wp_unslash( $_GET['lf_batch'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $batch ) && '' !== (string) $batch ) { $context['lf_batch'] = sanitize_key( (string) $batch ); }
		}
		$search = wp_unslash( $_GET['s'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_scalar( $search ) && '' !== trim( (string) $search ) ) { $context['s'] = sanitize_text_field( (string) $search ); }

		return $context;
	}

	/**
	 * Apply admin sorting to supported columns.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function handle_admin_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}

		$post_type  = is_string( $post_type ) ? $post_type : '';
		$orderby            = (string) $query->get( 'orderby' );
		$sport_slug         = $this->get_requested_sport_slug();
		$league_level_slug  = $this->get_requested_league_level_slug();

		if ( $sport_slug && in_array( $post_type, $this->get_taxonomy_sport_filterable_post_types(), true ) ) {
			$tax_query = $query->get( 'tax_query' );

			if ( ! is_array( $tax_query ) ) {
				$tax_query = array();
			}

			$tax_query[] = array(
				'taxonomy' => 'lf_sport',
				'field'    => 'slug',
				'terms'    => array( $sport_slug ),
			);
			$query->set( 'tax_query', $tax_query );
		}

		if ( $league_level_slug && in_array( $post_type, $this->get_taxonomy_sport_filterable_post_types(), true ) ) {
			$tax_query = $query->get( 'tax_query' );

			if ( ! is_array( $tax_query ) ) {
				$tax_query = array();
			}

			$tax_query[] = array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'slug',
				'terms'    => array( $league_level_slug ),
			);
			$query->set( 'tax_query', $tax_query );
		}

		if ( 'lf_join_request' === $post_type ) {
			// Join requests store sport and level in meta, not taxonomies. WordPress still
			// registers the lf_sport/lf_league_level query vars in admin, so clear them to
			// stop core adding a taxonomy clause no join request can match.
			$query->set( 'lf_sport', '' );
			$query->set( 'lf_league_level', '' );

			$meta_query = $query->get( 'meta_query' );

			if ( ! is_array( $meta_query ) ) {
				$meta_query = array();
			}

			if ( $sport_slug ) {
				$meta_query[] = array(
					'key'   => 'lf_sport_slug',
					'value' => $sport_slug,
				);
			}

			if ( $league_level_slug ) {
				$level = get_term_by( 'slug', $league_level_slug, 'lf_league_level' );

				$meta_query[] = array(
					'key'     => 'lf_league_level_id',
					'value'   => $level instanceof \WP_Term ? $level->term_id : 0,
					'compare' => '=',
					'type'    => 'NUMERIC',
				);
			}

			if ( $meta_query ) {
				$query->set( 'meta_query', $meta_query );
			}
		}

		$schedule_keys = array(
			'lf_match'          => 'lf_match_datetime',
			'lf_calendar_event' => 'lf_event_start_datetime',
		);

		if ( isset( $schedule_keys[ $post_type ] ) && 'unscheduled' === $this->get_requested_schedule_view() ) {
			$meta_query = $query->get( 'meta_query' );
			$meta_query = is_array( $meta_query ) ? $meta_query : array();

			$meta_query[] = $this->get_unscheduled_meta_clause( $schedule_keys[ $post_type ] );
			$query->set( 'meta_query', $meta_query );
		}

		if ( in_array( $post_type, array( 'lf_team', 'lf_match' ), true ) ) {
			foreach ( array( 'lf_competition', 'lf_season' ) as $filter_taxonomy ) {
				$filter = $this->get_list_term_filter( $filter_taxonomy );

				if ( '' === $filter ) {
					continue;
				}

				$tax_query   = $query->get( 'tax_query' );
				$tax_query   = is_array( $tax_query ) ? $tax_query : array();
				$tax_query[] = 'none' === $filter
					? array( 'taxonomy' => $filter_taxonomy, 'operator' => 'NOT EXISTS' )
					: array( 'taxonomy' => $filter_taxonomy, 'field' => 'term_id', 'terms' => array( (int) $filter ) );
				$query->set( 'tax_query', $tax_query );
			}
		}

		// Matches created by one "Generate fixtures" run (link from its result notice).
		$batch = sanitize_key( wp_unslash( $_GET['lf_batch'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'lf_match' === $post_type && '' !== $batch ) {
			$meta_query   = $query->get( 'meta_query' );
			$meta_query   = is_array( $meta_query ) ? $meta_query : array();
			$meta_query[] = array(
				'key'   => Fixture_Generator::META_BATCH,
				'value' => $batch,
			);
			$query->set( 'meta_query', $meta_query );
		}

		if ( 'lf_match' === $post_type && $this->get_requested_match_status() ) {
			$meta_query = $query->get( 'meta_query' );
			$meta_query = is_array( $meta_query ) ? $meta_query : array();
			$meta_query[] = array( 'key' => 'lf_status', 'value' => $this->get_requested_match_status() );
			$query->set( 'meta_query', $meta_query );
		}

		// Default order: unscheduled items first (they need attention), then newest date.
		// A LEFT JOIN keeps rows without the date key; a meta_key sort would drop them.
		if ( isset( $schedule_keys[ $post_type ] ) && empty( $orderby ) ) {
			$query->set(
				'leagueflow_admin_sort',
				array(
					'key'         => $schedule_keys[ $post_type ],
					'order'       => 'DESC',
					'numeric'     => false,
					'empty_first' => true,
				)
			);
		}

		$map = array(
			'lf_team' => array(
				'short_name'   => 'lf_short_name',
				'city'         => 'lf_city',
				'coach'        => 'lf_coach',
				'founded_year' => 'lf_founded_year',
			),
			'lf_player' => array(
				'jersey_number' => 'lf_jersey_number',
				'position'      => 'lf_position',
			),
			'lf_match' => array(
				'match_time' => 'lf_match_datetime',
				'status'     => 'lf_status',
			),
			'lf_calendar_event' => array(
				'event_time' => 'lf_event_start_datetime',
				'event_type' => 'lf_event_type',
				'status'     => 'lf_event_status',
			),
		);

		if ( isset( $map[ $post_type ][ $orderby ] ) ) {
			$order = strtoupper( (string) $query->get( 'order' ) );

			$query->set(
				'leagueflow_admin_sort',
				array(
					'key'         => $map[ $post_type ][ $orderby ],
					'order'       => 'ASC' === $order ? 'ASC' : 'DESC',
					'numeric'     => in_array( $orderby, array( 'founded_year', 'jersey_number' ), true ),
					'empty_first' => false,
				)
			);
		}
	}

	/**
	 * Sort an admin list by one meta value without dropping posts that lack it.
	 *
	 * Core's meta_key ordering adds an INNER JOIN, which hides every post
	 * without the key: undated generated drafts and trashed matches vanished
	 * from Matches while the status counts still included them.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query Query.
	 * @return array<string, string>
	 */
	public function filter_admin_sort_clauses( $clauses, $query ) {
		global $wpdb;

		$sort = $query instanceof \WP_Query ? $query->get( 'leagueflow_admin_sort' ) : null;

		if ( ! is_array( $sort ) || empty( $sort['key'] ) ) {
			return $clauses;
		}

		$order = 'ASC' === ( $sort['order'] ?? '' ) ? 'ASC' : 'DESC';
		$value = ! empty( $sort['numeric'] ) ? 'CAST(lf_sort.sort_value AS SIGNED)' : 'lf_sort.sort_value';
		$empty = "(lf_sort.sort_value IS NULL OR lf_sort.sort_value = '')";

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN ( SELECT post_id, MAX(meta_value) AS sort_value FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY post_id ) AS lf_sort ON lf_sort.post_id = {$wpdb->posts}.ID",
			(string) $sort['key']
		);
		$clauses['orderby'] = sprintf(
			'%1$s %2$s, %3$s %4$s, %5$s.ID %4$s',
			$empty,
			! empty( $sort['empty_first'] ) ? 'DESC' : 'ASC',
			$value,
			$order,
			$wpdb->posts
		);

		return $clauses;
	}

	/**
	 * Meta clause matching posts with no saved date.
	 *
	 * @param string $key Date meta key.
	 * @return array<string, mixed>
	 */
	protected function get_unscheduled_meta_clause( $key ) {
		return array(
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $key,
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	/**
	 * Requested schedule view on Matches/Calendar Events lists.
	 *
	 * @return string
	 */
	protected function get_requested_schedule_view() {
		return 'unscheduled' === sanitize_key( wp_unslash( $_GET['lf_schedule'] ?? '' ) ) ? 'unscheduled' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Keep list-table status views in the selected sport/level context.
	 *
	 * Core counts every sport (Teams showed "All (46)" over a 12-team Soccer
	 * list) and its links drop the sport filter. Inside a sport context the
	 * counts are recomputed with the same filters the list uses.
	 *
	 * @param array<string, string> $views View links.
	 * @return array<string, string>
	 */
	public function filter_list_views( $views ) {
		$post_type = $this->get_current_admin_post_type();

		if ( ! $post_type ) {
			return $views;
		}

		$context     = $this->get_list_filter_context( $post_type );
		$base_url    = add_query_arg( array_merge( array( 'post_type' => $post_type ), $context ), admin_url( 'edit.php' ) );
		$current     = sanitize_key( wp_unslash( $_GET['post_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$schedule    = $this->get_requested_schedule_view();
		$schedule_on = in_array( $post_type, array( 'lf_match', 'lf_calendar_event' ), true );

		if ( $context ) {
			$rebuilt = array();
			$all     = $this->count_list_posts( $post_type, 'all' );

			$rebuilt['all'] = $this->format_list_view( $base_url, __( 'All', 'leagueflow' ), $all, '' === $current && '' === $schedule && empty( $_GET['author'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// Core only offers "Mine" when it applies to this user; keep it, counted in context.
			if ( isset( $views['mine'] ) ) {
				$mine = $this->count_list_posts( $post_type, 'all', array(), get_current_user_id() );

				if ( $mine ) {
					$rebuilt['mine'] = $this->format_list_view( add_query_arg( 'author', get_current_user_id(), $base_url ), __( 'Mine', 'leagueflow' ), $mine, get_current_user_id() === absint( $_GET['author'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}

			foreach ( get_post_stati( array( 'show_in_admin_status_list' => true ), 'objects' ) as $status => $object ) {
				$count = $this->count_list_posts( $post_type, $status );

				if ( ! $count ) {
					continue;
				}

				$label = is_array( $object->label_count ) ? translate_nooped_plural( $object->label_count, $count ) : $object->label;
				$label = trim( preg_replace( '/\s*<span class="count">.*$/s', '', sprintf( $label, number_format_i18n( $count ) ) ) );

				$rebuilt[ $status ] = $this->format_list_view( add_query_arg( 'post_status', $status, $base_url ), $label, $count, $current === $status );
			}

			$views = $rebuilt;
		}

		if ( $schedule_on ) {
			$key   = 'lf_match' === $post_type ? 'lf_match_datetime' : 'lf_event_start_datetime';
			$count = $this->count_list_posts( $post_type, 'all', $this->get_unscheduled_meta_clause( $key ) );

			if ( $count || 'unscheduled' === $schedule ) {
				$views['lf_unscheduled'] = $this->format_list_view( add_query_arg( 'lf_schedule', 'unscheduled', $base_url ), __( 'Unscheduled', 'leagueflow' ), $count, 'unscheduled' === $schedule && '' === $current );
			}
		}

		return $views;
	}

	/**
	 * Format one list-table view link.
	 *
	 * @param string $url Link URL.
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @param bool   $current Whether this view is selected.
	 * @return string
	 */
	protected function format_list_view( $url, $label, $count, $current ) {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Count list posts in the requested sport/level context.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $status Post status, or "all" for the All view.
	 * @param array<string, mixed> $extra_meta Optional extra meta clause.
	 * @param int                  $author Optional author ID (the "Mine" view).
	 * @return int
	 */
	protected function count_list_posts( $post_type, $status, $extra_meta = array(), $author = 0 ) {
		$args = array(
			'post_type'              => $post_type,
			'post_status'            => 'all' === $status ? array_values( get_post_stati( array( 'show_in_admin_all_list' => true ) ) ) : $status,
			// Same visibility as the core list and wp_count_posts( 'readable' ): other
			// users' private records only count for users who may read them.
			'perm'                   => 'readable',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
			'tax_query'              => array(),
			'meta_query'             => array(),
		);

		$context    = $this->get_list_filter_context( $post_type );
		$sport_slug = $context['lf_sport'] ?? '';
		$level_slug = $context['lf_league_level'] ?? '';

		if ( 'lf_join_request' === $post_type ) {
			if ( $sport_slug ) {
				$args['meta_query'][] = array(
					'key'   => 'lf_sport_slug',
					'value' => $sport_slug,
				);
			}

			if ( $level_slug ) {
				$level                = get_term_by( 'slug', $level_slug, 'lf_league_level' );
				$args['meta_query'][] = array(
					'key'     => 'lf_league_level_id',
					'value'   => $level instanceof \WP_Term ? $level->term_id : 0,
					'compare' => '=',
					'type'    => 'NUMERIC',
				);
			}
		} else {
			foreach ( array( 'lf_sport' => $sport_slug, 'lf_league_level' => $level_slug ) as $taxonomy => $slug ) {
				if ( $slug ) {
					$args['tax_query'][] = array(
						'taxonomy' => $taxonomy,
						'field'    => 'slug',
						'terms'    => array( $slug ),
					);
				}
			}
		}

		if ( $extra_meta ) {
			$args['meta_query'][] = $extra_meta;
		}

		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$filter = $context[ $taxonomy . '_filter' ] ?? '';
			if ( '' !== $filter ) {
				$args['tax_query'][] = 'none' === $filter
					? array( 'taxonomy' => $taxonomy, 'operator' => 'NOT EXISTS' )
					: array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array( (int) $filter ) );
			}
		}
		foreach ( array( 'lf_match_status' => 'lf_status', 'lf_batch' => Fixture_Generator::META_BATCH ) as $parameter => $meta_key ) {
			if ( ! empty( $context[ $parameter ] ) ) {
				$args['meta_query'][] = array( 'key' => $meta_key, 'value' => $context[ $parameter ] );
			}
		}
		if ( isset( $context['s'] ) ) { $args['s'] = $context['s']; }

		if ( $author ) {
			$args['author'] = (int) $author;
		}

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Limit competition and season term lists to the selected sport context.
	 *
	 * @param array<string, mixed> $args Taxonomy query args.
	 * @param array<int, string>   $taxonomies Taxonomies being queried.
	 * @return array<string, mixed>
	 */
	public function filter_admin_terms_by_sport( $args, $taxonomies ) {
		if ( ! is_admin() || 'edit-tags.php' !== $this->get_current_admin_file() ) {
			return $args;
		}

		$taxonomies = array_map( 'sanitize_key', (array) $taxonomies );

		$sport_scoped_taxonomies = array( 'lf_competition', 'lf_season' );

		if ( ! array_intersect( $sport_scoped_taxonomies, $taxonomies ) ) {
			return $args;
		}

		$sport_slug = $this->get_requested_sport_slug();

		if ( ! $this->is_enabled_sport_slug( $sport_slug ) ) {
			return $args;
		}

		if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}

		$args['meta_query'][] = array(
			'key'   => 'lf_sport_slug',
			'value' => $sport_slug,
		);

		return $args;
	}

	/**
	 * Register match bulk actions.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function register_match_bulk_actions( $actions ) {
		$actions['leagueflow_auto_schedule'] = __( 'Auto schedule from field availability', 'leagueflow' );

		foreach ( $this->statuses as $status => $label ) {
			$actions[ 'leagueflow_set_status_' . $status ] = sprintf(
				/* translators: %s: status label */
				__( 'Set status to %s', 'leagueflow' ),
				$label
			);
		}

		return $actions;
	}

	/**
	 * Handle match bulk actions.
	 *
	 * @param string   $redirect_to Redirect URL.
	 * @param string   $doaction Action.
	 * @param int[]    $post_ids IDs.
	 * @return string
	 */
	public function handle_match_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( 'leagueflow_auto_schedule' === $doaction ) {
			$result = $this->field_availability_manager->schedule_matches(
				array(
					'match_ids'  => array_map( 'absint', (array) $post_ids ),
					'sport_slug' => $this->get_requested_sport_slug(),
					'mode'       => 'both',
				)
			);

			$this->store_schedule_result( $result );

			return add_query_arg( 'leagueflow_schedule_complete', 1, $redirect_to );
		}
		$post_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $post_ids ) ), static function( $post_id ) {
			return 'lf_match' === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id );
		} ) );
		if ( empty( $post_ids ) ) {
			return $redirect_to;
		}

		if ( 0 !== strpos( $doaction, 'leagueflow_set_status_' ) ) {
			return $redirect_to;
		}

		$status = str_replace( 'leagueflow_set_status_', '', $doaction );

		if ( ! isset( $this->statuses[ $status ] ) ) {
			return $redirect_to;
		}

		$updated = 0;
		$skipped = 0;
		foreach ( $post_ids as $post_id ) {
			$lock = db_lock_name( 'result', (string) $post_id );
			if ( ! acquire_db_lock( $lock, 0 ) ) { ++$skipped; continue; }
			$saved = false;
			try {
				clean_post_cache( $post_id );
				if ( 'lf_match' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { ++$skipped; continue; }
				if ( 'finished' === $status ) {
					$home = get_post_meta( $post_id, 'lf_home_score', true );
					$away = get_post_meta( $post_id, 'lf_away_score', true );
					$outcome = get_post_meta( $post_id, 'lf_outcome', true );
					$forfeit = is_string( $outcome ) && in_array( $outcome, array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true );
					if ( ( ! $forfeit && ( ! Match_Status::is_valid_score( $home ) || ! Match_Status::is_valid_score( $away ) ) )
						|| ( $forfeit && ( ( '' !== $home && ! Match_Status::is_valid_score( $home ) ) || ( '' !== $away && ! Match_Status::is_valid_score( $away ) ) ) ) ) {
						++$skipped;
						continue;
					}
					if ( ! Result_Service::can_finish_context( $post_id ) ) {
						++$skipped;
						continue;
					}
				}
				Match_Status::defer_transitions( $post_id );
				update_post_meta( $post_id, 'lf_status', $status );
				$saved = $status === get_post_meta( $post_id, 'lf_status', true );
				if ( $saved ) { ++$updated; } else { ++$skipped; }
			} finally {
				Match_Status::flush_transitions( $post_id, $saved );
				release_db_lock( $lock );
			}
			if ( $saved ) { do_action( 'leagueflow_match_result_saved', $post_id ); }
		}
		if ( $skipped ) {
			$this->queue_notice( sprintf( __( '%d selected matches were not changed. Check edit access, concurrent updates, both teams and complete valid scores before marking a match Finished.', 'leagueflow' ), $skipped ), 'warning' );
		}

		return add_query_arg(
			array(
				'leagueflow_bulk_updated' => $updated,
				'leagueflow_bulk_status'  => rawurlencode( $this->statuses[ $status ] ),
			),
			$redirect_to
		);
	}

	/**
	 * Force the initial sport setup before any other LeagueFlow admin screen is usable.
	 *
	 * Sends the administrator straight to Sports Setup the first time they enter wp-admin
	 * after activating, and keeps every other LeagueFlow dashboard screen redirecting there
	 * until the sports have been saved.
	 *
	 * @return void
	 */
	public function maybe_force_sport_setup() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$setup_required = $this->sports_manager->is_setup_required();

		// Redirect once, right after activation, from wherever the admin lands.
		if ( $setup_required && get_transient( 'leagueflow_activation_redirect' ) ) {
			delete_transient( 'leagueflow_activation_redirect' );

			// Skip when several plugins were activated in bulk from the plugins screen.
			if ( ! isset( $_GET['activate-multi'] ) && ! $this->is_sport_setup_screen() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_safe_redirect( admin_url( 'admin.php?page=leagueflow-sports' ) );
				exit;
			}
		}

		if ( ! $setup_required ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Only intercept LeagueFlow dashboard screens; never the setup page itself.
		if ( 'leagueflow-sports' === $page || ( 'leagueflow' !== $page && 0 !== strpos( $page, 'leagueflow-' ) ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=leagueflow-sports' ) );
		exit;
	}

	/**
	 * Whether the current request targets the Sports Setup screen.
	 *
	 * @return bool
	 */
	protected function is_sport_setup_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 'leagueflow-sports' === $page;
	}

	/**
	 * Output admin notices.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		$placement_notice = sanitize_key( wp_unslash( $_GET['leagueflow_placement_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $placement_notice ) {
			$placement_messages = array(
				'assigned'         => array( 'success', __( 'Player assigned and request approved.', 'leagueflow' ) ),
				'already-assigned' => array( 'warning', __( 'That player is already assigned to a team in this sport.', 'leagueflow' ) ),
				'mismatch'         => array( 'error', __( 'The selected team no longer matches the request sport and level.', 'leagueflow' ) ),
				'invalid'          => array( 'error', __( 'The placement request could not be completed.', 'leagueflow' ) ),
			);

			if ( isset( $placement_messages[ $placement_notice ] ) ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $placement_messages[ $placement_notice ][0] ),
					esc_html( $placement_messages[ $placement_notice ][1] )
				);
			}
		}

		if ( get_transient( 'leagueflow_registration_mail_warning' ) ) {
			delete_transient( 'leagueflow_registration_mail_warning' );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The LeagueFlow action completed, but at least one notification email could not be sent. Check the site mail configuration.', 'leagueflow' ) . '</p></div>';
		}

		// The setup screen guides the user directly, so the reminder is only useful elsewhere.
		if ( $this->sports_manager->is_setup_required() && current_user_can( 'manage_options' ) && ! $this->is_sport_setup_screen() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'LeagueFlow still needs its initial sport setup. Enable the sports you want before building out league data.', 'leagueflow' ),
				esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ),
				esc_html__( 'Open Sports Setup', 'leagueflow' )
			);
		}

		if ( ! empty( $_GET['leagueflow_bulk_updated'] ) && ! empty( $_GET['leagueflow_bulk_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of matches 2: status label */
						_n( '%1$d match updated to %2$s.', '%1$d matches updated to %2$s.', absint( $_GET['leagueflow_bulk_updated'] ), 'leagueflow' ),
						absint( $_GET['leagueflow_bulk_updated'] ),
						sanitize_text_field( wp_unslash( $_GET['leagueflow_bulk_status'] ) )
					)
				)
			);
		}

		if ( ! empty( $_GET['leagueflow_schedule_complete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$transient_key = 'leagueflow_schedule_result_' . get_current_user_id();
			$result        = get_transient( $transient_key );

			if ( is_array( $result ) ) {
				delete_transient( $transient_key );

				$scheduled = absint( $result['scheduled'] ?? 0 );
				$skipped   = absint( $result['skipped'] ?? 0 );
				$failed    = absint( $result['failed'] ?? 0 );
				$type      = $scheduled > 0 ? 'success' : 'warning';

				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $type ),
					esc_html(
						sprintf(
							/* translators: 1: scheduled count 2: skipped count 3: failed count */
							__( 'Scheduling assistant complete: %1$d scheduled, %2$d skipped, %3$d still unscheduled.', 'leagueflow' ),
							$scheduled,
							$skipped,
							$failed
						)
					)
				);

				if ( ! empty( $result['messages'] ) && is_array( $result['messages'] ) ) {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( implode( ' ', array_slice( array_map( 'sanitize_text_field', $result['messages'] ), 0, 3 ) ) ) . '</p></div>';
				}
			}
		}

		if ( ! empty( $_GET['leagueflow_seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Demo data created successfully.', 'leagueflow' ) . '</p></div>';
		}

		if ( ! empty( $_GET['leagueflow_seed_refused'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$refusals = array(
				'confirm'     => __( 'Demo data was not created. Tick the confirmation box first.', 'leagueflow' ),
				'environment' => __( 'Demo data was not created: it is only available on local or development sites.', 'leagueflow' ),
				'conflicts'   => __( 'Demo data was not created: this site already has records it would collide with. Nothing was changed.', 'leagueflow' ),
				'stale'       => __( 'Demo data was not created: the site changed after the preview. Review the preview again.', 'leagueflow' ),
				'collision'   => __( 'Demo data creation stopped: a matching record appeared after the preview. Existing records were not changed.', 'leagueflow' ),
			);
			$refusal  = sanitize_key( wp_unslash( $_GET['leagueflow_seed_refused'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $refusals[ $refusal ] ?? $refusals['conflicts'] ) . '</p></div>';
		}

		if ( ! empty( $_GET['leagueflow_sports_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sports updated successfully.', 'leagueflow' ) . '</p></div>';
		}

		// Discard legacy credential notices without ever rendering a cleartext password.
		delete_transient( 'leagueflow_player_credentials_' . get_current_user_id() );

		$transient_key = 'leagueflow_notices_' . get_current_user_id();
		$notices       = get_transient( $transient_key );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $transient_key );

		foreach ( $notices as $notice ) {
			$link = ! empty( $notice['link'] ) ? sprintf( ' <a href="%1$s">%2$s</a>', esc_url( $notice['link'] ), esc_html( $notice['link_text'] ?? '' ) ) : '';

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] ),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
	}

	/**
	 * Handle demo data generation.
	 *
	 * @return void
	 */
	public function handle_seed_demo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ) );
		}

		check_admin_referer( 'leagueflow_seed_demo', 'leagueflow_seed_demo_nonce' );

		$args = array( 'page' => 'leagueflow-settings' );

		if ( empty( $_POST['leagueflow_demo_confirm'] ) ) {
			$args['leagueflow_seed_refused'] = 'confirm';
		} else {
			$token  = sanitize_key( wp_unslash( $_POST['leagueflow_demo_token'] ?? '' ) );
			$result = '' === $token ? new \WP_Error( 'leagueflow_demo_stale', '' ) : $this->seeder->seed_demo_data( $token );

			if ( is_wp_error( $result ) ) {
				$args['leagueflow_seed_refused'] = sanitize_key( str_replace( 'leagueflow_demo_', '', $result->get_error_code() ) );
			} else {
				$args['leagueflow_seeded'] = 1;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save enabled sports from the setup screen.
	 *
	 * @return void
	 */
	public function handle_save_sports() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ) );
		}

		check_admin_referer( 'leagueflow_save_sports', 'leagueflow_save_sports_nonce' );

		// Capture this before the update clears the flag so we can tell setup from a later edit.
		$was_initial_setup = $this->sports_manager->is_setup_required();

		$sports = isset( $_POST['leagueflow_enabled_sports'] ) ? wp_unslash( $_POST['leagueflow_enabled_sports'] ) : array();
		$result = $this->sports_manager->update_enabled_sports( $sports );
		if ( is_wp_error( $result ) ) {
			$this->queue_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=leagueflow-sports' ) );
			exit;
		}

		// After the first-time setup, drop the user into the Overview; later edits stay on Sports.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => $was_initial_setup ? 'leagueflow' : 'leagueflow-sports',
					'leagueflow_sports_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save a field availability window.
	 *
	 * @return void
	 */
	public function handle_save_field_availability() {
		if ( ! current_user_can( 'leagueflow_manage_field_availability' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'leagueflow_save_field_availability', 'leagueflow_save_field_availability_nonce' );

		$page       = sanitize_key( wp_unslash( $_POST['leagueflow_availability_page'] ?? 'leagueflow-fields' ) );
		$page       = $page ? $page : 'leagueflow-fields';
		$filter_sport = sanitize_key( wp_unslash( $_POST['leagueflow_current_sport'] ?? '' ) );

		$result = $this->field_availability_manager->save_availability(
			array(
				'id'             => sanitize_key( wp_unslash( $_POST['leagueflow_availability_id'] ?? '' ) ),
				'name'           => wp_unslash( $_POST['leagueflow_availability_name'] ?? '' ),
				'venue'          => wp_unslash( $_POST['leagueflow_availability_venue'] ?? '' ),
				'sport_slug'     => wp_unslash( $_POST['leagueflow_availability_sport'] ?? '' ),
				'date'           => wp_unslash( $_POST['leagueflow_availability_date'] ?? '' ),
				'weekday'        => wp_unslash( $_POST['leagueflow_availability_weekday'] ?? '' ),
				'start_time'     => wp_unslash( $_POST['leagueflow_availability_start'] ?? '' ),
				'end_time'       => wp_unslash( $_POST['leagueflow_availability_end'] ?? '' ),
				'slot_minutes'   => wp_unslash( $_POST['leagueflow_availability_slot_minutes'] ?? 60 ),
				'buffer_minutes' => wp_unslash( $_POST['leagueflow_availability_buffer_minutes'] ?? 0 ),
				'active'         => ! empty( $_POST['leagueflow_availability_active'] ),
				'notes'          => wp_unslash( $_POST['leagueflow_availability_notes'] ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->queue_notice( $result->get_error_message(), 'error' );
		} else {
			$this->queue_notice( __( 'Field availability saved.', 'leagueflow' ), 'success' );
		}

		wp_safe_redirect( $this->get_field_page_url( $page, $filter_sport ) );
		exit;
	}

	/**
	 * Delete a field availability window.
	 *
	 * @return void
	 */
	public function handle_delete_field_availability() {
		if ( ! current_user_can( 'leagueflow_manage_field_availability' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		$availability_id = sanitize_key( wp_unslash( $_GET['availability_id'] ?? '' ) );

		check_admin_referer( 'leagueflow_delete_field_availability_' . $availability_id, 'leagueflow_delete_field_availability_nonce' );

		$page       = sanitize_key( wp_unslash( $_GET['page'] ?? 'leagueflow-fields' ) );
		$page       = $page ? $page : 'leagueflow-fields';
		$sport_slug = sanitize_key( wp_unslash( $_GET['sport'] ?? '' ) );

		if ( $this->field_availability_manager->delete_availability( $availability_id ) ) {
			$this->queue_notice( __( 'Field availability deleted.', 'leagueflow' ), 'success' );
		} else {
			$this->queue_notice( __( 'Field availability could not be found.', 'leagueflow' ), 'warning' );
		}

		wp_safe_redirect( $this->get_field_page_url( $page, $sport_slug ) );
		exit;
	}

	/**
	 * Run the auto scheduler from the field availability page.
	 *
	 * @return void
	 */
	public function handle_auto_schedule_matches() {
		if ( ! current_user_can( 'leagueflow_manage_schedule' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'leagueflow_auto_schedule_matches', 'leagueflow_auto_schedule_matches_nonce' );

		$page           = sanitize_key( wp_unslash( $_POST['leagueflow_schedule_page'] ?? 'leagueflow-fields' ) );
		$page           = $page ? $page : 'leagueflow-fields';
		$sport_slug     = sanitize_key( wp_unslash( $_POST['leagueflow_schedule_sport'] ?? '' ) );
		$competition_id = wp_unslash( $_POST['leagueflow_schedule_competition'] ?? 0 );
		$season_id      = wp_unslash( $_POST['leagueflow_schedule_season'] ?? 0 );
		$mismatch       = false;

		// A competition or season from another sport would silently schedule nothing (or the wrong sport).
		foreach ( array( 'lf_competition' => $competition_id, 'lf_season' => $season_id ) as $taxonomy => $term_id ) {
			// Read ownership with an integer, but preserve the raw scope above so
			// the scheduling service can reject malformed IDs before any writes.
			$term_id = is_int( $term_id ) || is_string( $term_id ) ? absint( $term_id ) : 0;
			if ( ! $term_id ) {
				continue;
			}

			$term_sport = get_term( $term_id, $taxonomy ) instanceof \WP_Term ? get_term_sport_slug( $term_id ) : '__missing';

			if ( '__missing' === $term_sport || ( $sport_slug && $term_sport && $term_sport !== $sport_slug ) ) {
				$mismatch = true;
			}
		}

		if ( $mismatch ) {
			$this->store_schedule_result(
				array(
					'scheduled' => 0,
					'skipped'   => 0,
					'failed'    => 0,
					'messages'  => array( __( 'The competition or season belongs to another sport. Nothing was scheduled.', 'leagueflow' ) ),
				)
			);
			wp_safe_redirect( add_query_arg( 'leagueflow_schedule_complete', 1, $this->get_field_page_url( $page, $sport_slug ) ) );
			exit;
		}

		$result = $this->field_availability_manager->schedule_matches(
			array(
				'sport_slug'      => $sport_slug,
				'competition_id'  => $competition_id,
				'season_id'       => $season_id,
				'availability_id' => sanitize_key( wp_unslash( $_POST['leagueflow_schedule_availability'] ?? '' ) ),
				'mode'            => sanitize_key( wp_unslash( $_POST['leagueflow_schedule_mode'] ?? 'both' ) ),
				'date_from'       => $this->sanitize_admin_date( wp_unslash( $_POST['leagueflow_schedule_date_from'] ?? '' ) ),
				'date_to'         => $this->sanitize_admin_date( wp_unslash( $_POST['leagueflow_schedule_date_to'] ?? '' ) ),
				'date'            => $this->sanitize_admin_date( wp_unslash( $_POST['leagueflow_schedule_date'] ?? '' ) ),
				'overwrite'       => ! empty( $_POST['leagueflow_schedule_overwrite'] ),
			)
		);

		$this->store_schedule_result( $result );

		wp_safe_redirect(
			add_query_arg(
				'leagueflow_schedule_complete',
				1,
				$this->get_field_page_url( $page, $sport_slug )
			)
		);
		exit;
	}

	/**
	 * Handle player roster export downloads.
	 *
	 * @return void
	 */
	public function handle_export_players() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ) );
		}

		check_admin_referer( 'leagueflow_export_players', 'leagueflow_export_players_nonce' );

		$format     = sanitize_key( wp_unslash( $_POST['leagueflow_export_file_format'] ?? 'xlsx' ) );
		$delivery   = sanitize_key( wp_unslash( $_POST['leagueflow_export_delivery'] ?? 'single' ) );
		$sport_slug = sanitize_key( wp_unslash( $_POST['leagueflow_export_sport'] ?? 'all' ) );
		$enabled    = $this->sports_manager->get_enabled_sport_slugs();

		if ( ! in_array( $format, array( 'xlsx', 'csv' ), true ) ) {
			$format = 'xlsx';
		}

		if ( ! in_array( $delivery, array( 'single', 'bundle' ), true ) ) {
			$delivery = 'single';
		}

		if ( 'all' !== $sport_slug && ! in_array( $sport_slug, $enabled, true ) ) {
			wp_die( esc_html__( 'Invalid sport selected for export.', 'leagueflow' ) );
		}

		$sport_slugs = $this->exporter->get_export_sport_slugs( $sport_slug );

		if ( empty( $sport_slugs ) ) {
			wp_die( esc_html__( 'No enabled sports are available to export.', 'leagueflow' ) );
		}

		$args = array(
			'season_id'             => absint( wp_unslash( $_POST['leagueflow_export_season'] ?? 0 ) ),
			'date_from'             => sanitize_text_field( wp_unslash( $_POST['leagueflow_export_date_from'] ?? '' ) ),
			'date_to'               => sanitize_text_field( wp_unslash( $_POST['leagueflow_export_date_to'] ?? '' ) ),
			'status_scope'          => sanitize_key( wp_unslash( $_POST['leagueflow_export_status_scope'] ?? 'active' ) ),
			'include_unassigned'    => ! empty( $_POST['leagueflow_export_include_unassigned'] ),
			'include_user_accounts' => ! empty( $_POST['leagueflow_export_include_user_accounts'] ),
		);

		if ( 'bundle' === $delivery ) {
			$contents = $this->build_player_export_bundle( $sport_slugs, $format, $args );

			if ( is_wp_error( $contents ) ) {
				wp_die( esc_html( $contents->get_error_message() ) );
			}

			$this->send_export_download(
				$contents,
				$this->get_player_export_filename( $sport_slugs, 'zip' ),
				'application/zip'
			);
		}

		if ( 'xlsx' === $format ) {
			$contents     = $this->exporter->build_player_roster_xlsx( $sport_slugs, $args );
			$content_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
		} else {
			$contents     = $this->exporter->build_player_roster_csv( $sport_slugs, $args );
			$content_type = 'text/csv; charset=utf-8';
		}

		if ( is_wp_error( $contents ) ) {
			wp_die( esc_html( $contents->get_error_message() ) );
		}

		$this->send_export_download(
			$contents,
			$this->get_player_export_filename( $sport_slugs, $format ),
			$content_type
		);
	}

	/**
	 * Build a ZIP bundle for player exports.
	 *
	 * @param array<int, string> $sport_slugs Sport slugs.
	 * @param string $format Inner file format.
	 * @param array<string, mixed> $args Export options.
	 * @return string|\WP_Error
	 */
	protected function build_player_export_bundle( $sport_slugs, $format, $args ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'leagueflow_zip_missing', __( 'The PHP Zip extension is required to create export bundles.', 'leagueflow' ) );
		}

		$temp_file = wp_tempnam( 'leagueflow-player-export.zip' );

		if ( ! $temp_file ) {
			return new \WP_Error( 'leagueflow_export_temp_file', __( 'Could not create a temporary export file.', 'leagueflow' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $temp_file );
			return new \WP_Error( 'leagueflow_export_zip_open', __( 'Could not create the export bundle.', 'leagueflow' ) );
		}

		foreach ( $sport_slugs as $sport_slug ) {
			if ( 'xlsx' === $format ) {
				$contents = $this->exporter->build_player_roster_xlsx( array( $sport_slug ), $args );
			} else {
				$contents = $this->exporter->build_player_roster_csv( array( $sport_slug ), $args );
			}

			if ( is_wp_error( $contents ) ) {
				$zip->close();
				wp_delete_file( $temp_file );
				return $contents;
			}

			$zip->addFromString( $this->get_player_export_inner_filename( $sport_slug, $format ), $contents );
		}

		$zip->close();
		$contents = file_get_contents( $temp_file );
		wp_delete_file( $temp_file );

		if ( false === $contents ) {
			return new \WP_Error( 'leagueflow_export_read_file', __( 'Could not read the generated export bundle.', 'leagueflow' ) );
		}

		return $contents;
	}

	/**
	 * Stream an export download.
	 *
	 * @param string $contents File contents.
	 * @param string $filename Download filename.
	 * @param string $content_type Content type.
	 * @return void
	 */
	protected function send_export_download( $contents, $filename, $content_type ) {
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Could not start the download because output has already been sent.', 'leagueflow' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $contents ) );

		echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw export download bytes.
		exit;
	}

	/**
	 * Build an exported player roster filename.
	 *
	 * @param array<int, string> $sport_slugs Sport slugs.
	 * @param string $extension File extension.
	 * @return string
	 */
	protected function get_player_export_filename( $sport_slugs, $extension ) {
		$scope = 1 === count( $sport_slugs ) ? sanitize_key( $sport_slugs[0] ) : 'all-sports';

		return sanitize_file_name( 'leagueflow-player-rosters-' . $scope . '-' . wp_date( 'Ymd-His' ) . '.' . sanitize_key( $extension ) );
	}

	/**
	 * Build an inner bundle filename for a sport.
	 *
	 * @param string $sport_slug Sport slug.
	 * @param string $extension File extension.
	 * @return string
	 */
	protected function get_player_export_inner_filename( $sport_slug, $extension ) {
		return sanitize_file_name( 'player-roster-' . sanitize_key( $sport_slug ) . '-' . wp_date( 'Ymd-His' ) . '.' . sanitize_key( $extension ) );
	}

	/**
	 * Validate shared post save requirements.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $nonce_name Nonce field.
	 * @param string $nonce_action Nonce action.
	 * @return bool
	 */
	protected function can_save( $post_id, $nonce_name, $nonce_action ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		if ( empty( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Generate or reset a random WordPress login for a player.
	 *
	 * @param int $post_id Player post ID.
	 * @return void
	 */
	protected function maybe_generate_player_login( $post_id ) {
		if ( empty( $_POST['lf_generate_player_login'] ) || ! can_manage_player_accounts()
			|| ! current_user_can( 'create_users' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$player = get_post( $post_id );
		if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) {
			return;
		}
		if ( get_post_meta( $post_id, 'lf_user_id', true ) ) {
			$this->queue_notice( __( 'This player already has an account. Use WordPress password recovery for existing accounts.', 'leagueflow' ), 'warning' );
			return;
		}
		$email = sanitize_email( get_post_meta( $post_id, 'lf_email', true ) );
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			$this->queue_notice( __( 'Provide a valid, unused email address to create a new portal account.', 'leagueflow' ), 'error' );
			return;
		}
		$user_id = wp_insert_user( array(
			'user_login' => $this->get_unique_player_username( $post_id ),
			'user_pass' => wp_generate_password( 32, true, true ),
			'user_email' => $email,
			'display_name' => $player->post_title,
			'nickname' => $player->post_title,
			'role' => 'leagueflow_player',
		) );
		if ( is_wp_error( $user_id ) ) {
			$this->queue_notice( $user_id->get_error_message(), 'error' );
			return;
		}
		update_post_meta( $post_id, 'lf_user_id', (int) $user_id );
		wp_new_user_notification( $user_id, null, 'user' );
		$this->queue_notice( __( 'Portal account created. WordPress has requested a password-setup email for the player.', 'leagueflow' ), 'success' );
	}

	/**
	 * Build a unique generated username for a player.
	 *
	 * @param int $post_id Player post ID.
	 * @return string
	 */
	protected function get_unique_player_username( $post_id ) {
		$base     = sanitize_user( 'player_' . absint( $post_id ), true );
		$username = $base;
		$suffix   = 2;

		while ( username_exists( $username ) ) {
			$username = $base . '_' . $suffix;
			$suffix++;
		}

		return $username;
	}

	/**
	 * Save player email and linked user metadata.
	 *
	 * @param int $post_id Player post ID.
	 * @return bool Whether account provisioning may continue.
	 */
	protected function save_team_manager_accounts( $post_id ) {
		if ( ! can_manage_player_accounts() || ( ! array_key_exists( 'lf_manager_user_ids', $_POST ) && ! isset( $_POST['lf_manager_user_ids_present'] ) ) ) {
			return;
		}
		$raw_ids = wp_unslash( $_POST['lf_manager_user_ids'] ?? array() );
		if ( ! is_array( $raw_ids ) ) { return; }
		foreach ( $raw_ids as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) ) { return; }
		}
		$manager_ids = sanitize_user_id_list( $raw_ids );
		$previous_ids = get_team_manager_user_ids( $post_id );
		foreach ( array_diff( $manager_ids, $previous_ids ) as $id ) {
			if ( ! can_link_player_account( $id ) ) {
				$this->queue_notice( __( 'Only authorized, low-privilege portal accounts can be added as team managers. Manager assignments were not changed.', 'leagueflow' ), 'error' );
				return;
			}
		}
		foreach ( array_diff( $previous_ids, $manager_ids ) as $id ) {
			if ( ! current_user_can( 'edit_user', $id ) || ! current_user_can( 'promote_user', $id ) ) {
				$this->queue_notice( __( 'You cannot remove one of the selected manager accounts. Manager assignments were not changed.', 'leagueflow' ), 'error' );
				return;
			}
		}
		update_post_meta( $post_id, 'lf_manager_user_ids', $manager_ids );
		foreach ( array_diff( $manager_ids, $previous_ids ) as $id ) {
			add_user_role_if_missing( $id, 'leagueflow_team_manager' );
		}
	}

	/** Save a player account link only for staff authorized to manage its identity. */
	protected function save_player_identity_meta( $post_id ) {
		$email = sanitize_email( wp_unslash( $_POST['lf_email'] ?? '' ) );
		if ( is_email( $email ) ) {
			update_post_meta( $post_id, 'lf_email', strtolower( $email ) );
		} else {
			delete_post_meta( $post_id, 'lf_email' );
		}
		if ( ! can_manage_player_accounts() ) {
			return false;
		}
		if ( ! array_key_exists( 'lf_user_id', $_POST ) ) {
			return true;
		}
		$user_id = absint( wp_unslash( $_POST['lf_user_id'] ) );
		$old_id = (int) get_post_meta( $post_id, 'lf_user_id', true );
		if ( ( $user_id && ! can_link_player_account( $user_id ) )
			|| ( $old_id && $old_id !== $user_id && ( ! current_user_can( 'edit_user', $old_id ) || ! current_user_can( 'promote_user', $old_id ) ) ) ) {
			$this->queue_notice( __( 'Only authorized, low-privilege portal accounts can be linked to players.', 'leagueflow' ), 'error' );
			return false;
		}
		if ( $user_id ) {
			update_post_meta( $post_id, 'lf_user_id', $user_id );
			add_user_role_if_missing( $user_id, 'leagueflow_player' );
		} else {
			delete_post_meta( $post_id, 'lf_user_id' );
		}
		return true;
	}

	/**
	 * Build a safe field availability page URL.
	 *
	 * @param string $page Page slug.
	 * @param string $sport_slug Sport slug.
	 * @param array<string, string> $extra Extra query args.
	 * @return string
	 */
	protected function get_field_page_url( $page, $sport_slug = '', $extra = array() ) {
		$args = array_merge(
			array_filter(
				array(
					'page'  => sanitize_key( $page ),
					'sport' => sanitize_key( $sport_slug ),
				)
			),
			$extra
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Get a human-readable sport scope label.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return string
	 */
	protected function get_sport_scope_label( $sport_slug ) {
		$sport_slug = sanitize_key( $sport_slug );

		if ( '' === $sport_slug ) {
			return __( 'All sports', 'leagueflow' );
		}

		$sport = $this->sports_manager->get_definition( $sport_slug );

		return ! empty( $sport['label'] ) ? $sport['label'] : $sport_slug;
	}

	/**
	 * Term options for a sport; with "All sports" each label names its sport,
	 * so competitions and seasons of different sports cannot be confused.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $sport_slug Sport slug.
	 * @return array<int, string>
	 */
	protected function get_sport_labelled_term_options( $taxonomy, $sport_slug = '' ) {
		$options = $this->get_term_options_for_sport( $taxonomy, $sport_slug );

		if ( '' !== $sport_slug ) {
			return $options;
		}

		foreach ( $options as $term_id => $name ) {
			$term_sport = get_term_sport_slug( $term_id );
			$definition = $term_sport ? $this->sports_manager->get_definition( $term_sport ) : array();
			/* translators: 1: sport, 2: competition or season */
			$options[ $term_id ] = sprintf( __( '%1$s: %2$s', 'leagueflow' ), ! empty( $definition['label'] ) ? $definition['label'] : __( 'No sport', 'leagueflow' ), $name );
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	/**
	 * Term options for a sport (all terms when no sport is given).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $sport_slug Sport.
	 * @return array<int, string>
	 */
	protected function get_term_options_for_sport( $taxonomy, $sport_slug = '' ) {
		$options = array();

		if ( $sport_slug ) {
			$term_ids = get_term_ids_by_sport( $taxonomy, $sport_slug );

			if ( empty( $term_ids ) ) {
				return $options;
			}

			$args     = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'include'    => $term_ids,
				'orderby'    => 'name',
				'order'      => 'ASC',
			);
		} else {
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			);
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return $options;
		}

		foreach ( $terms as $term ) {
			$options[ (int) $term->term_id ] = $term->name;
		}

		return $options;
	}

	/**
	 * Sanitize a YYYY-MM-DD admin date.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	protected function sanitize_admin_date( $date ) {
		$date = sanitize_text_field( (string) $date );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parts = array_map( 'absint', explode( '-', $date ) );

		return checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : '';
	}

	/**
	 * Render a user select control.
	 *
	 * @param string          $name Field name.
	 * @param string          $id Field ID.
	 * @param array<int, int> $selected Selected user IDs.
	 * @param bool            $multiple Whether multiple users can be selected.
	 * @param string          $placeholder Placeholder option.
	 * @return void
	 */
	protected function render_user_select( $name, $id, $selected, $multiple, $placeholder ) {
		$selected = sanitize_user_id_list( $selected );
		$attrs    = $multiple ? ' multiple size="6"' : '';
		$options  = $this->get_portal_user_options();
		// Keep current assignments visible even beyond the bounded user picker.
		// Otherwise submitting an unrelated edit could silently remove a manager.
		foreach ( $selected as $user_id ) {
			if ( isset( $options[ $user_id ] ) ) { continue; }
			$user = get_user_by( 'id', $user_id );
			if ( $user ) { $options[ $user_id ] = sprintf( '%1$s <%2$s>', $user->display_name ?: $user->user_email, $user->user_email ); }
		}

		printf( '<select id="%1$s" name="%2$s"%3$s%4$s>', esc_attr( $id ), esc_attr( $name ), $attrs, $this->get_searchable_select_attributes( __( 'Filter users by name or email', 'leagueflow' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $multiple ) {
			printf( '<option value="">%s</option>', esc_html( $placeholder ) );
		}

		foreach ( $options as $user_id => $label ) {
			if ( 'lf_user_id' === $id && ! can_link_player_account( $user_id ) ) { continue; }
			if ( 'lf_manager_user_ids' === $id && ! in_array( (int) $user_id, $selected, true ) && ! can_link_player_account( $user_id ) ) { continue; }
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $user_id ),
				selected( in_array( (int) $user_id, $selected, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Attributes that let admin.js add an accessible filter box to a long select.
	 *
	 * @param string $label Accessible name of the filter box.
	 * @return string Escaped attribute string with a leading space.
	 */
	protected function get_searchable_select_attributes( $label ) {
		return sprintf(
			' data-leagueflow-searchable="1" data-leagueflow-search-label="%1$s" data-leagueflow-search-placeholder="%2$s" data-leagueflow-search-count="%3$s"',
			esc_attr( $label ),
			esc_attr__( 'Type to filter…', 'leagueflow' ),
			/* translators: %d: number of matching options */
			esc_attr__( '%d matching options', 'leagueflow' )
		);
	}

	/**
	 * Get user options for portal identity fields.
	 *
	 * @return array<int, string>
	 */
	protected function get_portal_user_options() {
		$options = array();
		$users   = get_users(
			array(
				'number'  => 500,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		foreach ( $users as $user ) {
			$options[ (int) $user->ID ] = sprintf(
				'%1$s <%2$s>',
				$user->display_name ? $user->display_name : $user->user_email,
				$user->user_email
			);
		}

		return $options;
	}

	/**
	 * Render a select control.
	 *
	 * @param string               $name Field name.
	 * @param array<string|int, string> $options Options.
	 * @param mixed                $selected Selected value.
	 * @param string               $placeholder Placeholder.
	 * @return void
	 */
	protected function render_select( $name, $options, $selected, $placeholder ) {
		printf( '<select id="%1$s" name="%1$s">', esc_attr( $name ) );

		// A null placeholder means the field always has a real value; an empty
		// first option captioned like a real one ("Home", "Recreational") was ambiguous.
		if ( null !== $placeholder ) {
			printf( '<option value="">%s</option>', esc_html( $placeholder ) );
		}

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $selected, (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Update or delete a meta field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param string $value Meta value.
	 * @return void
	 */
	protected function update_or_delete_meta( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Queue an admin notice.
	 *
	 * @param string $message Message.
	 * @param string $type Notice type.
	 * @param string $link Optional follow-up link.
	 * @param string $link_text Link text.
	 * @return void
	 */
	protected function queue_notice( $message, $type = 'warning', $link = '', $link_text = '' ) {
		$transient_key = 'leagueflow_notices_' . get_current_user_id();
		$notices       = get_transient( $transient_key );

		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notices[] = array(
			'type'      => $type,
			'message'   => $message,
			'link'      => $link,
			'link_text' => $link_text,
		);

		set_transient( $transient_key, $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Store scheduling assistant results for the next admin page load.
	 *
	 * @param array<string, mixed> $result Scheduling result.
	 * @return void
	 */
	protected function store_schedule_result( $result ) {
		set_transient( 'leagueflow_schedule_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
	}

	/**
	 * Synchronize a match title from selected teams and datetime.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $home_team_id Home team.
	 * @param int    $away_team_id Away team.
	 * @param string $datetime Datetime string.
	 * @return void
	 */
	protected function sync_match_title( $post_id, $home_team_id, $away_team_id, $datetime ) {
		if ( $this->syncing_match_title ) {
			return;
		}

		$this->syncing_match_title = true;

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => build_match_title( $home_team_id, $away_team_id, $datetime ),
			)
		);

		$this->syncing_match_title = false;
	}

	/**
	 * Get the current LeagueFlow admin page slug.
	 *
	 * @return string
	 */
	protected function get_current_page_slug() {
		return sanitize_key( wp_unslash( $_GET['page'] ?? 'leagueflow' ) );
	}

	/**
	 * Get the LeagueFlow menu slug for the current admin screen.
	 *
	 * Global screens use the main menu; scoped records and pages highlight the
	 * corresponding enabled sport's shortcuts.
	 *
	 * @return string
	 */
	protected function get_current_leagueflow_admin_menu_slug() {
		$page = $this->get_current_admin_plugin_page_slug();
		$sport = $this->get_context_sport_slug();
		$global = in_array( $page, array( 'leagueflow-sports', 'leagueflow-settings', 'leagueflow-utilities', 'leagueflow-guide' ), true ) || 'lf_league_level' === $this->get_current_admin_taxonomy();
		if ( $sport && ! $global && ( 0 === strpos( $page, 'leagueflow' ) || in_array( $this->get_current_admin_post_type(), $this->get_leagueflow_admin_post_types(), true ) || isset( $this->get_leagueflow_taxonomy_submenu_files()[ $this->get_current_admin_taxonomy() ] ) ) ) {
			return 'leagueflow-sport-' . $sport;
		}

		if ( $page ) {
			return ( 'leagueflow' === $page || 0 === strpos( $page, 'leagueflow-' ) ) ? 'leagueflow' : '';
		}

		if ( isset( $this->get_leagueflow_taxonomy_submenu_files()[ $this->get_current_admin_taxonomy() ] ) ) {
			return 'leagueflow';
		}

		return in_array( $this->get_current_admin_post_type(), $this->get_leagueflow_admin_post_types(), true ) ? 'leagueflow' : '';
	}

	/**
	 * Get the LeagueFlow submenu file for the current admin screen.
	 *
	 * Lists, add and edit screens highlight their list item whatever the sport
	 * context; join request records belong to the Requests inbox.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	protected function get_current_leagueflow_admin_submenu_file( $parent_file ) {
		$sport_parent = 0 === strpos( $parent_file, 'leagueflow-sport-' );
		$sport = $sport_parent ? $this->get_context_sport_slug() : '';
		$scoped = static function ( $file ) use ( $sport ) {
			return $sport ? add_query_arg( 'lf_sport', $sport, $file ) : $file;
		};

		$page = $this->get_current_admin_plugin_page_slug();

		if ( $page && ( 'leagueflow' === $page || 0 === strpos( $page, 'leagueflow-' ) ) ) {
			return $sport_parent ? ( $page === $parent_file || 'leagueflow' === $page ? $parent_file : $scoped( 'admin.php?page=' . $page ) ) : $page;
		}

		$taxonomy_files = $this->get_leagueflow_taxonomy_submenu_files();
		$taxonomy       = $this->get_current_admin_taxonomy();

		if ( isset( $taxonomy_files[ $taxonomy ] ) ) {
			return $scoped( $taxonomy_files[ $taxonomy ] );
		}

		$post_type = $this->get_current_admin_post_type();

		if ( 'lf_join_request' === $post_type ) {
			return $sport_parent ? $scoped( 'admin.php?page=leagueflow-requests' ) : 'leagueflow-requests';
		}

		return in_array( $post_type, $this->get_leagueflow_admin_post_types(), true ) ? $scoped( 'edit.php?post_type=' . $post_type ) : '';
	}

	/**
	 * Get the current admin file.
	 *
	 * @return string
	 */
	protected function get_current_admin_file() {
		global $pagenow;

		return (string) $pagenow;
	}

	/**
	 * Get the current plugin page slug from the admin request.
	 *
	 * @return string
	 */
	protected function get_current_admin_plugin_page_slug() {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get the current admin post type.
	 *
	 * @return string
	 */
	protected function get_current_admin_post_type() {
		global $typenow, $post_type;

		if ( ! empty( $post_type ) ) {
			return sanitize_key( (string) $post_type );
		}

		if ( ! empty( $typenow ) ) {
			return sanitize_key( (string) $typenow );
		}

		if ( isset( $_REQUEST['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( (string) wp_unslash( $_REQUEST['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$post_id = absint( wp_unslash( $_REQUEST['post'] ?? $_REQUEST['post_ID'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id ) {
			$post = get_post( $post_id );

			return $post ? sanitize_key( $post->post_type ) : '';
		}

		return '';
	}

	/**
	 * Get the current admin taxonomy.
	 *
	 * @return string
	 */
	protected function get_current_admin_taxonomy() {
		global $taxnow, $taxonomy;

		if ( ! empty( $taxonomy ) ) {
			return sanitize_key( (string) $taxonomy );
		}

		if ( ! empty( $taxnow ) ) {
			return sanitize_key( (string) $taxnow );
		}

		if ( isset( $_REQUEST['taxonomy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( (string) wp_unslash( $_REQUEST['taxonomy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	/**
	 * Check whether a sport slug has an enabled admin menu.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return bool
	 */
	protected function is_enabled_sport_slug( $sport_slug ) {
		return in_array( sanitize_key( $sport_slug ), $this->sports_manager->get_enabled_sport_slugs(), true );
	}

	/**
	 * Get LeagueFlow post types shown in the admin menus.
	 *
	 * @return array<int, string>
	 */
	protected function get_leagueflow_admin_post_types() {
		return array( 'lf_team', 'lf_player', 'lf_join_request', 'lf_match', 'lf_calendar_event' );
	}

	/**
	 * Get LeagueFlow taxonomy submenu files.
	 *
	 * @return array<string, string>
	 */
	protected function get_leagueflow_taxonomy_submenu_files() {
		return array(
			'lf_league_level' => 'edit-tags.php?taxonomy=lf_league_level&post_type=lf_match',
			'lf_competition'  => 'edit-tags.php?taxonomy=lf_competition&post_type=lf_match',
			'lf_season'       => 'edit-tags.php?taxonomy=lf_season&post_type=lf_match',
		);
	}

	/**
	 * Resolve the requested sport slug from the current admin context.
	 *
	 * @return string
	 */
	protected function get_current_requested_sport_slug() {
		$request_sport = $this->get_requested_sport_slug();

		if ( $request_sport ) {
			return $request_sport;
		}

		$page = $this->get_current_page_slug();

		if ( preg_match( '/^leagueflow-sport-(.+?)(?:-(?:fixtures|standings|brackets|fields))?$/', $page, $matches ) ) {
			return sanitize_key( $matches[1] );
		}

		$competition_id = resolve_term_id( $_GET['competition'] ?? '', 'lf_competition' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$season_id      = resolve_term_id( $_GET['season'] ?? '', 'lf_season' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $competition_id ) {
			$sport_slug = sanitize_key( (string) get_term_meta( $competition_id, 'lf_sport_slug', true ) );
			if ( $sport_slug ) {
				return $sport_slug;
			}
		}

		if ( $season_id ) {
			$sport_slug = sanitize_key( (string) get_term_meta( $season_id, 'lf_sport_slug', true ) );
			if ( $sport_slug ) {
				return $sport_slug;
			}
		}

		$enabled = $this->sports_manager->get_enabled_sport_slugs();
		return ! empty( $enabled ) ? $enabled[0] : 'soccer';
	}

	/**
	 * Get the requested league level slug from query vars.
	 *
	 * @return string
	 */
	protected function get_requested_league_level_slug() {
		$value = wp_unslash( $_GET['lf_league_level'] ?? $_GET['league_level'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ), 'lf_league_level' );
			return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
		}

		return sanitize_key( (string) $value );
	}

	/**
	 * Count published and private posts from a wp_count_posts() result.
	 *
	 * Matches the statuses used by the per-sport overview so both dashboards agree.
	 *
	 * @param object $counts wp_count_posts() result.
	 * @return int
	 */
	protected function count_visible_posts( $counts ) {
		return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->private ?? 0 );
	}

	/**
	 * Get content counts for a sport.
	 *
	 * @param int $sport_id Sport term ID.
	 * @return array<string, int>
	 */
	protected function get_sport_content_counts( $sport_id ) {
		$counts = array(
			'teams'           => 0,
			'players'         => 0,
			'matches'         => 0,
			'calendar_events' => 0,
			'league_levels'   => 0,
			'competitions'    => 0,
			'seasons'         => 0,
		);

		if ( ! $sport_id ) {
			return $counts;
		}

		$sport = get_term( $sport_id, 'lf_sport' );

		if ( ! $sport || is_wp_error( $sport ) ) {
			return $counts;
		}

		foreach ( array( 'lf_team' => 'teams', 'lf_player' => 'players', 'lf_match' => 'matches', 'lf_calendar_event' => 'calendar_events' ) as $post_type => $key ) {
			$counts[ $key ] = $this->count_dashboard_records( $post_type, array(
				array( 'taxonomy' => 'lf_sport', 'field' => 'term_id', 'terms' => array( $sport_id ) ),
			) );
		}

		$counts['competitions'] = count( get_term_ids_by_sport( 'lf_competition', $sport->slug ) );
		$counts['seasons']      = count( get_term_ids_by_sport( 'lf_season', $sport->slug ) );
		$counts['league_levels'] = count( $this->get_league_level_terms() );

		return $counts;
	}

	/** Count visible dashboard records without fetching every post ID. */
	protected function count_dashboard_records( $post_type, $tax_query ) {
		$query = new \WP_Query( array(
			'post_type' => $post_type,
			'post_status' => array( 'publish', 'private' ),
			'perm' => 'readable',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'no_found_rows' => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query' => $tax_query,
		) );

		return (int) $query->found_posts;
	}

	/**
	 * Render counts for each league level in a sport.
	 *
	 * @param int $sport_id Sport term ID.
	 * @return void
	 */
	protected function render_sport_level_breakdown( $sport_id ) {
		if ( ! $sport_id ) {
			echo '<p>' . esc_html__( 'No sport context is available.', 'leagueflow' ) . '</p>';
			return;
		}

		echo '<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="' . esc_attr__( 'League level breakdown', 'leagueflow' ) . '">';
		echo '<table class="widefat striped leagueflow-level-breakdown"><thead><tr>';
		echo '<th>' . esc_html__( 'Level', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Teams', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Matches', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Calendar Events', 'leagueflow' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->get_league_level_terms() as $level ) {
			$counts = array();

			foreach ( array( 'lf_team' => 'teams', 'lf_match' => 'matches', 'lf_calendar_event' => 'calendar_events' ) as $post_type => $key ) {
				$counts[ $key ] = $this->count_dashboard_records( $post_type, array(
					array( 'taxonomy' => 'lf_sport', 'field' => 'term_id', 'terms' => array( $sport_id ) ),
					array( 'taxonomy' => 'lf_league_level', 'field' => 'term_id', 'terms' => array( (int) $level->term_id ) ),
				) );
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $level->name ),
				esc_html( (string) $counts['teams'] ),
				esc_html( (string) $counts['matches'] ),
				esc_html( (string) $counts['calendar_events'] )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Get post types that can be filtered by sport in admin list tables.
	 *
	 * @return array<int, string>
	 */
	protected function get_sport_filterable_post_types() {
		return array_merge( $this->get_taxonomy_sport_filterable_post_types(), array( 'lf_join_request' ) );
	}

	/**
	 * Get post types that store sport assignments in the sport taxonomy.
	 *
	 * @return array<int, string>
	 */
	protected function get_taxonomy_sport_filterable_post_types() {
		return array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' );
	}

	/**
	 * Ensure a post has a sport assignment.
	 *
	 * Used only for records whose sport has no details-box control (players
	 * without a team, matches whose teams have no sport) and for older forms
	 * without the selector.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $preferred_slug Optional preferred sport slug.
	 * @return bool Whether the post now has a sport.
	 */
	protected function assign_default_sport_if_missing( $post_id, $preferred_slug = '' ) {
		$existing_sport_id = get_post_primary_term_id( $post_id, 'lf_sport' );

		if ( $existing_sport_id ) {
			return true;
		}

		// Only an explicit, enabled sport: never the first enabled sport and never
		// an invented "soccer" when that sport is not enabled.
		$sport_slug = $preferred_slug ? sanitize_key( $preferred_slug ) : $this->get_context_sport_slug();

		if ( ! $this->is_assignable_sport_slug( $sport_slug ) ) {
			return false;
		}

		$sport  = get_term_by( 'slug', $sport_slug, 'lf_sport' );
		$result = wp_set_object_terms( $post_id, array( (int) $sport->term_id ), 'lf_sport', false );

		return ! is_wp_error( $result );
	}

	/**
	 * Ensure a post has a league level assignment.
	 *
	 * @param int $post_id Post ID.
	 * @return int League level term ID.
	 */
	protected function assign_default_league_level_if_missing( $post_id ) {
		$existing_level_id = get_post_primary_term_id( $post_id, 'lf_league_level' );

		if ( $existing_level_id ) {
			return $existing_level_id;
		}

		$level_id = $this->get_default_league_level_id();

		if ( $level_id ) {
			wp_set_object_terms( $post_id, array( $level_id ), 'lf_league_level', false );
		}

		return $level_id;
	}

	/**
	 * Assign a league level from the current editor request.
	 *
	 * @param int $post_id Post ID.
	 * @return int League level term ID.
	 */
	protected function assign_league_level_from_request( $post_id ) {
		$level_id = isset( $_POST['lf_league_level_id'] ) ? absint( wp_unslash( $_POST['lf_league_level_id'] ) ) : 0;

		if ( ! $level_id ) {
			$level_id = get_post_primary_term_id( $post_id, 'lf_league_level' );
		}

		if ( ! $level_id ) {
			$level_id = $this->get_default_league_level_id();
		}

		if ( $level_id ) {
			wp_set_object_terms( $post_id, array( $level_id ), 'lf_league_level', false );
		}

		return $level_id;
	}

	/**
	 * Get the default recreational league level ID.
	 *
	 * @return int
	 */
	protected function get_default_league_level_id() {
		ensure_default_league_levels();

		$term = get_term_by( 'slug', 'recreational', 'lf_league_level' );

		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	/**
	 * Default league level for display, without creating terms during render.
	 *
	 * @return int
	 */
	protected function find_default_league_level_id() {
		$term = get_term_by( 'slug', 'recreational', 'lf_league_level' );

		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}

		$options = $this->get_league_level_options();

		return $options ? (int) array_key_first( $options ) : 0;
	}

	/**
	 * Resolve the sport slug for metabox rendering.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function get_editor_sport_slug( $post_id ) {
		$sport_slug = $post_id ? get_post_primary_term_slug( $post_id, 'lf_sport' ) : '';

		if ( $sport_slug ) {
			return $sport_slug;
		}

		return $this->get_context_sport_slug();
	}

	/**
	 * Whether a slug is an enabled LeagueFlow sport with an existing term.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return bool
	 */
	protected function is_assignable_sport_slug( $sport_slug ) {
		$sport_slug = sanitize_key( (string) $sport_slug );

		if ( '' === $sport_slug || ! in_array( $sport_slug, $this->sports_manager->get_enabled_sport_slugs(), true ) ) {
			return false;
		}

		$term = get_term_by( 'slug', $sport_slug, 'lf_sport' );

		return $term instanceof \WP_Term;
	}

	/**
	 * Enabled sport choices for a details-box sport selector.
	 *
	 * @param string $current_slug A current (possibly disabled) sport to keep listed.
	 * @return array<string, string>
	 */
	protected function get_sport_choice_options( $current_slug = '' ) {
		$options = array();

		foreach ( $this->sports_manager->get_enabled_sports() as $slug => $definition ) {
			if ( get_term_by( 'slug', $slug, 'lf_sport' ) instanceof \WP_Term ) {
				$options[ $slug ] = $definition['label'];
			}
		}

		$current_slug = sanitize_key( (string) $current_slug );

		if ( $current_slug && ! isset( $options[ $current_slug ] ) ) {
			$term                     = get_term_by( 'slug', $current_slug, 'lf_sport' );
			$label                    = $term instanceof \WP_Term ? $term->name : $current_slug;
			/* translators: %s: sport name */
			$options[ $current_slug ] = sprintf( __( '%s (not enabled)', 'leagueflow' ), $label );
		}

		return $options;
	}

	/**
	 * Read the sport submitted from a details-box selector.
	 *
	 * @return string Submitted slug ('' when absent).
	 */
	protected function get_submitted_sport_choice() {
		return isset( $_POST['lf_sport_choice'] ) ? sanitize_key( wp_unslash( $_POST['lf_sport_choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by can_save() in the save handlers.
	}

	/**
	 * Render a "no sports enabled" setup message with a link for staff who can fix it.
	 *
	 * @return void
	 */
	protected function render_no_enabled_sports_notice() {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No enabled sport is set up yet, so this record cannot be given a sport.', 'leagueflow' ) . ' ';
		if ( current_user_can( 'manage_options' ) ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ), esc_html__( 'Enable a sport in LeagueFlow > Sports.', 'leagueflow' ) );
		} else {
			esc_html_e( 'Ask a site administrator to enable one in LeagueFlow > Sports.', 'leagueflow' );
		}
		echo '</p></div>';
	}

	/**
	 * Resolve the league level ID for metabox rendering.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	protected function get_editor_league_level_id( $post_id ) {
		$level_id = $post_id ? get_post_primary_term_id( $post_id, 'lf_league_level' ) : 0;

		return $level_id ? $level_id : $this->get_default_league_level_id();
	}

	/**
	 * Get league level terms.
	 *
	 * @return array<int, \WP_Term>
	 */
	protected function get_league_level_terms() {
		return get_league_level_terms();
	}

	/**
	 * Get league level select options.
	 *
	 * @return array<int, string>
	 */
	protected function get_league_level_options() {
		$options = array();

		foreach ( $this->get_league_level_terms() as $term ) {
			$options[ (int) $term->term_id ] = $term->name;
		}

		return $options;
	}

	/**
	 * Get sport-filtered team options.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return array<int, string>
	 */
	protected function get_team_options( $sport_slug = '' ) {
		$args = array(
			'post_type'      => 'lf_team',
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $sport_slug ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'lf_sport',
					'field'    => 'slug',
					'terms'    => array( sanitize_key( $sport_slug ) ),
				),
			);
		}

		$options = array();

		foreach ( get_posts( $args ) as $team ) {
			if ( ! can_view_league_post( $team ) ) {
				continue;
			}
			$options[ $team->ID ] = $team->post_title;
		}

		return $options;
	}

	/**
	 * "Advance winner to" choices: later-round knockout matches the existing
	 * validation (Knockout_Service::can_advance_to) accepts for this match,
	 * labelled with round, title and date. A stored link that is no longer
	 * valid stays visible and marked, so it is never dropped silently.
	 *
	 * @param int    $match_id Match being edited.
	 * @param string $sport_slug Sport slug.
	 * @return array<int, string>
	 */
	protected function get_knockout_target_options( $match_id, $sport_slug ) {
		$current = (int) get_post_meta( $match_id, 'lf_next_match_id', true );
		$args    = array(
			'post_type'      => 'lf_match',
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post__not_in'   => array( (int) $match_id ),
			'meta_query'     => array(
				array(
					'key'   => 'lf_is_knockout',
					'value' => '1',
				),
			),
		);

		if ( $sport_slug ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'lf_sport',
					'field'    => 'slug',
					'terms'    => array( sanitize_key( $sport_slug ) ),
				),
			);
		}

		$options = array();

		foreach ( get_posts( $args ) as $match ) {
			if ( ! can_view_league_post( $match ) ) {
				continue;
			}
			if ( ! $this->knockout_service->can_advance_to( $match_id, $match->ID ) && $match->ID !== $current ) {
				continue;
			}

			$round    = (string) get_post_meta( $match->ID, 'lf_round_label', true );
			$datetime = (string) get_post_meta( $match->ID, 'lf_match_datetime', true );
			$label    = implode( ' · ', array_filter( array( $round, $match->post_title, $datetime ? mysql2date( get_option( 'date_format' ), $datetime ) : __( 'Unscheduled', 'leagueflow' ) ) ) );

			if ( $match->ID === $current && ! $this->knockout_service->can_advance_to( $match_id, $match->ID ) ) {
				/* translators: %s: match label */
				$label = sprintf( __( '%s (current link, no longer valid: choose again)', 'leagueflow' ), $label );
			}

			$options[ $match->ID ] = $label;
		}

		// Keep a stored link to a match outside this list visible instead of silently dropping it.
		if ( $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = 'lf_match' === get_post_type( $current ) && can_view_league_post( $current )
				? sprintf( /* translators: %s: match title */ __( '%s (current link, no longer valid: choose again)', 'leagueflow' ), get_the_title( $current ) )
				: __( 'Current linked match (unavailable: ask a league administrator to review)', 'leagueflow' );
		}

		return $options;
	}

	/**
	 * Render competition and season filters.
	 *
	 * @param string $page Page slug.
	 * @param int    $competition_id Competition term ID.
	 * @param int    $season_id Season term ID.
	 * @param string $sport_slug Sport slug.
	 * @param int    $league_level_id League level term ID.
	 * @return void
	 */
	protected function render_context_filters( $page, $competition_id, $season_id, $sport_slug = '', $league_level_id = 0, $season_mode = '' ) {
		$sport_slug      = sanitize_key( (string) $sport_slug );
		$current_season  = $sport_slug ? get_current_season_id( $sport_slug ) : 0;
		$current_term    = $current_season ? get_term( $current_season, 'lf_season' ) : null;
		$competitions    = array();
		$seasons         = array();

		foreach ( get_term_ids_by_sport( 'lf_competition', $sport_slug ) as $term_id ) {
			$term = get_term( $term_id, 'lf_competition' );
			if ( $term && ! is_wp_error( $term ) ) {
				$competitions[ $term_id ] = $term->name;
			}
		}

		foreach ( get_term_ids_by_sport( 'lf_season', $sport_slug ) as $term_id ) {
			$term = get_term( $term_id, 'lf_season' );
			if ( $term && ! is_wp_error( $term ) ) {
				$seasons[ $term_id ] = $term->name;
			}
		}
		?>
		<form method="get" class="leagueflow-filter-bar">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<input type="hidden" name="lf_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
			<span class="leagueflow-filter-field">
				<label for="leagueflow-level-filter"><?php esc_html_e( 'Level', 'leagueflow' ); ?></label>
				<select id="leagueflow-level-filter" name="league_level">
					<option value=""><?php esc_html_e( 'All levels', 'leagueflow' ); ?></option>
					<?php foreach ( $this->get_league_level_terms() as $term ) : ?>
						<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $league_level_id, (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>

			<span class="leagueflow-filter-field">
				<label for="leagueflow-competition-filter"><?php esc_html_e( 'Competition', 'leagueflow' ); ?></label>
				<select id="leagueflow-competition-filter" name="competition">
					<option value=""><?php esc_html_e( 'All competitions', 'leagueflow' ); ?></option>
					<?php foreach ( $competitions as $id => $name ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $competition_id, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>

			<span class="leagueflow-filter-field">
				<label for="leagueflow-season-filter"><?php esc_html_e( 'Season', 'leagueflow' ); ?></label>
				<select id="leagueflow-season-filter" name="season">
					<option value=""><?php esc_html_e( 'All seasons', 'leagueflow' ); ?></option>
					<?php if ( $sport_slug ) : ?>
						<?php if ( $current_term instanceof \WP_Term ) : ?>
							<option value="current" <?php selected( 'current', $season_mode ); ?>><?php echo esc_html( sprintf( /* translators: %s: season name */ __( 'Current season (%s)', 'leagueflow' ), $current_term->name ) ); ?></option>
						<?php else : ?>
							<option value="current" disabled><?php esc_html_e( 'Current season (not set for this sport)', 'leagueflow' ); ?></option>
						<?php endif; ?>
					<?php endif; ?>
					<?php foreach ( $seasons as $id => $name ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( 'selected' === $season_mode || '' === $season_mode ? $season_id : -1, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>

			<?php submit_button( __( 'Filter', 'leagueflow' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Read the admin season filter: All (default), Current, or one season.
	 *
	 * @param string $sport_slug Sport context.
	 * @return array{mode: string, id: int}
	 */
	protected function get_admin_season_choice( $sport_slug ) {
		return resolve_admin_season_choice( wp_unslash( $_GET['season'] ?? '' ), $sport_slug ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get the requested sport slug from query vars.
	 *
	 * @return string
	 */
	protected function get_requested_sport_slug() {
		$value = wp_unslash( $_POST['leagueflow_requested_sport'] ?? $_GET['lf_sport'] ?? $_GET['sport'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $value && preg_match( '/^leagueflow-sport-(.+?)(?:-(?:fixtures|standings|brackets|fields))?$/', $this->get_current_admin_plugin_page_slug(), $matches ) ) {
			$value = $matches[1];
		}

		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ), 'lf_sport' );
			return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
		}

		return sanitize_key( (string) $value );
	}
}
