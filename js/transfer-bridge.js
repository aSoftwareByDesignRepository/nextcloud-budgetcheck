/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.BudgetCheckApi / window.BudgetCheckMessaging contract
 * the synced js/transfer.js expects, in BudgetCheck's own API envelope
 * ({success:true,data} / {success:false,error,context}). App-specific —
 * not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.BudgetCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/budgetcheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	window.BudgetCheckMessaging = {
		alert(text) {
			if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(text);
			}
			const el = document.getElementById('bc-transfer-result-live');
			if (el) el.textContent = text;
		},
		polite(text) { this.alert(text); },
	};
})();
