# Public match events and results API

LeagueFlow exposes two **read-only, unauthenticated JSON feeds** for signage,
mobile applications, club websites, newsrooms and other integrations:

```text
GET https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-events
GET https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-results
```

These routes ship with the plugin; no API key, new database or external service
is required. They are separate from the existing `/matches`, `/events` and
`/calendar/events` routes, whose response contracts remain unchanged. Installing
updated plugin code enables the new routes through WordPress's normal REST
registration; saving permalinks is unnecessary. A Git branch/PR alone does not
enable them on a website.

The envelope follows [CampusManager's events API](https://github.com/Over-the-Edge-Newspaper-Society/campusmanager/blob/main/includes/class-rest-api.php):
an item array, `eventMetadata` keyed by stable event ID, `total`, `pages`, and
`pagination`. LeagueFlow uses its own namespace and match-specific fields rather
than claiming the two APIs are interchangeable.

## Common requests

```sh
# Next 10 matches, from the current site-local day, earliest first.
curl 'https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-events?per_page=10'

# Last 20 finished matches, through October 31 inclusive, newest first.
curl 'https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-results?date=2026-10-31&per_page=20'

# Last 10 dated fixtures before/through a day, regardless of match status.
curl 'https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-events?view=past&date=2026-10-31&limit=10'

# All Volleyball recreational fixtures in a calendar month, page 2.
curl 'https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-events?sport=volleyball&league_level=recreational&start_date=2026-10-01&end_date=2026-10-31&per_page=20&page=2'

# Finished results for one team (home or away) in one competition/season.
curl 'https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1/match-results?team=123&competition=fall-league&season=fall-2026&per_page=10'
```

`match-results` includes **only `finished` matches**. A past scheduled match is
not a result. The events feed may include finished, scheduled, live, postponed
and cancelled fixtures; narrow it with `status` when needed. Non-match calendar
items such as drop-ins remain in the existing `/calendar/events` API.

## Parameters

| Parameter | Meaning |
| --- | --- |
| `per_page` | Integer 1–100; default 20. Choose 10, 20, etc. |
| `limit` | Alias for `per_page`. If both are supplied they must agree. |
| `page` | Integer 1–10000; default 1. |
| `view` | `upcoming`, `past`, or `all`. Events default to upcoming; results default to past. Supplying `start_date` or `end_date` defaults to all unless view is explicit. |
| `date` | `YYYY-MM-DD` anchor for past/upcoming, default current site-local day. Past includes that entire day; upcoming starts at its midnight. With view=all, use the range parameters instead. |
| `start_date`, `end_date` | Inclusive site-local calendar-day bounds. Either can be supplied independently. Intersect with an explicitly selected past/upcoming view. |
| `order` | `asc` or `desc`, by kickoff and then match ID. Defaults to desc for results/past, otherwise asc. |
| `sport`, `league_level`, `competition`, `season` | An existing taxonomy term ID or slug. Filters combine with AND. No implicit current-season filter. |
| `team` | Public team ID or slug; matches either the home or away team. |
| `status` | Events: scheduled/live/finished/postponed/cancelled. Results: only finished. |
| `include_knockout` | `true` selects knockout fixtures, `false` selects non-knockout fixtures; omitted includes both, matching LeagueFlow's existing convention. |

Malformed dates, reversed ranges, contradictory views, unknown taxonomy/team
filters, oversized pages and invalid values return HTTP **400** with WordPress's
JSON error structure. Unknown filters never silently become an unfiltered feed.
Unknown parameter names are ignored; check spelling against the REST index or
this table. Results cannot be widened with `status=scheduled` or `context=edit`.

## Response and metadata

The main array is `events` or `results`. Both contain self-contained records with
the same shape. `eventMetadata` is also present on both feeds for integrations
that follow CampusManager's keyed metadata pattern. Its values repeat the team,
taxonomy, knockout and result metadata; the records already include those fields.

Example record (IDs/names here are illustrative):

```json
{
  "id": "match-456",
  "matchId": 456,
  "type": "match",
  "title": "Home Team vs Away Team",
  "url": "https://example.com/matches/456/",
  "startDate": "2026-10-15T18:30:00-07:00",
  "endDate": null,
  "date": "2026-10-15",
  "startTime": "18:30:00",
  "timezone": "America/Vancouver",
  "timeStatus": "valid",
  "location": "NSC GYM 3",
  "status": "finished",
  "teams": {
    "home": {"id": 123, "name": "Home Team", "slug": "home-team", "url": "https://example.com/teams/home-team/", "logoUrl": null},
    "away": {"id": 124, "name": "Away Team", "slug": "away-team", "url": "https://example.com/teams/away-team/", "logoUrl": null}
  },
  "sports": [{"id": 3, "name": "Volleyball", "slug": "volleyball"}],
  "leagueLevels": [{"id": 4, "name": "Recreational", "slug": "recreational"}],
  "competitions": [{"id": 5, "name": "Fall League", "slug": "fall-league"}],
  "seasons": [{"id": 6, "name": "Fall 2026", "slug": "fall-2026"}],
  "isKnockout": false,
  "round": "",
  "result": {
    "homeScore": 3,
    "awayScore": 1,
    "outcome": "normal",
    "outcomeLabel": "",
    "winnerTeamId": 123,
    "winnerOverride": null,
    "isDraw": false,
    "complete": true
  }
}
```

“League” is represented by `competitions` and `leagueLevels`, matching the
plugin's data model. Taxonomies are arrays of `{id, name, slug}` so multiple
assignments are preserved. A zero/unassigned participant is `null` (TBD), not an
invented team. A fixture needs at least one real public participant to appear.

Scores are non-negative integers or `null`. `result` is null until the match is
finished. A finished legacy record with missing/invalid scores has
`complete:false`, unless a recorded forfeit or valid winner override resolves it.
An explicit 0–0 is a valid draw. Outcomes are `normal`, `forfeit_home`,
`forfeit_away`, `double_forfeit`, or `unknown`. `forfeit_home` means the **home
team forfeited**. `outcomeLabel` provides a localized explanation for a forfeit
and is an empty string for a normal played result.

Forfeits return `null` for both played scores, including when older numeric
metadata is still present. The configured walkover score used by the standings
is an administrative award and is not presented as a played score in this feed.
A forfeit takes precedence over an older winner override: the non-forfeiting
side wins, and a double forfeit has no winner. `winnerOverride` is `null` for
forfeits. A normal knockout result decided by penalties or a ruling retains its
valid `winnerOverride`, so a tied played score can still have a winner and
`isDraw:false`.

An unrecognized imported result type returns `outcome:"unknown"`, a localized
`outcomeLabel` explaining that the result is unavailable, `complete:false`, and
`null` scores, winner and winner override. It is not silently interpreted as a
normal result. Consumers should use `complete` and handle unknown outcome values
without assuming a win or draw. A played score needs two distinct participants;
missing or duplicate participants leave those score fields `null`. Two copies
of the same team cannot form a complete result. Reopening a match as Live, Scheduled, Postponed or Cancelled
returns `result:null` in the event feed and removes it from the finished-results
feed until staff finalize it again.

Dates mean the **WordPress site's configured timezone**; the API does not change
that setting or assume a particular campus timezone. `startDate` includes the
UTC offset from PHP's timezone database for that date, including historical
daylight-saving changes. Hosts must keep timezone data current when regional
rules change; a timezone name does not imply that every future winter uses the
old standard-time offset. For example, [B.C. ended seasonal clock changes in
2026](https://news.gov.bc.ca/releases/2026CITZ0009-001073).
No end time is stored for matches, so `endDate` is null. Canonical stored kickoff
values use `YYYY-MM-DD HH:MM[:SS]`; unscheduled/invalid calendar dates are excluded
before pagination. A nonexistent local clock time during a DST jump is retained
with `startDate:null` and `timeStatus:invalid_local_time` so staff can repair it;
the original local date/time remain visible rather than silently moving the match.

## Pagination and polling

The envelope includes `total`, `pages`, `timezone` and:

```json
{
  "currentPage": 1,
  "perPage": 20,
  "hasMore": true,
  "nextPage": 2,
  "previousPage": null,
  "nextUrl": "https://example.com/wp-json/leagueflow/v1/match-results?date=2026-10-31&per_page=20&view=past&order=desc&page=2",
  "previousUrl": null,
  "view": "past",
  "order": "desc",
  "loadedRange": {"start": null, "end": "2026-10-31 23:59:59"}
}
```

Follow `nextUrl` until null. It retains all requested filters and pins the
effective date anchor so crossing midnight does not change the scope mid-walk.
Kickoff ties use match ID in the same direction. An out-of-range page returns an
empty collection with the same totals. Empty `eventMetadata` serializes as `{}`.
Headers include `X-WP-Total`, `X-WP-TotalPages`, and `Link` when applicable.

These are **current-state feeds**, not a webhook, immutable snapshot or change
log. A consumer can poll results periodically (for example every 60 seconds) and
upsert by `matchId`. Scores/status/assignments may be corrected; re-read the
relevant date/season window when reconciling older records. New/removed matches
can shift offset pages while you paginate, so deduplicate by ID and do not treat
absence from one limited page as deletion. Feeds use `Cache-Control:no-store`
and do not retain a server-side response/query cache; metadata-only result edits
are reflected by the next request. Host/CDN policies may need to honor this header.
WordPress supplies its normal REST CORS behavior for browser clients; no new
credentials or permissive CORS overrides are added.

```js
const base = 'https://YOUR-WORDPRESS-SITE/wp-json/leagueflow/v1';
const query = new URLSearchParams({ date: '2026-10-31', per_page: '10' });
const response = await fetch(`${base}/match-results?${query}`, { credentials: 'omit' });
if (!response.ok) throw new Error(`LeagueFlow returned ${response.status}`);
const feed = await response.json();
for (const match of feed.results) {
  console.log(match.teams.home?.name, match.teams.away?.name,
    match.result.homeScore, match.result.awayScore, match.location);
}
```

## Public visibility and verification

Both feeds expose only published, password-free matches and referenced public
teams. Private/protected/draft/missing referenced teams exclude a fixture before
totals and pagination, even with an administrator session or password cookie.
Only an allowlist is serialized: no player identities, emails, account IDs,
rosters, RSVP identities, private notes, credentials or arbitrary post metadata.
There are no write operations. Existing protected event creation endpoints keep
their existing permissions.

The native integration suite is `tests/wordpress-public-api.php`, run on
disposable WordPress 6.5/current installations in CI. It checks real REST
validation and SQL pagination, date/taxonomy/team scopes, public-only totals,
summer/winter offsets, tied kickoffs, unknown filters, zero scores, incomplete
results, forfeits, overrides, immediate metadata edits and read-only methods.
`tests/wordpress-result-display.php` additionally compares the feed with match
cards, match lists, calendars and both bracket layouts. It covers forfeit
precedence, stale played scores, unknown outcomes, tied knockout winners and
reopened results. Calendar result labels and score details also have DOM
coverage in `tests/calendar-results.cjs`.
Never run its fixture program against an existing website.
