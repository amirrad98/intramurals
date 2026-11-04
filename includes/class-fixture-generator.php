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
	 * Match meta key linking a generated match to its generation run.
	 *
	 * @var string
	 */
	const META_BATCH = '_lf_fixture_batch';

	/**
	 * Generation scope (sport|level|competition|season) stored on each generated
	 * match, so the duplicate check still sees a match whose terms could not all
	 * be saved.
	 */
	const META_SCOPE = '_lf_fixture_scope';

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

		return array_values( array_filter( array_map( 'absint', get_posts( $args ) ), static function( $id ) { return current_user_can( 'read_post', $id ); } ) );
	}

	/**
	 * Persist generated rounds as draft matches.
	 *
	 * @param array<int, array<string, mixed>> $rounds Rounds from build_rounds().
	 * @param array<string, mixed>             $context Context term IDs and options.
	 * @return array<string, mixed>|\WP_Error Result with created IDs, failed pairings and
	 *                                     incomplete matches (created but missing part of their scope).
	 */
	public function persist( $rounds, $context ) {
		$context = $this->normalize_context( $context );
		$type = get_post_type_object( 'lf_match' );
		if ( ! current_user_can( 'leagueflow_manage_fixtures' ) || ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'leagueflow_fixtures_forbidden', __( 'You do not have permission to generate fixtures.', 'leagueflow' ), array( 'status' => 403 ) );
		}
		$status = 'publish' === $context['post_status'] && current_user_can( $type->cap->publish_posts ) ? 'publish' : 'draft';
		foreach ( $rounds as $round ) {
			foreach ( $round['matches'] as $pairing ) {
				foreach ( array( $pairing['home'] ?? 0, $pairing['away'] ?? 0 ) as $team_id ) {
					if ( 'lf_team' !== get_post_type( $team_id ) || ! current_user_can( 'read_post', $team_id ) ) {
						return new \WP_Error( 'leagueflow_fixtures_team_forbidden', __( 'Every fixture team must be readable by you.', 'leagueflow' ), array( 'status' => 403 ) );
					}
				}
			}
		}

		$created    = array();
		$failed     = array();
		$incomplete = array();

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
					// Reported, never hidden: the pairing was not created.
					$failed[] = array(
						'home'  => $home_id,
						'away'  => $away_id,
						'error' => is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'The match could not be saved.', 'leagueflow' ),
					);
					continue;
				}

				$problems = array();

				foreach ( array( 'lf_home_team_id' => $home_id, 'lf_away_team_id' => $away_id, 'lf_status' => 'scheduled', self::META_MATCHDAY => $round_number, 'lf_is_knockout' => 0 ) as $key => $value ) {
					if ( false === update_post_meta( $post_id, $key, $value ) && (string) get_post_meta( $post_id, $key, true ) !== (string) $value ) {
						$problems[] = $key;
					}
				}

				if ( '' !== (string) $context['batch'] && false === update_post_meta( $post_id, self::META_BATCH, sanitize_key( (string) $context['batch'] ) ) ) {
					$problems[] = self::META_BATCH;
				}

				if ( false === update_post_meta( $post_id, self::META_SCOPE, $this->scope_key( $context, $context['league_level_id'] ) ) ) {
					$problems[] = self::META_SCOPE;
				}

				$problems = array_merge( $problems, $this->assign_context_terms( $post_id, $context ) );

				if ( $problems ) {
					// The match exists but is missing part of its scope. Keep it (no
					// hidden deletes) and list it separately for review.
					$incomplete[] = array( 'id' => (int) $post_id, 'missing' => $problems );
					continue;
				}

				$created[] = (int) $post_id;
			}
		}

		return array(
			'created'         => count( $created ),
			'created_ids'     => $created,
			'failed'          => count( $failed ),
			'failed_pairings' => $failed,
			'incomplete_ids'  => wp_list_pluck( $incomplete, 'id' ),
			'incomplete'      => $incomplete,
			'rounds'          => count( $rounds ),
			'post_status'     => $status,
		);
	}

	/**
	 * Server-side preview of a generation run. No writes.
	 *
	 * Teams are grouped by league level (division): fixtures are only ever made
	 * between teams of the same level, matching the manual match rule. Teams
	 * without a level are listed and left out. With "All levels" and more than
	 * one level in scope, a person must choose one level or explicitly ask for
	 * each level to be generated separately. Pairings that already exist in
	 * the same sport, level, competition and season are counted as duplicates
	 * and are not created again.
	 *
	 * @param array<string, mixed> $context Context term IDs and options.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( $context ) {
		$context = $this->normalize_context( $context );

		if ( ! $context['sport_id'] ) {
			return new \WP_Error( 'leagueflow_fixtures_sport', __( 'Choose a sport. Fixtures are generated for one sport at a time.', 'leagueflow' ) );
		}

		$team_ids  = $this->get_context_team_ids( $context );
		$groups    = array();
		$no_level  = array();
		$legs      = 2 === (int) $context['legs'] ? 2 : 1;

		foreach ( $team_ids as $team_id ) {
			$level_id = get_post_primary_term_id( $team_id, 'lf_league_level' );

			if ( ! $level_id ) {
				$no_level[] = $team_id;
				continue;
			}

			$groups[ $level_id ][] = $team_id;
		}

		ksort( $groups );

		$summary = array();
		$state   = array( $context['sport_id'], $context['competition_id'], $context['season_id'], $context['league_level_id'], $legs, (int) ! empty( $context['per_level'] ) );

		foreach ( $groups as $level_id => $group_team_ids ) {
			$level      = get_term( $level_id, 'lf_league_level' );
			$rounds     = count( $group_team_ids ) >= 2 ? $this->build_rounds( $group_team_ids, $legs ) : array();
			$existing   = $this->existing_pair_keys( $context, $level_id, $legs );
			$new        = 0;
			$duplicates = 0;

			foreach ( $rounds as $round ) {
				foreach ( $round['matches'] as $pairing ) {
					isset( $existing[ $this->pair_key( $pairing['home'], $pairing['away'], $legs ) ] ) ? ++$duplicates : ++$new;
				}
			}

			$summary[] = array(
				'level_id'   => (int) $level_id,
				'level_name' => $level instanceof \WP_Term ? $level->name : '#' . $level_id,
				'team_ids'   => $group_team_ids,
				'new'        => $new,
				'duplicates' => $duplicates,
			);
			$state[]   = $level_id . ':' . implode( ',', $group_team_ids ) . ':' . $new . ':' . $duplicates;
		}

		$state[] = 'none:' . implode( ',', $no_level );

		return array(
			'groups'                => $summary,
			'no_level_team_ids'     => $no_level,
			'requires_level_choice' => ! $context['league_level_id'] && count( $summary ) > 1 && empty( $context['per_level'] ),
			'new'                   => array_sum( wp_list_pluck( $summary, 'new' ) ),
			'duplicates'            => array_sum( wp_list_pluck( $summary, 'duplicates' ) ),
			'token'                 => substr( hash( 'sha256', implode( '|', $state ) ), 0, 24 ),
		);
	}

	/**
	 * Generate and persist fixtures for a context, optionally scheduling them.
	 *
	 * Re-runs the preview under a lock and refuses when the scope needs a level
	 * choice, when the reviewed preview token no longer matches, or when every
	 * pairing already exists. Existing matches are never changed.
	 *
	 * @param array<string, mixed> $context Context term IDs and options (token = preview token).
	 * @return array<string, mixed>|\WP_Error Result summary or an error.
	 */
	public function generate( $context ) {
		if ( ! current_user_can( 'leagueflow_manage_fixtures' ) ) {
			return new \WP_Error( 'leagueflow_fixtures_forbidden', __( 'You do not have permission to generate fixtures.', 'leagueflow' ), array( 'status' => 403 ) );
		}

		$context = $this->normalize_context( $context );
		$lock    = db_lock_name( 'fix', (string) absint( $context['sport_id'] ) );

		if ( ! $this->lock( $lock ) ) {
			return new \WP_Error( 'leagueflow_fixtures_busy', __( 'Fixtures are already being generated for this sport, or the generation lock is unavailable. Nothing was created; reload and check.', 'leagueflow' ) );
		}

		try {
			$preview = $this->preview( $context );

			if ( is_wp_error( $preview ) ) {
				return $preview;
			}

			if ( '' !== (string) $context['token'] && ! hash_equals( $preview['token'], (string) $context['token'] ) ) {
				return new \WP_Error( 'leagueflow_fixtures_stale', __( 'The teams or existing matches changed since you reviewed this scope (or it was already generated). Nothing was created; review the preview again.', 'leagueflow' ) );
			}

			if ( $preview['requires_level_choice'] ) {
				return new \WP_Error( 'leagueflow_fixtures_level', __( 'Teams in different levels are in scope. Choose one level, or choose to generate each level separately. Nothing was created.', 'leagueflow' ) );
			}

			$groups = array_values( array_filter( $preview['groups'], static function ( $group ) { return count( $group['team_ids'] ) >= 2; } ) );

			if ( ! $groups ) {
				return new \WP_Error(
					'leagueflow_fixtures_teams',
					__( 'At least two teams in the same level must match the selected competition, season, sport, and level.', 'leagueflow' )
				);
			}

			if ( ! $preview['new'] ) {
				return new \WP_Error( 'leagueflow_fixtures_duplicates', __( 'Every pairing in this scope already exists. Nothing was created.', 'leagueflow' ) );
			}

			$legs             = 2 === (int) $context['legs'] ? 2 : 1;
			$context['batch'] = strtolower( wp_generate_password( 12, false, false ) );
			$result           = array(
				'created'         => 0,
				'created_ids'     => array(),
				'failed'          => 0,
				'failed_pairings' => array(),
				'incomplete_ids'  => array(),
				'incomplete'      => array(),
				'duplicates'      => 0,
				'teams'           => 0,
				'groups'          => array(),
				'batch'           => $context['batch'],
				'post_status'     => '',
				'partial'         => false,
				'partial_error'   => '',
			);

			foreach ( $groups as $group ) {
				$existing = $this->existing_pair_keys( $context, $group['level_id'], $legs );
				$rounds   = array();

				foreach ( $this->build_rounds( $group['team_ids'], $legs ) as $round ) {
					$round['matches'] = array_values(
						array_filter(
							$round['matches'],
							function ( $pairing ) use ( $existing, $legs ) {
								return ! isset( $existing[ $this->pair_key( $pairing['home'], $pairing['away'], $legs ) ] );
							}
						)
					);
					$rounds[]         = $round;
				}

				$persisted = $this->persist( $rounds, array_merge( $context, array( 'league_level_id' => $group['level_id'] ) ) );

				if ( is_wp_error( $persisted ) ) {
					if ( ! $result['created'] && ! $result['incomplete_ids'] ) {
						return $persisted;
					}

					// Earlier levels were written: keep them and report the stop.
					$result['partial']       = true;
					$result['partial_error'] = $persisted->get_error_message();
					break;
				}

				$result['created']        += (int) $persisted['created'];
				$result['created_ids']     = array_merge( $result['created_ids'], $persisted['created_ids'] );
				$result['failed']         += (int) $persisted['failed'];
				$result['failed_pairings'] = array_merge( $result['failed_pairings'], $persisted['failed_pairings'] );
				$result['incomplete_ids']  = array_merge( $result['incomplete_ids'], $persisted['incomplete_ids'] );
				$result['incomplete']      = array_merge( $result['incomplete'], $persisted['incomplete'] );
				$result['duplicates']     += (int) $group['duplicates'];
				$result['teams']      += count( $group['team_ids'] );
				$result['post_status'] = $persisted['post_status'];
				$result['groups'][]    = array( 'level_name' => $group['level_name'], 'created' => (int) $persisted['created'], 'failed' => (int) $persisted['failed'], 'incomplete' => count( $persisted['incomplete_ids'] ) );
			}

			if ( $result['failed'] || $result['incomplete_ids'] ) {
				$result['partial'] = true;
			}

			if ( ! $result['created'] && ! $result['incomplete_ids'] ) {
				return new \WP_Error( 'leagueflow_fixtures_failed', __( 'No fixture could be saved. Nothing was created.', 'leagueflow' ), array( 'failed_pairings' => $result['failed_pairings'] ) );
			}
		} finally {
			$this->unlock( $lock );
		}

		$result['scheduled']         = 0;
		$result['unscheduled']       = count( $result['created_ids'] );
		$result['schedule_messages'] = array();

		if ( ! empty( $context['auto_schedule'] ) && ! empty( $result['created_ids'] ) ) {
			$sport_slug = '';

			if ( $context['sport_id'] ) {
				$term       = get_term( $context['sport_id'], 'lf_sport' );
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
			$result['unscheduled']       = max( 0, count( $result['created_ids'] ) - $result['scheduled'] );
			$result['schedule_messages'] = (array) ( $schedule['messages'] ?? array() );
		}

		return $result;
	}

	/**
	 * Pairings that already exist (not trashed) in the same sport, level,
	 * competition and season. Knockout matches are ignored.
	 *
	 * @param array<string, mixed> $context Context.
	 * @param int                  $level_id Level.
	 * @param int                  $legs Legs.
	 * @return array<string, true>
	 */
	public function existing_pair_keys( $context, $level_id, $legs ) {
		$tax_query = array(
			array(
				'taxonomy' => 'lf_sport',
				'field'    => 'term_id',
				'terms'    => array( (int) $context['sport_id'] ),
			),
			array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'term_id',
				'terms'    => array( (int) $level_id ),
			),
		);

		foreach ( array( 'lf_competition' => $context['competition_id'], 'lf_season' => $context['season_id'] ) as $taxonomy => $term_id ) {
			$tax_query[] = $term_id
				? array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term_id ) )
				: array( 'taxonomy' => $taxonomy, 'operator' => 'NOT EXISTS' );
		}

		$statuses  = array( 'publish', 'private', 'draft', 'pending', 'future' );
		$match_ids = get_posts(
			array(
				'post_type'      => 'lf_match',
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => $tax_query,
				'no_found_rows'  => true,
			)
		);
		// Generated matches carry their scope too, even if a term write failed.
		$scoped    = get_posts(
			array(
				'post_type'      => 'lf_match',
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_SCOPE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $this->scope_key( $context, $level_id ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'no_found_rows'  => true,
			)
		);
		$match_ids = array_unique( array_merge( array_map( 'intval', $match_ids ), array_map( 'intval', $scoped ) ) );
		$keys      = array();

		foreach ( $match_ids as $match_id ) {
			if ( get_post_meta( $match_id, 'lf_is_knockout', true ) ) {
				continue;
			}

			$home = absint( get_post_meta( $match_id, 'lf_home_team_id', true ) );
			$away = absint( get_post_meta( $match_id, 'lf_away_team_id', true ) );

			if ( $home && $away ) {
				$keys[ $this->pair_key( $home, $away, $legs ) ] = true;
			}
		}

		return $keys;
	}

	/**
	 * Scope key of a generation run for one level.
	 *
	 * @param array<string, mixed> $context Context.
	 * @param int                  $level_id Level.
	 * @return string
	 */
	protected function scope_key( $context, $level_id ) {
		return implode( '|', array( absint( $context['sport_id'] ), absint( $level_id ), absint( $context['competition_id'] ), absint( $context['season_id'] ) ) );
	}

	/**
	 * Duplicate key: unordered for a single round-robin, ordered for home and away.
	 *
	 * @param int $home Home team.
	 * @param int $away Away team.
	 * @param int $legs Legs.
	 * @return string
	 */
	protected function pair_key( $home, $away, $legs ) {
		$home = (int) $home;
		$away = (int) $away;

		return 2 === (int) $legs ? $home . '>' . $away : min( $home, $away ) . '-' . max( $home, $away );
	}

	/**
	 * Take a named database lock. Fails closed: a timeout or an unavailable
	 * lock service creates nothing.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	protected function lock( $name ) {
		return acquire_db_lock( $name, 3 );
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	protected function unlock( $name ) {
		release_db_lock( $name );
	}

	/**
	 * Assign taxonomy terms from the context onto a generated match.
	 *
	 * @param int                  $post_id Match ID.
	 * @param array<string, mixed> $context Context.
	 * @return array<int, string> Taxonomies that could not be assigned.
	 */
	protected function assign_context_terms( $post_id, $context ) {
		$failed = array();

		foreach ( array( 'lf_sport' => 'sport_id', 'lf_league_level' => 'league_level_id', 'lf_competition' => 'competition_id', 'lf_season' => 'season_id' ) as $taxonomy => $key ) {
			if ( ! $context[ $key ] ) {
				continue;
			}

			$result = wp_set_object_terms( $post_id, array( (int) $context[ $key ] ), $taxonomy, false );

			if ( is_wp_error( $result ) || ! object_term_saved( $post_id, (int) $context[ $key ], $taxonomy ) ) {
				$failed[] = $taxonomy;
			}
		}

		return $failed;
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
				'per_level'       => false,
				'token'           => '',
				'batch'           => '',
			)
		);
	}
}
