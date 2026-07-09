<?php
/**
 * Multi-sport standings template.
 *
 * @var array<int, array<string, mixed>> $sections
 * @var bool                             $show_logos
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-sport-standings">
	<?php if ( empty( $sections ) ) : ?>
		<p><?php esc_html_e( 'No sports are enabled yet.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<?php foreach ( $sections as $section ) : ?>
			<details class="leagueflow-sport-standings__panel">
				<summary class="leagueflow-sport-standings__summary">
					<span class="leagueflow-sport-standings__label"><?php echo esc_html( $section['label'] ); ?></span>
					<span class="leagueflow-sport-standings__meta">
						<?php
						printf(
							/* translators: %d: number of teams. */
							esc_html( _n( '%d team', '%d teams', count( $section['rows'] ), 'leagueflow' ) ),
							absint( count( $section['rows'] ) )
						);
						?>
					</span>
				</summary>

				<div class="leagueflow-sport-standings__body">
					<?php
					$rows         = $section['rows'];
					$table_labels = $section['table_labels'];
					include LEAGUEFLOW_PATH . 'templates/league-table.php';
					?>
				</div>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
