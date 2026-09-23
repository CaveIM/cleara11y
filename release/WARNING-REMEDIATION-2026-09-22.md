# Cleara11y Plugin Check warning remediation

The rebuilt release passes Plugin Check 2.1.0 with **0 errors and 0 warnings**, including runtime checks. Verified both in the isolated release test installation and the clean inspection site at http://localhost:8890. Only Cleara11y and Plugin Check are installed on that inspection site.

## Code fixes

- Centralized operational logging behind WP_DEBUG; production diagnostics are disabled when debugging is off. The intentional diagnostic sink is annotated.
- Unslash and sanitize boolean preference input before comparison.
- Prepare schema metadata query values; escape literal table names used in LIKE queries.
- Avoid wpdb::prepare with an empty parameter list for unfiltered exception impact counts; filtered values remain bound.
- Prefix uninstall variables.

## Reviewed warning exceptions

The previous 888 findings were not 888 security defects. After code fixes, 796 warnings remained. Reviewed custom-table operations have line or query scoped PHPCS annotations for the specific reported rules, rather than file-wide disabling or changes to Plugin Check configuration. They cover plugin-owned schema identifiers, fixed SQL fragments with separately prepared values, schema migrations, and direct queries against live job/scan/exception state where caching could make state stale. Expiring-token bulk cleanup has no corresponding bulk options API.

Read-only URL flags and filters have individual nonce-rule explanations; privileged actions retain their existing nonce/capability or expiring-token checks. DONOTCACHEPAGE is an established cache integration constant. These annotations acknowledge scanner limitations; they are not a security certification or a guarantee of WordPress.org approval.

## Verification

- Plugin Check: 0 errors, 0 warnings on the packaged plugin on both installations.
- 12 integration/security suites pass, including a new regression for unfiltered SQL preparation and quoted rule filter values.
- 17 JavaScript tests pass.
- All 41 runtime PHP files pass syntax checks; git diff --check passes.
- Diagnostic logging tested with WP_DEBUG both true and false.
- ZIP CRC, exact 72-file allowlist, and source-byte comparisons pass.
- Clean inspection site updated from this ZIP; existing inspection data preserved.

Package: cleara11y-1.6.1.zip (446,833 bytes).
SHA-256: `5f683ce7bd2fbecc51be654c31764d6b363b9007d59061006c239a89b3658620`.

Evidence: plugin-check-clean-2026-09-22.txt, plugin-check-clean-2026-09-22.json, integration-warning-fixes-2026-09-22.txt, js-warning-fixes-2026-09-22.txt.

The earlier COMPLIANCE-SECURITY-2026-09-22.md and Plugin Check reports are historical evidence of the preceding package; this report supersedes their package hash and outstanding-warning counts. No submission, commit, or deployment to WordPress.org was performed.
