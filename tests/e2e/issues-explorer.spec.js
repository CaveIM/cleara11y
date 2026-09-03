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
	await page.goto('/wp-admin/admin.php?page=cleara11y-page-report&post_id=' + fixturePostId);
	const issueCard = page.locator('.cleara11y-issue-card').filter({hasText: ruleId}).first();
	await issueCard.getByRole('button', {name: 'Create exception…'}).click();
	const dialog = page.getByRole('dialog', {name: 'Create Exception'});

	await expect(dialog.locator('#cleara11y-finding-rule-id')).toHaveText(ruleId);
	await dialog.locator('input[name="target_type"][value="rule"]').check();
	await dialog.getByRole('button', {name: 'Next'}).click();
	await expect(dialog.locator('#cleara11y-selected-page')).toContainText('ClearA11y Issues Explorer Fixture');
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

	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
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
	const resultsRegion = page.locator('#cleara11y-results-region');
	const resultsBefore = await resultsRegion.boundingBox();
	await details.focus();
	await page.keyboard.press('Enter');
	await expect(page).toHaveURL(/occurrenceId=\d+/);
	await expect(page.getByRole('heading', {name: 'Location'})).toBeVisible();
	await expect(page.locator('#cleara11y-detail-panel')).toHaveClass(/is-open/);
	await expect(details).toHaveAttribute('aria-expanded', 'true');
	await expect(page.locator('#cleara11y-issues-container')).not.toHaveAttribute('aria-busy', 'true');
	const resultsAfter = await resultsRegion.boundingBox();
	expect(resultsAfter.width).toBe(resultsBefore.width);
	expect(resultsAfter.x).toBe(resultsBefore.x);

	await page.goBack();
	await expect(page).not.toHaveURL(/occurrenceId=/);
	await expect(page.getByRole('button', {name: 'View details'}).first()).toBeFocused();
	await expect(page.locator('#cleara11y-detail-panel')).toBeHidden();

	await expect(page.locator('.cleara11y-group-toggle')).toHaveCount(0);
	await expect(page.locator('.cleara11y-secondary-groups').first()).toBeVisible();
});

test('group-aware rows remove repeated context and expose contextual actions', async ({page}) => {
	await page.goto(`/wp-admin/admin.php?page=cleara11y-issues&pageId=${fixturePostId}&groupBy=page`);
	await waitForResults(page);
	const pageGroup = page.locator('.cleara11y-result-group').first();
	await expect(pageGroup.locator('.cleara11y-group-header h3')).toContainText('ClearA11y Issues Explorer Fixture');
	await expect(pageGroup.locator('.cleara11y-group-header__path')).toContainText('/cleara11y-issues-explorer-fixture/');
	await expect(pageGroup.getByRole('link', {name: 'View', exact: true})).toBeVisible();
	await expect(pageGroup.getByRole('link', {name: 'Edit'})).toBeVisible();
	await expect(pageGroup.locator('.count')).toHaveCount(0);

	const pageSecondaryGroup = pageGroup.locator('.cleara11y-secondary-group').first();
	await expect(pageSecondaryGroup.locator('.cleara11y-secondary-header h4')).toBeVisible();
	await expect(pageSecondaryGroup.locator('.cleara11y-secondary-header code')).toHaveCount(0);
	await expect(pageSecondaryGroup.getByRole('link', {name: /View issue reference for/})).toBeVisible();
	const pageRow = pageSecondaryGroup.locator('[data-occurrence-row]').first();
	await expect(pageRow).toHaveAttribute('role', 'button');
	await expect(pageRow).toHaveAttribute('aria-label', /View details for/);
	await expect(pageRow.locator('.cleara11y-badge')).toHaveCount(0);
	await expect(pageRow.locator('.screen-reader-text')).toContainText(/severity/i);
	await expect(pageRow.locator('.cleara11y-occurrence-summary__label')).toBeVisible();
	await expect(pageRow.locator('.cleara11y-occurrence-summary__meta')).toBeVisible();
	expect(await pageRow.locator('.cleara11y-occurrence-summary').evaluate(
		element => element.firstElementChild?.className
	)).toContain('cleara11y-occurrence-summary__meta');
	await expect(pageRow.locator('.cleara11y-occurrence-evidence')).toHaveCount(0);
	await expect(pageRow.locator('.cleara11y-result-row__meta')).toHaveCount(0);
	await expect(pageRow).not.toContainText('ClearA11y Issues Explorer Fixture');
	const pageDividerGroup = pageGroup.locator('.cleara11y-secondary-group:not(:last-child)').first();
	if (await pageDividerGroup.count()) {
		expect(await pageDividerGroup.evaluate(node => getComputedStyle(node, '::after').left)).toBe('4px');
	}
	await page.locator('#cleara11y-include-exceptions').check();
	await waitForResults(page);
	await expect(page).toHaveURL(/includeExceptions=1/);
	await page.locator('#cleara11y-include-exceptions').uncheck();
	await waitForResults(page);

	await page.locator('#cleara11y-filter-status').selectOption('review');
	await waitForResults(page);
	await expect(page).toHaveURL(/findingType=review/);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
	await expect(page.locator('[data-occurrence-row] .is-unconfirmed')).toHaveCount(
		await page.locator('[data-occurrence-row]').count()
	);

	await page.locator('#cleara11y-filter-status').selectOption('violation');
	await waitForResults(page);
	await expect(page).toHaveURL(/findingType=violation/);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
	await expect(page.locator('[data-occurrence-row] .is-unconfirmed')).toHaveCount(0);

	await page.locator('#cleara11y-filter-status').selectOption('');
	await page.locator('#cleara11y-group-by').selectOption('rule');
	await waitForResults(page);
	const ruleGroup = page.locator('.cleara11y-result-group').filter({
		has: page.locator('.cleara11y-group-header h3', {hasText: 'Buttons must have discernible text'})
	}).first();
	await expect(ruleGroup.locator('.cleara11y-group-header__rule-id')).toHaveCount(0);
	const ruleSecondaryGroup = ruleGroup.locator('.cleara11y-secondary-group').filter({hasText: 'ClearA11y Issues Explorer Fixture'}).first();
	await expect(ruleSecondaryGroup.locator('.cleara11y-secondary-header h4')).toHaveText('ClearA11y Issues Explorer Fixture');
	const secondaryPath = ruleSecondaryGroup.locator('.cleara11y-secondary-header .cleara11y-truncated-value');
	await expect(secondaryPath).toContainText('/cleara11y-issues-explorer-fixture/');
	await expect(secondaryPath).not.toHaveClass(/is-truncated/);
	await expect(secondaryPath.locator('.cleara11y-truncated-value__text')).toHaveCSS('mask-image', 'none');
	await expect(ruleSecondaryGroup.getByRole('link', {name: 'View', exact: true})).toBeVisible();
	await expect(ruleSecondaryGroup.getByRole('link', {name: 'Edit'})).toBeVisible();
	const ruleDividerLeft = await ruleSecondaryGroup.evaluate(node => {
		const temporarySibling = node.cloneNode(false);
		node.parentNode.append(temporarySibling);
		const left = getComputedStyle(node, '::after').left;
		temporarySibling.remove();
		return left;
	});
	expect(ruleDividerLeft).toBe('0px');
	const ruleRow = ruleSecondaryGroup.locator('[data-occurrence-row]').first();
	await expect(ruleRow).not.toContainText('ClearA11y Issues Explorer Fixture');
	await expect(ruleRow.locator('.cleara11y-element-visual .dashicons')).toBeVisible();
	await expect(ruleRow.locator('.cleara11y-occurrence-summary__label')).toContainText(/Unlabelled button|Affected/);
	await expect(ruleRow).not.toContainText('Element must have text that is visible to screen readers');
	const confirmedRow = page.locator('[data-occurrence-row]:not(:has(.is-unconfirmed)):not(:has(.is-exception))').first();
	const unconfirmedRow = page.locator('[data-occurrence-row]:has(.is-unconfirmed)').first();
	if (await confirmedRow.count() && await unconfirmedRow.count()) {
		const confirmedBox = await confirmedRow.boundingBox();
		const unconfirmedBox = await unconfirmedRow.boundingBox();
		expect(Math.abs(confirmedBox.height - unconfirmedBox.height)).toBeLessThan(1);
	}

	await ruleRow.click();
	await expect(page.locator('#cleara11y-detail-panel')).toHaveClass(/is-open/);
	await page.getByRole('button', {name: /Close details/}).click();
	await expect(page.locator('#cleara11y-detail-panel')).toBeHidden();
	await expect(ruleRow).toBeFocused();

	await ruleGroup.getByRole('link', {name: 'View issue reference for button-name'}).click();
	await expect(page).toHaveURL(/page=cleara11y-issue-reference.*ruleId=button-name/);
	await expect(page.locator('#cleara11y-issue-search')).toHaveValue('button-name');
	await expect(page.locator('.cleara11y-reference-item')).toHaveCount(1);
	await expect(page.locator('#cleara11y-detail-modal')).toBeVisible();
	await expect(page.locator('#cleara11y-modal-title')).toBeFocused();
});

test('Issues per page Screen Option controls the explorer request', async ({page}) => {
	const previous = JSON.parse(wp([
		'eval',
		`$user = get_user_by('login', 'admin');
		echo wp_json_encode([
			'exists' => metadata_exists('user', $user->ID, 'cleara11y_issues_per_page'),
			'value' => get_user_meta($user->ID, 'cleara11y_issues_per_page', true),
		]);`
	]));

	try {
		wp(['user', 'meta', 'update', 'admin', 'cleara11y_issues_per_page', '7']);
		const responsePromise = page.waitForResponse(response =>
			response.url().includes('/cleara11y/v1/issues/occurrences')
			&& response.request().method() === 'GET'
		);
		await page.goto('/wp-admin/admin.php?page=cleara11y-issues');
		const response = await responsePromise;
		expect(new URL(response.url()).searchParams.get('per_page')).toBe('7');
		await page.locator('#show-settings-link').click();
		await expect(page.locator('#cleara11y_issues_per_page')).toHaveValue('7');
	} finally {
		if (previous.exists) {
			wp(['user', 'meta', 'update', 'admin', 'cleara11y_issues_per_page', String(previous.value)]);
		} else {
			wp(['user', 'meta', 'delete', 'admin', 'cleara11y_issues_per_page']);
		}
	}
});

test('issue display Screen Options persist and update occurrence evidence', async ({page}) => {
	const previous = JSON.parse(wp([
		'eval',
		`$user = get_user_by('login', 'admin');
		echo wp_json_encode([
			'exists' => metadata_exists('user', $user->ID, 'cleara11y_issue_view_options'),
			'value' => get_user_meta($user->ID, 'cleara11y_issue_view_options', true),
		]);`
	]));

	try {
		wp([
			'eval',
			`$user = get_user_by('login', 'admin');
			update_user_meta($user->ID, 'cleara11y_issue_view_options', [
				'show_selector' => false,
				'show_html' => false,
				'show_thumbnails' => false,
			]);`
		]);
		await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active');
		await waitForResults(page);
		await page.locator('#show-settings-link').click();

		const selectorOption = page.locator('[data-cleara11y-view-option="show_selector"]');
		const htmlOption = page.locator('[data-cleara11y-view-option="show_html"]');
		const thumbnailOption = page.locator('[data-cleara11y-view-option="show_thumbnails"]');
		await expect(selectorOption).not.toBeChecked();
		await expect(htmlOption).not.toBeChecked();
		await expect(thumbnailOption).not.toBeChecked();

		const saveResponse = page.waitForResponse(response =>
			response.url().includes('/wp-admin/admin-ajax.php')
			&& response.request().postData()?.includes('action=cleara11y_save_issue_view_option')
		);
		await selectorOption.check();
		expect((await saveResponse).ok()).toBe(true);
		await expect(page.locator('[data-occurrence-row] .cleara11y-occurrence-evidence:not(.is-html)').first()).toBeVisible();

		await page.reload();
		await waitForResults(page);
		await page.locator('#show-settings-link').click();
		await expect(page.locator('[data-cleara11y-view-option="show_selector"]')).toBeChecked();
		await expect(page.locator('[data-occurrence-row] .cleara11y-occurrence-evidence:not(.is-html)').first()).toBeVisible();
	} finally {
		if (previous.exists) {
			const encoded = Buffer.from(JSON.stringify(previous.value)).toString('base64');
			wp([
				'eval',
				`$user = get_user_by('login', 'admin');
				update_user_meta(
					$user->ID,
					'cleara11y_issue_view_options',
					json_decode(base64_decode('${encoded}'), true)
				);`
			]);
		} else {
			wp(['user', 'meta', 'delete', 'admin', 'cleara11y_issue_view_options']);
		}
	}
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
	await expect(page.locator('#cleara11y-include-exceptions-control')).toBeHidden();
	await page.getByRole('button', {name: 'View details'}).first().click();
	const url = page.url();
	await page.goto(url);
	await page.getByRole('tab', {name: 'Evidence'}).click();
	await expect(page.getByRole('heading', {name: 'Scan evidence'})).toBeVisible();
	await expect(page.locator('#cleara11y-detail-panel-evidence pre').first()).toBeVisible();
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
	const drawer = page.locator('#cleara11y-detail-panel');
	await expect(drawer.getByRole('region', {name: 'Issue actions'})).toBeVisible();
	await expect(drawer.getByRole('tab', {name: 'Exception'})).toHaveCount(0);
	await expect(drawer.locator('#cleara11y-detail-panel-overview pre code').first()).toBeVisible();
	await expect(drawer.locator('.cleara11y-review-note')).toHaveCSS('margin-top', '16px');
	const viewMenu = drawer.getByRole('button', {name: 'More viewing options'});
	await viewMenu.click();
	await expect(drawer.getByRole('link', {name: 'View page'})).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(viewMenu).toHaveAttribute('aria-expanded', 'false');
	const overviewTab = page.getByRole('tab', {name: 'Overview'});
	const evidenceTab = page.getByRole('tab', {name: 'Evidence'});
	await expect(overviewTab).toHaveAttribute('aria-selected', 'true');
	await overviewTab.focus();
	await page.keyboard.press('ArrowRight');
	await expect(evidenceTab).toBeFocused();
	await expect(evidenceTab).toHaveAttribute('aria-selected', 'true');
	await expect(page.getByRole('heading', {name: 'Scan evidence'})).toBeVisible();
	result = await page.evaluate(() => axe.run('#cleara11y-issues-explorer'));
	expect(result.violations, JSON.stringify(result.violations, null, 2)).toEqual([]);
});

test('issue inspector becomes a full-width non-modal drawer on narrow screens', async ({page}) => {
	await page.setViewportSize({width: 500, height: 700});
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active');
	await waitForResults(page);
	const details = page.getByRole('button', {name: 'View details'}).first();
	await details.click();
	const drawer = page.locator('#cleara11y-detail-panel');
	await expect(drawer).toHaveClass(/is-open/);
	const drawerBounds = await drawer.boundingBox();
	expect(drawerBounds.width).toBeCloseTo(500, 2);
	const closeButton = drawer.getByRole('button', {name: /Close details/});
	const closeBounds = await closeButton.boundingBox();
	expect(closeBounds.x).toBeGreaterThan(drawerBounds.x + drawerBounds.width / 2);
	await expect(drawer.getByRole('button', {name: 'Copy link'})).toHaveCount(0);
	await page.locator('body').focus();
	await page.keyboard.press('Escape');
	await expect(drawer).toBeHidden();
	await expect(details).toBeFocused();
});

test('reviewed exception wizard preserves occurrence context and updates the explorer', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=button-name&groupBy=rule');
	await waitForResults(page);
	await page.getByRole('button', {name: 'View details'}).first().click();
	const exceptionMenu = page.getByRole('button', {name: 'More exception options'});
	await exceptionMenu.click();
	await expect(exceptionMenu).toHaveAttribute('aria-expanded', 'true');
	await page.getByRole('button', {name: 'Snooze until next scan'}).click();
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=exception&ruleId=button-name&groupBy=rule');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();

	await rescanOccurrenceFixture(page);
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=button-name&groupBy=rule');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
	await page.getByRole('button', {name: 'View details'}).first().click();
	await page.getByRole('button', {name: 'Create exception…'}).click();

	const dialog = page.getByRole('dialog', {name: 'Create Exception'});
	await expect(dialog).toBeVisible();
	await expect(dialog.locator('input[name="target_type"][value="rule_on_element"]')).toBeChecked();
	await expect(dialog.locator('#cleara11y-finding-rule-id')).toHaveText('button-name');
	await expect(dialog.locator('#cleara11y-finding-selector')).not.toHaveText('Unavailable');

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
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=exception&ruleId=button-name&groupBy=rule');
	await waitForResults(page);
	await expect(page.locator('[data-occurrence-row]').first()).toBeVisible();
	await page.getByRole('button', {name: 'View details'}).first().click();
	await expect(page.getByText('Current exception').first()).toBeVisible();
});

test('exceptions management directs new exception creation to detected issues', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-exceptions');
	await expect(page.getByRole('link', {name: 'Create Exception'})).toHaveCount(0);
	const reviewIssues = page.getByRole('link', {name: 'Review Issues'});
	await expect(reviewIssues).toBeVisible();
	await expect(page.getByText(/Create exceptions from a detected finding/)).toBeVisible();
	await reviewIssues.click();
	await expect(page).toHaveURL(/page=cleara11y-issues/);
});

test('reviewed exception API requires a source occurrence', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues');
	await waitForResults(page);
	const result = await page.evaluate(async () => {
		const response = await fetch(cleara11yData.apiUrl + 'exceptions/preview', {
			method: 'POST',
			headers: {'X-WP-Nonce': cleara11yData.nonce, 'Content-Type': 'application/json'},
			body: JSON.stringify({
				target_type: 'rule',
				rule_ids: ['image-alt'],
				scope: {scope_type: 'site'},
				duration: {duration_type: 'permanent'},
				reason_category: 'accepted_risk',
				note: 'Missing occurrence validation test.'
			})
		});
		return {status: response.status, body: await response.json()};
	});
	expect(result.status).toBe(400);
	expect(result.body.code).toBe('exception_occurrence_required');
});

test('reviewed exception API derives rule and page from the source occurrence', async ({page}) => {
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=image-alt&groupBy=rule');
	await waitForResults(page);
	const violationId = Number(await page.locator('[data-occurrence-row]').first().getAttribute('data-occurrence-row'));
	const result = await page.evaluate(async id => {
		const response = await fetch(cleara11yData.apiUrl + 'exceptions', {
			method: 'POST',
			headers: {'X-WP-Nonce': cleara11yData.nonce, 'Content-Type': 'application/json'},
			body: JSON.stringify({
				violation_id: id,
				target_type: 'rule',
				rule_ids: ['client-supplied-rule'],
				scope: {scope_type: 'page', url: 'https://invalid.example/client-page/'},
				duration: {duration_type: 'permanent'},
				reason_category: 'accepted_risk',
				note: 'Server-owned occurrence context test.'
			})
		});
		return {status: response.status, body: await response.json()};
	}, violationId);
	expect(result.status).toBe(200);
	expect(result.body.rule.rule_ids).toEqual(['image-alt']);
	expect(result.body.rule.scope.url).toContain('cleara11y-issues-explorer-fixture');

	await page.evaluate(async exceptionId => {
		await fetch(cleara11yData.apiUrl + 'exceptions/' + encodeURIComponent(exceptionId), {
			method: 'DELETE',
			headers: {'X-WP-Nonce': cleara11yData.nonce}
		});
	}, result.body.id);
});

test('exception wizard navigation, focus containment, and compact layout remain usable', async ({page}) => {
	await page.setViewportSize({width: 1280, height: 900});
	await page.goto('/wp-admin/admin.php?page=cleara11y-page-report&post_id=' + fixturePostId);
	const createButton = page.locator('.cleara11y-issue-card').filter({hasText: 'image-alt'}).first()
		.getByRole('button', {name: 'Create exception…'});
	await createButton.click();

	const dialog = page.getByRole('dialog', {name: 'Create Exception'});
	const closeButton = dialog.locator('.cleara11y-modal-close--icon');
	const cancelButton = dialog.locator('#cleara11y-wizard-cancel');
	const backButton = dialog.getByRole('button', {name: 'Back'});

	await expect(dialog.locator('.cleara11y-progress-step[aria-current="step"]')).toContainText('Target');
	await expect(dialog.locator('.cleara11y-wizard-step-count')).toContainText('Step 1 of 5');
	await expect(backButton).toBeHidden();
	expect(await cancelButton.evaluate(button => button.scrollWidth <= button.clientWidth)).toBe(true);

	await closeButton.focus();
	await page.keyboard.press('Shift+Tab');
	await expect(dialog.getByRole('button', {name: 'Next'})).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(closeButton).toBeFocused();

	await expect(dialog).toHaveCSS('transition-property', 'height');
	await expect(dialog.locator('#cleara11y-finding-rule-id')).toHaveText('image-alt');
	await expect(dialog.locator('#cleara11y-rule-search')).toHaveCount(0);

	await page.setViewportSize({width: 500, height: 500});
	await dialog.getByRole('button', {name: 'Next'}).click();

	await expect(backButton).toBeVisible();
	await expect(dialog.locator('.cleara11y-progress-step[aria-current="step"]')).toContainText('Scope');
	await expect(dialog.locator('.cleara11y-wizard-step[data-step="2"] h3')).toBeFocused();

	await backButton.click();
	await expect(dialog.locator('.cleara11y-progress-step[aria-current="step"]')).toContainText('Target');
	await expect(dialog.locator('input[name="target_type"][value="rule_on_element"]')).toBeChecked();

	const dialogBounds = await dialog.boundingBox();
	expect(dialogBounds).not.toBeNull();
	expect(dialogBounds.y).toBeGreaterThanOrEqual(0);
	expect(dialogBounds.y + dialogBounds.height).toBeLessThanOrEqual(500);
	await expect(cancelButton).toBeVisible();

	await page.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
	await expect(createButton).toBeFocused();
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
	await expect(dialog.locator('#cleara11y-finding-rule-id')).toHaveText('color-contrast');
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

	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active&ruleId=button-name&groupBy=rule');
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
	await expect(dialog.locator('input[name="target_type"][value="rule"]')).toBeChecked();
	await expect(dialog.locator('input[name="target_type"][value="rule_on_element"]')).toBeDisabled();
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

test('narrow view overlays occurrence detail without removing the results', async ({page}) => {
	await page.setViewportSize({width: 600, height: 900});
	await page.goto('/wp-admin/admin.php?page=cleara11y-issues&status=active');
	await waitForResults(page);
	if (!await page.locator('[data-occurrence-row]').count()) {
		throw new Error('The self-contained occurrence fixture was not returned.');
	}
	await page.getByRole('button', {name: 'View details'}).first().click();
	await expect(page.locator('#cleara11y-results-region')).toBeVisible();
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
