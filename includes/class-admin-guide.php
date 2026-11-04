<?php
/** Staff workflow reference. @package LeagueFlow */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/** Keep the routine tasks available beside the screens where staff do the work. */
class Admin_Guide {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 40 );
	}

	public function register_menu() {
		add_submenu_page( 'leagueflow', __( 'Staff guide', 'leagueflow' ), __( 'Staff guide', 'leagueflow' ), 'edit_posts', 'leagueflow-guide', array( $this, 'render' ) );
	}

	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) { return; }
		$sections = array(
			array(
				'title' => __( '1. Set up a sport, league and season', 'leagueflow' ),
				'url' => admin_url( 'admin.php?page=leagueflow-sports' ),
				'link' => __( 'Open Sports', 'leagueflow' ),
				'steps' => array(
					__( 'In Sports, enable the supported sports you run. A competition is a league or tournament within a sport; a league level separates divisions such as recreational and competitive.', 'leagueflow' ),
					__( 'In Competitions, create the league and select its sport. In Seasons, create the term (for example, Fall 2026) and select its sport. Mark the appropriate season Current when you want Current season views to use it.', 'leagueflow' ),
					__( 'Assign teams and matches to the same sport, competition, season and league level. A new season does not copy teams or fixtures automatically. Use Generate fixtures after reviewing the intended team list.', 'leagueflow' ),
					__( 'In Settings, leave a sport or competition rule blank to inherit its parent value. Enter 0 only when you intend a zero. Points, tie-breakers and the forfeit score can be overridden independently.', 'leagueflow' ),
					__( 'Run Setup check to find missing sport and league context. Check the calendar block season setting: All seasons and Current season are different choices.', 'leagueflow' ),
				),
			),
			array(
				'title' => __( '2. Publish matches and record final scores', 'leagueflow' ),
				'url' => admin_url( 'edit.php?post_type=lf_match' ),
				'link' => __( 'Open Matches', 'leagueflow' ),
				'steps' => array(
					__( 'Choose the two teams and enter Match date/time in the website timezone. Publish the match now so a future fixture is visible. The WordPress publication date controls when the post becomes public; it is separate from the match date.', 'leagueflow' ),
					__( 'Enter both scores, including an explicit 0 for a scoreless team. For volleyball, use sets won in the main score fields; set-by-set details are optional notes.', 'leagueflow' ),
					__( 'Saving both valid scores on a Scheduled match automatically marks it Finished. A forfeit outcome does the same without requiring played scores. Use Keep the selected status if you are deliberately recording provisional scores. Live, Postponed and Cancelled keep their selected status; use Save match as final result when play is complete.', 'leagueflow' ),
					__( 'Correct a Finished result by editing its scores and saving. To reopen it as Scheduled, select that status and Keep the selected status. An unplayed next round follows a corrected winner; a started next round is preserved and flagged for staff review.', 'leagueflow' ),
					__( 'If standings look empty, open Standings and review the result diagnostics. Confirm Finished status, publication, both teams, valid scores or a forfeit, and the same season/league filters. Knockout matches do not count toward league standings.', 'leagueflow' ),
				),
			),
			array(
				'title' => __( '3. Add drop-ins, practices and events', 'leagueflow' ),
				'url' => admin_url( 'post-new.php?post_type=lf_calendar_event' ),
				'link' => __( 'Add Calendar Event', 'leagueflow' ),
				'steps' => array(
					__( 'Open Calendar Events → Add New. Choose a title and event type, start/end times, location, sport, and any season or league filters you want it to follow.', 'leagueflow' ),
					__( 'Add a cost description and registration link when needed. Publish the event. A drop-in does not require opponents, scores or a team roster.', 'leagueflow' ),
					__( 'On the website page, add or select the Sports Calendar block. Enable standalone events and choose the initial view, season, sport, filters, costs, and month event display. Update the page and check the event on its date.', 'leagueflow' ),
					__( 'Create a separate event for each session. Repeating series and attendance booking are not currently automated. The registration link can point to the registration service you already use.', 'leagueflow' ),
				),
			),
			array(
				'title' => __( '4. Give referees access', 'leagueflow' ),
				'url' => admin_url( 'admin.php?page=leagueflow-referee' ),
				'link' => __( 'Open Referees', 'leagueflow' ),
				'steps' => array(
					__( 'In LeagueFlow → Referees, add the official by email or username. Their player role is kept, so a player can also be a referee.', 'leagueflow' ),
					__( 'Referees sign in on the website and choose Referee desk from their account menu. They can search every published game, open both rosters, and publish a score or save it for you to review.', 'leagueflow' ),
					__( 'Assigning a referee to a game is optional and puts it at the top of their list. If a referee plays in a game they are flagged on the match, the Results screen and the Referees page, and they can only save that score for staff to publish.', 'leagueflow' ),
				),
			),
			array(
				'title' => __( '5. Require waiver and Code of Conduct acknowledgement', 'leagueflow' ),
				'url' => admin_url( 'admin.php?page=leagueflow-settings' ),
				'link' => __( 'Open Settings', 'leagueflow' ),
				'steps' => array(
					__( 'Under Registration, add the approved waiver and Code of Conduct text or a versioned document URL. Give each document an explicit version and enable its requirement only after reviewing the content.', 'leagueflow' ),
					__( 'Each participant must accept the current required documents through their own portal account. Captains and staff cannot record acceptance on someone else’s behalf. A new document version, text or URL requires a new acknowledgement.', 'leagueflow' ),
					__( 'Use a new URL when a linked PDF changes; replacing the file at the same URL does not create a new document version automatically. Do not assume players already on a roster have accepted. Check the evidence on their player record.', 'leagueflow' ),
				),
			),
			array(
				'title' => __( '6. Keep placement requests current', 'leagueflow' ),
				'url' => admin_url( 'admin.php?page=leagueflow-requests&type=placement' ),
				'link' => __( 'Open placement requests', 'leagueflow' ),
				'steps' => array(
					__( 'Review requests in the Requests inbox. Assign players to the intended team in that sport. A successful placement closes the matching request and preserves its history.', 'leagueflow' ),
					__( 'For older pending entries whose players were already added to a roster, use the inbox preview for already-placed players, review the matches, then close that batch. Unplaced players remain pending.', 'leagueflow' ),
				),
			),
		);
		?>
		<div class="wrap leagueflow-admin">
			<h1><?php esc_html_e( 'LeagueFlow staff guide', 'leagueflow' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Website timezone: %s. These steps cover the normal intramural workflow. Some settings require administrator access.', 'leagueflow' ), wp_timezone_string() ) ); ?></p>
			<?php foreach ( $sections as $section ) : ?>
			<section class="card" style="max-width:960px">
				<h2><?php echo esc_html( $section['title'] ); ?></h2>
				<ol><?php foreach ( $section['steps'] as $step ) : ?><li><p><?php echo esc_html( $step ); ?></p></li><?php endforeach; ?></ol>
				<p><a class="button" href="<?php echo esc_url( $section['url'] ); ?>"><?php echo esc_html( $section['link'] ); ?></a></p>
			</section>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
