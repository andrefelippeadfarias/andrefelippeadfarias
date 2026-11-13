/**
 * The screens in Portuguese (admin spec 7, AC-X-8), on the throwaway site after the other passes,
 * with the administrator's language set to pt_BR (tests/e2e/locale.php): every screen of the menu
 * and its tabs, at 1280 and 390 px, shows no English sentence of the plugin (none of the texts of
 * languages/ritmo-revenue.pot that are whole sentences), uses the words of the glossary on the
 * main screens, and has no sideways scroll. Screenshots of every screen in pt_BR at both widths.
 *
 *   RITMO_E2E_SITE=http://127.0.0.1:8090 node tests/e2e/m4-i18n.js
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const SITE = process.env.RITMO_E2E_SITE || 'http://127.0.0.1:8090';
const USER = 'ritmo-e2e-admin';
const PASSWORD = 'ritmo-e2e-only-password';
const PROPERTY = 'example-inn';
const SHOTS = process.env.RITMO_E2E_SCREENS || path.join(__dirname, 'screenshots');

const SCREENS = [
    ['ritmo-revenue', ['Visão geral', 'Rodar em sombra agora']],
    ['ritmo-revenue-nights', ['Noites']],
    ['ritmo-revenue-approvals', ['Aprovações']],
    ['ritmo-revenue-alerts', ['Alertas']],
    ['ritmo-revenue-todo', ['Pendências']],
    ['ritmo-revenue-changes', ['Registro de mudanças']],
    ['ritmo-revenue-reports', ['Relatórios']],
    ['ritmo-revenue-market', ['Mercado']],
    ['ritmo-revenue-orders', ['Ordens']],
    ['ritmo-revenue-rules', ['Regras']],
    ['ritmo-revenue-rules&tab=safety', ['Limites de segurança']],
    ['ritmo-revenue-operations', ['Operação', 'Rodar agora', 'Chave de emergência']],
    ['ritmo-revenue-settings&tab=connections', ['Configurações', 'Conexões']],
    ['ritmo-revenue-settings&tab=setup', ['Configurações']],
    ['ritmo-revenue-settings&tab=diagnostics', ['Diagnóstico']],
];

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

/**
 * The English sentences of the plugin: msgids of the .pot with at least four words, no
 * placeholder and that are not the same in Portuguese (names, codes).
 */
function englishSentences() {
    const pot = fs.readFileSync(path.join(__dirname, '../../languages/ritmo-revenue.pot'), 'utf8');
    const po = fs.readFileSync(path.join(__dirname, '../../languages/ritmo-revenue-pt_BR.po'), 'utf8');
    const same = new Set();
    const pairs = po.split(/\n\n/);
    for (const block of pairs) {
        const id = (block.match(/^msgid "(.*)"$/m) || [])[1];
        const str = (block.match(/^msgstr "(.*)"$/m) || [])[1];
        if (id !== undefined && id === str) {
            same.add(id);
        }
    }
    const out = [];
    for (const match of pot.matchAll(/^msgid "(.+)"$/gm)) {
        const text = match[1].replace(/\\"/g, '"');
        if (!/%/.test(text) && text.split(' ').length >= 4 && !same.has(text)) {
            out.push(text);
        }
    }
    return out;
}

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });
    const sentences = englishSentences();
    check(sentences.length > 500, 'only ' + sentences.length + ' English sentences found in the .pot');
    const browser = await chromium.launch();
    const context = await browser.newContext({ deviceScaleFactor: 1 });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const results = [];
    try {
        await page.goto(SITE + '/wp-login.php');
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

        for (const width of [1280, 390]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
            for (const [slug, words] of SCREENS) {
                await page.goto(SITE + '/wp-admin/admin.php?page=' + slug + '&property=' + PROPERTY);
                const text = await page.locator('#wpbody-content').innerText();
                const english = sentences.filter((s) => text.includes(s));
                check(english.length === 0, slug + ' at ' + width + ' px shows English: ' + english.slice(0, 3).join(' | '));
                for (const word of words) {
                    check(text.includes(word), slug + ' at ' + width + ' px lacks "' + word + '"');
                }
                const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
                check(!sideways, slug + ' scrolls sideways at ' + width + ' px');
                const name = 'pt_BR-' + slug.replace(/[^a-z0-9]+/g, '-') + '-' + width + '.png';
                await page.screenshot({ path: path.join(SHOTS, name), fullPage: width === 1280 });
                results.push(name);
            }
        }
        const menu = await page.locator('#toplevel_page_ritmo-revenue .wp-submenu li a').allInnerTexts();
        check(menu.some((t) => t.startsWith('Visão geral')) && menu.some((t) => t.startsWith('Configurações')), 'the menu is not in Portuguese: ' + menu.join(', '));
        check(errors.length === 0, 'the browser reported errors: ' + errors.join(' | '));
        console.log(JSON.stringify({ ok: true, sentences: sentences.length, screenshots: results.length }, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error('E2E pt_BR FAILED: ' + error.message);
    process.exit(1);
});
