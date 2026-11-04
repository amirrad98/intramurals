<?php
/**
 * Versioned, personal registration acknowledgements.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the documents accepted by a participant separate from roster changes.
 * Staff and captains cannot accept documents on somebody else's behalf.
 */
class Registration_Consent {
	const META_KEY = '_lf_registration_acceptance';

	/** Register the private, read-only staff evidence panel. */
	public function register() {
		add_action( 'add_meta_boxes_lf_player', array( $this, 'register_meta_box' ) );
	}

	/** Fixed policy types; the actual approved documents are supplied by staff. */
	public static function labels() {
		return array(
			'waiver' => __( 'Participation waiver', 'leagueflow' ),
			'conduct' => __( 'Intramural Code of Conduct', 'leagueflow' ),
		);
	}

	/** Sanitize configuration without silently dropping an enabled requirement. */
	public static function sanitize_documents( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( self::labels() as $key => $label ) {
			$raw = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$scalar = static function ( $name ) use ( $raw ) {
				return isset( $raw[ $name ] ) && is_scalar( $raw[ $name ] ) ? (string) $raw[ $name ] : '';
			};
			$out[ $key ] = array(
				'required' => '1' === $scalar( 'required' ) ? 1 : 0,
				'title'    => sanitize_text_field( $scalar( 'title' ) ) ?: $label,
				'version'  => sanitize_text_field( $scalar( 'version' ) ),
				'url'      => esc_url_raw( $scalar( 'url' ), array( 'https', 'http' ) ),
				'text'     => wp_kses_post( $scalar( 'text' ) ),
			);
		}
		return $out;
	}

	/** Required documents, including invalid configuration so failures stay closed. */
	public static function documents() {
		$documents = self::sanitize_documents( get_setting( 'registration_documents', array() ) );
		foreach ( $documents as $key => &$document ) {
			$document['key'] = $key;
			$document['hash'] = hash( 'sha256', wp_json_encode( $document ) );
			$document['valid'] = '' !== $document['version'] && ( '' !== trim( wp_strip_all_tags( $document['text'] ) ) || '' !== $document['url'] );
		}
		unset( $document );
		return array_filter( $documents, static function ( $document ) { return 1 === $document['required']; } );
	}

	/** Read append-only evidence; private meta is never registered for REST exposure. */
	public static function records( $player_id ) {
		return array_values( array_filter( (array) get_post_meta( absint( $player_id ), self::META_KEY, false ), 'is_array' ) );
	}

	/** Documents that this authenticated identity has not accepted in this revision. */
	public static function pending( $player_id, $user_id ) {
		$documents = self::documents();
		if ( ! $player_id || ! $user_id || absint( get_post_meta( $player_id, 'lf_user_id', true ) ) !== absint( $user_id ) ) {
			return $documents;
		}
		foreach ( self::records( $player_id ) as $record ) {
			if ( absint( $record['user_id'] ?? 0 ) !== absint( $user_id ) ) {
				continue;
			}
			foreach ( (array) ( $record['documents'] ?? array() ) as $accepted ) {
				$key = is_array( $accepted ) ? ( $accepted['key'] ?? '' ) : '';
				if ( isset( $documents[ $key ] ) && ! empty( $documents[ $key ]['valid'] ) && hash_equals( $documents[ $key ]['hash'], (string) ( $accepted['hash'] ?? '' ) ) ) {
					unset( $documents[ $key ] );
				}
			}
		}
		return $documents;
	}

	/** Current status; this reports acknowledgement, not medical or playing eligibility. */
	public static function is_current( $player_id ) {
		return ! self::pending( $player_id, absint( get_post_meta( absint( $player_id ), 'lf_user_id', true ) ) );
	}

	/** Validate every checkbox and revision before any registration data is changed. */
	public static function validate_submission( $player_id, $user_id, $post ) {
		if ( ! $user_id || absint( $user_id ) !== get_current_user_id() ) {
			return new \WP_Error( 'consent-required', __( 'Sign in to accept your registration documents.', 'leagueflow' ) );
		}
		$documents = self::pending( $player_id, $user_id );
		$checks = isset( $post['lf_consent'] ) && is_array( $post['lf_consent'] ) ? wp_unslash( $post['lf_consent'] ) : array();
		$hashes = isset( $post['lf_consent_version'] ) && is_array( $post['lf_consent_version'] ) ? wp_unslash( $post['lf_consent_version'] ) : array();
		foreach ( $documents as $key => $document ) {
			if ( ! $document['valid'] ) {
				return new \WP_Error( 'consent-unavailable', __( 'A required registration document is not ready. Please contact recreation staff.', 'leagueflow' ) );
			}
			if ( ! isset( $hashes[ $key ] ) || ! is_string( $hashes[ $key ] ) || ! hash_equals( $document['hash'], $hashes[ $key ] ) ) {
				return new \WP_Error( 'consent-updated', __( 'The registration documents changed or were not loaded. Review the current documents and submit again.', 'leagueflow' ) );
			}
			if ( ! isset( $checks[ $key ] ) || ! is_scalar( $checks[ $key ] ) || '1' !== (string) $checks[ $key ] ) {
				return new \WP_Error( 'consent-required', __( 'Review and accept each required registration document.', 'leagueflow' ) );
			}
		}
		return $documents;
	}

	/**
	 * Append one atomic evidence row for the validated document bundle.
	 * No API updates an earlier row. Submitted HTML, user IDs and timestamps are ignored.
	 */
	public static function record( $player_id, $user_id, $documents ) {
		$pending = self::pending( $player_id, $user_id );
		if ( ! is_array( $documents ) || array_diff_key( $pending, $documents ) ) {
			return new \WP_Error( 'consent-updated', __( 'The registration documents changed. Review them again.', 'leagueflow' ) );
		}
		if ( ! $documents ) {
			return true;
		}
		if ( ! is_array( $documents ) || get_current_user_id() !== absint( $user_id ) || 'lf_player' !== get_post_type( $player_id ) || absint( get_post_meta( $player_id, 'lf_user_id', true ) ) !== absint( $user_id ) ) {
			return new \WP_Error( 'consent-save-error', __( 'The acknowledgement could not be saved for your profile.', 'leagueflow' ) );
		}
		$current = self::documents();
		foreach ( $documents as $key => $document ) {
			if ( ! isset( $current[ $key ] ) || ! $current[ $key ]['valid'] || ! isset( $document['hash'] ) || ! hash_equals( $current[ $key ]['hash'], $document['hash'] ) ) {
				return new \WP_Error( 'consent-updated', __( 'The registration documents changed. Review them again.', 'leagueflow' ) );
			}
			$documents[ $key ] = $current[ $key ];
		}
		$pending = self::pending( $player_id, $user_id );
		$documents = array_intersect_key( $documents, $pending );
		if ( ! $documents ) {
			return true;
		}
		$record = array(
			'user_id'     => absint( $user_id ),
			'player_id'   => absint( $player_id ),
			'accepted_at' => gmdate( 'Y-m-d H:i:s' ),
			'documents'   => array_values( $documents ),
		);
		return add_post_meta( $player_id, self::META_KEY, wp_slash( $record ) )
			? true : new \WP_Error( 'consent-save-error', __( 'The acknowledgement could not be saved. Please try again.', 'leagueflow' ) );
	}

	/** Render accessible unchecked personal acknowledgements inside a form. */
	public static function render_fields( $player_id, $user_id ) {
		$documents = self::pending( $player_id, $user_id );
		if ( ! $documents ) {
			return '';
		}
		ob_start();
		?>
		<fieldset class="leagueflow-portal__consent">
			<legend><?php esc_html_e( 'Registration documents', 'leagueflow' ); ?></legend>
			<p><?php esc_html_e( 'Read each document and acknowledge it for yourself. Team registration does not accept documents for other players.', 'leagueflow' ); ?></p>
			<?php foreach ( $documents as $key => $document ) : ?>
				<?php if ( ! $document['valid'] ) : ?>
					<p role="alert"><?php echo esc_html( sprintf( __( '%s is not ready. Please contact recreation staff before registering.', 'leagueflow' ), $document['title'] ) ); ?></p>
					<?php continue; ?>
				<?php endif; ?>
				<div class="leagueflow-portal__consent-document">
					<h4><?php echo esc_html( $document['title'] ); ?> <small><?php echo esc_html( sprintf( __( '(version %s)', 'leagueflow' ), $document['version'] ) ); ?></small></h4>
					<?php if ( $document['text'] ) : ?><details><summary><?php esc_html_e( 'Read document', 'leagueflow' ); ?></summary><div><?php echo wp_kses_post( wpautop( $document['text'] ) ); ?></div></details><?php endif; ?>
					<?php if ( $document['url'] ) : ?><p><a href="<?php echo esc_url( $document['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( __( 'Open %s (new tab)', 'leagueflow' ), $document['title'] ) ); ?></a></p><?php endif; ?>
					<input type="hidden" name="lf_consent_version[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $document['hash'] ); ?>" />
					<label class="leagueflow-portal__consent-check"><input type="checkbox" name="lf_consent[<?php echo esc_attr( $key ); ?>]" value="1" required /> <span><?php echo esc_html( sprintf( __( 'I have read and accept %s.', 'leagueflow' ), $document['title'] ) ); ?></span></label>
				</div>
			<?php endforeach; ?>
		</fieldset>
		<?php
		return (string) ob_get_clean();
	}

	/** Register a read-only player audit panel. */
	public function register_meta_box() {
		add_meta_box( 'leagueflow-registration-consent', __( 'Registration acknowledgements', 'leagueflow' ), array( $this, 'render_meta_box' ), 'lf_player', 'normal', 'default' );
	}

	/** Display evidence only to an editor who can access this player. */
	public function render_meta_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$documents = self::documents();
		echo '<p>' . esc_html( ! $documents ? __( 'No required registration documents are configured.', 'leagueflow' ) : ( self::is_current( $post->ID ) ? __( 'The player has acknowledged the current documents.', 'leagueflow' ) : __( 'The player needs to acknowledge the current documents in their portal. Staff cannot accept for them.', 'leagueflow' ) ) ) . '</p>';
		foreach ( array_reverse( self::records( $post->ID ) ) as $record ) {
			echo '<details><summary>' . esc_html( sprintf( __( 'Accepted %1$s UTC by account #%2$d', 'leagueflow' ), $record['accepted_at'] ?? '', absint( $record['user_id'] ?? 0 ) ) ) . '</summary>';
			foreach ( (array) ( $record['documents'] ?? array() ) as $document ) {
				if ( ! is_array( $document ) ) { continue; }
				echo '<h4>' . esc_html( ( $document['title'] ?? '' ) . ' — ' . ( $document['version'] ?? '' ) ) . '</h4>';
				if ( ! empty( $document['url'] ) ) { echo '<p><a href="' . esc_url( $document['url'] ) . '">' . esc_html( $document['url'] ) . '</a></p>'; }
				if ( ! empty( $document['text'] ) ) { echo '<div>' . wp_kses_post( wpautop( $document['text'] ) ) . '</div>'; }
				echo '<p><code>' . esc_html( $document['hash'] ?? '' ) . '</code></p>';
			}
			echo '</details>';
		}
	}
}
