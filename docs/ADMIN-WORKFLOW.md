# LeagueFlow admin workflow

## Current scoring and setup behavior (1.1.1 review)

The current changes and acceptance results are documented in
[Scoring and admin review](SCORING-ADMIN-REVIEW-2026-10-21.md). Saving complete scores
on a Scheduled match now automatically finishes it; the keep-status checkbox
preserves deliberate provisional/reopened states. Live, Postponed and Cancelled
remain explicit. Result corrections synchronize eligible linked bracket fixtures.

Lists now preserve the full sport/level/competition/season scope, match lifecycle
has its own filter, and per-sport rules inherit by field with explicit zero values.
Competition/season ownership is checked before edits can mix sports.

The older audit and installed-site evidence below are retained as historical
records. They do **not** mean the 1.1.1 changes have been installed or browser-verified
on that site. The current review used fresh disposable WordPress installations.

## Historical admin audit

This document describes the intended administrator journey and tracks every
finding from the 2026-10-14 read-only admin coherence audit (local Intramurals
copy, LeagueFlow 1.0.3, canonical `7483fc0`). Work is delivered in reviewed
batches on `codex/leagueflow-admin-workflow`.

Status columns are separate on purpose:

- **Implemented**: changed in maintained source (batch number).
- **Tested**: automated evidence. `ADM-xx` = `tests/wordpress-admin.php` on a
  disposable WordPress (6.5 and 7.1.3); `unit` = standalone PHP harness;
  `fixture-browser` = local headless Chromium against the disposable site
  (layout only, not the live site).
- **Applied**: installed on the local Intramurals copy through the reviewed
  WordPress Manager development update (`ce16748`, job `4bede15b`, digest
  `9e8483e5…41fa3`). `live LAxx` = rendered and checked in a native Playwright
  Chromium browser on that copy (see "Installed-site acceptance"); `root live`
  = root's own browser/DOM check; `installed` alone = installed but not
  rechecked live (source and disposable-site tests only).

## Intended journey

1. **Setup**: enable sports; League Levels are one global list shared by every
   sport; competitions and seasons each belong to exactly one sport.
2. **Rosters and requests**: teams, players, join requests and placements.
3. **Generate fixtures** for one sport and one division (level).
4. **Schedule** the generated drafts from venue availability, or by hand.
5. **Review and publish** matches; undated matches are visibly *Unscheduled*.
6. **Score** finished matches with explicit scores or a forfeit result.
7. **Standings and brackets** are computed from finished matches.

## Behaviour changed in batch 1 (foundations)

- **Lists keep undated items.** Matches and Calendar Events sort by their own
  date through a `LEFT JOIN`, so rows without a date are never dropped (the
  default `meta_key` sort used an `INNER JOIN`). Default order: *Unscheduled*
  first (they need action), then newest date. Explicit column sorts put empty
  values last in both directions. The same join is used for every LeagueFlow
  meta column sort (short name, jersey number, status…). A new **Unscheduled**
  view lists items with no date, and the date cell says "Unscheduled".
  Existing records are not modified; the 190 undated trashed matches on the
  Intramurals copy simply become visible in Trash.
- **Status views stay in context.** Inside a sport (or level) the
  All/Published/Draft/Private/Trash counts are recomputed for that context and
  their links keep `lf_sport`/`lf_league_level`.
- **Sort indicator.** The Date / Time column carries the initial descending
  sort marker; the publish Date column no longer claims it.
- **Scores.** A match saved as Finished and *played as normal* needs both
  scores. Scores are whole numbers of 0 or more, with no sport-specific cap; the only
  limit is the largest integer PHP stores exactly (`PHP_INT_MAX`), and larger
  values are refused with that limit named. A blank score is never turned into
  0; an explicit 0–0 is kept; forfeits may finish without a score. Invalid
  input is refused before any LeagueFlow metadata is written (core still saves
  the title/status of the post itself, as for the other validation errors).
- **Competition/season sport.** The add form requires a sport (server-side
  check on `add-tag`); a blank edit keeps the stored sport; Quick Edit and
  programmatic updates no longer reset the sport to the first enabled one.
  With a single enabled sport it is preselected.
- **Demo data** is development-only (`WP_ENVIRONMENT_TYPE` local/development,
  `LEAGUEFLOW_ALLOW_DEMO_DATA`, or the `leagueflow_allow_demo_data` filter).
  Settings shows a read-only preview. It is strictly create-only and only for
  an empty league: any existing league record (any status except WordPress's
  own auto-drafts), or any page/term/record using a demo slug, blocks the run
  before a write, **including objects an earlier demo run created** (the
  `_leagueflow_demo_seed` marker is never treated as permission to overwrite;
  remove earlier demo data by hand to create it again). A run requires a
  confirmation box and the preview token; the token fingerprints the existing
  IDs, modification times and league-record counts, so a changed site is
  "stale". If something with a demo slug appears after the preview, creation
  stops with a collision error listing what it had created; the colliding
  record is never adopted, assigned or edited. League levels are reused
  without renaming; only newly created competitions/seasons get a sport.
- **Unambiguous selects.** Selects that always have a value (schedule mode,
  league level, next-match slot, event type/status) no longer show a blank
  first option captioned like a real one.
- **Phones.** Player memberships become labelled cards below 783px; scroll
  regions no longer widen their parent (Player edit was 784px wide at 390/320,
  sport Overview 338–343px at 320). Placements stacks each request with a
  visible "Team" label and a full-width Assign button below 601px. On LeagueFlow
  edit screens below 851px the record's own details come before the taxonomy
  and Publish panels (Publish is at the end of the form on phones).
- **Searchable long selects.** `assets/js/admin.js` (no dependencies) adds a
  filter box to Team Managers, Linked User and Add-to-team. Typing hides
  non-matching options (selected ones stay), a polite live region announces the
  count, and Enter never submits the post form. Without JavaScript the plain
  select still works.
- **Accessible names.** Membership No./Position inputs and Placements team
  selects have contextual names; Overview count rows use row headers; the
  level breakdown is a focusable, labelled scroll region.
- **Asset cache keys.** `admin.css`, `editor.css`, `blocks.js` and the new
  `admin.js` use plugin version + content hash like the frontend assets.

## Behaviour changed in batches 2–3 (navigation, context, requests)

- **LeagueFlow and enabled sport menus.** LeagueFlow retains the shared workflow
  screens. Each enabled sport also has its own sidebar entry and filtered links
  to the same setup, roster, request, match, scheduling and standings screens.
  Global settings and the shared League Levels list stay under LeagueFlow.
  Add links remain on the relevant screens. Disabled sports have no sidebar entry.
- **Sport context bar** above every LeagueFlow screen: breadcrumb (LeagueFlow ›
  sport › level › screen) and an "All sports / Soccer / …" switcher that keeps
  the current screen and resets paging and sport-specific filters. Edit/add
  screens show "Back to Teams (Soccer)" for the record's own sport; core Add
  New links carry `lf_sport` (server-side `admin_url` filter, LeagueFlow paths
  only). The League Levels screen states that levels are shared by all sports
  and that counts include every sport.
- **Old deep links keep working.** `admin.php?page=leagueflow-sport-{sport}`,
  `…-fixtures`, `…-standings`, `…-brackets`, `…-fields` and
  `leagueflow-placements` stay registered as hidden aliases (same
  capabilities) that redirect to the unified screen with `lf_sport` (and keep
  competition/season/level/request filters). `edit.php?…&lf_sport=` links were
  already native.
- **Per-sport tools ask for a sport.** Generate fixtures and Knockout Brackets
  no longer silently default to the first enabled sport; without a sport they
  show a "choose a sport" page. Standings without a sport show every sport
  grouped by level; with a sport, the table plus a note when levels are mixed.
- **Players in a sport** show only that sport's team, number, position and
  captaincy, its level, and "(also Basketball…)" for other sports (C-06).
- **Overview** has a *Needs attention* queue (join requests, players needing
  placement, requests with problems, undated matches, draft matches, missing
  venue availability), in-context work links (including Add links), and either
  a per-sport summary or the sport's counts and level breakdown.
- **Names and empty states.** "Generate fixtures", "Venues & availability",
  Calendar Events described as non-match items; list and term empty states say
  what to do next (C-10/C-11); the Utilities placeholder row is gone (C-14).
- **Lists and forms.** Secondary Matches/Teams/Players columns are hidden by
  default (Screen Options shows them); the Fixture column is wider. The core
  League Level box is removed from Team/Match/Event edit and the core Sport box
  from Match edit, because the details box already sets them (U-06).
- **Requests inbox** (`admin.php?page=leagueflow-requests`, capability
  `leagueflow_manage_placements`, pending count in the menu): tabs *All /
  Join a team / Needs placement*, statuses *Pending / Approved / Declined /
  Dismissed / All*, sport filter, player search, "only requests that need
  attention", paging, and a detail panel with the player's current team in the
  sport, the note, flags and history. Actions, each in its own form with an
  action- and record-bound nonce, and re-validated before any write:
  *Approve and add to team*, *Assign to team* (eligible published or private
  teams in the requested sport and level), *Close: already on the team* (no
  roster change), *Move to Needs placement* (target trashed/missing/draft/
  wrong sport or level; keeps the original team), *Decline* and *Dismiss*
  (reason required; never remove anyone from a roster; nothing is deleted).
  A request without a player can only be dismissed — no player is invented.
  Flags: player missing, team missing/trashed/not published, sport or level
  mismatch, already on the requested team, already on another team in the
  sport. Optional e-mails reuse the existing placement wording (on by default
  for approve/assign, off for decline).
- **Reconciliation.** When a roster gains a membership (portal, admin player
  or team save, request approval), the same player's *pending* requests that
  the new membership satisfies are closed as approved with a "Closed by a
  roster change" history entry. It never runs on activation, upgrade or page
  view, never touches other sports/levels, and is idempotent. Existing stale
  requests are only flagged until someone chooses a reviewed action.
- **Request records** can no longer be created empty by hand
  (`create_posts` → `do_not_allow`); the classic edit screen shows a read-only
  summary that links to the inbox review; list rows get a *Review* action.
- **Counts follow core visibility.** Context status counts use
  `perm => readable`, so an Author/Contributor sees counts for exactly the
  rows core lists for them (other users' private records are not counted);
  core's *Mine* view is kept and counted in context.
- **Scores** have no sport cap: any whole number up to PHP's integer maximum.

## Changed after review of batches 1–3

- **Demo data (review A + sport terms).** Seeding is strictly create-only.
  It no longer calls the sport-term synchroniser: missing enabled sport terms
  are created, existing ones (and any name or description an editor gave
  them) are reused untouched. Note: LeagueFlow's existing `init` hook still
  re-synchronises enabled sport names from the sport definitions on every
  request, as it did before this branch; that pre-existing behaviour is not
  changed here.
- **Requests (reviews F1–F6).**
  - *F1* The generic membership setters (`set_player_team_ids`,
    `assign_player_to_team`, the one-time migration, demo data, portal
    rollbacks) never reconcile requests. Only explicit, authorized roster
    entry points call `reconcile_roster_additions()`: the player edit screen,
    a staff or captain roster add in the portal, a portal join-request
    approval, captain team creation (after every rollback point) and an inbox
    approval/assignment.
  - *F2* A missing or unrecognised stored status is shown as *Needs status
    review*, never as pending: the only actions are *Reopen as pending*
    (reason required; the old value is kept in `lf_previous_request_status`
    and the history) or *Dismiss*. Reconciliation requires an exact `pending`
    status and the same sport, level (when stored) and team (named requests).
  - *F3* *Close: already on the team* for a placement request needs exactly one
    membership that fits the requested sport and level. Otherwise the request
    is flagged *membership conflict* and only Decline/Dismiss are offered. A
    trashed, missing or wrong-sport/level named target is never treated as
    satisfied because membership meta still contains its ID.
  - *F4* Teams in any roster status (published, private, draft, pending,
    scheduled) are valid targets for staff who can edit them; drafts are marked
    "(draft, not public yet)" and flagged as a warning, not as nonexistent.
    Assignable teams are filtered per team with `edit_post`, so another user's
    private team title is never offered.
  - *F5* The shared service checks `leagueflow_manage_placements` and
    `edit_post` on the request before every action (not only the screen
    handler), plus `edit_post` on the team and player for roster actions.
    Captains keep their existing owned-team review in the portal and get no
    inbox access.
  - *F6* One shared service instance (`Join_Request_Service::shared()`;
    a second instance cannot register a duplicate hook). Every action runs
    under a per-request database lock (`GET_LOCK`), re-reads the stored state,
    and checks the state token the reviewer saw. Double submissions, stale
    forms and a concurrent reviewer are refused without writes; history is
    appended once per decision.

## Changed after the final review (G1–G4, H1–H5)

Implemented in maintained source and tested on disposable sites only; not yet
applied to the Intramurals copy.

- **G1 (draw policy).** No change needed: nothing infers a no-draw rule from
  a sport's description. The admin standings show the D column only when a
  row in that table has a draw; points always use the effective rules, which
  equal the global settings until a sport or competition override is saved.
- **G2 (division context).** The Requests inbox has a *Level* filter
  (`lf_league_level`) that is kept on the type tabs, status counts, search,
  paging, the detail panel, every action's return address and the Back link.
  Requests and players have no competition or season of their own, so neither
  screen offers an always-empty competition/season filter; both say so and
  point to the team lists.
- **G3 (request record state).** A request in the Trash, an auto-draft or any
  status other than published/private/pending/draft/scheduled is flagged
  *cannot be reviewed*, offers no actions, and is refused inside the locked
  re-check for every path (staff, captain, reconciliation).
- **G4 (decision layout).** The primary decision (Approve, Assign or *Close:
  already on the team*) stays visible. Decline, Dismiss, *Move to Needs
  placement* and *Reopen* sit in a native `<details>` disclosure (keyboard and
  no-JS friendly), each its own form with an action- and record-bound nonce
  and a required reason; it starts open when there is no primary decision.
- **H1 (locks fail closed).** All LeagueFlow named locks go through
  `acquire_db_lock()`: only an explicit `1` from `GET_LOCK` counts. A timeout,
  an error or an unavailable lock service (`NULL`) changes nothing and shows
  a "busy or lock unavailable, nothing changed" message. This covers request
  review, reconciliation (the request stays pending), fixture generation and
  mapping.
- **H2 (HTTP freshness).** The inbox handler requires a well-formed state token
  (16 hex characters) on every POST; an omitted, empty or malformed token is
  sent to a fresh review without writes. The service checks the token whenever
  the `state` key is passed (even empty). Programmatic callers that omit the
  key keep every other check. The deprecated Placements form no longer writes:
  after its capability and nonce checks it opens a fresh review.
- **H3 (locked re-checks).** After taking the lock and clearing the caches the
  service re-reads the request record and its post state, re-checks the
  caller's authority (staff capability and `edit_post`, or the captain's
  managed-team check), then the business state and token. An unknown status
  with a missing player can only be dismissed; dismissing keeps the raw stored
  status in `lf_previous_request_status` and names it in the history.
- **H4 (one review path).** The portal captain review now calls
  `Join_Request_Service::apply_team_manager_decision()`: the same lock, fresh
  re-read and state token as the inbox. Captains keep their owned-team
  authority (passed as a callback) and get no inbox access; placement requests
  stay staff-only. The portal form carries the state token; a stale or
  stateless form shows *That request changed or was reviewed by someone else*
  and changes nothing. History and the decision e-mail happen once, only for
  the winning decision. Older portal requests without a stored sport keep the
  managed team's sport, as before.
- **H5 (mapping and generation writes).**
  - Mapping apply takes a site-wide mapping lock, clears the records' term
    caches, recomputes the plan (capability, sport context, records, existing
    assignments) and compares the user-bound preview token, then consumes the
    preview once (`delete_transient` must succeed). Of two conflicting
    previews only the first applies; the second is stale and writes nothing.
  - Fixture generation records every refused match insert (`failed_pairings`)
    and every match saved without part of its scope (`incomplete_ids`, kept
    for review, never deleted). An early stop keeps the earlier levels' work.
    The result always has the same keys, including `scheduled`,
    `unscheduled`, `partial` and `partial_error`; the notice reports each
    count. Generated matches also store their scope (`_lf_fixture_scope`), so
    the duplicate check still sees a match whose terms could not be saved and
    a fresh preview offers only the pairing that failed.
  - WordPress core boundary: `wp_set_object_terms()` does not check its
    relationship INSERT, so a refused write can still return term IDs. Mapping
    and generation therefore read each assignment back
    (`object_term_saved()`) before reporting it as applied. Writes are not
    all-or-nothing: successful ones are kept and failures are listed for a
    fresh preview.

## Behaviour changed in batch 4 (seasons, setup check, mapping)

- **Current season per sport (C-07).** Marking a season current stores it for
  that season's sport only (`leagueflow_current_seasons`). Other sports keep
  theirs. The Seasons list has a *Current* column. Legacy data keeps working:
  a season flagged with the old site-wide `lf_is_current` meta is still used
  exactly as before while no sport has its own current season; once a sport
  has one, that sport's legacy flag is superseded and another sport's legacy
  flag no longer leaks into it. A season without a sport keeps the old
  site-wide flag. Deleting a season removes it from the map; caches are reset
  after every change.
- **All / Current / one season (N-02).** `resolve_season_context()` accepts
  `all` (every season) and `current`. Admin season filters default to *All
  seasons*, offer *Current season (name)* explicitly, and never turn All into
  the current season; Knockout Brackets passes `all` to the renderer. Public
  blocks and shortcodes are unchanged: an empty season still means "current
  season if one applies, else all", now using the block's own sport.
- **Setup check (C-16, C-09, C-11).** New screen *LeagueFlow › Setup check*
  (`edit_others_posts`). Read-only per sport: shared levels, competitions,
  seasons, current season, teams, teams without a level, teams/matches without
  a competition or season, matches, venue availability rules. Opening it never
  writes.
- **Explicit mapping (C-16).** On a sport's Setup check, staff can give a
  competition or season to teams or matches that have **none**. LeagueFlow
  never guesses: the person picks the sport, type, target (owned by that
  sport) and each record (nothing preselected). A preview lists every change;
  records that already have one, belong to another sport, are trashed or not
  editable are conflicts that block the whole apply. Applying needs the
  confirmation box, the action nonce and the stored single-use preview, and
  re-validates the records' current state (title, status, sport, modified
  time); anything changed is refused as stale before the first write.
  Existing assignments are never replaced. Nothing runs on activation,
  upgrade or page view.
- **List filters.** Teams and Matches lists can be filtered by competition and
  season, including *No competition* / *No season* (the Setup check links to
  those views). Choices name their sport when *All sports* is selected.
- **Overview.** A sport's Overview shows its current season; the attention
  queue counts teams and matches without a season and links to Setup check.

## Behaviour changed in batch 5 (fixtures, scheduling, scoring)

- **Generate fixtures (U-05, C-09).** The page is a server-side preview: teams
  are grouped by level (division) and fixtures are only made within a level,
  matching the manual Add Match rule. Teams without a level are named and left
  out. With *All levels* and several levels in scope, a person must pick one
  level or explicitly choose *Generate each level separately*. Each level
  shows new pairings and pairings that already exist (same sport, level,
  competition and season; knockout matches ignored); duplicates are never
  recreated. Generating re-runs the preview under a per-sport lock and
  requires the preview token, so a double click, a stale page (a team added,
  another run finished) or a forged competition/season from another sport
  creates nothing. Created drafts are tagged with the run and the result
  notice links to exactly those drafts (`lf_batch`). Without venue
  availability, the scheduling option is replaced by the prerequisite and a
  link; with it, the result reports how many fixtures got a date and how many
  stay *Unscheduled*. Generated drafts have no slug until first publication,
  so their address comes from the then-current title (P-01); published slugs
  stay stable.
- **Scheduling assistant (C-09, C-13).** With no availability rules the
  assistant form is replaced by an explanation and links (availability form,
  Unscheduled matches). With *All sports*, competition and season choices name
  their sport, and a competition or season from another sport is refused
  before anything is scheduled. Manual scheduling and the existing overwrite
  capability are unchanged.
- **Knockout targets.** *Advance winner to* lists only later-round knockout
  matches that the existing `can_advance_to()` validation accepts (same sport,
  level, competition and season, no loop), labelled with round, title and
  date. An existing link that is no longer valid stays visible and marked.
  Saving still uses the same validation.
- **Scoring by sport and competition (C-08).** *Settings › Scoring by sport
  and competition* shows the scoring each sport (and customised competition)
  uses and where each value comes from. Every scope inherits the global
  settings until someone saves an override (`leagueflow_rule_overrides`);
  unticking it inherits again. A competition override applies only to its
  own sport's tables. With no overrides the standings are byte-for-byte the
  global results. No league policy is invented and nothing is migrated.
  Tie-breaker and forfeit labels are sport-neutral.
- **Standings (U-05, C-08).** A sport's admin Standings shows one table per
  level by default (or the chosen level), states the table points in use, and
  shows the D (draw) column only when a team in that table has a draw. Public
  blocks are unchanged.
- **Team save (C-15).** Re-synchronising a team's sport/level onto its players
  now loads only that team's members (database pre-filter on the stored
  membership meta, confirmed per player; private, draft and legacy string
  IDs included) instead of every player on the site. The public roster uses
  the same lookup with the same statuses.

## Changed in the final UI batch (team editor review, 2026-10-14)

Source changes after proposal `90cfa6a8`; tested on disposable sites only and
**not installed** on the local Intramurals copy yet.

- **Team sport (T-01).** Team Details now starts with a required **Sport**
  selector listing only enabled sports that have a sport term. A sport chosen
  in the list context (`lf_sport`) is preselected; *All sports* preselects
  nothing. Once a team has a sport it is shown read-only, because player
  memberships, requests and matches are checked against it; a submitted
  value (stale or forged form) never changes it. Older teams keep every
  existing sport term (including several sports or a sport that is no longer
  enabled, which is labelled "(not enabled)"); an older team without a sport
  gets the selector so it can be fixed. A first publication without a valid
  sport is held as a draft; an already published sportless team keeps its
  status. Every free-form sport path is gone: the `lf_sport` taxonomy is now
  registered with `show_ui => false`, which removes the generic term screen,
  the Sports tag box (Team, Player, Match), the Quick Edit "Sports" field and
  the block-editor Sports panel (Calendar Events are the only LeagueFlow type
  edited in the block editor). The list-table Sport column, REST exposure and
  every existing term assignment are unchanged. Player League Level stays a
  tag box (players without a team have no other level control).
- **League Level duplicates (U-06 follow-up).** Quick Edit no longer offers a
  free-form League Levels field for Teams, Matches or Calendar Events (it
  saved without the details box, skipping the team-to-player level sync), and
  the Calendar Event block editor no longer shows its League Levels sidebar
  panel (`removeEditorPanel`, editor-only inline script). Players keep both.
- **Calendar event sport (T-01).** Calendar Event Details has the same
  enabled-sport selector; an event may move to another enabled sport, a blank
  or disabled choice keeps its current sport, and a current sport that was
  later disabled stays listed and selected.
- **Player sports (T-01).** Player Details shows the player's sports
  read-only, explaining that they come from team memberships.
- **No silent default sport (T-02).** `assign_default_sport_if_missing()`
  only assigns an explicit, enabled sport from the screen context (or an
  older form's hidden field). It no longer falls back to the first enabled
  sport or to an invented Soccer. Sports_Manager itself still treats Soccer
  as enabled when the enabled list is empty (its existing default); when no
  enabled sport has a term the selector is replaced by a setup notice linking
  administrators to LeagueFlow > Sports. While initial sport setup is not
  finished, administrators see a link to confirm it.
- **Sport term authority (T-06).** `lf_sport` term create/rename/delete now
  needs `manage_options`, the capability of the existing LeagueFlow > Sports
  screen that owns sport terms. Assigning an existing sport keeps WordPress's
  default (`edit_posts`). League Levels, Competitions and Seasons keep
  `manage_categories` (unchanged; LeagueFlow's menu uses that capability).
  No role is added or removed.
- **Settings labels (T-03).** Every Settings checkbox has an `id`, a row
  title linked with `label_for`, and a label naming its effect (the three
  display toggles used to say only "Enabled"; the uninstall toggle now lists
  what is deleted). The responsive panel grid is unchanged.
- **Team Roster (T-04).** The read-only roster now says roster changes are
  made on each player record, gives every row a "Manage player" link with an
  accessible name (only when the user may edit that player), offers "Add a
  new player to this team" (`post-new.php?post_type=lf_player&lf_sport=…&lf_add_to_team=…`)
  and an "All <sport> players" list link. `lf_add_to_team` is handled by the
  server: on a new player only, it preselects (never saves) that team in
  "Add to team" if it is an existing, non-trashed team the user can edit; the
  normal save validation still applies. The roster table sits in a labelled,
  keyboard-scrollable region for 320/390.
- **Sidebar help (T-05).** The Team Details note no longer claims taxonomy
  boxes for all assignments; it says the Competitions and Seasons sidebar
  boxes add the team to existing ones for its sport, and that new ones with a
  sport are created under LeagueFlow > Competitions or Seasons. (Those two tag
  boxes are unchanged and can still type a new name; that is recorded as a
  remaining limit.)
- **Other Utilities removed (C-14).** The "Other Utilities" card was removed
  in batch 2: its "Player roster exporter — Available" row only repeated the
  real Player Roster Exporter card above it, and its "Additional import,
  audit, and cleanup tools — Ready for future utilities" row had no
  implementation behind it. The real exporter, its form, nonce and
  published-only default are unchanged; no placeholder replaces the card.
- **Remaining limits of this batch.** A team's sport cannot be changed in
  wp-admin by design (add a new team instead). The Competitions and Seasons
  tag boxes can still type a new name, which creates a term without a sport.
  Sports_Manager still treats Soccer as enabled when the enabled list is
  empty. All of this is source plus disposable-site evidence; nothing here is
  installed on the Intramurals copy yet.

## Polish after installed-site acceptance (source only, 2026-10-15)

Implemented and tested on disposable sites; **not installed** on the
Intramurals copy until root imports, builds and updates it. No data,
capability, validation, version or updater-origin change.

- **Match Sport row (L-01).** The help no longer promises a first-enabled
  fallback (the code never had one after batch 1). It now says a match takes
  its sport from the selected teams and, until then, from the sport the screen
  was opened from; from All sports no sport is set until teams are chosen. A
  match with no sport and no context shows "Not set yet" instead of "Soccer".
- **Settings points row (L-02).** The win/draw/loss rows are a column whose
  label takes the spare height, so the three inputs share a bottom line even
  when "Points for a draw (tie)" wraps.
- **Filter bars (L-03).** Every label in the Standings, Generate fixtures,
  Brackets (shared `render_context_filters`), Requests, Setup mapping and slot
  preview filter bars sits with its own control in a `leagueflow-filter-field`
  group: side by side on wide screens, stacked label-above-control at 782 px
  and below. Names, ids, values and GET parameters are unchanged.
- **Venues buttons (L-04).** Add/Update Availability, Auto Schedule Matches and
  Preview Slots have their own ids (`leagueflow-availability-submit`,
  `leagueflow-schedule-submit`, `leagueflow-preview-slots-submit`); their
  submitted `name="submit"` is unchanged.
- **Venue availability list (L-05, found while testing this batch).** With at
  least one availability rule the list table widened the page to 459 px at 390
  and 320 (pre-existing; the live copy has no rules, so LA07 could not show
  it). It now scrolls inside a labelled `leagueflow-table-scroll` region like
  the other admin tables.
- **Not changed (L-06).** Matches still use WordPress's classic Competitions and
  Seasons tag boxes; typing a new name there creates a term with no sport.
  Replacing them needs a sport-aware selector and is left for a later batch.

Evidence (disposable `leagueflow.test` only, not live acceptance):
`tests/wordpress-admin.php` ADM-16 (16 checks; the installed `ce16748` runtime
fails 11 of them, the other 5 guard behaviour that must not change); full admin
suite 378/378 on WordPress 7.1.3 and 6.5. Local headless Chromium against the
configured fixture at 1440, 1280, 1024, 900, 390 and 320 (Settings, Brackets,
Standings, Requests, Setup check, Venues with one rule, new match from All
sports): 42/42 with the polished package, 9/42 with the `ce16748` package (on
which the draw input sits 16 px lower at 1280/1024/900, labels are ungrouped,
`submit` is duplicated, Venues is 459 px wide on phones, and the unscoped match
says "Soccer"). PHP lint (51 files), asset-version, admin-asset, admin-filter
and Python packaging tests pass. JS is unchanged, so calendar tests were not
rerun; security/portal suites were not rerun (no change to those paths).

## Request detail display (L-07, installed, 2026-10-15)

Installed from `6debab8` by normal WordPress Manager update `44d379cf`,
with exact full-package read-back and rollback retained. Admin display only: no change to
`Join_Request_Service`, review actions, conflict rules, stored meta, history,
capabilities, options, version or updater origin.

- **Current team.** `inspect()` deliberately lists the player's *other* teams
  in the sport (it feeds conflict checks), so a player already on the requested
  team was shown "Current team in this sport: None". The detail panel now adds
  the requested team back when the request is flagged `already_member`.
- **Resolved placement.** Assigning a placement stores the chosen team on the
  request, which made it read as "Join a team". When an approved request has the
  persisted `lf_placed_from_queue` marker, the detail shows "Placement request"
  and "Assigned team", and the list summary reads "Placement request: placed
  on …". Pending placements still read "Needs placement".
- Team names still go through the existing label, so a team the reviewer cannot
  read stays "team #ID (not visible to you)"; anonymous and non-reviewing roles
  still get no detail at all.

Evidence (disposable `leagueflow.test` only): `tests/wordpress-admin.php`
ADM-17 — named approval, resolved already-member, pending and assigned
placement, pending request to another team (only the real team listed),
private-team placement for admin/author/anonymous, service list unchanged,
history unchanged, list summary. Admin suite 396/396 and security suite 696 on
WordPress 7.1.3 and 6.5; the `5235d8bf` request screen fails 7 ADM-17 checks
(389/396). PHP lint, admin-filter (11) and admin-asset (14) tests pass. JS, CSS
and portal code are unchanged, so calendar and portal suites were not rerun.

## Finding checklist

Implemented = in maintained source on `codex/leagueflow-admin-workflow`.
Tested = automated, on disposable `leagueflow.test` sites (WordPress 6.5 and
7.1.3) unless marked otherwise. Applied = installed on the local Intramurals
copy through the reviewed update; the cell says whether and how it was
retested there. A live render is not proof that a write action works: no
decision, generation, mapping or save was submitted on the real site.

| ID | Finding | Implemented | Tested | Applied |
|---|---|---|---|---|
| C-01 | Undated matches/events hidden by the default and date sorts; Trash showed 0 of 190 | batch 1 | ADM-01 (old code fails), fixture-browser | installed |
| N-05 | Sort arrow on the publish Date column | batch 1 | ADM-01 header check | installed |
| C-04 | Sport lists show all-sport status counts; links drop the sport | batch 1; readable-only counts after review B | ADM-02, ADM-07 (Author) | installed |
| N-01 | Finished + blank score silently became 0–0; negative/decimal accepted | batch 1; no invented cap after review C | ADM-03 | installed |
| N-03 | Blank "Select a sport" saved as the first enabled sport (also via Quick Edit) | batch 1 | ADM-04 | installed |
| R-01 | One-click demo seeding could overwrite same-slug pages and real terms | batch 1; strict create-only after review A; sport terms reused untouched | ADM-06 (edits survive rerun; post-preview collision; renamed sport kept), mutation M13 | live LA08 (demo data off: environment production) |
| B-02 | Duplicate first-option captions | batch 1 | ADM-05 | installed |
| N-04 | Membership No./Position inputs unnamed | batch 1 | ADM-05, fixture-browser | live LA12 (jersey/position/captain/remove names) |
| D-02 | Placements team selects unnamed | batch 1 (inbox Assign select labelled) | ADM-05, Expect audit (fixture) | installed; Assign select not rendered live (#1800 is Dismiss-only) |
| D-01 | Placements Assign control escapes its table at 320 (root's fresh sweep found no page overflow) | batch 1 | fixture-browser | installed |
| C-18 | Player edit 784px wide at 390/320 | batch 1 | fixture-browser (784 → 390/320) | live LA12 (390: 375 wide incl. scrollbar) |
| C-19 | Sport Overview level table overflows at 320 | batch 1 | ADM-05 markup, fixture-browser | installed |
| U-03 | Team Managers (274) / Linked User (272) unsearchable | batch 1 (filter box) | ADM-05 markup; fixture-browser | live LA12 (Linked User and Add-to-team filters) |
| U-06 | Details far down on phones; duplicated level/sport boxes | batches 1–2 | fixture-browser; ADM-08 | live LA12, LA13 (details before Publish at 390/320) |
| S-01 | admin.css/editor.css/blocks.js cached by plugin version only | batch 1 | unit `tests/asset-versions.php` (old fails) | installed |
| B-01 | Edit screens and Add New lose the sport context | batch 2 | ADM-08; fixture-browser | live LA12, LA13 (Volleyball breadcrumb, back link, Add New) |
| C-05 | Each sport's "League Levels" is the global list with cross-sport counts | batch 2 | ADM-08 | live LA01 (one "League Levels (all sports)" entry) |
| C-06 | Multi-sport players show another sport's team/level | batch 2 | ADM-08 | installed |
| N-06 | Global Matches list has no menu entry | batch 2 | ADM-08 | installed |
| C-11 | Empty states without next steps; Overview quick links incomplete | batches 2 and 4 (Setup check, attention item) | ADM-08, ADM-11 | live LA01 (Needs attention), LA10, LA15 empty state |
| C-14 | Utilities placeholder row ("Other Utilities": duplicate exporter row and an unimplemented "future utilities" row) | batch 2; documented in final UI batch | ADM-08 | root live (Utilities 320: real exporter, no placeholder) |
| C-10 | Empty term screens say "No tags found." | batch 2 | ADM-08 | installed |
| C-02 | Roster adds leave matching join requests Pending | batch 3; explicit entry points after F1/F2 | ADM-09 (F1, F2, F6), POR-04 portal roster add; mutations M06, M09 | installed |
| C-03 | Requests target trashed/missing teams; no flag or reviewed repair | batch 3; F3/F4 | ADM-09; mutations M10, M16 | live LA02 (trashed/missing/other-level flags; no repair submitted) |
| U-01/U-02 | Named-team requests cannot be approved/declined; no status filter | batch 3; F2/F5/F6 | ADM-09; mutations M07, M08, M15 | live LA02, LA03 (tabs, status filters, detail panel; no decision submitted) |
| C-12 | Placements global-only; orphan request cannot be dismissed | batch 3 | ADM-09 | live LA03 (#1800 offers only Dismiss; not submitted) |
| C-07 | One global current season set from per-sport screens | batch 4 | ADM-10; mutation M02 | live LA14 (Current column, per-sport checkbox; nothing saved) |
| N-02 | Brackets "All seasons" resolves to the current season | batch 4 | ADM-10 (brackets render); mutation M01 | live LA04, LA05, LA15 (explicit All/Current options) |
| C-16 | Teams/matches without season/competition: validation and explicit mapping | batch 4 | ADM-11; mutation M04 | live LA11 (validation table and mapping form; no preview/apply) |
| C-09 | No availability rules but scheduling enabled without a prerequisite | batch 5 | ADM-12, ADM-14; mutation M12 | live LA07 (prerequisite notice, auto-schedule off) |
| U-05 | Fixture generation/standings mix levels by default | batch 5 | ADM-12, ADM-13; mutations M03, M14 | live LA04 (level-scoped preview, duplicates counted; not generated), LA05 |
| C-08 | Global soccer-worded scoring; D column for sports without draws | batch 5 | ADM-13; mutation M05 | live LA05 (no D column, set labels), LA08 scoring table |
| C-13 | Availability/scheduling competition list mixes sports | batch 5 | ADM-14 | installed |
| C-15 | `save_team_meta()` loads every player per team save | batch 5 | ADM-14; mutation M11 | installed |
| P-01 | Published slugs stay stable (policy) | no change: drafts get their slug at first publish | ADM-12 (generated draft has no slug yet) | installed |
| P-02 | Export default "Active published records only" (policy) | unchanged by design | — | installed |
| G1 | No sport-description draw inference | review: already data-driven | ADM-13 | installed |
| G2 | Requests keep division context; no fake season/competition filter for requests/players | final review | ADM-09 G2 | live LA02 (Level filter) |
| G3 | Trashed/auto-draft request records rejected inside locked checks | final review | ADM-09 G3 | installed |
| G4 | Primary decision visible; secondary decisions in native `<details>` | final review | ADM-09 G4 | live LA03 ("Other decisions" disclosure) |
| H1 | Request/generation locks accepted `NULL` as success | final review (fail closed) | ADM-09 H1, ADM-11 H5, ADM-12 H5 (NULL injection and held lock) | installed |
| H2 | Omitted HTTP state token skipped the freshness check | final review | ADM-09 H2 (omitted/empty/forged/stale player/stale team; legacy form) | installed |
| H3 | Authority/record state not re-checked after the lock | final review | ADM-09 H3 (trashed, status and capability changed while waiting) | installed |
| H4 | Portal captain review outside the request lock | final review | ADM-09 H4; POR-04 (stateless, stale, staff-first, locked re-read); HTTP-06 (rendered token, removed token, stale page) | installed |
| H5 | Mapping not serialized; term/insert errors ignored; incomplete partial result | final review | ADM-11 H5, ADM-12 H5 (conflicting previews, held/NULL lock, injected term and insert failures, early stop) | installed |
| T-01 | Free-form sport entry (tag boxes, Quick Edit, block-editor panel, generic term screen); no safe team/event sport control | final UI batch | ADM-15 (selector, preselect, disabled/invented/blank refused, read-only, legacy multi/disabled/sportless kept, publish held as draft, nonce/capability, show_ui/Quick Edit/editor panel); mutations U-M2/M3/M4/M9/M10; fixture-browser (1440/390/320, publish blocked without sport, event sidebar) | root live (team 1440/320: read-only sport, no tag boxes) |
| T-02 | Default sport silently first enabled sport / Soccer | final UI batch | ADM-15 (player without context, disabled context); mutation U-M1 | installed |
| T-03 | Settings checkboxes without id/label_for; three toggles said only "Enabled" | final UI batch | ADM-15 (all five checkboxes); mutation U-M6 | live LA08, LA09; root live |
| T-04 | Team Roster read-only dead end | final UI batch | ADM-15 (manage/add/list links, region, preselect only if editable, no write); mutations U-M7/U-M8 | root live (roster Manage/Add links) |
| T-05 | Team sidebar competition/season help inaccurate | final UI batch (text) | ADM-15 render; review | installed |
| T-06 | Sport terms editable by anyone with manage_categories / created by edit_posts tag box | final UI batch | ADM-15 (Editor vs Administrator caps); mutation U-M5 | installed |
| U-06b | Quick Edit and Calendar Event block-editor League Levels duplicated the details box | final UI batch | ADM-15 (Quick Edit filter, editor script per type); fixture-browser event sidebar | installed |
| L-01 | Match Sport help promised a first-enabled fallback; unscoped match showed "Soccer" | polish | ADM-16; fixture-browser (new match, 6 widths) | installed (`5235d8bf`); not retested live (no unscoped Add Match opened) |
| L-02 | Settings draw input lower than its neighbours when the label wraps | polish (CSS) | fixture-browser: equal input tops at 1440/1280/1024/900 (old: 16 px lower at 1280/1024/900); ADM-16 row class | installed; live F12 (1440: win/draw/loss input tops equal) |
| L-03 | Phone filter labels sat beside the previous select | polish | ADM-16 (DOM grouping on 5 filter bars); fixture-browser (label beside or above its own control, 6 widths) | installed; live F25/F30 (Requests 1440, Setup mapping 390: each label with its own control) |
| L-04 | Venues & availability had duplicate `input#submit` | polish | ADM-16 (no `id="submit"`, three own ids, names kept, all ids unique); fixture-browser | installed; not retested live |
| L-05 | Venue availability list widened the page to 459 px on phones when rules exist | polish | ADM-16 (labelled scroll region); fixture-browser 390/320 | installed; not observable live (the copy has no availability rules; none were added) |
| L-06 | Match Competitions/Seasons tag boxes can create a term with no sport | not changed (documented limit) | — | installed as before |
| L-07 | Request detail said "Current team in this sport: None" for a player already on the requested team, and an assigned placement read as "Join a team" | request-detail display (admin only; service unchanged) | ADM-17 (18 checks incl. 4 fixture checks; `5235d8bf` fails 7) | installed; final operator browser read-back passed for owned 2494/2495 |

Earlier usability IDs from the first admin audit map onto these rows: U-04
(Matches density) → U-06/batch 2 columns; U-07 (menu sprawl) → B-01/C-05;
U-08 (orphan request) → C-12; U-09 (Players/Teams lists) → C-06; U-10
(Overviews, empty states) → C-11; U-11 (sport-agnostic rules) → C-08.

Players have no competition or season of their own (they belong to teams), so
no player list adds a competition or season filter; the sport context filters
players by their sport terms only.

## Testing

`tests/wordpress-admin.php` groups: ADM-01 undated lists, ADM-02 context
views, ADM-03 scores, ADM-04 term sport, ADM-05 selects/labels, ADM-06 demo
data, ADM-07 count visibility (Author), ADM-08 navigation/context, ADM-09
requests inbox (nonce missing/forged/other action/other request/other user,
forged ID, unauthorized role, cross sport/level, trash/missing/draft targets,
double submission, explicit-entry reconciliation, unknown statuses, ambiguous
memberships, service-level authority, stale state token, a second database
connection holding the request lock, history counts), ADM-10 current seasons
and All/Current semantics (including the Brackets render), ADM-11 Setup check,
list filters and the previewed single-use mapping, ADM-12 fixture generation
(level scope, duplicates, stale and repeated submissions, forged scope,
batch link, scheduling prerequisite), ADM-13 scoring overrides and per-level
standings, ADM-14 scheduling scope, knockout targets and the team-member
lookup, ADM-15 Team/Event sport controls, sport term authority, Settings
labels and Team Roster links, ADM-16 final polish (match Sport row, Venues
button ids and table region, filter label grouping). `tests/wordpress-portal.php` POR-04 also checks portal review history
and that an authorized portal roster add closes the matching request once.

The final-review cases inject faults without touching core: a `query` filter
turns `GET_LOCK` into `SELECT NULL` (unavailable lock service) or runs a
change "while waiting" just before the lock query; a second MariaDB
connection holds a lock (a concurrent process); a `query` filter makes one
term-relationship INSERT fail; `wp_insert_post_empty_content` refuses one
match insert. `tests/wordpress-portal-http.php` HTTP-06 reads the state token
from the rendered portal form and submits it over real HTTP.

A dev-only mutation runner re-introduced 16 old behaviours one at a time
(each F1–F6 rule, the seeder sync, per-sport seasons, Brackets "all",
mapping token, duplicates, level scope, overrides, prerequisites, member
lookup); the suite failed on every one.

- `php tests/asset-versions.php`, `php tests/admin-assets.php` (standalone).
- `LEAGUEFLOW_DISPOSABLE_WP=1 wp eval-file tests/wordpress-admin.php` on a
  disposable `http://leagueflow.test` site only. It refuses any other home URL,
  blocks outbound HTTP and mail, cleans up its own `lfa ` fixtures, and runs the
  successful demo-seeding case inside a rolled-back transaction. Never run it on
  the Intramurals database.
- Phone layout was measured on the disposable site with local headless Chromium
  (fixture evidence). Live acceptance on the installed Intramurals copy is
  recorded below and is separate evidence.

## Installed-site acceptance (2026-10-15, `ce16748`)

Runtime: LeagueFlow 1.0.3 built from `ce16748`, installed by WordPress Manager
job `4bede15b` (digest `9e8483e5…41fa3`, rollback retained). Root's read-only
snapshots before and after install matched for content/statuses, 6,620 LF
business meta rows, terms/relationships, settings, roles/permissions and 12
anonymous standings scopes.

Browser: native Zoer Playwright Chromium, compact, logged in as administrator
through a one-time admin handoff (not recorded). Diagnostics were started on a
blank tab before navigation. Two short sessions were used; the first was
released early by mistake and one replacement was prepared. Both were released
and the saved Clearcote profile was not touched. These are viewport checks,
not physical devices, WebKit or stock Firefox.

| Case | Screen | Width | Result |
|---|---|---|---|
| LA01 | Overview: one menu (17 links), sport switcher, Needs attention, per-sport counts | 1440 | pass |
| LA02 | Requests: tabs, Pending/Approved/Declined/Dismissed/All, Sport/Level/search/attention filters, problem flags | 1440 | pass |
| LA03 | Request #1800 detail: missing player, only Dismiss (reason required), focus moved to panel | 320 | pass, not submitted |
| LA04 | Generate fixtures (Volleyball): level groups, duplicates counted, scope required, no generate button until scoped | 320 | pass, nothing generated |
| LA05 | Standings (Volleyball): one table per level, no D column, set labels | 390 | pass |
| LA06 | Public /standings/ (viewed while logged in) | 390 | pass |
| LA07 | Venues & availability: prerequisite notice, auto-schedule off, labelled form | 390 | pass, nothing added |
| LA08 | Settings: 3-column grid, all labels and checkbox purposes, scoring by sport/competition | 1440 | pass, nothing saved |
| LA09 | Settings | 390 | pass |
| LA10 | Setup check (all sports) | 390 | pass |
| LA11 | Setup check (Volleyball): 12 checks, mapping form | 390 | pass, no preview/apply |
| LA12 | Existing player edit: context, back link, details first, labelled membership card, filter boxes | 390 | pass, not saved |
| LA13 | Existing match edit: date/time, venue, scores, status, result type, sport read-only, knockout targets | 320 | pass, not saved |
| LA14 | Seasons: Sport and Current columns, required sport, per-sport current | 1440 | pass, nothing added |
| LA15 | Knockout Brackets (Volleyball): explicit All/Current season, empty state | 390 | pass |

No page overflowed horizontally (document width ≤ viewport). Console: only
jQuery Migrate logs and one core 403 from the logout confirmation page; no page
errors or failed requests.

Root's own live checks (separate evidence): Settings at 1440/390/320, Utilities
at 320, an existing Team at 1440/320, and the 17-link menu.

Observations, not regressions (the first, third and fourth are fixed in
source by the polish batch above; that source is not installed yet):

- Match edit Sport help still said "…or the first enabled sport"; the screen
  itself uses only the record's or the opened sport (`get_context_sport_slug`).
  With no sport at all it also printed "Soccer" (the definition fallback).
- Matches keep classic Competitions/Seasons tag boxes, so typing a new name
  there creates a term with no sport (documented limit, unchanged).
- Settings: the "Points for a draw (tie)" label wraps, so its input sat lower
  than its neighbours. Venues & availability rendered two `input#submit`
  (duplicate id, pre-existing).
- On phones, Brackets/Standings/Generate filter labels sat beside the previous
  select.

Not covered live: any write (approve, decline, assign, dismiss, mapping
preview/apply, fixture generation, scheduling, saves, scoring overrides),
Add forms (`post-new.php` would create auto-drafts), Calendar Event editor,
Teams/Players/Matches list views and legacy alias redirects, keyboard Tab
order beyond focus moving to the request panel, other sports' screens, and
anonymous or participant roles. No fixtures, accounts or records were created,
so nothing needed cleanup; opening the two edit screens set WordPress's normal
edit lock only.

## Installed-site acceptance with owned fixtures (2026-10-15, `5235d8bf`)

Runtime: LeagueFlow 1.0.3 built from `5235d8bf`, installed by WordPress Manager
job `42ff9cc3` (digest `4e36faa7…f864`, rollback retained); root confirmed all
12 protected baseline sections identical before fixtures. Root created the
owned fixtures (marker `lfaudit-20261014-145c27`: level 19, Volleyball
competition 20, season 21 never current, private teams 2490/2491, private
players 2492/2493 with `@example.invalid` contacts, requests 2494/2495). Browser:
native Playwright Chromium compact, two sessions on separate turns, one-time
admin handoffs (never recorded), diagnostics started on a blank tab before
navigation, own WordPress session logged out, sessions released; the saved
Clearcote profile was not touched. Viewport checks only — no physical device,
WebKit or stock Firefox. Screenshots with real names stay private.

Write flows on owned records only (all passed):

| Case | Action | Result |
|---|---|---|
| F01 | Request 2494 (One → Team B) while One is on Team A | "Already on another team in this sport"; no Approve, only Decline/Dismiss |
| F02 | Remove One from Team A in the player editor, save, reload | persisted |
| F03 | Approve 2494 (e-mail box unticked; it defaults to ticked) | One on Team B; pending 53 → 51 with F04 |
| F04 | Assign placement 2495 to Team A (e-mail unticked) | approved, one history entry, Team A roster = Player Two |
| F05–F07 | Generate fixtures: Volleyball + level 19 + competition 20 + season 21; repeat | preview = the two private teams; created draft 2496; repeat "Every pairing in this scope already exists. Nothing was created." |
| F08 | Matches list (Volleyball, drafts) | 2496 labelled Unscheduled, listed first; counts scoped |
| F09–F11 | Edit match 2496 (venue), Team A 2490 (title), Player Two 2493 (number 77); reload | all persisted |
| F16–F19 | Add Team / Player / Match / Calendar Event saved as marker-named drafts | 2497 / 2498 / 2499 / 2500 (adopted by root) |

Read-only states (pass unless noted):

| Case | Screen | Width | Result |
|---|---|---|---|
| F12 | Settings | 1440 | 3 columns; win/draw/loss input tops equal |
| F13 | Settings | 320 | one column, no overflow, every field labelled |
| F14/F15 | Public /standings/, Volleyball – Recreational expanded (admin) | 390 | minus icon, names aligned with or without logo, no overflow |
| F20 | Request 2495 detail after reload | 1440 | **fail (L-07):** "Join a team", "Current team in this sport: None" beside "Already on the requested team"; fixed in source above |
| F21 | Team 2497 (Add Team draft) edit | 1440 | saved sport Volleyball shown read-only; roster empty state with Add/All-players links |
| F22 | Teams list, Soccer context | 1440 | breadcrumb + switcher; All (12) / Published (12) / Trash (4) scoped |
| F23 | Legacy `page=leagueflow-sport-basketball` | 390 | redirects to Overview with `lf_sport=basketball`; Needs attention, Work on, counts; no overflow |
| F24 | Settings | 390 | one column, labelled, scoring overrides region |
| F25 | Requests list, Volleyball | 1440 | tabs, status filters, labelled filter fields, flags |
| F26 | Players list, Basketball | 390 | All (122) / Published (48) / Private (74) / Trash (1); paging |
| F27 | Keyboard: Enter on a Players row "Show more details" | 390 | row expands (Sport, Level, Team…), focus stays on the toggle |
| F28 | Matches list, Volleyball | 390 | All (22) / Drafts (2) / Trash (123) / Unscheduled (2); "unscheduled first" caption |
| F30 | Setup check mapping **preview** (draft 2497 → competition 20) | 390 | preview listed exactly 2497; Apply not clicked (copy nit: "1 records") |
| F31 | Public /standings/ expanded (admin) | 1440 | expanded with focus on the summary; see observation 1 |
| F32 | Public /standings/ expanded, **logged out** | 390 | 10 teams, no draft, private audit teams hidden; see observation 2 |
| F33 | Calendar Event 2500 sport field | 1440 | **blocked:** the block editor's meta-box pane did not expand through the browser tool, and snapshots do not expose select values |

Console (both sessions): jQuery Migrate logs, WordPress core 403 on the logout
confirmation page, an aborted editor blob request and the core iframe-styles
warning; no page errors and no LeagueFlow-attributed console error.

Observations, not fixed in this batch:

1. Logged in as administrator, the public standings list the owned **draft**
   team 2497 as a row in Volleyball – Recreational; logged out it is not shown.
   Admin-only exposure of drafts in a public block; worth a follow-up decision.
2. Public standings render an empty "0 teams" group for every sport when a
   shared level has no visible teams in that sport (seen logged out for the
   audit level in Soccer, Basketball and Volleyball). It disappears once the
   audit level is cleaned up.
3. The Approve/Assign "E-mail the player" box is ticked by default (local mail
   guard stayed on; it was unticked for every owned decision).
4. Requests links with unknown query names fall back to the default list
   silently (`type`/`status`/`request_id` are the real ones).

Not covered live: the Calendar Event sport field (F33), Venues & availability
with rules (none exist; none were added), keyboard order across whole screens
(only the request panel focus, row toggle and standings summary were checked),
Soccer/Basketball edit screens, participant roles, physical devices. Cleanup of
the owned fixtures and drafts 2496–2500 is root's step; this document does not
claim it.


## Final operator verification and cleanup (2026-10-15 UTC)

The final runtime code is commit `6debab816491898f10496a81c189827fd4e29c6f`,
installed by normal WordPress Manager job
`44d379cf-b2e7-461d-8027-d79d2a4d4c61`. Its full 63-file read-back digest is
`b4423aa057b5afbb548b6cae083922bbd3bd9cb120c071ad76075155a8304394`;
rollback is retained. LeagueFlow remains active at 1.0.3 with its updater origin
unchanged. This follow-up documentation is excluded from the runtime package.

Root independently verified in the actual local-site browser:

- All 17 visible LeagueFlow menu destinations at 390 x 844 and 1440 x 900:
  each loaded its expected screen, with no critical error and no horizontal
  document overflow (34 read-only page checks). Navigation was allowed to finish
  at DOMContentLoaded; an initial empty-document observation was excluded.
- Installed request 2494 showed Join a team, requested Team B and current Team B.
  Installed request 2495 showed Placement request, assigned Team A and current
  Team A. No decision was submitted during this read-back.
- F33 is now resolved: in the WordPress block editor, click Meta Boxes, then
  press Arrow Up on its focused Drag to resize separator. The Calendar Event
  Details pane opens. Owned event 2500 had Volleyball selected, with no duplicate
  Sports/Levels tag boxes; no field was changed or saved.

Cleanup completed through the rehearsed ownership manifest, never broad deletion:
11 marked owned posts/drafts (2490–2500) and three marked terms (19–21) were
removed, including the five exact browser-created drafts adopted earlier.
Foreign-reference checks passed; no extra marked or unadopted LeagueFlow posts
remained. All 12 protected snapshot sections exactly matched the pre-fixture
baseline afterward: original posts/dates, 6,620 LeagueFlow business-meta rows,
protected history metadata, terms and memberships, settings/options, role and
user-permission hashes, and all 12 anonymous standings scopes. This is not a
claim that WordPress edit locks or session/transient rows are byte-identical.

The dedicated development computer was restored after its temporary memory
pause: PHP 8.3.32, Composer 2.10.3, setup ready, build-on-save enabled and its
worker alive with a successful build of current source. No production site,
real league record, account, role definition, current-season selection or old
trashed match was changed. No public release or remote push was performed.

Remaining acceptance limits: no physical device, WebKit or stock Firefox; no
actual populated availability table because this local copy has no rules; no
full keyboard traversal of every field or participant-account browser audit.
The populated-table responsive matrix and role/security suites are disposable-site
coverage, explicitly separate from these actual admin/browser checks. Admin-only
draft preview rows, empty shared-level groups, notification defaults and generic
competition/season tag creation remain the documented observations above.

## October 15 follow-up: sidebar and calendar completeness

Enabled sport sidebar entries are restored without replacing the sport switcher.
A scoped list/edit screen highlights its sport; All sports returns to the shared
menu. Existing capabilities and legacy custom-page redirects remain in force.

The public calendar initially embedded only its first 20 chronological records.
Its default batch is now 100, with an explicit next-page button when more records
exist. Every request stays within the public endpoint's 100-record cap and retains
its original filters. Failed pages can be retried without clearing loaded events;
new sport/type chips appear as later batches arrive. No automatic historical crawl
or fixture creation is part of this change.

Read-only production inventory on October 15 found 68 dated public matches:
Volleyball only, 32 in October, 31 in November and 5 in December. Soccer and
Basketball had no dated matches in that public feed. Their schedules must be
entered or generated from reviewed fixtures and venue availability; enabling a
sport alone does not create fixtures. No live database or media was changed by
this inventory. Calendar regression coverage includes page 2, duplicate clicks,
failed requests, deduplication and a sport first appearing after the first batch.
