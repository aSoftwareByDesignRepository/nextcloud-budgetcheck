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
		// Dedup on kind+text with timer reset: an identical toast already
		// showing gets its dismiss timer reset instead of stacking duplicates
		// (rapid retries, repeated API failures).
		const dedupKey = k + '|' + String(message);
		// The key is tracked both as an attribute (probe/debug visibility) and
		// as a JS property so the dedup still works in minimal DOM shims.
		const existing = typeof container.querySelectorAll === 'function'
			? container.querySelectorAll('.bc-toast[data-bc-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (const prev of existing) {
			const prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-bc-toast-key')
				: prev._bcToastKey;
			if (prevKey === dedupKey || prev._bcToastKey === dedupKey) {
				if (prev._bcDismissTimer && typeof window.clearTimeout === 'function') {
					window.clearTimeout(prev._bcDismissTimer);
				}
				prev._bcDismissTimer = window.setTimeout(() => {
					if (prev.parentNode) prev.parentNode.removeChild(prev);
				}, k === 'error' ? 7000 : 4000);
				return;
			}
		}
		const toast = document.createElement('div');
		toast.className = 'bc-toast bc-toast--' + k;
		toast.setAttribute('role', k === 'error' ? 'alert' : 'status');
		toast.setAttribute('data-bc-toast-key', dedupKey);
		toast._bcToastKey = dedupKey;
		const content = document.createElement('span');
		content.className = 'bc-toast__content';
		const text = document.createElement('span');
		text.className = 'bc-toast__text';
		text.textContent = String(message);
		content.appendChild(text);
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'bc-toast__close';
		close.setAttribute('aria-label', t('budgetcheck', 'Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', () => toast.remove());
		toast.appendChild(content);
		toast.appendChild(close);
		if (k === 'error') {
			// Family contract (_shared/app-feedback): error toasts offer a
			// "Report this problem" mailto. The shared showToast/showError
			// wrapper never sees bc-toast surfaces (announce() is the API),
			// so attach the link directly here.
			try {
				const feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					const link = document.createElement('a');
					link.className = 'bc-nav-footer__toast-link';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('budgetcheck', 'Report this problem');
					content.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		container.appendChild(toast);
		toast._bcDismissTimer = window.setTimeout(() => {
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
		// Closed-month write rejections: keep the server message (already plain language)
		// and nudge managers toward reopen without a hard reload that loses context.
		const lower = message.toLowerCase();
		if (lower.indexOf('closed month') !== -1 || lower.indexOf('month is closed') !== -1) {
			announce(message, 'warning');
			return;
		}
		// NC-core envelopes (e.g. 412 "CSRF check failed" after session loss)
		// are technical English — surface the session-expired copy instead.
		if (status === 412) {
			announce(t('budgetcheck', 'Your session expired. Please reload and sign in again.'), 'error');
			return;
		}
		// Web server rejected the request body before PHP could parse it
		// (client_max_body_size / LimitRequestBody / WAF) — the app never saw
		// the upload, so a generic retry message would be a dead end.
		if (status === 413) {
			announce(t('budgetcheck', 'The file exceeds the maximum upload size configured on this server.'), 'error');
			return;
		}
		// 'invalid_input' 400s carry an authored, user-actionable server
		// message (fixed strings — never HTML or stack data). Route it
		// through t() so msgids shared with the client localize and unknown
		// text stays readable English: a real reason beats a dead-end
		// generic toast.
		if (status === 400 && code === 'invalid_input' && message && message.length <= 300) {
			announce(t('budgetcheck', message), 'error');
			return;
		}
		if (status >= 500) {
			console.warn('BudgetCheck server error:', err);
			announce(t('budgetcheck', 'The server could not complete the request. Please try again.'), 'error');
			return;
		}
		// Unmapped 4xx/unknown statuses: never echo raw server text into the
		// assertive live region — localized generic copy, details in console.
		console.warn('BudgetCheck unhandled API error:', err);
		announce(t('budgetcheck', 'The action could not be completed. Please try again.'), 'error');
	}

	/**
	 * Field-level error for client-side validation — parity with server
	 * `fields` maps. Renders the inline .bc-field-error + aria-invalid via
	 * CheckFieldErrors (which also focuses the offender) AND announces on the
	 * assertive live region. `field` may be a control name, a [data-field]
	 * key, or an element id — the shared renderer resolves all three.
	 */
	function fieldError(field, message) {
		const msg = String(message);
		try {
			if (window.CheckFieldErrors && typeof window.CheckFieldErrors.markValidationFields === 'function') {
				const fields = {};
				fields[String(field)] = msg;
				window.CheckFieldErrors.markValidationFields(fields);
			}
		} catch (e) { /* marking must never break the error path */ }
		announce(msg, 'error');
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — Messaging cannot register');
	}
	window.BudgetCheck.define('Messaging', { announce, fieldError, handleApiError });
})();
