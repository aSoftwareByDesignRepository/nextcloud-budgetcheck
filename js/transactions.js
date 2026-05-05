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
		filters: { from: '', to: '', categoryId: '', q: '', isSpecial: false, uncategorized: false },
		offset: 0,
		categories: [],
	};

	document.addEventListener('DOMContentLoaded', () => {
		if (!Ws.workspace) return;
		hydrateInitialFilters();
		wireFilterForm();
		wireCreateButton();
		loadCategoriesIntoSelect();
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
				state.filters = { from: '', to: '', categoryId: '', q: '', isSpecial: false, uncategorized: false };
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

	async function loadAndRender() {
		const tbody = document.querySelector('[data-bc-tx-rows]');
		if (!tbody) return;
		tbody.replaceChildren(C.createElement('tr', null, [
			C.createElement('td', { attrs: { colspan: '7' }, class: 'bc-loading', text: t('budgetcheck', 'Loading…') }),
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
			if (state.filters.q) params.q = state.filters.q;
			if (state.filters.isSpecial) params.isSpecial = '1';
			if (state.filters.uncategorized) params.uncategorized = '1';
			const data = await Api.get('/apps/budgetcheck/api/transactions', params);
			renderRows(tbody, data.items || []);
			renderMeta(data);
		} catch (err) {
			Msg.handleApiError(err);
			tbody.replaceChildren(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '7' }, class: 'bc-loading', text: t('budgetcheck', 'Could not load transactions.') }),
			]));
		}
	}

	function renderRows(tbody, items) {
		tbody.replaceChildren();
		if (items.length === 0) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '7' }, class: 'bc-loading', text: t('budgetcheck', 'No transactions match these filters.') }),
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
		const directionLabel = tx.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense');
		const tr = C.createElement('tr');
		tr.appendChild(C.createElement('td', { text: Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang) }));
		tr.appendChild(C.createElement('td', { text: tx.title }));
		tr.appendChild(C.createElement('td', { text: cat ? cat.name : '#' + tx.categoryId }));
		const amountTd = C.createElement('td', { class: 'bc-table__col--num bc-tx-amount--' + tx.direction });
		amountTd.appendChild(C.createElement('span', { class: 'bc-tx-amount__value', text: (tx.direction === 'income' ? '+' : '−') + ' ' + Money.formatEnvelope(tx.amount, Ws.htmlLang) }));
		tr.appendChild(amountTd);
		tr.appendChild(C.createElement('td', { text: directionLabel }));
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
				wrapField(form, t('budgetcheck', 'Direction'), directionSelect);

				const titleInput = C.createElement('input', { name: 'title', type: 'text', class: 'bc-input', maxlength: 180, required: true });
				titleInput.value = tx ? tx.title : '';
				wrapField(form, t('budgetcheck', 'Title'), titleInput);

				const dateDh = 'bc-tx-booking-' + Math.random().toString(36).slice(2);
				const dateInput = C.createElement('input', {
					name: 'bookingDate',
					type: 'date',
					class: 'bc-input',
					autocomplete: 'off',
					required: true,
					value: tx ? String(tx.bookingDate) : Dates.isoDate(new Date()),
					attrs: { 'aria-describedby': dateDh, lang: Ws.htmlLang },
				});
				const dateHint = C.createElement('span', { id: dateDh, class: 'bc-field__hint', text: dateHintText });
				form.appendChild(C.createElement('label', { class: 'bc-field' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Date') }),
					dateInput,
					dateHint,
				]));

				const amountInput = C.createElement('input', {
					name: 'amount', type: 'text', inputmode: 'decimal', class: 'bc-input', required: true,
					attrs: { 'aria-label': t('budgetcheck', 'Amount') },
				});
				amountInput.value = tx ? String(tx.amount.minor / Math.pow(10, activeDecimals())).replace('.', ',') : '';
				wrapField(form, t('budgetcheck', 'Amount'), amountInput);

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
				wrapField(form, t('budgetcheck', 'Category'), catSelect);

				const specialOuter = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' });
				specialOuter.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Special') }));
				const specialRow = C.createElement('span', { class: 'bc-boolean-control' });
				const specialInput = C.createElement('input', { type: 'checkbox', name: 'isSpecial', value: '1' });
				specialInput.checked = !!(tx && tx.isSpecial);
				specialRow.appendChild(specialInput);
				specialRow.appendChild(C.createElement('span', { class: 'bc-boolean-control__text', text: t('budgetcheck', 'Mark as special (large/unusual entry)') }));
				specialOuter.appendChild(specialRow);
				form.appendChild(specialOuter);

				const notesArea = C.createElement('textarea', { name: 'notes', class: 'bc-input', maxlength: 4000, rows: 3 });
				notesArea.value = tx && tx.notes ? tx.notes : '';
				wrapField(form, t('budgetcheck', 'Notes'), notesArea);

				form._collect = () => ({
					workspaceId: Ws.workspace.id,
					title: titleInput.value.trim(),
					direction: directionSelect.value,
					bookingDate: dateInput.value.trim(),
					amount: amountInput.value,
					categoryId: catSelect.value ? Number.parseInt(catSelect.value, 10) : 0,
					isSpecial: specialInput.checked,
					notes: notesArea.value.trim(),
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

	function wrapField(form, label, control) {
		const wrap = C.createElement('label', { class: 'bc-field' }, [
			C.createElement('span', { class: 'bc-field__label', text: label }),
			control,
		]);
		form.appendChild(wrap);
	}
})();
