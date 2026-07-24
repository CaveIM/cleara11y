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

function updatePost(postId, fields) {
	const args = ['post', 'update', String(postId)];
	Object.entries(fields).forEach(([key, value]) => args.push(`--${key}=${value}`));
	wp(args);
}

function identitiesForScan(scanId) {
	const code = `
		$rows = $GLOBALS['wpdb']->get_results(
			"SELECT violation_identity_v2, violation_identity_v2_inputs,
				element_identity_v2_inputs, source_type, source_ref,
				owner_type, owner_name, source_key, selector
			FROM {$GLOBALS['wpdb']->prefix}cleara11y_issues
			WHERE scan_id = ${Number(scanId)}
				AND violation_identity_v2 IS NOT NULL",
			ARRAY_A
		);
		echo wp_json_encode($rows);
	`;
	const rows = JSON.parse(wp(['eval', code]) || '[]');
	const identities = new Map();
	rows.forEach(row => {
		if (!identities.has(row.violation_identity_v2)) {
			identities.set(row.violation_identity_v2, {
				violationInputs: JSON.parse(row.violation_identity_v2_inputs),
				elementInputs: JSON.parse(row.element_identity_v2_inputs),
				source: {
					type: row.source_type,
					ref: row.source_ref,
					ownerType: row.owner_type,
					ownerName: row.owner_name,
					key: row.source_key
				},
				selector: row.selector
			});
		}
	});
	return identities;
}

async function runScan(browser, postId) {
	const tokenCode = `echo wp_json_encode(
		\\ClearA11y\\Services\\Scan_Token_Manager::generate_token(${Number(postId)})
	);`;
	const tokenData = JSON.parse(wp(['eval', tokenCode]));
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

function compareIdentitySets(mutation, baseline, after) {
	const retained = [...baseline.keys()].filter(identity => after.has(identity));
	const lost = [...baseline.keys()].filter(identity => !after.has(identity));
	const spuriouslyNew = [...after.keys()].filter(identity => !baseline.has(identity));
	const retention = baseline.size ? 100 * retained.length / baseline.size : 100;

	return {
		mutation,
		before: baseline.size,
		after: after.size,
		retained: retained.length,
		lost,
		spuriouslyNew,
		retention
	};
}

test('real scans retain v2 identity across M1-M7 content mutations', async ({browser}) => {
	test.setTimeout(180000);

	const blockA = '<!-- wp:html --><section><button></button><img src="/cleara11y-missing-alt.png"></section><!-- /wp:html -->';
	const copy = '<!-- wp:paragraph --><p style="color:#111;background:#fff">Stable unrelated copy.</p><!-- /wp:paragraph -->';
	const blockB = '<!-- wp:html --><aside><a href="/identity-harness-docs"></a><input type="text"></aside><!-- /wp:html -->';
	const baselineContent = blockA + copy + blockB;
	const originalPermalink = wp(['option', 'get', 'permalink_structure']);
	const postId = Number(wp([
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		'--post_title=ClearA11y Identity Mutation Harness',
		'--post_name=cleara11y-identity-mutation-harness',
		`--post_content=${baselineContent}`,
		'--porcelain'
	]));
	const createdPostIds = [postId];
	const createdScanIds = [];
	const reports = [];

	const resetFixture = () => {
		updatePost(postId, {
			post_content: baselineContent,
			post_name: 'cleara11y-identity-mutation-harness'
		});
		wp(['option', 'update', 'permalink_structure', originalPermalink]);
		wp(['rewrite', 'flush']);
	};

	try {
		resetFixture();
		const baselineScan = await runScan(browser, postId);
		createdScanIds.push(baselineScan);
		const baseline = identitiesForScan(baselineScan);
		expect(baseline.size, 'The real-scan fixture produced no v2 identities').toBeGreaterThan(0);
		const unrelatedPageIds = [];

		const mutations = [
			{
				id: 'M1',
				apply() {
					const paragraph = '<!-- wp:paragraph --><p style="color:#111;background:#fff">Inserted above.</p><!-- /wp:paragraph -->';
					updatePost(postId, {post_content: paragraph + baselineContent});
				}
			},
			{
				id: 'M2',
				apply() {
					updatePost(postId, {post_name: 'cleara11y-renamed-identity-harness'});
				}
			},
			{
				id: 'M3',
				apply() {
					updatePost(postId, {post_content: blockB + copy + blockA});
				}
			},
			{
				id: 'M4',
				apply() {
					updatePost(postId, {
						post_content: blockA
							+ copy.replace('Stable unrelated copy.', 'Edited unrelated body copy.')
							+ blockB
					});
				}
			},
			{
				id: 'M5',
				apply() {
					updatePost(postId, {
						post_content: baselineContent.replace(
							'<section><button></button><img src="/cleara11y-missing-alt.png"></section>',
							'<div><section><button></button><img src="/cleara11y-missing-alt.png"></section></div>'
						)
					});
				}
			},
			{
				id: 'M6',
				allowNew: true,
				apply() {
					for (let index = 1; index <= 10; index++) {
						const unrelatedId = Number(wp([
							'post',
							'create',
							'--post_type=page',
							'--post_status=publish',
							`--post_title=Identity Harness Unrelated ${index}`,
							`--post_content=Unrelated page ${index}`,
							'--porcelain'
						]));
						createdPostIds.push(unrelatedId);
						unrelatedPageIds.push(unrelatedId);
					}
				},
				cleanup() {
					unrelatedPageIds.forEach(unrelatedId => {
						updatePost(unrelatedId, {post_status: 'draft'});
					});
				}
			},
			{
				id: 'M7',
				apply() {
					wp(['option', 'update', 'permalink_structure', '/index.php/%postname%/']);
					wp(['rewrite', 'flush']);
				}
			}
		];

		for (const mutation of mutations) {
			resetFixture();
			mutation.apply();
			const scanId = await runScan(browser, postId);
			createdScanIds.push(scanId);
			const after = identitiesForScan(scanId);
			const report = compareIdentitySets(
				mutation.id,
				baseline,
				after
			);
			reports.push(report);

			expect(
				report.retention,
				`${mutation.id} lost identities:\n${JSON.stringify(
					report.lost.map(identity => ({
						identity,
						before: baseline.get(identity),
						afterCandidates: report.spuriouslyNew.map(newIdentity => ({
							identity: newIdentity,
							inputs: after.get(newIdentity)
						}))
					})),
					null,
					2
				)}`
			).toBeGreaterThanOrEqual(95);
			if (!mutation.allowNew) {
				expect(
					report.spuriouslyNew,
					`${mutation.id} produced unexpected identities`
				).toEqual([]);
			}
			if (mutation.cleanup) mutation.cleanup();
		}

		console.log(JSON.stringify(reports, null, 2));
	} finally {
		wp(['option', 'update', 'permalink_structure', originalPermalink]);
		wp(['rewrite', 'flush']);

		if (createdScanIds.length) {
			const scanIds = createdScanIds.map(Number).join(',');
			const cleanupCode = `
				$scan_ids = [${scanIds}];
				$prefix = $GLOBALS['wpdb']->prefix . 'cleara11y_';
				$issue_ids = $GLOBALS['wpdb']->get_col(
					"SELECT id FROM {$prefix}issues WHERE scan_id IN (${scanIds})"
				);
				if ($issue_ids) {
					$GLOBALS['wpdb']->query(
						"DELETE FROM {$prefix}violation_ignore_matches
						WHERE violation_id IN (" . implode(',', array_map('absint', $issue_ids)) . ")"
					);
				}
				$GLOBALS['wpdb']->query(
					"DELETE FROM {$prefix}occurrence_states WHERE latest_scan_id IN (${scanIds})"
				);
				$GLOBALS['wpdb']->query("DELETE FROM {$prefix}issues WHERE scan_id IN (${scanIds})");
				$GLOBALS['wpdb']->query("DELETE FROM {$prefix}scan_items WHERE scan_id IN (${scanIds})");
				$GLOBALS['wpdb']->query("DELETE FROM {$prefix}scans WHERE id IN (${scanIds})");
			`;
			wp(['eval', cleanupCode]);
		}

		createdPostIds.forEach(createdPostId => {
			try {
				wp(['post', 'delete', String(createdPostId)]);
			} catch (error) {
				// The primary assertion should remain the reported failure.
			}
		});
	}
});
