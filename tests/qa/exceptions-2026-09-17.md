# Exception QA — September 17, 2026

## Fix verification

The reproduced behavior bugs below have now been addressed, with one correction to the original test result:

- Scope restrictions apply before element/violation matching.
- Explicit all-rules-on-element exceptions match the element identity, with duplicate-identity protection retained.
- Next-scan exceptions expire for page, site, content-type and URL-pattern scopes; expiry does not depend on a finding still being present.
- The wizard retains its selected page URL independently of the final submitted scope.
- Escape is stopped at the wizard and restores focus to the Create exception trigger, leaving issue details open.
- Corrected the `color-contrast` label and made Notes visibly required.
- **Correction:** empty-note validation already existed. The original browser `fill('')` operation did not clear the prefilled value. Retesting with Select All + Backspace confirmed Next is disabled. The input handler now also handles change events.
- Removed an empty-parameter SQL preparation warning encountered while applying element exceptions.

Verification: the scope/target matrix passes; extended transaction-backed `exception-matching.php` tests pass (including all-rules collisions and next-scan scope exclusions); real scans #24 and #27 pass unchanged/content-mutation lifecycle checks; scan #28 confirms both rules on the selected input remain suppressed while other elements/pages stay active, and the wizard-created URL-pattern next-scan exception is expired. Browser checks confirm scope Back/Next recovery, required-note validation, and Escape focus restoration. PHP/JS syntax and diff checks pass.

All test exceptions are inactive after cleanup. Fixture A is restored to its original text input. Detailed post-fix evidence is in `exception-results-after-fixes-2026-09-17.json`. The historical results below are retained as reproduction notes, not current failures.


Local WordPress, current working tree based on main `3ab038cd`, with the wizard UI edits in this session. No production exception behavior was changed during this test run. Full-site and selected-page scan confirmation prompts were removed afterward as requested.

## Fixtures and method

- Exception QA A: page 15, http://localhost:8888/?page_id=15
- Exception QA B: page 16, http://localhost:8888/?page_id=16
- Exception QA C: post 17, http://localhost:8888/?p=17
- Each contains a primary input with both `label` and `aria-valid-attr-value` failures, a distinct secondary input with a `label` failure, and a button with a `button-name` failure.
- Created by `tests/fixtures/exception-qa.php`. The setup is local-only and preserves existing fixtures when rerun.
- Baseline browser scan #16 scanned all six published items. A had 9 findings, B had 9, and C had 15 (including theme findings).
- Scope/target tests used the registered REST routes and persisted issue status on these real findings. They did not fabricate scan results. Each matrix exception was disabled before the next case.
- Actual browser rescans #18 (A unchanged), #19 (A primary input changed from text to search), and #20 (B) checked lifecycle behavior. The deliberate failures remained in the content.
- `tests/integration/exception-qa-local.php` contains the repeatable matrix/lifecycle checks. Its JSON evidence is in `exception-results-2026-09-17.json`. Run phases separately, with real browser scans between prepare/unchanged/changed. Failed assertions are recorded explicitly in the JSON output.

## Passed

- Rule-only scope: Single Page affects only A; Entire Site affects A/B/C; Pages affects A/B but not C; Posts affects C but not A/B.
- URL patterns: `*page_id=16` affects only B; a nonmatching pattern suppresses nothing.
- Rule-on-element, single page: only the chosen primary-input label finding is suppressed. Its other rule, the secondary input, and other pages remain active.
- Disable restores the issue; re-enable suppresses it again.
- Element-specific Until Content Changes survives an unchanged scan and stops suppressing when the input type changes. An unrelated permanent exception stays effective.
- Quick snooze REST action expires on B's next scan (#20), unlike the wizard duration option below.
- Date expiry was tested earlier in this session using real elapsed time at 16:02 UTC: scans #13/#14/#15 showed 2 → 1 → 2 active findings. Exact-second matcher/list mismatch remains as previously discussed; non-UTC site configurations were not covered.
- Empty URL pattern and no selected content types disable Next.
- URL pattern text and selected post-type controls survive scope switching and Back/Next. The final saved URL-pattern scope contains only `scope_type` and `patterns`, without hidden post-type values or page URL.
- Radio arrow keys work; Tab/Shift+Tab wrap inside the modal.
- Scope controls open inside the selected option; desktop and narrow/short viewport sizing were verified in the preceding UI pass.

## Reproduced failures

1. **Element-specific scope is not enforced.** Create This rule on this element from A's primary label finding, select Content Types → Posts. A is a page and should remain active, but it is suppressed. `Exception_Matcher_Service::matches_rule()` checks scope only for target `rule`; the identity branch bypasses it.
2. **All rules on this element only suppresses the original rule.** From A's primary label finding, choose target `element`, single page. `label` becomes an exception but `aria-valid-attr-value` on the same input remains active. Both target types anchor and match the original violation identity; a matching element identity alone is only a resemblance.
3. **Wizard Until Next Scan never expires on rescan.** Both a page-scoped element exception and a wizard-created rule/URL-pattern exception stay active across real scans. They have `system_generated=false`; `expire_snoozes_for_url()` only expires system-generated, page-scoped snoozes. The quick snooze control (`system_generated=true`) passed the same rescan test.
4. **Single Page loses its saved URL after switching scopes across steps.** Choose Content Types → Posts, Next, Back, Single Page. The detected page is still displayed, but Next is disabled. `saveCurrentStep()` replaces `data.scope`, removing the original URL; the hidden page picker prevents recovery within that wizard instance.
5. **Notes validation disagrees with the server.** Choose a reason and clear Additional Notes: Next is enabled. The REST create handler rejects the same empty note with HTTP 400 `exception_reason_required`. The label says “Additional Notes” without indicating it is required.
6. **Escape closes two layers.** Escape closes the Create Exception modal and the underlying issue detail panel, returning focus to the occurrence row instead of the Create exception trigger. Radio navigation and focus wrapping otherwise passed.

Previously observed: the Exceptions list mislabels `color-contrast` as “Links must have discernible text.”

## Cleanup and remaining work

All QA exceptions were disabled or naturally expired; the original expiry test remains expired. The fixture pages/post are intentionally retained for review and future tests. A's primary input remains `type=search` from the mutation test. No existing user content was edited. No commits were made.

Recommended order: fix scope enforcement, all-rules targeting, next-scan expiry, and wizard state/validation; then align the Exceptions management UI with Issues Explorer. These tests do not establish every target × scope × duration combination, custom post types, alternate themes, or timezone behavior.

## Exceptions management UI

Updated the management list to a compact five-column layout, with readable target labels and a side panel for the review decision, selector context, recorded match count, and history. Actions now live in that panel. Fixed empty-state rendering, rapid status-request cancellation, filtered counts/pagination, editable page navigation, and audit metadata escaping. The audit list links to individual exceptions and expands metadata on demand.

Verified locally: Active empty state; Disabled pages 1/2 (20 + 6 rows); Expired filter drops 4 to 3 when hiding quick snoozes; Enable then Disable updates counts and closes stale details; Edit opens the existing wizard; audit history loads; Escape returns focus; Shift+Tab wraps within details. Inspected desktop 1280×720 and mobile 390×640; page has no horizontal overflow on mobile, table scrolls within its container, panel fills viewport with fixed actions. Corrected inherited Close-button width. No test exceptions remain active from this UI check.

PHP syntax checks for all three changed PHP files, JavaScript syntax check, git diff whitespace check, layout detector, and fail-safe exception matching integration test passed. Revoke submission and saving through every edit-wizard step were not repeated in this UI pass.
