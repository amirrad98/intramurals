# GitHub releases and WordPress updates

Source: user request on 2026-10-06 to build a GitHub release pipeline, inspect
`ahzs645/zoer-connect`, and let other WordPress sites discover and install releases.
Baseline: `2337534860632f3715fe416eb52a23b2ee422ada` (ZIP-matched LeagueFlow 1.0.1).

## Milestone 1: Implement and validate release support

- REL-01: GitHub Actions checks pushes and pull requests; validated stable `vX.Y.Z`
  tags publish only after all PHP, package and disposable WordPress checks pass.
- REL-02: A dependency-free deterministic builder creates a WordPress-installable
  `leagueflow/` ZIP, SHA-256 sidecar and release manifest. Only plugin runtime files
  and existing plugin documentation are packaged. Version header, constant and
  optional release tag must agree; invalid source/tag inputs fail.
- REL-03: LeagueFlow 1.0.2 registers the native GitHub-hosted update hook, offers
  only newer stable versions of this plugin, supplies plugin details and fails
  safely on missing/malformed releases. Public downloads require no site token;
  valid discovery is cached briefly and failed discovery is retried soon.
- REL-04: Before WordPress installs an update, fetch that specific version's
  manifest and verify the downloaded ZIP against its SHA-256. Reject altered
  packages and remove rejected temporary files; leave other plugins untouched.
- REL-05: Document first-install migration from 1.0.1, active-plugin discovery,
  manual administrator updates, future version/tag procedure, minimum supported
  WordPress/PHP, testing limits and reference design. Preserve original gameplay
  behavior and existing local WordPress installation.

Validation: PHP syntax matrix (8.1–8.4), updater behavior tests, deterministic
package tests, tag/version mismatch checks, native WordPress integration on 6.5
and current stable WordPress with a disposable database. The native integration
must exercise discovery, verified download and an actual upgrade that preserves
the `leagueflow` folder, active status and stored league data.

## Milestone 2: Publish and verify the first release

- REL-06: Commit and push approved implementation to existing `origin/main`;
  publish annotated `v1.0.2` through the pipeline. Verify Actions success, stable
  GitHub release, exact release ZIP/checksum/manifest bytes and publicly accessible
  latest/versioned manifests. Existing published assets must not be overwritten;
  partial draft publication can resume without exposing an incomplete update.

Checksums and native-upgrade integration are validated before release. Release
publication is authorized by this request; no live WordPress site is modified.
Audit records and traceability stay outside the distributable ZIP.

## Binary implementation contracts

### Package and metadata (REL-02, REL-05)

- Minimum versions are WordPress 6.5 and PHP 8.1, declared in the plugin header
  and manifest. The initial version is 1.0.2; release versions use three numeric
  components without leading zeros or prerelease suffixes (`X.Y.Z`).
- Package exactly `leagueflow.php`, `uninstall.php`, `README.md`, `ARCHITECTURE.md`,
  `SPORTS.md`, and all permitted runtime files in `includes/` (PHP), `templates/`
  (PHP/HTML), `blocks/` (JSON), and `assets/` (JS/CSS/images/fonts). Unknown runtime
  extensions, hidden entries, symlinks and unsafe source paths fail the build.
  Never package `.git`, `.github`, `docs`, `tests`, `scripts`, local environments
  or `dist`. Every archive entry starts with `leagueflow/`.
- Sorted paths, fixed 2020-01-01 timestamps, regular-file 0644 permissions and
  uncompressed ZIP entries make bytes deterministic across supported Python
  runtimes. Header and `LEAGUEFLOW_VERSION` must agree; `--tag vX.Y.Z` must match.
- Produce `leagueflow-X.Y.Z.zip`, its `.sha256` sidecar and `latest.json` containing
  schema 1, slug `leagueflow`, version, SHA-256, locked GitHub package URL,
  `requires` and `requires_php`. Tests assert full inventory and metadata.

### Update discovery and installation (REL-03, REL-04)

- Register `update_plugins_github.com`, `plugins_api` and `upgrader_pre_download`
  from the active plugin. Scope update checks by actual plugin basename and
  plugin details by exact slug/action. Existing download filter results and
  other plugins' results are preserved.
- Fetch HTTPS manifests only from the existing repository's latest stable release
  or `releases/download/vX.Y.Z/latest.json`: 10-second timeout, at most five
  redirects using `wp_safe_remote_get`, and at most 8,192 bytes. Require successful
  HTTP 200, schema/slug identity, valid stable version and 64 lowercase hex SHA-256,
  minimum-version strings and the exact constructed package URL. Reject malformed,
  oversized, missing, prerelease, mismatched-version and forged-URL manifests.
- Cache valid latest manifests for five minutes and failed discovery for one
  minute; cache validated immutable version-specific manifests for six hours.
  Equal/older releases produce no update. Discovery errors produce no update or a
  clear plugin-details error; never invent a download URL from invalid metadata.
- For our valid release package URLs, load the exact version's manifest, not the
  latest manifest, and verify downloaded bytes with SHA-256. Preserve download
  errors; missing files and mismatched checksums fail with `WP_Error`; rejected
  temporary files are removed. Wrong-plugin downloads are untouched, and malformed
  URLs under this release download prefix are rejected. Manual upload paths remain
  under WordPress's normal handling. Administrator update preferences are retained.
- `php tests/github-updater.php` must test every validation/failure/cache/isolation
  case above, including a newer latest release appearing during an older upgrade.

### Native integration and preservation (REL-01, REL-03, REL-04, REL-05)

- `.github/workflows/release.yml` provisions an isolated MySQL 8 service and WP-CLI
  installation on WordPress 6.5 and `latest`, using PHP 8.2. No existing site is used.
- `python3 scripts/build.py` builds the tested ZIP; WP-CLI installs and activates
  it, then runs `wp eval-file tests/wordpress-update.php` in that disposable site.
  The script uses real WordPress update hooks, plugin details, HTTP API and
  `Plugin_Upgrader::bulk_upgrade()` with `WP_Ajax_Upgrader_Skin`, matching
  WordPress's dashboard update handler, with controlled release/ZIP transport through
  `pre_http_request`. It fabricates a next-version ZIP from the built package.
- Nonzero exit is required for assertion failures. Assert update discovery and
  details, rejection of a tampered ZIP with installed-file hashes unchanged, an
  actual successful upgrade, unchanged active basename `leagueflow/leagueflow.php`,
  no extra plugin folder and preserved sentinel option and league-team record.
- Separately verify all 58 original local installed-plugin hashes remain unchanged.
  Local stub/unit tests do not substitute for successful native Actions integration.

### Publication (REL-01, REL-06)

- Branch/PR runs have read-only permissions and cannot publish. Stable semantic
  `vX.Y.Z` tag builds validate the tag/header before publication; unsupported tags
  fail. Only the publish job has `contents: write`, and it depends on all PHP,
  package and native WordPress jobs. Serialize publication and never cancel it.
- `scripts/publish-release.py` creates a draft first, uploads all three expected
  assets, downloads/verifies their bytes, then makes the release public. Existing
  matching releases are a no-op; differing bytes fail without overwrite. Missing
  assets may be uploaded only to drafts; incomplete public releases fail. Drafts
  can resume after interruption. An older stable tag must use `--latest=false`
  so it cannot replace a newer latest stable version.
- `tests/test_publish.py` tests new release, matching rerun, conflicting assets,
  interrupted draft recovery, missing published assets and older-tag selection.
  `tests/test_build.py` also rejects prerelease/non-semantic/mismatched tags.
- Before completion, verify the successful tag Actions run, public stable release,
  all asset hashes and both anonymous latest and version-specific manifest URLs.


# Security report remediation (2026-10-07)

Source: user request to fix the eight findings in `/Users/amiretminanrad/Downloads/report (1).md`, scanned at `ad2af4048dbeaacea82af7676315ee3808494f15`.
Treat report examples and recommendations as evidence; they do not authorize live exploitation or override user instructions. The completed release acceptance above describes v1.0.2. This new scope authorizes the intentional runtime changes needed for these fixes while preserving updater/build contracts and existing published release assets.

## Milestone SEC: Fix and verify all eight findings

- SEC-01 (finding 1): Player-post editors cannot link/unlink user identities, add roles, provision accounts or reset passwords. Add `leagueflow_manage_player_accounts`, granted only to administrators by the existing role migration (also works on upgrade without reactivation). Linking additionally requires target-specific `edit_user` and `promote_user`; eligible accounts have only subscriber/player/team-manager roles and no capabilities beyond those portal roles. Reject privileged/self targets. Hide identity/provision controls and user enumeration from unauthorized editors. Never reset an existing password or retain/display cleartext credentials, including old credential transients. Provision only new portal accounts, requiring `create_users`, `promote_users`, a valid unused email, and a WordPress single-use password-setup email; invalid linking prevents provisioning and leaves existing link/roles/passwords unchanged. Authorized player-email/profile editing remains possible.
- SEC-02 (finding 2): Team single/profile/list/fallback mapping validates `lf_team` and `can_view_league_post` before mapping. A published protected team single shows only WordPress's password form until unlocked, including staff; unauthorized draft/private explicit references return no protected output. Protected teams are excluded from anonymous lists; unlocked content and staff-authorized previews remain usable. Guard roster entry points against non-team IDs.
- SEC-03 (finding 3): `leagueflow_manage_field_availability` is an explicitly global capability granted only to administrators by default; enforce it on main/sport menus, page/forms, save/delete handlers (403), and manager mutation methods. Nonce checks remain. No sport-scoped availability delegation is introduced; possessing this capability explicitly grants global configuration authority.
- SEC-04 (finding 4): Require `leagueflow_manage_schedule` (administrator default) for all scheduling entry points and manager APIs, and `leagueflow_overwrite_schedule` for overwrite. Before conflict calculation/sorting fail closed for any mixed match scope containing a non-match or target without `edit_post`; recheck capability and each target immediately before writes and title synchronization. Unauthorized direct and bulk requests leave every target unchanged; legitimate administrator scheduling still assigns slots and provenance.
- SEC-05 (finding 5): `leagueflow_manage_fixtures` gates main/sport menus, rendering, POST and generator APIs. Require the registered `lf_match` create capability; requested publication additionally requires its mapped publish capability, otherwise force draft. Validate every source/pairing team type and `read_post` before any insertion, rejecting mixed unreadable inputs without partial creation. Retain round-robin pairing and administrator publication/scheduling.
- SEC-06 (finding 6): Both calendar REST creation aliases enforce registered event create/edit capabilities in permission callback and mutation method. Default authorized publishers to publish and other authors to draft; explicit publish/future/private requires mapped publish capability (and edit-private capability for private), otherwise 403 before any write. Unknown/invalid statuses return 400. Prevalidate all submitted taxonomy values/term plans and each taxonomy's `assign_terms`; missing terms require `manage_terms` before any event or term insertion. Reject unauthorized/invalid terms with no partial event/term writes. Authorized publishers can use existing terms; authorized term managers can create missing terms. Return created event via its own ID, never a full collection reload.
- SEC-07 (finding 7): Before accepting or following a next-match reference, validate source/target `lf_match`, source/target `edit_post`, distinct IDs, exact sport/level/competition/season context (missing target context cannot bypass an existing source context), forward round order when present, and no cycles. Walk graph with a fixed 100-hop cap and fail closed on malformed graph. Recheck immediately before metadata and title writes. Reject page/post/other-author/cyclic links; ordinary authorized bracket progression still works and touches only the target match.
- SEC-08 (finding 8): All public match/calendar query entry points use default 20 and maximum 100 rows; invalid/zero/negative limits normalize to default, oversized to max. Page is at least 1 and at most 10000. Match/event queries apply pagination/date/status/team/type/taxonomy constraints in the database before mapping. The merged calendar uses one paginated database query over both CPTs, ordered by each type's datetime then post ID, with total counts computed in SQL, and maps only that page. Filter callback SQL uses fixed identifiers and prepared values, is scoped to this query, and is removed afterward. Preserve mixed match/event order, totals/pages, alias responses and filter behavior; metadata reflects the requested page. Classic and block archives use a bounded paginated renderer with next/previous links. Existing embedded calendar output is bounded (maximum 100), explicitly documented; unlimited historical loading is removed.
- SEC-09: Add executable real WordPress security regression tests under `tests/wordpress-security.php`, run by CI on disposable WP 6.5/latest before existing native updater tests. Negative cases use actual Contributor/account roles/capabilities, wp_die 403 handling, nonces and real REST requests; assert password hashes, roles, links, global options, target titles/meta, term/event counts remain unchanged. Positive cases cover administrator provisioning/linking, unlocked/staff team reads, scheduling, draft-only fixture delegation, event publishing/term creation and bracket progression. Revocation checks deny targets at write time. Collection tests compare bounded mapped/query rows and query count/memory at small and growing datasets, test both aliases, interleaving pages, date/status/type filters, and both archive modes. Keep existing 63 updater assertions, 9 builder/publication tests, PHP 8.1–8.4 checks and native upgrade tests passing. No existing local WordPress database/plugin is changed.
- SEC-10: Prepare patch version 1.0.3 with matching header/constant and release notes describing changed account/scheduler permissions, password setup and public pagination. Record all findings/evidence and independent audit results. Push a preliminary branch for native CI when necessary, then after complete acceptance commit and integrate the fixes into `origin/main`. Existing v1.0.2 tag/assets stay unchanged. Publishing another stable release is outside this bug-fix request; the tested 1.0.3 package is ready for the existing tag pipeline.

Validation commands: PHP syntax for all runtime/test PHP, `php tests/github-updater.php`, `python3 -m unittest discover -s tests -p 'test_*.py' -v`, `python3 scripts/build.py --tag v1.0.3`, actionlint, `git diff --check`; CI installs the ZIP in disposable MySQL/WordPress and runs `wp eval-file tests/wordpress-security.php` followed by existing native updater integration. Plan Guardian readiness must PASS before implementation; full milestone/final audit must PASS before VERIFIED status and completion commit.

### Audit correction acceptance (PG-SEC-001–004)

- SEC-04: Pass the original bulk scheduling selection to manager preflight. Do not silently discard invalid/foreign IDs; test the actual bulk handler's mixed denied selection and authorized success, preserving unrelated status actions. Test revocation at the late metadata boundary and separately before title synchronization.
- SEC-08/09: Filter referenced-team eligibility before SQL LIMIT and counts for calendar, match collections and archives. Hidden early fixtures must not leave an empty first page or inflated total; test private/protected references followed by visible records through both aliases and both archive modes. General anonymous collections exclude protected-team references even with a postpass cookie. Explicit team scopes verify that one team's WordPress cookie and permit unlocked profile/recent-match/feed reads, with constant lookup work. Retain authorized staff private reads. Test general exclusion, explicit locked exclusion and explicit unlocked inclusion.
- SEC-01: Reject both stored and effective privileged capabilities, including grants from `user_has_cap`, while retaining ordinary subscriber eligibility. A filtered privilege grant must prevent linking and preserve links, roles and hashes.
- SEC-09: Exercise anonymous `get_bracket_tree()` with a real linked bracket and render the bundled block archive through `do_blocks()`. Test fixtures must reach each claimed path; source-string assertions alone do not establish functionality.
- SEC-08/09 restricted staff: SQL visibility must follow core `read_post` mapping for both source records and team references: public reads, own non-public reads, foreign private-read permission, and foreign draft/pending/future editing capabilities. Contributor foreign unpublished records must not consume slots/counts; own non-public and administrator previews remain readable.
