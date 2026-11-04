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
		'goal_difference' => 'Score difference',
		'goals_for'       => 'Most scored',
		'wins'            => 'Wins',
		'goals_against'   => 'Fewest conceded',
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
			'leagueflow_scheduling',
			__( 'Scheduling Constraints', 'leagueflow' ),
			array( $this, 'render_scheduling_section' ),
			'leagueflow-settings',
			array(
				'before_section' => '<div class="leagueflow-settings-panel %s">',
				'after_section'  => '</div>',
				'section_class'  => 'leagueflow-settings-panel--scheduling',
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

		add_settings_field( 'points_win', __( 'Points for a win', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'points_win', 'label_for' => 'leagueflow_setting_points_win', 'min' => 0, 'class' => 'leagueflow-setting-row--points' ) );
		add_settings_field( 'points_draw', __( 'Points for a draw (tie)', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'points_draw', 'label_for' => 'leagueflow_setting_points_draw', 'min' => 0, 'class' => 'leagueflow-setting-row--points' ) );
		add_settings_field( 'points_loss', __( 'Points for a loss', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'points_loss', 'label_for' => 'leagueflow_setting_points_loss', 'min' => 0, 'class' => 'leagueflow-setting-row--points' ) );
		add_settings_field( 'forfeit_score_winner', __( 'Forfeit walkover score', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'key' => 'forfeit_score_winner', 'label_for' => 'leagueflow_setting_forfeit_score_winner', 'min' => 0, 'class' => 'leagueflow-setting-row--points leagueflow-setting-row--wide', 'description' => __( 'Score credited to the team that wins by forfeit (default 3-0), in the sport\'s own unit (goals, points, runs or sets).', 'leagueflow' ) ) );
		add_settings_field( 'tie_breakers', __( 'Tie-breaker priority', 'leagueflow' ), array( $this, 'render_tie_breakers_field' ), 'leagueflow-settings', 'leagueflow_points', array( 'class' => 'leagueflow-setting-row--wide' ) );

		add_settings_field( 'min_rest_days', __( 'Preferred rest days', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_scheduling', array( 'key' => 'min_rest_days', 'label_for' => 'leagueflow_setting_min_rest_days', 'min' => 0, 'description' => __( 'Auto-scheduling spaces a team\'s games at least this many days apart when slots allow. 0 disables spacing.', 'leagueflow' ) ) );
		add_settings_field( 'min_days_between_rematch', __( 'Days between rematches', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_scheduling', array( 'key' => 'min_days_between_rematch', 'label_for' => 'leagueflow_setting_min_days_between_rematch', 'min' => 0, 'description' => __( 'The two legs of the same pairing are kept at least this many days apart. 0 disables the rule.', 'leagueflow' ) ) );
		add_settings_field( 'max_games_per_day_per_team', __( 'Max games per team per day', 'leagueflow' ), array( $this, 'render_number_field' ), 'leagueflow-settings', 'leagueflow_scheduling', array( 'key' => 'max_games_per_day_per_team', 'label_for' => 'leagueflow_setting_max_games_per_day_per_team', 'min' => 0, 'description' => __( 'Hard cap on games a single team can be auto-scheduled into on one day. 0 means no cap.', 'leagueflow' ) ) );

		add_settings_field( 'captain_registration_open', __( 'Captain registration', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_registration', array( 'key' => 'captain_registration_open', 'label_for' => 'leagueflow_setting_captain_registration_open', 'label' => __( 'Captains can create teams during the team-building window', 'leagueflow' ), 'class' => 'leagueflow-setting-row--wide' ) );
		add_settings_field( 'player_registration_open', __( 'Player registration', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_registration', array( 'key' => 'player_registration_open', 'label_for' => 'leagueflow_setting_player_registration_open', 'label' => __( 'Players can create profiles and request or ask for team placement', 'leagueflow' ), 'class' => 'leagueflow-setting-row--wide' ) );
		add_settings_field( 'registration_email', __( 'Registration inbox', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_registration', array( 'key' => 'registration_email', 'label_for' => 'leagueflow_setting_registration_email', 'type' => 'email', 'placeholder' => get_option( 'admin_email', '' ), 'description' => __( 'Receives player placement requests. The site administration email is used when this is blank.', 'leagueflow' ), 'class' => 'leagueflow-setting-row--wide' ) );

		add_settings_field( 'registration_documents', __( 'Waiver and Code of Conduct', 'leagueflow' ), array( $this, 'render_registration_documents' ), 'leagueflow-settings', 'leagueflow_registration', array( 'class' => 'leagueflow-setting-row--wide' ) );

		add_settings_field( 'team_slug', __( 'Team slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'team_slug', 'label_for' => 'leagueflow_setting_team_slug' ) );
		add_settings_field( 'match_slug', __( 'Match slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'match_slug', 'label_for' => 'leagueflow_setting_match_slug' ) );
		add_settings_field( 'competition_slug', __( 'Competition slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'competition_slug', 'label_for' => 'leagueflow_setting_competition_slug' ) );
		add_settings_field( 'season_slug', __( 'Season slug', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'season_slug', 'label_for' => 'leagueflow_setting_season_slug' ) );
		add_settings_field( 'date_time_format', __( 'Match date/time format', 'leagueflow' ), array( $this, 'render_text_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'date_time_format', 'label_for' => 'leagueflow_setting_date_time_format', 'placeholder' => 'F j, Y g:i a' ) );
		add_settings_field( 'show_logos', __( 'Show team logos by default', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'show_logos', 'label_for' => 'leagueflow_setting_show_logos', 'label' => __( 'Show team logos in LeagueFlow blocks and shortcodes that do not set their own logo option', 'leagueflow' ), 'class' => 'leagueflow-setting-row--toggle' ) );
		add_settings_field( 'show_player_photos', __( 'Show player photos by default', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'show_player_photos', 'label_for' => 'leagueflow_setting_show_player_photos', 'label' => __( 'Show player photos in rosters that do not set their own photo option', 'leagueflow' ), 'class' => 'leagueflow-setting-row--toggle' ) );
		add_settings_field( 'cleanup_on_uninstall', __( 'Delete data on uninstall', 'leagueflow' ), array( $this, 'render_checkbox_field' ), 'leagueflow-settings', 'leagueflow_display', array( 'key' => 'cleanup_on_uninstall', 'label_for' => 'leagueflow_setting_cleanup_on_uninstall', 'label' => __( 'When the plugin is deleted, permanently delete its teams, players, matches, competitions, seasons, league levels, venue availability and settings', 'leagueflow' ), 'class' => 'leagueflow-setting-row--toggle' ) );
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

		$numbers = self::scoring_number_labels() + array(
			'min_rest_days'              => __( 'Preferred rest days', 'leagueflow' ),
			'min_days_between_rematch'   => __( 'Days between rematches', 'leagueflow' ),
			'max_games_per_day_per_team' => __( 'Max games per team per day', 'leagueflow' ),
		);
		foreach ( $numbers as $key => $label ) {
			$value = $input[ $key ] ?? $current[ $key ];
			$value = is_string( $value ) ? trim( $value ) : $value;
			$sanitized[ $key ] = $current[ $key ];
			if ( Match_Status::is_valid_score( $value ) ) {
				$sanitized[ $key ] = (int) $value;
			} else {
				add_settings_error( 'leagueflow_settings', 'leagueflow_invalid_' . $key, sprintf( /* translators: %s: setting label */ __( '%s must be a non-negative whole number. Its previous value was kept.', 'leagueflow' ), $label ) );
			}
		}
		$sanitized['date_time_format']   = sanitize_text_field( $input['date_time_format'] ?? $current['date_time_format'] );
		$sanitized['show_logos']         = bool_to_int( $input['show_logos'] ?? 0 );
		$sanitized['show_player_photos'] = bool_to_int( $input['show_player_photos'] ?? 0 );
		$sanitized['captain_registration_open'] = bool_to_int( $input['captain_registration_open'] ?? 0 );
		$sanitized['player_registration_open'] = bool_to_int( $input['player_registration_open'] ?? 0 );
		$sanitized['registration_email'] = sanitize_email( $input['registration_email'] ?? '' );
		$sanitized['registration_documents'] = Registration_Consent::sanitize_documents( $input['registration_documents'] ?? ( $current['registration_documents'] ?? array() ) );
		foreach ( $sanitized['registration_documents'] as $document ) {
			if ( $document['required'] && ( '' === $document['version'] || ( '' === trim( wp_strip_all_tags( $document['text'] ) ) && '' === $document['url'] ) ) ) {
				add_settings_error( 'leagueflow_settings', 'leagueflow_consent_incomplete', __( 'A required registration document needs a version and approved text or a document URL. Registration is blocked until this is completed or the requirement is disabled.', 'leagueflow' ) );
			}
		}
		$sanitized['cleanup_on_uninstall'] = bool_to_int( $input['cleanup_on_uninstall'] ?? 0 );

		foreach ( $slug_keys as $key ) {
			$raw = $input[ $key ] ?? $current[ $key ];
			$sanitized[ $key ] = is_string( $raw ) ? sanitize_title( $raw ) : '';
			if ( '' === $sanitized[ $key ] ) {
				$sanitized[ $key ] = $current[ $key ];
				add_settings_error( 'leagueflow_settings', 'leagueflow_empty_' . $key, __( 'Public URL slugs cannot be blank. The previous value was kept.', 'leagueflow' ) );
			}
		}
		if ( count( array_unique( array_intersect_key( $sanitized, array_flip( $slug_keys ) ) ) ) !== count( $slug_keys ) ) {
			foreach ( $slug_keys as $key ) {
				$sanitized[ $key ] = $current[ $key ];
			}
			add_settings_error( 'leagueflow_settings', 'leagueflow_duplicate_slugs', __( 'Teams, matches, competitions and seasons need different URL slugs. The previous slugs were kept.', 'leagueflow' ) );
		}
		foreach ( $slug_keys as $key ) {
			$changed_rewrites = $changed_rewrites || $sanitized[ $key ] !== $current[ $key ];
		}

		$tie_breakers = array();

		if ( ! empty( $input['tie_breakers'] ) && is_array( $input['tie_breakers'] ) ) {
			foreach ( $input['tie_breakers'] as $value ) {
				$value = is_string( $value ) ? sanitize_key( $value ) : '';
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

	/** Names shared by the defaults and per-sport scoring editors. */
	public static function scoring_number_labels() {
		return array(
			'points_win'           => __( 'Points for a win', 'leagueflow' ),
			'points_draw'          => __( 'Points for a draw (tie)', 'leagueflow' ),
			'points_loss'          => __( 'Points for a loss', 'leagueflow' ),
			'forfeit_score_winner' => __( 'Forfeit walkover score', 'leagueflow' ),
		);
	}

	/**
	 * Validate a submitted override before replacing its saved values.
	 * Blank numeric values and an empty tie-breaker order mean inheritance.
	 *
	 * @param mixed $values Submitted override.
	 * @return array<int, string> Validation errors.
	 */
	public static function validate_rule_override( $values ) {
		if ( ! is_array( $values ) ) {
			return array( __( 'Scoring values must be submitted as a form.', 'leagueflow' ) );
		}
		$errors = array();
		foreach ( self::scoring_number_labels() as $key => $label ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = is_string( $values[ $key ] ) ? trim( $values[ $key ] ) : $values[ $key ];
			if ( '' !== $value && ! Match_Status::is_valid_score( $value ) ) {
				$errors[] = sprintf( /* translators: %s: scoring setting */ __( '%s must be a non-negative whole number, or blank to inherit.', 'leagueflow' ), $label );
			}
		}
		if ( array_key_exists( 'tie_breakers', $values ) ) {
			if ( ! is_array( $values['tie_breakers'] ) ) {
				$errors[] = __( 'Choose a valid tie-breaker order.', 'leagueflow' );
			} else {
				$seen = array();
				foreach ( $values['tie_breakers'] as $value ) {
					if ( '' === $value ) { continue; }
					if ( ! is_string( $value ) || ! in_array( $value, tie_breaker_keys(), true ) || in_array( $value, $seen, true ) || count( $seen ) >= 4 ) {
						$errors[] = __( 'Choose each tie-breaker at most once, with no more than four priorities.', 'leagueflow' );
						break;
					}
					$seen[] = $value;
				}
			}
		}
		return $errors;
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
		echo '<p>' . esc_html__( 'Default scoring for every sport: table points per result and how ties on points are broken. A sport or competition can use different values (see "Scoring by sport and competition"); until then it uses these.', 'leagueflow' ) . '</p>';
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
	 * Render scheduling section description.
	 *
	 * @return void
	 */
	public function render_scheduling_section() {
		echo '<p>' . esc_html__( 'Tune how the auto-scheduler spreads fixtures. All limits are optional; leave a value at 0 to keep the previous first-fit behavior.', 'leagueflow' ) . '</p>';
	}

	/**
	 * Render registration section description.
	 *
	 * @return void
	 */
	public function render_registration_section() {
		echo '<p>' . esc_html__( 'Open captain registration first for team creation. After the team-building window, close captain registration and open player registration.', 'leagueflow' ) . '</p>';
	}

	/** Configure the approved documents; no default waiver language is invented. */
	public function render_registration_documents() {
		$documents = Registration_Consent::sanitize_documents( get_setting( 'registration_documents', array() ) );
		echo '<p class="description">' . esc_html__( 'Add your approved documents, then require each acknowledgement. A document is optional until its requirement is enabled. Changing its version, text or URL asks existing players to accept again.', 'leagueflow' ) . '</p>';
		foreach ( Registration_Consent::labels() as $key => $label ) {
			$document = $documents[ $key ];
			$prefix = 'leagueflow_settings[registration_documents][' . $key . ']';
			$id = 'leagueflow-document-' . $key;
			echo '<fieldset class="leagueflow-registration-document"><legend><strong>' . esc_html( $label ) . '</strong></legend>';
			printf( '<p><label><input type="checkbox" name="%1$s[required]" value="1" %2$s /> %3$s</label></p>', esc_attr( $prefix ), checked( $document['required'], 1, false ), esc_html__( 'Require personal acknowledgement during registration', 'leagueflow' ) );
			foreach ( array( 'title' => __( 'Document title', 'leagueflow' ), 'version' => __( 'Version or effective date', 'leagueflow' ), 'url' => __( 'Document URL (optional when text is supplied)', 'leagueflow' ) ) as $field => $field_label ) {
				printf( '<p><label for="%1$s-%2$s">%3$s</label><br /><input class="large-text" id="%1$s-%2$s" name="%4$s[%2$s]" type="%5$s" value="%6$s" /></p>', esc_attr( $id ), esc_attr( $field ), esc_html( $field_label ), esc_attr( $prefix ), 'url' === $field ? 'url' : 'text', esc_attr( $document[ $field ] ) );
			}
			printf( '<p><label for="%1$s-text">%2$s</label><br /><textarea class="large-text" rows="7" id="%1$s-text" name="%3$s[text]">%4$s</textarea></p>', esc_attr( $id ), esc_html__( 'Approved document text (basic HTML allowed)', 'leagueflow' ), esc_attr( $prefix ), esc_textarea( $document['text'] ) );
			echo '</fieldset>';
		}
		echo '<p class="description">' . esc_html__( 'The acceptance record keeps the text, URL, version, account and time. Linked file contents are not copied: use a new, versioned document URL when replacing a PDF. Captains and staff cannot acknowledge for another player.', 'leagueflow' ) . '</p>';
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
			'<input type="number" class="small-text" min="%1$d" id="leagueflow_setting_%2$s" name="leagueflow_settings[%2$s]" value="%3$s" />',
			(int) $minimum,
			esc_attr( $key ),
			esc_attr( (string) $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $args['description'] ) );
		}
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
		$type        = isset( $args['type'] ) && in_array( $args['type'], array( 'text', 'email', 'url' ), true ) ? $args['type'] : 'text';

		printf(
			'<input type="%4$s" class="regular-text" id="leagueflow_setting_%1$s" name="leagueflow_settings[%1$s]" value="%2$s" placeholder="%3$s" />',
			esc_attr( $key ),
			esc_attr( (string) $value ),
			esc_attr( (string) $placeholder ),
			esc_attr( $type )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $args['description'] ) );
		}
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
		// Every checkbox names its purpose; the row title is a <label for> via label_for.
		$label = isset( $args['label'] ) ? (string) $args['label'] : '';

		printf(
			'<label><input type="checkbox" id="leagueflow_setting_%1$s" name="leagueflow_settings[%1$s]" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( $value, 1, false ),
			esc_html( $label )
		);
	}

	/**
	 * Tie-breaker keys and sport-neutral labels.
	 *
	 * @return array<string, string>
	 */
	public function get_tie_breaker_labels() {
		return $this->tie_breakers;
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
