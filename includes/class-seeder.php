<?php
/**
 * Demo content generator.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Seed sample data for local testing.
 */
class Seeder {

	/**
	 * Marker stored on every post and term the demo seeder creates.
	 */
	const DEMO_META = '_leagueflow_demo_seed';

	/**
	 * Slugs the demo data uses, by object type.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function get_demo_slugs() {
		$players = array();

		foreach ( $this->get_demo_player_map() as $team_slug => $team_players ) {
			foreach ( $team_players as $player ) {
				$players[] = sanitize_title( $player['name'] . '-' . $team_slug );
			}
		}

		return array(
			'lf_competition' => array( 'spring-league', '2026-cup' ),
			'lf_season'      => array( '2026-spring' ),
			'lf_team'        => array_keys( $this->get_demo_teams() ),
			'lf_player'      => $players,
			'lf_match'       => array(
				'spring-league-northern-wolves-river-city',
				'spring-league-summit-athletic-timberline-united',
				'spring-league-northern-wolves-river-city-rematch',
				'spring-league-summit-athletic-timberline-united-rematch',
				'cup-final',
				'cup-semifinal-1',
				'cup-semifinal-2',
			),
			'page'           => array( 'league-table', 'sport-standings', 'fixtures-results', 'cup-bracket' ),
		);
	}

	/**
	 * Whether this site is a development environment that may hold demo data.
	 *
	 * @return bool
	 */
	public function is_demo_environment() {
		$allowed = ( defined( 'LEAGUEFLOW_ALLOW_DEMO_DATA' ) && LEAGUEFLOW_ALLOW_DEMO_DATA ) || in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		/**
		 * Filter whether demo data may be created on this site. Conflicts still block it.
		 *
		 * @param bool $allowed Whether the environment allows demo data.
		 */
		return (bool) apply_filters( 'leagueflow_allow_demo_data', $allowed );
	}

	/**
	 * Read-only preview of what demo seeding would do. Never writes.
	 *
	 * Demo data is create-only and only for empty development sites. Any existing
	 * league record, page or term with a demo slug blocks the whole run, including
	 * items an earlier demo run created (they may have been edited since). Remove
	 * earlier demo data by hand before creating it again.
	 *
	 * @return array<string, mixed>
	 */
	public function plan_demo_data() {
		$plan = array(
			'environment' => wp_get_environment_type(),
			'allowed'     => $this->is_demo_environment(),
			'creates'     => array(),
			'reuses'      => array(),
			'conflicts'   => array(),
		);

		$fingerprint = array();

		foreach ( $this->get_demo_slugs() as $type => $slugs ) {
			foreach ( $slugs as $slug ) {
				$existing = $this->find_existing( $type, $slug );
				$label    = $type . ':' . $slug;

				if ( ! $existing ) {
					$plan['creates'][] = $label;
					continue;
				}

				$is_demo             = $this->is_term_type( $type ) ? get_term_meta( $existing, self::DEMO_META, true ) : get_post_meta( $existing, self::DEMO_META, true );
				$plan['conflicts'][] = $label . ( $is_demo ? ' (earlier demo item)' : '' );
				$fingerprint[]       = $label . '#' . $existing . '@' . ( $this->is_term_type( $type ) ? '' : get_post_field( 'post_modified_gmt', $existing ) );
			}
		}

		foreach ( array( 'lf_team', 'lf_player', 'lf_match', 'lf_join_request', 'lf_calendar_event' ) as $post_type ) {
			$counts = (array) wp_count_posts( $post_type );
			unset( $counts['auto-draft'] ); // Empty "Add New" placeholders WordPress deletes on its own.
			$count = array_sum( array_map( 'intval', $counts ) );

			if ( $count > 0 ) {
				$plan['conflicts'][] = $post_type . ':existing-league-records';
			}

			$fingerprint[] = $post_type . '=' . $count;
		}

		$plan['conflicts'] = array_values( array_unique( $plan['conflicts'] ) );
		$plan['ready']     = $plan['allowed'] && empty( $plan['conflicts'] );
		$plan['token']     = substr( hash( 'sha256', wp_json_encode( array( $plan['creates'], $plan['conflicts'], $fingerprint ) ) ), 0, 16 );

		return $plan;
	}

	/**
	 * Whether a demo object type is a taxonomy.
	 *
	 * @param string $type Post type or taxonomy.
	 * @return bool
	 */
	protected function is_term_type( $type ) {
		return in_array( $type, array( 'lf_competition', 'lf_season' ), true );
	}

	/**
	 * Existing object ID for a demo slug, in any status (including trash).
	 *
	 * @param string $type Post type or taxonomy.
	 * @param string $slug Slug.
	 * @return int
	 */
	protected function find_existing( $type, $slug ) {
		global $wpdb;

		if ( $this->is_term_type( $type ) ) {
			$term = get_term_by( 'slug', $slug, $type );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}

		// Includes trashed posts, whose slug WordPress keeps with a __trashed suffix.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name IN (%s, %s) LIMIT 1",
				$type,
				$slug,
				$slug . '__trashed'
			)
		);
	}

	/**
	 * Create demo data after a successful preview.
	 *
	 * Refuses, before any write, outside a development environment or when the
	 * preview has conflicts. It never updates a page, term or league record it
	 * did not create.
	 *
	 * @param string $token Optional preview token the user confirmed.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function seed_demo_data( $token = '' ) {
		$plan = $this->plan_demo_data();

		if ( ! $plan['allowed'] ) {
			return new \WP_Error( 'leagueflow_demo_environment', __( 'Demo data is only available on local or development sites.', 'leagueflow' ) );
		}

		if ( $plan['conflicts'] ) {
			return new \WP_Error( 'leagueflow_demo_conflicts', __( 'Demo data was not created because this site already has league records, pages or terms it would collide with.', 'leagueflow' ), $plan['conflicts'] );
		}

		if ( '' !== $token && ! hash_equals( $plan['token'], (string) $token ) ) {
			return new \WP_Error( 'leagueflow_demo_stale', __( 'The site changed after the demo preview. Review the preview again before creating demo data.', 'leagueflow' ) );
		}

		$this->created = array();

		try {
			return $this->create_demo_data();
		} catch ( \RuntimeException $collision ) {
			// Something appeared after the preview. Stop: never adopt or edit it.
			return new \WP_Error(
				'leagueflow_demo_collision',
				__( 'Demo data creation stopped because a matching record appeared after the preview. Existing records were not changed; only the demo items listed were created.', 'leagueflow' ),
				array(
					'collision' => $collision->getMessage(),
					'created'   => $this->created,
				)
			);
		}
	}

	/**
	 * Objects created by the current run, as type:id labels.
	 *
	 * @var array<int, string>
	 */
	protected $created = array();

	/**
	 * Create every demo object. Throws on any collision before touching it.
	 *
	 * @return array<string, mixed>
	 */
	protected function create_demo_data() {

		$sports_manager = new Sports_Manager();
		// Create-only: an existing sport term (renamed or not) is reused untouched.
		$sports_manager->ensure_missing_enabled_terms();

		$enabled_sports = $sports_manager->get_enabled_sport_slugs();
		$sport_slug     = ! empty( $enabled_sports ) ? $enabled_sports[0] : 'soccer';
		$sport_term     = get_term_by( 'slug', $sport_slug, 'lf_sport' );
		$sport_term_id  = ( $sport_term && ! is_wp_error( $sport_term ) ) ? (int) $sport_term->term_id : 0;

		$competition_ids = array(
			'spring-league' => $this->create_term( 'lf_competition', 'Spring League', 'spring-league' ),
			'2026-cup'      => $this->create_term( 'lf_competition', '2026 Cup', '2026-cup' ),
		);

		ensure_default_league_levels();

		// League levels are shared site configuration: reuse them, never rename them.
		$level_ids = array();

		foreach ( array( 'recreational' => 'Recreational', 'competitive' => 'Competitive' ) as $level_slug => $level_name ) {
			$level                    = get_term_by( 'slug', $level_slug, 'lf_league_level' );
			$level_ids[ $level_slug ] = $level instanceof \WP_Term ? (int) $level->term_id : 0;
		}

		$season_id = $this->create_term( 'lf_season', '2026 Spring', '2026-spring' );

		// Only the terms created just above (never an existing term) get a sport.
		foreach ( array_merge( array_values( $competition_ids ), array( $season_id ) ) as $term_id ) {
			update_term_meta( $term_id, 'lf_sport_slug', $sport_slug );
		}

		$teams = $this->get_demo_teams();

		$team_ids = array();

		foreach ( $teams as $slug => $team ) {
			$team_ids[ $slug ] = $this->create_post(
				'lf_team',
				$team['name'],
				$slug,
				array(
					'post_content' => $team['description'],
					'post_excerpt' => $team['description'],
					'post_status'  => 'publish',
				),
				array(
					'lf_short_name'   => $team['short_name'],
					'lf_city'         => $team['city'],
					'lf_coach'        => $team['coach'],
					'lf_founded_year' => $team['founded_year'],
				),
				array(
					'lf_sport'       => $sport_term_id ? array( $sport_term_id ) : array(),
					'lf_league_level' => array( $level_ids[ $team['level'] ] ),
					'lf_competition' => array_values( $competition_ids ),
					'lf_season'      => array( $season_id ),
				)
			);
		}

		$player_map = $this->get_demo_player_map();

		foreach ( $player_map as $team_slug => $players ) {
			foreach ( $players as $player ) {
				$player_id = $this->create_post(
					'lf_player',
					$player['name'],
					sanitize_title( $player['name'] . '-' . $team_slug ),
					array(
						'post_status' => 'publish',
					),
					array(
						'lf_jersey_number' => $player['number'],
						'lf_position'      => $player['position'],
						'lf_age'           => $player['age'],
						'lf_nationality'   => $player['nationality'],
						'lf_team_id'       => $team_ids[ $team_slug ],
						'lf_is_captain'    => $player['captain'] ? 1 : 0,
					),
					array(
						'lf_sport'        => $sport_term_id ? array( $sport_term_id ) : array(),
						'lf_league_level' => array( $level_ids[ $teams[ $team_slug ]['level'] ] ),
					)
				);

				assign_player_to_team(
					$player_id,
					$team_ids[ $team_slug ],
					array(
						'jersey_number' => $player['number'],
						'position'      => $player['position'],
						'is_captain'    => $player['captain'] ? 1 : 0,
					)
				);
			}
		}

		$league_matches = array(
			array(
				'slug'       => 'spring-league-northern-wolves-river-city',
				'title'      => build_match_title( $team_ids['northern-wolves'], $team_ids['river-city'], '2026-03-18 19:00' ),
				'datetime'   => '2026-03-18 19:00',
				'venue'      => 'Campus Field 1',
				'home'       => $team_ids['northern-wolves'],
				'away'       => $team_ids['river-city'],
				'home_score' => '2',
				'away_score' => '1',
				'status'     => 'finished',
				'level'      => 'recreational',
			),
			array(
				'slug'       => 'spring-league-summit-athletic-timberline-united',
				'title'      => build_match_title( $team_ids['summit-athletic'], $team_ids['timberline-united'], '2026-03-19 20:00' ),
				'datetime'   => '2026-03-19 20:00',
				'venue'      => 'Campus Field 2',
				'home'       => $team_ids['summit-athletic'],
				'away'       => $team_ids['timberline-united'],
				'home_score' => '1',
				'away_score' => '1',
				'status'     => 'finished',
				'level'      => 'competitive',
			),
			array(
				'slug'       => 'spring-league-northern-wolves-river-city-rematch',
				'title'      => build_match_title( $team_ids['northern-wolves'], $team_ids['river-city'], '2026-04-05 18:30' ),
				'datetime'   => '2026-04-05 18:30',
				'venue'      => 'Campus Field 1',
				'home'       => $team_ids['northern-wolves'],
				'away'       => $team_ids['river-city'],
				'home_score' => '',
				'away_score' => '',
				'status'     => 'scheduled',
				'level'      => 'recreational',
			),
			array(
				'slug'       => 'spring-league-summit-athletic-timberline-united-rematch',
				'title'      => build_match_title( $team_ids['summit-athletic'], $team_ids['timberline-united'], '2026-04-07 18:30' ),
				'datetime'   => '2026-04-07 18:30',
				'venue'      => 'Campus Field 2',
				'home'       => $team_ids['summit-athletic'],
				'away'       => $team_ids['timberline-united'],
				'home_score' => '',
				'away_score' => '',
				'status'     => 'scheduled',
				'level'      => 'competitive',
			),
		);

		foreach ( $league_matches as $match ) {
			$this->create_post(
				'lf_match',
				$match['title'],
				$match['slug'],
				array(
					'post_status' => 'publish',
				),
				array(
					'lf_match_datetime' => $match['datetime'],
					'lf_venue'          => $match['venue'],
					'lf_home_team_id'   => $match['home'],
					'lf_away_team_id'   => $match['away'],
					'lf_home_score'     => $match['home_score'],
					'lf_away_score'     => $match['away_score'],
					'lf_status'         => $match['status'],
					'lf_is_knockout'    => 0,
				),
				array(
					'lf_sport'       => $sport_term_id ? array( $sport_term_id ) : array(),
					'lf_league_level' => array( $level_ids[ $match['level'] ] ),
					'lf_competition' => array( $competition_ids['spring-league'] ),
					'lf_season'      => array( $season_id ),
				)
			);
		}

		$final_id = $this->create_post(
			'lf_match',
			build_match_title( 0, 0, '2026-04-20 19:00' ),
			'cup-final',
			array(
				'post_status' => 'publish',
			),
			array(
				'lf_match_datetime' => '2026-04-20 19:00',
				'lf_venue'          => 'Championship Ground',
				'lf_home_team_id'   => 0,
				'lf_away_team_id'   => 0,
				'lf_home_score'     => '',
				'lf_away_score'     => '',
				'lf_status'         => 'scheduled',
				'lf_is_knockout'    => 1,
				'lf_round_label'    => 'Final',
				'lf_round_order'    => 40,
			),
			array(
				'lf_sport'       => $sport_term_id ? array( $sport_term_id ) : array(),
				'lf_league_level' => array( $level_ids['competitive'] ),
				'lf_competition' => array( $competition_ids['2026-cup'] ),
				'lf_season'      => array( $season_id ),
			)
		);

		$semi_one = $this->create_post(
			'lf_match',
			build_match_title( $team_ids['northern-wolves'], $team_ids['timberline-united'], '2026-04-12 18:00' ),
			'cup-semifinal-1',
			array( 'post_status' => 'publish' ),
			array(
				'lf_match_datetime' => '2026-04-12 18:00',
				'lf_venue'          => 'Cup Field North',
				'lf_home_team_id'   => $team_ids['northern-wolves'],
				'lf_away_team_id'   => $team_ids['timberline-united'],
				'lf_home_score'     => '3',
				'lf_away_score'     => '2',
				'lf_status'         => 'finished',
				'lf_is_knockout'    => 1,
				'lf_round_label'    => 'Semifinals',
				'lf_round_order'    => 30,
				'lf_next_match_id'  => $final_id,
				'lf_next_match_slot'=> 'home',
			),
			array(
				'lf_sport'       => $sport_term_id ? array( $sport_term_id ) : array(),
				'lf_league_level' => array( $level_ids['competitive'] ),
				'lf_competition' => array( $competition_ids['2026-cup'] ),
				'lf_season'      => array( $season_id ),
			)
		);

		$semi_two = $this->create_post(
			'lf_match',
			build_match_title( $team_ids['river-city'], $team_ids['summit-athletic'], '2026-04-13 18:00' ),
			'cup-semifinal-2',
			array( 'post_status' => 'publish' ),
			array(
				'lf_match_datetime' => '2026-04-13 18:00',
				'lf_venue'          => 'Cup Field South',
				'lf_home_team_id'   => $team_ids['river-city'],
				'lf_away_team_id'   => $team_ids['summit-athletic'],
				'lf_home_score'     => '1',
				'lf_away_score'     => '2',
				'lf_status'         => 'finished',
				'lf_is_knockout'    => 1,
				'lf_round_label'    => 'Semifinals',
				'lf_round_order'    => 30,
				'lf_next_match_id'  => $final_id,
				'lf_next_match_slot'=> 'away',
			),
			array(
				'lf_sport'       => $sport_term_id ? array( $sport_term_id ) : array(),
				'lf_league_level' => array( $level_ids['competitive'] ),
				'lf_competition' => array( $competition_ids['2026-cup'] ),
				'lf_season'      => array( $season_id ),
			)
		);

		$knockout = new Knockout_Service();
		$knockout->advance_winner( $semi_one );
		$knockout->advance_winner( $semi_two );

		$this->create_page( 'League Table', 'league-table', '[league_table competition="spring-league" season="2026-spring" sport="' . sanitize_key( $sport_slug ) . '" league_level="recreational"]' );
		$this->create_page( 'All Sport Standings', 'sport-standings', '[sport_standings competition="spring-league" season="2026-spring" sports="' . sanitize_key( $sport_slug ) . '"]' );
		$this->create_page( 'Fixtures & Results', 'fixtures-results', '[match_list competition="spring-league" season="2026-spring" sport="' . sanitize_key( $sport_slug ) . '"]' );
		$this->create_page( 'Cup Bracket', 'cup-bracket', '[knockout_bracket competition="2026-cup" season="2026-spring" sport="' . sanitize_key( $sport_slug ) . '" league_level="competitive"]' );

		return array(
			'competition_ids' => $competition_ids,
			'season_id'       => $season_id,
			'team_ids'        => $team_ids,
			'final_id'        => $final_id,
		);
	}

	/**
	 * Demo team definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_demo_teams() {
		return array(
			'northern-wolves'  => array(
				'name'         => 'Northern Wolves FC',
				'short_name'   => 'NWFC',
				'city'         => 'Prince George',
				'coach'        => 'Jordan Walsh',
				'founded_year' => 2011,
				'level'        => 'recreational',
				'description'  => 'A disciplined side built around a compact midfield and aggressive pressing in transition.',
			),
			'river-city'       => array(
				'name'         => 'River City FC',
				'short_name'   => 'RCFC',
				'city'         => 'Quesnel',
				'coach'        => 'Mateo Alvarez',
				'founded_year' => 2014,
				'level'        => 'recreational',
				'description'  => 'River City rely on possession control and quick switches from flank to flank.',
			),
			'summit-athletic'  => array(
				'name'         => 'Summit Athletic',
				'short_name'   => 'SUM',
				'city'         => 'Burns Lake',
				'coach'        => 'Priya Singh',
				'founded_year' => 2009,
				'level'        => 'competitive',
				'description'  => 'Summit Athletic lean on technical midfield play and late runs from full-back.',
			),
			'timberline-united' => array(
				'name'         => 'Timberline United',
				'short_name'   => 'TLU',
				'city'         => 'Smithers',
				'coach'        => 'Connor Lee',
				'founded_year' => 2016,
				'level'        => 'competitive',
				'description'  => 'Timberline are direct, physical, and dangerous from corners and second balls.',
			),
		);
	}

	/**
	 * Demo roster definitions keyed by team slug.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	protected function get_demo_player_map() {
		return array(
			'northern-wolves' => array(
				array( 'name' => 'Ethan Cole', 'number' => 1, 'position' => 'Goalkeeper', 'age' => 24, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Lucas Hart', 'number' => 4, 'position' => 'Centre Back', 'age' => 27, 'nationality' => 'Canada', 'captain' => true ),
				array( 'name' => 'Noah Kerr', 'number' => 8, 'position' => 'Midfielder', 'age' => 23, 'nationality' => 'Scotland', 'captain' => false ),
				array( 'name' => 'Owen Park', 'number' => 10, 'position' => 'Attacking Midfielder', 'age' => 22, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Mason Reid', 'number' => 9, 'position' => 'Forward', 'age' => 25, 'nationality' => 'England', 'captain' => false ),
			),
			'river-city' => array(
				array( 'name' => 'Hugo Diaz', 'number' => 1, 'position' => 'Goalkeeper', 'age' => 26, 'nationality' => 'Mexico', 'captain' => false ),
				array( 'name' => 'Caleb Shaw', 'number' => 5, 'position' => 'Centre Back', 'age' => 28, 'nationality' => 'Canada', 'captain' => true ),
				array( 'name' => 'Mateus Silva', 'number' => 6, 'position' => 'Defensive Midfielder', 'age' => 24, 'nationality' => 'Brazil', 'captain' => false ),
				array( 'name' => 'Jamie Frost', 'number' => 11, 'position' => 'Winger', 'age' => 22, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Leo Bennett', 'number' => 14, 'position' => 'Forward', 'age' => 21, 'nationality' => 'Canada', 'captain' => false ),
			),
			'summit-athletic' => array(
				array( 'name' => 'Arjun Patel', 'number' => 1, 'position' => 'Goalkeeper', 'age' => 23, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Ben Sato', 'number' => 3, 'position' => 'Left Back', 'age' => 24, 'nationality' => 'Japan', 'captain' => false ),
				array( 'name' => 'Elias Murray', 'number' => 7, 'position' => 'Winger', 'age' => 22, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Rohan Gill', 'number' => 8, 'position' => 'Central Midfielder', 'age' => 26, 'nationality' => 'Canada', 'captain' => true ),
				array( 'name' => 'Felix Grant', 'number' => 19, 'position' => 'Forward', 'age' => 25, 'nationality' => 'Ireland', 'captain' => false ),
			),
			'timberline-united' => array(
				array( 'name' => 'Isaac Moore', 'number' => 1, 'position' => 'Goalkeeper', 'age' => 25, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Cole Turner', 'number' => 2, 'position' => 'Right Back', 'age' => 23, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Samir Rahman', 'number' => 6, 'position' => 'Centre Back', 'age' => 27, 'nationality' => 'Bangladesh', 'captain' => true ),
				array( 'name' => 'Ty Brooks', 'number' => 12, 'position' => 'Winger', 'age' => 21, 'nationality' => 'Canada', 'captain' => false ),
				array( 'name' => 'Jonas Mercer', 'number' => 17, 'position' => 'Striker', 'age' => 24, 'nationality' => 'USA', 'captain' => false ),
			),
		);
	}

	/**
	 * Throw when a demo slug is already taken (it appeared after the preview).
	 *
	 * @param string $type Post type or taxonomy.
	 * @param string $slug Slug.
	 * @return void
	 * @throws \RuntimeException On a collision.
	 */
	protected function assert_absent( $type, $slug ) {
		if ( $this->find_existing( $type, $slug ) ) {
			throw new \RuntimeException( $type . ':' . $slug );
		}
	}

	/**
	 * Create a demo page. Never updates an existing page.
	 *
	 * @param string $title Page title.
	 * @param string $slug Page slug.
	 * @param string $content Page content.
	 * @return int
	 */
	protected function create_page( $title, $slug, $content ) {
		return $this->create_post( 'page', $title, $slug, array( 'post_content' => $content ) );
	}

	/**
	 * Create a demo term. Never reuses or edits an existing term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name Name.
	 * @param string $slug Slug.
	 * @return int
	 * @throws \RuntimeException On a collision or failed insert.
	 */
	protected function create_term( $taxonomy, $name, $slug ) {
		$this->assert_absent( $taxonomy, $slug );

		$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );

		if ( is_wp_error( $created ) ) {
			throw new \RuntimeException( $taxonomy . ':' . $slug );
		}

		update_term_meta( (int) $created['term_id'], self::DEMO_META, 1 );
		$this->created[] = $taxonomy . ':' . (int) $created['term_id'];

		return (int) $created['term_id'];
	}

	/**
	 * Create a demo post with meta and terms. Never updates an existing post.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $title Title.
	 * @param string               $slug Slug.
	 * @param array<string, mixed> $post_args Post args.
	 * @param array<string, mixed> $meta Meta args.
	 * @param array<string, mixed> $taxonomies Taxonomy terms.
	 * @return int
	 * @throws \RuntimeException On a collision or failed insert.
	 */
	protected function create_post( $post_type, $title, $slug, $post_args = array(), $meta = array(), $taxonomies = array() ) {
		$this->assert_absent( $post_type, $slug );

		$args = wp_parse_args(
			$post_args,
			array(
				'post_type'   => $post_type,
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => 'publish',
			)
		);

		unset( $args['ID'] );
		$args['meta_input'] = array( self::DEMO_META => 1 );
		$post_id            = (int) wp_insert_post( $args );

		if ( ! $post_id ) {
			throw new \RuntimeException( $post_type . ':' . $slug );
		}

		$this->created[] = $post_type . ':' . $post_id;

		foreach ( $meta as $key => $value ) {
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		foreach ( $taxonomies as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, array_filter( array_map( 'absint', (array) $term_ids ) ), $taxonomy, false );
		}

		return $post_id;
	}
}
