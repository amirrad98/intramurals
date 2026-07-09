<?php
/**
 * Knockout bracket template.
 *
 * @var array<int, array<string, mixed>>          $rounds Round-grouped fixtures (fallback layout).
 * @var array{linked: bool, roots: array<int, array<string, mixed>>} $tree Connected bracket tree.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'leagueflow_render_bracket_node' ) ) {
	/**
	 * Recursively render a bracket node and the matches that feed it.
	 *
	 * @param array<string, mixed> $node Bracket node.
	 * @return void
	 */
	function leagueflow_render_bracket_node( $node ) {
		$has_children = ! empty( $node['children'] );
		$home_winner  = (int) $node['winner_team_id'] && (int) $node['winner_team_id'] === (int) $node['home_team_id'];
		$away_winner  = (int) $node['winner_team_id'] && (int) $node['winner_team_id'] === (int) $node['away_team_id'];
		?>
		<div class="lf-node<?php echo $has_children ? ' has-kids' : ''; ?>">
			<div class="lf-node__game">
				<article class="leagueflow-bracket__match leagueflow-bracket-tree__match">
					<?php if ( ! empty( $node['round_label'] ) ) : ?>
						<span class="leagueflow-bracket-tree__round"><?php echo esc_html( $node['round_label'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $node['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $node['datetime_raw'] ); ?>"><?php echo esc_html( $node['datetime'] ); ?></time><?php endif; ?>
					<div class="leagueflow-bracket__team<?php echo $home_winner ? ' is-winner' : ''; ?>">
						<span><?php echo esc_html( $node['home_team'] ? $node['home_team'] : __( 'TBD', 'leagueflow' ) ); ?></span>
						<strong><?php echo has_score( $node['home_score'] ) ? esc_html( (string) score_to_int( $node['home_score'] ) ) : '&mdash;'; ?></strong>
					</div>
					<?php if ( ! empty( $node['is_bye'] ) ) : ?>
						<div class="leagueflow-bracket__team leagueflow-bracket__team--bye">
							<span><?php esc_html_e( 'Bye', 'leagueflow' ); ?></span>
							<strong>&mdash;</strong>
						</div>
					<?php else : ?>
						<div class="leagueflow-bracket__team<?php echo $away_winner ? ' is-winner' : ''; ?>">
							<span><?php echo esc_html( $node['away_team'] ? $node['away_team'] : __( 'TBD', 'leagueflow' ) ); ?></span>
							<strong><?php echo has_score( $node['away_score'] ) ? esc_html( (string) score_to_int( $node['away_score'] ) ) : '&mdash;'; ?></strong>
						</div>
					<?php endif; ?>
					<p><a href="<?php echo esc_url( $node['permalink'] ); ?>"><?php esc_html_e( 'View match', 'leagueflow' ); ?></a></p>
				</article>
			</div>
			<?php if ( $has_children ) : ?>
				<div class="lf-node__kids">
					<?php foreach ( $node['children'] as $child ) : ?>
						<?php leagueflow_render_bracket_node( $child ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

$tree      = isset( $tree ) && is_array( $tree ) ? $tree : array( 'linked' => false, 'roots' => array() );
$use_tree  = ! empty( $tree['linked'] ) && ! empty( $tree['roots'] );
?>
<div class="leagueflow leagueflow-bracket-wrap">
	<?php if ( empty( $rounds ) && empty( $tree['roots'] ) ) : ?>
		<p><?php esc_html_e( 'No knockout fixtures are available yet.', 'leagueflow' ); ?></p>
	<?php elseif ( $use_tree ) : ?>
		<div class="leagueflow-bracket-tree">
			<?php foreach ( $tree['roots'] as $root ) : ?>
				<?php leagueflow_render_bracket_node( $root ); ?>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="leagueflow-bracket">
			<?php foreach ( $rounds as $round ) : ?>
				<section class="leagueflow-bracket__round">
					<h3><?php echo esc_html( $round['label'] ); ?></h3>
					<?php foreach ( $round['matches'] as $match ) : ?>
						<article class="leagueflow-bracket__match">
							<?php if ( ! empty( $match['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time><?php endif; ?>
							<div class="leagueflow-bracket__team<?php echo (int) $match['winner_team_id'] === (int) $match['home_team_id'] ? ' is-winner' : ''; ?>">
								<span><?php echo esc_html( $match['home_team'] ? $match['home_team'] : __( 'TBD', 'leagueflow' ) ); ?></span>
								<strong><?php echo has_score( $match['home_score'] ) ? esc_html( (string) score_to_int( $match['home_score'] ) ) : '&mdash;'; ?></strong>
							</div>
							<div class="leagueflow-bracket__team<?php echo (int) $match['winner_team_id'] === (int) $match['away_team_id'] ? ' is-winner' : ''; ?>">
								<span><?php echo esc_html( $match['away_team'] ? $match['away_team'] : __( 'TBD', 'leagueflow' ) ); ?></span>
								<strong><?php echo has_score( $match['away_score'] ) ? esc_html( (string) score_to_int( $match['away_score'] ) ) : '&mdash;'; ?></strong>
							</div>
							<p><a href="<?php echo esc_url( $match['permalink'] ); ?>"><?php esc_html_e( 'View match', 'leagueflow' ); ?></a></p>
						</article>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
