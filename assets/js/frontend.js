/**
 * ClearA11y Frontend JavaScript
 *
 * Shows accessibility issues panel on pages when authorized users visit.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	// Initialize when document is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		// Always initialize if data is available, even with no issues
		if (!window.cleara11yIssues) {
			return;
		}

		ClearA11yFrontend.init();
	}

	var ClearA11yFrontend = {

		issues: [],
		highlightsVisible: false,
		panel: null,
		tooltip: null,
		tooltipHideTimer: null,
		$toggle: null,
		currentIssueIndex: -1,

		// Panel display settings (persisted per user)
		settings: {
			highlight_all: true,
			pulse: true,
			tooltips: true,
			sort: 'page',
			group: 'none'
		},
		$gear: null,
		settingsLightbox: null,

		// Drag state
		isDragging: false,
		dragOffset: { x: 0, y: 0 },
		panelPosition: { x: null, y: null },

		// Resize state
		isResizing: false,
		panelHeight: null,
		resizeStartY: 0,
		resizeStartHeight: 0,




		init: function() {
			this.issues = window.cleara11yIssues.issues || [];
			if (window.cleara11yIssues.settings) {
				var saved = window.cleara11yIssues.settings;
				['highlight_all', 'pulse', 'tooltips'].forEach(function(key) {
					if (key in saved) this.settings[key] = Boolean(saved[key]);
				}, this);
				['sort', 'group'].forEach(function(key) {
					if (key in saved && saved[key]) this.settings[key] = String(saved[key]);
				}, this);
			}
			this.applySettings();
			this.createToggleButton();
			this.createPanel();
			this.loadPanelPosition();
			this.loadPanelHeight();


			this.bindEvents();
		},

		createToggleButton: function() {
			var toggle = document.createElement('button');
			var hasIssues = this.issues.length > 0;

			toggle.className = 'cleara11y-toggle' + (hasIssues ? ' has-issues' : ' no-issues');
			toggle.title = hasIssues
				? 'Toggle Accessibility Issues (' + this.issues.length + ' found)'
				: 'Toggle Accessibility Panel (No issues found)';
			toggle.setAttribute('aria-label', 'Toggle accessibility issues panel');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-controls', 'cleara11y-issues-panel');

			// Show different icon and state based on issues
			var icon = hasIssues ? '⚠' : '✓';
			var count = hasIssues ? this.issues.length : 'OK';

			toggle.innerHTML = '<span class="cleara11y-toggle-icon" data-cleara11y-plugin="true">' + icon + '</span><span class="cleara11y-toggle-count" data-cleara11y-plugin="true">' + count + '</span>';
			toggle.setAttribute('data-cleara11y-plugin', 'true'); // Mark as plugin element
			document.documentElement.appendChild(toggle);
			this.$toggle = toggle;
		},

		createPanel: function() {
			var panel = document.createElement('aside');
			panel.className = 'cleara11y-panel';
			panel.id = 'cleara11y-issues-panel';
			panel.setAttribute('role', 'complementary');
			panel.setAttribute('aria-label', 'Accessibility issues panel');
			panel.setAttribute('data-cleara11y-plugin', 'true'); // Mark as plugin element
			panel.innerHTML = this.buildPanelHtml();
			document.documentElement.appendChild(panel);
			this.panel = panel;
			this.$gear = panel.querySelector('.cleara11y-panel-gear');
			this.settingsLightbox = panel.querySelector('.cleara11y-settings-lightbox');

			// Add overlay backdrop
			var backdrop = document.createElement('div');
			backdrop.className = 'cleara11y-backdrop';
			backdrop.setAttribute('data-cleara11y-plugin', 'true');
			document.documentElement.appendChild(backdrop);
		},

		buildPanelHtml: function() {
			var html = '';

			html += '<div class="cleara11y-panel-header" data-cleara11y-plugin="true">';

			html += '<div class="cleara11y-panel-header-left">';
			html += '<div class="cleara11y-panel-drag-handle" title="Drag to move panel" aria-label="Drag handle">';
			html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#646970" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="12" r="1"></circle><circle cx="9" cy="5" r="1"></circle><circle cx="9" cy="19" r="1"></circle><circle cx="15" cy="12" r="1"></circle><circle cx="15" cy="5" r="1"></circle><circle cx="15" cy="19" r="1"></circle></svg>';
			html += '</div>';
			html += '<div class="cleara11y-panel-title-group">';
			html += '<h2 class="cleara11y-panel-title" data-cleara11y-plugin="true">Accessibility Issues</h2>';
			html += '<span class="cleara11y-panel-issue-count" data-cleara11y-plugin="true">' + this.issues.length + ' issues</span>';
			html += '</div>';

			html += '</div>';
			html += '<div class="cleara11y-panel-header-right">';
			html += '<button class="cleara11y-panel-prev" title="Previous issue (Shift + ↑)" aria-label="Previous issue" disabled>';
			html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#646970" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><polyline points="15 18 9 12 15 6"></polyline></svg>';
			html += '</button>';
			html += '<button class="cleara11y-panel-next" title="Next issue (Shift + ↓)" aria-label="Next issue">';
			html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#646970" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><polyline points="9 18 15 12 9 6"></polyline></svg>';
			html += '</button>';
			html += '<button class="cleara11y-panel-close" title="Close panel (Escape)" aria-label="Close panel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#646970" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>';
			html += '</div>';
			html += '</div>';

			// Content
			html += '<div class="cleara11y-panel-content" data-cleara11y-plugin="true">';

			// Summary
			var criticalCount = this.issues.filter(function(i) { return i.severity === 'critical'; }).length;
			var moderateCount = this.issues.filter(function(i) { return i.severity === 'moderate'; }).length;
			var minorCount = this.issues.filter(function(i) { return i.severity === 'minor'; }).length;

			html += '<div class="cleara11y-panel-summary' + (this.issues.length > 0 ? ' has-violations' : ' no-violations') + '" data-cleara11y-plugin="true">';
			html += '<div class="cleara11y-summary-header">';
			html += '<svg class="cleara11y-summary-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><circle cx="12" cy="17" r="0.5"></circle></svg>';
			html += '<h3 data-cleara11y-plugin="true">' + (this.issues.length > 0 ? this.issues.length + ' Issues Found' : 'No Issues Found') + '</h3>';
			html += '</div>';

			if (this.issues.length > 0) {
				html += '<p class="cleara11y-summary-text">Click on an issue to highlight it on the page.</p>';
				html += '<div class="cleara11y-summary-stats">';
				html += '<div class="cleara11y-stat cleara11y-stat-critical">';
				html += '<span class="cleara11y-stat-value">' + criticalCount + '</span>';
				html += '<span class="cleara11y-stat-label">Critical</span>';
				html += '</div>';
				html += '<div class="cleara11y-stat cleara11y-stat-moderate">';
				html += '<span class="cleara11y-stat-value">' + moderateCount + '</span>';
				html += '<span class="cleara11y-stat-label">Moderate</span>';
				html += '</div>';
				html += '<div class="cleara11y-stat cleara11y-stat-minor">';
				html += '<span class="cleara11y-stat-value">' + minorCount + '</span>';
				html += '<span class="cleara11y-stat-label">Minor</span>';
				html += '</div>';
				html += '</div>';
			} else {
				html += '<p class="cleara11y-summary-text">Great job! No accessibility issues were detected on this page.</p>';
			}
			html += '</div>';

			if (this.issues.length > 0) {
				// Filter tabs
				html += '<div class="cleara11y-filter-tabs">';
				html += '<button class="cleara11y-filter-tab active" data-filter="all">All (' + this.issues.length + ')</button>';
				html += '<button class="cleara11y-filter-tab" data-filter="critical">Critical (' + criticalCount + ')</button>';
				html += '<button class="cleara11y-filter-tab" data-filter="moderate">Moderate (' + moderateCount + ')</button>';
				html += '<button class="cleara11y-filter-tab" data-filter="minor">Minor (' + minorCount + ')</button>';
				html += '</div>';

				// Issues list
				html += '<div class="cleara11y-issues-list-container">';
				html += this.buildIssuesListHtml();
				html += '</div>';
			}

			html += '</div>';

			// Settings lightbox
			html += '<div class="cleara11y-settings-lightbox" id="cleara11y-settings-lightbox" role="dialog" aria-label="Panel display settings" hidden>';
			html += '<div class="cleara11y-settings-card" data-cleara11y-plugin="true">';
			html += '<div class="cleara11y-settings-header">';
			html += '<h3>Display Settings</h3>';
			html += '<button class="cleara11y-settings-close" title="Close settings (Escape)" aria-label="Close display settings"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>';
			html += '</div>';
			html += '<label class="cleara11y-setting-row">';
			html += '<input type="checkbox" data-cleara11y-setting="highlight_all"' + (this.settings.highlight_all ? ' checked' : '') + '>';
			html += '<span class="cleara11y-setting-text"><strong>Highlight all issues</strong><em>Outline every issue on the page while the panel is open.</em></span>';
			html += '</label>';
			html += '<label class="cleara11y-setting-row">';
			html += '<input type="checkbox" data-cleara11y-setting="pulse"' + (this.settings.pulse ? ' checked' : '') + '>';
			html += '<span class="cleara11y-setting-text"><strong>Pulse animation</strong><em>Pulse the highlighted element when an issue is selected.</em></span>';
			html += '</label>';
			html += '<label class="cleara11y-setting-row">';
			html += '<input type="checkbox" data-cleara11y-setting="tooltips"' + (this.settings.tooltips ? ' checked' : '') + '>';
			html += '<span class="cleara11y-setting-text"><strong>Tooltips</strong><em>Show issue details on hover over highlighted elements on the page.</em></span>';
			html += '</label>';
			html += '<label class="cleara11y-setting-row is-select">';
			html += '<span class="cleara11y-setting-text"><strong>Sort issues</strong><em>Order of the issue list.</em></span>';
			html += '<select data-cleara11y-setting-select="sort">';
			html += '<option value="page"' + ('page' === this.settings.sort ? ' selected' : '') + '>Page order</option>';
			html += '<option value="severity"' + ('severity' === this.settings.sort ? ' selected' : '') + '>Severity</option>';
			html += '</select>';
			html += '</label>';
			html += '<label class="cleara11y-setting-row is-select">';
			html += '<span class="cleara11y-setting-text"><strong>Group issues</strong><em>Organize the list into sections.</em></span>';
			html += '<select data-cleara11y-setting-select="group">';
			html += '<option value="none"' + ('none' === this.settings.group ? ' selected' : '') + '>No grouping</option>';
			html += '<option value="rule"' + ('rule' === this.settings.group ? ' selected' : '') + '>By rule</option>';
			html += '<option value="element"' + ('element' === this.settings.group ? ' selected' : '') + '>By element</option>';
			html += '</select>';
			html += '</label>';
			html += '<span class="cleara11y-settings-status" role="status" aria-live="polite"></span>';
			html += '</div>';
			html += '</div>';

			// Footer
			html += '<div class="cleara11y-panel-footer">';
			if (this.issues.length > 0) {
				html += '<div class="cleara11y-panel-footer-info">';
				html += '<span class="cleara11y-keyboard-hint">Keyboard: <kbd>Shift</kbd> + <kbd>↑</kbd>/<kbd>↓</kbd> to navigate</span>';
				html += '</div>';
			}
			html += '<button class="cleara11y-panel-gear" title="Panel display settings" aria-label="Panel display settings" aria-expanded="false" aria-controls="cleara11y-settings-lightbox">';
			html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33 1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82 1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>';
			html += '</button>';
			html += '<div class="cleara11y-panel-resize-handle" title="Drag to resize panel height" aria-label="Resize handle"></div>';
			html += '</div>';

			return html;
		},

		escapeAttribute: function(text) {
			return this.escapeHtml(text).replace(/"/g, '&quot;');
		},

		parseNodeEvidence: function(value) {
			if (!value) return {};
			try {
				var parsed = 'string' === typeof value ? JSON.parse(value) : value;
				return (parsed && parsed.node_evidence) ? parsed.node_evidence : (parsed || {});
			} catch (e) {
				return {};
			}
		},

		htmlTagName: function(html) {
			var match = String(html || '').match(/^\s*<([a-z0-9-]+)/i);
			return match ? match[1] : '';
		},

		normalizeDisplayText: function(value, maxLength) {
			var text = String(value || '').replace(/\s+/g, ' ').trim();
			if (!maxLength || text.length <= maxLength) return text;
			return text.slice(0, maxLength - 1).trimEnd() + '…';
		},

		elementType: function(tag, role, inputType) {
			var roleTypes = {
				button: 'Button', link: 'Link', img: 'Image', textbox: 'Text field', checkbox: 'Checkbox', radio: 'Radio button',
				combobox: 'Select field', heading: 'Heading', navigation: 'Navigation', main: 'Main content', banner: 'Banner',
				contentinfo: 'Footer', form: 'Form', list: 'List', listitem: 'List item', table: 'Table'
			};
			if (roleTypes[role]) return roleTypes[role];
			if ('input' === tag) {
				if ('checkbox' === inputType) return 'Checkbox';
				if ('radio' === inputType) return 'Radio button';
				if (['button', 'submit', 'reset'].indexOf(inputType) !== -1) return 'Button';
				return 'Text field';
			}
			var tagTypes = {
				a: 'Link', button: 'Button', img: 'Image', textarea: 'Text field', select: 'Select field', label: 'Label',
				h1: 'Heading', h2: 'Heading', h3: 'Heading', h4: 'Heading', h5: 'Heading', h6: 'Heading',
				nav: 'Navigation', main: 'Main content', header: 'Header', footer: 'Footer', form: 'Form',
				ul: 'List', ol: 'List', li: 'List item', table: 'Table', iframe: 'Embedded frame', video: 'Video', audio: 'Audio',
				p: 'Paragraph', span: 'Text', div: 'Container', section: 'Section', article: 'Article'
			};
			return tagTypes[tag] || (tag ? '<' + tag + '> element' : 'Element');
		},

		imageFilename: function(source) {
			if (!source) return '';
			try {
				var path = new URL(source, window.location.href).pathname;
				return decodeURIComponent(path.split('/').filter(Boolean).pop() || '');
			} catch (e) {
				return '';
			}
		},

		buildIssuesListHtml: function() {
			var self = this;
			var entries = this.issues.map(function(issue, index) {
				return { issue: issue, index: index };
			});

			if ('severity' === this.settings.sort) {
				var rank = { critical: 4, serious: 3, moderate: 2, minor: 1 };
				entries.sort(function(a, b) {
					var ra = rank[a.issue.severity] || 0;
					var rb = rank[b.issue.severity] || 0;
					return rb !== ra ? rb - ra : a.index - b.index;
				});
			} else {
				// 'page' = document order: sort issues by where their element appears on the page
				entries.forEach(function(entry) {
					entry.el = null;
					if (entry.issue.selector) {
						try {
							entry.el = document.querySelector(entry.issue.selector);
						} catch (e) {
							entry.el = null;
						}
					}
				});
				entries.sort(function(a, b) {
					if (a.el && b.el) {
						var pos = a.el.compareDocumentPosition(b.el);
						if (pos & Node.DOCUMENT_POSITION_FOLLOWING) return -1;
						if (pos & Node.DOCUMENT_POSITION_PRECEDING) return 1;
						return a.index - b.index;
					}
					// Unresolvable selectors sink to the end, keeping original order
					if (a.el) return -1;
					if (b.el) return 1;
					return a.index - b.index;
				});
			}

			var html = '<ul class="cleara11y-panel-issues">';

			if ('rule' === this.settings.group || 'element' === this.settings.group) {
				var groups = [];
				var byKey = {};
				entries.forEach(function(entry) {
					var key = 'rule' === self.settings.group
						? String(entry.issue.rule_id || '')
						: String(entry.issue.selector || entry.issue.html || 'issue-' + entry.index);
					if (!byKey[key]) {
						byKey[key] = { entries: [] };
						groups.push(byKey[key]);
					}
					byKey[key].entries.push(entry);
				});

				groups.forEach(function(group) {
					var first = group.entries[0].issue;
					var label = 'rule' === self.settings.group
						? (first.help_text || first.rule_id)
						: self.getElementPresentation(first).text;
					html += '<li class="cleara11y-group-header" data-cleara11y-plugin="true">';
					html += '<span class="cleara11y-group-header-title">' + self.escapeHtml(label || '') + '</span>';
					html += '<span class="cleara11y-group-header-count">' + group.entries.length + '</span>';
					html += '</li>';
					group.entries.forEach(function(entry) {
						html += self.buildIssueCard(entry.issue, entry.index);
					});
				});
			} else {
				entries.forEach(function(entry) {
					html += self.buildIssueCard(entry.issue, entry.index);
				});
			}

			html += '</ul>';
			return html;
		},

		buildIssueCard: function(issue, index) {
			var presentation = this.getElementPresentation(issue);
			// Grouped by element: the header carries the element text, so the card shows the rule name
			var title = 'element' === this.settings.group
				? (issue.help_text || issue.rule_id)
				: (presentation.text || issue.help_text || issue.rule_id);

			var html = '<li class="cleara11y-panel-issue severity-' + issue.severity + '" data-issue-index="' + index + '" data-severity="' + issue.severity + '">';
			html += '<div class="cleara11y-issue-header">';
			html += '<div class="cleara11y-issue-info">';
			html += '<span class="cleara11y-issue-title">' + this.escapeHtml(title) + '</span>';
			html += '<span class="cleara11y-issue-info-btn" data-issue-index="' + index + '" aria-hidden="true">';
			html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="10.5" x2="12" y2="16"></line><circle cx="12" cy="7.5" r="0.5"></circle></svg>';
			html += '</span>';
			html += '</div>';
			html += '<span class="cleara11y-issue-severity-badge severity-' + issue.severity + '">' + issue.severity + '</span>';
			html += '</div>';
			if (presentation.htmlSnippet) {
				html += '<code class="cleara11y-issue-snippet" title="' + this.escapeAttribute(presentation.htmlFull) + '">' + this.escapeHtml(presentation.htmlSnippet) + '</code>';
			}
			html += '</li>';
			return html;
		},

		renderIssuesList: function() {
			if (!this.panel) return;
			var container = this.panel.querySelector('.cleara11y-issues-list-container');
			if (!container) return;

			var selectedIndex = this.currentIssueIndex;
			container.innerHTML = this.buildIssuesListHtml();

			// Reapply the active severity filter
			var activeTab = this.panel.querySelector('.cleara11y-filter-tab.active');
			if (activeTab) {
				this.filterIssues(activeTab.getAttribute('data-filter'));
			}

			// Restore the selection marker if the selected issue survived the re-render
			if (selectedIndex >= 0) {
				var selected = container.querySelector('.cleara11y-panel-issue[data-issue-index="' + selectedIndex + '"]');
				if (selected && 'none' !== selected.style.display) {
					selected.classList.add('selected');
					this.currentIssueIndex = selectedIndex;
				}
			}

			this.updateNavigationButtons();
		},

		getElementPresentation: function(issue) {
			var evidence = this.parseNodeEvidence(issue.node_evidence);
			var attributes = evidence.attributes || {};
			var tag = String(evidence.tag_name || this.htmlTagName(issue.html) || '').toLowerCase();
			var role = String(evidence.computed_role || attributes.role || '').toLowerCase();
			var type = this.elementType(tag, role, evidence.input_type || attributes.type);
			var accessibleName = this.normalizeDisplayText(issue.accessible_name || evidence.accessible_name, 120);
			var innerText = this.normalizeDisplayText(issue.inner_text_snippet || evidence.inner_text_snippet, 120);
			var text = accessibleName || innerText;
			var filename;

			if (!text) {
				if ('img' === tag && (filename = this.imageFilename(attributes.src))) {
					text = filename;
				} else if ('img' === tag || 'Image' === type) {
					text = 'Image without alternative text';
				} else if (['Button', 'Link', 'Text field', 'Checkbox', 'Radio button', 'Select field'].indexOf(type) !== -1) {
					text = 'Unlabelled ' + type.toLowerCase();
				} else {
					text = 'Affected ' + type.toLowerCase();
				}
			}

			return {
				text: text,
				htmlSnippet: this.normalizeDisplayText(issue.html, 120),
				htmlFull: this.normalizeDisplayText(issue.html, 240)
			};
		},

		bindEvents: function() {
			var self = this;

			// Toggle button click
			if (this.$toggle) {
				this.$toggle.addEventListener('click', function() {
					self.togglePanel();
				});
			}

			// Backdrop click
			var backdrop = document.querySelector('.cleara11y-backdrop');
			if (backdrop) {
				backdrop.addEventListener('click', function() {
					self.closePanel();
				});
			}

			// Panel close button
			var closeBtn = this.panel.querySelector('.cleara11y-panel-close');
			if (closeBtn) {
				closeBtn.addEventListener('click', function() {
					self.closePanel();
				});
			}

			// Navigation buttons
			var prevBtn = this.panel.querySelector('.cleara11y-panel-prev');
			var nextBtn = this.panel.querySelector('.cleara11y-panel-next');
			if (prevBtn) {
				prevBtn.addEventListener('click', function() {
					self.navigateToIssue('prev');
				});
			}
			if (nextBtn) {
				nextBtn.addEventListener('click', function() {
					self.navigateToIssue('next');
				});
			}

			// Filter tabs
			var filterTabs = this.panel.querySelectorAll('.cleara11y-filter-tab');
			filterTabs.forEach(function(tab) {
				tab.addEventListener('click', function() {
					var filter = this.getAttribute('data-filter');
					self.filterIssues(filter);

					// Update active tab
					filterTabs.forEach(function(t) {
						t.classList.remove('active');
					});
					this.classList.add('active');
				});
			});

			// Issue item click (delegated so list re-renders keep working)
			var listContainer = this.panel.querySelector('.cleara11y-issues-list-container');
			if (listContainer) {
				listContainer.addEventListener('click', function(e) {
					// Don't highlight when clicking links/buttons or the info icon (hover only)
					if (e.target.closest('a, button, .cleara11y-issue-info-btn')) return;

					var item = e.target.closest('.cleara11y-panel-issue');
					if (!item) return;

					var index = parseInt(item.getAttribute('data-issue-index'), 10);
					self.highlightIssue(index);
				});
			}

			// Highlight is triggered by clicking an issue row; no per-row button

			// Keyboard shortcuts
			document.addEventListener('keydown', function(e) {
				// Escape closes the settings lightbox first, then the panel
				if (e.key === 'Escape') {
					if (self.settingsLightbox && !self.settingsLightbox.hidden) {
						self.closeSettingsLightbox();
					} else if (self.panel && self.panel.classList.contains('open')) {
						self.closePanel();
					}
				}
				// Alt + A to toggle panel
				if (e.altKey && (e.key === 'a' || e.key === 'A')) {
					e.preventDefault();
					self.togglePanel();
				}
				// Shift + Arrow keys for navigation (when panel is open)
				if (self.panel && self.panel.classList.contains('open')) {
					if (e.shiftKey && e.key === 'ArrowUp') {
						e.preventDefault();
						self.navigateToIssue('prev');
					}
					if (e.shiftKey && e.key === 'ArrowDown') {
						e.preventDefault();
						self.navigateToIssue('next');
					}
				}
			});

				// Window resize handler with debounce
				var resizeTimeout;
				window.addEventListener('resize', function() {
					clearTimeout(resizeTimeout);
					resizeTimeout = setTimeout(function() {
						self.handleResize();
					}, 100);
				});

				// Orientation change handler for mobile devices
				window.addEventListener('orientationchange', function() {
					setTimeout(function() {
						self.handleResize();
					}, 200);
				});



			// Mouse events for tooltips (page highlights + card info icons)
			function isTooltipTrigger(el) {
				return !!(el && el.classList && (
					el.classList.contains('cleara11y-highlight') ||
					el.classList.contains('cleara11y-issue-info-btn')
				));
			}

			document.addEventListener('mouseenter', function(e) {
				var el = e.target;
				if (!el || !el.classList) return;
				// Info-icon tooltips inside the panel are always available
				if (el.classList.contains('cleara11y-issue-info-btn')) {
					self.showTooltip(el, e);
					return;
				}
				// Page-highlight tooltips honor the Tooltips setting
				if (self.settings.tooltips && el.classList.contains('cleara11y-highlight')) {
					self.showTooltip(el, e);
				}
			}, true);

			document.addEventListener('mouseleave', function(e) {
				if (isTooltipTrigger(e.target)) {
					self.scheduleTooltipHide();
				}
			}, true);

			// Settings gear + lightbox
			if (this.$gear) {
				this.$gear.addEventListener('click', function() {
					self.toggleSettingsLightbox();
				});
			}
			if (this.settingsLightbox) {
				this.settingsLightbox.addEventListener('click', function(e) {
					if (e.target === self.settingsLightbox) {
						self.closeSettingsLightbox();
					}
				});
				var settingsClose = this.settingsLightbox.querySelector('.cleara11y-settings-close');
				if (settingsClose) {
					settingsClose.addEventListener('click', function() {
						self.closeSettingsLightbox();
					});
				}
				this.settingsLightbox.querySelectorAll('[data-cleara11y-setting]').forEach(function(input) {
					input.addEventListener('change', function() {
						self.saveSetting(input.dataset.cleara11ySetting, input.checked, input);
					});
				});
				this.settingsLightbox.querySelectorAll('[data-cleara11y-setting-select]').forEach(function(select) {
					select.addEventListener('change', function() {
						self.saveSetting(select.dataset.cleara11ySettingSelect, select.value, select);
					});
				});
			}

				// Bind drag events to panel handle
				this.bindDragEvents();

		},

		togglePanel: function() {
			if (!this.panel) return;

			if (this.panel.classList.contains('open')) {
				this.closePanel();
			} else {
				this.openPanel();
			}
		},

		openPanel: function() {
			if (!this.panel) return;

			this.panel.classList.add('open');
			document.body.classList.add('cleara11y-panel-open');
			if (this.$toggle) {
				this.$toggle.classList.add('panel-open');
				this.$toggle.setAttribute('aria-expanded', 'true');
			}
			if (this.settings.highlight_all) {
				this.showHighlights();
			}

			// Re-render the list: the init-time build can predate late-rendered content or
			// runtime-added selectors (e.g. Elementor sticky classes), so page-order sorting
			// must resolve selectors against the DOM as it exists when the panel is opened
			this.renderIssuesList();

			this.updateNavigationButtons();

			// Apply saved panel position if available
			this.applyPanelPosition();
			this.applyPanelHeight();


		},

		closePanel: function() {
			if (!this.panel) return;

			this.panel.classList.remove('open');
			document.body.classList.remove('cleara11y-panel-open');

			// Reset inline styles so panel returns to default hidden position
			this.panel.style.left = '';
			this.panel.style.top = '';
			this.panel.style.transform = '';
			this.panel.style.height = '';

			if (this.$toggle) {
				this.$toggle.classList.remove('panel-open');
				this.$toggle.setAttribute('aria-expanded', 'false');
			}

			this.hideHighlights();
			this.currentIssueIndex = -1;
		},

			handleResize: function() {
				// Constrain panel to viewport when window resizes
				if (this.panel && this.panel.classList.contains('open')) {
					this.constrainPanelToViewport();

					// Recalculate tooltip position if visible
					if (this.tooltip && this.tooltip.classList.contains('show')) {
						this.hideTooltip();
					}
				}
			},


		filterIssues: function(severity) {
			var issues = this.panel.querySelectorAll('.cleara11y-panel-issue');

			issues.forEach(function(issue) {
				var issueSeverity = issue.getAttribute('data-severity');
				if (severity === 'all' || issueSeverity === severity) {
					issue.style.display = '';
				} else {
					issue.style.display = 'none';
				}
			});

			this.currentIssueIndex = -1;
			this.updateNavigationButtons();
		},

		navigateToIssue: function(direction) {
			var visibleIssues = Array.from(this.panel.querySelectorAll('.cleara11y-panel-issue:not([style*="display: none"])'));

			if (visibleIssues.length === 0) return;

			var currentIndex = visibleIssues.findIndex(function(issue) {
				return issue.classList.contains('selected');
			});

			var nextIndex;
			if (direction === 'next') {
				nextIndex = currentIndex < visibleIssues.length - 1 ? currentIndex + 1 : 0;
			} else {
				nextIndex = currentIndex > 0 ? currentIndex - 1 : visibleIssues.length - 1;
			}

			var issueIndex = parseInt(visibleIssues[nextIndex].getAttribute('data-issue-index'), 10);
			this.highlightIssue(issueIndex);
		},

		updateNavigationButtons: function() {
			var prevBtn = this.panel.querySelector('.cleara11y-panel-prev');
			var nextBtn = this.panel.querySelector('.cleara11y-panel-next');
			var visibleIssues = this.panel.querySelectorAll('.cleara11y-panel-issue:not([style*="display: none"])');

			if (prevBtn && nextBtn) {
				if (visibleIssues.length > 0) {
					prevBtn.disabled = false;
					nextBtn.disabled = false;
				} else {
					prevBtn.disabled = true;
					nextBtn.disabled = true;
				}
			}
		},

		showHighlights: function() {
			var self = this;

			this.issues.forEach(function(issue, index) {
				if (issue.selector) {
					try {
						var elements = document.querySelectorAll(issue.selector);
						elements.forEach(function(el) {
							// Skip highlighting elements inside the panel or already marked as plugin elements
							if (self.panel && self.panel.contains(el)) return;
							if (el.hasAttribute('data-cleara11y-plugin')) return;
							if (el.hasAttribute('data-cleara11y-highlighted')) return;

							el.classList.add('cleara11y-highlight', 'severity-' + issue.severity);
							el.setAttribute('data-issue-index', index);
							el.setAttribute('data-cleara11y-highlighted', 'true'); // Mark as highlighted by plugin
						});
					} catch (e) {
						console.warn('Could not highlight element with selector:', issue.selector);
					}
				}
			});

			this.highlightsVisible = true;
		},

		hideHighlights: function() {
			var highlights = document.querySelectorAll('.cleara11y-highlight');
			highlights.forEach(function(el) {
				el.classList.remove('cleara11y-highlight', 'cleara11y-highlight-focus', 'severity-critical', 'severity-moderate', 'severity-minor', 'severity-serious');
				el.removeAttribute('data-issue-index');
				el.removeAttribute('data-cleara11y-highlighted');
			});

			this.highlightsVisible = false;
			this.hideTooltip();
		},

		highlightIssue: function(index) {
			var issue = this.issues[index];

			if (!issue || !issue.selector) return;

			this.currentIssueIndex = index;

			// With "Highlight all" disabled, only one issue is highlighted at a time
			if (!this.settings.highlight_all) {
				this.hideHighlights();
			}

			// Remove previous focus highlights
			var previousFocus = document.querySelectorAll('.cleara11y-highlight-focus');
			previousFocus.forEach(function(el) {
				el.classList.remove('cleara11y-highlight-focus');
			});

			// Add focus highlight to this issue's elements
			var self = this;
			try {
				var elements = document.querySelectorAll(issue.selector);
				elements.forEach(function(el) {
					// Skip highlighting elements inside the panel or already marked as plugin elements
					if (self.panel && self.panel.contains(el)) return;
					if (el.hasAttribute('data-cleara11y-plugin')) return;

					// Ensure base highlight class is present for the pulse animation
					el.classList.add('cleara11y-highlight', 'severity-' + issue.severity);
					el.setAttribute('data-issue-index', index);
					el.setAttribute('data-cleara11y-highlighted', 'true'); // Mark as highlighted by plugin
					el.classList.add('cleara11y-highlight-focus');
				});

				// Scroll to first element
				if (elements.length > 0) {
					elements[0].scrollIntoView({
						behavior: 'smooth',
						block: 'center'
					});
				}
			} catch (e) {
				console.warn('ClearA11y: Could not focus element with selector:', issue.selector, e);
			}

			// Update panel selection
			var issueItems = this.panel.querySelectorAll('.cleara11y-panel-issue');
			issueItems.forEach(function(item) {
				item.classList.remove('selected');
			});
			var selectedItem = this.panel.querySelector('.cleara11y-panel-issue[data-issue-index="' + index + '"]');
			if (selectedItem) {
				selectedItem.classList.add('selected');
				// Scroll selected item into view in panel
				selectedItem.scrollIntoView({
					behavior: 'smooth',
					block: 'nearest'
				});
			}
		},

		applySettings: function() {
			document.documentElement.classList.toggle('cleara11y-pulse-off', !this.settings.pulse);
		},

		applySetting: function(option) {
			if ('pulse' === option) {
				document.documentElement.classList.toggle('cleara11y-pulse-off', !this.settings.pulse);
				return;
			}
			if ('highlight_all' === option) {
				if (this.settings.highlight_all) {
					if (this.panel && this.panel.classList.contains('open')) {
						this.showHighlights();
					}
				} else {
					this.hideHighlights();
				}
			}
			if ('sort' === option || 'group' === option) {
				this.renderIssuesList();
			}
		},

		toggleSettingsLightbox: function() {
			if (!this.settingsLightbox) return;
			if (this.settingsLightbox.hidden) {
				this.settingsLightbox.hidden = false;
			} else {
				this.closeSettingsLightbox();
				return;
			}
			if (this.$gear) {
				this.$gear.setAttribute('aria-expanded', 'true');
			}
		},

		closeSettingsLightbox: function() {
			if (!this.settingsLightbox) return;
			this.settingsLightbox.hidden = true;
			if (this.$gear) {
				this.$gear.setAttribute('aria-expanded', 'false');
			}
			var status = this.settingsLightbox.querySelector('.cleara11y-settings-status');
			if (status) status.textContent = '';
		},

		saveSetting: function(option, value, input) {
			var self = this;
			var previous = this.settings[option];
			this.settings[option] = value;
			this.applySetting(option);

			var status = this.settingsLightbox ? this.settingsLightbox.querySelector('.cleara11y-settings-status') : null;
			input.disabled = true;
			if (status) status.textContent = 'Saving…';

			var body = new URLSearchParams({
				action: 'cleara11y_save_panel_setting',
				nonce: window.cleara11yIssues.settingsNonce || '',
				option: option
			});
			if ('boolean' === typeof value) {
				body.append('enabled', value ? '1' : '0');
			} else {
				body.append('value', String(value));
			}

			fetch(window.cleara11yIssues.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			})
				.then(function(response) {
					return response.json().then(function(result) {
						if (!response.ok || !result.success) {
							throw new Error((result && result.data && result.data.message) || 'Setting could not be saved.');
						}
					});
				})
				.then(function() {
					if (status) status.textContent = 'Setting saved.';
				})
				.catch(function() {
					self.settings[option] = previous;
					if ('boolean' === typeof previous) {
						input.checked = previous;
					} else {
						input.value = previous;
					}
					self.applySetting(option);
					if (status) status.textContent = 'Setting could not be saved.';
				})
				.finally(function() {
					input.disabled = false;
				});
		},

		showTooltip: function(element, event) {
			var issueIndex = element.getAttribute('data-issue-index');

			if (!issueIndex || !this.issues[issueIndex]) return;

			// Cancel any pending hide so moving between trigger and tooltip keeps it open
			if (this.tooltipHideTimer) {
				clearTimeout(this.tooltipHideTimer);
				this.tooltipHideTimer = null;
			}

			var issue = this.issues[issueIndex];

			// Create tooltip if it doesn't exist
			if (!this.tooltip) {
				var self = this;
				this.tooltip = document.createElement('div');
				this.tooltip.className = 'cleara11y-tooltip';
				this.tooltip.setAttribute('data-cleara11y-plugin', 'true'); // Mark as plugin element
				this.tooltip.setAttribute('role', 'tooltip');
				document.documentElement.appendChild(this.tooltip);

				// Keep the tooltip open while the pointer is over it (Learn more link)
				this.tooltip.addEventListener('mouseenter', function() {
					if (self.tooltipHideTimer) {
						clearTimeout(self.tooltipHideTimer);
						self.tooltipHideTimer = null;
					}
				});
				this.tooltip.addEventListener('mouseleave', function() {
					self.scheduleTooltipHide();
				});
			}

			// Build tooltip content
			var tooltipHtml = '<div class="cleara11y-tooltip-header">';
			tooltipHtml += '<span class="cleara11y-tooltip-title">' + this.escapeHtml(issue.help_text || issue.rule_id) + '</span>';
			tooltipHtml += '<span class="cleara11y-tooltip-severity severity-' + issue.severity + '">' + issue.severity + '</span>';
			tooltipHtml += '</div>';
			tooltipHtml += '<div class="cleara11y-tooltip-message">' + this.escapeHtml(issue.message || issue.help_text || '') + '</div>';
			if (issue.help_url) {
				tooltipHtml += '<div class="cleara11y-tooltip-footer">';
				tooltipHtml += '<a href="' + this.escapeHtml(issue.help_url) + '" target="_blank" rel="noopener" class="cleara11y-tooltip-help">Learn more →</a>';
				tooltipHtml += '</div>';
			}

			this.tooltip.innerHTML = tooltipHtml;

			// Position tooltip intelligently
			var rect = element.getBoundingClientRect();
			var tooltipRect = this.tooltip.getBoundingClientRect();

			// Default to top-right of element
			var top = rect.top - tooltipRect.height - 10;
			var left = rect.right + 10;

			// If not enough space on top, show below
			if (top < 10) {
				top = rect.bottom + 10;
			}

			// If not enough space on right, show to the left
			if (left + tooltipRect.width > window.innerWidth - 10) {
				left = rect.left - tooltipRect.width - 10;
			}

			// If still not enough space, center horizontally
			if (left < 10) {
				left = Math.max(10, (window.innerWidth - tooltipRect.width) / 2);
			}

			this.tooltip.style.top = Math.max(10, top) + 'px';
			this.tooltip.style.left = Math.max(10, left) + 'px';
			this.tooltip.classList.add('show');
		},

		hideTooltip: function() {
			if (this.tooltipHideTimer) {
				clearTimeout(this.tooltipHideTimer);
				this.tooltipHideTimer = null;
			}
			if (this.tooltip) {
				this.tooltip.classList.remove('show');
			}
		},

		// Grace period before hiding so the pointer can travel from the trigger
		// onto the tooltip (which carries the Learn more link)
		scheduleTooltipHide: function() {
			var self = this;
			if (this.tooltipHideTimer) {
				clearTimeout(this.tooltipHideTimer);
			}
			this.tooltipHideTimer = setTimeout(function() {
				self.tooltipHideTimer = null;
				if (self.tooltip) {
					self.tooltip.classList.remove('show');
				}
			}, 250);
		},

		escapeHtml: function(text) {
			if (!text) return '';
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		bindDragEvents: function() {
			var self = this;
			var dragHandle = this.panel.querySelector('.cleara11y-panel-drag-handle');
			if (!dragHandle) return;

			// Mouse events
			dragHandle.addEventListener('mousedown', function(e) {
				self.startDrag(e);
			});

			// Touch events
			dragHandle.addEventListener('touchstart', function(e) {
				self.startDrag(e);
			}, { passive: false });

			// Bind resize events
			var resizeHandle = this.panel.querySelector('.cleara11y-panel-resize-handle');
			if (resizeHandle) {
				resizeHandle.addEventListener('mousedown', function(e) {
					self.startResize(e);
				});
				resizeHandle.addEventListener('touchstart', function(e) {
					self.startResize(e);
				}, { passive: false });
			}
		},

			startDrag: function(e) {
				if (!this.panel || !this.panel.classList.contains('open')) return;

				e.preventDefault();
				this.isDragging = true;
				this.panel.classList.add('draggable');

				var clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
				var clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;

				var rect = this.panel.getBoundingClientRect();
				this.dragOffset.x = clientX - rect.left;
				this.dragOffset.y = clientY - rect.top;

				// Add global event listeners
				var self = this;
				this._dragBoundHandler = function(ev) { self.drag(ev); };
				this._dragEndHandler = function(ev) { self.endDrag(ev); };

				if (e.type.includes('touch')) {
					document.addEventListener('touchmove', this._dragBoundHandler, { passive: false });
					document.addEventListener('touchend', this._dragEndHandler);
				} else {
					document.addEventListener('mousemove', this._dragBoundHandler);
					document.addEventListener('mouseup', this._dragEndHandler);
				}
			},

			drag: function(e) {
				if (!this.isDragging) return;

				e.preventDefault();

				var clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
				var clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;

				var newX = clientX - this.dragOffset.x;
				var newY = clientY - this.dragOffset.y;

				// Keep panel within viewport bounds with minimum margins
				var minMargin = window.innerWidth <= 600 ? 10 : (window.innerWidth <= 900 ? 20 : 30);
				var maxX = window.innerWidth - this.panel.offsetWidth - minMargin;
				var maxY = window.innerHeight - this.panel.offsetHeight - minMargin;

				newX = Math.max(minMargin, Math.min(newX, maxX));
				newY = Math.max(minMargin, Math.min(newY, maxY));

				this.panel.style.left = newX + 'px';
				this.panel.style.top = newY + 'px';
				this.panel.style.transform = 'none';
				this.panel.style.right = 'auto';
			},

			endDrag: function(e) {
				if (!this.isDragging) return;

				this.isDragging = false;
				this.panel.classList.remove('draggable');

				// Remove global event listeners
				if (this._dragBoundHandler) {
					document.removeEventListener('mousemove', this._dragBoundHandler);
					document.removeEventListener('touchmove', this._dragBoundHandler);
					this._dragBoundHandler = null;
				}
				if (this._dragEndHandler) {
					document.removeEventListener('mouseup', this._dragEndHandler);
					document.removeEventListener('touchend', this._dragEndHandler);
					this._dragEndHandler = null;
				}

				// Save position
				var rect = this.panel.getBoundingClientRect();
				this.panelPosition.x = rect.left;
				this.panelPosition.y = rect.top;
				this.savePanelPosition();
			},


			startResize: function(e) {
				if (!this.panel || !this.panel.classList.contains('open')) return;

				e.preventDefault();
				e.stopPropagation();

				this.isResizing = true;
				this.panel.classList.add('resizing');

				var clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
				var rect = this.panel.getBoundingClientRect();

				this.resizeStartY = clientY;
				this.resizeStartHeight = rect.height;

				// Add global event listeners
				var self = this;
				this._resizeBoundHandler = function(ev) { self.resize(ev); };
				this._resizeEndHandler = function(ev) { self.endResize(ev); };

				if (e.type.includes('touch')) {
					document.addEventListener('touchmove', this._resizeBoundHandler, { passive: false });
					document.addEventListener('touchend', this._resizeEndHandler);
				} else {
					document.addEventListener('mousemove', this._resizeBoundHandler);
					document.addEventListener('mouseup', this._resizeEndHandler);
				}
			},

			resize: function(e) {
				if (!this.isResizing) return;

				e.preventDefault();

				var clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;

				var deltaY = clientY - this.resizeStartY;
				var newHeight = this.resizeStartHeight + deltaY;

				// Calculate minimum height needed to show at least 1 issue (approximately 200px)
				var minMargin = window.innerWidth <= 600 ? 10 : (window.innerWidth <= 900 ? 20 : 30);
				var minPanelHeight = 200; // Minimum for 1 issue + header
				var maxPanelHeight = window.innerHeight - (minMargin * 2);

				newHeight = Math.max(minPanelHeight, Math.min(newHeight, maxPanelHeight));

				this.panel.style.height = newHeight + 'px';
				this.panelHeight = newHeight;
			},

			endResize: function(e) {
				if (!this.isResizing) return;

				this.isResizing = false;
				this.panel.classList.remove('resizing');

				// Remove global event listeners
				if (this._resizeBoundHandler) {
					document.removeEventListener('mousemove', this._resizeBoundHandler);
					document.removeEventListener('touchmove', this._resizeBoundHandler);
					this._resizeBoundHandler = null;
				}
				if (this._resizeEndHandler) {
					document.removeEventListener('mouseup', this._resizeEndHandler);
					document.removeEventListener('touchend', this._resizeEndHandler);
					this._resizeEndHandler = null;
				}

				// Save height
				this.savePanelHeight();
			},

			savePanelHeight: function() {
				if (this.panelHeight !== null) {
					try {
						localStorage.setItem('cleara11y-panel-height', this.panelHeight);
					} catch (e) {
						console.warn('Could not save panel height:', e);
					}
				}
			},

			loadPanelHeight: function() {
				try {
					var saved = localStorage.getItem('cleara11y-panel-height');
					if (saved) {
						this.panelHeight = parseInt(saved, 10);
					}
				} catch (e) {
					console.warn('Could not load panel height:', e);
				}
			},

			applyPanelHeight: function() {
				if (this.panelHeight && this.panel) {
					var minMargin = window.innerWidth <= 600 ? 10 : (window.innerWidth <= 900 ? 20 : 30);
					var minPanelHeight = 200;
					var maxPanelHeight = window.innerHeight - (minMargin * 2);

					// Ensure saved height is within valid range
					if (this.panelHeight >= minPanelHeight && this.panelHeight <= maxPanelHeight) {
						this.panel.style.height = this.panelHeight + 'px';
					}
				}
			},

			savePanelPosition: function() {
				if (this.panelPosition.x !== null && this.panelPosition.y !== null) {
					try {
						localStorage.setItem('cleara11y-panel-position', JSON.stringify(this.panelPosition));
					} catch (e) {
						console.warn('Could not save panel position:', e);
					}
				}
			},

			loadPanelPosition: function() {
				try {
					var saved = localStorage.getItem('cleara11y-panel-position');
					if (saved) {
						this.panelPosition = JSON.parse(saved);
					}
				} catch (e) {
					console.warn('Could not load panel position:', e);
				}
			},

			applyPanelPosition: function() {
				if (this.panelPosition.x !== null && this.panelPosition.y !== null) {
					// Check if position is still within viewport with minimum margins
					var minMargin = window.innerWidth <= 600 ? 10 : (window.innerWidth <= 900 ? 20 : 30);
					var maxX = window.innerWidth - this.panel.offsetWidth - minMargin;
					var maxY = window.innerHeight - this.panel.offsetHeight - minMargin;

					if (this.panelPosition.x >= minMargin && this.panelPosition.x <= maxX &&
						this.panelPosition.y >= minMargin && this.panelPosition.y <= maxY) {
						this.panel.style.left = this.panelPosition.x + 'px';
						this.panel.style.top = this.panelPosition.y + 'px';
						this.panel.style.transform = 'none';
						this.panel.style.right = 'auto';
					}
				}
			},

			constrainPanelToViewport: function() {
				if (!this.panel) return;

				var rect = this.panel.getBoundingClientRect();
				var minMargin = window.innerWidth <= 600 ? 10 : (window.innerWidth <= 900 ? 20 : 30);
				var maxX = window.innerWidth - this.panel.offsetWidth - minMargin;
				var maxY = window.innerHeight - this.panel.offsetHeight - minMargin;

				if (rect.left > maxX) {
					this.panel.style.left = maxX + 'px';
				}
				if (rect.top > maxY) {
					this.panel.style.top = maxY + 'px';
				}
				if (rect.left < minMargin) {
					this.panel.style.left = minMargin + 'px';
				}
				if (rect.top < minMargin) {
					this.panel.style.top = minMargin + 'px';
				}
			},

	};

	// Export for use in other scripts
	window.ClearA11yFrontend = ClearA11yFrontend;

})();
