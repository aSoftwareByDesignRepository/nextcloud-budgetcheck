/**
 * Web receipt AI suggest — Bachus UX: checking → review → save / edit / fallback.
 * Suggest never auto-commits. Overlay mounts inside the transaction modal dialog.
 */
(function () {
	'use strict';

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — ReceiptSuggest cannot register');
	}

	const BC = (typeof window.BudgetCheck.live === 'function')
		? window.BudgetCheck.live(['Api', 'Messaging', 'Components', 'Money', 'Workspace'])
		: null;

	const POLL_MS = 1200;
	const TIMEOUT_MS = 90000;
	const CONFIDENCE_SINGLE_MIN = 0.72;
	const CONFIDENCE_SPLIT_LINE_MIN = 0.78;

	/** @type {{receiptSuggest?: boolean, receiptSuggestModes?: string[], loaded?: boolean}|null} */
	let capabilityCache = null;
	/** @type {Promise<boolean>|null} */
	let capabilityPromise = null;

	function t(key) {
		return window.t('budgetcheck', key);
	}

	function announce(msg) {
		const live = document.getElementById('bc-live-region');
		if (live) {
			live.textContent = '';
			requestAnimationFrame(() => {
				live.textContent = msg;
			});
		}
	}

	async function refreshCapability() {
		if (!BC || !BC.Api) return false;
		try {
			const data = await BC.Api.get('/apps/budgetcheck/api/workspaces');
			const caps = data.capabilities || {};
			capabilityCache = {
				receiptSuggest: caps.receiptSuggest === true,
				receiptSuggestModes: Array.isArray(caps.receiptSuggestModes) ? caps.receiptSuggestModes : [],
				loaded: true,
			};
			return capabilityCache.receiptSuggest === true;
		} catch (_) {
			capabilityCache = { receiptSuggest: false, receiptSuggestModes: [], loaded: true };
			return false;
		}
	}

	function isEnabled() {
		if (capabilityCache && capabilityCache.loaded) {
			return capabilityCache.receiptSuggest === true;
		}
		if (!capabilityPromise) {
			capabilityPromise = refreshCapability().finally(() => {
				capabilityPromise = null;
			});
		}
		return false;
	}

	function ensureEnabled() {
		if (capabilityCache && capabilityCache.loaded) {
			return Promise.resolve(capabilityCache.receiptSuggest === true);
		}
		return refreshCapability();
	}

	function isSuggestableFile(file) {
		if (!file || typeof file.type !== 'string') return false;
		return file.type.startsWith('image/') || file.type === 'application/pdf';
	}

	function passesClientGate(suggestion, allowedCategoryIds, currencyCode) {
		if (!suggestion || suggestion.status !== 'ready') return false;
		const lines = Array.isArray(suggestion.lines) ? suggestion.lines : [];
		if (lines.length === 0) return false;
		const allowed = new Set((allowedCategoryIds || []).filter((id) => Number.isInteger(id) && id > 0));
		if (allowed.size === 0) return false;
		const currency = String(suggestion.currencyCode || '').toUpperCase();
		if (currency && currency !== String(currencyCode || '').toUpperCase()) return false;
		let sum = 0;
		for (const line of lines) {
			const catId = Number(line.categoryId);
			const amount = Number(line.amountMinor);
			const conf = Number(line.confidence);
			if (!allowed.has(catId) || !Number.isInteger(amount) || amount < 1) return false;
			if (!(conf >= 0 && conf <= 1)) return false;
			sum += amount;
		}
		const total = Number(suggestion.totalMinor);
		if (!Number.isInteger(total) || total < 1 || sum !== total) return false;
		if (lines.length === 1) return Number(lines[0].confidence) >= CONFIDENCE_SINGLE_MIN;
		return lines.every((l) => Number(l.confidence) >= CONFIDENCE_SPLIT_LINE_MIN);
	}

	function sleep(ms, signal) {
		return new Promise((resolve, reject) => {
			if (signal && signal.aborted) {
				reject(Object.assign(new Error('Aborted'), { name: 'AbortError' }));
				return;
			}
			const id = setTimeout(resolve, ms);
			if (signal) {
				signal.addEventListener('abort', () => {
					clearTimeout(id);
					reject(Object.assign(new Error('Aborted'), { name: 'AbortError' }));
				}, { once: true });
			}
		});
	}

	async function pollUntilDone(workspaceId, jobId, signal) {
		const started = Date.now();
		while (Date.now() - started < TIMEOUT_MS) {
			if (signal && signal.aborted) {
				throw Object.assign(new Error('Aborted'), { name: 'AbortError' });
			}
			const payload = await BC.Api.get(
				'/apps/budgetcheck/api/workspaces/' + workspaceId + '/receipt-suggestions/' + jobId
			);
			if (payload.status !== 'pending') {
				return payload;
			}
			await sleep(POLL_MS, signal);
		}
		return { jobId: jobId, status: 'failed', reasons: ['timeout'] };
	}

	/**
	 * @param {{
	 *   host: HTMLElement,
	 *   workspaceId: number,
	 *   file: File,
	 *   categories: Array<{id:number,name:string,type?:string,isActive?:boolean}>,
	 *   currencyCode: string,
	 *   htmlLang?: string,
	 *   onAccepted: function(number): void,
	 *   onEdit: function(object): void,
	 *   onDismiss: function(): void,
	 * }} opts
	 */
	function mountOverlay(opts) {
		if (!BC || !BC.Components || !BC.Api || !BC.Money) {
			opts.onDismiss();
			return { destroy: function () {} };
		}
		const host = opts.host;
		const workspaceId = opts.workspaceId;
		let jobId = null;
		let suggestion = null;
		let destroyed = false;
		const ac = new AbortController();

		const overlay = BC.Components.createElement('div', {
			class: 'bc-receipt-suggest',
			attrs: { role: 'region', 'aria-label': t('Receipt suggestion') },
		});
		const panel = BC.Components.createElement('div', { class: 'bc-receipt-suggest__panel' });
		overlay.appendChild(panel);
		host.appendChild(overlay);

		function setPanel(nodes) {
			while (panel.firstChild) panel.removeChild(panel.firstChild);
			(nodes || []).forEach((n) => panel.appendChild(n));
		}

		function categoryName(id) {
			const cat = (opts.categories || []).find((c) => Number(c.id) === Number(id));
			return cat ? String(cat.name) : t('Category');
		}

		async function cancelRemote() {
			if (jobId == null) return;
			const id = jobId;
			jobId = null;
			try {
				await BC.Api.del('/apps/budgetcheck/api/workspaces/' + workspaceId + '/receipt-suggestions/' + id);
			} catch (_) { /* ignore */ }
		}

		function destroy() {
			if (destroyed) return;
			destroyed = true;
			ac.abort();
			if (jobId != null) {
				void cancelRemote();
			}
			if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
		}

		function renderChecking() {
			announce(t('Checking your receipt…'));
			setPanel([
				BC.Components.createElement('div', { class: 'bc-receipt-suggest__center', attrs: { 'aria-live': 'polite' } }, [
					BC.Components.createElement('p', { class: 'bc-receipt-suggest__title', text: t('Checking your receipt…') }),
					BC.Components.createElement('p', {
						class: 'bc-receipt-suggest__hint',
						text: t('This usually takes a few seconds. You can cancel anytime.'),
					}),
					BC.Components.createElement('button', {
						type: 'button',
						class: 'button',
						text: t('Cancel'),
						on: {
							click: async () => {
								await cancelRemote();
								destroy();
								opts.onDismiss();
							},
						},
					}),
				]),
			]);
		}

		function renderFallback(message) {
			announce(message || t('Could not read this clearly'));
			setPanel([
				BC.Components.createElement('div', { class: 'bc-receipt-suggest__center' }, [
					BC.Components.createElement('p', {
						class: 'bc-receipt-suggest__title',
						text: t('Could not read this clearly'),
					}),
					BC.Components.createElement('p', {
						class: 'bc-receipt-suggest__hint',
						text: message || t('No problem — enter the booking yourself. Your receipt stays attached.'),
					}),
					BC.Components.createElement('button', {
						type: 'button',
						class: 'primary',
						text: t('Enter manually'),
						on: {
							click: async () => {
								await cancelRemote();
								destroy();
								opts.onEdit(suggestion || { status: 'low_quality' });
							},
						},
					}),
					BC.Components.createElement('button', {
						type: 'button',
						class: 'button',
						text: t('Cancel'),
						on: {
							click: async () => {
								await cancelRemote();
								destroy();
								opts.onDismiss();
							},
						},
					}),
				]),
			]);
		}

		function renderReview(payload) {
			announce(t('Does this look right?'));
			suggestion = payload;
			const amountText = BC.Money.formatMinor(
				Number(payload.totalMinor) || 0,
				opts.currencyCode,
				opts.htmlLang || 'en'
			);
			const titleText = payload.title || payload.merchant || t('Receipt');
			const children = [
				BC.Components.createElement('p', { class: 'bc-receipt-suggest__title', text: t('Does this look right?') }),
				BC.Components.createElement('p', {
					class: 'bc-receipt-suggest__hint',
					text: t('Save to book it, or edit the fields yourself.'),
				}),
				BC.Components.createElement('p', {
					class: 'bc-receipt-suggest__amount',
					text: amountText,
				}),
				BC.Components.createElement('p', { class: 'bc-receipt-suggest__subtitle', text: titleText }),
			];
			if (payload.bookingDate) {
				children.push(BC.Components.createElement('p', {
					class: 'bc-receipt-suggest__meta',
					text: String(payload.bookingDate),
				}));
			}
			const lines = Array.isArray(payload.lines) ? payload.lines : [];
			if (payload.mode === 'split' && lines.length >= 2) {
				const list = BC.Components.createElement('ul', {
					class: 'bc-receipt-suggest__lines',
					attrs: { 'aria-label': t('Split into several bookings') },
				});
				lines.forEach((line) => {
					list.appendChild(BC.Components.createElement('li', { class: 'bc-receipt-suggest__line' }, [
						BC.Components.createElement('span', {}, [
							BC.Components.createElement('strong', { text: String(line.label || '') }),
							BC.Components.createElement('span', {
								class: 'bc-receipt-suggest__meta',
								text: categoryName(line.categoryId),
							}),
						]),
						BC.Components.createElement('span', {
							text: BC.Money.formatMinor(Number(line.amountMinor) || 0, opts.currencyCode, opts.htmlLang || 'en'),
						}),
					]));
				});
				children.push(list);
			} else if (lines[0]) {
				children.push(BC.Components.createElement('p', {
					class: 'bc-receipt-suggest__meta',
					text: categoryName(lines[0].categoryId),
				}));
			}

			const errorEl = BC.Components.createElement('p', {
				class: 'bc-receipt-suggest__error',
				attrs: { role: 'alert', hidden: true },
			});
			children.push(errorEl);

			const saveBtn = BC.Components.createElement('button', {
				type: 'button',
				class: 'primary',
				text: t('Save'),
			});
			saveBtn.addEventListener('click', async () => {
				saveBtn.disabled = true;
				try {
					const result = await BC.Api.post(
						'/apps/budgetcheck/api/workspaces/' + workspaceId + '/receipt-suggestions/' + jobId + '/accept',
						{ suggestion: payload }
					);
					const count = Array.isArray(result.transactions) ? result.transactions.length : 1;
					jobId = null;
					destroy();
					opts.onAccepted(count);
				} catch (err) {
					errorEl.hidden = false;
					errorEl.textContent = t('Could not save the suggestion. Try again or enter manually.');
					saveBtn.disabled = false;
					if (BC.Messaging && typeof BC.Messaging.handleApiError === 'function') {
						BC.Messaging.handleApiError(err);
					}
				}
			});

			children.push(saveBtn);
			children.push(BC.Components.createElement('button', {
				type: 'button',
				class: 'button',
				text: t('Edit fields'),
				on: {
					click: async () => {
						await cancelRemote();
						destroy();
						opts.onEdit(payload);
					},
				},
			}));
			children.push(BC.Components.createElement('button', {
				type: 'button',
				class: 'button button--flat',
				text: t('Cancel'),
				on: {
					click: async () => {
						await cancelRemote();
						destroy();
						opts.onDismiss();
					},
				},
			}));

			setPanel([BC.Components.createElement('div', { class: 'bc-receipt-suggest__review' }, children)]);
		}

		async function run() {
			renderChecking();
			try {
				const form = new FormData();
				form.append('file', opts.file, opts.file.name || 'receipt.jpg');
				const started = await BC.Api.upload(
					'/apps/budgetcheck/api/workspaces/' + workspaceId + '/receipt-suggestions',
					form,
					{ signal: ac.signal }
				);
				jobId = Number(started.jobId);
				if (!Number.isInteger(jobId) || jobId < 1) {
					renderFallback(t('Receipt check did not work. Enter the booking yourself.'));
					return;
				}
				const result = await pollUntilDone(workspaceId, jobId, ac.signal);
				if (destroyed) return;
				const allowed = (opts.categories || [])
					.filter((c) => c.isActive !== false && (c.type === 'expense' || c.type === 'both' || !c.type))
					.map((c) => Number(c.id));
				if (passesClientGate(result, allowed, opts.currencyCode)) {
					renderReview(result);
				} else {
					suggestion = result;
					renderFallback();
				}
			} catch (err) {
				if (destroyed || (err && err.name === 'AbortError')) return;
				renderFallback(t('Receipt check did not work. Enter the booking yourself.'));
			}
		}

		void run();
		return { destroy: destroy };
	}

	window.BudgetCheck.define('ReceiptSuggest', {
		isEnabled: isEnabled,
		ensureEnabled: ensureEnabled,
		isSuggestableFile: isSuggestableFile,
		passesClientGate: passesClientGate,
		mountOverlay: mountOverlay,
		refreshCapability: refreshCapability,
		/**
		 * Test/E2E seam: force capability cache without hitting the API.
		 * Production UI never calls this.
		 * @param {boolean} enabled
		 * @param {string[]} [modes]
		 */
		forceCapabilityForTests: function (enabled, modes) {
			capabilityCache = {
				receiptSuggest: enabled === true,
				receiptSuggestModes: Array.isArray(modes) ? modes : (enabled ? ['analyze-images'] : []),
				loaded: true,
			};
		},
		CONFIDENCE_SINGLE_MIN: CONFIDENCE_SINGLE_MIN,
		CONFIDENCE_SPLIT_LINE_MIN: CONFIDENCE_SPLIT_LINE_MIN,
	});
}());
