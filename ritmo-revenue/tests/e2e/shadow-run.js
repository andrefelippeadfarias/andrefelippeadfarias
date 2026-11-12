/**
 * End-to-end check of milestone M1 on the throwaway site of bin/wp-test-site.sh (run it through
 * bin/e2e.sh): log in, set the connections, open the dashboard, run the shadow run, wait for it,
 * check that "what would be written" appears, take screenshots, and prove through the
 * simulator's log (GET /__log) that nothing but reads reached the APIs.
 *
 * Environment:
 *   RITMO_E2E_PASS               "setup" (connections, setup and diagnostics too) or "rerun" (shadow run only)
 *   RITMO_PRICING_CALENDAR_READ  "1" when the site allows the pricing calendar read, the one POST allowed
 *   RITMO_E2E_SCREENS            folder of the screenshots
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const SITE = 'http://127.0.0.1:8090';
const API = 'http://127.0.0.1:8091';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const CALENDAR_READ_PATH = '/pricelabs/v1/listing_prices';
const SECRETS = ['sim-refresh-token-0001', 'sim-pricelabs-key-0001'];

const pass = process.env.RITMO_E2E_PASS || 'setup';
const calendarRead = process.env.RITMO_PRICING_CALENDAR_READ === '1';
const screens = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const shots = [];

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function simulatorRequests() {
    const response = await fetch(API + '/__log');
    const body = await response.json();
    return body.requests.filter((request) => !request.path.startsWith('/__'));
}

async function shot(page, name) {
    const file = path.join(screens, name);
    await page.screenshot({ path: file, fullPage: true });
    shots.push(file);
}

async function submitAndWait(page, selector) {
    await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click(selector)]);
}

async function rest(page, route, nonce) {
    return page.evaluate(async ([url, header]) => {
        const response = await fetch(url, { headers: header === null ? {} : { 'X-WP-Nonce': header }, credentials: 'same-origin' });
        return { status: response.status, body: await response.json() };
    }, ['/?rest_route=/ritmo/v1/' + route, nonce]);
}

async function connections(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-connections');
    await page.selectOption('#ritmo-beds24-kind', 'refresh');
    await page.fill('#ritmo-beds24-token', SECRETS[0]);
    await page.fill('#ritmo-beds24-properties', '901001');
    await page.fill('#ritmo-pricelabs-key', SECRETS[1]);
    await page.fill('#ritmo-pricelabs-pms', 'beds24');
    await submitAndWait(page, '#save_connections');
    check((await page.textContent('.ritmo-notice')).includes('Connections saved.'), 'the connections were not saved');
    const html = await page.content();
    check(SECRETS.every((secret) => !html.includes(secret)), 'a secret came back to the browser');
    const beds24Status = await page.textContent('[data-ritmo="beds24-secret"]');
    check(beds24Status.includes('Configured, saved on'), 'the Beds24 token status is missing: ' + beds24Status);
    check(!beds24Status.includes('0001'), 'characters of the token are shown: ' + beds24Status);

    for (const provider of ['beds24', 'pricelabs']) {
        await submitAndWait(page, '[data-ritmo="test-' + provider + '-button"]');
        const result = await page.textContent('[data-ritmo="test-' + provider + '"]');
        check(result.trim().startsWith('OK'), 'connection test of ' + provider + ' failed: ' + result.trim());
    }
    await shot(page, '01-connections.png');
}

async function setup(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-setup');
    const table = await page.textContent('[data-ritmo="properties"]');
    check(table.includes(PROPERTY) && table.includes('902001'), 'the seeded property or its mapping is missing from Setup');
    await shot(page, '02-setup.png');
}

async function shadowRun(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue&property=' + PROPERTY);
    if (pass === 'setup') {
        await shot(page, '03-dashboard-before.png');
    }
    await Promise.all([
        page.waitForNavigation({ timeout: 180000 }),
        page.click('[data-ritmo="run-shadow"]'),
    ]);
    await page.waitForSelector('[data-ritmo="run-status"]');
    const status = (await page.textContent('[data-ritmo="run-status"]')).trim();
    check(status === 'completed', 'the shadow run ended as "' + status + '"');

    const rows = await page.locator('.ritmo-would-write tbody tr').count();
    check(rows > 0, '"What would be written" is empty');
    const total = (await page.textContent('[data-ritmo="would-write-total"]')).trim();
    check(new RegExp('^' + rows + ' of \\d+ room nights shown').test(total), 'the count of room nights is wrong: ' + total);
    const why = (await page.locator('.ritmo-would-write tbody tr .ritmo-why').first().textContent()).trim();
    check(why.includes('Proposed step'), 'the reason of an item is not explained: ' + why);
    check((await page.textContent('[data-ritmo="last-run"]')).includes('0 writes sent'), 'the run does not report zero writes');
    await shot(page, pass === 'setup' ? '04-dashboard-after-shadow-run.png' : '06-dashboard-with-calendar-read.png');
    return rows;
}

async function restApi(page, browserErrors) {
    const nonce = await page.getAttribute('[data-ritmo="run-shadow"]', 'data-rest-nonce');
    const expectedFailures = browserErrors.length;
    const refused = await rest(page, 'status', null);
    check(refused.status === 401, 'GET /status without a nonce answered ' + refused.status);
    const unknown = await rest(page, 'properties/nowhere/occupancy', nonce);
    check(unknown.status === 404, 'an unknown property answered ' + unknown.status);
    // The browser logs the two refusals above as failed loads; they are expected.
    browserErrors.splice(expectedFailures);
    const status = await rest(page, 'status', nonce);
    check(status.status === 200 && status.body.db_up_to_date === true, 'GET /status failed');
    const lastRun = status.body.properties.find((p) => p.id === PROPERTY).last_run;
    check(lastRun.status === 'completed', 'GET /status does not show the completed run');
    const occupancy = await rest(page, 'properties/' + PROPERTY + '/occupancy', nonce);
    check(occupancy.status === 200 && occupancy.body.signal !== 'unknown' && occupancy.body.rooms.STD !== undefined, 'GET /occupancy failed');
    const run = await rest(page, 'runs/' + lastRun.id, nonce);
    check(run.status === 200 && run.body.would_write > 0 && run.body.summary.shadow.writes_sent === 0, 'GET /runs/{id} failed');
    return { run: lastRun.id, would_write: run.body.would_write, calendar_read: run.body.summary.collect.pricing_calendar_read };
}

async function diagnostics(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-diagnostics');
    const db = (await page.textContent('[data-ritmo="db-version"]')).trim();
    check(/^(\d+) of \1$/.test(db), 'the database is not up to date: ' + db);
    check((await page.textContent('[data-ritmo="runs"]')).includes('completed'), 'Diagnostics does not list the run');
    await shot(page, '05-diagnostics.png');
}

function onlyReads(requests) {
    const writes = requests.filter((r) => r.method !== 'GET' && !(calendarRead && r.method === 'POST' && r.path === CALENDAR_READ_PATH));
    check(writes.length === 0, 'requests that are not reads reached the APIs: ' + JSON.stringify(writes));
    const posts = requests.filter((r) => r.method === 'POST');
    if (calendarRead) {
        check(posts.length > 0, 'the allowed calendar read was not made');
    } else {
        check(posts.length === 0, 'a POST reached the APIs although the calendar read is not allowed');
    }
    check(requests.some((r) => r.path.startsWith('/beds24/v2/')), 'Beds24 was not read');
    check(requests.some((r) => r.path.startsWith('/pricelabs/v1/')), 'PriceLabs was not read');
    const counts = {};
    for (const r of requests) {
        const key = r.method + ' ' + r.path;
        counts[key] = (counts[key] || 0) + 1;
    }
    return counts;
}

async function main() {
    fs.mkdirSync(screens, { recursive: true });
    await fetch(API + '/__reset', { method: 'POST' });

    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
    const page = await context.newPage();
    const browserErrors = [];
    page.on('pageerror', (error) => browserErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(message.text());
        }
    });

    try {
        await page.goto(SITE + '/wp-login.php');
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASSWORD);
        await submitAndWait(page, '#wp-submit');
        check(page.url().includes('/wp-admin'), 'login failed');

        if (pass === 'setup') {
            await connections(page);
            await setup(page);
        }
        const rows = await shadowRun(page);
        const api = await restApi(page, browserErrors);
        check(api.calendar_read === calendarRead, 'the run did not read the pricing calendar as configured');
        if (pass === 'setup') {
            await diagnostics(page);
        }
        const counts = onlyReads(await simulatorRequests());
        check(browserErrors.length === 0, 'the browser reported errors: ' + browserErrors.join(' | '));

        console.log(JSON.stringify({ pass, calendar_read: calendarRead, would_write_rows: rows, run: api.run, would_write_items: api.would_write, requests: counts, screenshots: shots }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error('E2E FAILED: ' + error.message);
    process.exit(1);
});
