/**
 * Unit tests for web ReceiptSuggest client gates (mirrors server thresholds).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const BOOTSTRAP = fs.readFileSync(path.join(ROOT, 'js', 'common', 'bootstrap.js'), 'utf8');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'receipt-suggest.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function loadModule() {
	const sandbox = {
		console,
		document: {
			getElementById() { return null; },
			readyState: 'complete',
			addEventListener() {},
			createElement(tag) {
				return {
					tagName: String(tag).toUpperCase(),
					style: {},
					setAttribute() {},
					appendChild() {},
					addEventListener() {},
				};
			},
		},
		window: null,
		FormData: class FormData {
			append() {}
		},
		AbortController: class AbortController {
			constructor() { this.signal = { aborted: false, addEventListener() {} }; }
			abort() { this.signal.aborted = true; }
		},
		Promise,
		Object,
		Array,
		Error,
		Set,
		Number,
		String,
		Date,
		Math,
		setTimeout,
		clearTimeout,
		requestAnimationFrame(fn) { fn(); },
		t(_app, key) { return key; },
	};
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.runInNewContext(BOOTSTRAP, sandbox, { filename: 'bootstrap.js' });
	vm.runInNewContext(SRC, sandbox, { filename: 'receipt-suggest.js' });
	const RS = sandbox.window.BudgetCheck.get('ReceiptSuggest');
	assert(!!RS, 'ReceiptSuggest defined');
	assert(sandbox.window.BudgetCheckReceiptSuggest === RS, 'mirrored to window');
	return RS;
}

function baseReady() {
	return {
		status: 'ready',
		currencyCode: 'EUR',
		totalMinor: 4523,
		lines: [{ label: 'Total', amountMinor: 4523, categoryId: 12, confidence: 0.86 }],
	};
}

const RS = loadModule();

assert(RS.CONFIDENCE_SINGLE_MIN === 0.72, 'single confidence threshold');
assert(RS.CONFIDENCE_SPLIT_LINE_MIN === 0.78, 'split confidence threshold');

assert(RS.isSuggestableFile({ type: 'image/jpeg' }) === true, 'jpeg suggestable');
assert(RS.isSuggestableFile({ type: 'application/pdf' }) === true, 'pdf suggestable');
assert(RS.isSuggestableFile({ type: 'application/xml' }) === false, 'xml not suggestable');
assert(RS.isSuggestableFile(null) === false, 'null file rejected');

assert(RS.passesClientGate(baseReady(), [12], 'EUR') === true, 'happy single passes');
assert(RS.passesClientGate(baseReady(), [99], 'EUR') === false, 'foreign category rejected');
assert(RS.passesClientGate(baseReady(), [12], 'USD') === false, 'currency mismatch rejected');

{
	const low = baseReady();
	low.lines[0].confidence = 0.71;
	assert(RS.passesClientGate(low, [12], 'EUR') === false, 'below single confidence rejected');
}

{
	const split = baseReady();
	split.mode = 'split';
	split.totalMinor = 5000;
	split.lines = [
		{ label: 'A', amountMinor: 2000, categoryId: 12, confidence: 0.80 },
		{ label: 'B', amountMinor: 3000, categoryId: 13, confidence: 0.79 },
	];
	assert(RS.passesClientGate(split, [12, 13], 'EUR') === true, 'split passes');
	split.lines[1].confidence = 0.77;
	assert(RS.passesClientGate(split, [12, 13], 'EUR') === false, 'split line confidence rejected');
	split.lines[1].confidence = 0.79;
	split.totalMinor = 4999;
	assert(RS.passesClientGate(split, [12, 13], 'EUR') === false, 'split sum mismatch rejected');
}

{
	const bad = baseReady();
	bad.status = 'low_quality';
	assert(RS.passesClientGate(bad, [12], 'EUR') === false, 'low_quality rejected');
}

{
	const empty = baseReady();
	empty.lines = [];
	assert(RS.passesClientGate(empty, [12], 'EUR') === false, 'empty lines rejected');
}

{
	const zero = baseReady();
	zero.lines[0].amountMinor = 0;
	zero.totalMinor = 0;
	assert(RS.passesClientGate(zero, [12], 'EUR') === false, 'zero amount rejected');
}

assert(
	fs.readFileSync(path.join(ROOT, 'js', 'common', 'receipt-suggest.js'), 'utf8').includes('0.72'),
	'source pins single threshold literal'
);
assert(
	fs.readFileSync(path.join(ROOT, 'lib', 'Controller', 'PageController.php'), 'utf8').includes('common/receipt-suggest'),
	'PageController registers receipt-suggest script'
);
assert(
	fs.readFileSync(path.join(ROOT, 'js', 'common', 'bootstrap.js'), 'utf8').includes("ReceiptSuggest: 'BudgetCheckReceiptSuggest'"),
	'bootstrap REGISTRY includes ReceiptSuggest'
);
assert(
	fs.readFileSync(path.join(ROOT, 'js', 'common', 'transaction-editor.js'), 'utf8').includes('onPendingQueued'),
	'transaction-editor hooks onPendingQueued'
);
assert(
	fs.readFileSync(path.join(ROOT, 'js', 'common', 'transaction-attachments.js'), 'utf8').includes('clearPending'),
	'attachments exposes clearPending for accept path'
);

{
	const src = fs.readFileSync(path.join(ROOT, 'js', 'common', 'receipt-suggest.js'), 'utf8');
	assert(src.includes("window.__BC_E2E__ !== true"), 'forceCapability gated on __BC_E2E__');
	assert(src.includes("role: 'dialog'"), 'overlay uses dialog role');
	assert(src.includes('aria-modal'), 'overlay marks aria-modal');
	assert(src.includes('accepting'), 'overlay tracks accepting to block cancel');
}

if (failures > 0) {
	process.stderr.write(failures + ' receipt-suggest test(s) failed\n');
	process.exit(1);
}
process.stdout.write('receipt-suggest.test.js OK\n');
