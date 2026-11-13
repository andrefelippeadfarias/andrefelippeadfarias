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
 *
 * With the argument "wizard" it runs the setup wizard instead (spec 5.1), on a fresh site before
 * tests/e2e/seed.php:  RITMO_E2E_WP=<wordpress folder> node tests/e2e/m4-screens.js wizard
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const API = process.env.RITMO_E2E_API || 'http://127.0.0.1:8091';
/* The WordPress folder of the site (the wizard pass adds market prices through tests/e2e/seed-market.php). */
const WP = process.env.RITMO_E2E_WP || '';
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

/* A page without JavaScript is driven by the keyboard: focus the submit button and press Enter. */
async function pressAndWait(page, selector) {
    await page.locator(selector).focus();
    await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.keyboard.press('Enter')]);
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

    // AC-TODO-1 (part): a "Sync Now" item is never added twice for the same room and day. Whether one
    // exists right after the write depends on the change log holding tonight's rows (see docs/ADMIN.md 7).
    await open(page, 'ritmo-revenue-todo');
    const sync = await page.locator('[data-ritmo="todo-row"]', { hasText: 'Sync Now' }).count();
    await open(page, 'ritmo-revenue-todo');
    check(await page.locator('[data-ritmo="todo-row"]', { hasText: 'Sync Now' }).count() === sync, 'to-do: a "Sync Now" item was added twice');
    return { entries, segments, approved, writes: written.length, sync_now: sync };
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

/* Alerts (spec 5.10): the severity filter, a critical alert's facts in words, acknowledging it. */
async function alerts(page) {
    await open(page, 'ritmo-revenue-alerts&severity=warning');
    const warnings = await page.locator('[data-ritmo="alert-row"]').allInnerTexts();
    check(warnings.length > 0 && warnings.every((row) => /Warning/.test(row) && !/Critical/.test(row)), 'alerts: the warning filter shows other severities');
    await open(page, 'ritmo-revenue-alerts&severity=critical&status=open');
    const critical = page.locator('[data-ritmo="alert-row"]').first();
    check(await critical.count() === 1, 'alerts: the scenario has no open critical alert');
    await Promise.all([page.waitForNavigation(), critical.locator('a').first().click()]);
    const detail = await page.locator('[data-ritmo="alert-detail"]').innerText();
    check(await page.locator('[data-ritmo="alert-detail"] dl dt').count() > 0, 'alerts: the detail has no facts');
    check(!/\{\s*"/.test(detail) && !/[a-z]+_[a-z]+_[a-z]+/.test(detail), 'alerts: the detail shows raw data: ' + detail.slice(0, 200));
    await submitAndWait(page, '[data-ritmo="alert-ack"]');
    await open(page, 'ritmo-revenue-alerts&severity=critical&status=acknowledged');
    check(await page.locator('[data-ritmo="alert-row"]').count() >= 1, 'alerts: acknowledging did not change the status');
}

/* The change log export (spec 5.11, AC-CHG-4): BOM, header, the formula guard, amounts with a dot. */
async function changesExport(page) {
    await open(page, 'ritmo-revenue-changes');
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('[data-ritmo="changes-export"]')]);
    const body = fs.readFileSync(await download.path(), 'utf8');
    check(body.charCodeAt(0) === 0xfeff, 'export: no UTF-8 BOM');
    const lines = body.slice(1).trim().split(/\r?\n/);
    check(lines.length > 1, 'export: no rows');
    const cells = lines.flatMap((line) => line.split(','));
    const unguarded = cells.filter((cell) => /^"?[=+\-@\t\r]/.test(cell) && !/^-?\d+(\.\d+)?$/.test(cell));
    check(unguarded.length === 0, 'export: a text cell starts with a formula character: ' + unguarded.slice(0, 3).join(' | '));
    check(lines.slice(1).every((line) => !/\d,\d{2}"/.test(line)), 'export: an amount with a decimal comma');
    check(!/[^\s@",]+@[^\s@",]+\.[a-z]{2,}/i.test(body), 'export: an e-mail address is in it');
}

/* Connections (spec 5.13, AC-CON-4): forgetting a key with the wrong name typed changes nothing. */
async function forgetWithWrongName(page) {
    await open(page, 'ritmo-revenue-settings&tab=connections');
    await page.click('details:has([data-ritmo="forget-pricelabs"]) summary');
    await page.fill('#ritmo-forget-pricelabs', 'Pricelabs');
    check(await page.locator('[data-ritmo="forget-pricelabs"] button[type=submit]').isDisabled(), 'connections: the button is not disabled while the typed name differs');
    // The server must refuse it too: post the form as a browser without the script would.
    await Promise.all([page.waitForNavigation(), page.locator('[data-ritmo="forget-pricelabs"]').evaluate((form) => form.submit())]);
    check(/does not match/.test(await page.locator('[data-ritmo="notice"]').innerText()), 'connections: a wrong name did not refuse forgetting the key');
    await page.click('[data-ritmo="connection-test-pricelabs"]');
    await page.waitForFunction(() => /Connected|Tested/.test(document.querySelector('[data-ritmo="connection-result-pricelabs"]').textContent), null, { timeout: 30000 });
}

/* Diagnostics (spec 5.14, AC-DIA-1 and 3): the log in words, a run's stages in words. */
async function diagnostics(page) {
    await open(page, 'ritmo-revenue-settings&tab=diagnostics');
    check(!/\{\s*"/.test(await page.locator('[data-ritmo="log"]').innerText()), 'diagnostics: raw JSON in the log');
    const link = page.locator('[data-ritmo="runs"] a[href*="run="]').first();
    check(await link.count() === 1, 'diagnostics: no run to open');
    await Promise.all([page.waitForNavigation(), link.click()]);
    const stages = await page.locator('[data-ritmo="run-detail"] li').allInnerTexts();
    check(stages.length >= 3 && stages.every((s) => !/[a-z]+_[a-z]+/.test(s)), 'diagnostics: the stages of a run are not in words: ' + stages.slice(0, 3).join(' | '));
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

async function login(page) {
    await page.goto(SITE + '/wp-login.php');
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASSWORD);
    await submitAndWait(page, '#wp-submit');
}

async function setupStep(page, step) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-settings&tab=setup' + (step ? '&step=' + step : '') + '&property=' + PROPERTY);
}

async function currentStep(page) {
    return Number(((await page.locator('[data-ritmo-current="1"]').getAttribute('data-ritmo')) || '').replace('wizard-step-', ''));
}

/*
 * The setup wizard (spec 5.1, AC-WIZ-1 to 7) on a fresh site, before tests/e2e/seed.php: the keys
 * with a wrong token first, then steps 4 to 8 without JavaScript, the room cards at 390 px, a wrong
 * typed confirmation, and "what the plugin would do" with only reads in the simulator log.
 */
async function wizard(browser) {
    const context = await browser.newContext({ deviceScaleFactor: 1 });
    const page = await context.newPage();
    await login(page);
    await page.setViewportSize({ width: 1280, height: 900 });

    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue');
    check(await page.locator('[data-ritmo="empty"] a[href*="tab=setup"]').count() === 1, 'wizard: the overview of a fresh site does not offer the setup');
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-settings&tab=setup');
    check(await currentStep(page) === 2, 'wizard: a fresh site does not open on step 2');
    for (let step = 3; step <= 9; step++) {
        check(await page.locator('[data-ritmo="wizard-step-' + step + '"] .ritmo-done').count() === 0, 'wizard: step ' + step + ' is done on a fresh site');
    }
    await crossChecks(page, 'wizard-step-2', 1280);

    await page.fill('#ritmo-w-token', 'wrong-refresh-token-9999');
    await page.fill('#ritmo-w-ids', '901001');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    const refused = await page.locator('.wrap.ritmo').innerText();
    check(/refused/i.test(refused), 'wizard: a wrong token does not name the cause: ' + refused.slice(0, 300));
    check(!/Exception|not valid|HTTP|401|wrong-refresh/.test(refused), 'wizard: the answer to a wrong token shows internal details');
    check(await currentStep(page) === 2, 'wizard: a wrong token went on to the next step');

    await page.fill('#ritmo-w-token', SECRETS[0]);
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 3, 'wizard: the PMS step did not go on after a good token');
    await page.fill('#ritmo-w-key', SECRETS[1]);
    await page.fill('#ritmo-w-pms', 'beds24');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 4, 'wizard: the pricing step did not go on');

    const plain = await browser.newContext({ javaScriptEnabled: false, deviceScaleFactor: 1 });
    const form = await plain.newPage();
    await login(form);
    await setupStep(form, 4);
    check(await form.inputValue('#ritmo-p-cur') === 'BRL', 'wizard: the currency of the listings is not suggested');
    await form.fill('#ritmo-p-name', 'Example Inn');
    await form.fill('#ritmo-p-id', PROPERTY);
    await form.selectOption('#ritmo-p-tz', 'America/Sao_Paulo');
    await pressAndWait(form, '[data-ritmo="wizard-continue"]');
    check(await currentStep(form) === 5, 'wizard: the property step did not go on without JavaScript');

    check(await form.locator('[data-ritmo="rooms-suggested"]').count() === 1, 'wizard: the rooms are not suggested from the listings');
    const units = { 902001: '4', 902002: '2', 902003: '1' };
    for (let i = 0; i < 3; i++) {
        const pms = await form.inputValue('#ritmo-r-' + i + '-pms_ref');
        check(units[pms] !== undefined, 'wizard: room row ' + i + ' has no PMS room id suggested (' + pms + ')');
        await form.fill('#ritmo-r-' + i + '-units', units[pms]);
    }
    const narrow = await plain.newPage();
    await narrow.setViewportSize({ width: 390, height: 844 });
    await setupStep(narrow, 5);
    const cards = await narrow.locator('[data-ritmo="room-row"]').evaluateAll((rows) => rows.slice(0, 2).map((r) => r.getBoundingClientRect().toJSON()));
    check(Math.abs(cards[0].x - cards[1].x) < 2 && cards[1].y > cards[0].y + cards[0].height - 2, 'wizard: the rooms are not one card per room at 390 px');
    check(await narrow.evaluate(() => document.documentElement.scrollWidth - window.innerWidth) <= 1, 'wizard: the rooms step scrolls sideways at 390 px');
    await narrow.screenshot({ path: path.join(SHOTS, 'm4-wizard-rooms-390.png'), fullPage: true });
    await narrow.close();
    await pressAndWait(form, '[data-ritmo="wizard-continue"]');
    check(await currentStep(form) === 6, 'wizard: the rooms step did not go on: ' + await form.locator('[data-ritmo="notice"]').innerText().catch(() => ''));

    await pressAndWait(form, '[data-ritmo="wizard-continue"]');
    check(await currentStep(form) === 7, 'wizard: the rules step did not go on');

    await form.fill('#ritmo-s-confirm', 'wrong-inn');
    await pressAndWait(form, '[data-ritmo="wizard-continue"]');
    check(/does not match/.test(await form.locator('[data-ritmo="notice"]').innerText()), 'wizard: a wrong typed confirmation was not refused');
    check(await currentStep(form) === 7, 'wizard: a wrong typed confirmation went on');
    await form.fill('#ritmo-s-confirm', PROPERTY);
    await pressAndWait(form, '[data-ritmo="wizard-continue"]');
    check(await currentStep(form) === 8, 'wizard: the safety step did not go on');

    await pressAndWait(form, '[data-ritmo="run-shadow"]');
    await setupStep(form, 0);
    check(await currentStep(form) === 9, 'wizard: the shadow run did not lead to step 9');
    const bare = await form.locator('[data-ritmo="would-do-summary"]').innerText();
    check(/would change 0 nights/.test(bare) && await form.locator('[data-ritmo="would-do-no-market"] a[href*="ritmo-revenue-market"]').count() === 1, 'wizard: a run without market prices does not say why it changes nothing: ' + bare.slice(0, 300));

    execFileSync('php', [path.join(__dirname, 'seed-market.php'), WP, PROPERTY]);
    await setupStep(form, 8);
    await pressAndWait(form, '[data-ritmo="run-shadow"]');
    await setupStep(form, 9);
    const summary = await form.locator('[data-ritmo="would-do-summary"]').innerText();
    const would = Number((summary.match(/would change ([\d,.]+) night/) || [0, '0'])[1].replace(/[,.]/g, ''));
    check(would >= 1, 'wizard: "what the plugin would do" changes no night: ' + summary.slice(0, 200));
    check(/R\$/.test(summary), 'wizard: the changes are not in money');
    check(writes(await simulatorRequests()).length === 0, 'wizard: the simulator was written to');
    await plain.close();

    for (let step = 1; step <= 9; step++) {
        for (const width of [1280, 390]) {
            await page.setViewportSize({ width, height: width > 400 ? 900 : 844 });
            await setupStep(page, step);
            await crossChecks(page, 'wizard-step-' + step, width);
        }
    }
    await page.setViewportSize({ width: 1280, height: 900 });
    await setupStep(page, 9);
    await page.screenshot({ path: path.join(SHOTS, 'm4-wizard-would-do-1280.png'), fullPage: true });
    await context.close();

    return { would_change: would };
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

async function wizardPass() {
    const browser = await chromium.launch();
    try {
        console.log(JSON.stringify({ ok: true, results: [{ wizard: await wizard(browser) }] }, null, 2));
    } catch (error) {
        const pages = browser.contexts().flatMap((c) => c.pages());
        for (let i = 0; i < pages.length; i++) {
            await pages[i].screenshot({ path: path.join(SHOTS, 'm4-wizard-failure-' + i + '.png'), fullPage: true }).catch(() => null);
        }
        throw error;
    } finally {
        await browser.close();
    }
}

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });
    if (process.argv[2] === 'wizard') {
        check(WP !== '', 'the wizard pass needs RITMO_E2E_WP, the WordPress folder of the site');
        await wizardPass();
        return;
    }
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
        await alerts(page);
        await changesExport(page);
        await forgetWithWrongName(page);
        await diagnostics(page);
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
