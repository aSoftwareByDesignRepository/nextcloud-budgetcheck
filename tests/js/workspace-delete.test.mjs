/**
 * Source contracts for workspace delete UI wiring (issue #19).
 * Run: node --test tests/js/workspace-delete.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const settingsJs = fs.readFileSync(path.join(root, 'js/settings.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'templates/parts/settings/workspace.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'css/app.css'), 'utf8');

test('settings wires typed-name delete with impact preview', () => {
	assert.match(settingsJs, /function wireWorkspaceDelete\(/);
	assert.match(settingsJs, /delete-impact/);
	assert.match(settingsJs, /confirmName/);
	assert.match(settingsJs, /Api\.del\('\/apps\/budgetcheck\/api\/workspaces\/' \+ workspaceId, \{ confirmName \}\)/);
	assert.match(settingsJs, /\/apps\/budgetcheck\/workspaces/);
	assert.match(settingsJs, /livePrimary\.disabled = input\.value\.trim\(\) !== workspaceName/);
});

test('workspace template exposes manager-only danger zone', () => {
	assert.match(template, /data-bc-workspace-delete-zone/);
	assert.match(template, /data-bc-workspace-delete/);
	assert.match(template, /InvoiceCheck invoices that used expenses/);
	assert.ok(template.indexOf('canManage') !== -1);
});

test('danger zone styles exist', () => {
	assert.match(css, /\.bc-danger-zone\b/);
	assert.match(css, /\.bc-workspace-delete-confirm__name\b/);
});
