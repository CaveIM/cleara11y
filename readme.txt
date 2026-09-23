=== Cleara11y ===
Contributors: cleara11y
Tags: accessibility, a11y, wcag, accessibility audit, axe
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find accessibility issues in published WordPress content, review page evidence, and manage exceptions with Cleara11y.

== Description ==

Cleara11y helps agencies, developers, and content teams audit published WordPress pages and posts from the WordPress dashboard.

* Run browser-based accessibility checks using the bundled axe-core engine.
* Review findings by page, rule, severity, and scan.
* Inspect element selectors and evidence to help locate issues.
* Distinguish detected violations from findings that need manual review.
* Track recurring findings and manage exceptions with review history.
* Highlight findings on the front end for authorized users.
* Queue scans and configure recurring scan schedules.

The plugin runs locally within WordPress and your browser. No Cleara11y account, paid subscription, or external scanning service is required.

= Scanning and manual review =

Automated checks cover only part of accessibility testing. Cleara11y does not certify WCAG compliance or automatically repair your site. Combine results with keyboard, screen reader, and other manual testing.

Browser scans require an authorized WordPress admin page to remain open while queued pages are processed. Scheduled scans queue work using WP-Cron; browser processing still needs an active authorized admin session. Site traffic and hosting configuration affect WP-Cron timing. Server-side checks have different coverage from the rendered-page browser engine.

= Privacy and stored data =

Scan records, page URLs and titles, element selectors, HTML evidence, and exception history are stored in your WordPress database. Evidence can contain content from scanned pages. Restrict access to reports and database backups accordingly. Scanning loads your site's pages, including resources and third-party services already used by your theme and content.

Cleara11y does not send scan results to an external Cleara11y service and does not require a remote axe-core CDN. Links to accessibility guidance open external websites only when followed.

Deactivation retains scan data. Deleting the plugin removes its scan and exception tables and settings for the current site. Back up reports before deleting it. Network-wide multisite deployment has not been verified.

= Third-party code =

This plugin includes axe-core 4.10.2 by Deque Systems, Inc., licensed under the Mozilla Public License 2.0. The distributed file is assets/js/axe.min.js. The corresponding human-readable source is available at https://github.com/dequelabs/axe-core/tree/v4.10.2 and https://unpkg.com/axe-core@4.10.2/axe.js . License text is included in licenses/axe-core-MPL-2.0.txt.

== Installation ==

1. Upload the cleara11y folder to /wp-content/plugins/, or install the ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate Cleara11y in the Plugins screen.
3. Open Cleara11y in the WordPress dashboard.
4. Select published content and start a scan. Keep an authorized admin page open until browser scanning finishes.
5. Review findings, verify them manually, and rescan after making changes.

Your site must allow the WordPress REST API and same-site page loading for browser scans. Security plugins, restrictive framing policies, password protection, and caching can affect scanning.

== Frequently Asked Questions ==

= Does Cleara11y make my site accessible? =

No. It identifies potential issues and provides evidence for review. Fixes must be made in your content, theme, or other plugins, and manual testing remains necessary.

= Is a subscription required? =

No. The local plugin works without a subscription or Cleara11y account.

= Can I ignore an irrelevant finding? =

Yes. Use exceptions after reviewing the finding and its scope. Broad exceptions can hide relevant findings, so review them periodically.

= Why has my scheduled scan not finished? =

WP-Cron queues scheduled work. Browser scans are processed while an authorized admin page is open. Check the queue, REST API availability, and browser console if progress stops.

= Will uninstalling remove my data? =

Deleting the plugin removes its current-site scan and exception tables and settings. Deactivation retains them. Back up any data you need before deletion.

== Screenshots ==

1. Dashboard with scan progress and page accessibility findings on a demonstration site.
2. Issues explorer for reviewing findings by page, rule, and severity.
3. Exception management for reviewed findings.

== Changelog ==

= 1.6.1 =
* Initial WordPress.org submission candidate.
* Browser scanning, page reports, finding evidence, and exception management.
* Hardened scan queue permissions, scan-token expiry, output escaping, and uninstall cleanup.
