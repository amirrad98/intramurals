# LeagueFlow logic audit — 21 October 2026

## Scope and conclusion

This review covers the public calendar, score entry and standings, knockout advancement, field scheduling, registration documents, placement requests, referee access, season setup, fixture generation and player export. It uses the repository at baseline commit `8c833d6f7e2850e6b861812172cab97f3c687d3a`, the revised source in this checkout and regression tests in a disposable WordPress installation. It incorporates Zoe Nunes's October 15–21 requests.

The two reported operational problems have credible, reproducible mechanisms in the source. The calendar initially loaded a bounded slice of the complete schedule; changing the displayed month did not necessarily fetch that month's records. Standings required Finished results, but ordinary score saving did not make a match Finished, and a separate date dependency excluded otherwise valid undated results. The implementation addresses these mechanisms and adds explanations where staff previously saw an unexplained empty result.

This is a code and disposable-site finding, not a claim that the affected records on UNBC's production website have been inspected. Their publication status, team visibility, taxonomy assignments and page configuration still need to be checked when the update is evaluated against that site's data. The email mentions attached waivers, but their contents were not supplied with the request; the plugin therefore provides document configuration and evidence recording without inventing approved institutional text.

## Zoe's requests and the resulting behavior

| Request | Result prepared in this revision | Evidence and remaining input |
| --- | --- | --- |
| Soccer matches from November 29 through December 7 do not appear | Calendar navigation fetches the visible date range from the server, retains bounded pagination and shows loading, partial-result and retry states. Date overlap includes events spanning the range boundary. | [Renderer](../includes/class-renderer.php), [calendar controller](../assets/js/calendar.js), [REST controller](../includes/class-rest-controller.php), [native calendar regressions](../tests/wordpress-calendar.php), [browser range regressions](../tests/calendar-range.cjs). Production fixture IDs and page filters are still needed to verify those exact records. |
| Volleyball scores do not populate standings | Staff can explicitly save a match as a final result; unfinished scored matches receive a clear explanation. Standings diagnostics identify common exclusion reasons. Volleyball totals are labelled sets won; set-by-set text is supplementary. | [Admin result handling](../includes/class-admin.php), [standings service](../includes/class-standings-service.php), [sport definitions](../includes/class-sports-manager.php), [standings tests](../tests/wordpress-standings.php). Confirm the competition's points and set-format policy before adding format-specific scoring rules. |
| Referees need limited rosters and optionally score entry | A dedicated referee role and desk expose assigned published matches and their two rosters. Score permission is enabled per match. A final submission records the actor and timestamp and closes referee editing. Staff handle later corrections and bracket advancement. | [Referee service](../includes/class-referee.php), [referee tests](../tests/wordpress-referee.php). Staff must create/assign the accounts and enable score entry on the intended matches. |
| Add drop-in sports and other events to the calendar | Existing standalone Calendar Events are retained and integrated with the corrected date-range feed. Events have their own start/end, venue, type, cost, registration link and lifecycle. | [Calendar event editor](../includes/class-admin.php), [calendar API](../includes/class-rest-controller.php). Recurrence series and attendee bookings are separate future features. |
| Players review and accept waivers during registration | Configurable required documents appear with separate unchecked acknowledgements. Server validation checks the current document revision before registration or renewal, and records personal evidence on the player. | [Consent service](../includes/class-registration-consent.php), [portal](../includes/class-portal.php), [registration tests](../tests/wordpress-registration.php), [operating guide](REGISTRATION-AND-PLACEMENTS.md). Supply the approved waiver text/file URL and version. |
| Include the Intramural Code of Conduct | A separate Code of Conduct requirement uses the same revision-aware acknowledgement process. | Same consent implementation and guide. Supply the approved document and its version; captain consent applies only to that captain's own player identity. |
| Remove placements already fulfilled by adding new players | Existing exact roster additions reconcile matching requests. A reviewable action closes older already-satisfied requests without deleting their history or changing the roster. | [Request service](../includes/class-join-request-service.php), [Requests admin](../includes/class-admin-requests.php), [registration tests](../tests/wordpress-registration.php). Similar names do not prove identical player records; identity duplicates remain a staff review item. |
| Learn how to create seasons, sports and leagues | Existing sport/level/competition/season configuration is retained, and current-season changes are protected against unrelated programmatic term updates. The workflow must distinguish enabling a built-in sport from developing a new sport module. | [Sports manager](../includes/class-sports-manager.php), [taxonomy handling](../includes/class-taxonomies.php), [README](../README.md). See the follow-up scope below for custom sports and season history. |

## Confirmed defects and corrections

### 1. Calendar results depended on the record's position in the whole schedule — P1

The baseline `Renderer::render_match_calendar()` requested the first calendar page with a default limit of 100, without first narrowing the query to the displayed month. Calendar navigation operated on the loaded collection. A valid later fixture could therefore be absent from a displayed month because earlier fixtures had occupied the initial page. This mechanism fits the reported late-season cutoff, although the production data is needed to establish which exclusion affected each reported match.

The revised calendar fetches the visible month, week or day; List fetches upcoming events. Filtering and paging operate within that server-side scope. This preserves bounded requests while making a date range independent of how many older records exist. A range with more than one page shows that more records remain, and List's Load more control can fetch the next server page. A failed request shows a retry state instead of implying that no events exist. Request versioning and cancellation prevent an older response from replacing a newer navigation result.

The feed validates explicit dates and filter identifiers. It uses event interval overlap rather than only event start dates, so an event that began before a selected day but continues into it remains visible. Match end times use the canonical duration described below. Public calendar rendering remains restricted to published, non-password-protected records and visible participants even when the person viewing the page is staff.

Implementation: [`Renderer::render_match_calendar()` and `get_calendar_page()`](../includes/class-renderer.php), [`REST_Controller::get_calendar_events()`](../includes/class-rest-controller.php), [calendar JavaScript](../assets/js/calendar.js).

### 2. Entering a score and finalizing a result were disconnected — P1

The standings query deliberately counted only Finished matches. The ordinary score editor could save two totals while leaving the lifecycle Scheduled. In volleyball, the optional `lf_set_scores` field was also a text note: entering `25-18, 21-25, 15-11` there did not populate the numeric home/away totals used by standings.

The editor now gives staff an explicit final-result action and explains when scores are saved without contributing to standings. Finalization validates both totals for an ordinary played result. A blank score is not converted to an invented zero; an explicit 0–0 remains valid. Forfeits can be final without played scores because their outcome determines the result.

Result metadata is saved before the lifecycle transition, so observers see the complete result. Referee submission uses the same numeric score validator, an assignment/state recheck, a concurrency token and a result lock. It commits both totals, the audit record and Finished together on transactional metadata storage. A later referee resubmission cannot overwrite a completed result.

Staff editing, bulk status updates and automatic bracket participant changes share that result lock. Bulk finalization validates stored totals, skips incomplete played results, rechecks edit authority after acquiring the lock and reports the number actually saved. A valid knockout bulk finalization can advance only after releasing its source lock. The ordinary edit form also detects a changed result rather than overwriting a referee's intervening submission.

The standings admin diagnostics classify readable fixtures as counted, scored but unfinished, unfinished without scores, incomplete/invalid scores, unpublished, knockout or invalid participants. The examples are limited and are unavailable to anonymous callers. This turns an unexplained empty table into a repairable staff workflow.

Implementation: [admin match saving and standings diagnostics](../includes/class-admin.php), [`Match_Status::is_valid_score()`](../includes/class-match-status.php), [`Standings_Service::get_diagnostics()`](../includes/class-standings-service.php), [referee result service](../includes/class-referee.php).

### 3. Standings had hidden date dependencies and inconsistent inclusion rules — P1

The baseline standings query ordered using `meta_key = lf_match_datetime`. WordPress's metadata join consequently removed results that lacked that metadata row, even though a schedule date is not required to calculate a final score. The old score-presence check also accepted arbitrary nonempty strings. Knockout results could enter the league table, and the staff calculation could include unpublished fixtures despite describing the table as published results.

The service now queries finished results independently of their schedule date, then applies a common inclusion rule. A counted result requires a published match, two distinct published team records, and either a valid forfeit outcome or two nonnegative integer scores. Existing visibility checks continue to exclude inaccessible password-protected records. Knockout fixtures are kept out of the league table.

The integer validator rejects malformed strings, negative values, decimals, exponents, booleans, arrays and overflow. Zero is valid. Forfeit and double-forfeit behavior is covered by exact W/D/L, points and for/against assertions. Manual adjustments remain separate from derived points in the returned row.

A separate rule-resolution bug appeared when a table aggregated more than one competition: the service resolved one rule set for the whole request, so competition overrides could be ignored when the competition filter was empty. Point awards are now resolved from each match's actual competition and sport. Tiebreak ordering remains a property of the requested table context; a cross-competition table should only be used where staff intend to compare those competitions.

Implementation: [`Standings_Service`](../includes/class-standings-service.php), [public league table](../templates/league-table.php). Native coverage: [standings and knockout regressions](../tests/wordpress-standings.php).

### 4. Knockout advancement could announce a premature winner or corrupt a later result — P1

The baseline winner function considered winner overrides and forfeits before checking Finished. The advancement service could therefore act on an unfinished source. It also lacked a lifecycle-aware protection against replacing a participant in a later fixture that had already begun or received scores.

Advancement now requires a Finished knockout source and validates the referenced knockout graph, including edit authority, matching sport/level/competition/season context, forward round order when specified, cycle prevention and the existing 100-hop guard. A league fixture is not a valid bracket target.

Each occupied automatic slot records the source match that supplied it. A corrected semifinal can update its own still-unplayed final slot. It cannot replace a manually chosen different participant, a slot supplied by another match, a live/finished/cancelled fixture, or any fixture with a score already entered. Even an explicit zero protects the later fixture. It cannot place the same team on both sides.

The target uses the shared result lock. Source and target caches are refreshed after lock acquisition, and the current source outcome and target graph are rechecked. This matters because pre-lock graph checks prime WordPress's metadata cache: another request may commit a change before the lock is obtained. Native regressions simulate a source becoming unfinished and a target becoming live between those checks and verify that neither is overwritten.

Staff changing a participant manually clears the corresponding automatic provenance. Referees do not acquire broad authority to edit a downstream bracket merely because they can submit one assigned result. Staff review and perform that advancement.

Implementation: [`Knockout_Service::advance_winner()` and `can_advance_to()`](../includes/class-knockout-service.php), [admin participant saving](../includes/class-admin.php). Tests also retain a positive 100-hop graph and reject 101 hops using otherwise valid knockout fixtures.

### 5. Scheduling treated every existing match as one hour — P1

Availability rules support arbitrary slot durations, but conflict calculation used 60 minutes for every existing match. A 90-minute game could consequently be overlapped by a later scheduling run, while a 30-minute game could reserve unnecessary time.

`Field_Availability_Manager::get_match_duration_minutes()` is now the canonical duration lookup. It prefers a valid duration stored on the match, otherwise infers a legacy fixture's linked availability duration, otherwise defaults to 60 minutes. Newly scheduled fixtures store their actual duration. Later changes to an availability rule therefore do not resize those existing reservations. Both scheduling conflicts and public calendar match end times use the same duration convention.

Historical duration cannot be reconstructed reliably if a legacy availability rule was already changed or deleted before duration was stored. Those exceptional records need staff confirmation; the update does not pretend to recover information that was never retained.

Implementation: [field availability manager](../includes/class-field-availability-manager.php), [calendar mapper and interval query](../includes/class-renderer.php). Coverage: [scheduling regressions](../tests/wordpress-scheduling.php).

### 6. Closure and rest/rematch lookups could miss relevant conflicts — P1

The scheduler queried events whose **start** fell within a padded range. A long closure beginning before that range could overlap the entire target date and still be absent from the conflict list. The match lookback/lookahead also used a fixed one day even when the configured rest or rematch interval exceeded one day.

Event conflict queries now include intervals that overlap the target range. Cancelled closures remain excluded. Match history padding is at least the largest configured rest/rematch interval. Tests cover earlier and later rematches outside the old one-day lookup and permit a rematch exactly at the configured boundary.

The existing policy distinction is preserved: a rematch gap is a hard restriction; minimum rest is a scheduling preference when choosing among otherwise valid slots. The implementation does not silently turn that preference into a new hard eligibility rule.

### 7. Simultaneous automatic scheduling could reserve the same slot — P1

Before this revision, two scheduling runs could both calculate availability before either wrote its assignments. A site-wide advisory lock now serializes automatic scheduling, including sports sharing a venue. If the lock is busy or the lock service is unavailable, the operation fails closed and reports that no matches were changed. Existing per-match edit checks remain in place, and live matches are excluded from automatic overwrite alongside finished and cancelled matches.

Each selected fixture additionally uses its shared result lock and refreshes its metadata before selecting or writing a slot. That prevents an earlier cached Scheduled value from moving a match just finalized by a referee. Per-match lock acquisition does not wait while holding the site scheduling lock, avoiding an indefinite lock-order wait when an editor is simultaneously requesting auto scheduling.

The conflict snapshot also retains every selected fixture's existing booking until that fixture successfully moves. Previously, selecting an existing booking removed it from the initial conflict calculation even if that fixture was later skipped or could not be moved. Another selected fixture could then take its still-occupied slot. Current calculations omit only the fixture being moved and the old bookings already replaced by successful in-run reservations. This is deliberately conservative: a booking is not treated as free merely because a later operation might move it.

The native test uses a second database connection to hold the real site lock, verifies refusal without mutation, releases it, and verifies that scheduling can then proceed. It separately holds one selected fixture's result lock and proves that another fixture takes the next free slot without overlapping the preserved booking. It also exercises an editor already holding its own result lock and checks that nested scheduling leaves the outer lock intact. These locks serialize the participating operations; they do not claim to make every manual calendar edit or third-party import part of one global booking transaction.

### 8. Unrelated season edits could unset the current season — P2, corrected

`Taxonomies::save_season_current_field()` interpreted an absent current-season checkbox as a request to clear the flag even for programmatic term updates. A rename through an integration could therefore change which season public blocks selected.

The full season form now supplies an explicit presence marker. A term update that did not submit that form preserves the selection. Quick Edit also remains non-destructive. Current-season behavior stays per sport, and unrelated sports retain their own current season.

Implementation: [taxonomy form/save handling](../includes/class-taxonomies.php).

## Registration, placements and referee boundaries

The registration work is documented in detail in [Registration documents and placement requests](REGISTRATION-AND-PLACEMENTS.md). The important logic distinction is between personal acceptance and staff roster maintenance. A staff member adding someone to a team does not prove that person accepted a document. Required current acknowledgements gate new registration/request decisions, while existing roster records remain intact and participants receive a renewal path.

Evidence is append-only and tied to the authenticated account and player identity. It includes the accepted document version, title, content/URL and fingerprint with a UTC timestamp. Text configured in the plugin is snapshotted. An external PDF's bytes are not downloaded or archived; replacing a file at an unchanged URL cannot be detected automatically. Versioned URLs or pasted approved text provide a reviewable change history.

Placement cleanup operates on the exact player identity, sport and requested level, and on the named team when the request names one. It closes already-satisfied requests while preserving decisions and history. It does not delete records, merge people by name or send an unsolicited message. New membership through the normal staff/player/team workflow already invokes reconciliation; the review action addresses older stale requests and external changes.

The referee role receives dedicated capabilities rather than general post/user administration. Assignment is checked at access and again before score mutation. Private contact details, account credentials and full consent evidence are not part of the referee desk; it shows roster names, number, position/captain label and an acknowledgement status. Score access can remain disabled, satisfying the roster-only option in Zoe's request.

## Existing mechanisms retained after review

- **Fixture generation:** the existing generator derives teams from the selected sport/season/competition/level context, presents a preview, rejects stale preview tokens, uses a sport-scoped generation lock, checks capabilities and avoids already-generated pairings. These controls should remain in place when adding new scheduling features. Source: [fixture generator](../includes/class-fixture-generator.php).
- **Player export:** the admin export entry point requires `manage_options` and a nonce. The CSV exporter neutralizes spreadsheet formula prefixes. There is no reason to give referees this export authority merely to let them view two assigned rosters. Sources: [admin export handler](../includes/class-admin.php), [exporter](../includes/class-exporter.php).
- **Public versus operational data:** public schedule/standings data and authenticated roster operations have distinct visibility checks. The calendar revision preserves bounded queries and filters visibility before pagination; widening the query to an unlimited history would reintroduce performance and data-boundary problems. Sources: [renderer](../includes/class-renderer.php), [post type REST guards](../includes/class-post-types.php).

## Follow-up development scope

The following work remains substantive product development or requires a data/policy decision. It should not be represented as already implemented.

| Priority | Finding / feature | Concrete implementation scope and acceptance condition |
| --- | --- | --- |
| P2 | Points deductions are stored on the team globally | `Standings_Service` reads `lf_points_adjustment` from the team for every table. A deduction intended for one season can consequently affect another season or competition. Introduce adjustment records keyed by team, season and competition, with amount, reason, actor and timestamp. Provide a preview to assign legacy global adjustments; do not silently guess their intended season. Prove two seasons of one team can have different deductions. |
| P2 | Volleyball rules are generic numeric totals plus optional text | The current implementation records sets won but does not parse or validate individual set scores, a required number of sets, winning margins, or awards dependent on a 3–0 versus 3–2 result. Agree UNBC's actual match formats and points policy, then add typed per-set results and a sport-specific validator/calculator. Preserve the existing simple total-entry path where that is the intended intramural workflow. |
| P2 | Custom sport support is not a complete sport builder | `Sports_Manager::get_definitions()` contains a fixed catalog and `get_enabled_sport_slugs()` filters to that catalog. Creating a taxonomy term through an integration does not create a fully configured module, score labels or portal sport. Add a definition registry/settings model, validation and migration before exposing an Add custom sport promise. Built-in sports can already be enabled through LeagueFlow → Sports. |
| P2 | Rosters are current membership, not a season history | Player-team membership and team details are stored against current team IDs. Reusing a team across seasons and editing its players does not create a historical roster snapshot. Add effective season membership or archived roster snapshots and an explicit rollover preview. Acceptance should show last season's roster unchanged after the new season's roster changes. |
| P2 | Recurring drop-ins require a series model | Standalone Calendar Events are implemented; a recurring field-availability rule is not a recurring published event series. Add recurrence, exclusions, end conditions and “this occurrence / future occurrences / whole series” editing. Store a stable series relationship and preserve cancelled occurrence history. |
| P2 | Match duration has no complete manual editing workflow | Scheduled fixtures now preserve duration, but ordinary manual match entry still chiefly exposes start and venue. Add a validated duration/end-time control and make schedule changes retain or deliberately replace it. Keep the public feed and conflict calculation on the canonical helper. |
| P2 | Manual venue edits can race an automatic run | The new site lock covers automatic scheduling runs. If full booking guarantees are required, make manual match/event writers participate in the same reservation contract, with conflict preview and transactional checks. Avoid implying that a single scheduler lock controls all third-party database writes. |
| P2 | Staff repairs lack a unified visibility workbench | Standings now explains exclusions, but calendar publication/participant/filter problems still require checking the relevant editor and page configuration. Add a read-only “Why is this match missing?” tool with the effective page scope, publication state, participant visibility, date validity and matching query result. Apply the viewer's existing edit authority. |

The release updater and publishing scripts were also found to target a different GitHub repository than the one supplied for this task. The revised target is the requested `ahzs645/leugueflow`, and the cache namespace is separated from the previous repository. This operational correction is part of release verification before packaging or publishing. Installation/testing in this workspace does not publish a GitHub release or update a production site. Sources: [updater](../includes/class-github-updater.php), [publisher](../scripts/publish-release.py), [release workflow](../.github/workflows/release.yml).

## Verification and deployment acceptance

The dedicated native logic suites passed on WordPress 6.5 in a disposable local installation:

| Suite | Verified result | Meaningful cases |
| --- | --- | --- |
| [`tests/wordpress-standings.php`](../tests/wordpress-standings.php) | **85 assertions passed** | Date-free finished results; strict score matrix; draft/live/knockout/invalid-team exclusions; exact diagnostics; 0–0; forfeits; per-competition point overrides; privacy; downstream participant safety; stale cache interleavings; bulk finalization validation/locks/late permissions; anonymous mutation refusal. |
| [`tests/wordpress-scheduling.php`](../tests/wordpress-scheduling.php) | **51 assertions passed** | Spanning closures; cancelled closure exclusion; legacy and stored duration; duration preservation after rule edits; earlier/future rematches; rest preference; live/finalized-match protection; unavailable lock; real concurrent connection; retained selected booking occupancy; nested editor lock preservation; unauthorized callers. |

The calendar, referee, registration, legacy security, admin and portal suites are separate release gates. Run them against their documented disposable setup and record their final results with the packaged revision. In particular, the legacy security fixture uses fixed accounts and retains data: it needs a fresh installation when rerun. A run against its leftover data is not a valid regression result. Its knockout graph fixtures have been updated to mark all graph nodes as knockouts, retaining both the positive 100-hop assertion and the negative 101-hop assertion.

After installing the candidate on a staging copy of the actual website, check the outcomes that matter to staff:

1. Open the same public calendar used by participants, navigate through November 29–December 7, and match the displayed fixtures to staff's fixture IDs. Check any intentionally unpublished or mismatched-season records separately.
2. Save a known volleyball match using sets-won totals and the final-result action. Confirm one played match and the configured points for both teams, then correct the result and confirm totals change once.
3. Sign in as an assigned referee and as an unassigned referee. Verify the intended roster-only or score-enabled behavior and that the second account cannot open the first account's assignment.
4. Configure the approved waiver and Code of Conduct, complete a registration and a renewal, and inspect the recorded evidence. Confirm the participant must make each acknowledgement personally.
5. Review an already-placed request, close it through the previewed cleanup action, and confirm the roster is unchanged and request history remains.
6. Add a drop-in event and a spanning venue closure, then schedule fixtures around them. Verify the venue calendar and scheduler agree on actual match duration and occupied intervals.

These checks validate the production configuration and data assumptions that a source review cannot establish. They do not require changing the institution's points rules, publishing missing documents, deleting historical placements or silently finalizing old scores.
