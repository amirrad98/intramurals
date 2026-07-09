<?php
/**
 * Front-end team and player portal.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * LeagueFlow front-end portal.
 */
class Portal {

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	protected $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', __NAMESPACE__ . '\\ensure_portal_roles', 1 );
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_action( 'init', array( $this, 'maybe_create_portal_page' ), 100 );
		add_shortcode( 'leagueflow_portal', array( $this, 'render_portal' ) );
		add_action( 'admin_post_leagueflow_portal', array( $this, 'handle_form_submission' ) );
		add_action( 'deleted_user', array( $this, 'handle_wp_user_deleted' ), 10, 3 );
	}

	/**
	 * Register the editable Gutenberg block that renders the portal logic.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type_from_metadata(
			LEAGUEFLOW_PATH . 'blocks/portal',
			array(
				'render_callback' => array( $this, 'render_portal_block' ),
			)
		);
	}

	/**
	 * Create a public portal page if one does not already exist.
	 *
	 * @return void
	 */
	public function maybe_create_portal_page() {
		$page_id = absint( get_option( 'leagueflow_portal_page_id' ) );

		if ( $page_id ) {
			$page = get_post( $page_id );

			if ( $page instanceof \WP_Post ) {
				$this->maybe_upgrade_portal_page_content( $page );
				return;
			}
		}

		$page = get_page_by_path( 'portal', OBJECT, 'page' );

		if ( $page instanceof \WP_Post ) {
			update_option( 'leagueflow_portal_page_id', $page->ID );
			$this->maybe_upgrade_portal_page_content( $page );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Portal', 'leagueflow' ),
				'post_name'    => 'portal',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $this->get_portal_block_content(),
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'leagueflow_portal_page_id', absint( $page_id ) );
		}
	}

	/**
	 * Upgrade generated portal page content to the portal block when safe.
	 *
	 * @param \WP_Post $page Portal page.
	 * @return void
	 */
	protected function maybe_upgrade_portal_page_content( $page ) {
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type ) {
			return;
		}

		$content = $this->get_upgraded_portal_page_content( (string) $page->post_content );

		if ( null === $content ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		);
	}

	/**
	 * Render the portal block.
	 *
	 * The shortcode remains registered for backwards compatibility, but the
	 * generated portal page now stores this dynamic block in editable content.
	 *
	 * @return string
	 */
	public function render_portal_block() {
		return $this->render_portal();
	}

	/**
	 * Get the default block content for the generated portal page.
	 *
	 * @return string
	 */
	protected function get_portal_block_content() {
		return '<!-- wp:leagueflow/portal /-->';
	}

	/**
	 * Upgrade generated portal content to the block while preserving edits.
	 *
	 * @param string $content Existing page content.
	 * @return string|null Upgraded content, or null when no change is needed.
	 */
	protected function get_upgraded_portal_page_content( $content ) {
		$trimmed = trim( $content );

		if ( '' === $trimmed ) {
			return $this->get_portal_block_content();
		}

		if ( false !== strpos( $content, '<!-- wp:leagueflow/portal' ) ) {
			return null;
		}

		$block = $this->get_portal_block_content();
		$count = 0;

		$upgraded = preg_replace(
			'/<!--\s+wp:shortcode\s+-->\s*\[leagueflow_portal\]\s*<!--\s+\/wp:shortcode\s+-->/',
			$block,
			$content,
			-1,
			$count
		);

		if ( $count > 0 && null !== $upgraded ) {
			return $upgraded;
		}

		$upgraded = str_replace( '[leagueflow_portal]', $block, $content, $count );

		return $count > 0 ? $upgraded : null;
	}

	/**
	 * Render the portal shortcode.
	 *
	 * @return string
	 */
	public function render_portal() {
		wp_enqueue_style( 'leagueflow-frontend' );

		if ( ! is_user_logged_in() ) {
			return $this->render_logged_out();
		}

		wp_enqueue_script( 'leagueflow-portal' );

		$user = wp_get_current_user();

		$player_id           = $this->get_player_id_for_user( $user, is_player_registration_enabled() );
		$manager_teams       = $this->get_manager_teams( $user->ID );
		$needs_initial_setup = $this->player_needs_initial_setup( $player_id, $user );
		$captain_open        = is_captain_registration_enabled();

		if ( ! $this->user_can_access_portal( $user, $player_id, $manager_teams ) ) {
			return $this->render_access_denied( $user );
		}

		if ( ! empty( $manager_teams ) ) {
			add_user_role_if_missing( $user->ID, 'leagueflow_team_manager' );
		}

		ob_start();
		?>
		<div class="leagueflow leagueflow-portal alignfull">
			<?php echo wp_kses_post( $this->render_notice() ); ?>

			<div class="leagueflow-portal__layout">
				<?php if ( $captain_open && ! $needs_initial_setup ) : ?>
					<?php echo $this->render_captain_registration_panel( $user ); ?>
				<?php endif; ?>

				<?php if ( ! $needs_initial_setup && ! empty( $manager_teams ) ) : ?>
					<?php echo $this->render_manager_dashboard( $manager_teams ); ?>
				<?php endif; ?>

				<?php if ( $player_id || is_player_registration_enabled() ) : ?>
					<?php echo $this->render_player_dashboard( $player_id, $user ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Process portal forms.
	 *
	 * @return void
	 */
	public function handle_form_submission() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->get_portal_url() ) );
			exit;
		}

		$user = wp_get_current_user();

		if ( ! $this->user_can_access_portal( $user, $this->get_player_id_for_user( $user, false ), $this->get_manager_teams( $user->ID ) ) ) {
			$this->redirect_with_notice( 'access-denied' );
		}

		$portal_action = sanitize_key( wp_unslash( $_POST['leagueflow_portal_action'] ?? '' ) );
		$nonce         = sanitize_text_field( wp_unslash( $_POST['leagueflow_portal_nonce'] ?? '' ) );

		if ( ! $portal_action || ! wp_verify_nonce( $nonce, 'leagueflow_portal_' . $portal_action ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		switch ( $portal_action ) {
			case 'update_player_profile':
				$this->handle_update_player_profile( $user );
				break;

			case 'request_join_team':
				$this->handle_request_join_team( $user );
				break;

			case 'update_team_profile':
				$this->handle_update_team_profile( $user );
				break;

			case 'create_team':
				$this->handle_create_team( $user );
				break;

			case 'add_player':
				$this->handle_add_player( $user );
				break;

			case 'update_roster_player':
				$this->handle_update_roster_player( $user );
				break;

			case 'remove_roster_player':
				$this->handle_remove_roster_player( $user );
				break;

			case 'review_join_request':
				$this->handle_review_join_request( $user );
				break;

			default:
				$this->redirect_with_notice( 'invalid-request' );
		}
	}

	/**
	 * Reset linked player profile names when a user is removed in WordPress.
	 *
	 * Keeps the player record for existing team relationships, but clears
	 * the saved profile name so a later user who reclaims the same email is
	 * required to go through first-time setup again.
	 *
	 * @param int      $user_id  Deleted user ID.
	 * @param int      $reassign Unused reassign ID from the delete action.
	 * @param \WP_User $user     Deleted user object.
	 * @return void
	 */
	protected function handle_wp_user_deleted( $user_id, $reassign = 0, $user = null ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$players = get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_user_id',
						'value' => $user_id,
					),
				),
			)
		);

		foreach ( $players as $player_id ) {
			$player_id = absint( $player_id );

			if ( ! $player_id ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => '',
				)
			);
			delete_post_meta( $player_id, 'lf_user_id' );
		}

		do_action( 'leagueflow_player_profiles_cleared_for_deleted_user', $user_id, $players, $user );
	}

	/**
	 * Render the logged-out portal state.
	 *
	 * @return string
	 */
	protected function render_logged_out() {
		ob_start();
		?>
		<div class="leagueflow leagueflow-portal leagueflow-portal--auth alignfull">
			<div class="leagueflow-portal__panel">
				<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'UNBC Intramurals', 'leagueflow' ); ?></p>
				<h1><?php esc_html_e( 'Sign in to your team portal', 'leagueflow' ); ?></h1>
				<p><?php esc_html_e( 'Use the username and temporary password provided by the intramurals admin to see your profile and upcoming games.', 'leagueflow' ); ?></p>
				<a class="leagueflow-portal__button" href="<?php echo esc_url( wp_login_url( $this->get_portal_url() ) ); ?>"><?php esc_html_e( 'Sign in', 'leagueflow' ); ?></a>
			</div>
		</div>
		<?php
		$default_html = (string) ob_get_clean();

		/**
		 * Filter the portal's logged-out panel.
		 *
		 * Allows an auth provider (e.g. Clerk) to replace the default WordPress
		 * login prompt with its own embedded sign-in experience.
		 *
		 * @param string $default_html Default logged-out markup.
		 * @param string $portal_url   Portal URL to return to after sign-in.
		 */
		return apply_filters( 'leagueflow_portal_logged_out_html', $default_html, $this->get_portal_url() );
	}

	/**
	 * Render an access denied message.
	 *
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_access_denied( $user ) {
		ob_start();
		?>
		<div class="leagueflow leagueflow-portal leagueflow-portal--auth alignfull">
			<div class="leagueflow-portal__panel">
				<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Access denied', 'leagueflow' ); ?></p>
				<h1><?php esc_html_e( 'No portal profile is linked', 'leagueflow' ); ?></h1>
				<p>
					<?php
					printf(
						/* translators: %s: current user email */
						esc_html__( 'This login is not connected to a player profile or managed team. You are signed in as %s.', 'leagueflow' ),
						esc_html( $user->user_login )
					);
					?>
				</p>
				<a class="leagueflow-portal__button" href="<?php echo esc_url( wp_logout_url( $this->get_portal_url() ) ); ?>"><?php esc_html_e( 'Use another account', 'leagueflow' ); ?></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render player dashboard.
	 *
	 * @param int      $player_id Player post ID.
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_player_dashboard( $player_id, $user ) {
		if ( ! $player_id ) {
			return $this->render_panel(
				__( 'Player Profile', 'leagueflow' ),
				'<p>' . esc_html__( 'No player record is linked to this account yet.', 'leagueflow' ) . '</p>'
			);
		}

		$player     = get_post( $player_id );

		if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) {
			return $this->render_panel(
				__( 'Player Profile', 'leagueflow' ),
				'<p>' . esc_html__( 'No player record is linked to this account yet.', 'leagueflow' ) . '</p>'
			);
		}

		$team_ids       = get_player_team_ids( $player_id );
		$team_titles    = $this->render_player_team_links( $team_ids );
		$photo          = get_post_image( $player_id, 'medium', 'leagueflow-portal__avatar' );
		$jersey_number  = (string) get_post_meta( $player_id, 'lf_jersey_number', true );
		$position       = (string) get_post_meta( $player_id, 'lf_position', true );
		$upcoming_games = $this->get_upcoming_matches_for_teams( $team_ids, 6 );
		$profile_form_id = 'leagueflow-profile-form-' . absint( $player_id );
		$needs_initial_setup = $this->player_needs_initial_setup( $player_id, $user );
		$preference_summary  = $this->render_player_sport_level_summary( $player_id );

		if ( $needs_initial_setup ) {
			return $this->render_player_setup( $player_id, $player, $user, $profile_form_id, $photo );
		}

		ob_start();
		?>
		<section class="leagueflow-portal__panel">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Player', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'My Profile', 'leagueflow' ); ?></h2>
				</div>
				<div class="leagueflow-portal__identity">
					<label class="leagueflow-portal__avatar-upload" title="<?php echo esc_attr__( 'Upload profile image', 'leagueflow' ); ?>">
						<?php if ( ! empty( $photo ) ) : ?>
							<?php echo wp_kses_post( $photo ); ?>
						<?php else : ?>
							<span class="leagueflow-portal__avatar-placeholder"><?php echo esc_html( strtoupper( substr( $player->post_title, 0, 1 ) ) ); ?></span>
						<?php endif; ?>
						<span class="leagueflow-portal__avatar-upload-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false">
								<path d="M12 16V5"></path>
								<path d="M8 9l4-4 4 4"></path>
								<path d="M5 19h14"></path>
							</svg>
						</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Upload profile image', 'leagueflow' ); ?></span>
						<input class="leagueflow-portal__avatar-upload-input" form="<?php echo esc_attr( $profile_form_id ); ?>" type="file" name="lf_player_photo" accept="image/*" />
					</label>
				</div>
			</div>

			<div class="leagueflow-portal__summary">
				<span><strong><?php esc_html_e( 'Name', 'leagueflow' ); ?></strong><?php echo esc_html( $player->post_title ); ?></span>
				<span><strong><?php esc_html_e( 'Email', 'leagueflow' ); ?></strong><?php echo esc_html( $user->user_email ); ?></span>
				<span><strong><?php esc_html_e( 'Teams', 'leagueflow' ); ?></strong><?php echo $team_titles ? wp_kses_post( $team_titles ) : esc_html__( 'Unassigned', 'leagueflow' ); ?></span>
				<span><strong><?php esc_html_e( 'Requests', 'leagueflow' ); ?></strong><?php echo esc_html( (string) $this->count_pending_join_requests( $player_id ) ); ?></span>
				<span><strong><?php esc_html_e( 'Upcoming', 'leagueflow' ); ?></strong><?php echo esc_html( (string) count( $upcoming_games ) ); ?></span>
				<?php if ( '' !== $preference_summary ) : ?>
					<span><strong><?php esc_html_e( 'Sport preferences', 'leagueflow' ); ?></strong><?php echo esc_html( $preference_summary ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $jersey_number ) : ?>
					<span><strong><?php esc_html_e( 'No.', 'leagueflow' ); ?></strong><?php echo esc_html( $jersey_number ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $position ) : ?>
					<span><strong><?php esc_html_e( 'Position', 'leagueflow' ); ?></strong><?php echo esc_html( $position ); ?></span>
				<?php endif; ?>
			</div>

			<form id="<?php echo esc_attr( $profile_form_id ); ?>" class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php echo $this->render_hidden_fields( 'update_player_profile' ); ?>
				<label>
					<span><?php esc_html_e( 'Description', 'leagueflow' ); ?></span>
					<textarea name="lf_player_description" rows="6"><?php echo esc_textarea( $player->post_content ); ?></textarea>
				</label>
				<?php echo $this->render_player_sport_level_fields( $player_id ); ?>
				<button type="submit"><?php esc_html_e( 'Save profile', 'leagueflow' ); ?></button>
			</form>

			<div class="leagueflow-portal__subsection">
				<h3><?php esc_html_e( 'Upcoming Games', 'leagueflow' ); ?></h3>
				<?php echo ! empty( $team_ids ) ? $this->render_upcoming_matches_for_teams( $team_ids, 6 ) : '<p>' . esc_html__( 'You are not assigned to a team yet.', 'leagueflow' ) . '</p>'; ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h3><?php esc_html_e( 'Request a Team', 'leagueflow' ); ?></h3>
				<?php echo $this->render_join_request_form( $player_id, $user ); ?>
				<?php echo $this->render_player_join_requests( $player_id ); ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the required first-time profile setup screen.
	 *
	 * @param int      $player_id Player ID.
	 * @param \WP_Post $player Player post.
	 * @param \WP_User $user Current user.
	 * @param string   $profile_form_id Form ID.
	 * @param string   $photo Existing profile image markup.
	 * @return string
	 */
	protected function render_player_setup( $player_id, $player, $user, $profile_form_id, $photo ) {
		$placeholder_initial = strtoupper( substr( (string) ( $user->display_name ?: $user->user_login ), 0, 1 ) );
		$needs_name_setup    = $this->player_name_needs_setup( $player, $user );
		$name_value          = ! $needs_name_setup && $player instanceof \WP_Post ? (string) $player->post_title : '';

		ob_start();
		?>
		<section class="leagueflow-portal__panel leagueflow-portal__panel--setup">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'First-time setup', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'Set Up Your Profile', 'leagueflow' ); ?></h2>
				</div>
				<div class="leagueflow-portal__identity">
					<label class="leagueflow-portal__avatar-upload" title="<?php echo esc_attr__( 'Upload profile image', 'leagueflow' ); ?>">
						<?php if ( ! empty( $photo ) ) : ?>
							<?php echo wp_kses_post( $photo ); ?>
						<?php else : ?>
							<span class="leagueflow-portal__avatar-placeholder"><?php echo esc_html( $placeholder_initial ); ?></span>
						<?php endif; ?>
						<span class="leagueflow-portal__avatar-upload-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false">
								<path d="M12 16V5"></path>
								<path d="M8 9l4-4 4 4"></path>
								<path d="M5 19h14"></path>
							</svg>
						</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Upload profile image', 'leagueflow' ); ?></span>
						<input class="leagueflow-portal__avatar-upload-input" form="<?php echo esc_attr( $profile_form_id ); ?>" type="file" name="lf_player_photo" accept="image/*" />
					</label>
				</div>
			</div>

			<div class="leagueflow-portal__summary">
				<span><strong><?php esc_html_e( 'Email', 'leagueflow' ); ?></strong><?php echo esc_html( $user->user_email ); ?></span>
			</div>

			<form id="<?php echo esc_attr( $profile_form_id ); ?>" class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php echo $this->render_hidden_fields( 'update_player_profile' ); ?>
				<p class="leagueflow-portal__form-help">
					<?php esc_html_e( 'Enter your full name and choose every sport you want to play. Rosters and team requests use these saved details.', 'leagueflow' ); ?>
				</p>
				<label>
					<span><?php esc_html_e( 'Full name', 'leagueflow' ); ?></span>
					<input type="text" name="lf_player_name" value="<?php echo esc_attr( $name_value ); ?>" autocomplete="name" required autofocus />
				</label>
				<?php echo $this->render_player_sport_level_fields( $player_id ); ?>
				<label>
					<span><?php esc_html_e( 'Description', 'leagueflow' ); ?></span>
					<textarea name="lf_player_description" rows="6"><?php echo esc_textarea( $player->post_content ); ?></textarea>
				</label>
				<button type="submit"><?php esc_html_e( 'Finish setup', 'leagueflow' ); ?></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render manager dashboard.
	 *
	 * @param array<int, \WP_Post> $teams Teams.
	 * @return string
	 */
	protected function render_manager_dashboard( $teams ) {
		ob_start();
		?>
		<section class="leagueflow-portal__panel leagueflow-portal__panel--wide">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Manager', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'My Teams', 'leagueflow' ); ?></h2>
				</div>
			</div>

			<div class="leagueflow-portal__teams">
				<?php foreach ( $teams as $team ) : ?>
					<?php echo $this->render_manager_team( $team ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the captain team registration panel.
	 *
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_captain_registration_panel( $user ) {
		$sports_manager = new Sports_Manager();
		$sports         = $sports_manager->get_enabled_sports();
		$name           = $this->get_default_user_full_name( $user );

		if ( empty( $sports ) ) {
			return '';
		}

		ob_start();
		?>
		<section class="leagueflow-portal__panel">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Captain', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'Register a Team', 'leagueflow' ); ?></h2>
				</div>
			</div>

			<form class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo $this->render_hidden_fields( 'create_team' ); ?>
				<label>
					<span><?php esc_html_e( 'Captain name', 'leagueflow' ); ?></span>
					<input type="text" name="lf_captain_name" value="<?php echo esc_attr( $name ); ?>" autocomplete="name" required />
				</label>
				<label>
					<span><?php esc_html_e( 'Sport', 'leagueflow' ); ?></span>
					<select name="lf_sport_slug" required>
						<option value=""><?php esc_html_e( 'Choose a sport', 'leagueflow' ); ?></option>
						<?php foreach ( $sports as $sport_slug => $sport ) : ?>
							<option value="<?php echo esc_attr( $sport_slug ); ?>"><?php echo esc_html( $sport['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'League level', 'leagueflow' ); ?></span>
					<select name="lf_league_level_id" required>
						<?php foreach ( $this->get_league_level_terms() as $level ) : ?>
							<option value="<?php echo esc_attr( (string) $level->term_id ); ?>"><?php echo esc_html( $level->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Team name', 'leagueflow' ); ?></span>
					<input type="text" name="lf_team_name" required />
				</label>
				<label>
					<span><?php esc_html_e( 'Short name', 'leagueflow' ); ?></span>
					<input type="text" name="lf_short_name" />
				</label>
				<label>
					<span><?php esc_html_e( 'Team description', 'leagueflow' ); ?></span>
					<textarea name="lf_team_description" rows="4"></textarea>
				</label>
				<button type="submit"><?php esc_html_e( 'Register team', 'leagueflow' ); ?></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a manager team workspace.
	 *
	 * @param \WP_Post $team Team.
	 * @return string
	 */
	protected function render_manager_team( $team ) {
		$team_id    = (int) $team->ID;
		$team_logo  = get_post_image( $team_id, 'medium', 'leagueflow-portal__team-logo' );
		$players    = $this->get_team_player_posts( $team_id );
		$short_name = (string) get_post_meta( $team_id, 'lf_short_name', true );
		$requests   = $this->get_team_join_requests( $team_id );

		ob_start();
		?>
		<article class="leagueflow-portal-team">
			<header class="leagueflow-portal-team__header">
				<?php if ( ! empty( $team_logo ) ) : ?>
					<?php echo wp_kses_post( $team_logo ); ?>
				<?php endif; ?>
				<div>
					<h3><?php echo esc_html( get_the_title( $team ) ); ?></h3>
					<?php if ( ! empty( $short_name ) ) : ?><p><?php echo esc_html( $short_name ); ?></p><?php endif; ?>
				</div>
			</header>

			<div class="leagueflow-portal__columns">
				<form class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php echo $this->render_hidden_fields( 'update_team_profile' ); ?>
					<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
					<label>
						<span><?php esc_html_e( 'Team name', 'leagueflow' ); ?></span>
						<input type="text" name="lf_team_name" value="<?php echo esc_attr( get_the_title( $team ) ); ?>" required />
					</label>
					<label>
						<span><?php esc_html_e( 'Short name', 'leagueflow' ); ?></span>
						<input type="text" name="lf_short_name" value="<?php echo esc_attr( $short_name ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Team logo', 'leagueflow' ); ?></span>
						<input type="file" name="lf_team_logo" accept="image/*" />
					</label>
					<label>
						<span><?php esc_html_e( 'Team description', 'leagueflow' ); ?></span>
						<textarea name="lf_team_description" rows="6"><?php echo esc_textarea( $team->post_content ); ?></textarea>
					</label>
					<button type="submit"><?php esc_html_e( 'Save team', 'leagueflow' ); ?></button>
				</form>

				<?php if ( is_player_registration_enabled() ) : ?>
					<form class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php echo $this->render_hidden_fields( 'add_player' ); ?>
						<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
						<h4><?php esc_html_e( 'Add player', 'leagueflow' ); ?></h4>
						<label>
							<span><?php esc_html_e( 'Player email', 'leagueflow' ); ?></span>
							<input type="email" name="lf_email" />
						</label>
						<label>
							<span><?php esc_html_e( 'Name', 'leagueflow' ); ?></span>
							<input type="text" name="lf_player_name" required />
						</label>
						<div class="leagueflow-portal__form-grid">
							<label>
								<span><?php esc_html_e( 'No.', 'leagueflow' ); ?></span>
								<input type="number" min="0" name="lf_jersey_number" />
							</label>
							<label>
								<span><?php esc_html_e( 'Position', 'leagueflow' ); ?></span>
								<input type="text" name="lf_position" />
							</label>
						</div>
						<label class="leagueflow-portal__checkbox">
							<input type="checkbox" name="lf_is_captain" value="1" />
							<span><?php esc_html_e( 'Captain', 'leagueflow' ); ?></span>
						</label>
						<button type="submit"><?php esc_html_e( 'Add to roster', 'leagueflow' ); ?></button>
					</form>
				<?php else : ?>
					<div class="leagueflow-portal__form">
						<h4><?php esc_html_e( 'Add player', 'leagueflow' ); ?></h4>
						<p class="leagueflow-portal__form-help"><?php esc_html_e( 'Player registration is currently closed.', 'leagueflow' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h4><?php esc_html_e( 'Join Requests', 'leagueflow' ); ?></h4>
				<?php echo $this->render_team_join_requests( $team_id, $requests ); ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h4><?php esc_html_e( 'Roster', 'leagueflow' ); ?></h4>
				<?php if ( empty( $players ) ) : ?>
					<p><?php esc_html_e( 'This team has no players assigned yet.', 'leagueflow' ); ?></p>
				<?php else : ?>
					<div class="leagueflow-portal-roster">
						<?php foreach ( $players as $player ) : ?>
							<?php echo $this->render_roster_player_form( $team_id, $player ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h4><?php esc_html_e( 'Upcoming Games', 'leagueflow' ); ?></h4>
				<?php echo $this->render_upcoming_matches( $team_id, 5 ); ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the player's request-to-join form.
	 *
	 * @param int      $player_id Player ID.
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_join_request_form( $player_id, $user ) {
		if ( ! is_player_registration_enabled() ) {
			return '<p>' . esc_html__( 'Player registration is currently closed.', 'leagueflow' ) . '</p>';
		}

		$sport_groups = $this->get_requestable_sport_teams();

		if ( empty( $sport_groups ) ) {
			return '<p>' . esc_html__( 'No sports are available for player registration yet.', 'leagueflow' ) . '</p>';
		}

		$player = get_post( $player_id );
		$player_name = $player instanceof \WP_Post ? $player->post_title : get_the_title( $player_id );
		$team_select_id  = 'leagueflow-team-select-' . absint( $player_id );
		$request_help_id = 'leagueflow-request-help-' . absint( $player_id );

		ob_start();
		?>
		<form class="leagueflow-portal__form leagueflow-portal__form--join-request" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php echo $this->render_hidden_fields( 'request_join_team' ); ?>
			<p id="<?php echo esc_attr( $request_help_id ); ?>" class="leagueflow-portal__form-help" data-leagueflow-team-help>
				<?php
				printf(
					/* translators: %s: current player profile name. */
					esc_html__( 'Requesting as %s. Choose a sport first, then pick a team or ask to be placed on one.', 'leagueflow' ),
					esc_html( $player_name )
				);
				?>
			</p>
			<div class="leagueflow-portal__form-grid leagueflow-portal__form-grid--even">
				<label>
					<span><?php esc_html_e( 'Sport', 'leagueflow' ); ?></span>
					<select name="lf_sport_slug" required data-leagueflow-sport-select data-team-target="<?php echo esc_attr( $team_select_id ); ?>" aria-describedby="<?php echo esc_attr( $request_help_id ); ?>">
						<option value=""><?php esc_html_e( 'Choose a sport', 'leagueflow' ); ?></option>
						<?php foreach ( $sport_groups as $sport_slug => $group ) : ?>
							<option value="<?php echo esc_attr( $sport_slug ); ?>"><?php echo esc_html( $group['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Team', 'leagueflow' ); ?></span>
					<select id="<?php echo esc_attr( $team_select_id ); ?>" name="lf_team_id" required data-leagueflow-team-select aria-describedby="<?php echo esc_attr( $request_help_id ); ?>">
						<option value=""><?php esc_html_e( 'Choose a sport first', 'leagueflow' ); ?></option>
						<option value="0" data-placement-option hidden disabled><?php esc_html_e( "I don't have a team yet - please place me", 'leagueflow' ); ?></option>
						<?php foreach ( $sport_groups as $sport_slug => $group ) : ?>
							<?php foreach ( $group['teams'] as $team ) : ?>
								<option value="<?php echo esc_attr( (string) $team->ID ); ?>" data-sport="<?php echo esc_attr( $sport_slug ); ?>"><?php echo esc_html( get_the_title( $team ) ); ?></option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<label>
				<span><?php esc_html_e( 'Message to team manager', 'leagueflow' ); ?></span>
				<textarea name="lf_request_note" rows="3" placeholder="<?php echo esc_attr__( 'Optional: share your availability, experience, or preferred role.', 'leagueflow' ); ?>"></textarea>
			</label>
			<button type="submit"><?php esc_html_e( 'Submit request', 'leagueflow' ); ?></button>
		</form>
		<?php
		unset( $user );
		return (string) ob_get_clean();
	}

	/**
	 * Render a player's submitted join requests.
	 *
	 * @param int $player_id Player ID.
	 * @return string
	 */
	protected function render_player_join_requests( $player_id ) {
		$requests = $this->get_player_join_requests( $player_id );

		if ( empty( $requests ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="leagueflow-portal-requests">
			<?php foreach ( $requests as $request ) : ?>
				<?php
				$team_id    = (int) get_post_meta( $request->ID, 'lf_team_id', true );
				$sport_slug = sanitize_key( (string) get_post_meta( $request->ID, 'lf_sport_slug', true ) );
				$status     = sanitize_key( (string) get_post_meta( $request->ID, 'lf_request_status', true ) );
				$status     = $status ? $status : 'pending';
				$label      = $team_id ? get_the_title( $team_id ) : sprintf(
					/* translators: %s: sport label. */
					__( 'Placement request: %s', 'leagueflow' ),
					$this->get_sport_label( $sport_slug )
				);
				?>
				<article class="leagueflow-portal-request">
					<div>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( ucfirst( $status ) ); ?></span>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render pending join requests for a managed team.
	 *
	 * @param int             $team_id Team ID.
	 * @param array<\WP_Post> $requests Pending requests.
	 * @return string
	 */
	protected function render_team_join_requests( $team_id, $requests ) {
		if ( empty( $requests ) ) {
			return '<p>' . esc_html__( 'No pending join requests.', 'leagueflow' ) . '</p>';
		}

		ob_start();
		?>
		<div class="leagueflow-portal-requests">
			<?php foreach ( $requests as $request ) : ?>
				<?php
				$player_id            = (int) get_post_meta( $request->ID, 'lf_player_id', true );
				$request_team_id      = (int) get_post_meta( $request->ID, 'lf_team_id', true );
				$is_placement_request = ! $request_team_id;
				$note                 = (string) get_post_meta( $request->ID, 'lf_request_note', true );
				$email                = $player_id ? (string) get_post_meta( $player_id, 'lf_email', true ) : '';
				?>
				<article class="leagueflow-portal-request">
					<div>
						<strong><?php echo esc_html( $player_id ? get_the_title( $player_id ) : __( 'Player', 'leagueflow' ) ); ?></strong>
						<?php if ( $email ) : ?><span><?php echo esc_html( $email ); ?></span><?php endif; ?>
						<?php if ( $is_placement_request ) : ?><span><?php esc_html_e( 'Needs a team in this sport', 'leagueflow' ); ?></span><?php endif; ?>
						<?php if ( $note ) : ?><p><?php echo esc_html( $note ); ?></p><?php endif; ?>
					</div>
					<div class="leagueflow-portal-request__actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php echo $this->render_hidden_fields( 'review_join_request' ); ?>
							<input type="hidden" name="lf_join_request_id" value="<?php echo esc_attr( (string) $request->ID ); ?>" />
							<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
							<input type="hidden" name="lf_join_decision" value="approve" />
							<button type="submit"><?php echo esc_html( $is_placement_request ? __( 'Add to my roster', 'leagueflow' ) : __( 'Approve', 'leagueflow' ) ); ?></button>
						</form>
						<?php if ( ! $is_placement_request ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php echo $this->render_hidden_fields( 'review_join_request' ); ?>
								<input type="hidden" name="lf_join_request_id" value="<?php echo esc_attr( (string) $request->ID ); ?>" />
								<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
								<input type="hidden" name="lf_join_decision" value="decline" />
								<button type="submit"><?php esc_html_e( 'Decline', 'leagueflow' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a roster edit form for one player.
	 *
	 * @param int      $team_id Team ID.
	 * @param \WP_Post $player Player.
	 * @return string
	 */
	protected function render_roster_player_form( $team_id, $player ) {
		$player_id = (int) $player->ID;

		ob_start();
		?>
		<div class="leagueflow-portal-roster__card">
			<form class="leagueflow-portal-roster__item" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo $this->render_hidden_fields( 'update_roster_player' ); ?>
				<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
				<input type="hidden" name="lf_player_id" value="<?php echo esc_attr( (string) $player_id ); ?>" />
				<label>
					<span><?php esc_html_e( 'Name', 'leagueflow' ); ?></span>
					<input type="text" name="lf_player_name" value="<?php echo esc_attr( $player->post_title ); ?>" required />
				</label>
			<label>
				<span><?php esc_html_e( 'Player email', 'leagueflow' ); ?></span>
				<input type="email" name="lf_email" value="<?php echo esc_attr( (string) get_post_meta( $player_id, 'lf_email', true ) ); ?>" />
			</label>
				<div class="leagueflow-portal__form-grid">
					<label>
						<span><?php esc_html_e( 'No.', 'leagueflow' ); ?></span>
						<input type="number" min="0" name="lf_jersey_number" value="<?php echo esc_attr( (string) get_post_meta( $player_id, 'lf_jersey_number', true ) ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Position', 'leagueflow' ); ?></span>
						<input type="text" name="lf_position" value="<?php echo esc_attr( (string) get_post_meta( $player_id, 'lf_position', true ) ); ?>" />
					</label>
				</div>
				<div class="leagueflow-portal-roster__actions">
					<label class="leagueflow-portal__checkbox">
						<input type="checkbox" name="lf_is_captain" value="1" <?php checked( (bool) get_post_meta( $player_id, 'lf_is_captain', true ) ); ?> />
						<span><?php esc_html_e( 'Captain', 'leagueflow' ); ?></span>
					</label>
					<button type="submit"><?php esc_html_e( 'Save', 'leagueflow' ); ?></button>
				</div>
			</form>
			<form class="leagueflow-portal-roster__remove" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo $this->render_hidden_fields( 'remove_roster_player' ); ?>
				<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
				<input type="hidden" name="lf_player_id" value="<?php echo esc_attr( (string) $player_id ); ?>" />
				<button type="submit"><?php esc_html_e( 'Remove from team', 'leagueflow' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handle player profile updates.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_update_player_profile( $user ) {
		$player_id = $this->get_player_id_for_user( $user, is_player_registration_enabled() );

		if ( ! $player_id ) {
			$this->redirect_with_notice( 'player-missing' );
		}

		$player             = get_post( $player_id );
		$needs_name_setup   = $this->player_name_needs_setup( $player, $user );
		$current_name       = $player instanceof \WP_Post ? (string) $player->post_title : '';
		$has_name_submission = array_key_exists( 'lf_player_name', $_POST );
		$submitted_name     = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );
		$description        = wp_kses_post( wp_unslash( $_POST['lf_player_description'] ?? '' ) );
		$sport_preferences  = $this->sanitize_player_sport_level_submission(
			$_POST['lf_player_sports'] ?? array(),
			$_POST['lf_player_sport_levels'] ?? array()
		);
		$profile_setup_done = false;

		if ( $needs_name_setup || $has_name_submission ) {
			if ( '' === $submitted_name || $this->is_placeholder_player_name( $submitted_name, $user ) ) {
				$this->redirect_with_notice( 'name-setup-required' );
			}

			$current_name       = $submitted_name;
			$profile_setup_done = true;
		}

		if ( $this->player_sport_level_fields_are_available() && empty( $sport_preferences ) ) {
			$this->redirect_with_notice( 'sport-level-required' );
		}

		$post_data = array(
			'ID'           => $player_id,
			'post_content' => $description,
		);

		if ( $profile_setup_done ) {
			$post_data['post_title'] = $current_name;
		}

		wp_update_post( $post_data );
		$this->save_player_sport_level_preferences( $player_id, $sport_preferences );

		if ( $profile_setup_done ) {
			$this->sync_user_display_name( $user, $current_name );
		}

		$this->maybe_save_uploaded_image( 'lf_player_photo', $player_id );
		$this->redirect_with_notice( $profile_setup_done ? 'profile-setup-complete' : 'profile-saved' );
	}

	/**
	 * Handle a player request to join an existing team.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_request_join_team( $user ) {
		if ( ! is_player_registration_enabled() ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$player_id = $this->get_player_id_for_user( $user, true );

		if ( ! $player_id ) {
			$this->redirect_with_notice( 'player-missing' );
		}

		$sport_slug    = sanitize_key( wp_unslash( $_POST['lf_sport_slug'] ?? '' ) );
		$team_id_value = isset( $_POST['lf_team_id'] ) && ! is_array( $_POST['lf_team_id'] ) ? sanitize_text_field( wp_unslash( $_POST['lf_team_id'] ) ) : '';
		$team_id       = absint( $team_id_value );
		$is_placement  = '0' === $team_id_value;
		$note          = sanitize_textarea_field( wp_unslash( $_POST['lf_request_note'] ?? '' ) );
		$player        = get_post( $player_id );
		$name          = $player instanceof \WP_Post ? $player->post_title : '';

		if ( '' === trim( (string) $name ) || '' === $sport_slug || ( '' === $team_id_value || ( ! $team_id && ! $is_placement ) ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$sports_manager = new Sports_Manager();

		if ( ! in_array( $sport_slug, $sports_manager->get_enabled_sport_slugs(), true ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		if ( $is_placement ) {
			if ( $this->player_has_team_in_sport( $player_id, $sport_slug ) ) {
				$this->redirect_with_notice( 'placement-request-member' );
			}
		} else {
			$team = get_post( $team_id );

			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
				$this->redirect_with_notice( 'invalid-request' );
			}

			$team_sport_slug = get_post_primary_term_slug( $team_id, 'lf_sport' );

			if ( $team_sport_slug !== $sport_slug ) {
				$this->redirect_with_notice( 'invalid-request' );
			}

			if ( player_has_team( $player_id, $team_id ) ) {
				$this->redirect_with_notice( 'join-request-member' );
			}
		}

		if ( $this->find_pending_join_request( $player_id, $team_id, $sport_slug ) ) {
			$this->redirect_with_notice( $is_placement ? 'placement-request-exists' : 'join-request-exists' );
		}

		if ( $is_placement ) {
			$title = sprintf(
				/* translators: 1: player name 2: sport label */
				__( '%1$s needs a %2$s team', 'leagueflow' ),
				$name,
				$this->get_sport_label( $sport_slug )
			);
		} else {
			$title = sprintf(
				/* translators: 1: player name 2: team name */
				__( '%1$s requested %2$s', 'leagueflow' ),
				$name,
				get_the_title( $team_id )
			);
		}

		$request_id = wp_insert_post(
			array(
				'post_type'   => 'lf_join_request',
				'post_status' => 'private',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $request_id ) || ! $request_id ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		update_post_meta( $request_id, 'lf_player_id', $player_id );
		update_post_meta( $request_id, 'lf_user_id', (int) $user->ID );
		update_post_meta( $request_id, 'lf_team_id', $is_placement ? 0 : $team_id );
		update_post_meta( $request_id, 'lf_sport_slug', $sport_slug );
		update_post_meta( $request_id, 'lf_request_status', 'pending' );
		update_post_meta( $request_id, 'lf_request_note', $note );
		update_post_meta( $request_id, 'lf_request_type', $is_placement ? 'placement' : 'team' );

		$this->redirect_with_notice( $is_placement ? 'placement-request-sent' : 'join-request-sent' );
	}

	/**
	 * Handle captain team registration.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_create_team( $user ) {
		if ( ! is_captain_registration_enabled() ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$sports_manager = new Sports_Manager();
		$sport_slug     = sanitize_key( wp_unslash( $_POST['lf_sport_slug'] ?? '' ) );
		$level_id       = absint( wp_unslash( $_POST['lf_league_level_id'] ?? 0 ) );
		$team_name      = sanitize_text_field( wp_unslash( $_POST['lf_team_name'] ?? '' ) );
		$short_name     = sanitize_text_field( wp_unslash( $_POST['lf_short_name'] ?? '' ) );
		$captain_name   = sanitize_text_field( wp_unslash( $_POST['lf_captain_name'] ?? '' ) );
		$description    = wp_kses_post( wp_unslash( $_POST['lf_team_description'] ?? '' ) );
		$enabled_sports = $sports_manager->get_enabled_sport_slugs();

		if ( '' === $sport_slug || ! in_array( $sport_slug, $enabled_sports, true ) || '' === $team_name || '' === $captain_name ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$level = $level_id ? get_term( $level_id, 'lf_league_level' ) : null;

		if ( ! $level || is_wp_error( $level ) ) {
			$level_id = $this->get_default_league_level_id();
		}

		$duplicate_team_id = $this->find_team_id_by_title_and_sport( $team_name, $sport_slug );

		if ( $duplicate_team_id ) {
			$this->redirect_with_notice( 'team-exists' );
		}

		$team_id = wp_insert_post(
			array(
				'post_type'    => 'lf_team',
				'post_status'  => 'publish',
				'post_title'   => $team_name,
				'post_content' => $description,
				'post_author'  => (int) $user->ID,
			),
			true
		);

		if ( is_wp_error( $team_id ) || ! $team_id ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		update_post_meta( $team_id, 'lf_short_name', $short_name );
		update_post_meta( $team_id, 'lf_manager_user_ids', array( (int) $user->ID ) );

		$sport_term = get_term_by( 'slug', $sport_slug, 'lf_sport' );

		if ( $sport_term && ! is_wp_error( $sport_term ) ) {
			wp_set_object_terms( $team_id, array( (int) $sport_term->term_id ), 'lf_sport', false );
		}

		if ( $level_id ) {
			wp_set_object_terms( $team_id, array( $level_id ), 'lf_league_level', false );
		}

		add_user_role_if_missing( $user->ID, 'leagueflow_team_manager' );

		$captain_id = $this->get_or_create_captain_player_id( $user, $captain_name );

		if ( $captain_id ) {
			add_player_team_id( $captain_id, (int) $team_id );
			update_post_meta( $captain_id, 'lf_is_captain', 1 );
			$this->assign_player_sport_from_team( $captain_id, (int) $team_id );
		}

		$this->redirect_with_notice( 'team-registered' );
	}

	/**
	 * Approve or decline a team join request.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_review_join_request( $user ) {
		$request_id = absint( wp_unslash( $_POST['lf_join_request_id'] ?? 0 ) );
		$decision   = sanitize_key( wp_unslash( $_POST['lf_join_decision'] ?? '' ) );
		$request    = get_post( $request_id );

		if ( ! $request instanceof \WP_Post || 'lf_join_request' !== $request->post_type ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$request_team_id      = (int) get_post_meta( $request_id, 'lf_team_id', true );
		$submitted_team_id    = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );
		$is_placement_request = ! $request_team_id;
		$team_id              = $is_placement_request ? $submitted_team_id : $request_team_id;
		$player_id            = (int) get_post_meta( $request_id, 'lf_player_id', true );
		$status               = sanitize_key( (string) get_post_meta( $request_id, 'lf_request_status', true ) );

		if ( 'pending' !== $status || ! $team_id || ! $player_id || ! $this->user_can_manage_team( $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		if ( ! $is_placement_request && $submitted_team_id && $submitted_team_id !== $request_team_id ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		if ( $is_placement_request ) {
			$request_sport_slug = sanitize_key( (string) get_post_meta( $request_id, 'lf_sport_slug', true ) );

			if ( '' === $request_sport_slug || get_post_primary_term_slug( $team_id, 'lf_sport' ) !== $request_sport_slug ) {
				$this->redirect_with_notice( 'team-denied' );
			}

			if ( 'decline' === $decision ) {
				$this->redirect_with_notice( 'invalid-request' );
			}
		}

		if ( 'approve' === $decision ) {
			add_player_team_id( $player_id, $team_id );
			$this->assign_player_sport_from_team( $player_id, $team_id );
			update_post_meta( $request_id, 'lf_request_status', 'approved' );
			update_post_meta( $request_id, 'lf_team_id', $team_id );
			$this->redirect_with_notice( 'join-request-approved' );
		}

		if ( 'decline' === $decision ) {
			update_post_meta( $request_id, 'lf_request_status', 'declined' );
			$this->redirect_with_notice( 'join-request-declined' );
		}

		$this->redirect_with_notice( 'invalid-request' );
	}

	/**
	 * Handle team profile updates.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_update_team_profile( $user ) {
		$team_id = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_team( $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$team_name = sanitize_text_field( wp_unslash( $_POST['lf_team_name'] ?? '' ) );

		if ( '' === $team_name ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		wp_update_post(
			array(
				'ID'           => $team_id,
				'post_title'   => $team_name,
				'post_content' => wp_kses_post( wp_unslash( $_POST['lf_team_description'] ?? '' ) ),
			)
		);

		update_post_meta( $team_id, 'lf_short_name', sanitize_text_field( wp_unslash( $_POST['lf_short_name'] ?? '' ) ) );
		$this->maybe_save_uploaded_image( 'lf_team_logo', $team_id );
		$this->redirect_with_notice( 'team-saved' );
	}

	/**
	 * Handle adding a roster player.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_add_player( $user ) {
		if ( ! is_player_registration_enabled() ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$team_id = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_team( $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$email = strtolower( sanitize_email( wp_unslash( $_POST['lf_email'] ?? '' ) ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$this->redirect_with_notice( 'invalid-email' );
		}

		$linked_user = get_user_by( 'email', $email );
		$name        = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );

		if ( '' === $name && $linked_user instanceof \WP_User ) {
			$name = $linked_user->display_name;
		}

		if ( '' === $name ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$player_id = $email ? $this->find_player_id_by_email( $email ) : 0;

		if ( ! $player_id && $linked_user instanceof \WP_User ) {
			$player_id = $this->find_player_id_by_user( $linked_user->ID );
		}

		if ( ! $player_id ) {
			$player_id = wp_insert_post(
				array(
					'post_type'   => 'lf_player',
					'post_status' => 'private',
					'post_title'  => $name,
					'post_author' => (int) $user->ID,
				)
			);
		}

		if ( ! $player_id || is_wp_error( $player_id ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$this->save_roster_player_fields( absint( $player_id ), $team_id, $email, $name );
		$this->redirect_with_notice( 'player-added' );
	}

	/**
	 * Handle roster player updates.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_update_roster_player( $user ) {
		$team_id   = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );
		$player_id = absint( wp_unslash( $_POST['lf_player_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_roster_player( $player_id, $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$email = strtolower( sanitize_email( wp_unslash( $_POST['lf_email'] ?? '' ) ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$this->redirect_with_notice( 'invalid-email' );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );
		$this->save_roster_player_fields( $player_id, $team_id, $email, $name );
		$this->redirect_with_notice( 'roster-saved' );
	}

	/**
	 * Handle roster removal.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_remove_roster_player( $user ) {
		$team_id   = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );
		$player_id = absint( wp_unslash( $_POST['lf_player_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_roster_player( $player_id, $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		remove_player_team_id( $player_id, $team_id );
		update_post_meta( $player_id, 'lf_is_captain', 0 );
		$this->redirect_with_notice( 'player-removed' );
	}

	/**
	 * Save manager-editable roster fields.
	 *
	 * @param int    $player_id Player ID.
	 * @param int    $team_id Team ID.
	 * @param string $email Player email.
	 * @param string $name Player name.
	 * @return void
	 */
	protected function save_roster_player_fields( $player_id, $team_id, $email, $name ) {
		if ( '' !== $name ) {
			wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => $name,
				)
			);
		}

		add_player_team_id( $player_id, $team_id );
		update_post_meta( $player_id, 'lf_jersey_number', absint( wp_unslash( $_POST['lf_jersey_number'] ?? 0 ) ) );
		update_post_meta( $player_id, 'lf_position', sanitize_text_field( wp_unslash( $_POST['lf_position'] ?? '' ) ) );
		update_post_meta( $player_id, 'lf_is_captain', ! empty( $_POST['lf_is_captain'] ) ? 1 : 0 );

		if ( '' !== $email ) {
			update_post_meta( $player_id, 'lf_email', $email );

			$linked_user = get_user_by( 'email', $email );

			if ( $linked_user instanceof \WP_User ) {
				update_post_meta( $player_id, 'lf_user_id', (int) $linked_user->ID );
				add_user_role_if_missing( (int) $linked_user->ID, 'leagueflow_player' );
			} else {
				delete_post_meta( $player_id, 'lf_user_id' );
			}
		}

		$this->assign_player_sport_from_team( $player_id, $team_id );
	}

	/**
	 * Save an uploaded image as a post thumbnail.
	 *
	 * @param string $field File input field.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	protected function maybe_save_uploaded_image( $field, $post_id ) {
		if ( empty( $_FILES[ $field ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) {
			return;
		}

		if ( UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
			$this->redirect_with_notice( 'upload-error' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload(
			$field,
			$post_id,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'gif'          => 'image/gif',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->redirect_with_notice( 'upload-error' );
		}

		set_post_thumbnail( $post_id, absint( $attachment_id ) );
	}

	/**
	 * Check whether a player still appears to be using their login code as a name.
	 *
	 * @param int      $player_id Player ID.
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function player_needs_initial_setup( $player_id, $user ) {
		$player = $player_id ? get_post( $player_id ) : null;

		return $this->player_name_needs_setup( $player, $user ) || $this->player_sport_level_needs_setup( $player_id );
	}

	/**
	 * Check whether a player still appears to be using their login code as a name.
	 *
	 * @param \WP_Post $player Player post.
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function player_name_needs_setup( $player, $user ) {
		if ( ! $player instanceof \WP_Post || ! $user instanceof \WP_User ) {
			return false;
		}

		return $this->is_placeholder_player_name( (string) $player->post_title, $user );
	}

	/**
	 * Check whether a submitted profile name is still a login identifier.
	 *
	 * @param string   $name Submitted/player name.
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function is_placeholder_player_name( $name, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$name       = strtolower( trim( $name ) );
		$login      = strtolower( trim( (string) $user->user_login ) );
		$email      = strtolower( trim( (string) $user->user_email ) );
		$email_name = is_email( $email ) ? strtolower( strtok( $email, '@' ) ) : '';

		return '' === $name || $name === $login || ( '' !== $email_name && $name === $email_name );
	}

	/**
	 * Check whether player sport and level preferences still need setup.
	 *
	 * @param int $player_id Player ID.
	 * @return bool
	 */
	protected function player_sport_level_needs_setup( $player_id ) {
		if ( ! $player_id || ! $this->player_sport_level_fields_are_available() ) {
			return false;
		}

		return empty( $this->get_player_sport_level_preferences( $player_id ) );
	}

	/**
	 * Whether sport/level setup can be rendered and validated.
	 *
	 * @return bool
	 */
	protected function player_sport_level_fields_are_available() {
		return ! empty( $this->get_player_preference_sports() ) && ! empty( $this->get_league_level_terms() );
	}

	/**
	 * Render sport and level preference controls for a player profile form.
	 *
	 * @param int $player_id Player ID.
	 * @return string
	 */
	protected function render_player_sport_level_fields( $player_id ) {
		$sports = $this->get_player_preference_sports();
		$levels = $this->get_league_level_terms();

		if ( empty( $sports ) || empty( $levels ) ) {
			return '';
		}

		$preferences     = $this->get_player_sport_level_preferences( $player_id );
		$default_level_id = $this->get_default_league_level_id();
		$selected_slugs  = array_keys( $preferences );

		if ( empty( $selected_slugs ) && 1 === count( $sports ) ) {
			$sport_slugs    = array_keys( $sports );
			$selected_slugs = array( reset( $sport_slugs ) );
		}

		ob_start();
		?>
		<fieldset class="leagueflow-portal__sport-preferences" data-leagueflow-player-sport-preferences>
			<legend><?php esc_html_e( 'Sport sign-up preferences', 'leagueflow' ); ?></legend>
			<p class="leagueflow-portal__form-help"><?php esc_html_e( 'Select every sport you want to play, then choose the level you prefer for each selected sport.', 'leagueflow' ); ?></p>
			<div class="leagueflow-portal__sport-preference-list">
				<?php foreach ( $sports as $sport_slug => $sport ) : ?>
					<?php
					$is_selected = in_array( $sport_slug, $selected_slugs, true );
					$level_id    = isset( $preferences[ $sport_slug ]['level_id'] ) ? (int) $preferences[ $sport_slug ]['level_id'] : $default_level_id;
					$input_id    = 'leagueflow-player-sport-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
					$select_id   = 'leagueflow-player-level-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
					?>
					<div class="leagueflow-portal__sport-preference-row">
						<label class="leagueflow-portal__sport-choice" for="<?php echo esc_attr( $input_id ); ?>">
							<input id="<?php echo esc_attr( $input_id ); ?>" type="checkbox" name="lf_player_sports[]" value="<?php echo esc_attr( $sport_slug ); ?>" <?php checked( $is_selected ); ?> data-leagueflow-player-sport-toggle />
							<span><?php echo esc_html( $sport['label'] ); ?></span>
						</label>
						<label class="leagueflow-portal__sport-level" for="<?php echo esc_attr( $select_id ); ?>">
							<span><?php esc_html_e( 'Level', 'leagueflow' ); ?></span>
							<select id="<?php echo esc_attr( $select_id ); ?>" name="lf_player_sport_levels[<?php echo esc_attr( $sport_slug ); ?>]" data-leagueflow-player-level-select>
								<?php foreach ( $levels as $level ) : ?>
									<option value="<?php echo esc_attr( (string) $level->term_id ); ?>" <?php selected( $level_id, (int) $level->term_id ); ?>><?php echo esc_html( $level->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sanitize posted sport and per-sport level preferences.
	 *
	 * @param mixed $sports Posted selected sport slugs.
	 * @param mixed $levels Posted level IDs keyed by sport slug.
	 * @return array<string, int>
	 */
	protected function sanitize_player_sport_level_submission( $sports, $levels ) {
		$sports         = is_array( $sports ) ? $sports : array();
		$levels         = is_array( $levels ) ? $levels : array();
		$enabled_sports = array_keys( $this->get_player_preference_sports() );
		$valid_levels   = array();

		foreach ( $this->get_league_level_terms() as $level ) {
			$valid_levels[] = (int) $level->term_id;
		}

		if ( empty( $enabled_sports ) || empty( $valid_levels ) ) {
			return array();
		}

		$default_level_id = $this->get_default_league_level_id();
		$preferences      = array();

		foreach ( $sports as $sport_slug ) {
			if ( is_array( $sport_slug ) ) {
				continue;
			}

			$sport_slug = sanitize_key( wp_unslash( (string) $sport_slug ) );

			if ( '' === $sport_slug || ! in_array( $sport_slug, $enabled_sports, true ) ) {
				continue;
			}

			$level_id = isset( $levels[ $sport_slug ] ) && ! is_array( $levels[ $sport_slug ] )
				? absint( wp_unslash( (string) $levels[ $sport_slug ] ) )
				: 0;

			if ( ! in_array( $level_id, $valid_levels, true ) ) {
				$level_id = $default_level_id;
			}

			if ( $level_id ) {
				$preferences[ $sport_slug ] = $level_id;
			}
		}

		return $preferences;
	}

	/**
	 * Save sport and level preferences onto player taxonomies and mapping meta.
	 *
	 * @param int                $player_id Player ID.
	 * @param array<string, int> $preferences Sport slug to level ID map.
	 * @return void
	 */
	protected function save_player_sport_level_preferences( $player_id, $preferences ) {
		if ( ! $this->player_sport_level_fields_are_available() ) {
			return;
		}

		$sport_term_ids = array();
		$level_ids      = array();
		$mapping        = array();

		foreach ( $preferences as $sport_slug => $level_id ) {
			$sport = get_term_by( 'slug', sanitize_key( $sport_slug ), 'lf_sport' );
			$level = get_term( absint( $level_id ), 'lf_league_level' );

			if ( ! $sport || is_wp_error( $sport ) || ! $level || is_wp_error( $level ) ) {
				continue;
			}

			$sport_term_ids[]       = (int) $sport->term_id;
			$level_ids[]            = (int) $level->term_id;
			$mapping[ $sport_slug ] = (int) $level->term_id;
		}

		if ( empty( $sport_term_ids ) || empty( $level_ids ) ) {
			return;
		}

		wp_set_object_terms( $player_id, array_values( array_unique( $sport_term_ids ) ), 'lf_sport', false );
		wp_set_object_terms( $player_id, array_values( array_unique( $level_ids ) ), 'lf_league_level', false );
		update_post_meta( $player_id, 'lf_player_sport_levels', $mapping );
	}

	/**
	 * Get enabled sports for player preference controls.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_player_preference_sports() {
		$sports_manager = new Sports_Manager();
		$sports_manager->ensure_enabled_terms();

		return $sports_manager->get_enabled_sports();
	}

	/**
	 * Get saved sport and level preferences for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return array<string, array{sport_label: string, level_id: int, level_label: string}>
	 */
	protected function get_player_sport_level_preferences( $player_id ) {
		$player_id = absint( $player_id );

		if ( ! $player_id ) {
			return array();
		}

		$sports       = $this->get_player_preference_sports();
		$levels       = $this->get_league_level_terms();
		$level_labels = array();

		foreach ( $levels as $level ) {
			$level_labels[ (int) $level->term_id ] = (string) $level->name;
		}

		$saved = get_post_meta( $player_id, 'lf_player_sport_levels', true );

		if ( is_array( $saved ) ) {
			$preferences = array();

			foreach ( $saved as $sport_slug => $level_id ) {
				$sport_slug = sanitize_key( $sport_slug );
				$level_id   = absint( $level_id );

				if ( ! isset( $sports[ $sport_slug ] ) || ! isset( $level_labels[ $level_id ] ) ) {
					continue;
				}

				$preferences[ $sport_slug ] = array(
					'sport_label' => (string) $sports[ $sport_slug ]['label'],
					'level_id'    => $level_id,
					'level_label' => $level_labels[ $level_id ],
				);
			}

			if ( ! empty( $preferences ) ) {
				return $preferences;
			}
		}

		return $this->get_player_sport_level_preferences_from_terms( $player_id, $sports, $level_labels );
	}

	/**
	 * Backfill preference display from existing player taxonomy terms.
	 *
	 * @param int                  $player_id Player ID.
	 * @param array<string, array> $sports Enabled sports keyed by slug.
	 * @param array<int, string>   $level_labels League level labels keyed by term ID.
	 * @return array<string, array{sport_label: string, level_id: int, level_label: string}>
	 */
	protected function get_player_sport_level_preferences_from_terms( $player_id, $sports, $level_labels ) {
		$sport_terms = wp_get_object_terms( $player_id, 'lf_sport' );

		if ( is_wp_error( $sport_terms ) || empty( $sport_terms ) ) {
			return array();
		}

		$level_terms = wp_get_object_terms( $player_id, 'lf_league_level' );
		$level_id    = 0;

		if ( ! is_wp_error( $level_terms ) && ! empty( $level_terms ) ) {
			$first_level = reset( $level_terms );
			$level_id    = $first_level instanceof \WP_Term ? (int) $first_level->term_id : 0;
		}

		if ( ! $level_id ) {
			$level_id = $this->get_default_league_level_id();
		}

		if ( ! isset( $level_labels[ $level_id ] ) ) {
			return array();
		}

		$preferences = array();

		foreach ( $sport_terms as $sport_term ) {
			if ( ! $sport_term instanceof \WP_Term || ! isset( $sports[ $sport_term->slug ] ) ) {
				continue;
			}

			$preferences[ $sport_term->slug ] = array(
				'sport_label' => (string) $sports[ $sport_term->slug ]['label'],
				'level_id'    => $level_id,
				'level_label' => $level_labels[ $level_id ],
			);
		}

		return $preferences;
	}

	/**
	 * Render player sport and level preference summary.
	 *
	 * @param int $player_id Player ID.
	 * @return string
	 */
	protected function render_player_sport_level_summary( $player_id ) {
		$preferences = $this->get_player_sport_level_preferences( $player_id );

		if ( empty( $preferences ) ) {
			return '';
		}

		$items = array();

		foreach ( $preferences as $preference ) {
			$items[] = sprintf(
				/* translators: 1: sport label, 2: league level label. */
				__( '%1$s (%2$s)', 'leagueflow' ),
				$preference['sport_label'],
				$preference['level_label']
			);
		}

		return implode( ', ', $items );
	}

	/**
	 * Keep the user's WordPress display name aligned with their portal profile.
	 *
	 * @param \WP_User $user User.
	 * @param string   $name Full name.
	 * @return void
	 */
	protected function sync_user_display_name( $user, $name ) {
		if ( ! $user instanceof \WP_User || '' === trim( (string) $name ) ) {
			return;
		}

		$name = trim( (string) $name );
		$name_parts    = preg_split( '/\s+/', $name );
		$first_name    = is_array( $name_parts ) ? array_shift( $name_parts ) : '';
		$last_name     = is_array( $name_parts ) ? implode( ' ', $name_parts ) : '';
		$display_name  = $name;
		$nickname      = $name;

		wp_update_user(
			array(
				'ID'           => $user->ID,
				'display_name' => $display_name,
				'nickname'     => $nickname,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			)
		);
	}

	/**
	 * Get or create the current user's player record.
	 *
	 * @param \WP_User $user User.
	 * @param bool     $create Whether to create a missing player.
	 * @return int
	 */
	protected function get_player_id_for_user( $user, $create = true ) {
		$player_id = $this->find_player_id_by_user( $user->ID );

		if ( $player_id ) {
			add_user_role_if_missing( $user->ID, 'leagueflow_player' );
			return $player_id;
		}

		$email = strtolower( sanitize_email( $user->user_email ) );

		$player_id = is_email( $email ) ? $this->find_player_id_by_email( $email ) : 0;

		if ( $player_id ) {
			update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );
			add_user_role_if_missing( $user->ID, 'leagueflow_player' );
			return $player_id;
		}

		if ( $create ) {
			/**
			 * Allow an integration to auto-create a player for a logged-in user
			 * who has no existing player record (e.g. Clerk self sign-up).
			 *
			 * Return a new published lf_player post ID to grant portal access,
			 * or 0 to leave the user without a profile (default).
			 *
			 * @param int      $player_id Default 0 (no auto-create).
			 * @param \WP_User $user      The logged-in user.
			 * @param string   $email     The user's sanitized email.
			 */
			$created = (int) apply_filters( 'leagueflow_autocreate_player_id', 0, $user, $email );

			if ( $created > 0 ) {
				add_user_role_if_missing( $user->ID, 'leagueflow_player' );
				return $created;
			}
		}

		return 0;
	}

	/**
	 * Find a player by linked user ID.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	protected function find_player_id_by_user( $user_id ) {
		$players = get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_user_id',
						'value' => absint( $user_id ),
					),
				),
			)
		);

		return empty( $players ) ? 0 : absint( $players[0] );
	}

	/**
	 * Find a player by email.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	protected function find_player_id_by_email( $email ) {
		$players = get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_email',
						'value' => strtolower( sanitize_email( $email ) ),
					),
				),
			)
		);

		return empty( $players ) ? 0 : absint( $players[0] );
	}

	/**
	 * Check portal access.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function user_can_access_portal( $user, $player_id = 0, $manager_teams = array() ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $player_id ) {
			return true;
		}

		if ( is_captain_registration_enabled() ) {
			return true;
		}

		return ! empty( $manager_teams );
	}

	/**
	 * Check if a user may manage a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	protected function user_can_manage_team( $team_id, $user_id ) {
		$team = get_post( $team_id );

		if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return in_array( absint( $user_id ), get_team_manager_user_ids( $team_id ), true );
	}

	/**
	 * Check if a manager may update a roster player.
	 *
	 * @param int $player_id Player ID.
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	protected function user_can_manage_roster_player( $player_id, $team_id, $user_id ) {
		$player = get_post( $player_id );

		if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) {
			return false;
		}

		if ( ! player_has_team( $player_id, $team_id ) ) {
			return false;
		}

		return $this->user_can_manage_team( $team_id, $user_id );
	}

	/**
	 * Get teams managed by a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_manager_teams( $user_id ) {
		$teams = get_posts(
			array(
				'post_type'      => 'lf_team',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( current_user_can( 'manage_options' ) ) {
			return $teams;
		}

		return array_values(
			array_filter(
				$teams,
				static function( $team ) use ( $user_id ) {
					return in_array( absint( $user_id ), get_team_manager_user_ids( $team->ID ), true );
				}
			)
		);
	}

	/**
	 * Get team player posts.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_team_player_posts( $team_id ) {
		$team_id = absint( $team_id );

		return array_values(
			array_filter(
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
				static function( $player ) use ( $team_id ) {
					return player_has_team( $player->ID, $team_id );
				}
			)
		);
	}

	/**
	 * Assign player sport from their team sport.
	 *
	 * @param int $player_id Player ID.
	 * @param int $team_id Team ID.
	 * @return void
	 */
	protected function assign_player_sport_from_team( $player_id, $team_id ) {
		$sport_id = get_post_primary_term_id( $team_id, 'lf_sport' );
		$level_id = get_post_primary_term_id( $team_id, 'lf_league_level' );

		if ( $sport_id ) {
			wp_set_object_terms( $player_id, array( $sport_id ), 'lf_sport', true );
		}

		if ( $level_id ) {
			wp_set_object_terms( $player_id, array( $level_id ), 'lf_league_level', true );
		}
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
	 * Render team membership links for the player summary.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @return string
	 */
	protected function render_player_team_links( $team_ids ) {
		$links = array();

		foreach ( $team_ids as $team_id ) {
			$team = get_post( $team_id );

			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
				continue;
			}

			$permalink = get_permalink( $team_id );
			$links[]   = $permalink
				? '<a href="' . esc_url( $permalink ) . '">' . esc_html( get_the_title( $team_id ) ) . '</a>'
				: esc_html( get_the_title( $team_id ) );
		}

		return implode( ', ', $links );
	}

	/**
	 * Check whether a player already belongs to a team in a sport.
	 *
	 * @param int    $player_id Player ID.
	 * @param string $sport_slug Sport slug.
	 * @return bool
	 */
	protected function player_has_team_in_sport( $player_id, $sport_slug ) {
		$sport_slug = sanitize_key( $sport_slug );

		if ( ! $player_id || '' === $sport_slug ) {
			return false;
		}

		foreach ( get_player_team_ids( $player_id ) as $team_id ) {
			if ( get_post_primary_term_slug( $team_id, 'lf_sport' ) === $sport_slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a display label for a sport slug.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return string
	 */
	protected function get_sport_label( $sport_slug ) {
		$sport_slug = sanitize_key( $sport_slug );

		if ( '' === $sport_slug ) {
			return __( 'Selected sport', 'leagueflow' );
		}

		$sports_manager = new Sports_Manager();
		$sport          = $sports_manager->get_definition( $sport_slug );

		return ! empty( $sport['label'] ) ? (string) $sport['label'] : ucwords( str_replace( '-', ' ', $sport_slug ) );
	}

	/**
	 * Get enabled sports and their teams for request forms.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_requestable_sport_teams() {
		$sports_manager = new Sports_Manager();
		$groups         = array();

		foreach ( $sports_manager->get_enabled_sports() as $sport_slug => $sport ) {
			$teams = get_posts(
				array(
					'post_type'      => 'lf_team',
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'tax_query'      => array(
						array(
							'taxonomy' => 'lf_sport',
							'field'    => 'slug',
							'terms'    => array( $sport_slug ),
						),
					),
				)
			);

			$groups[ $sport_slug ] = array(
				'label' => (string) $sport['label'],
				'teams' => $teams,
			);
		}

		return $groups;
	}

	/**
	 * Find a team with the same title in a sport.
	 *
	 * @param string $title Team title.
	 * @param string $sport_slug Sport slug.
	 * @return int
	 */
	protected function find_team_id_by_title_and_sport( $title, $sport_slug ) {
		$teams = get_posts(
			array(
				'post_type'      => 'lf_team',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				's'              => sanitize_text_field( $title ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'lf_sport',
						'field'    => 'slug',
						'terms'    => array( sanitize_key( $sport_slug ) ),
					),
				),
			)
		);

		foreach ( $teams as $team_id ) {
			if ( 0 === strcasecmp( (string) get_the_title( $team_id ), $title ) ) {
				return absint( $team_id );
			}
		}

		return 0;
	}

	/**
	 * Get or create the logged-in captain's player profile.
	 *
	 * @param \WP_User $user User.
	 * @param string   $captain_name Captain name.
	 * @return int
	 */
	protected function get_or_create_captain_player_id( $user, $captain_name ) {
		$player_id = $this->get_player_id_for_user( $user, false );
		$email     = strtolower( sanitize_email( $user->user_email ) );

		if ( $player_id ) {
			wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => $captain_name,
				)
			);
			$this->sync_user_display_name( $user, $captain_name );
			update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );

			if ( is_email( $email ) ) {
				update_post_meta( $player_id, 'lf_email', $email );
			}

			add_user_role_if_missing( $user->ID, 'leagueflow_player' );
			return $player_id;
		}

		$player_id = wp_insert_post(
			array(
				'post_type'   => 'lf_player',
				'post_status' => 'publish',
				'post_title'  => $captain_name,
				'post_author' => (int) $user->ID,
			),
			true
		);

		if ( is_wp_error( $player_id ) || ! $player_id ) {
			return 0;
		}

		update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );

		if ( is_email( $email ) ) {
			update_post_meta( $player_id, 'lf_email', $email );
		}

		$this->sync_user_display_name( $user, $captain_name );
		add_user_role_if_missing( $user->ID, 'leagueflow_player' );

		return absint( $player_id );
	}

	/**
	 * Get a sensible default name for a logged-in user.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	protected function get_default_user_full_name( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );

		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}

		if ( '' === $name || false !== strpos( $name, '@' ) ) {
			$name = trim( (string) $user->user_login );
		}

		return $name;
	}

	/**
	 * Get join requests for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_player_join_requests( $player_id ) {
		return get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => 6,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'lf_player_id',
						'value' => absint( $player_id ),
					),
				),
			)
		);
	}

	/**
	 * Count pending join requests for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return int
	 */
	protected function count_pending_join_requests( $player_id ) {
		$requests = get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_player_id',
						'value' => absint( $player_id ),
					),
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
				),
			)
		);

		return count( $requests );
	}

	/**
	 * Get pending join requests for a team.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_team_join_requests( $team_id ) {
		$team_id    = absint( $team_id );
		$sport_slug = get_post_primary_term_slug( $team_id, 'lf_sport' );
		$request_query = array(
			'key'     => 'lf_team_id',
			'value'   => $team_id,
			'compare' => '=',
			'type'    => 'NUMERIC',
		);

		if ( $sport_slug ) {
			$request_query = array(
				'relation' => 'OR',
				$request_query,
				array(
					'relation' => 'AND',
					array(
						'key'     => 'lf_team_id',
						'value'   => 0,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'   => 'lf_sport_slug',
						'value' => $sport_slug,
					),
				),
			);
		}

		return get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
					$request_query,
				),
			)
		);
	}

	/**
	 * Find an existing pending request for the same player/team.
	 *
	 * @param int $player_id Player ID.
	 * @param int $team_id Team ID.
	 * @param string $sport_slug Sport slug.
	 * @return int
	 */
	protected function find_pending_join_request( $player_id, $team_id, $sport_slug = '' ) {
		$team_id    = absint( $team_id );
		$sport_slug = sanitize_key( $sport_slug );
		$meta_query = array(
			array(
				'key'   => 'lf_player_id',
				'value' => absint( $player_id ),
			),
			array(
				'key'     => 'lf_team_id',
				'value'   => $team_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
			array(
				'key'   => 'lf_request_status',
				'value' => 'pending',
			),
		);

		if ( ! $team_id && $sport_slug ) {
			$meta_query[] = array(
				'key'   => 'lf_sport_slug',
				'value' => $sport_slug,
			);
		}

		$requests = get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		return empty( $requests ) ? 0 : absint( $requests[0] );
	}

	/**
	 * Render upcoming matches across multiple team memberships.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @param int        $limit Limit.
	 * @return string
	 */
	protected function render_upcoming_matches_for_teams( $team_ids, $limit = 6 ) {
		$matches = $this->get_upcoming_matches_for_teams( $team_ids, $limit );

		if ( empty( $matches ) ) {
			return '<p>' . esc_html__( 'No upcoming games are scheduled yet.', 'leagueflow' ) . '</p>';
		}

		return $this->render_match_stack( $matches );
	}

	/**
	 * Get upcoming matches across multiple team memberships.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @param int        $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_upcoming_matches_for_teams( $team_ids, $limit = 6 ) {
		$matches = array();

		foreach ( $team_ids as $team_id ) {
			foreach ( $this->get_upcoming_matches( $team_id, $limit ) as $match ) {
				$matches[ (int) $match['id'] ] = $match;
			}
		}

		usort(
			$matches,
			static function( $a, $b ) {
				return strtotime( (string) $a['datetime_raw'] ) <=> strtotime( (string) $b['datetime_raw'] );
			}
		);

		return array_slice( array_values( $matches ), 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Render upcoming matches for a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $limit Limit.
	 * @return string
	 */
	protected function render_upcoming_matches( $team_id, $limit = 5 ) {
		$matches = $this->get_upcoming_matches( $team_id, $limit );

		if ( empty( $matches ) ) {
			return '<p>' . esc_html__( 'No upcoming games are scheduled yet.', 'leagueflow' ) . '</p>';
		}

		return $this->render_match_stack( $matches );
	}

	/**
	 * Render a stack of match rows.
	 *
	 * @param array<int, array<string, mixed>> $matches Matches.
	 * @return string
	 */
	protected function render_match_stack( $matches ) {
		ob_start();
		?>
		<div class="leagueflow-match-stack leagueflow-portal-matches">
			<?php foreach ( $matches as $match ) : ?>
				<article class="leagueflow-match-row">
					<header class="leagueflow-match-row__header">
						<strong><?php echo esc_html( $match['sport_label'] ); ?></strong>
						<?php if ( ! empty( $match['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time><?php endif; ?>
					</header>
					<div class="leagueflow-match-row__teams">
						<span><?php echo esc_html( $match['home_team'] ); ?></span>
						<span class="leagueflow-match-row__score"><?php esc_html_e( 'vs', 'leagueflow' ); ?></span>
						<span><?php echo esc_html( $match['away_team'] ); ?></span>
					</div>
					<?php if ( ! empty( $match['venue'] ) ) : ?><p class="leagueflow-match-row__venue"><?php echo esc_html( $match['venue'] ); ?></p><?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get upcoming scheduled matches for a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_upcoming_matches( $team_id, $limit ) {
		$now     = current_time( 'timestamp' );
		$matches = $this->renderer->get_match_items(
			array(
				'team'   => absint( $team_id ),
				'status' => 'scheduled',
				'limit'  => -1,
			)
		);

		$matches = array_filter(
			$matches,
			static function( $match ) use ( $now ) {
				$timestamp = ! empty( $match['datetime_raw'] ) ? strtotime( $match['datetime_raw'] ) : 0;
				return $timestamp && $timestamp >= $now;
			}
		);

		return array_slice( array_values( $matches ), 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Render hidden form fields.
	 *
	 * @param string $portal_action Portal action.
	 * @return string
	 */
	protected function render_hidden_fields( $portal_action ) {
		ob_start();
		?>
		<input type="hidden" name="action" value="leagueflow_portal" />
		<input type="hidden" name="leagueflow_portal_action" value="<?php echo esc_attr( $portal_action ); ?>" />
		<input type="hidden" name="leagueflow_redirect_to" value="<?php echo esc_url( $this->get_portal_url() ); ?>" />
		<?php wp_nonce_field( 'leagueflow_portal_' . $portal_action, 'leagueflow_portal_nonce' ); ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render notice from query string.
	 *
	 * @return string
	 */
	protected function render_notice() {
		$notice = sanitize_key( wp_unslash( $_GET['leagueflow_notice'] ?? '' ) );

		if ( ! $notice ) {
			return '';
		}

		$messages = array(
			'profile-saved'  => __( 'Profile saved.', 'leagueflow' ),
			'profile-setup-complete' => __( 'Profile setup complete.', 'leagueflow' ),
			'team-saved'     => __( 'Team saved.', 'leagueflow' ),
			'team-registered' => __( 'Team registered. You are assigned as the team manager and captain.', 'leagueflow' ),
			'team-exists'    => __( 'A team with that name already exists for this sport.', 'leagueflow' ),
			'player-added'   => __( 'Player added to the roster.', 'leagueflow' ),
			'roster-saved'   => __( 'Roster player saved.', 'leagueflow' ),
			'player-removed' => __( 'Player removed from the team.', 'leagueflow' ),
			'player-assigned' => __( 'That player is already assigned to another team.', 'leagueflow' ),
			'join-request-sent' => __( 'Your team request was sent.', 'leagueflow' ),
			'placement-request-sent' => __( 'Your placement request was sent.', 'leagueflow' ),
			'join-request-approved' => __( 'Join request approved.', 'leagueflow' ),
			'join-request-declined' => __( 'Join request declined.', 'leagueflow' ),
			'join-request-exists' => __( 'You already have a pending request for that team.', 'leagueflow' ),
			'placement-request-exists' => __( 'You already have a pending placement request for that sport.', 'leagueflow' ),
			'join-request-member' => __( 'You are already on that team.', 'leagueflow' ),
			'placement-request-member' => __( 'You are already assigned to a team for that sport.', 'leagueflow' ),
			'invalid-email'  => __( 'Please enter a valid email address or leave the email field blank.', 'leagueflow' ),
			'access-denied'  => __( 'You do not have access to the portal.', 'leagueflow' ),
			'team-denied'    => __( 'You do not have permission to manage that team.', 'leagueflow' ),
			'player-missing' => __( 'No player profile is linked to your account.', 'leagueflow' ),
			'name-setup-required' => __( 'Enter your full name before using the portal.', 'leagueflow' ),
			'sport-level-required' => __( 'Choose at least one sport and level before using the portal.', 'leagueflow' ),
			'registration-closed' => __( 'That registration window is currently closed.', 'leagueflow' ),
			'upload-error'   => __( 'The image upload failed. Please try another image.', 'leagueflow' ),
			'invalid-request' => __( 'The portal request could not be verified.', 'leagueflow' ),
		);

		if ( empty( $messages[ $notice ] ) ) {
			return '';
		}

		$type = in_array( $notice, array( 'invalid-email', 'access-denied', 'team-denied', 'team-exists', 'player-missing', 'player-assigned', 'join-request-exists', 'placement-request-exists', 'join-request-member', 'placement-request-member', 'name-setup-required', 'sport-level-required', 'registration-closed', 'upload-error', 'invalid-request' ), true ) ? 'error' : 'success';

		return sprintf(
			'<div class="leagueflow-portal__notice leagueflow-portal__notice--%1$s">%2$s</div>',
			esc_attr( $type ),
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * Render a simple panel.
	 *
	 * @param string $title Title.
	 * @param string $content HTML content.
	 * @return string
	 */
	protected function render_panel( $title, $content ) {
		return sprintf(
			'<section class="leagueflow-portal__panel"><h2>%1$s</h2>%2$s</section>',
			esc_html( $title ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Get portal URL.
	 *
	 * @return string
	 */
	protected function get_portal_url() {
		$page_id = absint( get_option( 'leagueflow_portal_page_id' ) );

		if ( $page_id ) {
			$permalink = get_permalink( $page_id );

			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/portal/' );
	}

	/**
	 * Redirect to the portal with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	protected function redirect_with_notice( $notice ) {
		$redirect = esc_url_raw( wp_unslash( $_POST['leagueflow_redirect_to'] ?? $this->get_portal_url() ) );
		$redirect = remove_query_arg( 'leagueflow_notice', $redirect );
		$redirect = add_query_arg( 'leagueflow_notice', sanitize_key( $notice ), $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}
}
