const {test, expect} = require('@playwright/test');
const {execFileSync} = require('node:child_process');

const WP_PATH = process.env.CLEARA11Y_WP_PATH || '/var/www/html';

function wp(args) {
	return execFileSync(
		'wp',
		[...args, `--path=${WP_PATH}`, '--allow-root'],
		{encoding: 'utf8'}
	).trim();
}

function createPage(title, content) {
	return Number(wp([
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		`--post_title=${title}`,
		`--post_content=${content}`,
		'--porcelain'
	]));
}

async function runScan(browser, postId) {
	const tokenData = JSON.parse(wp([
		'eval',
		`echo wp_json_encode(
			\\ClearA11y\\Services\\Scan_Token_Manager::generate_token(${Number(postId)})
		);`
	]));
	const page = await browser.newPage();

	try {
		const resultRequest = page.waitForResponse(
			response => response.url().includes('/cleara11y/v1/scan/results')
				&& response.request().method() === 'POST',
			{timeout: 60000}
		);
		await page.goto(tokenData.scan_url, {
			waitUntil: 'domcontentloaded',
			timeout: 60000
		});
		const response = await resultRequest;
		expect(response.status(), `Scan ${tokenData.scan_id} did not persist`).toBe(200);
		return Number(tokenData.scan_id);
	} finally {
		await page.close();
	}
}

function coverageForScan(scanId, fixture) {
	const rows = JSON.parse(wp([
		'eval',
		`
			$rows = $GLOBALS['wpdb']->get_results(
				"SELECT source_type, source_ref, owner_type, source_key, node_evidence
				FROM {$GLOBALS['wpdb']->prefix}cleara11y_issues
				WHERE scan_id = ${Number(scanId)}",
				ARRAY_A
			);
			echo wp_json_encode($rows);
		`
	]) || '[]');
	const resolvable = rows.filter(row => {
		if (!row.node_evidence) return false;
		const evidence = JSON.parse(row.node_evidence);
		return Boolean(evidence?.node_evidence);
	});
	const attributed = resolvable.filter(row => Boolean(row.source_key));
	const sourceTypes = [...new Set(attributed.map(row => row.source_type).filter(Boolean))];

	return {
		fixture,
		totalOccurrences: rows.length,
		resolvableNodes: resolvable.length,
		attributedNodes: attributed.length,
		coverage: resolvable.length ? 100 * attributed.length / resolvable.length : 0,
		sourceTypes
	};
}

function cleanupScans(scanIds) {
	if (!scanIds.length) return;

	const ids = scanIds.map(Number).join(',');
	wp([
		'eval',
		`
			$scan_ids = [${ids}];
			$prefix = $GLOBALS['wpdb']->prefix . 'cleara11y_';
			$issue_ids = $GLOBALS['wpdb']->get_col(
				"SELECT id FROM {$prefix}issues WHERE scan_id IN (${ids})"
			);
			if ($issue_ids) {
				$GLOBALS['wpdb']->query(
					"DELETE FROM {$prefix}violation_ignore_matches
					WHERE violation_id IN (" . implode(',', array_map('absint', $issue_ids)) . ")"
				);
			}
			$GLOBALS['wpdb']->query(
				"DELETE FROM {$prefix}occurrence_states WHERE latest_scan_id IN (${ids})"
			);
			$GLOBALS['wpdb']->query("DELETE FROM {$prefix}issues WHERE scan_id IN (${ids})");
			$GLOBALS['wpdb']->query("DELETE FROM {$prefix}scan_items WHERE scan_id IN (${ids})");
			$GLOBALS['wpdb']->query("DELETE FROM {$prefix}scans WHERE id IN (${ids})");
		`
	]);
}

test('reports attribution coverage for block, classic, and Elementor fixtures', async ({
	browser,
	request
}) => {
	test.setTimeout(180000);

	const originalTheme = wp(['option', 'get', 'stylesheet']);
	const elementorWasActive = wp(['plugin', 'get', 'elementor', '--field=status']) === 'active';
	const fixtureContent = [
		'<!-- wp:heading --><h2 style="color:#fff;background:#fff">Invisible heading</h2><!-- /wp:heading -->',
		'<!-- wp:html --><button></button><img src="/missing-attribution-fixture.png"><input type="text"><a href="/"></a><!-- /wp:html -->'
	].join('');
	const createdPostIds = [];
	const createdScanIds = [];
	const reports = [];

	try {
		wp(['theme', 'activate', 'twentytwentyfive']);
		const blockPostId = createPage('ClearA11y Block Attribution Fixture', fixtureContent);
		createdPostIds.push(blockPostId);
		const normalUrl = wp(['post', 'url', String(blockPostId)]);
		const normalResponse = await request.get(normalUrl);
		const normalHtml = await normalResponse.text();

		expect(normalResponse.headers()['x-cleara11y-attribution']).toBeUndefined();
		expect(normalHtml).not.toContain('<!--a11y:s:');
		expect(normalHtml).not.toContain('cleara11y-attribution-map');

		const blockScanId = await runScan(browser, blockPostId);
		createdScanIds.push(blockScanId);
		const blockReport = coverageForScan(blockScanId, 'block-theme');
		reports.push(blockReport);
		expect(blockReport.resolvableNodes).toBeGreaterThan(0);
		expect(blockReport.coverage).toBeGreaterThan(85);

		wp(['theme', 'activate', 'twentytwentyone']);
		const classicPostId = createPage('ClearA11y Classic Attribution Fixture', fixtureContent);
		createdPostIds.push(classicPostId);
		const classicScanId = await runScan(browser, classicPostId);
		createdScanIds.push(classicScanId);
		const classicReport = coverageForScan(classicScanId, 'classic-theme');
		reports.push(classicReport);
		expect(classicReport.resolvableNodes).toBeGreaterThan(0);
		expect(classicReport.coverage).toBeGreaterThan(60);

		wp(['theme', 'activate', 'twentytwentyfive']);
		if (!elementorWasActive) {
			wp(['plugin', 'activate', 'elementor']);
		}
		const builderPostId = createPage('ClearA11y Elementor Attribution Fixture', '');
		createdPostIds.push(builderPostId);
		const elementorData = JSON.stringify([
			{
				id: 'a11y0001',
				elType: 'section',
				settings: [],
				elements: [{
					id: 'a11y0002',
					elType: 'column',
					settings: {_column_size: 100},
					elements: [{
						id: 'a11y0003',
						elType: 'widget',
						widgetType: 'html',
						settings: {
							html: '<div><button></button><img src="/missing-builder-fixture.png"><p style="color:#fff;background:#fff">Invisible builder copy</p></div>'
						},
						elements: []
					}]
				}]
			}
		]);
		wp(['post', 'meta', 'update', String(builderPostId), '_elementor_edit_mode', 'builder']);
		wp(['post', 'meta', 'update', String(builderPostId), '_elementor_template_type', 'wp-page']);
		wp(['post', 'meta', 'update', String(builderPostId), '_elementor_version', '4.2.0']);
		wp(['post', 'meta', 'update', String(builderPostId), '_elementor_data', elementorData]);

		const builderScanId = await runScan(browser, builderPostId);
		createdScanIds.push(builderScanId);
		const builderReport = coverageForScan(builderScanId, 'elementor');
		reports.push(builderReport);
		expect(builderReport.resolvableNodes).toBeGreaterThan(0);

		console.log(JSON.stringify(reports, null, 2));
	} finally {
		wp(['theme', 'activate', originalTheme]);
		if (!elementorWasActive) {
			try {
				wp(['plugin', 'deactivate', 'elementor']);
			} catch (error) {
				// Preserve the primary test result.
			}
		}
		cleanupScans(createdScanIds);
		createdPostIds.forEach(postId => {
			try {
				wp(['post', 'delete', String(postId), '--force']);
			} catch (error) {
				// Preserve the primary test result.
			}
		});
	}
});
