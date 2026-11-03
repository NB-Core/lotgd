// Bold and italic in player texts: chat, preview, mail, bio, clan hall. SKIN=twig:aurora|jade.htm
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
const SKIN = process.env.SKIN || 'twig:aurora';
const OUT = process.env.OUT || '.';
const tag = Date.now().toString(36);
async function login(page) {
  await page.goto(BASE + 'home.php'); await page.fill('#name', 'Admin'); await page.fill('#password', cfg.PASSWORD);
  await Promise.all([page.waitForNavigation(), page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']")]);
  await settle(page);
}
async function nav(page, prefix) {
  const href = await page.$eval(`[data-lotgd-region="nav"] a[href^="${prefix}"], .nav a[href^="${prefix}"], a.nav[href^="${prefix}"], a[href^="${prefix}"]`, a => a.getAttribute('href'));
  await page.click(`a[href="${href}"] >> nth=0`);
  await page.waitForLoadState('load'); await settle(page);
}
// Nothing outside the text is bold or italic: the nav links and the stats.
const leaks = p => p.evaluate(() => {
  const outside = [...document.querySelectorAll('a[href*="village.php"], a[href*="forest.php"], a[href*="mail.php"], .charinfo, [data-lotgd-region="stats"], td.navs a, .nav a')];
  // A skin's own bold around a header slot (puritanic_ai.htm wraps the mail link in <b>) is its design, not a leak.
  return outside.filter(e => { const f = e.closest('b, i, em'); return f && !f.querySelector('[data-lotgd-slot]'); }).map(e => e.textContent.trim().slice(0, 20));
});
(async () => {
  const set = (c, b, m, cl) => sql(`INSERT INTO settings (setting,value) VALUES ('formatting_commentary','${c}'),('formatting_bio','${b}'),('formatting_mail','${m}'),('formatting_clan','${cl}'),('postinglimit','0'),('ajax_rate_limit_seconds','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  set(1, 1, 1, 1);
  // The clan these checks use; another suite may have removed it.
  sql(`INSERT INTO clans (clanid,clanname,clanshort,clanmotd,clandesc,motdauthor,descauthor,customsay) VALUES (26,'Formtest Hall','FT','','',1,1,'') ON DUPLICATE KEY UPDATE clanid=clanid`);
  sql(`UPDATE accounts SET prefs='a:2:{s:4:"ajax";i:1;s:13:"partialrender";i:1;}', lastmotd=NOW(), lasthit=NOW(), restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0, clanid=26, clanrank=31, bio='\`bBIO-OPEN \`iBIO-IT', experience=0, seenmaster=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1; DELETE FROM mail WHERE msgto=1;
       INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (385, 1, '\`bSUBJ-OPEN', '\`bMAIL-OPEN \`iMAIL-IT', NOW(), 0);
       UPDATE clans SET clanmotd='\`bMOTD-OPEN', clandesc='\`iDESC-OPEN' WHERE clanid=26`);
  const mailId = sql(`SELECT MAX(messageid) FROM mail WHERE msgto=1`);
  const browser = await chromium.launch(cfg.launch);
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies([{ name: 'template', value: SKIN, url: BASE }]);
  const p = await ctx.newPage();
  const errors = []; p.on('pageerror', e => errors.push(e.message)); p.on('dialog', d => d.accept());
  try {
    await login(p);
    const field = (await p.$('[data-lotgd-commentary-form] [name="insertcommentary"]')) ? '[data-lotgd-commentary-form] [name="insertcommentary"]' : '[name="insertcommentary"]';
    // Preview
    await p.click(field); await p.keyboard.type(`\`bPREV-OPEN \`iPREV-IT`);
    await settle(p, 300);
    const preview = await p.$eval('#previewtextinsertcommentary', e => e.innerHTML);
    check(`${SKIN}: the preview shows bold and italic, closed`, /<b>PREV-OPEN <i>PREV-IT<\/i><\/b>/.test(preview), preview.slice(-160));
    await p.fill(field, '');
    // Chat
    const line = `\`bCHAT-${tag} \`iITAL`;
    await p.fill(field, line);
    const async = field.startsWith('[data');
    if (async) { await p.press(field, 'Enter'); await settle(p, 1500); } else { await Promise.all([p.waitForNavigation(), p.press(field, 'Enter')]); await settle(p); }
    if (!async) { /* classic: the page reloaded */ }
    const chat = await p.evaluate(t => { const b = [...document.querySelectorAll('b')].find(x => x.textContent.includes('CHAT-' + t)); return b ? b.outerHTML : null; }, tag);
    check(`${SKIN}: a chat line is bold and italic`, chat && /<i>ITAL<\/i><\/b>$/.test(chat), String(chat).slice(-120));
    check(`${SKIN}: nothing outside the chat line is bold`, (await leaks(p)).length === 0, JSON.stringify(await leaks(p)));
    await p.screenshot({ path: `${OUT}/${SKIN.replace(/\W/g, '')}-chat.png`, fullPage: true });
    // Reload to see the stored line rendered by the page.
    await p.goto(BASE + 'village.php').catch(() => {}); await p.goto(BASE + 'badnav.php'); await settle(p);
    // Mail
    await p.goto(BASE + 'mail.php'); await settle(p);
    const subj = await p.content();
    check(`${SKIN}: the inbox closes the subject's bold`, /<b>SUBJ-OPEN<\/b>/.test(subj), (subj.match(/.{60}SUBJ-OPEN.{40}/s) || [''])[0]);
    await p.goto(BASE + 'mail.php?op=read&id=' + mailId); await settle(p);
    const mail = await p.content();
    check(`${SKIN}: a mail's body closes its bold and italic`, /<b>MAIL-OPEN <i>MAIL-IT<\/i><\/b>/.test(mail), (mail.match(/.{60}MAIL-OPEN.{80}/s) || [''])[0]);
    const buttons = await p.$$eval('.mail-nav a, .mail-nav button, .mail-nav input', els => els.filter(e => e.closest('b, i')).map(e => e.textContent || e.value));
    check(`${SKIN}: the mail's buttons are not bold`, buttons.length === 0, JSON.stringify(buttons));
    // Back to the game
    await p.goto(BASE + 'badnav.php'); await settle(p);
    // Clan hall
    await nav(p, 'clan.php');
    const clan = await p.content();
    check(`${SKIN}: the clan hall closes its message and description`, /<b>MOTD-OPEN<\/b>/.test(clan) && /<i>DESC-OPEN<\/i>/.test(clan), (clan.match(/.{40}MOTD-OPEN.{40}/s) || [''])[0]);
    check(`${SKIN}: nothing outside the clan texts is bold`, (await leaks(p)).length === 0, JSON.stringify(await leaks(p)));
    await p.screenshot({ path: `${OUT}/${SKIN.replace(/\W/g, '')}-clan.png`, fullPage: true });
    // Bio, through the warrior list
    await nav(p, 'village.php'); await nav(p, 'list.php');
    const bioHref = await p.$eval('a[href*="bio.php?char=1"]', a => a.getAttribute('href'));
    await p.click(`a[href="${bioHref}"] >> nth=0`); await p.waitForLoadState('load'); await settle(p);
    const bio = await p.content();
    check(`${SKIN}: the bio closes its bold and italic`, /<b>BIO-OPEN <i>BIO-IT<\/i><\/b>/.test(bio), (bio.match(/.{40}BIO-OPEN.{60}/s) || [''])[0]);
    check(`${SKIN}: nothing outside the bio is bold`, (await leaks(p)).length === 0, JSON.stringify(await leaks(p)));
    // Off: the codes show as typed
    set(0, 0, 0, 0);
    await p.goto(BASE + 'mail.php?op=read&id=' + mailId); await settle(p);
    const off = await p.content();
    check(`${SKIN}: with mail formatting off the codes show as typed`, off.includes('`bMAIL-OPEN') && !/<b>MAIL-OPEN/.test(off));
    await p.goto(BASE + 'badnav.php'); await settle(p);
    await nav(p, 'list.php');
    await p.click(`a[href*="bio.php?char=1"] >> nth=0`); await p.waitForLoadState('load'); await settle(p);
    const bioOff = await p.content();
    check(`${SKIN}: with bio formatting off the codes show as typed`, bioOff.includes('`bBIO-OPEN') && !/<b>BIO-OPEN/.test(bioOff), (bioOff.match(/.{20}BIO-OPEN.{20}/s) || [''])[0]);
    await nav(p, 'list.php'); await nav(p, 'village.php');
    const chatOff = await p.content();
    check(`${SKIN}: with chat formatting off the stored line shows its codes`, chatOff.includes('`bCHAT-' + tag), (chatOff.match(new RegExp('.{30}CHAT-' + tag + '.{20}', 's')) || [''])[0]);
    check(`${SKIN}: no page errors`, errors.length === 0, errors.join(' | '));
  } finally {
    set(0, 0, 1, 1);
    sql(`UPDATE accounts SET clanid=0, clanrank=0, bio='' WHERE acctid=1; UPDATE clans SET clanmotd='', clandesc='' WHERE clanid=26`);
    await browser.close();
    console.log(`${results.filter(Boolean).length}/${results.length}`);
  }
})();
