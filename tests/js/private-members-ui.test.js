#!/usr/bin/env node
/**
 * Private workspace members UI: groups must lock when privacyMode=private.
 * Minimal DOM shim (no jsdom) — same approach as warning-recovery-dom.test.js.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function El(tag) {
	this.tagName = String(tag || 'div').toUpperCase();
	this.children = [];
	this.attributes = {};
	this.className = '';
	this.hidden = false;
	this.disabled = false;
	this.parentNode = null;
	this._classes = new Set();
}
El.prototype.setAttribute = function (k, v) {
	this.attributes[k] = String(v);
};
El.prototype.getAttribute = function (k) {
	return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null;
};
El.prototype.removeAttribute = function (k) {
	delete this.attributes[k];
};
El.prototype.classList = {
	add: function () {},
	remove: function () {},
	contains: function () {
		return false;
	},
};
El.prototype.querySelectorAll = function () {
	return [];
};
El.prototype.querySelector = function () {
	return null;
};
El.prototype.closest = function () {
	return null;
};

const settingsSrc = fs.readFileSync(path.join(ROOT, 'js', 'settings.js'), 'utf8');
const membersTpl = fs.readFileSync(
	path.join(ROOT, 'templates', 'parts', 'settings', 'members.php'),
	'utf8',
);

assert(/function syncPrivateMembersUi\s*\(/.test(settingsSrc), 'settings.js defines syncPrivateMembersUi');
assert(/data-bc-private-groups-blocked/.test(settingsSrc), 'settings.js toggles private groups callout');
assert(/is-private-locked/.test(settingsSrc), 'settings.js adds is-private-locked');
assert(/aria-disabled/.test(settingsSrc), 'settings.js sets aria-disabled on locked controls');
assert(/data-bc-private-groups-blocked/.test(membersTpl), 'members.php has private groups callout');
assert(/data-bc-group-invite/.test(membersTpl), 'members.php has group invite panel');
assert(
	/Only individual people can be members/.test(membersTpl)
		|| /groups are turned off/i.test(membersTpl),
	'members.php callout explains why groups are off'
);

// Behavioural: run extracted syncPrivateMembersUi against a tiny DOM.
const fnMatch = settingsSrc.match(/function syncPrivateMembersUi\(\) \{[\s\S]*?\n\t\}/);
assert(!!fnMatch, 'extract syncPrivateMembersUi');

const blocked = new El('p');
blocked.hidden = true;
blocked.attributes['data-bc-private-groups-blocked'] = '';
const input = new El('input');
const select = new El('select');
const button = new El('button');
const controls = [input, select, button];
controls.forEach((el) => {
	el.closest = function () {
		return null;
	};
});

const panelClasses = new Set();
const panel = new El('div');
panel.querySelectorAll = function () {
	return controls;
};
panel.querySelector = function (sel) {
	if (String(sel).includes('data-bc-private-groups-blocked')) {
		return blocked;
	}
	return null;
};
panel.classList = {
	add: (c) => panelClasses.add(c),
	remove: (c) => panelClasses.delete(c),
	contains: (c) => panelClasses.has(c),
};
panel.setAttribute = El.prototype.setAttribute.bind(panel);
panel.removeAttribute = El.prototype.removeAttribute.bind(panel);
panel.getAttribute = El.prototype.getAttribute.bind(panel);

const document = {
	querySelector: function (sel) {
		if (String(sel).includes('data-bc-group-invite') && !String(sel).includes('blocked')) {
			return panel;
		}
		if (String(sel).includes('data-bc-private-groups-blocked')) {
			return blocked;
		}
		return null;
	},
};

const Ws = { workspace: { privacyMode: 'private' }, canManage: true };
const run = new Function('document', 'Ws', fnMatch[0] + '\nreturn syncPrivateMembersUi;');
const sync = run(document, Ws);

sync();
assert(blocked.hidden === false, 'private: callout visible');
assert(panelClasses.has('is-private-locked'), 'private: panel locked class');
assert(panel.getAttribute('aria-disabled') === 'true', 'private: panel aria-disabled');
assert(input.disabled === true && select.disabled === true && button.disabled === true, 'private: controls disabled');

Ws.workspace.privacyMode = 'standard';
sync();
assert(blocked.hidden === true, 'standard: callout hidden');
assert(!panelClasses.has('is-private-locked'), 'standard: unlocked');
assert(input.disabled === false, 'standard: search enabled');

if (failures > 0) {
	process.stderr.write(failures + ' assertion(s) failed\n');
	process.exit(1);
}
process.stdout.write('private-members-ui.test.js OK\n');
