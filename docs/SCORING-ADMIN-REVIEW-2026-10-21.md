# Scoring, admin and sport setup review

Prepared for **LeagueFlow 1.1.1**, against `main` at `4a79fc4809afdd0e9dc01ce7803ff84fe34f3071`.
This review changes repository code and tests. It does not certify or change a live
website, migrate existing league data, publish a release, or install an update.

## Result workflow

The ordinary match editor now handles the common final-result workflow directly:

| Saved input | Resulting match lifecycle |
| --- | --- |
| Scheduled, both valid scores, including `0–0` | Finished automatically |
| Scheduled, a valid forfeit outcome | Finished automatically; played scores are optional |
| Scheduled, one or both scores blank | Scheduled; blanks remain missing |
| Scheduled, complete scores, **Keep the selected status** checked | Scheduled; the choice persists through later edits |
| Live, Postponed or Cancelled with valid scores | The selected state is preserved |
| **Save match as final result** with a valid complete result | Finished explicitly |
| Finished with corrected scores | Finished with the corrected result; standings count the match once |
| Finished changed to Scheduled with **Keep the selected status** checked | Reopened; no longer contributes to standings |
| Invalid/negative/fractional/overflow score, stale form or incompatible participant context | Protected result update refused; prior protected values retained |

Scores remain nonnegative whole numbers up to PHP's exact integer range. No new
sport-specific numeric cap is imposed. Volleyball's main fields are **sets won**;
per-set details remain optional notes. Basketball uses points, soccer uses goals,
and other modules use their configured labels. Multiline sport notes and literal
backslashes survive the save correctly.

Publication and match lifecycle are separate. Finishing a draft does not publish
it. Public league standings use published, Finished, non-knockout matches with
valid published participants in the selected context. Corrections are derived
from the same source match and cannot create another standings result. Existing
scored Scheduled records are not bulk-finalized at installation: staff can review
and save them or use the existing validated bulk Finished action.

### Save integrity

The staff and referee writers hold the same per-match database lock, reject stale
forms, validate the complete submitted result, and verify protected metadata and
fixture context in a transaction. Match status is written last. Lifecycle observers
run only after successful commit, so they see the saved scores and context together.
A failure rolls back protected result values and does not announce completion.

This transaction covers LeagueFlow's protected result fields. WordPress saves core
post title/publication fields and some taxonomy data in its own editor flow; the
entire WordPress post edit is not one atomic operation. Classic context validators
prevent incompatible competition/season assignments before core writes them.

## Knockout progression

A completed result automatically feeds its configured next match when the graph,
permissions, context and target state are valid. Normal score winners and valid
participant overrides are supported; a valid forfeit takes precedence over an old
manual override. Double forfeits, unknown outcomes and unresolved normal ties have
no advancing winner.

The synchronizer records which source supplied each participant. Correcting a
winner updates an eligible unplayed target. Reopening, cancelling, retargeting or
trashing a source retracts its old owned slot when it is still safe to do so.
Restoring a source retries eligible advancement. The opposite participant is kept.
A target with a score, result outcome, explicit winner, Live/Finished/Cancelled
status, conflicting feeder or incompatible context is preserved. The source shows
a staff-review notice rather than silently rewriting a later result.

Source and target state are refreshed under their locks. Participant, provenance
and generated target title save together; partial writes roll back. Internal title
updates do not replay the original editor form into another match. Bracket writes
use a savepoint inside a caller-owned transaction so imports/tests can still roll
back their work. External importers editing existing shared brackets must retain
the relevant result locks through their own commit, or synchronize after commit;
a savepoint alone does not provide that caller-level concurrency guarantee.

Assigned referees can finalize only permitted matches. Their audit stores actor,
scores and the complete committed source-state token. Automatic advancement verifies
that exact state and current participant context, and permits writes only to the
assigned source and its configured direct target/slot. It does not give a referee
general match-editing rights or permission to alter obsolete historical targets.
Later rounds may be read to validate the graph without granting edit authority.

## Admin navigation and interface

- Match lifecycle has a dedicated filter; WordPress Published/Draft/Trash views
  remain publication controls.
- List views, counts and return links retain sport, level, competition, season,
  generated batch, search and lifecycle scope where applicable.
- Changing sport clears incompatible competition, season and batch selections.
- Editor breadcrumbs use the record's stored sport, even if the URL has stale
  context. Term editor links return to a valid list screen.
- Sidebar entries are selected explicitly. Staff guide links, highlighting and
  assets follow the same navigation behavior as the other LeagueFlow screens.
- Dashboard counts and participant selectors respect object visibility. Stored
  inaccessible knockout links keep their ID without exposing the hidden title.
- Match lists label forfeits and avoid turning malformed legacy scores into zero.
- Result preview text agrees with server-side behavior, including blank versus
  zero, integer overflow, intentional non-final states and the explicit final action.
- Batch selection handles disabled and partly selected rows. Form, settings and
  list-filter layouts include responsive rules.

These are functional and clarity changes to WordPress-native administration, not
a replacement admin application. Layout behavior is covered by markup/DOM and
JavaScript regressions. The connected browser could not reach the isolated local
server, so this review does **not** claim new rendered browser screenshots or
visual acceptance on the user's site.

## Per-sport configuration and taxonomy context

At least one supported sport must be selected. An empty or invalid submission
preserves the saved configuration and reports the problem. Disabling a sport keeps
its records. Normal initialization creates missing sport terms without rewriting
staff-edited labels or descriptions.

Rules inherit independently through **global defaults → sport → competition**.
A blank override inherits its parent; an explicit zero remains zero. Points for
wins/draws/losses, tie-breakers and the forfeit winner score are independently
configurable. Invalid sport/competition overrides are refused before replacing
their saved values, including invalid numbers and duplicate/invalid tie-breakers.
Global settings retain an invalid numeric field’s previous value and report it.
Blank or duplicate public URL slugs also retain the previous configuration. The
rule forms show inherited values and reset controls. Standings award points and
walkover scores using each match's effective competition rules, including when a
table spans competitions. The table's tie-breaker order follows the selected table
context.

Competition and season terms retain their owning sport. Changing that owner cannot
strand existing records. Classic team/match forms, supported event creation APIs,
and native team/event REST edits validate the resulting sport context. Existing
unchanged legacy assignments can still be edited without a forced migration.
Calendar API creation preserves its capability-based custom-sport creation contract;
new competitions/seasons receive their selected sport, all context is validated
before creation, and failure cleans up the incomplete event and newly created terms.
Current seasons are tracked and returned consistently per sport.

Setup mapping validates raw identifiers before coercion. It also refuses context
changes on linked knockout nodes: an operator must resolve the graph relationship
rather than silently moving one fixture into an incompatible sport or competition.

## Scheduling consistency

The scheduler holds a site-wide reservation lock and a per-match result lock,
refreshes the selected fixture, and rechecks authority and started/closed status.
The date, venue, saved duration, availability reference, source/timestamp and
optional generated title now form one verified update. Failed metadata/title writes
or late permission loss restore the previous schedule. A candidate slot is reserved
only after the update succeeds, so a failed fixture cannot consume a slot or release
its old booking for another selected match.

Malformed IDs are rejected as submitted instead of converting a negative, fractional,
boolean, overflow or text-prefix value into another match or wider scope. A selected
batch with any malformed ID fails before changing its valid entries.

Scheduling refuses an unfinished caller-owned transaction. The caller must commit
or roll back before scheduling so the site-wide lock cannot be released while its
new reservations are still invisible to another request. It never implicitly commits
or rolls back the caller's transaction.

## Public results and integration boundaries

Cards, lists, brackets, calendar details/exports and the public result feed agree on
forfeits and explicit knockout winners. They do not display obsolete played scores
as a forfeit result or coerce malformed scores into `0`. A Finished result without
valid scores is described as unavailable/incomplete. An incomplete one-participant
fixture remains TBD; only a completed, explicitly awarded one-sided fixture is shown
as a bye, with the participant on its actual side.

The public API adds `outcomeLabel`; known forfeits return no played score and resolve
the winner consistently. Unknown stored outcomes are explicitly incomplete. See
[Public match API](PUBLIC-MATCH-API.md) for the exact response contract.

Generic `/wp/v2/lf_match` writes to protected result, participant, schedule, bracket
or taxonomy context fields now return `leagueflow_match_workflow_required` before
core saves anything. Core's sequential metadata writer cannot enforce the result
workflow's lock, validation and stale-form token. Ordinary content/publication edits
remain supported. Integrations that previously attempted protected generic REST
writes must use a supported result workflow; no replacement write API is introduced.
Programmatic metadata writes do not auto-finalize after each individual field, since
that would finalize partially imported or intentionally live data.

## Verification

The final acceptance run uses fresh installations of WordPress **6.5** and the
currently downloaded stable **7.1.3**, PHP **8.3.6**, MariaDB **10.11.14**, and the
built `leagueflow-1.1.1.zip`. These are disposable sites with synthetic records.
Final local verification completed on **22 October 2026**. All **18 native suites**
passed on both versions. The 17 counted application suites contain **2,426 assertions
per WordPress version**, followed by the successful native update integration test.

| Native suite | WordPress 6.5 | WordPress 7.1.3 |
| --- | ---: | ---: |
| Security and permissions | 696 | 696 |
| Portal workflows | 258 | 258 |
| Portal HTTP requests and cleanup | 180 | 180 |
| Existing admin workflows | 406 | 406 |
| Admin navigation and result form | 69 | 69 |
| Sport setup, rule inheritance and context | 92 | 92 |
| Automatic results and atomic saves | 78 | 78 |
| Knockout synchronization | 66 | 66 |
| Native REST result boundary | 22 | 22 |
| Public result displays | 58 | 58 |
| Standings and knockout lifecycle | 85 | 85 |
| Scheduling and concurrency | 82 | 82 |
| Calendar and public scope | 37 | 37 |
| Registration and placement | 53 | 53 |
| REST identity and workflows | 42 | 42 |
| Referee and result administration | 80 | 80 |
| Public match API | 122 | 122 |
| **Counted assertions** | **2,426** | **2,426** |
| Native update discovery and verified replacement | Passed | Passed |

Additional local checks passed:

- PHP syntax across the plugin and test files.
- Standalone updater (63 assertions), admin filters (11), admin assets (15), asset
  versions (19), and match date/time (15 cases).
- `npm test`, including calendar/result rendering and exports, time-zone regressions,
  registration consent and admin form interactions.
- All 17 Python packaging, release-tag and publication tests.
- `git diff --check` and deterministic package generation.

The tested package SHA-256 is
`b07ac28171bb9597f779b89a7e7066d08b562e1658e708bba000f4b4bff8fa9f`.

The first full run exposed a leftover temporary offline guard from the HTTP test,
which blocked the following updater test. Its cleanup previously suppressed deletion
errors and never asserted success. Cleanup is now checked, and the revised HTTP suite
followed immediately by the update suite passed on both installations. A controlled
comparison did not reproduce the original deletion failure; no unsupported filesystem
root cause is asserted. These are test-harness changes, not plugin runtime changes.

GitHub Actions includes the new native suites, PHP syntax/standalone checks on
8.1–8.4, JavaScript tests, deterministic packaging/publication tests, and native
WordPress 6.5/latest tests using MySQL 8.0 and PHP 8.2. Remote run status is reported
on the pull request. The update suite exercises
checksum rejection and a real successful `Plugin_Upgrader` replacement while
preserving settings, records and active plugin status.

Fault cases include rejected metadata/title writes, unavailable locks, held locks
from another database connection, stale forms, permission changes, cross-sport
context, malformed identifiers, transaction rollback, referee audit replay and
unsafe downstream bracket changes. These targeted checks supplement existing
registration, consent, roster, request, fixture, calendar and public API suites.

## Review and rollout

1. Review the pull request and CI results, including the protected generic REST
   write restriction if external integrations are in use.
2. Test the package against a staging copy with the actual theme, plugins, roles,
   sports and representative existing fixtures.
3. Publish version 1.1.1 through the established verified release workflow after
   review, then install it through the normal plugin update process.
4. Review existing scored Scheduled fixtures and any bracket notices. Installation
   itself does not bulk-finalize matches, rewrite old scores, move teams between
   sports, or repair a started bracket automatically.
