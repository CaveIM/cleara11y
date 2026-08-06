const {test, expect} = require('@playwright/test');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const WP_PATH = process.env.CLEARA11Y_WP_PATH || '/var/www/html';
let fixturePostId = 0;
let fixtureScanId = 0;
const fixtureScanIds = [];

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
	fixtureScanIds.push(fixtureScanId);
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
	for (const scanId of fixtureScanIds) {
		wp([
			'eval',
			`
				$scan_id = ${scanId};
				$prefix = $GLOBALS['wpdb']->prefix . 'cleara11y_';
				$issue_ids = $GLOBALS['wpdb']->get_col(
					"SELECT id FROM {$prefix}issues WHERE scan_id = {$scan_id}"
				);
				if ($issue_ids) {
					$rule_ids = $GLOBALS['wpdb']->get_col(
						"SELECT DISTINCT exception_rule_id
						FROM {$prefix}issue_exception_matches
						WHERE violation_id IN (" . implode(',', array_map('absint', $issue_ids)) . ")"
					);
					$GLOBALS['wpdb']->query(
						"DELETE FROM {$prefix}issue_exception_matches
						WHERE violation_id IN (" . implode(',', array_map('absint', $issue_ids)) . ")"
					);
					foreach ($rule_ids as $rule_id) {
						$GLOBALS['wpdb']->delete(
							$prefix . 'exception_audit_log',
							['exception_rule_id' => $rule_id],
							['%s']
						);
						$GLOBALS['wpdb']->delete(
							$prefix . 'exception_rules',
							['id' => $rule_id],
							['%s']
						);
					}
				}
				$GLOBALS['wpdb']->delete($prefix . 'occurrence_states', ['latest_scan_id' => $scan_id], ['%d']);
				$GLOBALS['wpdb']->delete($prefix . 'issues', ['scan_id' => $scan_id], ['%d']);
				$GLOBALS['wpdb']->delete($prefix . 'scan_items', ['scan_id' => $scan_id], ['%d']);
				$GLOBALS['wpdb']->delete($prefix . 'scans', ['id' => $scan_id], ['%d']);
			`
		]);
	}
	wp([
		'eval',
		`
			$prefix = $GLOBALS['wpdb']->prefix . 'cleara11y_';
			$rule_ids = $GLOBALS['wpdb']->get_col(
				"SELECT id FROM {$prefix}exception_rules
				WHERE scope LIKE '%cleara11y-issues-explorer-fixture%'"
			);
			foreach ($rule_ids as $rule_id) {
				$GLOBALS['wpdb']->delete(
					$prefix . 'issue_exception_matches',
					['exception_rule_id' => $rule_id],
					['%s']
				);
				$GLOBALS['wpdb']->delete(
					$prefix . 'exception_audit_log',
					['exception_rule_id' => $rule_id],
					['%s']
				);
				$GLOBALS['wpdb']->delete(
					$prefix . 'exception_rules',
					['id' => $rule_id],
					['%s']
				);
			}
		`
	]);
	if (fixturePostId) {
		wp(['post', 'delete', String(fixturePostId), '--force']);
	}
}

async function rescanOccurrenceFixture(page) {
	const tokenData = JSON.parse(wp([
		'eval',
		`echo wp_json_encode(
			\\ClearA11y\\Services\\Scan_Token_Manager::generate_token(${fixturePostId})
		);`
	]));
	fixtureScanIds.push(Number(tokenData.scan_id));
	const scanPage = await page.context().newPage();
	try {
		const resultRequest = scanPage.waitForResponse(
			response => response.url().includes('/cleara11y/v1/scan/results')
				&& response.request().method() === 'POST',
			{timeout: 60000}
		);
		await scanPage.goto(tokenData.scan_url, {
			waitUntil: 'domcontentloaded',
			timeout: 60000
		});
		const response = await resultRequest;
		expect(response.status(), 'The exception workflow rescan did not persist').toBe(200);
	} finally {
		await scanPage.close();
	}
}

async function login(page) {
	for (let attempt = 0; attempt < 2; attempt++) {
		await page.goto('/wp-login.php');
		await page.locator('#loginform').evaluate(form => {
			form.action = window.location.origin + '/wp-login.php';
		});
		await page.locator('input[name="redirect_to"]').evaluate(
			(input, value) => { input.value = value; },
			new URL('/wp-admin/', page.url()).href
		);
		await page.locator('#user_login').fill(process.env.CLEARA11Y_ADMIN_USER || 'admin');
		await page.locator('#user_pass').fill(process.env.CLEARA11Y_ADMIN_PASSWORD || 'password');
		await Promise.all([
			page.waitForLoadState('domcontentloaded'),
			page.getByRole('button', {name: 'Log In'}).click()
		]);
		if (/\/wp-admin\//.test(page.url())) {
			return;
		}
	}
	throw new Error('Could not log in to the disposable WordPress test site.');
}

async function waitForResults(page) {
	await expect(page.locator('#cleara11y-issues-container'))
		.not.toHaveAttribute('aria-busy', 'true');
}

async function createRulePageException(page, {ruleId, durationType, note, expiresAt = ''}) {
	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
	await page.getByRole('link', {name: 'Create Exception'}).click();
	const dialog = page.getByRole('dialog', {name: 'Create Exception'});

	await dialog.locator('#cleara11y-rule-search').fill(ruleId);
	await dialog.locator('#cleara11y-rule-search').focus();
	await dialog.locator('#cleara11y-rule-options button').filter({hasText: ruleId}).first().click();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('radio', {name: /Single Page/i}).check();
	await dialog.locator('#cleara11y-page-search').fill('ClearA11y Issues Explorer Fixture');
	await dialog.locator('#cleara11y-page-search').focus();
	await dialog.locator('#cleara11y-page-options button')
		.filter({hasText: 'ClearA11y Issues Explorer Fixture'})
		.first()
		.click();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.locator(`input[name="duration_type"][value="${durationType}"]`).check();
	if (expiresAt) {
		await dialog.locator('#cleara11y-expires-at').fill(expiresAt);
	}
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.locator('#cleara11y-reason-category').selectOption('accepted_risk');
	await dialog.locator('#cleara11y-note').fill(note);
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Create Exception'}).click();
	await expect(dialog).toBeHidden();

	return page.locator('#cleara11y-exceptions-table-body tr').filter({hasText: note}).first();
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

test('reviewed exception wizard preserves occurrence context and updates the explorer', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=button-name&groupBy=none');
	await waitForResults(page);
	await page.getByRole('button', {name: 'View details'}).first().click();
	await page.getByRole('button', {name: 'Snooze until next scan'}).click();
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=exception&ruleId=button-name&groupBy=none');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();

	await rescanOccurrenceFixture(page);
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=button-name&groupBy=none');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
	await page.getByRole('button', {name: 'View details'}).first().click();
	await page.getByRole('button', {name: 'Create exception…'}).click();

	const dialog = page.getByRole('dialog', {name: 'Create Exception'});
	await expect(dialog).toBeVisible();
	await expect(dialog.getByRole('radio', {name: /Rule on Element/i})).toBeChecked();
	await expect(dialog.locator('#cleara11y-selected-rules')).toContainText('button-name');
	await expect(dialog.locator('#cleara11y-css-selector')).not.toHaveValue('');

	await dialog.getByRole('button', {name: 'Next'}).click();
	await expect(dialog.getByRole('radio', {name: /Single Page/i})).toBeChecked();
	await expect(dialog.locator('#cleara11y-selected-page')).toContainText('ClearA11y Issues Explorer Fixture');
	await dialog.getByRole('radio', {name: /Content Types/i}).check();
	await expect(dialog.locator('input[name="post_types"][value="page"]')).toBeChecked();
	await dialog.getByRole('radio', {name: /Single Page/i}).check();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await expect(dialog.getByRole('radio', {name: /Permanent/i})).toBeChecked();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.locator('#cleara11y-reason-category').selectOption('accepted_risk');
	await dialog.locator('#cleara11y-note').fill('Reviewed by the exception workflow integration test.');
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Create Exception'}).click();
	await expect(dialog).toBeHidden();

	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
	const exceptionRow = page.locator('#cleara11y-exceptions-table-body tr').filter({hasText: 'button-name'}).first();
	await expect(exceptionRow).toBeVisible();
	await exceptionRow.getByRole('button', {name: 'View'}).click();
	const detailsDialog = page.getByRole('dialog', {name: 'Exception Rule Details'});
	await expect(detailsDialog).toBeVisible();
	await detailsDialog.getByRole('button', {name: 'Edit Rule'}).click();
	const editDialog = page.getByRole('dialog', {name: 'Edit reviewed exception'});
	await expect(editDialog).toBeVisible();
	await editDialog.getByRole('button', {name: 'Next'}).click();
	await editDialog.getByRole('button', {name: 'Next'}).click();
	await editDialog.getByRole('button', {name: 'Next'}).click();
	await expect(editDialog.locator('#cleara11y-note')).toHaveValue('Reviewed by the exception workflow integration test.');
	await editDialog.locator('#cleara11y-note').fill('Reviewed and edited by the exception workflow integration test.');
	await editDialog.getByRole('button', {name: 'Next'}).click();
	await editDialog.getByRole('button', {name: 'Save Exception'}).click();
	await expect(editDialog).toBeHidden();

	await rescanOccurrenceFixture(page);
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=exception&ruleId=button-name&groupBy=none');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
	await page.getByRole('button', {name: 'View details'}).first().click();
	await expect(page.getByText('Current exception').first()).toBeVisible();
});

test('standalone wizard searches rules and pages and persists the exception', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
	await page.getByRole('link', {name: 'Create Exception'}).click();

	const dialog = page.getByRole('dialog', {name: 'Create Exception'});
	await expect(dialog.getByRole('radio', {name: /Rule Only/i})).toBeChecked();
	await expect(dialog.getByRole('radio', {name: /Rule on Element/i})).toBeDisabled();

	await dialog.locator('#cleara11y-rule-search').fill('image-alt');
	await dialog.locator('#cleara11y-rule-search').focus();
	await dialog.locator('#cleara11y-rule-options button').filter({hasText: 'image-alt'}).first().click();
	await expect(dialog.locator('#cleara11y-selected-rules')).toContainText('image-alt');
	await page.waitForTimeout(300);
	await expect(dialog.locator('#cleara11y-rule-options')).toBeHidden();
	await dialog.getByRole('button', {name: 'Next'}).click();

	await dialog.getByRole('radio', {name: /Single Page/i}).check();
	await dialog.locator('#cleara11y-page-search').fill('ClearA11y Issues Explorer Fixture');
	await dialog.locator('#cleara11y-page-search').focus();
	await dialog.locator('#cleara11y-page-options button').filter({hasText: 'ClearA11y Issues Explorer Fixture'}).first().click();
	await expect(dialog.locator('#cleara11y-selected-page')).toContainText('ClearA11y Issues Explorer Fixture');
	await page.waitForTimeout(300);
	await expect(dialog.locator('#cleara11y-page-options')).toBeHidden();
	await dialog.getByRole('button', {name: 'Next'}).click();

	await dialog.getByRole('radio', {name: /Permanent/i}).check();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.locator('#cleara11y-reason-category').selectOption('accepted_risk');
	await dialog.locator('#cleara11y-note').fill('Standalone searchable wizard persistence test.');
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Create Exception'}).click();
	await expect(dialog).toBeHidden();

	const exceptionRow = page.locator('#cleara11y-exceptions-table-body tr')
		.filter({hasText: 'image-alt'})
		.filter({hasText: 'Standalone searchable wizard persistence test.'})
		.first();
	await expect(exceptionRow).toBeVisible();
});

test('scan list can cancel unfinished work and keeps the scan record', async ({page}) => {
	const scanId = Number(wp([
		'eval',
		`
			$scan = new \\ClearA11y\\Models\\Scan();
			$scan->scan_name = 'Cancellation interface fixture';
			$scan->status = 'in_progress';
			$scan->total_items = 1;
			$scan->started_at = current_time('mysql', true);
			$scan->created_at = current_time('mysql', true);
			$scan_id = \\ClearA11y\\Database\\Scan_Repository::insert($scan);
			$item = new \\ClearA11y\\Models\\Scan_Item();
			$item->scan_id = $scan_id;
			$item->post_id = 1;
			$item->post_url = home_url('/cancellation-interface-fixture/');
			$item->post_title = 'Cancellation interface fixture';
			$item->status = 'pending';
			$item->scan_method = 'client';
			$item->created_at = current_time('mysql', true);
			\\ClearA11y\\Database\\Scan_Item_Repository::insert($item);
			echo $scan_id;
		`
	]));

	try {
		await page.goto('/wp-admin/admin.php?page=cleara11y-scans');
		const row = page.locator('tbody tr').filter({hasText: 'Cancellation interface fixture'}).first();
		await expect(row).toBeVisible();
		page.once('dialog', dialog => dialog.accept());
		await row.getByRole('button', {name: 'Cancel scan'}).click();
		await expect(page.getByText(`Scan #${scanId} was cancelled.`)).toBeVisible();
		await expect(row).toContainText('Cancelled');

		const state = JSON.parse(wp([
			'eval',
			`$scan = \\ClearA11y\\Database\\Scan_Repository::get_by_id(${scanId});
			$item = \\ClearA11y\\Database\\Scan_Item_Repository::get_by_scan_id(${scanId})[0] ?? null;
			echo wp_json_encode(['scan' => $scan->status, 'item' => $item?->status]);`
		]));
		expect(state).toEqual({scan: 'cancelled', item: 'cancelled'});
	} finally {
		wp(['eval', `\\ClearA11y\\Database\\Job_Repository::delete_by_scan_id(${scanId}); \\ClearA11y\\Database\\Scan_Repository::delete(${scanId});`]);
	}
});

test('page report finding opens a prefilled wizard and saves', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-page-report&post_id=' + fixturePostId);
	const issueCard = page.locator('.cleara11y-issue-card').filter({hasText: 'color-contrast'}).first();
	await issueCard.getByRole('button', {name: 'Create exception…'}).click();

	const dialog = page.getByRole('dialog', {name: 'Create Exception'});
	await expect(dialog.locator('#cleara11y-selected-rules')).toContainText('color-contrast');
	await dialog.getByRole('button', {name: 'Next'}).click();
	await expect(dialog.locator('#cleara11y-selected-page')).toContainText('ClearA11y Issues Explorer Fixture');
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.locator('#cleara11y-reason-category').selectOption('accepted_risk');
	await dialog.locator('#cleara11y-note').fill('Created directly from a scan finding.');
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Create Exception'}).click();
	await expect(dialog).toBeHidden();

	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
	await expect(
		page.locator('#cleara11y-exceptions-table-body tr')
			.filter({hasText: 'color-contrast'})
			.filter({hasText: 'Created directly from a scan finding.'})
			.first()
	).toBeVisible();
});

test('exception lifecycle controls disable, enable, and revoke without losing audit state', async ({page}) => {
	const note = 'Lifecycle controls E2E test.';
	let exceptionRow = await createRulePageException(page, {
		ruleId: 'button-name',
		durationType: 'permanent',
		note
	});
	await expect(exceptionRow).toBeVisible();

	page.once('dialog', dialog => dialog.accept());
	await exceptionRow.getByRole('button', {name: 'Disable'}).click();
	await expect(exceptionRow).toBeHidden();

	await page.locator('.nav-tab[data-tab="disabled"]').click();
	exceptionRow = page.locator('#cleara11y-exceptions-table-body tr')
		.filter({hasText: note})
		.first();
	await expect(exceptionRow).toBeVisible();
	await exceptionRow.getByRole('button', {name: 'Enable'}).click();
	await expect(exceptionRow).toBeHidden();

	await page.locator('.nav-tab[data-tab="active"]').click();
	exceptionRow = page.locator('#cleara11y-exceptions-table-body tr')
		.filter({hasText: note})
		.first();
	await expect(exceptionRow).toBeVisible();
	page.once('dialog', dialog => dialog.accept());
	await exceptionRow.getByRole('button', {name: 'Revoke'}).click();
	await expect(exceptionRow).toBeHidden();

	await page.locator('.nav-tab[data-tab="revoked"]').click();
	await expect(
		page.locator('#cleara11y-exceptions-table-body tr')
			.filter({hasText: note})
			.first()
	).toBeVisible();

	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=button-name&groupBy=none');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
});

test('legacy finding falls back to an explicit rule-on-page exception', async ({page}) => {
	wp([
		'eval',
		`$GLOBALS['wpdb']->update(
			$GLOBALS['wpdb']->prefix . 'cleara11y_issues',
			[
				'element_identity_v2' => null,
				'violation_identity_v2' => null,
				'identity_signature_version' => null,
			],
			['post_id' => ${fixturePostId}, 'rule_id' => 'button-name'],
			['%s', '%s', '%d'],
			['%d', '%s']
		);`
	]);

	await page.goto('/wp-admin/admin.php?page=cleara11y-page-report&post_id=' + fixturePostId);
	const issueCard = page.locator('.cleara11y-issue-card').filter({hasText: 'button-name'}).first();
	await issueCard.getByRole('button', {name: 'Create exception…'}).click();

	const dialog = page.getByRole('dialog', {name: 'Create Exception'});
	await expect(dialog.getByRole('radio', {name: /Rule Only/i})).toBeChecked();
	await expect(dialog.getByRole('radio', {name: /Rule on Element/i})).toBeDisabled();
	await expect(dialog.locator('#cleara11y-occurrence-fallback-notice')).toBeVisible();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.locator('#cleara11y-reason-category').selectOption('accepted_risk');
	await dialog.locator('#cleara11y-note').fill('Legacy finding fallback E2E test.');
	await dialog.getByRole('button', {name: 'Next'}).click();
	await dialog.getByRole('button', {name: 'Create Exception'}).click();
	await expect(dialog).toBeHidden();

	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
	const exceptionRow = page.locator('#cleara11y-exceptions-table-body tr')
		.filter({hasText: 'Legacy finding fallback E2E test.'})
		.first();
	await expect(exceptionRow).toContainText('rule');
	await expect(exceptionRow).toContainText('cleara11y-issues-explorer-fixture');
});

test('duration choices persist and date-based expiration is enforced', async ({page}) => {
	const future = new Date(Date.now() + 24 * 60 * 60 * 1000)
		.toISOString()
		.slice(0, 16);
	let exceptionRow = await createRulePageException(page, {
		ruleId: 'color-contrast',
		durationType: 'until_date',
		note: 'Date expiration E2E test.',
		expiresAt: future
	});
	await expect(exceptionRow).toBeVisible();
	await expect(exceptionRow).toContainText('Until:');
	const exceptionId = await exceptionRow.getByRole('button', {name: 'Revoke'}).getAttribute('data-id');
	expect(exceptionId).toBeTruthy();

	wp([
		'eval',
		`$GLOBALS['wpdb']->update(
			$GLOBALS['wpdb']->prefix . 'cleara11y_exception_rules',
			['expires_at' => '2000-01-01 00:00:00'],
			['id' => '${exceptionId}'],
			['%s'],
			['%s']
		);`
	]);

	await page.reload();
	await page.locator('.nav-tab[data-tab="expired"]').click();
	exceptionRow = page.locator('#cleara11y-exceptions-table-body tr')
		.filter({hasText: 'Date expiration E2E test.'})
		.first();
	await expect(exceptionRow).toBeVisible();

	exceptionRow = await createRulePageException(page, {
		ruleId: 'image-alt',
		durationType: 'until_content_changes',
		note: 'Content change duration E2E test.'
	});
	await expect(exceptionRow).toBeVisible();
	await expect(exceptionRow).toContainText('Until content changes');
});

test.fixme(
	'content-change duration expires after the selected WordPress content changes',
	async () => {
		// The UI and persistence support this duration, but the scanner does not
		// yet store a content revision or expire the exception when it changes.
	}
);

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
