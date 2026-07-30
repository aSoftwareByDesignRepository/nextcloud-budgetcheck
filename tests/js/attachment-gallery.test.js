'use strict';

/**
 * Attachment gallery pure helpers + rotate-before-crop contract.
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
	OC: { L10N: { translate: (_app, s) => s } },
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
sandbox.ResizeObserver = function ResizeObserver() { this.observe = () => {}; this.disconnect = () => {}; };
sandbox.requestAnimationFrame = (cb) => cb();

vm.runInNewContext(bootstrap + '\n' + gallerySrc, sandbox);

const Gallery = sandbox.window.BudgetCheck.get('AttachmentGallery');
assert(!!Gallery, 'AttachmentGallery registered');
assert(typeof Gallery.normalizeRotationSteps === 'function', 'normalizeRotationSteps exported');
assert(Gallery.normalizeRotationSteps(5) === 1, 'rotation wraps to 0..3');
assert(Gallery.normalizeRotationSteps(-1) === 3, 'negative rotation wraps');
assert(typeof Gallery.exportEditedImage === 'function', 'exportEditedImage exported');
assert(typeof Gallery.cropRectToNatural === 'function', 'cropRectToNatural exported');

assert(
	gallerySrc.includes('bakeRotationIntoImage'),
	'crop path must bake rotation before crop mapping'
);
assert(
	gallerySrc.includes('confirmDiscardDirty'),
	'gallery must confirm before discarding dirty edits'
);
assert(
	gallerySrc.includes('close(result, force)'),
	'close must support force to skip discard prompt on internal teardown'
);

const attachmentsSrc = fs.readFileSync(path.join(ROOT, 'js', 'common', 'transaction-attachments.js'), 'utf8');
assert(
	attachmentsSrc.includes('state.items.length + state.pendingItems.length'),
	'attachment count notifications must include pending files'
);

if (failures > 0) {
	process.stderr.write(failures + ' attachment-gallery test(s) failed\n');
	process.exit(1);
}
process.stdout.write('attachment-gallery.test.js OK\n');
