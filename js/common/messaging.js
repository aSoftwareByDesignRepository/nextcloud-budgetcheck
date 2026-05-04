(function () {
	'use strict';

	let toastContainer = null;

	function ensureToastContainer() {
		if (toastContainer && document.body.contains(toastContainer)) {
			return toastContainer;
		}
		toastContainer = document.createElement('div');
		toastContainer.className = 'bc-toasts';
		toastContainer.id = 'bc-toasts';
		document.body.appendChild(toastContainer);
		return toastContainer;
	}

	function announce(message, kind) {
		const k = kind === 'error' ? 'error' : (kind === 'warning' ? 'warning' : 'success');
		// Live regions: polite for info, assertive for errors. Two regions keep
		// success and error announcements semantically separate.
		const polite = document.getElementById('bc-live-region');
		const assertive = document.getElementById('bc-alert-region');
		const target = (k === 'error' ? assertive : polite);
		if (target) {
			target.textContent = '';
			window.setTimeout(() => { target.textContent = String(message); }, 10);
		}
		const container = ensureToastContainer();
		const toast = document.createElement('div');
		toast.className = 'bc-toast bc-toast--' + k;
		toast.setAttribute('role', k === 'error' ? 'alert' : 'status');
		const text = document.createElement('span');
		text.textContent = String(message);
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'bc-toast__close';
		close.setAttribute('aria-label', t('budgetcheck', 'Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', () => toast.remove());
		toast.appendChild(text);
		toast.appendChild(close);
		container.appendChild(toast);
		window.setTimeout(() => {
			if (toast.parentNode) toast.parentNode.removeChild(toast);
		}, k === 'error' ? 7000 : 4000);
	}

	function handleApiError(err, options) {
		const status = Number((err && err.status) || 0);
		const code = err && err.code ? String(err.code) : null;
		const message = String((err && err.message) || t('budgetcheck', 'Request failed.'));
		if (status === 401) {
			announce(t('budgetcheck', 'Your session expired. Please reload and sign in again.'), 'error');
			return;
		}
		if (status === 403 || code === 'access_denied') {
			announce(t('budgetcheck', 'You are not authorized to perform that action.'), 'error');
			return;
		}
		if (status === 429 || code === 'rate_limit_exceeded') {
			announce(t('budgetcheck', 'Too many requests. Please wait and retry.'), 'warning');
			return;
		}
		if (status === 409 || code === 'version_conflict') {
			announce(t('budgetcheck', 'Someone else changed this entry. Reloading…'), 'warning');
			if (!options || options.reloadOnConflict !== false) {
				window.setTimeout(() => window.location.reload(), 600);
			}
			return;
		}
		if (status === 422 && code === 'NOT_APPLICABLE_FOR_WORKSPACE_TYPE') {
			announce(t('budgetcheck', 'This action does not apply to this workspace type.'), 'warning');
			return;
		}
		announce(message, 'error');
	}

	window.BudgetCheckMessaging = { announce, handleApiError };
})();
