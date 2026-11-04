# Calendar and Gutenberg rebuild

## Scope and reference

This change adapts the relevant calendar interaction model from [CampusManager](https://github.com/Over-the-Edge-Newspaper-Society/campusmanager/tree/ac56cd12d05e7e37be21379845a58c3128705d4e) to LeagueFlow's matches, sports, competitions, seasons, teams and standalone recreation events. The reviewed reference commit is `ac56cd12d05e7e37be21379845a58c3128705d4e`; the LeagueFlow baseline is `8c833d6f7e2850e6b861812172cab97f3c687d3a`.

The implementation keeps LeagueFlow's WordPress data model, server rendering, existing calendar exports and overlap-lane logic. CampusManager's React bundle, organization model, booking/cart features and development event fixtures are not dependencies of this plugin. Existing block names and shortcodes remain compatible.

Primary reference files reviewed:

- `blocks/calendar-view/block.json` and `blocks/calendar-view/src/index.js`: editor settings, list limits, available views, cost, ordering and month detail layouts.
- `assets/react/src/components/unbc-calendar.tsx`: view/navigation state, filters, search, list behavior and event dialog integration.
- `assets/react/src/hooks/useEvents.ts` and `assets/react/src/services/eventsApi.ts`: date range queries, bounded pages, cancellation and stale-response protection.
- `assets/react/src/components/calendar-views/{month,week,day}-view.tsx`, `mobile-month-view.tsx` and `event-dialog.tsx`: layout and event-detail behavior.
- `assets/react/src/utils/ical.ts` and `includes/class-rest-api.php`: export and REST contracts.

## Why the late November and December fixtures could disappear

The older supplied source loaded a maximum of 100 events from the entire chronologically ordered schedule. Its calendar navigation then filtered that in-memory subset. Later matches could be correctly saved and published without ever reaching the calendar.

The current GitHub baseline already had a bounded public collection API and a manual “Load next schedule page” control. That improvement did not make navigation request the month being viewed: moving to November or December still filtered the events already loaded. A visitor could consequently see an empty date despite additional matches existing on another history page.

There was a second independent omission: the renderer accepted only minute-precision stored datetimes when normalizing events. Records stored as `YYYY-MM-DD HH:MM:SS` could count in a query and then disappear while generating the payload. The rebuilt normalizer accepts both canonical forms, preserves explicit seconds and resets omitted seconds to zero. Invalid calendar dates are excluded before pagination/counting.

These are reproduced source-level failure mechanisms. Confirming the exact cause of Zoe's production report still requires the deployed plugin version and the affected records' publication state, teams, taxonomy assignments, saved dates and page/block settings. This review did not access that site's database or deploy to it.

## Reference feature mapping

| CampusManager behavior | LeagueFlow implementation |
| --- | --- |
| Month, week, day and list views | Existing views retained; every navigation/filter change now requests the appropriate date range. Site start-of-week remains authoritative. |
| Complete calendar ranges, incremental upcoming lists | Month/week/day automatically finish the visible range in sequential requests of at most 100 items. Upcoming lists load one page at a time through their existing load-more action. Earlier history is never a prerequisite for opening a later month. |
| Request cancellation and generation checks | Superseded requests are aborted. A request generation counter also prevents a late response from replacing the newly selected range. Failed requests show an error and retry action. |
| Category and organization filters | Adapted to sport and event type for visitors, plus competition, season, league level and team scope in the block. Fixed block scope cannot be replaced by a visitor filter. |
| Search | Search is debounced and sent to the scoped collection endpoint, so a match on a later page can be found. Search includes titles, venue, teams, relevant taxonomy names and event-type labels. |
| Month sidebar, dropdown and popover | Persistent sidebar, expandable panel below the month, and a positioned day popover. The LeagueFlow popover opens on click/keyboard/touch; it does not require mouse hover. |
| Sidebar left/right | Both placements are implemented on wide containers. Narrow containers stack the panel below the calendar. |
| Cost visibility and event order | Cost controls cards and details. Ascending/descending order is applied in SQL before pagination and respected by list/day summaries. Chronological time-grid positions remain determined by start time. |
| Initial list items / load-more count | Both settings work with local display batches and additional server pages. Loading more does not depend on a separate technical pagination control. |
| Event details and calendar exports | Existing dialog retains focus handling, details, score/status, venue, registration and Google/ICS actions. Outlook export is added. Exports use the original interval, including when the clicked card is a clipped multi-day segment. |
| Responsive display | Existing mobile presentation remains; container-width observation also adapts calendars inside narrow themes and Gutenberg previews. |
| Gutenberg configuration | Registered attributes reach the server payload and frontend. The editor mounts the real rendered calendar, observes server-rendered replacements and cleans up old instances. Wide/full alignment is passed to the frontend wrapper. |

## Data and rendering behavior

The initial server-rendered payload covers the current month, week or day in the WordPress site timezone. An initial list starts today and continues forward. The date picker can jump directly to November 29 or December 7 without stepping through each prior month. Changing the displayed range resets its collection page while retaining the block's configured filters.

Every individual collection request is capped at 100 events and uses stable datetime/ID ordering. Calendar views complete their selected range automatically; the UI exposes loading and retry state instead of presenting an incomplete result as an empty schedule. The list remains intentionally incremental. Response validation checks page progression and bounds, and duplicate event IDs are removed when pages are merged.

Date queries use interval overlap. An event beginning November 30 and ending December 2 appears on December 1 even though its start is outside the selected month. End times are exclusive: an event ending at midnight does not occupy the following day. Week/day rendering clips a spanning event to the visible day's time range while details and exports retain its original dates. Match duration comes from saved `lf_duration_minutes`, then a linked legacy availability window, then the historical 60-minute default. SQL range inclusion and the displayed interval use the same rule.

The public block and its Gutenberg preview deliberately use a public-only collection: published, password-free records with visible referenced teams. Being logged in as an administrator does not cause private fixtures to appear in a public preview. Incremental requests omit credentials, require a same-origin endpoint and request uncached responses. The REST response also sends `Cache-Control: no-store`. Full-page/CDN caches can still serve an older initial HTML payload until their normal purge or expiry; this change does not control external cache software.

Malformed dates, reversed ranges, unknown teams and unknown taxonomy scopes fail explicitly through the REST API. Invalid shortcode scopes return no matching records rather than broadening to the entire schedule. Public counts and pages are calculated after visibility, date and context filtering. Requests beyond the final page preserve the actual total instead of reporting a misleading zero.

Multiple calendars have distinct IDs for dialog and day-panel labelling. Day controls expose date and event count, selected/expanded state and focus-visible styling. Escape and close actions restore relevant focus; the existing event-dialog focus trap remains covered by regression tests. Search and asynchronous progress are announced using the existing accessible status regions. The no-JavaScript fallback clearly identifies itself as the initial range's first page.

## Gutenberg settings and shortcode attributes

Insert **Sports Calendar** in Gutenberg. The same configuration is available through `[match_calendar ...]` or its `[sports_calendar ...]` alias.

| Setting / attribute | Default | Behavior |
| --- | --- | --- |
| Sport / `sport` | Unrestricted | Fix the calendar to one sport. Changing it clears dependent editor selections. |
| Competition / `competition` | All | Restrict matches and events to a competition. |
| Season / `season` | Current, if configured | Blank uses the configured current-season context. Explicit `all` includes all seasons. The editor labels distinguish the two. |
| League level / `league_level` | All | Restrict to a level, such as recreational. |
| Team schedule / `team` | All teams | Shows that team's matches; standalone recreation events are not team fixtures. Selecting a team clears an incompatible standalone event-type selection. |
| Default view / `view` | `month` | `month`, `list`, `week` or `day`. Disabled views are removed from default-view choices. |
| Include standalone events / `include_events` | `true` | Include drop-ins, practices, clinics, tournaments, meetings and other events. Disabling it clears an incompatible event-type selection. |
| Show week/day / `show_week`, `show_day` | `true` | Controls available views. Disabling the currently selected default resets the default to month. |
| Event type / `event_type` | All available types | Supports `match`, `drop_in`, `practice`, `clinic`, `tournament`, `meeting`, `other`. The editor offers only compatible types in a team/matches-only calendar. |
| Match stage / `include_knockout` | Both | Blank includes both; `0` selects league matches and `1` selects knockout matches. Applies to matches. |
| Month day details / `month_display` | `sidebar` | `sidebar`, `popover` or `dropdown`. The sidebar default preserves existing LeagueFlow layout. |
| Sidebar position / `sidebar_position` | `right` | `left` or `right`; enabled in the inspector for sidebar mode. |
| Show cost / `show_cost` | `true` | Controls cost in event cards and details. |
| Event order / `event_sort_order` | `asc` | `asc` or `desc`, applied before paging. |
| Initial list items / `list_initial` | `30` | First visible list batch; inspector allows 5–100. |
| Load more count / `list_more` | `15` | Additional visible list batch; inspector allows 5–50. |
| Events per request / `limit` | `100` | Transport batch size, not a schedule cutoff; inspector allows 10–100. |
| Alignment | Theme default | Gutenberg wide/full alignment, where supported by the theme. |

Example public recreation calendar:

```text
[match_calendar season="all" include_events="1" month_display="sidebar" sidebar_position="right" show_cost="1"]
```

Example soccer drop-in list:

```text
[match_calendar sport="soccer" season="all" event_type="drop_in" view="list" list_initial="10" list_more="10"]
```

## Adding drop-ins and other events

LeagueFlow already provides a standalone calendar event post type and administration form. Staff can create an event, set its type, start/end, venue, status, cost and registration information, assign the intended sport/competition/season/level, then publish it. The public calendar must include standalone events and its fixed context must match those assignments. A team-only calendar intentionally shows only team fixtures.

The form's date validation is strengthened by the accompanying administration changes. Staff navigation is also made more discoverable in that work. Recurring drop-in series, recurring-event exception editing and subscribed calendar feeds are additional product features; this rebuild supports individual and multi-day events and per-event exports. It does not introduce a recurrence data model or an external calendar synchronization service.

## Verification evidence

`npm run test:calendar` runs four real-script DOM suites in each of `America/Vancouver`, `UTC` and `Asia/Tokyo`:

- `calendar-regressions.cjs`: site wall-clock placement, original UTC export instants, dialog labels, focus trap, Escape and focus restoration.
- `calendar-lanes.cjs`: simultaneous and chained overlaps, adjacency, separate-day lanes and filtered placement.
- `calendar-pagination.cjs`: bounded legacy paging, duplicate-click protection, failed-page recovery, ID deduplication and later-page sport availability.
- `calendar-range.cjs`: direct Nov/Dec range requests, cancellation and stale responses, failure/retry, debounced search, spanning events, exclusive midnight ends, fixed author scopes, cost/Outlook behavior, complete dense ranges and incremental lists.

`tests/wordpress-calendar.php` runs against a guarded disposable WordPress installation. It passes **37 assertions** using real posts, metadata, SQL, REST dispatch and registered Gutenberg blocks. The fixture includes 115 earlier matches before Nov 29/Dec 7, minute and second precision, invalid dates, drafts/protected records, hidden referenced teams, a month-spanning drop-in and saved/legacy match durations. It also verifies filtered counts/headers, empty-page totals, public preview consistency, block attribute transport, unique dialog IDs and frontend wide alignment.

PHP syntax checks, JavaScript syntax checks, existing asset-registration/version tests and `git diff --check` also pass. The test runner is wired into the plugin's normal calendar test command; the native suite is included in the release validation workflow by the integration changes.

These are DOM and native integration results. **Actual browser rendering, mobile screenshots and a live Gutenberg editing session were not verified in this environment.** The connected browser and cloud browser could not reach the isolated local WordPress server. No screenshot or visual-parity claim is made. The implementation follows the reviewed reference behavior, but theme-specific appearance and editor interactions still require browser review on a reachable staging installation.

## Repeatable staging review

`tests/seed-review-demo.php` prepares synthetic calendar pages for a disposable `http://leagueflow.test` installation. It requires both WP-CLI and `LEAGUEFLOW_DISPOSABLE_WP=1`. It sets the site timezone to Vancouver, enables the example sports, and creates review-only teams, matches, drop-ins and three pages. Re-running it replaces only posts carrying its review marker. It is a development fixture, not a production migration.

```sh
LEAGUEFLOW_DISPOSABLE_WP=1 wp eval-file wp-content/plugins/leagueflow/tests/seed-review-demo.php
```

The command prints the generated page IDs and URLs. The main page includes more than 100 earlier matches and fixtures on Nov 29, Dec 1, Dec 6 and Dec 7. The layout page places popover, dropdown and left-sidebar calendars together. The list page uses smaller display/request batches. Concurrent volleyball/basketball fixtures and a multi-day event exercise time lanes and month boundaries.

On a reachable staging site, review:

1. Jump directly to Nov 29 and Dec 7; compare against the published fixture list. Change sport/type and search for a later-page fixture.
2. Navigate quickly between months on a throttled connection, then force a failed request and retry. The final visible month must match the latest navigation.
3. Check month/week/day/list at desktop and phone widths, with two or three simultaneous events. Check narrow content columns as well as full-width pages.
4. Select each month detail mode, both sidebar positions, costs on/off and both ordering directions. Check keyboard focus and the multi-block dialog labels.
5. In Gutenberg, change context and layout controls, save, reload the editor and compare the frontend. Confirm wide/full behavior in the active theme.
6. Add a published drop-in across Nov 30–Dec 2; verify each occupied day and its original export interval. Confirm an event ending at midnight is absent the next day.
7. Check the affected production records' season, competition, team visibility and published state after deploying through the normal release process. Purge the site's page cache when reviewing the result.

High-volume query latency and cross-theme visual compatibility remain staging checks. The bounded range design fixes the history cutoff without loading the full schedule into each page, but it is not a substitute for measuring the production database's actual size and indexing.
