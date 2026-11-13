/**
 * End-to-end check of milestone M3 on the throwaway site (run it through bin/e2e.sh, after the
 * shadow passes): on the Operations screen, switch the seeded property to approval, "Run now" and
 * see the plan wait with nothing written; approve everything, let the scheduler run (wp-cron.php)
 * and see the approved writes reach the simulator, only on the write routes of the pricing system
 * (never a price or a booking to the PMS); then engage the emergency switch on the screen and see
 * that "Run now" sends nothing. Proved through the simulator's own log (GET /__log).
 *
 * Environment:
 *   RITMO_E2E_SCREENS   folder of the screenshots
 */
'use strict';

const path = require('path');
const { chromium } = require('playwright');

const SITE = 'http://127.0.0.1:8090';
const API = 'http://127.0.0.1:8091';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const READ_POST = '/pricelabs/v1/listing_prices';
const WRITE_ROUTES = [/^\/pricelabs\/v1\/listings\/[^/]+\/overrides$/, /^\/pricelabs\/v1\/refresh_listing$/];

const screens = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const shots = [];

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function writesInLog() {
    const response = await fetch(API + '/__log');
    const body = await response.json();
    return body.requests.filter((r) => !r.path.startsWith('/__') && r.method !== 'GET' && !(r.method === 'POST' && r.path === READ_POST));
}

async function shot(page, name) {
    const file = path.join(screens, name);
    await page.screenshot({ path: file, fullPage: true });
    shots.push(file);
}

async function submitAndWait(page, selector) {
    await Promise.all([page.waitForNavigation({ timeout: 180000 }), page.click(selector)]);
}

async function restCall(page, method, route, nonce) {
    return page.evaluate(async ([verb, url, header]) => {
        const response = await fetch(url, { method: verb, headers: header === null ? {} : { 'X-WP-Nonce': header }, credentials: 'same-origin' });
        return { status: response.status, body: await response.json() };
    }, [method, '/?rest_route=/ritmo/v1/' + route, nonce]);
}

async function restPost(page, route, nonce, body) {
    return page.evaluate(async ([url, header, payload]) => {
        const response = await fetch(url, { method: 'POST', headers: { 'X-WP-Nonce': header, 'Content-Type': 'application/json' }, body: JSON.stringify(payload), credentials: 'same-origin' });
        return { status: response.status, body: await response.json() };
    }, ['/?rest_route=/ritmo/v1/' + route, nonce, body]);
}

/**
 * The REST routes of the operation: the request waiting is listed, a change of state without
 * the nonce is refused, and a malformed decision or switch position is a 400 that changes nothing
 * (a refusal typed wrong never approves; a request without a clear position never releases the
 * switch).
 */
async function restChecks(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue&property=' + PROPERTY);
    const nonce = await page.getAttribute('[data-ritmo="run-shadow"]', 'data-rest-nonce');
    const approvals = await restCall(page, 'GET', 'properties/' + PROPERTY + '/approvals', nonce);
    check(approvals.status === 200 && approvals.body.length === 1 && approvals.body[0].entries.length > 0, 'GET /approvals does not list the request: ' + approvals.status);
    const refused = await restCall(page, 'POST', 'emergency', null);
    check(refused.status === 401, 'POST /emergency without a nonce answered ' + refused.status);

    const request = 'approvals/' + approvals.body[0].id;
    const malformed = [
        { property: PROPERTY, decision: 'refuse' },
        { property: PROPERTY },
        { property: PROPERTY, decision: 'approve', keys: approvals.body[0].entries[0].key },
        { property: PROPERTY, decision: 'approve', keys: [] },
    ];
    for (const body of malformed) {
        const answer = await restPost(page, request, nonce, body);
        check(answer.status === 400, 'POST /' + request + ' ' + JSON.stringify(body) + ' answered ' + answer.status);
    }
    const still = await restCall(page, 'GET', 'properties/' + PROPERTY + '/approvals', nonce);
    check(still.body.length === 1, 'a malformed decision changed the request waiting');
    for (const body of [{}, { engaged: 'maybe' }, { engage: false }]) {
        const answer = await restPost(page, 'emergency', nonce, body);
        check(answer.status === 400, 'POST /emergency ' + JSON.stringify(body) + ' answered ' + answer.status);
    }
}

async function operations(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-operations&property=' + PROPERTY);
}

async function notice(page) {
    return (await page.textContent('.ritmo-notice')).trim();
}

/**
 * "Run now" until the run of the property is no longer in progress.
 */
async function runNow(page) {
    for (let attempt = 0; attempt < 10; attempt++) {
        await operations(page);
        await submitAndWait(page, '[data-ritmo="run-now-button"]');
        const text = await notice(page);
        if (!text.startsWith('Run: running')) {
            return text;
        }
    }
    throw new Error('the run did not finish');
}

async function main() {
    await fetch(API + '/__reset', { method: 'POST' });
    await fetch(API + '/__writes', { method: 'POST', body: JSON.stringify({ not_ready: [] }) });

    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
    const page = await context.newPage();
    const browserErrors = [];
    page.on('pageerror', (error) => browserErrors.push(error.message));

    try {
        await page.goto(SITE + '/wp-login.php');
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASSWORD);
        await submitAndWait(page, '#wp-submit');

        // Approval mode: the plan waits, nothing is written.
        await operations(page);
        check((await page.getAttribute('[data-ritmo="current-mode"]', 'data-mode')) === 'shadow', 'a new property is not in shadow mode');
        await page.selectOption('#ritmo-mode', 'approval');
        await submitAndWait(page, '[data-ritmo="set-mode"]');
        check((await notice(page)).includes('approval mode'), 'the mode did not change: ' + await notice(page));
        const run = await runNow(page);
        check(run.startsWith('Run: completed'), 'the run in approval mode did not complete: ' + run);
        await operations(page);
        check(await page.locator('[data-ritmo="approval-request"]').count() === 1, 'no plan waits for approval');
        check((await writesInLog()).length === 0, 'something was written before the approval: ' + JSON.stringify(await writesInLog()));
        await shot(page, '07-operations-waiting-for-approval.png');
        await restChecks(page);
        await operations(page);

        // Approve everything: the scheduler writes the approved plan.
        await submitAndWait(page, '[data-ritmo="approve-all"]');
        check((await notice(page)).includes('entries approved'), 'the approval failed: ' + await notice(page));
        let writes = [];
        for (let attempt = 0; attempt < 10 && writes.length === 0; attempt++) {
            await fetch(SITE + '/wp-cron.php?doing_wp_cron=' + (Date.now() / 1000).toFixed(4));
            writes = await writesInLog();
            if (writes.length === 0) {
                await runNow(page);
                writes = await writesInLog();
            }
        }
        check(writes.length > 0, 'the approved plan was not written');
        const outside = writes.filter((w) => !WRITE_ROUTES.some((route) => route.test(w.path)));
        check(outside.length === 0, 'a write left the write routes of the pricing system: ' + JSON.stringify(outside));
        check(!writes.some((w) => w.path.startsWith('/beds24/')), 'something was written to the PMS');
        await operations(page);
        check(await page.locator('[data-ritmo="change"]').count() > 0, 'the change log is empty after the write');
        await shot(page, '08-operations-after-approved-write.png');

        // The emergency switch on the screen: nothing more leaves.
        await submitAndWait(page, '[data-ritmo="switch-button"]');
        check((await page.textContent('[data-ritmo="switch-state"]')).includes('Engaged'), 'the switch did not engage');
        const before = (await writesInLog()).length;
        await runNow(page);
        check((await writesInLog()).length === before, 'a write left with the emergency switch engaged');
        await shot(page, '09-operations-emergency-switch.png');
        await operations(page);
        await submitAndWait(page, '[data-ritmo="switch-button"]');
        check((await page.textContent('[data-ritmo="switch-state"]')).includes('Released'), 'the switch was not released');

        check(browserErrors.length === 0, 'the browser reported errors: ' + browserErrors.join(' | '));
        console.log(JSON.stringify({ pass: 'operations', writes: writes.length, screenshots: shots }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error('E2E FAILED: ' + error.message);
    process.exit(1);
});
