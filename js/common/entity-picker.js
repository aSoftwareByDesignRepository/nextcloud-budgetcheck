/**
 * Inline combobox + listbox for user/group search (ProjectCheck-style UX).
 * Uses BudgetCheckComponents.createElement only — no innerHTML with user data.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
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


	/**
	 * @typedef {object} PickerItem
	 * @property {string} id
	 * @property {string} [displayName]
	 */

	/**
	 * @typedef {object} BindComboboxOptions
	 * @property {HTMLInputElement} input
	 * @property {HTMLElement} suggest
	 * @property {() => boolean} isTaken
	 * @property {(q: string) => Promise<{ items: PickerItem[], error: (null|string) }>} fetchItems
	 * @property {(item: PickerItem) => void} onPick
	 * @property {{ noResults?: string, searchErrorNetwork?: string, searchErrorServer?: string }} [strings]
	 * @property {number} [minLen]
	 * @property {number} [debounceMs]
	 */

	/**
	 * @param {BindComboboxOptions} opts
	 * @returns {() => void} teardown
	 */
	function bindCombobox(opts) {
		const input = opts.input;
		const suggest = opts.suggest;
		const minLen = typeof opts.minLen === 'number' ? opts.minLen : 2;
		const debounceMs = typeof opts.debounceMs === 'number' ? opts.debounceMs : 280;
		const ps = opts.strings || {};
		let timer = null;
		let inflight = 0;
		let ar = 0;

		function setSuggestVisible(visible) {
			input.setAttribute('aria-expanded', visible ? 'true' : 'false');
			if (!visible) {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function errMsg(errK) {
			if (errK === 'network') {
				return typeof ps.searchErrorNetwork === 'string' && ps.searchErrorNetwork ? ps.searchErrorNetwork : null;
			}
			return typeof ps.searchErrorServer === 'string' && ps.searchErrorServer ? ps.searchErrorServer : null;
		}

		function setActive(optLis, idx) {
			if (!optLis || !optLis.length) {
				input.removeAttribute('aria-activedescendant');
				return;
			}
			if (typeof idx !== 'number' || idx < 0 || idx >= optLis.length) {
				idx = 0;
			}
			ar = idx;
			for (let i = 0; i < optLis.length; i++) {
				if (i === ar) {
					optLis[i].setAttribute('aria-selected', 'true');
					const oid = optLis[i].id;
					if (oid) {
						input.setAttribute('aria-activedescendant', oid);
					}
				} else {
					optLis[i].setAttribute('aria-selected', 'false');
				}
			}
		}

		function showOpts(items, err, suggestId) {
			suggest.replaceChildren();
			if (err) {
				input.setAttribute('aria-controls', suggest.id);
				const pe = errMsg(err) || ps.searchErrorServer || '';
				if (pe) {
					const perr = BC.Components.createElement('p', {
						class: 'bc-entity-picker__noresult bc-entity-picker__noresult--err',
						attrs: { role: 'alert' },
						text: pe,
					});
					suggest.appendChild(perr);
				}
				suggest.hidden = !suggest.hasChildNodes();
				setSuggestVisible(!suggest.hidden);
				if (suggest.hidden) {
					ar = 0;
				}
				return;
			}
			const taken = opts.isTaken;
			const pick = (items || []).filter((x) => x && x.id && !taken(x.id));
			if (!pick.length) {
				input.setAttribute('aria-controls', suggest.id);
				const qv = input.value.trim();
				if ((items && items.length) || qv.length >= minLen) {
					const nr = typeof ps.noResults === 'string' ? ps.noResults : '';
					if (nr) {
						suggest.appendChild(BC.Components.createElement('p', {
							class: 'bc-entity-picker__noresult',
							attrs: { role: 'status' },
							text: nr,
						}));
					}
				}
				suggest.hidden = !suggest.hasChildNodes();
				setSuggestVisible(!suggest.hidden);
				if (suggest.hidden) {
					ar = 0;
				}
				return;
			}
			const lbId = suggestId + '-lb';
			const listbox = BC.Components.createElement('ul', {
				class: 'bc-entity-picker__listbox',
				attrs: { id: lbId, role: 'listbox' },
			});
			input.setAttribute('aria-controls', lbId);
			pick.forEach((it, oi) => {
				const oid = suggestId + '-o' + oi;
				const li = BC.Components.createElement('li', {
					attrs: { role: 'option', id: oid, 'aria-selected': oi === 0 ? 'true' : 'false' },
				});
				const dn = it.displayName && String(it.displayName) !== String(it.id) ? String(it.displayName) : '';
				if (dn) {
					li.appendChild(BC.Components.createElement('div', { class: 'bc-entity-suggest__line', text: dn }));
					li.appendChild(BC.Components.createElement('div', { class: 'bc-entity-suggest__id', text: String(it.id) }));
				} else {
					li.appendChild(BC.Components.createElement('div', { class: 'bc-entity-suggest__line', text: String(it.id) }));
				}
				li.addEventListener('mousedown', (ev) => {
					if (ev.button !== 0) return;
					ev.preventDefault();
					if (!it.id) return;
					opts.onPick({ id: String(it.id), displayName: String(it.displayName || it.id) });
					suggest.replaceChildren();
					suggest.hidden = true;
					setSuggestVisible(false);
					ar = 0;
					input.value = '';
					input.focus();
				});
				listbox.appendChild(li);
			});
			suggest.appendChild(listbox);
			suggest.hidden = false;
			setSuggestVisible(true);
			const optLis = Array.from(listbox.querySelectorAll('li[role="option"]'));
			setActive(optLis, 0);
		}

		function onInput() {
			if (timer) {
				window.clearTimeout(timer);
			}
			inflight += 1;
			const v = input.value.trim();
			if (v.length < minLen) {
				suggest.replaceChildren();
				suggest.hidden = true;
				setSuggestVisible(false);
				ar = 0;
				return;
			}
			const my = inflight;
			timer = window.setTimeout(async () => {
				let result = { items: [], error: null };
				try {
					result = await opts.fetchItems(v);
				} catch (_) {
					result = { items: [], error: 'server' };
				}
				if (my !== inflight) return;
				showOpts(result.items || [], result.error || null, suggest.id);
			}, debounceMs);
		}

		function onKeydown(e) {
			const optLis = suggest.hidden ? [] : Array.from(suggest.querySelectorAll('li[role="option"]'));
			if (e.key === 'Enter') {
				e.preventDefault();
				if (optLis.length) {
					const li = optLis[ar] || optLis[0];
					li.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 }));
				}
				return;
			}
			if (e.key === 'Escape') {
				if (!suggest.hidden) {
					e.preventDefault();
					suggest.replaceChildren();
					suggest.hidden = true;
					setSuggestVisible(false);
					ar = 0;
				}
				return;
			}
			if (!optLis.length) return;
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				setActive(optLis, (ar + 1) % optLis.length);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				setActive(optLis, (ar - 1 + optLis.length) % optLis.length);
			}
		}

		function onBlur() {
			window.setTimeout(() => {
				if (suggest.contains(document.activeElement)) return;
				if (document.activeElement === input) return;
				suggest.replaceChildren();
				suggest.hidden = true;
				setSuggestVisible(false);
				ar = 0;
			}, 150);
		}

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		if (!input.getAttribute('aria-controls')) {
			input.setAttribute('aria-controls', suggest.id);
		}

		input.addEventListener('input', onInput);
		input.addEventListener('keydown', onKeydown);
		input.addEventListener('blur', onBlur);

		return () => {
			input.removeEventListener('input', onInput);
			input.removeEventListener('keydown', onKeydown);
			input.removeEventListener('blur', onBlur);
			if (timer) window.clearTimeout(timer);
			suggest.replaceChildren();
			suggest.hidden = true;
		};
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — EntityPicker cannot register');
	}
	window.BudgetCheck.define('EntityPicker', { bindCombobox });
})();
