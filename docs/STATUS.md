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
