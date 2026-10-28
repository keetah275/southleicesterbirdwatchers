import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const {chromium} = require(process.env.SLB_PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(await readFile(new URL('../../../.local-migration/live-browser-fixture.json', import.meta.url), 'utf8'));
const browser = await chromium.launch({executablePath:process.env.SLB_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
const context = await browser.newContext({viewport:{width:320,height:740}});
await context.addCookies(fixture.cookies);
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
async function saved() { await page.waitForFunction(() => document.getElementById('slb-status')?.textContent.startsWith('Saved at')); }
async function publish() {
    await page.locator('#slb-preview-button').click();
    assert.equal(await page.locator('#slb-preview-content tbody tr').count(),60);
    await page.locator('#slb-publish').click();
    await page.waitForFunction(() => document.getElementById('slb-publish-status').textContent.includes('successfully'));
}
const anonymous = await browser.newContext({viewport:{width:320,height:740}});
const publicPage = await anonymous.newPage();
try {
    await page.goto(fixture.settingsUrl);
    assert.equal(await page.locator('#slb-master').inputValue(), '', 'Real master list remains empty');
    const listUrl = new URL(fixture.editorUrl);
    listUrl.searchParams.set('event_id', '0');
    await page.goto(listUrl.href);
    const pageTwo = page.locator('a.page-numbers').filter({hasText:/^2$/});
    if (await pageTwo.count()) {
        assert.equal(new URL(await pageTwo.getAttribute('href')).searchParams.get('paged'), '2');
        await pageTwo.click();
        assert(await page.locator('.slb-trip-index li').count() > 0, 'Older trips remain reachable');
    }
    await page.goto(fixture.editorUrl);
    await page.locator('.slb-bird').first().waitFor();
    assert.equal(await page.locator('.slb-bird').count(), 60);
    assert.equal(await page.locator('.tribe-notice-event-tickets-install:visible').count(), 0);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Real admin fits a 320px phone');
    await page.locator('.slb-bird input').nth(0).check();
    await saved();
    await page.locator('#slb-search').fill('Wren');
    await page.locator('.slb-bird:visible input').check();
    await saved();
    await page.locator('#slb-clear').click();
    await publish();
    const reportUrl = await page.locator('#slb-report-link a').getAttribute('href');
    await publicPage.goto(reportUrl);
    assert.equal(await publicPage.locator('.slb-report tbody tr').count(), 60);
    assert.equal(await publicPage.locator('.slb-report td strong').count(), 2);
    assert.equal(await publicPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Public report fits a 320px phone');
    await page.locator('#slb-close-preview').click();
    await page.locator('.slb-bird input').nth(2).check();
    await saved();
    await publicPage.reload();
    assert.equal(await publicPage.locator('.slb-report td strong').count(), 2, 'Real public report excludes draft changes');
    await publish();
    assert.equal(await page.locator('#slb-report-link a').getAttribute('href'), reportUrl);
    await publicPage.reload();
    assert.equal(await publicPage.locator('.slb-report td strong').count(), 3);
    await mkdir('/private/tmp/slb-trip-bird-lists', {recursive:true});
    await publicPage.screenshot({path:'/private/tmp/slb-trip-bird-lists/wordpress-report-mobile.png',fullPage:true});
    await page.locator('#slb-close-preview').click();
    await page.evaluate(() => scrollTo(0,0));
    await page.screenshot({path:'/private/tmp/slb-trip-bird-lists/wordpress-editor-mobile.png'});
    await publicPage.emulateMedia({media:'print'});
    await publicPage.pdf({path:'/private/tmp/slb-trip-bird-lists/report-print.pdf',format:'A4'});
    const denied = await anonymous.request.get(`${fixture.origin}/wp-json/slb-birds/v1/trips/${fixture.eventId}`);
    assert.equal(denied.status(),403);
    assert.deepEqual(errors, []);
    console.log('Real WordPress browser checks passed: authenticated mobile admin, empty master settings, saved sightings, complete public report, private draft corrections, report updates, anonymous API denial, and print output.');
} catch (error) {
    await page.screenshot({path:'/private/tmp/slb-wordpress-browser-failure.png',fullPage:true});
    console.error('Page:',page.url(),'Status:',await page.locator('#slb-status').textContent().catch(()=>'unavailable'));
    throw error;
} finally {
    await anonymous.close();
    await context.close();
    await browser.close();
}
