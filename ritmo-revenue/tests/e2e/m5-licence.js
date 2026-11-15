'use strict';

/*
 * Licence and updates (milestone M5) on the throwaway site, against the local licence and update
 * server (tests/fake-apis/LicenceUpdateApi.php, started here on 127.0.0.1:8093 with a key pair made
 * by bin/sign-release.php keygen in a temporary folder; no key is kept).
 *
 *   RITMO_E2E_WP=<wordpress folder> node tests/e2e/m5-licence.js
 *
 * Licence: Settings > Licence without a key (shadow mode only), a key refused by format, a key saved
 * and activated (write-only: never printed back), the server down (grace, last valid answer kept),
 * the "Check now" cooldown, an expired licence (grace), the grace period over (lapsed: notice on the
 * plugin's screens), a test key outside development mode (sent to the real server, not accepted),
 * removing the key (ticked confirmation, freed on the server), a form without nonce (403), the tab
 * at 390 px.
 * Updates: a release whose package was changed on the server is offered but never installed; a
 * package on another server is never offered; the signed release is installed by WordPress' own
 * updater on a copy of the plugin (the site's link to this folder is put back at the end).
 *
 * The site's wp-config.php has RITMO_DEV (plain http to 127.0.0.1); the server addresses and the
 * public key are defined by a must-use plugin this script writes and removes.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const WP = process.env.RITMO_E2E_WP || '';
const ROOT = path.resolve(__dirname, '..', '..');
const PORT = Number(process.env.RITMO_E2E_LICENCE_PORT || 8093);
const SERVER = 'http://127.0.0.1:' + PORT;
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const SHOTS = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');
const LICENCE_TAB = SITE + '/wp-admin/admin.php?page=ritmo-revenue-settings&tab=licence';
const PLUGIN_DIR = path.join(WP, 'wp-content', 'plugins', 'ritmo-revenue');
const MU_PLUGIN = path.join(WP, 'wp-content', 'mu-plugins', 'ritmo-e2e-licence.php');
const KEY = 'RR-E2E-VALID-0001';
const RELEASE = '9.9.0';

const results = [];
let simulator = null;
let work = '';

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function iso(days) {
    return new Date(Date.now() + days * 86400000).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

async function control(method, route, body) {
    const response = await fetch(SERVER + route, { method, body: body === undefined ? undefined : JSON.stringify(body) });
    check(response.status === 200, method + ' ' + route + ' answered ' + response.status);
    return response.json();
}

/* The requests of the licence protocol (WordPress also asks the update channel on its own). */
async function licenceLog() {
    return (await control('GET', '/__log')).requests.filter((r) => r.path.startsWith('/licence/'));
}

async function updateLog() {
    return (await control('GET', '/__log')).requests.filter((r) => r.path.startsWith('/updates/'));
}

async function startSimulator() {
    work = fs.mkdtempSync(path.join(os.tmpdir(), 'ritmo-m5-'));
    const publicKey = execFileSync('php', [path.join(ROOT, 'bin', 'sign-release.php'), 'keygen', '--out', path.join(work, 'signing.key')], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
    const secret = fs.readFileSync(path.join(work, 'signing.key'), 'utf8').trim();
    simulator = spawn('php', ['-S', '127.0.0.1:' + PORT, path.join(ROOT, 'tests', 'fake-apis', 'LicenceUpdateApi.php')], {
        env: { ...process.env, RITMO_FAKE_LICENCE_STATE: path.join(work, 'state.json'), RITMO_FAKE_UPDATE_SECRET_KEY: secret },
        stdio: 'ignore',
    });
    for (let attempt = 0; attempt < 50; attempt++) {
        try {
            const answer = await control('GET', '/__public-key');
            check(answer.public_key === publicKey, 'the simulator signs with the key pair made by keygen');
            return publicKey;
        } catch (error) {
            if (attempt === 49) {
                throw error;
            }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }
    return publicKey;
}

function writeMuPlugin(publicKey) {
    fs.mkdirSync(path.dirname(MU_PLUGIN), { recursive: true });
    fs.writeFileSync(MU_PLUGIN, [
        '<?php',
        '// Written by tests/e2e/m5-licence.js for the throwaway site only.',
        "define('RITMO_LICENCE_SERVER_URL', '" + SERVER + "/licence');",
        "define('RITMO_UPDATE_URL', '" + SERVER + "/updates/latest.json');",
        "define('RITMO_UPDATE_PUBLIC_KEY', '" + publicKey + "');",
        '',
    ].join('\n'));
}

async function login(page) {
    await page.goto(SITE + '/wp-login.php');
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASSWORD);
    await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click('#wp-submit')]);
}

async function submit(page, selector) {
    await Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click(selector)]);
}

async function standing(page) {
    return page.locator('[data-ritmo="licence-standing"]').getAttribute('data-standing');
}

async function noticeText(page) {
    return (await page.locator('.wrap.ritmo .notice').first().innerText()).trim();
}

async function shot(page, name) {
    await page.screenshot({ path: path.join(SHOTS, 'm5-' + name + '.png'), fullPage: true });
}

async function step(name, body) {
    await body();
    results.push(name);
    console.log('ok   ' + name);
}

async function licencePass(page, context) {
    await step('without a key the plugin works in shadow mode only and says so', async () => {
        await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue');
        check(await page.locator('[data-ritmo="licence-notice"][data-standing="unlicensed"]').count() === 1, 'the overview tells that there is no licence');
        await page.goto(LICENCE_TAB);
        check(await standing(page) === 'unlicensed', 'no licence');
        check(await page.locator('[data-ritmo="licence-notice"]').count() === 0, 'no notice on the licence tab itself');
        check(await page.locator('[data-ritmo="licence-key"]').getAttribute('type') === 'password', 'the key field is a password field');
        check(await page.locator('[data-ritmo="licence-check"]').count() === 0, 'no "Check now" without a key');
        check((await page.locator('[data-ritmo="licence-consequence"]').innerText()).includes('shadow mode'), 'what no licence means');
        await shot(page, 'licence-none');
    });

    await step('a text that cannot be a key is refused and nothing is sent', async () => {
        await page.fill('[data-ritmo="licence-key"]', 'not a key');
        await submit(page, '[data-ritmo="licence-save"]');
        check((await noticeText(page)).includes('8 to 128'), 'the format is explained');
        check((await licenceLog()).length === 0, 'nothing reached the licence server');
    });

    await step('a key is saved, activated and never printed back', async () => {
        await control('POST', '/__licence', { key: KEY, status: 'valid', expires_at: iso(365), max_sites: 1 });
        await page.goto(LICENCE_TAB);
        await page.fill('[data-ritmo="licence-key"]', KEY);
        await submit(page, '[data-ritmo="licence-save"]');
        check(await standing(page) === 'active', 'active after activation');
        check((await page.locator('[data-ritmo="licence-expires"]').innerText()).trim() !== '', 'the end date is shown');
        check(await page.locator('[data-ritmo="licence-key"]').inputValue() === '', 'the field stays empty');
        check(!(await page.content()).includes(KEY), 'the key is nowhere in the page');
        const log = await licenceLog();
        check(log.length === 1 && log[0].path === '/licence/activate' && log[0].status === 200, 'one activation');
        check(log[0].site === SITE, 'activated for this site');
        await shot(page, 'licence-active');
        await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue');
        check(await page.locator('[data-ritmo="licence-notice"]').count() === 0, 'no notice while the licence is active');
    });

    await step('"Check now" has a cooldown', async () => {
        await page.goto(LICENCE_TAB);
        await submit(page, '[data-ritmo="licence-check"]');
        check((await noticeText(page)).includes('moments ago'), 'the cooldown is explained');
        check((await licenceLog()).length === 1, 'the check right after the activation was not sent');
    });

    await step('the server down keeps the last valid answer for the grace period', async () => {
        await page.waitForTimeout(31000);
        await control('POST', '/__mode', { mode: 'down' });
        await page.goto(LICENCE_TAB);
        await submit(page, '[data-ritmo="licence-check"]');
        check(await standing(page) === 'grace', 'grace while the server is down');
        check((await page.locator('[data-ritmo="licence-reason"]').innerText()).includes('could not be reached'), 'the reason in words');
        check((await page.locator('[data-ritmo="licence-grace-ends"]').innerText()).trim() !== '', 'the end of the grace period');
        await shot(page, 'licence-grace');
        await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue');
        check(await page.locator('[data-ritmo="licence-notice"][data-standing="grace"]').count() === 1, 'a warning on the plugin screens');
        check(await page.locator('[data-ritmo="licence-notice"]').first().evaluate((el) => el.classList.contains('notice-warning')), 'as a warning');
    });

    await step('an expired licence keeps working during the grace period, then lapses', async () => {
        await control('POST', '/__mode', { mode: 'up' });
        await control('POST', '/__licence', { key: KEY, status: 'expired', expires_at: iso(-2), max_sites: 1 });
        await page.goto(LICENCE_TAB);
        await page.fill('[data-ritmo="licence-key"]', KEY);
        await submit(page, '[data-ritmo="licence-save"]');
        check(await standing(page) === 'grace', 'grace after expiry');
        check((await page.locator('[data-ritmo="licence-reason"]').innerText()).includes('expired'), 'expired in words');
        execFileSync('php', [path.join(__dirname, 'licence-age.php'), WP, '31'], { stdio: 'ignore' });
        await page.goto(LICENCE_TAB);
        check(await standing(page) === 'lapsed', 'lapsed 31 days later');
        check((await page.locator('[data-ritmo="licence-consequence"]').innerText()).includes('shadow mode'), 'what it means for the properties');
        await shot(page, 'licence-lapsed');
        await page.goto(SITE + '/wp-admin/admin.php?page=ritmo-revenue');
        const notice = page.locator('[data-ritmo="licence-notice"][data-standing="lapsed"]');
        check(await notice.count() === 1, 'an error notice on the plugin screens');
        check(await notice.evaluate((el) => el.classList.contains('notice-error')), 'as an error');
        check(await notice.locator('a').count() === 1, 'with the way to the licence settings');
    });

    await step('a test key outside development mode goes to the real server and is not accepted', async () => {
        await page.goto(LICENCE_TAB);
        await page.fill('[data-ritmo="licence-key"]', 'TEST-VALID-0001');
        await submit(page, '[data-ritmo="licence-save"]');
        check(await standing(page) === 'unlicensed', 'not a licence');
        const log = await licenceLog();
        check(log.slice(-2).map((r) => r.path).join(' ') === '/licence/deactivate /licence/activate', 'the earlier key freed, the test key asked to the server');
    });

    await step('removing the key needs the box ticked, then frees it on the server', async () => {
        await control('POST', '/__licence', { key: KEY, status: 'valid', expires_at: iso(365), max_sites: 1 });
        await page.goto(LICENCE_TAB);
        await page.fill('[data-ritmo="licence-key"]', KEY);
        await submit(page, '[data-ritmo="licence-save"]');
        check(await standing(page) === 'active', 'active again');
        await page.locator('[data-ritmo="licence-updates"]').scrollIntoViewIfNeeded();
        await page.click('summary:has-text("Remove the licence key")');
        await page.locator('[data-ritmo="licence-remove-confirm"]').evaluate((el) => el.removeAttribute('required'));
        await submit(page, '[data-ritmo="licence-remove"]');
        check((await noticeText(page)).includes('Tick the box'), 'refused without the box');
        check(await standing(page) === 'active', 'nothing removed');
        await page.click('summary:has-text("Remove the licence key")');
        await page.check('[data-ritmo="licence-remove-confirm"]');
        await submit(page, '[data-ritmo="licence-remove"]');
        check((await noticeText(page)).includes('freed on the licence server'), 'freed');
        check(await standing(page) === 'unlicensed', 'no licence');
        check((await page.locator('[data-ritmo="licence-key-state"]').innerText()).includes('No key'), 'no key saved');
        check((await licenceLog()).slice(-1)[0].path === '/licence/deactivate', 'deactivated on the server');
    });

    await step('a form without its nonce is refused', async () => {
        const answer = await context.request.post(SITE + '/wp-admin/admin-post.php', { form: { action: 'ritmo_licence_check', tab: 'licence' }, maxRedirects: 0 });
        check(answer.status() === 403, 'admin-post.php refused the form without nonce (' + answer.status() + ')');
    });

    await step('the licence tab at 390 px has no sideways scroll', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(LICENCE_TAB);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        check(overflow <= 0, 'no sideways scroll at 390 px (' + overflow + ')');
        await shot(page, 'licence-390');
        await page.setViewportSize({ width: 1280, height: 900 });
    });
}

function buildRelease() {
    const stage = path.join(work, 'stage', 'ritmo-revenue');
    fs.mkdirSync(stage, { recursive: true });
    for (const item of ['src', 'templates', 'assets', 'languages', 'presets', 'ritmo-revenue.php', 'uninstall.php']) {
        if (fs.existsSync(path.join(ROOT, item))) {
            fs.cpSync(path.join(ROOT, item), path.join(stage, item), { recursive: true });
        }
    }
    const main = path.join(stage, 'ritmo-revenue.php');
    const header = fs.readFileSync(main, 'utf8');
    const current = /Version:\s+([0-9.]+)/.exec(header)[1];
    fs.writeFileSync(main, header.split(current).join(RELEASE));
    const zip = path.join(work, 'ritmo-revenue-' + RELEASE + '.zip');
    execFileSync('zip', ['-qr', zip, 'ritmo-revenue'], { cwd: path.join(work, 'stage') });
    return { zip, current };
}

function installedVersion() {
    return /Version:\s+([0-9.]+)/.exec(fs.readFileSync(path.join(PLUGIN_DIR, 'ritmo-revenue.php'), 'utf8'))[1];
}

/* The site links this folder as the plugin; WordPress' updater must work on a copy. */
function replaceLinkWithCopy() {
    check(fs.lstatSync(PLUGIN_DIR).isSymbolicLink(), 'the site links the plugin');
    fs.unlinkSync(PLUGIN_DIR);
    fs.mkdirSync(PLUGIN_DIR);
    for (const item of ['src', 'templates', 'assets', 'languages', 'presets', 'ritmo-revenue.php', 'uninstall.php']) {
        if (fs.existsSync(path.join(ROOT, item))) {
            fs.cpSync(path.join(ROOT, item), path.join(PLUGIN_DIR, item), { recursive: true });
        }
    }
}

function restoreLink() {
    if (!fs.existsSync(PLUGIN_DIR) && !isLink(PLUGIN_DIR)) {
        fs.symlinkSync(ROOT, PLUGIN_DIR);
        return;
    }
    if (!isLink(PLUGIN_DIR)) {
        check(path.resolve(PLUGIN_DIR).startsWith(path.resolve(WP) + path.sep), 'only a copy inside the site is removed');
        fs.rmSync(PLUGIN_DIR, { recursive: true, force: true });
        fs.symlinkSync(ROOT, PLUGIN_DIR);
    }
}

function isLink(file) {
    try {
        return fs.lstatSync(file).isSymbolicLink();
    } catch (error) {
        return false;
    }
}

async function checkForUpdates(page) {
    await page.goto(LICENCE_TAB);
    await submit(page, '[data-ritmo="update-check"]');
    return noticeText(page);
}

async function updateLink(page) {
    await page.goto(SITE + '/wp-admin/plugins.php');
    const row = page.locator('tr.plugin-update-tr[data-plugin="ritmo-revenue/ritmo-revenue.php"]');
    if (await row.count() === 0) {
        return null;
    }
    return row.locator('a.update-link').getAttribute('href');
}

async function updatePass(page) {
    const release = buildRelease();

    await step('a package on another server is never offered', async () => {
        await control('POST', '/__release', { version: RELEASE, zip_path: release.zip, tamper: 'other_origin', changelog: ['Signed updates.'] });
        check((await checkForUpdates(page)).includes('does not come from the update server'), 'refused in words');
        check(await updateLink(page) === null, 'WordPress offers no update');
    });

    await step('a release whose package was changed on the server is never installed', async () => {
        replaceLinkWithCopy();
        await control('POST', '/__release', { version: RELEASE, zip_path: release.zip, tamper: 'zip', changelog: ['Signed updates.'] });
        check((await checkForUpdates(page)).includes(RELEASE), 'the signed metadata is offered');
        const link = await updateLink(page);
        check(link !== null, 'WordPress shows the update');
        await shot(page, 'update-offered');
        await page.goto(new URL(link, SITE + '/wp-admin/').toString());
        await page.waitForLoadState('load');
        const output = await page.locator('.wrap').innerText();
        check(output.includes('does not match its signature'), 'the refusal in words');
        check(!output.includes('updated successfully'), 'nothing installed');
        check(installedVersion() === release.current, 'the installed copy is untouched');
        await shot(page, 'update-refused');
    });

    await step('the signed release is installed by the WordPress updater', async () => {
        await control('POST', '/__release', { version: RELEASE, zip_path: release.zip, tamper: 'none', changelog: ['Signed updates.'] });
        check((await checkForUpdates(page)).includes(RELEASE), 'offered');
        const link = await updateLink(page);
        check(link !== null, 'WordPress shows the update');
        await page.goto(new URL(link, SITE + '/wp-admin/').toString());
        await page.waitForLoadState('load');
        const output = await page.locator('.wrap').innerText();
        check(output.includes('updated successfully'), 'installed: ' + output.slice(0, 400));
        check(installedVersion() === RELEASE, 'the new version is on disk');
        const log = await updateLog();
        check(log.some((r) => r.path === '/updates/packages/ritmo-revenue-' + RELEASE + '.zip' && r.status === 200), 'the package came from the update server');
        await page.goto(LICENCE_TAB);
        check((await page.locator('[data-ritmo="licence-updates"]').innerText()).includes(RELEASE), 'the plugin runs at the new version');
        await shot(page, 'update-installed');
    });
}

(async () => {
    check(WP !== '' && fs.existsSync(path.join(WP, 'wp-load.php')), 'RITMO_E2E_WP must be the WordPress folder of the throwaway site');
    fs.mkdirSync(SHOTS, { recursive: true });
    const browser = await chromium.launch();
    try {
        const publicKey = await startSimulator();
        writeMuPlugin(publicKey);
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        await login(page);
        await licencePass(page, context);
        await updatePass(page);
        console.log('M5 licence and updates: ' + results.length + ' checks passed.');
    } finally {
        await browser.close();
        restoreLink();
        if (fs.existsSync(MU_PLUGIN)) {
            fs.unlinkSync(MU_PLUGIN);
        }
        if (simulator) {
            simulator.kill();
        }
        if (work) {
            fs.rmSync(work, { recursive: true, force: true });
        }
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
