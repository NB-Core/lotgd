const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const fs = require('fs');
const GAME = cfg.GAME; const BASE = cfg.BASE;
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
(async () => {
  const cfg = GAME + '/config/async.settings.php'; const saved = fs.readFileSync(cfg, 'utf8');
  fs.writeFileSync(cfg, saved.replace(/'start_timeout_show_seconds'\s*=>\s*\d+/, "'start_timeout_show_seconds' => 60").replace(/'never_timeout_if_browser_open'\s*=>\s*\d+/, "'never_timeout_if_browser_open' => 1"));
  // Realtime off: with it, every poll keeps the session alive and no keep-alive is needed.
  const oldRealtime = sql(`SELECT value FROM settings WHERE setting='realtime_enabled'`);
  sql(`UPDATE settings SET value='0' WHERE setting='realtime_enabled'`);
  sql(`UPDATE settings SET value='75' WHERE setting='LOGINTIMEOUT'; UPDATE accounts SET loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid=1`);
  const b = await chromium.launch(cfg.launch);
  try {
    const p = await (await b.newContext()).newPage();
    await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
    await p.click("input[value='Log in'], form[action*='login.php'] button[type='submit']"); await p.waitForLoadState('load');
    const before = Number(sql(`SELECT UNIX_TIMESTAMP(laston) FROM accounts WHERE acctid=1`));
    await p.waitForTimeout(25000);
    const after = Number(sql(`SELECT UNIX_TIMESTAMP(laston) FROM accounts WHERE acctid=1`));
    const shown = await p.$eval('.lotgd-timeout', e => !e.hidden).catch(() => false);
    console.log((after > before && !shown ? 'PASS' : 'FAIL') + ' the open browser keeps the session, silently', before, after, shown);
    // A failing endpoint: retries back off instead of looping.
    let calls = 0;
    await p.route('**/async/process.php', r => { if (/keepAlive/.test(r.request().postData() || '')) { calls++; return r.fulfill({ status: 503, body: '' }); } return r.continue(); });
    await p.reload(); await p.waitForTimeout(32000);
    console.log((calls >= 1 && calls <= 5 ? 'PASS' : 'FAIL') + ' a failing keep-alive backs off', calls);
    // Logged out on the server: the notice says so, no more calls.
    await p.unroute('**/async/process.php'); calls = 0;
    await p.route('**/async/process.php', r => { if (/keepAlive/.test(r.request().postData() || '')) { calls++; return r.fulfill({ status: 401, body: '{}' }); } return r.continue(); });
    await p.reload(); await p.waitForTimeout(20000);
    const expired = await p.$eval('.lotgd-timeout', e => !e.hidden && e.classList.contains('lotgd-timeout--expired')).catch(() => false);
    console.log((expired && calls === 1 ? 'PASS' : 'FAIL') + ' a lost session is final', calls, expired);
  } finally {
    await b.close(); fs.writeFileSync(cfg, saved); sql(`UPDATE settings SET value='900' WHERE setting='LOGINTIMEOUT'; UPDATE settings SET value='${oldRealtime || 0}' WHERE setting='realtime_enabled'`);
  }
})();
