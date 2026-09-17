'use strict';

/**
 * Regression: attachment View/Download must not double-prefix /index.php/
 * when hydrate() URLs already come from linkToRoute (GitHub #20).
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
let failures = 0;

function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

const bootstrap = fs.readFileSync(path.join(ROOT, 'js', 'common', 'bootstrap.js'), 'utf8');
const gallerySrc = fs.readFileSync(path.join(ROOT, 'js', 'common', 'attachment-gallery.js'), 'utf8');

function loadGallery(generateUrlImpl) {
	const sandbox = {
		window: {},
		document: {
			createElement: () => ({
				style: {},
				setAttribute() {},
				appendChild() {},
				addEventListener() {},
				querySelectorAll: () => [],
			}),
			body: { appendChild() {}, classList: { add() {}, remove() {} } },
			addEventListener() {},
		},
		OC: {
			L10N: { translate: (_app, s) => s },
			generateUrl: generateUrlImpl,
		},
		t: (_app, s) => s,
		console,
	};
	sandbox.window = sandbox;
	sandbox.HTMLElement = function HTMLElement() {};
	sandbox.Image = function Image() {};
	sandbox.URL = { createObjectURL: () => 'blob:test', revokeObjectURL() {} };
	sandbox.FormData = function FormData() {};
	sandbox.File = function File() {};
	sandbox.Blob = function Blob() {};
	sandbox.ResizeObserver = function ResizeObserver() {
		this.observe = () => {};
		this.disconnect = () => {};
	};
	sandbox.requestAnimationFrame = (cb) => cb();
	vm.runInNewContext(bootstrap + '\n' + gallerySrc, sandbox);
	return sandbox.window.BudgetCheck.get('AttachmentGallery');
}

/** Mimic Nextcloud without pretty URLs: prepend /index.php to app paths. */
function generateUrlNoPretty(p) {
	const pathOnly = String(p || '');
	if (pathOnly.startsWith('/index.php/')) {
		return pathOnly;
	}
	if (pathOnly.startsWith('/')) {
		return '/index.php' + pathOnly;
	}
	return '/index.php/' + pathOnly;
}

const calls = [];
const Gallery = loadGallery((p) => {
	calls.push(p);
	return generateUrlNoPretty(p);
});

assert(typeof Gallery.resolveMediaUrl === 'function', 'resolveMediaUrl exported');

// Bare app path (API client style) → generateUrl once.
calls.length = 0;
assert(
	Gallery.resolveMediaUrl('/apps/budgetcheck/api/transaction-attachments/42?inline=1')
		=== '/index.php/apps/budgetcheck/api/transaction-attachments/42?inline=1',
	'bare app path gets a single /index.php prefix with query preserved'
);
assert(calls.length === 1, 'generateUrl called once for bare app path');
assert(
	calls[0] === '/apps/budgetcheck/api/transaction-attachments/42',
	'query stripped before generateUrl'
);

// linkToRoute output on non-pretty installs — the #20 failure mode.
calls.length = 0;
const alreadyIndexed = '/index.php/apps/budgetcheck/api/transaction-attachments/7?inline=1';
assert(
	Gallery.resolveMediaUrl(alreadyIndexed) === alreadyIndexed,
	'already /index.php/-prefixed linkToRoute URL must pass through unchanged'
);
assert(calls.length === 0, 'must not call generateUrl on already-prefixed paths (avoids /index.php/index.php/)');

calls.length = 0;
const downloadOnly = '/index.php/apps/budgetcheck/api/transaction-attachments/7';
assert(
	Gallery.resolveMediaUrl(downloadOnly) === downloadOnly,
	'downloadUrl without query also passes through'
);
assert(calls.length === 0, 'no generateUrl for download-only indexed path');

// Subdirectory webroot (pretty or not).
calls.length = 0;
const subdir = '/nextcloud/apps/budgetcheck/api/transaction-attachments/9?inline=1';
assert(
	Gallery.resolveMediaUrl(subdir) === subdir,
	'subdirectory webroot + app path must not be re-prefixed'
);
assert(calls.length === 0, 'no generateUrl for subdirectory-prefixed app path');

calls.length = 0;
const subdirIndexed = '/nextcloud/index.php/apps/budgetcheck/api/transaction-attachments/9';
assert(
	Gallery.resolveMediaUrl(subdirIndexed) === subdirIndexed,
	'subdirectory + /index.php/ must pass through'
);
assert(calls.length === 0, 'no generateUrl for subdir+/index.php paths');

// Absolute / blob / empty.
calls.length = 0;
assert(
	Gallery.resolveMediaUrl('https://cloud.example/index.php/apps/budgetcheck/api/x')
		=== 'https://cloud.example/index.php/apps/budgetcheck/api/x',
	'absolute https URL unchanged'
);
assert(
	Gallery.resolveMediaUrl('blob:http://localhost/abc') === 'blob:http://localhost/abc',
	'blob URL unchanged'
);
assert(Gallery.resolveMediaUrl('') === '', 'empty string → empty');
assert(Gallery.resolveMediaUrl(null) === '', 'null → empty');
assert(calls.length === 0, 'absolute/blob/empty never call generateUrl');

// Source-level guard (mutation bait).
assert(
	gallerySrc.includes("path.includes(appMarker) && !path.startsWith(appMarker)"),
	'source must keep the already-normalized app-path guard'
);
assert(
	gallerySrc.includes("url.startsWith('//')"),
	'source must treat protocol-relative URLs as absolute'
);

if (failures > 0) {
	process.stderr.write(failures + ' attachment-resolve-media-url test(s) failed\n');
	process.exit(1);
}
process.stdout.write('attachment-resolve-media-url.test.js OK\n');
