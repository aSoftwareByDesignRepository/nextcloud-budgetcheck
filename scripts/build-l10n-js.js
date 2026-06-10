#!/usr/bin/env node
/**
 * Emits l10n/{lang}.js from l10n/{lang}.json so Nextcloud can load JS
 * translations via OC.L10N.register. The plain .json files cannot be loaded
 * by t()/n() at runtime; .js files are required.
 *
 * Important (CSP / Nextcloud ≥26): register() from @nextcloud/l10n only takes
 * (appId, translations). Do NOT pass the gettext pluralForm string as a third
 * argument — legacy shims compiled it with new Function(), which violates
 * strict script-src (nonce) policies without 'unsafe-eval'. Plural rules for
 * t()/n() come from getPlural() inside @nextcloud/l10n instead.
 */
const fs = require('fs');
const path = require('path');

const APP_ID = 'budgetcheck';
const l10nDir = path.join(__dirname, '..', 'l10n');

function buildJs(lang) {
	const jsonPath = path.join(l10nDir, `${lang}.json`);
	const data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
	const translations = data.translations || {};
	const lines = ['OC.L10N.register(', `    "${APP_ID}",`, '    {'];
	const entries = Object.entries(translations);
	entries.forEach(([key, value], i) => {
		const comma = i < entries.length - 1 ? ',' : '';
		if (Array.isArray(value)) {
			const parts = value.map((v) => JSON.stringify(v)).join(', ');
			lines.push(`    ${JSON.stringify(key)} : [${parts}]${comma}`);
		} else {
			lines.push(`    ${JSON.stringify(key)} : ${JSON.stringify(value)}${comma}`);
		}
	});
	lines.push('});');
	lines.push('');
	fs.writeFileSync(path.join(l10nDir, `${lang}.js`), lines.join('\n'));
}

for (const lang of ['de', 'en', 'fr', 'es', 'da']) {
	buildJs(lang);
}
console.log(`Wrote ${APP_ID} l10n/*.js`);
