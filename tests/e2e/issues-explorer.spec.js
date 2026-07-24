const {test, expect} = require('@playwright/test');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const WP_PATH = process.env.CLEARA11Y_WP_PATH || '/var/www/html';
let fixturePostId = 0;
let fixtureScanId = 0;

function wp(args) {
	return execFileSync(
		'wp',
		[...args, `--path=${WP_PATH}`, '--allow-root'],
		{encoding: 'utf8'}
	).trim();
}

async function createOccurrenceFixture(browser) {
	fixturePostId = Number(wp([
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		'--post_title=ClearA11y Issues Explorer Fixture',
		'--post_content=<!-- wp:html --><button></button><img src="/missing-explorer-fixture.png"><p style="color:#fff;background:#fff">Invisible fixture</p><!-- /wp:html -->',
		'--porcelain'
	]));
	const tokenData = JSON.parse(wp([
		'eval',
		`echo wp_json_encode(
			\\ClearA11y\\Services\\Scan_Token_Manager::generate_token(${fixturePostId})
		);`
	]));
	fixtureScanId = Number(tokenData.scan_id);
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
		expect(response.status(), 'The Explorer fixture scan did not persist').toBe(200);
	} finally {
		await page.close();
	}
}

function cleanupOccurrenceFixture() {
	if (fixtureScanId) {
		wp([
			'eval',
			`
				$scan_id = ${fixtureScanId};
				$prefix = $GLOBALS['wpdb']->prefix . 'cleara11y_';
				$issue_ids = $GLOBALS['wpdb']->get_col(
					"SELECT id FROM {$prefix}issues WHERE scan_id = {$scan_id}"
				);
				if ($issue_ids) {
					$GLOBALS['wpdb']->query(
						"DELETE FROM {$prefix}violation_ignore_matches
						WHERE violation_id IN (" . implode(',', array_map('absint', $issue_ids)) . ")"
					);
				}
				$GLOBALS['wpdb']->delete($prefix . 'occurrence_states', ['latest_scan_id' => $scan_id], ['%d']);
				$GLOBALS['wpdb']->delete($prefix . 'issues', ['scan_id' => $scan_id], ['%d']);
				$GLOBALS['wpdb']->delete($prefix . 'scan_items', ['scan_id' => $scan_id], ['%d']);
				$GLOBALS['wpdb']->delete($prefix . 'scans', ['id' => $scan_id], ['%d']);
			`
		]);
	}
	if (fixturePostId) {
		wp(['post', 'delete', String(fixturePostId), '--force']);
	}
}

async function login(page) {
	await page.goto('/wp-login.php');
	await page.locator('#loginform').evaluate(form => {
		form.action = window.location.origin + '/wp-login.php';
	});
	await page.locator('input[name="redirect_to"]').evaluate(
		(input, value) => { input.value = value; },
		new URL('/wp-admin/', page.url()).href
	);
	await page.getByLabel('Username or Email Address').fill(process.env.CLEARA11Y_ADMIN_USER || 'admin');
	await page.locator('#user_pass').fill(process.env.CLEARA11Y_ADMIN_PASSWORD || 'password');
	await page.getByRole('button', {name: 'Log In'}).click();
	await expect(page).toHaveURL(/wp-admin/);
}

async function waitForResults(page) {
	await expect(page.locator('#cleara11y-issues-container'))
		.not.toHaveAttribute('aria-busy', 'true');
}

test.beforeEach(async ({page}) => {
	await login(page);
});

test.beforeAll(async ({browser}) => {
	await createOccurrenceFixture(browser);
});

test.afterAll(() => {
	cleanupOccurrenceFixture();
});

test('URL filters, grouping, and occurrence history are keyboard operable', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&groupBy=page');
	await waitForResults(page);
	await expect(page.getByRole('heading', {name: 'Active accessibility issues'})).toBeVisible();

	await page.locator('#cleara11y-filter-severity').selectOption('critical');
	await expect(page).toHaveURL(/severity=critical/);
	await expect(page.getByRole('heading', {name: 'Critical accessibility issues'})).toBeVisible();

	await page.locator('#cleara11y-filter-severity').selectOption('');
	await expect(page.locator('#cleara11y-issues-container')).not.toHaveAttribute('aria-busy', 'true');
	if (!await page.locator('[data-occurrence-row]').count()) {
		await expect(page.getByRole('heading', {name: /No (active )?issues/})).toBeVisible();
		return;
	}

	const details = page.getByRole('button', {name: 'View details'}).first();
	await details.focus();
	await page.keyboard.press('Enter');
	await expect(page).toHaveURL(/occurrenceId=\d+/);
	await expect(page.getByRole('heading', {name: 'Location'})).toBeVisible();

	await page.goBack();
	await expect(page).not.toHaveURL(/occurrenceId=/);
	await expect(page.getByRole('button', {name: 'View details'}).first()).toBeFocused();

	const group = page.locator('.cleara11y-group-toggle').first();
	await group.click();
	await expect(group).toHaveAttribute('aria-expanded', 'false');
	await group.click();
	await expect(group).toHaveAttribute('aria-expanded', 'true');
});

test('direct scan and occurrence URLs render snapshot and safe evidence', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active');
	await waitForResults(page);
	if (!await page.locator('[data-occurrence-row]').count()) {
		throw new Error('The self-contained occurrence fixture was not returned.');
	}
	await page.getByRole('button', {name: 'View details'}).first().click();
	const occurrenceUrl = page.url();
	await page.goto(occurrenceUrl);
	const scanLink = page.locator('[data-scan-explorer-url]');
	const scanUrl = await scanLink.getAttribute('href');
	await page.goto(scanUrl);
	await expect(page.locator('#cleara11y-snapshot-banner')).toBeVisible();
	await page.getByRole('button', {name: 'View details'}).first().click();
	const url = page.url();
	await page.goto(url);
	await expect(page.getByRole('heading', {name: 'Scan evidence'})).toBeVisible();
	await expect(page.locator('.cleara11y-detail pre').first()).toBeVisible();
	await expect(page.locator('.cleara11y-detail script')).toHaveCount(0);
});

test('explorer has no automated accessibility violations in list and detail states', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active');
	await waitForResults(page);
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/axe.min.js')});
	let result = await page.evaluate(() => axe.run('#cleara11y-issues-explorer'));
	expect(result.violations, JSON.stringify(result.violations, null, 2)).toEqual([]);

	if (!await page.locator('[data-occurrence-row]').count()) return;
	await page.getByRole('button', {name: 'View details'}).first().click();
	await expect(page.getByRole('heading', {name: 'Location'})).toBeVisible();
	result = await page.evaluate(() => axe.run('#cleara11y-issues-explorer'));
	expect(result.violations, JSON.stringify(result.violations, null, 2)).toEqual([]);
});

test('narrow view presents occurrence detail as the primary content', async ({page}) => {
	await page.setViewportSize({width: 600, height: 900});
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active');
	await waitForResults(page);
	if (!await page.locator('[data-occurrence-row]').count()) {
		throw new Error('The self-contained occurrence fixture was not returned.');
	}
	await page.getByRole('button', {name: 'View details'}).first().click();
	await expect(page.locator('#cleara11y-results-region')).toBeHidden();
	await expect(page.locator('#cleara11y-detail-panel')).toBeVisible();
});

test('evidence extractor covers violations and incomplete findings', async ({page}) => {
	await page.setContent(`
		<style>body, h1 { color: #fff; background: #fff; }</style>
		<h1>Invisible heading</h1>
		<img src="missing-alt.png">
	`);
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/axe.min.js')});
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/evidence-extractor.js')});

	const extracted = await page.evaluate(async () => {
		const results = await axe.run(document, {
			runOnly: {type: 'rule', values: ['image-alt', 'color-contrast']},
			resultTypes: ['violations', 'incomplete']
		});
		const evidence = await extractEvidenceFromAxeResults(results);
		return {
			resultTypes: [...new Set(evidence.map(record => record.result_type))],
			resolved: evidence.filter(record => record.node_evidence).length,
			total: evidence.length
		};
	});

	expect(extracted.resultTypes).toContain('violation');
	expect(extracted.resultTypes).toContain('incomplete');
	expect(extracted.resolved).toBe(extracted.total);
});

test('evidence extractor resolves the nearest template source marker', async ({page}) => {
	await page.setContent(`
		<!--a11y:s:000001-->
		<main>
			<!--a11y:s:000002--><button></button><!--a11y:e:000002-->
		</main>
		<!--a11y:e:000001-->
		<script type="application/json" id="cleara11y-attribution-map">{
			"sources": {
				"000001": {
					"source_type": "template",
					"source_ref": "themes/example/index.php",
					"owner_type": "theme",
					"owner_name": "Example",
					"source_key": "outer"
				},
				"000002": {
					"source_type": "content",
					"source_ref": "post:1",
					"owner_type": "content",
					"owner_name": "Editor-authored content",
					"source_key": "inner"
				}
			},
			"documentSourceId": "000001"
		}</script>
	`);
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/axe.min.js')});
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/evidence-extractor.js')});

	const source = await page.evaluate(async () => {
		const results = await axe.run(document, {
			runOnly: {type: 'rule', values: ['button-name']},
			resultTypes: ['violations']
		});
		const evidence = await extractEvidenceFromAxeResults(results);
		return evidence[0]?.source_descriptor;
	});

	expect(source).toMatchObject({
		source_type: 'content',
		source_ref: 'post:1',
		owner_type: 'content',
		source_key: 'inner'
	});
});

test('tokenized extraction fails loudly when a cached page has no attribution map', async ({page}) => {
	await page.setContent('<button></button>');
	await page.evaluate(() => history.replaceState({}, '', '?cleara11y_scan=fixture'));
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/axe.min.js')});
	await page.addScriptTag({path: path.resolve(__dirname, '../../assets/js/evidence-extractor.js')});

	const message = await page.evaluate(async () => {
		const results = await axe.run(document, {
			runOnly: {type: 'rule', values: ['button-name']},
			resultTypes: ['violations']
		});
		try {
			await extractEvidenceFromAxeResults(results);
			return null;
		} catch (error) {
			return error.message;
		}
	});

	expect(message).toContain('may have been served from a cache');
});
