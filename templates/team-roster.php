<?php
/**
 * Team roster template.
 *
 * @var array<int, array<string, mixed>> $players
 * @var bool                             $show_photos
 * @var string                           $team_name
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-roster">
	<?php if ( empty( $players ) ) : ?>
		<p><?php esc_html_e( 'This team has no players assigned yet.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<table class="leagueflow-roster-table">
			<thead>
				<tr>
					<?php if ( $show_photos ) : ?><th><?php esc_html_e( 'Photo', 'leagueflow' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'Player', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'No.', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Position', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Age', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Nationality', 'leagueflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $players as $player ) : ?>
					<tr>
						<?php if ( $show_photos ) : ?>
							<td class="leagueflow-roster-table__photo"><?php echo ! empty( $player['photo'] ) ? wp_kses_post( $player['photo'] ) : '&mdash;'; ?></td>
						<?php endif; ?>
						<td>
							<?php echo esc_html( $player['name'] ); ?>
							<?php if ( ! empty( $player['is_captain'] ) ) : ?>
								<span class="leagueflow-badge"><?php esc_html_e( 'Captain', 'leagueflow' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $player['jersey_number'] ); ?></td>
						<td><?php echo esc_html( (string) $player['position'] ); ?></td>
						<td><?php echo esc_html( (string) $player['age'] ); ?></td>
						<td><?php echo esc_html( (string) $player['nationality'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
