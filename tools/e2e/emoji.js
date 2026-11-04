// Emoji in chat lines (commentary_emoji): kept, pictographs removed, or all
// removed -- in the comment preview and on the page, on Aurora (the partial
// client's declarative preview) and jade (the classic preview script).
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();

const FACE = '\u{1F600}';
const WAVE = '\u{1F44B}\u{1F3FD}';
// What a mode leaves of "mark FACE WAVE © end": the copyright sign is a text
// symbol, kept unless everything Unicode marks as emoji goes. The line ends
// in plain letters: the classic preview updates on keyup, and Playwright
// types other characters without key events.
const expected = {
  0: m => `${m} ${FACE} ${WAVE} © end`,
  1: m => `${m}   © end`,
  2: m => `${m}    end`,
};

const results = [];
function check(name, ok, info = '') {
  results.push(!!ok);
  console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : ''));
}

(async () => {
  const restoreSettings = cfg.keepSettings(sql, ['commentary_emoji', 'defaultskin']);
  const b = await chromium.launch(cfg.launch);
  try {
    for (const [skin, partial] of [['twig:aurora', 1], ['jade.htm', 0]]) {
      for (const mode of [0, 1, 2]) {
        sql(`${cfg.setting('commentary_emoji', String(mode))}; ${cfg.setting('defaultskin', skin)};
             UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:${partial};}', lasthit=NOW(), restorepage='village.php',
               allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0, location='Degolburg', alive=1 WHERE acctid=1;
             DELETE FROM accounts_output WHERE acctid=1`);
        const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
        const p = await ctx.newPage();
        await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
        await Promise.all([p.waitForNavigation(), p.press('#password', 'Enter')]);
        if (!(await p.title()).includes('Square')) await p.goto(BASE + 'badnav.php');
        const mark = `emo${mode}x${Date.now() % 100000}`;
        const typed = `${mark} ${FACE} ${WAVE} © end`;
        const tag = `${skin} mode ${mode}`;

        await p.click('[name="insertcommentary"]');
        await p.keyboard.type(typed);
        await p.waitForTimeout(300);
        const preview = await p.$eval('#previewtextinsertcommentary', e => e.textContent);
        check(`${tag}: the preview shows what the page will`, preview.includes(expected[mode](mark)), JSON.stringify(preview.slice(-40)));

        await p.press('[name="insertcommentary"]', 'Enter');
        await p.waitForLoadState('load'); await p.waitForTimeout(1500);
        // As bytes: the mysql client may print in another character set.
        const stored = sql(`SELECT HEX(comment) FROM commentary WHERE comment LIKE '${mark}%' ORDER BY commentid DESC LIMIT 1`);
        check(`${tag}: the line is stored as written`, stored === Buffer.from(typed, 'utf8').toString('hex').toUpperCase(), stored);
        const shown = await p.evaluate(m => {
          const line = [...document.querySelectorAll('[data-cid], div, span')].map(e => e.textContent).filter(t => t.includes(m)).sort((x, y) => x.length - y.length)[0] || '';
          return line;
        }, mark);
        check(`${tag}: the page shows it as the mode says`, shown.includes(expected[mode](mark)), JSON.stringify(shown.slice(-50)));
        await ctx.close();
      }
    }
  } finally {
    await b.close();
    sql(`DELETE FROM commentary WHERE comment LIKE 'emo_x%'`);
    restoreSettings();
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
