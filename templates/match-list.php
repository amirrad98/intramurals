<?php
/**
 * Match list template.
 *
 * @var array<int, array<string, mixed>> $matches
 */

defined( 'ABSPATH' ) || exit;

use function LeagueFlow\render_match_card;
?>
<div class="leagueflow leagueflow-match-list">
	<?php if ( empty( $matches ) ) : ?>
		<p><?php esc_html_e( 'No fixtures were found for this view.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<div class="leagueflow-match-stack">
			<?php foreach ( $matches as $match ) : ?>
				<?php echo render_match_card( $match ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by render_match_card(). ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
