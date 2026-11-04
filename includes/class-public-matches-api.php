<?php
/**
 * Versioned, public match feeds for third-party consumers.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/** These collections always expose public data, even in an administrator session. */
class Public_Matches_API {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		foreach ( array( 'match-events', 'match-results' ) as $route ) {
			register_rest_route( 'leagueflow/v1', '/' . $route . '/?', array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_collection' ),
				'args'                => $this->collection_args(),
			) );
		}
	}

	/** Parameters are discoverable through WordPress's REST index and OPTIONS. */
	protected function collection_args() {
		$args = array(
			'per_page' => array( 'description' => 'Records per page (default 20, maximum 100).', 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			'limit'    => array( 'description' => 'Alias of per_page; conflicting values are rejected.', 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			'page'     => array( 'description' => 'Page number.', 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 10000 ),
			'view'     => array( 'description' => 'upcoming, past, or all. Explicit date ranges default to all.', 'type' => 'string', 'enum' => array( 'upcoming', 'past', 'all' ) ),
			'order'    => array( 'description' => 'Kickoff order; defaults to desc for past/results, asc otherwise. ID breaks ties.', 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
			'status'   => array( 'description' => 'Match status. Results always require finished.', 'type' => 'string', 'enum' => array_keys( Match_Status::statuses() ) ),
			'include_knockout' => array( 'description' => 'true selects knockout matches; false selects league matches; omitted includes both.', 'type' => 'boolean' ),
		);
		foreach ( array( 'date', 'start_date', 'end_date' ) as $key ) {
			$args[ $key ] = array( 'description' => 'Site-local YYYY-MM-DD. Bounds are inclusive; date anchors past/upcoming.', 'type' => 'string', 'validate_callback' => array( $this, 'validate_date' ) );
		}
		foreach ( array( 'sport', 'league_level', 'competition', 'season', 'team' ) as $key ) {
			$args[ $key ] = array( 'description' => 'Existing ID or slug. Unknown filters return 400.', 'type' => array( 'string', 'integer' ) );
		}
		return $args;
	}

	public function validate_date( $value ) {
		return is_string( $value ) && '' !== public_collection_date( $value );
	}

	protected function invalid( $message ) {
		return new \WP_Error( 'leagueflow_invalid_filter', $message, array( 'status' => 400 ) );
	}

	/** Resolve every filter before querying; an unknown slug must never broaden a feed. */
	protected function filters( $request, $results ) {
		$per_page = $request->get_param( 'per_page' );
		$limit    = $request->get_param( 'limit' );
		if ( null !== $per_page && null !== $limit && (int) $per_page !== (int) $limit ) {
			return $this->invalid( 'Use per_page or limit, or give both the same value.' );
		}
		$filters = array( 'per_page' => (int) ( $per_page ?? $limit ?? 20 ), 'page' => (int) $request['page'] );
		$filters['view'] = $request['view'] ?? ( $request['start_date'] || $request['end_date'] ? 'all' : ( $results ? 'past' : 'upcoming' ) );
		$filters['order'] = $request['order'] ?? ( $results || 'past' === $filters['view'] ? 'desc' : 'asc' );
		$start = $request['start_date'];
		$end   = $request['end_date'];
		if ( $start && $end && $start > $end ) { return $this->invalid( 'start_date must be on or before end_date.' ); }
		if ( $request['date'] && 'all' === $filters['view'] ) { return $this->invalid( 'date anchors past/upcoming; use start_date and end_date with view=all.' ); }
		$filters['after']  = $start ? $start . ' 00:00:00' : null;
		$filters['before'] = $end ? $end . ' 23:59:59' : null;
		if ( 'all' !== $filters['view'] ) {
			$past   = 'past' === $filters['view'];
			$filters['date'] = $request['date'] ?: current_datetime()->format( 'Y-m-d' );
			$anchor = $filters['date'] . ( $past ? ' 23:59:59' : ' 00:00:00' );
			$key = $past ? 'before' : 'after';
			$filters[ $key ] = $filters[ $key ] ? ( $past ? min( $filters[ $key ], $anchor ) : max( $filters[ $key ], $anchor ) ) : $anchor;
		}
		if ( $filters['after'] && $filters['before'] && $filters['after'] > $filters['before'] ) { return $this->invalid( 'The date range does not intersect the selected view.' ); }
		if ( $results && $request['status'] && 'finished' !== $request['status'] ) { return $this->invalid( 'The results feed only contains finished matches.' ); }
		$filters['status'] = $results ? 'finished' : $request['status'];
		$filters['include_knockout'] = $request->get_param( 'include_knockout' );
		$filters['tax_query'] = array();
		foreach ( array( 'sport' => 'lf_sport', 'league_level' => 'lf_league_level', 'competition' => 'lf_competition', 'season' => 'lf_season' ) as $key => $taxonomy ) {
			$value = $request[ $key ];
			if ( null === $value ) { continue; }
			$term = ctype_digit( (string) $value ) ? get_term( (int) $value, $taxonomy ) : get_term_by( 'slug', (string) $value, $taxonomy );
			if ( ! $term instanceof \WP_Term ) { return $this->invalid( 'Unknown ' . $key . ' filter.' ); }
			$filters['tax_query'][] = array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array( $term->term_id ) );
		}
		$filters['team'] = 0;
		if ( null !== $request['team'] ) {
			$team = ctype_digit( (string) $request['team'] ) ? get_post( (int) $request['team'] ) : get_page_by_path( (string) $request['team'], OBJECT, 'lf_team' );
			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || 'publish' !== $team->post_status || '' !== $team->post_password ) {
				return $this->invalid( 'Unknown public team filter.' );
			}
			$filters['team'] = (int) $team->ID;
		}
		return $filters;
	}

	/** Apply visibility, date, status and taxonomy scopes before LIMIT and COUNT. */
	protected function query( $filters ) {
		global $wpdb;
		$query = new \WP_Query();
		$args = array( 'post_type' => 'lf_match', 'post_status' => 'publish', 'has_password' => false,
			'posts_per_page' => $filters['per_page'], 'paged' => $filters['page'], 'tax_query' => $filters['tax_query'],
			'ignore_sticky_posts' => true, 'suppress_filters' => false, 'orderby' => 'none', 'cache_results' => false );
		$clauses_filter = static function( $clauses, $candidate ) use ( $query, $filters, $wpdb ) {
			if ( $candidate !== $query ) { return $clauses; }
			$p = $wpdb->posts;
			$m = $wpdb->postmeta;
			// Match get_post_meta's first value if old writers left duplicate rows.
			$clauses['join'] .= " INNER JOIN $m AS lf_api_date ON lf_api_date.post_id = $p.ID AND lf_api_date.meta_key = 'lf_match_datetime' AND lf_api_date.meta_id = (SELECT MIN(lf_api_first.meta_id) FROM $m AS lf_api_first WHERE lf_api_first.post_id = $p.ID AND lf_api_first.meta_key = 'lf_match_datetime')";
			$date = 'lf_api_date.meta_value';
			$clauses['where'] .= " AND $p.post_status = 'publish' AND $p.post_password = ''";
			$clauses['where'] .= " AND $date REGEXP '^[1-9][0-9]{3}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01]) ([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$'";
			$clauses['where'] .= " AND CAST(SUBSTRING($date,9,2) AS UNSIGNED) <= DAY(LAST_DAY(CONCAT(SUBSTRING($date,1,7),'-01')))";
			foreach ( array( 'after' => '>=', 'before' => '<=' ) as $key => $op ) {
				if ( $filters[ $key ] ) { $clauses['where'] .= $wpdb->prepare( " AND CAST($date AS DATETIME) $op %s", $filters[ $key ] ); }
			}
			$exists = static function( $key, $value ) use ( $wpdb, $p, $m ) {
				return $wpdb->prepare( "EXISTS (SELECT 1 FROM $m AS lf_api_meta WHERE lf_api_meta.post_id = $p.ID AND lf_api_meta.meta_key = %s AND lf_api_meta.meta_value = %s)", $key, $value );
			};
			if ( $filters['status'] ) { $clauses['where'] .= ' AND ' . $exists( 'lf_status', $filters['status'] ); }
			if ( $filters['team'] ) { $clauses['where'] .= ' AND (' . $exists( 'lf_home_team_id', (string) $filters['team'] ) . ' OR ' . $exists( 'lf_away_team_id', (string) $filters['team'] ) . ')'; }
			if ( null !== $filters['include_knockout'] ) { $clauses['where'] .= ' AND ' . ( $filters['include_knockout'] ? '' : 'NOT ' ) . $exists( 'lf_is_knockout', '1' ); }
			$keys = "('lf_home_team_id','lf_away_team_id')";
			$clauses['where'] .= " AND EXISTS (SELECT 1 FROM $m AS lf_api_ref JOIN $p AS lf_api_team ON lf_api_team.ID = CAST(lf_api_ref.meta_value AS UNSIGNED) AND lf_api_team.post_type = 'lf_team' AND lf_api_team.post_status = 'publish' AND lf_api_team.post_password = '' WHERE lf_api_ref.post_id = $p.ID AND lf_api_ref.meta_key IN $keys)";
			$clauses['where'] .= " AND NOT EXISTS (SELECT 1 FROM $m AS lf_api_ref LEFT JOIN $p AS lf_api_team ON lf_api_team.ID = CAST(lf_api_ref.meta_value AS UNSIGNED) AND lf_api_team.post_type = 'lf_team' AND lf_api_team.post_status = 'publish' AND lf_api_team.post_password = '' WHERE lf_api_ref.post_id = $p.ID AND lf_api_ref.meta_key IN $keys AND lf_api_ref.meta_value NOT IN ('','0') AND lf_api_team.ID IS NULL)";
			$direction = 'desc' === $filters['order'] ? 'DESC' : 'ASC';
			$clauses['groupby'] = "$p.ID";
			$clauses['orderby'] = "CAST($date AS DATETIME) $direction, $p.ID $direction";
			return $clauses;
		};
		add_filter( 'posts_clauses', $clauses_filter, 10, 2 );
		try { $query->query( $args ); } finally { remove_filter( 'posts_clauses', $clauses_filter, 10 ); }
		return $query;
	}

	public function get_collection( \WP_REST_Request $request ) {
		global $wpdb;
		$results = '/leagueflow/v1/match-results' === untrailingslashit( $request->get_route() );
		$filters = $this->filters( $request, $results );
		if ( is_wp_error( $filters ) ) { return $filters; }
		$query = $this->query( $filters );
		// Core WP_Query skips FOUND_ROWS when an offset page is empty. Recover the
		// real filtered total with a one-row query, without loading an entire feed.
		if ( ! $wpdb->last_error && ! $query->posts && $filters['page'] > 1 ) {
			$count = $this->query( array_merge( $filters, array( 'page' => 1, 'per_page' => 1 ) ) );
			$query->found_posts = $count->found_posts;
		}
		if ( $wpdb->last_error ) { return new \WP_Error( 'leagueflow_feed_unavailable', 'The match feed could not be loaded.', array( 'status' => 500 ) ); }
		$match_ids = wp_list_pluck( $query->posts, 'ID' );
		if ( $match_ids ) {
			update_meta_cache( 'post', $match_ids );
			update_object_term_cache( $match_ids, 'lf_match' );
		}
		$team_ids = array();
		foreach ( $query->posts as $post ) {
			$team_ids[] = (int) get_post_meta( $post->ID, 'lf_home_team_id', true );
			$team_ids[] = (int) get_post_meta( $post->ID, 'lf_away_team_id', true );
		}
		if ( $team_ids ) { _prime_post_caches( array_values( array_unique( array_filter( $team_ids ) ) ), false, true ); }
		$records = array();
		$metadata = new \stdClass();
		foreach ( $query->posts as $post ) {
			$record = $this->record( $post );
			$records[] = $record;
			$metadata->{$record['id']} = array_intersect_key( $record, array_flip( array( 'matchId', 'teams', 'sports', 'leagueLevels', 'competitions', 'seasons', 'isKnockout', 'round', 'result' ) ) );
		}
		$total = (int) $query->found_posts;
		$pages = (int) ceil( $total / $filters['per_page'] );
		$page  = $filters['page'];
		$link = static function( $number ) use ( $request, $filters ) {
			return add_query_arg( array_merge( $request->get_query_params(), array( 'page' => $number, 'per_page' => $filters['per_page'], 'view' => $filters['view'], 'order' => $filters['order'] ), isset( $filters['date'] ) ? array( 'date' => $filters['date'] ) : array() ), rest_url( ltrim( $request->get_route(), '/' ) ) );
		};
		$next = $page < $pages ? $link( $page + 1 ) : null;
		$prev = $page > 1 && $pages ? $link( min( $page - 1, $pages ) ) : null;
		$response = new \WP_REST_Response( array(
			$results ? 'results' : 'events' => $records,
			'eventMetadata' => $metadata, 'total' => $total, 'pages' => $pages, 'timezone' => wp_timezone_string(),
			'pagination' => array( 'currentPage' => $page, 'perPage' => $filters['per_page'], 'hasMore' => null !== $next,
				'nextPage' => $next ? $page + 1 : null, 'previousPage' => $prev ? min( $page - 1, $pages ) : null,
				'nextUrl' => $next, 'previousUrl' => $prev, 'view' => $filters['view'], 'order' => $filters['order'],
				'loadedRange' => array( 'start' => $filters['after'], 'end' => $filters['before'] ) ),
		) );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $pages );
		$response->header( 'Cache-Control', 'no-store' );
		$links = array();
		if ( $prev ) { $links[] = '<' . $prev . '>; rel="prev"'; }
		if ( $next ) { $links[] = '<' . $next . '>; rel="next"'; }
		if ( $links ) { $response->header( 'Link', implode( ', ', $links ) ); }
		return $response;
	}

	protected function terms( $id, $taxonomy ) {
		$terms = get_the_terms( $id, $taxonomy );
		if ( ! is_array( $terms ) ) { return array(); }
		usort( $terms, static function( $a, $b ) { return $a->term_id <=> $b->term_id; } );
		return array_map( static function( $term ) { return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug ); }, $terms );
	}

	protected function team( $id ) {
		$post = get_post( $id );
		if ( ! $id || ! $post || 'lf_team' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) { return null; }
		return array( 'id' => (int) $id, 'name' => $post->post_title, 'slug' => $post->post_name, 'url' => get_permalink( $id ), 'logoUrl' => get_the_post_thumbnail_url( $id, 'thumbnail' ) ?: null );
	}

	/** Missing/invalid scores are null, never fabricated 0–0 results. */
	protected function score( $value ) {
		return Match_Status::is_valid_score( $value ) ? (int) $value : null;
	}

	protected function record( $post ) {
		$id = (int) $post->ID;
		$raw = (string) get_post_meta( $id, 'lf_match_datetime', true );
		$start = new \DateTimeImmutable( $raw, wp_timezone() );
		$valid_time = $start->format( 'Y-m-d H:i' ) === substr( $raw, 0, 16 );
		$status = (string) get_post_meta( $id, 'lf_status', true ) ?: 'scheduled';
		$home = (int) get_post_meta( $id, 'lf_home_team_id', true );
		$away = (int) get_post_meta( $id, 'lf_away_team_id', true );
		$result = null;
		if ( 'finished' === $status ) {
			$stored_outcome = (string) get_post_meta( $id, 'lf_outcome', true );
			$forfeit = in_array( $stored_outcome, array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true );
			$outcome = '' === $stored_outcome ? 'normal' : ( $forfeit ? $stored_outcome : 'unknown' );
			$played_result = 'normal' === $outcome && $home && $away && $home !== $away;
			$home_score = $played_result ? $this->score( get_post_meta( $id, 'lf_home_score', true ) ) : null;
			$away_score = $played_result ? $this->score( get_post_meta( $id, 'lf_away_score', true ) ) : null;
			$valid_participants = $home !== $away && ( $home || $away );
			$override = (int) get_post_meta( $id, 'lf_winner_team_id', true );
			// Explicit forfeits decide the result before any older winner override.
			$override = $valid_participants && 'normal' === $outcome && $override && in_array( $override, array( $home, $away ), true ) ? $override : null;
			$complete = $valid_participants && ( ( $home && $away && null !== $home_score && null !== $away_score ) || $forfeit || null !== $override );
			$winner = $override;
			if ( $complete && 'forfeit_home' === $outcome ) { $winner = $away ?: null; }
			if ( $complete && 'forfeit_away' === $outcome ) { $winner = $home ?: null; }
			if ( $complete && ! $winner && 'normal' === $outcome && null !== $home_score && null !== $away_score && $home_score !== $away_score ) { $winner = ( $home_score > $away_score ? $home : $away ) ?: null; }
			$result = array( 'homeScore' => $home_score, 'awayScore' => $away_score, 'outcome' => $outcome, 'winnerTeamId' => $winner,
				'outcomeLabel' => 'unknown' === $outcome ? __( 'Result unavailable', 'leagueflow' ) : Match_Status::outcome_label( $stored_outcome ),
				'winnerOverride' => $override, 'isDraw' => $complete && ! $winner && 'normal' === $outcome && null !== $home_score && $home_score === $away_score, 'complete' => $complete );
		}
		return array(
			'id' => 'match-' . $id, 'matchId' => $id, 'type' => 'match', 'title' => $post->post_title,
			'url' => get_permalink( $id ), 'startDate' => $valid_time ? $start->format( DATE_ATOM ) : null, 'endDate' => null,
			'date' => substr( $raw, 0, 10 ), 'startTime' => substr( $raw, 11 ) . ( 16 === strlen( $raw ) ? ':00' : '' ), 'timezone' => wp_timezone_string(),
			'timeStatus' => $valid_time ? 'valid' : 'invalid_local_time',
			'location' => (string) get_post_meta( $id, 'lf_venue', true ), 'status' => $status,
			'teams' => array( 'home' => $this->team( $home ), 'away' => $this->team( $away ) ),
			'sports' => $this->terms( $id, 'lf_sport' ), 'leagueLevels' => $this->terms( $id, 'lf_league_level' ),
			'competitions' => $this->terms( $id, 'lf_competition' ), 'seasons' => $this->terms( $id, 'lf_season' ),
			'isKnockout' => (bool) get_post_meta( $id, 'lf_is_knockout', true ), 'round' => (string) get_post_meta( $id, 'lf_round_label', true ), 'result' => $result,
		);
	}
}
