'use strict';

/*
 * The central screens of M4 (admin spec 5.1-5.4, 5.10-5.14 and the navigation) on the throwaway
 * site with the simulator: the connections and the shadow run through the new screens, every
 * screen at 1280 and 390 px with the cross-screen checks (no raw JSON, no internal names, no
 * sideways scroll, accessible names, one h1, captions, focus ring, no secret in the HTML), the
 * night detail, the to-do privacy guard, the connection test cooldown, the support file and an
 * approval in approval mode. Without JavaScript the main screens still render.
 *
 *   RITMO_E2E_SITE=http://127.0.0.1:8090 RITMO_E2E_API=http://127.0.0.1:8091 node tests/e2e/m4-screens.js
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const API = process.env.RITMO_E2E_API || 'http://127.0.0.1:8091';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const SECRETS = ['sim-refresh-token-0001', 'sim-pricelabs-key-0001'];
const SHOTS = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const INTERNAL = ['floor_pct', 'ceiling_pct', 'check_in_mask', 'PACE_STEP', 'security_unknown_origin', 'would_write', 'no_change', 'rule_trace', 'min_percent', 'approval_pending'];

const SCREENS = [
    ['overview', 'ritmo-revenue'],
    ['nights', 'ritmo-revenue-nights'],
    ['approvals', 'ritmo-revenue-approvals'],
    ['approvals-history', 'ritmo-revenue-approvals&tab=history'],
    ['alerts', 'ritmo-revenue-alerts'],
    ['todo', 'ritmo-revenue-todo'],
    ['changes', 'ritmo-revenue-changes'],
    ['settings-setup', 'ritmo-revenue-settings&tab=setup'],
    ['settings-connections', 'ritmo-revenue-settings&tab=connections'],
    ['settings-diagnostics', 'ritmo-revenue-settings&tab=diagnostics'],
];

const results = [];

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function simulatorRequests() {
    const body = await (await fetch(API + '/__log')).json();
    return body.requests.filter((request) => !request.path.startsWith('/__'));
}

async function submitAndWait(page, selector) {
    await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click(selector)]);
}

async function open(page, slug) {
    await page.goto(SITE + '/wp-admin/admin.php?page=' + slug + (slug.includes('property=') ? '' : '&property=' + PROPERTY));
}

/* The cross-screen checks of admin spec 11.2 on the page as it is. */
async function crossChecks(page, name, width) {
    const report = await page.evaluate(([internal, secrets]) => {
        const wrap = document.querySelector('.wrap.ritmo');
        const text = wrap ? wrap.innerText : '';
        const html = document.documentElement.outerHTML;
        const unlabeled = [];
        wrap.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((el) => {
            if (el.offsetParent === null) {
                return;
            }
            const id = el.getAttribute('id');
            const label = (id && document.querySelector('label[for="' + id + '"]')) || el.closest('label') || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
            if (!label) {
                unlabeled.push(el.outerHTML.slice(0, 80));
            }
        });
        const tables = Array.from(wrap.querySelectorAll('table')).filter((t) => !t.querySelector('caption') && !t.getAttribute('aria-label'));
        const nameless = Array.from(wrap.querySelectorAll('a, button')).filter((el) => el.offsetParent !== null && (el.textContent || el.getAttribute('aria-label') || '').trim() === '');
        const icons = Array.from(wrap.querySelectorAll('.dashicons')).filter((el) => el.getAttribute('aria-hidden') !== 'true');
        const secretParts = [];
        secrets.forEach((s) => {
            for (let i = 0; i + 8 <= s.length; i++) {
                // Windows made only of letters are words of the screen ("pricelab"); the rest is the key.
                if (/\d/.test(s.slice(i, i + 8)) && html.includes(s.slice(i, i + 8))) {
                    secretParts.push(s.slice(i, i + 8));
                }
            }
        });
        return {
            json: /\{\s*"[A-Za-z_]+"\s*:/.test(text),
            internal: internal.filter((word) => text.includes(word)),
            h1: wrap.querySelectorAll('h1').length,
            scroll: document.documentElement.scrollWidth - window.innerWidth,
            unlabeled,
            tables: tables.length,
            nameless: nameless.map((el) => el.outerHTML.slice(0, 80)),
            icons: icons.length,
            secrets: secretParts,
            php: /(Fatal error|Warning:|Notice:|Deprecated:)/.test(text),
        };
    }, [INTERNAL, SECRETS]);
    check(!report.json, name + ': raw JSON on the screen');
    check(report.internal.length === 0, name + ': internal names on the screen: ' + report.internal.join(', '));
    check(report.h1 === 1, name + ': ' + report.h1 + ' h1 headings');
    check(width > 400 || report.scroll <= 1, name + ': the page scrolls sideways at 390 px by ' + report.scroll + ' px');
    check(report.unlabeled.length === 0, name + ': fields without a label: ' + report.unlabeled.join(' | '));
    check(report.tables === 0, name + ': a table without caption');
    check(report.nameless.length === 0, name + ': links or buttons without text: ' + report.nameless.join(' | '));
    check(report.icons === 0, name + ': icons not hidden from screen readers');
    check(report.secrets.length === 0, name + ': a piece of a saved key is in the HTML');
    check(!report.php, name + ': a PHP error is on the screen');
}

async function everyScreen(page, width, label) {
    await page.setViewportSize({ width, height: width > 400 ? 900 : 844 });
    for (const [name, slug] of SCREENS) {
        await open(page, slug);
        await crossChecks(page, name, width);
        await page.screenshot({ path: path.join(SHOTS, 'm4-' + name + (label ? '-' + label : '') + '-' + width + '.png'), fullPage: true });
    }
}

async function focusRing(page) {
    await open(page, 'ritmo-revenue-nights');
    await page.locator('.wrap.ritmo summary, .wrap.ritmo a[href], .wrap.ritmo button').first().focus();
    let checked = 0;
    for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab');
        const ring = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || !el.closest('.wrap.ritmo')) {
                return null;
            }
            const style = window.getComputedStyle(el);
            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        });
        if (ring !== null) {
            checked += 1;
            check(ring, 'a focused control of the plugin has no visible focus');
        }
    }
    check(checked >= 20, 'only ' + checked + ' plugin controls were reached by Tab');

    return checked;
}

async function connectionsAndRun(page) {
    await open(page, 'ritmo-revenue-settings&tab=connections');
    await page.fill('#ritmo-c-token', SECRETS[0]);
    await page.fill('#ritmo-c-ids', '901001');
    await page.fill('#ritmo-c-key', SECRETS[1]);
    await page.fill('#ritmo-c-pms', 'beds24');
    await submitAndWait(page, 'form:has(#ritmo-c-token) button[type=submit]');
    check(page.url().includes('ritmo-revenue-settings') && page.url().includes('tab=connections'), 'saving the keys did not come back to Settings › Connections: ' + page.url());

    for (const provider of ['beds24', 'pricelabs']) {
        const region = '[data-ritmo="connection-result-' + provider + '"]';
        const initial = await page.locator(region).innerText();
        await page.click('[data-ritmo="connection-test-' + provider + '"]');
        await page.waitForFunction(([r, t]) => document.querySelector(r).textContent.trim() !== t.trim(), [region, initial], { timeout: 30000 });
        check(/Connected|Conectado/.test(await page.locator(region).innerText()), provider + ': the test did not connect: ' + await page.locator(region).innerText());
        const before = (await simulatorRequests()).length;
        await page.click('[data-ritmo="connection-test-' + provider + '"]');
        await page.waitForFunction((p) => /Tested|Testado/.test(document.querySelector('[data-ritmo="connection-result-' + p + '"]').textContent), provider, { timeout: 30000 });
        check((await simulatorRequests()).length === before, provider + ': the second test within 30 s called the simulator again');
    }

    await open(page, 'ritmo-revenue');
    await submitAndWait(page, '[data-ritmo="run-shadow"]');
    await open(page, 'ritmo-revenue');
    check(/completed/.test(await page.locator('[data-ritmo="card-last-run"]').innerText()), 'the shadow run did not complete');
    const writes = (await simulatorRequests()).filter((r) => r.method !== 'GET' && !(r.method === 'POST' && r.path === '/pricelabs/v1/listing_prices'));
    check(writes.length === 0, 'the shadow run wrote to the simulator: ' + JSON.stringify(writes));
}

async function overview(page) {
    await open(page, 'ritmo-revenue');
    for (const hook of ['card-today', 'card-pace', 'card-last-run', 'card-next-runs']) {
        check(await page.locator('[data-ritmo="' + hook + '"]').count() === 1, 'overview: card ' + hook + ' is missing');
    }
    const next7 = await page.locator('[data-ritmo="card-next-7"]').innerText();
    check(/%/.test(next7) && /room nights sold/.test(next7) && /R\$/.test(next7), 'overview: the 7-day card lacks the occupancy in words or the money: ' + next7);
    const rows = await page.evaluate(() => Math.max(0, ...Array.from(document.querySelectorAll('.wrap.ritmo table')).map((t) => t.querySelectorAll('tbody tr').length)));
    check(rows <= 31, 'overview: a table has ' + rows + ' rows');
}

async function nights(page) {
    await open(page, 'ritmo-revenue-nights');
    const cells = await page.locator('[data-ritmo="grid-cell"]').count();
    check(cells === 90, 'nights: the grid has ' + cells + ' cells instead of 3 rooms x 30 nights');
    const label = await page.locator('[data-ritmo="grid-cell"]').first().getAttribute('aria-label');
    check(/(R\$|sold out|closed)/.test(label) && /of \d+ sold/.test(label), 'nights: the accessible name of a cell does not read the cell: ' + label);
    const caption = await page.locator('[data-ritmo="grid"] caption').innerText();
    await submitAndWait(page, '[data-ritmo="grid-next"]');
    check(page.url().includes('from='), 'nights: the next window is not in the URL');
    check((await page.locator('[data-ritmo="grid"] caption').innerText()) !== caption, 'nights: the caption did not change');

    await open(page, 'ritmo-revenue-nights&only=would_change');
    const would = await page.locator('[data-ritmo="grid-cell"]').count();
    check(would === Number(await page.locator('[data-ritmo="grid-count"]').innerText()), 'nights: the count of the filter differs from the cells');
    if (would > 0) {
        await page.locator('[data-ritmo="grid-cell"]').first().focus();
        await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
        await page.waitForSelector('[data-ritmo="night-detail"]');
        const why = await page.locator('[data-ritmo="night-why"]').innerText();
        const prices = await page.locator('[data-ritmo="night-prices"]').innerText();
        check(why.length > 20, 'nights: the detail has no reason');
        check(/(below|above|at) the base/.test(prices), 'nights: the detail has no percent of the base in words');
        const focused = await page.waitForFunction(() => document.activeElement && document.activeElement.closest('[data-ritmo="night-detail"]') !== null, null, { timeout: 5000 }).then(() => true, () => false);
        check(focused, 'nights: the focus is not on the detail after opening it');
    }
    return would;
}

function writes(requests) {
    return requests.filter((r) => r.method !== 'GET' && !(r.method === 'POST' && r.path === '/pricelabs/v1/listing_prices'));
}

/* Approval mode through the M3 operations screen, then the redesigned approval screen (5.4). */
async function approvals(page) {
    await fetch(API + '/__writes', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ not_ready: [] }) });
    await open(page, 'ritmo-revenue-operations');
    await page.selectOption('#ritmo-mode', 'approval');
    await submitAndWait(page, '[data-ritmo="set-mode"]');
    await submitAndWait(page, '[data-ritmo="run-now-button"]');
    check(writes(await simulatorRequests()).length === 0, 'approval mode wrote before any approval');

    await open(page, 'ritmo-revenue-approvals');
    check(await page.locator('[data-ritmo="approval-request"]').count() === 1, 'approvals: no request waits');
    const all = await page.locator('[data-ritmo="approval-request"]').evaluate((form) => form.textContent);
    check(!/\{\s*"/.test(all) && !/_pct/.test(all), 'approvals: raw JSON or internal names');
    const entries = await page.locator('[data-ritmo="night-row"]').count();
    const segments = await page.locator('[data-ritmo="segment"]').count();
    check(segments > 0 && segments < entries, 'approvals: ' + segments + ' segments for ' + entries + ' entries');
    const rowsWithoutMoney = await page.locator('[data-ritmo="night-row"]').evaluateAll((rows) => rows.filter((r) => r.getAttribute('data-kind') !== 'deletion' && (!/R\$/.test(r.textContent) || !/%/.test(r.textContent))).length);
    check(rowsWithoutMoney === 0, 'approvals: ' + rowsWithoutMoney + ' night rows without an amount and a percent');
    check(await page.locator('[data-ritmo="impact"]').count() === 1, 'approvals: no impact summary');
    await page.screenshot({ path: path.join(SHOTS, 'm4-approvals-waiting-1280.png'), fullPage: false });

    const first = page.locator('[data-ritmo="segment"]').first();
    const inFirst = await first.locator('input[name="entries[]"]').count();
    await first.locator('[data-ritmo="segment-check"]').focus();
    await page.keyboard.press('Space');
    check(await first.locator('input[name="entries[]"]:checked').count() === 0, 'approvals: unticking a segment kept its nights');
    check(/Selected: /.test(await page.locator('[data-ritmo="selection-total"]').innerText()), 'approvals: no live total');
    await submitAndWait(page, '[data-ritmo="approve-selected"]');
    const notice = await page.locator('[data-ritmo="notice"]').innerText();
    const approved = Number((notice.match(/^(\d+)/) || [0, -1])[1]);
    check(approved > 0 && approved <= entries - inFirst + 2, 'approvals: the notice does not give the nights approved: ' + notice);
    check(await page.locator('[data-ritmo="approval-progress"]').count() === 1, 'approvals: the approved request is not followed');
    await page.screenshot({ path: path.join(SHOTS, 'm4-approvals-being-written-1280.png'), fullPage: false });

    let written = [];
    for (let attempt = 0; attempt < 10 && written.length === 0; attempt++) {
        await fetch(SITE + '/wp-cron.php?doing_wp_cron=' + (Date.now() / 1000).toFixed(4));
        written = writes(await simulatorRequests());
    }
    check(written.length > 0, 'approvals: the approved nights were not written');
    check(!written.some((w) => w.path.startsWith('/beds24/')), 'approvals: something was written to the PMS');
    await open(page, 'ritmo-revenue-changes');
    const group = await page.locator('[data-ritmo="run-group"]').first().innerText();
    check(/Approved write/.test(group) && /approved by/.test(group) && /R\$/.test(group), 'change log: no approved write with its approver and amounts: ' + group.slice(0, 200));
    await page.screenshot({ path: path.join(SHOTS, 'm4-changes-after-write-1280.png'), fullPage: false });
    return { entries, segments, approved, writes: written.length };
}

async function todo(page) {
    await open(page, 'ritmo-revenue-todo');
    await page.fill('#ritmo-todo-title', 'Call 11 98765-4321 about the heater');
    await submitAndWait(page, '[data-ritmo="todo-add"] button[type=submit]');
    check(/phone numbers/.test(await page.locator('[data-ritmo="notice"]').innerText()), 'to-do: a phone number was not refused');
    await page.fill('#ritmo-todo-title', 'Check the heater of room 12');
    await submitAndWait(page, '[data-ritmo="todo-add"] button[type=submit]');
    check(await page.locator('[data-ritmo="todo-row"]', { hasText: 'Check the heater of room 12' }).count() === 1, 'to-do: the item was not added');
    await submitAndWait(page, '[data-ritmo="todo-row"]:has-text("Check the heater of room 12") [data-ritmo="todo-done"]');
    check(await page.locator('[data-ritmo="todo-row"]', { hasText: 'Check the heater of room 12' }).count() === 0, 'to-do: "Done" did not move the item');
}

async function supportFile(page) {
    await open(page, 'ritmo-revenue-settings&tab=diagnostics');
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('[data-ritmo="support-bundle"]')]);
    const body = fs.readFileSync(await download.path(), 'utf8');
    const bundle = JSON.parse(body);
    check(JSON.stringify(Object.keys(bundle)) === JSON.stringify(['generated_at', 'versions', 'environment', 'active_plugins', 'settings', 'rules', 'properties', 'room_types', 'schedule', 'runs', 'alerts', 'log', 'migrations']), 'support file: unexpected keys');
    check(!SECRETS.some((s) => body.includes(s.slice(0, 8))), 'support file: a key is in it');
    check(!/[^\s@"]+@[^\s@"]+\.[a-z]{2,}/i.test(body), 'support file: an e-mail address is in it');
}

async function forgedPost(page) {
    const status = await page.evaluate(async (site) => {
        const form = new FormData();
        form.append('action', 'ritmo_todo_close');
        form.append('item_id', '1');
        form.append('_wpnonce', 'forged');
        const response = await fetch(site + '/wp-admin/admin-post.php', { method: 'POST', body: form, credentials: 'same-origin', redirect: 'manual' });
        const get = await fetch(site + '/wp-admin/admin-post.php?action=ritmo_todo_close', { credentials: 'same-origin', redirect: 'manual' });
        return [response.status, get.status];
    }, SITE);
    check(status[0] === 403, 'a post with a forged nonce was not refused (' + status[0] + ')');
    check(status[1] === 405, 'a GET of a form action was not refused with 405 (' + status[1] + ')');
}

async function restChecks(page) {
    const answer = await page.evaluate(async (site) => {
        const noNonce = await fetch(site + '/?rest_route=/ritmo/v1/approvals/1', { credentials: 'same-origin' });
        return noNonce.status;
    }, SITE);
    check(answer === 401, 'a REST route answered without the nonce (' + answer + ')');
}

async function withoutJavaScript(browser) {
    const context = await browser.newContext({ javaScriptEnabled: false, deviceScaleFactor: 1 });
    const page = await context.newPage();
    await page.goto(SITE + '/wp-login.php');
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASSWORD);
    await submitAndWait(page, '#wp-submit');
    for (const [name, slug] of SCREENS) {
        await open(page, slug);
        check(await page.locator('.wrap.ritmo h1').count() === 1, name + ' does not render without JavaScript');
    }
    await context.close();
}

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ deviceScaleFactor: 1 });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/the server responded with a status of 4(01|03|05|29)/.test(message.text())) {
            errors.push(message.text());
        }
    });
    try {
        await page.goto(SITE + '/wp-login.php');
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASSWORD);
        await submitAndWait(page, '#wp-submit');
        await page.setViewportSize({ width: 1280, height: 900 });

        const menu = await page.locator('#toplevel_page_ritmo-revenue .wp-submenu li a').allInnerTexts();
        check(menu.length === 12, 'the menu has ' + menu.length + ' entries');
        results.push({ menu: menu.map((t) => t.replace(/\s+\d+.*$/, '')) });

        await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-connections&property=' + PROPERTY);
        check(page.url().includes('page=ritmo-revenue-settings') && page.url().includes('tab=connections') && page.url().includes('property=' + PROPERTY), 'the old slug did not redirect: ' + page.url());

        await connectionsAndRun(page);
        await overview(page);
        results.push({ would_change: await nights(page) });
        await todo(page);
        results.push({ approvals: await approvals(page) });
        await supportFile(page);
        await forgedPost(page);
        await restChecks(page);
        await everyScreen(page, 1280, '');
        await everyScreen(page, 390, '');
        await page.setViewportSize({ width: 1280, height: 900 });
        results.push({ focus_checked: await focusRing(page) });
        await withoutJavaScript(browser);
        check(errors.length === 0, 'the browser reported errors: ' + errors.join(' | '));
        console.log(JSON.stringify({ ok: true, results }, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
