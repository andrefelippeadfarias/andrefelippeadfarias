'use strict';

/*
 * The distributed zip on a clean WordPress, as a customer would use it (bin/e2e-zip.sh runs the
 * passes in this order, on the throwaway site of bin/wp-test-site.sh and its simulator):
 *
 *   install   Plugins > Add New > Upload Plugin with the zip, "Activate Plugin"; the Plugins row
 *             shows the version of the zip; every screen of the plugin opens without error before
 *             any setup
 *   wizard    the setup wizard up to the first shadow run against the simulator, which receives
 *             only reads
 *   screens   every screen and tab of the plugin's menu opens without error, in the language of
 *             the administrator (RITMO_E2E_LANG: en or pt_BR; in pt_BR the menu is in Portuguese)
 *   remove    Settings > Uninstall: "delete the data", then Deactivate and Delete on the Plugins
 *             screen; the plugin is gone from the list and from the disk
 *
 *   RITMO_E2E_ZIP=<zip> RITMO_E2E_ZIP_VERSION=1.0.0 node tests/e2e/zip-install.js install
 *
 * Every page is checked for PHP errors, WordPress' error page, "not allowed", JavaScript errors and
 * one h1 in the plugin's wrapper. Screenshots go to RITMO_E2E_SCREENS (zip-*.png).
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const API = process.env.RITMO_E2E_API || 'http://127.0.0.1:8091';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const ZIP = process.env.RITMO_E2E_ZIP || '';
const VERSION = process.env.RITMO_E2E_ZIP_VERSION || '';
const LANG = process.env.RITMO_E2E_LANG || 'en';
const SHOTS = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const ROW = 'tr[data-slug="ritmo-revenue"]';

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function submitAndWait(page, selector) {
    await Promise.all([page.waitForNavigation({ timeout: 120000 }), page.click(selector)]);
}

async function login(page) {
    await page.goto(SITE + '/wp-login.php');
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASSWORD);
    await submitAndWait(page, '#wp-submit');
    check(page.url().includes('/wp-admin'), 'the administrator could not log in');
}

/* What any page of the site must not show. */
async function pageProblems(page, name) {
    const report = await page.evaluate(() => ({
        text: document.body ? document.body.innerText : '',
        errorPage: document.body ? document.body.id === 'error-page' || document.body.classList.contains('wp-die-message') : false,
    }));
    check(!report.errorPage, name + ': WordPress showed its error page: ' + report.text.slice(0, 300));
    check(!/There has been a critical error|Sorry, you are not allowed|Você não tem permissão/.test(report.text), name + ': ' + report.text.slice(0, 300));
    check(!/(Fatal error|Parse error|Warning|Notice|Deprecated): .+ in \/.+ on line \d+/.test(report.text), name + ': a PHP message is on the page');
}

async function plainPage(page, url, name) {
    const response = await page.goto(url);
    check(response !== null && response.status() < 400, name + ': HTTP ' + (response ? response.status() : 'none') + ' for ' + url);
    await pageProblems(page, name);
}

/* A screen of the plugin: the checks of any page, then exactly one h1 in the plugin's wrapper. */
async function screen(page, url, name) {
    await plainPage(page, url, name);
    const h1 = await page.locator('.wrap.ritmo h1').count();
    check(h1 === 1, name + ': ' + h1 + ' h1 headings in the plugin\'s screen (' + url + ')');
    const text = await page.locator('.wrap.ritmo').first().innerText();
    check(!/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/.test(text), name + ': a PHP message is in the plugin\'s screen');
    return (await page.locator('.wrap.ritmo h1').innerText()).trim();
}

/* The entries of the plugin's menu, as WordPress printed them. */
async function menu(page) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue');
    return page.locator('#toplevel_page_ritmo-revenue .wp-submenu li a').evaluateAll((links) => links.map((a) => ({ text: a.textContent.replace(/\s+\d+.*$/, '').trim(), href: a.href })));
}

/* Every screen and every tab of the menu; returns the h1 of each URL. */
async function everyScreen(page, label) {
    const entries = await menu(page);
    check(entries.length >= 12, label + ': the plugin\'s menu has ' + entries.length + ' entries');
    const seen = new Map();
    const queue = entries.map((e) => e.href);
    while (queue.length > 0) {
        const url = queue.shift();
        if (seen.has(url)) {
            continue;
        }
        const name = label + ' ' + new URL(url).search.replace(/^\?page=/, '');
        seen.set(url, await screen(page, url, name));
        const tabs = await page.locator('.wrap.ritmo .nav-tab-wrapper a.nav-tab').evaluateAll((links) => links.map((a) => a.href));
        for (const tab of tabs) {
            if (!seen.has(tab) && !queue.includes(tab) && new URL(tab).searchParams.get('page').startsWith('ritmo-revenue')) {
                queue.push(tab);
            }
        }
    }
    await page.goto(entries[0].href);
    await page.screenshot({ path: path.join(SHOTS, 'zip-' + label + '-overview.png'), fullPage: true });
    return { entries, seen };
}

async function simulatorRequests() {
    const body = await (await fetch(API + '/__log')).json();
    return body.requests.filter((request) => !request.path.startsWith('/__'));
}

function writes(requests) {
    return requests.filter((r) => r.method !== 'GET' && !(r.method === 'POST' && r.path === '/pricelabs/v1/listing_prices'));
}

async function install(page) {
    check(ZIP !== '' && fs.existsSync(ZIP), 'RITMO_E2E_ZIP must name the zip to install');
    check(/^\d+\.\d+\.\d+/.test(VERSION), 'RITMO_E2E_ZIP_VERSION must name the version of the zip');

    await plainPage(page, SITE + '/wp-admin/plugins.php', 'plugins before');
    check(await page.locator(ROW).count() === 0, 'the clean site already lists the plugin');

    /* WordPress shows the site's upload limit on Media > Add New (the plugin upload form does not). */
    await plainPage(page, SITE + '/wp-admin/media-new.php', 'media upload form');
    const limit = await page.locator('.max-upload-size').first().innerText().catch(() => '');
    const mb = Number((limit.match(/([\d.]+)\s*MB/) || [0, 'NaN'])[1]);
    const size = fs.statSync(ZIP).size;
    check(!Number.isNaN(mb) && size < mb * 1024 * 1024, 'the zip (' + size + ' bytes) is larger than the upload limit of the site: ' + limit);
    await plainPage(page, SITE + '/wp-admin/plugin-install.php?tab=upload', 'upload form');
    await page.setInputFiles('#pluginzip', ZIP);
    await submitAndWait(page, '#install-plugin-submit');
    await pageProblems(page, 'upload result');
    const result = await page.locator('#wpbody-content').innerText();
    check(/Plugin installed successfully/.test(result), 'the upload did not install the plugin: ' + result.slice(0, 400));
    await page.screenshot({ path: path.join(SHOTS, 'zip-uploaded.png'), fullPage: true });

    await submitAndWait(page, '#wpbody-content a[href*="action=activate"]');
    await pageProblems(page, 'activation');
    check(page.url().includes('plugins.php'), 'the activation did not return to the Plugins screen: ' + page.url());
    const row = page.locator(ROW);
    check(await row.count() === 1, 'the Plugins screen does not list the plugin after the upload');
    check(/\bactive\b/.test(await row.getAttribute('class')), 'the plugin is not active after "Activate Plugin"');
    const details = await row.innerText();
    check(details.includes('Version ' + VERSION), 'the Plugins row does not show version ' + VERSION + ': ' + details.slice(0, 300));
    await page.screenshot({ path: path.join(SHOTS, 'zip-activated.png'), fullPage: true });

    const { entries, seen } = await everyScreen(page, 'fresh');
    return { size, upload_limit: limit, menu: entries.map((e) => e.text), screens: seen.size };
}

async function currentStep(page) {
    return Number(((await page.locator('[data-ritmo-current="1"]').getAttribute('data-ritmo')) || '').replace('wizard-step-', ''));
}

async function setupStep(page, step) {
    await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue-settings&tab=setup' + (step ? '&step=' + step : '') + '&property=' + PROPERTY);
}

async function wizard(page) {
    const before = (await simulatorRequests()).length;
    await plainPage(page, SITE + '/wp-admin/admin.php?page=ritmo-revenue-settings&tab=setup', 'wizard');
    check(await currentStep(page) === 2, 'wizard: a fresh install does not open on the PMS step');

    await page.fill('#ritmo-w-token', 'sim-refresh-token-0001');
    await page.fill('#ritmo-w-ids', '901001');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 3, 'wizard: the PMS step did not go on: ' + (await page.locator('.wrap.ritmo').innerText()).slice(0, 300));

    await page.fill('#ritmo-w-key', 'sim-pricelabs-key-0001');
    await page.fill('#ritmo-w-pms', 'beds24');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 4, 'wizard: the pricing step did not go on');

    await page.fill('#ritmo-p-name', 'Example Inn');
    await page.locator('details.ritmo-advanced summary').click();
    await page.fill('#ritmo-p-id', PROPERTY);
    await page.selectOption('#ritmo-p-tz', 'America/Sao_Paulo');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 5, 'wizard: the property step did not go on');

    /* The simulator's three rooms are suggested from the listings; a further row is left empty for a new room. */
    const units = { 902001: '4', 902002: '2', 902003: '1' };
    const rows = await page.locator('[data-ritmo="room-row"]').count();
    const suggested = [];
    for (let i = 0; i < rows; i++) {
        const pms = await page.inputValue('#ritmo-r-' + i + '-pms_ref');
        if (pms === '') {
            continue;
        }
        check(units[pms] !== undefined, 'wizard: room row ' + i + ' suggests an unknown PMS room id (' + pms + ')');
        await page.fill('#ritmo-r-' + i + '-units', units[pms]);
        suggested.push(pms);
    }
    check(suggested.length === 3, 'wizard: ' + suggested.length + ' rooms suggested from the listings');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 6, 'wizard: the rooms step did not go on');
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 7, 'wizard: the rules step did not go on');
    await page.fill('#ritmo-s-confirm', PROPERTY);
    await submitAndWait(page, '[data-ritmo="wizard-continue"]');
    check(await currentStep(page) === 8, 'wizard: the safety step did not go on');

    await submitAndWait(page, '[data-ritmo="run-shadow"]');
    await pageProblems(page, 'shadow run');
    await setupStep(page, 0);
    check(await currentStep(page) === 9, 'wizard: the first shadow run did not lead to "what the plugin would do"');
    const summary = (await page.locator('[data-ritmo="would-do-summary"]').innerText()).trim();
    check(/would change \d+ night/.test(summary), 'wizard: no summary of the shadow run: ' + summary.slice(0, 300));
    await page.screenshot({ path: path.join(SHOTS, 'zip-wizard-would-do.png'), fullPage: true });

    const requests = (await simulatorRequests()).slice(before);
    check(requests.length > 0, 'wizard: the simulator received no request');
    check(writes(requests).length === 0, 'wizard: the simulator was written to: ' + JSON.stringify(writes(requests).slice(0, 3)));
    return { summary, reads: requests.length };
}

async function screens(page) {
    const { entries, seen } = await everyScreen(page, LANG);
    const titles = entries.map((e) => e.text);
    if (LANG === 'pt_BR') {
        for (const word of ['Visão geral', 'Noites', 'Aprovações', 'Configurações']) {
            check(titles.includes(word), 'pt_BR: the menu has no "' + word + '": ' + titles.join(', '));
        }
        check(!titles.includes('Overview') && !titles.includes('Settings'), 'pt_BR: the menu is still in English: ' + titles.join(', '));
    } else {
        check(titles.includes('Overview') && titles.includes('Settings'), 'en: the menu is not in English: ' + titles.join(', '));
    }
    return { menu: titles, screens: seen.size };
}

async function remove(page) {
    await plainPage(page, SITE + '/wp-admin/admin.php?page=ritmo-revenue-settings&tab=uninstall', 'uninstall tab');
    await page.check('.wrap.ritmo input[name="delete_data"]');
    await submitAndWait(page, '.wrap.ritmo form:has(input[name="delete_data"]) button[type="submit"]');
    await pageProblems(page, 'uninstall choice');
    check(await page.locator('.wrap.ritmo input[name="delete_data"]').isChecked(), 'the choice "delete the data" was not saved');

    await plainPage(page, SITE + '/wp-admin/plugins.php', 'plugins');
    await submitAndWait(page, ROW + ' a[href*="action=deactivate"]');
    await pageProblems(page, 'deactivation');
    check(!/\bactive\b/.test(await page.locator(ROW).getAttribute('class')), 'the plugin is still active after "Deactivate"');

    page.once('dialog', (dialog) => dialog.accept());
    await page.click(ROW + ' .delete a');
    /* WordPress deletes by Ajax and replaces the row with a "was successfully deleted" row. */
    await page.waitForSelector(ROW + '.plugin-deleted-tr', { timeout: 120000 });
    const deleted = await page.locator(ROW + '.plugin-deleted-tr').innerText();
    check(/successfully deleted/.test(deleted), 'the Plugins screen did not confirm the deletion: ' + deleted.slice(0, 200));
    await page.screenshot({ path: path.join(SHOTS, 'zip-deleted.png'), fullPage: true });
    await plainPage(page, SITE + '/wp-admin/plugins.php', 'plugins after');
    check(await page.locator(ROW).count() === 0, 'the Plugins screen still lists the plugin after "Delete"');
    return { deleted: true };
}

(async () => {
    const pass = process.argv[2] || '';
    const passes = { install, wizard, screens, remove };
    check(passes[pass] !== undefined, 'Usage: node tests/e2e/zip-install.js install|wizard|screens|remove');
    fs.mkdirSync(SHOTS, { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ deviceScaleFactor: 1, viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/the server responded with a status of 4\d\d/.test(message.text())) {
            errors.push(message.text());
        }
    });
    try {
        await login(page);
        const result = await passes[pass](page);
        check(errors.length === 0, pass + ': the browser reported errors: ' + errors.join(' | '));
        console.log(JSON.stringify({ ok: true, pass, result }));
    } catch (error) {
        await page.screenshot({ path: path.join(SHOTS, 'zip-failure-' + pass + '.png'), fullPage: true }).catch(() => null);
        throw error;
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
