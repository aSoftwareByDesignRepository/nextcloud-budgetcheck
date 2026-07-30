(function () {
	'use strict';

	/**
	 * Live dependency bag (getters). Never snapshot window.BudgetCheck* at IIFE load.
	 * @type {Record<string, any>}
	 */
	const BC = (window.BudgetCheck && typeof window.BudgetCheck.live === 'function')
		? window.BudgetCheck.live(['Components'])
		: (function () {
			const fallback = {};
			const map = {'Components': 'BudgetCheckComponents'};
			Object.keys(map).forEach(function (shortName) {
				Object.defineProperty(fallback, shortName, {
					enumerable: true,
					configurable: false,
					get: function () {
						const v = window[map[shortName]];
						return v === undefined ? null : v;
					},
				});
			});
			return fallback;
		}());


	const TZ_MAX_VISIBLE = 80;

	function setStatus(el, message, isError) {
		if (!el) {
			return;
		}
		const text = message || '';
		el.textContent = text;
		el.hidden = text === '';
		el.classList.toggle('bc-catalog-picker__status--error', Boolean(isError));
	}

	function normalizeQuery(q) {
		return String(q || '').trim().toLowerCase();
	}

	function wireCombobox(root, config) {
		if (root._bcPickerApi) {
			return root._bcPickerApi;
		}
		const select = root.querySelector('.bc-catalog-picker__native');
		const input = root.querySelector('.bc-catalog-picker__input');
		const results = root.querySelector('.bc-catalog-picker__results');
		const status = root.querySelector('.bc-catalog-picker__status');
		const clearBtn = root.querySelector('.bc-catalog-picker__clear');
		if (!select || !input || !results) {
			return null;
		}

		const isRequired = select.hasAttribute('required');
		const readOnly = Boolean(config.readOnly) || input.disabled || select.disabled;

		let activeIndex = -1;
		let visibleItems = [];

		function closeResults() {
			results.hidden = true;
			results.replaceChildren();
			visibleItems = [];
			activeIndex = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function updateClearButton() {
			if (!clearBtn) {
				return;
			}
			clearBtn.hidden = readOnly || isRequired || select.value === '';
		}

		function applySelection(item, dispatchChange) {
			if (!item) {
				select.value = '';
				input.value = '';
			} else {
				config.ensureNativeOption(select, item);
				select.value = item.value;
				input.value = item.label;
			}
			closeResults();
			setStatus(status, '', false);
			updateClearButton();
			if (dispatchChange !== false) {
				select.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		function renderResults(query) {
			visibleItems = config.collectOptions(query);
			results.replaceChildren();
			activeIndex = -1;
			if (!visibleItems.length) {
				const empty = document.createElement('li');
				empty.className = 'bc-catalog-picker__empty';
				empty.setAttribute('role', 'presentation');
				empty.textContent = config.emptyMessage(query);
				results.appendChild(empty);
				results.hidden = false;
				input.setAttribute('aria-expanded', 'true');
				return;
			}

			let lastGroup = null;
			visibleItems.forEach((item, index) => {
				if (item.group && item.group !== lastGroup) {
					lastGroup = item.group;
					const heading = document.createElement('li');
					heading.className = 'bc-catalog-picker__group';
					heading.setAttribute('role', 'presentation');
					heading.textContent = item.group;
					results.appendChild(heading);
				}
				const li = document.createElement('li');
				li.className = 'bc-catalog-picker__option';
				li.id = `${config.optionIdPrefix}-${index}`;
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', 'false');
				li.tabIndex = -1;
				config.renderOption(li, item);
				li.addEventListener('mousedown', (event) => {
					event.preventDefault();
					applySelection(item);
				});
				results.appendChild(li);
			});

			if (config.truncatedMessage && config.isTruncated(query, visibleItems.length)) {
				setStatus(status, config.truncatedMessage(visibleItems.length), false);
			} else {
				setStatus(status, '', false);
			}

			results.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function optionElements() {
			return Array.from(results.querySelectorAll('.bc-catalog-picker__option'));
		}

		function highlightIndex(next) {
			const opts = optionElements();
			if (!opts.length) {
				return;
			}
			activeIndex = Math.max(0, Math.min(next, opts.length - 1));
			opts.forEach((el, i) => {
				const on = i === activeIndex;
				el.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) {
					input.setAttribute('aria-activedescendant', el.id);
					el.scrollIntoView({ block: 'nearest' });
				}
			});
		}

		function setValue(value) {
			const trimmed = String(value || '').trim();
			if (!trimmed) {
				applySelection(null, false);
				return;
			}
			const indexed = config.index.get(config.normalizeValue(trimmed));
			if (indexed) {
				applySelection(indexed, false);
				return;
			}
			applySelection(config.fallbackItem(trimmed), false);
		}

		function reset() {
			setValue(config.defaultValue);
		}

		input.addEventListener('focus', () => renderResults(input.value));
		input.addEventListener('input', () => renderResults(input.value));
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				closeResults();
				setStatus(status, '', false);
				return;
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				if (results.hidden) {
					renderResults(input.value);
				}
				highlightIndex(activeIndex + 1);
				return;
			}
			if (event.key === 'ArrowUp') {
				event.preventDefault();
				if (results.hidden) {
					renderResults(input.value);
				}
				highlightIndex(activeIndex <= 0 ? 0 : activeIndex - 1);
				return;
			}
			if (event.key === 'Enter' && !results.hidden && activeIndex >= 0) {
				event.preventDefault();
				const item = visibleItems[activeIndex];
				if (item) {
					applySelection(item);
				}
			}
		});
		input.addEventListener('blur', () => {
			window.setTimeout(() => {
				if (!root.contains(document.activeElement)) {
					closeResults();
					if (select.value) {
						const item = config.index.get(config.normalizeValue(select.value));
						if (item) {
							input.value = item.label;
						}
					}
				}
			}, 160);
		});
		clearBtn?.addEventListener('click', () => {
			applySelection(null);
			input.focus();
		});
		document.addEventListener('click', (event) => {
			if (!root.contains(event.target)) {
				closeResults();
			}
		});

		reset();

		const api = {
			setValue,
			getValue: () => String(select.value || '').trim(),
			reset,
			select,
			input,
		};
		root._bcPickerApi = api;
		return api;
	}

	function formatTimezoneOffset(tz, when) {
		try {
			const parts = new Intl.DateTimeFormat('en', {
				timeZone: tz,
				timeZoneName: 'shortOffset',
			}).formatToParts(when || new Date());
			const hit = parts.find((p) => p.type === 'timeZoneName');
			return hit ? hit.value : '';
		} catch (_) {
			return '';
		}
	}

	function buildTimezoneIndex(catalog) {
		const index = new Map();
		const seen = new Set();
		const pinnedLabel = t('budgetcheck', 'Common choices');
		const add = (value, group) => {
			if (!value || seen.has(value)) {
				return;
			}
			seen.add(value);
			const offset = formatTimezoneOffset(value);
			index.set(value, {
				value,
				label: offset ? `${value} (${offset})` : value,
				group,
			});
		};
		(catalog.pinned || []).forEach((tz) => add(String(tz), pinnedLabel));
		(catalog.groups || []).forEach((g) => {
			const group = String(g.label || '');
			(g.items || []).forEach((tz) => add(String(tz), group));
		});
		return { index, pinnedLabel, catalog };
	}

	function timezoneOrderedEntries(state) {
		const out = [];
		(state.catalog.pinned || []).forEach((tz) => {
			const item = state.index.get(String(tz));
			if (item) {
				out.push(item);
			}
		});
		(state.catalog.groups || []).forEach((g) => {
			(g.items || []).forEach((tz) => {
				const item = state.index.get(String(tz));
				if (item && item.group !== state.pinnedLabel) {
					out.push(item);
				}
			});
		});
		return out;
	}

	/**
	 * @param {HTMLElement} root
	 * @param {{ pinned?: string[], groups?: {label:string,items:string[]}[] }} catalog
	 * @param {{ defaultTimezone?: string }} [config]
	 */
	function attachTimezone(root, catalog, config) {
		if (!root || !catalog) {
			return null;
		}
		if (root._bcPickerApi) {
			if (config?.defaultTimezone) {
				root._bcPickerApi.setValue(config.defaultTimezone);
			}
			return root._bcPickerApi;
		}
		const state = buildTimezoneIndex(catalog);
		const defaultValue = String(
			config?.defaultTimezone
			|| root.getAttribute('data-default-timezone')
			|| 'UTC',
		).trim() || 'UTC';

		return wireCombobox(root, {
			index: state.index,
			defaultValue,
			readOnly: root.querySelector('.bc-catalog-picker__input')?.disabled === true,
			optionIdPrefix: 'bc-tz-opt',
			normalizeValue: (v) => v,
			ensureNativeOption(select, item) {
				let opt = Array.from(select.options).find((o) => o.value === item.value);
				if (!opt) {
					opt = document.createElement('option');
					opt.value = item.value;
					opt.textContent = item.label;
					select.appendChild(opt);
				}
				return opt;
			},
			collectOptions(query) {
				const q = normalizeQuery(query);
				if (!q) {
					return timezoneOrderedEntries(state).filter((item) => item.group === state.pinnedLabel);
				}
				const out = [];
				for (const item of timezoneOrderedEntries(state)) {
					const hay = `${item.value} ${item.label} ${item.group}`.toLowerCase();
					if (hay.includes(q)) {
						out.push(item);
					}
					if (out.length >= TZ_MAX_VISIBLE) {
						break;
					}
				}
				return out;
			},
			emptyMessage(query) {
				return normalizeQuery(query)
					? t('budgetcheck', 'No matching timezones. Try a city or region name.')
					: t('budgetcheck', 'Type to search all IANA timezones.');
			},
			isTruncated(query, count) {
				return normalizeQuery(query) && count >= TZ_MAX_VISIBLE;
			},
			truncatedMessage(count) {
				return t('budgetcheck', 'Showing the first {count} matches. Keep typing to narrow the list.').replace('{count}', String(count));
			},
			fallbackItem(value) {
				const offset = formatTimezoneOffset(value);
				return { value, label: offset ? `${value} (${offset})` : value, group: '' };
			},
			renderOption(li, item) {
				const primary = document.createElement('span');
				primary.className = 'bc-catalog-picker__option-primary';
				primary.textContent = item.value;
				li.appendChild(primary);
				const offset = formatTimezoneOffset(item.value);
				if (offset) {
					const secondary = document.createElement('span');
					secondary.className = 'bc-catalog-picker__option-secondary';
					secondary.textContent = offset;
					li.appendChild(secondary);
				}
			},
		});
	}

	function buildCurrencyIndex(catalog) {
		const index = new Map();
		const seen = new Set();
		const pinnedLabel = t('budgetcheck', 'Common choices');
		const add = (entry, group) => {
			const code = String(entry.code || '').toUpperCase();
			if (!code || seen.has(code)) {
				return;
			}
			seen.add(code);
			const decimals = Number(entry.decimals);
			const hint = Number.isFinite(decimals) && decimals !== 2
				? t('budgetcheck', '{decimals} decimal places').replace('{decimals}', String(decimals))
				: '';
			index.set(code, {
				value: code,
				label: code,
				group,
				decimals,
				hint,
			});
		};
		(catalog.pinned || []).forEach((code) => {
			const entry = { code: String(code), decimals: 2 };
			const fromGroup = findCurrencyEntry(catalog, code);
			if (fromGroup) {
				add(fromGroup, pinnedLabel);
			} else {
				add(entry, pinnedLabel);
			}
		});
		(catalog.groups || []).forEach((g) => {
			const group = String(g.label || '');
			(g.items || []).forEach((entry) => add(entry, group));
		});
		return { index, pinnedLabel, catalog };
	}

	function findCurrencyEntry(catalog, code) {
		const want = String(code).toUpperCase();
		for (const g of catalog.groups || []) {
			for (const entry of g.items || []) {
				if (String(entry.code).toUpperCase() === want) {
					return entry;
				}
			}
		}
		return null;
	}

	function currencyOrderedEntries(state) {
		const out = [];
		(state.catalog.pinned || []).forEach((code) => {
			const item = state.index.get(String(code).toUpperCase());
			if (item) {
				out.push(item);
			}
		});
		(state.catalog.groups || []).forEach((g) => {
			(g.items || []).forEach((entry) => {
				const item = state.index.get(String(entry.code).toUpperCase());
				if (item && item.group !== state.pinnedLabel) {
					out.push(item);
				}
			});
		});
		return out;
	}

	/**
	 * @param {HTMLElement} root
	 * @param {{ pinned?: string[], groups?: {label:string,items:{code:string,decimals:number}[]}[] }} catalog
	 * @param {{ defaultCurrency?: string }} [config]
	 */
	function attachCurrency(root, catalog, config) {
		if (!root || !catalog) {
			return null;
		}
		if (root._bcPickerApi) {
			if (config?.defaultCurrency) {
				root._bcPickerApi.setValue(config.defaultCurrency);
			}
			return root._bcPickerApi;
		}
		const state = buildCurrencyIndex(catalog);
		const defaultValue = String(
			config?.defaultCurrency
			|| root.getAttribute('data-default-currency')
			|| 'EUR',
		).trim().toUpperCase() || 'EUR';

		return wireCombobox(root, {
			index: state.index,
			defaultValue,
			readOnly: root.querySelector('.bc-catalog-picker__input')?.disabled === true,
			optionIdPrefix: 'bc-cur-opt',
			normalizeValue: (v) => String(v).toUpperCase(),
			ensureNativeOption(select, item) {
				let opt = Array.from(select.options).find((o) => o.value === item.value);
				if (!opt) {
					opt = document.createElement('option');
					opt.value = item.value;
					opt.textContent = item.value;
					select.appendChild(opt);
				}
				return opt;
			},
			collectOptions(query) {
				const q = normalizeQuery(query);
				if (!q) {
					return currencyOrderedEntries(state).filter((item) => item.group === state.pinnedLabel);
				}
				const out = [];
				for (const item of currencyOrderedEntries(state)) {
					const hay = `${item.value} ${item.hint || ''} ${item.group}`.toLowerCase();
					if (hay.includes(q)) {
						out.push(item);
					}
				}
				return out;
			},
			emptyMessage(query) {
				return normalizeQuery(query)
					? t('budgetcheck', 'No matching currencies. Try another ISO code.')
					: t('budgetcheck', 'Type to search supported currencies.');
			},
			isTruncated() {
				return false;
			},
			truncatedMessage() {
				return '';
			},
			fallbackItem(value) {
				const code = String(value).toUpperCase();
				return { value: code, label: code, group: '', hint: '' };
			},
			renderOption(li, item) {
				const primary = document.createElement('span');
				primary.className = 'bc-catalog-picker__option-primary';
				primary.textContent = item.value;
				li.appendChild(primary);
				if (item.hint) {
					const secondary = document.createElement('span');
					secondary.className = 'bc-catalog-picker__option-secondary';
					secondary.textContent = item.hint;
					li.appendChild(secondary);
				}
			},
		});
	}

	/**
	 * Build picker markup for modals (create workspace).
	 *
	 * @param {'timezone'|'currency'} kind
	 * @param {{ idPrefix: string, name: string, label: string, defaultValue?: string }} opts
	 */
	function createPickerShell(kind, opts) {
		if (!BC.Components) {
			return null;
		}
		const idPrefix = opts.idPrefix;
		const dataAttr = kind === 'timezone' ? 'data-bc-timezone-picker' : 'data-bc-currency-picker';
		const defaultAttr = kind === 'timezone'
			? { 'data-default-timezone': opts.defaultValue || 'UTC' }
			: { 'data-default-currency': opts.defaultValue || 'EUR' };
		const placeholder = kind === 'timezone'
			? t('budgetcheck', 'Search timezones (e.g. Europe/Berlin or Moscow)')
			: t('budgetcheck', 'Search currencies (e.g. EUR, USD, or RUB)');
		const clearLabel = kind === 'timezone'
			? t('budgetcheck', 'Clear timezone selection')
			: t('budgetcheck', 'Clear currency selection');

		const root = BC.Components.createElement('div', {
			class: `bc-catalog-picker bc-catalog-picker--${kind}`,
		});
		root.setAttribute(dataAttr, '');
		if (kind === 'timezone') {
			root.setAttribute('data-default-timezone', opts.defaultValue || 'UTC');
		} else {
			root.setAttribute('data-default-currency', opts.defaultValue || 'EUR');
		}

		const select = BC.Components.createElement('select', {
			id: idPrefix,
			name: opts.name,
			class: 'bc-catalog-picker__native',
			attrs: { tabindex: '-1', 'aria-hidden': 'true' },
		});
		if (kind === 'currency' || kind === 'timezone') {
			select.required = true;
		}
		const input = BC.Components.createElement('input', {
			id: idPrefix + '-input',
			type: 'search',
			class: 'bc-input bc-catalog-picker__input',
			attrs: {
				role: 'combobox',
				'aria-autocomplete': 'list',
				'aria-expanded': 'false',
				'aria-controls': idPrefix + '-results',
				autocomplete: 'off',
				spellcheck: 'false',
				inputmode: 'search',
				placeholder,
			},
		});
		const clearBtn = BC.Components.createElement('button', {
			type: 'button',
			class: 'bc-catalog-picker__clear button',
			text: '×',
			attrs: { hidden: true, 'aria-label': clearLabel },
		});
		const control = BC.Components.createElement('div', { class: 'bc-catalog-picker__control' }, [input, clearBtn]);
		const results = BC.Components.createElement('ul', {
			id: idPrefix + '-results',
			class: 'bc-catalog-picker__results',
			attrs: { role: 'listbox', hidden: true },
		});
		const status = BC.Components.createElement('p', {
			id: idPrefix + '-status',
			class: 'bc-catalog-picker__status',
			attrs: { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', hidden: true },
		});
		root.appendChild(select);
		root.appendChild(control);
		root.appendChild(results);
		root.appendChild(status);

		const field = BC.Components.createElement('label', { class: 'bc-field bc-field--catalog' }, [
			BC.Components.createElement('span', { class: 'bc-field__label', id: idPrefix + '-label', text: opts.label }),
			root,
		]);
		return { field, root };
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — CatalogPickers cannot register');
	}
	window.BudgetCheck.define('CatalogPickers', {
		attachTimezone,
		attachCurrency,
		createPickerShell,
	});
})();
