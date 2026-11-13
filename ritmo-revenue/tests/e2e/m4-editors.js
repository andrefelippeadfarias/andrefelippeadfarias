'use strict';

/*
 * The editors and the market of M4 (admin spec 5.5-5.9, market spec 06 part A) on the throwaway
 * site: a rule changed, reviewed and saved as a new version; a safety limit that needs the typed
 * value; a relation loop refused; national holidays imported into the calendar; an owner order
 * given and ended; a competitor, a price file previewed with its bad lines and imported, the
 * market exports; and on every screen at 1280 and 390 px the cross-screen checks (no raw JSON, no
 * internal names, no sideways scroll, labels, captions, one h1, no PHP error).
 *
 * The site must register the rules, orders and market screens and their form actions (see
 * docs/EDITORS.md, "Registration").
 *
 *   RITMO_E2E_SITE=http://127.0.0.1:8090 node tests/e2e/m4-editors.js
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const SHOTS = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const INTERNAL = ['weekly_listing_change_max', 'never_raise_today', 'min_competitors_per_stay', 'dedup_key', 'bad_source', 'night_type', 'at_least', 'rules_changed', 'series_unknown', 'comparable_factor', 'stay_nights', 'check_in_mask'];

const SCREENS = [
    ['rules-general', 'ritmo-revenue-rules'],
    ['rules-safety', 'ritmo-revenue-rules&tab=safety'],
    ['rules-relations', 'ritmo-revenue-rules&tab=relations'],
    ['rules-calendar', 'ritmo-revenue-rules&tab=calendar'],
    ['rules-versions', 'ritmo-revenue-rules&tab=versions'],
    ['rules-review', 'ritmo-revenue-rules&tab=review'],
    ['orders', 'ritmo-revenue-orders'],
    ['orders-new', 'ritmo-revenue-orders&tab=new&type=floor'],
    ['market', 'ritmo-revenue-market'],
    ['market-competitors', 'ritmo-revenue-market&tab=competitors'],
    ['market-prices', 'ritmo-revenue-market&tab=prices'],
    ['market-import', 'ritmo-revenue-market&tab=import'],
    ['market-export', 'ritmo-revenue-market&tab=export'],
];

const results = [];

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function open(page, slug) {
    await page.goto(SITE + '/wp-admin/admin.php?page=' + slug + '&property=' + PROPERTY);
}

async function submit(page, selector) {
    await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click(selector)]);
}

async function notice(page) {
    const box = page.locator('[data-ritmo="notice"]');
    return (await box.count()) > 0 ? (await box.innerText()).trim() : '';
}

/* The cross-screen checks of admin spec 11.2 on the page as it is. */
async function crossChecks(page, name, width) {
    const report = await page.evaluate((internal) => {
        const wrap = document.querySelector('.wrap.ritmo');
        const text = wrap ? wrap.innerText : '';
        const unlabeled = [];
        wrap.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((el) => {
            if (el.offsetParent === null) {
                return;
            }
            const id = el.getAttribute('id');
            const label = (id && document.querySelector('label[for="' + id + '"]')) || el.closest('label') || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
            if (!label) {
                unlabeled.push(el.outerHTML.slice(0, 100));
            }
        });
        const tables = Array.from(wrap.querySelectorAll('table')).filter((t) => !t.querySelector('caption') && !t.getAttribute('aria-label'));
        const nameless = Array.from(wrap.querySelectorAll('a, button')).filter((el) => el.offsetParent !== null && (el.textContent || el.getAttribute('aria-label') || '').trim() === '');
        return {
            json: /\{\s*"[A-Za-z_]+"\s*:/.test(text),
            internal: internal.filter((word) => text.includes(word)),
            h1: wrap.querySelectorAll('h1').length,
            scroll: document.documentElement.scrollWidth - window.innerWidth,
            unlabeled,
            tables: tables.length,
            nameless: nameless.map((el) => el.outerHTML.slice(0, 80)),
            php: /(Fatal error|Warning:|Notice:|Deprecated:)/.test(text),
        };
    }, INTERNAL);
    check(!report.json, name + ': raw JSON on the screen');
    check(report.internal.length === 0, name + ': internal names on the screen: ' + report.internal.join(', '));
    check(report.h1 === 1, name + ': ' + report.h1 + ' h1 headings');
    check(width > 400 || report.scroll <= 1, name + ': the page scrolls sideways at 390 px by ' + report.scroll + ' px');
    check(report.unlabeled.length === 0, name + ': fields without a label: ' + report.unlabeled.join(' | '));
    check(report.tables === 0, name + ': a table without caption');
    check(report.nameless.length === 0, name + ': links or buttons without text: ' + report.nameless.join(' | '));
    check(!report.php, name + ': a PHP error is on the screen');
}

async function everyScreen(page, width) {
    await page.setViewportSize({ width, height: width > 400 ? 900 : 844 });
    for (const [name, slug] of SCREENS) {
        await open(page, slug);
        await crossChecks(page, name, width);
        await page.screenshot({ path: path.join(SHOTS, 'm4-' + name + '-' + width + '.png'), fullPage: true });
    }
}

async function versionCount(page) {
    await open(page, 'ritmo-revenue-rules&tab=versions');
    return page.locator('[data-ritmo="versions-table"] tbody tr').count();
}

/* AC-RULES: a goal changed, kept in the draft, reviewed and saved as a new version in force. */
async function ruleVersion(page) {
    const before = await versionCount(page);
    await open(page, 'ritmo-revenue-rules');
    const field = page.getByLabel('Next 7 days');
    const old = await field.inputValue();
    const next = String(Number(old) + 5);
    await field.fill(next);
    await submit(page, '[data-ritmo="keep-draft"]');
    check((await notice(page)).includes('draft'), 'keeping a change in the draft said: ' + (await notice(page)));
    check(await page.locator('[data-ritmo="draft-bar"]').isVisible(), 'no draft bar after a change was kept');

    await open(page, 'ritmo-revenue-rules&tab=review');
    const row = page.locator('[data-ritmo="review-row"]', { hasText: 'Next 7 days' });
    check((await row.count()) === 1, 'the review does not list the changed goal');
    const rowText = await row.innerText();
    check(rowText.includes(old) && rowText.includes(next), 'the review row does not show both values: ' + rowText);
    await page.fill('#ritmo-note', 'End-to-end: goal of the next 7 days');
    await submit(page, '[data-ritmo="save-version"]');
    check(/version/i.test(await notice(page)), 'saving said: ' + (await notice(page)));

    const after = await versionCount(page);
    check(after === before + 1, 'saving did not add one version (' + before + ' then ' + after + ')');
    const top = await page.locator('[data-ritmo="versions-table"] tbody tr').first().innerText();
    check(top.includes('In force') && top.includes('End-to-end: goal of the next 7 days'), 'the new version is not the one in force: ' + top);
    return { versions: after };
}

/* AC-RULES safety: a safety limit needs the typed value; a wrong one is refused by the server. */
async function safetyLimit(page) {
    await open(page, 'ritmo-revenue-rules&tab=safety');
    const field = page.getByLabel('Largest weekly change of a listing');
    const next = String(Number(await field.inputValue()) + 2);
    await field.fill(next);
    await submit(page, '[data-ritmo="keep-draft"]');

    await open(page, 'ritmo-revenue-rules&tab=review');
    check(await page.locator('[data-ritmo="safety-confirm"]').isVisible(), 'a safety change shows no typed confirmation');
    const expected = await page.locator('#ritmo-confirmation').getAttribute('data-expected');
    // The browser keeps the button off until the text matches: the server must refuse a wrong one too.
    await page.fill('#ritmo-confirmation', 'wrong');
    await page.evaluate(() => document.querySelector('[data-ritmo="save-version"]').removeAttribute('disabled'));
    await submit(page, '[data-ritmo="save-version"]');
    check((await notice(page)).length > 0 && !/saved as version/i.test(await notice(page)), 'a wrong confirmation was not refused: ' + (await notice(page)));

    await open(page, 'ritmo-revenue-rules&tab=review');
    await page.fill('#ritmo-confirmation', expected);
    await submit(page, '[data-ritmo="save-version"]');
    check(/version/i.test(await notice(page)), 'the typed confirmation did not save: ' + (await notice(page)));
    await open(page, 'ritmo-revenue-rules&tab=safety');
    check((await page.getByLabel('Largest weekly change of a listing').inputValue()) === next, 'the safety limit in force is not the new one');
    return { safety_confirmation: expected };
}

async function addRelation(page, target, reference) {
    await open(page, 'ritmo-revenue-rules&tab=relations&add=at_least');
    await page.selectOption('[data-ritmo="relation-form"] select[name="rel[target]"]', target);
    await page.selectOption('[data-ritmo="relation-form"] select[name="rel[params][reference]"]', reference);
    await submit(page, '[data-ritmo="relation-form"] button[type=submit]');
    return notice(page);
}

/* AC-REL: a relation kept in the draft, its sentence; the opposite one is a loop and refused. */
async function relations(page) {
    const kept = await addRelation(page, 'GDN', 'STD');
    check(kept.includes('draft'), 'the relation was not kept: ' + kept);
    const sentence = await page.locator('[data-ritmo="relation-sentence"]').first().innerText();
    check(sentence.length > 10, 'the relation has no sentence');
    const loop = await addRelation(page, 'STD', 'GDN');
    check(/loop/i.test(loop), 'a loop was not refused: ' + loop);
    check((await page.locator('[data-ritmo="relation-card"]').count()) === 1, 'the refused relation was kept');
    await submit(page, '[data-ritmo="discard-draft"]');
    return { relation: sentence };
}

/* AC-CAL: the national holidays of next year previewed and added to the draft. */
async function holidays(page) {
    const year = String(new Date().getFullYear() + 1);
    await open(page, 'ritmo-revenue-rules&tab=calendar');
    const form = page.locator('[data-ritmo="holiday-import"]');
    await form.locator('select[name=country]').selectOption('BR');
    for (const box of await form.locator('input[name="years[]"]').all()) {
        await box.setChecked((await box.getAttribute('value')) === year);
    }
    await submit(page, '[data-ritmo="holiday-import"] button[type=submit]');
    const proposals = await page.locator('[data-ritmo="proposal-row"]').count();
    check(proposals >= 8, 'only ' + proposals + ' holidays proposed for ' + year);
    await submit(page, 'form:has([data-ritmo="proposal-row"]) button.button-primary');
    check((await notice(page)).includes('draft'), 'the holidays were not kept: ' + (await notice(page)));
    const periods = await page.locator('[data-ritmo="holiday-row"]').count();
    check(periods >= 8, 'only ' + periods + ' holiday periods in the draft');
    await submit(page, '[data-ritmo="discard-draft"]');
    return { holidays_proposed: proposals, periods };
}

/* AC-ORD: a floor order for two nights, listed in force, then ended. */
async function orders(page) {
    const from = new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10);
    const to = new Date(Date.now() + 21 * 86400000).toISOString().slice(0, 10);
    // Unique per run, in letters: a long number in a note is refused as a possible phone number.
    const note = 'End-to-end floor ' + String(Date.now()).replace(/\d/g, (d) => 'abcdefghij'[d]);
    await open(page, 'ritmo-revenue-orders&tab=new&type=floor');
    const form = page.locator('[data-ritmo="order-form"]');
    await form.locator('input[name="order[from]"]').fill(from);
    await form.locator('input[name="order[to]"]').fill(to);
    await form.locator('input[name="order[rooms][]"][value="STD"]').check();
    await form.locator('input[name="order[floors][STD]"]').fill('450');
    await form.locator('input[name="order[note]"], textarea[name="order[note]"]').first().fill(note);
    await submit(page, '[data-ritmo="order-form"] button.button-primary');
    const saved = await notice(page);
    check(/order/i.test(saved), 'the order was not saved: ' + saved);
    await open(page, 'ritmo-revenue-orders');
    const row = page.locator('[data-ritmo="order-row"]', { hasText: note });
    check((await row.count()) === 1, 'the order is not in force; saving said: ' + saved);
    await submit(page, `[data-ritmo="order-row"]:has-text("${note}") [data-ritmo="order-end"]`);
    await open(page, 'ritmo-revenue-orders&tab=ended');
    check((await page.locator('[data-ritmo="order-row"]', { hasText: note }).count()) === 1, 'the ended order is not listed as ended');
    return { order: 'floor given and ended' };
}

/* AC-MKT: a competitor, a price file with bad lines previewed then imported, the exports. */
async function market(page) {
    await open(page, 'ritmo-revenue-market&tab=competitors');
    await page.fill('#ritmo-c-name', 'Example Lodge');
    await page.fill('#ritmo-c-tags', 'peer');
    await submit(page, 'form:has(#ritmo-c-name) button.button-primary');
    check((await page.locator('[data-ritmo="competitor-row"]', { hasText: 'Example Lodge' }).count()) === 1, 'the competitor is not listed');

    const day = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
    const lines = [
        'competitor,check_in,check_out,price,price_basis,room,captured_at',
        // A price that changes per run: the same file twice is refused as already imported.
        `example-lodge,${day(10)},${day(11)},${500 + (Date.now() % 97)},total,comparable,${day(0)}`,
        `example-lodge,${day(11)},${day(13)},1100,total,comparable,${day(0)}`,
        `example-lodge,${day(12)},${day(11)},500,total,comparable,${day(0)}`,
        `example-lodge,${day(14)},${day(15)},=1+1,total,comparable,${day(0)}`,
    ];
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'ritmo-e2e-')), 'prices.csv');
    fs.writeFileSync(file, lines.join('\n') + '\n');
    await open(page, 'ritmo-revenue-market&tab=import');
    await page.setInputFiles('#ritmo-prices-file', file);
    await submit(page, '[data-ritmo="csv-upload"] button.button-primary');
    const valid = await page.locator('[data-ritmo="csv-preview-valid"] tbody tr').count();
    const invalid = await page.locator('[data-ritmo="csv-preview-invalid"] li').count();
    check(valid === 2 && invalid >= 2, 'the preview shows ' + valid + ' valid and ' + invalid + ' invalid lines');
    await submit(page, '[data-ritmo="csv-import"]');
    check(/prices? recorded/i.test(await notice(page)), 'the import said: ' + (await notice(page)));

    await open(page, 'ritmo-revenue-market&tab=prices');
    await page.selectOption('[data-ritmo="price-filters"] select[name=competitor]', { label: 'Example Lodge' });
    await submit(page, '[data-ritmo="price-filters"] button[type=submit]');
    check((await page.locator('tr', { hasText: 'Example Lodge' }).count()) >= 2, 'the imported prices are not listed');

    await open(page, 'ritmo-revenue-market&tab=export');
    const exported = {};
    for (const form of await page.locator('[data-ritmo="market-export"]').all()) {
        const [download] = await Promise.all([page.waitForEvent('download'), form.locator('button[type=submit]').click()]);
        const body = fs.readFileSync(await download.path(), 'utf8');
        check(!/^[=+\-@]/m.test(body.split('\n').slice(1).map((l) => l.split(',')[0]).join('\n')), 'an exported cell starts like a formula');
        exported[download.suggestedFilename()] = body.split('\n').filter((l) => l !== '').length;
    }
    check(Object.keys(exported).length === 3, 'not every export downloaded: ' + JSON.stringify(exported));
    return { csv_preview: { valid, invalid }, exported };
}

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, acceptDownloads: true });
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
        await submit(page, '#wp-submit');

        results.push(await ruleVersion(page));
        results.push(await safetyLimit(page));
        results.push(await relations(page));
        results.push(await holidays(page));
        results.push(await orders(page));
        results.push(await market(page));
        await everyScreen(page, 1280);
        await everyScreen(page, 390);
        check(errors.length === 0, 'the browser reported errors: ' + errors.join(' | '));
        console.log(JSON.stringify({ ok: true, results }, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
