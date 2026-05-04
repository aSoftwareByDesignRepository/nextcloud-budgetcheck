(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const ws = Ws.workspace;
	let capabilities = null;

	document.addEventListener('DOMContentLoaded', () => {
		bootstrap();
	});

	async function bootstrap() {
		const needsCaps = document.querySelector('[data-bc-timezone-select]')
			|| document.querySelector('[data-bc-currency-select]')
			|| document.querySelector('[data-bc-app-policy-form]');
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
			loadRecurring();
		} else if (ws) {
			loadCategories();
		}
		await initAppPolicyUi();
		wireAppPolicy();
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
		fillTimezoneSelect(document.querySelector('[data-bc-timezone-select]'), ws?.timezone);
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
		if (!sel || !ws) return;
		fillCurrencySelect(sel, ws.currencyCode);
	}

	function hydrateWorkspaceForm() {
		if (!ws) return;
		const form = document.querySelector('[data-bc-workspace-form]');
		if (!form) return;
		setVal(form, 'name', ws.name);
		populateWorkspaceCurrencySelect();
		setVal(form, 'overspendThresholdMinor', ws.overspendThresholdMinor !== null ? String(ws.overspendThresholdMinor) : '');
		if (ws.type === 'household') {
			const pyFromWs = typeof ws.primaryPlanningYear === 'number' ? ws.primaryPlanningYear : null;
			const pyFromCreated = Number.parseInt(String(ws.createdAt || '').slice(0, 4), 10);
			const py = pyFromWs !== null && pyFromWs >= 1900 && pyFromWs <= 9999
				? pyFromWs
				: (Number.isFinite(pyFromCreated) && pyFromCreated >= 1900 ? pyFromCreated : new Date().getFullYear());
			setVal(form, 'primaryPlanningYear', String(py));
			const cb = form.querySelector('input[name="autoCopyBudgetsFromPreviousMonth"]');
			if (cb) cb.checked = !!ws.autoCopyBudgetsFromPreviousMonth;
		} else {
			setVal(form, 'projectStartDate', ws.projectStartDate ? String(ws.projectStartDate) : '');
			setVal(form, 'projectEndDate', ws.projectEndDate ? String(ws.projectEndDate) : '');
			setVal(form, 'projectTotalCapMinor', ws.projectTotalCapMinor !== null ? String(ws.projectTotalCapMinor) : '');
		}
	}

	function hydrateTaxForm() {
		if (!ws) return;
		const form = document.querySelector('[data-bc-tax-form]');
		if (!form) return;
		const enabled = form.querySelector('input[name="taxModeEnabled"]');
		if (enabled) enabled.checked = !!ws.taxModeEnabled;
		setVal(form, 'taxBudgetBasis', ws.taxBudgetBasis || 'gross');
		const presetSelect = form.querySelector('[data-bc-vat-preset]');
		const customWrap = form.querySelector('[data-bc-vat-custom-wrap]');
		const customInput = form.querySelector('[data-bc-vat-custom]');
		if (!presetSelect || !customWrap || !customInput) return;
		const bp = ws.defaultVatRateBp;
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
		if (!form || !ws) return;
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (!Ws.canManage) return;
			const payload = {
				name: getVal(form, 'name').trim(),
				currencyCode: getVal(form, 'currencyCode').trim().toUpperCase(),
				timezone: getVal(form, 'timezone'),
				overspendThresholdMinor: getVal(form, 'overspendThresholdMinor').trim() || null,
			};
			if (ws.type === 'household') {
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
				payload.projectTotalCapMinor = cap === '' ? null : Number.parseInt(cap, 10);
			}
			if (payload.overspendThresholdMinor !== null) {
				payload.overspendThresholdMinor = Number.parseInt(payload.overspendThresholdMinor, 10);
			}
			try {
				await Api.put('/apps/budgetcheck/api/workspaces/' + ws.id, payload);
				Msg.announce(t('budgetcheck', 'Workspace saved.'), 'success');
				window.setTimeout(() => window.location.reload(), 600);
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	function wireTaxForm() {
		const form = document.querySelector('[data-bc-tax-form]');
		if (!form || !ws) return;
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
				await Api.put('/apps/budgetcheck/api/workspaces/' + ws.id + '/tax-mode', payload);
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
	async function loadCategories() {
		const tbody = document.querySelector('[data-bc-category-rows]');
		if (!tbody) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: ws.id, includeInactive: '1' });
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
			if (cat.isActive) {
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
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: ws.id, includeInactive: '1' });
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
				wrap(form, t('budgetcheck', 'Name'), name);
				const typeSelect = C.createElement('select', { name: 'type', class: 'bc-input', disabled: isEdit }, [
					C.createElement('option', { value: 'expense', text: t('budgetcheck', 'Expense') }),
					C.createElement('option', { value: 'income', text: t('budgetcheck', 'Income') }),
				]);
				typeSelect.value = cat ? cat.type : 'expense';
				wrap(form, t('budgetcheck', 'Direction'), typeSelect);
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
				wrap(form, t('budgetcheck', 'Group'), groupSelect);
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
				wrap(form, t('budgetcheck', 'Tax handling'), taxSelect);
				const specialOuter = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' });
				specialOuter.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Category options') }));
				const specialRow = C.createElement('span', { class: 'bc-boolean-control' });
				const specialInput = C.createElement('input', { type: 'checkbox', name: 'isSpecial', value: '1' });
				specialInput.checked = !!(cat && cat.isSpecial);
				specialRow.appendChild(specialInput);
				specialRow.appendChild(C.createElement('span', { class: 'bc-boolean-control__text', text: t('budgetcheck', 'Mark as special by default') }));
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
						workspaceId: ws.id,
						name: name.value.trim(),
						type: typeSelect.value,
						groupKey,
						taxHandlingMode: taxSelect.value,
						isSpecial: specialInput.checked,
					};
				};
				return form;
			},
			onSubmit: async ({ close }) => {
				const form = document.querySelector('.bc-modal__form');
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
		if (!tbody || !ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/workspaces/' + ws.id + '/members');
			tbody.replaceChildren();
			(data.members || []).forEach((m) => tbody.appendChild(renderMemberRow(m)));
		} catch (err) {
			Msg.handleApiError(err);
		}
		document.querySelectorAll('[data-bc-action="open-add-member"]').forEach((btn) => {
			btn.addEventListener('click', () => openAddMemberModal());
		});
	}

	function renderMemberRow(member) {
		const tr = C.createElement('tr');
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

	function openAddMemberModal() {
		C.openModal({
			title: t('budgetcheck', 'Add member'),
			primaryLabel: t('budgetcheck', 'Add'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const search = C.createElement('input', { type: 'search', name: 'q', class: 'bc-input', autocomplete: 'off', attrs: { 'aria-label': t('budgetcheck', 'Find user') } });
				wrap(form, t('budgetcheck', 'Find user'), search);
				const roleSelect = C.createElement('select', { name: 'role', class: 'bc-input' }, [
					C.createElement('option', { value: 'viewer', text: t('budgetcheck', 'Viewer') }),
					C.createElement('option', { value: 'contributor', text: t('budgetcheck', 'Contributor') }),
					C.createElement('option', { value: 'manager', text: t('budgetcheck', 'Manager') }),
				]);
				wrap(form, t('budgetcheck', 'Role'), roleSelect);
				const list = C.createElement('div', { class: 'bc-user-search-results', attrs: { role: 'listbox', 'aria-label': t('budgetcheck', 'Search results') } });
				form.appendChild(list);
				let pickedUserId = '';
				let timer = null;
				search.addEventListener('input', () => {
					if (timer) window.clearTimeout(timer);
					timer = window.setTimeout(async () => {
						const q = search.value.trim();
						list.replaceChildren();
						if (q.length < 2) return;
						try {
							const data = await Api.get('/apps/budgetcheck/api/admin/users', { q });
							(data.users || []).forEach((u) => {
								const btn = C.createElement('button', { type: 'button', class: 'button', text: u.displayName + ' (' + u.id + ')' });
								btn.addEventListener('click', () => {
									pickedUserId = u.id;
									search.value = u.id;
									list.replaceChildren();
								});
								list.appendChild(btn);
							});
						} catch (err) {
							Msg.handleApiError(err);
						}
					}, 250);
				});
				form._collect = () => ({ userId: pickedUserId || search.value.trim(), role: roleSelect.value });
				return form;
			},
			onSubmit: async ({ close }) => {
				const form = document.querySelector('.bc-modal__form');
				const payload = form && form._collect ? form._collect() : null;
				if (!payload || !payload.userId) {
					Msg.announce(t('budgetcheck', 'Pick a user.'), 'warning');
					return false;
				}
				try {
					await Api.post('/apps/budgetcheck/api/workspaces/' + ws.id + '/members', payload);
					Msg.announce(t('budgetcheck', 'Member added.'), 'success');
					loadMembers();
					close(true);
				} catch (err) {
					Msg.handleApiError(err, { reloadOnConflict: false });
					return false;
				}
			},
		});
	}

	// ------ Recurring rules ------
	async function loadRecurring() {
		const tbody = document.querySelector('[data-bc-recurring-rows]');
		if (!tbody || !ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/recurring-rules', { workspaceId: ws.id });
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
		const cats = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: ws.id });
		const decimals = typeof ws.currencyDecimals === 'number' ? ws.currencyDecimals : (ws.currencyCode === 'JPY' ? 0 : 2);
		C.openModal({
			title: isEdit ? t('budgetcheck', 'Edit rule') : t('budgetcheck', 'New recurring rule'),
			primaryLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add rule'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const titleInput = C.createElement('input', { type: 'text', name: 'title', class: 'bc-input', maxlength: 180, required: true, value: rule ? rule.title : '' });
				wrap(form, t('budgetcheck', 'Title'), titleInput);
				const directionSelect = C.createElement('select', { name: 'direction', class: 'bc-input' }, [
					C.createElement('option', { value: 'expense', text: t('budgetcheck', 'Expense') }),
					C.createElement('option', { value: 'income', text: t('budgetcheck', 'Income') }),
				]);
				directionSelect.value = rule ? rule.direction : 'expense';
				wrap(form, t('budgetcheck', 'Direction'), directionSelect);
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
				wrap(form, t('budgetcheck', 'Category'), catSelect);
				const amountInput = C.createElement('input', {
					type: 'text', inputmode: 'decimal', name: 'amount', class: 'bc-input', required: true,
					value: rule ? (rule.amount.minor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',') : '',
					attrs: { 'aria-label': t('budgetcheck', 'Amount') },
				});
				wrap(form, t('budgetcheck', 'Amount'), amountInput);
				const freqSelect = C.createElement('select', { name: 'frequency', class: 'bc-input' }, [
					C.createElement('option', { value: 'monthly', text: t('budgetcheck', 'Monthly') }),
					C.createElement('option', { value: 'quarterly', text: t('budgetcheck', 'Quarterly') }),
					C.createElement('option', { value: 'yearly', text: t('budgetcheck', 'Yearly') }),
					C.createElement('option', { value: 'custom_interval', text: t('budgetcheck', 'Custom (months)') }),
				]);
				freqSelect.value = rule ? rule.frequency : 'monthly';
				wrap(form, t('budgetcheck', 'Frequency'), freqSelect);
				const intervalInput = C.createElement('input', { type: 'number', name: 'intervalCount', min: 1, max: 36, class: 'bc-input', value: rule ? String(rule.intervalCount) : '1' });
				wrap(form, t('budgetcheck', 'Interval count'), intervalInput);
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
					endHint,
				]));

				form._collect = () => ({
					workspaceId: ws.id,
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
			onSubmit: async ({ close }) => {
				const form = document.querySelector('.bc-modal__form');
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

	// ------ App policy ------
	async function initAppPolicyUi() {
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form || !Ws.canAdmin) return;
		if (!capabilities?.timezones || !capabilities?.currencies) {
			try {
				const data = await Api.get('/apps/budgetcheck/api/workspaces');
				capabilities = data.capabilities || {};
			} catch (err) {
				Msg.handleApiError(err);
				return;
			}
		}
		let policy = { appAdminUserIds: [], defaultTimezone: 'Europe/Berlin', defaultCurrency: 'EUR' };
		try {
			const res = await Api.get('/apps/budgetcheck/api/admin/policy');
			policy = res.policy || policy;
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		form._bcAppAdminIds = [...(policy.appAdminUserIds || [])];
		fillTimezoneSelect(form.querySelector('[data-bc-default-timezone-select]'), policy.defaultTimezone);
		fillCurrencySelect(form.querySelector('[data-bc-default-currency-select]'), policy.defaultCurrency);
		renderAppAdminChips(form);
		const addBtn = form.querySelector('[data-bc-action="add-app-admin"]');
		if (addBtn && !addBtn.dataset.bcWired) {
			addBtn.dataset.bcWired = '1';
			addBtn.addEventListener('click', () => { void openAddAppAdminForPolicy(form); });
		}
	}

	function renderAppAdminChips(form) {
		const ul = form.querySelector('[data-bc-app-admin-list]');
		if (!ul) return;
		ul.replaceChildren();
		(form._bcAppAdminIds || []).forEach((uid) => {
			const li = C.createElement('li', { class: 'bc-chip' });
			li.appendChild(C.createElement('span', { class: 'bc-chip__text', text: uid }));
			const rm = C.createElement('button', {
				type: 'button',
				class: 'bc-chip__remove',
				text: '×',
				attrs: { 'aria-label': t('budgetcheck', 'Remove') + ' ' + uid },
			});
			rm.addEventListener('click', () => {
				form._bcAppAdminIds = (form._bcAppAdminIds || []).filter((x) => x !== uid);
				renderAppAdminChips(form);
			});
			li.appendChild(rm);
			ul.appendChild(li);
		});
	}

	function openAddAppAdminForPolicy(form) {
		C.openModal({
			title: t('budgetcheck', 'Add app administrator'),
			primaryLabel: t('budgetcheck', 'Add'),
			render: () => {
				const bodyForm = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const hint = C.createElement('p', { class: 'bc-field__hint bc-field__hint--block', text: t('budgetcheck', 'Type at least two characters to search by user ID or display name.') });
				bodyForm.appendChild(hint);
				const search = C.createElement('input', { type: 'search', name: 'q', class: 'bc-input', autocomplete: 'off', attrs: { 'aria-label': t('budgetcheck', 'Find user') } });
				wrap(bodyForm, t('budgetcheck', 'Find user'), search);
				const list = C.createElement('div', { class: 'bc-user-search-results', attrs: { role: 'listbox', 'aria-label': t('budgetcheck', 'Search results') } });
				bodyForm.appendChild(list);
				let timer = null;
				search.addEventListener('input', () => {
					if (timer) window.clearTimeout(timer);
					timer = window.setTimeout(async () => {
						const q = search.value.trim();
						list.replaceChildren();
						if (q.length < 2) return;
						try {
							const data = await Api.get('/apps/budgetcheck/api/admin/users', { q });
							(data.users || []).forEach((u) => {
								const btn = C.createElement('button', {
									type: 'button',
									class: 'button bc-user-search-hit',
									text: u.displayName + ' (' + u.id + ')',
								});
								btn.addEventListener('click', () => {
									search.value = u.id;
									list.replaceChildren();
								});
								list.appendChild(btn);
							});
						} catch (err) {
							Msg.handleApiError(err);
						}
					}, 250);
				});
				bodyForm._picked = () => search.value.trim();
				return bodyForm;
			},
			onSubmit: async ({ close }) => {
				const bodyForm = document.querySelector('.bc-modal__form');
				const uid = bodyForm && bodyForm._picked ? bodyForm._picked() : '';
				if (!uid) {
					Msg.announce(t('budgetcheck', 'Pick a user.'), 'warning');
					return false;
				}
				if ((form._bcAppAdminIds || []).includes(uid)) {
					Msg.announce(t('budgetcheck', 'That user is already an administrator.'), 'warning');
					return false;
				}
				form._bcAppAdminIds = [...(form._bcAppAdminIds || []), uid];
				renderAppAdminChips(form);
				close(true);
			},
		});
	}

	function wireAppPolicy() {
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form) return;
		if (form.dataset.bcSubmitWired) return;
		form.dataset.bcSubmitWired = '1';
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const payload = {
				appAdminUserIds: form._bcAppAdminIds || [],
				defaultTimezone: getVal(form, 'defaultTimezone'),
				defaultCurrency: getVal(form, 'defaultCurrency').trim().toUpperCase(),
			};
			try {
				await Api.post('/apps/budgetcheck/api/admin/policy', payload);
				Msg.announce(t('budgetcheck', 'App policy saved.'), 'success');
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	function setVal(form, name, value) {
		const el = form.querySelector('[name="' + name + '"]');
		if (el) el.value = value === null || value === undefined ? '' : value;
	}
	function getVal(form, name) {
		const el = form.querySelector('[name="' + name + '"]');
		return el ? String(el.value || '') : '';
	}
	function wrap(form, label, control) {
		const wrapper = C.createElement('label', { class: 'bc-field' }, [
			C.createElement('span', { class: 'bc-field__label', text: label }),
			control,
		]);
		form.appendChild(wrapper);
	}
})();
