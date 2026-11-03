# Browser suites (developers only)

Playwright suites that play the game in a real browser: pages changing in
place, fights, forms, chat, notices, the in-page dialog, realtime over polling
and server-sent events, live duels, the formatting of player texts, the
bundled modules, every bundled skin.

They are a developer's tool. They are **not** run in CI, are not part of the
Docker image (`tools/` is in `.dockerignore`) and are not shipped
(`shipped-files.txt`). Administrators never need them. The suites change
settings, accounts and data of the game they run against: point them at a
test game, never at a live one.

## What you need

- A copy of the game, installed on its own database, served by PHP:
  ```sh
  php bin/install --admin=Admin --password-stdin <<< 'AdminPass123!'
  ```
  With the module suites (`modules`, `keys`, `dweditor`, `dwtypes`), the
  dwellings modules from NB-Core/modules (`dwellings`, `dwshacks`, `cityprefs`
  and their folders) must be in its `modules/` and installed.
- The second and third test player, once:
  ```sh
  $LOTGD_E2E_DB_CMD < tools/e2e/setup.sql
  ```
- The game served with OPcache **off**, with several workers (the realtime
  suites hold a stream open while they click):
  ```sh
  cd /path/to/game
  PHP_CLI_SERVER_WORKERS=8 php -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:8099
  ```
  With OPcache on, a configuration file a suite rewrites (`config/async.settings.php`)
  is not read again in time, and the timeout suites fail for no fault of the game.
- Node.js and Playwright with Chromium (`npm install` in this folder, then
  `npx playwright install chromium`, unless the machine has them already).

## Configuration

| Variable | Default | What |
| --- | --- | --- |
| `LOTGD_E2E_BASE` | `http://127.0.0.1:8099/` | The game, with a trailing slash |
| `LOTGD_E2E_GAME` | this checkout | The game's directory (some suites rewrite its async settings or empty its data cache) |
| `LOTGD_E2E_DB_CMD` | `docker exec -i lotgd-e2e-db mariadb -N -ulotgd -plotgdpw lotgd` | A command that runs SQL from standard input against the game's database, one value per line |
| `LOTGD_E2E_PASSWORD` | `AdminPass123!` | The test accounts' password |
| `LOTGD_E2E_CHROMIUM` | `/opt/pw-browsers/chromium` if it exists | Chromium to launch |
| `LOTGD_E2E_OUT` | `tools/e2e/results/` | Logs, screenshots and `results.tsv` |

The test accounts are `Admin` (1), `offe2e` (385) and `appe2e` (386); some
suites create more (`sleepere2e`, `duele2e`, …) and remove them again.

## Running

```sh
tools/e2e/run.sh                      # everything; skin-dependent suites on Aurora and jade
tools/e2e/run.sh town fights          # some suites
tools/e2e/run.sh --skins all forms    # one suite on all ten skins
node tools/e2e/overlay.js             # one suite by hand (no reset)
```

`run.sh` resets what one suite can leave for the next (`reset.sql`) before
each run, prints a line per run and lists the failed runs at the end.
Every suite prints `PASS`/`FAIL` lines and a total.

Time on a 4-core machine: one suite 1–5 minutes; everything on Aurora and jade
(47 runs) 27 minutes, measured; everything on all skins (`--skins all`) about
2 hours, estimated from the per-skin suites.

## The suites

| Suite | What it plays |
| --- | --- |
| `town`, `fights`, `forms` | Shops and the inn, fights (forest, master, dragon, PvP), forms posting in place |
| `chat`, `commentary`, `async`, `realtime`, `sse` | The chat, live comments, the async endpoint, realtime by polling and by stream |
| `notices`, `timeout`, `never`, `indicator` | Notices in the upper right, the session timeout, the dot beside the mail link |
| `overlay`, `skins`, `legacy` | The mail dialog; every Twig skin; the legacy templates |
| `duel`, `encounters`, `extras`, `pvplive`, `grants` | Live duels, challenges, rematch and spectators, grants |
| `bank`, `staletab`, `tour`, `e2e` | Guarded bank actions, stale tabs, pages changing in place |
| `fmt`, `fmtpreview`, `fmtliteral` | Bold and italic in player texts and the preview |
| `status`, `usereditor`, `xss` | Live features table, user editor, escaping of player names |
| `modules`, `keys`, `dweditor`, `dwtypes` | cities, lovers and the dwellings modules |

A suite that fails points at its check in its log
(`results/<run>.log`, lines starting with `FAIL`). Before you look for a bug
in the game, run that suite again on its own: a suite can still trip over
data another suite left behind that `reset.sql` does not know yet. If it
does, add the line to `reset.sql`.
