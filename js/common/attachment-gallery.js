(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Icons = window.BudgetCheckIcons;

	const MIN_ZOOM = 0.25;
	const MAX_ZOOM = 6;
	const ZOOM_STEP = 0.25;
	const FIT_SCALE_CAP = 100;

	let openInstance = null;

	function uid(prefix) {
		return prefix + '-' + Math.random().toString(36).slice(2);
	}

	function escapeHtml(text) {
		return String(text || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function clamp(value, min, max) {
		return Math.min(max, Math.max(min, value));
	}

	function normalizeRotationSteps(steps) {
		return ((steps % 4) + 4) % 4;
	}

	function resolveMediaUrl(url) {
		if (!url || url.startsWith('blob:') || /^https?:\/\//i.test(url)) {
			return url || '';
		}
		const path = url.startsWith('/') ? url : '/' + url;
		if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
			const q = path.indexOf('?');
			if (q >= 0) {
				return OC.generateUrl(path.slice(0, q)) + path.slice(q);
			}
			return OC.generateUrl(path);
		}
		return path;
	}

	function focusables(root) {
		if (!root) return [];
		return Array.from(root.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
		)).filter((el) => !el.hidden && el.getAttribute('aria-hidden') !== 'true');
	}

	function cropRectToNatural(imgEl, cropRect) {
		const naturalW = imgEl.naturalWidth;
		const naturalH = imgEl.naturalHeight;
		if (!naturalW || !naturalH) {
			return null;
		}
		const box = imgEl.getBoundingClientRect();
		const scale = Math.min(box.width / naturalW, box.height / naturalH);
		if (!Number.isFinite(scale) || scale <= 0) {
			return null;
		}
		const renderedW = naturalW * scale;
		const renderedH = naturalH * scale;
		const offsetX = box.left + (box.width - renderedW) / 2;
		const offsetY = box.top + (box.height - renderedH) / 2;

		const x1 = clamp((cropRect.left - offsetX) / scale, 0, naturalW);
		const y1 = clamp((cropRect.top - offsetY) / scale, 0, naturalH);
		const x2 = clamp((cropRect.right - offsetX) / scale, 0, naturalW);
		const y2 = clamp((cropRect.bottom - offsetY) / scale, 0, naturalH);

		return {
			x: Math.round(x1),
			y: Math.round(y1),
			w: Math.max(1, Math.round(x2 - x1)),
			h: Math.max(1, Math.round(y2 - y1)),
		};
	}

	function exportEditedImage(img, rotationSteps, cropNatural) {
		const steps = normalizeRotationSteps(rotationSteps);
		const srcW = img.naturalWidth;
		const srcH = img.naturalHeight;
		if (!srcW || !srcH) {
			return Promise.reject(new Error(t('budgetcheck', 'Image is not ready yet.')));
		}

		const rotW = steps % 2 === 0 ? srcW : srcH;
		const rotH = steps % 2 === 0 ? srcH : srcW;
		const canvas1 = document.createElement('canvas');
		canvas1.width = rotW;
		canvas1.height = rotH;
		const ctx1 = canvas1.getContext('2d');
		if (!ctx1) {
			return Promise.reject(new Error(t('budgetcheck', 'Could not process image.')));
		}
		ctx1.translate(rotW / 2, rotH / 2);
		ctx1.rotate(steps * Math.PI / 2);
		ctx1.drawImage(img, -srcW / 2, -srcH / 2);

		const crop = cropNatural || { x: 0, y: 0, w: rotW, h: rotH };
		const safeCrop = {
			x: clamp(Math.round(crop.x), 0, rotW - 1),
			y: clamp(Math.round(crop.y), 0, rotH - 1),
			w: clamp(Math.round(crop.w), 1, rotW),
			h: clamp(Math.round(crop.h), 1, rotH),
		};
		if (safeCrop.x + safeCrop.w > rotW) safeCrop.w = rotW - safeCrop.x;
		if (safeCrop.y + safeCrop.h > rotH) safeCrop.h = rotH - safeCrop.y;

		const canvas2 = document.createElement('canvas');
		canvas2.width = safeCrop.w;
		canvas2.height = safeCrop.h;
		const ctx2 = canvas2.getContext('2d');
		if (!ctx2) {
			return Promise.reject(new Error(t('budgetcheck', 'Could not process image.')));
		}
		ctx2.drawImage(canvas1, safeCrop.x, safeCrop.y, safeCrop.w, safeCrop.h, 0, 0, safeCrop.w, safeCrop.h);

		return new Promise((resolve, reject) => {
			canvas2.toBlob((blob) => {
				if (!blob) {
					reject(new Error(t('budgetcheck', 'Could not save image.')));
					return;
				}
				resolve(blob);
			}, 'image/jpeg', 0.92);
		});
	}

	function itemKind(item) {
		if (!item) return 'unknown';
		if (item.isPreviewable || item.isImage) return 'image';
		if (item.isPdf) return 'pdf';
		if (item.isXml) return 'xml';
		const mime = String(item.mimeType || '');
		if (mime.startsWith('image/')) return 'image';
		if (mime === 'application/pdf') return 'pdf';
		if (mime === 'text/xml' || mime === 'application/xml') return 'xml';
		return 'file';
	}

	function fileLabel(item) {
		const kind = itemKind(item);
		if (kind === 'pdf') return 'PDF';
		if (kind === 'xml') return t('budgetcheck', 'E-invoice');
		return t('budgetcheck', 'File');
	}

	/**
	 * @param {{
	 *   items: Array<object>,
	 *   startIndex?: number,
	 *   readOnly?: boolean,
	 *   onItemsChange?: function(Array, number): void,
	 *   onClose?: function(): void,
	 * }} options
	 */
	function open(options) {
		const opts = options || {};
		const items = Array.isArray(opts.items) ? opts.items.slice() : [];
		if (items.length === 0) return null;

		if (openInstance) {
			openInstance.close(false);
		}

		const previousFocus = document.activeElement;
		const underlyingModals = Array.from(document.querySelectorAll('.bc-modal'));
		underlyingModals.forEach((el) => { el.inert = true; });

		const labelId = uid('bc-attach-gallery-title');
		const statusId = uid('bc-attach-gallery-status');

		let index = clamp(Number(opts.startIndex) || 0, 0, items.length - 1);
		const readOnly = !!opts.readOnly;
		let saving = false;
		let loadingMedia = false;
		let loadingXml = false;
		let loadSeq = 0;
		const blobUrls = new Set();

		const imageState = {
			scale: 1,
			fitScale: 1,
			rotationSteps: 0,
			panX: 0,
			panY: 0,
			cropMode: false,
			dirty: false,
			dragging: false,
			resizing: false,
			panning: false,
			dragStart: null,
			cropRect: null,
		};

		let panStart = null;
		const xmlCache = new Map();

		const overlay = C.createElement('div', { class: 'bc-attach-gallery' });
		const dialog = C.createElement('div', {
			class: 'bc-attach-gallery__dialog',
			attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': labelId },
		});

		const header = C.createElement('div', { class: 'bc-attach-gallery__header' });
		const titleWrap = C.createElement('div', { class: 'bc-attach-gallery__title-wrap' });
		const titleEl = C.createElement('h2', { id: labelId, class: 'bc-attach-gallery__title' });
		const counterEl = C.createElement('p', { class: 'bc-attach-gallery__counter', attrs: { 'aria-live': 'polite' } });
		titleWrap.append(titleEl, counterEl);
		const closeBtn = iconButton({
			label: t('budgetcheck', 'Close gallery'),
			icon: 'x',
			className: 'bc-attach-gallery__close',
		});

		const stage = C.createElement('div', { class: 'bc-attach-gallery__stage' });
		const stageStatus = C.createElement('p', {
			class: 'bc-attach-gallery__stage-status',
			attrs: { role: 'status', 'aria-live': 'polite' },
		});

		const imagePanel = C.createElement('div', { class: 'bc-attach-gallery__panel bc-attach-gallery__panel--image' });
		const imageViewport = C.createElement('div', { class: 'bc-attach-gallery__viewport' });
		const imgEl = C.createElement('img', {
			class: 'bc-attach-gallery__image',
			attrs: { alt: '', decoding: 'async' },
		});
		const cropOverlay = C.createElement('div', { class: 'bc-attach-gallery__crop-overlay' });
		const cropBox = C.createElement('div', {
			class: 'bc-attach-gallery__crop-box',
			attrs: { tabindex: '0', 'aria-label': t('budgetcheck', 'Crop area. Drag to move.') },
		});
		const cropHandle = C.createElement('div', { class: 'bc-attach-gallery__crop-handle', attrs: { 'aria-hidden': 'true' } });
		cropBox.appendChild(cropHandle);
		cropOverlay.appendChild(cropBox);
		imageViewport.append(imgEl, cropOverlay);
		imagePanel.appendChild(imageViewport);

		const pdfPanel = C.createElement('div', { class: 'bc-attach-gallery__panel bc-attach-gallery__panel--pdf' });
		const pdfFrame = C.createElement('iframe', {
			class: 'bc-attach-gallery__pdf',
			attrs: { title: t('budgetcheck', 'PDF preview') },
		});
		pdfPanel.appendChild(pdfFrame);

		const xmlPanel = C.createElement('div', { class: 'bc-attach-gallery__panel bc-attach-gallery__panel--xml' });
		const xmlPre = C.createElement('pre', { class: 'bc-attach-gallery__xml-content', attrs: { tabindex: '0' } });
		xmlPanel.appendChild(xmlPre);

		const prevBtn = iconButton({
			label: t('budgetcheck', 'Previous receipt'),
			icon: 'chevron-left',
			className: 'bc-attach-gallery__nav bc-attach-gallery__nav--prev',
		});
		const nextBtn = iconButton({
			label: t('budgetcheck', 'Next receipt'),
			icon: 'chevron-right',
			className: 'bc-attach-gallery__nav bc-attach-gallery__nav--next',
		});

		stage.append(stageStatus, imagePanel, pdfPanel, xmlPanel, prevBtn, nextBtn);

		const toolbar = C.createElement('div', {
			class: 'bc-attach-gallery__toolbar',
			attrs: { role: 'toolbar', 'aria-label': t('budgetcheck', 'Receipt tools') },
		});
		const statusEl = C.createElement('p', {
			id: statusId,
			class: 'bc-attach-gallery__status bc-sr-only',
			attrs: { role: 'status', 'aria-live': 'polite' },
		});

		const btnZoomOut = iconButton({
			label: t('budgetcheck', 'Zoom out'),
			caption: t('budgetcheck', 'Zoom out'),
			hint: t('budgetcheck', 'Zoom out — scroll wheel down also works.'),
			icon: 'zoom-out',
			className: 'bc-attach-gallery__tool',
		});
		const btnZoomIn = iconButton({
			label: t('budgetcheck', 'Zoom in'),
			caption: t('budgetcheck', 'Zoom in'),
			hint: t('budgetcheck', 'Zoom in — scroll wheel up also works.'),
			icon: 'zoom-in',
			className: 'bc-attach-gallery__tool',
		});
		const btnRotateLeft = iconButton({
			label: t('budgetcheck', 'Rotate left'),
			caption: t('budgetcheck', 'Rotate left'),
			hint: t('budgetcheck', 'Rotate 90° counter-clockwise.'),
			icon: 'rotate-ccw',
			className: 'bc-attach-gallery__tool',
		});
		const btnRotateRight = iconButton({
			label: t('budgetcheck', 'Rotate right'),
			caption: t('budgetcheck', 'Rotate right'),
			hint: t('budgetcheck', 'Rotate 90° clockwise.'),
			icon: 'rotate-cw',
			className: 'bc-attach-gallery__tool',
		});
		const btnCrop = iconButton({
			label: t('budgetcheck', 'Crop'),
			caption: t('budgetcheck', 'Crop'),
			hint: t('budgetcheck', 'Draw a crop box, then save to trim the image.'),
			icon: 'crop',
			className: 'bc-attach-gallery__tool',
			toggle: true,
		});
		const btnReset = iconButton({
			label: t('budgetcheck', 'Reset view'),
			caption: t('budgetcheck', 'Reset view'),
			hint: t('budgetcheck', 'Reset zoom, rotation, and crop.'),
			icon: 'refresh-cw',
			className: 'bc-attach-gallery__tool',
		});
		const btnSave = iconButton({
			label: t('budgetcheck', 'Save changes'),
			hint: t('budgetcheck', 'Save edited image to the server.'),
			icon: 'check',
			text: t('budgetcheck', 'Save'),
			className: 'bc-attach-gallery__tool bc-attach-gallery__tool--primary',
		});
		const btnDownload = iconButton({
			label: t('budgetcheck', 'Download'),
			caption: t('budgetcheck', 'Download'),
			hint: t('budgetcheck', 'Download a copy of this file.'),
			icon: 'download',
			className: 'bc-attach-gallery__tool',
		});
		const btnDelete = iconButton({
			label: t('budgetcheck', 'Remove'),
			caption: t('budgetcheck', 'Remove'),
			hint: t('budgetcheck', 'Remove this file from the transaction.'),
			icon: 'trash',
			className: 'bc-attach-gallery__tool bc-attach-gallery__tool--danger',
		});

		const groupView = toolbarGroup(t('budgetcheck', 'View controls'), [btnZoomOut, btnZoomIn, btnReset]);
		const groupEdit = toolbarGroup(t('budgetcheck', 'Edit image'), [btnRotateLeft, btnRotateRight, btnCrop, btnSave]);
		const groupFile = toolbarGroup(t('budgetcheck', 'File actions'), [btnDownload, btnDelete]);

		toolbar.append(groupView, groupEdit, groupFile);

		header.append(titleWrap, closeBtn);
		dialog.append(header, stage, toolbar, statusEl);
		overlay.appendChild(dialog);
		document.body.appendChild(overlay);
		document.body.classList.add('bc-modal-open');

		function toolbarGroup(label, buttons) {
			const group = C.createElement('div', {
				class: 'bc-attach-gallery__tool-group',
				attrs: { role: 'group', 'aria-label': label },
			});
			const labelEl = C.createElement('p', {
				class: 'bc-attach-gallery__tool-group-label',
				text: label,
			});
			const row = C.createElement('div', { class: 'bc-attach-gallery__tool-group-row' });
			buttons.forEach((btn) => {
				if (btn) row.appendChild(btn);
			});
			group.append(labelEl, row);
			return group;
		}

		function iconButton(options) {
			const opts = options || {};
			const label = opts.label || '';
			const hint = opts.hint || label;
			const wrap = C.createElement('span', { class: 'bc-attach-gallery__tool-wrap' });
			const btn = C.createElement('button', {
				type: 'button',
				class: opts.className || 'bc-attach-gallery__tool',
				attrs: {
					'aria-label': label,
				},
			});
			if (opts.toggle) {
				btn.setAttribute('aria-pressed', 'false');
			}
			if (Icons && typeof Icons.render === 'function' && opts.icon) {
				const iconEl = Icons.render(opts.icon);
				if (iconEl) {
					const iconWrap = C.createElement('span', { class: 'bc-attach-gallery__tool-icon' });
					iconWrap.appendChild(iconEl);
					btn.appendChild(iconWrap);
				}
			}
			if (opts.text) {
				btn.appendChild(C.createElement('span', {
					class: 'bc-attach-gallery__tool-label',
					text: opts.text,
				}));
			} else if (opts.caption) {
				btn.appendChild(C.createElement('span', {
					class: 'bc-attach-gallery__tool-caption',
					text: opts.caption,
				}));
			}
			if (hint) {
				const tip = C.createElement('span', {
					class: 'bc-attach-gallery__tool-tip',
					attrs: { 'aria-hidden': 'true' },
					text: hint,
				});
				wrap.append(btn, tip);
			} else {
				wrap.appendChild(btn);
			}
			return wrap;
		}

		function toolButton(wrap) {
			return wrap ? wrap.querySelector('button') : null;
		}

		const elZoomOut = toolButton(btnZoomOut);
		const elZoomIn = toolButton(btnZoomIn);
		const elRotateLeft = toolButton(btnRotateLeft);
		const elRotateRight = toolButton(btnRotateRight);
		const elCrop = toolButton(btnCrop);
		const elReset = toolButton(btnReset);
		const elSave = toolButton(btnSave);
		const elDownload = toolButton(btnDownload);
		const elDelete = toolButton(btnDelete);
		setWrapHidden(btnSave, true);

		function setWrapHidden(wrap, hidden) {
			if (!wrap) return;
			wrap.hidden = !!hidden;
		}

		function exitCropMode() {
			if (!imageState.cropMode) return false;
			imageState.cropMode = false;
			cropOverlay.hidden = true;
			elCrop.setAttribute('aria-pressed', 'false');
			elCrop.setAttribute('aria-label', t('budgetcheck', 'Crop'));
			updateToolbarControls();
			announce(t('budgetcheck', 'Crop cancelled.'));
			return true;
		}

		function updateToolbarControls() {
			const item = currentItem();
			const kind = itemKind(item);
			const isImage = kind === 'image';
			const busy = saving || loadingMedia || loadingXml;
			const bounds = zoomBounds();
			const atMinZoom = imageState.scale <= bounds.min * 1.001;
			const atMaxZoom = imageState.scale >= bounds.max * 0.999;

			groupView.hidden = !isImage;
			groupEdit.hidden = !isImage || readOnly;
			groupFile.hidden = false;

			if (elZoomOut) elZoomOut.disabled = !isImage || busy || imageState.cropMode || atMinZoom;
			if (elZoomIn) elZoomIn.disabled = !isImage || busy || imageState.cropMode || atMaxZoom;
			if (elReset) elReset.disabled = !isImage || busy || imageState.cropMode;
			if (elRotateLeft) elRotateLeft.disabled = !isImage || readOnly || busy || imageState.cropMode;
			if (elRotateRight) elRotateRight.disabled = !isImage || readOnly || busy || imageState.cropMode;
			if (elCrop) {
				elCrop.disabled = !isImage || readOnly || busy;
				elCrop.setAttribute(
					'aria-label',
					imageState.cropMode ? t('budgetcheck', 'Exit crop mode') : t('budgetcheck', 'Crop'),
				);
			}
			if (elSave) {
				const showSave = isImage && !readOnly && imageState.dirty;
				setWrapHidden(btnSave, !showSave);
				elSave.disabled = busy || !showSave;
			}
			if (elDownload) elDownload.disabled = busy || !item;
			if (elDelete) elDelete.disabled = busy || readOnly || !item;
			prevBtn.disabled = busy || index <= 0;
			nextBtn.disabled = busy || index >= items.length - 1;
		}

		function currentItem() {
			return items[index] || null;
		}

		function announce(message) {
			statusEl.textContent = '';
			window.requestAnimationFrame(() => {
				statusEl.textContent = message;
			});
		}

		function trackBlobUrl(url) {
			if (url && url.startsWith('blob:')) {
				blobUrls.add(url);
			}
		}

		function revokeBlobUrl(url) {
			if (!url || !blobUrls.has(url)) return;
			try {
				URL.revokeObjectURL(url);
			} catch (_) { /* ignore */ }
			blobUrls.delete(url);
		}

		function setActivePanel(kind) {
			imagePanel.classList.toggle('is-active', kind === 'image');
			pdfPanel.classList.toggle('is-active', kind === 'pdf');
			xmlPanel.classList.toggle('is-active', kind === 'xml');
		}

		function zoomBounds() {
			const fit = imageState.fitScale || 1;
			return {
				min: fit * MIN_ZOOM,
				max: fit * MAX_ZOOM,
			};
		}

		function resetImageState() {
			imageState.scale = imageState.fitScale || 1;
			imageState.rotationSteps = 0;
			imageState.panX = 0;
			imageState.panY = 0;
			imageState.cropMode = false;
			imageState.dirty = false;
			imageState.cropRect = null;
			cropOverlay.hidden = true;
			elCrop.setAttribute('aria-pressed', 'false');
			elCrop.setAttribute('aria-label', t('budgetcheck', 'Crop'));
			setWrapHidden(btnSave, true);
			applyImageTransform();
		}

		function fitImageToViewport() {
			const vpW = imageViewport.clientWidth;
			const vpH = imageViewport.clientHeight;
			const iW = imgEl.naturalWidth;
			const iH = imgEl.naturalHeight;
			if (!vpW || !vpH || !iW || !iH) return;
			const fit = Math.min((vpW - 32) / iW, (vpH - 32) / iH);
			imageState.fitScale = clamp(fit, 0.01, FIT_SCALE_CAP);
			imageState.scale = imageState.fitScale;
			imageState.panX = 0;
			imageState.panY = 0;
			applyImageTransform();
			updateToolbarControls();
		}

		function applyImageTransform() {
			const rot = normalizeRotationSteps(imageState.rotationSteps);
			const px = imageState.panX;
			const py = imageState.panY;
			const s = imageState.scale;
			imgEl.style.transform = 'translate(calc(-50% + ' + px + 'px), calc(-50% + ' + py + 'px)) scale(' + s + ') rotate(' + (rot * 90) + 'deg)';
			updateToolbarControls();
		}

		function markDirty() {
			if (readOnly) return;
			imageState.dirty = true;
			updateToolbarControls();
		}

		function updateCounter() {
			counterEl.textContent = t('budgetcheck', '{current} of {total}')
				.replace('{current}', String(index + 1))
				.replace('{total}', String(items.length));
			updateToolbarControls();
		}

		function setToolbarForKind(kind) {
			updateToolbarControls();
		}

		function setStageStatus(message, isError) {
			stageStatus.textContent = message || '';
			stageStatus.classList.toggle('bc-attach-gallery__stage-status--error', !!isError);
			stageStatus.hidden = !message;
		}

		function initCropBox() {
			const vp = imageViewport.getBoundingClientRect();
			const margin = Math.min(vp.width, vp.height) * 0.1;
			imageState.cropRect = {
				left: vp.left + margin,
				top: vp.top + margin,
				right: vp.right - margin,
				bottom: vp.bottom - margin,
			};
			positionCropBox();
		}

		function positionCropBox() {
			if (!imageState.cropRect) return;
			const overlayBox = cropOverlay.getBoundingClientRect();
			const r = imageState.cropRect;
			cropBox.style.left = (r.left - overlayBox.left) + 'px';
			cropBox.style.top = (r.top - overlayBox.top) + 'px';
			cropBox.style.width = Math.max(48, r.right - r.left) + 'px';
			cropBox.style.height = Math.max(48, r.bottom - r.top) + 'px';
		}

		async function fetchBlobUrl(item) {
			if (item.isPending && item.file instanceof File) {
				const objectUrl = URL.createObjectURL(item.file);
				trackBlobUrl(objectUrl);
				return objectUrl;
			}
			const url = resolveMediaUrl(item.previewUrl || item.downloadUrl);
			if (!url) {
				throw new Error(t('budgetcheck', 'Attachment could not be loaded.'));
			}
			const response = await fetch(url, { credentials: 'same-origin' });
			if (!response.ok) {
				throw new Error(t('budgetcheck', 'Attachment could not be loaded.'));
			}
			const blob = await response.blob();
			const objectUrl = URL.createObjectURL(blob);
			trackBlobUrl(objectUrl);
			return objectUrl;
		}

		async function loadImageItem(item, seq) {
			loadingMedia = true;
			updateToolbarControls();
			setStageStatus(t('budgetcheck', 'Loading…'), false);
			imgEl.removeAttribute('src');
			imgEl.alt = item.fileName || t('budgetcheck', 'Attachment');

			try {
				const objectUrl = await fetchBlobUrl(item);
				if (seq !== loadSeq) {
					revokeBlobUrl(objectUrl);
					return;
				}
				await new Promise((resolve, reject) => {
					imgEl.onload = () => resolve();
					imgEl.onerror = () => reject(new Error(t('budgetcheck', 'Image could not be loaded.')));
					imgEl.src = objectUrl;
				});
				if (seq !== loadSeq) return;
				setStageStatus('', false);
				fitImageToViewport();
				window.requestAnimationFrame(() => {
					window.requestAnimationFrame(() => {
						if (seq === loadSeq) fitImageToViewport();
					});
				});
			} catch (err) {
				if (seq !== loadSeq) return;
				setStageStatus(err.message || t('budgetcheck', 'Image could not be loaded.'), true);
			} finally {
				if (seq === loadSeq) {
					loadingMedia = false;
					updateToolbarControls();
				}
			}
		}

		async function loadPdfItem(item, seq) {
			loadingMedia = true;
			updateToolbarControls();
			setStageStatus(t('budgetcheck', 'Loading…'), false);
			pdfFrame.removeAttribute('src');
			try {
				if (item.isPending && item.file instanceof File) {
					const objectUrl = await fetchBlobUrl(item);
					if (seq !== loadSeq) {
						revokeBlobUrl(objectUrl);
						return;
					}
					pdfFrame.src = objectUrl;
				} else {
					const url = resolveMediaUrl(item.previewUrl);
					if (!url) {
						throw new Error(t('budgetcheck', 'Attachment could not be loaded.'));
					}
					// Inline preview URL (inline=1) must be embeddable; download URLs use attachment disposition.
					pdfFrame.src = url;
				}
				pdfFrame.title = item.fileName || t('budgetcheck', 'PDF preview');
				setStageStatus('', false);
			} catch (err) {
				if (seq !== loadSeq) return;
				setStageStatus(err.message || t('budgetcheck', 'Could not load document.'), true);
			} finally {
				if (seq === loadSeq) {
					loadingMedia = false;
					updateToolbarControls();
				}
			}
		}

		async function loadXmlContent(item, seq) {
			const key = item.previewUrl || item.downloadUrl || item.fileName;
			if (xmlCache.has(key)) {
				xmlPre.textContent = xmlCache.get(key);
				setStageStatus('', false);
				return;
			}
			loadingXml = true;
			setStageStatus(t('budgetcheck', 'Loading…'), false);
			xmlPre.textContent = '';
			try {
				let text = '';
				if (item.isPending && item.file instanceof File) {
					text = await item.file.text();
				} else {
					const url = resolveMediaUrl(item.previewUrl || item.downloadUrl);
					const response = await fetch(url, {
						credentials: 'same-origin',
						headers: { Accept: 'text/xml, application/xml, text/plain, */*' },
					});
					if (!response.ok) {
						throw new Error(t('budgetcheck', 'Could not load document.'));
					}
					text = await response.text();
				}
				if (seq !== loadSeq) return;
				const safe = text.slice(0, 500000);
				xmlCache.set(key, safe + (text.length > 500000 ? '\n\n…' : ''));
				xmlPre.textContent = xmlCache.get(key);
				setStageStatus('', false);
			} catch (err) {
				if (seq !== loadSeq) return;
				setStageStatus(err.message || t('budgetcheck', 'Could not load document.'), true);
			} finally {
				loadingXml = false;
				updateToolbarControls();
			}
		}

		function renderCurrent() {
			const item = currentItem();
			if (!item) {
				instance.close(false);
				return;
			}

			const seq = ++loadSeq;
			imageState.fitScale = 1;
			resetImageState();

			const kind = itemKind(item);
			const name = item.fileName || t('budgetcheck', 'Attachment');
			titleEl.textContent = name;
			updateCounter();
			setToolbarForKind(kind);
			setActivePanel(kind);

			if (kind === 'image') {
				loadImageItem(item, seq);
			} else if (kind === 'pdf') {
				loadPdfItem(item, seq);
			} else if (kind === 'xml') {
				loadXmlContent(item, seq);
			} else {
				setStageStatus(t('budgetcheck', 'Preview is not available for this file type. Use Download.'), false);
			}

			announce(t('budgetcheck', 'Showing {name}').replace('{name}', name));
		}

		function goTo(nextIndex) {
			if (nextIndex < 0 || nextIndex >= items.length || nextIndex === index) return;
			if (saving || loadingMedia || loadingXml) return;
			index = nextIndex;
			renderCurrent();
		}

		async function saveImageEdits() {
			if (readOnly || saving || !imageState.dirty) return;
			const item = currentItem();
			if (!item || itemKind(item) !== 'image') return;

			saving = true;
			updateToolbarControls();
			announce(t('budgetcheck', 'Saving changes…'));

			try {
				await new Promise((resolve, reject) => {
					if (imgEl.complete && imgEl.naturalWidth) {
						resolve();
						return;
					}
					imgEl.onload = () => resolve();
					imgEl.onerror = () => reject(new Error(t('budgetcheck', 'Image could not be loaded.')));
				});

				let cropNatural = null;
				if (imageState.cropMode && imageState.cropRect) {
					cropNatural = cropRectToNatural(imgEl, imageState.cropRect);
				}

				const blob = await exportEditedImage(imgEl, imageState.rotationSteps, cropNatural);
				const baseName = (item.fileName || 'receipt').replace(/\.[^.]+$/, '');
				const fileName = baseName + '.jpg';

				if (item.isPending && item.file) {
					const file = new File([blob], fileName, { type: 'image/jpeg' });
					item.file = file;
					item.fileName = fileName;
					item.fileSize = file.size;
					item.mimeType = 'image/jpeg';
					item.isPreviewable = true;
					item.isImage = true;
					if (item.previewUrl) revokeBlobUrl(item.previewUrl);
					if (item.downloadUrl && item.downloadUrl !== item.previewUrl) revokeBlobUrl(item.downloadUrl);
					const objectUrl = URL.createObjectURL(file);
					trackBlobUrl(objectUrl);
					item.previewUrl = objectUrl;
					item.downloadUrl = objectUrl;
					resetImageState();
					const seq = ++loadSeq;
					await loadImageItem(item, seq);
					Msg.announce(t('budgetcheck', 'Changes saved locally. Save the transaction to upload.'), 'success');
				} else if (item.id) {
					const formData = new FormData();
					formData.append('file', blob, fileName);
					const data = await Api.upload('/apps/budgetcheck/api/transaction-attachments/' + item.id + '/replace', formData);
					if (data && data.attachment) {
						Object.assign(item, data.attachment);
					}
					resetImageState();
					const seq = ++loadSeq;
					await loadImageItem(item, seq);
					Msg.announce(t('budgetcheck', 'Receipt image saved.'), 'success');
				}

				if (typeof opts.onItemsChange === 'function') {
					opts.onItemsChange(items, items.length);
				}
			} catch (err) {
				Msg.handleApiError(err, { reloadOnConflict: false });
			} finally {
				saving = false;
				updateToolbarControls();
			}
		}

		async function deleteCurrent() {
			const item = currentItem();
			if (!item || readOnly) return;

			const ok = await C.confirmDialog({
				title: t('budgetcheck', 'Remove attachment?'),
				body: t('budgetcheck', 'This permanently deletes the file from this transaction.'),
				confirmLabel: t('budgetcheck', 'Remove'),
				danger: true,
			});
			if (!ok) return;

			try {
				if (item.isPending) {
					revokeBlobUrl(item.previewUrl);
					revokeBlobUrl(item.downloadUrl);
				} else if (item.id) {
					await Api.del('/apps/budgetcheck/api/transaction-attachments/' + item.id);
				}

				items.splice(index, 1);
				Msg.announce(t('budgetcheck', 'Attachment removed.'), 'success');

				if (typeof opts.onItemsChange === 'function') {
					opts.onItemsChange(items, items.length);
				}

				if (items.length === 0) {
					instance.close(true);
					return;
				}
				if (index >= items.length) index = items.length - 1;
				renderCurrent();
			} catch (err) {
				Msg.handleApiError(err, { reloadOnConflict: false });
			}
		}

		function downloadCurrent() {
			const item = currentItem();
			if (!item) return;
			const url = resolveMediaUrl(item.downloadUrl || item.previewUrl);
			if (!url) return;
			const a = document.createElement('a');
			a.href = url;
			a.download = item.fileName || 'attachment';
			a.rel = 'noopener noreferrer';
			if (!item.isPending) {
				a.target = '_blank';
			}
			document.body.appendChild(a);
			a.click();
			a.remove();
		}

		elZoomIn.addEventListener('click', () => {
			const bounds = zoomBounds();
			imageState.scale = clamp(imageState.scale + ZOOM_STEP * imageState.fitScale, bounds.min, bounds.max);
			applyImageTransform();
		});
		elZoomOut.addEventListener('click', () => {
			const bounds = zoomBounds();
			imageState.scale = clamp(imageState.scale - ZOOM_STEP * imageState.fitScale, bounds.min, bounds.max);
			applyImageTransform();
		});
		elRotateLeft.addEventListener('click', () => {
			imageState.rotationSteps -= 1;
			applyImageTransform();
			markDirty();
		});
		elRotateRight.addEventListener('click', () => {
			imageState.rotationSteps += 1;
			applyImageTransform();
			markDirty();
		});
		elReset.addEventListener('click', () => {
			resetImageState();
			fitImageToViewport();
		});
		elCrop.addEventListener('click', () => {
			if (imageState.cropMode) {
				exitCropMode();
				return;
			}
			imageState.cropMode = true;
			elCrop.setAttribute('aria-pressed', 'true');
			cropOverlay.hidden = false;
			imageState.panX = 0;
			imageState.panY = 0;
			imageState.rotationSteps = normalizeRotationSteps(imageState.rotationSteps);
			fitImageToViewport();
			window.requestAnimationFrame(() => initCropBox());
			markDirty();
			updateToolbarControls();
		});
		elSave.addEventListener('click', () => saveImageEdits());
		elDownload.addEventListener('click', () => downloadCurrent());
		elDelete.addEventListener('click', () => deleteCurrent());
		prevBtn.addEventListener('click', () => goTo(index - 1));
		nextBtn.addEventListener('click', () => goTo(index + 1));
		closeBtn.addEventListener('click', () => instance.close(false));

		imageViewport.addEventListener('wheel', (event) => {
			if (itemKind(currentItem()) !== 'image' || imageState.cropMode) return;
			event.preventDefault();
			const delta = event.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP;
			const bounds = zoomBounds();
			imageState.scale = clamp(imageState.scale + delta * imageState.fitScale, bounds.min, bounds.max);
			applyImageTransform();
		}, { passive: false });

		imageViewport.addEventListener('pointerdown', (event) => {
			const bounds = zoomBounds();
			if (itemKind(currentItem()) !== 'image' || imageState.cropMode || imageState.scale <= bounds.min * 1.01) return;
			imageState.panning = true;
			panStart = { x: event.clientX - imageState.panX, y: event.clientY - imageState.panY };
			imageViewport.setPointerCapture(event.pointerId);
		});
		imageViewport.addEventListener('pointermove', (event) => {
			if (!imageState.panning || !panStart) return;
			imageState.panX = event.clientX - panStart.x;
			imageState.panY = event.clientY - panStart.y;
			applyImageTransform();
		});
		imageViewport.addEventListener('pointerup', (event) => {
			imageState.panning = false;
			panStart = null;
			try { imageViewport.releasePointerCapture(event.pointerId); } catch (_) { /* ignore */ }
		});

		cropBox.addEventListener('pointerdown', (event) => {
			if (!imageState.cropMode || !imageState.cropRect || event.target === cropHandle) return;
			event.stopPropagation();
			imageState.dragging = true;
			imageState.dragStart = {
				x: event.clientX,
				y: event.clientY,
				rect: Object.assign({}, imageState.cropRect),
			};
			cropBox.setPointerCapture(event.pointerId);
		});
		cropBox.addEventListener('pointermove', (event) => {
			if (!imageState.dragging || !imageState.dragStart) return;
			const dx = event.clientX - imageState.dragStart.x;
			const dy = event.clientY - imageState.dragStart.y;
			const vp = imageViewport.getBoundingClientRect();
			const w = imageState.dragStart.rect.right - imageState.dragStart.rect.left;
			const h = imageState.dragStart.rect.bottom - imageState.dragStart.rect.top;
			let left = imageState.dragStart.rect.left + dx;
			let top = imageState.dragStart.rect.top + dy;
			left = clamp(left, vp.left, vp.right - w);
			top = clamp(top, vp.top, vp.bottom - h);
			imageState.cropRect = { left, top, right: left + w, bottom: top + h };
			positionCropBox();
		});
		cropBox.addEventListener('pointerup', (event) => {
			imageState.dragging = false;
			imageState.dragStart = null;
			try { cropBox.releasePointerCapture(event.pointerId); } catch (_) { /* ignore */ }
		});

		cropHandle.addEventListener('pointerdown', (event) => {
			if (!imageState.cropMode || !imageState.cropRect) return;
			event.stopPropagation();
			imageState.resizing = true;
			imageState.dragStart = {
				x: event.clientX,
				y: event.clientY,
				rect: Object.assign({}, imageState.cropRect),
			};
			cropHandle.setPointerCapture(event.pointerId);
		});
		cropHandle.addEventListener('pointermove', (event) => {
			if (!imageState.resizing || !imageState.dragStart) return;
			const vp = imageViewport.getBoundingClientRect();
			const minSize = 48;
			const dx = event.clientX - imageState.dragStart.x;
			const dy = event.clientY - imageState.dragStart.y;
			let right = imageState.dragStart.rect.right + dx;
			let bottom = imageState.dragStart.rect.bottom + dy;
			right = clamp(right, imageState.dragStart.rect.left + minSize, vp.right);
			bottom = clamp(bottom, imageState.dragStart.rect.top + minSize, vp.bottom);
			imageState.cropRect = {
				left: imageState.dragStart.rect.left,
				top: imageState.dragStart.rect.top,
				right,
				bottom,
			};
			positionCropBox();
		});
		cropHandle.addEventListener('pointerup', (event) => {
			imageState.resizing = false;
			imageState.dragStart = null;
			try { cropHandle.releasePointerCapture(event.pointerId); } catch (_) { /* ignore */ }
		});

		const onKey = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				if (exitCropMode()) {
					return;
				}
				instance.close(false);
				return;
			}
			if (loadingXml || saving) return;
			if (event.key === 'ArrowLeft') {
				event.preventDefault();
				goTo(index - 1);
			} else if (event.key === 'ArrowRight') {
				event.preventDefault();
				goTo(index + 1);
			} else if (event.key === '+' || event.key === '=') {
				if (itemKind(currentItem()) === 'image') {
					event.preventDefault();
					const bounds = zoomBounds();
					imageState.scale = clamp(imageState.scale + ZOOM_STEP * imageState.fitScale, bounds.min, bounds.max);
					applyImageTransform();
				}
			} else if (event.key === '-') {
				if (itemKind(currentItem()) === 'image') {
					event.preventDefault();
					const bounds = zoomBounds();
					imageState.scale = clamp(imageState.scale - ZOOM_STEP * imageState.fitScale, bounds.min, bounds.max);
					applyImageTransform();
				}
			} else if (event.key === 'Tab') {
				const list = focusables(dialog);
				if (list.length === 0) return;
				const first = list[0];
				const last = list[list.length - 1];
				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			}
		};
		dialog.addEventListener('keydown', onKey);

		overlay.addEventListener('click', (event) => {
			if (event.target === overlay) instance.close(false);
		});

		let viewportResizeObs = null;
		if (typeof ResizeObserver !== 'undefined') {
			viewportResizeObs = new ResizeObserver(() => {
				if (itemKind(currentItem()) !== 'image' || imageState.cropMode || !imgEl.naturalWidth) {
					return;
				}
				if (imageState.panX !== 0 || imageState.panY !== 0) {
					return;
				}
				if (Math.abs(imageState.scale - imageState.fitScale) > 0.001) {
					return;
				}
				fitImageToViewport();
			});
			viewportResizeObs.observe(imageViewport);
		}

		const instance = {
			close(result) {
				if (!instance._open) return;
				instance._open = false;
				loadSeq += 1;
				if (viewportResizeObs) {
					viewportResizeObs.disconnect();
					viewportResizeObs = null;
				}
				dialog.removeEventListener('keydown', onKey);
				pdfFrame.removeAttribute('src');
				imgEl.removeAttribute('src');
				blobUrls.forEach((url) => revokeBlobUrl(url));
				underlyingModals.forEach((el) => { el.inert = false; });
				if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
				document.body.classList.remove('bc-modal-open');
				openInstance = null;
				if (previousFocus && typeof previousFocus.focus === 'function') {
					try { previousFocus.focus(); } catch (_) { /* ignore */ }
				}
				if (typeof opts.onClose === 'function') opts.onClose(result);
			},
			_open: true,
		};
		openInstance = instance;

		renderCurrent();
		closeBtn.focus();
		return instance;
	}

	window.BudgetCheckAttachmentGallery = {
		open,
		itemKind,
		fileLabel,
		resolveMediaUrl,
	};
})();
