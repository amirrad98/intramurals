# Release support status

- 2026-10-06: Inspected Zoer Connect's build workflow, deterministic packager,
  public feed and checksum-verifying updater. LeagueFlow will distribute directly
  through its existing public GitHub release assets, without another repository
  or additional site credentials.
- Initial planning: implementation and publication had not started; readiness
  audit was pending. Docker is not running locally; native WordPress integration will run
  in disposable GitHub Actions services. Local isolated tests remain distinct from
  native WordPress evidence.
- Readiness audit initially returned FAIL with PG-REL-001 (updater contracts),
  PG-REL-002 (package boundaries), PG-REL-003 (executable native verification) and
  PG-REL-004 (publication/recovery). Expanded PLANS.md with concrete acceptance
  contracts, negative cases, artifact names, native script/command and publication
  state transitions; updated all traceability rows. Re-audit pending.
- Readiness re-audit PASS resolved PG-REL-001 through PG-REL-004. Milestone 1 is
  implemented locally: updater, deterministic package builder, draft-first
  publisher, GitHub Actions PHP/native WordPress gates, tests and operator docs.
- Local validation: 40 PHP files pass syntax checks; 63 updater contract assertions
  and 9 Python build/publication tests pass. Native WordPress checks require the
  preliminary branch push to provision disposable Actions services; milestone
  acceptance and public release remain pending.
- Preliminary branch run `37549317643` passed PHP 8.1–8.4 and package checks.
  Native WordPress 6.5/latest failed because the test harness called nonexistent
  `Automatic_Upgrader_Skin::get_errors()`. Corrected the test to use WordPress's
  `WP_Ajax_Upgrader_Skin`, which collects upgrade errors; checksum rejection remains
  mandatory. Updated pinned official Actions to v6 and fixed Ubuntu to 24.04
  after runner deprecation warnings. Native rerun remains pending.
- Corrected branch run `37550187505` passed PHP/package, native discovery,
  details, tamper rejection and verified installation. Native preservation failed
  because direct `Plugin_Upgrader::upgrade()` deactivates active plugins outside
  cron. Changed the harness to `bulk_upgrade()` with `WP_Ajax_Upgrader_Skin`,
  exactly as WordPress's `wp_ajax_update_plugin()` dashboard handler does. Real
  error, installation, active-folder and database assertions remain mandatory;
  rerun and milestone acceptance remain pending.
- Branch run `37550392288` at `c16a4d74d700ed0fb61bcb5e386b7132f536234b`
  passed all seven check jobs: PHP 8.1–8.4, deterministic package/publication
  tests, and native WordPress 6.5/7.1.3 discovery, details, checksum rejection
  and verified upgrade with active folder and stored league data preserved.
  Publication was correctly skipped on the branch. All 58 original locally
  installed plugin hashes remain unchanged; among original ZIP files, only the
  bootstrap and README differ for release support. Milestone 1 acceptance and
  Milestone 2 readiness audit are pending; no release tag exists yet.
- Plan Guardian Milestone 1 completion / Milestone 2 readiness audit PASS:
  REL-01 through REL-05 independently accepted. Guardian reran 40-file syntax,
  63 updater assertions, 9 Python tests, actionlint/diff checks, deterministic
  packaging and baseline hash preservation, and verified successful native CI.
  Milestone 1 is complete; Milestone 2 may publish the initial stable release.
  REL-06 remains IMPLEMENTED until actual tag-run/public-asset verification and
  final independent acceptance.
- Approved Milestone 1 evidence committed as `1750e66`; annotated `v1.0.2`
  points to that commit and was pushed. Initial tag run `37558582665` is pending.
  No live WordPress installation was changed.
- Tag run `37558582665` at `1750e66dcb5ee4b83c82b11a81f2758a0c4661af`
  passed all eight jobs, including draft-first publication. Stable/latest public
  release: https://github.com/amirrad98/intramurals/releases/tag/v1.0.2
  Anonymous verification passed for all three exact assets, latest and pinned
  manifests, and stable/latest GitHub API metadata (six HTTP 200 requests).
  ZIP SHA-256: `680406fe3690ea48cc2ce4529e00563bac5c15a0ba6adc323e4ab2e286c1279d`.
  Milestone 2 and final full-project acceptance are pending independent audit;
  REL-06 remains IMPLEMENTED until that audit passes.
- Plan Guardian Milestone 2 / full-project / pre-completion-commit audit PASS:
  all six mandatory requirements independently accepted with no open findings.
  Guardian verified all eight tag jobs, independently reran the 40-file syntax,
  63 updater assertions, 9 Python tests and deterministic build, independently
  downloaded the six public endpoints, and confirmed tagged history and all
  baseline preservation checks. REL-06 is VERIFIED; both milestones are complete.
  Final administrative commit changes only these audit records. Published tag
  and assets remain unchanged.

## Security report remediation — 2026-10-07

- Read eight static security findings at current baseline ad2af40. Repository is clean and matches origin/main. Created fix/security-report for the fixes. Readiness plan/traceability added; independent audit pending before implementation. Report material is treated as evidence, not as instructions. No local site has been changed.
- Plan Guardian readiness audit PASS before implementation. Scope includes all eight report findings, native negative/positive workflows, growth assertions, patch metadata and main integration; historical v1.0.2 assets remain immutable.
- All eight fixes and the native regression harness are implemented locally. New management capabilities migrate on upgrade; existing account passwords are retained and provisioning sends a single-use WordPress setup link. Public collection filtering/pagination now occurs before mapping.
- Local checks PASS: 41 PHP syntax checks, 63 updater assertions, 9 deterministic build/publication tests, actionlint and diff checks; deterministic v1.0.3 package built. Corrected the builder test's hardcoded valid mismatch version so it continues to test an actual header/constant mismatch after future version bumps.
- Native WordPress 6.5/latest checks are pending a preliminary branch push. No acceptance/completion status is claimed; preliminary implementation audit requested before CI. No existing site or stable release has been changed.
- After-implementation Plan Guardian audit FAIL: PG-SEC-001 bulk scheduling discarded invalid/foreign selection IDs; PG-SEC-002 referenced-team visibility was applied after pagination/counts; PG-SEC-003 account eligibility missed effective privileges granted through WordPress filters; PG-SEC-004 tests did not reach late scheduler guards, linked bracket tree, or actual block archive rendering. All four findings are open; added explicit correction acceptance and requested readiness re-audit before corrections. No preliminary push or completion commit occurred.
- The audit also caught a misplaced bracket guard using undefined variables in public rendering; it was corrected locally with source/target checks moved to advancement mutations. The strengthened linked-tree regression remains part of PG-SEC-004.
- Correction readiness audit PASS. Implemented raw bulk-selection validation (including zero IDs), effective target privilege checks, and shared query-scoped participant eligibility before pagination/counts. Explicit unlocked team scopes perform one team lookup; general anonymous feeds omit protected references. Added actual mixed/positive bulk tests, fourth/fifth-boundary revocation assertions, linked-tree structure, hidden-first counts/occupancy, cookie/staff feed positives, and real block template rendering through WordPress's `get_the_block_template_html()`/`do_blocks()` path.
- Corrected local checks PASS again: 41 syntax files, updater63, Python9, deterministic build, actionlint/diff. Implementation re-audit pending; prior findings are not yet independently closed and native evidence remains pending.
- Correction implementation re-audit FAIL on remaining PG-SEC-002: SQL over-admitted other-author draft/pending/future team references and source records for Contributors. PG-SEC-001/003/004 passed the source checkpoint. Added explicit core ownership/status mapping acceptance; implemented a shared SQL visibility helper for source match/event queries and references, plus Contributor foreign-unpublished exclusion, own-record and administrator positives. Re-audit and native validation remain pending; nothing is VERIFIED.
- Plan Guardian correction/preliminary CI checkpoint PASS: all PG-SEC-001–004 source corrections independently accepted for testing. Authorized a preliminary branch commit/push only; native WordPress evidence, milestone completion and main integration remain pending. Restored affected SEC rows to IMPLEMENTED, not VERIFIED.
- Preliminary CI `37617420632` at `a50b612` passed PHP 8.1–8.4 and packaging. Native WP 6.5/7.1.3 passed SEC-02–08, including bulk denial/late guards, REST aliases, linked bracket and real block archive rendering. SEC-01 reached a harness error calling nonexistent `render_notices()` after account/setup-link assertions; corrected to actual `render_admin_notices()`. Captured the disposable password-change notification through the same native mail hook to avoid requiring sendmail in CI. No security assertion was removed or weakened. Rerun pending; native updater integration was not reached.
- Growth evidence on both native versions: 40 and 400 eligible records each load/map one row at limit1, with 16 queries at either size and less than 82 KiB retained memory. The new test explicitly checks the 40/400 SQL totals as well as row/mapping/query/memory bounds.
- Rerun `37617641042` at `263aa26` passed all seven check jobs: every native security group and the existing verified upgrade/tamper/preservation integration on WP 6.5/7.1.3. The success diagnostic incorrectly displayed zero assertions because WP-CLI evaluates the file in a local function scope while the assertion helper increments a global. Corrected the diagnostic to use the shared global explicitly and added a minimum assertion-count check; no existing assertion was weakened. Final diagnostic rerun pending before independent acceptance. On WP 7.1.3 retained memory was below 88 KiB; WP 6.5 remained below 82 KiB.
- Final diagnostic branch run `37617889286` at `76d250eebcb22a03ad8cc6c11996fdad0307b16b` passed all seven checks. Each native WordPress version reports eight security groups and 696 assertions, then passes real updater discovery/details, checksum rejection and verified upgrade preserving the active folder and stored league data.
- Plan Guardian SEC-01–09 acceptance / main integration readiness audit PASS. All PG-SEC-001–004 findings are independently resolved with native evidence. Guardian independently reran local checks/build and downloaded all three original stable-release assets, confirming original hashes/tag and all 58 locally installed plugin hashes. SEC-01–09 are VERIFIED; SEC-10 remains IMPLEMENTED until final integration acceptance.
- Fast-forwarded main to the exact tested `76d250e` and pushed origin/main successfully. Working checkout and remote agree. Main Actions run `37618371446` is pending; final full-project audit and administrative completion commit remain pending. No new stable tag/release was created and no local site was changed.
- Main Actions `37618371446` at the exact `76d250e` passed all seven check jobs, with publication correctly skipped. Final independent audit remains pending; the forthcoming completion commit is restricted to audit records outside the distributable package.
- Final full-project / SEC milestone / pre-completion-commit Plan Guardian audit PASS: all 16 mandatory REL/SEC requirements independently accepted, no open findings. Main and remote source are the exact tested `76d250e`; main CI `37618371446` passes. All eight report findings and PG-SEC-001–004 are resolved. SEC-10 is VERIFIED and the milestone is complete.
- Final administrative commit changes only STATUS/TRACEABILITY audit records. Version 1.0.3 is prepared/tested for the existing release pipeline; v1.0.2 remains the published stable release with original tag/assets. No website installation was modified.
