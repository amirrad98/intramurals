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
	 * Seeder.
	 *
	 * @var Seeder
	 */
	protected $seeder;

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
	 */
	public function __construct( Standings_Service $standings_service, Knockout_Service $knockout_service, Renderer $renderer, Seeder $seeder, Sports_Manager $sports_manager, Exporter $exporter, Field_Availability_Manager $field_availability_manager ) {
		$this->standings_service          = $standings_service;
		$this->knockout_service           = $knockout_service;
		$this->renderer                   = $renderer;
		$this->seeder                     = $seeder;
		$this->sports_manager             = $sports_manager;
		$this->exporter                   = $exporter;
		$this->field_availability_manager = $field_availability_manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_filter( 'parent_file', array( $this, 'filter_admin_menu_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_admin_menu_submenu_file' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_lf_team', array( $this, 'save_team_meta' ), 10, 2 );
		add_action( 'save_post_lf_player', array( $this, 'save_player_meta' ), 10, 2 );
		add_action( 'save_post_lf_match', array( $this, 'save_match_meta' ), 10, 2 );
		add_action( 'save_post_lf_calendar_event', array( $this, 'save_calendar_event_meta' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_post_leagueflow_seed_demo', array( $this, 'handle_seed_demo' ) );
		add_action( 'admin_post_leagueflow_save_sports', array( $this, 'handle_save_sports' ) );
		add_action( 'admin_post_leagueflow_export_players', array( $this, 'handle_export_players' ) );
		add_action( 'admin_post_leagueflow_save_field_availability', array( $this, 'handle_save_field_availability' ) );
		add_action( 'admin_post_leagueflow_delete_field_availability', array( $this, 'handle_delete_field_availability' ) );
		add_action( 'admin_post_leagueflow_auto_schedule_matches', array( $this, 'handle_auto_schedule_matches' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_admin_sorting' ) );
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

		add_filter( 'manage_edit-lf_team_sortable_columns', array( $this, 'team_sortable_columns' ) );
		add_filter( 'manage_edit-lf_player_sortable_columns', array( $this, 'player_sortable_columns' ) );
		add_filter( 'manage_edit-lf_match_sortable_columns', array( $this, 'match_sortable_columns' ) );
		add_filter( 'manage_edit-lf_calendar_event_sortable_columns', array( $this, 'calendar_event_sortable_columns' ) );

		add_filter( 'bulk_actions-edit-lf_match', array( $this, 'register_match_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-lf_match', array( $this, 'handle_match_bulk_actions' ), 10, 3 );
	}

	/**
	 * Register admin menus.
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

		add_submenu_page( 'leagueflow', __( 'Overview', 'leagueflow' ), __( 'Overview', 'leagueflow' ), 'edit_posts', 'leagueflow', array( $this, 'render_dashboard_page' ) );
		add_submenu_page( 'leagueflow', __( 'Sports', 'leagueflow' ), __( 'Sports', 'leagueflow' ), 'manage_options', 'leagueflow-sports', array( $this, 'render_sports_page' ) );
		add_submenu_page( 'leagueflow', __( 'League Levels', 'leagueflow' ), __( 'League Levels', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_league_level&post_type=lf_match' );
		add_submenu_page( 'leagueflow', __( 'Field Availability', 'leagueflow' ), __( 'Field Availability', 'leagueflow' ), 'edit_posts', 'leagueflow-fields', array( $this, 'render_field_availability_page' ) );
		add_submenu_page( 'leagueflow', __( 'Utilities', 'leagueflow' ), __( 'Utilities', 'leagueflow' ), 'manage_options', 'leagueflow-utilities', array( $this, 'render_utilities_page' ) );
		add_submenu_page( 'leagueflow', __( 'Settings', 'leagueflow' ), __( 'Settings', 'leagueflow' ), 'manage_options', 'leagueflow-settings', array( $this, 'render_settings_page' ) );

		if ( $this->sports_manager->is_setup_required() ) {
			return;
		}

		$position = 26;

		foreach ( $this->sports_manager->get_enabled_sports() as $sport_slug => $sport ) {
			$menu_slug = 'leagueflow-sport-' . $sport_slug;

			add_menu_page(
				$sport['label'],
				$sport['menu_label'],
				'edit_posts',
				$menu_slug,
				array( $this, 'render_sport_dashboard_page' ),
				$this->sports_manager->get_menu_icon_data_uri( $sport_slug ),
				$position++
			);

			add_submenu_page( $menu_slug, __( 'Overview', 'leagueflow' ), __( 'Overview', 'leagueflow' ), 'edit_posts', $menu_slug, array( $this, 'render_sport_dashboard_page' ) );
			add_submenu_page( $menu_slug, __( 'Teams', 'leagueflow' ), __( 'Teams', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_team&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Add Team', 'leagueflow' ), __( 'Add Team', 'leagueflow' ), 'edit_posts', 'post-new.php?post_type=lf_team&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Players', 'leagueflow' ), __( 'Players', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_player&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Add Player', 'leagueflow' ), __( 'Add Player', 'leagueflow' ), 'edit_posts', 'post-new.php?post_type=lf_player&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Join Requests', 'leagueflow' ), __( 'Join Requests', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_join_request&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Matches', 'leagueflow' ), __( 'Matches', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_match&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Add Match', 'leagueflow' ), __( 'Add Match', 'leagueflow' ), 'edit_posts', 'post-new.php?post_type=lf_match&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Field Availability', 'leagueflow' ), __( 'Field Availability', 'leagueflow' ), 'edit_posts', $menu_slug . '-fields', array( $this, 'render_field_availability_page' ) );
			add_submenu_page( $menu_slug, __( 'Calendar Events', 'leagueflow' ), __( 'Calendar Events', 'leagueflow' ), 'edit_posts', 'edit.php?post_type=lf_calendar_event&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Add Calendar Event', 'leagueflow' ), __( 'Add Event', 'leagueflow' ), 'edit_posts', 'post-new.php?post_type=lf_calendar_event&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'League Levels', 'leagueflow' ), __( 'League Levels', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_league_level&post_type=lf_match&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Competitions', 'leagueflow' ), __( 'Competitions', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_competition&post_type=lf_match&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Seasons', 'leagueflow' ), __( 'Seasons', 'leagueflow' ), 'manage_categories', 'edit-tags.php?taxonomy=lf_season&post_type=lf_match&lf_sport=' . $sport_slug );
			add_submenu_page( $menu_slug, __( 'Standings', 'leagueflow' ), __( 'Standings', 'leagueflow' ), 'edit_posts', $menu_slug . '-standings', array( $this, 'render_standings_page' ) );
			add_submenu_page( $menu_slug, __( 'Knockout Brackets', 'leagueflow' ), __( 'Knockout Brackets', 'leagueflow' ), 'edit_posts', $menu_slug . '-brackets', array( $this, 'render_brackets_page' ) );
		}
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
	 * Render the sports setup and management page.
	 *
	 * @return void
	 */
	public function render_sports_page() {
		$enabled = $this->sports_manager->get_enabled_sport_slugs();
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php esc_html_e( 'Sports Setup', 'leagueflow' ); ?></h1>
			<p><?php esc_html_e( 'Enable the sports you want LeagueFlow to support. Enabled sports get their own left-hand admin menu with sport-specific dashboards, icons, and match detail fields.', 'leagueflow' ); ?></p>

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

				<p><?php submit_button( __( 'Save Sports', 'leagueflow' ), 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a sport-specific dashboard page.
	 *
	 * @return void
	 */
	public function render_sport_dashboard_page() {
		$sport_slug = $this->get_current_requested_sport_slug();
		$sport      = $this->sports_manager->get_definition( $sport_slug );
		$sport_term = get_term_by( 'slug', $sport_slug, 'lf_sport' );
		$sport_id   = ( $sport_term && ! is_wp_error( $sport_term ) ) ? (int) $sport_term->term_id : 0;
		$counts     = $this->get_sport_content_counts( $sport_id );
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php echo esc_html( $sport['label'] ); ?></h1>
			<p><?php echo esc_html( $sport['description'] ); ?></p>

			<div class="leagueflow-admin-grid">
				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Content Overview', 'leagueflow' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr><td><?php esc_html_e( 'Teams', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['teams'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Players', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['players'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Matches', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['matches'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Calendar Events', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['calendar_events'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'League Levels', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['league_levels'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Competitions', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['competitions'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Seasons', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['seasons'] ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Quick Links', 'leagueflow' ); ?></h2>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lf_team&lf_sport=' . $sport_slug ) ); ?>"><?php esc_html_e( 'Manage Teams', 'leagueflow' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lf_player&lf_sport=' . $sport_slug ) ); ?>"><?php esc_html_e( 'Manage Players', 'leagueflow' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lf_match&lf_sport=' . $sport_slug ) ); ?>"><?php esc_html_e( 'Manage Matches', 'leagueflow' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lf_calendar_event&lf_sport=' . $sport_slug ) ); ?>"><?php esc_html_e( 'Manage Calendar Events', 'leagueflow' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=lf_league_level&post_type=lf_match&lf_sport=' . $sport_slug ) ); ?>"><?php esc_html_e( 'Manage League Levels', 'leagueflow' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sport-' . $sport_slug . '-standings' ) ); ?>"><?php esc_html_e( 'View Standings', 'leagueflow' ); ?></a></p>
				</div>

			</div>

			<div class="leagueflow-admin-card">
				<h2><?php esc_html_e( 'League Level Breakdown', 'leagueflow' ); ?></h2>
				<?php $this->render_sport_level_breakdown( $sport_id ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render overview page.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		$enabled_sports = $this->sports_manager->get_enabled_sports();
		$counts = array(
			'teams'        => wp_count_posts( 'lf_team' ),
			'players'      => wp_count_posts( 'lf_player' ),
			'matches'      => wp_count_posts( 'lf_match' ),
			'calendar_events' => wp_count_posts( 'lf_calendar_event' ),
			'league_levels' => wp_count_terms(
				array(
					'taxonomy'   => 'lf_league_level',
					'hide_empty' => false,
				)
			),
			'competitions' => wp_count_terms(
				array(
					'taxonomy'   => 'lf_competition',
					'hide_empty' => false,
				)
			),
			'seasons'      => wp_count_terms(
				array(
					'taxonomy'   => 'lf_season',
					'hide_empty' => false,
				)
			),
		);
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php esc_html_e( 'LeagueFlow', 'leagueflow' ); ?></h1>
			<p><?php esc_html_e( 'Manage competitions, squads, fixtures, standings, and knockout brackets with a classic WordPress workflow. Sports are modular, so one LeagueFlow install can power multiple leagues with shared data structures.', 'leagueflow' ); ?></p>

			<div class="leagueflow-admin-grid">
				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Content Overview', 'leagueflow' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr><td><?php esc_html_e( 'Teams', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['teams']->publish ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Players', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['players']->publish ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Matches', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['matches']->publish ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Calendar Events', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['calendar_events']->publish ); ?></td></tr>
							<tr><td><?php esc_html_e( 'League Levels', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['league_levels'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Competitions', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['competitions'] ); ?></td></tr>
							<tr><td><?php esc_html_e( 'Seasons', 'leagueflow' ); ?></td><td><?php echo esc_html( (string) $counts['seasons'] ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Quick Start', 'leagueflow' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Enable the sports you want from the Sports page.', 'leagueflow' ); ?></li>
						<li><?php esc_html_e( 'Create your league levels, competitions, and seasons first.', 'leagueflow' ); ?></li>
						<li><?php esc_html_e( 'Add teams, then assign players to each team roster.', 'leagueflow' ); ?></li>
						<li><?php esc_html_e( 'Create fixtures and mark completed matches as finished to update standings automatically.', 'leagueflow' ); ?></li>
						<li><?php esc_html_e( 'Use the LeagueFlow blocks or shortcodes on frontend pages for tables, fixtures, rosters, and brackets.', 'leagueflow' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Enabled Sports', 'leagueflow' ); ?></h3>
					<?php if ( $this->sports_manager->is_setup_required() ) : ?>
						<p><?php esc_html_e( 'Initial sport setup is still required. Configure the sports you want to enable before building out competitions and schedules.', 'leagueflow' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $enabled_sports as $sport ) : ?>
								<li>
									<strong><?php echo esc_html( $sport['label'] ); ?></strong>
									<?php echo esc_html( ' - ' . $sport['match_structure'] . ', ' . $sport['score_label'] ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<p class="leagueflow-admin-actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-sports' ) ); ?>"><?php esc_html_e( 'Manage Sports', 'leagueflow' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-utilities' ) ); ?>"><?php esc_html_e( 'Open Utilities', 'leagueflow' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leagueflow-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'leagueflow' ); ?></a>
					</p>
				</div>

			</div>
		</div>
		<?php
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

				<div class="leagueflow-admin-card">
					<h2><?php esc_html_e( 'Other Utilities', 'leagueflow' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Player roster exporter', 'leagueflow' ); ?></strong></td>
								<td><?php esc_html_e( 'Available', 'leagueflow' ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Additional import, audit, and cleanup tools', 'leagueflow' ); ?></td>
								<td><?php esc_html_e( 'Ready for future utilities', 'leagueflow' ); ?></td>
							</tr>
						</tbody>
					</table>
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
		$page            = $this->get_current_page_slug();
		$is_sport_locked = 0 === strpos( $page, 'leagueflow-sport-' );
		$sport_slug      = $is_sport_locked ? $this->get_current_requested_sport_slug() : sanitize_key( wp_unslash( $_GET['sport'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id         = sanitize_key( wp_unslash( $_GET['availability_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing         = $edit_id ? $this->field_availability_manager->get_availability( $edit_id ) : null;
		$availabilities  = $this->field_availability_manager->get_availabilities(
			array(
				'sport_slug' => $sport_slug,
			)
		);
		?>
		<div class="wrap leagueflow-admin-page leagueflow-field-page">
			<h1><?php esc_html_e( 'Field Availability', 'leagueflow' ); ?></h1>
			<p><?php esc_html_e( 'Define when site fields are available, then let the scheduling assistant place matches into open date, time, and venue slots. Existing match fields remain editable for manual overrides.', 'leagueflow' ); ?></p>

			<?php if ( ! $is_sport_locked ) : ?>
				<?php $this->render_field_sport_filter( $page, $sport_slug ); ?>
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
	 * Render sport filter for the global field availability page.
	 *
	 * @param string $page Page slug.
	 * @param string $sport_slug Selected sport slug.
	 * @return void
	 */
	protected function render_field_sport_filter( $page, $sport_slug ) {
		?>
		<form class="leagueflow-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<label for="leagueflow-field-sport-filter"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
			<select id="leagueflow-field-sport-filter" name="sport">
				<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
				<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
					<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $sport_slug, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'leagueflow' ), 'secondary', 'submit', false ); ?>
		</form>
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

			<?php submit_button( $availability['id'] ? __( 'Update Availability', 'leagueflow' ) : __( 'Add Availability', 'leagueflow' ), 'primary', 'submit', false ); ?>
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
		<?php
	}

	/**
	 * Render auto schedule form.
	 *
	 * @param string $sport_slug Current sport slug.
	 * @param string $page Page slug.
	 * @param bool   $is_sport_locked Whether page is inside sport menu.
	 * @return void
	 */
	protected function render_auto_schedule_form( $sport_slug, $page, $is_sport_locked ) {
		$today          = current_time( 'Y-m-d' );
		$default_to     = wp_date( 'Y-m-d', strtotime( '+90 days', current_time( 'timestamp' ) ) );
		$competitions   = $this->get_term_options_for_sport( 'lf_competition', $sport_slug );
		$seasons        = $this->get_term_options_for_sport( 'lf_season', $sport_slug );
		$availabilities = $this->field_availability_manager->get_availability_options( $sport_slug );
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
					<td><?php $this->render_select( 'leagueflow_schedule_mode', $this->field_availability_manager->get_update_modes(), 'both', __( 'Set date/time and venue', 'leagueflow' ) ); ?></td>
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

			<?php submit_button( __( 'Auto Schedule Matches', 'leagueflow' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render open slot preview.
	 *
	 * @param string $sport_slug Current sport slug.
	 * @param string $page Page slug.
	 * @param bool   $is_sport_locked Whether page is inside sport menu.
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
				<label for="leagueflow-preview-sport"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
				<select id="leagueflow-preview-sport" name="sport">
					<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
					<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
						<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $sport_slug, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label for="leagueflow-preview-date"><?php esc_html_e( 'Date', 'leagueflow' ); ?></label>
			<input type="date" id="leagueflow-preview-date" name="preview_date" value="<?php echo esc_attr( $preview_date ); ?>" />
			<label for="leagueflow-preview-availability"><?php esc_html_e( 'Availability', 'leagueflow' ); ?></label>
			<select id="leagueflow-preview-availability" name="preview_availability">
				<option value=""><?php esc_html_e( 'Any matching availability', 'leagueflow' ); ?></option>
				<?php foreach ( $this->field_availability_manager->get_availability_options( $sport_slug ) as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $availability_id, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Preview Slots', 'leagueflow' ), 'secondary', 'submit', false ); ?>
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
		$competition_id  = resolve_term_id( $_GET['competition'] ?? '', 'lf_competition' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$season_id       = resolve_term_id( $_GET['season'] ?? '', 'lf_season' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$league_level_id = resolve_term_id( $_GET['league_level'] ?? '', 'lf_league_level' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sport_slug      = $this->get_current_requested_sport_slug();
		$sport_id        = resolve_term_id( $sport_slug, 'lf_sport' );
		$rows            = $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, $league_level_id );
		$sport           = $this->sports_manager->get_definition( $sport_slug );
		$table_labels    = $sport['table_labels'];
		$page            = $this->get_current_page_slug();
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php esc_html_e( 'Standings', 'leagueflow' ); ?></h1>
			<?php if ( 0 !== strpos( $page, 'leagueflow-sport-' ) ) : ?>
				<?php $this->render_admin_sport_standings_overview( $competition_id, $season_id, $league_level_id ); ?>
			<?php endif; ?>
			<?php $this->render_context_filters( $page, $competition_id, $season_id, $sport_slug, $league_level_id ); ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '#', 'leagueflow' ); ?></th>
						<th><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
						<th><?php esc_html_e( 'P', 'leagueflow' ); ?></th>
						<th><?php esc_html_e( 'W', 'leagueflow' ); ?></th>
						<th><?php esc_html_e( 'D', 'leagueflow' ); ?></th>
						<th><?php esc_html_e( 'L', 'leagueflow' ); ?></th>
						<th><?php echo esc_html( $table_labels['for'] ); ?></th>
						<th><?php echo esc_html( $table_labels['against'] ); ?></th>
						<th><?php echo esc_html( $table_labels['difference'] ); ?></th>
						<th><?php esc_html_e( 'Pts', 'leagueflow' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No standings are available for the selected competition and season yet.', 'leagueflow' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['position'] ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row['team_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
							<td><?php echo esc_html( (string) $row['played'] ); ?></td>
							<td><?php echo esc_html( (string) $row['wins'] ); ?></td>
							<td><?php echo esc_html( (string) $row['draws'] ); ?></td>
							<td><?php echo esc_html( (string) $row['losses'] ); ?></td>
							<td><?php echo esc_html( (string) $row['goals_for'] ); ?></td>
							<td><?php echo esc_html( (string) $row['goals_against'] ); ?></td>
							<td><?php echo esc_html( (string) $row['goal_difference'] ); ?></td>
							<td><strong><?php echo esc_html( (string) $row['points'] ); ?></strong></td>
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
			<p><?php esc_html_e( 'Review calculated standings by sport and league level, or use the filters below for a focused table.', 'leagueflow' ); ?></p>
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
							<div class="leagueflow-admin-sport-standings__body">
								<?php if ( empty( $rows ) ) : ?>
									<p><?php esc_html_e( 'No standings are available for this sport and level yet.', 'leagueflow' ); ?></p>
								<?php else : ?>
									<table class="widefat striped">
										<thead>
											<tr>
												<th><?php esc_html_e( '#', 'leagueflow' ); ?></th>
												<th><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
												<th><?php esc_html_e( 'P', 'leagueflow' ); ?></th>
												<th><?php esc_html_e( 'W', 'leagueflow' ); ?></th>
												<th><?php esc_html_e( 'D', 'leagueflow' ); ?></th>
												<th><?php esc_html_e( 'L', 'leagueflow' ); ?></th>
												<th><?php echo esc_html( $table_labels['for'] ); ?></th>
												<th><?php echo esc_html( $table_labels['against'] ); ?></th>
												<th><?php echo esc_html( $table_labels['difference'] ); ?></th>
												<th><?php esc_html_e( 'Pts', 'leagueflow' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $rows as $row ) : ?>
												<tr>
													<td><?php echo esc_html( (string) $row['position'] ); ?></td>
													<td><a href="<?php echo esc_url( get_edit_post_link( $row['team_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
													<td><?php echo esc_html( (string) $row['played'] ); ?></td>
													<td><?php echo esc_html( (string) $row['wins'] ); ?></td>
													<td><?php echo esc_html( (string) $row['draws'] ); ?></td>
													<td><?php echo esc_html( (string) $row['losses'] ); ?></td>
													<td><?php echo esc_html( (string) $row['goals_for'] ); ?></td>
													<td><?php echo esc_html( (string) $row['goals_against'] ); ?></td>
													<td><?php echo esc_html( (string) $row['goal_difference'] ); ?></td>
													<td><strong><?php echo esc_html( (string) $row['points'] ); ?></strong></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
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
		$competition_id  = resolve_term_id( $_GET['competition'] ?? '', 'lf_competition' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$season_id       = resolve_term_id( $_GET['season'] ?? '', 'lf_season' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$league_level_id = resolve_term_id( $_GET['league_level'] ?? '', 'lf_league_level' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sport_slug      = $this->get_current_requested_sport_slug();
		$competition     = $competition_id ? get_term( $competition_id, 'lf_competition' ) : null;
		$season          = $season_id ? get_term( $season_id, 'lf_season' ) : null;
		$league_level    = $league_level_id ? get_term( $league_level_id, 'lf_league_level' ) : null;
		?>
		<div class="wrap leagueflow-admin-page">
			<h1><?php esc_html_e( 'Knockout Brackets', 'leagueflow' ); ?></h1>
			<?php $this->render_context_filters( $this->get_current_page_slug(), $competition_id, $season_id, $sport_slug, $league_level_id ); ?>
			<p><?php esc_html_e( 'Mark a match as knockout, define its round label and order, and optionally set the next match and home or away slot for automatic winner advancement.', 'leagueflow' ); ?></p>
			<?php
			echo $this->renderer->render_knockout_bracket(
				array(
					'competition'  => $competition ? $competition->slug : '',
					'season'       => $season ? $season->slug : '',
					'sport'        => $sport_slug,
					'league_level' => ( $league_level && ! is_wp_error( $league_level ) ) ? $league_level->slug : '',
				)
			);
			?>
		</div>
		<?php
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

			<div class="leagueflow-settings-secondary">
				<div class="leagueflow-admin-card leagueflow-admin-card--compact">
					<h2><?php esc_html_e( 'Demo Data', 'leagueflow' ); ?></h2>
					<p><?php esc_html_e( 'Generate sample competitions, teams, players, matches, and frontend pages for local testing.', 'leagueflow' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="leagueflow_seed_demo" />
						<?php wp_nonce_field( 'leagueflow_seed_demo', 'leagueflow_seed_demo_nonce' ); ?>
						<?php submit_button( __( 'Generate Demo Data', 'leagueflow' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>

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
					<?php $this->render_user_select( 'lf_manager_user_ids[]', 'lf_manager_user_ids', $managers, true, __( 'Select managers', 'leagueflow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Managers can edit this team profile and roster from the front-end portal.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_league_level_id"><?php esc_html_e( 'League Level', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_league_level_id', $this->get_league_level_options(), $league_level_id, __( 'Recreational', 'leagueflow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Teams, players, matches, standings, and schedules can be separated by league level inside each sport.', 'leagueflow' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Use the featured image as the team logo. Competition and season assignments are available in the sidebar taxonomy boxes.', 'leagueflow' ); ?></p>
		<?php
	}

	/**
	 * Render player details metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_player_metabox( $post ) {
		wp_nonce_field( 'leagueflow_save_player', 'leagueflow_player_nonce' );
		$sport_slug = $this->get_editor_sport_slug( $post->ID );
		$teams      = $this->get_team_options( $sport_slug );
		$user_id    = (int) get_post_meta( $post->ID, 'lf_user_id', true );
		$linked_user = $user_id ? get_user_by( 'id', $user_id ) : false;
		?>
		<input type="hidden" name="leagueflow_requested_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
		<table class="form-table leagueflow-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lf_email"><?php esc_html_e( 'Player Email', 'leagueflow' ); ?></label></th>
				<td>
					<input type="email" id="lf_email" name="lf_email" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_email', true ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Random portal logins can be generated without a real email address.', 'leagueflow' ); ?></p>
				</td>
			</tr>
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
					<p class="description"><?php esc_html_e( 'Use the checkbox below to generate or reset this player login.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Player Login', 'leagueflow' ); ?></th>
				<td>
					<label><input type="checkbox" name="lf_generate_player_login" value="1" /> <?php esc_html_e( 'Generate or reset a random portal login on save', 'leagueflow' ); ?></label>
					<p class="description"><?php esc_html_e( 'After saving, WordPress will show the generated username and temporary password once. Give those credentials to the player.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_team_id"><?php esc_html_e( 'Team', 'leagueflow' ); ?></label></th>
				<td>
					<select id="lf_team_id" name="lf_team_id">
						<option value="0"><?php esc_html_e( 'Select a team', 'leagueflow' ); ?></option>
						<?php foreach ( $teams as $team_id => $team_name ) : ?>
							<option value="<?php echo esc_attr( (string) $team_id ); ?>" <?php selected( (int) get_post_meta( $post->ID, 'lf_team_id', true ), $team_id ); ?>><?php echo esc_html( $team_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_jersey_number"><?php esc_html_e( 'Jersey Number', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="0" id="lf_jersey_number" name="lf_jersey_number" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_jersey_number', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_position"><?php esc_html_e( 'Position', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_position" name="lf_position" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_position', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_age"><?php esc_html_e( 'Age', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="0" id="lf_age" name="lf_age" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_age', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_nationality"><?php esc_html_e( 'Nationality', 'leagueflow' ); ?></label></th>
				<td><input type="text" id="lf_nationality" name="lf_nationality" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_nationality', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Captain', 'leagueflow' ); ?></th>
				<td><label><input type="checkbox" name="lf_is_captain" value="1" <?php checked( (bool) get_post_meta( $post->ID, 'lf_is_captain', true ) ); ?> /> <?php esc_html_e( 'Mark as team captain', 'leagueflow' ); ?></label></td>
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
		$players = array_filter(
			get_posts(
				array(
					'post_type'      => 'lf_player',
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page' => -1,
					'orderby'        => array(
						'meta_value_num' => 'ASC',
						'title'          => 'ASC',
					),
					'meta_key'       => 'lf_jersey_number',
				)
			),
			static function( $player ) use ( $post ) {
				return player_has_team( $player->ID, $post->ID );
			}
		);

		if ( empty( $players ) ) {
			echo '<p>' . esc_html__( 'This team has no players assigned yet.', 'leagueflow' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Player', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'No.', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Login', 'leagueflow' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $players as $player ) {
			$user_id = (int) get_post_meta( $player->ID, 'lf_user_id', true );
			$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_url( get_edit_post_link( $player->ID ) ),
				esc_html( $player->post_title ),
				esc_html( (string) get_post_meta( $player->ID, 'lf_jersey_number', true ) ),
				esc_html( (string) get_post_meta( $player->ID, 'lf_position', true ) ),
				$user instanceof \WP_User ? esc_html( $user->user_login ) : esc_html__( 'Not generated', 'leagueflow' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Render match details metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_match_metabox( $post ) {
		wp_nonce_field( 'leagueflow_save_match', 'leagueflow_match_nonce' );

		$sport_slug      = $this->get_editor_sport_slug( $post->ID );
		$league_level_id = $this->get_editor_league_level_id( $post->ID );
		$teams           = $this->get_team_options( $sport_slug );
		$matches         = $this->get_match_options( $sport_slug, $post->ID );
		$home_team       = (int) get_post_meta( $post->ID, 'lf_home_team_id', true );
		$away_team       = (int) get_post_meta( $post->ID, 'lf_away_team_id', true );
		$winner_team     = (int) get_post_meta( $post->ID, 'lf_winner_team_id', true );
		$datetime        = (string) get_post_meta( $post->ID, 'lf_match_datetime', true );
		$datetime    = $datetime ? substr( str_replace( ' ', 'T', $datetime ), 0, 16 ) : '';
		$availability_options = $this->field_availability_manager->get_availability_options( $sport_slug );
		$availability_id      = (string) get_post_meta( $post->ID, Field_Availability_Manager::META_AVAILABILITY_ID, true );
		$schedule_source      = (string) get_post_meta( $post->ID, Field_Availability_Manager::META_SCHEDULE_SOURCE, true );
		$scheduled_at         = (string) get_post_meta( $post->ID, Field_Availability_Manager::META_SCHEDULED_AT, true );
		?>
		<input type="hidden" name="leagueflow_requested_sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
		<table class="form-table leagueflow-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lf_match_datetime"><?php esc_html_e( 'Date and Time', 'leagueflow' ); ?></label></th>
				<td><input type="datetime-local" id="lf_match_datetime" name="lf_match_datetime" value="<?php echo esc_attr( $datetime ); ?>" /></td>
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
				<th scope="row"><label for="lf_home_score"><?php esc_html_e( 'Home Score', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="0" id="lf_home_score" name="lf_home_score" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_home_score', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_away_score"><?php esc_html_e( 'Away Score', 'leagueflow' ); ?></label></th>
				<td><input type="number" min="0" id="lf_away_score" name="lf_away_score" class="small-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'lf_away_score', true ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_status"><?php esc_html_e( 'Status', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_status', $this->statuses, (string) get_post_meta( $post->ID, 'lf_status', true ), __( 'Select status', 'leagueflow' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th>
				<td>
					<strong><?php echo esc_html( $this->sports_manager->get_definition( $sport_slug )['label'] ); ?></strong>
					<p class="description"><?php esc_html_e( 'Matches inherit their sport from the selected teams. When no teams are selected yet, LeagueFlow uses the current sport menu context or the first enabled sport.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_league_level_id"><?php esc_html_e( 'League Level', 'leagueflow' ); ?></label></th>
				<td>
					<?php $this->render_select( 'lf_league_level_id', $this->get_league_level_options(), $league_level_id, __( 'Recreational', 'leagueflow' ) ); ?>
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
				<td><?php $this->render_select( 'lf_next_match_id', $matches, (int) get_post_meta( $post->ID, 'lf_next_match_id', true ), __( 'Do not advance automatically', 'leagueflow' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_next_match_slot"><?php esc_html_e( 'Advance Into Slot', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_next_match_slot', array( 'home' => __( 'Home', 'leagueflow' ), 'away' => __( 'Away', 'leagueflow' ) ), (string) get_post_meta( $post->ID, 'lf_next_match_slot', true ), __( 'Home', 'leagueflow' ) ); ?></td>
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
		$sport_slug = $this->get_editor_sport_slug( $post->ID );
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
				<td><?php $this->render_select( 'lf_event_type', $this->calendar_event_types, (string) get_post_meta( $post->ID, 'lf_event_type', true ), __( 'Drop-in', 'leagueflow' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_event_status"><?php esc_html_e( 'Status', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_event_status', $this->statuses, (string) get_post_meta( $post->ID, 'lf_event_status', true ), __( 'Scheduled', 'leagueflow' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></th>
				<td>
					<strong><?php echo esc_html( $this->sports_manager->get_definition( $sport_slug )['label'] ); ?></strong>
					<p class="description"><?php esc_html_e( 'Calendar events use the same sport taxonomy as teams and matches. The sport is prefilled from the current sport menu when possible.', 'leagueflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf_league_level_id"><?php esc_html_e( 'League Level', 'leagueflow' ); ?></label></th>
				<td><?php $this->render_select( 'lf_league_level_id', $this->get_league_level_options(), $league_level_id, __( 'Recreational', 'leagueflow' ) ); ?></td>
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

		$manager_ids = sanitize_user_id_list( wp_unslash( $_POST['lf_manager_user_ids'] ?? array() ) );
		update_post_meta( $post_id, 'lf_manager_user_ids', $manager_ids );

		foreach ( $manager_ids as $manager_id ) {
			add_user_role_if_missing( $manager_id, 'leagueflow_team_manager' );
		}

		$this->assign_default_sport_if_missing( $post_id );
		$league_level_id = $this->assign_league_level_from_request( $post_id );

		$sport_id = get_post_primary_term_id( $post_id, 'lf_sport' );

		if ( $sport_id ) {
			$player_ids = get_posts(
				array(
					'post_type'      => 'lf_player',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $player_ids as $player_id ) {
				if ( player_has_team( $player_id, $post_id ) ) {
					wp_set_object_terms( $player_id, array( $sport_id ), 'lf_sport', true );
					if ( $league_level_id ) {
						wp_set_object_terms( $player_id, array( $league_level_id ), 'lf_league_level', false );
					}
				}
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

		$team_id = isset( $_POST['lf_team_id'] ) ? absint( wp_unslash( $_POST['lf_team_id'] ) ) : 0;

		$this->save_player_identity_meta( $post_id );
		$this->maybe_generate_player_login( $post_id );

		set_player_team_ids( $post_id, $team_id ? array( $team_id ) : array() );
		update_post_meta( $post_id, 'lf_jersey_number', isset( $_POST['lf_jersey_number'] ) ? absint( wp_unslash( $_POST['lf_jersey_number'] ) ) : 0 );
		update_post_meta( $post_id, 'lf_position', sanitize_text_field( wp_unslash( $_POST['lf_position'] ?? '' ) ) );
		update_post_meta( $post_id, 'lf_age', isset( $_POST['lf_age'] ) ? absint( wp_unslash( $_POST['lf_age'] ) ) : 0 );
		update_post_meta( $post_id, 'lf_nationality', sanitize_text_field( wp_unslash( $_POST['lf_nationality'] ?? '' ) ) );
		update_post_meta( $post_id, 'lf_is_captain', ! empty( $_POST['lf_is_captain'] ) ? 1 : 0 );

		if ( $team_id ) {
			$team_sport_id = get_post_primary_term_id( $team_id, 'lf_sport' );

			if ( $team_sport_id ) {
				wp_set_object_terms( $post_id, array( $team_sport_id ), 'lf_sport', false );
			} else {
				$this->assign_default_sport_if_missing( $post_id );
			}

			$team_level_id = get_post_primary_term_id( $team_id, 'lf_league_level' );

			if ( $team_level_id ) {
				wp_set_object_terms( $post_id, array( $team_level_id ), 'lf_league_level', false );
			} else {
				$this->assign_default_league_level_if_missing( $post_id );
			}

			return;
		}

		$this->assign_default_sport_if_missing( $post_id );
		$this->assign_default_league_level_if_missing( $post_id );
	}

	/**
	 * Save match metadata.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function save_match_meta( $post_id, $post ) {
		unset( $post );

		if ( $this->syncing_match_title ) {
			return;
		}

		if ( ! $this->can_save( $post_id, 'leagueflow_match_nonce', 'leagueflow_save_match' ) ) {
			return;
		}

		$previous_datetime = (string) get_post_meta( $post_id, 'lf_match_datetime', true );
		$previous_venue    = (string) get_post_meta( $post_id, 'lf_venue', true );
		$datetime = sanitize_text_field( wp_unslash( $_POST['lf_match_datetime'] ?? '' ) );
		$datetime = $datetime ? str_replace( 'T', ' ', $datetime ) : '';
		$venue    = sanitize_text_field( wp_unslash( $_POST['lf_venue'] ?? '' ) );

		$home_team_id = isset( $_POST['lf_home_team_id'] ) ? absint( wp_unslash( $_POST['lf_home_team_id'] ) ) : 0;
		$away_team_id = isset( $_POST['lf_away_team_id'] ) ? absint( wp_unslash( $_POST['lf_away_team_id'] ) ) : 0;

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

		if ( ! $match_level_id && isset( $_POST['lf_league_level_id'] ) ) {
			$match_level_id = absint( wp_unslash( $_POST['lf_league_level_id'] ) );
		}

		if ( ! $match_level_id ) {
			$match_level_id = get_post_primary_term_id( $post_id, 'lf_league_level' );
		}

		if ( ! $match_level_id ) {
			$match_level_id = $this->get_default_league_level_id();
		}

		$status = sanitize_key( wp_unslash( $_POST['lf_status'] ?? 'scheduled' ) );

		if ( ! isset( $this->statuses[ $status ] ) ) {
			$status = 'scheduled';
		}

		$home_score = sanitize_text_field( wp_unslash( $_POST['lf_home_score'] ?? '' ) );
		$away_score = sanitize_text_field( wp_unslash( $_POST['lf_away_score'] ?? '' ) );

		if ( 'finished' === $status ) {
			if ( '' === $home_score ) {
				$home_score = '0';
			}
			if ( '' === $away_score ) {
				$away_score = '0';
			}
		}

		if ( '' !== $home_score && ! is_numeric( $home_score ) ) {
			$home_score = '';
			$this->queue_notice( __( 'Home score must be a number.', 'leagueflow' ), 'error' );
		}

		if ( '' !== $away_score && ! is_numeric( $away_score ) ) {
			$away_score = '';
			$this->queue_notice( __( 'Away score must be a number.', 'leagueflow' ), 'error' );
		}

		$is_knockout   = ! empty( $_POST['lf_is_knockout'] ) ? 1 : 0;
		$winner_team   = isset( $_POST['lf_winner_team_id'] ) ? absint( wp_unslash( $_POST['lf_winner_team_id'] ) ) : 0;
		$next_match_id = isset( $_POST['lf_next_match_id'] ) ? absint( wp_unslash( $_POST['lf_next_match_id'] ) ) : 0;
		$next_slot     = sanitize_key( wp_unslash( $_POST['lf_next_match_slot'] ?? 'home' ) );

		if ( $winner_team && ! in_array( $winner_team, array( $home_team_id, $away_team_id ), true ) ) {
			$winner_team = 0;
			$this->queue_notice( __( 'Winner override must match the selected home or away team.', 'leagueflow' ), 'error' );
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

		$this->update_or_delete_meta( $post_id, 'lf_match_datetime', $datetime );
		$this->update_or_delete_meta( $post_id, 'lf_venue', $venue );
		update_post_meta( $post_id, 'lf_home_team_id', $home_team_id );
		update_post_meta( $post_id, 'lf_away_team_id', $away_team_id );
		$this->update_or_delete_meta( $post_id, 'lf_home_score', '' === $home_score ? '' : (string) absint( $home_score ) );
		$this->update_or_delete_meta( $post_id, 'lf_away_score', '' === $away_score ? '' : (string) absint( $away_score ) );
		update_post_meta( $post_id, 'lf_status', $status );
		update_post_meta( $post_id, 'lf_is_knockout', $is_knockout );

		foreach ( Sports_Manager::get_all_match_meta_keys() as $meta_key ) {
			$this->update_or_delete_meta(
				$post_id,
				$meta_key,
				sanitize_textarea_field( wp_unslash( $_POST[ $meta_key ] ?? '' ) )
			);
		}

		if ( $is_knockout ) {
			update_post_meta( $post_id, 'lf_round_label', sanitize_text_field( wp_unslash( $_POST['lf_round_label'] ?? '' ) ) );
			update_post_meta( $post_id, 'lf_round_order', isset( $_POST['lf_round_order'] ) ? absint( wp_unslash( $_POST['lf_round_order'] ) ) : 0 );
			update_post_meta( $post_id, 'lf_winner_team_id', $winner_team );
			update_post_meta( $post_id, 'lf_next_match_id', $next_match_id );
			update_post_meta( $post_id, 'lf_next_match_slot', in_array( $next_slot, array( 'home', 'away' ), true ) ? $next_slot : 'home' );
		} else {
			delete_post_meta( $post_id, 'lf_round_label' );
			delete_post_meta( $post_id, 'lf_round_order' );
			delete_post_meta( $post_id, 'lf_winner_team_id' );
			delete_post_meta( $post_id, 'lf_next_match_id' );
			delete_post_meta( $post_id, 'lf_next_match_slot' );
		}

		if ( $match_sport_id ) {
			wp_set_object_terms( $post_id, array( $match_sport_id ), 'lf_sport', false );
		} else {
			$this->assign_default_sport_if_missing( $post_id );
		}

		if ( $match_level_id ) {
			wp_set_object_terms( $post_id, array( $match_level_id ), 'lf_league_level', false );
		} else {
			$this->assign_default_league_level_if_missing( $post_id );
		}

		$this->sync_match_title( $post_id, $home_team_id, $away_team_id, $datetime );

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
		} elseif ( $datetime !== $previous_datetime || $venue !== $previous_venue ) {
			if ( '' !== $datetime || '' !== $venue ) {
				update_post_meta( $post_id, Field_Availability_Manager::META_SCHEDULE_SOURCE, 'manual' );
			} else {
				delete_post_meta( $post_id, Field_Availability_Manager::META_SCHEDULE_SOURCE );
			}

			delete_post_meta( $post_id, Field_Availability_Manager::META_AVAILABILITY_ID );
			delete_post_meta( $post_id, Field_Availability_Manager::META_SCHEDULED_AT );
		}
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
			return;
		}

		$start = sanitize_text_field( wp_unslash( $_POST['lf_event_start_datetime'] ?? '' ) );
		$end   = sanitize_text_field( wp_unslash( $_POST['lf_event_end_datetime'] ?? '' ) );
		$start = $start ? str_replace( 'T', ' ', $start ) : '';
		$end   = $end ? str_replace( 'T', ' ', $end ) : '';

		if ( $start && $end && strtotime( $end ) < strtotime( $start ) ) {
			$end = '';
			$this->queue_notice( __( 'Calendar event end time must be after the start time.', 'leagueflow' ), 'error' );
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

		$this->assign_default_sport_if_missing( $post_id );
		$this->assign_league_level_from_request( $post_id );
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
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_player_column( $column, $post_id ) {
		switch ( $column ) {
			case 'sport':
				echo esc_html( $this->sports_manager->get_post_sport_label( $post_id ) );
				break;
			case 'league_level':
				echo esc_html( get_post_league_level_label( $post_id ) );
				break;
			case 'team':
				$team_names = array_filter( array_map( 'get_the_title', get_player_team_ids( $post_id ) ) );
				echo ! empty( $team_names ) ? esc_html( implode( ', ', $team_names ) ) : '&mdash;';
				break;
			case 'jersey_number':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_jersey_number', true ) );
				break;
			case 'position':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_position', true ) );
				break;
			case 'nationality':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_nationality', true ) );
				break;
			case 'captain':
				echo (bool) get_post_meta( $post_id, 'lf_is_captain', true ) ? esc_html__( 'Yes', 'leagueflow' ) : '&mdash;';
				break;
		}
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
				echo esc_html( format_match_datetime( (string) get_post_meta( $post_id, 'lf_match_datetime', true ) ) );
				break;
			case 'venue':
				echo esc_html( (string) get_post_meta( $post_id, 'lf_venue', true ) );
				break;
			case 'score':
				$home = get_post_meta( $post_id, 'lf_home_score', true );
				$away = get_post_meta( $post_id, 'lf_away_score', true );
				echo has_score( $home ) && has_score( $away ) ? esc_html( score_to_int( $home ) . ' - ' . score_to_int( $away ) ) : '&mdash;';
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
				echo esc_html( format_match_datetime( (string) get_post_meta( $post_id, 'lf_event_start_datetime', true ) ) );
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
		$columns['match_time'] = 'match_time';
		$columns['status']     = 'status';
		return $columns;
	}

	/**
	 * Calendar event sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function calendar_event_sortable_columns( $columns ) {
		$columns['event_time'] = 'event_time';
		$columns['event_type'] = 'event_type';
		$columns['status']     = 'status';
		return $columns;
	}

	/**
	 * Render sport filter dropdown on LeagueFlow list tables.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $which Filter location.
	 * @return void
	 */
	public function render_admin_sport_filter( $post_type, $which = 'top' ) {
		if ( ! is_string( $post_type ) || ! in_array( $post_type, $this->get_sport_filterable_post_types(), true ) ) {
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

		if ( $sport_slug && 'lf_join_request' === $post_type ) {
			$meta_query = $query->get( 'meta_query' );

			if ( ! is_array( $meta_query ) ) {
				$meta_query = array();
			}

			$meta_query[] = array(
				'key'   => 'lf_sport_slug',
				'value' => $sport_slug,
			);
			$query->set( 'meta_query', $meta_query );
		}

		if ( 'lf_match' === $post_type && empty( $orderby ) ) {
			$query->set( 'meta_key', 'lf_match_datetime' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'DESC' );
		}

		if ( 'lf_calendar_event' === $post_type && empty( $orderby ) ) {
			$query->set( 'meta_key', 'lf_event_start_datetime' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'DESC' );
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
			$query->set( 'meta_key', $map[ $post_type ][ $orderby ] );
			$query->set( 'orderby', in_array( $orderby, array( 'founded_year', 'jersey_number' ), true ) ? 'meta_value_num' : 'meta_value' );
		}
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

		if ( 0 !== strpos( $doaction, 'leagueflow_set_status_' ) ) {
			return $redirect_to;
		}

		$status = str_replace( 'leagueflow_set_status_', '', $doaction );

		if ( ! isset( $this->statuses[ $status ] ) ) {
			return $redirect_to;
		}

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, 'lf_status', $status );
		}

		return add_query_arg(
			array(
				'leagueflow_bulk_updated' => count( $post_ids ),
				'leagueflow_bulk_status'  => rawurlencode( $this->statuses[ $status ] ),
			),
			$redirect_to
		);
	}

	/**
	 * Output admin notices.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		if ( $this->sports_manager->is_setup_required() && current_user_can( 'manage_options' ) ) {
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

		if ( ! empty( $_GET['leagueflow_sports_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sports updated successfully.', 'leagueflow' ) . '</p></div>';
		}

		$credential_transient_key = 'leagueflow_player_credentials_' . get_current_user_id();
		$credentials              = get_transient( $credential_transient_key );

		if ( is_array( $credentials ) && ! empty( $credentials['username'] ) && ! empty( $credentials['password'] ) ) {
			delete_transient( $credential_transient_key );
			printf(
				'<div class="notice notice-success"><p><strong>%1$s</strong></p><p>%2$s</p><p><code>%3$s</code></p><p><code>%4$s</code></p><p>%5$s</p></div>',
				esc_html__( 'Player portal login generated.', 'leagueflow' ),
				esc_html( $credentials['player'] ?? '' ),
				esc_html( sprintf( 'Username: %s', $credentials['username'] ) ),
				esc_html( sprintf( 'Temporary password: %s', $credentials['password'] ) ),
				esc_html( sprintf( 'Portal: %s', wp_login_url( home_url( '/portal/' ) ) ) )
			);
		}

		$transient_key = 'leagueflow_notices_' . get_current_user_id();
		$notices       = get_transient( $transient_key );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $transient_key );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
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

		$this->seeder->seed_demo_data();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'leagueflow-settings',
					'leagueflow_seeded' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
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

		$sports = isset( $_POST['leagueflow_enabled_sports'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['leagueflow_enabled_sports'] ) ) : array();

		$this->sports_manager->update_enabled_sports( $sports );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => 'leagueflow-sports',
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ) );
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ) );
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leagueflow' ) );
		}

		check_admin_referer( 'leagueflow_auto_schedule_matches', 'leagueflow_auto_schedule_matches_nonce' );

		$page       = sanitize_key( wp_unslash( $_POST['leagueflow_schedule_page'] ?? 'leagueflow-fields' ) );
		$page       = $page ? $page : 'leagueflow-fields';
		$sport_slug = sanitize_key( wp_unslash( $_POST['leagueflow_schedule_sport'] ?? '' ) );

		$result = $this->field_availability_manager->schedule_matches(
			array(
				'sport_slug'      => $sport_slug,
				'competition_id'  => absint( wp_unslash( $_POST['leagueflow_schedule_competition'] ?? 0 ) ),
				'season_id'       => absint( wp_unslash( $_POST['leagueflow_schedule_season'] ?? 0 ) ),
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
		if ( empty( $_POST['lf_generate_player_login'] ) ) {
			return;
		}

		$player = get_post( $post_id );

		if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) {
			return;
		}

		$password = wp_generate_password( 12, false );
		$user_id  = (int) get_post_meta( $post_id, 'lf_user_id', true );
		$user     = $user_id ? get_user_by( 'id', $user_id ) : false;

		if ( $user instanceof \WP_User ) {
			wp_set_password( $password, $user_id );
			add_user_role_if_missing( $user_id, 'leagueflow_player' );
			$username = $user->user_login;
		} else {
			$username = $this->get_unique_player_username( $post_id );
			$email    = $this->get_unique_player_login_email( $post_id );
			$user_id  = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_pass'    => $password,
					'user_email'   => $email,
					'display_name' => $player->post_title,
					'nickname'     => $player->post_title,
					'role'         => 'leagueflow_player',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				$this->queue_notice(
					'error',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not generate player login: %s', 'leagueflow' ),
						$user_id->get_error_message()
					)
				);
				return;
			}

			update_post_meta( $post_id, 'lf_user_id', absint( $user_id ) );
		}

		set_transient(
			'leagueflow_player_credentials_' . get_current_user_id(),
			array(
				'player'   => get_the_title( $post_id ),
				'username' => $username,
				'password' => $password,
			),
			MINUTE_IN_SECONDS
		);
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
	 * Build a unique generated email for player login accounts.
	 *
	 * @param int $post_id Player post ID.
	 * @return string
	 */
	protected function get_unique_player_login_email( $post_id ) {
		$stored_email = sanitize_email( get_post_meta( $post_id, 'lf_email', true ) );

		if ( is_email( $stored_email ) && ! email_exists( $stored_email ) ) {
			return $stored_email;
		}

		$email  = 'leagueflow-player-' . absint( $post_id ) . '@example.invalid';
		$suffix = 2;

		while ( email_exists( $email ) ) {
			$email = 'leagueflow-player-' . absint( $post_id ) . '-' . $suffix . '@example.invalid';
			$suffix++;
		}

		return $email;
	}

	/**
	 * Save player email and linked user metadata.
	 *
	 * @param int $post_id Player post ID.
	 * @return void
	 */
	protected function save_player_identity_meta( $post_id ) {
		$email   = sanitize_email( wp_unslash( $_POST['lf_email'] ?? '' ) );
		$user_id = isset( $_POST['lf_user_id'] ) ? absint( wp_unslash( $_POST['lf_user_id'] ) ) : 0;
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

		if ( $user instanceof \WP_User ) {
			update_post_meta( $post_id, 'lf_user_id', $user_id );
			add_user_role_if_missing( $user_id, 'leagueflow_player' );
		} else {
			delete_post_meta( $post_id, 'lf_user_id' );
		}

		if ( is_email( $email ) ) {
			update_post_meta( $post_id, 'lf_email', strtolower( $email ) );
		} else {
			delete_post_meta( $post_id, 'lf_email' );
		}
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
	 * Get taxonomy select options optionally filtered to a sport.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $sport_slug Sport slug.
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

		printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $name ), $attrs );

		if ( ! $multiple ) {
			printf( '<option value="">%s</option>', esc_html( $placeholder ) );
		}

		foreach ( $this->get_portal_user_options() as $user_id => $label ) {
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
		printf( '<option value="">%s</option>', esc_html( $placeholder ) );

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
	 * @return void
	 */
	protected function queue_notice( $message, $type = 'warning' ) {
		$transient_key = 'leagueflow_notices_' . get_current_user_id();
		$notices       = get_transient( $transient_key );

		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notices[] = array(
			'type'    => $type,
			'message' => $message,
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
	 * @return string
	 */
	protected function get_current_leagueflow_admin_menu_slug() {
		$page = $this->get_current_admin_plugin_page_slug();

		if ( $page ) {
			if ( 'leagueflow' !== $page && 0 !== strpos( $page, 'leagueflow-' ) ) {
				return '';
			}

			$sport_menu_slug = $this->get_sport_menu_slug_from_page_slug( $page );

			return $sport_menu_slug ? $sport_menu_slug : 'leagueflow';
		}

		$taxonomy       = $this->get_current_admin_taxonomy();
		$taxonomy_files = $this->get_leagueflow_taxonomy_submenu_files();

		if ( isset( $taxonomy_files[ $taxonomy ] ) ) {
			$sport_menu_slug = $this->get_current_taxonomy_sport_menu_slug();

			return $sport_menu_slug ? $sport_menu_slug : 'leagueflow';
		}

		$post_type = $this->get_current_admin_post_type();

		if ( ! in_array( $post_type, $this->get_leagueflow_admin_post_types(), true ) ) {
			return '';
		}

		if ( in_array( $post_type, $this->get_leagueflow_sport_menu_post_types(), true ) ) {
			$sport_menu_slug = $this->get_requested_sport_menu_slug();

			if ( $sport_menu_slug ) {
				return $sport_menu_slug;
			}
		}

		return 'leagueflow';
	}

	/**
	 * Get the LeagueFlow submenu file for the current admin screen.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	protected function get_current_leagueflow_admin_submenu_file( $parent_file ) {
		$page = $this->get_current_admin_plugin_page_slug();

		if ( $page && ( 'leagueflow' === $page || 0 === strpos( $page, 'leagueflow-' ) ) ) {
			return $page;
		}

		$taxonomy       = $this->get_current_admin_taxonomy();
		$taxonomy_files = $this->get_leagueflow_taxonomy_submenu_files();

		if ( isset( $taxonomy_files[ $taxonomy ] ) ) {
			$sport_query = '';

			if ( 0 === strpos( $parent_file, 'leagueflow-sport-' ) ) {
				$sport_slug = substr( $parent_file, strlen( 'leagueflow-sport-' ) );

				if ( $this->is_enabled_sport_slug( $sport_slug ) ) {
					$sport_query = '&lf_sport=' . $sport_slug;
				}
			}

			return $taxonomy_files[ $taxonomy ] . $sport_query;
		}

		$post_type = $this->get_current_admin_post_type();

		if ( ! in_array( $post_type, $this->get_leagueflow_admin_post_types(), true ) ) {
			return '';
		}

		$sport_query = '';

		if ( 0 === strpos( $parent_file, 'leagueflow-sport-' ) && in_array( $post_type, $this->get_leagueflow_sport_menu_post_types(), true ) ) {
			$sport_slug = substr( $parent_file, strlen( 'leagueflow-sport-' ) );

			if ( $this->is_enabled_sport_slug( $sport_slug ) ) {
				$sport_query = '&lf_sport=' . $sport_slug;
			}
		}

		if ( 'post-new.php' === $this->get_current_admin_file() && $sport_query ) {
			return 'post-new.php?post_type=' . $post_type . $sport_query;
		}

		return 'edit.php?post_type=' . $post_type . $sport_query;
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
	 * Get the parent menu slug from a sport page slug.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	protected function get_sport_menu_slug_from_page_slug( $page ) {
		if ( ! preg_match( '/^leagueflow-sport-(.+?)(?:-(?:standings|brackets|fields))?$/', $page, $matches ) ) {
			return '';
		}

		$sport_slug = sanitize_key( $matches[1] );

		return $this->is_enabled_sport_slug( $sport_slug ) ? 'leagueflow-sport-' . $sport_slug : '';
	}

	/**
	 * Get the sport-specific parent menu slug from the current request.
	 *
	 * @return string
	 */
	protected function get_requested_sport_menu_slug() {
		$sport_slug = $this->get_requested_sport_slug();

		return $this->is_enabled_sport_slug( $sport_slug ) ? 'leagueflow-sport-' . $sport_slug : '';
	}

	/**
	 * Get the sport-specific parent menu slug for the current taxonomy context.
	 *
	 * @return string
	 */
	protected function get_current_taxonomy_sport_menu_slug() {
		$sport_slug = $this->get_requested_sport_slug();

		if ( ! $sport_slug ) {
			$term_id = absint( wp_unslash( $_REQUEST['tag_ID'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $term_id ) {
				$sport_slug = sanitize_key( (string) get_term_meta( $term_id, 'lf_sport_slug', true ) );
			}
		}

		return $this->is_enabled_sport_slug( $sport_slug ) ? 'leagueflow-sport-' . $sport_slug : '';
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
	 * Get LeagueFlow post types shown inside sport-specific menus.
	 *
	 * @return array<int, string>
	 */
	protected function get_leagueflow_sport_menu_post_types() {
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

		if ( preg_match( '/^leagueflow-sport-(.+?)(?:-(?:standings|brackets|fields))?$/', $page, $matches ) ) {
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
			$counts[ $key ] = (int) count(
				get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'tax_query'      => array(
							array(
								'taxonomy' => 'lf_sport',
								'field'    => 'term_id',
								'terms'    => array( $sport_id ),
							),
						),
					)
				)
			);
		}

		$counts['competitions'] = count( get_term_ids_by_sport( 'lf_competition', $sport->slug ) );
		$counts['seasons']      = count( get_term_ids_by_sport( 'lf_season', $sport->slug ) );
		$counts['league_levels'] = count( $this->get_league_level_terms() );

		return $counts;
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

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Level', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Teams', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Matches', 'leagueflow' ) . '</th>';
		echo '<th>' . esc_html__( 'Calendar Events', 'leagueflow' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->get_league_level_terms() as $level ) {
			$counts = array();

			foreach ( array( 'lf_team' => 'teams', 'lf_match' => 'matches', 'lf_calendar_event' => 'calendar_events' ) as $post_type => $key ) {
				$counts[ $key ] = count(
					get_posts(
						array(
							'post_type'      => $post_type,
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'tax_query'      => array(
								array(
									'taxonomy' => 'lf_sport',
									'field'    => 'term_id',
									'terms'    => array( $sport_id ),
								),
								array(
									'taxonomy' => 'lf_league_level',
									'field'    => 'term_id',
									'terms'    => array( (int) $level->term_id ),
								),
							),
						)
					)
				);
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $level->name ),
				esc_html( (string) $counts['teams'] ),
				esc_html( (string) $counts['matches'] ),
				esc_html( (string) $counts['calendar_events'] )
			);
		}

		echo '</tbody></table>';
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
	 * @param int    $post_id Post ID.
	 * @param string $preferred_slug Optional preferred sport slug.
	 * @return void
	 */
	protected function assign_default_sport_if_missing( $post_id, $preferred_slug = '' ) {
		$existing_sport_id = get_post_primary_term_id( $post_id, 'lf_sport' );

		if ( $existing_sport_id ) {
			return;
		}

		$sport_slug = $preferred_slug ? sanitize_key( $preferred_slug ) : $this->get_requested_sport_slug();

		if ( ! $sport_slug ) {
			$enabled_sports = $this->sports_manager->get_enabled_sport_slugs();
			$sport_slug     = ! empty( $enabled_sports ) ? $enabled_sports[0] : 'soccer';
		}

		$sport = get_term_by( 'slug', $sport_slug, 'lf_sport' );

		if ( $sport && ! is_wp_error( $sport ) ) {
			wp_set_object_terms( $post_id, array( (int) $sport->term_id ), 'lf_sport', false );
		}
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

		return $this->get_current_requested_sport_slug();
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
			$options[ $team->ID ] = $team->post_title;
		}

		return $options;
	}

	/**
	 * Get sport-filtered match options.
	 *
	 * @param string $sport_slug Sport slug.
	 * @param int    $exclude_id Match ID to exclude.
	 * @return array<int, string>
	 */
	protected function get_match_options( $sport_slug = '', $exclude_id = 0 ) {
		$args = array(
			'post_type'      => 'lf_match',
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post__not_in'   => $exclude_id ? array( $exclude_id ) : array(),
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
			$options[ $match->ID ] = $match->post_title;
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
	protected function render_context_filters( $page, $competition_id, $season_id, $sport_slug = '', $league_level_id = 0 ) {
		$is_sport_locked = 0 === strpos( $page, 'leagueflow-sport-' );
		$sport_slug      = sanitize_key( (string) $sport_slug );
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
			<?php if ( $is_sport_locked ) : ?>
				<input type="hidden" name="sport" value="<?php echo esc_attr( $sport_slug ); ?>" />
			<?php else : ?>
				<label for="leagueflow-sport-filter"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
				<select id="leagueflow-sport-filter" name="sport">
					<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
						<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $sport_slug, $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label for="leagueflow-level-filter"><?php esc_html_e( 'Level', 'leagueflow' ); ?></label>
			<select id="leagueflow-level-filter" name="league_level">
				<option value=""><?php esc_html_e( 'All levels', 'leagueflow' ); ?></option>
				<?php foreach ( $this->get_league_level_terms() as $term ) : ?>
					<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $league_level_id, (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="leagueflow-competition-filter"><?php esc_html_e( 'Competition', 'leagueflow' ); ?></label>
			<select id="leagueflow-competition-filter" name="competition">
				<option value=""><?php esc_html_e( 'All competitions', 'leagueflow' ); ?></option>
				<?php foreach ( $competitions as $id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $competition_id, $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="leagueflow-season-filter"><?php esc_html_e( 'Season', 'leagueflow' ); ?></label>
			<select id="leagueflow-season-filter" name="season">
				<option value=""><?php esc_html_e( 'All seasons', 'leagueflow' ); ?></option>
				<?php foreach ( $seasons as $id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $season_id, $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'leagueflow' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Get the requested sport slug from query vars.
	 *
	 * @return string
	 */
	protected function get_requested_sport_slug() {
		$value = wp_unslash( $_POST['leagueflow_requested_sport'] ?? $_GET['lf_sport'] ?? $_GET['sport'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ), 'lf_sport' );
			return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
		}

		return sanitize_key( (string) $value );
	}
}
