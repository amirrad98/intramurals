<?php
/**
 * REST API routes.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST controller.
 */
class Rest_Controller {

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
	 * Constructor.
	 *
	 * @param Standings_Service $standings_service Standings.
	 * @param Knockout_Service  $knockout_service Knockout.
	 * @param Renderer          $renderer Renderer.
	 */
	public function __construct( Standings_Service $standings_service, Knockout_Service $knockout_service, Renderer $renderer ) {
		$this->standings_service = $standings_service;
		$this->knockout_service  = $knockout_service;
		$this->renderer          = $renderer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register public endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'leagueflow/v1',
			'/standings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_standings' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/matches',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_matches' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/teams',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_teams' ),
				'permission_callback' => array( $this, 'can_read_teams' ),
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/bracket',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bracket' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'match' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_calendar_events' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/calendar/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_calendar_events' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_calendar_event' ),
					'permission_callback' => array( $this, 'can_edit_events' ),
				),
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/events/create-calendar-event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_calendar_event' ),
				'permission_callback' => array( $this, 'can_edit_events' ),
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/sports',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sports' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/competitions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_competitions' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/league-levels',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_league_levels' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/seasons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_seasons' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leagueflow/v1',
			'/event-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event_types' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Get standings response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_standings( WP_REST_Request $request ) {
		$competition_id  = resolve_term_id( $request->get_param( 'competition' ), 'lf_competition' );
		$season_id       = resolve_term_id( $request->get_param( 'season' ), 'lf_season' );
		$sport_id        = resolve_term_id( $request->get_param( 'sport' ), 'lf_sport' );
		$league_level_id = resolve_term_id( $request->get_param( 'league_level' ), 'lf_league_level' );

		return rest_ensure_response( $this->standings_service->get_rows( $competition_id, $season_id, $sport_id, $league_level_id ) );
	}

	/**
	 * Get match response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_matches( WP_REST_Request $request ) {
		return rest_ensure_response(
			$this->renderer->get_match_items(
				array(
					'competition'      => $request->get_param( 'competition' ),
					'season'           => $request->get_param( 'season' ),
					'sport'            => $request->get_param( 'sport' ),
					'league_level'     => $request->get_param( 'league_level' ),
					'status'           => $request->get_param( 'status' ),
					'limit'            => (int) $request->get_param( 'limit' ),
					'include_knockout' => null !== $request->get_param( 'include_knockout' ) ? rest_sanitize_boolean( $request->get_param( 'include_knockout' ) ) : null,
					'team'             => $request->get_param( 'team' ),
				)
			)
		);
	}

	/**
	 * Get teams response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_teams( WP_REST_Request $request ) {
		$competition_id  = resolve_term_id( $request->get_param( 'competition' ), 'lf_competition' );
		$season_id       = resolve_term_id( $request->get_param( 'season' ), 'lf_season' );
		$sport_id        = resolve_term_id( $request->get_param( 'sport' ), 'lf_sport' );
		$league_level_id = resolve_term_id( $request->get_param( 'league_level' ), 'lf_league_level' );

		return rest_ensure_response( $this->renderer->get_team_items( $competition_id, $season_id, $sport_id, $league_level_id ) );
	}

	/**
	 * Check whether the current visitor can read team directory data.
	 *
	 * @return bool
	 */
	public function can_read_teams() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get bracket response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_bracket( WP_REST_Request $request ) {
		$competition_id  = resolve_term_id( $request->get_param( 'competition' ), 'lf_competition' );
		$season_id       = resolve_term_id( $request->get_param( 'season' ), 'lf_season' );
		$sport_id        = resolve_term_id( $request->get_param( 'sport' ), 'lf_sport' );
		$league_level_id = resolve_term_id( $request->get_param( 'league_level' ), 'lf_league_level' );

		return rest_ensure_response( $this->knockout_service->get_bracket( $competition_id, $season_id, $sport_id, $league_level_id ) );
	}

	/**
	 * Get aggregate availability (RSVP) counts for a match.
	 *
	 * Returns counts only (no player identities). When the requester is a
	 * logged-in linked player, their own status is included.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_availability( WP_REST_Request $request ) {
		$match_id = absint( $request->get_param( 'match' ) );

		if ( ! $match_id || 'lf_match' !== get_post_type( $match_id ) ) {
			return rest_ensure_response(
				new WP_Error(
					'leagueflow_invalid_match',
					__( 'A valid match is required.', 'leagueflow' ),
					array( 'status' => 404 )
				)
			);
		}

		$response = array(
			'match_id' => $match_id,
			'counts'   => Availability::counts( $match_id ),
		);

		$user_id = get_current_user_id();

		if ( $user_id ) {
			$player_ids = get_posts(
				array(
					'post_type'      => 'lf_player',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => 'lf_user_id',
					'meta_value'     => $user_id,
				)
			);

			if ( ! empty( $player_ids ) ) {
				$response['viewer_status'] = Availability::get_status( (int) $player_ids[0], $match_id );
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get the combined match/drop-in calendar feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_calendar_events( WP_REST_Request $request ) {
		$range    = $this->get_date_range_from_request( $request );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$type     = $request->get_param( 'type' );

		if ( null === $type ) {
			$type = $request->get_param( 'kind' );
		}

		if ( null === $type ) {
			$type = $request->get_param( 'event_type' );
		}

		if ( $per_page > 0 ) {
			$per_page = min( 500, $per_page );
		}

		$items = $this->renderer->get_calendar_items(
			array(
				'competition'      => $request->get_param( 'competition' ),
				'season'           => $request->get_param( 'season' ),
				'sport'            => $request->get_param( 'sport' ),
				'league_level'     => $request->get_param( 'league_level' ),
				'team'             => $request->get_param( 'team' ),
				'include_knockout' => null !== $request->get_param( 'include_knockout' ) ? rest_sanitize_boolean( $request->get_param( 'include_knockout' ) ) : null,
				'include_events'   => null !== $request->get_param( 'include_events' ) ? rest_sanitize_boolean( $request->get_param( 'include_events' ) ) : true,
				'event_type'       => $request->get_param( 'event_type' ),
				'type'             => $type,
				'kind'             => $request->get_param( 'kind' ),
				'status'           => $request->get_param( 'status' ),
				'match_status'     => $request->get_param( 'match_status' ),
				'event_status'     => $request->get_param( 'event_status' ),
				'source'           => $request->get_param( 'source' ),
				'search'           => $request->get_param( 'search' ),
				'start_date'       => $range['start_date'],
				'end_date'         => $range['end_date'],
				'limit'            => -1,
			)
		);

		$total       = count( $items );
		$offset      = $per_page > 0 ? ( $page - 1 ) * $per_page : 0;
		$paged_items = $per_page > 0 ? array_slice( $items, $offset, $per_page ) : $items;
		$pages       = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$colors      = $this->get_calendar_sport_colors();
		$events      = array();
		$metadata    = array();

		foreach ( $paged_items as $item ) {
			$event                 = $this->format_calendar_api_event( $item, $colors );
			$events[]              = $event;
			$metadata[ $event['id'] ] = $this->build_calendar_event_metadata( $event );
		}

		$filters = $this->build_calendar_filter_metadata( $items, $colors );

		return rest_ensure_response(
			array(
				'events'        => $events,
				'eventMetadata' => $metadata,
				'sports'        => $filters['sports'],
				'leagueLevels'  => $filters['leagueLevels'],
				'eventTypes'    => $filters['eventTypes'],
				'types'         => $filters['eventTypes'],
				'total'         => $total,
				'pages'         => $pages,
				'performance'   => array(
					'server_processed' => true,
					'cache_hit'        => false,
				),
				'pagination'    => array(
					'hasMore'     => $per_page > 0 ? $page < $pages : false,
					'nextPage'    => ( $per_page > 0 && $page < $pages ) ? $page + 1 : null,
					'currentPage' => $page,
					'perPage'     => $per_page > 0 ? $per_page : $total,
					'view'        => sanitize_key( (string) $request->get_param( 'view' ) ),
					'loadedRange' => array(
						'start' => $range['start_date'],
						'end'   => $range['end_date'],
					),
				),
			)
		);
	}

	/**
	 * Get sports taxonomy terms.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_sports() {
		return rest_ensure_response( $this->get_terms_response( 'lf_sport', 'sports' ) );
	}

	/**
	 * Get competitions taxonomy terms.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_competitions() {
		return rest_ensure_response( $this->get_terms_response( 'lf_competition', 'competitions' ) );
	}

	/**
	 * Get league level taxonomy terms.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_league_levels() {
		ensure_default_league_levels();

		return rest_ensure_response( $this->get_terms_response( 'lf_league_level', 'leagueLevels' ) );
	}

	/**
	 * Get seasons taxonomy terms.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_seasons() {
		return rest_ensure_response( $this->get_terms_response( 'lf_season', 'seasons' ) );
	}

	/**
	 * Get standalone event type options.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_event_types() {
		$types = array();

		foreach ( $this->get_event_type_options() as $slug => $label ) {
			$types[] = array(
				'slug'  => $slug,
				'name'  => $label,
				'label' => $label,
			);
		}

		return rest_ensure_response(
			array(
				'eventTypes' => $types,
				'total'      => count( $types ),
			)
		);
	}

	/**
	 * Create a standalone calendar event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_calendar_event( WP_REST_Request $request ) {
		$data = $request->get_param( 'event_data' );

		if ( ! is_array( $data ) ) {
			$data = $request->get_json_params();
		}

		if ( isset( $data['event_data'] ) && is_array( $data['event_data'] ) ) {
			$data = $data['event_data'];
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$start = $this->resolve_event_datetime( $data, 'start' );
		$end   = $this->resolve_event_datetime( $data, 'end' );

		if ( '' === $title ) {
			return new WP_Error( 'leagueflow_missing_title', __( 'Event title is required.', 'leagueflow' ), array( 'status' => 400 ) );
		}

		if ( '' === $start ) {
			return new WP_Error( 'leagueflow_missing_start_datetime', __( 'Event start date/time is required.', 'leagueflow' ), array( 'status' => 400 ) );
		}

		$post_status = isset( $data['post_status'] ) && is_scalar( $data['post_status'] ) ? sanitize_key( (string) $data['post_status'] ) : 'publish';

		if ( ! in_array( $post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
			$post_status = 'publish';
		}

		$description = isset( $data['description'] ) && is_scalar( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '';
		$excerpt     = isset( $data['excerpt'] ) && is_scalar( $data['excerpt'] ) ? sanitize_textarea_field( (string) $data['excerpt'] ) : '';
		$venue       = '';
		$type_value  = 'drop_in';
		$status      = 'scheduled';
		$cost        = '';
		$register    = false;
		$register_url = '';

		if ( isset( $data['venue'] ) && is_scalar( $data['venue'] ) ) {
			$venue = sanitize_text_field( (string) $data['venue'] );
		} elseif ( isset( $data['location'] ) && is_scalar( $data['location'] ) ) {
			$venue = sanitize_text_field( (string) $data['location'] );
		}

		if ( isset( $data['event_type'] ) && is_scalar( $data['event_type'] ) ) {
			$type_value = (string) $data['event_type'];
		} elseif ( isset( $data['type'] ) && is_scalar( $data['type'] ) ) {
			$type_value = (string) $data['type'];
		}

		if ( isset( $data['status'] ) && is_scalar( $data['status'] ) ) {
			$status = sanitize_key( (string) $data['status'] );
		} elseif ( isset( $data['event_status'] ) && is_scalar( $data['event_status'] ) ) {
			$status = sanitize_key( (string) $data['event_status'] );
		}

		if ( isset( $data['cost'] ) && is_scalar( $data['cost'] ) ) {
			$cost = sanitize_text_field( (string) $data['cost'] );
		}

		if ( isset( $data['registration_required'] ) ) {
			$register = rest_sanitize_boolean( $data['registration_required'] );
		} elseif ( isset( $data['registrationRequired'] ) ) {
			$register = rest_sanitize_boolean( $data['registrationRequired'] );
		}

		foreach ( array( 'registration_url', 'registration_link', 'website' ) as $url_key ) {
			if ( isset( $data[ $url_key ] ) && is_scalar( $data[ $url_key ] ) ) {
				$register_url = esc_url_raw( (string) $data[ $url_key ] );
				break;
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'lf_calendar_event',
				'post_status'  => $post_status,
				'post_title'   => $title,
				'post_content' => $description,
				'post_excerpt' => $excerpt,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, 'lf_event_start_datetime', $start );
		update_post_meta( $post_id, 'lf_event_end_datetime', $end );
		update_post_meta( $post_id, 'lf_event_venue', $venue );
		update_post_meta( $post_id, 'lf_event_type', $this->sanitize_event_type( $type_value ) );
		update_post_meta( $post_id, 'lf_event_status', $status ? $status : 'scheduled' );
		update_post_meta( $post_id, 'lf_event_cost', $cost );
		update_post_meta( $post_id, 'lf_event_registration_required', $register ? '1' : '0' );
		update_post_meta( $post_id, 'lf_event_registration_url', $register_url );

		$this->assign_calendar_event_terms( $post_id, $data );

		$created = array_values(
			array_filter(
				$this->renderer->get_calendar_items(
					array(
						'source'         => 'event',
						'include_events' => true,
						'limit'          => -1,
					)
				),
				static function( $item ) use ( $post_id ) {
					return (int) $item['postId'] === (int) $post_id;
				}
			)
		);

		$response = rest_ensure_response(
			array(
				'created' => true,
				'event'   => isset( $created[0] ) ? $this->format_calendar_api_event( $created[0], $this->get_calendar_sport_colors() ) : array( 'postId' => $post_id ),
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Check whether the request can mutate calendar events.
	 *
	 * @return bool
	 */
	public function can_edit_events() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Resolve date range params, including old calendar view/date behavior.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{start_date:string,end_date:string}
	 */
	protected function get_date_range_from_request( WP_REST_Request $request ) {
		$start = $this->sanitize_date_param( $request->get_param( 'start_date' ) );
		$end   = $this->sanitize_date_param( $request->get_param( 'end_date' ) );

		if ( $start || $end ) {
			return array(
				'start_date' => $start,
				'end_date'   => $end,
			);
		}

		$view = sanitize_key( (string) $request->get_param( 'view' ) );
		$date = $this->sanitize_date_param( $request->get_param( 'date' ) );

		if ( ! $date || ! in_array( $view, array( 'month', 'week', 'day' ), true ) ) {
			return array(
				'start_date' => '',
				'end_date'   => '',
			);
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d', $date, wp_timezone() );

		if ( ! $datetime ) {
			return array(
				'start_date' => '',
				'end_date'   => '',
			);
		}

		if ( 'day' === $view ) {
			return array(
				'start_date' => $datetime->format( 'Y-m-d' ),
				'end_date'   => $datetime->format( 'Y-m-d' ),
			);
		}

		if ( 'week' === $view ) {
			$start_of_week = (int) get_option( 'start_of_week', 1 );
			$offset        = ( (int) $datetime->format( 'w' ) - $start_of_week + 7 ) % 7;
			$week_start    = $datetime->modify( '-' . $offset . ' days' );

			return array(
				'start_date' => $week_start->format( 'Y-m-d' ),
				'end_date'   => $week_start->modify( '+6 days' )->format( 'Y-m-d' ),
			);
		}

		return array(
			'start_date' => $datetime->modify( 'first day of this month' )->format( 'Y-m-d' ),
			'end_date'   => $datetime->modify( 'last day of this month' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * Format a normalized calendar item for the API.
	 *
	 * @param array<string, mixed>  $event Normalized event.
	 * @param array<string, string> $colors Sport colors.
	 * @return array<string, mixed>
	 */
	protected function format_calendar_api_event( $event, $colors ) {
		$sport = sanitize_key( (string) $event['sport'] );
		$color = $colors[ $sport ] ?? '#2e7d4f';

		$event['startDate'] = $event['start'];
		$event['endDate']   = $event['end'];
		$event['date']      = $event['day'];
		$event['category']  = $sport;
		$event['color']     = $color;
		$event['location']  = $event['venue'];
		$event['permalink'] = $event['url'];

		return $event;
	}

	/**
	 * Build event metadata keyed by event id.
	 *
	 * @param array<string, mixed> $event API event.
	 * @return array<string, mixed>
	 */
	protected function build_calendar_event_metadata( $event ) {
		return array(
			'location'             => $event['venue'],
			'organization'         => $event['sportLabel'],
			'sport'                => $event['sport'],
			'sportLabel'           => $event['sportLabel'],
			'leagueLevel'          => $event['leagueLevel'] ?? '',
			'leagueLevelLabel'     => $event['leagueLevelLabel'] ?? '',
			'source'               => $event['source'],
			'kind'                 => $event['kind'],
			'kindLabel'            => $event['kindLabel'],
			'status'               => $event['status'],
			'statusLabel'          => $event['statusLabel'],
			'competition'          => $event['competition'],
			'season'               => $event['season'],
			'cost'                 => $event['cost'],
			'registrationRequired' => (bool) $event['registrationRequired'],
			'website'              => $event['registrationUrl'] ? $event['registrationUrl'] : $event['url'],
			'permalink'            => $event['url'],
			'description'          => $event['description'],
		);
	}

	/**
	 * Build filter metadata from calendar events.
	 *
	 * @param array<int, array<string, mixed>> $events Events.
	 * @param array<string, string>            $colors Sport colors.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	protected function build_calendar_filter_metadata( $events, $colors ) {
		$sports = array();
		$levels = array();
		$types  = array();

		foreach ( $events as $event ) {
			$sport = sanitize_key( (string) $event['sport'] );
			$level = sanitize_key( (string) ( $event['leagueLevel'] ?? '' ) );
			$type  = sanitize_key( (string) $event['kind'] );

			if ( ! isset( $sports[ $sport ] ) ) {
				$sports[ $sport ] = array(
					'slug'  => $sport,
					'name'  => $event['sportLabel'],
					'label' => $event['sportLabel'],
					'count' => 0,
					'color' => $colors[ $sport ] ?? '#2e7d4f',
				);
			}

			if ( $level && ! isset( $levels[ $level ] ) ) {
				$levels[ $level ] = array(
					'slug'  => $level,
					'name'  => $event['leagueLevelLabel'] ?? ucwords( str_replace( '-', ' ', $level ) ),
					'label' => $event['leagueLevelLabel'] ?? ucwords( str_replace( '-', ' ', $level ) ),
					'count' => 0,
				);
			}

			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = array(
					'slug'  => $type,
					'name'  => $event['kindLabel'],
					'label' => $event['kindLabel'],
					'count' => 0,
				);
			}

			$sports[ $sport ]['count']++;
			if ( $level ) {
				$levels[ $level ]['count']++;
			}
			$types[ $type ]['count']++;
		}

		uasort(
			$sports,
			static function( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		uasort(
			$levels,
			static function( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		uasort(
			$types,
			static function( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return array(
			'sports'       => array_values( $sports ),
			'leagueLevels' => array_values( $levels ),
			'eventTypes'   => array_values( $types ),
		);
	}

	/**
	 * Get taxonomy terms in a REST-friendly envelope.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $key Response key.
	 * @return array<string, mixed>
	 */
	protected function get_terms_response( $taxonomy, $key ) {
		$terms = 'lf_league_level' === $taxonomy
			? get_league_level_terms()
			: get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		return array(
			$key     => array_map( array( $this, 'format_term_response' ), $terms ),
			'total'  => count( $terms ),
		);
	}

	/**
	 * Format a taxonomy term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	protected function format_term_response( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'label'       => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
		);
	}

	/**
	 * Get sport color palette.
	 *
	 * @return array<string, string>
	 */
	protected function get_calendar_sport_colors() {
		return apply_filters(
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
	}

	/**
	 * Get standalone event type labels.
	 *
	 * @return array<string, string>
	 */
	protected function get_event_type_options() {
		return array(
			'drop_in'    => __( 'Drop-in', 'leagueflow' ),
			'practice'   => __( 'Practice', 'leagueflow' ),
			'clinic'     => __( 'Clinic', 'leagueflow' ),
			'tournament' => __( 'Tournament', 'leagueflow' ),
			'meeting'    => __( 'Meeting', 'leagueflow' ),
			'other'      => __( 'Other', 'leagueflow' ),
		);
	}

	/**
	 * Sanitize event type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	protected function sanitize_event_type( $type ) {
		$type = sanitize_key( $type );
		$options = $this->get_event_type_options();

		return isset( $options[ $type ] ) ? $type : 'other';
	}

	/**
	 * Sanitize a date param.
	 *
	 * @param mixed $date Date value.
	 * @return string
	 */
	protected function sanitize_date_param( $date ) {
		$date = is_scalar( $date ) ? (string) $date : '';

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Resolve a start/end datetime from old and new API field names.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @param string               $which start|end.
	 * @return string
	 */
	protected function resolve_event_datetime( $data, $which ) {
		$keys = 'start' === $which
			? array( 'start_datetime', 'startDate', 'start' )
			: array( 'end_datetime', 'endDate', 'end' );

		foreach ( $keys as $key ) {
			if ( empty( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}

			$timestamp = strtotime( (string) $data[ $key ] );

			if ( false !== $timestamp ) {
				return wp_date( 'Y-m-d H:i', $timestamp, wp_timezone() );
			}
		}

		$date = $this->sanitize_date_param( $data['date'] ?? $data['event_date'] ?? '' );
		$time = '';

		if ( 'start' === $which ) {
			$time = isset( $data['start_time'] ) && is_scalar( $data['start_time'] ) ? (string) $data['start_time'] : '';
		} else {
			$time = isset( $data['end_time'] ) && is_scalar( $data['end_time'] ) ? (string) $data['end_time'] : '';
		}

		if ( ! $date || ! $time ) {
			return '';
		}

		$timestamp = strtotime( $date . ' ' . $time );

		return false !== $timestamp ? wp_date( 'Y-m-d H:i', $timestamp, wp_timezone() ) : '';
	}

	/**
	 * Assign sport, competition, and season terms to a new calendar event.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data Event data.
	 * @return void
	 */
	protected function assign_calendar_event_terms( $post_id, $data ) {
		$map = array(
			'lf_sport'        => array( 'sport', 'category' ),
			'lf_league_level' => array( 'league_level', 'leagueLevel', 'level' ),
			'lf_competition'  => array( 'competition' ),
			'lf_season'       => array( 'season' ),
		);

		foreach ( $map as $taxonomy => $keys ) {
			$values = array();

			foreach ( $keys as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$values = is_array( $data[ $key ] ) ? $data[ $key ] : explode( ',', (string) $data[ $key ] );
					break;
				}
			}

			$term_ids = array();

			foreach ( $values as $value ) {
				$term_id = $this->resolve_or_create_term( $value, $taxonomy );

				if ( $term_id ) {
					$term_ids[] = $term_id;
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}
	}

	/**
	 * Resolve an existing term or create one from an import value.
	 *
	 * @param mixed  $value Term ID, slug, or name.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	protected function resolve_or_create_term( $value, $taxonomy ) {
		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ), $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}

		$name = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $name ) {
			return 0;
		}

		$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );

		if ( ! $term ) {
			$term = get_term_by( 'name', $name, $taxonomy );
		}

		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy, array( 'slug' => sanitize_title( $name ) ) );

		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}
}
