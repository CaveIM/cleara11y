> Superseded for the current ZIP by [September 22 compliance and security audit](COMPLIANCE-SECURITY-2026-09-22.md). The package has been rebuilt with additional security fixes and corrected submission metadata. The findings below describe the September 18 candidate.

# ClearA11y 1.6.1 release candidate

Prepared September 18, 2026. This review note is intentionally excluded from both publishing ZIPs.

## Deliverables

- `cleara11y-1.6.1.zip`: installable plugin, with 72 explicitly allowlisted files under `cleara11y/`.
- `cleara11y-1.6.1.sha256` and `.manifest.txt`: checksum and exact file inventory.
- `cleara11y-wordpress-org-assets.zip`: directory artwork and demonstration screenshots under `assets/`.
- `../readme.txt`: WordPress.org listing, installation, FAQs, privacy/data retention, scanner limitations, source attribution, and changelog.
- `plugin-check.json`: full Plugin Check output; not included in the plugin.

## Security changes

- Restricted scan state, queue advancement, job leasing, heartbeat, and completion REST endpoints to administrators. Anonymous clients previously could lease jobs and receive bearer tokens.
- Restricted legacy site-wide AJAX queue operations to administrators. Completion now requires the caller's lease token and uses the REST handler's evidence and size validation instead of looking up the token on the caller's behalf.
- Required post-specific edit capability for AJAX page statistics and scan initiation. Global scanner scripts load only for administrators.
- Normalized token expiry to UTC, rejected malformed token strings, bound frontend token use to its intended page, and consumed successful REST submission tokens. Added no-referrer response headers and removed logging of tokenized URLs.
- Bound iframe results to the correct worker window. Parent-origin error messages carry the corresponding job ID.
- Prepared the page-type SQL filter. Reviewed dynamic SQL findings; narrow PHPCS annotations explain fixed SQL fragments, schema-derived identifiers, and separately prepared values. Table helpers were moved out of SQL concatenations where needed for static analysis. No minimum-version-incompatible identifier placeholders were introduced.
- Escaped HTML attribute quotes and translated output. Restored TLS verification and safe redirect/address validation for server-side HTTP scanning.
- Removed the URL-triggered development test runner and temporary OPcache invalidation. Removed raw database error details from plugin logging because they can contain evidence content.
- Fixed uninstall class loading, exception-table cleanup, escaped token-option matching, cron cleanup, and single-site display-preference cleanup.
- Fixed REST response types on error paths to allow WP_Error responses.
- Excluded the interactive scanner overlay from axe analysis after browser verification exposed self-generated findings.

## Verification

- PHP syntax checks passed for all 41 runtime PHP files; `git diff --check` passed.
- 14 JavaScript tests passed, including cross-worker message isolation.
- Existing WordPress integration suite passed on the development WordPress 7.0 installation.
- 11 integration/security tests passed against the extracted release on isolated WordPress 7.1.1: WCAG configuration, timezones, job lifecycle, evidence persistence, template attribution, fingerprints, exception matching, identity retention, occurrence lifecycle, REST/token boundaries, and author-level AJAX denial.
- Real anonymous browser scans persisted results for three demonstration pages on WordPress 7.1.1. Replaying the same scan token was rejected with HTTP 403. Anonymous queue/state/leasing requests returned HTTP 401. Scanner-overlay findings were absent.
- Full browser queue scan completed all five published demo pages/posts after reinstall, verifying authenticated worker leasing and completion end to end.
- ZIP installed and activated on a clean WordPress 7.1.1 installation. Deactivate/uninstall succeeded, removed plugin tables, and preserved an unrelated similarly named option. The ZIP was then installed again.
- Plugin Check 2.1.0, run against a separate extraction of the final ZIP: **0 errors, 894 warnings**. These are not a warning-free certification. Most warnings concern custom-table queries, intentionally uncached queue/state access, schema operations, schema-generated identifiers, and operational logging. Other warnings include read-only GET filters without nonces, booleans validated by comparison, date validation helpers the analyzer cannot follow, and dynamic placeholder lists. Reviewers may still request changes.
- Vendored axe-core 4.10.2 was not changed. Its MPL license and exact-version human-readable source links are provided.
- Package contents are validated against an explicit file allowlist. No `.git`, hidden files, AI instructions, notes, test fixtures, development configuration, application/database files, node modules, package manifests, lockfiles, or operational scripts are included. Known credential/private-key patterns were checked; none detected.

## Remaining limits and assumptions

- Retained the existing name ClearA11y, version 1.6.1, author/contributor `caveim`, and existing GitHub author/plugin URLs. Confirm the WordPress.org username and preferred public links before submission.
- `Tested up to: 7.1` reflects actual WordPress 7.1.1 checks. This was not a complete WordPress/PHP/browser compatibility matrix; the declared WordPress 6.0/PHP 8.0 minimum was retained. No multisite certification is claimed.
- Browser processing requires an active authorized admin page; scheduled scans queue work rather than providing a standalone headless browser service.
- Automated findings need manual review and do not certify WCAG compliance.
- The existing dashboard's narrow “Pages to Scan” column can truncate titles when several action buttons are present. This is a UI follow-up, not hidden by altered screenshots.
- Screenshots use a separate demonstration site, not customer data or private exception notes. Original geometric icon/banner artwork is a proposed publication identity; confirm or replace it before upload.
- Existing uncommitted exception-system changes were preserved and included as current runtime code. Unrelated untracked application files were untouched and excluded.
- No commit, push, WordPress.org submission, or SVN publication was performed.

## Publishing

1. Confirm the public metadata above. If changed, update both the plugin header/version constant and readme as appropriate.
2. Rebuild with `python3 tools/build-release.py` after any runtime or readme edit. Recheck that exact ZIP before uploading; never ZIP the repository itself.
3. Submit only the installable plugin ZIP through https://wordpress.org/plugins/developers/add/ . WordPress.org account ownership, slug availability, and review approval are external to this preparation.
4. After directory approval, place the extracted plugin files in SVN `trunk` and the matching release tag. Place the separate artwork ZIP's contents in the SVN top-level `assets` directory, alongside `trunk`, not inside the plugin.
5. Follow the directory reviewer's instructions, verify the published listing and screenshots, and download/install the directory-produced ZIP before announcing the release.

References: [Plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), [readme format](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/), and [directory artwork requirements](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
