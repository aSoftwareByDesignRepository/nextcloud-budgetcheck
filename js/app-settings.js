(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const EntityPicker = window.BudgetCheckEntityPicker;

	let capabilities = null;

	document.addEventListener('DOMContentLoaded', () => {
		void bootstrap();
	});

	async function bootstrap() {
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

	async function initAppPolicyUi() {
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form) return;
		if (!capabilities?.timezones || !capabilities?.currencies) {
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
		fillTimezoneSelect(form.querySelector('[data-bc-default-timezone-select]'), policy.defaultTimezone);
		fillCurrencySelect(form.querySelector('[data-bc-default-currency-select]'), policy.defaultCurrency);
		renderAllowedUserChips(form);
		renderAllowedGroupChips(form);
		renderAppAdminChips(form);
		wirePolicyEntityPickers(form);
	}

	function wirePolicyEntityPickers(form) {
		if (!EntityPicker || form.dataset.bcPolicyPickersBound === '1') return;
		const usersQ = document.getElementById('bc-policy-users-q');
		const usersSuggest = document.getElementById('bc-policy-users-suggest');
		const groupsQ = document.getElementById('bc-policy-groups-q');
		const groupsSuggest = document.getElementById('bc-policy-groups-suggest');
		const adminsQ = document.getElementById('bc-policy-admins-q');
		const adminsSuggest = document.getElementById('bc-policy-admins-suggest');
		if (!usersQ || !usersSuggest || !groupsQ || !groupsSuggest || !adminsQ || !adminsSuggest) return;
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

	function wireAppPolicy() {
		const form = document.querySelector('[data-bc-app-policy-form]');
		if (!form) return;
		if (form.dataset.bcSubmitWired) return;
		form.dataset.bcSubmitWired = '1';
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const restrictCb = form.querySelector('input[name="accessRestrictionEnabled"]');
			const accessRestrictionEnabled = !!(restrictCb && restrictCb.checked);
			const allowedUserIds = (form._bcAllowedUsers || []).map((u) => u.id);
			const allowedGroupIds = (form._bcAllowedGroups || []).map((g) => g.id);
			if (accessRestrictionEnabled && allowedUserIds.length === 0 && allowedGroupIds.length === 0) {
				Msg.announce(t('budgetcheck', 'When restriction is enabled, add at least one user or one group before saving.'), 'warning');
				restrictCb?.focus();
				return;
			}
			const payload = {
				appAdminUserIds: (form._bcAppAdmins || []).map((a) => a.id),
				accessRestrictionEnabled,
				allowedUserIds,
				allowedGroupIds,
				defaultTimezone: getVal(form, 'defaultTimezone'),
				defaultCurrency: getVal(form, 'defaultCurrency').trim().toUpperCase(),
			};
			try {
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
})();
