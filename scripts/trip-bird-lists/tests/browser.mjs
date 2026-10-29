import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const {chromium} = require(process.env.SLB_PLAYWRIGHT_MODULE || 'playwright');
const base = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const plugin = path.join(base, 'app/public/wp-content/plugins/slb-trip-bird-lists');
const js = await readFile(path.join(plugin, 'assets/editor.js'), 'utf8');
const css = await readFile(path.join(plugin, 'assets/editor.css'), 'utf8');
const browser = await chromium.launch({executablePath:process.env.SLB_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless:true});
const testWidth = Number(process.env.SLB_TEST_WIDTH || 320);
const context = await browser.newContext({viewport:{width:testWidth,height:740}});
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const birds = Array.from({length:60}, (_, i) => ({id:`bird-${i}`,name:i === 0 ? 'Robin' : i === 1 ? 'Wren' : `Test bird ${i + 1}`}));
let server = {
    revision:1, draft:{title:'Mobile test trip',summary:'',species:birds,seen:[]},
    eventTitle:'Mobile test trip',eventDate:'28 October 2026',eventUrl:'http://slb-test.invalid/event/',
    updatedAt:new Date().toISOString(),published:false,unpublishedChanges:true,reportId:0,reportStatus:null,reportUrl:null
};
let offline = false;
let expire = false;
let saveGate = null;
let publishCalls = 0;
const copy = value => JSON.parse(JSON.stringify(value));

await page.route('http://slb-test.invalid/**', async route => {
    const request = route.request();
    if (request.url().includes('/api/trip')) {
        if (offline) return route.abort('internetdisconnected');
        if (expire) return route.fulfill({status:403,json:{code:'rest_cookie_invalid_nonce',message:'Cookie check failed.'}});
        if (request.method() === 'GET') return route.fulfill({json:server});
        const body = request.postDataJSON();
        if (body.revision !== server.revision) return route.fulfill({status:409,json:{code:'conflict',data:{current:server}}});
        if (request.url().endsWith('/publish')) {
            publishCalls++;
            server.published = true; server.unpublishedChanges = false; server.reportId = 42; server.reportStatus = 'publish'; server.reportUrl = 'http://slb-test.invalid/report/';
            return route.fulfill({json:server});
        }
        if (saveGate) await saveGate.promise;
        server.draft.heard = body.heard; server.draft.seen = body.seen; server.draft.title = body.title; server.draft.summary = body.summary;
        server.revision++; server.updatedAt = new Date().toISOString(); server.unpublishedChanges = true;
        return route.fulfill({json:server});
    }
    const config = {endpoint:'http://slb-test.invalid/api/trip',nonce:'test',eventId:7,userId:1,initial:server,listUrl:'http://slb-test.invalid/admin/',loginUrl:'http://slb-test.invalid/login/'};
    return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px sans-serif;margin:10px;background:#f0f0f1}.button{display:inline-block;border:1px solid #888;border-radius:3px}button{font:inherit}input,textarea{box-sizing:border-box} ${css}</style></head><body><main class="slb-app"><h1>Trip bird lists</h1><div id="slb-editor"></div></main><script>window.slbBirdLists=${JSON.stringify(config)}</script><script>${js}</script></body></html>`});
});

const status = () => page.locator('#slb-status').textContent();
async function waitForSaved() {
    await page.waitForFunction(() => document.getElementById('slb-status').textContent.startsWith('Saved at'));
}
async function reload() {
    page.once('dialog', dialog => dialog.accept());
    await page.reload();
}

try {
    await page.goto('http://slb-test.invalid/admin/');
    assert.equal(await page.locator('.slb-bird').count(), 60);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No horizontal overflow at ${testWidth}px`);
    assert.equal(await page.locator('.slb-bird').first().evaluate(el => el.getBoundingClientRect().height >= 44), true);
    await page.locator('#slb-jump-bottom').click();
    assert.equal(await page.locator('#slb-report-heading').evaluate(el => document.activeElement === el), true);
    assert.equal(await page.locator('#slb-report-heading').evaluate(el => el.getBoundingClientRect().top >= document.querySelector('.slb-toolbar').getBoundingClientRect().bottom), true, 'Report heading clears the sticky toolbar');
    await page.locator('#slb-jump-top').click();
    assert.equal(await page.locator('.slb-bird input[data-kind="seen"]').first().evaluate(el => document.activeElement === el), true);
    await page.locator('.slb-bird input[data-kind="seen"]').nth(0).check();
    await waitForSaved();
    assert.deepEqual(server.draft.seen, ['bird-0']);
    await page.locator('.slb-bird input[data-kind="heard"]').nth(0).check();
    await waitForSaved();
    await page.locator('.slb-bird input[data-kind="heard"]').nth(9).check();
    await waitForSaved();
    assert.deepEqual(server.draft.heard, ['bird-0','bird-9']);
    assert(!server.draft.seen.includes('bird-9'), 'Heard-only birds do not become seen');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);

    await page.locator('#slb-search').fill('Wren');
    assert.equal(await page.locator('.slb-bird:visible').count(), 1);
    await page.locator('.slb-bird:visible input[data-kind="seen"]').check();
    await waitForSaved();
    assert.equal(server.draft.seen.length, 2, 'Search preserves earlier selections');
    assert.equal(await page.locator('#slb-search').inputValue(), 'Wren', 'Autosave keeps the search text');
    assert.equal(await page.locator('.slb-bird:visible').count(), 1, 'Autosave keeps the search results');

    // A previous selection may still be saving while the next search is typed.
    saveGate = Promise.withResolvers();
    const searchSave = page.waitForRequest(request => request.url().endsWith('/api/trip') && request.method() === 'POST');
    await page.locator('.slb-bird:visible input[data-kind="heard"]').check();
    await searchSave;
    await page.locator('#slb-search').fill('Test bird');
    await page.locator('#slb-search').evaluate(input => input.setSelectionRange(2, 5));
    saveGate.resolve();
    saveGate = null;
    await waitForSaved();
    assert.equal(await page.locator('#slb-search').inputValue(), 'Test bird', 'An older save cannot reset a newer search');
    assert.equal(await page.locator('#slb-search').evaluate(input => document.activeElement === input), true, 'Save completion keeps search focus');
    assert.deepEqual(await page.locator('#slb-search').evaluate(input => [input.selectionStart, input.selectionEnd]), [2, 5], 'Save completion keeps the search cursor selection');
    assert.equal(await page.locator('.slb-bird:visible').count(), 58, 'Save completion keeps the newer results');
    await page.locator('#slb-search').fill('Wren');
    // Keep the matching row mounted while selecting during an earlier save.
    saveGate = Promise.withResolvers();
    const selectionSave = page.waitForRequest(request => request.url().endsWith('/api/trip') && request.method() === 'POST');
    await page.locator('.slb-bird:visible input[data-kind="heard"]').uncheck();
    await selectionSave;
    await page.locator('.slb-bird:visible input[data-kind="seen"]').uncheck();
    assert.equal(await page.locator('.slb-bird:visible').count(), 1, 'Unrecorded search result remains visible during saving');
    saveGate.resolve();
    saveGate = null;
    await waitForSaved();
    assert.equal(await page.locator('#slb-search').inputValue(), 'Wren');
    assert.equal(await page.locator('.slb-bird:visible').count(), 1, 'Queued save cannot hide the matching bird');
    assert.equal(await page.locator('.slb-bird:visible input[data-kind="seen"]').isChecked(), false);
    await page.locator('.slb-bird:visible input[data-kind="seen"]').check();
    await waitForSaved();

    // The recorded-only filter intentionally hides the last unchecked record.
    await page.locator('#slb-seen-only').check();
    await page.locator('.slb-bird:visible input[data-kind="seen"]').uncheck();
    assert.equal(await page.locator('.slb-bird:visible').count(), 0);
    assert.equal(await page.locator('#slb-no-results').isVisible(), true);
    assert.equal(await page.locator('#slb-search').inputValue(), 'Wren', 'Recorded-only filtering does not clear search');
    await waitForSaved();
    await page.locator('#slb-seen-only').uncheck();
    assert.equal(await page.locator('.slb-bird:visible').count(), 1, 'Turning off recorded-only restores the search result');
    await page.locator('.slb-bird:visible input[data-kind="seen"]').check();
    await waitForSaved();
    await page.locator('#slb-clear').click();

    // Edits while a save is in flight must be sent in a subsequent revision.
    saveGate = Promise.withResolvers();
    const inFlight = page.waitForRequest(request => request.url().endsWith('/api/trip') && request.method() === 'POST');
    await page.locator('.slb-bird input[data-kind="seen"]').nth(2).check();
    await inFlight;
    await page.locator('.slb-bird input[data-kind="seen"]').nth(3).check();
    saveGate.resolve();
    saveGate = null;
    await waitForSaved();
    assert.equal(server.draft.seen.length, 4, 'In-flight edits persist');

    offline = true;
    await page.locator('.slb-bird input[data-kind="seen"]').nth(4).check();
    await page.locator('.slb-bird input[data-kind="heard"]').nth(11).check();
    await page.waitForFunction(() => document.getElementById('slb-status').textContent.includes('Connection lost'));
    assert.equal(server.draft.seen.length, 4);
    assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('slb-birds:1:7')).draft.seen.length), 5);
    offline = false;
    await reload();
    await page.locator('#slb-apply-recovery').waitFor();
    await page.locator('#slb-apply-recovery').click();
    await waitForSaved();
    assert.equal(server.draft.seen.length, 5, 'Recover browser edits after reload');
    assert(server.draft.heard.includes('bird-11'), 'Recover pending heard records after signal loss');

    // Remote unrelated selections survive a conflict reconciliation.
    server.draft.seen.push('bird-8'); server.draft.heard.push('bird-10'); server.revision++;
    await page.locator('.slb-bird input[data-kind="seen"]').nth(5).check();
    await page.locator('#slb-apply-recovery').waitFor();
    assert.match(await page.locator('#slb-review').textContent(), /Another screen/);
    await page.locator('#slb-apply-recovery').click();
    await waitForSaved();
    assert(server.draft.seen.includes('bird-8') && server.draft.seen.includes('bird-5'));
    assert(server.draft.heard.includes('bird-10') && server.draft.heard.includes('bird-9'), 'Merge preserves remote and local heard selections');

    await page.locator('#slb-seen-only').check();
    await page.locator('#slb-review-report').click();
    assert.equal(await page.locator('#slb-preview').evaluate(el => document.activeElement === el), true);
    assert.equal(await page.locator('#slb-preview').evaluate(el => el.getBoundingClientRect().top >= document.querySelector('.slb-toolbar').getBoundingClientRect().bottom), true, 'Preview clears the sticky toolbar');
    assert.equal(await page.locator('#slb-publish').evaluate(el => el.getBoundingClientRect().bottom <= innerHeight), true, 'Publish control is reachable without scrolling through the preview');
    assert.equal(await page.locator('#slb-preview-content tbody tr').count(), 60, 'Preview always includes every bird');
    assert.equal(await page.locator('#slb-preview-content tbody td:nth-child(3)').filter({hasText:'✓ Heard'}).count(), 4);
    await page.locator('#slb-publish').click();
    await page.waitForFunction(() => document.getElementById('slb-publish-status').textContent.includes('successfully'));
    assert.equal(publishCalls, 1);
    assert.equal(server.reportId, 42);
    assert.equal(await page.locator('#slb-review-report').textContent(), 'Review & update report');
    await page.locator('#slb-publish').click();
    await page.waitForFunction(() => document.getElementById('slb-publish-status').textContent.includes('successfully'));
    assert.equal(server.reportId, 42, 'Same associated report on subsequent publish');

    await page.locator('#slb-close-preview').click();
    assert.equal(await page.locator('#slb-review-report').evaluate(el => document.activeElement === el), true, 'Closing preview returns to its toolbar shortcut');
    await page.locator('#slb-clear').click();
    await page.locator('.slb-bird input[data-kind="seen"]').nth(6).check();
    await waitForSaved();
    assert.match(await page.locator('#slb-publication-status').textContent(), /Unpublished changes/);

    await mkdir('/private/tmp/slb-trip-bird-lists', {recursive:true});
    await page.screenshot({path:'/private/tmp/slb-trip-bird-lists/mobile.png',fullPage:true});
    await page.setViewportSize({width:1280,height:850});
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);

    expire = true;
    await page.locator('#slb-summary').fill('Pending summary after sign-in expiry');
    await page.waitForFunction(() => document.getElementById('slb-status').textContent.includes('Sign-in expired'));
    assert.equal(await page.locator('#slb-login').isVisible(), true);
    assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('slb-birds:1:7')).draft.summary), 'Pending summary after sign-in expiry');
    assert.deepEqual(errors, []);
    console.log('Browser checks passed: mobile sizing, search, autosave, in-flight edits, signal loss, recovery, conflict merge, full preview, publication, draft corrections, and expired login.');
    console.log('Mobile screenshot: /private/tmp/slb-trip-bird-lists/mobile.png');
} catch (error) {
    console.error('Editor status:', await status());
    console.error('Browser errors:', errors);
    console.error('Server revision / selected:', server.revision, server.draft.seen);
    await page.screenshot({path:'/private/tmp/slb-bird-list-failure.png', fullPage:true});
    throw error;
} finally {
    await context.close();
    await browser.close();
}
