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
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;

	const MAX_ROWS = 500;
	const PREVIEW_ROWS = 20;
	const MAX_ERRORS_UI = 25;
	const IMPORT_PREFS_KEY_PREFIX = 'bc_import_prefs_';

	const COLUMN_ALIASES = {
		bookingDate: ['bookingdate', 'date', 'transactiondate', 'valuta', 'buchungstag', 'buchungsdatum', 'buchung'],
		title: ['title', 'description', 'text', 'memo', 'payee', 'name', 'verwendungszweck', 'buchungstext', 'purpose'],
		amount: ['amount', 'value', 'betrag', 'sum', 'umsatz'],
		direction: ['direction', 'incomeexpense', 'sollhaben', 'debit', 'credit', 'buchungstyp'],
		category: ['category', 'kategorie', 'categoryname'],
		categoryId: ['categoryid'],
		notes: ['notes', 'note', 'comment', 'notiz', 'remark'],
		isSpecial: ['isspecial', 'special'],
		externalRef: [
			'externalref', 'reference', 'ref', 'transactionid', 'transactionnr', 'transaktionsnr',
			'referenz', 'buchungsreferenz', 'bankreferenz', 'auftragsreferenz', 'referencenumber',
			'referenceno', 'referencenr', 'bankreference', 'paymentreference',
		],
		bookingStatus: ['bookingstatus'],
		bookingStatusId: ['bookingstatusid'],
	};

	let validatedRows = [];
	let lockedImportDefaults = null;
	let lockedImportOptions = null;
	let serverImportPrefs = null;
	let categories = [];
	let statuses = [];
	let categoryByName = new Map();
	let statusByName = new Map();

	document.addEventListener('DOMContentLoaded', async () => {
		if (!Ws.workspace || !Ws.canContribute) return;
		try {
			await loadCatalogs();
			await syncImportPreferencesFromServer();
			renderCategoryReference();
			populateDefaultCategorySelects();
			updateDefaultPickerVisibility();
			updateSkipFingerprintVisibility();
			wireForm();
			wireTemplateDownload();
		} catch (err) {
			Msg.handleApiError(err);
		}
	});

	function isProjectWorkspace() {
		return !!(Ws.workspace && Ws.workspace.type === 'project');
	}

	function importLocale() {
		const root = document.getElementById('app-content');
		if (root) {
			const locale = root.getAttribute('data-bc-locale');
			if (locale) return locale;
		}
		return document.documentElement.lang || 'en';
	}

	async function loadCatalogs() {
		const [cats, statusRes] = await Promise.all([
			Api.get('/apps/budgetcheck/api/categories', { workspaceId: Ws.workspace.id }),
			isProjectWorkspace()
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

	function importPrefsStorageKey() {
		const wsId = Ws.workspace && Ws.workspace.id ? Number(Ws.workspace.id) : 0;
		return wsId > 0 ? IMPORT_PREFS_KEY_PREFIX + String(wsId) : '';
	}

	function loadImportPreferences() {
		if (serverImportPrefs && typeof serverImportPrefs === 'object') {
			return serverImportPrefs;
		}
		const key = importPrefsStorageKey();
		if (!key) return null;
		try {
			const raw = window.localStorage.getItem(key);
			if (!raw) return null;
			const parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : null;
		} catch (_) {
			return null;
		}
	}

	function saveImportPreferencesLocal(prefs) {
		const key = importPrefsStorageKey();
		if (!key) return;
		try {
			window.localStorage.setItem(key, JSON.stringify(prefs));
		} catch (_) {
			/* private browsing / quota */
		}
	}

	function buildPrefsObject() {
		const defaults = getDefaultCategories();
		const skipDuplicates = getSkipDuplicates();
		return {
			expenseCategoryId: defaults.expenseId || null,
			incomeCategoryId: defaults.incomeId || null,
			directionMode: getDirectionMode(),
			skipDuplicates,
			skipFingerprintDuplicates: skipDuplicates && getSkipFingerprintDuplicates(),
		};
	}

	async function syncImportPreferencesFromServer() {
		if (!Ws.workspace || !Ws.workspace.id) return;
		try {
			const res = await Api.get('/apps/budgetcheck/api/workspaces/' + String(Ws.workspace.id) + '/import-preferences');
			const prefs = res.preferences;
			if (prefs && typeof prefs === 'object') {
				serverImportPrefs = prefs;
				saveImportPreferencesLocal(prefs);
			}
		} catch (_) {
			/* offline or first visit — localStorage fallback */
		}
	}

	function saveImportPreferences() {
		const prefs = buildPrefsObject();
		serverImportPrefs = prefs;
		saveImportPreferencesLocal(prefs);
		if (!Ws.workspace || !Ws.workspace.id) return;
		Api.put('/apps/budgetcheck/api/workspaces/' + String(Ws.workspace.id) + '/import-preferences', prefs).catch(() => {
			/* best-effort sync */
		});
	}

	function categoryOptionExists(categoryId, type) {
		if (!categoryId) return false;
		return categories.some((cat) => Number(cat.id) === Number(categoryId) && cat.type === type);
	}

	function populateDefaultCategorySelects() {
		const expenseSel = document.querySelector('[data-bc-import-default-expense]');
		const incomeSel = document.querySelector('[data-bc-import-default-income]');
		const directionSel = document.querySelector('[data-bc-import-direction-mode]');
		const skipDup = document.querySelector('[data-bc-import-skip-duplicates]');
		const skipFingerprint = document.querySelector('[data-bc-import-skip-fingerprint]');
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

		const saved = loadImportPreferences();
		const savedExpense = saved && saved.expenseCategoryId ? Number(saved.expenseCategoryId) : 0;
		const savedIncome = saved && saved.incomeCategoryId ? Number(saved.incomeCategoryId) : 0;
		expenseSel.value = categoryOptionExists(savedExpense, 'expense')
			? String(savedExpense)
			: (firstExpense || '');
		incomeSel.value = categoryOptionExists(savedIncome, 'income')
			? String(savedIncome)
			: (firstIncome || '');

		if (directionSel && saved && (saved.directionMode === 'auto' || saved.directionMode === 'expense' || saved.directionMode === 'income')) {
			directionSel.value = saved.directionMode;
		}
		if (skipDup && saved && typeof saved.skipDuplicates === 'boolean') {
			skipDup.checked = saved.skipDuplicates;
		}
		if (skipFingerprint && saved && typeof saved.skipFingerprintDuplicates === 'boolean') {
			skipFingerprint.checked = saved.skipDuplicates && saved.skipFingerprintDuplicates;
		}
		updateSkipFingerprintVisibility();
	}

	function getSkipFingerprintDuplicates() {
		const el = document.querySelector('[data-bc-import-skip-fingerprint]');
		return !!(el && el.checked && !el.disabled);
	}

	function updateSkipFingerprintVisibility() {
		const skipDup = document.querySelector('[data-bc-import-skip-duplicates]');
		const wrap = document.querySelector('[data-bc-import-fingerprint-wrap]');
		const fingerprint = document.querySelector('[data-bc-import-skip-fingerprint]');
		if (!skipDup || !wrap) return;
		const enabled = skipDup.checked;
		wrap.hidden = !enabled;
		if (fingerprint) {
			fingerprint.disabled = !enabled;
			if (!enabled) fingerprint.checked = false;
		}
	}

	function getDirectionMode() {
		const sel = document.querySelector('[data-bc-import-direction-mode]');
		const v = sel ? String(sel.value || 'auto') : 'auto';
		return v === 'expense' || v === 'income' ? v : 'auto';
	}

	function getSkipDuplicates() {
		const el = document.querySelector('[data-bc-import-skip-duplicates]');
		return !!(el && el.checked);
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

	function buildImportOptions() {
		const skipDuplicates = getSkipDuplicates();
		return {
			skipDuplicates,
			skipFingerprintDuplicates: skipDuplicates && getSkipFingerprintDuplicates(),
		};
	}

	function rowNeedsDefaultCategory(row) {
		if (!row._hasCategoryCol) return false;
		return !row.categoryId && !row.category;
	}

	function hasRowsWithEmptyCategory(rows) {
		return rows.some((row) => rowNeedsDefaultCategory(row));
	}

	function validateDefaults(rows, directionMode) {
		const hasCategoryCol = rows.length > 0 && rows[0]._hasCategoryCol === true;
		const needsDefaults = !hasCategoryCol || hasRowsWithEmptyCategory(rows);
		if (!needsDefaults) {
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
			if (hasCategoryCol && hasRowsWithEmptyCategory(rows)) {
				return t('budgetcheck', 'Some rows have an empty category. Choose default expense and income categories for those rows.');
			}
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

	function clearErrorsPanel() {
		const wrap = document.querySelector('[data-bc-import-errors-wrap]');
		const list = document.querySelector('[data-bc-import-errors]');
		if (list) list.replaceChildren();
		if (wrap) wrap.hidden = true;
	}

	function renderErrors(errors, totalInvalid) {
		const wrap = document.querySelector('[data-bc-import-errors-wrap]');
		const list = document.querySelector('[data-bc-import-errors]');
		const title = document.querySelector('[data-bc-import-errors-title]');
		if (!wrap || !list) return;
		list.replaceChildren();
		if (!errors || errors.length === 0) {
			wrap.hidden = true;
			return;
		}
		const shown = errors.slice(0, MAX_ERRORS_UI);
		shown.forEach((err) => {
			const li = C.createElement('li', { text: formatImportRowError(err) });
			list.appendChild(li);
		});
		if (title) {
			const extra = Number(totalInvalid || errors.length) - shown.length;
			title.textContent = extra > 0
				? t('budgetcheck', 'Validation errors ({shown} of {total} shown)')
					.replace('{shown}', String(shown.length))
					.replace('{total}', String(totalInvalid || errors.length))
				: t('budgetcheck', 'Validation errors');
		}
		wrap.hidden = false;
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
		const isProject = isProjectWorkspace();
		const headers = ['bookingDate', 'title', 'direction', 'amount', 'category', 'notes', 'externalRef'];
		if (isProject) headers.push('bookingStatus');

		const expenseCat = categories.find((c) => c.type === 'expense');
		const incomeCat = categories.find((c) => c.type === 'income');
		const expenseName = expenseCat ? expenseCat.name : t('budgetcheck', 'Groceries');
		const incomeName = incomeCat ? incomeCat.name : t('budgetcheck', 'Salary');
		const statusName = isProject && statuses.length > 0 ? statuses[0].name : '';

		const today = isoToday();
		const rows = [
			[today, t('budgetcheck', 'Example: weekly groceries'), 'expense', '42,50', expenseName, '', 'BANK-001'],
			[today, t('budgetcheck', 'Example: salary payment'), 'income', '2500,00', incomeName, '', 'BANK-002'],
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
			lockedImportDefaults = null;
			lockedImportOptions = null;
			commitBtn.disabled = true;
			clearErrorsPanel();
			const previewWrap = document.querySelector('[data-bc-import-preview-wrap]');
			const previewSummary = document.querySelector('[data-bc-import-preview-summary]');
			if (previewWrap) previewWrap.hidden = true;
			if (previewSummary) {
				previewSummary.hidden = true;
				previewSummary.textContent = '';
			}
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

		form.querySelectorAll('[data-bc-import-default-expense], [data-bc-import-default-income], [data-bc-import-direction-mode], [data-bc-import-skip-duplicates], [data-bc-import-skip-fingerprint]').forEach((el) => {
			el.addEventListener('change', () => {
				updateDefaultPickerVisibility();
				updateSkipFingerprintVisibility();
				saveImportPreferences();
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
				clearErrorsPanel();
				statusEl.textContent = t('budgetcheck', 'Validating CSV…');
				const parsed = await parseCsvFile(file);
				const directionMode = getDirectionMode();
				const defaultsErrorAfterParse = validateDefaults(parsed, directionMode);
				if (defaultsErrorAfterParse) {
					throw new Error(defaultsErrorAfterParse);
				}
				const importDefaults = buildImportDefaults();
				const importOptions = buildImportOptions();
				renderPreview(parsed, importDefaults);
				const res = await Api.post('/apps/budgetcheck/api/transactions/import/preview', {
					workspaceId: Ws.workspace.id,
					defaults: importDefaults,
					options: importOptions,
					rows: parsed.map(stripImportMeta),
				});
				const preview = res.preview || {};
				if (Number(preview.invalidRows || 0) > 0) {
					validatedRows = [];
					lockedImportDefaults = null;
					lockedImportOptions = null;
					renderErrors(preview.errors || [], Number(preview.invalidRows || 0));
					const firstErr = preview.errors && preview.errors[0] ? preview.errors[0] : null;
					const first = firstErr
						? formatImportRowError(firstErr)
						: t('budgetcheck', 'Validation failed.');
					statusEl.textContent = t('budgetcheck', 'Validation failed: {count} invalid rows. First error: {error}')
						.replace('{count}', String(preview.invalidRows || 0))
						.replace('{error}', first);
					return;
				}
				validatedRows = parsed.map(stripImportMeta);
				lockedImportDefaults = { ...importDefaults };
				lockedImportOptions = { ...importOptions };
				commitBtn.disabled = false;
				const ready = Number(preview.validRows || parsed.length);
				const skipped = Number(preview.skippedRows || 0);
				if (skipped > 0) {
					statusEl.textContent = t('budgetcheck', 'Validation passed. {count} rows ready; {skipped} duplicates will be skipped.')
						.replace('{count}', String(ready))
						.replace('{skipped}', String(skipped));
				} else {
					statusEl.textContent = t('budgetcheck', 'Validation passed. {count} rows are ready to import.')
						.replace('{count}', String(ready));
				}
			} catch (err) {
				validatedRows = [];
				lockedImportDefaults = null;
				lockedImportOptions = null;
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
					defaults: lockedImportDefaults || buildImportDefaults(),
					options: lockedImportOptions || buildImportOptions(),
					rows: validatedRows,
				});
				const result = res.result || {};
				if (Number(result.errorCount || 0) > 0) {
					renderErrors(result.errors || [], Number(result.errorCount || 0));
					const first = result.errors && result.errors[0]
						? formatImportRowError(result.errors[0])
						: t('budgetcheck', 'Import failed.');
					Msg.announce(first, 'error');
					commitBtn.disabled = false;
					return;
				}
				saveImportPreferences();
				const created = Number(result.createdCount || 0);
				const skipped = Number(result.skippedCount || 0);
				if (skipped > 0) {
					Msg.announce(
						t('budgetcheck', 'Imported {created} transactions. Skipped {skipped} duplicates.')
							.replace('{created}', String(created))
							.replace('{skipped}', String(skipped)),
						'success',
					);
				} else {
					Msg.announce(
						t('budgetcheck', 'Imported {count} transactions successfully.').replace('{count}', String(created)),
						'success',
					);
				}
				window.location.href = Ws.withWorkspace(Ws.urls.transactions);
			} catch (err) {
				commitBtn.disabled = false;
				Msg.handleApiError(err);
			}
		});
	}

	function previewDefaultsForRows(rows, fallbackDefaults) {
		const expenseId = fallbackDefaults.expenseCategoryId || 0;
		const incomeId = fallbackDefaults.incomeCategoryId || 0;
		return { expenseId, incomeId };
	}

	function renderPreview(rows, importDefaults) {
		const wrap = document.querySelector('[data-bc-import-preview-wrap]');
		const tbody = document.querySelector('[data-bc-import-preview]');
		const summary = document.querySelector('[data-bc-import-preview-summary]');
		if (!wrap || !tbody) return;
		const defaults = previewDefaultsForRows(rows, importDefaults);
		tbody.replaceChildren();
		rows.slice(0, PREVIEW_ROWS).forEach((row) => {
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
			const tr = C.createElement('tr');
			if (row._lineNumber) {
				tr.appendChild(C.createElement('td', { text: String(row._lineNumber) }));
			}
			tr.appendChild(C.createElement('td', { text: row.bookingDate }));
			tr.appendChild(C.createElement('td', { text: row.title }));
			tr.appendChild(C.createElement('td', { text: row.direction }));
			tr.appendChild(C.createElement('td', { text: row.amount }));
			tr.appendChild(C.createElement('td', { text: cat ? cat.name : '—' }));
			tbody.appendChild(tr);
		});
		if (summary) {
			if (rows.length > PREVIEW_ROWS) {
				summary.hidden = false;
				summary.textContent = t('budgetcheck', 'Showing the first {shown} of {total} rows.')
					.replace('{shown}', String(PREVIEW_ROWS))
					.replace('{total}', String(rows.length));
			} else {
				summary.hidden = false;
				summary.textContent = t('budgetcheck', 'Preview of {total} rows.')
					.replace('{total}', String(rows.length));
			}
		}
		wrap.hidden = false;
	}

	function parseCsvFile(file) {
		return new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onerror = () => reject(new Error(t('budgetcheck', 'Could not read CSV file.')));
			reader.onload = () => {
				try {
					const text = decodeCsvBuffer(reader.result);
					resolve(csvToRows(text));
				} catch (e) {
					reject(e);
				}
			};
			reader.readAsArrayBuffer(file);
		});
	}

	/**
	 * Decode bank CSV bytes: UTF-8 (with BOM) first, then Windows-1252 / Latin-1 fallback.
	 */
	function decodeCsvBuffer(buffer) {
		const bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : new Uint8Array(buffer || []);
		if (bytes.length === 0) {
			return '';
		}
		let offset = 0;
		if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
			offset = 3;
		}
		const payload = offset > 0 ? bytes.subarray(offset) : bytes;
		try {
			return new TextDecoder('utf-8', { fatal: true }).decode(payload);
		} catch (_) {
			try {
				return new TextDecoder('windows-1252').decode(bytes);
			} catch (_2) {
				return new TextDecoder('iso-8859-1').decode(bytes);
			}
		}
	}

	function csvToRows(text) {
		let source = String(text || '');
		if (source.charCodeAt(0) === 0xFEFF) {
			source = source.slice(1);
		}
		const delimiter = detectDelimiter(source);
		const records = parseCsvRecords(source, delimiter);
		if (records.length < 2) {
			throw new Error(t('budgetcheck', 'CSV must include a header row and at least one data row.'));
		}
		const header = records[0].map((h) => String(h || '').trim());
		const col = buildColumnIndex(header);
		const defaults = getDefaultCategories();
		const directionMode = getDirectionMode();

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

		const dataRecords = [];
		records.slice(1).forEach((cols, idx) => {
			const lineNumber = idx + 2;
			if (cols.some((v) => String(v || '').trim() !== '')) {
				dataRecords.push({ cols, lineNumber });
			}
		});
		if (dataRecords.length > MAX_ROWS) {
			throw new Error(t('budgetcheck', 'Too many rows. Maximum is {max}.').replace('{max}', String(MAX_ROWS)));
		}

		const parsed = dataRecords.map(({ cols, lineNumber }) => {
			const row = normalizeRow(col, cols, lineNumber, {
				hasCategoryCol,
				hasDirectionCol,
				defaults,
				directionMode,
			});
			row._hasCategoryCol = hasCategoryCol;
			row._lineNumber = lineNumber;
			return row;
		});

		const defaultsError = validateDefaults(parsed, directionMode);
		if (defaultsError) {
			throw new Error(defaultsError);
		}

		return parsed;
	}

	function stripImportMeta(row) {
		const out = { ...row };
		if (row._lineNumber) {
			out.sourceRowNumber = row._lineNumber;
		}
		delete out._hasCategoryCol;
		delete out._lineNumber;
		delete out._resolvedCategoryId;
		return out;
	}

	function buildColumnIndex(header) {
		const index = {};
		const isProject = isProjectWorkspace();
		header.forEach((cell, i) => {
			const canon = resolveCanonicalColumn(cell, isProject);
			if (canon && index[canon] === undefined) {
				index[canon] = i;
			}
		});
		return index;
	}

	function resolveCanonicalColumn(label, isProject) {
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
		};
		if (isProject) {
			direct.bookingstatus = 'bookingStatus';
			direct.bookingstatusid = 'bookingStatusId';
		}
		if (direct[compact]) return direct[compact];
		for (const [canon, aliases] of Object.entries(COLUMN_ALIASES)) {
			if (!isProject && (canon === 'bookingStatus' || canon === 'bookingStatusId')) {
				continue;
			}
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
			amountRaw = validateAmountForRow(stripAmountSign(amountRaw).amount, rowNumber);
		} else if (!direction) {
			const signed = inferDirectionAndAmount(amountRaw, rowNumber);
			direction = signed.direction;
			amountRaw = signed.amount;
		} else {
			amountRaw = validateAmountForRow(stripAmountSign(amountRaw).amount, rowNumber);
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
			bookingStatusId: isProjectWorkspace()
				? resolveStatusId({
					bookingStatusId: get('bookingStatusId'),
					bookingStatus: get('bookingStatus'),
				}, rowNumber)
				: null,
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
		if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
			return value;
		}
		const parsed = Dates && typeof Dates.parseDisplayDateToIso === 'function'
			? Dates.parseDisplayDateToIso(value, importLocale())
			: null;
		if (parsed) {
			return parsed;
		}
		const pattern = Dates && typeof Dates.expectedFormatPattern === 'function'
			? Dates.expectedFormatPattern(importLocale())
			: 'dd.mm.yyyy';
		throw new Error(
			t('budgetcheck', 'Row {row}: date is not valid. Use {pattern} or YYYY-MM-DD.')
				.replace('{row}', String(rowNumber))
				.replace('{pattern}', pattern),
		);
	}

	function currencyDecimals() {
		const ws = Ws.workspace;
		return ws && Number.isInteger(ws.currencyDecimals) ? ws.currencyDecimals : 2;
	}

	function stripAmountSign(raw) {
		let amount = String(raw || '').trim();
		if (/^\(.*\)$/.test(amount)) {
			return { negative: true, amount: amount.slice(1, -1).trim() };
		}
		if (amount.startsWith('-')) {
			return { negative: true, amount: amount.slice(1).trim() };
		}
		if (amount.startsWith('+')) {
			return { negative: false, amount: amount.slice(1).trim() };
		}
		return { negative: false, amount };
	}

	function stripCurrencyDecorations(raw) {
		let amount = String(raw || '').trim();
		amount = amount.replace(/^(EUR|USD|GBP|NOK|SEK|DKK|CHF|PLN|CZK|€|\$|£|kr\.?)\s*/i, '');
		amount = amount.replace(/\s*(EUR|USD|GBP|NOK|SEK|DKK|CHF|PLN|CZK|€|\$|£|kr\.?)$/i, '');
		return amount.trim();
	}

	function validateAmountForRow(raw, rowNumber) {
		const unsigned = stripCurrencyDecorations(stripAmountSign(raw).amount);
		if (unsigned === '') {
			throw new Error(t('budgetcheck', 'Row {row}: amount is required.').replace('{row}', String(rowNumber)));
		}
		try {
			Money.parseHuman(unsigned, currencyDecimals());
		} catch (_) {
			throw new Error(
				t('budgetcheck', 'Row {row}: amount is not valid. Use formats like 42,50 or 1.234,56 or 1234.56 (no currency symbol).')
					.replace('{row}', String(rowNumber)),
			);
		}
		return unsigned;
	}

	function inferDirectionAndAmount(raw, rowNumber) {
		const trimmed = String(raw || '').trim();
		if (trimmed === '') {
			throw new Error(t('budgetcheck', 'Row {row}: amount is required.').replace('{row}', String(rowNumber)));
		}
		const signed = stripAmountSign(trimmed);
		const amount = validateAmountForRow(signed.amount, rowNumber);
		return {
			direction: signed.negative ? 'expense' : 'income',
			amount,
		};
	}

	function formatImportRowError(err) {
		const rowNumber = err && err.rowNumber ? Number(err.rowNumber) : 0;
		const message = err && err.message ? String(err.message) : t('budgetcheck', 'Validation failed.');
		if (rowNumber > 0 && !/^Row \d+:/.test(message)) {
			return t('budgetcheck', 'Row {row}: {error}')
				.replace('{row}', String(rowNumber))
				.replace('{error}', message);
		}
		return message;
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
		const candidates = [
			{ sep: '\t', count: (firstLine.match(/\t/g) || []).length },
			{ sep: ';', count: (firstLine.match(/;/g) || []).length },
			{ sep: ',', count: (firstLine.match(/,/g) || []).length },
		];
		candidates.sort((a, b) => b.count - a.count);
		return candidates[0].count > 0 ? candidates[0].sep : ',';
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
