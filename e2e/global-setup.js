'use strict';

/**
 * Optional: log in once and write Playwright storage state when credentials are set.
 * E2E_USER + E2E_PASSWORD (or E2E_PASS) → .auth/storage-state.json
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

module.exports = async function globalSetup() {
	const agentUser = process.env.E2E_USER;
	const agentPass = process.env.E2E_PASSWORD || process.env.E2E_PASS;
	if (!agentUser || !agentPass) {
		return;
	}

	const base = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
	const loginUrl = process.env.E2E_LOGIN_URL || `${base}/index.php/login`;
	const outputPath = process.env.E2E_STORAGE_STATE
		? path.resolve(process.env.E2E_STORAGE_STATE)
		: path.join(__dirname, '..', '.auth', 'storage-state.json');

	fs.mkdirSync(path.dirname(outputPath), { recursive: true });

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });

	const accountField = page.locator('input#user, input[name="user"]').first();
	const passwordField = page.locator('input#password, input[name="password"]').first();
	try {
		await accountField.waitFor({ state: 'visible', timeout: 30_000 });
	} catch (err) {
		await browser.close();
		// Credentials were provided — fail hard so CI cannot false-green.
		throw new Error('[budgetcheck:e2e] global-setup: login form not ready with E2E_USER set');
	}
	await accountField.fill(agentUser);
	await passwordField.fill(agentPass);
	await page.locator('button[type="submit"], input[type="submit"]').first().click();
	try {
		await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 });
	} catch {
		await browser.close();
		throw new Error('[budgetcheck:e2e] global-setup: login failed with E2E_USER set');
	}
	await context.storageState({ path: outputPath });
	await browser.close();
	console.log(`[budgetcheck:e2e] storage state written: ${outputPath}`);
	process.env.E2E_STORAGE_STATE = outputPath;
};
