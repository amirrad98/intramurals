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
