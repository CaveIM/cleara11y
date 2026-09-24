document.addEventListener('DOMContentLoaded', function () {
	const form = document.getElementById('cleara11y-clear-database-form');
	if (!form) return;
	form.addEventListener('submit', function (event) {
		if (!window.confirm(cleara11ySettings.confirmClear) || !window.confirm(cleara11ySettings.confirmFinal)) event.preventDefault();
	});
});
