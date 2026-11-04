const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name);
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in']");
  await page.waitForLoadState('load'); await settle(page);
  await page.goto(BASE + 'badnav.php'); await settle(page);
  for (let i = 0; i < 25 && !(await page.title()).includes('Square'); i++) {
    const href = await page.evaluate(() => {
      const links = [...document.querySelectorAll('a')].filter(a => !a.hasAttribute('onclick'));
      const pick = links.find(a => /^village\.php(\?c=|$)/.test(a.getAttribute('href') || '')) || links.find(a => /^newday\.php/.test(a.getAttribute('href') || ''));
      return pick ? pick.getAttribute('href') : null;
    });
    if (!href) break;
    await page.click(`a[href="${href}"] >> nth=0`); await page.waitForLoadState('load'); await settle(page);
  }
}
const dialogText = async p => (await p.evaluate(() => { const d = document.querySelector('dialog.lotgd-overlay'); return d && d.open ? d.innerText.replace(/\s+/g, ' ') : null; }));
const SKINS = ['aurora', 'aurora_minimal', 'aurora_test', 'modern', 'puritanic', 'puritanic_ai'];

(async () => {
  const browser = await chromium.launch(cfg.launch);
  for (const skin of SKINS) {
    sql(`INSERT INTO settings (setting,value) VALUES ('ajax','1'),('partial_render','1'),('realtime_enabled','0'),('forcedmotdpopup','0') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
    sql(`UPDATE accounts SET prefs='a:2:{s:4:"ajax";i:1;s:13:"partialrender";i:1;}', lastmotd=NOW(), restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0, clanid=0, clanrank=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1; DELETE FROM mail WHERE msgto=1 OR msgfrom=1; INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (1, 1, 'Skin test ${skin}', 'Body', NOW(), 0)`);
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    await ctx.addCookies([{ name: 'template', value: 'twig:' + skin, url: BASE }]);
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', r => { if (r.status() === 429) console.log('  429', skin, r.url().replace(BASE, ''), decodeURIComponent((r.request().postData() || '').replace(/\+/g, ' ')).slice(0, 120)); });
    let popups = 0; ctx.on('page', () => { popups++; });
    await login(page, 'Admin');
    const css = await page.evaluate(() => [...document.querySelectorAll('link[rel=stylesheet]')].map(l => l.getAttribute('href')).join(' '));
    check(`${skin}: rendered with the skin`, css.includes(`templates_twig/${skin}/`), css.slice(0, 120));
    await page.evaluate(() => { window.__noReload = 1; });

    await page.click('[data-lotgd-region="nav"] a[href^="forest.php"] >> nth=0'); await settle(page, 1200);
    const titles = await page.evaluate(() => [...document.querySelectorAll('[data-lotgd-title]')].map(e => e.textContent.trim()));
    check(`${skin}: village -> forest swapped in`, /Forest/.test(await page.title()) && (await page.evaluate(() => window.__noReload)) === 1, page.url());
    check(`${skin}: the title in the layout follows`, titles.every(t => t === 'The Forest'), JSON.stringify(titles));
    await page.click('[data-lotgd-region="nav"] a[href^="village.php"] >> nth=0'); await settle(page, 1200);
    check(`${skin}: forest -> village swapped in`, /Square/.test(await page.title()) && (await page.evaluate(() => window.__noReload)) === 1);

    await page.click('[data-lotgd-slot="mail"] a[data-lotgd-overlay] >> nth=0', { timeout: 5000 });
    const opened = await page.waitForFunction(() => { const d = document.querySelector('dialog.lotgd-overlay'); return d && d.open && /Skin test/.test(d.innerText); }, null, { timeout: 10000 }).then(() => true, () => false);
    check(`${skin}: mail opens in the dialog`, opened && popups === 0);
    const closeLook = await page.$eval('dialog.lotgd-overlay .lotgd-overlay__close', b => ({ bg: getComputedStyle(b).backgroundImage, before: getComputedStyle(b, '::before').content }));
    check(`${skin}: one close cross`, closeLook.bg === 'none' && closeLook.before.includes('×'), JSON.stringify(closeLook));
    await page.click('dialog.lotgd-overlay .lotgd-overlay__close'); await settle(page);
    check(`${skin}: the dialog closes`, await page.evaluate(() => !document.querySelector('dialog.lotgd-overlay').open));

    // Mobile: the offcanvas menu closes after a swap.
    const toggler = await page.$('[data-bs-toggle="offcanvas"]');
    if (toggler) {
      await page.setViewportSize({ width: 420, height: 800 });
      const target = await toggler.getAttribute('data-bs-target');
      await page.click(`[data-bs-target="${target}"]`, { timeout: 5000 }); await settle(page, 800);
      const shown = await page.$eval(target, e => e.classList.contains('show'));
      await page.click(`${target} [data-lotgd-region="nav"] a[href^="forest.php"]`, { timeout: 5000 }); await settle(page, 1500);
      check(`${skin}: the mobile menu closes after the swap`, shown && /Forest/.test(await page.title()) && !(await page.$eval(target, e => e.classList.contains('show'))) && (await page.evaluate(() => window.__noReload)) === 1);
    }
    check(`${skin}: no page errors`, errors.length === 0, errors.join(' | '));
    await ctx.close();
  }
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
