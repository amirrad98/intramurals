<?php
/**
 * WP-CLI commands.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Manage LeagueFlow results.
 */
class CLI {

	/**
	 * Result writer.
	 *
	 * @var Result_Service
	 */
	protected $results;

	/**
	 * Constructor.
	 *
	 * @param Result_Service $results Result writer.
	 */
	public function __construct( Result_Service $results ) {
		$this->results = $results;
	}

	/**
	 * Register the command when running under WP-CLI.
	 *
	 * @return void
	 */
	public function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'leagueflow results', $this );
		}
	}

	/**
	 * Publish saved results: mark every unpublished match that already has
	 * both scores (or a forfeit) as Finished, through the normal result writer.
	 *
	 * Postponed and cancelled matches are skipped, as are matches whose
	 * teams, sport or season are inconsistent. Run with --user=<admin>.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be published without changing anything.
	 *
	 * [--include-future]
	 * : Also publish scored matches whose start time has not passed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp leagueflow results finish-scored --user=admin --dry-run
	 *     wp leagueflow results finish-scored --user=admin
	 *
	 * @subcommand finish-scored
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function finish_scored( $args, $assoc_args ) {
		unset( $args );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$future  = ! empty( $assoc_args['include-future'] );
		if ( ! $dry_run && ! get_current_user_id() ) {
			\WP_CLI::error( 'Run as a LeagueFlow manager, for example --user=admin.' );
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lf_match',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$rows = array();
		$done = 0;
		foreach ( $ids as $id ) {
			$status  = (string) get_post_meta( $id, 'lf_status', true );
			$home    = (string) get_post_meta( $id, 'lf_home_score', true );
			$away    = (string) get_post_meta( $id, 'lf_away_score', true );
			$outcome = (string) get_post_meta( $id, 'lf_outcome', true );
			if ( 'finished' === $status || in_array( $status, array( 'postponed', 'cancelled' ), true ) || ! Result_Service::has_result( $id ) ) {
				continue;
			}
			$start = Result_Service::start_timestamp( $id );
			if ( ! $future && null !== $start && $start > time() ) {
				$rows[] = $this->row( $id, $home, $away, $outcome, 'skipped', 'Game has not started yet' );
				continue;
			}
			if ( Result_Service::validate_scores( $home, $away, $outcome ) ) {
				$rows[] = $this->row( $id, $home, $away, $outcome, 'skipped', 'Incomplete score' );
				continue;
			}
			if ( $dry_run ) {
				$rows[] = $this->row( $id, $home, $away, $outcome, 'would publish', Result_Service::can_finish_context( $id ) ? '' : 'Match context will be refused' );
				continue;
			}
			$result = $this->results->save( $id, array( 'home' => $home, 'away' => $away, 'outcome' => $outcome, 'mode' => 'finish', 'source' => 'cli-finish-scored' ) );
			if ( is_wp_error( $result ) ) {
				$rows[] = $this->row( $id, $home, $away, $outcome, 'failed', $result->get_error_message() );
			} else {
				++$done;
				$rows[] = $this->row( $id, $home, $away, $outcome, 'published', '' );
			}
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'match', 'result', 'action', 'note' ) );
		}
		\WP_CLI::success( $dry_run ? sprintf( '%d matches listed; nothing changed.', count( $rows ) ) : sprintf( '%d results published.', $done ) );
	}

	/** One output row. */
	protected function row( $id, $home, $away, $outcome, $action, $note ) {
		return array(
			'id'     => $id,
			'match'  => wp_specialchars_decode( get_the_title( $id ) ),
			'result' => '' !== $outcome ? $outcome . ( '' !== $home . $away ? " ($home-$away)" : '' ) : "$home-$away",
			'action' => $action,
			'note'   => $note,
		);
	}
}
