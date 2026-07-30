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
 *   - Render: KPI strip, ledger rows (responsive cards below 768px / NC mobile), pagination,
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

	/** @type {any} */
	let Api;
	/** @type {any} */
	let Msg;
	/** @type {any} */
	let C;
	/** @type {any} */
	let Money;
	/** @type {any} */
	let Dates;
	/** @type {any} */
	let Ws;
	/** @type {any} */
	let BcConst;


	const PAGE_SIZE = 50;
	const SEARCH_DEBOUNCE_MS = 250;

	// Whitelist of range preset keys. Anything else falls back to the default preset.
	const RANGE_PRESETS = new Set(['all', 'thisMonth', 'lastMonth', 'last30', 'ytd', 'last12', 'custom']);
	// First visit / reset uses the current calendar month (matches dashboard mental model).
	const DEFAULT_RANGE_PRESET = 'thisMonth';

	const state = {
		filters: emptyFilters(),
		offset: 0,
		viewYearMonth: '',
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
		const range = datesForPreset(DEFAULT_RANGE_PRESET);
		return {
			from: range.from,
			to: range.to,
			rangePreset: DEFAULT_RANGE_PRESET,
			categoryId: '',
			groupKey: '',
			statusId: '',
			q: '',
			isSpecial: false,
			uncategorized: false,
		};
	}

	function isDefaultDateRange(fromIso, toIso) {
		const def = datesForPreset(DEFAULT_RANGE_PRESET);
		return fromIso === def.from && toIso === def.to;
	}

	function applyDefaultDateRange(filters) {
		const range = datesForPreset(DEFAULT_RANGE_PRESET);
		filters.from = range.from;
		filters.to = range.to;
		filters.rangePreset = DEFAULT_RANGE_PRESET;
	}

	/** True when only the baseline date scope is active (default month or explicit all-time). */
	function isBaselineFilterState() {
		const f = state.filters;
		if (f.categoryId || f.groupKey || f.statusId || f.q || f.isSpecial || f.uncategorized) return false;
		if (f.rangePreset === 'all' && !f.from && !f.to) return true;
		return f.rangePreset === DEFAULT_RANGE_PRESET && isDefaultDateRange(f.from, f.to);
	}

	function shouldShowDateChip() {
		const f = state.filters;
		if (!f.from && !f.to) return false;
		if (f.rangePreset === 'all') return false;
		return !(f.rangePreset === DEFAULT_RANGE_PRESET && isDefaultDateRange(f.from, f.to));
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
	function pageInit() {
		if (!Ws || typeof Ws !== 'object' || !Ws.workspace) return;
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
		if (state.filters.rangePreset === 'custom') {
			openMorePanel(true);
		}
		render(); // initial paint of chips + advanced visibility
		loadAndRender();
		maybeOpenNewTransactionFromUrl();
	}

	function resolveViewYearMonth() {
		// Active date filters win over a stale URL month so the header always matches the ledger scope.
		const ym = Dates.yearMonthFromDateRange(state.filters.from, state.filters.to);
		if (ym) {
			return ym;
		}
		if (state.viewYearMonth && /^\d{4}-(0[1-9]|1[0-2])$/.test(state.viewYearMonth)) {
			return state.viewYearMonth;
		}
		return '';
	}

	function syncViewYearMonthFromFilters() {
		const ym = Dates.yearMonthFromDateRange(state.filters.from, state.filters.to);
		state.viewYearMonth = ym || '';
	}

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
			state.viewYearMonth = yearMonth;
			state.filters.rangePreset = 'custom';
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

		finalizeRangeFromUrl(sp);
		if (!state.viewYearMonth) {
			syncViewYearMonthFromFilters();
		}
		if (state.viewYearMonth && state.filters.rangePreset === DEFAULT_RANGE_PRESET) {
			state.filters.rangePreset = 'custom';
		}
	}

	/**
	 * After reading URL params: apply the default month when no date scope was given,
	 * resolve preset-only deep links, and honour an explicit `rangePreset=all`.
	 */
	function finalizeRangeFromUrl(sp) {
		const hasDateParams = sp.has('from') || sp.has('to') || sp.has('yearMonth');
		const explicitAll = sp.get('rangePreset') === 'all';

		if (explicitAll) {
			state.filters.from = '';
			state.filters.to = '';
			state.filters.rangePreset = 'all';
			return;
		}

		if (!hasDateParams) {
			const presetInUrl = sp.get('rangePreset');
			if (presetInUrl === 'custom') {
				state.filters.rangePreset = 'custom';
				state.filters.from = '';
				state.filters.to = '';
				return;
			}
			if (presetInUrl && RANGE_PRESETS.has(presetInUrl) && presetInUrl !== 'all' && presetInUrl !== 'custom') {
				const range = datesForPreset(presetInUrl);
				state.filters.from = range.from;
				state.filters.to = range.to;
				state.filters.rangePreset = presetInUrl;
				return;
			}
			applyDefaultDateRange(state.filters);
			return;
		}

		if (state.filters.rangePreset !== 'all'
			&& state.filters.rangePreset !== 'custom'
			&& !state.filters.from
			&& !state.filters.to) {
			const range = datesForPreset(state.filters.rangePreset);
			state.filters.from = range.from;
			state.filters.to = range.to;
		}
	}

	function syncUrlFromState() {
		// `workspaceId` is required by the page; preserve any other unknown params untouched.
		const sp = new URLSearchParams(window.location.search);
		const drop = ['from', 'to', 'yearMonth', 'filter', 'categoryId', 'groupKey', 'statusId', 'q', 'isSpecial', 'uncategorized', 'rangePreset', 'offset', 'newTransaction'];
		drop.forEach((k) => sp.delete(k));
		const f = state.filters;
		if (f.from) sp.set('from', f.from);
		if (f.to) sp.set('to', f.to);
		const viewYm = resolveViewYearMonth();
		if (viewYm) sp.set('yearMonth', viewYm);
		if (f.categoryId) sp.set('categoryId', f.categoryId);
		if (f.groupKey) sp.set('groupKey', f.groupKey);
		if (f.statusId) sp.set('statusId', f.statusId);
		if (f.q) sp.set('q', f.q);
		if (f.isSpecial) sp.set('isSpecial', '1');
		if (f.uncategorized) sp.set('uncategorized', '1');
		if (f.rangePreset === 'all') {
			sp.set('rangePreset', 'all');
		} else if (f.rangePreset && f.rangePreset !== 'custom') {
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
			state.viewYearMonth = '';
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
				state.viewYearMonth = '';
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
		// If user manually edits dates, switch preset to 'custom'; if they clear both, restore default month.
		if (key === 'from' || key === 'to') {
			const f = state.filters;
			if (!f.from && !f.to) {
				applyDefaultDateRange(f);
			} else {
				f.rangePreset = detectPresetFromDates(f.from, f.to);
			}
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
		syncViewYearMonthFromFilters();
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
		set('[data-bc-filter="rangePreset"]', RANGE_PRESETS.has(f.rangePreset)
			? f.rangePreset
			: (Dates.yearMonthFromDateRange(f.from, f.to) ? 'custom' : DEFAULT_RANGE_PRESET));
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
			syncViewYearMonthFromFilters();
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
			state.viewYearMonth = '';
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
					if (cat.groupKey && BcConst.shouldShowCategoryGroupBadge(cat.groupKey)) {
						groups.add(String(cat.groupKey));
					}
				});
				select.value = state.filters.categoryId || '';
			}
			if (groupSelect) {
				Array.from(groups).sort((a, b) => a.localeCompare(b)).forEach((groupKey) => {
					groupSelect.appendChild(C.createElement('option', {
						value: groupKey,
						text: BcConst.categoryGroupKeyLabel(groupKey),
					}));
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

		// Window subline. When the ledger shows a full calendar month, keep the
		// header scope strip in sync so it never contradicts the content below.
		// For non-month scopes (all time / multi-month custom), reset the strip
		// to the workspace's active calendar month so it never looks "stuck".
		const windowEl = document.querySelector('[data-bc-tx-window]');
		const scopeYm = resolveViewYearMonth();
		if (Ws && typeof Ws.setScopeMonth === 'function') {
			if (scopeYm) {
				Ws.setScopeMonth(scopeYm);
			} else {
				const active = document.querySelector('[data-bc-scope-month]')?.getAttribute('data-bc-scope-month-active');
				if (active) Ws.setScopeMonth(active);
			}
		}
		if (windowEl) {
			const viewYm = scopeYm;
			if (viewYm) {
				windowEl.textContent = t('budgetcheck', 'Month: {month}')
					.replace('{month}', Dates.formatYearMonth(viewYm, Ws.htmlLang));
			} else if (window_.from && window_.to) {
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
			const msg = isBaselineFilterState()
				? (state.filters.rangePreset === DEFAULT_RANGE_PRESET
					? t('budgetcheck', 'No transactions this month.')
					: t('budgetcheck', 'No bookings yet.'))
				: t('budgetcheck', 'No transactions match these filters.');
			Msg.announce(msg, 'success');
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
		const planned = totals.planned || null;
		const plannedCount = Number(planned?.count || 0);

		const netMinor = net ? Number(net.minor || 0) : 0;
		const netVariant = netMinor > 0 ? 'bc-tx-kpi-tile--net-positive' : (netMinor < 0 ? 'bc-tx-kpi-tile--net-negative' : '');
		// Tile values omit the currency symbol (the workspace currency sits in
		// the header); a visually hidden code keeps screen readers unambiguous.
		const fmt = (env) => C.moneyTileValue(env, Ws.htmlLang);

		const tileNodes = [
			kpiTile({
				modifier: 'bc-tx-kpi-tile--income',
				label: t('budgetcheck', 'Income'),
				value: fmt(income),
				sub: t('budgetcheck', 'Actual bookings only'),
			}),
			kpiTile({
				modifier: 'bc-tx-kpi-tile--expense',
				label: t('budgetcheck', 'Expenses'),
				value: fmt(expense),
				sub: t('budgetcheck', 'Actual bookings only'),
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
		];
		if (plannedCount > 0) {
			tileNodes.push(kpiTile({
				modifier: 'bc-tx-kpi-tile--planned',
				label: t('budgetcheck', 'Planned placeholders'),
				value: plannedCount === 1
					? t('budgetcheck', '1 entry')
					: t('budgetcheck', '{count} entries').replace('{count}', String(plannedCount)),
				// Sub-line keeps the full currency format like tables do.
				sub: t('budgetcheck', 'In {income} · Out {expense}')
					.replace('{income}', planned.income ? Money.formatEnvelope(planned.income, Ws.htmlLang) : '—')
					.replace('{expense}', planned.expense ? Money.formatEnvelope(planned.expense, Ws.htmlLang) : '—'),
			}));
		}

		tiles.setAttribute('aria-busy', 'false');
		tiles.replaceChildren(...tileNodes);
	}

	function kpiTile({ modifier, label, value, sub, primary }) {
		const cls = 'bc-summary-tile bc-tx-kpi-tile ' + (primary ? 'bc-summary-tile--primary ' : '') + (modifier || '');
		const valueEl = Array.isArray(value)
			? C.createElement('div', { class: 'bc-summary-tile__value' }, value)
			: C.createElement('div', { class: 'bc-summary-tile__value', text: value });
		const children = [
			C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
			valueEl,
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
		const tr = C.createElement('tr', { attrs: { 'data-bc-tx-id': String(tx.id) } });

		// Date
		tr.appendChild(C.createElement('td', {
			attrs: { 'data-cell': 'date' },
			text: Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang),
		}));

		// Title (+ optional notes preview)
		const titleTd = C.createElement('td', { attrs: { 'data-cell': 'title' } });
		const usesCategoryTitle = cat && tx.title === cat.name;
		if (usesCategoryTitle) {
			titleTd.appendChild(C.createElement('span', {
				class: 'bc-tx-row-title bc-tx-row-title--default',
				attrs: { 'aria-hidden': 'true' },
				text: '—',
			}));
			titleTd.appendChild(C.createElement('span', {
				class: 'bc-sr-only',
				text: t('budgetcheck', 'Uses category name: {name}').replace('{name}', cat.name),
			}));
		} else {
			titleTd.appendChild(C.createElement('span', { class: 'bc-tx-row-title', text: tx.title || '' }));
		}
		if (tx.notes) {
			titleTd.appendChild(C.createElement('span', { class: 'bc-tx-row-notes', text: String(tx.notes).slice(0, 280) }));
		}
		if (tx.hasAttachments || (tx.attachmentCount && tx.attachmentCount > 0)) {
			const count = Number(tx.attachmentCount) || 0;
			const receiptLabel = count === 1
				? t('budgetcheck', '1 receipt attached')
				: t('budgetcheck', '{count} receipts attached').replace('{count}', String(count));
			titleTd.appendChild(C.createElement('span', {
				class: 'bc-tx-row-receipts',
				attrs: { role: 'status' },
				text: receiptLabel,
			}));
		}
		tr.appendChild(titleTd);

		// Category (with group sub)
		const catTd = C.createElement('td', { attrs: { 'data-cell': 'category' } });
		catTd.appendChild(C.createElement('span', { class: 'bc-tx-row-category', text: cat ? cat.name : '#' + tx.categoryId }));
		if (cat && BcConst.shouldShowCategoryGroupBadge(cat.groupKey)) {
			catTd.appendChild(C.createElement('span', {
				class: 'bc-tx-row-group',
				text: BcConst.categoryGroupKeyLabel(cat.groupKey),
			}));
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
		if (tx.hasAttachments || (tx.attachmentCount && tx.attachmentCount > 0)) {
			out.push({ cls: 'bc-tx-tag-pill bc-tx-tag-pill--receipt', text: t('budgetcheck', 'Receipt') });
		}
		if (tx.isPlanned) out.push({ cls: 'bc-tx-tag-pill bc-tx-tag-pill--planned', text: t('budgetcheck', 'Planned') });
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

		const narrowed = !isBaselineFilterState();
		const defaultMonthEmpty = !narrowed
			&& state.filters.rangePreset === DEFAULT_RANGE_PRESET
			&& isDefaultDateRange(state.filters.from, state.filters.to);
		const allTimeEmpty = !narrowed
			&& state.filters.rangePreset === 'all'
			&& !state.filters.from
			&& !state.filters.to;

		const icon = C.createElement('div', { class: 'bc-tx-ledger__state-icon', attrs: { 'aria-hidden': 'true' } }, [
			iconSvg('M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z'),
		]);
		const title = C.createElement('h3', {
			class: 'bc-tx-ledger__state-title',
			text: narrowed
				? t('budgetcheck', 'No bookings match these filters.')
				: (defaultMonthEmpty
					? t('budgetcheck', 'No transactions this month.')
					: (allTimeEmpty && Ws.canContribute
						? t('budgetcheck', 'Add your first booking')
						: t('budgetcheck', 'No bookings yet'))),
		});
		const body = C.createElement('p', {
			class: 'bc-tx-ledger__state-body',
			text: narrowed
				? t('budgetcheck', 'Try a wider date range, a different category, or clear the filters to see everything.')
				: (defaultMonthEmpty
					? t('budgetcheck', 'Nothing is booked for the selected month yet. Widen the date range to see older entries, or add a new transaction.')
					: (allTimeEmpty && Ws.canContribute
						? t('budgetcheck', 'Once you add a booking it will appear here. You can edit, tag, or delete entries from this page.')
						: t('budgetcheck', 'Ask a workspace contributor to add bookings. You can still review breakdowns once entries exist.'))),
		});
		const actions = C.createElement('div', { class: 'bc-tx-ledger__state-actions' });
		if (defaultMonthEmpty) {
			const allTimeBtn = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Show all time') });
			allTimeBtn.addEventListener('click', () => {
				state.filters.from = '';
				state.filters.to = '';
				state.filters.rangePreset = 'all';
				state.offset = 0;
				syncFormFromState();
				loadAndRender();
			});
			actions.appendChild(allTimeBtn);
		}
		if ((allTimeEmpty || defaultMonthEmpty) && Ws.canContribute) {
			const newBtn = C.createElement('button', { type: 'button', class: 'button primary', text: t('budgetcheck', 'New transaction') });
			newBtn.addEventListener('click', () => openEditModal(null));
			actions.appendChild(newBtn);
		}
		if (narrowed) {
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
		return !isBaselineFilterState();
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

		if (shouldShowDateChip()) {
			const label = f.from && f.to
				? Dates.formatDisplayDate(f.from, Ws.htmlLang) + ' – ' + Dates.formatDisplayDate(f.to, Ws.htmlLang)
				: (f.from
					? t('budgetcheck', 'From {from}').replace('{from}', Dates.formatDisplayDate(f.from, Ws.htmlLang))
					: t('budgetcheck', 'Until {to}').replace('{to}', Dates.formatDisplayDate(f.to, Ws.htmlLang)));
			chips.push({
				labelPrefix: t('budgetcheck', 'Date'),
				label,
				removeAria: t('budgetcheck', 'Clear date range'),
				remove: () => { applyDefaultDateRange(f); afterChipChange(); },
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
				label: f.groupKey === '__none__' ? t('budgetcheck', 'No group') : BcConst.categoryGroupKeyLabel(f.groupKey),
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
		if (f.rangePreset === 'custom' && (f.from || f.to)) n++;
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
					text: row.groupKey ? BcConst.categoryGroupKeyLabel(row.groupKey) : t('budgetcheck', 'No group'),
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
					text: row.groupKey ? BcConst.categoryGroupKeyLabel(row.groupKey) : t('budgetcheck', 'No group'),
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
			state.viewYearMonth = yearMonth;
		} else {
			syncViewYearMonthFromFilters();
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

	function defaultBookingDateForNew() {
		return window.BudgetCheckTransactionEditor.defaultBookingDateForRange(state.filters.from, state.filters.to);
	}

	function maybeOpenNewTransactionFromUrl() {
		const sp = new URLSearchParams(window.location.search);
		if (sp.get('newTransaction') !== '1') return;
		sp.delete('newTransaction');
		const qs = sp.toString();
		const next = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
		window.history.replaceState(null, '', next);
		if (!Ws.canContribute) return;
		openEditModal(null, { bookingDate: defaultBookingDateForNew() });
	}

	function patchLedgerReceiptCount(transactionId, count) {
		const txId = Number(transactionId);
		if (!txId) return;
		const n = Math.max(0, Number(count) || 0);
		if (state.lastResponse && Array.isArray(state.lastResponse.items)) {
			const tx = state.lastResponse.items.find((entry) => Number(entry.id) === txId);
			if (tx && window.BudgetCheckTransactionList) {
				window.BudgetCheckTransactionList.applyReceiptCount(tx, n);
			}
		}
		const tr = document.querySelector('[data-bc-tx-rows] [data-bc-tx-id="' + txId + '"]');
		if (!tr) return;

		const titleTd = tr.querySelector('[data-cell="title"]');
		if (titleTd) {
			let receiptEl = titleTd.querySelector('.bc-tx-row-receipts');
			if (n <= 0) {
				if (receiptEl) receiptEl.remove();
			} else {
				const receiptLabel = n === 1
					? t('budgetcheck', '1 receipt attached')
					: t('budgetcheck', '{count} receipts attached').replace('{count}', String(n));
				if (!receiptEl) {
					receiptEl = C.createElement('span', {
						class: 'bc-tx-row-receipts',
						attrs: { role: 'status' },
					});
					titleTd.appendChild(receiptEl);
				}
				receiptEl.textContent = receiptLabel;
			}
		}

		const tagsRow = tr.querySelector('[data-cell="tags"] .bc-tx-row-tags');
		if (tagsRow) {
			let pill = tagsRow.querySelector('.bc-tx-tag-pill--receipt');
			if (n <= 0) {
				if (pill) pill.remove();
			} else if (!pill) {
				tagsRow.appendChild(C.createElement('span', {
					class: 'bc-tx-tag-pill bc-tx-tag-pill--receipt',
					text: t('budgetcheck', 'Receipt'),
				}));
			}
		}
	}

	function openEditModal(tx, opts) {
		window.BudgetCheckTransactionEditor.open({
			tx: tx || null,
			bookingDate: opts && opts.bookingDate ? opts.bookingDate : undefined,
			onSaved: () => loadAndRender(),
			onAttachmentsChanged: (payload) => {
				if (!payload || !payload.transactionId) return;
				patchLedgerReceiptCount(payload.transactionId, payload.attachmentCount);
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
			await Api.del('/apps/budgetcheck/api/transactions/' + tx.id, {
				version: tx.version,
			});
			Msg.announce(t('budgetcheck', 'Transaction deleted.'), 'success');
			loadAndRender();
		} catch (err) {
			Msg.handleApiError(err);
		}
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

	function boot(deps) {
		Api = deps.Api;
		Msg = deps.Messaging;
		C = deps.Components;
		Money = deps.Money;
		Dates = deps.Dates;
		Ws = deps.Workspace;
		BcConst = deps.Constants;
		if (typeof state !== 'undefined' && state && Object.prototype.hasOwnProperty.call(state, 'yearMonth') && state.yearMonth == null && typeof initialYearMonth === 'function') {
			state.yearMonth = initialYearMonth();
		}
		if (typeof dashState !== 'undefined' && dashState && Object.prototype.hasOwnProperty.call(dashState, 'yearMonth') && dashState.yearMonth == null && typeof initialYearMonth === 'function') {
			dashState.yearMonth = initialYearMonth();
		}
		pageInit();
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.onReady !== 'function') {
		return;
	}
	window.BudgetCheck.onReady(boot, {
		required: ['Api', 'Messaging', 'Components', 'Money', 'Dates', 'Workspace', 'Constants'],
		optional: [],
	});

})();
