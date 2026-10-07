(function () {
	'use strict';

	// Centralized JSON API client.
	//
	// - Always sends the CSRF token (`requesttoken`) on mutations.
	// - Same-origin credentials, JSON content-type, JSON parsing.
	// - Normalises errors with `error.status`, `error.code`, and `error.payload`.
	// - The router prefixes are based on /apps/budgetcheck/* (already configured
	//   via OC.generateUrl) — paths must start with `/`.

	const MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

	// Field-level validation errors (canonical apps/_shared/field-errors):
	// server VALIDATION payloads carry `error.fields` ({fieldKey: message});
	// each named control gets aria-invalid + an inline .bc-field-error.
	if (window.CheckFieldErrors && typeof window.CheckFieldErrors.install === 'function') {
		try {
			window.CheckFieldErrors.install({ prefix: 'bc' });
		} catch (_e) { /* never break the API client */ }
	}

	function markFieldErrors(data) {
		try {
			const fields = data && data.error && typeof data.error === 'object'
				? data.error.fields
				: null;
			if (fields && typeof fields === 'object'
				&& window.CheckFieldErrors
				&& typeof window.CheckFieldErrors.markValidationFields === 'function') {
				// Server field messages are authored msgids — run them through
				// t() so they localize like the top-level message does.
				const localized = {};
				Object.keys(fields).forEach((key) => {
					const msg = String(fields[key] || '');
					localized[key] = msg === '' ? msg : t('budgetcheck', msg);
				});
				window.CheckFieldErrors.markValidationFields(localized);
				attachProjectWindowRemedy(localized);
			}
		} catch (_e) { /* never mask the real error path */ }
	}

	// The project-window rejection is the most common actionable 400: the
	// field error names the constraint, this link is the remedy — it opens
	// the project-period fields under Workspace settings in a new tab so the
	// pending form state survives (reporter workflow: extend the window,
	// then retry the save).
	function attachProjectWindowRemedy(fields) {
		if (!Object.prototype.hasOwnProperty.call(fields, 'bookingDate')) {
			return;
		}
		const Ws = window.BudgetCheckWorkspace;
		const section = Ws && Ws.urls && Ws.urls.settingsSections
			? Ws.urls.settingsSections.workspace : null;
		if (!section || typeof Ws.withWorkspace !== 'function') {
			return;
		}
		const errEl = document.getElementById('bc-field-error-bookingDate');
		if (!errEl || errEl.querySelector('a[data-bc-remedy]')) {
			return;
		}
		const link = document.createElement('a');
		link.href = Ws.withWorkspace(String(section));
		link.className = 'bc-field-error__link';
		link.setAttribute('data-bc-remedy', 'project-window');
		link.setAttribute('target', '_blank');
		link.setAttribute('rel', 'noopener');
		link.textContent = ' ' + t('budgetcheck', 'Open workspace settings');
		errEl.appendChild(link);
	}

	function csrfToken() {
		if (window.OC && OC.requestToken) {
			return OC.requestToken;
		}
		const head = document.querySelector('head');
		const fromHead = head ? head.getAttribute('data-requesttoken') : '';
		if (fromHead) {
			return fromHead;
		}
		const input = document.querySelector('input[name="requesttoken"]');
		return input ? input.value : '';
	}

	function buildUrl(path, params) {
		const built = OC.generateUrl(path);
		const query = new URLSearchParams();
		Object.entries(params || {}).forEach(([key, value]) => {
			if (value === undefined || value === null || value === '') {
				return;
			}
			if (Array.isArray(value)) {
				value.forEach((entry) => query.append(key + '[]', String(entry)));
			} else {
				query.append(key, String(value));
			}
		});
		const suffix = query.toString();
		return suffix ? `${built}?${suffix}` : built;
	}

	async function request(path, options) {
		const opts = options || {};
		const method = (opts.method || 'GET').toUpperCase();
		const isMutation = MUTATION_METHODS.has(method);
		const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
		if (isMutation) {
			const token = csrfToken();
			if (!token) {
				throw new Error(t('budgetcheck', 'Missing CSRF request token.'));
			}
			headers.requesttoken = token;
		}
		if (opts.body !== undefined) {
			headers['Content-Type'] = 'application/json';
		}
		let response;
		try {
			response = await fetch(buildUrl(path, opts.params), {
				method,
				credentials: 'same-origin',
				headers,
				body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('budgetcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		const isJson = (response.headers.get('content-type') || '').toLowerCase().includes('application/json');
		const data = isJson ? await response.json().catch(() => null) : await response.text();
		if (!response.ok) {
			markFieldErrors(data);
			const err = new Error(
				(data && typeof data === 'object' && data.message)
					? String(data.message)
					: t('budgetcheck', 'Request failed.')
			);
			err.status = response.status;
			err.payload = data;
			err.code = (data && data.error && data.error.code) || null;
			throw err;
		}
		return data;
	}

	/**
	 * GET binary responses (exports). Returns the raw Response so callers can read blobs
	 * and Content-Disposition headers.
	 */
	async function download(path, params, options) {
		const opts = options || {};
		const headers = Object.assign({}, opts.headers || {});
		let response;
		try {
			response = await fetch(buildUrl(path, params), {
				method: 'GET',
				credentials: 'same-origin',
				headers,
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('budgetcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		if (!response.ok) {
			const message = await response.text().catch(() => '');
			const err = new Error(message || t('budgetcheck', 'Request failed.'));
			err.status = response.status;
			throw err;
		}
		return response;
	}

	/**
	 * GET an already-resolved same-origin media URL (attachment previews/blobs).
	 * Unlike download(), the URL is used as-is — callers resolved it via
	 * OC.generateUrl already. Returns the raw Response for blob()/text() reads.
	 */
	async function media(url, options) {
		const opts = options || {};
		let response;
		try {
			response = await fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: opts.headers || {},
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('budgetcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		return response;
	}

	/**
	 * POST multipart/form-data (file uploads). Do not set Content-Type — the browser
	 * adds the boundary. CSRF token is always sent.
	 */
	async function upload(path, formData, options) {
		const opts = options || {};
		if (!(formData instanceof FormData)) {
			throw new Error('upload() expects FormData.');
		}
		const token = csrfToken();
		if (!token) {
			throw new Error(t('budgetcheck', 'Missing CSRF request token.'));
		}
		const headers = Object.assign({ Accept: 'application/json', requesttoken: token }, opts.headers || {});
		let response;
		try {
			response = await fetch(buildUrl(path, opts.params), {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: formData,
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('budgetcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		const isJson = (response.headers.get('content-type') || '').toLowerCase().includes('application/json');
		const data = isJson ? await response.json().catch(() => null) : await response.text();
		if (!response.ok) {
			markFieldErrors(data);
			const err = new Error(
				(data && typeof data === 'object' && data.message)
					? String(data.message)
					: t('budgetcheck', 'Request failed.')
			);
			err.status = response.status;
			err.payload = data;
			err.code = (data && data.error && data.error.code) || null;
			throw err;
		}
		return data;
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — Api cannot register');
	}
	window.BudgetCheck.define('Api', {
		get: (path, params, options) => request(path, Object.assign({}, options || {}, { method: 'GET', params })),
		post: (path, body, options) => request(path, Object.assign({}, options || {}, { method: 'POST', body })),
		put: (path, body, options) => request(path, Object.assign({}, options || {}, { method: 'PUT', body })),
		del: (path, body, options) => request(path, Object.assign({}, options || {}, { method: 'DELETE', body })),
		download,
		media,
		upload,
		request,
	});
})();
