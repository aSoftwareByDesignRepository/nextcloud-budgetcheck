(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const dashState = { yearMonth: Dates.currentYearMonth() };
	/** Set on DOMContentLoaded after #app-content exists (see lazy workspace.js). */
	let ws = null;
	let isHousehold = false;
	let dashPeriodPicker = null;

	document.addEventListener('DOMContentLoaded', () => {
		ws = Ws.workspace;
		if (!ws) {
			wireWorkspaceCreator();
			return;
		}
		isHousehold = ws.type === 'household';
		const summarySection = document.querySelector('[data-bc-summary]');
		const box = summarySection?.querySelector('[data-bc-household-period]');
		const Period = window.BudgetCheckHouseholdPeriod;
		if (isHousehold && box && Period && typeof Period.wire === 'function') {
			dashPeriodPicker = Period.wire(box, {
				workspace: ws,
				htmlLang: Ws.htmlLang,
				initialYearMonth: dashState.yearMonth,
				onChange: (ym) => {
					dashState.yearMonth = ym;
					loadAndRender(ym);
				},
			});
		}
		loadAndRender(isHousehold ? dashState.yearMonth : null);
		loadRecent();
		wireWorkspaceCreator();
	});

	async function loadAndRender(yearMonth) {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const periodLabel = document.querySelector('[data-bc-summary-period]');
		const warningsSection = document.querySelector('[data-bc-warnings]');
		const warningsList = document.querySelector('[data-bc-warnings-list]');
		if (!grid) return;
		grid.setAttribute('aria-busy', 'true');
		try {
			const data = isHousehold
				? await Api.get('/apps/budgetcheck/api/monthly-summary', { workspaceId: ws.id, yearMonth: yearMonth || Dates.currentYearMonth() })
				: await Api.get('/apps/budgetcheck/api/project-period-summary', { workspaceId: ws.id });
			const summary = data.summary;
			renderSummaryGrid(grid, summary);
			if (periodLabel) periodLabel.textContent = formatSummaryPeriod(summary);
			const dashLedger = document.querySelector('[data-bc-dash-ledger-help]');
			if (isHousehold && dashLedger) {
				C.renderMonthlyLedgerHelp(dashLedger, summary, yearMonth || Dates.currentYearMonth(), Ws.htmlLang);
			}
			if (isHousehold && dashPeriodPicker && summary.ledgerYearMonthSpan) {
				dashPeriodPicker.refreshLedgerSpan(summary.ledgerYearMonthSpan);
			}
			renderWarnings(warningsSection, warningsList, summary.warnings || []);
		} catch (err) {
			Msg.handleApiError(err);
			grid.replaceChildren(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'Could not load the summary.') }));
		} finally {
			grid.setAttribute('aria-busy', 'false');
		}
	}

	async function loadRecent() {
		const list = document.querySelector('[data-bc-recent-list]');
		if (!list) return;
		list.setAttribute('aria-busy', 'true');
		try {
			const data = await Api.get('/apps/budgetcheck/api/transactions', { workspaceId: ws.id, limit: 8, offset: 0 });
			const items = data.items || [];
			list.replaceChildren();
			if (items.length === 0) {
				list.appendChild(C.createElement('li', { class: 'bc-tx-list__item' }, [
					C.createElement('div', { class: 'bc-tx-list__title', text: t('budgetcheck', 'No transactions yet.') }),
					C.createElement('div', { class: 'bc-tx-list__meta', text: t('budgetcheck', 'Use the transactions screen to add your first entry.') }),
				]));
				return;
			}
			items.forEach((tx) => list.appendChild(renderTxListItem(tx)));
		} catch (err) {
			Msg.handleApiError(err);
			list.replaceChildren(C.createElement('li', { class: 'bc-loading', text: t('budgetcheck', 'Could not load transactions.') }));
		} finally {
			list.setAttribute('aria-busy', 'false');
		}
	}

	function renderTxListItem(tx) {
		const directionLabel = tx.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense');
		const amount = Money.formatEnvelope(tx.amount, Ws.htmlLang);
		return C.createElement('li', { class: 'bc-tx-list__item' }, [
			C.createElement('div', null, [
				C.createElement('div', { class: 'bc-tx-list__title', text: tx.title }),
				C.createElement('div', { class: 'bc-tx-list__meta', text: `${Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang)} · ${directionLabel}` }),
			]),
			C.createElement('div', {
				class: 'bc-tx-list__amount ' + (tx.direction === 'income' ? 'bc-tx-amount--income' : 'bc-tx-amount--expense'),
				text: (tx.direction === 'income' ? '+' : '−') + ' ' + amount,
			}),
		]);
	}

	function renderSummaryGrid(grid, summary) {
		grid.replaceChildren();
		const totals = summary.totals || {};
		const tiles = [];
		tiles.push(makeTile(t('budgetcheck', 'Income'), totals.income));
		tiles.push(makeTile(t('budgetcheck', 'Expenses'), totals.expense));
		tiles.push(makeTile(t('budgetcheck', 'Net result'), totals.netResult, { primary: true }));
		if (isHousehold) {
			tiles.push(makeTile(t('budgetcheck', 'Savings target'), totals.savingsTarget));
			tiles.push(makeTile(t('budgetcheck', 'Available after savings'), totals.availableAfterSavings, { hint: t('budgetcheck', 'After income − expenses − savings target') }));
		} else if (summary.allTime && summary.allTime.cap) {
			tiles.push(makeTile(t('budgetcheck', 'Cap'), summary.allTime.cap, { hint: t('budgetcheck', 'Project total cap') }));
			tiles.push(makeTile(t('budgetcheck', 'Spent so far'), summary.allTime.expense));
			tiles.push(makeTile(t('budgetcheck', 'Remaining headroom'), summary.allTime.remainingHeadroom, { primary: true }));
		}
		tiles.forEach((node) => grid.appendChild(node));
	}

	function makeTile(label, env, opts) {
		const o = opts || {};
		return C.createElement('div', { class: 'bc-summary-tile' + (o.primary ? ' bc-summary-tile--primary' : '') }, [
			C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
			C.createElement('div', { class: 'bc-summary-tile__value', text: env ? Money.formatEnvelope(env, Ws.htmlLang) : '—' }),
			o.hint ? C.createElement('div', { class: 'bc-summary-tile__hint', text: o.hint }) : null,
		]);
	}

	function formatSummaryPeriod(summary) {
		if (isHousehold) {
			return Dates.formatYearMonth(summary.yearMonth, Ws.htmlLang);
		}
		const win = summary.window || {};
		if (!win.from || !win.to) return '';
		return Dates.formatDisplayDate(win.from, Ws.htmlLang) + ' – ' + Dates.formatDisplayDate(win.to, Ws.htmlLang);
	}

	function renderWarnings(section, list, warnings) {
		if (!section || !list) return;
		if (!warnings.length) {
			section.hidden = true;
			list.replaceChildren();
			return;
		}
		section.hidden = false;
		list.replaceChildren();
		warnings.forEach((w) => list.appendChild(renderWarning(w)));
	}

	function renderWarning(warning) {
		const sev = warning.severity || 'info';
		const item = C.createElement('li', { class: 'bc-warning bc-warning--' + sev });
		item.appendChild(C.createElement('span', { 'aria-hidden': 'true', text: sev === 'critical' ? '!' : (sev === 'warning' ? '⚠' : 'i') }));
		const body = C.createElement('div');
		body.appendChild(C.createElement('div', { class: 'bc-warning__title', text: warning.title || warning.code }));
		body.appendChild(C.createElement('div', { class: 'bc-warning__message', text: warning.message || '' }));
		item.appendChild(body);
		const recovery = warning.recovery;
		if (recovery && recovery.screen && Ws.urls[recovery.screen]) {
			let href = Ws.withWorkspace(Ws.urls[recovery.screen]);
			const params = recovery.params || {};
			Object.entries(params).forEach(([key, value]) => {
				if (value === null || value === undefined || value === '') return;
				href += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
			});
			const link = C.createElement('a', { class: 'button', href, text: t('budgetcheck', 'Open') });
			item.appendChild(C.createElement('div', { class: 'bc-warning__action' }, [link]));
		} else {
			item.appendChild(C.createElement('div'));
		}
		return item;
	}

	function wireWorkspaceCreator() {
		document.querySelectorAll('[data-bc-action="open-create-workspace"]').forEach((btn) => {
			btn.addEventListener('click', () => openCreateWorkspaceModal());
		});
	}

	async function openCreateWorkspaceModal() {
		let capabilities;
		try {
			const data = await Api.get('/apps/budgetcheck/api/workspaces');
			capabilities = data.capabilities || {};
			if (!capabilities.canCreateWorkspace) {
				Msg.announce(t('budgetcheck', 'Only app administrators can create workspaces.'), 'warning');
				return;
			}
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}

		C.openModal({
			title: t('budgetcheck', 'New workspace'),
			primaryLabel: t('budgetcheck', 'Create workspace'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form' });
				const nameInput = field(form, t('budgetcheck', 'Name'), 'name', 'text', { required: true, maxlength: 120 });
				nameInput.focus();

				const typeSelect = C.createElement('select', { name: 'type', class: 'bc-input' }, [
					C.createElement('option', { value: 'household', text: t('budgetcheck', 'Household (monthly rhythm)') }),
					C.createElement('option', { value: 'project', text: t('budgetcheck', 'Project (start/end dates)') }),
				]);
				wrap(form, t('budgetcheck', 'Type'), typeSelect);

				const planYearSelect = C.createElement('select', { name: 'primaryPlanningYear', class: 'bc-input' });
				const yNow = new Date().getFullYear();
				for (let y = yNow + 3; y >= yNow - 15; y--) {
					planYearSelect.appendChild(C.createElement('option', { value: String(y), text: String(y), selected: y === yNow }));
				}
				const planYearField = C.createElement('label', { class: 'bc-field' }, [
					C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Primary planning year (household)') }),
					planYearSelect,
					C.createElement('p', {
						class: 'bc-field__hint bc-field__hint--block',
						text: t('budgetcheck', 'Households plan one calendar year at a time. You can change this later in workspace settings.'),
					}),
				]);
				form.appendChild(planYearField);

				const currencySelect = C.createElement('select', { name: 'currencyCode', class: 'bc-input', required: true });
				(capabilities.currencies || []).forEach((entry) => {
					const code = typeof entry === 'string' ? entry : (entry && entry.code);
					if (!code) return;
					currencySelect.appendChild(C.createElement('option', { value: code, text: code }));
				});
				const defCur = String(capabilities.defaultCurrency || 'EUR').toUpperCase();
				if (Array.from(currencySelect.options).some((o) => o.value === defCur)) {
					currencySelect.value = defCur;
				}
				wrap(form, t('budgetcheck', 'Currency'), currencySelect);
				const tzSelect = C.createElement('select', { name: 'timezone', class: 'bc-input' });
				(capabilities.timezones || []).forEach((g) => {
					const og = C.createElement('optgroup', { attrs: { label: g.label } });
					(g.items || []).forEach((tz) => og.appendChild(C.createElement('option', { value: tz, text: tz, selected: tz === (capabilities.defaultTimezone || 'UTC') })));
					tzSelect.appendChild(og);
				});
				wrap(form, t('budgetcheck', 'Timezone'), tzSelect);

				const defaultEnd = new Date();
				defaultEnd.setMonth(defaultEnd.getMonth() + 1);
				const startInput = localeDateField(form, t('budgetcheck', 'Project start (project only)'), 'projectStartDate', Dates.isoDate(new Date()));
				const endInput = localeDateField(form, t('budgetcheck', 'Project end (project only)'), 'projectEndDate', Dates.isoDate(defaultEnd));
				const capInput = field(form, t('budgetcheck', 'Project cap (minor units, optional)'), 'projectTotalCapMinor', 'number');
				const projectFields = [startInput, endInput, capInput].map((el) => el.closest('.bc-field'));
				const updateVisibility = () => {
					const isProject = typeSelect.value === 'project';
					projectFields.forEach((f) => f && (f.hidden = !isProject));
					if (planYearField) planYearField.hidden = isProject;
				};
				typeSelect.addEventListener('change', updateVisibility);
				updateVisibility();

				form._collect = () => {
					const isProject = typeSelect.value === 'project';
					const payload = {
						name: nameInput.value.trim(),
						type: typeSelect.value,
						currencyCode: (currencySelect.value || '').toUpperCase().trim(),
						timezone: tzSelect.value,
					};
					if (isProject) {
						payload.projectStartDate = startInput.value;
						payload.projectEndDate = endInput.value;
						const cap = capInput.value.trim();
						if (cap !== '') payload.projectTotalCapMinor = Number.parseInt(cap, 10);
					} else {
						payload.primaryPlanningYear = Number.parseInt(planYearSelect.value, 10);
					}
					return payload;
				};
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				const payload = form && form._collect ? form._collect() : null;
				if (!payload) return false;
				if (payload.type === 'project') {
					const s = String(payload.projectStartDate || '').trim();
					const e = String(payload.projectEndDate || '').trim();
					if (s === '' || e === '') {
						Msg.announce(t('budgetcheck', 'Choose project start and end dates.'), 'error');
						return false;
					}
					if (!Dates.isIsoCalendarDay(s) || !Dates.isIsoCalendarDay(e)) {
						Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
						return false;
					}
					payload.projectStartDate = s;
					payload.projectEndDate = e;
				} else {
					const py = Number.parseInt(String(payload.primaryPlanningYear), 10);
					if (!Number.isFinite(py) || py < 1900 || py > 9999) {
						Msg.announce(t('budgetcheck', 'Primary planning year must be between 1900 and 9999.'), 'error');
						return false;
					}
					payload.primaryPlanningYear = py;
				}
				try {
					const res = await Api.post('/apps/budgetcheck/api/workspaces', payload);
					Msg.announce(t('budgetcheck', 'Workspace created.'), 'success');
					const newId = res.workspace && res.workspace.id;
					if (newId) {
						window.location.href = Ws.urls.dashboard + '?workspaceId=' + newId;
					} else {
						window.location.reload();
					}
					close(true);
				} catch (err) {
					Msg.handleApiError(err, { reloadOnConflict: false });
					return false;
				}
			},
		});
	}

	function field(form, label, name, type, opts) {
		const o = opts || {};
		const input = C.createElement('input', Object.assign({ name, type, class: 'bc-input' }, o));
		wrap(form, label, input);
		return input;
	}

	function localeDateField(form, label, name, initialIsoYmd) {
		const dh = 'bc-ws-new-' + name + '-' + Math.random().toString(36).slice(2);
		const hintText = t('budgetcheck', 'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.');
		const input = C.createElement('input', {
			name,
			type: 'date',
			class: 'bc-input',
			autocomplete: 'off',
			value: initialIsoYmd ? String(initialIsoYmd) : '',
			attrs: { 'aria-describedby': dh, lang: Ws.htmlLang },
		});
		const hint = C.createElement('span', { id: dh, class: 'bc-field__hint', text: hintText });
		form.appendChild(C.createElement('label', { class: 'bc-field' }, [
			C.createElement('span', { class: 'bc-field__label', text: label }),
			input,
			hint,
		]));
		return input;
	}

	function wrap(form, label, input) {
		const wrapper = C.createElement('label', { class: 'bc-field' }, [
			C.createElement('span', { class: 'bc-field__label', text: label }),
			input,
		]);
		form.appendChild(wrapper);
	}
})();
