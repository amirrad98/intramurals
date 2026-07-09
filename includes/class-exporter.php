<?php
/**
 * Data export helpers.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Builds LeagueFlow export files.
 */
class Exporter {

	/**
	 * Sports manager.
	 *
	 * @var Sports_Manager
	 */
	protected $sports_manager;

	/**
	 * Constructor.
	 *
	 * @param Sports_Manager $sports_manager Sports manager.
	 */
	public function __construct( Sports_Manager $sports_manager ) {
		$this->sports_manager = $sports_manager;
	}

	/**
	 * Get enabled sport slugs for export.
	 *
	 * @param string $sport_slug Requested sport slug, or all.
	 * @return array<int, string>
	 */
	public function get_export_sport_slugs( $sport_slug = 'all' ) {
		$sport_slug = sanitize_key( (string) $sport_slug );
		$enabled    = $this->sports_manager->get_enabled_sport_slugs();

		if ( 'all' !== $sport_slug && in_array( $sport_slug, $enabled, true ) ) {
			return array( $sport_slug );
		}

		return $enabled;
	}

	/**
	 * Build a player roster CSV.
	 *
	 * @param array<int, string> $sport_slugs Sport slugs.
	 * @param array<string, mixed> $args Export options.
	 * @return string
	 */
	public function build_player_roster_csv( $sport_slugs, $args = array() ) {
		$sport_slugs = $this->normalize_sport_slugs( $sport_slugs );
		$args    = $this->normalize_player_roster_args( $args );
		$headers = $this->get_player_roster_headers( (bool) $args['include_user_accounts'] );
		$rows    = $this->get_player_roster_rows_for_sports( $sport_slugs, $args );
		$handle  = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return '';
		}

		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, $headers );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return false === $csv ? '' : $csv;
	}

	/**
	 * Build a player roster XLSX.
	 *
	 * @param array<int, string> $sport_slugs Sport slugs.
	 * @param array<string, mixed> $args Export options.
	 * @return string|\WP_Error
	 */
	public function build_player_roster_xlsx( $sport_slugs, $args = array() ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'leagueflow_zip_missing', __( 'The PHP Zip extension is required to create Excel files.', 'leagueflow' ) );
		}

		$sport_slugs  = $this->normalize_sport_slugs( $sport_slugs );
		$args        = $this->normalize_player_roster_args( $args );
		$headers     = $this->get_player_roster_headers( (bool) $args['include_user_accounts'] );
		$rows        = $this->get_player_roster_rows_for_sports( $sport_slugs, $args );
		$sheet_title = 1 === count( $sport_slugs ) ? $this->sports_manager->get_definition( $sport_slugs[0] )['label'] : __( 'Players', 'leagueflow' );

		return $this->build_xlsx( $sheet_title, $headers, $rows );
	}

	/**
	 * Build rows for a set of sports.
	 *
	 * @param array<int, string> $sport_slugs Sport slugs.
	 * @param array<string, mixed> $args Export options.
	 * @return array<int, array<int, string>>
	 */
	public function get_player_roster_rows_for_sports( $sport_slugs, $args = array() ) {
		$sport_slugs = $this->normalize_sport_slugs( $sport_slugs );
		$args        = $this->normalize_player_roster_args( $args );
		$rows        = array();

		foreach ( $sport_slugs as $sport_slug ) {
			foreach ( $this->get_player_roster_rows( $sport_slug, $args ) as $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Normalize a sport list.
	 *
	 * @param array<int, string> $sport_slugs Sport slugs.
	 * @return array<int, string>
	 */
	protected function normalize_sport_slugs( $sport_slugs ) {
		$sport_slugs = array_values( array_filter( array_unique( array_map( 'sanitize_key', (array) $sport_slugs ) ) ) );

		return empty( $sport_slugs ) ? $this->sports_manager->get_enabled_sport_slugs() : $sport_slugs;
	}

	/**
	 * Build player roster rows for one sport.
	 *
	 * @param string $sport_slug Sport slug.
	 * @param array<string, mixed> $args Export options.
	 * @return array<int, array<int, string>>
	 */
	protected function get_player_roster_rows( $sport_slug, $args ) {
		$sport_slug = sanitize_key( (string) $sport_slug );
		$args       = $this->normalize_player_roster_args( $args );
		$players    = get_posts(
			array_filter(
				array(
					'post_type'      => 'lf_player',
					'post_status'    => $this->get_post_statuses_for_scope( $args['status_scope'] ),
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'date_query'     => $this->get_date_query( $args['date_from'], $args['date_to'] ),
				)
			)
		);
		$rows       = array();

		foreach ( $players as $player ) {
			if ( ! $player instanceof \WP_Post ) {
				continue;
			}

			$matching_team_ids = $this->get_matching_player_team_ids( $player->ID, $sport_slug, $args );

			if ( empty( $matching_team_ids ) ) {
				if ( empty( $args['include_unassigned'] ) || ! $this->player_has_sport( $player->ID, $sport_slug ) || $args['season_id'] ) {
					continue;
				}

				$rows[] = $this->build_player_roster_row( $player, null, $sport_slug, $args );
				continue;
			}

			foreach ( $matching_team_ids as $team_id ) {
				$team = get_post( $team_id );

				if ( $team instanceof \WP_Post ) {
					$rows[] = $this->build_player_roster_row( $player, $team, $sport_slug, $args );
				}
			}
		}

		return $rows;
	}

	/**
	 * Get matching team memberships for a player.
	 *
	 * @param int $player_id Player post ID.
	 * @param string $sport_slug Sport slug.
	 * @param array<string, mixed> $args Export options.
	 * @return array<int, int>
	 */
	protected function get_matching_player_team_ids( $player_id, $sport_slug, $args ) {
		$team_ids = array();

		foreach ( get_player_team_ids( $player_id ) as $team_id ) {
			$team = get_post( $team_id );

			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
				continue;
			}

			if ( ! in_array( $team->post_status, $this->get_post_statuses_for_scope( $args['status_scope'] ), true ) ) {
				continue;
			}

			if ( $sport_slug !== $this->sports_manager->get_post_sport_slug( $team_id ) ) {
				continue;
			}

			if ( ! empty( $args['season_id'] ) && ! has_term( (int) $args['season_id'], 'lf_season', $team_id ) ) {
				continue;
			}

			$team_ids[] = $team_id;
		}

		sort( $team_ids );

		return array_values( array_unique( array_map( 'absint', $team_ids ) ) );
	}

	/**
	 * Build one export row.
	 *
	 * @param \WP_Post|null $player Player post.
	 * @param \WP_Post|null $team Team post.
	 * @param string $sport_slug Sport slug.
	 * @param array<string, mixed> $args Export options.
	 * @return array<int, string>
	 */
	protected function build_player_roster_row( $player, $team, $sport_slug, $args ) {
		$sport             = $this->sports_manager->get_definition( $sport_slug );
		$team_id           = $team instanceof \WP_Post ? (int) $team->ID : 0;
		$player_id         = $player instanceof \WP_Post ? (int) $player->ID : 0;
		$player_email      = (string) get_post_meta( $player_id, 'lf_email', true );
		$linked_user_id    = (int) get_post_meta( $player_id, 'lf_user_id', true );
		$linked_user       = $linked_user_id ? get_user_by( 'id', $linked_user_id ) : false;
		$competition_names = $team_id ? wp_get_post_terms( $team_id, 'lf_competition', array( 'fields' => 'names' ) ) : array();
		$season_names      = $team_id ? wp_get_post_terms( $team_id, 'lf_season', array( 'fields' => 'names' ) ) : array();
		$row               = array(
			(string) $sport['label'],
			$team instanceof \WP_Post ? $team->post_title : __( 'Unassigned', 'leagueflow' ),
			$team_id ? (string) $team_id : '',
			$team instanceof \WP_Post ? $this->get_readable_post_status( $team->post_status ) : '',
			$team_id ? (string) get_post_meta( $team_id, 'lf_short_name', true ) : '',
			$team_id ? (string) get_post_meta( $team_id, 'lf_city', true ) : '',
			$team_id ? (string) get_post_meta( $team_id, 'lf_coach', true ) : '',
			is_wp_error( $competition_names ) ? '' : implode( ', ', $competition_names ),
			is_wp_error( $season_names ) ? '' : implode( ', ', $season_names ),
			$player instanceof \WP_Post ? $player->post_title : '',
			$player_id ? (string) $player_id : '',
			$player instanceof \WP_Post ? $this->get_readable_post_status( $player->post_status ) : '',
			(string) get_post_meta( $player_id, 'lf_jersey_number', true ),
			(string) get_post_meta( $player_id, 'lf_position', true ),
			(string) get_post_meta( $player_id, 'lf_age', true ),
			(string) get_post_meta( $player_id, 'lf_nationality', true ),
			(bool) get_post_meta( $player_id, 'lf_is_captain', true ) ? __( 'Yes', 'leagueflow' ) : __( 'No', 'leagueflow' ),
			$player_email,
		);

		if ( ! empty( $args['include_user_accounts'] ) ) {
			$row[] = $linked_user_id ? (string) $linked_user_id : '';
			$row[] = $linked_user instanceof \WP_User ? $linked_user->user_login : '';
			$row[] = $linked_user instanceof \WP_User ? $linked_user->user_email : '';
		}

		$row[] = $player instanceof \WP_Post ? $player->post_date : '';
		$row[] = $player instanceof \WP_Post ? $player->post_modified : '';
		$row[] = $player_id ? (string) get_edit_post_link( $player_id, 'raw' ) : '';

		return array_map( 'strval', $row );
	}

	/**
	 * Get player roster headers.
	 *
	 * @param bool $include_user_accounts Include linked WP user fields.
	 * @return array<int, string>
	 */
	protected function get_player_roster_headers( $include_user_accounts ) {
		$headers = array(
			__( 'Sport', 'leagueflow' ),
			__( 'Team', 'leagueflow' ),
			__( 'Team ID', 'leagueflow' ),
			__( 'Team Status', 'leagueflow' ),
			__( 'Team Short Name', 'leagueflow' ),
			__( 'Team City', 'leagueflow' ),
			__( 'Team Coach', 'leagueflow' ),
			__( 'Competitions', 'leagueflow' ),
			__( 'Seasons', 'leagueflow' ),
			__( 'Player', 'leagueflow' ),
			__( 'Player ID', 'leagueflow' ),
			__( 'Player Status', 'leagueflow' ),
			__( 'Jersey Number', 'leagueflow' ),
			__( 'Position', 'leagueflow' ),
			__( 'Age', 'leagueflow' ),
			__( 'Nationality', 'leagueflow' ),
			__( 'Captain', 'leagueflow' ),
			__( 'Player Email', 'leagueflow' ),
		);

		if ( $include_user_accounts ) {
			$headers[] = __( 'Linked User ID', 'leagueflow' );
			$headers[] = __( 'Linked Username', 'leagueflow' );
			$headers[] = __( 'Linked User Email', 'leagueflow' );
		}

		$headers[] = __( 'Player Created', 'leagueflow' );
		$headers[] = __( 'Player Updated', 'leagueflow' );
		$headers[] = __( 'Player Edit URL', 'leagueflow' );

		return $headers;
	}

	/**
	 * Normalize player roster options.
	 *
	 * @param array<string, mixed> $args Export options.
	 * @return array<string, mixed>
	 */
	protected function normalize_player_roster_args( $args ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'season_id'             => 0,
				'date_from'             => '',
				'date_to'               => '',
				'status_scope'          => 'active',
				'include_unassigned'    => true,
				'include_user_accounts' => false,
			)
		);

		$args['season_id']             = absint( $args['season_id'] );
		$args['date_from']             = $this->sanitize_date_value( $args['date_from'] );
		$args['date_to']               = $this->sanitize_date_value( $args['date_to'] );
		$args['status_scope']          = 'historical' === sanitize_key( (string) $args['status_scope'] ) ? 'historical' : 'active';
		$args['include_unassigned']    = ! empty( $args['include_unassigned'] );
		$args['include_user_accounts'] = ! empty( $args['include_user_accounts'] );

		return $args;
	}

	/**
	 * Get post statuses for active or historical exports.
	 *
	 * @param string $status_scope Status scope.
	 * @return array<int, string>
	 */
	protected function get_post_statuses_for_scope( $status_scope ) {
		if ( 'historical' === $status_scope ) {
			return array( 'publish', 'draft', 'pending', 'future', 'private' );
		}

		return array( 'publish' );
	}

	/**
	 * Build a WordPress date query.
	 *
	 * @param string $date_from From date.
	 * @param string $date_to To date.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_date_query( $date_from, $date_to ) {
		if ( ! $date_from && ! $date_to ) {
			return array();
		}

		$query = array(
			'inclusive' => true,
			'column'    => 'post_date',
		);

		if ( $date_from ) {
			$query['after'] = $date_from;
		}

		if ( $date_to ) {
			$query['before'] = $date_to;
		}

		return array( $query );
	}

	/**
	 * Check if a player is assigned to a sport term.
	 *
	 * @param int $player_id Player post ID.
	 * @param string $sport_slug Sport slug.
	 * @return bool
	 */
	protected function player_has_sport( $player_id, $sport_slug ) {
		$terms = wp_get_post_terms( absint( $player_id ), 'lf_sport', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}

		return in_array( sanitize_key( $sport_slug ), array_map( 'sanitize_key', $terms ), true );
	}

	/**
	 * Get a readable post status label.
	 *
	 * @param string $status Post status.
	 * @return string
	 */
	protected function get_readable_post_status( $status ) {
		$status_object = get_post_status_object( $status );

		return $status_object ? $status_object->label : ucfirst( $status );
	}

	/**
	 * Sanitize a date value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected function sanitize_date_value( $value ) {
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Build a minimal XLSX file.
	 *
	 * @param string $sheet_title Sheet title.
	 * @param array<int, string> $headers Headers.
	 * @param array<int, array<int, string>> $rows Rows.
	 * @return string|\WP_Error
	 */
	protected function build_xlsx( $sheet_title, $headers, $rows ) {
		$temp_file = wp_tempnam( 'leagueflow-export.xlsx' );

		if ( ! $temp_file ) {
			return new \WP_Error( 'leagueflow_export_temp_file', __( 'Could not create a temporary export file.', 'leagueflow' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $temp_file );
			return new \WP_Error( 'leagueflow_export_zip_open', __( 'Could not create the Excel archive.', 'leagueflow' ) );
		}

		$zip->addFromString( '[Content_Types].xml', $this->xlsx_content_types_xml() );
		$zip->addFromString( '_rels/.rels', $this->xlsx_root_rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', $this->xlsx_workbook_xml( $sheet_title ) );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->xlsx_workbook_rels_xml() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $this->xlsx_sheet_xml( $headers, $rows ) );
		$zip->close();

		$contents = file_get_contents( $temp_file );
		wp_delete_file( $temp_file );

		if ( false === $contents ) {
			return new \WP_Error( 'leagueflow_export_read_file', __( 'Could not read the generated Excel file.', 'leagueflow' ) );
		}

		return $contents;
	}

	/**
	 * Build worksheet XML.
	 *
	 * @param array<int, string> $headers Headers.
	 * @param array<int, array<int, string>> $rows Rows.
	 * @return string
	 */
	protected function xlsx_sheet_xml( $headers, $rows ) {
		$sheet_rows = array_merge( array( $headers ), $rows );
		$row_xml    = '';
		$column_max = count( $headers );

		foreach ( $sheet_rows as $row_index => $row ) {
			$excel_row = $row_index + 1;
			$row_xml  .= '<row r="' . absint( $excel_row ) . '">';

			for ( $column = 0; $column < $column_max; $column++ ) {
				$cell_ref = $this->xlsx_column_name( $column + 1 ) . $excel_row;
				$value    = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
				$row_xml .= '<c r="' . esc_attr( $cell_ref ) . '" t="inlineStr"><is><t' . $this->xlsx_xml_space_attribute( $value ) . '>' . $this->xml_escape( $value ) . '</t></is></c>';
			}

			$row_xml .= '</row>';
		}

		$last_cell = $this->xlsx_column_name( max( 1, $column_max ) ) . max( 1, count( $sheet_rows ) );

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. $this->xlsx_columns_xml( $headers )
			. '<sheetData>' . $row_xml . '</sheetData>'
			. '<autoFilter ref="A1:' . esc_attr( $last_cell ) . '"/>'
			. '</worksheet>';
	}

	/**
	 * Build column sizing XML.
	 *
	 * @param array<int, string> $headers Headers.
	 * @return string
	 */
	protected function xlsx_columns_xml( $headers ) {
		$xml = '<cols>';

		foreach ( array_values( $headers ) as $index => $header ) {
			$width = min( 42, max( 12, strlen( (string) $header ) + 4 ) );
			$col   = $index + 1;
			$xml  .= '<col min="' . absint( $col ) . '" max="' . absint( $col ) . '" width="' . esc_attr( (string) $width ) . '" customWidth="1"/>';
		}

		return $xml . '</cols>';
	}

	/**
	 * Build Content Types XML.
	 *
	 * @return string
	 */
	protected function xlsx_content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>';
	}

	/**
	 * Build root relationships XML.
	 *
	 * @return string
	 */
	protected function xlsx_root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Build workbook XML.
	 *
	 * @param string $sheet_title Sheet title.
	 * @return string
	 */
	protected function xlsx_workbook_xml( $sheet_title ) {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . esc_attr( $this->sanitize_sheet_title( $sheet_title ) ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/**
	 * Build workbook relationships XML.
	 *
	 * @return string
	 */
	protected function xlsx_workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Build an Excel column label.
	 *
	 * @param int $index 1-based column index.
	 * @return string
	 */
	protected function xlsx_column_name( $index ) {
		$name  = '';
		$index = absint( $index );

		while ( $index > 0 ) {
			$index--;
			$name  = chr( 65 + ( $index % 26 ) ) . $name;
			$index = (int) floor( $index / 26 );
		}

		return $name;
	}

	/**
	 * Add xml:space when a string has significant outer whitespace.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	protected function xlsx_xml_space_attribute( $value ) {
		return preg_match( '/^\s|\s$/', $value ) ? ' xml:space="preserve"' : '';
	}

	/**
	 * Sanitize a sheet title for Excel.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	protected function sanitize_sheet_title( $title ) {
		$title = trim( preg_replace( '/[\[\]\:\*\?\/\\\\]/', ' ', (string) $title ) );

		if ( '' === $title ) {
			$title = __( 'Players', 'leagueflow' );
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 31 ) : substr( $title, 0, 31 );
	}

	/**
	 * Escape XML text content.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected function xml_escape( $value ) {
		return htmlspecialchars( wp_check_invalid_utf8( (string) $value ), ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}
}
