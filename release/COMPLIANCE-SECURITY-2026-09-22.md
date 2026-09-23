# Cleara11y submission audit — September 22, 2026

> Historical audit snapshot: see [warning remediation](WARNING-REMEDIATION-2026-09-22.md) for the updated package and passing Plugin Check results.

## Conclusion

The current installable ZIP has been checked with Plugin Check 2.1.0 and manually reviewed for WordPress.org submission and security concerns. Confirmed security problems found during this audit were fixed. This is not a blanket certification of security or a guarantee of directory approval. Plugin Check still reports **0 errors and 888 warnings**; warnings were not suppressed to manufacture a clean report.

Submission metadata is now `Plugin Name: Cleara11y`, readme heading `Cleara11y`, contributor `cleara11y`, text domain `cleara11y`, and archive root `cleara11y/`. The main file is `cleara11y.php`. The version header, constant, and stable tag remain 1.6.1; this is an unpublished release candidate. Author display/URI remain the existing `caveim`/GitHub attribution, which is separate from the WordPress.org contributor account.

## Evidence and package identity

- Package: `cleara11y-1.6.1.zip`, 439,116 bytes, 72 explicitly allowlisted files.
- SHA-256: `441fd4748c85957ddecde2cb008fb0d24d146d91908efec7390ac5805b0a4d61`.
- Final Plugin Check output: `plugin-check-2026-09-22.json`.
- Fresh pre-fix comparison: `plugin-check-before-2026-09-22.json` (894 warnings, zero errors).
- Integration output: `integration-2026-09-22.txt`.
- Every final ZIP entry was compared byte-for-byte against its current source file. ZIP CRC and manifest checks passed. Audit notes, tests, apps, service code, development tools, Git metadata, and directory artwork are excluded.

## Requirements review

Reviewed the [Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/) and [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Their submission, code, metadata, and distribution requirements informed the following checks. Account conduct and future release practices cannot be certified from source code.

| Area | Evidence / status |
| --- | --- |
| Licensing (1–2) | GPL-2.0-or-later declaration and license included. Vendored axe-core has MPL-2.0 attribution, license, and exact-version source links. Original artwork generator exists. |
| Complete, readable distribution (3–4, 16) | Installable 72-file package; no obfuscated PHP; only axe-core is minified, with readable source documented. |
| Trialware and service dependence (5–6) | Local functionality; no payment gate, trial expiration, or required SaaS account found. |
| Privacy and remote execution (7–8) | No plugin telemetry, remote updater, or remote executable dependency found. Local scan requests and data retention are described in the readme. |
| Honest behavior and branding (9–12, 17) | Readme explains manual-review limits; five tags; no affiliate/review incentives found. Public highlighter access defect fixed. Naming checks pass; availability remains external. |
| Libraries (13) | Bundled axe-core; WordPress-provided jQuery. No replacement core libraries packaged. |
| Release practices (14–15) | Versions agree. GitHub-to-SVN workflow is not yet installed; future releases must be versioned. |
| Directory decisions (18) | WordPress.org review and approval are pending. |

FAQ/package checks: slug normalization is `cleara11y`; valid readme and main headers; package below 10 MB; no nested archives; icons/banners/screenshots remain separately under `wordpress-org/`; artwork must be deployed to SVN assets, not plugin trunk. Slug availability/reservation is not established by these checks.

## Security findings fixed

1. **Legacy frontend highlighter exposed stored issue data without authorization.** `inject_highlighter_script()` independently accepted `?edac=<issue-id>` even when nonce/capability validation in the earlier hook failed. It now renders only an issue authorized earlier in the request, requiring a valid nonce, permission to edit that issue's post, and a matching queried page.
2. **Stored JavaScript injection in that highlighter.** HTML escaping did not make rule/message strings safe inside a JavaScript template literal; `${...}` and backticks could remain active syntax. Issue data now uses JSON hex escaping and DOM `textContent`/text nodes, preserving evidence as text.
3. **Unsafe link and attribute rendering.** Browser-submitted help URLs could reach frontend/explorer links without protocol validation. Links now allow HTTP/HTTPS only. Explorer escaping now encodes quotes as well as markup before insertion into attributes.
4. **Sensitive diagnostic content.** Removed scan names from selected operational logs and replaced arbitrary REST authentication error messages with debug-only sanitized error codes.
5. **Additional cleanup.** Removed unconditional self-OPcache invalidation from eight runtime files; fixed date-filter unslashing/sanitization; removed the legacy highlighter's calls to nonexistent JS/CSS files when replacing that path with its existing inline renderer.

Existing controls reviewed include REST/AJAX capability and nonce boundaries, post-specific scan permissions, token generation/expiry/consumption, SQL construction, output rendering, local HTTP fetches, direct-file guards, and uninstall entry guarding. Database identifiers originate from the schema and query values use WordPress prepare/insert/update APIs in the paths examined. Scan HTML/evidence intentionally remains intact as data; stripping it would damage reporting.

The highlighter regression test failed against the old installed package with unauthorized output and passed against the rebuilt package. Tests cover anonymous access, invalid nonce, wrong page, cross-author access, authorized output, and malicious stored strings. JS tests cover attribute breakout strings and executable/obfuscated URL schemes.

## Verification performed now

Environment: isolated WordPress 7.1.1 installation inside the development container, PHP 8.2.31, `WP_DEBUG=1`, Plugin Check 2.1.0. The main development WordPress installation is 7.0.

- Plugin Check on the extracted installed release, with runtime checks enabled using `--require=.../plugin-check/cli.php`: **0 errors, 888 warnings**.
- Separate final header/readme/trademark checks: no errors found.
- All **41 runtime PHP files** passed syntax checks; changed JavaScript passed syntax checks; `git diff --check` passed.
- **17 JavaScript tests passed**.
- **12 integration/security suites passed**: WCAG configuration, timezone handling, jobs, evidence persistence, template attribution, fingerprints, exception matching, identity retention, occurrence lifecycle, REST/token boundaries, AJAX permissions, and highlighting security.
- Expanded REST boundary test additionally verified **46 protected route handlers** reject anonymous access and tokenless result submission returns 403.
- Rebuilt package deactivation/reactivation succeeded on the isolated WordPress installation.
- Builder's limited known-secret-signature scan passed. This is not an exhaustive secret detector.
- Vendored `axe.min.js` matches `https://unpkg.com/axe-core@4.10.2/axe.min.js` exactly, SHA-256 `b511cd9dec01c76f4b2ad1723b66b6db37d4c2eb4ed199076e1829d9ee7b75e3`.
- GitHub global advisory API lookup for ecosystem npm, `axe-core@4.10.2`: zero matching advisories returned on this date. This does not establish absence of unknown vulnerabilities.

Reproduction:

```sh
python3 tools/build-release.py
node --test tests/js/*.test.js
# Against an isolated WordPress installation containing the extracted ZIP and activated Plugin Check:
wp plugin check cleara11y --require=/path/to/wp-content/plugins/plugin-check/cli.php --format=json
wp eval-file /path/to/repository/tests/integration/security-boundaries.php
wp eval-file /path/to/repository/tests/integration/security-ajax.php
wp eval-file /path/to/repository/tests/integration/security-highlighter.php
```

The CLI emits per-file JSON blocks; the saved report normalizes them into one JSON array with file names. Initial attempts against an archive with runtime mode and an inactive checker failed at the checker setup stage; the reported successful runs used an installed extracted package and an activated checker on the isolated site.

## Remaining warnings and limits

| Plugin Check warning family | Count | Interpretation |
| --- | ---: | --- |
| Direct database calls / no cache | 423 | Custom scan tables and mutable worker state. Blind caching would be unsafe; query construction still needs human review. |
| Interpolated SQL / direct DB parameters | 321 | Mostly schema identifiers and composed queries. Reviewed construction patterns; no new injection demonstrated. These warnings are not a formal proof of safety. |
| Schema changes | 19 | Plugin-owned table creation/migrations. |
| Dynamically constructed placeholders | 8 | Placeholder lists built for prepared `IN`/filter queries; static analyzer limitations. |
| Nonce verification | 44 | Primarily read-only filters and tokenized scan detection. State-changing handlers reviewed separately. |
| Input sanitization | 2 | Boolean preferences compared strictly with `1` after unslashing. |
| Logging | 68 | Operational/debug logging remains. This is not a logging-free release. |
| Prefixing | 3 | Includes the WordPress cache-control constant and uninstall loop variables. |

[Plugin Check's documentation](https://wordpress.org/plugins/plugin-check/) explicitly distinguishes automated checks from manual approval. This audit does not claim all warnings have been eliminated or every possible execution path proved safe.

Not rerun: a complete WordPress 6.0/PHP 8.0 minimum-version matrix, multisite, full browser end-to-end scanning, or destructive uninstall. Prior September 18 notes describe broader browser/uninstall checks, but those are historical evidence. The new highlighting behavior was exercised through WordPress integration tests, not a fresh visual browser review. No independent penetration test was performed.

Publication follow-ups: confirm slug on the actual submission, obtain approval/SVN access, configure release automation, and verify the directory-produced ZIP. The existing banner/screenshots still reflect the previous `ClearA11y` capitalization; the plugin header/readme now use the requested `Cleara11y`. Update/review artwork before deploying directory assets. No submission, commit, tag, push, or publication occurred.
