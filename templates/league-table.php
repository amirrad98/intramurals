<?php
/**
 * League table template.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var bool                             $show_logos
 * @var array<string, string>            $table_labels
 */

defined( 'ABSPATH' ) || exit;
$show_draws = (bool) array_filter( $rows, static function( $row ) { return ! empty( $row['draws'] ); } );
?>
<div class="leagueflow leagueflow-table-wrap" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'League standings', 'leagueflow' ); ?>">
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No teams or completed league results match this sport, season and level yet.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<?php
		// Columns are named, so themes hide the detail columns on small screens
		// by meaning rather than position (Draws only appears when needed).
		$col = static function ( $name, $extra = '' ) {
			return 'leagueflow-table__col leagueflow-table__col--' . $name . ( $extra ? ' ' . $extra : '' );
		};
		?>
		<table class="leagueflow-table" aria-label="<?php esc_attr_e( 'League standings', 'leagueflow' ); ?>">
			<thead>
				<tr>
					<th scope="col" class="<?php echo esc_attr( $col( 'pos' ) ); ?>"><abbr title="<?php esc_attr_e( 'Position', 'leagueflow' ); ?>"><?php esc_html_e( '#', 'leagueflow' ); ?></abbr></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'team' ) ); ?>"><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'played', 'is-number' ) ); ?>"><abbr title="<?php esc_attr_e( 'Played', 'leagueflow' ); ?>"><?php esc_html_e( 'P', 'leagueflow' ); ?></abbr></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'won', 'is-number' ) ); ?>"><abbr title="<?php esc_attr_e( 'Won', 'leagueflow' ); ?>"><?php esc_html_e( 'W', 'leagueflow' ); ?></abbr></th>
					<?php if ( $show_draws ) : ?><th scope="col" class="<?php echo esc_attr( $col( 'drawn', 'is-number' ) ); ?>"><abbr title="<?php esc_attr_e( 'Drawn (tied)', 'leagueflow' ); ?>"><?php esc_html_e( 'D', 'leagueflow' ); ?></abbr></th><?php endif; ?>
					<th scope="col" class="<?php echo esc_attr( $col( 'lost', 'is-number' ) ); ?>"><abbr title="<?php esc_attr_e( 'Lost', 'leagueflow' ); ?>"><?php esc_html_e( 'L', 'leagueflow' ); ?></abbr></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'for', 'is-number is-detail' ) ); ?>"><?php echo esc_html( $table_labels['for'] ); ?></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'against', 'is-number is-detail' ) ); ?>"><?php echo esc_html( $table_labels['against'] ); ?></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'diff', 'is-number is-diff' ) ); ?>"><?php echo esc_html( $table_labels['difference'] ); ?></th>
					<th scope="col" class="<?php echo esc_attr( $col( 'points', 'is-number' ) ); ?>"><abbr title="<?php esc_attr_e( 'Table points', 'leagueflow' ); ?>"><?php esc_html_e( 'Pts', 'leagueflow' ); ?></abbr></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td class="<?php echo esc_attr( $col( 'pos' ) ); ?>"><?php echo esc_html( (string) $row['position'] ); ?></td>
						<td class="<?php echo esc_attr( $col( 'team', 'leagueflow-table__team' ) ); ?>">
							<span class="leagueflow-table__team-inner">
								<?php if ( $show_logos && ! empty( $row['logo'] ) ) : ?>
									<span class="leagueflow-table__logo-wrap"><?php echo wp_kses_post( $row['logo'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $row['permalink'] ) ) : ?>
									<a href="<?php echo esc_url( $row['permalink'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
								<?php else : ?>
									<span><?php echo esc_html( $row['name'] ); ?></span>
								<?php endif; ?>
							</span>
						</td>
						<td class="<?php echo esc_attr( $col( 'played', 'is-number' ) ); ?>"><?php echo esc_html( (string) $row['played'] ); ?></td>
						<td class="<?php echo esc_attr( $col( 'won', 'is-number' ) ); ?>"><?php echo esc_html( (string) $row['wins'] ); ?></td>
						<?php if ( $show_draws ) : ?><td class="<?php echo esc_attr( $col( 'drawn', 'is-number' ) ); ?>"><?php echo esc_html( (string) $row['draws'] ); ?></td><?php endif; ?>
						<td class="<?php echo esc_attr( $col( 'lost', 'is-number' ) ); ?>"><?php echo esc_html( (string) $row['losses'] ); ?></td>
						<td class="<?php echo esc_attr( $col( 'for', 'is-number is-detail' ) ); ?>"><?php echo esc_html( (string) $row['goals_for'] ); ?></td>
						<td class="<?php echo esc_attr( $col( 'against', 'is-number is-detail' ) ); ?>"><?php echo esc_html( (string) $row['goals_against'] ); ?></td>
						<td class="<?php echo esc_attr( $col( 'diff', 'is-number is-diff' ) ); ?>"><?php echo esc_html( ( (int) $row['goal_difference'] > 0 ? '+' : '' ) . (string) $row['goal_difference'] ); ?></td>
						<td class="<?php echo esc_attr( $col( 'points', 'is-number' ) ); ?>">
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
