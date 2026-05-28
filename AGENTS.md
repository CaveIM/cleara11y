# ClearA11y Agent Instructions

## Project Context

- ClearA11y is a WordPress accessibility plugin for scanning published content for WCAG 2.1 AA issues.
- The plugin supports WordPress 6.0+ and PHP 8.0+.
- The repo is mounted in the devcontainer at `/workspaces/cleara11y` and into WordPress at `/var/www/html/wp-content/plugins/cleara11y`.
- The local WordPress site runs at `http://localhost:8888`; phpMyAdmin runs at `http://localhost:8081`.
- Admin credentials for the disposable dev site are `admin` / `password`.

## Product Direction

- ClearA11y is primarily for agencies and developers building WordPress sites who need to run accessibility audits for client work.
- The plugin itself should be fully functional and free for WordPress.org distribution.
- A future paid SaaS service may connect to the plugin for deeper external scanning, AI-assisted accessibility analysis, and richer reporting, but the local plugin should remain useful on its own.
- Do not build SaaS-only assumptions into core plugin behavior unless explicitly requested.
- Optimize for technical accuracy and agency reporting workflows first, while keeping the UI usable for non-specialists working inside an agency context.

## Product Priorities

- Scans should be reliable, fast, and consistent.
- Avoid the poor experience where separate scan modes disagree with each other. Prefer one clear source of truth for issue detection and reporting.
- Local scanning should do the best job possible with tools that run inside WordPress, PHP, browser APIs, axe-core, and normal web server constraints.
- Improve usability around running scans, understanding reports, and acting on results.
- The ignore system is a core feature. Preserve and improve the ability to suppress irrelevant findings without hiding valid accessibility issues unintentionally.
- Avoid workflows that require users to keep a fragile browser/admin page open longer than necessary unless that is inherent to the current scanner architecture.

## Working Style

- Make the smallest correct change that solves the task.
- Preserve existing behavior unless the task explicitly asks for a behavior change.
- Do not rewrite large files, reorganize directories, or introduce abstractions unless there is a concrete benefit.
- Treat user changes as authoritative. Do not revert or overwrite work you did not make.
- Do not commit, amend, push, or create PRs unless explicitly requested.

## Dependencies And Tooling

- This repo currently has no Composer or npm project manifest.
- Development tooling may be added only when it does not change production dependencies or runtime behavior.
- Ask before adding production dependencies, build steps, generated bundles, or package manager lockfiles.
- Do not replace the vendored `assets/js/axe.min.js` casually. If updating axe-core, follow `assets/js/README.md`.

## PHP And WordPress Standards

- Follow WordPress Coding Standards for new and changed PHP.
- Match the surrounding file style where existing code differs, while improving security-sensitive code when needed.
- Use WordPress APIs for capabilities, nonces, sanitization, escaping, filesystem paths, URLs, cron, REST, and database access.
- Escape output at render time with the appropriate `esc_html`, `esc_attr`, `esc_url`, or `wp_kses` function.
- Sanitize and validate all request data before use.
- Verify nonces and capabilities before privileged admin, REST, AJAX, migration, or destructive operations.
- Use `$wpdb->prepare()` or safe schema APIs for dynamic SQL.
- Preserve the `ClearA11y\` namespace and the current PSR-4-style autoload mapping from `cleara11y.php` to `src/`.

## JavaScript And CSS

- Keep JavaScript dependency-free unless explicitly approved.
- Prefer plain browser APIs and existing WordPress-provided globals over adding libraries.
- Preserve the scanner architecture around axe-core, REST calls, iframe/background scanning, and WordPress admin/front-end boundaries.
- Avoid adding frontend overhead to normal visitor pages unless it is required for scanning or highlighting.
- Keep CSS scoped to ClearA11y admin/frontend classes to avoid leaking styles into themes or wp-admin globally.

## Accessibility Priorities

- Accessibility result integrity matters more than cosmetic changes.
- Preserve WCAG/axe metadata, evidence extraction, node targeting, selector handling, fingerprints, severity mapping, and ignore matching unless the task is specifically about changing them.
- Avoid changes that increase false positives, hide real issues, or make issue evidence less actionable.
- When changing scanner behavior, consider both client-side axe scans and PHP/server-side scans.

## Performance Priorities

- Protect admin responsiveness and frontend page load performance.
- Be careful with scan batching, cron work, REST polling, iframe worker counts, leases, and heartbeat behavior.
- Avoid unbounded loops, full-table scans, excessive DOM traversal, or large synchronous operations on normal page loads.
- For database changes, consider existing site data volume and migration safety.

## Verification

- After PHP changes, run syntax checks on changed PHP files when practical, for example `php -l path/to/file.php`.
- Use the devcontainer WordPress site for manual behavior checks when changes affect activation, admin pages, REST endpoints, scans, schedules, ignores, frontend highlighting, or database behavior.
- Useful WP-CLI checks from inside the devcontainer include `wp plugin status cleara11y --allow-root` and `wp plugin activate cleara11y --allow-root`.
- If verification cannot be run, state exactly what was not run and why.

## Nifty Workflow

- Use Nifty tools, not shell commands, for Nifty operations.
- Use workflow alias `plugin` for this project.
- The plugin workflow states are `Ideas`, `Shaped`, `Planned`, `Not Now`, `To Do`, `In Progress`, `Review`, `Dogfood`, `Ready to Release`, and `Released`.
- Add Nifty task comments for meaningful progress, blockers, verification results, and completion notes when working from a Nifty task.
- Do not create unresolved open questions in Nifty task descriptions. Ask the user first.

## Files To Treat Carefully

- `cleara11y.php`: plugin bootstrap, constants, autoloading, activation/deactivation, cron hooks, migrations.
- `src/Database/Schema.php` and related repositories: database schema and persistence safety.
- `src/API/*`: REST permissions, nonces, input validation, response shape.
- `src/Services/*Scanner*`, `assets/js/scanner*.js`, and `assets/js/evidence-extractor.js`: scan correctness and performance.
- `assets/js/axe.min.js`: vendored third-party library; update only intentionally.
- `uninstall.php`: destructive cleanup path; be conservative and verify carefully.

## Security Checklist

- Capability checks are required before admin-only actions.
- Nonces are required for state-changing admin actions.
- REST endpoints must define appropriate permission callbacks.
- All request data must be sanitized, validated, and normalized before use.
- All output must be escaped for its context.
- SQL must be prepared when dynamic values are used.
- Avoid logging secrets, nonces, tokens, cookies, raw request payloads, or sensitive content.
