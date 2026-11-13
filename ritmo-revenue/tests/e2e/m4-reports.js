'use strict';

/*
 * The Reports screen of M4 (admin spec 5.15, AC-REP-1 to 4; reports spec 06 part B) on the
 * throwaway site: every tab renders with a summary sentence and tables with a caption and column
 * headers; the channels summary equals the table's totals row; a CSV download carries the BOM,
 * the header and the formula guard; targets saved by form; the run reports in words; and on every
 * tab at 1280 and 390 px the cross-screen checks (no raw JSON, no internal names, no sideways
 * scroll, labels, one h1, no PHP error).
 *
 * The site must register the Reports screen and its form actions (docs/REPORTS.md, section 9) and
 * hold the data of tests/e2e/seed.php and tests/e2e/seed-reports.php.
 *
 *   RITMO_E2E_SITE=http://127.0.0.1:8090 node tests/e2e/m4-reports.js
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const SHOTS = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const SLUG = 'ritmo-revenue-reports';
const TABS = ['weekly', 'month', 'channels', 'rooms', 'review', 'targets', 'occupancy', 'runs'];
const INTERNAL = ['shadow_would_write', 'market_stale', 'direct_api', 'direct_manual', 'room_nights', 'weekly-made', 'on_pace', 'pms_skipped', 'market_sold_percent'];

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function open(page, tab, extra) {
    await page.goto(SITE + '/wp-admin/admin.php?page=' + SLUG + '&property=' + PROPERTY + '&tab=' + tab + (extra || ''));
}

async function crossChecks(page, name, width) {
    const report = await page.evaluate((internal) => {
        const wrap = document.querySelector('.wrap.ritmo') || document.body;
        const text = wrap.innerText;
        const unlabeled = [];
        wrap.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((el) => {
            const id = el.getAttribute('id');
            const label = (id && document.querySelector('label[for="' + id + '"]')) || el.closest('label') || el.getAttribute('aria-label');
            if (!label) {
                unlabeled.push(el.outerHTML.slice(0, 100));
            }
        });
        return {
            json: /\{\s*"[A-Za-z_]+"\s*:/.test(text),
            internal: internal.filter((word) => text.includes(word)),
            h1: wrap.querySelectorAll('h1').length,
            scroll: document.documentElement.scrollWidth - window.innerWidth,
            unlabeled,
            captionless: Array.from(wrap.querySelectorAll('table')).filter((t) => !t.querySelector('caption')).length,
            scopeless: Array.from(wrap.querySelectorAll('thead th')).filter((th) => th.getAttribute('scope') !== 'col').length,
            php: /(Fatal error|Warning:|Notice:|Deprecated:|There has been a critical error)/.test(document.body.innerText),
            chart: Array.from(document.querySelectorAll('svg.ritmo-bars')).every((svg) => svg.getAttribute('aria-hidden') === 'true'),
        };
    }, INTERNAL);
    check(!report.php, name + ': a PHP error is on the screen');
    check(!report.json, name + ': raw JSON on the screen');
    check(report.internal.length === 0, name + ': internal names on the screen: ' + report.internal.join(', '));
    check(report.h1 === 1, name + ': ' + report.h1 + ' h1 headings');
    check(width > 400 || report.scroll <= 1, name + ': the page scrolls sideways at 390 px by ' + report.scroll + ' px');
    check(report.unlabeled.length === 0, name + ': fields without a label: ' + report.unlabeled.join(' | '));
    check(report.captionless === 0, name + ': a table without caption');
    check(report.scopeless === 0, name + ': a column header without scope');
    check(report.chart, name + ': a chart that screen readers would read');
}

async function everyTab(page, width) {
    await page.setViewportSize({ width, height: width > 400 ? 900 : 844 });
    const seen = {};
    for (const tab of TABS) {
        await open(page, tab);
        await crossChecks(page, 'reports-' + tab, width);
        seen[tab] = await page.locator('[data-ritmo="report-table"]').count();
        await page.screenshot({ path: path.join(SHOTS, 'm4-reports-' + tab + '-' + width + '.png'), fullPage: true });
    }
    return seen;
}

/* AC-REP-2: the numbers of the summary sentence are those of the totals row. */
async function summaryEqualsTotals(page) {
    await open(page, 'channels');
    const summary = (await page.locator('[data-ritmo="report-summary"]').innerText()).trim();
    const totals = await page.locator('[data-table="weekly-channels"] [data-ritmo="totals"] td').allInnerTexts();
    const bookings = summary.match(/(\d+) bookings? made/);
    check(bookings !== null, 'channels: the summary has no booking count: ' + summary);
    check(totals[0].trim() === bookings[1], 'channels: summary ' + bookings[1] + ' bookings, totals row ' + totals[0]);
    check(summary.includes(totals[4].trim()), 'channels: the summary revenue ' + summary + ' is not the totals row ' + totals[4]);
    return { summary };
}

/* AC-REP-3: the CSV of a table has the BOM, the header and the formula guard. */
async function csv(page) {
    await open(page, 'weekly');
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator('[data-table="weekly-made"] [data-ritmo="download-csv"]').click(),
    ]);
    const file = await download.path();
    const body = fs.readFileSync(file, 'utf8');
    check(body.charCodeAt(0) === 0xfeff, 'csv: no UTF-8 BOM');
    const lines = body.slice(1).trim().split('\n');
    check(lines[0].startsWith('"Week starting",'), 'csv: header row missing: ' + lines[0]);
    check(lines.length > 1, 'csv: no rows');
    check(lines.every((line) => !/^[=+@]/.test(line) && !/,[=+@]/.test(line)), 'csv: a cell starts like a formula');
    check(/^ritmo-weekly-made-example-inn-\d{4}-\d{2}-\d{2}\.csv$/.test(download.suggestedFilename()), 'csv: file name ' + download.suggestedFilename());
    return { rows: lines.length - 1 };
}

async function targets(page) {
    await open(page, 'targets');
    const month = page.locator('[data-ritmo="target-month"]').first();
    await month.locator('summary').click();
    const field = month.locator('input[name="target_minimum"]');
    await field.fill('45.000,50');
    await Promise.all([page.waitForNavigation({ timeout: 60000 }), month.locator('button[type="submit"]').click()]);
    const after = page.locator('[data-ritmo="target-month"]').first().locator('input[name="target_minimum"]');
    check((await after.inputValue()) === '45000.5', 'targets: the minimum was not saved: ' + (await after.inputValue()));
    return { minimum: await after.inputValue() };
}

async function runReports(page) {
    await open(page, 'runs');
    const text = await page.locator('[data-ritmo="run-reports"]').innerText();
    check(text.includes('Shadow mode: 12 change would be written to the pricing system and 3 to the PMS; nothing was sent.'), 'runs: the coded line is not in words');
    check(text.includes('Market prices are 9 days old.'), 'runs: the market line is missing');
    return { ok: true };
}

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, acceptDownloads: true });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    const results = {};
    try {
        await page.goto(SITE + '/wp-login.php');
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASSWORD);
        await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click('#wp-submit')]);

        results.tables1280 = await everyTab(page, 1280);
        results.summary = await summaryEqualsTotals(page);
        results.csv = await csv(page);
        results.targets = await targets(page);
        results.runs = await runReports(page);
        results.tables390 = await everyTab(page, 390);
        check(errors.length === 0, 'the browser reported errors: ' + errors.join(' | '));
        console.log(JSON.stringify({ ok: true, results }, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
