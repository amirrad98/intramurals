<?php
/**
 * Results screen: enter scores for played games, publish now, hold or schedule.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Walks staff through games that have been played: which still need a score,
 * which results are saved but not published, which are scheduled, and what
 * has already been published.
 */
class Admin_Results {

	const PAGE   = 'leagueflow-results';
	const ACTION = 'leagueflow_result_action';
	const BULK   = 'leagueflow_result_bulk';

	/** How far back the Published tab looks. */
	const PUBLISHED_DAYS = 30;

	/**
	 * Result writer.
	 *
	 * @var Result_Service
	 */
	protected $results;

	/**
	 * Sports.
	 *
	 * @var Sports_Manager
	 */
	protected $sports_manager;

	/**
	 * Constructor.
	 *
	 * @param Result_Service $results        Result writer.
	 * @param Sports_Manager $sports_manager Sports.
	 */
	public function __construct( Result_Service $results, Sports_Manager $sports_manager ) {
		$this->results        = $results;
		$this->sports_manager = $sports_manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_post_' . self::BULK, array( $this, 'handle_bulk' ) );
	}

	/** Tabs in workflow order. */
	public static function stages() {
		return array(
			'needs_score' => __( 'Needs a score', 'leagueflow' ),
			'held'        => __( 'Saved, not published', 'leagueflow' ),
			'scheduled'   => __( 'Scheduled to publish', 'leagueflow' ),
			'conflict'    => __( 'Needs review', 'leagueflow' ),
			'published'   => __( 'Published', 'leagueflow' ),
		);
	}

	/** Played games waiting for staff: no score, held or needing review. */
	public function count_waiting() {
		$counts = $this->counts( $this->load( '' ) );
		return $counts['needs_score'] + $counts['held'] + $counts['conflict'];
	}

	/**
	 * Matches that belong on this screen, keyed by stage.
	 *
	 * @param string $sport Sport slug or ''.
	 * @return array<string, array<int, int>>
	 */
	public function load( $sport ) {
		$now   = time();
		// A plain ID query; several OR'd meta conditions make MySQL join postmeta
		// repeatedly, which took seconds on every admin page for the menu count.
		$args  = array(
			'post_type'      => 'lf_match',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		if ( '' !== $sport ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'lf_sport', 'field' => 'slug', 'terms' => $sport ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$cutoff = $now - self::PUBLISHED_DAYS * DAY_IN_SECONDS;
		$staged = array_fill_keys( array_keys( self::stages() ), array() );
		$ids = get_posts( $args );
		update_meta_cache( 'post', $ids );
		// Game time order; games without a time sort last.
		$start = array();
		foreach ( $ids as $id ) {
			$start[ $id ] = (string) get_post_meta( $id, 'lf_match_datetime', true );
		}
		uksort( $start, static function ( $a, $b ) use ( $start ) {
			return array( '' === $start[ $a ], $start[ $a ], $a ) <=> array( '' === $start[ $b ], $start[ $b ], $b );
		} );
		foreach ( array_keys( $start ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$stage = Result_Service::stage( $id, $now );
			if ( 'published' === $stage ) {
				$time = Result_Service::start_timestamp( $id );
				if ( null !== $time && $time < $cutoff ) {
					continue;
				}
			}
			if ( isset( $staged[ $stage ] ) ) {
				$staged[ $stage ][] = (int) $id;
			}
		}
		// Most recent games first once they are published.
		$staged['published'] = array_reverse( $staged['published'] );
		return $staged;
	}

	/** Count per stage. */
	protected function counts( $staged ) {
		return array_map( 'count', $staged );
	}

	/** Screen URL. */
	public function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to enter results.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen filters.
		$sport = sanitize_key( wp_unslash( $_GET['lf_sport'] ?? '' ) );
		$sport = in_array( $sport, $this->sports_manager->get_enabled_sport_slugs(), true ) ? $sport : '';
		$tab   = sanitize_key( wp_unslash( $_GET['stage'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$staged = $this->load( $sport );
		$counts = $this->counts( $staged );
		if ( ! isset( $staged[ $tab ] ) ) {
			$tab = 'needs_score';
			foreach ( array( 'needs_score', 'held', 'conflict', 'scheduled' ) as $candidate ) {
				if ( $counts[ $candidate ] ) {
					$tab = $candidate;
					break;
				}
			}
		}
		$base = array_filter( array( 'lf_sport' => $sport ) );
		?>
		<div class="wrap leagueflow-admin-page leagueflow-results-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Results', 'leagueflow' ); ?></h1>
			<hr class="wp-header-end" />
			<p class="leagueflow-page-intro"><?php esc_html_e( 'Enter the score for each game that has been played. Publish it now to show it on the site and count it in the standings, save it without publishing while you check it, or choose when it publishes.', 'leagueflow' ); ?></p>
			<?php $this->render_notice(); ?>

			<form method="get" class="leagueflow-results-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="hidden" name="stage" value="<?php echo esc_attr( $tab ); ?>" />
				<label for="leagueflow-results-sport"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
				<select id="leagueflow-results-sport" name="lf_sport" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
					<?php foreach ( $this->sports_manager->get_enabled_sports() as $slug => $definition ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $sport, $slug ); ?>><?php echo esc_html( $definition['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button class="button"><?php esc_html_e( 'Filter', 'leagueflow' ); ?></button></noscript>
			</form>

			<ul class="leagueflow-results-summary" aria-label="<?php esc_attr_e( 'Result progress', 'leagueflow' ); ?>">
				<?php foreach ( self::stages() as $stage => $label ) : ?>
					<?php if ( 'conflict' === $stage && ! $counts[ $stage ] ) { continue; } ?>
					<li class="leagueflow-results-summary__item is-<?php echo esc_attr( $stage ); ?><?php echo $tab === $stage ? ' is-current' : ''; ?>">
						<a href="<?php echo esc_url( $this->url( $base + array( 'stage' => $stage ) ) ); ?>"<?php echo $tab === $stage ? ' aria-current="page"' : ''; ?>>
							<strong><?php echo esc_html( number_format_i18n( $counts[ $stage ] ) ); ?></strong>
							<span><?php echo esc_html( $label ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php $this->render_stage( $tab, $staged[ $tab ], $base ); ?>
		</div>
		<?php
	}

	/** Explain the current tab and list its games. */
	protected function render_stage( $stage, $ids, $base ) {
		$help = array(
			'needs_score' => __( 'These games have started or finished and have no score yet.', 'leagueflow' ),
			'held'        => __( 'These scores are saved but not on the site or in the standings yet.', 'leagueflow' ),
			'scheduled'   => __( 'These results publish automatically at the time shown, unless the score is changed first.', 'leagueflow' ),
			'conflict'    => __( 'A scheduled result did not publish, because its score changed after it was scheduled or the match can no longer be finished. Check it and publish again.', 'leagueflow' ),
			/* translators: %d: number of days */
			'published'   => sprintf( __( 'Results published for games in the last %d days. Change a score here or take it off the site by saving it without publishing.', 'leagueflow' ), self::PUBLISHED_DAYS ),
		);
		echo '<p class="description">' . esc_html( $help[ $stage ] ) . '</p>';
		if ( ! $ids ) {
			echo '<p class="leagueflow-results-empty">' . esc_html__( 'Nothing here right now.', 'leagueflow' ) . '</p>';
			return;
		}
		if ( 'held' === $stage && count( $ids ) > 1 ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-results-bulk">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::BULK ); ?>" />
				<?php wp_nonce_field( self::BULK ); ?>
				<?php foreach ( $ids as $id ) : ?>
					<input type="hidden" name="matches[<?php echo esc_attr( (string) $id ); ?>]" value="<?php echo esc_attr( Result_Service::state_token( $id ) ); ?>" />
				<?php endforeach; ?>
				<input type="hidden" name="return" value="<?php echo esc_attr( $this->url( $base + array( 'stage' => $stage ) ) ); ?>" />
				<button type="submit" class="button button-primary">
					<?php
					/* translators: %s: number of results */
					echo esc_html( sprintf( _n( 'Publish %s saved result', 'Publish all %s saved results', count( $ids ), 'leagueflow' ), number_format_i18n( count( $ids ) ) ) );
					?>
				</button>
			</form>
			<?php
		}
		echo '<div class="leagueflow-results-list">';
		foreach ( $ids as $id ) {
			$this->render_row( $id, $stage, $base );
		}
		echo '</div>';
	}

	/** One game with its score form. */
	protected function render_row( $id, $stage, $base ) {
		$home_id  = (int) get_post_meta( $id, 'lf_home_team_id', true );
		$away_id  = (int) get_post_meta( $id, 'lf_away_team_id', true );
		$home     = $home_id ? get_the_title( $home_id ) : __( 'Home team', 'leagueflow' );
		$away     = $away_id ? get_the_title( $away_id ) : __( 'Away team', 'leagueflow' );
		$sport    = $this->sports_manager->get_definition( $this->sports_manager->get_post_sport_slug( $id ) );
		$when     = format_match_datetime( (string) get_post_meta( $id, 'lf_match_datetime', true ) );
		$venue    = (string) get_post_meta( $id, 'lf_venue', true );
		$outcome  = (string) get_post_meta( $id, 'lf_outcome', true );
		$at       = (int) get_post_meta( $id, Result_Service::PUBLISH_AT_META, true );
		$log      = get_post_meta( $id, Result_Service::LOG_META );
		$last     = $log ? end( $log ) : null;
		$prefix   = 'lf-result-' . $id;
		$default_at = wp_date( 'Y-m-d\TH:i', $at ? $at : time() + HOUR_IN_SECONDS, wp_timezone() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-result-card is-<?php echo esc_attr( $stage ); ?>" aria-labelledby="<?php echo esc_attr( $prefix ); ?>-title">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="state" value="<?php echo esc_attr( Result_Service::state_token( $id ) ); ?>" />
			<input type="hidden" name="return" value="<?php echo esc_attr( $this->url( $base + array( 'stage' => $stage ) ) ); ?>" />
			<?php wp_nonce_field( self::ACTION . '_' . $id ); ?>
			<header class="leagueflow-result-card__head">
				<h2 id="<?php echo esc_attr( $prefix ); ?>-title"><?php echo esc_html( $home . ' vs ' . $away ); ?></h2>
				<p class="leagueflow-result-card__meta">
					<?php echo esc_html( implode( ' · ', array_filter( array( $sport['label'] ?? '', get_post_league_level_label( $id ), $when, $venue ) ) ) ); ?>
					<?php if ( 'publish' !== get_post_status( $id ) ) : ?>
						<span class="leagueflow-result-card__flag"><?php esc_html_e( 'Fixture not public', 'leagueflow' ); ?></span>
					<?php endif; ?>
				</p>
				<?php foreach ( Referee::conflict_messages( $id ) as $conflict ) : ?>
					<p class="leagueflow-result-card__conflict"><strong><?php esc_html_e( 'Referee conflict:', 'leagueflow' ); ?></strong> <?php echo esc_html( $conflict ); ?></p>
				<?php endforeach; ?>
			</header>
			<div class="leagueflow-result-card__score">
				<label for="<?php echo esc_attr( $prefix ); ?>-home"><span><?php echo esc_html( $home ); ?></span>
					<input id="<?php echo esc_attr( $prefix ); ?>-home" name="home" type="number" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) get_post_meta( $id, 'lf_home_score', true ) ); ?>" /></label>
				<span aria-hidden="true">–</span>
				<label for="<?php echo esc_attr( $prefix ); ?>-away"><span><?php echo esc_html( $away ); ?></span>
					<input id="<?php echo esc_attr( $prefix ); ?>-away" name="away" type="number" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) get_post_meta( $id, 'lf_away_score', true ) ); ?>" /></label>
				<label for="<?php echo esc_attr( $prefix ); ?>-outcome"><span><?php esc_html_e( 'Result', 'leagueflow' ); ?></span>
					<select id="<?php echo esc_attr( $prefix ); ?>-outcome" name="outcome">
						<?php foreach ( Result_Service::outcomes() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $outcome, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></label>
			</div>
			<div class="leagueflow-result-card__actions">
				<button type="submit" name="mode" value="finish" class="button button-primary"><?php echo esc_html( 'published' === $stage ? __( 'Update published result', 'leagueflow' ) : __( 'Publish result', 'leagueflow' ) ); ?></button>
				<button type="submit" name="mode" value="hold" class="button"><?php echo esc_html( 'published' === $stage ? __( 'Take off the site', 'leagueflow' ) : __( 'Save without publishing', 'leagueflow' ) ); ?></button>
				<span class="leagueflow-result-card__schedule">
					<label for="<?php echo esc_attr( $prefix ); ?>-at"><?php esc_html_e( 'Publish at', 'leagueflow' ); ?></label>
					<input id="<?php echo esc_attr( $prefix ); ?>-at" name="publish_at" type="datetime-local" value="<?php echo esc_attr( $default_at ); ?>" />
					<button type="submit" name="mode" value="schedule" class="button"><?php esc_html_e( 'Schedule', 'leagueflow' ); ?></button>
				</span>
				<a class="leagueflow-result-card__edit" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Open match', 'leagueflow' ); ?></a>
			</div>
			<?php if ( 'scheduled' === $stage && $at ) : ?>
				<?php /* translators: %s: date and time */ ?>
				<p class="leagueflow-result-card__status"><?php echo esc_html( sprintf( __( 'Publishes %s.', 'leagueflow' ), wp_date( (string) get_setting( 'date_time_format', 'F j, Y g:i a' ), $at, wp_timezone() ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( is_array( $last ) ) : ?>
				<?php
				$user  = get_userdata( (int) ( $last['user_id'] ?? 0 ) );
				$modes = array( 'finish' => __( 'Published', 'leagueflow' ), 'hold' => __( 'Saved without publishing', 'leagueflow' ), 'schedule' => __( 'Scheduled', 'leagueflow' ) );
				if ( 'referee-desk' === ( $last['source'] ?? '' ) ) {
					$modes = array( 'finish' => __( 'Published from the Referee desk', 'leagueflow' ), 'hold' => __( 'Sent from the Referee desk for review', 'leagueflow' ) );
				}
				$time  = strtotime( (string) ( $last['at_utc'] ?? '' ) . ' UTC' );
				?>
				<p class="leagueflow-result-card__history">
					<?php
					/* translators: 1: action, 2: person, 3: date and time */
					echo esc_html( sprintf( __( '%1$s by %2$s, %3$s', 'leagueflow' ), $modes[ $last['mode'] ?? '' ] ?? __( 'Saved', 'leagueflow' ), $user ? $user->display_name : __( 'the scheduler', 'leagueflow' ), $time ? wp_date( (string) get_setting( 'date_time_format', 'F j, Y g:i a' ), $time, wp_timezone() ) : '' ) );
					?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/** Show the last action's outcome. */
	protected function render_notice() {
		$key    = 'leagueflow_results_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible" role="status"><p>%2$s</p></div>', $notice['error'] ? 'error' : 'success', esc_html( $notice['message'] ) );
	}

	/** Store a notice and return to the screen. */
	protected function finish_request( $error, $message ) {
		set_transient( 'leagueflow_results_notice_' . get_current_user_id(), array( 'error' => $error, 'message' => $message ), 120 );
		$return = wp_unslash( $_POST['return'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		wp_safe_redirect( is_string( $return ) && '' !== $return ? wp_validate_redirect( $return, $this->url() ) : $this->url() );
		exit;
	}

	/** Parse a datetime-local value in the site time zone. */
	protected static function parse_local_time( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value ) ) {
			return 0;
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * One game's result.
	 *
	 * @return void
	 */
	public function handle_action() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Use the Results screen to save a result.', 'leagueflow' ), '', array( 'response' => 405 ) );
		}
		$id = isset( $_POST['match_id'] ) && is_scalar( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		foreach ( array( 'home', 'away', 'outcome', 'mode', 'publish_at', 'state' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				$this->finish_request( true, __( 'The submitted result is invalid. Reload and try again.', 'leagueflow' ) );
			}
		}
		$mode   = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$result = $this->results->save(
			$id,
			array(
				'home'         => sanitize_text_field( wp_unslash( $_POST['home'] ?? '' ) ),
				'away'         => sanitize_text_field( wp_unslash( $_POST['away'] ?? '' ) ),
				'outcome'      => sanitize_key( wp_unslash( $_POST['outcome'] ?? '' ) ),
				'mode'         => $mode,
				'publish_at'   => self::parse_local_time( wp_unslash( $_POST['publish_at'] ?? '' ) ),
				'state'        => (string) wp_unslash( $_POST['state'] ?? '' ),
				'publish_post' => true,
				'source'       => 'results-screen',
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->finish_request( true, $result->get_error_message() );
		}
		$title    = get_the_title( $id );
		$messages = array(
			/* translators: %s: match */
			'finish'   => __( 'Published: %s. It now shows on the site and counts in the standings.', 'leagueflow' ),
			/* translators: %s: match */
			'hold'     => __( 'Saved without publishing: %s.', 'leagueflow' ),
			/* translators: %s: match */
			'schedule' => __( 'Scheduled: %s.', 'leagueflow' ),
		);
		$message = sprintf( $messages[ $mode ], wp_specialchars_decode( $title ) );
		if ( 'finish' === $mode && get_post_meta( $id, '_lf_bracket_sync_notice', true ) ) {
			$message .= ' ' . __( 'A bracket conflict needs staff review.', 'leagueflow' );
		}
		$this->finish_request( false, $message );
	}

	/**
	 * Publish every saved result shown on the Saved tab.
	 *
	 * @return void
	 */
	public function handle_bulk() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Use the Results screen to publish results.', 'leagueflow' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( self::BULK );
		$matches = isset( $_POST['matches'] ) && is_array( $_POST['matches'] ) ? wp_unslash( $_POST['matches'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is checked below.
		$done    = 0;
		$failed  = array();
		foreach ( $matches as $id => $state ) {
			$id = absint( $id );
			if ( ! $id || ! is_string( $state ) || 'held' !== Result_Service::stage( $id ) ) {
				continue;
			}
			$result = $this->results->save(
				$id,
				array(
					'home'         => (string) get_post_meta( $id, 'lf_home_score', true ),
					'away'         => (string) get_post_meta( $id, 'lf_away_score', true ),
					'outcome'      => (string) get_post_meta( $id, 'lf_outcome', true ),
					'mode'         => 'finish',
					'state'        => $state,
					'publish_post' => true,
					'source'       => 'results-screen-bulk',
				)
			);
			if ( is_wp_error( $result ) ) {
				$failed[] = wp_specialchars_decode( get_the_title( $id ) ) . ': ' . $result->get_error_message();
			} else {
				++$done;
			}
		}
		/* translators: %s: number of results */
		$message = sprintf( _n( 'Published %s result.', 'Published %s results.', $done, 'leagueflow' ), number_format_i18n( $done ) );
		if ( $failed ) {
			$message .= ' ' . __( 'Not published:', 'leagueflow' ) . ' ' . implode( ' ', $failed );
		}
		$this->finish_request( (bool) $failed && ! $done, $message );
	}
}
