(function () {
	'use strict';

	const SVG_NS = 'http://www.w3.org/2000/svg';

	/**
	 * Lucide-style 24×24 stroke icons. Paths mirror lib/Service/IconCatalog.php where
	 * possible so PHP templates and JS widgets stay visually consistent.
	 *
	 * Each entry is an array of SVG child specs: { tag, ...attributes }.
	 */
	const ICONS = {
		x: [
			{ tag: 'path', d: 'M18 6 6 18' },
			{ tag: 'path', d: 'M6 6l12 12' },
		],
		check: [{ tag: 'path', d: 'm5 12 5 5L20 7' }],
		trash: [
			{ tag: 'path', d: 'M3 6h18' },
			{ tag: 'path', d: 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6' },
			{ tag: 'path', d: 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2' },
		],
		'chevron-left': [{ tag: 'path', d: 'm15 18-6-6 6-6' }],
		'chevron-right': [{ tag: 'path', d: 'm9 18 6-6-6-6' }],
		'zoom-in': [
			{ tag: 'circle', cx: '11', cy: '11', r: '8' },
			{ tag: 'path', d: 'm21 21-4.3-4.3' },
			{ tag: 'path', d: 'M11 8v6' },
			{ tag: 'path', d: 'M8 11h6' },
		],
		'zoom-out': [
			{ tag: 'circle', cx: '11', cy: '11', r: '8' },
			{ tag: 'path', d: 'm21 21-4.3-4.3' },
			{ tag: 'path', d: 'M8 11h6' },
		],
		'rotate-ccw': [
			{ tag: 'path', d: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8' },
			{ tag: 'path', d: 'M3 3v5h5' },
		],
		'rotate-cw': [
			{ tag: 'path', d: 'M3 12a9 9 0 1 0 3-6.7' },
			{ tag: 'path', d: 'M3 4v5h5' },
		],
		crop: [
			{ tag: 'path', d: 'M6 2v14a2 2 0 0 0 2 2h14' },
			{ tag: 'path', d: 'M18 22V8a2 2 0 0 0-2-2H2' },
		],
		'refresh-cw': [
			{ tag: 'path', d: 'M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8' },
			{ tag: 'path', d: 'M21 3v5h-5' },
		],
		download: [
			{ tag: 'path', d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' },
			{ tag: 'path', d: 'm7 10 5 5 5-5' },
			{ tag: 'path', d: 'M12 15V3' },
		],
	};

	/**
	 * @param {string} name
	 * @param {string|null} [extraClass]
	 * @returns {SVGElement|null}
	 */
	function render(name, extraClass) {
		const parts = ICONS[name];
		if (!parts) {
			return null;
		}

		const svg = document.createElementNS(SVG_NS, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '1.75');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		svg.classList.add('bc-icon');
		if (extraClass) {
			extraClass.split(/\s+/).filter(Boolean).forEach((cls) => svg.classList.add(cls));
		}

		parts.forEach((part) => {
			const el = document.createElementNS(SVG_NS, part.tag);
			Object.entries(part).forEach(([key, value]) => {
				if (key === 'tag') {
					return;
				}
				el.setAttribute(key, String(value));
			});
			svg.appendChild(el);
		});

		return svg;
	}

	window.BudgetCheckIcons = {
		render,
		has(name) {
			return Object.prototype.hasOwnProperty.call(ICONS, name);
		},
	};
})();
