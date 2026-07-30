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
					Object.entries(value).forEach(([ak, av]) => {
						if (av === null || av === undefined || av === false) {
							el.removeAttribute(ak);
							return;
						}
						if (av === true) {
							el.setAttribute(ak, '');
							return;
						}
						el.setAttribute(ak, String(av));
					});
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
			showCancel: true,
			danger: false,
			dialogClass: '',
			onSubmit: null,
			onCancel: null,
		}, options || {});

		if (openInstance) {
			openInstance.close(false);
		}
		const previousFocus = document.activeElement;

		const labelId = 'bc-modal-title-' + Math.random().toString(36).slice(2);
		const dialog = createElement('div', {
			class: ('bc-modal__dialog ' + String(opts.dialogClass || '')).trim(),
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
		// Dedicated scrollport: keeps overflow-x:hidden reliable when overflow-y
		// is auto (some engines otherwise promote hidden→auto and show a
		// horizontal scrollbar for slightly-wide children like the receipt dropzone).
		const bodyScroll = createElement('div', { class: 'bc-modal__body-scroll' });
		const userBody = opts.render({ close: (result) => instance.close(result) });
		if (userBody) bodyScroll.appendChild(userBody);
		bodyContainer.appendChild(bodyScroll);
		// Layout sanity (dev): horizontal overflow in the booking dialog is a
		// regression — log once so it cannot slip past QA unnoticed.
		requestAnimationFrame(() => {
			try {
				if (bodyScroll.scrollWidth > bodyScroll.clientWidth + 1) {
					console.warn(
						'[BudgetCheck] modal body horizontal overflow',
						{ scrollWidth: bodyScroll.scrollWidth, clientWidth: bodyScroll.clientWidth }
					);
				}
			} catch (_) { /* ignore */ }
		});

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

		const actionChildren = [];
		if (opts.showCancel) {
			const cancelBtn = createElement('button', {
				type: 'button',
				class: 'button',
				text: opts.cancelLabel,
				on: { click: () => instance.close(false) },
			});
			actionChildren.push(cancelBtn);
		}
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
		actionChildren.push(primaryBtn);
		const actions = createElement('div', { class: 'bc-modal__actions bc-form-actions' }, actionChildren);
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

		const bodyFocusables = focusables(bodyContainer);
		const firstField = bodyFocusables[0] || primaryBtn;
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

	/**
	 * Children for a summary tile's value: locale-formatted amount without the
	 * currency symbol (the workspace currency already sits in the page header),
	 * plus a visually hidden currency code so screen readers stay unambiguous.
	 *
	 * @returns {Array<Node|string>}
	 */
	function currencyDecimals(currency, decimalsHint) {
		const d = Number(decimalsHint);
		if (Number.isFinite(d) && d >= 0 && d <= 8) return d;
		return String(currency || '').toUpperCase() === 'JPY' ? 0 : 2;
	}

	function moneyTileValue(env, htmlLang) {
		const Money = window.BudgetCheckMoney;
		if (!env || !Money) {
			return ['—'];
		}
		return [
			document.createTextNode(Money.formatEnvelopeValue(env, htmlLang)),
			createElement('span', { class: 'bc-sr-only', text: '\u00A0' + String(env.currency || '').toUpperCase() }),
		];
	}

	function renderHouseholdSummaryTiles(grid, summary, htmlLang, opts) {
		const Money = window.BudgetCheckMoney;
		const SpecialsView = window.BudgetCheckSpecialsView;
		if (!grid || !Money) {
			return;
		}
		const options = opts || {};
		const includeSpecials = !!options.includeSpecials;
		const rawTotals = summary.totals || {};
		const totals = SpecialsView
			? SpecialsView.resolveCashFlowTotals(rawTotals, includeSpecials)
			: rawTotals;
		const budget = summary.budget || null;

		grid.replaceChildren();
		grid.className = 'bc-summary-sections';

		const makeTile = (label, env, tileOpts) => {
			const o = tileOpts || {};
			return createElement('div', { class: 'bc-summary-tile' + (o.primary ? ' bc-summary-tile--primary' : '') }, [
				createElement('div', { class: 'bc-summary-tile__label', text: label }),
				createElement('div', { class: 'bc-summary-tile__value' }, moneyTileValue(env, htmlLang)),
				o.hint ? createElement('div', { class: 'bc-summary-tile__hint', text: o.hint }) : null,
			]);
		};

		const makeSection = (title, hint, tiles, footerNodes, extraClass, sectionKey) => {
			const titleId = sectionKey ? ('bc-summary-' + sectionKey + '-title') : null;
			const section = createElement('section', {
				class: ('bc-summary-section ' + String(extraClass || '')).trim(),
				attrs: titleId ? { 'aria-labelledby': titleId } : {},
			});
			const head = createElement('header', { class: 'bc-summary-section__header' });
			head.appendChild(createElement('h3', {
				class: 'bc-summary-section__title',
				attrs: titleId ? { id: titleId } : {},
				text: title,
			}));
			if (hint) {
				head.appendChild(createElement('p', { class: 'bc-summary-section__hint', text: hint }));
			}
			section.appendChild(head);
			const tileGrid = createElement('div', { class: 'bc-summary-grid' });
			tiles.forEach((tile) => tileGrid.appendChild(tile));
			section.appendChild(tileGrid);
			if (footerNodes && footerNodes.length) {
				const foot = createElement('div', { class: 'bc-summary-section__footer' });
				footerNodes.forEach((node) => foot.appendChild(node));
				section.appendChild(foot);
			}
			grid.appendChild(section);
		};

		const makeInfoCallout = (bodyText, linkHref, linkLabel) => {
			const callout = createElement('div', {
				class: 'bc-callout bc-callout--info',
				attrs: { role: 'note' },
			});
			callout.appendChild(createElement('p', { class: 'bc-summary-callout__text', text: bodyText }));
			if (linkHref && linkLabel) {
				const actions = createElement('div', { class: 'bc-summary-callout__actions' });
				actions.appendChild(createElement('a', {
					class: 'button',
					attrs: { href: linkHref },
					text: linkLabel,
				}));
				callout.appendChild(actions);
			}
			return callout;
		};

		const makeSavingsSetupCallout = () => {
			const Ws = window.BudgetCheckWorkspace;
			const callout = createElement('div', {
				class: 'bc-callout bc-callout--info',
				attrs: { role: 'note' },
			});
			callout.appendChild(createElement('p', {
				class: 'bc-summary-callout__title',
				text: t('budgetcheck', 'Savings transfers not tracked yet'),
			}));
			callout.appendChild(createElement('p', {
				class: 'bc-callout__hint',
				text: t('budgetcheck', 'Mark one expense category as Savings transfer to track money you set aside toward your target.'),
			}));
			const settingsUrl = Ws && Ws.urls ? Ws.urls.settings : null;
			const settingsHref = settingsUrl
				? Ws.withWorkspace(settingsUrl) + '#bc-categories-title'
				: null;
			if (settingsHref) {
				const actions = createElement('div', { class: 'bc-summary-callout__actions' });
				actions.appendChild(createElement('a', {
					class: 'button',
					attrs: { href: settingsHref },
					text: t('budgetcheck', 'Open categories'),
				}));
				callout.appendChild(actions);
			}
			return callout;
		};

		const cashFlowHint = includeSpecials
			? t('budgetcheck', 'Full ledger totals, including one-off special transactions.')
			: (rawTotals.hasSpecialTransactions
				? t('budgetcheck', 'Everyday income and expenses—one-off special transactions are excluded.')
				: t('budgetcheck', 'Actual bookings only. Planned placeholders are shown separately below.'));
		const cashFlowTiles = [
			makeTile(t('budgetcheck', 'Income'), totals.income),
			makeTile(t('budgetcheck', 'Expenses'), totals.expense, {
				hint: totals.tracksSavingsTransfers
					? t('budgetcheck', 'Includes savings transfers')
					: null,
			}),
			makeTile(t('budgetcheck', 'Net result'), totals.netResult, { primary: true }),
		];
		let cashFlowFooter = null;
		if (!includeSpecials && rawTotals.hasSpecialTransactions) {
			const parts = [];
			const si = rawTotals.specialIncome?.minor || 0;
			const se = rawTotals.specialExpense?.minor || 0;
			if (si > 0) {
				parts.push(t('budgetcheck', 'Excluded special income: {amount}')
					.replace('{amount}', Money.formatEnvelope(rawTotals.specialIncome, htmlLang)));
			}
			if (se > 0) {
				parts.push(t('budgetcheck', 'Excluded special expense: {amount}')
					.replace('{amount}', Money.formatEnvelope(rawTotals.specialExpense, htmlLang)));
			}
			if (parts.length) {
				const Ws = window.BudgetCheckWorkspace;
				const settingsUrl = Ws && Ws.urls ? Ws.urls.settings : null;
				const settingsHref = settingsUrl
					? Ws.withWorkspace(settingsUrl)
					: null;
				cashFlowFooter = [
					makeInfoCallout(
						parts.join(' · '),
						settingsHref,
						settingsHref ? t('budgetcheck', 'Change in workspace settings') : null,
					),
				];
			}
		}
		makeSection(
			t('budgetcheck', 'Cash flow'),
			cashFlowHint,
			cashFlowTiles,
			cashFlowFooter,
			'',
			'cashflow',
		);

		const planned = summary.planned || null;
		const plannedLedger = planned && planned.ledger ? planned.ledger : null;
		const plannedEntryCount = Number.parseInt(String(plannedLedger?.entryCount ?? 0), 10) || 0;
		const incomeTargetMinor = Number.parseInt(String(planned?.incomeTarget?.minor ?? 0), 10) || 0;
		const expenseTargetMinor = Number.parseInt(String(budget?.plannedTotal?.minor ?? 0), 10) || 0;
		const showPlannedSection = plannedEntryCount > 0 || incomeTargetMinor > 0 || expenseTargetMinor > 0;
		if (showPlannedSection) {
			const plannedTiles = [];
			if (incomeTargetMinor > 0) {
				plannedTiles.push(makeTile(t('budgetcheck', 'Income target'), planned.incomeTarget, {
					hint: t('budgetcheck', 'From your category budget plan'),
				}));
			}
			if (expenseTargetMinor > 0) {
				plannedTiles.push(makeTile(t('budgetcheck', 'Expense target'), budget.plannedTotal, {
					hint: t('budgetcheck', 'From your category budget plan'),
				}));
			}
			if (plannedEntryCount > 0) {
				plannedTiles.push(
					makeTile(t('budgetcheck', 'Planned income'), plannedLedger.income),
					makeTile(t('budgetcheck', 'Planned expenses'), plannedLedger.expense),
					makeTile(t('budgetcheck', 'Planned net'), plannedLedger.netResult, { primary: true }),
				);
			}
			const Ws = window.BudgetCheckWorkspace;
			const txUrl = Ws && Ws.urls ? Ws.urls.transactions : null;
			const ledgerHref = txUrl && summary.yearMonth
				? Ws.withWorkspace(txUrl) + '&yearMonth=' + encodeURIComponent(String(summary.yearMonth))
				: null;
			const plannedFooter = plannedEntryCount > 0 && ledgerHref
				? [makeInfoCallout(
					plannedEntryCount === 1
						? t('budgetcheck', '1 planned placeholder awaits a real booking. A matching import removes it automatically.')
						: t('budgetcheck', '{count} planned placeholders await real bookings. Matching imports remove them automatically.')
							.replace('{count}', String(plannedEntryCount)),
					ledgerHref,
					t('budgetcheck', 'Open ledger for this month'),
				)]
				: null;
			makeSection(
				t('budgetcheck', 'Expected (plan)'),
				t('budgetcheck', 'Budget targets and ledger placeholders for this month—not counted in actual cash flow above.'),
				plannedTiles,
				plannedFooter,
				'bc-summary-section--plan',
				'plan',
			);
		}

		const savingsTiles = [
			makeTile(t('budgetcheck', 'Savings target'), totals.savingsTarget),
		];
		let savingsFooter = null;
		if (totals.tracksSavingsTransfers) {
			savingsTiles.push(makeTile(t('budgetcheck', 'Saved this month'), totals.savingsTransferred, { primary: true }));
			const aboveMinor = Number.parseInt(String(totals.savingsAboveTarget?.minor ?? 0), 10) || 0;
			if (aboveMinor > 0) {
				savingsTiles.push(makeTile(t('budgetcheck', 'Above savings target'), totals.savingsAboveTarget));
			}
		} else {
			savingsFooter = [makeSavingsSetupCallout()];
		}
		savingsTiles.push(makeTile(t('budgetcheck', 'Available after savings'), totals.availableAfterSavings, {
			hint: t('budgetcheck', 'Planning cushion after the target—not your bank balance.'),
		}));
		makeSection(
			t('budgetcheck', 'Savings'),
			t('budgetcheck', 'Your monthly savings goal and how much you moved aside.'),
			savingsTiles,
			savingsFooter,
			'',
			'savings',
		);

		if (budget) {
			const saldoMinor = Number.parseInt(String(budget.remaining?.minor ?? 0), 10) || 0;
			const currency = totals.income?.currency || budget.remaining?.currency || 'EUR';
			const decimals = currencyDecimals(currency, totals.income?.decimals ?? budget.remaining?.decimals);
			const zeroEnv = { minor: 0, currency, decimals };
			const unspentEnv = saldoMinor > 0 ? budget.remaining : zeroEnv;
			const overspentEnv = saldoMinor < 0
				? { minor: Math.abs(saldoMinor), currency, decimals }
				: zeroEnv;
			makeSection(
				t('budgetcheck', 'Everyday spending budget'),
				t('budgetcheck', 'Planned spending on groceries, housing, and similar—excluding savings transfers.'),
				[
					makeTile(t('budgetcheck', 'Budget saldo'), budget.remaining, { primary: true }),
					makeTile(t('budgetcheck', 'Not spent (under budget)'), unspentEnv),
					makeTile(t('budgetcheck', 'Overspent (over budget)'), overspentEnv),
				],
				null,
				'',
				'budget',
			);
		}

		if (totals.tax && totals.taxBasis) {
			makeSection(t('budgetcheck', 'Tax totals'), null, [
				makeTile(t('budgetcheck', 'Tax net total'), totals.tax.net),
				makeTile(t('budgetcheck', 'Tax VAT total'), totals.tax.vat),
				makeTile(
					t('budgetcheck', 'Tax gross total'),
					totals.tax.gross,
					{ hint: t('budgetcheck', 'Budget basis: {basis}').replace('{basis}', totals.taxBasis === 'net' ? t('budgetcheck', 'Net') : t('budgetcheck', 'Gross')) },
				),
			]);
		}
	}

	/**
	 * Warning row with optional recovery link (§6.4). Shared by dashboard,
	 * monthly plan, and period overview so recovery never depends on hover-only UI.
	 *
	 * @param {{severity?:string,title?:string,code?:string,message?:string,recovery?:{screen?:string,params?:Record<string,unknown>}}} warning
	 * @param {{urls?:Record<string,string>,withWorkspace?:(url:string)=>string}|null} workspace
	 */
	function renderWarningItem(warning, workspace) {
		const sev = warning.severity || 'info';
		const severityLabel = sev === 'critical'
			? t('budgetcheck', 'Critical')
			: (sev === 'warning' ? t('budgetcheck', 'Warning') : t('budgetcheck', 'Info'));
		const titleText = (warning.title || warning.code || '').trim();
		const item = createElement('li', {
			class: 'bc-warning bc-warning--' + sev,
		});
		item.appendChild(createElement('span', {
			'aria-hidden': 'true',
			text: sev === 'critical' ? '!' : (sev === 'warning' ? '⚠' : 'i'),
		}));
		const body = createElement('div', {
			// Keep role=status off <li> — axe/WCAG list rule requires listitem children.
			attrs: { role: 'status' },
		});
		body.appendChild(createElement('div', {
			class: 'bc-warning__title',
			text: severityLabel + (titleText ? ': ' + titleText : ''),
		}));
		body.appendChild(createElement('div', {
			class: 'bc-warning__message',
			text: warning.message || '',
		}));
		item.appendChild(body);
		const recovery = warning.recovery;
		const urls = workspace && workspace.urls ? workspace.urls : null;
		const canBuildRecovery = !!(
			recovery
			&& recovery.screen
			&& urls
			&& urls[recovery.screen]
			&& workspace
			&& typeof workspace.withWorkspace === 'function'
		);
		if (canBuildRecovery) {
			// Call as a method (preserve `this`) — never extract/unbind withWorkspace.
			let href = workspace.withWorkspace(urls[recovery.screen]);
			const params = recovery.params || {};
			Object.entries(params).forEach(([key, value]) => {
				if (value === null || value === undefined || value === '') return;
				href += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
			});
			const link = createElement('a', {
				class: 'button',
				href,
				text: t('budgetcheck', 'Open'),
			});
			item.appendChild(createElement('div', { class: 'bc-warning__action' }, [link]));
		} else {
			item.appendChild(createElement('div'));
		}
		return item;
	}

	function renderWarningsList(section, list, warnings, workspace) {
		if (!section || !list) return;
		if (!warnings || !warnings.length) {
			section.hidden = true;
			list.replaceChildren();
			return;
		}
		section.hidden = false;
		list.replaceChildren();
		warnings.forEach((w) => list.appendChild(renderWarningItem(w, workspace)));
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — Components cannot register');
	}
	window.BudgetCheck.define('Components', {
		createElement,
		openModal,
		confirmDialog,
		renderMonthlyLedgerHelp,
		renderHouseholdSummaryTiles,
		moneyTileValue,
		renderWarningItem,
		renderWarningsList,
	});
})();
