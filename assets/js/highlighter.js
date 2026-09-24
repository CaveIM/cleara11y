document.addEventListener('DOMContentLoaded', function() {
	try {
		var issue = cleara11yHighlight;
		var element = document.querySelector(issue.selector);

		if (element) {
			element.classList.add('cleara11y-highlight-issue');
			element.setAttribute('data-cleara11y-highlighted', 'true');
			element.scrollIntoView({ behavior: 'smooth', block: 'center' });

			// Add info panel
			var panel = document.createElement('div');
			panel.className = 'cleara11y-highlight-panel cleara11y-severity-' + issue.severity;
			panel.setAttribute('data-cleara11y-plugin', 'true');
			panel.setAttribute('aria-hidden', 'true');
			var title = document.createElement('strong');
			title.textContent = issue.ruleId;
			var close = document.createElement('button');
			close.className = 'cleara11y-close-panel';
			close.textContent = '×';
			panel.append(title, document.createElement('br'), document.createTextNode(issue.message || ''), close);
			document.body.appendChild(panel);

			// Close button handler
			panel.querySelector('.cleara11y-close-panel').addEventListener('click', function() {
				element.classList.remove('cleara11y-highlight-issue');
				panel.remove();
			});

			// Auto-hide after 10 seconds
			setTimeout(function() {
				element.classList.remove('cleara11y-highlight-issue');
				panel.remove();
			}, 10000);
		}
	} catch (e) {
		console.error('ClearA11y: Could not highlight element', e);
	}
});
