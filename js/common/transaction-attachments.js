(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;

	const ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,application/pdf,text/xml,application/xml,.jpg,.jpeg,.png,.gif,.webp,.pdf,.xml';

	const LIMITS = {
		maxFiles: 10,
		maxFileSize: 5 * 1024 * 1024,
		maxTotalSize: 25 * 1024 * 1024,
		allowedExtensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'xml'],
		dangerousExtensions: [
			'php', 'phtml', 'php3', 'php4', 'php5', 'pht', 'phar',
			'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'vbs', 'js', 'jar', 'app',
		],
	};

	function uid(prefix) {
		return prefix + '-' + Math.random().toString(36).slice(2);
	}

	function formatFileSize(bytes) {
		const n = Number(bytes);
		if (!Number.isFinite(n) || n < 0) return '';
		if (n < 1024) return n + ' B';
		if (n < 1024 * 1024) return (n / 1024).toFixed(n < 10 * 1024 ? 1 : 0) + ' KB';
		return (n / (1024 * 1024)).toFixed(1) + ' MB';
	}

	function fileExtension(name) {
		const base = String(name || '').split(/[/\\]/).pop() || '';
		const dot = base.lastIndexOf('.');
		if (dot < 1) return '';
		return base.slice(dot + 1).toLowerCase();
	}

	function isPreviewableMime(mimeType) {
		return mimeType === 'image/jpeg'
			|| mimeType === 'image/png'
			|| mimeType === 'image/gif'
			|| mimeType === 'image/webp';
	}

	function isXmlMime(mimeType) {
		return mimeType === 'text/xml' || mimeType === 'application/xml';
	}

	function galleryApi() {
		return window.BudgetCheckAttachmentGallery;
	}

	function hasBlockedExtension(name) {
		const lower = String(name || '').toLowerCase();
		return LIMITS.dangerousExtensions.some((ext) => lower.includes('.' + ext));
	}

	function previewLabel(attachment) {
		if (!attachment) return t('budgetcheck', 'Attachment');
		return attachment.fileName || t('budgetcheck', 'Attachment');
	}

	function sanitizeFileName(name) {
		return String(name || '').split(/[/\\]/).pop() || 'attachment';
	}

	function pendingDedupeKey(file) {
		return sanitizeFileName(file.name) + '|' + String(file.size);
	}

	/**
	 * @param {File} file
	 * @param {number} currentCount
	 * @param {number} currentTotalBytes
	 * @return {{ok: boolean, message?: string}}
	 */
	function validateClientFile(file, currentCount, currentTotalBytes) {
		if (!file || typeof file.name !== 'string') {
			return { ok: false, message: t('budgetcheck', 'Invalid upload parameters.') };
		}
		const size = Number(file.size);
		if (!Number.isFinite(size) || size < 1) {
			return { ok: false, message: t('budgetcheck', 'File is empty.') };
		}
		if (size > LIMITS.maxFileSize) {
			return { ok: false, message: t('budgetcheck', 'File is too large. Maximum size is 5 MB per file.') };
		}
		const extension = fileExtension(file.name);
		if (!extension || !LIMITS.allowedExtensions.includes(extension)) {
			return { ok: false, message: t('budgetcheck', 'File type is not allowed. Use JPEG, PNG, GIF, WebP, PDF, or XML e-invoice.') };
		}
		if (hasBlockedExtension(file.name)) {
			return { ok: false, message: t('budgetcheck', 'File name contains a blocked extension.') };
		}
		if (currentCount >= LIMITS.maxFiles) {
			return { ok: false, message: t('budgetcheck', 'This transaction already has the maximum of 10 attachments.') };
		}
		if (currentTotalBytes + size > LIMITS.maxTotalSize) {
			return { ok: false, message: t('budgetcheck', 'Total attachment size for this transaction cannot exceed 25 MB.') };
		}
		return { ok: true };
	}

	/**
	 * @param {{transactionId?: number|null, readOnly?: boolean, closedMonth?: boolean, onChange?: function(): void, onAttachmentsChanged?: function(object): void}} options
	 */
	function mountSection(container, options) {
		const opts = options || {};
		const headingId = uid('bc-tx-attachments-heading');
		const pickerHintId = uid('bc-tx-attachments-picker-hint');
		const state = {
			transactionId: opts.transactionId || null,
			readOnly: !!opts.readOnly,
			closedMonth: !!opts.closedMonth,
			items: [],
			pendingItems: [],
			loading: false,
			uploading: false,
		};
		let loadSeq = 0;
		let destroyed = false;

		const section = C.createElement('section', {
			class: 'bc-tx-attachments',
			attrs: { 'aria-labelledby': headingId },
		});
		const introEl = C.createElement('p', {
			class: 'bc-field__hint bc-field__hint--block bc-tx-attachments__intro',
		});
		section.appendChild(C.createElement('h3', {
			id: headingId,
			class: 'bc-tx-attachments__heading',
			text: t('budgetcheck', 'Receipts & images'),
		}));
		section.appendChild(introEl);

		const statusEl = C.createElement('p', {
			class: 'bc-field__hint bc-field__hint--block',
			attrs: { role: 'status', 'aria-live': 'polite', hidden: true },
		});
		const grid = C.createElement('ul', { class: 'bc-tx-attachments__grid', attrs: { role: 'list' } });
		const pickerWrap = C.createElement('div', { class: 'bc-tx-attachments__picker-wrap', attrs: { hidden: true } });

		section.appendChild(statusEl);
		section.appendChild(grid);
		section.appendChild(pickerWrap);

		let fileInput = null;
		let picker = null;

		function totalFileCount() {
			return state.items.length + state.pendingItems.length;
		}

		function totalByteSize() {
			let total = 0;
			state.items.forEach((item) => { total += Number(item.fileSize) || 0; });
			state.pendingItems.forEach((item) => { total += Number(item.fileSize) || 0; });
			return total;
		}

		function revokePending(pending) {
			if (!pending) return;
			const urls = new Set([pending.previewUrl, pending.downloadUrl].filter(Boolean));
			urls.forEach((url) => {
				try {
					URL.revokeObjectURL(url);
				} catch (_) { /* ignore */ }
			});
			pending.previewUrl = null;
			pending.downloadUrl = null;
		}

		function syncBusyState() {
			section.setAttribute('aria-busy', (state.loading || state.uploading) ? 'true' : 'false');
		}

		function emptyGridMessage() {
			if (state.readOnly) {
				return t('budgetcheck', 'No receipts attached.');
			}
			return t('budgetcheck', 'No receipts yet. Add a photo or PDF below.');
		}

		function syncIntro() {
			if (state.closedMonth && state.readOnly && state.transactionId) {
				introEl.textContent = t('budgetcheck', 'Attachments in a closed month are read-only. Reopen the month to add or remove receipts.');
				return;
			}
			introEl.textContent = t('budgetcheck', 'Attach photos, PDF receipts, or XML e-invoices to this booking. Add them below — they upload when you save. Only workspace members can view them.');
		}

		function notifyChange() {
			if (typeof opts.onChange === 'function') {
				opts.onChange();
			}
			if (typeof opts.onAttachmentsChanged === 'function') {
				opts.onAttachmentsChanged({
					transactionId: state.transactionId,
					attachmentCount: state.items.length,
					hasAttachments: state.items.length > 0,
				});
			}
		}

		function openGallery(startItem) {
			const Gallery = galleryApi();
			if (!Gallery || typeof Gallery.open !== 'function') return;
			const all = state.items.concat(state.pendingItems);
			if (all.length === 0) return;
			let startIndex = 0;
			if (startItem) {
				const idx = all.findIndex((entry) => {
					if (startItem.id && entry.id) return String(entry.id) === String(startItem.id);
					if (startItem.pendingKey && entry.pendingKey) return entry.pendingKey === startItem.pendingKey;
					return entry === startItem;
				});
				if (idx >= 0) startIndex = idx;
			}
			Gallery.open({
				items: all,
				startIndex,
				readOnly: state.readOnly,
				onItemsChange: (items) => {
					const saved = items.filter((entry) => !entry.isPending);
					const pending = items.filter((entry) => entry.isPending);
					state.pendingItems.forEach((entry) => {
						if (!pending.some((p) => p.pendingKey === entry.pendingKey)) {
							revokePending(entry);
						}
					});
					state.items = saved;
					state.pendingItems = pending;
					renderGrid();
					buildPicker();
					notifyChange();
				},
			});
		}

		function setStatus(message, isError) {
			statusEl.setAttribute('aria-live', isError ? 'assertive' : 'polite');
			if (!message) {
				statusEl.hidden = true;
				statusEl.textContent = '';
				statusEl.classList.remove('bc-field__hint--error');
				return;
			}
			statusEl.hidden = false;
			statusEl.textContent = message;
			statusEl.classList.toggle('bc-field__hint--error', !!isError);
		}

		function createPendingItem(file) {
			const safeName = sanitizeFileName(file.name);
			const extension = fileExtension(safeName);
			const mimeType = String(file.type || '');
			const isPdf = extension === 'pdf' || mimeType === 'application/pdf';
			const isXml = extension === 'xml' || isXmlMime(mimeType);
			const isPreviewable = isPreviewableMime(mimeType);
			const pendingKey = uid('bc-tx-attachment-pending');
			const objectUrl = (isPreviewable || isPdf || isXml) ? URL.createObjectURL(file) : null;
			return {
				pendingKey,
				dedupeKey: safeName + '|' + String(file.size),
				file,
				fileName: safeName,
				fileSize: file.size,
				mimeType,
				isPreviewable,
				isPdf,
				isXml,
				isImage: isPreviewable,
				isEditableImage: isPreviewable,
				previewUrl: (isPreviewable || isPdf || isXml) ? objectUrl : null,
				downloadUrl: objectUrl,
				isPending: true,
			};
		}

		function renderFilePlaceholder(item) {
			const Gallery = galleryApi();
			const label = Gallery && typeof Gallery.fileLabel === 'function'
				? Gallery.fileLabel(item)
				: (item.isPdf ? 'PDF' : (item.isXml ? t('budgetcheck', 'E-invoice') : t('budgetcheck', 'File')));
			return C.createElement('div', {
				class: 'bc-tx-attachments__thumb bc-tx-attachments__thumb--file',
				attrs: { 'aria-hidden': 'true' },
				text: label,
			});
		}

		function renderAttachmentCard(item, isPending) {
			const cardClasses = ['bc-tx-attachments__card'];
			if (isPending) cardClasses.push('bc-tx-attachments__card--pending');

			const card = C.createElement('article', { class: cardClasses.join(' ') });

			const thumbBtn = C.createElement('button', {
				type: 'button',
				class: 'bc-tx-attachments__thumb-btn',
				attrs: {
					'aria-label': t('budgetcheck', 'Open {name} in gallery').replace('{name}', previewLabel(item)),
				},
				on: { click: () => openGallery(item) },
			});

			if (item.isPreviewable && item.previewUrl) {
				thumbBtn.appendChild(C.createElement('img', {
					class: 'bc-tx-attachments__thumb',
					attrs: {
						src: item.previewUrl,
						alt: '',
						loading: 'lazy',
						decoding: 'async',
					},
				}));
			} else {
				thumbBtn.appendChild(renderFilePlaceholder(item));
			}
			card.appendChild(thumbBtn);

			const meta = C.createElement('div', { class: 'bc-tx-attachments__meta' });
			meta.appendChild(C.createElement('span', {
				class: 'bc-tx-attachments__name',
				text: item.fileName || t('budgetcheck', 'Attachment'),
			}));
			meta.appendChild(C.createElement('span', {
				class: 'bc-tx-attachments__size',
				text: formatFileSize(item.fileSize),
			}));
			if (isPending) {
				meta.appendChild(C.createElement('span', {
					class: 'bc-tx-attachments__badge',
					text: t('budgetcheck', 'Ready to upload'),
				}));
			}
			card.appendChild(meta);

			const actions = C.createElement('div', { class: 'bc-tx-attachments__actions' });
			actions.appendChild(C.createElement('button', {
				type: 'button',
				class: 'button bc-tx-attachments__action',
				text: t('budgetcheck', 'View'),
				attrs: {
					'aria-label': t('budgetcheck', 'Open {name} in gallery').replace('{name}', previewLabel(item)),
				},
				on: { click: () => openGallery(item) },
			}));
			if (!state.readOnly) {
				actions.appendChild(C.createElement('button', {
					type: 'button',
					class: 'button bc-tx-attachments__action bc-tx-attachments__action--danger',
					text: t('budgetcheck', 'Remove'),
					attrs: {
						'aria-label': t('budgetcheck', 'Remove {name}').replace('{name}', previewLabel(item)),
					},
					on: {
						click: () => {
							if (isPending) removePending(item);
							else removeAttachment(item);
						},
					},
				}));
			}
			card.appendChild(actions);
			return card;
		}

		function renderGrid() {
			grid.replaceChildren();
			syncBusyState();
			if (state.loading) {
				grid.appendChild(C.createElement('li', {
					class: 'bc-tx-attachments__empty',
					attrs: { role: 'status' },
					text: t('budgetcheck', 'Loading attachments…'),
				}));
				return;
			}
			if (totalFileCount() === 0) {
				grid.appendChild(C.createElement('li', {
					class: 'bc-tx-attachments__empty',
					attrs: { role: 'status' },
					text: emptyGridMessage(),
				}));
				return;
			}
			state.items.forEach((item) => {
				const li = C.createElement('li', { class: 'bc-tx-attachments__item' });
				li.appendChild(renderAttachmentCard(item, false));
				grid.appendChild(li);
			});
			state.pendingItems.forEach((item) => {
				const li = C.createElement('li', { class: 'bc-tx-attachments__item' });
				li.appendChild(renderAttachmentCard(item, true));
				grid.appendChild(li);
			});
		}

		function buildPicker() {
			pickerWrap.replaceChildren();
			if (state.readOnly) {
				pickerWrap.hidden = true;
				return;
			}
			pickerWrap.hidden = false;

			picker = C.createElement('div', { class: 'bc-file-picker bc-tx-attachments__picker' });
			const surface = C.createElement('label', { class: 'bc-file-picker__surface' });
			fileInput = C.createElement('input', {
				class: 'bc-file-picker__input',
				attrs: {
					type: 'file',
					accept: ACCEPT,
					multiple: true,
					'aria-describedby': pickerHintId,
					'aria-label': t('budgetcheck', 'Add receipt or image'),
				},
			});
			surface.appendChild(fileInput);
			surface.appendChild(C.createElement('span', {
				class: 'bc-file-picker__icon',
				attrs: { 'aria-hidden': 'true' },
				text: '+',
			}));
			const copy = C.createElement('span', { class: 'bc-file-picker__copy' });
			copy.appendChild(C.createElement('span', {
				class: 'bc-file-picker__title',
				text: state.uploading
					? t('budgetcheck', 'Uploading receipts…')
					: t('budgetcheck', 'Add receipt or image'),
			}));
			copy.appendChild(C.createElement('span', {
				id: pickerHintId,
				class: 'bc-file-picker__sub',
				text: t('budgetcheck', 'JPEG, PNG, GIF, WebP, PDF, or XML e-invoice · up to 5 MB each · max 10 files'),
			}));
			surface.appendChild(copy);
			picker.appendChild(surface);
			pickerWrap.appendChild(picker);

			const pickerDisabled = state.uploading || totalFileCount() >= LIMITS.maxFiles;
			fileInput.disabled = pickerDisabled;
			surface.setAttribute('tabindex', pickerDisabled ? '-1' : '0');
			surface.setAttribute('aria-disabled', pickerDisabled ? 'true' : 'false');
			surface.addEventListener('keydown', (event) => {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					if (!pickerDisabled) fileInput.click();
				}
			});
			['dragenter', 'dragover'].forEach((type) => {
				picker.addEventListener(type, (event) => {
					event.preventDefault();
					if (!pickerDisabled) picker.classList.add('is-dragover');
				});
			});
			['dragleave', 'drop'].forEach((type) => {
				picker.addEventListener(type, (event) => {
					event.preventDefault();
					picker.classList.remove('is-dragover');
				});
			});
			picker.addEventListener('drop', (event) => {
				if (pickerDisabled || !event.dataTransfer || !event.dataTransfer.files) return;
				queueFiles(Array.from(event.dataTransfer.files));
			});
			fileInput.addEventListener('change', () => {
				if (!fileInput.files || fileInput.files.length === 0) return;
				queueFiles(Array.from(fileInput.files));
				fileInput.value = '';
			});
		}

		async function loadAttachments() {
			const seq = ++loadSeq;
			if (!state.transactionId) {
				state.items = [];
				state.loading = false;
				renderGrid();
				buildPicker();
				syncIntro();
				return;
			}
			state.loading = true;
			renderGrid();
			try {
				const data = await Api.get('/apps/budgetcheck/api/transactions/' + state.transactionId + '/attachments');
				if (seq !== loadSeq || destroyed) return;
				state.items = data.attachments || [];
				setStatus('', false);
			} catch (err) {
				if (seq !== loadSeq || destroyed) return;
				setStatus(err.message || t('budgetcheck', 'Could not load attachments.'), true);
				state.items = [];
			} finally {
				if (seq !== loadSeq || destroyed) return;
				state.loading = false;
				renderGrid();
				buildPicker();
				syncIntro();
			}
		}

		function queueFiles(files) {
			if (state.readOnly || state.uploading || !files.length) return;
			let added = 0;
			let skippedDuplicate = false;
			let firstError = '';
			let count = totalFileCount();
			let bytes = totalByteSize();
			const existingPending = new Set(state.pendingItems.map((item) => item.dedupeKey));

			for (const file of files) {
				const validation = validateClientFile(file, count, bytes);
				if (!validation.ok) {
					if (!firstError) firstError = validation.message || '';
					continue;
				}
				const dedupeKey = pendingDedupeKey(file);
				if (existingPending.has(dedupeKey)) {
					skippedDuplicate = true;
					continue;
				}
				const pending = createPendingItem(file);
				state.pendingItems.push(pending);
				existingPending.add(pending.dedupeKey);
				count += 1;
				bytes += Number(file.size) || 0;
				added += 1;
			}

			if (firstError) {
				setStatus(firstError, true);
			} else if (skippedDuplicate && added === 0) {
				setStatus(t('budgetcheck', 'That file is already queued.'), false);
			} else {
				setStatus('', false);
			}
			if (added > 0) {
				renderGrid();
				buildPicker();
				notifyChange();
			}
		}

		function removePending(item) {
			if (!item || !item.pendingKey || state.readOnly) return;
			const idx = state.pendingItems.findIndex((entry) => entry.pendingKey === item.pendingKey);
			if (idx < 0) return;
			revokePending(state.pendingItems[idx]);
			state.pendingItems.splice(idx, 1);
			renderGrid();
			buildPicker();
			notifyChange();
		}

		async function flushPending() {
			if (state.uploading) {
				return { ok: false, uploaded: 0, failed: state.pendingItems.length, busy: true };
			}
			if (!state.transactionId) {
				return {
					ok: state.pendingItems.length === 0,
					uploaded: 0,
					failed: state.pendingItems.length,
				};
			}
			if (state.pendingItems.length === 0) {
				return { ok: true, uploaded: 0, failed: 0 };
			}

			state.uploading = true;
			buildPicker();
			syncBusyState();
			setStatus(t('budgetcheck', 'Uploading receipts…'), false);

			let uploaded = 0;
			let failed = 0;
			let lastError = null;
			const remaining = [];
			const queue = state.pendingItems.slice();

			for (const pending of queue) {
				const formData = new FormData();
				formData.append('file', pending.file, pending.fileName);
				try {
					const data = await Api.upload(
						'/apps/budgetcheck/api/transactions/' + state.transactionId + '/attachments',
						formData,
					);
					if (data && data.attachment) {
						state.items.push(data.attachment);
						revokePending(pending);
						uploaded += 1;
					} else {
						failed += 1;
						remaining.push(pending);
					}
				} catch (err) {
					failed += 1;
					remaining.push(pending);
					lastError = err;
					const message = String(err && err.message ? err.message : '');
					if (/maximum|too many|max\s*10|limit/i.test(message)) {
						queue.slice(queue.indexOf(pending) + 1).forEach((rest) => {
							if (!remaining.includes(rest)) {
								remaining.push(rest);
								failed += 1;
							}
						});
						break;
					}
				}
			}

			state.pendingItems = remaining;
			state.uploading = false;
			renderGrid();
			buildPicker();
			syncIntro();

			if (uploaded > 0) {
				notifyChange();
			}
			if (failed === 0) {
				setStatus('', false);
			} else {
				if (lastError) {
					Msg.handleApiError(lastError, { reloadOnConflict: false });
				}
				setStatus(
					failed === 1
						? t('budgetcheck', '1 receipt could not be uploaded. Fix the issue below and save again.')
						: t('budgetcheck', '{count} receipts could not be uploaded. Fix the issue below and save again.').replace('{count}', String(failed)),
					true,
				);
			}

			return { ok: failed === 0, uploaded, failed };
		}

		async function removeAttachment(item) {
			if (!item || !item.id || state.readOnly) return;
			const ok = await C.confirmDialog({
				title: t('budgetcheck', 'Remove attachment?'),
				body: t('budgetcheck', 'This permanently deletes the file from this transaction.'),
				confirmLabel: t('budgetcheck', 'Remove'),
				danger: true,
			});
			if (!ok) return;
			try {
				await Api.del('/apps/budgetcheck/api/transaction-attachments/' + item.id);
				state.items = state.items.filter((entry) => String(entry.id) !== String(item.id));
				renderGrid();
				buildPicker();
				Msg.announce(t('budgetcheck', 'Attachment removed.'), 'success');
				notifyChange();
			} catch (err) {
				Msg.handleApiError(err, { reloadOnConflict: false });
			}
		}

		function destroy() {
			if (destroyed) return;
			destroyed = true;
			loadSeq += 1;
			state.pendingItems.forEach(revokePending);
			state.pendingItems = [];
		}

		container.appendChild(section);
		syncIntro();
		renderGrid();
		buildPicker();
		loadAttachments();

		return {
			setTransactionId(nextId, options) {
				state.transactionId = nextId || null;
				if (options && options.skipReload) {
					return;
				}
				loadAttachments();
			},
			setReadOnly(readOnly) {
				state.readOnly = !!readOnly;
				buildPicker();
				syncIntro();
				renderGrid();
			},
			setClosedMonth(closedMonth) {
				state.closedMonth = !!closedMonth;
				syncIntro();
			},
			reload: loadAttachments,
			flushPending,
			hasPending() {
				return state.pendingItems.length > 0;
			},
			getPendingCount() {
				return state.pendingItems.length;
			},
			getCount() {
				return totalFileCount();
			},
			destroy,
		};
	}

	window.BudgetCheckTransactionAttachments = {
		mountSection,
	};

})();
