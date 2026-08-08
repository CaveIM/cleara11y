/**
 * URL query model for the ClearA11y Issues Explorer.
 */
(function(root, factory) {
	const api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ClearA11yIssueQuery = api;
	}
})(typeof globalThis !== 'undefined' ? globalThis : window, function() {
	'use strict';

	const enums = {
		status: ['active', 'exception', 'all'],
		severity: ['critical', 'moderate', 'minor'],
		findingType: ['violation', 'review'],
		groupBy: ['page', 'rule'],
		sort: ['severity', 'newest', 'page', 'rule']
	};

	const positiveInteger = value => {
		const number = Number.parseInt(value, 10);
		return Number.isInteger(number) && number > 0 ? number : undefined;
	};

	const defaultGroup = query => {
		if (query.pageId || query.scanId) return 'rule';
		if (query.ruleId) return 'page';
		return 'page';
	};

	function parse(input) {
		const url = input instanceof URL ? input : new URL(input, 'http://localhost');
		const params = url.searchParams;
		const query = {
			status: enums.status.includes(params.get('status')) ? params.get('status') : 'active',
			severity: enums.severity.includes(params.get('severity')) ? params.get('severity') : '',
			findingType: enums.findingType.includes(params.get('findingType')) ? params.get('findingType') : '',
			includeExceptions: params.get('includeExceptions') === '1',
			ruleId: (params.get('ruleId') || '').trim(),
			pageId: positiveInteger(params.get('pageId')),
			scanId: positiveInteger(params.get('scanId')),
			search: (params.get('search') || '').trim(),
			sort: enums.sort.includes(params.get('sort')) ? params.get('sort') : 'severity',
			occurrenceId: positiveInteger(params.get('occurrenceId')),
			resultsPage: positiveInteger(params.get('resultsPage')) || 1
		};
		query.groupBy = enums.groupBy.includes(params.get('groupBy'))
			? params.get('groupBy')
			: defaultGroup(query);
		if (query.status === 'all' || query.status === 'exception') query.includeExceptions = true;
		if (query.scanId) {
			query.status = 'all';
			query.includeExceptions = true;
		}
		return query;
	}

	function toUrl(query, input) {
		const url = input instanceof URL ? new URL(input.href) : new URL(input, 'http://localhost');
		[
			'status', 'severity', 'findingType', 'includeExceptions', 'ruleId', 'pageId', 'scanId', 'search',
			'groupBy', 'sort', 'occurrenceId', 'resultsPage'
		].forEach(key => url.searchParams.delete(key));

		if (!query.scanId && query.status === 'exception') url.searchParams.set('status', 'exception');
		if (query.severity) url.searchParams.set('severity', query.severity);
		if (query.findingType) url.searchParams.set('findingType', query.findingType);
		if (!query.scanId && query.includeExceptions && query.status !== 'exception') url.searchParams.set('includeExceptions', '1');
		if (query.ruleId) url.searchParams.set('ruleId', query.ruleId);
		if (query.pageId) url.searchParams.set('pageId', String(query.pageId));
		if (query.scanId) url.searchParams.set('scanId', String(query.scanId));
		if (query.search) url.searchParams.set('search', query.search);
		if (query.groupBy) url.searchParams.set('groupBy', query.groupBy);
		if (query.sort && query.sort !== 'severity') url.searchParams.set('sort', query.sort);
		if (query.occurrenceId) url.searchParams.set('occurrenceId', String(query.occurrenceId));
		if (query.resultsPage > 1) url.searchParams.set('resultsPage', String(query.resultsPage));
		return url;
	}

	function apiParams(query, perPage) {
		const requestedPerPage = Number.parseInt(perPage, 10);
		const normalizedPerPage = Number.isInteger(requestedPerPage)
			? Math.min(100, Math.max(1, requestedPerPage))
			: 20;
		const params = new URLSearchParams({
			group_by: query.groupBy,
			sort: query.sort,
			page: String(query.resultsPage),
			per_page: String(normalizedPerPage)
		});
		if (!query.scanId) {
			params.set('status', query.status === 'exception' ? 'exception' : (query.includeExceptions ? 'all' : 'active'));
		}
		if (query.severity) params.set('severity', query.severity);
		if (query.findingType) params.set('finding_type', query.findingType);
		if (query.ruleId) params.set('rule_id', query.ruleId);
		if (query.pageId) params.set('page_id', String(query.pageId));
		if (query.scanId) params.set('scan_id', String(query.scanId));
		if (query.search) params.set('search', query.search);
		return params;
	}

	return {parse, toUrl, apiParams, defaultGroup, enums};
});
