<?php
/**
 * Settings API integration.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Settings management.
 */
class Settings {

	/**
	 * Allowed tie-breakers.
	 *
	 * @var array<string, string>
	 */
	protected $tie_breakers = array(
		'goal_difference' => 'Goal difference',
		'goals_for'       => 'Goals for',
		'wins'            => 'Wins',
		'goals_against'   => 'Fewest goals against',
		'name'            => 'Team name',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites' ), 25 );
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'leagueflow_settings',
			'leagueflow_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => defaults(),
			)
		);

		add_settings_section(
			'leagueflow_points',
			__( 'Competition Rules', 'leagueflow' ),
			array( $this, 'render_points_section' ),
			'leagueflow-settings',
			array(
				'before_section' => '<div class="leagueflow-settings-panel %s">',
				'after_section'  => '</div>',
				'section_class'  => 'leagueflow-settings-panel--rules',
			)
		);

		add_settings_section(
			'leagueflow_display',
			__( 'Display and Routing', 'leagueflow' ),
			array( $this, 'render_display_section' ),
			'leagueflow-settings',
			array(
				'before_section' => '<div class="leagueflow-settings-panel %s">',
				'after_section'  => '</div>',
				'section_class'  => 'leagueflow-settings-panel--routing',
			)
		);

		add_settings_section(
			'leagueflow_registration',
			__( 'Registration Windows', 'leagueflow' ),
			array( $this, 'render_registration_section' ),
			'leagueflow-settings',
			array(
				'before_section' => '<div class="leagueflow-settings-panel %s">',
				'after_section'  => '</div>',
				'section_class'  => 'leagueflow-settings-panel--registration',
			)
		);

		add_settings_field( 'points_win', __( 'Points for a win', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'points_win', 'min' => 0, 'class' => 'leagueflow-setting-row--points' ) );
		add_settings_field( 'points_draw', __( 'Points for a draw', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'points_draw', 'min' => 0, 'class' => 'leagueflow-setting-row--points' ) );
		add_settings_field( 'points_loss', __( 'Points for a loss', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'points_loss', 'min' => 0, 'class' => 'leagueflow-setting-row--points' ) );
		add_settings_field( 'tie_breakers', __( 'Tie-breaker priority', 'leagueflow' ), array( $this, 'render_tie_breakers_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'class' => 'leagueflow-setting-row--wide' ) );

		add_settings_field( 'captain_registration_open', __( 'Captain registration', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_registration', array( 'key' => 'captain_registration_open', 'label' => __( 'Captains can create teams during the team-building window', 'leagueflow' ), 'class' => 'leagueflow-setting-row--wide' ) );
		add_settings_field( 'player_registration_open', __( 'Player registration', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_registration', array( 'key' => 'player_registration_open', 'label' => __( 'Players can create profiles and request or ask for team placement', 'leagueflow' ), 'class' => 'leagueflow-setting-row--wide' ) );

		add_settings_field( 'team_slug', __( 'Team slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'team_slug' ) );
		add_settings_field( 'match_slug', __( 'Match slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'match_slug' ) );
		add_settings_field( 'competition_slug', __( 'Competition slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'competition_slug' ) );
		add_settings_field( 'season_slug', __( 'Season slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'season_slug' ) );
		add_settings_field( 'date_time_format', __( 'Match date/time format', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'date_time_format', 'placeholder' => 'F j, Y g:i a' ) );
		add_settings_field( 'show_logos', __( 'Show team logos by default', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'show_logos', 'class' => 'leagueflow-setting-row--toggle' ) );
		add_settings_field( 'show_player_photos', __( 'Show player photos by default', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'show_player_photos', 'class' => 'leagueflow-setting-row--toggle' ) );
		add_settings_field( 'cleanup_on_uninstall', __( 'Delete data on uninstall', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'cleanup_on_uninstall', 'class' => 'leagueflow-setting-row--toggle' ) );
	}

	/**
	 * Sanitize settings values.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$current          = get_settings();
		$input            = is_array( $input ) ? $input : array();
		$sanitized        = defaults();
		$slug_keys        = array( 'team_slug', 'match_slug', 'competition_slug', 'season_slug' );
		$changed_rewrites = false;

		$sanitized['points_win']         = max( 0, absint( $input['points_win'] ?? $current['points_win'] ) );
		$sanitized['points_draw']        = max( 0, absint( $input['points_draw'] ?? $current['points_draw'] ) );
		$sanitized['points_loss']        = max( 0, absint( $input['points_loss'] ?? $current['points_loss'] ) );
		$sanitized['date_time_format']   = sanitize_text_field( $input['date_time_format'] ?? $current['date_time_format'] );
		$sanitized['show_logos']         = bool_to_int( $input['show_logos'] ?? 0 );
		$sanitized['show_player_photos'] = bool_to_int( $input['show_player_photos'] ?? 0 );
		$sanitized['captain_registration_open'] = bool_to_int( $input['captain_registration_open'] ?? 0 );
		$sanitized['player_registration_open'] = bool_to_int( $input['player_registration_open'] ?? 0 );
		$sanitized['cleanup_on_uninstall'] = bool_to_int( $input['cleanup_on_uninstall'] ?? 0 );

		foreach ( $slug_keys as $key ) {
			$sanitized[ $key ] = sanitize_title( $input[ $key ] ?? $current[ $key ] );
			if ( $sanitized[ $key ] !== $current[ $key ] ) {
				$changed_rewrites = true;
			}
		}

		$tie_breakers = array();

		if ( ! empty( $input['tie_breakers'] ) && is_array( $input['tie_breakers'] ) ) {
			foreach ( $input['tie_breakers'] as $value ) {
				$value = sanitize_key( $value );
				if ( isset( $this->tie_breakers[ $value ] ) && ! in_array( $value, $tie_breakers, true ) ) {
					$tie_breakers[] = $value;
				}
			}
		}

		if ( count( $tie_breakers ) < 4 ) {
			foreach ( defaults()['tie_breakers'] as $default_value ) {
				if ( ! in_array( $default_value, $tie_breakers, true ) ) {
					$tie_breakers[] = $default_value;
				}

				if ( count( $tie_breakers ) >= 4 ) {
					break;
				}
			}
		}

		$sanitized['tie_breakers'] = array_values( array_slice( $tie_breakers, 0, 4 ) );

		if ( $changed_rewrites ) {
			update_option( 'leagueflow_flush_rewrite', 1, false );
		}

		return $sanitized;
	}

	/**
	 * Flush rewrites after slug changes.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites() {
		if ( ! get_option( 'leagueflow_flush_rewrite' ) ) {
			return;
		}

		flush_rewrite_rules();
		delete_option( 'leagueflow_flush_rewrite' );
	}

	/**
	 * Render section description.
	 *
	 * @return void
	 */
	public function render_points_section() {
		echo '<p>' . esc_html__( 'Choose how LeagueFlow calculates standings and resolves ties between teams on equal points.', 'leagueflow' ) . '</p>';
	}

	/**
	 * Render section description.
	 *
	 * @return void
	 */
	public function render_display_section() {
		echo '<p>' . esc_html__( 'Adjust default frontend display preferences and public slug settings for LeagueFlow content.', 'leagueflow' ) . '</p>';
	}

	/**
	 * Render registration section description.
	 *
	 * @return void
	 */
	public function render_registration_section() {
		echo '<p>' . esc_html__( 'Open captain registration first for team creation. After the team-building window, close captain registration and open player registration.', 'leagueflow' ) . '</p>';
	}

	/**
	 * Render number input field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function render_number_field( $args ) {
		$key     = $args['key'];
		$value   = get_setting( $key, '' );
		$minimum = isset( $args['min'] ) ? (int) $args['min'] : 0;

		printf(
			'<input type="number" class="small-text" min="%1$d" name="leagueflow_settings[%2$s]" value="%3$s" />',
			(int) $minimum,
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Render text field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function render_text_field( $args ) {
		$key         = $args['key'];
		$value       = get_setting( $key, '' );
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';

		printf(
			'<input type="text" class="regular-text" name="leagueflow_settings[%1$s]" value="%2$s" placeholder="%3$s" />',
			esc_attr( $key ),
			esc_attr( (string) $value ),
			esc_attr( (string) $placeholder )
		);
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function render_checkbox_field( $args ) {
		$key   = $args['key'];
		$value = bool_to_int( get_setting( $key, 0 ) );
		$label = isset( $args['label'] ) ? (string) $args['label'] : __( 'Enabled', 'leagueflow' );

		printf(
			'<label><input type="checkbox" name="leagueflow_settings[%1$s]" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( $value, 1, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render tie-breaker ranking fields.
	 *
	 * @return void
	 */
	public function render_tie_breakers_field() {
		$values = get_setting( 'tie_breakers', defaults()['tie_breakers'] );

		echo '<div class="leagueflow-tie-breakers">';
		echo '<div class="leagueflow-tie-breakers__grid">';

		for ( $index = 0; $index < 4; $index++ ) {
			$current = isset( $values[ $index ] ) ? $values[ $index ] : '';

			printf(
				'<label class="leagueflow-tie-breaker" for="leagueflow_tie_breaker_%1$d"><span>%2$s</span>',
				(int) $index,
				esc_html(
					sprintf(
						/* translators: %d: tie-breaker priority position */
						__( 'Priority %d', 'leagueflow' ),
						$index + 1
					)
				)
			);

			printf(
				'<select id="leagueflow_tie_breaker_%1$d" name="leagueflow_settings[tie_breakers][%1$d]">',
				(int) $index
			);

			foreach ( $this->tie_breakers as $value => $label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $value ),
					selected( $current, $value, false ),
					esc_html( $label )
				);
			}

			echo '</select></label>';
		}

		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Points remain the primary ranking value. These rules apply when teams are tied on points.', 'leagueflow' ) . '</p>';
		echo '</div>';
	}
}
