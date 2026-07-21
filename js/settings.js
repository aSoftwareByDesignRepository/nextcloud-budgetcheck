(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;
	const EntityPicker = window.BudgetCheckEntityPicker;
	const CatalogPickers = window.BudgetCheckCatalogPickers;
	const SpecialsView = window.BudgetCheckSpecialsView;

	let capabilities = null;
	let workspaceTimezonePicker = null;
	let workspaceCurrencyPicker = null;
	const budgetDefaultsState = {
		dirty: new Map(),
	};

	document.addEventListener('DOMContentLoaded', () => {
		bootstrap();
	});

	async function bootstrap() {
		const needsCaps = document.querySelector('[data-bc-timezone-picker]')
			|| document.querySelector('[data-bc-currency-picker]');
		if (needsCaps) {
			try {
				const data = await Api.get('/apps/budgetcheck/api/workspaces');
				capabilities = data.capabilities || {};
			} catch (err) {
				Msg.handleApiError(err);
			}
		}
		await initWorkspaceCatalogPickers();
		hydrateWorkspaceForm();
		hydrateTaxForm();
		wireWorkspaceForm();
		wireTaxForm();
		wireVatPresetUi();
		await initSummaryViewPreferences();
		if (Ws.canManage) {
			loadCategories();
			loadBudgetDefaults();
			loadMembers();
			initMemberInvite();
			initGroupInvite();
			loadRecurring();
		} else if (Ws.workspace) {
			loadCategories();
		}
		if (Ws.workspace && Ws.workspace.type === 'project') {
			loadBookingStatuses();
		}
		wireHelpPanels();
	}

	function workspaceBudgetDecimals() {
		return typeof Ws.workspace?.currencyDecimals === 'number'
			? Ws.workspace.currencyDecimals
			: (Ws.workspace?.currencyCode === 'JPY' ? 0 : 2);
	}

	async function initSummaryViewPreferences() {
		if (!Ws.workspace || Ws.workspace.type !== 'household' || !SpecialsView) {
			return;
		}
		const host = document.querySelector('[data-bc-summary-view-prefs]');
		if (!host) {
			return;
		}
		try {
			await SpecialsView.migrateLegacyLocalStorage(Ws.workspace.id);
		} catch (_) {
			/* best effort */
		}
		SpecialsView.mountToggle(host, {
			workspaceId: Ws.workspace.id,
			alwaysShow: true,
			inputId: 'bc-include-specials-pref',
		});
	}

	async function loadBudgetDefaults() {
		if (!Ws.workspace || Ws.workspace.type !== 'household') return;
		const tbody = document.querySelector('[data-bc-budget-default-rows]');
		if (!tbody) return;
		try {
			const [catData, defaultsData] = await Promise.all([
				Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id }),
				Api.get('/apps/budgetcheck/api/budget-defaults', { workspaceId: Ws.workspace.id }),
			]);
			const defaultsMap = new Map();
			(defaultsData.defaults || []).forEach((row) => {
				if (row && row.categoryId) defaultsMap.set(Number(row.categoryId), row);
			});
			const categories = window.BudgetCheckConstants.budgetableCategories(catData.categories || []);
			tbody.replaceChildren();
			if (categories.length === 0) {
				tbody.appendChild(C.createElement('tr', null, [
					C.createElement('td', { attrs: { colspan: '3' }, class: 'bc-loading', text: t('budgetcheck', 'Add income or expense categories first.') }),
				]));
				return;
			}
			const decimals = workspaceBudgetDecimals();
			let lastType = null;
			categories.forEach((cat) => {
				if (cat.type !== lastType) {
					lastType = cat.type;
					const sectionLabel = cat.type === 'income'
						? t('budgetcheck', 'Income')
						: t('budgetcheck', 'Expenses');
					const sectionRow = C.createElement('tr', { class: 'bc-table__section' });
					const th = C.createElement('th', {
						attrs: { colspan: '3', scope: 'colgroup' },
						class: 'bc-table__section-label',
						text: sectionLabel,
					});
					sectionRow.appendChild(th);
					tbody.appendChild(sectionRow);
				}
				const tr = C.createElement('tr');
				tr.appendChild(C.createElement('td', { text: cat.name }));
				tr.appendChild(C.createElement('td', {
					text: cat.type === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense'),
				}));
				const td = C.createElement('td', { class: 'bc-table__col--num' });
				const input = C.createElement('input', {
					type: 'text',
					inputmode: 'decimal',
					class: 'bc-input',
					attrs: { 'aria-label': t('budgetcheck', 'Default amount') + ' ' + cat.name },
				});
				const env = defaultsMap.get(Number(cat.id))?.planned || null;
				input.value = env && typeof env.minor === 'number'
					? (env.minor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',')
					: '';
				input.addEventListener('input', () => {
					budgetDefaultsState.dirty.set(Number(cat.id), input.value);
					toggleBudgetDefaultsSave(true);
				});
				td.appendChild(input);
				tr.appendChild(td);
				tbody.appendChild(tr);
			});
			wireBudgetDefaultsSave();
			toggleBudgetDefaultsSave(false);
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	function toggleBudgetDefaultsSave(enabled) {
		document.querySelectorAll('[data-bc-action="save-budget-defaults"]').forEach((btn) => {
			btn.disabled = !enabled;
		});
	}

	function wireBudgetDefaultsSave() {
		document.querySelectorAll('[data-bc-action="save-budget-defaults"]').forEach((btn) => {
			if (btn.dataset.bcBudgetDefaultsWired === '1') return;
			btn.dataset.bcBudgetDefaultsWired = '1';
			btn.addEventListener('click', async () => {
				if (!Ws.workspace || Ws.workspace.type !== 'household') return;
				const rows = [];
				const decimals = workspaceBudgetDecimals();
				try {
					budgetDefaultsState.dirty.forEach((value, categoryId) => {
						const raw = String(value || '').trim();
						if (raw === '') {
							rows.push({ categoryId, plannedMinor: 0 });
						} else {
							rows.push({ categoryId, plannedMinor: Money.parseHuman(raw, decimals) });
						}
					});
				} catch (err) {
					Msg.announce(err.message || t('budgetcheck', 'Amount is not a valid number.'), 'error');
					return;
				}
				if (rows.length === 0) {
					return;
				}
				try {
					await Api.post('/apps/budgetcheck/api/budget-defaults/bulk-upsert', {
						workspaceId: Ws.workspace.id,
						rows,
					});
					Msg.announce(t('budgetcheck', 'Default budgets saved.'), 'success');
					budgetDefaultsState.dirty.clear();
					await loadBudgetDefaults();
				} catch (err) {
					Msg.handleApiError(err);
				}
			});
		});
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

	async function initWorkspaceCatalogPickers() {
		if (!CatalogPickers || !capabilities) {
			return;
		}
		const tzRoot = document.querySelector('[data-bc-timezone-picker]');
		const curRoot = document.querySelector('[data-bc-currency-picker]');
		if (tzRoot && capabilities.timezoneCatalog) {
			workspaceTimezonePicker = CatalogPickers.attachTimezone(
				tzRoot,
				capabilities.timezoneCatalog,
				{ defaultTimezone: Ws.workspace?.timezone || capabilities.defaultTimezone },
			);
		}
		if (curRoot && capabilities.currencyCatalog) {
			workspaceCurrencyPicker = CatalogPickers.attachCurrency(
				curRoot,
				capabilities.currencyCatalog,
				{ defaultCurrency: Ws.workspace?.currencyCode || capabilities.defaultCurrency },
			);
		}
	}

	function syncWorkspaceCatalogPickersFromWorkspace() {
		if (workspaceTimezonePicker && Ws.workspace?.timezone) {
			workspaceTimezonePicker.setValue(Ws.workspace.timezone);
		}
		if (workspaceCurrencyPicker && Ws.workspace?.currencyCode) {
			workspaceCurrencyPicker.setValue(Ws.workspace.currencyCode);
		}
	}

	function hydrateWorkspaceForm() {
		if (!Ws.workspace) return;
		const form = document.querySelector('[data-bc-workspace-form]');
		if (!form) return;
		setVal(form, 'name', Ws.workspace.name);
		syncWorkspaceCatalogPickersFromWorkspace();
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
			const generatePlannedDefault = form.querySelector('input[name="generatePlannedFromBudgetsDefault"]');
			if (generatePlannedDefault) generatePlannedDefault.checked = !!Ws.workspace.generatePlannedFromBudgetsDefault;
			const specialsDefault = form.querySelector('input[name="includeSpecialsInTotalsDefault"]');
			if (specialsDefault) specialsDefault.checked = !!Ws.workspace.includeSpecialsInTotalsDefault;
			hydrateDefaultSavingsUi(form);
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

	function hydrateDefaultSavingsUi(form) {
		const mode = String(Ws.workspace?.defaultSavingsTargetMode || '');
		const percentBp = Ws.workspace?.defaultSavingsTargetPercentBp;
		const minor = Ws.workspace?.defaultSavingsTargetMinor;
		const radios = form.querySelectorAll('input[name="defaultSavingsTargetMode"]');
		radios.forEach((radio) => { radio.checked = radio.value === mode; });
		if (!Array.from(radios).some((radio) => radio.checked)) {
			const none = form.querySelector('input[name="defaultSavingsTargetMode"][value=""]');
			if (none) none.checked = true;
		}
		const percentInput = form.querySelector('input[name="defaultSavingsTargetPercent"]');
		if (percentInput) {
			percentInput.value = typeof percentBp === 'number' ? String(Math.round(percentBp / 100)) : '';
		}
		const amountInput = form.querySelector('input[name="defaultSavingsTargetAmount"]');
		if (amountInput) {
			amountInput.value = typeof minor === 'number'
				? formatMinorForInput(minor, workspaceCurrencyDecimals()).replace('.', ',')
				: '';
		}
		syncDefaultSavingsUi(form);
	}

	function syncDefaultSavingsUi(form) {
		const mode = String(form.querySelector('input[name="defaultSavingsTargetMode"]:checked')?.value || '');
		const percentWrap = form.querySelector('[data-bc-default-savings-percent-wrap]');
		const amountWrap = form.querySelector('[data-bc-default-savings-amount-wrap]');
		const showPercent = mode === 'percentage' || mode === 'hybrid';
		const showAmount = mode === 'absolute' || mode === 'hybrid';
		if (percentWrap) percentWrap.hidden = !showPercent;
		if (amountWrap) amountWrap.hidden = !showAmount;
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
		const enabled = form.querySelector('input[name="taxModeEnabled"]');
		const basis = form.querySelector('select[name="taxBudgetBasis"]');
		const presetSelect = form.querySelector('[data-bc-vat-preset]');
		const customWrap = form.querySelector('[data-bc-vat-custom-wrap]');
		const summary = form.querySelector('[data-bc-tax-preview-summary]');
		if (!presetSelect || !customWrap) return;
		const sync = () => {
			const taxOn = !!enabled?.checked;
			if (basis) basis.disabled = !taxOn || !Ws.canManage;
			presetSelect.disabled = !taxOn || !Ws.canManage;
			customWrap.hidden = !taxOn || presetSelect.value !== 'custom';
			const customInput = customWrap.querySelector('[data-bc-vat-custom]');
			if (customInput) customInput.disabled = customWrap.hidden || !Ws.canManage;
			if (summary) {
				const basisValue = String(basis?.value || 'gross');
				const modeLabel = basisValue === 'net' ? t('budgetcheck', 'Net') : t('budgetcheck', 'Gross');
				summary.textContent = taxOn
					? t('budgetcheck', 'Budget and cap calculations currently use: {basis}.').replace('{basis}', modeLabel)
					: t('budgetcheck', 'Tax mode is disabled. Budgets and cap use plain entry amounts.');
			}
		};
		enabled?.addEventListener('change', sync);
		basis?.addEventListener('change', sync);
		presetSelect.addEventListener('change', sync);
		sync();
	}

	function wireWorkspaceForm() {
		const form = document.querySelector('[data-bc-workspace-form]');
		if (!form || !Ws.workspace) return;
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!Ws.canManage) return;
			const currencyCode = workspaceCurrencyPicker
				? workspaceCurrencyPicker.getValue()
				: getVal(form, 'currencyCode').trim().toUpperCase();
			const timezone = workspaceTimezonePicker
				? workspaceTimezonePicker.getValue()
				: getVal(form, 'timezone').trim();
			if (currencyCode === '') {
				Msg.announce(t('budgetcheck', 'Please choose a currency.'), 'error');
				document.getElementById('bc-ws-currency-input')?.focus();
				return;
			}
			if (timezone === '') {
				Msg.announce(t('budgetcheck', 'Please choose a timezone.'), 'error');
				document.getElementById('bc-ws-timezone-input')?.focus();
				return;
			}
			const payload = {
				name: getVal(form, 'name').trim(),
				currencyCode,
				timezone,
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
				payload.generatePlannedFromBudgetsDefault = !!form.querySelector('input[name="generatePlannedFromBudgetsDefault"]')?.checked;
				payload.includeSpecialsInTotalsDefault = !!form.querySelector('input[name="includeSpecialsInTotalsDefault"]')?.checked;
				const defaultMode = String(form.querySelector('input[name="defaultSavingsTargetMode"]:checked')?.value || '');
				payload.defaultSavingsTargetMode = defaultMode;
				if (defaultMode === '') {
					payload.defaultSavingsTargetPercentBp = null;
					payload.defaultSavingsTargetMinor = null;
				} else {
					if (defaultMode === 'percentage' || defaultMode === 'hybrid') {
						const percentRaw = getVal(form, 'defaultSavingsTargetPercent').trim();
						const pct = Number.parseInt(percentRaw, 10);
						if (!Number.isFinite(pct) || pct < 0 || pct > 100) {
							Msg.announce(t('budgetcheck', 'Percentage must be between 0 and 100.'), 'error');
							return;
						}
						payload.defaultSavingsTargetPercentBp = pct * 100;
					} else {
						payload.defaultSavingsTargetPercentBp = null;
					}
					if (defaultMode === 'absolute' || defaultMode === 'hybrid') {
						const amountRaw = getVal(form, 'defaultSavingsTargetAmount').trim();
						if (amountRaw === '') {
							Msg.announce(t('budgetcheck', 'Enter an amount.'), 'error');
							return;
						}
						try {
							payload.defaultSavingsTargetMinor = Money.parseHuman(amountRaw, workspaceCurrencyDecimals());
						} catch (err) {
							Msg.announce(err.message || t('budgetcheck', 'Amount is not a valid number.'), 'error');
							return;
						}
					} else {
						payload.defaultSavingsTargetMinor = null;
					}
				}
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
				const msg = String(err?.message || err?.payload?.message || '');
				if (msg.includes('Currency cannot be changed')) {
					Msg.announce(t('budgetcheck', 'Currency cannot be changed once this workspace has transactions.'), 'error');
					return;
				}
				Msg.handleApiError(err);
			}
		});
		form.querySelectorAll('input[name="defaultSavingsTargetMode"]').forEach((radio) => {
			radio.addEventListener('change', () => syncDefaultSavingsUi(form));
		});
		syncDefaultSavingsUi(form);
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
			case 'schedule':
				return t('budgetcheck', 'Specific dates');
			default:
				return String(frequency || '');
		}
	}

	const RECURRING_MAX_SCHEDULE_ENTRIES = 60;

	function snapScheduleDateOnOrAfter(dates, isoDate) {
		const sorted = [...dates].sort();
		for (let i = 0; i < sorted.length; i++) {
			if (sorted[i] >= isoDate) {
				return sorted[i];
			}
		}
		return null;
	}

	const INTERNAL_UNCATEGORIZED_GROUP = window.BudgetCheckConstants.GROUP_INTERNAL_UNCATEGORIZED;

	function categoryGroupKeyLabel(groupKey) {
		return window.BudgetCheckConstants.categoryGroupKeyLabel(groupKey);
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
			const actions = C.createElement('td', { class: 'bc-table-actions-cell' });
			const actionsGroup = C.createElement('div', {
				class: 'bc-table-actions',
				attrs: { role: 'group', 'aria-label': t('budgetcheck', 'Actions for {title}').replace('{title}', status.name) },
			});
			const edit = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Edit') });
			edit.addEventListener('click', () => openBookingStatusModal(status));
			actionsGroup.appendChild(edit);
			if (status.isActive) {
				const deactivate = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Deactivate') });
				deactivate.addEventListener('click', () => deactivateBookingStatus(status));
				actionsGroup.appendChild(deactivate);
			}
			actions.appendChild(actionsGroup);
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
					C.createElement('td', { attrs: { colspan: '8' }, class: 'bc-loading', text: t('budgetcheck', 'No categories yet.') }),
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
		tr.appendChild(C.createElement('td', { text: cat.isSavingsTransfer ? t('budgetcheck', 'Yes') : t('budgetcheck', 'No') }));
		tr.appendChild(C.createElement('td', { text: taxHandlingModeLabel(cat.taxHandlingMode) }));
		tr.appendChild(C.createElement('td', { text: cat.isActive ? t('budgetcheck', 'Active') : t('budgetcheck', 'Inactive') }));
		if (Ws.canManage) {
			const actions = C.createElement('td', { class: 'bc-table-actions-cell' });
			const actionsGroup = C.createElement('div', {
				class: 'bc-table-actions',
				attrs: { role: 'group', 'aria-label': t('budgetcheck', 'Actions for {title}').replace('{title}', cat.name) },
			});
			const edit = C.createElement('button', { type: 'button', class: 'button', text: t('budgetcheck', 'Edit') });
			edit.addEventListener('click', () => { void openCategoryModal(cat); });
			actionsGroup.appendChild(edit);
			const canDeactivate = cat.isActive && cat.groupKey !== INTERNAL_UNCATEGORIZED_GROUP;
			if (canDeactivate) {
				const off = C.createElement('button', { type: 'button', class: 'button danger', text: t('budgetcheck', 'Deactivate') });
				off.addEventListener('click', () => deactivateCategory(cat));
				actionsGroup.appendChild(off);
			}
			actions.appendChild(actionsGroup);
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
				const savingsOuter = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean', attrs: { 'data-bc-savings-transfer-wrap': '1' } });
				savingsOuter.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Savings tracking') }));
				const savingsRow = C.createElement('span', { class: 'bc-boolean-control' });
				const savingsInput = C.createElement('input', { type: 'checkbox', name: 'isSavingsTransfer', value: '1' });
				savingsInput.checked = !!(cat && cat.isSavingsTransfer);
				savingsRow.appendChild(savingsInput);
				savingsRow.appendChild(C.createElement('span', {
					class: 'bc-boolean-control__text',
					text: t('budgetcheck', 'Transfers in this category count toward your savings goal and are excluded from everyday budget saldo.'),
				}));
				savingsOuter.appendChild(savingsRow);
				form.appendChild(savingsOuter);
				const syncSavingsUi = () => {
					savingsOuter.hidden = typeSelect.value !== 'expense';
					if (typeSelect.value !== 'expense') {
						savingsInput.checked = false;
					}
				};
				typeSelect.addEventListener('change', syncSavingsUi);
				syncSavingsUi();
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
						isSavingsTransfer: savingsInput.checked,
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

	function memberRoleLabel(role) {
		switch (String(role || 'viewer')) {
			case 'manager': return t('budgetcheck', 'Manager');
			case 'contributor': return t('budgetcheck', 'Contributor');
			default: return t('budgetcheck', 'Viewer');
		}
	}

	function renderMemberRow(member) {
		const isGroup = member.type === 'group';
		const tr = C.createElement('tr');
		if (isGroup) {
			tr.dataset.bcGroupId = member.groupId;
		} else {
			tr.dataset.bcUserId = member.userId;
		}

		// Member name (+ a small meta note for group size / missing principals)
		const principalId = isGroup ? member.groupId : member.userId;
		const nameTd = C.createElement('td');
		nameTd.appendChild(C.createElement('span', {
			class: 'bc-member-name',
			text: (member.displayName || principalId) + ' (' + principalId + ')',
		}));
		if (isGroup && member.exists === false) {
			nameTd.appendChild(C.createElement('span', { class: 'bc-badge bc-badge--warning', text: t('budgetcheck', 'Group no longer exists') }));
		} else if (isGroup && (typeof member.memberCount === 'number')) {
			const meta = member.memberCount === 1
				? t('budgetcheck', '1 person')
				: t('budgetcheck', '{count} people').replace('{count}', String(member.memberCount));
			nameTd.appendChild(C.createElement('span', { class: 'bc-member-meta', text: meta }));
		} else if (!isGroup && member.enabled === false) {
			nameTd.appendChild(C.createElement('span', { class: 'bc-badge bc-badge--warning', text: t('budgetcheck', 'Disabled account') }));
		}
		tr.appendChild(nameTd);

		// Type badge
		const typeTd = C.createElement('td');
		typeTd.appendChild(C.createElement('span', {
			class: 'bc-badge ' + (isGroup ? 'bc-badge--group' : 'bc-badge--user'),
			text: isGroup ? t('budgetcheck', 'Group') : t('budgetcheck', 'User'),
		}));
		tr.appendChild(typeTd);

		// Role select (groups may not be managers)
		const roleOptions = [
			C.createElement('option', { value: 'contributor', text: t('budgetcheck', 'Contributor') }),
			C.createElement('option', { value: 'viewer', text: t('budgetcheck', 'Viewer') }),
		];
		if (!isGroup) {
			roleOptions.unshift(C.createElement('option', { value: 'manager', text: t('budgetcheck', 'Manager') }));
		}
		const roleSelect = C.createElement('select', {
			class: 'bc-input',
			attrs: { 'aria-label': t('budgetcheck', 'Role for {name}').replace('{name}', member.displayName || principalId) },
		}, roleOptions);
		roleSelect.value = member.role;
		const baseUrl = isGroup
			? '/apps/budgetcheck/api/workspace-group-members/'
			: '/apps/budgetcheck/api/workspace-members/';
		roleSelect.addEventListener('change', async () => {
			try {
				await Api.put(baseUrl + member.id, { role: roleSelect.value });
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
				title: isGroup ? t('budgetcheck', 'Remove this group?') : t('budgetcheck', 'Remove this member?'),
				body: isGroup
					? t('budgetcheck', 'Everyone reaching this workspace only through this group will lose access. People with their own role keep it.')
					: t('budgetcheck', 'They will lose access to this workspace.'),
				confirmLabel: t('budgetcheck', 'Remove'),
				danger: true,
			});
			if (!ok) return;
			try {
				await Api.del(baseUrl + member.id);
				Msg.announce(isGroup ? t('budgetcheck', 'Group removed.') : t('budgetcheck', 'Member removed.'), 'success');
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

	function currentMemberGroupIds() {
		const ids = new Set();
		document.querySelectorAll('[data-bc-member-rows] tr[data-bc-group-id]').forEach((tr) => {
			const id = tr.getAttribute('data-bc-group-id');
			if (id) ids.add(id);
		});
		return ids;
	}

	/**
	 * Wire a directory-search invite block (used for both users and groups).
	 * The two blocks differ only in DOM ids, the search endpoint, and the
	 * mutation payload; the interaction model is identical so we keep one
	 * implementation to avoid drift between them.
	 */
	function wireInvite(opts) {
		const root = document.querySelector(opts.rootSelector);
		if (!root || !Ws.workspace || !EntityPicker || root.dataset.bcInviteWired === '1') {
			return;
		}
		root.dataset.bcInviteWired = '1';
		const q = document.getElementById(opts.queryId);
		const suggest = document.getElementById(opts.suggestId);
		const roleSel = document.getElementById(opts.roleId);
		const btn = root.querySelector('[data-bc-action="' + opts.submitAction + '"]');
		const clearBtn = root.querySelector('[data-bc-action="' + opts.clearAction + '"]');
		const selectedWrap = root.querySelector(opts.selectedWrapSelector);
		const selectedEl = root.querySelector(opts.selectedSelector);
		const selectedRoleEl = root.querySelector(opts.selectedRoleSelector);
		if (!q || !suggest || !roleSel || !btn) return;
		let picked = null;
		const syncInviteButtonLabel = () => {
			btn.textContent = picked
				? opts.strings.addSelectedAs.replace('{role}', memberRoleLabel(roleSel.value))
				: opts.strings.addDefault;
		};
		const setPicked = (next) => {
			picked = next;
			if (selectedEl) {
				selectedEl.textContent = next ? (next.displayName + ' (' + next.id + ')') : '';
			}
			if (selectedRoleEl) {
				selectedRoleEl.textContent = next
					? t('budgetcheck', 'Will be added as: {role}').replace('{role}', memberRoleLabel(roleSel.value))
					: '';
			}
			if (selectedWrap) {
				selectedWrap.hidden = !next;
			}
			btn.disabled = !next;
			syncInviteButtonLabel();
		};
		const pickerStrings = {
			noResults: opts.strings.noResults,
			searchErrorNetwork: t('budgetcheck', 'Search could not load (network).'),
			searchErrorServer: t('budgetcheck', 'Search could not load.'),
		};
		EntityPicker.bindCombobox({
			input: q,
			suggest,
			minLen: 2,
			strings: pickerStrings,
			isTaken: (id) => opts.currentIds().has(id),
			fetchItems: async (query) => {
				try {
					const data = await Api.get(opts.searchEndpoint, { q: query });
					const items = (data[opts.resultKey] || []).filter((it) => it && (!opts.requireEnabled || it.enabled !== false));
					return { items, error: null };
				} catch (err) {
					const status = err && err.status;
					if (status === 0) return { items: [], error: 'network' };
					return { items: [], error: 'server' };
				}
			},
			onPick: (item) => {
				setPicked({ id: item.id, displayName: item.displayName || item.id });
			},
		});
		setPicked(null);
		roleSel.addEventListener('change', () => {
			if (picked && selectedRoleEl) {
				selectedRoleEl.textContent = t('budgetcheck', 'Will be added as: {role}').replace('{role}', memberRoleLabel(roleSel.value));
			}
			syncInviteButtonLabel();
		});
		q.addEventListener('input', () => {
			// If the manager edits the search text after selecting someone, require
			// a fresh explicit selection to prevent adding a stale identity.
			if (picked) {
				setPicked(null);
			}
		});
		clearBtn?.addEventListener('click', () => {
			setPicked(null);
			q.value = '';
			q.focus();
		});
		btn.addEventListener('click', async () => {
			if (!picked) {
				Msg.announce(opts.strings.pickPrompt, 'warning');
				q.focus();
				return;
			}
			if (opts.currentIds().has(picked.id)) {
				Msg.announce(opts.strings.alreadyInList, 'warning');
				setPicked(null);
				q.value = '';
				q.focus();
				return;
			}
			btn.disabled = true;
			try {
				await Api.post(opts.postUrl(), Object.assign({ role: roleSel.value }, opts.payload(picked)));
				Msg.announce(opts.strings.added, 'success');
				setPicked(null);
				roleSel.value = 'viewer';
				q.value = '';
				loadMembers();
			} catch (err) {
				Msg.handleApiError(err, { reloadOnConflict: false });
			} finally {
				if (!picked) {
					btn.disabled = true;
				}
			}
		});
	}

	function initMemberInvite() {
		wireInvite({
			rootSelector: '[data-bc-member-invite]',
			queryId: 'bc-member-invite-q',
			suggestId: 'bc-member-invite-suggest',
			roleId: 'bc-member-invite-role',
			submitAction: 'member-invite-submit',
			clearAction: 'member-invite-clear',
			selectedWrapSelector: '[data-bc-member-selected-wrap]',
			selectedSelector: '[data-bc-member-selected]',
			selectedRoleSelector: '[data-bc-member-selected-role]',
			searchEndpoint: '/apps/budgetcheck/api/admin/users',
			resultKey: 'users',
			requireEnabled: true,
			currentIds: currentMemberUserIds,
			postUrl: () => '/apps/budgetcheck/api/workspaces/' + Ws.workspace.id + '/members',
			payload: (picked) => ({ userId: picked.id }),
			strings: {
				noResults: t('budgetcheck', 'No matching accounts.'),
				pickPrompt: t('budgetcheck', 'Pick a user.'),
				alreadyInList: t('budgetcheck', 'That user is already in the list.'),
				added: t('budgetcheck', 'Member added.'),
				addDefault: t('budgetcheck', 'Add to workspace'),
				addSelectedAs: t('budgetcheck', 'Add selected user as {role}'),
			},
		});
	}

	function initGroupInvite() {
		wireInvite({
			rootSelector: '[data-bc-group-invite]',
			queryId: 'bc-group-invite-q',
			suggestId: 'bc-group-invite-suggest',
			roleId: 'bc-group-invite-role',
			submitAction: 'group-invite-submit',
			clearAction: 'group-invite-clear',
			selectedWrapSelector: '[data-bc-group-selected-wrap]',
			selectedSelector: '[data-bc-group-selected]',
			selectedRoleSelector: '[data-bc-group-selected-role]',
			searchEndpoint: '/apps/budgetcheck/api/admin/groups',
			resultKey: 'groups',
			requireEnabled: false,
			currentIds: currentMemberGroupIds,
			postUrl: () => '/apps/budgetcheck/api/workspaces/' + Ws.workspace.id + '/group-members',
			payload: (picked) => ({ groupId: picked.id }),
			strings: {
				noResults: t('budgetcheck', 'No matching groups.'),
				pickPrompt: t('budgetcheck', 'Pick a group.'),
				alreadyInList: t('budgetcheck', 'That group is already in the list.'),
				added: t('budgetcheck', 'Group added.'),
				addDefault: t('budgetcheck', 'Add group to workspace'),
				addSelectedAs: t('budgetcheck', 'Add selected group as {role}'),
			},
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
					C.createElement('td', { attrs: { colspan: '8' }, class: 'bc-loading', text: t('budgetcheck', 'No recurring rules.') }),
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
		const ruleLabel = rule.title || ('#' + rule.id);
		tr.appendChild(C.createElement('td', { text: rule.title }));
		tr.appendChild(C.createElement('td', { text: rule.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense') }));
		const isSchedule = rule.frequency === 'schedule';
		const scheduleEntries = isSchedule && Array.isArray(rule.schedule) ? rule.schedule : [];
		const hasAmountOverrides = scheduleEntries.some((e) => e && e.amountMinor !== null && e.amountMinor !== undefined);
		tr.appendChild(C.createElement('td', {
			class: 'bc-table__col--num',
			text: hasAmountOverrides
				? t('budgetcheck', 'Varies (default {amount})').replace('{amount}', Money.formatEnvelope(rule.amount, Ws.htmlLang))
				: Money.formatEnvelope(rule.amount, Ws.htmlLang),
		}));
		let freqText = recurringFrequencyLabel(rule.frequency);
		if (isSchedule) {
			if (scheduleEntries.length === 0) {
				freqText = recurringFrequencyLabel('schedule');
			} else {
				freqText = scheduleEntries.length === 1
					? t('budgetcheck', 'Specific dates (1 date)')
					: t('budgetcheck', 'Specific dates ({count} dates)').replace('{count}', String(scheduleEntries.length));
			}
		} else if (rule.intervalCount > 1) {
			freqText += ' \u00d7 ' + rule.intervalCount;
		}
		tr.appendChild(C.createElement('td', { text: freqText }));
		tr.appendChild(C.createElement('td', { text: Dates.formatDisplayDate(rule.nextDueDate, Ws.htmlLang) }));
		tr.appendChild(C.createElement('td', {
			text: rule.endDate ? Dates.formatDisplayDate(rule.endDate, Ws.htmlLang) : t('budgetcheck', 'Open-ended'),
		}));
		tr.appendChild(C.createElement('td', { text: rule.isActive ? t('budgetcheck', 'Active') : t('budgetcheck', 'Inactive') }));
		const actions = C.createElement('td', { class: 'bc-recurring-actions-cell' });
		const actionsGroup = C.createElement('div', { class: 'bc-recurring-actions', attrs: { role: 'group', 'aria-label': t('budgetcheck', 'Actions for {title}').replace('{title}', ruleLabel) } });
		const gen = C.createElement('button', {
			type: 'button',
			class: 'button',
			text: t('budgetcheck', 'Generate next'),
			attrs: { 'aria-label': t('budgetcheck', 'Generate next for {title}').replace('{title}', ruleLabel) },
		});
		gen.addEventListener('click', () => generateRecurring(rule));
		actionsGroup.appendChild(gen);
		if (rule.endDate) {
			const genFull = C.createElement('button', {
				type: 'button',
				class: 'button',
				text: t('budgetcheck', 'Generate full period'),
				attrs: { 'aria-label': t('budgetcheck', 'Generate full period for {title}').replace('{title}', ruleLabel) },
			});
			genFull.addEventListener('click', () => generateRecurring(rule, { fullPeriod: true }));
			actionsGroup.appendChild(genFull);
		}
		const edit = C.createElement('button', {
			type: 'button',
			class: 'button',
			text: t('budgetcheck', 'Edit'),
			attrs: { 'aria-label': t('budgetcheck', 'Edit rule {title}').replace('{title}', ruleLabel) },
		});
		edit.addEventListener('click', () => openRecurringModal(rule));
		actionsGroup.appendChild(edit);
		const del = C.createElement('button', {
			type: 'button',
			class: 'button danger',
			text: t('budgetcheck', 'Delete'),
			attrs: { 'aria-label': t('budgetcheck', 'Delete rule {title}').replace('{title}', ruleLabel) },
		});
		del.addEventListener('click', () => deleteRecurring(rule));
		actionsGroup.appendChild(del);
		actions.appendChild(actionsGroup);
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
				form.appendChild(C.createElement('p', {
					class: 'bc-field__hint bc-field__hint--block',
					text: t('budgetcheck', 'Generate creates a planned entry on Transactions. A matching bank import or manual booking removes it automatically (same category, direction, amount, same or neighbouring month).'),
				}));
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
				const minorToHuman = (minor) => (minor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',');
				const amountInput = C.createElement('input', {
					type: 'text', inputmode: 'decimal', name: 'amount', class: 'bc-input', required: true,
					value: rule ? minorToHuman(rule.amount.minor) : '',
					attrs: { 'aria-label': t('budgetcheck', 'Amount') },
				});
				const amountLabelText = C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Amount') });
				const amountHintId = 'bc-rec-amount-hint-' + Math.random().toString(36).slice(2);
				const amountHint = C.createElement('span', {
					id: amountHintId,
					class: 'bc-field__hint',
					text: t('budgetcheck', 'Enter the amount for each occurrence.'),
				});
				amountInput.setAttribute('aria-describedby', amountHintId);
				form.appendChild(C.createElement('label', { class: 'bc-field' }, [amountLabelText, amountInput, amountHint]));
				const freqSelect = C.createElement('select', { name: 'frequency', class: 'bc-input' }, [
					C.createElement('option', { value: 'monthly', text: t('budgetcheck', 'Monthly') }),
					C.createElement('option', { value: 'quarterly', text: t('budgetcheck', 'Quarterly') }),
					C.createElement('option', { value: 'yearly', text: t('budgetcheck', 'Yearly') }),
					C.createElement('option', { value: 'custom_interval', text: t('budgetcheck', 'Custom (months)') }),
					C.createElement('option', { value: 'schedule', text: t('budgetcheck', 'Specific dates') }),
				]);
				freqSelect.value = rule ? rule.frequency : 'monthly';
				wrap(form, t('budgetcheck', 'Repeat'), freqSelect, t('budgetcheck', 'Choose how often this rule should create a suggestion.'));
				const intervalSelect = C.createElement('select', { name: 'intervalCount', class: 'bc-input' }, [
					C.createElement('option', { value: '2', text: t('budgetcheck', 'Every 2 months') }),
					C.createElement('option', { value: '3', text: t('budgetcheck', 'Every 3 months') }),
					C.createElement('option', { value: '4', text: t('budgetcheck', 'Every 4 months') }),
					C.createElement('option', { value: '6', text: t('budgetcheck', 'Every 6 months') }),
					C.createElement('option', { value: '12', text: t('budgetcheck', 'Every 12 months') }),
					C.createElement('option', { value: '24', text: t('budgetcheck', 'Every 24 months') }),
					C.createElement('option', { value: '36', text: t('budgetcheck', 'Every 36 months') }),
				]);
				const existingInterval = rule && rule.intervalCount > 1 ? String(rule.intervalCount) : '2';
				const hasExistingOption = Array.from(intervalSelect.options).some((opt) => opt.value === existingInterval);
				if (!hasExistingOption) {
					intervalSelect.appendChild(C.createElement('option', {
						value: existingInterval,
						text: t('budgetcheck', 'Every {count} months').replace('{count}', existingInterval),
					}));
				}
				intervalSelect.value = existingInterval;
				const intervalWrap = C.createElement('label', { class: 'bc-field bc-field--full-width bc-recurring-interval-wrap' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Custom interval') }),
					intervalSelect,
					C.createElement('span', { class: 'bc-field__hint', text: t('budgetcheck', 'Only used when Repeat is set to custom months.') }),
				]);
				form.appendChild(intervalWrap);
				const syncIntervalUi = () => {
					const custom = freqSelect.value === 'custom_interval';
					intervalWrap.hidden = !custom;
					intervalSelect.disabled = !custom;
				};
				freqSelect.addEventListener('change', syncIntervalUi);
				syncIntervalUi();
				const dateHintText = t('budgetcheck', 'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.');
				const startDh = 'bc-rec-dh-s-' + Math.random().toString(36).slice(2);
				const startPurposeId = 'bc-rec-start-purpose-' + Math.random().toString(36).slice(2);
				const startInput = C.createElement('input', {
					type: 'date', name: 'startDate', class: 'bc-input', autocomplete: 'off', required: true,
					value: rule ? String(rule.startDate) : Dates.isoDate(new Date()),
					attrs: { 'aria-describedby': startPurposeId + ' ' + startDh, lang: Ws.htmlLang },
				});
				const startPurposeHint = C.createElement('span', {
					id: startPurposeId,
					class: 'bc-field__hint',
					text: t('budgetcheck', 'First date when this rule can create a booking.'),
				});
				const startHint = C.createElement('span', { id: startDh, class: 'bc-field__hint', text: dateHintText });
				const startFieldWrap = C.createElement('label', { class: 'bc-field' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Start date') }),
					startInput,
					startPurposeHint,
					startHint,
				]);
				form.appendChild(startFieldWrap);
				const endDh = 'bc-rec-dh-e-' + Math.random().toString(36).slice(2);
				const endSameDayWarningId = 'bc-rec-end-same-' + Math.random().toString(36).slice(2);
				const endInput = C.createElement('input', {
					type: 'date', name: 'endDate', class: 'bc-input', autocomplete: 'off',
					value: rule && rule.endDate ? String(rule.endDate) : '',
					attrs: { 'aria-describedby': endDh + ' ' + endSameDayWarningId, lang: Ws.htmlLang },
				});
				const endHint = C.createElement('span', { id: endDh, class: 'bc-field__hint', text: dateHintText });
				const endSameDayWarning = C.createElement('p', {
					id: endSameDayWarningId,
					class: 'bc-field__warning',
					hidden: true,
					attrs: { role: 'alert' },
					text: t('budgetcheck', 'Start and end date are the same. This rule can create at most one booking on that day and will not repeat afterward. Clear the end date if it should continue on your repeat schedule.'),
				});
				const endFieldWrap = C.createElement('label', { class: 'bc-field' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'End date (optional)') }),
					endInput,
					C.createElement('span', { class: 'bc-field__hint', text: t('budgetcheck', 'Leave empty if this rule should continue indefinitely.') }),
					endHint,
					endSameDayWarning,
				]);
				form.appendChild(endFieldWrap);
				const syncEndDateWarning = () => {
					const start = startInput.value.trim();
					const end = endInput.value.trim();
					const show = end !== ''
						&& start !== ''
						&& end === start
						&& Dates.isIsoCalendarDay(start)
						&& Dates.isIsoCalendarDay(end);
					const wasHidden = endSameDayWarning.hidden;
					endSameDayWarning.hidden = !show;
					if (show && wasHidden) {
						Msg.announce(endSameDayWarning.textContent || '', 'error');
					}
				};
				startInput.addEventListener('input', syncEndDateWarning);
				startInput.addEventListener('change', syncEndDateWarning);
				endInput.addEventListener('input', syncEndDateWarning);
				endInput.addEventListener('change', syncEndDateWarning);
				syncEndDateWarning();

				// ---- Specific dates (schedule) editor ----
				let syncRealignUiRef = null;
				const scheduleRows = C.createElement('div', { class: 'bc-schedule-editor__rows' });
				const scheduleStatus = C.createElement('p', {
					class: 'bc-field__hint bc-schedule-editor__status',
					attrs: { role: 'status', 'aria-live': 'polite' },
				});
				const scheduleAddBtn = C.createElement('button', {
					type: 'button',
					class: 'button bc-schedule-editor__add',
					text: t('budgetcheck', 'Add date'),
				});
				const syncScheduleStatus = () => {
					const count = scheduleRows.children.length;
					scheduleStatus.textContent = count === 1
						? t('budgetcheck', '1 date scheduled.')
						: t('budgetcheck', '{count} dates scheduled.').replace('{count}', String(count));
					scheduleAddBtn.disabled = count >= RECURRING_MAX_SCHEDULE_ENTRIES;
				};
				const addScheduleRow = (dateVal, amountVal, focus) => {
					if (scheduleRows.children.length >= RECURRING_MAX_SCHEDULE_ENTRIES) return;
					const row = C.createElement('div', { class: 'bc-schedule-editor__row' });
					const dateInput = C.createElement('input', {
						type: 'date', class: 'bc-input bc-schedule-editor__date', autocomplete: 'off',
						value: dateVal || '',
						attrs: { 'aria-label': t('budgetcheck', 'Scheduled date'), lang: Ws.htmlLang, 'data-bc-schedule-date': '1' },
					});
					const rowAmountInput = C.createElement('input', {
						type: 'text', inputmode: 'decimal', class: 'bc-input bc-schedule-editor__amount',
						value: amountVal || '',
						attrs: {
							'aria-label': t('budgetcheck', 'Amount for this date (optional)'),
							placeholder: t('budgetcheck', 'Default amount'),
							'data-bc-schedule-amount': '1',
						},
					});
					const removeBtn = C.createElement('button', {
						type: 'button',
						class: 'button bc-schedule-editor__remove',
						text: t('budgetcheck', 'Remove'),
						attrs: { 'aria-label': t('budgetcheck', 'Remove this date') },
					});
					removeBtn.addEventListener('click', () => {
						row.remove();
						if (scheduleRows.children.length === 0) {
							addScheduleRow('', '', false);
						}
						syncScheduleStatus();
						if (syncRealignUiRef) syncRealignUiRef();
						scheduleAddBtn.focus();
					});
					const notifyRealignPreview = () => {
						if (syncRealignUiRef) syncRealignUiRef();
					};
					dateInput.addEventListener('input', notifyRealignPreview);
					dateInput.addEventListener('change', notifyRealignPreview);
					row.appendChild(dateInput);
					row.appendChild(rowAmountInput);
					row.appendChild(removeBtn);
					scheduleRows.appendChild(row);
					if (window.BudgetCheckDates && typeof window.BudgetCheckDates.applyLocaleToTemporalInputs === 'function') {
						window.BudgetCheckDates.applyLocaleToTemporalInputs(row);
					}
					if (focus) dateInput.focus();
					syncScheduleStatus();
				};
				scheduleAddBtn.addEventListener('click', () => addScheduleRow('', '', true));
				const scheduleWrap = C.createElement('fieldset', { class: 'bc-field bc-field--full-width bc-schedule-editor', hidden: true }, [
					C.createElement('legend', { class: 'bc-field__label', text: t('budgetcheck', 'Scheduled dates') }),
					C.createElement('p', {
						class: 'bc-field__hint bc-field__hint--block',
						text: t('budgetcheck', 'Add one row for each date this rule should create a planned booking. Leave a row\'s amount empty to use the default amount above. Dates can be in any order; they are sorted automatically.'),
					}),
					scheduleRows,
					C.createElement('div', { class: 'bc-schedule-editor__footer' }, [scheduleAddBtn, scheduleStatus]),
				]);
				form.appendChild(scheduleWrap);
				if (rule && rule.frequency === 'schedule' && Array.isArray(rule.schedule)) {
					rule.schedule.forEach((entry) => {
						addScheduleRow(
							String(entry.date || ''),
							entry.amountMinor !== null && entry.amountMinor !== undefined ? minorToHuman(entry.amountMinor) : '',
							false
						);
					});
				}
				const syncScheduleUi = () => {
					const scheduleMode = freqSelect.value === 'schedule';
					scheduleWrap.hidden = !scheduleMode;
					startFieldWrap.hidden = scheduleMode;
					endFieldWrap.hidden = scheduleMode;
					startInput.disabled = scheduleMode;
					endInput.disabled = scheduleMode;
					amountLabelText.textContent = scheduleMode ? t('budgetcheck', 'Default amount') : t('budgetcheck', 'Amount');
					amountHint.textContent = scheduleMode
						? t('budgetcheck', 'Used for scheduled dates that do not set their own amount.')
						: t('budgetcheck', 'Enter the amount for each occurrence.');
					if (scheduleMode && scheduleRows.children.length === 0) {
						addScheduleRow('', '', false);
					}
					syncScheduleStatus();
					if (syncRealignUiRef) syncRealignUiRef();
				};
				freqSelect.addEventListener('change', syncScheduleUi);
				syncScheduleUi();
				const isActiveInput = C.createElement('input', {
					type: 'checkbox',
					name: 'isActive',
					value: '1',
					checked: rule ? !!rule.isActive : true,
				});
				form.appendChild(C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Rule status') }),
					C.createElement('span', { class: 'bc-boolean-control' }, [
						isActiveInput,
						C.createElement('span', {
							class: 'bc-boolean-control__text',
							text: t('budgetcheck', 'Rule is active'),
						}),
					]),
					C.createElement('span', {
						class: 'bc-field__hint',
						text: t('budgetcheck', 'Inactive rules stay listed but cannot generate new planned entries.'),
					}),
				]));
				let realignToggle = null;
				let realignDateInput = null;
				let realignDateWrap = null;
				let realignPreview = null;
				if (isEdit) {
					realignToggle = C.createElement('input', {
						type: 'checkbox',
						name: 'realignNextDue',
						value: '1',
					});
					realignDateInput = C.createElement('input', {
						type: 'date',
						name: 'realignFromDate',
						class: 'bc-input',
						autocomplete: 'off',
						value: rule ? String(rule.startDate) : Dates.isoDate(new Date()),
						attrs: { lang: Ws.htmlLang },
					});
					const realignModeHint = C.createElement('span', {
						class: 'bc-field__hint',
						text: t('budgetcheck', 'When enabled, the next due date is reset to this date when you save.'),
					});
					realignDateWrap = C.createElement('label', { class: 'bc-field', hidden: true }, [
						C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Realign from date') }),
						realignDateInput,
						realignModeHint,
					]);
					const syncRealignModeHint = () => {
						realignModeHint.textContent = freqSelect.value === 'schedule'
							? t('budgetcheck', 'When enabled, the next due date jumps to the first scheduled date on or after this day when you save.')
							: t('budgetcheck', 'When enabled, the next due date is reset to this date when you save.');
					};
					freqSelect.addEventListener('change', syncRealignModeHint);
					syncRealignModeHint();
					realignPreview = C.createElement('span', { class: 'bc-field__hint' });
					const realignControl = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' }, [
						C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Schedule alignment') }),
						C.createElement('span', { class: 'bc-boolean-control' }, [
							realignToggle,
							C.createElement('span', {
								class: 'bc-boolean-control__text',
								text: t('budgetcheck', 'Reset upcoming due date to the new schedule'),
							}),
						]),
						realignPreview,
					]);
					form.appendChild(realignControl);
					form.appendChild(realignDateWrap);
					const syncRealignUi = () => {
						const enabled = !!realignToggle.checked;
						realignDateWrap.hidden = !enabled;
						if (!enabled) {
							realignPreview.textContent = t('budgetcheck', 'Current next due date stays at {date}.')
								.replace('{date}', Dates.formatDisplayDate(rule.nextDueDate, Ws.htmlLang));
							return;
						}
						const raw = (realignDateInput.value || '').trim();
						if (!Dates.isIsoCalendarDay(raw)) {
							realignPreview.textContent = t('budgetcheck', 'Select a valid realign date.');
							return;
						}
						if (freqSelect.value === 'schedule') {
							const scheduleDates = Array.from(scheduleRows.children).map((scheduleRow) => {
								return String(scheduleRow.querySelector('[data-bc-schedule-date]')?.value || '').trim();
							}).filter((d) => Dates.isIsoCalendarDay(d));
							const snapped = snapScheduleDateOnOrAfter(scheduleDates, raw);
							if (snapped === null) {
								realignPreview.textContent = t('budgetcheck', 'No scheduled date on or after the realign date.');
							} else {
								realignPreview.textContent = t('budgetcheck', 'After saving, next due date will be {date}.')
									.replace('{date}', Dates.formatDisplayDate(snapped, Ws.htmlLang));
							}
							return;
						}
						realignPreview.textContent = t('budgetcheck', 'After saving, next due date will be {date}.')
							.replace('{date}', Dates.formatDisplayDate(raw, Ws.htmlLang));
					};
					syncRealignUiRef = syncRealignUi;
					realignToggle.addEventListener('change', syncRealignUi);
					realignDateInput.addEventListener('input', syncRealignUi);
					freqSelect.addEventListener('change', syncRealignUi);
					syncRealignUi();
				}

				form._collect = () => {
					const scheduleMode = freqSelect.value === 'schedule';
					const payload = {
						workspaceId: Ws.workspace.id,
						title: titleInput.value.trim(),
						direction: directionSelect.value,
						categoryId: catSelect.value ? Number.parseInt(catSelect.value, 10) : 0,
						amount: amountInput.value,
						frequency: freqSelect.value,
						intervalCount: freqSelect.value === 'custom_interval' ? (Number.parseInt(intervalSelect.value, 10) || 2) : 1,
						isActive: isActiveInput.checked,
						realignNextDue: !!(realignToggle && realignToggle.checked),
						realignFromDate: realignDateInput ? realignDateInput.value.trim() : '',
					};
					if (scheduleMode) {
						payload.schedule = Array.from(scheduleRows.children).map((row) => ({
							date: String(row.querySelector('[data-bc-schedule-date]')?.value || '').trim(),
							amount: String(row.querySelector('[data-bc-schedule-amount]')?.value || '').trim(),
						}));
					} else {
						payload.startDate = startInput.value.trim();
						payload.endDate = endInput.value.trim();
					}
					return payload;
				};
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				const payload = form && form._collect ? form._collect() : null;
				if (!payload) return false;
				const scheduleMode = payload.frequency === 'schedule';
				if (scheduleMode) {
					const cleaned = [];
					const seen = new Set();
					for (const row of (payload.schedule || [])) {
						const date = String(row.date || '').trim();
						const amount = String(row.amount || '').trim();
						if (date === '' && amount === '') continue;
						if (date === '' || !Dates.isIsoCalendarDay(date)) {
							Msg.announce(t('budgetcheck', 'Every scheduled row needs a valid date.'), 'error');
							return false;
						}
						if (seen.has(date)) {
							Msg.announce(
								t('budgetcheck', 'The date {date} is listed twice.').replace('{date}', Dates.formatDisplayDate(date, Ws.htmlLang)),
								'error'
							);
							return false;
						}
						seen.add(date);
						if (amount !== '') {
							try {
								Money.parseHuman(amount, decimals);
							} catch (e) {
								Msg.announce(e.message, 'error');
								return false;
							}
						}
						cleaned.push({ date, amount });
					}
					if (cleaned.length === 0) {
						Msg.announce(t('budgetcheck', 'Add at least one scheduled date.'), 'error');
						return false;
					}
					if (cleaned.length > RECURRING_MAX_SCHEDULE_ENTRIES) {
						Msg.announce(
							t('budgetcheck', 'A schedule can include at most {count} dates.')
								.replace('{count}', String(RECURRING_MAX_SCHEDULE_ENTRIES)),
							'error'
						);
						return false;
					}
					payload.schedule = cleaned;
				} else {
					const endRaw = String(payload.endDate || '').trim();
					if (!Dates.isIsoCalendarDay(payload.startDate) || (endRaw !== '' && !Dates.isIsoCalendarDay(endRaw))) {
						Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
						return false;
					}
					payload.startDate = String(payload.startDate).trim();
					payload.endDate = endRaw;
					if (payload.endDate !== '' && payload.endDate < payload.startDate) {
						Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
						return false;
					}
					if (payload.endDate !== '' && payload.endDate === payload.startDate) {
						const proceed = await C.confirmDialog({
							title: t('budgetcheck', 'Same start and end date?'),
							body: t('budgetcheck', 'Only one planned booking can be created on this date. Clear the end date if you want the rule to repeat on your schedule.'),
							confirmLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add rule'),
						});
						if (!proceed) {
							return false;
						}
					}
				}
				try {
					Money.parseHuman(payload.amount, decimals);
				} catch (e) {
					Msg.announce(e.message, 'error');
					return false;
				}
				if (isEdit && payload.realignNextDue) {
					const realignRaw = String(payload.realignFromDate || '').trim();
					if (!Dates.isIsoCalendarDay(realignRaw)) {
						Msg.announce(t('budgetcheck', 'Select a valid realign date.'), 'error');
						return false;
					}
					if (scheduleMode) {
						const lastDate = payload.schedule.reduce((max, e) => (e.date > max ? e.date : max), '');
						if (lastDate !== '' && realignRaw > lastDate) {
							Msg.announce(t('budgetcheck', 'No scheduled date on or after the realign date.'), 'error');
							return false;
						}
					} else {
						if (realignRaw < payload.startDate) {
							Msg.announce(t('budgetcheck', 'Realign date must not be before start date.'), 'error');
							return false;
						}
						if (payload.endDate !== '' && realignRaw > payload.endDate) {
							Msg.announce(t('budgetcheck', 'Realign date must not be after end date.'), 'error');
							return false;
						}
					}
					payload.nextDueDate = realignRaw;
				}
				delete payload.realignNextDue;
				delete payload.realignFromDate;
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

	async function generateRecurring(rule, options = {}) {
		try {
			const fullPeriod = !!options.fullPeriod;
			const response = await Api.post(
				'/apps/budgetcheck/api/recurring-rules/' + rule.id + '/generate',
				fullPeriod ? { mode: 'full_period' } : {}
			);
			if (fullPeriod) {
				const count = Number.parseInt(String(response?.generated?.count || 0), 10) || 0;
				const message = count === 1
					? t('budgetcheck', '1 transaction generated for the full period.')
					: t('budgetcheck', '{count} transactions generated for the full period.').replace('{count}', String(count));
				Msg.announce(message, 'success');
			} else {
				Msg.announce(t('budgetcheck', 'Transaction generated.'), 'success');
			}
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
