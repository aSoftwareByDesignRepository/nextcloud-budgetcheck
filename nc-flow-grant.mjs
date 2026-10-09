// Drive Nextcloud Login Flow v2 browser side for the app's pending poll.
// Usage: node /tmp/nc-flow-grant.mjs <flowUrlOnLocalhost>
import { chromium } from 'playwright';

const flowUrl = process.argv[2];
const user = process.env.NC_ADMIN_USER || 'bc_atlas_e2e';
const pass = process.env.NC_ADMIN_PASS || 'BCatlas-UJ6RdIEqOJE3utw';
if (!flowUrl) { console.error('need flow url'); process.exit(2); }

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
try {
  await page.goto(flowUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  // "Connect to your account" page -> Log in link
  const loginLink = page.locator('a:has-text("Log in"), button:has-text("Log in")').first();
  if (await loginLink.count()) {
    await loginLink.click();
    await page.waitForLoadState('domcontentloaded');
  }
  // login form
  const userInput = page.locator('input#user, input[name="user"]').first();
  await userInput.waitFor({ state: 'visible', timeout: 30000 });
  await userInput.fill(user);
  await page.locator('input#password, input[name="password"]').first().fill(pass);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  // grant page -> "Grant access" button
  const grant = page.locator('button:has-text("Grant access"), input[value*="Grant"], button:has-text("Grant")').first();
  await grant.waitFor({ state: 'visible', timeout: 30000 });
  await grant.click();
  await page.waitForTimeout(2000);
  console.log('GRANT_DONE url=' + page.url());
} catch (e) {
  console.log('FLOW_FAIL ' + e.message + ' url=' + page.url());
  await page.screenshot({ path: '/tmp/nc-flow-fail.png' });
  process.exit(1);
} finally {
  await browser.close();
}
