# LeagueFlow

LeagueFlow is a classic WordPress plugin for running multi-sport leagues with teams, players, fixtures, derived standings, and knockout brackets. It uses WordPress-native admin screens, metaboxes, taxonomies, dynamic Gutenberg blocks, and shortcodes.

## Results workflow and calendar layout (1.2.0)

**LeagueFlow → Results** lists played games by stage: needs a score, saved but not
published, scheduled, needs review and published. Enter a score or forfeit, then
publish it, save it without publishing, or schedule it. Only published (Finished)
results show on the site and count in the standings. `wp leagueflow results
finish-scored` publishes scored matches that were never finished. The calendar and
league views use CampusManager's layout with theme-supplied design tokens; on phones
the month is a compact grid with the selected day's games below. Calendar Events use the
classic editor and can carry a poster image that visitors open full size from the calendar. See
[release notes](releases/1.2.0.md).

## Scoring and admin workflow fixes (1.1.1)

Saving both valid scores on a **Scheduled** match now marks it **Finished** and
updates derived standings. Explicit zeros are valid; blank scores stay missing.
A forfeit can finish without invented played scores. **Live**, **Postponed** and
**Cancelled** remain intentional states. The editor's keep-status control supports
provisional scores and reopening, and the final-result action remains available.

Linked knockout fixtures follow completed results, corrections and reopening
when their target is still unplayed. Started targets are preserved and flagged
for staff review. Referee final submissions use the same progression with limited
permissions. Public cards, lists, calendars, brackets and result feeds agree on
forfeits, tied knockout winners and incomplete legacy scores.

Admin navigation preserves sport/level/competition/season context and separates
match lifecycle from WordPress publication status. Sport setup preserves staff
labels, validates context ownership and uses per-field inheritance for points,
tie-breakers and forfeit scores. Result and scheduling saves verify all related
writes and roll back a failed operation.

See [the scoring/admin review](docs/SCORING-ADMIN-REVIEW-2026-10-21.md) for behavior,
verification, integration boundaries and rollout guidance. The version is prepared
for review; a branch or PR does not publish an update or install it on a website.

## Intramurals and calendar update (1.1.0)

The calendar now loads the visible date range and completes paginated month/week/day
results, with explicit loading and retry states. Its block controls follow the
CampusManager calendar's view, display, cost and ordering patterns while retaining
LeagueFlow's sports, teams and seasons. Drop-ins and other non-match events share
the same calendar. Existing `leagueflow/match-calendar` blocks and shortcodes remain
supported.

Staff can explicitly save final results, diagnose exclusions from standings, assign
limited referee access, configure personal waiver/Code of Conduct acknowledgements,
and preview closure of already-placed requests. **LeagueFlow → Staff guide** explains
the routine workflows inside WordPress.

- [Review, priorities and rollout](docs/REVIEW-2026-10-21.md)
- [Calendar reference mapping and block settings](docs/CALENDAR-REBUILD.md)
- [Registration documents and placements](docs/REGISTRATION-AND-PLACEMENTS.md)
- [Standings, knockout and scheduling audit](docs/LOGIC-AUDIT-2026-10-21.md)

## GitHub releases and WordPress updates

Requires **WordPress 6.5+ and PHP 8.1+**. Download the attached
`leagueflow-X.Y.Z.zip` from [GitHub Releases](https://github.com/amirrad98/intramurals/releases).
Install it through **Plugins → Add New → Upload Plugin**. The package contains the
stable `leagueflow/` directory. Use this asset rather than GitHub's generated source
archives, which contain development tooling and have a different directory name.

Older builds that do not include the updater, or point at the previous repository,
must upload the built ZIP once and replace the existing `leagueflow/` installation.
The 1.1.0 update feed points to **amirrad98/intramurals**. It can offer automatic updates
only after a verified stable release and its assets are published in that repository.
Branch and pull-request artifacts are review packages, not published stable releases.
While LeagueFlow is active, later stable releases
appear through WordPress's normal plugin update checks. **Dashboard → Updates →
Check again** requests a check; successful release metadata is cached for five
minutes. A site does not need a GitHub token. Administrators choose when to install
updates or enable WordPress's automatic updates. Updates replace plugin files;
they do not uninstall LeagueFlow, migrate websites or replace league data.

The updater uses the native `Update URI` hooks and the public release asset
`releases/latest/download/latest.json`. It accepts stable versions only and verifies
the downloaded ZIP against the specific version's manifest SHA-256. Failed checks
are cached for one minute; an unavailable or invalid feed supplies no update. Keep
the repository public and keep published assets immutable. Inactive plugins do not
load their update hooks; activate LeagueFlow before checking for GitHub updates.

The approach follows the deterministic packaging and verified-download design in
[Zoer Connect](https://github.com/ahzs645/zoer-connect), with assets served directly
from LeagueFlow's existing repository. No separate feed repository or deploy key is
needed. HTTPS and checksums verify transport/integrity; they are not independent
publisher signatures.

### Build and release

```sh
php tests/github-updater.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
python3 scripts/build.py
```

Outputs in ignored `dist/` are a deterministic plugin ZIP, `.zip.sha256` checksum
and `latest.json` manifest. Packaging includes the plugin entry point, uninstall
file, existing plugin documentation and runtime directories. It excludes Git,
workflows, audit documents, scripts and tests; unsafe runtime entries fail.

For a new stable release:

1. Update the plugin header and `LEAGUEFLOW_VERSION` to the same new `X.Y.Z`.
2. Add `releases/X.Y.Z.md` release notes, run the local checks, commit and push.
3. Wait for branch CI: PHP 8.1–8.4, package/publication tests, native security regressions and upgrade
   integration on disposable WordPress 6.5 and current stable sites.
4. Create an annotated matching tag (`git tag -a vX.Y.Z -m 'LeagueFlow X.Y.Z'`)
   and push it (`git push origin vX.Y.Z`).

Alternatively, after merging the version into `main`, open **Actions → LeagueFlow
checks and release → Run workflow**, select `main`, and enter `vX.Y.Z` in
**release_tag**. This repeats all checks, creates an annotated tag at the exact
tested commit, and publishes through the same verified-asset process. Leaving
the field blank runs checks only. A moved `main` stops creation of a new tag;
an existing matching tag can resume its original run after `main` advances.
A tag pointing at another commit stops publication; tags are never moved. The manual workflow
publishes in the same run because tags created with `GITHUB_TOKEN` do not trigger
another push workflow.

The tag workflow repeats all checks before publishing. It creates a draft,
verifies all three assets and then makes the release public. Reruns compare bytes
and can finish incomplete drafts; they never overwrite different existing assets.
An older release cannot displace a newer latest version. Prerelease/non-semantic
tags fail validation and do not publish. Never move a published tag or replace its
ZIP; increment the version instead. Branch and pull-request builds upload test
artifacts but do not publish releases. No additional GitHub secrets are required.

Native integration sets `LEAGUEFLOW_TEST_PACKAGE` to the built ZIP and runs
`wp eval-file tests/wordpress-security.php`, then
`LEAGUEFLOW_DISPOSABLE_WP=1 wp eval-file tests/wordpress-portal.php` and
`tests/wordpress-portal-http.php` and `tests/wordpress-admin.php`, then the standings,
scheduling, calendar, registration, REST identity, referee and public API suites, plus
automatic results, bracket synchronization, native REST guards, public result display,
admin workflow and per-sport setup regressions, followed by
`wp eval-file tests/wordpress-update.php` on an isolated
`http://leagueflow.test` installation with MySQL, PHP 8.2, WP-CLI, `zip`, `mysqli`,
`curl`, `gd` and the built plugin activated. The portal HTTP program starts PHP's
built-in server on `127.0.0.1` only (port `LEAGUEFLOW_HTTP_PORT`, default 8765). Controlled HTTP fixtures exercise real WordPress
update hooks and `Plugin_Upgrader`: both checksum rejection without file changes
and a successful next-version installation preserving active status and stored
data. Local isolated tests supplement this integration; they do not certify every
host, theme or league workflow.

## Architecture

- `lf_team`, `lf_player`, `lf_match`, `lf_calendar_event` and `lf_join_request` are shared custom post types used across all sports.
- `lf_sport` is the core taxonomy that assigns every team, player, and match to a sport module.
- `lf_league_level` assigns teams, players, matches, and calendar events to league levels such as Recreational and Competitive inside each sport.
- `lf_competition` and `lf_season` are taxonomies shared by teams and matches.
- `Sports_Manager` defines built-in sports, enables or disables sport modules, creates sport-specific admin menus, and exposes sport-specific match fields and icons.
- Standings are calculated from published, finished league results. Team point adjustments remain supported; knockout results are excluded.
- Knockout brackets are built from match meta (`lf_is_knockout`, `lf_round_label`, `lf_round_order`, `lf_next_match_id`, `lf_next_match_slot`).
- Frontend blocks and shortcodes use the same server-side renderer for consistent output.
- LeagueFlow ships portable public templates for team pages, match pages, and match archives. Classic themes use the bundled PHP wrappers, and block themes receive plugin-registered block templates unless the active theme or Site Editor provides a more specific override.

## Folder Structure

```text
leagueflow/
├── assets/
│   ├── css/
│   └── js/
├── blocks/
│   ├── knockout-bracket/
│   ├── league-table/
│   ├── match-card/
│   ├── match-list/
│   ├── team-list/
│   └── team-roster/
├── includes/
│   ├── class-activator.php
│   ├── class-admin.php
│   ├── class-assets.php
│   ├── class-blocks.php
│   ├── class-knockout-service.php
│   ├── class-plugin.php
│   ├── class-post-types.php
│   ├── class-renderer.php
│   ├── class-rest-controller.php
│   ├── class-seeder.php
│   ├── class-settings.php
│   ├── class-shortcodes.php
│   ├── class-sports-manager.php
│   ├── class-standings-service.php
│   ├── class-taxonomies.php
│   ├── class-template-loader.php
│   └── helpers.php
├── templates/
├── SPORTS.md
├── leagueflow.php
├── README.md
└── uninstall.php
```

## Installation

1. Copy the `leagueflow` folder into `wp-content/plugins/`.
2. Activate **LeagueFlow** in WordPress admin.
3. Visit `LeagueFlow > Sports` and enable the sports you want to support.
4. Review `LeagueFlow > Settings` for points, display defaults, and slug settings.
5. Create competitions and seasons from the LeagueFlow admin menu.

## Data Model

- **Sport**: taxonomy term used to modularize one shared data model across soccer, basketball, football, baseball, hockey, volleyball, cricket, rugby, and future sport modules.
- **League Level**: taxonomy term used with Sport to split each sport into levels such as Recreational and Competitive.
- **Competition**: taxonomy term.
- **Season**: taxonomy term.
- **Team**: post with short name, city, coach, founded year, description, and featured image as logo.
- **Player**: post with team assignment, jersey number, position, age, nationality, captain flag, and featured image as photo.
- **Match**: post with fixture data, scores, status, sport-specific notes, and optional knockout settings.
- **Venue availability**: saved admin availability windows that describe when a field or venue can host matches. Matches can link back to the availability window used by the scheduling assistant.

## Admin Usage

All LeagueFlow screens are available under the `LeagueFlow` menu, in workflow order.
A bar at the top of each screen shows where you are and switches the sport
(`All sports`, `Soccer`, …); edit screens link back to the list for the
record's sport. Enabled sports also have shortcuts to the shared screens.
`docs/ADMIN-WORKFLOW.md` describes the journey and every behaviour in detail.

### Setup

- Start at `LeagueFlow > Sports` and enable the sports you run.
- `LeagueFlow > Setup check` shows, per sport, what is configured and what is
  missing (levels, competitions, seasons, current season, teams without a
  level, records without a competition or season, venue availability). It is
  read-only; its mapping tool lets staff explicitly give a competition or
  season to teams or matches that have none, after a preview. Nothing is
  assigned automatically and existing assignments are never replaced.
- League levels are one list shared by every sport (`LeagueFlow > League Levels (all sports)`).
- Competitions and seasons each belong to one sport. Mark a season as the
  current season for its sport on its edit screen; other sports keep theirs.
  Blocks and shortcodes resolve an omitted season to the sport's current season
  when one is configured. The block inspector explicitly distinguishes
  **All seasons** (`all`) from **Current season** (blank or `current`). Admin
  screens show all seasons unless you choose Current season.

### Teams and Players

- Add teams from `LeagueFlow > Teams` and players from `LeagueFlow > Players`;
  `Add New` keeps the sport you are working in.
- Assign each team to a league level from the team details box. Long user and
  team lists have a filter box.
- Within a sport, the Players list shows that sport's team, number and position.
- Administrators can link an eligible subscriber or portal account, or provision
  a new account using the player's valid, unused email. New users receive a
  WordPress password-setup link. Linking keeps existing passwords unchanged.
  Account controls require `leagueflow_manage_player_accounts`, `edit_users`
  and `promote_users`; provisioning also requires `create_users`. Privileged
  users and your own account cannot be linked as player identities.

### Requests

- `LeagueFlow > Requests` (capability `leagueflow_manage_placements`) is one
  inbox for *Join a team* and *Needs placement* requests, with sport, level
  and status filters, search, a "needs attention" filter and a detail panel
  with history. The sport and level stay selected through tabs, paging, the
  detail panel and every action.
- Actions (approve, assign, close as already on the team, move to Needs
  placement, decline, dismiss, reopen a request whose status is unknown) are
  each protected by their own nonce and the review state the reviewer saw, and
  re-checked under a per-request lock (a lock that cannot be taken changes
  nothing). The primary decision is shown first; the others are under *Other
  decisions*. Captains reviewing their own team's requests in the portal use
  the same locked check, so staff and a captain cannot both decide. Every
  decision is recorded in the history. Declining or dismissing never removes
  anyone from a roster; nothing is deleted.
- Adding a player to a team from the player screen or the portal closes that
  player's matching pending request. Imports, migrations and demo data never do.

### Matches, fixtures and scheduling

- Add matches from `LeagueFlow > Matches`. Matches without a date stay listed
  and are labelled *Unscheduled* (there is an Unscheduled view).
- Match sport and level come from the selected teams, so cross-sport and
  cross-level fixtures are blocked.
- A Finished match needs both scores (whole numbers; 0–0 is fine) unless it was
  decided by forfeit.
- `LeagueFlow > Generate fixtures` previews a round-robin per level (division)
  for one sport, skips pairings that already exist, and creates drafts. The
  result links to exactly the new drafts.
- `LeagueFlow > Venues & availability` defines when venues are free. The
  scheduling assistant fills missing dates and venues from those rules; without
  rules it explains what to add first. Manual edits stay the override path
  unless the overwrite option is chosen.
- `Calendar Events` are non-match items (drop-ins, tournaments, closures).
- For knockout fixtures, enable `Knockout Match`, define the round label and
  order, and choose the next match from the valid later-round matches.
- Capabilities: venue configuration `leagueflow_manage_field_availability`;
  bulk scheduling `leagueflow_manage_schedule` plus edit rights on every
  affected match (overwrite also `leagueflow_overwrite_schedule`); fixture
  generation `leagueflow_manage_fixtures` and readable teams. Users without
  publication permission generate drafts. These are granted to administrators
  on upgrade; grant them explicitly to custom roles.

### Standings, brackets and scoring

- `LeagueFlow > Standings` shows one table per level for a sport, from finished
  published matches. The D (draw) column appears when a team has a draw.
- `LeagueFlow > Settings` holds the default scoring. *Scoring by sport and
  competition* lets a sport or competition use its own points and tie-breakers;
  until saved, everything inherits the defaults.
- `LeagueFlow > Knockout Brackets` previews the bracket. Winners advance when a
  knockout fixture is marked `finished`; use `Winner Override` for penalties or
  rulings.

## Gutenberg Blocks

- `League Table`
- `Sport Standings`
- `Team List`
- `Team Roster`
- `Match List`
- `Match Card`
- `Sports Calendar`
- `Knockout Bracket`

All blocks are dynamic server-side blocks and inherit the classic frontend styling from the plugin. League table, team list, match list, calendar, and knockout bracket blocks support `sport` and `league_level` attributes so editors can target a specific sport module and level. Sport Standings displays enabled sports split by league level as expandable standings panels.

### Theming

`assets/css/design-tokens.css` defines one semantic token set (`--lf-card`,
`--lf-muted`, `--lf-primary`, `--lf-accent`, `--lf-border`, `--lf-radius`,
`--lf-shadow-*`, `--lf-font-display`/`-label`, …) with zero-specificity
`:where(.leagueflow)` defaults. Every front-end view and the editor preview read
only these tokens, so a theme sets brand values on `.leagueflow` (or a wrapper)
without `!important` or stylesheet-order tricks. Layout follows CampusManager's
calendar: card shells, a muted pill-tab track, event cards with a rounded accent
pill in the sport colour, list dates with a hairline and a count pill, and on
phones a compact number grid with dots whose selected day's events form the
month card's footer. The older `--leagueflow-*` names still resolve to the new
tokens for existing custom CSS.

## Shortcodes

- `[league_table competition="spring-league" season="2026-spring" sport="soccer" league_level="recreational"]`
- `[sport_standings competition="spring-league" season="2026-spring"]`
- `[sport_standings sports="soccer,volleyball"]`
- `[team_roster team="123"]`
- `[match_list competition="spring-league" season="2026-spring" sport="soccer" league_level="competitive"]`
- `[knockout_bracket competition="2026-cup" season="2026-spring" sport="soccer" league_level="competitive"]`
- `[team_list competition="spring-league" season="2026-spring" sport="soccer" league_level="recreational"]`
- `[match_card match="456"]`
- `[match_calendar season="all" include_events="1" month_display="sidebar"]`
- `[leagueflow_referee]`

Shortcode attributes accept IDs or slugs where appropriate.

## REST API

Third-party integrations can use the dedicated **match events** and **finished
results** feeds:

- `/wp-json/leagueflow/v1/match-events?per_page=10`
- `/wp-json/leagueflow/v1/match-results?date=2026-10-31&per_page=20`

They include public team identities/logos, sport, league level, competition,
season, kickoff date/time/timezone, venue and result metadata. Date ranges,
latest-first results, ID/slug filters and bounded pagination are supported.
See [the public API guide](docs/PUBLIC-MATCH-API.md) for the complete contract,
examples, polling guidance and privacy rules. These additive endpoints follow
CampusManager's collection/metadata pattern and keep the older responses below.

Public read-only endpoints are available under:

- `/wp-json/leagueflow/v1/standings`
- `/wp-json/leagueflow/v1/matches`
- `/wp-json/leagueflow/v1/teams`
- `/wp-json/leagueflow/v1/bracket`
- `/wp-json/leagueflow/v1/league-levels`
- `/wp-json/leagueflow/v1/events`
- `/wp-json/leagueflow/v1/calendar/events`

Supported query parameters include `sport`, `league_level`, `competition`, `season`, `status`, `team`, `limit`, and `include_knockout`.

Matches use `limit` and `page`; calendar endpoints use `per_page` and `page`.
The default is 20 records per page and the maximum is 100. Zero/negative limits
use the default; oversized limits use the maximum. Calendar responses include
`total`, `pages` and `pagination`; filter metadata describes the requested page.
Date ranges (`start_date`, `end_date`), status, team, type and taxonomy filters
apply before database pagination. Calendar search includes titles, descriptions,
venues, teams and taxonomy labels. Match archives have page navigation.

Anonymous general collections omit fixtures referencing private or protected
teams, including when a password cookie exists. An explicit `team` filter verifies
that team's WordPress password cookie and includes its fixtures once unlocked;
the team's unlocked profile and recent matches remain available. Staff retain
private-team access through WordPress's normal permissions.

Embedded match lists default to 20. `[match_calendar]` starts with up to 100
records from the initial date range. Month/week/day views automatically complete
that range in bounded requests; the upcoming list loads additional pages through
**Load more events**. Each request retains the block/shortcode scope and is capped
at 100; `limit` controls batch size. Earlier schedule history is not loaded merely
to open a later month.

Both `POST /calendar/events` and `POST /events/create-calendar-event` require
WordPress event creation/editing permission. Users who can publish default to
published events; other authors default to draft. Explicit publication, scheduled
publication and private status require their corresponding WordPress permissions.
Existing terms require assignment permission, and new terms additionally require
taxonomy management permission.

## Demo Data

Demo data is for empty development sites only. It is available when
`WP_ENVIRONMENT_TYPE` is `local` or `development` (or `LEAGUEFLOW_ALLOW_DEMO_DATA`
is true). `LeagueFlow > Settings` shows a read-only preview; any existing league
record, or a page/term using a demo slug that the seeder did not create, blocks
the run before anything is written. After ticking the confirmation box,
**Create demo data** adds (and never overwrites):

- Sample competitions and a season
- Recreational and Competitive league levels (existing levels are reused, not renamed)
- Four teams
- Five players per team
- League fixtures
- Cup semifinals and a final with automatic winner advancement
- Example frontend pages using sport-aware shortcodes

## Local Testing Notes

1. Activate the plugin locally.
2. On an empty development site, preview and create demo data from Settings.
3. Visit the generated pages:
   - `League Table`
   - `Fixtures & Results`
   - `Cup Bracket`
4. Edit a finished match score and confirm the standings update.
5. Edit a knockout match, mark it finished, and confirm the winner advances into the configured next match.
6. Insert LeagueFlow blocks in the block editor, select a sport, and compare frontend output.
7. Review [SPORTS.md](SPORTS.md) for the built-in sport model notes and research references that shaped the modular field design.

## Security and Standards

- Uses capability checks and nonces for admin actions.
- Sanitizes and escapes data in admin and frontend output.
- Translation-ready with the `leagueflow` text domain.
- Avoids external frameworks and uses WordPress-native APIs.
