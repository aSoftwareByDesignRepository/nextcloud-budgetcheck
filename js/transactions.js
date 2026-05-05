(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	function activeDecimals() {
		const w = Ws.workspace;
		if (!w) return 2;
		return typeof w.currencyDecimals === 'number' ? w.currencyDecimals : (w.currencyCode === 'JPY' ? 0 : 2);
	}

	const PAGE_SIZE = 50;
	const state = {
		filters: { from: '', to: '', categoryId: '', statusId: '', q: '', isSpecial: false, uncategorized: false },
		offset: 0,
		categories: [],
		statuses: [],
	};

	function tableColumnCount() {
		const base = Ws.canContribute ? 7 : 6;
		return Ws.workspace && Ws.workspace.type === 'project' ? base + 1 : base;
	}

	document.addEventListener('DOMContentLoaded', () => {
		if (!Ws.workspace) return;
		hydrateInitialFilters();
		wireFilterForm();
		wireCreateButton();
		loadCategoriesIntoSelect();
		loadStatusesIntoSelect();
		loadAndRender();
	});

	function hydrateInitialFilters() {
		const sp = new URLSearchParams(window.location.search);
		const filterMode = sp.get('filter');
		const yearMonth = sp.get('yearMonth');
		if (yearMonth && /^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonth)) {
			state.filters.from = yearMonth + '-01';
			const [y, m] = yearMonth.split('-').map(Number);
			const lastDay = new Date(y, m, 0).getDate();
			state.filters.to = yearMonth + '-' + (lastDay < 10 ? '0' + lastDay : String(lastDay));
		}
		if (filterMode === 'special') state.filters.isSpecial = true;
		if (filterMode === 'uncategorized') state.filters.uncategorized = true;
		const form = document.querySelector('[data-bc-tx-filters]');
		if (!form) return;
		const fromEl = form.querySelector('[data-bc-filter="from"]');
		const toEl = form.querySelector('[data-bc-filter="to"]');
		fromEl.value = state.filters.from ? String(state.filters.from) : '';
		toEl.value = state.filters.to ? String(state.filters.to) : '';
		form.querySelector('[data-bc-filter="q"]').value = state.filters.q || '';
		form.querySelector('[data-bc-filter="isSpecial"]').checked = !!state.filters.isSpecial;
		const uncat = form.querySelector('[data-bc-filter="uncategorized"]');
		if (uncat) uncat.checked = !!state.filters.uncategorized;
		const status = form.querySelector('[data-bc-filter="statusId"]');
		if (status) status.value = state.filters.statusId || '';
	}

	function wireFilterForm() {
		const form = document.querySelector('[data-bc-tx-filters]');
		if (!form) return;
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
		form.addEventListener('reset', () => {
			window.setTimeout(() => {
				state.filters = { from: '', to: '', categoryId: '', statusId: '', q: '', isSpecial: false, uncategorized: false };
				state.offset = 0;
				loadAndRender();
			}, 0);
		});
	}

	function collectFilters(form) {
		const fromRaw = form.querySelector('[data-bc-filter="from"]').value.trim();
		const toRaw = form.querySelector('[data-bc-filter="to"]').value.trim();
		const invalidMsg = t('budgetcheck', 'Invalid calendar date.');
		let fromIso = '';
		if (fromRaw !== '') {
			if (!Dates.isIsoCalendarDay(fromRaw)) throw new Error(invalidMsg);
			fromIso = fromRaw;
		}
		let toIso = '';
		if (toRaw !== '') {
			if (!Dates.isIsoCalendarDay(toRaw)) throw new Error(invalidMsg);
			toIso = toRaw;
		}
		state.filters.from = fromIso;
		state.filters.to = toIso;
		state.filters.categoryId = form.querySelector('[data-bc-filter="categoryId"]').value || '';
		const statusEl = form.querySelector('[data-bc-filter="statusId"]');
		state.filters.statusId = statusEl ? (statusEl.value || '') : '';
		state.filters.q = form.querySelector('[data-bc-filter="q"]').value.trim();
		state.filters.isSpecial = form.querySelector('[data-bc-filter="isSpecial"]').checked;
		const uncatEl = form.querySelector('[data-bc-filter="uncategorized"]');
		state.filters.uncategorized = uncatEl ? uncatEl.checked : false;
	}

	async function loadCategoriesIntoSelect() {
		try {
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id });
			state.categories = data.categories || [];
			const select = document.querySelector('[data-bc-category-select]');
			if (!select) return;
			state.categories.forEach((cat) => {
				select.appendChild(C.createElement('option', {
					value: String(cat.id),
					text: cat.name + ' (' + (cat.type === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense')) + ')',
				}));
			});
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function loadStatusesIntoSelect() {
		if (!Ws.workspace || Ws.workspace.type !== 'project') return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/booking-statuses', { workspaceId: Ws.workspace.id });
			state.statuses = data.statuses || [];
			const select = document.querySelector('[data-bc-status-select]');
			if (!select) return;
			state.statuses.forEach((status) => {
				select.appendChild(C.createElement('option', { value: String(status.id), text: status.name }));
			});
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function loadAndRender() {
		const tbody = document.querySelector('[data-bc-tx-rows]');
		if (!tbody) return;
		tbody.replaceChildren(C.createElement('tr', null, [
			C.createElement('td', { attrs: { colspan: String(tableColumnCount()) }, class: 'bc-loading', text: t('budgetcheck', 'Loading…') }),
		]));
		try {
			const params = {
				workspaceId: Ws.workspace.id,
				limit: PAGE_SIZE,
				offset: state.offset,
			};
			if (state.filters.from) params.from = state.filters.from;
			if (state.filters.to) params.to = state.filters.to;
			if (state.filters.categoryId) params.categoryId = state.filters.categoryId;
			if (state.filters.statusId) params.statusId = state.filters.statusId;
			if (state.filters.q) params.q = state.filters.q;
			if (state.filters.isSpecial) params.isSpecial = '1';
			if (state.filters.uncategorized) params.uncategorized = '1';
			const data = await Api.get('/apps/budgetcheck/api/transactions', params);
			renderRows(tbody, data.items || []);
			renderMeta(data);
		} catch (err) {
			Msg.handleApiError(err);
			tbody.replaceChildren(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: String(tableColumnCount()) }, class: 'bc-loading', text: t('budgetcheck', 'Could not load transactions.') }),
			]));
		}
	}

	function renderRows(tbody, items) {
		tbody.replaceChildren();
		if (items.length === 0) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: String(tableColumnCount()) }, class: 'bc-loading', text: t('budgetcheck', 'No transactions match these filters.') }),
			]));
			return;
		}
		items.forEach((tx) => tbody.appendChild(renderRow(tx)));
	}

	function renderRow(tx) {
		const cat = state.categories.find((c) => c.id === tx.categoryId);
		const tags = [];
		if (tx.isSpecial) tags.push(t('budgetcheck', 'Special'));
		if (tx.entryAmountBasis === 'gross') tags.push(t('budgetcheck', 'Gross'));
		else if (tx.entryAmountBasis === 'net') tags.push(t('budgetcheck', 'Net'));
		else if (tx.entryAmountBasis && tx.entryAmountBasis !== 'simple') tags.push(String(tx.entryAmountBasis));
		if (Number.isInteger(tx.vatRateBp)) {
			tags.push(t('budgetcheck', 'VAT {rate}%').replace('{rate}', (tx.vatRateBp / 100).toString()));
		}
		const directionLabel = tx.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense');
		const bookingStatus = state.statuses.find((status) => status.id === tx.bookingStatusId);
		const tr = C.createElement('tr');
		tr.appendChild(C.createElement('td', { text: Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang) }));
		tr.appendChild(C.createElement('td', { text: tx.title }));
		tr.appendChild(C.createElement('td', { text: cat ? cat.name : '#' + tx.categoryId }));
		const amountTd = C.createElement('td', { class: 'bc-table__col--num bc-tx-amount--' + tx.direction });
		amountTd.appendChild(C.createElement('span', { class: 'bc-tx-amount__value', text: (tx.direction === 'income' ? '+' : '−') + ' ' + Money.formatEnvelope(tx.amount, Ws.htmlLang) }));
		if (tx.entryAmountBasis && tx.entryAmountBasis !== 'simple' && tx.net && tx.vat && tx.gross) {
			amountTd.appendChild(C.createElement('div', {
				class: 'bc-tx-amount__meta',
				text: t('budgetcheck', 'Net {net} · VAT {vat} · Gross {gross}')
					.replace('{net}', Money.formatEnvelope(tx.net, Ws.htmlLang))
					.replace('{vat}', Money.formatEnvelope(tx.vat, Ws.htmlLang))
					.replace('{gross}', Money.formatEnvelope(tx.gross, Ws.htmlLang)),
			}));
		}
		tr.appendChild(amountTd);
		tr.appendChild(C.createElement('td', { text: directionLabel }));
		if (Ws.workspace && Ws.workspace.type === 'project') {
			tr.appendChild(C.createElement('td', { text: bookingStatus ? bookingStatus.name : '—' }));
		}
		tr.appendChild(C.createElement('td', { text: tags.join(', ') }));
		if (Ws.canContribute) {
			const actionsTd = C.createElement('td');
			const editBtn = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Edit') });
			editBtn.addEventListener('click', () => openEditModal(tx));
			actionsTd.appendChild(editBtn);
			const delBtn = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Delete') });
			delBtn.addEventListener('click', () => deleteTransaction(tx));
			actionsTd.appendChild(delBtn);
			tr.appendChild(actionsTd);
		}
		return tr;
	}

	function renderMeta(data) {
		const window_ = data.window || {};
		const windowEl = document.querySelector('[data-bc-tx-window]');
		if (windowEl) {
			windowEl.textContent = window_.from && window_.to
				? Dates.formatDisplayDate(window_.from, Ws.htmlLang) + ' – ' + Dates.formatDisplayDate(window_.to, Ws.htmlLang)
				: '';
		}
		const countEl = document.querySelector('[data-bc-tx-count]');
		if (countEl) {
			countEl.textContent = (data.total !== undefined ? data.total : (data.items || []).length) + ' '
				+ t('budgetcheck', 'entries');
		}
		const pagination = document.querySelector('[data-bc-tx-pagination]');
		if (!pagination) return;
		pagination.replaceChildren();
		const total = Number(data.total || 0);
		const page = Math.floor(state.offset / PAGE_SIZE) + 1;
		const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
		const prev = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Previous') });
		prev.disabled = state.offset <= 0;
		prev.addEventListener('click', () => { state.offset = Math.max(0, state.offset - PAGE_SIZE); loadAndRender(); });
		const status = C.createElement('span', { class: 'bc-pill', text: t('budgetcheck', 'Page {page} of {pages}').replace('{page}', String(page)).replace('{pages}', String(pages)) });
		const next = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Next') });
		next.disabled = state.offset + PAGE_SIZE >= total;
		next.addEventListener('click', () => { state.offset += PAGE_SIZE; loadAndRender(); });
		pagination.appendChild(prev);
		pagination.appendChild(status);
		pagination.appendChild(next);
	}

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
})();
