(function () {
	'use strict';

	// Workspace context shared across page modules.
	//
	// The page-start template sets the active workspace + URL map onto
	// #app-content as JSON-encoded data attributes. Values are read **lazily**
	// from the live DOM so script load order never snapshots an empty
	// `#app-content` (which would permanently strand flags like `canAdmin`).

	function appRoot() {
		return document.querySelector('#app-content.bc-app') || document.getElementById('app-content');
	}

	function dataset() {
		return appRoot()?.dataset || {};
	}

	function parseJson(value, fallback) {
		if (typeof value !== 'string' || value === '' || value === 'null') return fallback;
		try {
			const parsed = JSON.parse(value);
			return parsed === null ? fallback : parsed;
		} catch (_) {
			return fallback;
		}
	}

	const ctx = {
		get workspace() {
			return parseJson(dataset().bcWorkspace, null);
		},
		get workspaces() {
			return parseJson(dataset().bcWorkspaces, []);
		},
		get urls() {
			return parseJson(dataset().bcUrls, {});
		},
		get canManage() {
			return dataset().bcCanManage === '1';
		},
		get canContribute() {
			return dataset().bcCanContribute === '1';
		},
		get canAdmin() {
			return dataset().bcCanAdmin === '1';
		},
		get canCreate() {
			return dataset().bcCanCreate === '1';
		},
		get locale() {
			return dataset().bcLocale || 'en';
		},
		get htmlLang() {
			return dataset().bcHtmlLang || 'en';
		},
		get timezone() {
			return dataset().bcTimezone || 'UTC';
		},
		get page() {
			return dataset().bcPage || '';
		},
		role() {
			// Prefer the closed-over `ctx` so extracted method refs stay safe in
			// strict mode (unbound `this` would otherwise throw on `.workspace`).
			const w = ctx.workspace;
			return w ? w.role : null;
		},
		withWorkspace(url) {
			// Use `ctx` (not `this`) so callers may pass `withWorkspace` around
			// without losing workspace scope — a prior dashboard crash came from
			// extracting this method and invoking it unbound.
			const target = typeof url === 'string' ? url : '';
			if (target === '') return target;
			const w = ctx.workspace;
			if (!w || w.id === undefined || w.id === null) return target;
			const sep = target.indexOf('?') === -1 ? '?' : '&';
			return target + sep + 'workspaceId=' + encodeURIComponent(String(w.id));
		},
		/**
		 * Keep the header scope strip in sync with the month a page is showing.
		 * Pages with a month picker call this whenever their selection changes so
		 * the strip never contradicts the content below it (e.g. strip "July"
		 * while the summary shows August).
		 *
		 * @param {string} ym YYYY-MM
		 */
		setScopeMonth(ym) {
			const el = document.querySelector('[data-bc-scope-month]');
			if (!el) return;
			const value = String(ym || '');
			if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return;
			const D = window.BudgetCheckDates;
			el.textContent = (D && typeof D.formatYearMonth === 'function')
				? D.formatYearMonth(value, ctx.htmlLang)
				: value;
			const active = el.getAttribute('data-bc-scope-month-active') || '';
			el.setAttribute('title', value === active
				? t('budgetcheck', 'Active calendar month in the workspace timezone')
				: t('budgetcheck', 'Month currently shown on this page'));
		},
		patchWorkspace(patch) {
			if (!patch || typeof patch !== 'object') return;
			const el = appRoot();
			if (!el) return;
			const current = parseJson(el.dataset.bcWorkspace, null);
			if (!current) return;
			Object.assign(current, patch);
			el.dataset.bcWorkspace = JSON.stringify(current);
		},
		navigateTo(name) {
			const url = ctx.urls[name];
			if (!url) return;
			window.location.href = ctx.withWorkspace(url);
		},
	};

	// Set up data-bc-link click handlers globally so pages can use
	// `<a data-bc-link="transactions">`.
	document.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-bc-link]');
		if (!trigger) return;
		const name = trigger.getAttribute('data-bc-link');
		if (!name || !ctx.urls[name]) return;
		event.preventDefault();
		ctx.navigateTo(name);
	});

	// Hide quick-start when previously dismissed (per-workspace key).
	document.addEventListener('DOMContentLoaded', () => {
		const dismiss = (key) => {
			const esc = (typeof CSS !== 'undefined' && typeof CSS.escape === 'function')
				? CSS.escape.bind(CSS)
				: (value) => String(value).replace(/["\\]/g, '\\$&');
			const button = document.querySelector(`[data-bc-dismiss-hint="${esc(key)}"]`);
			if (!button) return;
			const card = button.closest('.bc-card');
			if (!card) return;
			const w = ctx.workspace;
			const storageKey = 'bc:hint:' + (w ? w.id : 'global') + ':' + key;
			if (window.localStorage.getItem(storageKey) === '1') {
				card.hidden = true;
			} else {
				card.hidden = false;
				button.addEventListener('click', () => {
					try { window.localStorage.setItem(storageKey, '1'); } catch (_) { /* private mode */ }
					card.hidden = true;
				});
			}
		};
		dismiss('dashboard_quickstart_v1');

		const D = window.BudgetCheckDates;
		const appRootEl = appRoot();
		if (D && typeof D.applyLocaleToTemporalInputs === 'function' && appRootEl) {
			D.applyLocaleToTemporalInputs(appRootEl, ctx.htmlLang);
		}

		wireWorkspaceCreator();

	});

	function wireWorkspaceCreator() {
		document.querySelectorAll('[data-bc-action="open-create-workspace"]').forEach((btn) => {
			if (btn.dataset.bcWorkspaceCreateWired === '1') {
				return;
			}
			btn.dataset.bcWorkspaceCreateWired = '1';
			btn.addEventListener('click', () => {
				void openCreateWorkspaceModal();
			});
		});
	}

	async function openCreateWorkspaceModal() {
		const Api = window.BudgetCheckApi;
		const Msg = window.BudgetCheckMessaging;
		const C = window.BudgetCheckComponents;
		const Money = window.BudgetCheckMoney;
		const Dates = window.BudgetCheckDates;
		const CatalogPickers = window.BudgetCheckCatalogPickers;
		if (!Api || !Msg || !C || !Money || !Dates || !CatalogPickers) {
			return;
		}

		let capabilities;
		try {
			const data = await Api.get('/apps/budgetcheck/api/workspaces');
			capabilities = data.capabilities || {};
			if (!capabilities.canCreateWorkspace) {
				Msg.announce(t('budgetcheck', 'You cannot create a workspace with your current access.'), 'warning');
				return;
			}
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}

		const canStandard = !!capabilities.canCreateStandardWorkspace;
		const canPrivate = !!capabilities.canCreatePrivateWorkspace;
		const defaultPrivacy = canStandard ? 'standard' : 'private';

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

				const privacyFieldset = C.createElement('fieldset', {
					class: 'bc-fieldset bc-fieldset--mode-group bc-field--full-width bc-privacy-fieldset',
				});
				privacyFieldset.appendChild(C.createElement('legend', {
					class: 'bc-fieldset__legend',
					text: t('budgetcheck', 'Who can see this workspace'),
				}));
				privacyFieldset.appendChild(C.createElement('div', {
					class: 'bc-callout bc-callout--info',
					attrs: { role: 'note', id: 'bc-new-privacy-disclosure' },
				}, [
					C.createElement('p', {
						text: t('budgetcheck', 'Private means only people you add as members can open this workspace in BudgetCheck. Administrators who are not members cannot see it in the app.'),
					}),
					C.createElement('p', {
						class: 'bc-callout__hint',
						text: t('budgetcheck', 'People with direct database or server access may still read stored data. This is not end-to-end encryption.'),
					}),
				]));
				if (canStandard) {
					privacyFieldset.appendChild(privacyRadio('standard', t('budgetcheck', 'Standard'), t('budgetcheck', 'App administrators can see and manage this workspace for recovery.'), defaultPrivacy === 'standard'));
				}
				if (canPrivate) {
					privacyFieldset.appendChild(privacyRadio('private', t('budgetcheck', 'Private'), t('budgetcheck', 'Only members you add. You can start alone as manager; if your account is removed with no other manager, this private workspace cannot be opened in BudgetCheck.'), defaultPrivacy === 'private'));
				}
				form.appendChild(privacyFieldset);

				function privacyRadio(value, title, hint, selected) {
					return C.createElement('label', { class: 'bc-field bc-field--radio' }, [
						C.createElement('input', {
							type: 'radio',
							name: 'privacyMode',
							value,
							attrs: selected ? { checked: 'checked' } : {},
						}),
						C.createElement('span', {}, [
							C.createElement('strong', { text: title }),
							C.createElement('span', { class: 'bc-field__hint bc-field__hint--block', text: hint }),
						]),
					]);
				}

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

				const defCur = String(capabilities.defaultCurrency || 'EUR').toUpperCase();
				const defTz = String(capabilities.defaultTimezone || 'Europe/Berlin');
				const curShell = CatalogPickers.createPickerShell('currency', {
					idPrefix: 'bc-new-ws-currency',
					name: 'currencyCode',
					label: t('budgetcheck', 'Currency'),
					defaultValue: defCur,
				});
				const tzShell = CatalogPickers.createPickerShell('timezone', {
					idPrefix: 'bc-new-ws-timezone',
					name: 'timezone',
					label: t('budgetcheck', 'Timezone'),
					defaultValue: defTz,
				});
				form.appendChild(curShell.field);
				form.appendChild(tzShell.field);
				const modalCurrencyPicker = CatalogPickers.attachCurrency(
					curShell.root,
					capabilities.currencyCatalog || { pinned: [], groups: [] },
					{ defaultCurrency: defCur },
				);
				const modalTimezonePicker = CatalogPickers.attachTimezone(
					tzShell.root,
					capabilities.timezoneCatalog || { pinned: [], groups: [] },
					{ defaultTimezone: defTz },
				);

				const defaultEnd = new Date();
				defaultEnd.setMonth(defaultEnd.getMonth() + 1);
				const startInput = localeDateField(form, t('budgetcheck', 'Project start (project only)'), 'projectStartDate', Dates.isoDate(new Date()));
				const endInput = localeDateField(form, t('budgetcheck', 'Project end (project only)'), 'projectEndDate', Dates.isoDate(defaultEnd));
				const capInput = field(form, t('budgetcheck', 'Project cap (optional)'), 'projectTotalCapMinor', 'text', { inputmode: 'decimal' });
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
					const privacyInput = form.querySelector('input[name="privacyMode"]:checked');
					const payload = {
						name: nameInput.value.trim(),
						type: typeSelect.value,
						currencyCode: (modalCurrencyPicker ? modalCurrencyPicker.getValue() : '').toUpperCase().trim(),
						timezone: modalTimezonePicker ? modalTimezonePicker.getValue() : '',
						privacyMode: privacyInput ? privacyInput.value : defaultPrivacy,
					};
					if (isProject) {
						payload.projectStartDate = startInput.value;
						payload.projectEndDate = endInput.value;
						payload.projectTotalCapInput = capInput.value.trim();
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
				if (!payload.currencyCode) {
					Msg.announce(t('budgetcheck', 'Please choose a currency.'), 'error');
					return false;
				}
				if (!payload.timezone) {
					Msg.announce(t('budgetcheck', 'Please choose a timezone.'), 'error');
					return false;
				}
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
					if (payload.projectTotalCapInput) {
						try {
							payload.projectTotalCapMinor = Money.parseHuman(
								payload.projectTotalCapInput,
								(payload.currencyCode || 'EUR') === 'JPY' ? 0 : 2
							);
						} catch (err) {
							Msg.announce(err.message || t('budgetcheck', 'Amount is not a valid number.'), 'error');
							return false;
						}
					}
					delete payload.projectTotalCapInput;
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
						const dash = (ctx.urls && ctx.urls.dashboard)
							? String(ctx.urls.dashboard)
							: '/apps/budgetcheck/dashboard';
						const sep = dash.indexOf('?') === -1 ? '?' : '&';
						window.location.href = dash + sep + 'workspaceId=' + encodeURIComponent(String(newId));
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
		const C = window.BudgetCheckComponents;
		const o = opts || {};
		const input = C.createElement('input', Object.assign({ name, type, class: 'bc-input' }, o));
		wrap(form, label, input);
		return input;
	}

	function localeDateField(form, label, name, initialIsoYmd) {
		const C = window.BudgetCheckComponents;
		const Dates = window.BudgetCheckDates;
		const dh = 'bc-ws-new-' + name + '-' + Math.random().toString(36).slice(2);
		const hintText = t('budgetcheck', 'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.');
		const input = C.createElement('input', {
			name,
			type: 'date',
			class: 'bc-input',
			autocomplete: 'off',
			value: initialIsoYmd ? String(initialIsoYmd) : '',
			attrs: { 'aria-describedby': dh, lang: Dates && Dates.currentLocaleTag ? Dates.currentLocaleTag() : (document.documentElement.lang || 'en') },
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
		const C = window.BudgetCheckComponents;
		const wrapper = C.createElement('label', { class: 'bc-field' }, [
			C.createElement('span', { class: 'bc-field__label', text: label }),
			input,
		]);
		form.appendChild(wrapper);
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — Workspace cannot register');
	}
	window.BudgetCheck.define('Workspace', ctx);
})();
