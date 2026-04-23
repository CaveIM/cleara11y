/**
 * Issue Reference Page JavaScript
 *
 * Handles the issue reference page functionality including:
 * - Loading all axe-core rules via axe.getRules()
 * - Filtering by severity, category, and WCAG level
 * - Displaying detailed information about each rule
 */

document.addEventListener('DOMContentLoaded', function() {
	const IssueReferenceApp = {
		apiUrl: cleara11yData.apiUrl,
		nonce: cleara11yData.nonce,
		strings: cleara11yData.strings,

		state: {
			allRules: [],
			filteredRules: [],
			filters: {
				severity: '',
				category: '',
				wcag: '',
				search: ''
			}
		},

		init() {
			this.cacheElements();
			this.bindEvents();
			this.loadAxeRules();
		},

		cacheElements() {
			this.elements = {
				severityFilter: document.getElementById('cleara11y-severity-filter'),
				categoryFilter: document.getElementById('cleara11y-category-filter'),
				wcagFilter: document.getElementById('cleara11y-wcag-filter'),
				searchInput: document.getElementById('cleara11y-issue-search'),
				referenceList: document.getElementById('cleara11y-issue-reference-list'),
				totalRules: document.getElementById('cleara11y-total-rules'),
				filteredRules: document.getElementById('cleara11y-filtered-rules'),
				detailModal: document.getElementById('cleara11y-detail-modal'),
				modalTitle: document.getElementById('cleara11y-modal-title'),
				modalBody: document.getElementById('cleara11y-modal-body'),
				modalClose: document.querySelector('.cleara11y-modal-close'),
			};
		},

		bindEvents() {
			// Severity filter
			if (this.elements.severityFilter) {
				this.elements.severityFilter.addEventListener('change', (e) => {
					this.state.filters.severity = e.target.value;
					this.applyFilters();
				});
			}

			// Category filter
			if (this.elements.categoryFilter) {
				this.elements.categoryFilter.addEventListener('change', (e) => {
					this.state.filters.category = e.target.value;
					this.applyFilters();
				});
			}

			// WCAG filter
			if (this.elements.wcagFilter) {
				this.elements.wcagFilter.addEventListener('change', (e) => {
					this.state.filters.wcag = e.target.value;
					this.applyFilters();
				});
			}

			// Search (debounced)
			let searchTimeout;
			if (this.elements.searchInput) {
				this.elements.searchInput.addEventListener('input', (e) => {
					clearTimeout(searchTimeout);
					searchTimeout = setTimeout(() => {
						this.state.filters.search = e.target.value.toLowerCase();
						this.applyFilters();
					}, 300);
				});
			}

			// Modal close button
			if (this.elements.modalClose) {
				this.elements.modalClose.addEventListener('click', () => this.closeModal());
			}

			// Close modal on backdrop click
			if (this.elements.detailModal) {
				this.elements.detailModal.addEventListener('click', (e) => {
					if (e.target === this.elements.detailModal) {
						this.closeModal();
					}
				});
			}

			// Close modal on ESC key
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && this.elements.detailModal.style.display !== 'none') {
					this.closeModal();
				}
			});
		},

		async loadAxeRules() {
			try {
				// Check if axe is available
				if (typeof axe === 'undefined') {
					// Load axe-core dynamically
					await this.loadAxeCore();
				}

				// Get all rules from axe-core
				const rules = axe.getRules();

				if (!rules || rules.length === 0) {
					this.renderError('No rules found');
					return;
				}

				// Process and normalize rules
				this.state.allRules = this.processRules(rules);

				// Apply initial filters
				this.applyFilters();

			} catch (error) {
				console.error('Error loading axe rules:', error);
				this.renderError('Failed to load accessibility rules');
			}
		},

		loadAxeCore() {
			return new Promise((resolve, reject) => {
				const script = document.createElement('script');
				script.src = cleara11yData.pluginUrl + 'assets/js/axe.min.js';
				script.onload = resolve;
				script.onerror = reject;
				document.head.appendChild(script);
			});
		},

		processRules(rules) {
			// Get severity map from PHP (Rule_Severity_Map)
			const severityMap = cleara11yData.severityMap || {};

			return rules.map(rule => {
				const ruleId = rule.ruleId || rule.id;

				// Get numeric severity from our Rule_Severity_Map
				const numericSeverity = severityMap[ruleId] || severityMap['_default'] || 3;

				// Convert numeric severity to category (critical, moderate, minor)
				const severity = this.numericSeverityToCategory(numericSeverity);

				return {
					ruleId: ruleId,
					description: rule.description || '',
					help: rule.help || '',
					helpUrl: rule.helpUrl || '',
					numericSeverity: numericSeverity, // Store for reference
					severity: severity, // Normalized severity for filtering/display
					tags: rule.tags || []
				};
			}).sort((a, b) => a.ruleId.localeCompare(b.ruleId));
		},

		/**
		 * Convert numeric severity to category string.
		 * Maps to ClearA11y's category system: critical, moderate, minor
		 *
		 * Based on Rule_Severity_Map::severity_to_category()
		 *
		 * @param {number} severity - Numeric severity (1-4).
		 * @return {string} Severity category.
		 */
		numericSeverityToCategory(severity) {
			switch (severity) {
				case 1: // SEVERITY_CRITICAL
					return 'critical';
				case 2: // SEVERITY_HIGH
					return 'critical';
				case 3: // SEVERITY_MEDIUM
					return 'moderate';
				case 4: // SEVERITY_LOW
					return 'minor';
				default:
					return 'moderate';
			}
		},

		applyFilters() {
			const { severity, category, wcag, search } = this.state.filters;

			this.state.filteredRules = this.state.allRules.filter(rule => {
				// Filter by normalized severity (not raw impact)
				if (severity && rule.severity !== severity) {
					return false;
				}

				// Filter by category
				if (category) {
					const hasCategory = rule.tags.some(tag => tag.startsWith(category));
					if (!hasCategory) {
						return false;
					}
				}

				// Filter by WCAG level
				if (wcag) {
					const hasWcag = rule.tags.some(tag => tag.startsWith(wcag));
					if (!hasWcag) {
						return false;
					}
				}

				// Filter by search term
				if (search) {
					const searchLower = search.toLowerCase();
					const matchesId = rule.ruleId.toLowerCase().includes(searchLower);
					const matchesDescription = rule.description.toLowerCase().includes(searchLower);
					const matchesHelp = rule.help.toLowerCase().includes(searchLower);
					if (!matchesId && !matchesDescription && !matchesHelp) {
						return false;
					}
				}

				return true;
			});

			this.renderRules();
			this.updateStats();
		},

		renderRules() {
			if (this.state.filteredRules.length === 0) {
				this.elements.referenceList.innerHTML = `
					<div class="cleara11y-empty-state">
						<span class="dashicons dashicons-search"></span>
						<p>No rules match your current filters.</p>
					</div>
				`;
				return;
			}

			const html = this.state.filteredRules.map(rule => this.renderRuleItem(rule)).join('');
			this.elements.referenceList.innerHTML = html;

			// Bind detail button events
			this.bindDetailButtons();
		},

		renderRuleItem(rule) {
			const severity = rule.severity || 'moderate';
			const tags = (rule.tags || []).filter(tag => !tag.startsWith('wcag')).slice(0, 4);

			return `
				<div class="cleara11y-reference-item" data-rule-id="${this.escapeHtml(rule.ruleId)}">
					<div class="cleara11y-rule-meta">
						<span class="cleara11y-rule-impact ${severity}">${this.escapeHtml(severity)}</span>
						<div class="cleara11y-rule-tags">
							${tags.map(tag => `<span class="cleara11y-rule-tag">${this.escapeHtml(tag)}</span>`).join('')}
						</div>
					</div>
					<div class="cleara11y-rule-info">
						<h3>${this.escapeHtml(rule.help || rule.ruleId)}</h3>
						<div class="cleara11y-rule-id">${this.escapeHtml(rule.ruleId)}</div>
						<div class="cleara11y-rule-description">${this.escapeHtml(rule.description)}</div>
					</div>
					<div class="cleara11y-rule-actions">
						<button class="button cleara11y-view-details-btn" data-rule-id="${this.escapeHtml(rule.ruleId)}">
							View Details
						</button>
					</div>
				</div>
			`;
		},

		bindDetailButtons() {
			this.elements.referenceList.querySelectorAll('.cleara11y-view-details-btn').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const ruleId = e.target.dataset.ruleId;
					this.showRuleDetails(ruleId);
				});
			});
		},

		showRuleDetails(ruleId) {
			const rule = this.state.allRules.find(r => r.ruleId === ruleId);

			if (!rule) {
				return;
			}

			// Set modal title
			this.elements.modalTitle.textContent = rule.help || rule.ruleId;

			// Get WCAG tags
			const wcagTags = (rule.tags || []).filter(tag => tag.startsWith('wcag'));
			const categoryTags = (rule.tags || []).filter(tag => !tag.startsWith('wcag'));

			// Build modal content
			this.elements.modalBody.innerHTML = `
				<div class="cleara11y-detail-section">
					<h4>Rule ID</h4>
					<code>${this.escapeHtml(rule.ruleId)}</code>
				</div>

				<div class="cleara11y-detail-section">
					<h4>What It Checks</h4>
					<p>${this.escapeHtml(rule.description)}</p>
				</div>

				<div class="cleara11y-detail-section">
					<h4>Why It Matters</h4>
					<p>${this.escapeHtml(rule.help)}</p>
				</div>

				<div class="cleara11y-detail-section">
					<h4>Severity Level</h4>
					<span class="cleara11y-rule-impact ${rule.severity || 'moderate'}">${this.escapeHtml(rule.severity || 'moderate')}</span>
					<small style="display: block; margin-top: 5px; color: #646970;">
						(Numeric severity: ${rule.numericSeverity || 3} - ${this.getSeverityLabel(rule.numericSeverity)})
					</small>
				</div>

				${wcagTags.length > 0 ? `
					<div class="cleara11y-detail-section">
						<h4>WCAG Success Criteria</h4>
						<div class="cleara11y-wcag-tags">
							${wcagTags.map(tag => {
								const tagClass = tag.replace(/[\.\s]/g, '-');
								const label = this.getWcagLabel(tag);
								return `<span class="cleara11y-wcag-tag ${tagClass}">${this.escapeHtml(label)}</span>`;
							}).join('')}
						</div>
					</div>
				` : ''}

				${categoryTags.length > 0 ? `
					<div class="cleara11y-detail-section">
						<h4>Categories</h4>
						<div class="cleara11y-wcag-tags">
							${categoryTags.map(tag => `<span class="cleara11y-wcag-tag">${this.escapeHtml(tag)}</span>`).join('')}
						</div>
					</div>
				` : ''}

				${rule.helpUrl ? `
					<div class="cleara11y-detail-section">
						<h4>Learn More</h4>
						<a href="${this.escapeHtml(rule.helpUrl)}" target="_blank" rel="noopener noreferrer" class="cleara11y-help-url">
							Full Documentation <span class="dashicons dashicons-external"></span>
						</a>
					</div>
				` : ''}
			`;

			// Show modal
			this.elements.detailModal.style.display = 'flex';
		},

		getWcagLabel(tag) {
			const labels = {
				'wcag2a': 'WCAG 2.0 Level A',
				'wcag2aa': 'WCAG 2.0 Level AA',
				'wcag2aaa': 'WCAG 2.0 Level AAA',
				'wcag21a': 'WCAG 2.1 Level A',
				'wcag21aa': 'WCAG 2.1 Level AA',
				'wcag21aaa': 'WCAG 2.1 Level AAA',
				'wcag22a': 'WCAG 2.2 Level A',
				'wcag22aa': 'WCAG 2.2 Level AA',
			};
			return labels[tag] || tag;
		},

		getSeverityLabel(numericSeverity) {
			const labels = {
				1: 'Critical',
				2: 'High',
				3: 'Medium',
				4: 'Low'
			};
			return labels[numericSeverity] || 'Unknown';
		},

		closeModal() {
			this.elements.detailModal.style.display = 'none';
		},

		updateStats() {
			if (this.elements.totalRules) {
				this.elements.totalRules.textContent = this.state.allRules.length;
			}
			if (this.elements.filteredRules) {
				this.elements.filteredRules.textContent = this.state.filteredRules.length;
			}
		},

		renderError(message) {
			this.elements.referenceList.innerHTML = `
				<div class="cleara11y-error">
					<p>${this.escapeHtml(message)}</p>
				</div>
			`;
		},

		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},
	};

	// Initialize the app
	IssueReferenceApp.init();
});
