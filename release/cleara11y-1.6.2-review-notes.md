# WordPress.org review follow-up — 1.6.2

The ZIP is ready for resubmission. It has not been uploaded, and no email has been sent.

## Review findings

- Replaced direct script/style output in settings, reference pages, the editor metabox, debug redirect and frontend highlighter with WordPress enqueue/localization/inline-script APIs. Added the extracted assets to the release allowlist.
- Added shared validation and metadata sanitization before AJAX/REST scan results are persisted. Invalid structures are rejected, executable help URLs are removed, and unknown top-level fields are omitted. HTML evidence, selectors and fingerprints remain source data and are escaped by display consumers.
- Restricted DONOTCACHEPAGE and scan headers to valid, unexpired tokens bound to the requested page. Invalid tokens and wrong-page tokens return 403.
- Escaped generated attribution markers and moved the source map into an escaped data attribute on an inert template element. The existing rendered block/content/shortcode/widget HTML remains unchanged so the scanner observes the actual page. This intentional pass-through needs the clarification below.
- Checked the dashboard notice concern against guideline 11: settings notices follow user actions and are dismissible; scan progress is contextual and dismissible. No promotional or upgrade nags were found in these paths.

## Verification

- PHP syntax checks passed for all changed PHP files and the new sanitizer.
- All 18 JavaScript tests passed; new asset JavaScript syntax checks passed.
- WordPress-backed PHP regression checks passed for malformed results, metadata sanitization, evidence preservation, and attribution-map round trips.
- Two real full-site browser scans completed all six pages on the disposable development site; the second used the initial review fixes; the broader follow-up changes were checked with the regression suites below. Results and source attribution appeared in the Issues Explorer.
- Verified settings assets, editor metabox configuration, and authorized frontend highlighting in the browser.
- HTTP checks: ordinary page 200 without scan attribution; invalid token 403; valid page-bound token 200 with attribution; wrong-page token 403 without attribution.
- Installed the exact final ZIP into the isolated inspection WordPress site. Plugin Check reported: “Success: Checks complete. No errors found.”
- A check of the entire development directory also traversed unrelated development artifacts. The clean result above applies to the production ZIP built from the explicit allowlist.
- Verification covers the local WordPress installations and their existing theme, not every supported WordPress version or third-party cache integration. No production dependencies were added.

## Broader follow-up audit

Reviewed production request inputs, PHP output and JavaScript rendering, REST/AJAX authorization, asset loading, notices, and global side effects for further instances of the review categories. Additional fixes:

- Restricted scan types to supported values and sanitized scan names/error metadata; escaped stored metadata and restricted link protocols in dashboard, page, issue-type, reference, exception and frontend rendering.
- Added nested exception payload shape validation and additional scan evidence/target validation. Guarded direct request values against unexpected arrays.
- Enqueued the reference page's axe dependency through WordPress and removed per-request asset cache busting.
- Removed REST initialization table creation, cross-plugin REST error logging and unnecessary rewrite flushing. Scoped database maintenance notices to ClearA11y pages; restored libxml error state after PHP scanning.
- Required published content before creating a scan token, and added an explicit capability guard to debug rendering.

Follow-up verification: syntax checks passed for all 42 production PHP files and all asset JavaScript. Nine WordPress integration suites passed: security boundaries (46 protected route handlers), AJAX, highlighter, template attribution, evidence persistence, exception matching, fingerprint v2, job lifecycle and occurrence lifecycle. Additional WordPress regression checks passed for scan-type rejection, malformed exception inputs, sanitizer behavior and libxml state restoration. The Issue Reference browser check displayed all 104 rules. This audit targets the review's issue classes; it is not a guarantee against every possible defect or a full cross-version/theme compatibility certification.

## Suggested email reply after uploading the ZIP

Thank you. I’ve uploaded version 1.6.2 and tested the updated package with Plugin Check.

One clarification: template attribution runs only on validated scan requests. It preserves already-rendered WordPress/theme/plugin HTML so the accessibility audit sees the actual page; filtering that HTML would alter the results. ClearA11y’s added markers and source-map attributes are escaped.
