(function () {
	'use strict';

	/** @type {any} */
	let Api;
	/** @type {any} */
	let Msg;
	/** @type {any} */
	let C;
	/** @type {any} */
	let EntityPicker;
	/** @type {any} */
	let CatalogPickers;


	let capabilities = null;
	let policyTimezonePicker = null;
	let policyCurrencyPicker = null;

	/**
	 * Merge-on-save helper: AccessControlService::saveAppPolicy replaces ALL
	 * fields, so section pages must overlay ONLY their scope onto the current
	 * server policy before POSTing.
	 *
	 * @param {'access'|'admins'|'defaults'|string} scope
	 * @param {object} formState fields collected from this section's form
	 * @param {object} currentPolicy policy from GET /api/admin/policy
	 * @returns {object} full save payload
	 */
	function buildAppPolicySavePayload(scope, formState, currentPolicy) {
		const policy = currentPolicy && typeof currentPolicy === 'object' ? currentPolicy : {};
		const state = formState && typeof formState === 'object' ? formState : {};
		const base = {
			appAdminUserIds: Array.isArray(policy.appAdminUserIds)
				? policy.appAdminUserIds.map(String)
				: [],
			accessRestrictionEnabled: !!policy.accessRestrictionEnabled,
			allowedUserIds: Array.isArray(policy.allowedUserIds)
				? policy.allowedUserIds.map(String)
				: [],
			allowedGroupIds: Array.isArray(policy.allowedGroupIds)
				? policy.allowedGroupIds.map(String)
				: [],
			defaultTimezone: String(policy.defaultTimezone || 'Europe/Berlin'),
			defaultCurrency: String(policy.defaultCurrency || 'EUR').trim().toUpperCase(),
		};
		if (scope === 'access') {
			base.accessRestrictionEnabled = !!state.accessRestrictionEnabled;
			base.allowedUserIds = Array.isArray(state.allowedUserIds)
				? state.allowedUserIds.map(String)
				: [];
			base.allowedGroupIds = Array.isArray(state.allowedGroupIds)
				? state.allowedGroupIds.map(String)
				: [];
		} else if (scope === 'admins') {
			base.appAdminUserIds = Array.isArray(state.appAdminUserIds)
				? state.appAdminUserIds.map(String)
				: [];
		} else if (scope === 'defaults') {
			if (state.defaultTimezone != null && String(state.defaultTimezone) !== '') {
				base.defaultTimezone = String(state.defaultTimezone);
			}
			if (state.defaultCurrency != null && String(state.defaultCurrency) !== '') {
				base.defaultCurrency = String(state.defaultCurrency).trim().toUpperCase();
			}
		}
		if (scope === 'access' || scope === 'admins' || scope === 'defaults' || scope === 'all') {
			base.settings_section = scope;
		}
		return base;
	}

	if (typeof window !== 'undefined') {
		window.BudgetCheckAppSettingsPolicyMerge = Object.freeze({
			buildAppPolicySavePayload,
		});
	}

	function pageInit() {
		const Legacy = window.BudgetCheckAppSettingsLegacyRedirect;
		if (Legacy && typeof Legacy.resolve === 'function') {
			const redirectUrl = Legacy.resolve(document, window.location);
			if (redirectUrl) {
				window.location.replace(redirectUrl);
				return;
			}
		}
		void bootstrap();
	}

	async function bootstrap() {
		if (!Api || !Msg) return;
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/workspaces');
			capabilities = data.capabilities || {};
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		await initAppPolicyUi();
		wireAppPolicy();
	}

	function initPolicyCatalogPickers(policy) {
		if (!CatalogPickers || !capabilities) {
			return;
		}
		const tzRoot = document.querySelector('[data-bc-timezone-picker]');
		const curRoot = document.querySelector('[data-bc-currency-picker]');
		if (tzRoot && capabilities.timezoneCatalog) {
			policyTimezonePicker = CatalogPickers.attachTimezone(
				tzRoot,
				capabilities.timezoneCatalog,
				{ defaultTimezone: policy.defaultTimezone || capabilities.defaultTimezone },
			);
			if (policy.defaultTimezone) {
				policyTimezonePicker?.setValue(policy.defaultTimezone);
			}
		}
		if (curRoot && capabilities.currencyCatalog) {
			policyCurrencyPicker = CatalogPickers.attachCurrency(
				curRoot,
				capabilities.currencyCatalog,
				{ defaultCurrency: policy.defaultCurrency || capabilities.defaultCurrency },
			);
			if (policy.defaultCurrency) {
				policyCurrencyPicker?.setValue(policy.defaultCurrency);
			}
		}
	}

	async function initAppPolicyUi() {
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form) return;
		if (!capabilities?.timezoneCatalog || !capabilities?.currencyCatalog) {
			try {
				const data = await Api.get('/apps/budgetcheck/api/workspaces');
				capabilities = data.capabilities || {};
			} catch (err) {
				Msg.handleApiError(err);
				return;
			}
		}
		let policy = {
			appAdminUserIds: [],
			accessRestrictionEnabled: false,
			allowedUsersPreview: [],
			allowedGroupsPreview: [],
			defaultTimezone: 'Europe/Berlin',
			defaultCurrency: 'EUR',
		};
		try {
			const res = await Api.get('/apps/budgetcheck/api/admin/policy');
			policy = { ...policy, ...(res.policy || {}) };
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		const previewAdmins = Array.isArray(policy.appAdminsPreview) ? policy.appAdminsPreview : [];
		if (previewAdmins.length) {
			form._bcAppAdmins = previewAdmins.map((r) => ({
				id: String(r.id || ''),
				displayName: String(r.displayName || r.id || ''),
			})).filter((r) => r.id !== '');
		} else {
			form._bcAppAdmins = (policy.appAdminUserIds || []).map((id) => ({ id: String(id), displayName: String(id) }));
		}
		form._bcAllowedUsers = [...(policy.allowedUsersPreview || [])];
		form._bcAllowedGroups = [...(policy.allowedGroupsPreview || [])];
		const restrictCb = form.querySelector('input[name="accessRestrictionEnabled"]');
		if (restrictCb) {
			restrictCb.checked = !!policy.accessRestrictionEnabled;
		}
		initPolicyCatalogPickers(policy);
		renderAllowedUserChips(form);
		renderAllowedGroupChips(form);
		renderAppAdminChips(form);
		wirePolicyEntityPickers(form);
	}

	/**
	 * Bind only the pickers whose host DOM exists on this section page.
	 * Access has users+groups; admins has admins; defaults has none.
	 */
	function wirePolicyEntityPickers(form) {
		if (!EntityPicker || form.dataset.bcPolicyPickersBound === '1') return;
		const usersQ = document.getElementById('bc-policy-users-q');
		const usersSuggest = document.getElementById('bc-policy-users-suggest');
		const groupsQ = document.getElementById('bc-policy-groups-q');
		const groupsSuggest = document.getElementById('bc-policy-groups-suggest');
		const adminsQ = document.getElementById('bc-policy-admins-q');
		const adminsSuggest = document.getElementById('bc-policy-admins-suggest');
		const hasUsers = !!(usersQ && usersSuggest);
		const hasGroups = !!(groupsQ && groupsSuggest);
		const hasAdmins = !!(adminsQ && adminsSuggest);
		if (!hasUsers && !hasGroups && !hasAdmins) return;
		form.dataset.bcPolicyPickersBound = '1';
		const accountStr = {
			noResults: t('budgetcheck', 'No matching accounts.'),
			searchErrorNetwork: t('budgetcheck', 'Search could not load (network).'),
			searchErrorServer: t('budgetcheck', 'Search could not load.'),
		};
		const groupStr = {
			noResults: t('budgetcheck', 'No matching groups.'),
			searchErrorNetwork: t('budgetcheck', 'Search could not load (network).'),
			searchErrorServer: t('budgetcheck', 'Search could not load.'),
		};
		if (hasUsers) {
			EntityPicker.bindCombobox({
				input: usersQ,
				suggest: usersSuggest,
				minLen: 2,
				strings: accountStr,
				isTaken: (id) => (form._bcAllowedUsers || []).some((x) => x.id === id),
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
					if ((form._bcAllowedUsers || []).some((x) => x.id === item.id)) {
						Msg.announce(t('budgetcheck', 'That user is already in the list.'), 'warning');
						return;
					}
					form._bcAllowedUsers = [...(form._bcAllowedUsers || []), { id: item.id, displayName: item.displayName || item.id }];
					renderAllowedUserChips(form);
				},
			});
		}
		if (hasGroups) {
			EntityPicker.bindCombobox({
				input: groupsQ,
				suggest: groupsSuggest,
				minLen: 2,
				strings: groupStr,
				isTaken: (id) => (form._bcAllowedGroups || []).some((x) => x.id === id),
				fetchItems: async (query) => {
					try {
						const data = await Api.get('/apps/budgetcheck/api/admin/groups', { q: query });
						return { items: data.groups || [], error: null };
					} catch (err) {
						const status = err && err.status;
						if (status === 0) return { items: [], error: 'network' };
						return { items: [], error: 'server' };
					}
				},
				onPick: (item) => {
					if ((form._bcAllowedGroups || []).some((x) => x.id === item.id)) {
						Msg.announce(t('budgetcheck', 'That group is already in the list.'), 'warning');
						return;
					}
					form._bcAllowedGroups = [...(form._bcAllowedGroups || []), { id: item.id, displayName: item.displayName || item.id }];
					renderAllowedGroupChips(form);
				},
			});
		}
		if (hasAdmins) {
			EntityPicker.bindCombobox({
				input: adminsQ,
				suggest: adminsSuggest,
				minLen: 2,
				strings: accountStr,
				isTaken: (id) => (form._bcAppAdmins || []).some((x) => x.id === id),
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
					if ((form._bcAppAdmins || []).some((x) => x.id === item.id)) {
						Msg.announce(t('budgetcheck', 'That user is already an administrator.'), 'warning');
						return;
					}
					form._bcAppAdmins = [...(form._bcAppAdmins || []), { id: item.id, displayName: item.displayName || item.id }];
					renderAppAdminChips(form);
				},
			});
		}
	}

	function renderAllowedUserChips(form) {
		const ul = form.querySelector('[data-bc-allowed-user-list]');
		if (!ul) return;
		ul.replaceChildren();
		(form._bcAllowedUsers || []).forEach((u) => {
			const li = C.createElement('li', { class: 'bc-chip', attrs: { role: 'listitem' } });
			li.appendChild(C.createElement('span', { class: 'bc-chip__text', text: u.displayName + ' (' + u.id + ')' }));
			const rm = C.createElement('button', {
				type: 'button',
				class: 'bc-chip__remove',
				text: '×',
				attrs: { 'aria-label': t('budgetcheck', 'Remove') + ' ' + u.id },
			});
			rm.addEventListener('click', () => {
				form._bcAllowedUsers = (form._bcAllowedUsers || []).filter((x) => x.id !== u.id);
				renderAllowedUserChips(form);
			});
			li.appendChild(rm);
			ul.appendChild(li);
		});
	}

	function renderAllowedGroupChips(form) {
		const ul = form.querySelector('[data-bc-allowed-group-list]');
		if (!ul) return;
		ul.replaceChildren();
		(form._bcAllowedGroups || []).forEach((g) => {
			const li = C.createElement('li', { class: 'bc-chip', attrs: { role: 'listitem' } });
			li.appendChild(C.createElement('span', { class: 'bc-chip__text', text: g.displayName + ' (' + g.id + ')' }));
			const rm = C.createElement('button', {
				type: 'button',
				class: 'bc-chip__remove',
				text: '×',
				attrs: { 'aria-label': t('budgetcheck', 'Remove') + ' ' + g.id },
			});
			rm.addEventListener('click', () => {
				form._bcAllowedGroups = (form._bcAllowedGroups || []).filter((x) => x.id !== g.id);
				renderAllowedGroupChips(form);
			});
			li.appendChild(rm);
			ul.appendChild(li);
		});
	}

	function renderAppAdminChips(form) {
		const ul = form.querySelector('[data-bc-app-admin-list]');
		if (!ul) return;
		ul.replaceChildren();
		(form._bcAppAdmins || []).forEach((a) => {
			const li = C.createElement('li', { class: 'bc-chip', attrs: { role: 'listitem' } });
			const body = C.createElement('div', { class: 'bc-chip__body' });
			const labelName = a.displayName && String(a.displayName) !== String(a.id) ? String(a.displayName) : String(a.id);
			body.appendChild(C.createElement('span', { class: 'bc-chip__name', text: labelName }));
			if (a.displayName && String(a.displayName) !== String(a.id)) {
				body.appendChild(C.createElement('span', { class: 'bc-chip__id', text: String(a.id) }));
			}
			li.appendChild(body);
			const rm = C.createElement('button', {
				type: 'button',
				class: 'bc-chip__remove',
				text: '×',
				attrs: {
					'aria-label': t('budgetcheck', 'Remove') + ' — ' + labelName + (a.id ? ' (' + a.id + ')' : ''),
				},
			});
			rm.addEventListener('click', () => {
				form._bcAppAdmins = (form._bcAppAdmins || []).filter((x) => x.id !== a.id);
				renderAppAdminChips(form);
			});
			li.appendChild(rm);
			ul.appendChild(li);
		});
	}

	function collectFormState(form) {
		const restrictCb = form.querySelector('input[name="accessRestrictionEnabled"]');
		return {
			accessRestrictionEnabled: !!(restrictCb && restrictCb.checked),
			allowedUserIds: (form._bcAllowedUsers || []).map((u) => u.id),
			allowedGroupIds: (form._bcAllowedGroups || []).map((g) => g.id),
			appAdminUserIds: (form._bcAppAdmins || []).map((a) => a.id),
			defaultTimezone: policyTimezonePicker
				? policyTimezonePicker.getValue()
				: getVal(form, 'defaultTimezone'),
			defaultCurrency: (policyCurrencyPicker
				? policyCurrencyPicker.getValue()
				: getVal(form, 'defaultCurrency')),
		};
	}

	function wireAppPolicy() {
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form) return;
		if (form.dataset.bcSubmitWired) return;
		form.dataset.bcSubmitWired = '1';
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const scope = String(form.getAttribute('data-bc-app-policy-scope') || '');
			const formState = collectFormState(form);
			if (scope === 'access') {
				if (formState.accessRestrictionEnabled
					&& formState.allowedUserIds.length === 0
					&& formState.allowedGroupIds.length === 0) {
					Msg.announce(t('budgetcheck', 'When restriction is enabled, add at least one user or one group before saving.'), 'warning');
					form.querySelector('input[name="accessRestrictionEnabled"]')?.focus();
					return;
				}
			}
			try {
				const res = await Api.get('/apps/budgetcheck/api/admin/policy');
				const currentPolicy = res.policy || {};
				const payload = buildAppPolicySavePayload(scope, formState, currentPolicy);
				await Api.post('/apps/budgetcheck/api/admin/policy', payload);
				Msg.announce(t('budgetcheck', 'App policy saved.'), 'success');
				await initAppPolicyUi();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	function getVal(form, name) {
		const el = form.querySelector('[name="' + name + '"]');
		return el ? String(el.value || '') : '';
	}

	function boot(deps) {
		Api = deps.Api;
		Msg = deps.Messaging;
		C = deps.Components;
		EntityPicker = deps.EntityPicker || null;
		CatalogPickers = deps.CatalogPickers || null;
		if (typeof state !== 'undefined' && state && Object.prototype.hasOwnProperty.call(state, 'yearMonth') && state.yearMonth == null && typeof initialYearMonth === 'function') {
			state.yearMonth = initialYearMonth();
		}
		pageInit();
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.onReady !== 'function') {
		return;
	}
	window.BudgetCheck.onReady(boot, {
		required: ['Api', 'Messaging', 'Components'],
		optional: ['EntityPicker', 'CatalogPickers'],
	});

})();
