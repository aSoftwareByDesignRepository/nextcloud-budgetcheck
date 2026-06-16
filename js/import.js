/*
 * BudgetCheck import page controller.
 * Bank-friendly CSV import: no category IDs required.
 */
(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Ws = window.BudgetCheckWorkspace;

	const MAX_ROWS = 500;

	const COLUMN_ALIASES = {
		bookingDate: ['bookingdate', 'date', 'transactiondate', 'valuta', 'buchungstag', 'buchungsdatum', 'buchung'],
		title: ['title', 'description', 'text', 'memo', 'payee', 'name', 'verwendungszweck', 'buchungstext', 'purpose'],
		amount: ['amount', 'value', 'betrag', 'sum', 'umsatz'],
		direction: ['direction', 'type', 'incomeexpense', 'sollhaben'],
		category: ['category', 'kategorie', 'categoryname', 'kategorie'],
		categoryId: ['categoryid'],
		notes: ['notes', 'note', 'comment', 'notiz', 'remark'],
		isSpecial: ['isspecial', 'special'],
		externalRef: ['externalref', 'reference', 'ref', 'transactionid', 'id'],
		bookingStatus: ['bookingstatus', 'status'],
		bookingStatusId: ['bookingstatusid'],
	};

	let validatedRows = [];
	let categories = [];
	let statuses = [];
	let categoryByName = new Map();
	let statusByName = new Map();

	document.addEventListener('DOMContentLoaded', async () => {
		if (!Ws.workspace || !Ws.canContribute) return;
		try {
			await loadCatalogs();
			renderCategoryReference();
			populateDefaultCategorySelects();
			updateDefaultPickerVisibility();
			wireForm();
			wireTemplateDownload();
		} catch (err) {
			Msg.handleApiError(err);
		}
	});

	async function loadCatalogs() {
		const [cats, statusRes] = await Promise.all([
			Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id }),
			Ws.workspace.type === 'project'
				? Api.get('/apps/budgetcheck/api/booking-statuses', { workspaceId: Ws.workspace.id })
				: Promise.resolve({ statuses: [] }),
		]);
		categories = (cats.categories || []).filter((cat) => cat.isActive !== false);
		categoryByName = new Map();
		categories.forEach((cat) => {
			const key = normalizeKey(cat.name);
			if (!categoryByName.has(key)) categoryByName.set(key, []);
			categoryByName.get(key).push(cat);
		});
		statuses = (statusRes.statuses || []).filter((status) => status.isActive !== false);
		statusByName = new Map();
		statuses.forEach((status) => {
			const key = normalizeKey(status.name);
			if (!statusByName.has(key)) statusByName.set(key, []);
			statusByName.get(key).push(status);
		});
	}

	function renderCategoryReference() {
		const tbody = document.querySelector('[data-bc-import-category-rows]');
		if (!tbody) return;
		tbody.replaceChildren();
		if (categories.length === 0) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '2' }, text: t('budgetcheck', 'No categories yet. Add categories in workspace settings first.') }),
			]));
			return;
		}
		const sorted = categories.slice().sort((a, b) => {
			const typeCmp = String(a.type).localeCompare(String(b.type));
			return typeCmp !== 0 ? typeCmp : String(a.name).localeCompare(String(b.name));
		});
		sorted.forEach((cat) => {
			const tr = C.createElement('tr');
			tr.appendChild(C.createElement('td', { text: cat.name }));
			tr.appendChild(C.createElement('td', {
				text: cat.type === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense'),
			}));
			tbody.appendChild(tr);
		});
	}

	function populateDefaultCategorySelects() {
		const expenseSel = document.querySelector('[data-bc-import-default-expense]');
		const incomeSel = document.querySelector('[data-bc-import-default-income]');
		if (!expenseSel || !incomeSel) return;

		expenseSel.replaceChildren();
		incomeSel.replaceChildren();
		expenseSel.appendChild(C.createElement('option', { value: '', text: t('budgetcheck', 'Choose expense category…') }));
		incomeSel.appendChild(C.createElement('option', { value: '', text: t('budgetcheck', 'Choose income category…') }));

		let firstExpense = '';
		let firstIncome = '';
		categories.forEach((cat) => {
			const opt = C.createElement('option', { value: String(cat.id), text: cat.name });
			if (cat.type === 'expense') {
				expenseSel.appendChild(opt);
				if (!firstExpense) firstExpense = String(cat.id);
			} else if (cat.type === 'income') {
				incomeSel.appendChild(opt);
				if (!firstIncome) firstIncome = String(cat.id);
			}
		});
		if (firstExpense) expenseSel.value = firstExpense;
		if (firstIncome) incomeSel.value = firstIncome;
	}

	function getDirectionMode() {
		const sel = document.querySelector('[data-bc-import-direction-mode]');
		const v = sel ? String(sel.value || 'auto') : 'auto';
		return v === 'expense' || v === 'income' ? v : 'auto';
	}

	function getDefaultCategories() {
		const expenseSel = document.querySelector('[data-bc-import-default-expense]');
		const incomeSel = document.querySelector('[data-bc-import-default-income]');
		return {
			expenseId: expenseSel ? Number.parseInt(expenseSel.value, 10) : 0,
			incomeId: incomeSel ? Number.parseInt(incomeSel.value, 10) : 0,
		};
	}

	function buildImportDefaults() {
		const defaults = getDefaultCategories();
		return {
			expenseCategoryId: defaults.expenseId || undefined,
			incomeCategoryId: defaults.incomeId || undefined,
		};
	}

	function validateDefaults(hasCategoryCol, directionMode) {
		if (hasCategoryCol) {
			return null;
		}
		const defaults = getDefaultCategories();
		if (directionMode === 'expense') {
			if (!defaults.expenseId) {
				return t('budgetcheck', 'Choose a default expense category.');
			}
			return null;
		}
		if (directionMode === 'income') {
			if (!defaults.incomeId) {
				return t('budgetcheck', 'Choose a default income category.');
			}
			return null;
		}
		if (!defaults.expenseId || !defaults.incomeId) {
			return t('budgetcheck', 'Choose default expense and income categories (needed when amounts can be + or −).');
		}
		return null;
	}

	function updateDefaultPickerVisibility() {
		const mode = getDirectionMode();
		const expenseField = document.querySelector('[data-bc-import-default-expense]')?.closest('.bc-field');
		const incomeField = document.querySelector('[data-bc-import-default-income]')?.closest('.bc-field');
		if (expenseField) expenseField.hidden = mode === 'income';
		if (incomeField) incomeField.hidden = mode === 'expense';
	}

	function wireTemplateDownload() {
		const btn = document.querySelector('[data-bc-import-download-template]');
		if (!btn) return;
		btn.addEventListener('click', () => {
			try {
				downloadCsvTemplate();
				Msg.announce(t('budgetcheck', 'CSV template downloaded.'), 'success');
			} catch (err) {
				Msg.announce(err.message || t('budgetcheck', 'Could not build CSV template.'), 'error');
			}
		});
	}

	function downloadCsvTemplate() {
		const isProject = Ws.workspace && Ws.workspace.type === 'project';
		const headers = ['bookingDate', 'title', 'direction', 'amount', 'category', 'notes', 'externalRef'];
		if (isProject) headers.push('bookingStatus');

		const expenseCat = categories.find((c) => c.type === 'expense');
		const incomeCat = categories.find((c) => c.type === 'income');
		const expenseName = expenseCat ? expenseCat.name : t('budgetcheck', 'Groceries');
		const incomeName = incomeCat ? incomeCat.name : t('budgetcheck', 'Salary');
		const statusName = isProject && statuses.length > 0 ? statuses[0].name : '';

		const today = isoToday();
		const rows = [
			[today, t('budgetcheck', 'Example: weekly groceries'), 'expense', '42.50', expenseName, '', 'BANK-001'],
			[today, t('budgetcheck', 'Example: salary payment'), 'income', '2500.00', incomeName, '', 'BANK-002'],
		];
		if (isProject && statusName !== '') {
			rows[0].push(statusName);
			rows[1].push('');
		}

		const lines = [headers.map(csvEscape).join(',')];
		rows.forEach((row) => lines.push(row.map(csvEscape).join(',')));
		const filename = 'budgetcheck-import-template-' + sanitizeFilename(Ws.workspace.name || 'workspace') + '.csv';
		downloadTextFile(filename, lines.join('\n'));
	}

	function wireForm() {
		const form = document.querySelector('[data-bc-import-form]');
		const fileInput = document.querySelector('[data-bc-import-file]');
		const filePicker = document.querySelector('[data-bc-import-file-picker]');
		const fileNameEl = document.querySelector('[data-bc-import-file-name]');
		const fileNameTextEl = document.querySelector('[data-bc-import-file-name-text]');
		const validateBtn = document.querySelector('[data-bc-import-validate]');
		const commitBtn = document.querySelector('[data-bc-import-commit]');
		const statusEl = document.querySelector('[data-bc-import-status]');
		if (!form || !fileInput || !validateBtn || !commitBtn || !statusEl) return;

		const updateFileDisplay = () => {
			const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
			if (!filePicker || !fileNameEl) return;
			if (file) {
				filePicker.classList.add('is-selected');
				fileNameEl.hidden = false;
				if (fileNameTextEl) fileNameTextEl.textContent = file.name;
			} else {
				filePicker.classList.remove('is-selected');
				fileNameEl.hidden = true;
				if (fileNameTextEl) fileNameTextEl.textContent = '';
			}
		};

		const resetValidation = () => {
			validatedRows = [];
			commitBtn.disabled = true;
		};

		const wireFilePicker = () => {
			if (!filePicker) return;
			const surface = filePicker.querySelector('.bc-file-picker__surface');
			if (surface) {
				surface.setAttribute('tabindex', '0');
				surface.addEventListener('keydown', (event) => {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						fileInput.click();
					}
				});
			}
			fileInput.addEventListener('change', () => {
				updateFileDisplay();
				resetValidation();
			});
			['dragenter', 'dragover'].forEach((type) => {
				filePicker.addEventListener(type, (event) => {
					event.preventDefault();
					filePicker.classList.add('is-dragover');
				});
			});
			['dragleave', 'drop'].forEach((type) => {
				filePicker.addEventListener(type, (event) => {
					event.preventDefault();
					filePicker.classList.remove('is-dragover');
				});
			});
			filePicker.addEventListener('drop', (event) => {
				const files = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;
				if (!files || files.length === 0) return;
				const dt = new DataTransfer();
				dt.items.add(files[0]);
				fileInput.files = dt.files;
				fileInput.dispatchEvent(new Event('change', { bubbles: true }));
			});
		};
		wireFilePicker();

		form.querySelectorAll('[data-bc-import-default-expense], [data-bc-import-default-income], [data-bc-import-direction-mode]').forEach((el) => {
			el.addEventListener('change', () => {
				updateDefaultPickerVisibility();
				resetValidation();
			});
		});

		validateBtn.addEventListener('click', async () => {
			const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
			if (!file) {
				Msg.announce(t('budgetcheck', 'Please choose a CSV file first.'), 'error');
				return;
			}
			try {
				validateBtn.disabled = true;
				commitBtn.disabled = true;
				statusEl.textContent = t('budgetcheck', 'Validating CSV…');
				const parsed = await parseCsvFile(file);
				const defaultsErrorAfterParse = validateDefaults(
					parsed.length > 0 && parsed[0]._hasCategoryCol === true,
					getDirectionMode(),
				);
				if (defaultsErrorAfterParse) {
					throw new Error(defaultsErrorAfterParse);
				}
				renderPreview(parsed);
				const res = await Api.post('/apps/budgetcheck/api/transactions/import/preview', {
					workspaceId: Ws.workspace.id,
					defaults: buildImportDefaults(),
					rows: parsed.map(stripImportMeta),
				});
				const preview = res.preview || {};
				if (Number(preview.invalidRows || 0) > 0) {
					validatedRows = [];
					const first = preview.errors && preview.errors[0] ? String(preview.errors[0].message || '') : t('budgetcheck', 'Validation failed.');
					statusEl.textContent = t('budgetcheck', 'Validation failed: {count} invalid rows. First error: {error}')
						.replace('{count}', String(preview.invalidRows || 0))
						.replace('{error}', first);
					return;
				}
				validatedRows = parsed.map(stripImportMeta);
				commitBtn.disabled = false;
				statusEl.textContent = t('budgetcheck', 'Validation passed. {count} rows are ready to import.')
					.replace('{count}', String(preview.validRows || parsed.length));
			} catch (err) {
				validatedRows = [];
				statusEl.textContent = err.message || t('budgetcheck', 'Validation failed.');
				if (err.status) {
					Msg.handleApiError(err);
				} else {
					Msg.announce(err.message || t('budgetcheck', 'Validation failed.'), 'error');
				}
			} finally {
				validateBtn.disabled = false;
			}
		});

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (validatedRows.length === 0) {
				Msg.announce(t('budgetcheck', 'Validate your CSV before importing.'), 'error');
				return;
			}
			try {
				commitBtn.disabled = true;
				const res = await Api.post('/apps/budgetcheck/api/transactions/import/commit', {
					workspaceId: Ws.workspace.id,
					defaults: buildImportDefaults(),
					rows: validatedRows,
				});
				const result = res.result || {};
				if (Number(result.errorCount || 0) > 0) {
					const first = result.errors && result.errors[0] ? String(result.errors[0].message || '') : t('budgetcheck', 'Import failed.');
					Msg.announce(first, 'error');
					commitBtn.disabled = false;
					return;
				}
				Msg.announce(t('budgetcheck', 'Imported {count} transactions successfully.').replace('{count}', String(result.createdCount || 0)), 'success');
				window.location.href = Ws.withWorkspace(Ws.urls.transactions);
			} catch (err) {
				commitBtn.disabled = false;
				Msg.handleApiError(err);
			}
		});
	}

	function renderPreview(rows) {
		const wrap = document.querySelector('[data-bc-import-preview-wrap]');
		const tbody = document.querySelector('[data-bc-import-preview]');
		if (!wrap || !tbody) return;
		const defaults = getDefaultCategories();
		tbody.replaceChildren();
		rows.slice(0, 20).forEach((row) => {
			let cat = null;
			if (row.categoryId) {
				cat = categories.find((c) => Number(c.id) === Number(row.categoryId));
			} else if (row.category) {
				cat = categories.find((c) => normalizeKey(c.name) === normalizeKey(row.category));
			} else if (row.direction === 'income') {
				cat = categories.find((c) => Number(c.id) === defaults.incomeId);
			} else {
				cat = categories.find((c) => Number(c.id) === defaults.expenseId);
			}
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { text: row.bookingDate }),
				C.createElement('td', { text: row.title }),
				C.createElement('td', { text: row.direction }),
				C.createElement('td', { text: row.amount }),
				C.createElement('td', { text: cat ? cat.name : '—' }),
			]));
		});
		wrap.hidden = false;
	}

	function parseCsvFile(file) {
		return new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onerror = () => reject(new Error(t('budgetcheck', 'Could not read CSV file.')));
			reader.onload = () => {
				try {
					resolve(csvToRows(String(reader.result || '')));
				} catch (e) {
					reject(e);
				}
			};
			reader.readAsText(file, 'utf-8');
		});
	}

	function csvToRows(text) {
		const delimiter = detectDelimiter(text);
		const records = parseCsvRecords(text, delimiter);
		if (records.length < 2) {
			throw new Error(t('budgetcheck', 'CSV must include a header row and at least one data row.'));
		}
		const header = records[0].map((h) => String(h || '').trim());
		const col = buildColumnIndex(header);
		const defaults = getDefaultCategories();

		if (col.bookingDate === undefined) {
			throw new Error(t('budgetcheck', 'Missing a date column (for example “date” or “bookingDate”).'));
		}
		if (col.title === undefined) {
			throw new Error(t('budgetcheck', 'Missing a title column (for example “title” or “description”).'));
		}
		if (col.amount === undefined) {
			throw new Error(t('budgetcheck', 'Missing an amount column (for example “amount” or “value”).'));
		}

		const hasCategoryCol = col.category !== undefined || col.categoryId !== undefined;
		const hasDirectionCol = col.direction !== undefined;
		const directionMode = getDirectionMode();

		const defaultsError = validateDefaults(hasCategoryCol, directionMode);
		if (defaultsError) {
			throw new Error(defaultsError);
		}

		const dataRecords = records.slice(1).filter((r) => r.some((v) => String(v || '').trim() !== ''));
		if (dataRecords.length > MAX_ROWS) {
			throw new Error(t('budgetcheck', 'Too many rows. Maximum is {max}.').replace('{max}', String(MAX_ROWS)));
		}

		return dataRecords.map((cols, idx) => {
			const row = normalizeRow(col, cols, idx + 2, {
				hasCategoryCol,
				hasDirectionCol,
				defaults,
				directionMode,
			});
			row._hasCategoryCol = hasCategoryCol;
			return row;
		});
	}

	function stripImportMeta(row) {
		const out = { ...row };
		delete out._hasCategoryCol;
		delete out._resolvedCategoryId;
		return out;
	}

	function buildColumnIndex(header) {
		const index = {};
		header.forEach((cell, i) => {
			const canon = resolveCanonicalColumn(cell);
			if (canon && index[canon] === undefined) {
				index[canon] = i;
			}
		});
		return index;
	}

	function resolveCanonicalColumn(label) {
		const compact = normalizeKey(label).replace(/\s+/g, '');
		if (!compact) return null;
		const direct = {
			bookingdate: 'bookingDate',
			title: 'title',
			direction: 'direction',
			amount: 'amount',
			category: 'category',
			categoryid: 'categoryId',
			notes: 'notes',
			isspecial: 'isSpecial',
			externalref: 'externalRef',
			bookingstatus: 'bookingStatus',
			bookingstatusid: 'bookingStatusId',
		};
		if (direct[compact]) return direct[compact];
		for (const [canon, aliases] of Object.entries(COLUMN_ALIASES)) {
			if (aliases.includes(compact)) return canon;
		}
		return null;
	}

	function normalizeRow(col, cols, rowNumber, opts) {
		const get = (key) => {
			const i = col[key];
			if (i === undefined) return '';
			return cols[i] == null ? '' : String(cols[i]).trim();
		};

		let amountRaw = get('amount');
		let direction = normalizeDirection(get('direction'));

		if (!direction && opts.directionMode !== 'auto') {
			direction = opts.directionMode;
			amountRaw = normalizeAmountString(amountRaw);
		} else if (!direction) {
			const signed = inferDirectionAndAmount(amountRaw);
			direction = signed.direction;
			amountRaw = signed.amount;
		} else {
			amountRaw = normalizeAmountString(amountRaw);
		}

		if (direction !== 'income' && direction !== 'expense') {
			throw new Error(t('budgetcheck', 'Row {row}: could not tell income from expense. Add a direction column or use signed amounts (+ income, − expense).')
				.replace('{row}', String(rowNumber)));
		}

		const bookingDate = normalizeDate(get('bookingDate'), rowNumber);
		const title = get('title');
		if (!title) {
			throw new Error(t('budgetcheck', 'Row {row}: title is required.').replace('{row}', String(rowNumber)));
		}

		let categoryId;
		let categoryName = '';
		if (opts.hasCategoryCol) {
			const rawCategoryId = get('categoryId');
			categoryName = get('category');
			if (rawCategoryId) {
				categoryId = resolveCategoryId({
					categoryId: rawCategoryId,
					category: categoryName,
					direction,
				}, rowNumber);
			} else if (categoryName) {
				categoryId = resolveCategoryId({
					categoryId: '',
					category: categoryName,
					direction,
				}, rowNumber);
			}
		}

		const out = {
			bookingDate,
			title,
			direction,
			amount: amountRaw,
			bookingStatusId: resolveStatusId({
				bookingStatusId: get('bookingStatusId'),
				bookingStatus: get('bookingStatus'),
			}, rowNumber),
			notes: get('notes'),
			isSpecial: ['1', 'true', 'yes'].includes(String(get('isSpecial') || '').toLowerCase()),
			externalRef: get('externalRef'),
		};
		if (categoryId) {
			out.categoryId = categoryId;
		} else if (categoryName) {
			out.category = categoryName;
		}
		return out;
	}

	function normalizeDate(raw, rowNumber) {
		const value = String(raw || '').trim();
		if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
		const dmy = value.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/);
		if (dmy) {
			const dd = dmy[1].padStart(2, '0');
			const mm = dmy[2].padStart(2, '0');
			return dmy[3] + '-' + mm + '-' + dd;
		}
		throw new Error(t('budgetcheck', 'Row {row}: date must be YYYY-MM-DD or DD.MM.YYYY.').replace('{row}', String(rowNumber)));
	}

	function inferDirectionAndAmount(raw) {
		const trimmed = String(raw || '').trim();
		if (trimmed === '') {
			throw new Error(t('budgetcheck', 'Amount is required.'));
		}
		let negative = false;
		let amount = trimmed;
		if (/^\(.*\)$/.test(amount)) {
			negative = true;
			amount = amount.slice(1, -1).trim();
		} else if (amount.startsWith('-')) {
			negative = true;
			amount = amount.slice(1).trim();
		} else if (amount.startsWith('+')) {
			amount = amount.slice(1).trim();
		}
		amount = normalizeAmountString(amount);
		return {
			direction: negative ? 'expense' : 'income',
			amount,
		};
	}

	function normalizeAmountString(raw) {
		return String(raw || '').trim().replace(/\s/g, '').replace(',', '.');
	}

	function resolveCategoryId(row, rowNumber) {
		if (row.categoryId) {
			const id = Number.parseInt(row.categoryId, 10);
			if (Number.isInteger(id) && id > 0) {
				ensureCategoryMatchesDirection(id, row.direction, rowNumber);
				return id;
			}
		}
		const name = normalizeKey(row.category || '');
		if (!name) {
			throw new Error(t('budgetcheck', 'Row {row}: category is empty.').replace('{row}', String(rowNumber)));
		}
		const matches = categoryByName.get(name) || [];
		if (matches.length === 0) {
			throw new Error(t('budgetcheck', 'Row {row}: unknown category “{name}”. Use a name from the list above.')
				.replace('{row}', String(rowNumber))
				.replace('{name}', row.category));
		}
		const typed = matches.filter((c) => c.type === row.direction);
		if (typed.length === 1) return Number(typed[0].id);
		if (typed.length > 1) {
			throw new Error(t('budgetcheck', 'Row {row}: category “{name}” is ambiguous for {direction}.')
				.replace('{row}', String(rowNumber))
				.replace('{name}', row.category)
				.replace('{direction}', row.direction));
		}
		if (matches.length === 1) {
			ensureCategoryMatchesDirection(Number(matches[0].id), row.direction, rowNumber);
			return Number(matches[0].id);
		}
		throw new Error(t('budgetcheck', 'Row {row}: category “{name}” is ambiguous.')
			.replace('{row}', String(rowNumber))
			.replace('{name}', row.category));
	}

	function ensureCategoryMatchesDirection(categoryId, direction, rowNumber) {
		const cat = categories.find((c) => Number(c.id) === Number(categoryId));
		if (!cat) {
			throw new Error(t('budgetcheck', 'Row {row}: category is not available in this workspace.')
				.replace('{row}', String(rowNumber)));
		}
		if (cat.type !== direction) {
			throw new Error(t('budgetcheck', 'Row {row}: category “{name}” is for {catType}, but this row is {direction}.')
				.replace('{row}', String(rowNumber))
				.replace('{name}', cat.name)
				.replace('{catType}', cat.type === 'income' ? t('budgetcheck', 'income') : t('budgetcheck', 'expenses'))
				.replace('{direction}', direction === 'income' ? t('budgetcheck', 'income') : t('budgetcheck', 'expense')));
		}
	}

	function resolveStatusId(row, rowNumber) {
		if (!row.bookingStatusId && !row.bookingStatus) return null;
		if (row.bookingStatusId) {
			const id = Number.parseInt(row.bookingStatusId, 10);
			if (Number.isInteger(id) && id > 0) return id;
		}
		const name = normalizeKey(row.bookingStatus || '');
		const matches = statusByName.get(name) || [];
		if (matches.length === 0) {
			throw new Error(t('budgetcheck', 'Row {row}: unknown booking status “{name}”.')
				.replace('{row}', String(rowNumber))
				.replace('{name}', row.bookingStatus));
		}
		if (matches.length > 1) {
			throw new Error(t('budgetcheck', 'Row {row}: booking status “{name}” is ambiguous.')
				.replace('{row}', String(rowNumber))
				.replace('{name}', row.bookingStatus));
		}
		return Number(matches[0].id);
	}

	function normalizeKey(value) {
		return String(value || '').trim().toLowerCase();
	}

	function normalizeDirection(raw) {
		const v = normalizeKey(raw);
		if (!v) return '';
		if (v === 'income' || v === 'expense') return v;
		if (['credit', 'cr', 'haben', 'einnahme', 'deposit', 'gutschrift', 'eingang'].includes(v)) return 'income';
		if (['debit', 'dr', 'soll', 'ausgabe', 'withdrawal', 'lastschrift', 'ausgang'].includes(v)) return 'expense';
		return '';
	}

	function detectDelimiter(text) {
		const firstLine = String(text || '').split(/\r?\n/)[0] || '';
		const commas = (firstLine.match(/,/g) || []).length;
		const semis = (firstLine.match(/;/g) || []).length;
		return semis > commas ? ';' : ',';
	}

	function parseCsvRecords(text, delimiter) {
		const sep = delimiter || ',';
		const normalized = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
		const rows = [];
		let row = [];
		let cell = '';
		let inQuotes = false;
		for (let i = 0; i < normalized.length; i++) {
			const ch = normalized[i];
			if (ch === '"') {
				if (inQuotes && normalized[i + 1] === '"') {
					cell += '"';
					i++;
				} else {
					inQuotes = !inQuotes;
				}
				continue;
			}
			if (ch === sep && !inQuotes) {
				row.push(cell);
				cell = '';
				continue;
			}
			if (ch === '\n' && !inQuotes) {
				row.push(cell);
				rows.push(row);
				row = [];
				cell = '';
				continue;
			}
			cell += ch;
		}
		if (inQuotes) throw new Error(t('budgetcheck', 'CSV contains an unterminated quoted value.'));
		row.push(cell);
		rows.push(row);
		return rows;
	}

	function isoToday() {
		const d = new Date();
		const pad = (n) => (n < 10 ? '0' + n : String(n));
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function csvEscape(value) {
		const s = String(value == null ? '' : value);
		if (/[",\n\r]/.test(s)) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	function sanitizeFilename(value) {
		return String(value || 'workspace')
			.trim()
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '') || 'workspace';
	}

	function downloadTextFile(filename, content) {
		const blob = new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8' });
		const url = URL.createObjectURL(blob);
		const anchor = document.createElement('a');
		anchor.href = url;
		anchor.download = filename;
		anchor.rel = 'noopener';
		document.body.appendChild(anchor);
		anchor.click();
		anchor.remove();
		window.setTimeout(() => URL.revokeObjectURL(url), 0);
	}
})();
