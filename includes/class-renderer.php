<?php
/**
 * Shared frontend and block renderer.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend rendering.
 */
class Renderer {

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
	 * Sports manager.
	 *
	 * @var Sports_Manager
	 */
	protected $sports_manager;

	/**
	 * Standalone calendar event type labels.
	 *
	 * @var array<string, string>
	 */
	protected $calendar_event_type_labels = array(
		'drop_in'    => 'Drop-in',
		'practice'   => 'Practice',
		'clinic'     => 'Clinic',
		'tournament' => 'Tournament',
		'meeting'    => 'Meeting',
		'other'      => 'Other',
	);

	/**
	 * Constructor.
	 *
	 * @param Standings_Service $standings_service Standings.
	 * @param Knockout_Service  $knockout_service Knockout.
	 * @param Sports_Manager    $sports_manager Sports.
	 */
	public function __construct( Standings_Service $standings_service, Knockout_Service $knockout_service, Sports_Manager $sports_manager ) {
		$this->standings_service = $standings_service;
		$this->knockout_service  = $knockout_service;
		$this->sports_manager    = $sports_manager;
	}

	/**
	 * Render the league table.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_league_table( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'competition'  => '',
				'season'       => '',
				'sport'        => '',
				'league_level' => '',
				'show_logos'   => '',
			),
			$atts,
			'league_table'
		);

		$competition_id  = resolve_term_id( $atts['competition'], 'lf_competition' );
		$season_id       = resolve_season_context( $atts['season'] );
		$sport_id        = resolve_term_id( $atts['sport'], 'lf_sport' );
		$league_level_id = resolve_term_id( $atts['league_level'], 'lf_league_level' );
		$sport_slug      = $this->resolve_context_sport_slug( $sport_id, $competition_id, $season_id );
		$show_logos      = '' === $atts['show_logos'] ? (bool) get_setting( 'show_logos', 1 ) : ! empty( $atts['show_logos'] );
		$rows            = $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, $league_level_id );

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'league-table.php',
			array(
				'rows'         => $rows,
				'show_logos'   => $show_logos,
				'table_labels' => $this->sports_manager->get_definition( $sport_slug )['table_labels'],
			)
		);
	}

	/**
	 * Render standings grouped by sport.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_sport_standings( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'competition' => '',
				'season'      => '',
				'sports'      => '',
				'league_level' => '',
				'show_logos'  => '',
			),
			$atts,
			'sport_standings'
		);

		$competition_id   = resolve_term_id( $atts['competition'], 'lf_competition' );
		$season_id        = resolve_season_context( $atts['season'] );
		$league_level_id  = resolve_term_id( $atts['league_level'], 'lf_league_level' );
		$show_logos       = '' === $atts['show_logos'] ? (bool) get_setting( 'show_logos', 1 ) : ! empty( $atts['show_logos'] );
		$enabled_sports   = $this->sports_manager->get_enabled_sports();
		$requested        = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $atts['sports'] ) ) ) );
		$league_levels    = $league_level_id ? array( get_term( $league_level_id, 'lf_league_level' ) ) : $this->get_league_level_terms();

		if ( ! empty( $requested ) ) {
			$enabled_sports = array_intersect_key( $enabled_sports, array_flip( $requested ) );
		}

		$sections = array();

		foreach ( $enabled_sports as $sport_slug => $sport ) {
			$sport_id = resolve_term_id( $sport_slug, 'lf_sport' );

			foreach ( $league_levels as $league_level ) {
				if ( ! $league_level || is_wp_error( $league_level ) ) {
					continue;
				}

				$rows = $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, (int) $league_level->term_id );

				$sections[] = array(
					'slug'         => $sport_slug,
					'label'        => sprintf(
						/* translators: 1: sport label, 2: league level label. */
						__( '%1$s - %2$s', 'leagueflow' ),
						$sport['label'],
						$league_level->name
					),
					'level_slug'   => $league_level->slug,
					'level_label'  => $league_level->name,
					'rows'         => $rows,
					'table_labels' => $sport['table_labels'],
				);
			}
		}

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'sport-standings.php',
			array(
				'sections'   => $sections,
				'show_logos' => $show_logos,
			)
		);
	}

	/**
	 * Render a team list.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_team_list( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'competition'  => '',
				'season'       => '',
				'sport'        => '',
				'league_level' => '',
				'show_logos'   => '',
			),
			$atts,
			'team_list'
		);

		$competition_id  = resolve_term_id( $atts['competition'], 'lf_competition' );
		$season_id       = resolve_season_context( $atts['season'] );
		$sport_id        = resolve_term_id( $atts['sport'], 'lf_sport' );
		$league_level_id = resolve_term_id( $atts['league_level'], 'lf_league_level' );
		$show_logos      = '' === $atts['show_logos'] ? (bool) get_setting( 'show_logos', 1 ) : ! empty( $atts['show_logos'] );
		$teams           = $this->get_team_items( $competition_id, $season_id, $sport_id, $league_level_id );

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'team-list.php',
			array(
				'teams'       => $teams,
				'show_logos'  => $show_logos,
			)
		);
	}

	/**
	 * Render a team roster.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_team_roster( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'team'        => '',
				'show_photos' => '',
			),
			$atts,
			'team_roster'
		);

		$team_id = resolve_post_id( $atts['team'], 'lf_team' );

		if ( ! $team_id && is_singular( 'lf_team' ) ) {
			$team_id = get_the_ID();
		}

		if ( ! $team_id ) {
			return '<p>' . esc_html__( 'Select a team to display its roster.', 'leagueflow' ) . '</p>';
		}

		$show_photos = '' === $atts['show_photos'] ? (bool) get_setting( 'show_player_photos', 1 ) : ! empty( $atts['show_photos'] );
		$players     = $this->get_roster_items( $team_id );

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'team-roster.php',
			array(
				'players'      => $players,
				'show_photos'  => $show_photos,
				'team_id'      => $team_id,
				'team_name'    => get_the_title( $team_id ),
			)
		);
	}

	/**
	 * Render a match list.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_match_list( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'competition'      => '',
				'season'           => '',
				'sport'            => '',
				'league_level'     => '',
				'status'           => '',
				'limit'            => 20,
				'include_knockout' => '',
				'team'             => '',
			),
			$atts,
			'match_list'
		);

		$matches = $this->get_match_items(
			array(
				'competition'      => $atts['competition'],
				'season'           => resolve_season_context( $atts['season'] ),
				'sport'            => $atts['sport'],
				'league_level'     => $atts['league_level'],
				'status'           => $atts['status'],
				'limit'            => (int) $atts['limit'],
				'include_knockout' => '' === $atts['include_knockout'] ? null : ! empty( $atts['include_knockout'] ),
				'team'             => $atts['team'],
			)
		);

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'match-list.php',
			array(
				'matches' => $matches,
			)
		);
	}

	/**
	 * Render the interactive schedule calendar.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_match_calendar( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'competition'      => '',
				'season'           => '',
				'sport'            => '',
				'league_level'     => '',
				'team'             => '',
				'include_knockout' => '',
				'include_events'   => '1',
				'event_type'       => '',
				'view'             => 'month',
				'show_week'        => '1',
				'show_day'         => '1',
				'list_initial'     => 30,
				'list_more'        => 15,
				'limit'            => -1,
			),
			$atts,
			'match_calendar'
		);

		$include_events = $this->truthy_shortcode_value( $atts['include_events'] );
		$show_week      = $this->truthy_shortcode_value( $atts['show_week'] );
		$show_day       = $this->truthy_shortcode_value( $atts['show_day'] );
		$initial_view   = sanitize_key( (string) $atts['view'] );
		$allowed_views  = array( 'month', 'list', 'week', 'day' );

		if ( ! in_array( $initial_view, $allowed_views, true ) ) {
			$initial_view = 'month';
		}

		if ( 'week' === $initial_view && ! $show_week ) {
			$initial_view = 'month';
		}

		if ( 'day' === $initial_view && ! $show_day ) {
			$initial_view = 'month';
		}

		$events = $this->get_calendar_items(
			array(
				'competition'      => $atts['competition'],
				'season'           => resolve_season_context( $atts['season'] ),
				'sport'            => $atts['sport'],
				'league_level'     => $atts['league_level'],
				'team'             => $atts['team'],
				'include_knockout' => '' === $atts['include_knockout'] ? null : ! empty( $atts['include_knockout'] ),
				'include_events'   => $include_events,
				'event_type'       => $atts['event_type'],
				'limit'            => (int) $atts['limit'],
			)
		);

		$sport_data = $this->build_calendar_sport_data( $events );
		$type_data  = $this->build_calendar_type_data( $events );

		$colors = apply_filters(
			'leagueflow_calendar_sport_colors',
			array(
				'soccer'            => '#2e7d4f',
				'basketball'        => '#c2571f',
				'volleyball'        => '#b08c2e',
				'hockey'            => '#2b5d8a',
				'baseball'          => '#8a3b3b',
				'american-football' => '#6b4f2e',
				'cricket'           => '#3f7a7a',
				'rugby'             => '#7a3b5e',
			)
		);

		$fallback_palette = array( '#2e7d4f', '#c2571f', '#b08c2e', '#2b5d8a', '#8a3b3b', '#3f7a7a', '#7a3b5e', '#6b4f2e' );
		$fallback_index   = 0;

		foreach ( $sport_data as $slug => $sport ) {
			if ( isset( $colors[ $slug ] ) ) {
				$sport_data[ $slug ]['color'] = $colors[ $slug ];
			} else {
				$sport_data[ $slug ]['color'] = $fallback_palette[ $fallback_index % count( $fallback_palette ) ];
				$fallback_index++;
			}
		}

		// Keep chips in a stable, alphabetical order.
		uasort(
			$sport_data,
			static function( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		$sports = array_values( $sport_data );
		$types  = array_values( $type_data );

		global $wp_locale;

		$month_names    = array();
		$weekdays       = array();
		$weekdays_short = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$month_names[] = $wp_locale->get_month( $i );
		}

		for ( $i = 0; $i <= 6; $i++ ) {
			$weekday          = $wp_locale->get_weekday( $i );
			$weekdays[]       = $weekday;
			$weekdays_short[] = $wp_locale->get_weekday_abbrev( $weekday );
		}

		$payload = array(
			'events' => $events,
			'sports' => $sports,
			'types'  => $types,
			'config' => array(
				'startOfWeek'   => (int) get_option( 'start_of_week', 1 ),
				'today'         => wp_date( 'Y-m-d' ),
				'monthNames'    => $month_names,
				'weekdays'      => $weekdays,
				'weekdaysShort' => $weekdays_short,
				'initialView'   => $initial_view,
				'showWeek'      => $show_week,
				'showDay'       => $show_day,
				'listInitial'   => max( 1, (int) $atts['list_initial'] ),
				'listMore'      => max( 1, (int) $atts['list_more'] ),
			),
			'strings' => array(
				'allSports'      => __( 'All sports', 'leagueflow' ),
				'allTypes'       => __( 'All types', 'leagueflow' ),
				'today'          => __( 'Today', 'leagueflow' ),
				'month'          => __( 'Month', 'leagueflow' ),
				'list'           => __( 'List', 'leagueflow' ),
				'week'           => __( 'Week', 'leagueflow' ),
				'day'            => __( 'Day', 'leagueflow' ),
				'search'         => __( 'Search schedule', 'leagueflow' ),
				'noEventsDay'    => __( 'No events on this day.', 'leagueflow' ),
				'noEventsMonth'  => __( 'No events scheduled in %s.', 'leagueflow' ),
				'noEventsList'   => __( 'No upcoming events match the current filters.', 'leagueflow' ),
				'selectEvent'    => __( 'Select an event to see details.', 'leagueflow' ),
				'jumpToMonth'    => __( 'Jump to %s', 'leagueflow' ),
				'eventsOn'       => __( 'Schedule on %s', 'leagueflow' ),
				'monthSchedule'  => __( 'Schedule in %s', 'leagueflow' ),
				'event'          => __( 'event', 'leagueflow' ),
				'eventsPlural'   => __( 'events', 'leagueflow' ),
				'showMonth'      => __( 'Show full month', 'leagueflow' ),
				'details'        => __( 'Details', 'leagueflow' ),
				'vs'             => __( 'vs', 'leagueflow' ),
				'prev'           => __( 'Previous', 'leagueflow' ),
				'next'           => __( 'Next', 'leagueflow' ),
				'loadMore'       => __( 'Load more events', 'leagueflow' ),
				'registration'   => __( 'Registration required', 'leagueflow' ),
				'register'       => __( 'Register', 'leagueflow' ),
				'cost'           => __( 'Cost', 'leagueflow' ),
				'level'          => __( 'Level', 'leagueflow' ),
				'addGoogle'      => __( 'Add to Google Calendar', 'leagueflow' ),
				'addApple'       => __( 'Add to your Apple Calendar', 'leagueflow' ),
				'downloadIcs'    => __( 'Add to your Apple Calendar', 'leagueflow' ),
				'close'          => __( 'Close', 'leagueflow' ),
				'cancelled'      => __( 'Cancelled', 'leagueflow' ),
				'postponed'      => __( 'Postponed', 'leagueflow' ),
			),
		);

		$this->enqueue_frontend_assets();
		wp_enqueue_script( 'leagueflow-calendar' );

		return $this->render_template(
			'match-calendar.php',
			array(
				'payload'      => $payload,
				'sports'       => $sports,
				'types'        => $types,
				'show_chips'   => '' === $atts['sport'],
				'show_types'   => count( $types ) > 1,
				'items'        => $events,
			)
		);
	}

	/**
	 * Get normalized calendar items for matches and standalone events.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_calendar_items( $filters = array() ) {
		$defaults = array(
			'competition'      => '',
			'season'           => '',
			'sport'            => '',
			'league_level'     => '',
			'team'             => '',
			'include_knockout' => null,
			'include_events'   => true,
			'event_type'       => '',
			'type'             => '',
			'kind'             => '',
			'status'           => '',
			'match_status'     => '',
			'event_status'     => '',
			'source'           => '',
			'search'           => '',
			'start_date'       => '',
			'end_date'         => '',
			'limit'            => -1,
		);

		$filters        = wp_parse_args( $filters, $defaults );
		$source         = sanitize_key( (string) $filters['source'] );
		$include_events = is_bool( $filters['include_events'] ) ? $filters['include_events'] : $this->truthy_shortcode_value( $filters['include_events'] );
		$matches        = array();
		$events         = array();

		if ( 'event' !== $source ) {
			$matches = $this->get_match_items(
				array(
					'competition'      => $filters['competition'],
					'season'           => $filters['season'],
					'sport'            => $filters['sport'],
					'league_level'     => $filters['league_level'],
					'status'           => $filters['match_status'] ? $filters['match_status'] : $filters['status'],
					'limit'            => (int) $filters['limit'],
					'include_knockout' => $filters['include_knockout'],
					'team'             => $filters['team'],
				)
			);
		}

		if ( 'match' !== $source && $include_events && empty( $filters['team'] ) ) {
			$events = $this->get_calendar_event_items(
				array(
					'competition' => $filters['competition'],
					'season'      => $filters['season'],
					'sport'       => $filters['sport'],
					'league_level' => $filters['league_level'],
					'status'      => $filters['event_status'] ? $filters['event_status'] : $filters['status'],
					'event_type'  => $filters['event_type'],
					'limit'       => (int) $filters['limit'],
				)
			);
		}

		$timezone       = wp_timezone();
		$time_format    = (string) get_option( 'time_format', 'g:i a' );
		$calendar_items = array_merge(
			$this->normalize_match_calendar_items( $matches, $timezone, $time_format ),
			$this->normalize_standalone_calendar_items( $events, $timezone, $time_format )
		);

		usort(
			$calendar_items,
			static function( $a, $b ) {
				return (int) $a['startTimestamp'] <=> (int) $b['startTimestamp'];
			}
		);

		return array_values(
			array_filter(
				$calendar_items,
				function( $item ) use ( $filters ) {
					return $this->calendar_item_matches_filters( $item, $filters );
				}
			)
		);
	}

	/**
	 * Check a normalized calendar item against REST/frontend filters.
	 *
	 * @param array<string, mixed> $item Calendar item.
	 * @param array<string, mixed> $filters Filters.
	 * @return bool
	 */
	protected function calendar_item_matches_filters( $item, $filters ) {
		$start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $filters['start_date'] ) ? (string) $filters['start_date'] : '';
		$end_date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $filters['end_date'] ) ? (string) $filters['end_date'] : '';
		$type       = sanitize_key( (string) ( $filters['type'] ? $filters['type'] : $filters['kind'] ) );
		$search     = trim( strtolower( wp_strip_all_tags( (string) $filters['search'] ) ) );

		if ( $start_date && (string) $item['day'] < $start_date ) {
			return false;
		}

		if ( $end_date && (string) $item['day'] > $end_date ) {
			return false;
		}

		if ( $type && $type !== (string) $item['kind'] ) {
			return false;
		}

		if ( $search ) {
			$haystack = strtolower(
				implode(
					' ',
					array(
						$item['title'],
						$item['description'],
						$item['sportLabel'],
						$item['leagueLevelLabel'] ?? '',
						$item['kindLabel'],
						$item['venue'],
						$item['home'],
						$item['away'],
						$item['competition'],
						$item['season'],
					)
				)
			);

			if ( false === strpos( $haystack, $search ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize match items for the calendar frontend.
	 *
	 * @param array<int, array<string, mixed>> $matches Matches.
	 * @param \DateTimeZone                   $timezone Site timezone.
	 * @param string                          $time_format WordPress time format.
	 * @return array<int, array<string, mixed>>
	 */
	protected function normalize_match_calendar_items( $matches, $timezone, $time_format ) {
		$events = array();

		foreach ( $matches as $match ) {
			$datetime = date_create_immutable_from_format( 'Y-m-d H:i', (string) $match['datetime_raw'], $timezone );

			if ( ! $datetime ) {
				continue;
			}

			$end        = $datetime->modify( '+1 hour' );
			$sport_slug = $match['sport_slug'] ? $match['sport_slug'] : 'soccer';
			$sport_label = $this->get_sport_display_label( $sport_slug, $match['sport_label'] );
			$title      = sprintf(
				/* translators: 1: home team, 2: away team */
				__( '%1$s vs %2$s', 'leagueflow' ),
				$match['home_team'],
				$match['away_team']
			);

			$events[] = array(
				'id'                   => 'match-' . $match['id'],
				'postId'               => $match['id'],
				'source'               => 'match',
				'kind'                 => 'match',
				'kindLabel'            => __( 'Match', 'leagueflow' ),
				'title'                => $title,
				'description'          => '',
				'day'                  => $datetime->format( 'Y-m-d' ),
				'start'                => $datetime->format( 'c' ),
				'end'                  => $end->format( 'c' ),
				'time'                 => wp_date( $time_format, $datetime->getTimestamp(), $timezone ),
				'endTime'              => wp_date( $time_format, $end->getTimestamp(), $timezone ),
				'startTimestamp'       => $datetime->getTimestamp(),
				'endTimestamp'         => $end->getTimestamp(),
				'sport'                => $sport_slug,
				'sportLabel'           => $sport_label,
				'leagueLevel'          => $match['league_level_slug'],
				'leagueLevelLabel'     => $match['league_level_label'],
				'home'                 => $match['home_team'],
				'away'                 => $match['away_team'],
				'scoreline'            => $match['scoreline'],
				'status'               => $match['status'] ? $match['status'] : 'scheduled',
				'statusLabel'          => $match['status_label'],
				'venue'                => $match['venue'],
				'round'                => $match['round_label'],
				'competition'          => implode( ', ', (array) $match['competition'] ),
				'season'               => implode( ', ', (array) $match['season'] ),
				'url'                  => $match['permalink'],
				'cost'                 => '',
				'registrationRequired' => false,
				'registrationUrl'      => '',
			);
		}

		return $events;
	}

	/**
	 * Normalize standalone event items for the calendar frontend.
	 *
	 * @param array<int, array<string, mixed>> $items Calendar event items.
	 * @param \DateTimeZone                   $timezone Site timezone.
	 * @param string                          $time_format WordPress time format.
	 * @return array<int, array<string, mixed>>
	 */
	protected function normalize_standalone_calendar_items( $items, $timezone, $time_format ) {
		$events = array();

		foreach ( $items as $item ) {
			$datetime = date_create_immutable_from_format( 'Y-m-d H:i', (string) $item['datetime_raw'], $timezone );

			if ( ! $datetime ) {
				continue;
			}

			$end = ! empty( $item['end_datetime_raw'] ) ? date_create_immutable_from_format( 'Y-m-d H:i', (string) $item['end_datetime_raw'], $timezone ) : false;

			if ( ! $end || $end->getTimestamp() <= $datetime->getTimestamp() ) {
				$end = $datetime->modify( '+1 hour' );
			}

			$sport_slug = $item['sport_slug'] ? $item['sport_slug'] : 'soccer';
			$sport_label = $this->get_sport_display_label( $sport_slug, $item['sport_label'] );

			$events[] = array(
				'id'                   => 'event-' . $item['id'],
				'postId'               => $item['id'],
				'source'               => 'event',
				'kind'                 => $item['event_type'],
				'kindLabel'            => $item['event_type_label'],
				'title'                => $item['title'],
				'description'          => $item['description'],
				'day'                  => $datetime->format( 'Y-m-d' ),
				'start'                => $datetime->format( 'c' ),
				'end'                  => $end->format( 'c' ),
				'time'                 => wp_date( $time_format, $datetime->getTimestamp(), $timezone ),
				'endTime'              => wp_date( $time_format, $end->getTimestamp(), $timezone ),
				'startTimestamp'       => $datetime->getTimestamp(),
				'endTimestamp'         => $end->getTimestamp(),
				'sport'                => $sport_slug,
				'sportLabel'           => $sport_label,
				'leagueLevel'          => $item['league_level_slug'],
				'leagueLevelLabel'     => $item['league_level_label'],
				'home'                 => '',
				'away'                 => '',
				'scoreline'            => '',
				'status'               => $item['status'] ? $item['status'] : 'scheduled',
				'statusLabel'          => $item['status_label'],
				'venue'                => $item['venue'],
				'round'                => '',
				'competition'          => implode( ', ', (array) $item['competition'] ),
				'season'               => implode( ', ', (array) $item['season'] ),
				'url'                  => $item['permalink'],
				'cost'                 => $item['cost'],
				'registrationRequired' => (bool) $item['registration_required'],
				'registrationUrl'      => $item['registration_url'],
			);
		}

		return $events;
	}

	/**
	 * Build sport filter metadata from normalized calendar events.
	 *
	 * @param array<int, array<string, mixed>> $events Events.
	 * @return array<string, array<string, mixed>>
	 */
	protected function build_calendar_sport_data( $events ) {
		$sports = array();

		foreach ( $events as $event ) {
			$sport_slug = ! empty( $event['sport'] ) ? sanitize_key( $event['sport'] ) : 'soccer';

			if ( ! isset( $sports[ $sport_slug ] ) ) {
				$sports[ $sport_slug ] = array(
					'slug'  => $sport_slug,
					'label' => $this->get_sport_display_label( $sport_slug, $event['sportLabel'] ?? '' ),
					'count' => 0,
				);
			}

			$sports[ $sport_slug ]['count']++;
		}

		return $sports;
	}

	/**
	 * Build type filter metadata from normalized calendar events.
	 *
	 * @param array<int, array<string, mixed>> $events Events.
	 * @return array<string, array<string, mixed>>
	 */
	protected function build_calendar_type_data( $events ) {
		$types = array();

		foreach ( $events as $event ) {
			$type = ! empty( $event['kind'] ) ? sanitize_key( $event['kind'] ) : 'other';

			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = array(
					'slug'  => $type,
					'label' => $event['kindLabel'] ?? $this->get_calendar_event_type_label( $type ),
					'count' => 0,
				);
			}

			$types[ $type ]['count']++;
		}

		uksort(
			$types,
			static function( $a, $b ) {
				if ( 'match' === $a ) {
					return -1;
				}

				if ( 'match' === $b ) {
					return 1;
				}

				return strcasecmp( $a, $b );
			}
		);

		return $types;
	}

	/**
	 * Get a public label for a standalone calendar event type.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	protected function get_calendar_event_type_label( $type ) {
		$type = sanitize_key( $type );

		if ( 'match' === $type ) {
			return __( 'Match', 'leagueflow' );
		}

		return $this->calendar_event_type_labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Get league level terms in a stable display order.
	 *
	 * @return array<int, \WP_Term>
	 */
	protected function get_league_level_terms() {
		return get_league_level_terms();
	}

	/**
	 * Parse shortcode and block boolean-ish values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function truthy_shortcode_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( strtolower( (string) $value ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Get the public label for a sport slug, preferring the taxonomy term name.
	 *
	 * @param string $sport_slug Sport slug.
	 * @param string $fallback Fallback label.
	 * @return string
	 */
	protected function get_sport_display_label( $sport_slug, $fallback = '' ) {
		$term = get_term_by( 'slug', $sport_slug, 'lf_sport' );

		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}

		return $fallback ? $fallback : ucwords( str_replace( '-', ' ', $sport_slug ) );
	}

	/**
	 * Render a single match card.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_match_card( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'match' => '',
			),
			$atts,
			'match_card'
		);

		$match_id = resolve_post_id( $atts['match'], 'lf_match' );

		if ( ! $match_id && is_singular( 'lf_match' ) ) {
			$match_id = get_the_ID();
		}

		if ( ! $match_id ) {
			return '<p>' . esc_html__( 'Select a match to display its details.', 'leagueflow' ) . '</p>';
		}

		$match = $this->get_match_item( $match_id );

		if ( empty( $match ) ) {
			return '<p>' . esc_html__( 'Match not found.', 'leagueflow' ) . '</p>';
		}

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'match-card.php',
			array(
				'match' => $match,
			)
		);
	}

	/**
	 * Render the knockout bracket.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_knockout_bracket( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'competition'  => '',
				'season'       => '',
				'sport'        => '',
				'league_level' => '',
			),
			$atts,
			'knockout_bracket'
		);

		$competition_id  = resolve_term_id( $atts['competition'], 'lf_competition' );
		$season_id       = resolve_season_context( $atts['season'] );
		$sport_id        = resolve_term_id( $atts['sport'], 'lf_sport' );
		$league_level_id = resolve_term_id( $atts['league_level'], 'lf_league_level' );
		$rounds          = $this->knockout_service->get_bracket( $competition_id, $season_id, $sport_id, $league_level_id );
		$tree            = $this->knockout_service->get_bracket_tree( $competition_id, $season_id, $sport_id, $league_level_id );

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'knockout-bracket.php',
			array(
				'rounds' => $rounds,
				'tree'   => $tree,
			)
		);
	}

	/**
	 * Render a team single page.
	 *
	 * @param int $team_id Team ID.
	 * @return string
	 */
	public function render_team_single( $team_id ) {
		$team = get_post( $team_id );

		if ( ! $team instanceof \WP_Post ) {
			return '';
		}

		$this->enqueue_frontend_assets();

		return $this->render_template(
			'single-team-content.php',
			array(
				'team'           => $team,
				'team_logo'      => get_post_image( $team_id, 'medium', 'leagueflow-team-header__logo' ),
				'short_name'     => get_post_meta( $team_id, 'lf_short_name', true ),
				'city'           => get_post_meta( $team_id, 'lf_city', true ),
				'coach'          => get_post_meta( $team_id, 'lf_coach', true ),
				'founded_year'   => get_post_meta( $team_id, 'lf_founded_year', true ),
				'sport_label'    => $this->sports_manager->get_post_sport_label( $team_id ),
				'league_level_label' => get_post_league_level_label( $team_id ),
				'players'        => $this->get_roster_items( $team_id ),
				'recent_matches' => $this->get_match_items(
					array(
						'team'  => $team_id,
						'limit' => 8,
					)
				),
			)
		);
	}

	/**
	 * Render the full team profile, defaulting to the queried team.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render_team_page( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'team' => '',
			),
			$atts,
			'team_page'
		);

		$team_id = resolve_post_id( $atts['team'], 'lf_team' );

		if ( ! $team_id && is_singular( 'lf_team' ) ) {
			$team_id = get_the_ID();
		}

		if ( ! $team_id ) {
			return '<p>' . esc_html__( 'Select a team to display its profile.', 'leagueflow' ) . '</p>';
		}

		return $this->render_team_single( $team_id );
	}

	/**
	 * Get team list data.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_team_items( $competition_id = 0, $season_id = 0, $sport_id = 0, $league_level_id = 0 ) {
		$args = array(
			'post_type'      => 'lf_team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$tax_query = array();

		if ( $competition_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_competition',
				'field'    => 'term_id',
				'terms'    => array( $competition_id ),
			);
		}

		if ( $season_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_season',
				'field'    => 'term_id',
				'terms'    => array( $season_id ),
			);
		}

		if ( $sport_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_sport',
				'field'    => 'term_id',
				'terms'    => array( $sport_id ),
			);
		}

		if ( $league_level_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'term_id',
				'terms'    => array( $league_level_id ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$posts = get_posts( $args );

		if ( empty( $posts ) && ( $competition_id || $season_id || $league_level_id ) ) {
			$posts = array();
			$match_items = $this->get_match_items(
				array(
					'competition' => $competition_id,
					'season'      => $season_id,
					'sport'       => $sport_id,
					'league_level' => $league_level_id,
					'limit'       => -1,
				)
			);
			$team_ids = array();

			foreach ( $match_items as $match ) {
				$team_ids[] = $match['home_team_id'];
				$team_ids[] = $match['away_team_id'];
			}

			$team_ids = array_values( array_filter( array_unique( array_map( 'absint', $team_ids ) ) ) );

			foreach ( $team_ids as $team_id ) {
				$team = get_post( $team_id );
				if ( $team instanceof \WP_Post ) {
					$posts[] = $team;
				}
			}
		}

		$teams = array();

		foreach ( $posts as $post ) {
			$teams[] = array(
				'id'           => $post->ID,
				'name'         => $post->post_title,
				'short_name'   => get_post_meta( $post->ID, 'lf_short_name', true ),
				'city'         => get_post_meta( $post->ID, 'lf_city', true ),
				'coach'        => get_post_meta( $post->ID, 'lf_coach', true ),
				'founded_year' => get_post_meta( $post->ID, 'lf_founded_year', true ),
				'logo'         => get_post_image( $post->ID, 'medium', 'leagueflow-team-card__logo' ),
				'description'  => has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 22 ),
				'permalink'    => get_permalink( $post->ID ),
				'sport'        => $this->sports_manager->get_post_sport_label( $post->ID ),
				'league_level' => get_post_league_level_label( $post->ID ),
			);
		}

		return $teams;
	}

	/**
	 * Get roster entries for a team.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_roster_items( $team_id ) {
		$team_id = absint( $team_id );
		$players = get_team_roster_player_posts( $team_id );

		$items = array();

		foreach ( $players as $player ) {
			$detail  = get_player_team_detail( $player->ID, $team_id );
			$items[] = array(
				'id'            => $player->ID,
				'name'          => $player->post_title,
				'jersey_number' => $detail['jersey_number'],
				'position'      => $detail['position'],
				'age'           => get_post_meta( $player->ID, 'lf_age', true ),
				'nationality'   => get_post_meta( $player->ID, 'lf_nationality', true ),
				'is_captain'    => ! empty( $detail['is_captain'] ),
				'photo'         => get_post_image( $player->ID, 'thumbnail', 'leagueflow-player__photo' ),
			);
		}

		return $items;
	}

	/**
	 * Get standalone calendar event data for a set of filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_calendar_event_items( $filters = array() ) {
		$defaults = array(
			'competition'  => 0,
			'season'       => 0,
			'sport'        => 0,
			'league_level' => 0,
			'status'       => '',
			'event_type'   => '',
			'limit'        => 20,
		);

		$filters = wp_parse_args( $filters, $defaults );

		$competition_id  = is_numeric( $filters['competition'] ) ? absint( $filters['competition'] ) : resolve_term_id( $filters['competition'], 'lf_competition' );
		$season_id       = is_numeric( $filters['season'] ) ? absint( $filters['season'] ) : resolve_term_id( $filters['season'], 'lf_season' );
		$sport_id        = is_numeric( $filters['sport'] ) ? absint( $filters['sport'] ) : resolve_term_id( $filters['sport'], 'lf_sport' );
		$league_level_id = is_numeric( $filters['league_level'] ) ? absint( $filters['league_level'] ) : resolve_term_id( $filters['league_level'], 'lf_league_level' );
		$limit           = (int) $filters['limit'];
		$limit           = $limit < 1 ? -1 : $limit;

		$args = array(
			'post_type'      => 'lf_calendar_event',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => 'lf_event_start_datetime',
			'order'          => 'ASC',
		);

		$meta_query = array();
		$tax_query  = array();

		if ( ! empty( $filters['status'] ) ) {
			$meta_query[] = array(
				'key'   => 'lf_event_status',
				'value' => sanitize_key( $filters['status'] ),
			);
		}

		if ( ! empty( $filters['event_type'] ) ) {
			$meta_query[] = array(
				'key'   => 'lf_event_type',
				'value' => sanitize_key( $filters['event_type'] ),
			);
		}

		if ( $competition_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_competition',
				'field'    => 'term_id',
				'terms'    => array( $competition_id ),
			);
		}

		if ( $season_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_season',
				'field'    => 'term_id',
				'terms'    => array( $season_id ),
			);
		}

		if ( $sport_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_sport',
				'field'    => 'term_id',
				'terms'    => array( $sport_id ),
			);
		}

		if ( $league_level_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'term_id',
				'terms'    => array( $league_level_id ),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$events = get_posts( $args );

		return array_values(
			array_filter(
				array_map( array( $this, 'map_calendar_event_item' ), $events )
			)
		);
	}

	/**
	 * Get match data for a set of filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_match_items( $filters = array() ) {
		$defaults = array(
			'competition'      => 0,
			'season'           => 0,
			'sport'            => 0,
			'league_level'     => 0,
			'status'           => '',
			'limit'            => 20,
			'include_knockout' => null,
			'team'             => 0,
		);

		$filters = wp_parse_args( $filters, $defaults );

		$competition_id  = is_numeric( $filters['competition'] ) ? absint( $filters['competition'] ) : resolve_term_id( $filters['competition'], 'lf_competition' );
		$season_id       = is_numeric( $filters['season'] ) ? absint( $filters['season'] ) : resolve_term_id( $filters['season'], 'lf_season' );
		$sport_id        = is_numeric( $filters['sport'] ) ? absint( $filters['sport'] ) : resolve_term_id( $filters['sport'], 'lf_sport' );
		$league_level_id = is_numeric( $filters['league_level'] ) ? absint( $filters['league_level'] ) : resolve_term_id( $filters['league_level'], 'lf_league_level' );
		$team_id         = is_numeric( $filters['team'] ) ? absint( $filters['team'] ) : resolve_post_id( $filters['team'], 'lf_team' );
		$limit           = (int) $filters['limit'];
		$limit           = $limit < 1 ? -1 : $limit;

		$args = array(
			'post_type'      => 'lf_match',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => 'lf_match_datetime',
			'order'          => 'ASC',
		);

		$meta_query = array();
		$tax_query  = array();

		if ( ! empty( $filters['status'] ) ) {
			$meta_query[] = array(
				'key'   => 'lf_status',
				'value' => sanitize_key( $filters['status'] ),
			);
		}

		if ( null !== $filters['include_knockout'] ) {
			if ( $filters['include_knockout'] ) {
				$meta_query[] = array(
					'key'   => 'lf_is_knockout',
					'value' => '1',
				);
			} else {
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => 'lf_is_knockout',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'lf_is_knockout',
						'value' => '0',
					),
				);
			}
		}

		if ( $team_id ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'   => 'lf_home_team_id',
					'value' => $team_id,
				),
				array(
					'key'   => 'lf_away_team_id',
					'value' => $team_id,
				),
			);
		}

		if ( $competition_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_competition',
				'field'    => 'term_id',
				'terms'    => array( $competition_id ),
			);
		}

		if ( $season_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_season',
				'field'    => 'term_id',
				'terms'    => array( $season_id ),
			);
		}

		if ( $sport_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_sport',
				'field'    => 'term_id',
				'terms'    => array( $sport_id ),
			);
		}

		if ( $league_level_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'term_id',
				'terms'    => array( $league_level_id ),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$matches = get_posts( $args );

		return array_values(
			array_filter(
				array_map( array( $this, 'map_match_item' ), $matches )
			)
		);
	}

	/**
	 * Get a single match item.
	 *
	 * @param int $match_id Match ID.
	 * @return array<string, mixed>
	 */
	public function get_match_item( $match_id ) {
		$match = get_post( $match_id );

		if ( ! $match instanceof \WP_Post || 'lf_match' !== $match->post_type ) {
			return array();
		}

		return $this->map_match_item( $match );
	}

	/**
	 * Enqueue shared frontend styles.
	 *
	 * @return void
	 */
	protected function enqueue_frontend_assets() {
		wp_enqueue_style( 'leagueflow-frontend' );
	}

	/**
	 * Map a standalone event post into template data.
	 *
	 * @param \WP_Post $event Event post.
	 * @return array<string, mixed>
	 */
	protected function map_calendar_event_item( $event ) {
		$datetime_raw = (string) get_post_meta( $event->ID, 'lf_event_start_datetime', true );

		if ( '' === $datetime_raw ) {
			return array();
		}

		$sport_slug = $this->sports_manager->get_post_sport_slug( $event->ID );
		$sport      = $this->sports_manager->get_definition( $sport_slug );
		$type       = sanitize_key( (string) get_post_meta( $event->ID, 'lf_event_type', true ) );
		$status     = sanitize_key( (string) get_post_meta( $event->ID, 'lf_event_status', true ) );

		if ( ! $type ) {
			$type = 'drop_in';
		}

		if ( ! $status ) {
			$status = 'scheduled';
		}

		$content = trim( wp_strip_all_tags( $event->post_content ) );

		return array(
			'id'                    => $event->ID,
			'title'                 => $event->post_title,
			'permalink'             => get_permalink( $event->ID ),
			'description'           => $content,
			'excerpt'               => has_excerpt( $event->ID ) ? get_the_excerpt( $event->ID ) : wp_trim_words( $content, 30 ),
			'datetime_raw'          => $datetime_raw,
			'end_datetime_raw'      => (string) get_post_meta( $event->ID, 'lf_event_end_datetime', true ),
			'venue'                 => get_post_meta( $event->ID, 'lf_event_venue', true ),
			'event_type'            => $type,
			'event_type_label'      => $this->get_calendar_event_type_label( $type ),
			'status'                => $status,
			'status_label'          => ucfirst( $status ),
			'cost'                  => get_post_meta( $event->ID, 'lf_event_cost', true ),
			'registration_required' => (bool) get_post_meta( $event->ID, 'lf_event_registration_required', true ),
			'registration_url'      => get_post_meta( $event->ID, 'lf_event_registration_url', true ),
			'sport_slug'            => $sport_slug,
			'sport_label'           => $sport['label'],
			'league_level_slug'     => get_post_league_level_slug( $event->ID ),
			'league_level_label'    => get_post_league_level_label( $event->ID ),
			'competition'           => wp_get_post_terms( $event->ID, 'lf_competition', array( 'fields' => 'names' ) ),
			'season'                => wp_get_post_terms( $event->ID, 'lf_season', array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Map a match post into template data.
	 *
	 * @param \WP_Post $match Match post.
	 * @return array<string, mixed>
	 */
	protected function map_match_item( $match ) {
		$home_team_id = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
		$away_team_id = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );
		$datetime_raw = (string) get_post_meta( $match->ID, 'lf_match_datetime', true );
		$home_score   = get_post_meta( $match->ID, 'lf_home_score', true );
		$away_score   = get_post_meta( $match->ID, 'lf_away_score', true );
		$status       = (string) get_post_meta( $match->ID, 'lf_status', true );
		$sport_slug   = $this->sports_manager->get_post_sport_slug( $match->ID );
		$sport        = $this->sports_manager->get_definition( $sport_slug );

		if ( ! $home_team_id && ! $away_team_id ) {
			return array();
		}

		return array(
			'id'             => $match->ID,
			'title'          => $match->post_title,
			'permalink'      => get_permalink( $match->ID ),
			'home_team_id'   => $home_team_id,
			'away_team_id'   => $away_team_id,
			'home_team'      => $home_team_id ? get_the_title( $home_team_id ) : __( 'TBD', 'leagueflow' ),
			'away_team'      => $away_team_id ? get_the_title( $away_team_id ) : __( 'TBD', 'leagueflow' ),
			'home_logo'      => $home_team_id ? get_post_image( $home_team_id, 'thumbnail', 'leagueflow-match__logo' ) : '',
			'away_logo'      => $away_team_id ? get_post_image( $away_team_id, 'thumbnail', 'leagueflow-match__logo' ) : '',
			'home_score'     => $home_score,
			'away_score'     => $away_score,
			'scoreline'      => has_score( $home_score ) && has_score( $away_score ) ? score_to_int( $home_score ) . ' - ' . score_to_int( $away_score ) : '',
			'status'         => $status,
			'status_label'   => $status ? ucfirst( $status ) : __( 'Scheduled', 'leagueflow' ),
			'datetime'       => format_match_datetime( $datetime_raw ),
			'datetime_raw'   => $datetime_raw,
			'venue'          => get_post_meta( $match->ID, 'lf_venue', true ),
			'is_knockout'    => (bool) get_post_meta( $match->ID, 'lf_is_knockout', true ),
			'round_label'    => get_post_meta( $match->ID, 'lf_round_label', true ),
			'sport_slug'     => $sport_slug,
			'sport_label'    => $sport['label'],
			'score_label'    => $sport['score_label'],
			'league_level_slug' => get_post_league_level_slug( $match->ID ),
			'league_level_label' => get_post_league_level_label( $match->ID ),
			'sport_fields'   => $this->get_populated_match_fields( $match->ID, $sport_slug ),
			'competition'    => wp_get_post_terms( $match->ID, 'lf_competition', array( 'fields' => 'names' ) ),
			'season'         => wp_get_post_terms( $match->ID, 'lf_season', array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Resolve the sport slug from explicit or context filters.
	 *
	 * @param int $sport_id Sport term ID.
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @return string
	 */
	protected function resolve_context_sport_slug( $sport_id, $competition_id, $season_id ) {
		if ( $sport_id ) {
			$sport = get_term( $sport_id, 'lf_sport' );
			return ( $sport && ! is_wp_error( $sport ) ) ? $sport->slug : 'soccer';
		}

		if ( $competition_id ) {
			$sport_slug = get_term_meta( $competition_id, 'lf_sport_slug', true );
			if ( $sport_slug ) {
				return sanitize_key( $sport_slug );
			}
		}

		if ( $season_id ) {
			$sport_slug = get_term_meta( $season_id, 'lf_sport_slug', true );
			if ( $sport_slug ) {
				return sanitize_key( $sport_slug );
			}
		}

		return 'soccer';
	}

	/**
	 * Get populated sport fields for a match.
	 *
	 * @param int    $match_id Match ID.
	 * @param string $sport_slug Sport slug.
	 * @return array<int, array<string, string>>
	 */
	protected function get_populated_match_fields( $match_id, $sport_slug ) {
		$fields   = array();
		$raw_meta = $this->sports_manager->get_match_fields( $sport_slug );

		foreach ( $raw_meta as $field ) {
			$value = (string) get_post_meta( $match_id, $field['key'], true );

			if ( '' === $value ) {
				continue;
			}

			$fields[] = array(
				'label' => $field['label'],
				'value' => $value,
			);
		}

		return $fields;
	}

	/**
	 * Render a PHP template file.
	 *
	 * @param string               $template Template filename.
	 * @param array<string, mixed> $vars Template variables.
	 * @return string
	 */
	protected function render_template( $template, $vars = array() ) {
		$path = LEAGUEFLOW_PATH . 'templates/' . $template;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		ob_start();
		extract( $vars, EXTR_SKIP );
		include $path;
		return (string) ob_get_clean();
	}
}
