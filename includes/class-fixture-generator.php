<?php
/**
 * Round-robin fixture generation.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Generates round-robin fixture pairings and persists them as draft matches.
 *
 * The pairing math (build_rounds) is a pure function so the same routine feeds
 * both the admin preview and the commit. Persisted fixtures are created as
 * drafts and can then be handed to the Field_Availability_Manager to receive a
 * date/time and venue.
 */
class Fixture_Generator {

	/**
	 * Match meta key storing the round (matchday) index of a league fixture.
	 *
	 * @var string
	 */
	const META_MATCHDAY = 'lf_matchday';

	/**
	 * Field availability manager for optional auto-scheduling.
	 *
	 * @var Field_Availability_Manager
	 */
	protected $field_availability_manager;

	/**
	 * Constructor.
	 *
	 * @param Field_Availability_Manager $field_availability_manager Scheduler.
	 */
	public function __construct( Field_Availability_Manager $field_availability_manager ) {
		$this->field_availability_manager = $field_availability_manager;
	}

	/**
	 * Build round-robin rounds from a list of team IDs (pure, no database writes).
	 *
	 * Uses the circle method: pin the first team and rotate the rest. An odd
	 * team count gets a bye (the phantom opponent 0). Home/away alternates each
	 * round for balance, and a second leg mirrors the fixtures with home/away
	 * swapped.
	 *
	 * @param array<int, int> $team_ids Team post IDs.
	 * @param int             $legs Number of legs (1 = single, 2 = home and away).
	 * @return array<int, array<string, mixed>> Rounds, each with a 'round' index and 'matches'.
	 */
	public function build_rounds( $team_ids, $legs = 1 ) {
		$teams = array_values( array_filter( array_map( 'absint', (array) $team_ids ) ) );
		$teams = array_values( array_unique( $teams ) );
		$legs  = max( 1, (int) $legs );

		if ( count( $teams ) < 2 ) {
			return array();
		}

		// Odd number of teams: add a bye marker (0) so everyone sits out once.
		if ( 0 !== count( $teams ) % 2 ) {
			$teams[] = 0;
		}

		$count            = count( $teams );
		$rounds_per_leg   = $count - 1;
		$matches_per_round = intdiv( $count, 2 );
		$rotation         = $teams;
		$rounds           = array();
		$round_number     = 0;

		for ( $round = 0; $round < $rounds_per_leg; $round++ ) {
			$pairings = array();

			for ( $match = 0; $match < $matches_per_round; $match++ ) {
				$home = $rotation[ $match ];
				$away = $rotation[ $count - 1 - $match ];

				if ( ! $home || ! $away ) {
					// One side is the bye marker; that team rests this round.
					continue;
				}

				// Circle method home/away balancing (as in the roundrobin library):
				// rotating boards balance naturally as their position shifts each
				// round, so only the fixed pivot board is swapped on odd rounds.
				if ( 0 === $match && 1 === $round % 2 ) {
					$pairings[] = array(
						'home' => $away,
						'away' => $home,
					);
				} else {
					$pairings[] = array(
						'home' => $home,
						'away' => $away,
					);
				}
			}

			$round_number++;
			$rounds[] = array(
				'round'   => $round_number,
				'matches' => $pairings,
			);

			// Rotate: keep the first team fixed, move the last into slot 1.
			$moved = array_splice( $rotation, $count - 1, 1 );
			array_splice( $rotation, 1, 0, $moved );
		}

		if ( $legs < 2 ) {
			return $rounds;
		}

		// Second leg: mirror every fixture with home and away reversed.
		$first_leg = $rounds;

		foreach ( $first_leg as $leg_round ) {
			$pairings = array();

			foreach ( $leg_round['matches'] as $pairing ) {
				$pairings[] = array(
					'home' => $pairing['away'],
					'away' => $pairing['home'],
				);
			}

			$round_number++;
			$rounds[] = array(
				'round'   => $round_number,
				'matches' => $pairings,
			);
		}

		return $rounds;
	}

	/**
	 * Count the fixtures a round-robin will produce.
	 *
	 * @param int $team_count Number of teams.
	 * @param int $legs Number of legs.
	 * @return int
	 */
	public function count_matches( $team_count, $legs = 1 ) {
		$team_count = max( 0, (int) $team_count );
		$legs       = max( 1, (int) $legs );

		if ( $team_count < 2 ) {
			return 0;
		}

		return (int) ( ( $team_count * ( $team_count - 1 ) / 2 ) * $legs );
	}

	/**
	 * Get team IDs within a competition/season/sport/level context.
	 *
	 * @param array<string, mixed> $context Context term IDs.
	 * @return array<int, int>
	 */
	public function get_context_team_ids( $context ) {
		$context = $this->normalize_context( $context );

		$args = array(
			'post_type'      => 'lf_team',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$tax_query = array();

		foreach ( array(
			'lf_competition'  => $context['competition_id'],
			'lf_season'       => $context['season_id'],
			'lf_sport'        => $context['sport_id'],
			'lf_league_level' => $context['league_level_id'],
		) as $taxonomy => $term_id ) {
			if ( $term_id ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => array( (int) $term_id ),
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		return array_values( array_map( 'absint', get_posts( $args ) ) );
	}

	/**
	 * Persist generated rounds as draft matches.
	 *
	 * @param array<int, array<string, mixed>> $rounds Rounds from build_rounds().
	 * @param array<string, mixed>             $context Context term IDs and options.
	 * @return array<string, mixed> Result summary with created IDs.
	 */
	public function persist( $rounds, $context ) {
		$context = $this->normalize_context( $context );
		$status  = 'publish' === $context['post_status'] ? 'publish' : 'draft';

		$created = array();

		foreach ( $rounds as $round ) {
			$round_number = (int) $round['round'];

			foreach ( $round['matches'] as $pairing ) {
				$home_id = absint( $pairing['home'] ?? 0 );
				$away_id = absint( $pairing['away'] ?? 0 );

				if ( ! $home_id || ! $away_id ) {
					continue;
				}

				$post_id = wp_insert_post(
					array(
						'post_type'   => 'lf_match',
						'post_status' => $status,
						'post_title'  => build_match_title( $home_id, $away_id ),
					),
					true
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					continue;
				}

				update_post_meta( $post_id, 'lf_home_team_id', $home_id );
				update_post_meta( $post_id, 'lf_away_team_id', $away_id );
				update_post_meta( $post_id, 'lf_status', 'scheduled' );
				update_post_meta( $post_id, self::META_MATCHDAY, $round_number );
				update_post_meta( $post_id, 'lf_is_knockout', 0 );

				$this->assign_context_terms( $post_id, $context );

				$created[] = (int) $post_id;
			}
		}

		return array(
			'created'       => count( $created ),
			'created_ids'   => $created,
			'rounds'        => count( $rounds ),
			'post_status'   => $status,
		);
	}

	/**
	 * Generate and persist fixtures for a context, optionally scheduling them.
	 *
	 * @param array<string, mixed> $context Context term IDs and options.
	 * @return array<string, mixed>|\WP_Error Result summary or an error.
	 */
	public function generate( $context ) {
		$context  = $this->normalize_context( $context );
		$team_ids = $this->get_context_team_ids( $context );

		if ( count( $team_ids ) < 2 ) {
			return new \WP_Error(
				'leagueflow_fixtures_teams',
				__( 'At least two teams must match the selected competition, season, sport, and level.', 'leagueflow' )
			);
		}

		$rounds = $this->build_rounds( $team_ids, $context['legs'] );
		$result = $this->persist( $rounds, $context );

		$result['teams']     = count( $team_ids );
		$result['scheduled'] = 0;

		if ( ! empty( $context['auto_schedule'] ) && ! empty( $result['created_ids'] ) ) {
			$sport_slug = '';

			if ( $context['sport_id'] ) {
				$term = get_term( $context['sport_id'], 'lf_sport' );
				$sport_slug = ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
			}

			$schedule = $this->field_availability_manager->schedule_matches(
				array(
					'match_ids'      => $result['created_ids'],
					'sport_slug'     => $sport_slug,
					'competition_id' => $context['competition_id'],
					'season_id'      => $context['season_id'],
					'mode'           => 'both',
				)
			);

			$result['scheduled']         = (int) ( $schedule['scheduled'] ?? 0 );
			$result['schedule_messages'] = (array) ( $schedule['messages'] ?? array() );
		}

		return $result;
	}

	/**
	 * Assign taxonomy terms from the context onto a generated match.
	 *
	 * @param int                  $post_id Match ID.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	protected function assign_context_terms( $post_id, $context ) {
		if ( $context['sport_id'] ) {
			wp_set_object_terms( $post_id, array( $context['sport_id'] ), 'lf_sport', false );
		}

		if ( $context['league_level_id'] ) {
			wp_set_object_terms( $post_id, array( $context['league_level_id'] ), 'lf_league_level', false );
		}

		if ( $context['competition_id'] ) {
			wp_set_object_terms( $post_id, array( $context['competition_id'] ), 'lf_competition', false );
		}

		if ( $context['season_id'] ) {
			wp_set_object_terms( $post_id, array( $context['season_id'] ), 'lf_season', false );
		}
	}

	/**
	 * Normalize a context array.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	protected function normalize_context( $context ) {
		return wp_parse_args(
			$context,
			array(
				'competition_id'  => 0,
				'season_id'       => 0,
				'sport_id'        => 0,
				'league_level_id' => 0,
				'legs'            => 1,
				'post_status'     => 'draft',
				'auto_schedule'   => false,
			)
		);
	}
}
