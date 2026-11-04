<?php
/**
 * Match calendar template.
 *
 * @var array<string, mixed>             $payload    Calendar data for the frontend script.
 * @var array<int, array<string, mixed>> $sports     Sports present in the schedule.
 * @var array<int, array<string, mixed>> $types      Event types present in the schedule.
 * @var bool                             $show_chips Whether to render the sport filter.
 * @var bool                             $show_types Whether to render the type filter.
 * @var array<int, array<string, mixed>> $items      Flat schedule list for the no-JS fallback.
 */

defined( 'ABSPATH' ) || exit;
$calendar_id = wp_unique_id( 'leagueflow-calendar-' );
$wrapper_attributes = 'leagueflow/match-calendar' === ( \WP_Block_Supports::$block_to_render['blockName'] ?? '' )
	? get_block_wrapper_attributes( array( 'class' => 'leagueflow leagueflow-calendar', 'id' => $calendar_id ) )
	: 'class="leagueflow leagueflow-calendar" id="' . esc_attr( $calendar_id ) . '"';
?>
<div <?php echo $wrapper_attributes; ?> data-leagueflow-calendar>
	<div class="leagueflow-calendar__toolbar">
		<div class="leagueflow-calendar__view-tabs" role="group" aria-label="<?php esc_attr_e( 'Calendar view', 'leagueflow' ); ?>">
			<button type="button" class="leagueflow-calendar__view is-active" data-calendar-view="month" aria-pressed="true"><?php esc_html_e( 'Month', 'leagueflow' ); ?></button>
			<button type="button" class="leagueflow-calendar__view" data-calendar-view="list" aria-pressed="false"><?php esc_html_e( 'List', 'leagueflow' ); ?></button>
			<?php if ( ! empty( $payload['config']['showWeek'] ) ) : ?>
				<button type="button" class="leagueflow-calendar__view" data-calendar-view="week" aria-pressed="false"><?php esc_html_e( 'Week', 'leagueflow' ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $payload['config']['showDay'] ) ) : ?>
				<button type="button" class="leagueflow-calendar__view" data-calendar-view="day" aria-pressed="false"><?php esc_html_e( 'Day', 'leagueflow' ); ?></button>
			<?php endif; ?>
		</div>

		<label class="leagueflow-calendar__search">
			<span class="screen-reader-text"><?php esc_html_e( 'Search schedule', 'leagueflow' ); ?></span>
			<input type="search" data-calendar-search placeholder="<?php esc_attr_e( 'Search schedule', 'leagueflow' ); ?>" />
		</label>
	</div>

	<div class="leagueflow-calendar__filters">
		<?php if ( $show_chips ) : ?>
			<div class="leagueflow-calendar__chips" role="group" data-calendar-sport-filters <?php echo count( $sports ) <= 1 ? 'hidden' : ''; ?> aria-label="<?php esc_attr_e( 'Filter schedule by sport', 'leagueflow' ); ?>">
				<button type="button" class="leagueflow-calendar__chip is-active" data-sport="" aria-pressed="true">
					<?php esc_html_e( 'All sports', 'leagueflow' ); ?>
				</button>
				<?php foreach ( $sports as $sport ) : ?>
					<button type="button" class="leagueflow-calendar__chip" data-sport="<?php echo esc_attr( $sport['slug'] ); ?>" aria-pressed="false" style="--lf-cal-accent: <?php echo esc_attr( $sport['color'] ); ?>;">
						<span class="leagueflow-calendar__chip-dot" aria-hidden="true"></span>
						<?php echo esc_html( $sport['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

			<div class="leagueflow-calendar__chips leagueflow-calendar__chips--types" role="group" data-calendar-type-filters <?php echo count( $types ) <= 1 ? 'hidden' : ''; ?> aria-label="<?php esc_attr_e( 'Filter schedule by type', 'leagueflow' ); ?>">
				<button type="button" class="leagueflow-calendar__chip is-active" data-type="" aria-pressed="true">
					<?php esc_html_e( 'All types', 'leagueflow' ); ?>
				</button>
				<?php foreach ( $types as $type ) : ?>
					<button type="button" class="leagueflow-calendar__chip" data-type="<?php echo esc_attr( $type['slug'] ); ?>" aria-pressed="false">
						<?php echo esc_html( $type['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
	</div>

	<div class="leagueflow-calendar__layout">
		<section class="leagueflow-calendar__board">
			<header class="leagueflow-calendar__header">
				<h3 class="leagueflow-calendar__month" data-calendar-range-label>&nbsp;</h3>
				<div class="leagueflow-calendar__nav">
					<label class="leagueflow-calendar__jump"><span class="screen-reader-text"><?php esc_html_e( 'Jump to date', 'leagueflow' ); ?></span><input type="date" data-calendar-jump aria-label="<?php esc_attr_e( 'Jump to date', 'leagueflow' ); ?>" value="<?php echo esc_attr( $payload['config']['today'] ); ?>" /></label>
					<button type="button" class="leagueflow-calendar__nav-btn" data-calendar-today><?php esc_html_e( 'Today', 'leagueflow' ); ?></button>
					<button type="button" class="leagueflow-calendar__nav-btn" data-calendar-prev aria-label="<?php esc_attr_e( 'Previous', 'leagueflow' ); ?>">&lsaquo;</button>
					<button type="button" class="leagueflow-calendar__nav-btn" data-calendar-next aria-label="<?php esc_attr_e( 'Next', 'leagueflow' ); ?>">&rsaquo;</button>
				</div>
			</header>
			<div class="leagueflow-calendar__stage" data-calendar-stage></div>
		</section>
		<aside class="leagueflow-calendar__panel" data-calendar-panel>
			<div class="leagueflow-calendar__panel-head">
				<h3 class="leagueflow-calendar__panel-title" id="<?php echo esc_attr( $calendar_id . '-panel-title' ); ?>" data-calendar-panel-title>&nbsp;</h3>
				<button type="button" class="leagueflow-calendar__panel-clear" data-calendar-clear hidden><?php esc_html_e( 'Show full month', 'leagueflow' ); ?></button>
				<button type="button" class="leagueflow-calendar__panel-clear" data-calendar-panel-close hidden><?php esc_html_e( 'Close day', 'leagueflow' ); ?></button>
			</div>
			<div class="leagueflow-calendar__events" data-calendar-events aria-live="polite"></div>
		</aside>
	</div>

	<div class="leagueflow-calendar__pagination" data-calendar-pagination <?php echo empty( $payload['pagination']['nextPage'] ) ? 'hidden' : ''; ?>>
		<p data-calendar-progress role="status"></p>
		<button type="button" class="leagueflow-calendar__load-more" data-calendar-load-page><?php esc_html_e( 'Load next schedule page', 'leagueflow' ); ?></button>
	</div>

	<div class="leagueflow-calendar__dialog" data-calendar-dialog hidden>
		<div class="leagueflow-calendar__dialog-backdrop" data-calendar-dialog-close></div>
		<article class="leagueflow-calendar__dialog-card" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $calendar_id . '-dialog-title' ); ?>">
			<button type="button" class="leagueflow-calendar__dialog-close" data-calendar-dialog-close aria-label="<?php esc_attr_e( 'Close', 'leagueflow' ); ?>">&times;</button>
			<div data-calendar-dialog-content></div>
		</article>
	</div>

	<noscript>
		<div class="leagueflow-calendar__fallback">
			<p><?php esc_html_e( 'The interactive calendar needs JavaScript. The first page of the initial date range is listed below. Enable JavaScript to change dates, filter, and load additional events.', 'leagueflow' ); ?></p>
			<div class="leagueflow-match-stack">
				<?php foreach ( $items as $item ) : ?>
					<?php
					if ( 'match' === $item['source'] ) {
						echo \LeagueFlow\render_match_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by render_match_card().
							array(
								'permalink' => $item['url'] ?? '', 'status' => $item['status'] ?? '', 'status_label' => $item['statusLabel'] ?? '',
								'datetime_raw' => isset( $item['startTimestamp'] ) ? wp_date( 'Y-m-d H:i', (int) $item['startTimestamp'], wp_timezone() ) : '',
								'sport_slug' => $item['sport'] ?? '', 'sport_label' => $item['sportLabel'] ?? '', 'league_level_label' => $item['leagueLevelLabel'] ?? '',
								'home_team' => $item['home'] ?? '', 'away_team' => $item['away'] ?? '', 'scoreline' => $item['scoreline'] ?? '',
								'result_label' => $item['resultLabel'] ?? '', 'venue' => $item['venue'] ?? '',
							)
						);
					} else {
						?>
						<a class="leagueflow-calendar__event is-link" href="<?php echo esc_url( $item['url'] ?? '' ); ?>"><div class="leagueflow-calendar__event-top"><span class="leagueflow-calendar__event-sport"><?php echo esc_html( $item['sportLabel'] ?? '' ); ?></span><span class="leagueflow-calendar__event-time"><?php echo esc_html( $item['day'] . ' ' . $item['time'] ); ?></span></div><p class="leagueflow-calendar__event-title"><?php echo esc_html( $item['title'] ); ?></p></a>
						<?php
					}
					?>
				<?php endforeach; ?>
			</div>
		</div>
	</noscript>

	<script type="application/json" data-calendar-data><?php echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
</div>
