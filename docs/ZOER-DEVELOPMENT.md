# LeagueFlow development and interface audit in Zoer

Clone `https://github.com/amirrad98/intramurals.git` in **Repos**. For an existing
local installation, open **WordPress → local site → Plugins → Leagueflow →
Development**, select that repository, and save a link to the installed
`leagueflow` folder. The plugin's updater (`Update URI`) and release builds use
`amirrad98/intramurals`; linking a development repository does not change that
public update feed.

## Build and local update

1. Choose **Inspect repository and prepare build**, then **Start development
   computer**. The attached computer owns the shared repository files.
2. Save the detected PHP lint/package command with **Save settings and build
   now**. Add `__pycache__` to **Additional ignored folders** for Python tests.
3. Link **Plugin folder in repository** to `dist/leagueflow`. The checked-in
   `.distignore` excludes scripts, tests, release notes, audit documentation,
   caches and dependencies from Zoer's generic staging helper. Keep its inventory
   aligned with `ROOT_FILES` and `RUNTIME` in `scripts/build.py`.
4. Edit the attached computer's source through Repos Files, Terminal or Chat.
   Wait for a successful build of the current source, then **Review local update**.
   The review should contain only the runtime distribution. An exact match needs
   no replacement. Otherwise inspect the file changes and **Update local site**.
5. Open **Preview site** and **WordPress admin** to verify behavior. Code rollback
   restores previous files; it does not undo saved posts or league records.

PHP/Composer installation and build-on-save belong to the development computer.
The WordPress site uses DDEV's own PHP/database. A clean Git tree, a successful
build and a browser test are separate results. `python3 scripts/build.py` remains
the canonical deterministic release builder; Zoer staging is for local development.

## Use a separate Claude audit chat

Choose the intended computer in **Chats → Computer for new chats** and select
its live Claude model/effort. Add explicit composer references for this repository,
the local WordPress site, and WordPress Manager. A reference is context, not a
repository mount or a production deployment grant.

An authenticated CLI can still fail at inference if its OAuth refresh expires.
After one bounded retry, complete the supported Claude sign-in on that computer;
do not copy another computer's credentials or change models silently.

For browser and authorized local editing, the named chat needs Full access. In
the chat, use `chat_prepare_repository` to create a committed-source working copy
when the repository is not mounted. Retain `copyId`, `sourceCommit`, `baselineCommit`
and its returned path. Edits there do not trigger the shared repository's watcher.
Stage only intended files and use `chat_request_repository_publish` to prepare a
reviewed patch import. Once that patch reaches the original Zoer repository, the
attached computer rebuilds it; review the WordPress update separately.

Commit the original repository's intended setup changes locally before preparing
the audit copy. Import requires a clean original worktree at that exact source
commit. If its HEAD changes, prepare a fresh copy and reconcile the patch. The
native tool budget is per turn and shared by subagents; save coverage and continue
in bounded batches rather than waiting for a cooldown. Keep an attempt log, but
supersede old blocked rows in the current coverage matrix after a successful retest.

Use `chat_browser_prepare` with the selected provider or explicitly attached saved
profile. `browser_session_snapshot` supplies DOM and visual evidence;
`browser_session_resize` supports desktop 1440×900, mobile 390×844 and custom
sizes. This is viewport testing, not physical-device certification. For local
admin access, `create_wordpress_admin_link` produces a short-lived handoff; open
it promptly in the audit browser and keep its secret URL out of reports. Protected
Zoer deployments require the dashboard's session-bound admin handoff.

Use `list_wordpress_files`, `read_wordpress_file` and bounded read-only
`run_wordpress_cli` for site/source inventory. Direct, hash-checked WordPress file
edits are a separate path and can diverge from maintained repository source.
Do not use shell HTTP to replace a missing native tool or evade an approval.

## Audit coverage and evidence

Build a coverage table from the actual WordPress Pages inventory, frontend links,
active theme templates, plugin blocks, and registered admin menus. Give each row
a URL, viewport, authentication state, exercised interactions, result, screenshot
or receipt, and any blocker. A source-only or inaccessible row stays untested.

Cover all published pages and their navigation, public team/match detail routes,
sport variants, empty results and unavailable selections. Test desktop and mobile
navigation, keyboard focus, readable labels, horizontal overflow and form errors.
For the calendar, exercise Month/List/Week/Day, date navigation, Today, search,
filters and match/event details. Check tables, rosters and brackets with populated
and empty selections.

Verify all nine blocks in both Gutenberg and their rendered frontend output:

- `leagueflow/knockout-bracket`
- `leagueflow/league-table`
- `leagueflow/match-calendar`
- `leagueflow/match-card`
- `leagueflow/match-list`
- `leagueflow/portal`
- `leagueflow/sport-standings`
- `leagueflow/team-list`
- `leagueflow/team-roster`

Inspect global LeagueFlow Overview, Sports, League Levels, Fixtures, Field
Availability, Placements, Utilities and Settings. For each enabled sport, cover its
overview, teams, players, join requests, matches, availability, events, levels,
competitions, seasons, fixtures, standings and brackets. Inspect WordPress Pages,
the Site Editor and plugin status too. Use disposable audit content and a backup
for write tests; preserve actual participant records and scores. Fixture seeding,
uninstall and upgrade/security integration tests belong on disposable installations.

Keep anonymous, administrator and participant-role tests distinct. Administrator
login does not certify student/captain/referee permissions. Do not include private
participant records, login handoffs or credentials in audit artifacts.

Record missing capability precisely: tool name/schema, permission mode, target,
observed error, reproduction and impact. Distinguish missing tools from expired
authentication, a stopped resource, provider limitations, capacity and approvals.
An empty console log from a provider that cannot capture console events is not
evidence of no errors. Fix repository code, run the existing checks, import the
reviewed patch, build, update the local plugin, and retest affected coverage before
publishing anything.

## Automated calendar regressions

Run `npm ci && npm run test:calendar` for 63 source-level DOM checks (21 per
timezone) across America/Vancouver, UTC and Asia/Tokyo. They exercise site
wall-clock placement, calendar export instants, date labels, keyboard containment,
focus restoration and Week/Day lanes for overlapping events
(`tests/calendar-lanes.cjs`: two and three simultaneous events, a chained overlap,
adjacent non-overlapping events, the same time on different days, per-event
dialogs/links and search before layout; all synthetic data).
The same checks gate releases in CI. They complement the real WordPress browser
audit; jsdom supplies no actual rendering. Live check on the local Intramurals
copy (installed `4b12f09`, 2026-10-14, Playwright Chromium at 1440x900, logged
out): the two simultaneous Sunday 5:30 pm matches render in two Week lanes and
two Day lanes, each lane opens its own dialog and Details link, back-to-back
Thursday matches stay full width, and List/Month keep 6:30 pm. Narrow Week lanes
truncate team names; the dialog, Day and List views show them in full. Root's
DOM readback (reported, not captured here) found the Week pair on one vertical
interval with a 2px gap, and Day view at 768/390/320 with equal positive lane
widths, 2px gaps and no page overflow; at 320 both lane buttons open the correct
dialogs and Escape closes them. Week is intentionally not offered in the phone
view controls. None of this is physical-device evidence. Node manifests, test files and installed
dependencies are excluded from both development and canonical runtime packages.

The site's configured timezone controls labels and calendar export instants.
Confirm that configuration separately before publishing real schedules; changing
it is not part of a code-only local plugin update.

The isolated admin filter regression runs with `php tests/admin-filters.php`. It
checks that join requests filter by sport/level metadata while team lists retain
their taxonomy filters. CI runs it on PHP 8.1–8.4; the stubbed query fixture does
not replace the local WordPress list-table acceptance check.

`tests/wordpress-portal.php` runs only inside a disposable `http://leagueflow.test`
WordPress with `LEAGUEFLOW_DISPOSABLE_WP=1`; CI runs it after the security suite on
WordPress 6.5 and latest. It fires the real portal `admin_post` handler with real
nonces and roles, captures the redirect notice, blocks outbound HTTP and captures
mail. It covers roster add/remove, join requests and reviews, availability,
own/other team and player edits, invalid nonces/IDs, duplicate submissions and
upload type rejection (rejected files leave profile/team text unchanged), plus
team creation and player onboarding permission/validation paths. Join-request
reviews carry the request state token: a stateless or stale form, a staff decision
made first, and a decision stored while the captain waited for the request lock are
all refused with no roster change, history entry or mail. It is not an HTTP test. Never run it against the
Intramurals database.

`tests/wordpress-portal-http.php` is the HTTP counterpart, with the same
`LEAGUEFLOW_DISPOSABLE_WP=1` and `http://leagueflow.test` guard. It creates its own
`http_*` fixtures, writes a test-only must-use guard (blocks outbound HTTP, logs mail
to a temporary file, removed afterwards), starts `php -S 127.0.0.1:<port>` and sends
requests with `Host: leagueflow.test`. It logs in through `wp-login.php` with cookies,
scrapes nonces from the rendered portal forms and posts to `wp-admin/admin-post.php`,
including multipart uploads. It covers own saves and reloads, forged cross-team
posts, missing/forged/other-user/wrong-action nonces, roster add/remove, duplicate
submits, join request/review (the review form's rendered state token, the same form
with the token removed, and a page loaded before the request changed), availability, malformed and privileged invites, and
PNG upload success plus PHP/SVG/oversize rejection. A rejected photo or logo leaves the
submitted text fields unchanged. It also posts the rendered team-creation and player
onboarding forms with invalid levels, sports, names, team choices and files, then one
valid multipart team creation. Waivers/terms are not exercised. It does not
test a browser, JavaScript, a production web server or physical devices.

Run `php tests/asset-versions.php` to verify that every plugin asset
(`frontend.css`, `admin.css`, `editor.css`, `calendar.js`, `portal.js`,
`blocks.js`, `admin.js`) is versioned by plugin version plus a content hash, so a
development update with an unchanged plugin version is fetched fresh after a
reload even if an installer keeps the package's fixed 2020-01-01 file dates.

`tests/wordpress-admin.php` covers the admin workflow foundations tracked in
`docs/ADMIN-WORKFLOW.md`, with the same `LEAGUEFLOW_DISPOSABLE_WP=1` and
`http://leagueflow.test` guard. It drives the real `wp_edit_posts_query()` list
query (undated matches/events in default, Trash, date-sorted and Unscheduled
views), sport-scoped status counts, match score validation, competition/season
sport rules, accessible names and select options in rendered metaboxes, the
guarded create-only demo data (edits to earlier demo objects survive a rerun; a
record appearing after the preview stops the run), list counts that match what an
Author can see, the shared LeagueFlow screens, enabled sport menus, sport context and legacy page
aliases, and the Requests inbox (nonce/role/ID guards, flags, reviewed actions,
history, unknown legacy statuses, per-request locking checked with a second
database connection, stale forms, and reconciliation only from explicit roster
entry points). Final-review cases (G2–G4, H1–H5 in `docs/ADMIN-WORKFLOW.md`)
inject faults inside the test only: `GET_LOCK` turned into `SELECT NULL`, a change
made just before the lock query, a second MariaDB connection holding a lock, one
refused term-relationship INSERT and one refused match insert. They check that
locks fail closed, HTTP decisions need the state token, authority and record state
are re-checked after the lock, captain and staff share one locked review path, and
mapping/generation report applied, failed and incomplete records truthfully. Later
groups cover per-sport current seasons with the legacy
flag fallback and explicit All/Current season filters (including Brackets), the
Setup check and its previewed, single-use competition/season mapping, fixture
generation by level with duplicate, stale and forged-scope protection, scoring
overrides that inherit global settings (clearing them restores identical
standings), scheduling prerequisites, knockout target choices and the scoped
team-member lookup. Demo runs happen inside a database transaction that is
rolled back. CI runs it after the portal HTTP suite on WordPress 6.5 and
latest. Never run it against the Intramurals database.

Run `php tests/admin-assets.php` to verify that the plugin stylesheet loads on
Placements, Fixtures, Utilities and the other supported admin screens without
loading on unrelated WordPress screens. This source contract runs in the PHP CI
matrix; use a real mobile page reload to verify the resulting layout.

The canonical build ignores ordinary Finder `.DS_Store` files, so browsing the
source on macOS does not break packaging. Hidden directories, credentials and
symbolic links remain rejected; the metadata regression checks identical ZIP bytes.


Saved match values use the WordPress site timezone. For the Intramurals local copy,
existing game times were confirmed to mean Prince George/Vancouver time; its
`timezone_string` is `America/Vancouver`. The shared card/admin formatter parses
that wall-clock value in `wp_timezone()` before formatting it. It does not rewrite
stored match records or titles. `php tests/match-datetime.php` covers summer and
winter offsets, explicit UTC instants, PHP default-zone independence and invalid
dates; the old formatter fails this fixture. Real Chromium timezone overrides in
UTC, Vancouver and Tokyo all displayed October 15 at 6:30 pm, while Google/ICS
exports encoded October 16 at 01:30 UTC. Physical-device acceptance remains pending.
