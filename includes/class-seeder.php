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
	 * Create demo data.
	 *
	 * @return array<string, mixed>
	 */
	public function seed_demo_data() {
		$sports_manager = new Sports_Manager();
		$sports_manager->ensure_enabled_terms();

		$enabled_sports = $sports_manager->get_enabled_sport_slugs();
		$sport_slug     = ! empty( $enabled_sports ) ? $enabled_sports[0] : 'soccer';
		$sport_term     = get_term_by( 'slug', $sport_slug, 'lf_sport' );
		$sport_term_id  = ( $sport_term && ! is_wp_error( $sport_term ) ) ? (int) $sport_term->term_id : 0;

		$competition_ids = array(
			'spring-league' => $this->upsert_term( 'lf_competition', 'Spring League', 'spring-league' ),
			'2026-cup'      => $this->upsert_term( 'lf_competition', '2026 Cup', '2026-cup' ),
		);

		ensure_default_league_levels();

		$level_ids = array(
			'recreational' => $this->upsert_term( 'lf_league_level', 'Recreational', 'recreational' ),
			'competitive'  => $this->upsert_term( 'lf_league_level', 'Competitive', 'competitive' ),
		);

		$season_id = $this->upsert_term( 'lf_season', '2026 Spring', '2026-spring' );

		foreach ( $competition_ids as $term_id ) {
			update_term_meta( $term_id, 'lf_sport_slug', $sport_slug );
		}

		update_term_meta( $season_id, 'lf_sport_slug', $sport_slug );

		$teams = array(
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

		$team_ids = array();

		foreach ( $teams as $slug => $team ) {
			$team_ids[ $slug ] = $this->upsert_post(
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

		$player_map = array(
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

		foreach ( $player_map as $team_slug => $players ) {
			foreach ( $players as $player ) {
				$this->upsert_post(
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
			$this->upsert_post(
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

		$final_id = $this->upsert_post(
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

		$semi_one = $this->upsert_post(
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

		$semi_two = $this->upsert_post(
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

		$this->upsert_page( 'League Table', 'league-table', '[league_table competition="spring-league" season="2026-spring" sport="' . sanitize_key( $sport_slug ) . '" league_level="recreational"]' );
		$this->upsert_page( 'All Sport Standings', 'sport-standings', '[sport_standings competition="spring-league" season="2026-spring" sports="' . sanitize_key( $sport_slug ) . '"]' );
		$this->upsert_page( 'Fixtures & Results', 'fixtures-results', '[match_list competition="spring-league" season="2026-spring" sport="' . sanitize_key( $sport_slug ) . '"]' );
		$this->upsert_page( 'Cup Bracket', 'cup-bracket', '[knockout_bracket competition="2026-cup" season="2026-spring" sport="' . sanitize_key( $sport_slug ) . '" league_level="competitive"]' );

		return array(
			'competition_ids' => $competition_ids,
			'season_id'       => $season_id,
			'team_ids'        => $team_ids,
			'final_id'        => $final_id,
		);
	}

	/**
	 * Insert or update a page.
	 *
	 * @param string $title Page title.
	 * @param string $slug Page slug.
	 * @param string $content Page content.
	 * @return int
	 */
	protected function upsert_page( $title, $slug, $content ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );

		$args = array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		);

		if ( $page instanceof \WP_Post ) {
			$args['ID'] = $page->ID;
			return (int) wp_update_post( $args );
		}

		return (int) wp_insert_post( $args );
	}

	/**
	 * Insert or update a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name Name.
	 * @param string $slug Slug.
	 * @return int
	 */
	protected function upsert_term( $taxonomy, $name, $slug ) {
		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( $existing && ! is_wp_error( $existing ) ) {
			wp_update_term(
				$existing->term_id,
				$taxonomy,
				array(
					'name' => $name,
					'slug' => $slug,
				)
			);

			return (int) $existing->term_id;
		}

		$created = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug' => $slug,
			)
		);

		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	/**
	 * Insert or update a post plus meta and taxonomies.
	 *
	 * @param string               $post_type Post type.
	 * @param string               $title Title.
	 * @param string               $slug Slug.
	 * @param array<string, mixed> $post_args Post args.
	 * @param array<string, mixed> $meta Meta args.
	 * @param array<string, mixed> $taxonomies Taxonomy terms.
	 * @return int
	 */
	protected function upsert_post( $post_type, $title, $slug, $post_args = array(), $meta = array(), $taxonomies = array() ) {
		$existing = get_page_by_path( $slug, OBJECT, $post_type );

		$args = wp_parse_args(
			$post_args,
			array(
				'post_type'   => $post_type,
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => 'publish',
			)
		);

		if ( $existing instanceof \WP_Post ) {
			$args['ID'] = $existing->ID;
			$post_id    = (int) wp_update_post( $args );
		} else {
			$post_id = (int) wp_insert_post( $args );
		}

		foreach ( $meta as $key => $value ) {
			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			update_post_meta( $post_id, $key, $value );
		}

		foreach ( $taxonomies as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, array_map( 'absint', (array) $term_ids ), $taxonomy, false );
		}

		return $post_id;
	}
}
