(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;
	const EntityPicker = window.BudgetCheckEntityPicker;

	let capabilities = null;

	document.addEventListener('DOMContentLoaded', () => {
		bootstrap();
	});

	async function bootstrap() {
		const needsCaps = document.querySelector('[data-bc-timezone-select]')
			|| document.querySelector('[data-bc-currency-select]');
		if (needsCaps) {
			try {
				const data = await Api.get('/apps/budgetcheck/api/workspaces');
				capabilities = data.capabilities || {};
			} catch (err) {
				Msg.handleApiError(err);
			}
		}
		populateTimezoneOptions();
		populateWorkspaceCurrencySelect();
		hydrateWorkspaceForm();
		hydrateTaxForm();
		wireWorkspaceForm();
		wireTaxForm();
		wireVatPresetUi();
		if (Ws.canManage) {
			loadCategories();
			loadMembers();
			initMemberInvite();
			loadRecurring();
		} else if (Ws.workspace) {
			loadCategories();
		}
		if (Ws.workspace && Ws.workspace.type === 'project') {
			loadBookingStatuses();
		}
		wireHelpPanels();
	}

	function wireHelpPanels() {
		const bridge = document.getElementById('bc-spreadsheet-bridge');
		const panels = document.getElementById('bc-help-panels');
		if (!bridge || !panels) {
			return;
		}
		if (localStorage.getItem('bc_spreadsheet_bridge_hidden') === '1') {
			bridge.remove();
			if (!panels.querySelector('details')) {
				panels.remove();
			}
			return;
		}
		const btn = bridge.querySelector('[data-bc-dismiss-bridge]');
		btn?.addEventListener('click', () => {
			localStorage.setItem('bc_spreadsheet_bridge_hidden', '1');
			bridge.remove();
			if (!panels.querySelector('details')) {
				panels.remove();
			}
		});
	}

	function fillTimezoneSelect(selectEl, selectedTz) {
		if (!selectEl || !capabilities || !capabilities.timezones) return;
		selectEl.replaceChildren();
		capabilities.timezones.forEach((g) => {
			const og = C.createElement('optgroup', { attrs: { label: g.label } });
			(g.items || []).forEach((tz) => og.appendChild(C.createElement('option', { value: tz, text: tz })));
			selectEl.appendChild(og);
		});
		if (selectedTz) {
			selectEl.value = selectedTz;
		}
	}

	function populateTimezoneOptions() {
		fillTimezoneSelect(document.querySelector('[data-bc-timezone-select]'), Ws.workspace?.timezone);
	}

	function fillCurrencySelect(selectEl, selectedCode) {
		if (!selectEl || !capabilities || !capabilities.currencies) return;
		selectEl.replaceChildren();
		(capabilities.currencies || []).forEach((entry) => {
			const code = typeof entry === 'string' ? entry : (entry && entry.code);
			if (!code) return;
			selectEl.appendChild(C.createElement('option', { value: code, text: code }));
		});
		if (selectedCode) {
			selectEl.value = String(selectedCode).toUpperCase();
		}
	}

	function populateWorkspaceCurrencySelect() {
		const sel = document.querySelector('[data-bc-currency-select]');
		if (!sel || !Ws.workspace) return;
		fillCurrencySelect(sel, Ws.workspace.currencyCode);
	}

	function hydrateWorkspaceForm() {
		if (!Ws.workspace) return;
		const form = document.querySelector('[data-bc-workspace-form]');
		if (!form) return;
		setVal(form, 'name', Ws.workspace.name);
		populateWorkspaceCurrencySelect();
		setVal(form, 'overspendThresholdMinor', Ws.workspace.overspendThresholdMinor !== null ? String(Ws.workspace.overspendThresholdMinor) : '');
		if (Ws.workspace.type === 'household') {
			const pyFromWs = typeof Ws.workspace.primaryPlanningYear === 'number' ? Ws.workspace.primaryPlanningYear : null;
			const pyFromCreated = Number.parseInt(String(Ws.workspace.createdAt || '').slice(0, 4), 10);
			const py = pyFromWs !== null && pyFromWs >= 1900 && pyFromWs <= 9999
				? pyFromWs
				: (Number.isFinite(pyFromCreated) && pyFromCreated >= 1900 ? pyFromCreated : new Date().getFullYear());
			setVal(form, 'primaryPlanningYear', String(py));
			const cb = form.querySelector('input[name="autoCopyBudgetsFromPreviousMonth"]');
			if (cb) cb.checked = !!Ws.workspace.autoCopyBudgetsFromPreviousMonth;
		} else {
			setVal(form, 'projectStartDate', Ws.workspace.projectStartDate ? String(Ws.workspace.projectStartDate) : '');
			setVal(form, 'projectEndDate', Ws.workspace.projectEndDate ? String(Ws.workspace.projectEndDate) : '');
			setVal(
				form,
				'projectTotalCapMinor',
				Ws.workspace.projectTotalCapMinor !== null
					? formatMinorForInput(Ws.workspace.projectTotalCapMinor, workspaceCurrencyDecimals())
					: ''
			);
		}
	}

	function workspaceCurrencyDecimals() {
		return typeof Ws.workspace?.currencyDecimals === 'number'
			? Ws.workspace.currencyDecimals
			: (Ws.workspace?.currencyCode === 'JPY' ? 0 : 2);
	}

	function formatMinorForInput(minor, decimals) {
		const div = Math.pow(10, decimals);
		return (Number(minor) / div).toFixed(decimals);
	}

	function hydrateTaxForm() {
		if (!Ws.workspace) return;
		const form = document.querySelector('[data-bc-tax-form]');
		if (!form) return;
		const enabled = form.querySelector('input[name="taxModeEnabled"]');
		if (enabled) enabled.checked = !!Ws.workspace.taxModeEnabled;
		setVal(form, 'taxBudgetBasis', Ws.workspace.taxBudgetBasis || 'gross');
		const presetSelect = form.querySelector('[data-bc-vat-preset]');
		const customWrap = form.querySelector('[data-bc-vat-custom-wrap]');
		const customInput = form.querySelector('[data-bc-vat-custom]');
		if (!presetSelect || !customWrap || !customInput) return;
		const bp = Ws.workspace.defaultVatRateBp;
		const fixed = Array.from(presetSelect.options).map((o) => o.value).filter((v) => v !== 'custom');
		if (bp === null || bp === undefined) {
			presetSelect.value = '0';
			customWrap.hidden = true;
			customInput.value = '';
		} else if (fixed.includes(String(bp))) {
			presetSelect.value = String(bp);
			customWrap.hidden = true;
			customInput.value = '';
		} else {
			presetSelect.value = 'custom';
			customWrap.hidden = false;
			customInput.value = String(bp);
		}
	}

	function wireVatPresetUi() {
		const form = document.querySelector('[data-bc-tax-form]');
		if (!form) return;
		const presetSelect = form.querySelector('[data-bc-vat-preset]');
		const customWrap = form.querySelector('[data-bc-vat-custom-wrap]');
		if (!presetSelect || !customWrap) return;
		const sync = () => { customWrap.hidden = presetSelect.value !== 'custom'; };
		presetSelect.addEventListener('change', sync);
		sync();
	}

	function wireWorkspaceForm() {
		const form = document.querySelector('[data-bc-workspace-form]');
		if (!form || !Ws.workspace) return;
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!Ws.canManage) return;
			const payload = {
				name: getVal(form, 'name').trim(),
				currencyCode: getVal(form, 'currencyCode').trim().toUpperCase(),
				timezone: getVal(form, 'timezone'),
				overspendThresholdMinor: getVal(form, 'overspendThresholdMinor').trim() || null,
			};
			if (Ws.workspace.type === 'household') {
				const pyRaw = getVal(form, 'primaryPlanningYear').trim();
				const py = Number.parseInt(pyRaw, 10);
				if (!Number.isFinite(py) || py < 1900 || py > 9999) {
					Msg.announce(t('budgetcheck', 'Primary planning year must be between 1900 and 9999.'), 'error');
					return;
				}
				payload.primaryPlanningYear = py;
				payload.autoCopyBudgetsFromPreviousMonth = !!form.querySelector('input[name="autoCopyBudgetsFromPreviousMonth"]')?.checked;
			} else {
				const startRaw = getVal(form, 'projectStartDate').trim();
				const endRaw = getVal(form, 'projectEndDate').trim();
				if (!Dates.isIsoCalendarDay(startRaw) || !Dates.isIsoCalendarDay(endRaw)) {
					Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
					return;
				}
				payload.projectStartDate = startRaw;
				payload.projectEndDate = endRaw;
				const cap = getVal(form, 'projectTotalCapMinor').trim();
				if (cap === '') {
					payload.projectTotalCapMinor = null;
				} else {
					try {
						payload.projectTotalCapMinor = Money.parseHuman(cap, workspaceCurrencyDecimals());
					} catch (err) {
						Msg.announce(err.message || t('budgetcheck', 'Amount is not a valid number.'), 'error');
						return;
					}
				}
			}
			if (payload.overspendThresholdMinor !== null) {
				payload.overspendThresholdMinor = Number.parseInt(payload.overspendThresholdMinor, 10);
			}
			try {
				await Api.put('/apps/budgetcheck/api/workspaces/' + Ws.workspace.id, payload);
				Msg.announce(t('budgetcheck', 'Workspace saved.'), 'success');
				window.setTimeout(() => window.location.reload(), 600);
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	function wireTaxForm() {
		const form = document.querySelector('[data-bc-tax-form]');
		if (!form || !Ws.workspace) return;
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!Ws.canManage) return;
			const preset = getVal(form, 'vatPreset');
			let defaultVatRateBp = null;
			if (preset === 'custom') {
				const raw = getVal(form, 'defaultVatRateBp').trim();
				defaultVatRateBp = raw === '' ? null : Number.parseInt(raw, 10);
			} else if (preset !== '') {
				defaultVatRateBp = Number.parseInt(preset, 10);
			}
			const payload = {
				taxModeEnabled: !!form.querySelector('input[name="taxModeEnabled"]')?.checked,
				taxBudgetBasis: getVal(form, 'taxBudgetBasis') || 'gross',
				defaultVatRateBp,
			};
			try {
				await Api.put('/apps/budgetcheck/api/workspaces/' + Ws.workspace.id + '/tax-mode', payload);
				Msg.announce(t('budgetcheck', 'Tax settings saved.'), 'success');
				window.setTimeout(() => window.location.reload(), 600);
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	function taxHandlingModeLabel(mode) {
		switch (String(mode || '')) {
			case 'inherit_workspace':
				return t('budgetcheck', 'Inherit workspace setting');
			case 'taxable':
				return t('budgetcheck', 'Taxable');
			case 'tax_exempt':
				return t('budgetcheck', 'Tax exempt');
			default:
				return String(mode || '');
		}
	}

	function recurringFrequencyLabel(frequency) {
		switch (String(frequency || '')) {
			case 'monthly':
				return t('budgetcheck', 'Monthly');
			case 'quarterly':
				return t('budgetcheck', 'Quarterly');
			case 'yearly':
				return t('budgetcheck', 'Yearly');
			case 'custom_interval':
				return t('budgetcheck', 'Custom (months)');
			default:
				return String(frequency || '');
		}
	}

	const INTERNAL_UNCATEGORIZED_GROUP = window.BudgetCheckConstants.GROUP_INTERNAL_UNCATEGORIZED;

	function categoryGroupKeyLabel(groupKey) {
		if (!groupKey) return '—';
		if (groupKey === INTERNAL_UNCATEGORIZED_GROUP) {
			return t('budgetcheck', 'Built-in (uncategorized)');
		}
		return groupKey;
	}

	// ------ Categories ------
	async function loadBookingStatuses() {
		const tbody = document.querySelector('[data-bc-booking-status-rows]');
		if (!tbody || !Ws.workspace || Ws.workspace.type !== 'project') return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/booking-statuses', { workspaceId: Ws.workspace.id, includeInactive: '1' });
			tbody.replaceChildren();
			(data.statuses || []).forEach((status) => tbody.appendChild(renderBookingStatusRow(status)));
			if ((data.statuses || []).length === 0) {
				tbody.appendChild(C.createElement('tr', null, [
					C.createElement('td', { attrs: { colspan: Ws.canManage ? '4' : '3' }, class: 'bc-loading', text: t('budgetcheck', 'No booking statuses yet.') }),
				]));
			}
		} catch (err) {
			Msg.handleApiError(err);
		}
		document.querySelectorAll('[data-bc-action="open-create-booking-status"]').forEach((btn) => {
			if (btn.dataset.bcBookingStatusWired === '1') return;
			btn.dataset.bcBookingStatusWired = '1';
			btn.addEventListener('click', () => openBookingStatusModal(null));
		});
	}

	function renderBookingStatusRow(status) {
		const tr = C.createElement('tr');
		tr.appendChild(C.createElement('td', { text: status.name }));
		tr.appendChild(C.createElement('td', { text: String(status.sortOrder) }));
		tr.appendChild(C.createElement('td', { text: status.isActive ? t('budgetcheck', 'Active') : t('budgetcheck', 'Inactive') }));
		if (Ws.canManage) {
			const actions = C.createElement('td');
			const edit = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Edit') });
			edit.addEventListener('click', () => openBookingStatusModal(status));
			actions.appendChild(edit);
			if (status.isActive) {
				const deactivate = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Deactivate') });
				deactivate.addEventListener('click', () => deactivateBookingStatus(status));
				actions.appendChild(deactivate);
			}
			tr.appendChild(actions);
		}
		return tr;
	}

	function openBookingStatusModal(status) {
		const isEdit = !!status;
		C.openModal({
			title: isEdit ? t('budgetcheck', 'Edit booking status') : t('budgetcheck', 'New booking status'),
			primaryLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add status'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const name = C.createElement('input', { type: 'text', class: 'bc-input', maxlength: 80, required: true, value: status ? status.name : '' });
				wrap(form, t('budgetcheck', 'Name'), name, t('budgetcheck', 'Short label shown in filters and booking lists.'));
				const order = C.createElement('input', { type: 'number', class: 'bc-input', min: '0', max: '10000', step: '1', value: status ? String(status.sortOrder) : '100' });
				wrap(form, t('budgetcheck', 'Order'), order, t('budgetcheck', 'Lower numbers appear first in status pickers.'));
				form._collect = () => ({
					workspaceId: Ws.workspace.id,
					name: name.value.trim(),
					sortOrder: Number.parseInt(order.value, 10) || 100,
				});
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const payload = body && body._collect ? body._collect() : null;
				if (!payload) return false;
				try {
					if (isEdit) {
						await Api.put('/apps/budgetcheck/api/booking-statuses/' + status.id, payload);
						Msg.announce(t('budgetcheck', 'Booking status updated.'), 'success');
					} else {
						await Api.post('/apps/budgetcheck/api/booking-statuses', payload);
						Msg.announce(t('budgetcheck', 'Booking status created.'), 'success');
					}
					await loadBookingStatuses();
					close(true);
				} catch (err) {
					Msg.handleApiError(err);
					return false;
				}
			},
		});
	}

	async function deactivateBookingStatus(status) {
		const ok = await C.confirmDialog({
			title: t('budgetcheck', 'Deactivate this status?'),
			body: t('budgetcheck', 'Bookings currently using it will be reset to no status.'),
			confirmLabel: t('budgetcheck', 'Deactivate'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.post('/apps/budgetcheck/api/booking-statuses/' + status.id + '/deactivate');
			Msg.announce(t('budgetcheck', 'Booking status deactivated.'), 'success');
			loadBookingStatuses();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	// ------ Categories ------
	async function loadCategories() {
		const tbody = document.querySelector('[data-bc-category-rows]');
		if (!tbody) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id, includeInactive: '1' });
			tbody.replaceChildren();
			(data.categories || []).forEach((c) => tbody.appendChild(renderCategoryRow(c)));
			if ((data.categories || []).length === 0) {
				tbody.appendChild(C.createElement('tr', null, [
					C.createElement('td', { attrs: { colspan: '7' }, class: 'bc-loading', text: t('budgetcheck', 'No categories yet.') }),
				]));
			}
		} catch (err) {
			Msg.handleApiError(err);
		}
		document.querySelectorAll('[data-bc-action="open-create-category"]').forEach((btn) => {
			btn.addEventListener('click', () => { void openCategoryModal(null); });
		});
	}

	function renderCategoryRow(cat) {
		const tr = C.createElement('tr');
		tr.appendChild(C.createElement('td', { text: cat.name }));
		tr.appendChild(C.createElement('td', { text: cat.type === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense') }));
		tr.appendChild(C.createElement('td', { text: categoryGroupKeyLabel(cat.groupKey) }));
		tr.appendChild(C.createElement('td', { text: cat.isSpecial ? t('budgetcheck', 'Yes') : t('budgetcheck', 'No') }));
		tr.appendChild(C.createElement('td', { text: taxHandlingModeLabel(cat.taxHandlingMode) }));
		tr.appendChild(C.createElement('td', { text: cat.isActive ? t('budgetcheck', 'Active') : t('budgetcheck', 'Inactive') }));
		if (Ws.canManage) {
			const actions = C.createElement('td');
			const edit = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Edit') });
			edit.addEventListener('click', () => { void openCategoryModal(cat); });
			actions.appendChild(edit);
			const canDeactivate = cat.isActive && cat.groupKey !== INTERNAL_UNCATEGORIZED_GROUP;
			if (canDeactivate) {
				const off = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Deactivate') });
				off.addEventListener('click', () => deactivateCategory(cat));
				actions.appendChild(off);
			}
			tr.appendChild(actions);
		}
		return tr;
	}

	async function openCategoryModal(cat) {
		const isEdit = !!cat;
		let groupKeys = [];
		try {
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id, includeInactive: '1' });
			groupKeys = data.groupKeys || [];
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		C.openModal({
			title: isEdit ? t('budgetcheck', 'Edit category') : t('budgetcheck', 'New category'),
			primaryLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add category'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const name = C.createElement('input', { type: 'text', name: 'name', class: 'bc-input', maxlength: 120, required: true, value: cat ? cat.name : '' });
				wrap(form, t('budgetcheck', 'Name'), name, t('budgetcheck', 'Give the category a clear name people will recognize quickly.'));
				const typeSelect = C.createElement('select', { name: 'type', class: 'bc-input', disabled: isEdit }, [
					C.createElement('option', { value: 'expense', text: t('budgetcheck', 'Expense') }),
					C.createElement('option', { value: 'income', text: t('budgetcheck', 'Income') }),
				]);
				typeSelect.value = cat ? cat.type : 'expense';
				wrap(form, t('budgetcheck', 'Direction'), typeSelect, t('budgetcheck', 'Choose whether this category is used for incoming or outgoing money.'));
				const groupSelect = C.createElement('select', { name: 'groupKeyChoice', class: 'bc-input' });
				groupSelect.appendChild(C.createElement('option', { value: '', text: t('budgetcheck', 'No group') }));
				const sorted = [...new Set(groupKeys)].sort();
				sorted.forEach((k) => groupSelect.appendChild(C.createElement('option', { value: k, text: k })));
				groupSelect.appendChild(C.createElement('option', { value: '__new__', text: t('budgetcheck', 'New group…') }));
				const currentGk = cat && cat.groupKey ? String(cat.groupKey) : '';
				const groupCustom = C.createElement('input', { type: 'text', name: 'groupKeyCustom', class: 'bc-input', maxlength: 64, attrs: { autocomplete: 'off' } });
				const syncGroupCustom = () => {
					const show = groupSelect.value === '__new__';
					groupCustom.hidden = !show;
					customWrap.hidden = !show;
				};
				groupSelect.addEventListener('change', syncGroupCustom);
				wrap(form, t('budgetcheck', 'Group'), groupSelect, t('budgetcheck', 'Optional: group related categories together (for example Home, Travel).'));
				const customWrap = C.createElement('label', { class: 'bc-field', attrs: { 'data-bc-group-custom': '1' } });
				customWrap.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'New group name') }));
				customWrap.appendChild(groupCustom);
				if (currentGk === '') {
					groupSelect.value = '';
					groupCustom.value = '';
				} else if (sorted.includes(currentGk)) {
					groupSelect.value = currentGk;
					groupCustom.value = '';
				} else {
					groupSelect.value = '__new__';
					groupCustom.value = currentGk;
				}
				syncGroupCustom();
				form.appendChild(customWrap);
				const taxSelect = C.createElement('select', { name: 'taxHandlingMode', class: 'bc-input' }, [
					C.createElement('option', { value: 'inherit_workspace', text: t('budgetcheck', 'Inherit workspace setting') }),
					C.createElement('option', { value: 'taxable', text: t('budgetcheck', 'Taxable') }),
					C.createElement('option', { value: 'tax_exempt', text: t('budgetcheck', 'Tax exempt') }),
				]);
				taxSelect.value = cat ? cat.taxHandlingMode : 'inherit_workspace';
				wrap(form, t('budgetcheck', 'Tax handling'), taxSelect, t('budgetcheck', 'Controls how this category behaves when tax mode is enabled.'));
				const specialOuter = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' });
				specialOuter.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Category options') }));
				const specialRow = C.createElement('span', { class: 'bc-boolean-control' });
				const specialInput = C.createElement('input', { type: 'checkbox', name: 'isSpecial', value: '1' });
				specialInput.checked = !!(cat && cat.isSpecial);
				specialRow.appendChild(specialInput);
				specialRow.appendChild(C.createElement('span', { class: 'bc-boolean-control__text', text: t('budgetcheck', 'Enable this if entries in this category are usually one-off or exceptional.') }));
				specialOuter.appendChild(specialRow);
				form.appendChild(specialOuter);
				form._collect = () => {
					let groupKey = null;
					const choice = groupSelect.value;
					if (choice === '__new__') {
						const raw = groupCustom.value.trim();
						groupKey = raw === '' ? null : raw;
					} else if (choice === '') {
						groupKey = null;
					} else {
						groupKey = choice;
					}
					return {
						workspaceId: Ws.workspace.id,
						name: name.value.trim(),
						type: typeSelect.value,
						groupKey,
						taxHandlingMode: taxSelect.value,
						isSpecial: specialInput.checked,
					};
				};
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				const payload = form && form._collect ? form._collect() : null;
				if (!payload) return false;
				try {
					if (isEdit) {
						await Api.put('/apps/budgetcheck/api/categories/' + cat.id, payload);
						Msg.announce(t('budgetcheck', 'Category updated.'), 'success');
					} else {
						await Api.post('/apps/budgetcheck/api/categories', payload);
						Msg.announce(t('budgetcheck', 'Category created.'), 'success');
					}
					await loadCategories();
					close(true);
				} catch (err) {
					Msg.handleApiError(err, { reloadOnConflict: false });
					return false;
				}
			},
		});
	}

	async function deactivateCategory(cat) {
		const ok = await C.confirmDialog({
			title: t('budgetcheck', 'Deactivate this category?'),
			body: t('budgetcheck', 'Historical entries keep using it; new transactions cannot pick it.'),
			confirmLabel: t('budgetcheck', 'Deactivate'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.post('/apps/budgetcheck/api/categories/' + cat.id + '/deactivate');
			Msg.announce(t('budgetcheck', 'Category deactivated.'), 'success');
			loadCategories();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	// ------ Members ------
	async function loadMembers() {
		const tbody = document.querySelector('[data-bc-member-rows]');
		if (!tbody || !Ws.workspace) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/workspaces/' + Ws.workspace.id + '/members');
			tbody.replaceChildren();
			(data.members || []).forEach((m) => tbody.appendChild(renderMemberRow(m)));
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	function renderMemberRow(member) {
		const tr = C.createElement('tr');
		tr.dataset.bcUserId = member.userId;
		tr.appendChild(C.createElement('td', { text: member.displayName + ' (' + member.userId + ')' }));
		const roleSelect = C.createElement('select', { class: 'bc-input' }, [
			C.createElement('option', { value: 'manager', text: t('budgetcheck', 'Manager') }),
			C.createElement('option', { value: 'contributor', text: t('budgetcheck', 'Contributor') }),
			C.createElement('option', { value: 'viewer', text: t('budgetcheck', 'Viewer') }),
		]);
		roleSelect.value = member.role;
		roleSelect.addEventListener('change', async () => {
			try {
				await Api.put('/apps/budgetcheck/api/workspace-members/' + member.id, { role: roleSelect.value });
				Msg.announce(t('budgetcheck', 'Role updated.'), 'success');
				loadMembers();
			} catch (err) {
				Msg.handleApiError(err);
				roleSelect.value = member.role;
			}
		});
		const roleTd = C.createElement('td');
		roleTd.appendChild(roleSelect);
		tr.appendChild(roleTd);
		tr.appendChild(C.createElement('td', {
			text: Dates.formatDisplayDateTime(member.createdAt, Ws.htmlLang),
		}));
		const actions = C.createElement('td');
		const remove = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Remove') });
		remove.addEventListener('click', async () => {
			const ok = await C.confirmDialog({
				title: t('budgetcheck', 'Remove this member?'),
				body: t('budgetcheck', 'They will lose access to this workspace.'),
				confirmLabel: t('budgetcheck', 'Remove'),
				danger: true,
			});
			if (!ok) return;
			try {
				await Api.del('/apps/budgetcheck/api/workspace-members/' + member.id);
				Msg.announce(t('budgetcheck', 'Member removed.'), 'success');
				loadMembers();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
		actions.appendChild(remove);
		tr.appendChild(actions);
		return tr;
	}

	function currentMemberUserIds() {
		const ids = new Set();
		document.querySelectorAll('[data-bc-member-rows] tr[data-bc-user-id]').forEach((tr) => {
			const id = tr.getAttribute('data-bc-user-id');
			if (id) ids.add(id);
		});
		return ids;
	}

	function initMemberInvite() {
		const root = document.querySelector('[data-bc-member-invite]');
		if (!root || !Ws.workspace || !EntityPicker || root.dataset.bcInviteWired === '1') {
			return;
		}
		root.dataset.bcInviteWired = '1';
		const q = document.getElementById('bc-member-invite-q');
		const suggest = document.getElementById('bc-member-invite-suggest');
		const roleSel = document.getElementById('bc-member-invite-role');
		const btn = root.querySelector('[data-bc-action="member-invite-submit"]');
		const selectedWrap = root.querySelector('[data-bc-member-selected-wrap]');
		const selectedEl = root.querySelector('[data-bc-member-selected]');
		if (!q || !suggest || !roleSel || !btn) return;
		let picked = null;
		const pickerStrings = {
			noResults: t('budgetcheck', 'No matching accounts.'),
			searchErrorNetwork: t('budgetcheck', 'Search could not load (network).'),
			searchErrorServer: t('budgetcheck', 'Search could not load.'),
		};
		EntityPicker.bindCombobox({
			input: q,
			suggest,
			minLen: 2,
			strings: pickerStrings,
			isTaken: (id) => currentMemberUserIds().has(id),
			fetchItems: async (query) => {
				try {
					const data = await Api.get('/apps/budgetcheck/api/admin/users', { q: query });
					const items = (data.users || []).filter((u) => u && u.enabled !== false);
					return { items, error: null };
				} catch (err) {
					const status = err && err.status;
					if (status === 0) return { items: [], error: 'network' };
					return { items: [], error: 'server' };
				}
			},
			onPick: (item) => {
				picked = { id: item.id, displayName: item.displayName || item.id };
				if (selectedEl) selectedEl.textContent = picked.displayName + ' (' + picked.id + ')';
				if (selectedWrap) selectedWrap.hidden = false;
			},
		});
		btn.addEventListener('click', async () => {
			if (!picked) {
				Msg.announce(t('budgetcheck', 'Pick a user.'), 'warning');
				q.focus();
				return;
			}
			btn.disabled = true;
			try {
				await Api.post('/apps/budgetcheck/api/workspaces/' + Ws.workspace.id + '/members', {
					userId: picked.id,
					role: roleSel.value,
				});
				Msg.announce(t('budgetcheck', 'Member added.'), 'success');
				picked = null;
				q.value = '';
				if (selectedEl) selectedEl.textContent = '';
				if (selectedWrap) selectedWrap.hidden = true;
				loadMembers();
			} catch (err) {
				Msg.handleApiError(err, { reloadOnConflict: false });
			} finally {
				btn.disabled = false;
			}
		});
	}

	// ------ Recurring rules ------
	async function loadRecurring() {
		const tbody = document.querySelector('[data-bc-recurring-rows]');
		if (!tbody || !Ws.workspace) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/recurring-rules', { workspaceId: Ws.workspace.id });
			tbody.replaceChildren();
			(data.rules || []).forEach((r) => tbody.appendChild(renderRecurringRow(r)));
			if ((data.rules || []).length === 0) {
				tbody.appendChild(C.createElement('tr', null, [
					C.createElement('td', { attrs: { colspan: '7' }, class: 'bc-loading', text: t('budgetcheck', 'No recurring rules.') }),
				]));
			}
		} catch (err) {
			Msg.handleApiError(err);
		}
		document.querySelectorAll('[data-bc-action="open-create-recurring"]').forEach((btn) => {
			btn.addEventListener('click', () => openRecurringModal(null));
		});
	}

	function renderRecurringRow(rule) {
		const tr = C.createElement('tr');
		tr.appendChild(C.createElement('td', { text: rule.title }));
		tr.appendChild(C.createElement('td', { text: rule.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense') }));
		tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(rule.amount, Ws.htmlLang) }));
		tr.appendChild(C.createElement('td', {
			text: recurringFrequencyLabel(rule.frequency) + (rule.intervalCount > 1 ? ' \u00d7 ' + rule.intervalCount : ''),
		}));
		tr.appendChild(C.createElement('td', { text: Dates.formatDisplayDate(rule.nextDueDate, Ws.htmlLang) }));
		tr.appendChild(C.createElement('td', { text: rule.isActive ? t('budgetcheck', 'Active') : t('budgetcheck', 'Inactive') }));
		const actions = C.createElement('td');
		const gen = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Generate next') });
		gen.addEventListener('click', () => generateRecurring(rule));
		actions.appendChild(gen);
		const edit = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Edit') });
		edit.addEventListener('click', () => openRecurringModal(rule));
		actions.appendChild(edit);
		const del = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Delete') });
		del.addEventListener('click', () => deleteRecurring(rule));
		actions.appendChild(del);
		tr.appendChild(actions);
		return tr;
	}

	async function openRecurringModal(rule) {
		const isEdit = !!rule;
		const cats = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id });
		const decimals = typeof Ws.workspace.currencyDecimals === 'number' ? Ws.workspace.currencyDecimals : (Ws.workspace.currencyCode === 'JPY' ? 0 : 2);
		C.openModal({
			title: isEdit ? t('budgetcheck', 'Edit rule') : t('budgetcheck', 'New recurring rule'),
			primaryLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add rule'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const titleInput = C.createElement('input', { type: 'text', name: 'title', class: 'bc-input', maxlength: 180, required: true, value: rule ? rule.title : '' });
				wrap(form, t('budgetcheck', 'Title'), titleInput, t('budgetcheck', 'Use a short name that explains what repeats.'));
				const directionSelect = C.createElement('select', { name: 'direction', class: 'bc-input' }, [
					C.createElement('option', { value: 'expense', text: t('budgetcheck', 'Expense') }),
					C.createElement('option', { value: 'income', text: t('budgetcheck', 'Income') }),
				]);
				directionSelect.value = rule ? rule.direction : 'expense';
				wrap(form, t('budgetcheck', 'Type (income or expense)'), directionSelect, t('budgetcheck', 'Select whether this repeats as income or expense.'));
				const catSelect = C.createElement('select', { name: 'categoryId', class: 'bc-input', required: true });
				const filter = () => {
					catSelect.replaceChildren();
					(cats.categories || []).filter((c) => c.type === directionSelect.value && c.isActive).forEach((c) => {
						catSelect.appendChild(C.createElement('option', { value: String(c.id), text: c.name }));
					});
					if (rule && rule.categoryId) {
						const opt = Array.from(catSelect.options).find((o) => o.value === String(rule.categoryId));
						if (opt) catSelect.value = opt.value;
					}
				};
				filter();
				directionSelect.addEventListener('change', filter);
				wrap(form, t('budgetcheck', 'Category'), catSelect, t('budgetcheck', 'Only active categories of the selected type are shown.'));
				const amountInput = C.createElement('input', {
					type: 'text', inputmode: 'decimal', name: 'amount', class: 'bc-input', required: true,
					value: rule ? (rule.amount.minor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',') : '',
					attrs: { 'aria-label': t('budgetcheck', 'Amount') },
				});
				wrap(form, t('budgetcheck', 'Amount'), amountInput, t('budgetcheck', 'Enter the amount for each occurrence.'));
				const freqSelect = C.createElement('select', { name: 'frequency', class: 'bc-input' }, [
					C.createElement('option', { value: 'monthly', text: t('budgetcheck', 'Monthly') }),
					C.createElement('option', { value: 'quarterly', text: t('budgetcheck', 'Quarterly') }),
					C.createElement('option', { value: 'yearly', text: t('budgetcheck', 'Yearly') }),
					C.createElement('option', { value: 'custom_interval', text: t('budgetcheck', 'Custom (months)') }),
				]);
				freqSelect.value = rule ? rule.frequency : 'monthly';
				wrap(form, t('budgetcheck', 'Repeat'), freqSelect, t('budgetcheck', 'Choose how often this rule should create a suggestion.'));
				const intervalInput = C.createElement('input', { type: 'number', name: 'intervalCount', min: 1, max: 36, class: 'bc-input', value: rule ? String(rule.intervalCount) : '1' });
				wrap(form, t('budgetcheck', 'Every how many months'), intervalInput, t('budgetcheck', 'Used when Repeat is set to custom months.'));
				const dateHintText = t('budgetcheck', 'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.');
				const startDh = 'bc-rec-dh-s-' + Math.random().toString(36).slice(2);
				const startInput = C.createElement('input', {
					type: 'date', name: 'startDate', class: 'bc-input', autocomplete: 'off', required: true,
					value: rule ? String(rule.startDate) : Dates.isoDate(new Date()),
					attrs: { 'aria-describedby': startDh, lang: Ws.htmlLang },
				});
				const startHint = C.createElement('span', { id: startDh, class: 'bc-field__hint', text: dateHintText });
				form.appendChild(C.createElement('label', { class: 'bc-field' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Start date') }),
					startInput,
					C.createElement('span', { class: 'bc-field__hint', text: t('budgetcheck', 'First date when this rule can create a booking.') }),
					startHint,
				]));
				const endDh = 'bc-rec-dh-e-' + Math.random().toString(36).slice(2);
				const endInput = C.createElement('input', {
					type: 'date', name: 'endDate', class: 'bc-input', autocomplete: 'off',
					value: rule && rule.endDate ? String(rule.endDate) : '',
					attrs: { 'aria-describedby': endDh, lang: Ws.htmlLang },
				});
				const endHint = C.createElement('span', { id: endDh, class: 'bc-field__hint', text: dateHintText });
				form.appendChild(C.createElement('label', { class: 'bc-field' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'End date (optional)') }),
					endInput,
					C.createElement('span', { class: 'bc-field__hint', text: t('budgetcheck', 'Leave empty if this rule should continue indefinitely.') }),
					endHint,
				]));

				form._collect = () => ({
					workspaceId: Ws.workspace.id,
					title: titleInput.value.trim(),
					direction: directionSelect.value,
					categoryId: catSelect.value ? Number.parseInt(catSelect.value, 10) : 0,
					amount: amountInput.value,
					frequency: freqSelect.value,
					intervalCount: Number.parseInt(intervalInput.value, 10) || 1,
					startDate: startInput.value.trim(),
					endDate: endInput.value.trim(),
				});
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				const payload = form && form._collect ? form._collect() : null;
				if (!payload) return false;
				const endRaw = String(payload.endDate || '').trim();
				if (!Dates.isIsoCalendarDay(payload.startDate) || (endRaw !== '' && !Dates.isIsoCalendarDay(endRaw))) {
					Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
					return false;
				}
				payload.startDate = String(payload.startDate).trim();
				payload.endDate = endRaw;
				try {
					Money.parseHuman(payload.amount, decimals);
				} catch (e) {
					Msg.announce(e.message, 'error');
					return false;
				}
				try {
					if (isEdit) {
						await Api.put('/apps/budgetcheck/api/recurring-rules/' + rule.id, payload);
						Msg.announce(t('budgetcheck', 'Rule updated.'), 'success');
					} else {
						await Api.post('/apps/budgetcheck/api/recurring-rules', payload);
						Msg.announce(t('budgetcheck', 'Rule created.'), 'success');
					}
					loadRecurring();
					close(true);
				} catch (err) {
					Msg.handleApiError(err, { reloadOnConflict: false });
					return false;
				}
			},
		});
	}

	async function deleteRecurring(rule) {
		const ok = await C.confirmDialog({
			title: t('budgetcheck', 'Delete this rule?'),
			body: t('budgetcheck', 'Existing transactions generated from this rule remain.'),
			confirmLabel: t('budgetcheck', 'Delete'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.del('/apps/budgetcheck/api/recurring-rules/' + rule.id);
			Msg.announce(t('budgetcheck', 'Rule deleted.'), 'success');
			loadRecurring();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function generateRecurring(rule) {
		try {
			await Api.post('/apps/budgetcheck/api/recurring-rules/' + rule.id + '/generate');
			Msg.announce(t('budgetcheck', 'Transaction generated.'), 'success');
			loadRecurring();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	function setVal(form, name, value) {
		const el = form.querySelector('[name="' + name + '"]');
		if (el) el.value = value === null || value === undefined ? '' : value;
	}
	function getVal(form, name) {
		const el = form.querySelector('[name="' + name + '"]');
		return el ? String(el.value || '') : '';
	}
	function wrap(form, label, control, hint = '') {
		const children = [
			C.createElement('span', { class: 'bc-field__label', text: label }),
			control,
		];
		if (hint) {
			const hintId = 'bc-field-hint-' + Math.random().toString(36).slice(2);
			children.push(C.createElement('span', { id: hintId, class: 'bc-field__hint', text: hint }));
			if (control && typeof control.setAttribute === 'function') {
				const current = control.getAttribute('aria-describedby');
				control.setAttribute('aria-describedby', current ? (current + ' ' + hintId) : hintId);
			}
		}
		const wrapper = C.createElement('label', { class: 'bc-field' }, children);
		form.appendChild(wrapper);
	}
})();
