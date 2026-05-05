(function () {
	'use strict';

	// Accessible primitives shared across pages.
	//
	// - createElement(tag, props, children): tiny DOM helper, never sets innerHTML.
	// - openModal({ title, render, primary, onSubmit, onCancel }): focus-trapped
	//   dialog with a labelled title, Escape closes, click-on-backdrop cancels,
	//   focus is restored to the trigger.
	// - confirmDialog({ title, body, danger }): boolean Promise convenience.

	function createElement(tag, props, children) {
		const el = document.createElement(tag);
		if (props) {
			Object.entries(props).forEach(([key, value]) => {
				if (value === undefined || value === null) return;
				if (key === 'class' || key === 'className') {
					el.className = String(value);
					return;
				}
				if (key === 'dataset') {
					Object.entries(value).forEach(([dk, dv]) => { el.dataset[dk] = String(dv); });
					return;
				}
				if (key === 'on') {
					Object.entries(value).forEach(([eventName, handler]) => el.addEventListener(eventName, handler));
					return;
				}
				if (key === 'attrs') {
					Object.entries(value).forEach(([ak, av]) => el.setAttribute(ak, String(av)));
					return;
				}
				if (key === 'text') {
					el.textContent = String(value);
					return;
				}
				if (key in el && typeof el[key] !== 'object') {
					try { el[key] = value; return; } catch (_) { /* fall back to attribute */ }
				}
				el.setAttribute(key, String(value));
			});
		}
		if (children !== undefined && children !== null) {
			(Array.isArray(children) ? children : [children]).forEach((child) => {
				if (child === null || child === undefined || child === false) return;
				if (typeof child === 'string' || typeof child === 'number') {
					el.appendChild(document.createTextNode(String(child)));
				} else {
					el.appendChild(child);
				}
			});
		}
		return el;
	}

	function focusables(root) {
		return Array.from(root.querySelectorAll(
			'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [tabindex]:not([tabindex="-1"]), [contenteditable]'
		)).filter((node) => node.offsetParent !== null);
	}

	let openInstance = null;

	function openModal(options) {
		const opts = Object.assign({
			title: '',
			render: () => createElement('div'),
			primaryLabel: t('budgetcheck', 'Save'),
			cancelLabel: t('budgetcheck', 'Cancel'),
			danger: false,
			onSubmit: null,
			onCancel: null,
		}, options || {});

		if (openInstance) {
			openInstance.close(false);
		}
		const previousFocus = document.activeElement;

		const labelId = 'bc-modal-title-' + Math.random().toString(36).slice(2);
		const dialog = createElement('div', {
			class: 'bc-modal__dialog',
			attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': labelId },
		});
		const header = createElement('div', { class: 'bc-modal__header' }, [
			createElement('h2', { id: labelId, text: opts.title }),
			createElement('button', {
				type: 'button',
				class: 'bc-modal__close',
				attrs: { 'aria-label': t('budgetcheck', 'Close') },
				text: '✕',
				on: { click: () => instance.close(false) },
			}),
		]);
		const bodyContainer = createElement('div', { class: 'bc-modal__body' });
		const userBody = opts.render({ close: (result) => instance.close(result) });
		if (userBody) bodyContainer.appendChild(userBody);

		const submitContext = () => ({
			close: (r) => instance.close(r),
			body: userBody || null,
			dialog,
		});

		const appLang = document.getElementById('app-content')?.getAttribute('lang');
		if (appLang) {
			dialog.setAttribute('lang', appLang);
		}
		if (window.BudgetCheckDates && typeof window.BudgetCheckDates.applyLocaleToTemporalInputs === 'function') {
			window.BudgetCheckDates.applyLocaleToTemporalInputs(dialog);
		}

		const cancelBtn = createElement('button', {
			type: 'button',
			class: 'button',
			text: opts.cancelLabel,
			on: { click: () => instance.close(false) },
		});
		const primaryBtn = createElement('button', {
			type: 'button',
			class: opts.danger ? 'button danger primary' : 'button primary',
			text: opts.primaryLabel,
			on: {
				click: async () => {
					if (typeof opts.onSubmit !== 'function') {
						instance.close(true);
						return;
					}
					try {
						primaryBtn.disabled = true;
						const result = await opts.onSubmit(submitContext());
						if (result !== false) instance.close(true);
					} catch (err) {
						window.BudgetCheckMessaging.handleApiError(err, { reloadOnConflict: false });
					} finally {
						primaryBtn.disabled = false;
					}
				},
			},
		});
		const actions = createElement('div', { class: 'bc-modal__actions bc-form-actions' }, [cancelBtn, primaryBtn]);
		dialog.appendChild(header);
		dialog.appendChild(bodyContainer);
		dialog.appendChild(actions);

		const overlay = createElement('div', {
			class: 'bc-modal',
			on: {
				click: (event) => { if (event.target === overlay) instance.close(false); },
			},
		}, [dialog]);

		document.body.appendChild(overlay);
		document.body.classList.add('bc-modal-open');

		const onKey = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				instance.close(false);
				return;
			}
			if (event.key !== 'Tab') return;
			const list = focusables(dialog);
			if (list.length === 0) {
				event.preventDefault();
				return;
			}
			const first = list[0];
			const last = list[list.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		};
		dialog.addEventListener('keydown', onKey);

		const instance = {
			dialog,
			overlay,
			primaryBtn,
			close(result) {
				if (!instance._open) return;
				instance._open = false;
				dialog.removeEventListener('keydown', onKey);
				if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
				document.body.classList.remove('bc-modal-open');
				openInstance = null;
				if (previousFocus && typeof previousFocus.focus === 'function') {
					try { previousFocus.focus(); } catch (_) { /* element may be gone */ }
				}
				if (typeof opts.resolve === 'function') opts.resolve(result);
				if (result === false && typeof opts.onCancel === 'function') opts.onCancel();
			},
		};
		instance._open = true;
		openInstance = instance;

		const firstField = focusables(dialog)[0] || primaryBtn;
		firstField.focus();
		return instance;
	}

	function renderMonthlyLedgerHelp(container, summary, yearMonth, htmlLang) {
		const D = window.BudgetCheckDates;
		if (!container || !D || typeof D.monthlyLedgerHelpLines !== 'function') {
			return;
		}
		const lines = D.monthlyLedgerHelpLines(summary, yearMonth, htmlLang);
		if (!lines.spanLine && !lines.monthLine) {
			container.hidden = true;
			container.replaceChildren();
			return;
		}
		container.hidden = false;
		const children = [];
		if (lines.spanLine) {
			children.push(createElement('p', { class: 'bc-ledger-help__line', text: lines.spanLine }));
		}
		if (lines.monthLine) {
			children.push(createElement('p', { class: 'bc-ledger-help__line', text: lines.monthLine }));
		}
		container.replaceChildren(...children);
	}

	function confirmDialog(options) {
		const opts = Object.assign({
			title: t('budgetcheck', 'Are you sure?'),
			body: '',
			confirmLabel: t('budgetcheck', 'Confirm'),
			cancelLabel: t('budgetcheck', 'Cancel'),
			danger: false,
		}, options || {});
		return new Promise((resolve) => {
			openModal({
				title: opts.title,
				primaryLabel: opts.confirmLabel,
				cancelLabel: opts.cancelLabel,
				danger: opts.danger,
				render: () => createElement('p', { text: opts.body }),
				onSubmit: () => true,
				onCancel: () => resolve(false),
				resolve,
			});
		});
	}

	window.BudgetCheckComponents = { createElement, openModal, confirmDialog, renderMonthlyLedgerHelp };
})();
