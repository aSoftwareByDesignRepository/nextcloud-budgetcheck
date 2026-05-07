/*
 * BudgetCheck — Transactions ("Ledger") page controller.
 *
 * Production-grade refactor (Aristoteles, 2026-05).
 *
 * Responsibilities:
 *   - Hydrate filter state from the URL query string (deep-link friendly).
 *   - Drive the filter bar (search, quick range presets, "more filters" disclosure,
 *     active chips, clear-all, reset).
 *   - Fetch /api/transactions with AbortController so stale requests can never
 *     overwrite a fresh result, and a 250 ms debounce on free-text typing.
 *   - Render: KPI strip, ledger rows (responsive cards <640 px), pagination,
 *     analytics breakdowns (group / category / month) inside a tabbed disclosure.
 *   - Surface real loading / empty / error states (not italic "Loading…" text).
 *   - Announce result changes through the polite live region.
 *   - Manage focus: kebab-menu open/close, modal restore on close, restore on
 *     URL/popstate transitions.
 *
 * Security & correctness notes:
 *   - All values rendered via textContent / setAttribute (never innerHTML).
 *   - URL state is whitelisted before being applied (numeric IDs, ISO dates,
 *     known boolean flags, range presets in {fixed list}); anything else is
 *     dropped to keep the page reproducible from any URL.
 *   - Mutations still go through Api.post/put/del which set the CSRF token.
 *   - Per-request Abort + monotonic ticket guard discards out-of-order responses.
 */
(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const PAGE_SIZE = 50;
	const SEARCH_DEBOUNCE_MS = 250;

	// Whitelist of range preset keys. Anything else falls back to 'all'.
	const RANGE_PRESETS = new Set(['all', 'thisMonth', 'lastMonth', 'last30', 'ytd', 'last12', 'custom']);

	const state = {
		filters: emptyFilters(),
		offset: 0,
		categories: [],
		categoryById: new Map(),
		statuses: [],
		statusById: new Map(),
		lastResponse: null,
	};

	// Concurrency guard.
	let activeAbort = null;
	let requestTicket = 0;
	let searchDebounceTimer = null;

	// Open kebab menu reference (only one at a time).
	let openMenu = null;

	function emptyFilters() {
		return {
			from: '',
			to: '',
			rangePreset: 'all',
			categoryId: '',
			groupKey: '',
			statusId: '',
			q: '',
			isSpecial: false,
			uncategorized: false,
		};
	}

	function activeDecimals() {
		const w = Ws.workspace;
		if (!w) return 2;
		return typeof w.currencyDecimals === 'number' ? w.currencyDecimals : (w.currencyCode === 'JPY' ? 0 : 2);
	}

	function tableColumnCount() {
		// Date, Title, Category, Amount, Tags = 5; +Status (project), +Actions (contributor).
		const isProject = Ws.workspace && Ws.workspace.type === 'project';
		return 5 + (isProject ? 1 : 0) + (Ws.canContribute ? 1 : 0);
	}

	function pad2(n) { return n < 10 ? '0' + n : String(n); }

	function isoFromDate(date) {
		return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
	}

	function lastDayOfMonth(year, monthOneBased) {
		return new Date(year, monthOneBased, 0).getDate();
	}

	// Compute a {from, to} pair for a known range preset using LOCAL calendar fields,
	// so users see the same dates that were typed into the inputs.
	function datesForPreset(preset) {
		const now = new Date();
		const y = now.getFullYear();
		const m = now.getMonth(); // 0-based
		const d = now.getDate();
		switch (preset) {
			case 'thisMonth': {
				const from = new Date(y, m, 1);
				const to = new Date(y, m, lastDayOfMonth(y, m + 1));
				return { from: isoFromDate(from), to: isoFromDate(to) };
			}
			case 'lastMonth': {
				const refY = m === 0 ? y - 1 : y;
				const refM = m === 0 ? 11 : m - 1;
				const from = new Date(refY, refM, 1);
				const to = new Date(refY, refM, lastDayOfMonth(refY, refM + 1));
				return { from: isoFromDate(from), to: isoFromDate(to) };
			}
			case 'last30': {
				const to = new Date(y, m, d);
				const from = new Date(y, m, d - 29);
				return { from: isoFromDate(from), to: isoFromDate(to) };
			}
			case 'ytd': {
				const from = new Date(y, 0, 1);
				const to = new Date(y, m, d);
				return { from: isoFromDate(from), to: isoFromDate(to) };
			}
			case 'last12': {
				const to = new Date(y, m, d);
				const from = new Date(y - 1, m, d);
				return { from: isoFromDate(from), to: isoFromDate(to) };
			}
			default:
				return { from: '', to: '' };
		}
	}

	function detectPresetFromDates(fromIso, toIso) {
		// Best-effort: if the (from,to) pair matches one of the known presets exactly
		// (computed at the same wall-clock day), reflect that preset. Otherwise 'custom'
		// for any explicit pair, 'all' for both empty.
		if (!fromIso && !toIso) return 'all';
		for (const key of ['thisMonth', 'lastMonth', 'last30', 'ytd', 'last12']) {
			const r = datesForPreset(key);
			if (r.from === fromIso && r.to === toIso) return key;
		}
		return 'custom';
	}

	// ---------------------------------------------------------------
	//  Bootstrap
	// ---------------------------------------------------------------
	document.addEventListener('DOMContentLoaded', () => {
		if (!Ws.workspace) return;
		hydrateFromUrl();
		wireFilterForm();
		wireMoreToggle();
		wireSearchClear();
		wireRangePreset();
		wireBreakdownsDisclosure();
		wireBreakdownTabs();
		wireCreateButton();
		wireClearAll();
		wirePopstate();
		wireGlobalDismiss();
		loadCategoriesIntoSelect();
		loadStatusesIntoSelect();
		render(); // initial paint of chips + advanced visibility
		loadAndRender();
	});

	// ---------------------------------------------------------------
	//  URL ↔ state
	// ---------------------------------------------------------------
	function hydrateFromUrl() {
		const sp = new URLSearchParams(window.location.search);

		const from = sp.get('from');
		const to = sp.get('to');
		if (from && Dates.isIsoCalendarDay(from)) state.filters.from = from;
		if (to && Dates.isIsoCalendarDay(to)) state.filters.to = to;

		const yearMonth = sp.get('yearMonth');
		if (yearMonth && /^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonth)) {
			const [y, m] = yearMonth.split('-').map(Number);
			state.filters.from = yearMonth + '-01';
			state.filters.to = yearMonth + '-' + pad2(lastDayOfMonth(y, m));
		}

		const filterMode = sp.get('filter');
		if (filterMode === 'special') state.filters.isSpecial = true;
		if (filterMode === 'uncategorized') state.filters.uncategorized = true;

		const categoryIdRaw = sp.get('categoryId');
		if (categoryIdRaw && /^\d+$/.test(categoryIdRaw) && Number.parseInt(categoryIdRaw, 10) > 0) {
			state.filters.categoryId = categoryIdRaw;
		}
		const groupKey = sp.get('groupKey');
		if (typeof groupKey === 'string' && groupKey.length > 0 && groupKey.length <= 80) {
			state.filters.groupKey = groupKey;
		}
		const statusIdRaw = sp.get('statusId');
		if (statusIdRaw && /^\d+$/.test(statusIdRaw) && Number.parseInt(statusIdRaw, 10) > 0) {
			state.filters.statusId = statusIdRaw;
		}
		const q = sp.get('q');
		if (typeof q === 'string') {
			state.filters.q = q.slice(0, 200); // hard cap to prevent absurd URLs
		}
		if (sp.get('isSpecial') === '1') state.filters.isSpecial = true;
		if (sp.get('uncategorized') === '1') state.filters.uncategorized = true;

		const presetRaw = sp.get('rangePreset');
		state.filters.rangePreset = (presetRaw && RANGE_PRESETS.has(presetRaw))
			? presetRaw
			: detectPresetFromDates(state.filters.from, state.filters.to);

		const offsetRaw = sp.get('offset');
		if (offsetRaw && /^\d+$/.test(offsetRaw)) {
			state.offset = Math.min(100000, Number.parseInt(offsetRaw, 10));
		}
	}

	function syncUrlFromState() {
		// `workspaceId` is required by the page; preserve any other unknown params untouched.
		const sp = new URLSearchParams(window.location.search);
		const drop = ['from', 'to', 'yearMonth', 'filter', 'categoryId', 'groupKey', 'statusId', 'q', 'isSpecial', 'uncategorized', 'rangePreset', 'offset'];
		drop.forEach((k) => sp.delete(k));
		const f = state.filters;
		if (f.from) sp.set('from', f.from);
		if (f.to) sp.set('to', f.to);
		if (f.categoryId) sp.set('categoryId', f.categoryId);
		if (f.groupKey) sp.set('groupKey', f.groupKey);
		if (f.statusId) sp.set('statusId', f.statusId);
		if (f.q) sp.set('q', f.q);
		if (f.isSpecial) sp.set('isSpecial', '1');
		if (f.uncategorized) sp.set('uncategorized', '1');
		if (f.rangePreset && f.rangePreset !== 'all' && f.rangePreset !== 'custom') {
			sp.set('rangePreset', f.rangePreset);
		}
		if (state.offset > 0) sp.set('offset', String(state.offset));
		const qs = sp.toString();
		const newUrl = window.location.pathname + (qs ? '?' + qs : '');
		try {
			window.history.replaceState({ bcTx: true, filters: { ...f }, offset: state.offset }, '', newUrl);
		} catch (_) {
			/* very old browsers; ignore */
		}
	}

	function wirePopstate() {
		window.addEventListener('popstate', () => {
			state.filters = emptyFilters();
			state.offset = 0;
			hydrateFromUrl();
			syncFormFromState();
			loadAndRender({ skipUrlSync: true });
		});
	}

	// ---------------------------------------------------------------
	//  Filter form wiring
	// ---------------------------------------------------------------
	function wireFilterForm() {
		const form = document.querySelector('[data-bc-tx-filters]');
		if (!form) return;
		syncFormFromState();

		// Submit (Apply): explicit, used inside the More panel.
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			try {
				collectFilters(form);
			} catch (err) {
				Msg.announce(err.message, 'error');
				return;
			}
			state.offset = 0;
			loadAndRender();
		});

		// Reset: clear filters and refetch.
		form.addEventListener('reset', () => {
			window.setTimeout(() => {
				state.filters = emptyFilters();
				state.offset = 0;
				syncFormFromState();
				loadAndRender();
			}, 0);
		});

		// Implicit changes on selects + booleans + dates.
		form.addEventListener('change', (event) => {
			const target = event.target;
			if (!(target instanceof HTMLElement)) return;
			const key = target.getAttribute('data-bc-filter');
			if (!key) return;
			if (key === 'q') return; // search uses input event below
			handleFilterChange(form, key, target);
		});

		// Search input: live, debounced.
		const searchEl = form.querySelector('[data-bc-filter="q"]');
		if (searchEl) {
			searchEl.addEventListener('input', () => {
				toggleSearchClear(searchEl.value);
				if (searchDebounceTimer) window.clearTimeout(searchDebounceTimer);
				searchDebounceTimer = window.setTimeout(() => {
					searchDebounceTimer = null;
					state.filters.q = String(searchEl.value || '').trim();
					state.offset = 0;
					loadAndRender();
				}, SEARCH_DEBOUNCE_MS);
			});
			toggleSearchClear(searchEl.value);
		}
	}

	function handleFilterChange(form, key, target) {
		try {
			collectFilters(form);
		} catch (err) {
			Msg.announce(err.message, 'error');
			return;
		}
		// If user manually edits dates, switch preset to 'custom'; if they clear both, switch to 'all'.
		if (key === 'from' || key === 'to') {
			const f = state.filters;
			state.filters.rangePreset = (!f.from && !f.to) ? 'all' : detectPresetFromDates(f.from, f.to);
			const presetEl = form.querySelector('[data-bc-filter="rangePreset"]');
			if (presetEl) presetEl.value = state.filters.rangePreset;
		}
		state.offset = 0;
		loadAndRender();
	}

	function collectFilters(form) {
		const f = state.filters;
		const fromRaw = (form.querySelector('[data-bc-filter="from"]')?.value || '').trim();
		const toRaw = (form.querySelector('[data-bc-filter="to"]')?.value || '').trim();
		const invalidMsg = t('budgetcheck', 'Invalid calendar date.');
		if (fromRaw !== '' && !Dates.isIsoCalendarDay(fromRaw)) throw new Error(invalidMsg);
		if (toRaw !== '' && !Dates.isIsoCalendarDay(toRaw)) throw new Error(invalidMsg);
		if (fromRaw !== '' && toRaw !== '' && fromRaw > toRaw) {
			throw new Error(t('budgetcheck', '“From” date must be before “To” date.'));
		}
		f.from = fromRaw;
		f.to = toRaw;
		f.categoryId = form.querySelector('[data-bc-filter="categoryId"]')?.value || '';
		f.groupKey = form.querySelector('[data-bc-filter="groupKey"]')?.value || '';
		f.statusId = form.querySelector('[data-bc-filter="statusId"]')?.value || '';
		const qEl = form.querySelector('[data-bc-filter="q"]');
		f.q = qEl ? String(qEl.value || '').trim() : '';
		f.isSpecial = !!form.querySelector('[data-bc-filter="isSpecial"]')?.checked;
		f.uncategorized = !!form.querySelector('[data-bc-filter="uncategorized"]')?.checked;
		const presetEl = form.querySelector('[data-bc-filter="rangePreset"]');
		if (presetEl && RANGE_PRESETS.has(presetEl.value)) f.rangePreset = presetEl.value;
	}

	function syncFormFromState() {
		const form = document.querySelector('[data-bc-tx-filters]');
		if (!form) return;
		const f = state.filters;
		const set = (sel, val, type) => {
			const el = form.querySelector(sel);
			if (!el) return;
			if (type === 'checkbox') el.checked = !!val;
			else el.value = val == null ? '' : String(val);
		};
		set('[data-bc-filter="from"]', f.from);
		set('[data-bc-filter="to"]', f.to);
		set('[data-bc-filter="categoryId"]', f.categoryId);
		set('[data-bc-filter="groupKey"]', f.groupKey);
		set('[data-bc-filter="statusId"]', f.statusId);
		set('[data-bc-filter="q"]', f.q);
		set('[data-bc-filter="isSpecial"]', f.isSpecial, 'checkbox');
		set('[data-bc-filter="uncategorized"]', f.uncategorized, 'checkbox');
		set('[data-bc-filter="rangePreset"]', RANGE_PRESETS.has(f.rangePreset) ? f.rangePreset : 'all');
		toggleSearchClear(f.q);
		updateMoreCount();
	}

	function wireRangePreset() {
		const form = document.querySelector('[data-bc-tx-filters]');
		if (!form) return;
		const sel = form.querySelector('[data-bc-filter="rangePreset"]');
		if (!sel) return;
		sel.addEventListener('change', () => {
			const v = sel.value;
			if (v === 'custom') {
				openMorePanel(true);
				const fromInput = form.querySelector('[data-bc-filter="from"]');
				if (fromInput && typeof fromInput.focus === 'function') fromInput.focus();
				state.filters.rangePreset = 'custom';
				return;
			}
			const range = datesForPreset(v);
			state.filters.rangePreset = RANGE_PRESETS.has(v) ? v : 'all';
			state.filters.from = range.from;
			state.filters.to = range.to;
			state.offset = 0;
			syncFormFromState();
			loadAndRender();
		});
	}

	function wireMoreToggle() {
		const btn = document.querySelector('[data-bc-tx-more-toggle]');
		const panel = document.querySelector('[data-bc-tx-more-panel]');
		if (!btn || !panel) return;
		btn.addEventListener('click', () => {
			const expanded = btn.getAttribute('aria-expanded') === 'true';
			openMorePanel(!expanded);
		});
	}

	function openMorePanel(open) {
		const btn = document.querySelector('[data-bc-tx-more-toggle]');
		const panel = document.querySelector('[data-bc-tx-more-panel]');
		if (!btn || !panel) return;
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		panel.hidden = !open;
	}

	function wireSearchClear() {
		const btn = document.querySelector('[data-bc-search-clear]');
		const input = document.querySelector('#bc-tx-q');
		if (!btn || !input) return;
		btn.addEventListener('click', () => {
			input.value = '';
			toggleSearchClear('');
			state.filters.q = '';
			state.offset = 0;
			loadAndRender();
			input.focus();
		});
	}

	function toggleSearchClear(value) {
		const btn = document.querySelector('[data-bc-search-clear]');
		if (!btn) return;
		btn.hidden = !value || String(value).length === 0;
	}

	function wireClearAll() {
		const btn = document.querySelector('[data-bc-tx-clear-all]');
		if (!btn) return;
		btn.addEventListener('click', () => {
			state.filters = emptyFilters();
			state.offset = 0;
			syncFormFromState();
			loadAndRender();
			const search = document.querySelector('#bc-tx-q');
			if (search) search.focus();
		});
	}

	// ---------------------------------------------------------------
	//  Categories + statuses
	// ---------------------------------------------------------------
	async function loadCategoriesIntoSelect() {
		try {
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id });
			state.categories = data.categories || [];
			state.categoryById = new Map(state.categories.map((c) => [c.id, c]));
			const select = document.querySelector('[data-bc-category-select]');
			const groupSelect = document.querySelector('[data-bc-group-select]');
			const groups = new Set();
			if (select) {
				state.categories.forEach((cat) => {
					const label = cat.name + ' (' + (cat.type === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense')) + ')';
					select.appendChild(C.createElement('option', { value: String(cat.id), text: label }));
					if (cat.groupKey) groups.add(String(cat.groupKey));
				});
				select.value = state.filters.categoryId || '';
			}
			if (groupSelect) {
				Array.from(groups).sort((a, b) => a.localeCompare(b)).forEach((groupKey) => {
					groupSelect.appendChild(C.createElement('option', { value: groupKey, text: groupKey }));
				});
				groupSelect.value = state.filters.groupKey || '';
			}
			renderActiveChips();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function loadStatusesIntoSelect() {
		if (!Ws.workspace || Ws.workspace.type !== 'project') return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/booking-statuses', { workspaceId: Ws.workspace.id });
			state.statuses = data.statuses || [];
			state.statusById = new Map(state.statuses.map((s) => [s.id, s]));
			const select = document.querySelector('[data-bc-status-select]');
			if (select) {
				state.statuses.forEach((status) => {
					select.appendChild(C.createElement('option', { value: String(status.id), text: status.name }));
				});
				select.value = state.filters.statusId || '';
			}
			renderActiveChips();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	// ---------------------------------------------------------------
	//  Main fetch + render
	// ---------------------------------------------------------------
	async function loadAndRender(opts) {
		const options = opts || {};
		if (!options.skipUrlSync) syncUrlFromState();

		showLoadingState();
		renderActiveChips();
		updateMoreCount();

		// Concurrency guard: cancel in-flight + bump ticket so late responses are dropped.
		if (activeAbort) activeAbort.abort();
		const ticket = ++requestTicket;
		const ac = new AbortController();
		activeAbort = ac;

		try {
			const params = {
				workspaceId: Ws.workspace.id,
				limit: PAGE_SIZE,
				offset: state.offset,
			};
			const f = state.filters;
			if (f.from) params.from = f.from;
			if (f.to) params.to = f.to;
			if (f.categoryId) params.categoryId = f.categoryId;
			if (f.groupKey) params.groupKey = f.groupKey;
			if (f.statusId) params.statusId = f.statusId;
			if (f.q) params.q = f.q;
			if (f.isSpecial) params.isSpecial = '1';
			if (f.uncategorized) params.uncategorized = '1';

			const data = await Api.get('/apps/budgetcheck/api/transactions', params, { signal: ac.signal });
			if (ticket !== requestTicket) return; // a fresher request is in flight; drop this one.
			state.lastResponse = data;
			renderResult(data);
		} catch (err) {
			if (ac.signal.aborted) return;
			if (ticket !== requestTicket) return;
			showErrorState(err);
		} finally {
			if (activeAbort === ac) activeAbort = null;
		}
	}

	function showLoadingState() {
		const tbody = document.querySelector('[data-bc-tx-rows]');
		const stateEl = document.querySelector('[data-bc-tx-state]');
		const summary = document.querySelector('[data-bc-tx-summary]');
		const window_ = document.querySelector('[data-bc-tx-window]');
		const count = document.querySelector('[data-bc-tx-count]');
		if (stateEl) { stateEl.hidden = true; stateEl.replaceChildren(); }
		if (summary) summary.textContent = t('budgetcheck', 'Loading…');
		if (window_) window_.textContent = '';
		if (count) count.textContent = '';

		// Skeleton rows (5).
		if (tbody) {
			tbody.replaceChildren();
			for (let i = 0; i < 5; i++) tbody.appendChild(skeletonRow());
		}
		// KPI tiles to skeletons.
		const tiles = document.querySelector('[data-bc-tx-kpi-tiles]');
		if (tiles) {
			tiles.setAttribute('aria-busy', 'true');
			tiles.replaceChildren();
			for (let i = 0; i < 4; i++) {
				tiles.appendChild(C.createElement('div', { class: 'bc-summary-tile bc-tx-kpi-tile bc-tx-kpi-tile--skeleton', attrs: { 'aria-hidden': 'true' } }, [
					C.createElement('div', { class: 'bc-summary-tile__label' }, '\u00A0'),
					C.createElement('div', { class: 'bc-summary-tile__value' }, '\u00A0'),
				]));
			}
		}
	}

	function skeletonRow() {
		const colspan = String(tableColumnCount());
		const td = C.createElement('td', { attrs: { colspan } });
		td.appendChild(C.createElement('span', { class: 'bc-tx-skeleton bc-tx-skeleton--lg', attrs: { style: 'width: ' + (40 + Math.floor(Math.random() * 50)) + '%;' } }));
		const tr = C.createElement('tr', { attrs: { 'aria-hidden': 'true' } }, [td]);
		return tr;
	}

	function showErrorState(err) {
		const tbody = document.querySelector('[data-bc-tx-rows]');
		const stateEl = document.querySelector('[data-bc-tx-state]');
		const summary = document.querySelector('[data-bc-tx-summary]');
		if (tbody) tbody.replaceChildren();
		if (summary) summary.textContent = t('budgetcheck', 'Could not load transactions.');
		// First, surface the error through the existing helper (handles 401/403/409/429 auto).
		Msg.handleApiError(err);
		if (!stateEl) return;
		stateEl.hidden = false;
		const retryBtn = C.createElement('button', { type: 'button', class: 'button primary', text: t('budgetcheck', 'Retry') });
		retryBtn.addEventListener('click', () => loadAndRender());
		stateEl.replaceChildren(
			C.createElement('div', { class: 'bc-tx-ledger__state-icon bc-tx-ledger__state-icon--error', attrs: { 'aria-hidden': 'true' } }, [iconSvg('M22 12c0 5.5-4.5 10-10 10S2 17.5 2 12 6.5 2 12 2s10 4.5 10 10ZM12 7v6M12 17h.01')]),
			C.createElement('h3', { class: 'bc-tx-ledger__state-title', text: t('budgetcheck', 'Could not load transactions.') }),
			C.createElement('p', { class: 'bc-tx-ledger__state-body', text: t('budgetcheck', 'Check your connection and try again. The most recent attempt did not complete.') }),
			C.createElement('div', { class: 'bc-tx-ledger__state-actions' }, [retryBtn]),
		);
		// Hide the table to avoid a flash of stale data underneath.
		const scroll = document.querySelector('.bc-tx-ledger__scroll');
		if (scroll) scroll.hidden = true;
	}

	function renderResult(data) {
		const items = data.items || [];
		const total = Number(data.total || 0);
		const window_ = data.window || {};

		/*
		 * Edge case: the server reports total > 0 but returned no items because
		 * `offset` is beyond the last page (e.g. user paged forward, then a chip
		 * removal narrowed results). Reset to page 1 and refetch instead of
		 * showing a misleading "no results" empty state.
		 */
		if (items.length === 0 && total > 0 && state.offset > 0) {
			state.offset = 0;
			loadAndRender();
			return;
		}

		const stateEl = document.querySelector('[data-bc-tx-state]');
		const scroll = document.querySelector('.bc-tx-ledger__scroll');
		if (stateEl) { stateEl.hidden = true; stateEl.replaceChildren(); }
		if (scroll) scroll.hidden = false;

		// Window subline.
		const windowEl = document.querySelector('[data-bc-tx-window]');
		if (windowEl) {
			if (window_.from && window_.to) {
				windowEl.textContent = t('budgetcheck', 'Period: {from} – {to}')
					.replace('{from}', Dates.formatDisplayDate(window_.from, Ws.htmlLang))
					.replace('{to}', Dates.formatDisplayDate(window_.to, Ws.htmlLang));
			} else {
				windowEl.textContent = t('budgetcheck', 'Period: All time');
			}
		}

		const countEl = document.querySelector('[data-bc-tx-count]');
		if (countEl) countEl.textContent = formatCountLabel(total);

		// KPI strip + breakdowns from analytics.
		renderKpis(data.analytics || null);
		renderBreakdowns(data.analytics || null);

		// Empty state vs. table.
		const tbody = document.querySelector('[data-bc-tx-rows]');
		if (items.length === 0) {
			if (tbody) tbody.replaceChildren();
			renderEmptyState(total);
		} else {
			renderRows(tbody, items);
			renderSummary(total, items.length);
		}

		renderPagination(total);
		announceResult(total);
	}

	function formatCountLabel(total) {
		if (total === 1) return t('budgetcheck', '1 entry');
		return t('budgetcheck', '{count} entries').replace('{count}', String(total));
	}

	function renderSummary(total, pageItems) {
		const summary = document.querySelector('[data-bc-tx-summary]');
		if (!summary) return;
		if (total === 0) {
			summary.textContent = t('budgetcheck', 'No bookings yet.');
			return;
		}
		const start = state.offset + 1;
		const end = Math.min(state.offset + pageItems, total);
		summary.textContent = t('budgetcheck', 'Showing {start}–{end} of {total}')
			.replace('{start}', String(start))
			.replace('{end}', String(end))
			.replace('{total}', String(total));
	}

	function announceResult(total) {
		if (total === 0) {
			Msg.announce(t('budgetcheck', 'No transactions match these filters.'), 'success');
			return;
		}
		// Soft announcement; the polite live region inside page-start handles this.
		const msg = total === 1
			? t('budgetcheck', '1 transaction matches these filters.')
			: t('budgetcheck', '{count} transactions match these filters.').replace('{count}', String(total));
		const polite = document.getElementById('bc-live-region');
		if (polite) {
			polite.textContent = '';
			window.setTimeout(() => { polite.textContent = msg; }, 10);
		}
	}

	// ---------------------------------------------------------------
	//  KPI strip
	// ---------------------------------------------------------------
	function renderKpis(analytics) {
		const tiles = document.querySelector('[data-bc-tx-kpi-tiles]');
		if (!tiles) return;
		const totals = (analytics && analytics.totals) || {};
		const income = totals.income || null;
		const expense = totals.expense || null;
		const net = totals.net || null;
		const count = Number(totals.count || 0);

		const netMinor = net ? Number(net.minor || 0) : 0;
		const netVariant = netMinor > 0 ? 'bc-tx-kpi-tile--net-positive' : (netMinor < 0 ? 'bc-tx-kpi-tile--net-negative' : '');
		const fmt = (env) => env ? Money.formatEnvelope(env, Ws.htmlLang) : '—';

		tiles.setAttribute('aria-busy', 'false');
		tiles.replaceChildren(
			kpiTile({
				modifier: 'bc-tx-kpi-tile--income',
				label: t('budgetcheck', 'Income'),
				value: fmt(income),
			}),
			kpiTile({
				modifier: 'bc-tx-kpi-tile--expense',
				label: t('budgetcheck', 'Expenses'),
				value: fmt(expense),
			}),
			kpiTile({
				modifier: 'bc-tx-kpi-tile--net ' + netVariant,
				primary: true,
				label: t('budgetcheck', 'Net result'),
				value: fmt(net),
				sub: netMinor > 0 ? t('budgetcheck', 'Surplus in this filter window')
					: (netMinor < 0 ? t('budgetcheck', 'Deficit in this filter window') : ''),
			}),
			kpiTile({
				modifier: 'bc-tx-kpi-tile--count',
				label: t('budgetcheck', 'Entries'),
				value: String(count),
				sub: count === 0
					? t('budgetcheck', 'No bookings match these filters.')
					: (count === 1 ? t('budgetcheck', '1 booking in this window.')
						: t('budgetcheck', '{count} bookings in this window.').replace('{count}', String(count))),
			}),
		);
	}

	function kpiTile({ modifier, label, value, sub, primary }) {
		const cls = 'bc-summary-tile bc-tx-kpi-tile ' + (primary ? 'bc-summary-tile--primary ' : '') + (modifier || '');
		const children = [
			C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
			C.createElement('div', { class: 'bc-summary-tile__value', text: value }),
		];
		if (sub) children.push(C.createElement('div', { class: 'bc-tx-kpi-tile__sub', text: sub }));
		return C.createElement('div', { class: cls.trim() }, children);
	}

	// ---------------------------------------------------------------
	//  Ledger rows
	// ---------------------------------------------------------------
	function renderRows(tbody, items) {
		if (!tbody) return;
		tbody.replaceChildren();
		items.forEach((tx) => tbody.appendChild(renderRow(tx)));
	}

	function renderRow(tx) {
		const cat = state.categoryById.get(tx.categoryId) || null;
		const isProject = Ws.workspace && Ws.workspace.type === 'project';
		const tr = C.createElement('tr');

		// Date
		tr.appendChild(C.createElement('td', {
			attrs: { 'data-cell': 'date' },
			text: Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang),
		}));

		// Title (+ optional notes preview)
		const titleTd = C.createElement('td', { attrs: { 'data-cell': 'title' } });
		titleTd.appendChild(C.createElement('span', { class: 'bc-tx-row-title', text: tx.title || '' }));
		if (tx.notes) {
			titleTd.appendChild(C.createElement('span', { class: 'bc-tx-row-notes', text: String(tx.notes).slice(0, 280) }));
		}
		tr.appendChild(titleTd);

		// Category (with group sub)
		const catTd = C.createElement('td', { attrs: { 'data-cell': 'category' } });
		catTd.appendChild(C.createElement('span', { class: 'bc-tx-row-category', text: cat ? cat.name : '#' + tx.categoryId }));
		if (cat && cat.groupKey) {
			catTd.appendChild(C.createElement('span', { class: 'bc-tx-row-group', text: cat.groupKey }));
		}
		tr.appendChild(catTd);

		// Amount
		const amountTd = C.createElement('td', {
			class: 'bc-table__col--num bc-tx-amount--' + tx.direction,
			attrs: { 'data-cell': 'amount' },
		});
		amountTd.appendChild(C.createElement('span', {
			class: 'bc-tx-amount__value',
			text: (tx.direction === 'income' ? '+' : '−') + ' ' + Money.formatEnvelope(tx.amount, Ws.htmlLang),
		}));
		if (tx.entryAmountBasis && tx.entryAmountBasis !== 'simple' && tx.net && tx.vat && tx.gross) {
			amountTd.appendChild(C.createElement('div', {
				class: 'bc-tx-amount__meta',
				text: t('budgetcheck', 'Net {net} · VAT {vat} · Gross {gross}')
					.replace('{net}', Money.formatEnvelope(tx.net, Ws.htmlLang))
					.replace('{vat}', Money.formatEnvelope(tx.vat, Ws.htmlLang))
					.replace('{gross}', Money.formatEnvelope(tx.gross, Ws.htmlLang)),
			}));
		}
		// Screen-reader prefix so amount makes sense out of context (e.g. on mobile cards).
		amountTd.insertBefore(
			C.createElement('span', { class: 'bc-sr-only', text: tx.direction === 'income' ? t('budgetcheck', 'Income:') + ' ' : t('budgetcheck', 'Expense:') + ' ' }),
			amountTd.firstChild,
		);
		tr.appendChild(amountTd);

		// Status (project workspaces only)
		if (isProject) {
			const status = state.statusById.get(tx.bookingStatusId) || null;
			const statusTd = C.createElement('td', { attrs: { 'data-cell': 'status' } });
			if (status) {
				statusTd.appendChild(C.createElement('span', { class: 'bc-tx-row-status', text: status.name }));
			} else {
				statusTd.appendChild(C.createElement('span', { class: 'bc-tx-row-status', text: '—' }));
			}
			tr.appendChild(statusTd);
		}

		// Tags / chips
		const tagsTd = C.createElement('td', { attrs: { 'data-cell': 'tags' } });
		const tagsRow = C.createElement('span', { class: 'bc-tx-row-tags' });
		const tags = collectTagPills(tx);
		if (tags.length === 0) {
			tagsRow.appendChild(document.createTextNode(''));
		} else {
			tags.forEach((tag) => {
				tagsRow.appendChild(C.createElement('span', { class: tag.cls, text: tag.text }));
			});
		}
		tagsTd.appendChild(tagsRow);
		tr.appendChild(tagsTd);

		// Actions kebab menu
		if (Ws.canContribute) {
			const actionsTd = C.createElement('td', { attrs: { 'data-cell': 'actions' } });
			actionsTd.appendChild(buildRowActions(tx));
			tr.appendChild(actionsTd);
		}

		return tr;
	}

	function collectTagPills(tx) {
		const out = [];
		if (tx.isSpecial) out.push({ cls: 'bc-tx-tag-pill bc-tx-tag-pill--special', text: t('budgetcheck', 'Special') });
		if (tx.entryAmountBasis === 'gross') out.push({ cls: 'bc-tx-tag-pill', text: t('budgetcheck', 'Gross') });
		else if (tx.entryAmountBasis === 'net') out.push({ cls: 'bc-tx-tag-pill', text: t('budgetcheck', 'Net') });
		else if (tx.entryAmountBasis && tx.entryAmountBasis !== 'simple') out.push({ cls: 'bc-tx-tag-pill', text: String(tx.entryAmountBasis) });
		if (Number.isInteger(tx.vatRateBp) && tx.vatRateBp > 0) {
			out.push({ cls: 'bc-tx-tag-pill', text: t('budgetcheck', 'VAT {rate}%').replace('{rate}', (tx.vatRateBp / 100).toString()) });
		}
		return out;
	}

	function buildRowActions(tx) {
		const wrap = C.createElement('div', { class: 'bc-tx-actions' });
		const trigger = C.createElement('button', {
			type: 'button',
			class: 'bc-tx-actions__trigger',
			attrs: {
				'aria-haspopup': 'menu',
				'aria-expanded': 'false',
				'aria-label': t('budgetcheck', 'Actions for {title}').replace('{title}', tx.title || ('#' + tx.id)),
			},
		});
		trigger.appendChild(iconSvg('M12 6h.01M12 12h.01M12 18h.01', { stroke: 'currentColor' }));
		const menu = C.createElement('ul', { class: 'bc-tx-actions__menu', attrs: { role: 'menu' }, hidden: true });
		const editBtn = C.createElement('button', {
			type: 'button',
			class: 'bc-tx-actions__item',
			attrs: { role: 'menuitem' },
		});
		editBtn.appendChild(iconSvg('M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5'));
		editBtn.appendChild(C.createElement('span', { text: t('budgetcheck', 'Edit') }));
		editBtn.addEventListener('click', () => { closeOpenMenu(); openEditModal(tx); });
		const delBtn = C.createElement('button', {
			type: 'button',
			class: 'bc-tx-actions__item bc-tx-actions__item--danger',
			attrs: { role: 'menuitem' },
		});
		delBtn.appendChild(iconSvg('M3 6h18 M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6 M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'));
		delBtn.appendChild(C.createElement('span', { text: t('budgetcheck', 'Delete') }));
		delBtn.addEventListener('click', () => { closeOpenMenu(); deleteTransaction(tx); });
		menu.appendChild(C.createElement('li', { attrs: { role: 'none' } }, [editBtn]));
		menu.appendChild(C.createElement('li', { attrs: { role: 'none' } }, [delBtn]));
		trigger.addEventListener('click', (event) => {
			event.stopPropagation();
			toggleMenu(trigger, menu);
		});
		menu.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				closeOpenMenu();
				trigger.focus();
				return;
			}
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));
				const current = items.indexOf(document.activeElement);
				const next = event.key === 'ArrowDown'
					? (current < items.length - 1 ? current + 1 : 0)
					: (current > 0 ? current - 1 : items.length - 1);
				items[next] && items[next].focus();
			}
		});
		wrap.appendChild(trigger);
		wrap.appendChild(menu);
		return wrap;
	}

	function toggleMenu(trigger, menu) {
		if (openMenu && openMenu.menu === menu) {
			closeOpenMenu();
			return;
		}
		closeOpenMenu();
		menu.hidden = false;
		trigger.setAttribute('aria-expanded', 'true');
		openMenu = { trigger, menu };
		const first = menu.querySelector('[role="menuitem"]');
		if (first) first.focus();
	}

	function closeOpenMenu() {
		if (!openMenu) return;
		openMenu.menu.hidden = true;
		openMenu.trigger.setAttribute('aria-expanded', 'false');
		openMenu = null;
	}

	function wireGlobalDismiss() {
		document.addEventListener('click', (event) => {
			if (!openMenu) return;
			if (event.target instanceof Node && (openMenu.menu.contains(event.target) || openMenu.trigger.contains(event.target))) return;
			closeOpenMenu();
		});
	}

	// ---------------------------------------------------------------
	//  Empty state
	// ---------------------------------------------------------------
	function renderEmptyState(totalInWorkspace) {
		const stateEl = document.querySelector('[data-bc-tx-state]');
		const scroll = document.querySelector('.bc-tx-ledger__scroll');
		const summary = document.querySelector('[data-bc-tx-summary]');
		if (summary) summary.textContent = t('budgetcheck', 'No bookings yet.');
		if (!stateEl) return;
		stateEl.hidden = false;
		if (scroll) scroll.hidden = true;

		const noFilters = !hasActiveFilters();

		const icon = C.createElement('div', { class: 'bc-tx-ledger__state-icon', attrs: { 'aria-hidden': 'true' } }, [
			iconSvg('M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z'),
		]);
		const title = C.createElement('h3', {
			class: 'bc-tx-ledger__state-title',
			text: noFilters
				? (Ws.canContribute ? t('budgetcheck', 'Add your first booking') : t('budgetcheck', 'No bookings yet'))
				: t('budgetcheck', 'No bookings match these filters.'),
		});
		const body = C.createElement('p', {
			class: 'bc-tx-ledger__state-body',
			text: noFilters
				? (Ws.canContribute
					? t('budgetcheck', 'Once you add a booking it will appear here. You can edit, tag, or delete entries from this page.')
					: t('budgetcheck', 'Ask a workspace contributor to add bookings. You can still review breakdowns once entries exist.'))
				: t('budgetcheck', 'Try a wider date range, a different category, or clear the filters to see everything.'),
		});
		const actions = C.createElement('div', { class: 'bc-tx-ledger__state-actions' });
		if (noFilters && Ws.canContribute) {
			const newBtn = C.createElement('button', { type: 'button', class: 'button primary', text: t('budgetcheck', 'New transaction') });
			newBtn.addEventListener('click', () => openEditModal(null));
			actions.appendChild(newBtn);
		}
		if (!noFilters) {
			const clear = C.createElement('button', { type: 'button', class: 'button primary', text: t('budgetcheck', 'Clear filters') });
			clear.addEventListener('click', () => {
				state.filters = emptyFilters();
				state.offset = 0;
				syncFormFromState();
				loadAndRender();
			});
			actions.appendChild(clear);
		}
		stateEl.replaceChildren(icon, title, body, actions);

		// Pagination empty.
		const pag = document.querySelector('[data-bc-tx-pagination]');
		if (pag) pag.replaceChildren();
		// Window count subline already cleared / set above.
	}

	function hasActiveFilters() {
		const f = state.filters;
		return !!(f.from || f.to || f.categoryId || f.groupKey || f.statusId || f.q || f.isSpecial || f.uncategorized);
	}

	// ---------------------------------------------------------------
	//  Active filter chips
	// ---------------------------------------------------------------
	function renderActiveChips() {
		const wrapper = document.querySelector('[data-bc-tx-chips]');
		const list = document.querySelector('[data-bc-tx-chip-list]');
		if (!wrapper || !list) return;
		list.replaceChildren();
		const f = state.filters;
		const chips = [];

		if (f.from || f.to) {
			const label = f.from && f.to
				? Dates.formatDisplayDate(f.from, Ws.htmlLang) + ' – ' + Dates.formatDisplayDate(f.to, Ws.htmlLang)
				: (f.from
					? t('budgetcheck', 'From {from}').replace('{from}', Dates.formatDisplayDate(f.from, Ws.htmlLang))
					: t('budgetcheck', 'Until {to}').replace('{to}', Dates.formatDisplayDate(f.to, Ws.htmlLang)));
			chips.push({
				labelPrefix: t('budgetcheck', 'Date'),
				label,
				removeAria: t('budgetcheck', 'Clear date range'),
				remove: () => { f.from = ''; f.to = ''; f.rangePreset = 'all'; afterChipChange(); },
			});
		}
		if (f.categoryId) {
			const cat = state.categoryById.get(Number.parseInt(f.categoryId, 10));
			chips.push({
				labelPrefix: t('budgetcheck', 'Category'),
				label: cat ? cat.name : '#' + f.categoryId,
				removeAria: t('budgetcheck', 'Clear category filter'),
				remove: () => { f.categoryId = ''; afterChipChange(); },
			});
		}
		if (f.groupKey) {
			chips.push({
				labelPrefix: t('budgetcheck', 'Group'),
				label: f.groupKey === '__none__' ? t('budgetcheck', 'No group') : f.groupKey,
				removeAria: t('budgetcheck', 'Clear group filter'),
				remove: () => { f.groupKey = ''; afterChipChange(); },
			});
		}
		if (f.statusId) {
			const status = state.statusById.get(Number.parseInt(f.statusId, 10));
			chips.push({
				labelPrefix: t('budgetcheck', 'Status'),
				label: status ? status.name : '#' + f.statusId,
				removeAria: t('budgetcheck', 'Clear status filter'),
				remove: () => { f.statusId = ''; afterChipChange(); },
			});
		}
		if (f.q) {
			chips.push({
				labelPrefix: t('budgetcheck', 'Search'),
				label: '“' + f.q + '”',
				removeAria: t('budgetcheck', 'Clear search'),
				remove: () => {
					f.q = '';
					const search = document.querySelector('#bc-tx-q');
					if (search) { search.value = ''; toggleSearchClear(''); }
					afterChipChange();
				},
			});
		}
		if (f.isSpecial) {
			chips.push({
				labelPrefix: '',
				label: t('budgetcheck', 'Specials only'),
				removeAria: t('budgetcheck', 'Remove “Specials only” filter'),
				remove: () => { f.isSpecial = false; afterChipChange(); },
			});
		}
		if (f.uncategorized) {
			chips.push({
				labelPrefix: '',
				label: t('budgetcheck', 'Uncategorized expenses only'),
				removeAria: t('budgetcheck', 'Remove “Uncategorized expenses only” filter'),
				remove: () => { f.uncategorized = false; afterChipChange(); },
			});
		}
		wrapper.hidden = chips.length === 0;
		chips.forEach((chip) => list.appendChild(buildChipNode(chip)));
	}

	function buildChipNode(chip) {
		const li = C.createElement('li', { class: 'bc-chip' });
		const inner = C.createElement('span', { class: 'bc-chip__text' });
		if (chip.labelPrefix) {
			inner.appendChild(C.createElement('strong', { text: chip.labelPrefix + ': ' }));
		}
		inner.appendChild(document.createTextNode(chip.label));
		li.appendChild(inner);
		const removeBtn = C.createElement('button', {
			type: 'button',
			class: 'bc-chip__remove',
			attrs: { 'aria-label': chip.removeAria },
			text: '×',
		});
		removeBtn.addEventListener('click', chip.remove);
		li.appendChild(removeBtn);
		return li;
	}

	function afterChipChange() {
		state.offset = 0;
		syncFormFromState();
		loadAndRender();
	}

	function updateMoreCount() {
		const el = document.querySelector('[data-bc-more-count]');
		if (!el) return;
		const f = state.filters;
		// Count "advanced" filters (excluding search, range preset, category which live in primary row).
		let n = 0;
		if (f.from || f.to) n++;
		if (f.groupKey) n++;
		if (f.statusId) n++;
		if (f.isSpecial) n++;
		if (f.uncategorized) n++;
		if (n > 0) {
			el.hidden = false;
			el.textContent = String(n);
		} else {
			el.hidden = true;
			el.textContent = '';
		}
	}

	// ---------------------------------------------------------------
	//  Pagination
	// ---------------------------------------------------------------
	function renderPagination(total) {
		const pag = document.querySelector('[data-bc-tx-pagination]');
		if (!pag) return;
		pag.replaceChildren();
		if (total <= PAGE_SIZE) return;
		const page = Math.floor(state.offset / PAGE_SIZE) + 1;
		const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
		const start = state.offset + 1;
		const end = Math.min(state.offset + PAGE_SIZE, total);
		const counter = C.createElement('div', { class: 'bc-tx-pagination__count' });
		counter.textContent = t('budgetcheck', 'Showing {start}–{end} of {total}')
			.replace('{start}', String(start))
			.replace('{end}', String(end))
			.replace('{total}', String(total));
		const controls = C.createElement('div', { class: 'bc-tx-pagination__controls' });
		const prev = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Previous') });
		prev.disabled = state.offset <= 0;
		prev.addEventListener('click', () => {
			state.offset = Math.max(0, state.offset - PAGE_SIZE);
			loadAndRender();
		});
		const status = C.createElement('span', {
			class: 'bc-pill',
			text: t('budgetcheck', 'Page {page} of {pages}').replace('{page}', String(page)).replace('{pages}', String(pages)),
		});
		const next = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Next') });
		next.disabled = state.offset + PAGE_SIZE >= total;
		next.addEventListener('click', () => {
			state.offset += PAGE_SIZE;
			loadAndRender();
		});
		controls.appendChild(prev);
		controls.appendChild(status);
		controls.appendChild(next);
		pag.appendChild(counter);
		pag.appendChild(controls);
	}

	// ---------------------------------------------------------------
	//  Breakdowns
	// ---------------------------------------------------------------
	function wireBreakdownsDisclosure() {
		const btn = document.querySelector('[data-bc-breakdowns-toggle]');
		const body = document.querySelector('[data-bc-breakdowns-body]');
		const labelEl = document.querySelector('[data-bc-breakdowns-toggle-label]');
		if (!btn || !body || !labelEl) return;
		btn.addEventListener('click', () => {
			const expanded = btn.getAttribute('aria-expanded') === 'true';
			btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			body.hidden = expanded;
			labelEl.textContent = expanded ? t('budgetcheck', 'Show breakdowns') : t('budgetcheck', 'Hide breakdowns');
		});
	}

	function wireBreakdownTabs() {
		const tabs = Array.from(document.querySelectorAll('[role="tab"][data-bc-tab]'));
		if (tabs.length === 0) return;
		const panels = new Map();
		document.querySelectorAll('[data-bc-panel]').forEach((p) => panels.set(p.getAttribute('data-bc-panel'), p));
		const select = (key) => {
			tabs.forEach((tab) => {
				const isActive = tab.getAttribute('data-bc-tab') === key;
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
				tab.setAttribute('tabindex', isActive ? '0' : '-1');
			});
			panels.forEach((panel, panelKey) => { panel.hidden = panelKey !== key; });
		};
		tabs.forEach((tab) => {
			tab.addEventListener('click', () => select(tab.getAttribute('data-bc-tab') || 'group'));
			tab.addEventListener('keydown', (event) => {
				if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') return;
				event.preventDefault();
				const i = tabs.indexOf(tab);
				let next = i;
				if (event.key === 'ArrowRight') next = (i + 1) % tabs.length;
				else if (event.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
				else if (event.key === 'Home') next = 0;
				else if (event.key === 'End') next = tabs.length - 1;
				tabs[next].focus();
				select(tabs[next].getAttribute('data-bc-tab') || 'group');
			});
		});
	}

	function renderBreakdowns(analytics) {
		const groupsBody = document.querySelector('[data-bc-tx-analytics-groups]');
		const categoriesBody = document.querySelector('[data-bc-tx-analytics-categories]');
		const monthsBody = document.querySelector('[data-bc-tx-analytics-months]');
		if (!groupsBody || !categoriesBody || !monthsBody) return;

		const renderEmpty = (tbody, colspan) => {
			tbody.replaceChildren(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: String(colspan) }, class: 'bc-loading', text: t('budgetcheck', 'No transactions match these filters.') }),
			]));
		};

		const groups = (analytics && analytics.byGroup) || [];
		if (!groups.length) {
			renderEmpty(groupsBody, 5);
		} else {
			groupsBody.replaceChildren();
			groups.forEach((row) => {
				const tr = C.createElement('tr');
				const groupBtn = C.createElement('button', {
					type: 'button',
					class: 'button button--text',
					text: row.groupKey || t('budgetcheck', 'No group'),
				});
				groupBtn.addEventListener('click', () => applyAnalyticsDrilldown({ groupKey: row.groupKey || '__none__' }));
				tr.appendChild(C.createElement('td', null, [groupBtn]));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.income, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.expense, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.net, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: String(row.count || 0) }));
				groupsBody.appendChild(tr);
			});
		}

		const categories = (analytics && analytics.byCategory) || [];
		if (!categories.length) {
			renderEmpty(categoriesBody, 6);
		} else {
			categoriesBody.replaceChildren();
			categories.forEach((row) => {
				const tr = C.createElement('tr');
				const catBtn = C.createElement('button', {
					type: 'button',
					class: 'button button--text',
					text: row.name || ('#' + row.categoryId),
				});
				catBtn.addEventListener('click', () => applyAnalyticsDrilldown({ categoryId: row.categoryId }));
				const groupBtn = C.createElement('button', {
					type: 'button',
					class: 'button button--text',
					text: row.groupKey || t('budgetcheck', 'No group'),
				});
				groupBtn.addEventListener('click', () => applyAnalyticsDrilldown({ groupKey: row.groupKey || '__none__' }));
				tr.appendChild(C.createElement('td', null, [catBtn]));
				tr.appendChild(C.createElement('td', null, [groupBtn]));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.income, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.expense, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.net, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: String(row.count || 0) }));
				categoriesBody.appendChild(tr);
			});
		}

		const months = (analytics && analytics.byMonth) || [];
		if (!months.length) {
			renderEmpty(monthsBody, 5);
		} else {
			monthsBody.replaceChildren();
			months.forEach((row) => {
				const tr = C.createElement('tr');
				const monthBtn = C.createElement('button', {
					type: 'button',
					class: 'button button--text',
					text: Dates.formatYearMonth(row.yearMonth, Ws.htmlLang),
				});
				monthBtn.addEventListener('click', () => applyAnalyticsDrilldown({ yearMonth: row.yearMonth }));
				tr.appendChild(C.createElement('td', null, [monthBtn]));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.income, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.expense, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.net, Ws.htmlLang) }));
				tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: String(row.count || 0) }));
				monthsBody.appendChild(tr);
			});
		}
	}

	function applyAnalyticsDrilldown({ groupKey, categoryId, yearMonth }) {
		if (typeof groupKey === 'string') {
			state.filters.groupKey = groupKey;
		}
		if (Number.isInteger(categoryId) && categoryId > 0) {
			state.filters.categoryId = String(categoryId);
		}
		if (typeof yearMonth === 'string' && /^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonth)) {
			const [y, m] = yearMonth.split('-').map(Number);
			state.filters.from = yearMonth + '-01';
			state.filters.to = yearMonth + '-' + pad2(lastDayOfMonth(y, m));
			state.filters.rangePreset = 'custom';
		}
		state.offset = 0;
		syncFormFromState();
		loadAndRender();
		// Scroll to top of ledger so the user sees what they drilled into.
		const ledger = document.querySelector('[data-bc-tx-viewport]');
		if (ledger && typeof ledger.scrollIntoView === 'function') {
			ledger.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	// ---------------------------------------------------------------
	//  Create / edit modal (functionally identical to previous version)
	// ---------------------------------------------------------------
	function wireCreateButton() {
		document.querySelectorAll('[data-bc-action="open-create-transaction"]').forEach((btn) => {
			btn.addEventListener('click', () => openEditModal(null));
		});
	}

	function openEditModal(tx) {
		const isEdit = !!tx;
		const dateHintText = t('budgetcheck', 'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.');
		C.openModal({
			title: isEdit ? t('budgetcheck', 'Edit transaction') : t('budgetcheck', 'New transaction'),
			primaryLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add transaction'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });

				const directionSelect = C.createElement('select', { name: 'direction', class: 'bc-input' }, [
					C.createElement('option', { value: 'expense', text: t('budgetcheck', 'Expense') }),
					C.createElement('option', { value: 'income', text: t('budgetcheck', 'Income') }),
				]);
				directionSelect.value = tx ? tx.direction : 'expense';
				wrapField(form, t('budgetcheck', 'Direction'), directionSelect, t('budgetcheck', 'Expense means money leaving the workspace; income means money arriving. The category list updates when you change this.'));

				const titleInput = C.createElement('input', { name: 'title', type: 'text', class: 'bc-input', maxlength: 180, required: true });
				titleInput.value = tx ? tx.title : '';
				wrapField(form, t('budgetcheck', 'Title'), titleInput, t('budgetcheck', 'Short title shown in lists and reports (for example office supplies or client payment).'));

				const dateInput = C.createElement('input', {
					name: 'bookingDate',
					type: 'date',
					class: 'bc-input',
					autocomplete: 'off',
					required: true,
					value: tx ? String(tx.bookingDate) : Dates.isoDate(new Date()),
					attrs: { lang: Ws.htmlLang },
				});
				wrapField(form, t('budgetcheck', 'Date'), dateInput, dateHintText, 'bc-field--full-width');

				const amountInput = C.createElement('input', {
					name: 'amount', type: 'text', inputmode: 'decimal', class: 'bc-input', required: true,
				});
				amountInput.value = tx ? String(tx.amount.minor / Math.pow(10, activeDecimals())).replace('.', ',') : '';
				wrapField(form, t('budgetcheck', 'Amount'), amountInput, t('budgetcheck', 'Amount in this workspace’s currency. Use your usual decimal separator (dot or comma).'));

				const taxModeEnabled = !!(Ws.workspace && Ws.workspace.taxModeEnabled);
				let entryBasisSelect = null;
				let vatPresetSelect = null;
				let vatCustomWrap = null;
				let vatCustomInput = null;
				let taxPreviewEl = null;
				if (taxModeEnabled) {
					entryBasisSelect = C.createElement('select', { name: 'entryAmountBasis', class: 'bc-input' }, [
						C.createElement('option', { value: 'simple', text: t('budgetcheck', 'No tax split') }),
						C.createElement('option', { value: 'gross', text: t('budgetcheck', 'Gross') }),
						C.createElement('option', { value: 'net', text: t('budgetcheck', 'Net') }),
					]);
					entryBasisSelect.value = tx ? (tx.entryAmountBasis || 'simple') : (Ws.workspace.taxBudgetBasis || 'gross');
					wrapField(form, t('budgetcheck', 'Tax entry basis'), entryBasisSelect, t('budgetcheck', 'Choose whether the amount you entered is gross or net. Budget usage follows the workspace tax basis in settings.'));

					vatPresetSelect = C.createElement('select', { name: 'vatPreset', class: 'bc-input' }, [
						C.createElement('option', { value: '0', text: t('budgetcheck', '0 % (none)') }),
						C.createElement('option', { value: '500', text: t('budgetcheck', '5 %') }),
						C.createElement('option', { value: '700', text: t('budgetcheck', '7 %') }),
						C.createElement('option', { value: '1000', text: t('budgetcheck', '10 %') }),
						C.createElement('option', { value: '1300', text: t('budgetcheck', '13 %') }),
						C.createElement('option', { value: '1500', text: t('budgetcheck', '15 %') }),
						C.createElement('option', { value: '1900', text: t('budgetcheck', '19 %') }),
						C.createElement('option', { value: '2000', text: t('budgetcheck', '20 %') }),
						C.createElement('option', { value: '2100', text: t('budgetcheck', '21 %') }),
						C.createElement('option', { value: '2500', text: t('budgetcheck', '25 %') }),
						C.createElement('option', { value: 'custom', text: t('budgetcheck', 'Custom…') }),
					]);
					const initialRate = tx && Number.isFinite(tx.vatRateBp) ? String(tx.vatRateBp) : String(Ws.workspace.defaultVatRateBp ?? 0);
					const presetValues = new Set(Array.from(vatPresetSelect.options).map((o) => o.value));
					vatPresetSelect.value = presetValues.has(initialRate) ? initialRate : 'custom';
					wrapField(form, t('budgetcheck', 'VAT rate'), vatPresetSelect, t('budgetcheck', 'Used when basis is gross or net.'));

					vatCustomInput = C.createElement('input', { name: 'vatRateBpCustom', type: 'number', min: '0', max: '5000', step: '1', class: 'bc-input' });
					vatCustomInput.value = vatPresetSelect.value === 'custom' ? initialRate : '';
					vatCustomWrap = C.createElement('label', { class: 'bc-field', attrs: { 'data-bc-vat-custom-wrap': '1' } }, [
						C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Custom VAT (basis points)') }),
						vatCustomInput,
						C.createElement('span', { class: 'bc-field__hint', text: t('budgetcheck', 'Example: 1900 = 19%%') }),
					]);
					form.appendChild(vatCustomWrap);

					taxPreviewEl = C.createElement('p', { class: 'bc-field__hint bc-field__hint--block' });
					form.appendChild(taxPreviewEl);
				}

				const catSelect = C.createElement('select', { name: 'categoryId', class: 'bc-input', required: true });
				const filterCategories = () => {
					const dir = directionSelect.value;
					catSelect.replaceChildren();
					state.categories.filter((c) => c.type === dir && c.isActive).forEach((c) => {
						catSelect.appendChild(C.createElement('option', { value: String(c.id), text: c.name }));
					});
					if (tx && tx.categoryId) {
						const opt = Array.from(catSelect.options).find((o) => o.value === String(tx.categoryId));
						if (opt) catSelect.value = opt.value;
					}
				};
				filterCategories();
				directionSelect.addEventListener('change', filterCategories);
				wrapField(form, t('budgetcheck', 'Category'), catSelect, t('budgetcheck', 'Choose the category that best describes this booking. Only categories for the direction you selected are listed.'));

				let bookingStatusSelect = null;
				if (Ws.workspace && Ws.workspace.type === 'project') {
					bookingStatusSelect = C.createElement('select', { name: 'bookingStatusId', class: 'bc-input' });
					bookingStatusSelect.appendChild(C.createElement('option', { value: '', text: t('budgetcheck', 'No status') }));
					state.statuses.filter((status) => status.isActive).forEach((status) => {
						bookingStatusSelect.appendChild(C.createElement('option', { value: String(status.id), text: status.name }));
					});
					if (tx && tx.bookingStatusId) {
						bookingStatusSelect.value = String(tx.bookingStatusId);
					}
					wrapField(form, t('budgetcheck', 'Booking status'), bookingStatusSelect, t('budgetcheck', 'In project workspaces you can tag a booking with a status (for example in progress or paid). Leave empty if you do not need a workflow step.'), 'bc-field--full-width');
				}

				const specialOuter = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' });
				specialOuter.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Special') }));
				const specialRow = C.createElement('span', { class: 'bc-boolean-control' });
				const specialInput = C.createElement('input', { type: 'checkbox', name: 'isSpecial', value: '1' });
				specialInput.checked = !!(tx && tx.isSpecial);
				specialRow.appendChild(specialInput);
				specialRow.appendChild(C.createElement('span', { class: 'bc-boolean-control__text', text: t('budgetcheck', 'Mark as special (large/unusual entry)') }));
				specialOuter.appendChild(specialRow);
				const specialHintId = 'bc-field-hint-' + Math.random().toString(36).slice(2);
				specialOuter.appendChild(C.createElement('span', { id: specialHintId, class: 'bc-field__hint', text: t('budgetcheck', 'Use for unusually large or one-off entries so they are easy to find in summaries and filters.') }));
				specialInput.setAttribute('aria-describedby', specialHintId);
				form.appendChild(specialOuter);

				const notesArea = C.createElement('textarea', { name: 'notes', class: 'bc-input', maxlength: 4000, rows: 3 });
				notesArea.value = tx && tx.notes ? tx.notes : '';
				wrapField(form, t('budgetcheck', 'Notes'), notesArea, t('budgetcheck', 'Optional detail for people who can view this booking—references, links, or context.'), 'bc-field--full-width');

				function syncTaxControls() {
					if (!taxModeEnabled || !entryBasisSelect || !vatPresetSelect || !vatCustomWrap || !vatCustomInput || !taxPreviewEl) return;
					const basis = entryBasisSelect.value;
					const needsRate = basis !== 'simple';
					vatPresetSelect.disabled = !needsRate;
					vatCustomWrap.hidden = !needsRate || vatPresetSelect.value !== 'custom';
					vatCustomInput.disabled = vatCustomWrap.hidden;
					if (!needsRate) {
						taxPreviewEl.textContent = t('budgetcheck', 'No tax split: amount is stored as a plain amount.');
						return;
					}
					let amountMinor = null;
					try {
						amountMinor = Money.parseHuman(amountInput.value || '', activeDecimals());
					} catch (_) {
						taxPreviewEl.textContent = t('budgetcheck', 'Enter an amount to preview net/VAT/gross.');
						return;
					}
					let bp = null;
					if (vatPresetSelect.value === 'custom') {
						const raw = String(vatCustomInput.value || '').trim();
						if (!/^\d+$/.test(raw)) {
							taxPreviewEl.textContent = t('budgetcheck', 'Enter a valid VAT rate in basis points.');
							return;
						}
						bp = Number.parseInt(raw, 10);
					} else {
						bp = Number.parseInt(vatPresetSelect.value, 10);
					}
					if (!Number.isInteger(bp) || bp < 0 || bp > 5000) {
						taxPreviewEl.textContent = t('budgetcheck', 'VAT rate must be between 0 and 5000 basis points.');
						return;
					}
					const converted = Money.convertTaxPreview(amountMinor, bp, basis);
					taxPreviewEl.textContent = t('budgetcheck', 'Preview: Net {net} · VAT {vat} · Gross {gross}')
						.replace('{net}', Money.formatMinor(converted.net, Ws.workspace.currencyCode, Ws.htmlLang))
						.replace('{vat}', Money.formatMinor(converted.vat, Ws.workspace.currencyCode, Ws.htmlLang))
						.replace('{gross}', Money.formatMinor(converted.gross, Ws.workspace.currencyCode, Ws.htmlLang));
				}
				if (taxModeEnabled && entryBasisSelect && vatPresetSelect && vatCustomInput) {
					entryBasisSelect.addEventListener('change', syncTaxControls);
					vatPresetSelect.addEventListener('change', syncTaxControls);
					vatCustomInput.addEventListener('input', syncTaxControls);
					amountInput.addEventListener('input', syncTaxControls);
					syncTaxControls();
				}

				form._collect = () => ({
					workspaceId: Ws.workspace.id,
					title: titleInput.value.trim(),
					direction: directionSelect.value,
					bookingDate: dateInput.value.trim(),
					amount: amountInput.value,
					categoryId: catSelect.value ? Number.parseInt(catSelect.value, 10) : 0,
					bookingStatusId: bookingStatusSelect && bookingStatusSelect.value ? Number.parseInt(bookingStatusSelect.value, 10) : null,
					isSpecial: specialInput.checked,
					notes: notesArea.value.trim(),
					entryAmountBasis: entryBasisSelect ? entryBasisSelect.value : undefined,
					vatPreset: vatPresetSelect ? vatPresetSelect.value : undefined,
					vatRateBpCustom: vatCustomInput ? vatCustomInput.value : undefined,
					version: tx ? tx.version : undefined,
				});
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				const payload = form && form._collect ? form._collect() : null;
				if (!payload) return false;
				if (!Dates.isIsoCalendarDay(String(payload.bookingDate || '').trim())) {
					Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
					return false;
				}
				payload.bookingDate = String(payload.bookingDate).trim();
				try {
					Money.parseHuman(payload.amount, activeDecimals());
				} catch (e) {
					Msg.announce(e.message, 'error');
					return false;
				}
				if (Ws.workspace && Ws.workspace.taxModeEnabled) {
					const basis = String(payload.entryAmountBasis || 'simple');
					if (basis !== 'simple') {
						let bp = null;
						if (payload.vatPreset === 'custom') {
							const raw = String(payload.vatRateBpCustom || '').trim();
							if (!/^\d+$/.test(raw)) {
								Msg.announce(t('budgetcheck', 'Enter a valid VAT rate in basis points.'), 'error');
								return false;
							}
							bp = Number.parseInt(raw, 10);
						} else {
							bp = Number.parseInt(String(payload.vatPreset || ''), 10);
						}
						if (!Number.isInteger(bp) || bp < 0 || bp > 5000) {
							Msg.announce(t('budgetcheck', 'VAT rate must be between 0 and 5000 basis points.'), 'error');
							return false;
						}
						payload.vatRateBp = bp;
					}
					payload.entryAmountBasis = basis;
				}
				delete payload.vatPreset;
				delete payload.vatRateBpCustom;
				try {
					if (isEdit) {
						await Api.put('/apps/budgetcheck/api/transactions/' + tx.id, payload);
						Msg.announce(t('budgetcheck', 'Transaction updated.'), 'success');
					} else {
						await Api.post('/apps/budgetcheck/api/transactions', payload);
						Msg.announce(t('budgetcheck', 'Transaction created.'), 'success');
					}
					loadAndRender();
					close(true);
				} catch (err) {
					Msg.handleApiError(err, { reloadOnConflict: false });
					return false;
				}
			},
		});
	}

	async function deleteTransaction(tx) {
		const ok = await C.confirmDialog({
			title: t('budgetcheck', 'Delete this transaction?'),
			body: t('budgetcheck', 'This soft-deletes the entry. Closed months cannot be edited.'),
			confirmLabel: t('budgetcheck', 'Delete'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.del('/apps/budgetcheck/api/transactions/' + tx.id);
			Msg.announce(t('budgetcheck', 'Transaction deleted.'), 'success');
			loadAndRender();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	function wrapField(form, labelText, control, hintText, labelExtraClass) {
		const labelClasses = ['bc-field'];
		if (labelExtraClass) {
			String(labelExtraClass).split(/\s+/).filter(Boolean).forEach((c) => labelClasses.push(c));
		}
		const parts = [
			C.createElement('span', { class: 'bc-field__label', text: labelText }),
			control,
		];
		if (hintText) {
			const hintId = 'bc-field-hint-' + Math.random().toString(36).slice(2);
			parts.push(C.createElement('span', { id: hintId, class: 'bc-field__hint', text: hintText }));
			if (control && typeof control.setAttribute === 'function') {
				const cur = control.getAttribute('aria-describedby');
				control.setAttribute('aria-describedby', cur ? (cur + ' ' + hintId) : hintId);
			}
		}
		form.appendChild(C.createElement('label', { class: labelClasses.join(' ') }, parts));
	}

	function render() {
		// Initial paint of pieces that are available even before the first API result.
		renderActiveChips();
		updateMoreCount();
	}

	// ---------------------------------------------------------------
	//  Tiny SVG helper (uses currentColor; never sets innerHTML).
	// ---------------------------------------------------------------
	function iconSvg(d) {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '1.75');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		svg.classList.add('bc-icon');
		String(d || '').split(' M').forEach((segment, i) => {
			const path = document.createElementNS(ns, 'path');
			path.setAttribute('d', i === 0 ? segment : 'M' + segment);
			svg.appendChild(path);
		});
		return svg;
	}
})();
