<?php
/**
 * League table template.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var bool                             $show_logos
 * @var array<string, string>            $table_labels
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-table-wrap">
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No standings are available yet.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<table class="leagueflow-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '#', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'P', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'W', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'D', 'leagueflow' ); ?></th>
					<th><?php esc_html_e( 'L', 'leagueflow' ); ?></th>
					<th><?php echo esc_html( $table_labels['for'] ); ?></th>
					<th><?php echo esc_html( $table_labels['against'] ); ?></th>
					<th><?php echo esc_html( $table_labels['difference'] ); ?></th>
					<th><?php esc_html_e( 'Pts', 'leagueflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['position'] ); ?></td>
						<td class="leagueflow-table__team">
							<?php if ( $show_logos && ! empty( $row['logo'] ) ) : ?>
								<span class="leagueflow-table__logo-wrap"><?php echo wp_kses_post( $row['logo'] ); ?></span>
							<?php endif; ?>
							<a href="<?php echo esc_url( $row['permalink'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
						</td>
						<td><?php echo esc_html( (string) $row['played'] ); ?></td>
						<td><?php echo esc_html( (string) $row['wins'] ); ?></td>
						<td><?php echo esc_html( (string) $row['draws'] ); ?></td>
						<td><?php echo esc_html( (string) $row['losses'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goals_for'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goals_against'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goal_difference'] ); ?></td>
						<td>
							<strong><?php echo esc_html( (string) $row['points'] ); ?></strong>
							<?php if ( ! empty( $row['adjustment'] ) ) : ?>
								<span class="leagueflow-table__adjustment"<?php echo ! empty( $row['adjustment_note'] ) ? ' title="' . esc_attr( (string) $row['adjustment_note'] ) . '"' : ''; ?>><?php echo esc_html( sprintf( '%+d', (int) $row['adjustment'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
