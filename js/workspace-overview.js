(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Ws = window.BudgetCheckWorkspace;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;

	const state = {
		workspaces: [],
		favorites: new Set(),
		filters: {
			search: '',
			type: 'all',
			role: 'all',
			showArchived: false,
			onlyFavorites: false,
		},
	};

	document.addEventListener('DOMContentLoaded', () => {
		wireFilters();
		loadAndRender();
	});

	function wireFilters() {
		const root = document.querySelector('[data-bc-workspace-filters]');
		if (!root) return;
		const syncAndReload = () => {
			state.filters.search = String(root.querySelector('[data-bc-filter-search]')?.value || '').trim().toLowerCase();
			state.filters.type = String(root.querySelector('[data-bc-filter-type]')?.value || 'all');
			state.filters.role = String(root.querySelector('[data-bc-filter-role]')?.value || 'all');
			state.filters.showArchived = !!root.querySelector('[data-bc-filter-show-archived]')?.checked;
			state.filters.onlyFavorites = !!root.querySelector('[data-bc-filter-only-favorites]')?.checked;
			loadAndRender();
		};
		root.addEventListener('input', syncAndReload);
		root.addEventListener('change', syncAndReload);
		const resetBtn = root.querySelector('[data-bc-action="workspace-filters-reset"]');
		if (resetBtn) {
			resetBtn.addEventListener('click', () => {
				const search = root.querySelector('[data-bc-filter-search]');
				const type = root.querySelector('[data-bc-filter-type]');
				const role = root.querySelector('[data-bc-filter-role]');
				const archived = root.querySelector('[data-bc-filter-show-archived]');
				const favorites = root.querySelector('[data-bc-filter-only-favorites]');
				if (search) search.value = '';
				if (type) type.value = 'all';
				if (role) role.value = 'all';
				if (archived) archived.checked = false;
				if (favorites) favorites.checked = false;
				syncAndReload();
			});
		}
	}

	async function loadAndRender() {
		const grid = document.querySelector('[data-bc-workspace-grid]');
		if (!grid) return;
		grid.setAttribute('aria-busy', 'true');
		try {
			const data = await Api.get('/apps/budgetcheck/api/workspaces', {
				includeInactive: state.filters.showArchived ? '1' : '0',
			});
			state.workspaces = Array.isArray(data.workspaces) ? data.workspaces : [];
			state.favorites = new Set(Array.isArray(data.favoriteWorkspaceIds) ? data.favoriteWorkspaceIds.map((v) => Number(v)) : []);
			render();
		} catch (err) {
			Msg.handleApiError(err);
			grid.replaceChildren(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'Could not load workspaces.') }));
		} finally {
			grid.setAttribute('aria-busy', 'false');
		}
	}

	function render() {
		const grid = document.querySelector('[data-bc-workspace-grid]');
		const stats = document.querySelector('[data-bc-workspace-stats]');
		if (!grid || !stats) return;
		const rows = state.workspaces.filter((w) => matchesFilters(w));
		stats.textContent = n('budgetcheck', '%n workspace visible', '%n workspaces visible', rows.length);
		grid.replaceChildren();
		if (rows.length === 0) {
			grid.appendChild(C.createElement('article', { class: 'bc-workspace-card bc-workspace-card--empty' }, [
				C.createElement('h3', { text: t('budgetcheck', 'No matching workspaces') }),
				C.createElement('p', { text: t('budgetcheck', 'Try broadening your filters or include archived workspaces.') }),
			]));
			return;
		}
		rows.forEach((w) => grid.appendChild(renderWorkspaceCard(w)));
	}

	function matchesFilters(workspace) {
		const q = state.filters.search;
		if (q !== '' && !String(workspace.name || '').toLowerCase().includes(q)) {
			return false;
		}
		if (state.filters.type !== 'all' && workspace.type !== state.filters.type) {
			return false;
		}
		if (state.filters.role !== 'all' && workspace.role !== state.filters.role) {
			return false;
		}
		if (!state.filters.showArchived && workspace.isActive === false) {
			return false;
		}
		if (state.filters.onlyFavorites && !state.favorites.has(Number(workspace.id))) {
			return false;
		}
		return true;
	}

	function renderWorkspaceCard(workspace) {
		const id = Number(workspace.id);
		const isFavorite = state.favorites.has(id);
		const isArchived = workspace.isActive === false;
		const roleLabel = workspace.role === 'manager'
			? t('budgetcheck', 'Manager')
			: (workspace.role === 'contributor'
				? t('budgetcheck', 'Contributor')
				: (workspace.role === 'viewer' ? t('budgetcheck', 'Viewer') : String(workspace.role || '')));
		const card = C.createElement('article', { class: 'bc-workspace-card' + (isArchived ? ' is-archived' : '') });
		const openUrl = String((Ws.urls && Ws.urls.dashboard) || '/apps/budgetcheck/dashboard') + '?workspaceId=' + encodeURIComponent(String(id));
		const favoriteButton = C.createElement('button', {
			type: 'button',
			class: 'bc-workspace-card__favorite' + (isFavorite ? ' is-active' : ''),
			attrs: {
				'aria-pressed': isFavorite ? 'true' : 'false',
				'aria-label': isFavorite ? t('budgetcheck', 'Remove from quick access') : t('budgetcheck', 'Add to quick access'),
				title: isFavorite ? t('budgetcheck', 'Remove from quick access') : t('budgetcheck', 'Add to quick access'),
			},
			text: isFavorite ? '★' : '☆',
		});
		favoriteButton.disabled = isArchived;
		if (!isArchived) {
			favoriteButton.addEventListener('click', () => toggleFavorite(id, favoriteButton));
		}

		card.appendChild(C.createElement('div', { class: 'bc-workspace-card__top' }, [
			C.createElement('div', { class: 'bc-workspace-card__name', text: String(workspace.name || '') }),
			favoriteButton,
		]));
		card.appendChild(C.createElement('div', { class: 'bc-workspace-card__meta' }, [
			C.createElement('span', { class: 'bc-badge bc-badge--' + String(workspace.type || 'household'), text: workspace.type === 'project' ? t('budgetcheck', 'Project') : t('budgetcheck', 'Household') }),
			C.createElement('span', { class: 'bc-pill', text: String(workspace.currencyCode || '') }),
			C.createElement('span', { class: 'bc-pill', text: roleLabel }),
			isArchived ? C.createElement('span', { class: 'bc-pill', text: t('budgetcheck', 'Archived') }) : null,
		]));
		card.appendChild(C.createElement('div', { class: 'bc-workspace-card__actions' }, [
			C.createElement('a', {
				class: 'button' + (isArchived ? '' : ' primary'),
				href: isArchived ? '#' : openUrl,
				attrs: isArchived ? { 'aria-disabled': 'true', tabindex: '-1' } : {},
				text: isArchived ? t('budgetcheck', 'Archived workspace') : t('budgetcheck', 'Open workspace'),
			}),
		]));
		return card;
	}

	async function toggleFavorite(workspaceId, button) {
		const next = new Set(state.favorites);
		if (next.has(workspaceId)) {
			next.delete(workspaceId);
		} else {
			next.add(workspaceId);
		}
		const previous = state.favorites;
		state.favorites = next;
		render();
		try {
			await Api.put('/apps/budgetcheck/api/workspace-favorites', {
				workspaceIds: Array.from(next.values()),
			});
			syncSidebarQuickAccess(next);
			Msg.announce(t('budgetcheck', 'Quick access updated.'), 'success');
		} catch (err) {
			state.favorites = previous;
			render();
			Msg.handleApiError(err);
			if (button) {
				button.focus();
			}
		}
	}

	function syncSidebarQuickAccess(favoritesSet) {
		const slot = document.querySelector('#app-navigation [data-bc-quickaccess-slot]');
		const switcher = document.querySelector('#app-navigation .bc-switcher');
		if (!slot || !switcher) return;

		const iconTemplates = {};
		['household', 'project'].forEach((type) => {
			const existing = switcher.querySelector(`[data-bc-workspace-type="${type}"] .bc-switcher__icon`);
			if (existing) {
				iconTemplates[type] = existing.cloneNode(true);
			}
		});

		slot.replaceChildren();
		const favorites = state.workspaces.filter((w) => favoritesSet.has(Number(w.id)) && w.isActive !== false);
		if (favorites.length === 0) {
			slot.appendChild(C.createElement('p', { class: 'bc-switcher__empty', text: t('budgetcheck', 'No quick access workspaces yet.') }));
			return;
		}

		const activeId = Ws.workspace && Ws.workspace.id ? Number(Ws.workspace.id) : null;
		const group = C.createElement('div', { class: 'bc-switcher__group' });
		const titleId = 'bc-switcher-favorites';
		group.appendChild(C.createElement('p', { class: 'bc-switcher__group-title', id: titleId, text: t('budgetcheck', 'Quick access') }));
		const list = C.createElement('ul', { class: 'bc-switcher__list', attrs: { 'aria-labelledby': titleId } });

		favorites.forEach((w) => {
			const id = Number(w.id);
			const isActive = activeId !== null && id === activeId;
			const type = String(w.type || 'household');
			const href = String((Ws.urls && Ws.urls.dashboard) || '/apps/budgetcheck/dashboard') + '?workspaceId=' + encodeURIComponent(String(id));
			const icon = iconTemplates[type]
				? iconTemplates[type].cloneNode(true)
				: C.createElement('span', { class: 'bc-switcher__icon', attrs: { 'aria-hidden': 'true' } }, [type === 'project' ? '▣' : '⌂']);

			const link = C.createElement('a', {
				class: 'bc-switcher__link' + (isActive ? ' is-active' : ''),
				href,
				attrs: {
					'data-bc-workspace-id': String(id),
					'data-bc-workspace-type': type,
					'aria-current': isActive ? 'true' : null,
				},
			}, [
				icon,
				C.createElement('span', { class: 'bc-switcher__label' }, [
					C.createElement('span', { class: 'bc-switcher__name', text: String(w.name || '') }),
					C.createElement('span', { class: 'bc-switcher__meta', text: String(w.currencyCode || '') + (w.role ? (' · ' + String(w.role)) : '') }),
				]),
			]);

			list.appendChild(C.createElement('li', null, [link]));
		});

		group.appendChild(list);
		slot.appendChild(group);
	}
})();
