const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('formatting_commentary','1') ON DUPLICATE KEY UPDATE value=VALUES(value); UPDATE accounts SET lasthit=NOW(), restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  let ok = 0, n = 0;
  const b = await chromium.launch(cfg.launch);
  for (const skin of ['twig:aurora', 'jade.htm']) {
    const ctx = await b.newContext(); await ctx.addCookies([{ name: 'template', value: skin, url: BASE }]);
    const p = await ctx.newPage();
    await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
    await Promise.all([p.waitForNavigation(), p.click("input[value='Log in'], form[action*='login.php'] button[type='submit']")]);
    await p.click('[name="insertcommentary"]'); await p.keyboard.type('``bLIT `bBOLD `iEND`');
    await p.waitForTimeout(300);
    const html = await p.$eval('#previewtextinsertcommentary', e => e.innerHTML);
    const pass = html.includes('`bLIT <b>BOLD <i>END`</i></b>'); n++; if (pass) ok++;
    console.log((pass ? 'PASS ' : 'FAIL ') + skin + ': escaped backtick shows, bold closes -- ' + html.slice(-90));
    await ctx.close();
  }
  sql(`UPDATE settings SET value='0' WHERE setting='formatting_commentary'`);
  await b.close(); console.log(`${ok}/${n}`);
})();
