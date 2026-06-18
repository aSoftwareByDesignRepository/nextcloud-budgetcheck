(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;

	function Ws() {
		return window.BudgetCheckWorkspace;
	}

	const catalog = {
		workspaceId: null,
		categories: [],
		statuses: [],
		loading: null,
	};

	function uid(prefix) {
		return prefix + '-' + Math.random().toString(36).slice(2);
	}

	function activeDecimals() {
		const w = Ws()?.workspace;
		if (!w) return 2;
		return typeof w.currencyDecimals === 'number' ? w.currencyDecimals : (w.currencyCode === 'JPY' ? 0 : 2);
	}

	function wrapField(parent, labelText, control, hintText, labelExtraClass) {
		const labelClasses = ['bc-field'];
		if (labelExtraClass) {
			String(labelExtraClass).split(/\s+/).filter(Boolean).forEach((c) => labelClasses.push(c));
		}
		const parts = [
			C.createElement('span', { class: 'bc-field__label', text: labelText }),
			control,
		];
		if (hintText) {
			const hintId = uid('bc-field-hint');
			parts.push(C.createElement('span', { id: hintId, class: 'bc-field__hint', text: hintText }));
			if (control && typeof control.setAttribute === 'function') {
				const cur = control.getAttribute('aria-describedby');
				control.setAttribute('aria-describedby', cur ? (cur + ' ' + hintId) : hintId);
			}
		}
		parent.appendChild(C.createElement('label', { class: labelClasses.join(' ') }, parts));
	}

	async function ensureCatalog() {
		const ctx = Ws();
		if (!ctx || !ctx.workspace) {
			throw new Error('No workspace');
		}
		const wsId = ctx.workspace.id;
		if (catalog.workspaceId === wsId && catalog.categories.length > 0) {
			return catalog;
		}
		if (catalog.loading) {
			return catalog.loading;
		}
		catalog.loading = (async () => {
			const catData = await Api.get('/apps/budgetcheck/api/categories', {
				workspaceId: wsId,
				includeInactive: '1',
			});
			catalog.workspaceId = wsId;
			catalog.categories = catData.categories || [];
			catalog.statuses = [];
			if (ctx.workspace.type === 'project') {
				const statusData = await Api.get('/apps/budgetcheck/api/booking-statuses', { workspaceId: wsId });
				catalog.statuses = statusData.statuses || [];
			}
			catalog.loading = null;
			return catalog;
		})().catch((err) => {
			catalog.loading = null;
			throw err;
		});
		return catalog.loading;
	}

	function preload() {
		const ctx = Ws();
		if (!ctx || !ctx.workspace || !ctx.canContribute) return Promise.resolve();
		return ensureCatalog().catch((err) => {
			Msg.handleApiError(err);
		});
	}

	/**
	 * @param {{tx?: object|null, bookingDate?: string, onSaved?: function(): void}} options
	 */
	async function open(options) {
		const opts = options || {};
		const ctx = Ws();
		if (!ctx || !ctx.workspace) return;
		if (!ctx.canContribute) {
			Msg.announce(t('budgetcheck', 'You are not authorized to perform that action.'), 'error');
			return;
		}
		try {
			await ensureCatalog();
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		if (!catalog.categories.some((c) => c.isActive)) {
			Msg.announce(t('budgetcheck', 'Add income or expense categories first.'), 'error');
			return;
		}
		openModal(opts.tx || null, opts);
	}

	function categoryById(categoryId) {
		return catalog.categories.find((c) => String(c.id) === String(categoryId)) || null;
	}

	function titleMatchesCategoryDefault(title, categoryId) {
		const cat = categoryById(categoryId);
		if (!cat) return false;
		return String(title || '').trim() === String(cat.name || '').trim();
	}

	function initialCustomTitle(tx) {
		if (!tx) return '';
		if (titleMatchesCategoryDefault(tx.title, tx.categoryId)) return '';
		return tx.title || '';
	}

	function openModal(tx, opts) {
		const modalOpts = opts || {};
		const ctx = Ws();
		if (!ctx || !ctx.workspace) return;
		const isEdit = !!tx;
		const dateHintText = t('budgetcheck', 'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.');
		const currencyCode = ctx.workspace.currencyCode || '';
		const amountLabel = currencyCode
			? (t('budgetcheck', 'Amount') + ' (' + currencyCode + ')')
			: t('budgetcheck', 'Amount');

		C.openModal({
			title: isEdit ? t('budgetcheck', 'Edit transaction') : t('budgetcheck', 'New transaction'),
			primaryLabel: isEdit ? t('budgetcheck', 'Save changes') : t('budgetcheck', 'Add transaction'),
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form bc-modal__form--transaction' });

				const directionSelect = C.createElement('select', { name: 'direction', class: 'bc-input' }, [
					C.createElement('option', { value: 'expense', text: t('budgetcheck', 'Expense') }),
					C.createElement('option', { value: 'income', text: t('budgetcheck', 'Income') }),
				]);
				directionSelect.value = tx ? tx.direction : 'expense';
				wrapField(
					form,
					t('budgetcheck', 'Direction'),
					directionSelect,
					t('budgetcheck', 'Expense means money leaving the workspace; income means money arriving. The category list updates when you change this.'),
					'bc-field--full-width',
				);

				const catSelect = C.createElement('select', { name: 'categoryId', class: 'bc-input', required: true });
				const categoryEmptyHint = C.createElement('p', {
					class: 'bc-field__hint bc-field__hint--block',
					attrs: { role: 'status', hidden: true },
				});

				const amountInput = C.createElement('input', {
					name: 'amount',
					type: 'text',
					inputmode: 'decimal',
					class: 'bc-input',
					required: true,
					autocomplete: 'off',
				});
				amountInput.value = tx ? String(tx.amount.minor / Math.pow(10, activeDecimals())).replace('.', ',') : '';

				const dateInput = C.createElement('input', {
					name: 'bookingDate',
					type: 'date',
					class: 'bc-input',
					autocomplete: 'off',
					required: true,
					value: tx ? String(tx.bookingDate) : (modalOpts.bookingDate || Dates.isoDate(new Date())),
					attrs: { lang: ctx.htmlLang },
				});

				const titleInput = C.createElement('input', {
					name: 'title',
					type: 'text',
					class: 'bc-input',
					maxlength: 180,
					attrs: { autocomplete: 'off' },
				});
				titleInput.value = initialCustomTitle(tx);

				const syncTitlePlaceholder = () => {
					const cat = categoryById(catSelect.value);
					titleInput.placeholder = cat
						? t('budgetcheck', 'Uses {category} when empty').replace('{category}', cat.name)
						: t('budgetcheck', 'Uses the category name when empty');
				};

				const filterCategories = () => {
					const dir = directionSelect.value;
					const previousId = catSelect.value || (tx && tx.categoryId ? String(tx.categoryId) : '');
					catSelect.replaceChildren();
					const matches = catalog.categories.filter((c) => c.type === dir && c.isActive);
					if (tx && tx.categoryId) {
						const booked = categoryById(tx.categoryId);
						if (booked && booked.type === dir && !matches.some((c) => String(c.id) === String(booked.id))) {
							matches.unshift(booked);
						}
					}
					matches.forEach((c) => {
						const label = c.isActive
							? c.name
							: c.name + ' (' + t('budgetcheck', 'Inactive') + ')';
						catSelect.appendChild(C.createElement('option', { value: String(c.id), text: label }));
					});
					if (previousId) {
						const keep = Array.from(catSelect.options).find((o) => o.value === previousId);
						if (keep) catSelect.value = previousId;
					}
					const hasCategories = matches.length > 0;
					categoryEmptyHint.hidden = hasCategories;
					categoryEmptyHint.textContent = hasCategories
						? ''
						: t('budgetcheck', 'No categories for this direction. Add them in workspace settings.');
					catSelect.disabled = !hasCategories;
					if (hasCategories) {
						catSelect.setAttribute('required', '');
					} else {
						catSelect.removeAttribute('required');
					}
					syncTitlePlaceholder();
				};
				filterCategories();
				directionSelect.addEventListener('change', filterCategories);
				catSelect.addEventListener('change', syncTitlePlaceholder);

				wrapField(
					form,
					t('budgetcheck', 'Category'),
					catSelect,
					t('budgetcheck', 'Choose the category that best describes this booking. Only categories for the direction you selected are listed.'),
					'bc-field--full-width',
				);
				form.appendChild(categoryEmptyHint);

				const titleAmountRow = C.createElement('div', { class: 'bc-tx-title-amount-row' });
				wrapField(
					titleAmountRow,
					t('budgetcheck', 'Custom title (optional)'),
					titleInput,
					t('budgetcheck', 'Add a shop name or note when the category alone is not enough. Leave blank to use the category name in lists.'),
				);
				wrapField(
					titleAmountRow,
					amountLabel,
					amountInput,
					t('budgetcheck', 'Amount in this workspace’s currency. Use your usual decimal separator (dot or comma).'),
				);
				form.appendChild(titleAmountRow);

				wrapField(form, t('budgetcheck', 'Date'), dateInput, dateHintText, 'bc-field--full-width');

				const taxModeEnabled = !!(ctx.workspace && ctx.workspace.taxModeEnabled);
				let entryBasisSelect = null;
				let vatPresetSelect = null;
				let vatCustomWrap = null;
				let vatCustomInput = null;
				let taxPreviewEl = null;
				if (taxModeEnabled) {
					form.appendChild(C.createElement('hr', { class: 'bc-form-grid__divider' }));
					entryBasisSelect = C.createElement('select', { name: 'entryAmountBasis', class: 'bc-input' }, [
						C.createElement('option', { value: 'simple', text: t('budgetcheck', 'No tax split') }),
						C.createElement('option', { value: 'gross', text: t('budgetcheck', 'Gross') }),
						C.createElement('option', { value: 'net', text: t('budgetcheck', 'Net') }),
					]);
					entryBasisSelect.value = tx ? (tx.entryAmountBasis || 'simple') : (ctx.workspace.taxBudgetBasis || 'gross');
					wrapField(
						form,
						t('budgetcheck', 'Tax entry basis'),
						entryBasisSelect,
						t('budgetcheck', 'Choose whether the amount you entered is gross or net. Budget usage follows the workspace tax basis in settings.'),
						'bc-field--full-width',
					);

					vatPresetSelect = C.createElement('select', { name: 'vatPreset', class: 'bc-input' }, [
						C.createElement('option', { value: '0', text: t('budgetcheck', '0 % (none)') }),
						C.createElement('option', { value: '500', text: t('budgetcheck', '5 %') }),
						C.createElement('option', { value: '700', text: t('budgetcheck', '7 %') }),
						C.createElement('option', { value: '1000', text: t('budgetcheck', '10 %') }),
						C.createElement('option', { value: '1300', text: t('budgetcheck', '13 %') }),
						C.createElement('option', { value: '1500', text: t('budgetcheck', '15 %') }),
						C.createElement('option', { value: '1900', text: t('budgetcheck', '19 %') }),
						C.createElement('option', { value: '2000', text: t('budgetcheck', '20 %') }),
						C.createElement('option', { value: '2100', text: t('budgetcheck', '21 %') }),
						C.createElement('option', { value: '2500', text: t('budgetcheck', '25 %') }),
						C.createElement('option', { value: 'custom', text: t('budgetcheck', 'Custom…') }),
					]);
					const initialRate = tx && Number.isFinite(tx.vatRateBp) ? String(tx.vatRateBp) : String(ctx.workspace.defaultVatRateBp ?? 0);
					const presetValues = new Set(Array.from(vatPresetSelect.options).map((o) => o.value));
					vatPresetSelect.value = presetValues.has(initialRate) ? initialRate : 'custom';
					wrapField(
						form,
						t('budgetcheck', 'VAT rate'),
						vatPresetSelect,
						t('budgetcheck', 'Used when basis is gross or net.'),
						'bc-field--full-width',
					);

					vatCustomInput = C.createElement('input', { name: 'vatRateBpCustom', type: 'number', min: '0', max: '5000', step: '1', class: 'bc-input' });
					vatCustomInput.value = vatPresetSelect.value === 'custom' ? initialRate : '';
					vatCustomWrap = C.createElement('label', { class: 'bc-field bc-field--full-width', attrs: { 'data-bc-vat-custom-wrap': '1' } }, [
						C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Custom VAT (basis points)') }),
						vatCustomInput,
						C.createElement('span', { class: 'bc-field__hint', text: t('budgetcheck', 'Example: 1900 = 19%%') }),
					]);
					form.appendChild(vatCustomWrap);

					taxPreviewEl = C.createElement('p', { class: 'bc-field__hint bc-field__hint--block', attrs: { role: 'status' } });
					form.appendChild(taxPreviewEl);
				}

				let bookingStatusSelect = null;
				if (ctx.workspace && ctx.workspace.type === 'project') {
					bookingStatusSelect = C.createElement('select', { name: 'bookingStatusId', class: 'bc-input' });
					bookingStatusSelect.appendChild(C.createElement('option', { value: '', text: t('budgetcheck', 'No status') }));
					catalog.statuses.filter((status) => status.isActive).forEach((status) => {
						bookingStatusSelect.appendChild(C.createElement('option', { value: String(status.id), text: status.name }));
					});
					if (tx && tx.bookingStatusId) {
						bookingStatusSelect.value = String(tx.bookingStatusId);
					}
					wrapField(
						form,
						t('budgetcheck', 'Booking status'),
						bookingStatusSelect,
						t('budgetcheck', 'In project workspaces you can tag a booking with a status (for example in progress or paid). Leave empty if you do not need a workflow step.'),
						'bc-field--full-width',
					);
				}

				const notesArea = C.createElement('textarea', { name: 'notes', class: 'bc-input', maxlength: 4000, rows: 3 });
				notesArea.value = tx && tx.notes ? tx.notes : '';
				wrapField(
					form,
					t('budgetcheck', 'Notes'),
					notesArea,
					t('budgetcheck', 'Optional detail for people who can view this booking—references, links, or context.'),
					'bc-field--full-width',
				);

				const specialOuter = C.createElement('label', { class: 'bc-field bc-field--full-width bc-field--boolean' });
				specialOuter.appendChild(C.createElement('span', { class: 'bc-field__label', text: t('budgetcheck', 'Special') }));
				const specialRow = C.createElement('span', { class: 'bc-boolean-control' });
				const specialInput = C.createElement('input', { type: 'checkbox', name: 'isSpecial', value: '1' });
				specialInput.checked = !!(tx && tx.isSpecial);
				specialRow.appendChild(specialInput);
				specialRow.appendChild(C.createElement('span', { class: 'bc-boolean-control__text', text: t('budgetcheck', 'Mark as special (large/unusual entry)') }));
				specialOuter.appendChild(specialRow);
				const specialHintId = uid('bc-field-hint');
				specialOuter.appendChild(C.createElement('span', {
					id: specialHintId,
					class: 'bc-field__hint',
					text: t('budgetcheck', 'Use for unusually large or one-off entries. They stay in the ledger but are excluded from everyday monthly totals unless you include them in workspace settings.'),
				}));
				specialInput.setAttribute('aria-describedby', specialHintId);
				form.appendChild(specialOuter);

				function syncTaxControls() {
					if (!taxModeEnabled || !entryBasisSelect || !vatPresetSelect || !vatCustomWrap || !vatCustomInput || !taxPreviewEl) return;
					const basis = entryBasisSelect.value;
					const needsRate = basis !== 'simple';
					vatPresetSelect.disabled = !needsRate;
					vatCustomWrap.hidden = !needsRate || vatPresetSelect.value !== 'custom';
					vatCustomInput.disabled = vatCustomWrap.hidden;
					if (!needsRate) {
						taxPreviewEl.textContent = t('budgetcheck', 'No tax split: amount is stored as a plain amount.');
						return;
					}
					let amountMinor = null;
					try {
						amountMinor = Money.parseHuman(amountInput.value || '', activeDecimals());
					} catch (_) {
						taxPreviewEl.textContent = t('budgetcheck', 'Enter an amount to preview net/VAT/gross.');
						return;
					}
					let bp = null;
					if (vatPresetSelect.value === 'custom') {
						const raw = String(vatCustomInput.value || '').trim();
						if (!/^\d+$/.test(raw)) {
							taxPreviewEl.textContent = t('budgetcheck', 'Enter a valid VAT rate in basis points.');
							return;
						}
						bp = Number.parseInt(raw, 10);
					} else {
						bp = Number.parseInt(vatPresetSelect.value, 10);
					}
					if (!Number.isInteger(bp) || bp < 0 || bp > 5000) {
						taxPreviewEl.textContent = t('budgetcheck', 'VAT rate must be between 0 and 5000 basis points.');
						return;
					}
					const converted = Money.convertTaxPreview(amountMinor, bp, basis);
					taxPreviewEl.textContent = t('budgetcheck', 'Preview: Net {net} · VAT {vat} · Gross {gross}')
						.replace('{net}', Money.formatMinor(converted.net, ctx.workspace.currencyCode, ctx.htmlLang))
						.replace('{vat}', Money.formatMinor(converted.vat, ctx.workspace.currencyCode, ctx.htmlLang))
						.replace('{gross}', Money.formatMinor(converted.gross, ctx.workspace.currencyCode, ctx.htmlLang));
				}
				if (taxModeEnabled && entryBasisSelect && vatPresetSelect && vatCustomInput) {
					entryBasisSelect.addEventListener('change', syncTaxControls);
					vatPresetSelect.addEventListener('change', syncTaxControls);
					vatCustomInput.addEventListener('input', syncTaxControls);
					amountInput.addEventListener('input', syncTaxControls);
					syncTaxControls();
				}

				form._collect = () => ({
					workspaceId: ctx.workspace.id,
					title: titleInput.value.trim(),
					direction: directionSelect.value,
					bookingDate: dateInput.value.trim(),
					amount: amountInput.value,
					categoryId: catSelect.value ? Number.parseInt(catSelect.value, 10) : 0,
					bookingStatusId: bookingStatusSelect && bookingStatusSelect.value ? Number.parseInt(bookingStatusSelect.value, 10) : null,
					isSpecial: specialInput.checked,
					notes: notesArea.value.trim(),
					entryAmountBasis: entryBasisSelect ? entryBasisSelect.value : undefined,
					vatPreset: vatPresetSelect ? vatPresetSelect.value : undefined,
					vatRateBpCustom: vatCustomInput ? vatCustomInput.value : undefined,
					version: tx ? tx.version : undefined,
				});
				form._focusFirstInvalid = () => {
					if (catSelect.disabled || !catSelect.value) {
						(catSelect.disabled ? directionSelect : catSelect).focus();
						return;
					}
					if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
						const invalid = form.querySelector(':invalid');
						if (invalid && typeof invalid.focus === 'function') {
							invalid.focus();
						}
					}
				};
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				if (form && typeof form.reportValidity === 'function' && !form.reportValidity()) {
					if (typeof form._focusFirstInvalid === 'function') {
						form._focusFirstInvalid();
					}
					return false;
				}
				const payload = form && form._collect ? form._collect() : null;
				if (!payload) return false;
				if (!payload.categoryId || payload.categoryId < 1) {
					Msg.announce(t('budgetcheck', 'Choose a category.'), 'error');
					if (typeof form._focusFirstInvalid === 'function') {
						form._focusFirstInvalid();
					}
					return false;
				}
				const selectedCategory = categoryById(payload.categoryId);
				if (selectedCategory && !selectedCategory.isActive) {
					Msg.announce(t('budgetcheck', 'Category deactivated.'), 'error');
					catSelect.focus();
					return false;
				}
				if (String(payload.amount || '').trim() === '') {
					Msg.announce(t('budgetcheck', 'Amount is required.'), 'error');
					if (form.querySelector('[name="amount"]')) {
						form.querySelector('[name="amount"]').focus();
					}
					return false;
				}
				if (!Dates.isIsoCalendarDay(String(payload.bookingDate || '').trim())) {
					Msg.announce(t('budgetcheck', 'Invalid calendar date.'), 'error');
					if (form.querySelector('[name="bookingDate"]')) {
						form.querySelector('[name="bookingDate"]').focus();
					}
					return false;
				}
				payload.bookingDate = String(payload.bookingDate).trim();
				try {
					Money.parseHuman(payload.amount, activeDecimals());
				} catch (e) {
					Msg.announce(e.message, 'error');
					if (form.querySelector('[name="amount"]')) {
						form.querySelector('[name="amount"]').focus();
					}
					return false;
				}
				if (ctx.workspace && ctx.workspace.taxModeEnabled) {
					const basis = String(payload.entryAmountBasis || 'simple');
					if (basis !== 'simple') {
						let bp = null;
						if (payload.vatPreset === 'custom') {
							const raw = String(payload.vatRateBpCustom || '').trim();
							if (!/^\d+$/.test(raw)) {
								Msg.announce(t('budgetcheck', 'Enter a valid VAT rate in basis points.'), 'error');
								return false;
							}
							bp = Number.parseInt(raw, 10);
						} else {
							bp = Number.parseInt(String(payload.vatPreset || ''), 10);
						}
						if (!Number.isInteger(bp) || bp < 0 || bp > 5000) {
							Msg.announce(t('budgetcheck', 'VAT rate must be between 0 and 5000 basis points.'), 'error');
							return false;
						}
						payload.vatRateBp = bp;
					}
					payload.entryAmountBasis = basis;
				}
				delete payload.vatPreset;
				delete payload.vatRateBpCustom;
				try {
					if (isEdit) {
						await Api.put('/apps/budgetcheck/api/transactions/' + tx.id, payload);
						Msg.announce(t('budgetcheck', 'Transaction updated.'), 'success');
					} else {
						await Api.post('/apps/budgetcheck/api/transactions', payload);
						Msg.announce(t('budgetcheck', 'Transaction created.'), 'success');
					}
					if (typeof modalOpts.onSaved === 'function') {
						modalOpts.onSaved();
					}
					close(true);
				} catch (err) {
					Msg.handleApiError(err, { reloadOnConflict: false });
					return false;
				}
			},
		});
	}

	function defaultBookingDateForRange(from, to) {
		if (!from || !to) return Dates.isoDate(new Date());
		const today = Dates.isoDate(new Date());
		if (today >= from && today <= to) return today;
		return from;
	}

	window.BudgetCheckTransactionEditor = {
		open,
		preload,
		defaultBookingDateForRange,
		invalidateCatalog() {
			catalog.workspaceId = null;
			catalog.categories = [];
			catalog.statuses = [];
			catalog.loading = null;
		},
	};
})();
