# Reactive Core – Phase 0 Inventory

This is the inventory that has to exist before the reactive-core work
(see [ReactiveCore.md](ReactiveCore.md)) introduces request transactions,
partial rendering or a realtime outbox. It records where a request ends,
which code writes to `accounts` behind `Accounts::saveUser()`, what ends or
forbids a transaction, and what cannot be rolled back.

The numbers come from the scanner, so they can be regenerated at any time:

```bash
composer qa:reactive-inventory                       # core only
php scripts/inventory-reactive-core.php ../modules   # core + modules checkout
php scripts/inventory-reactive-core.php --category=accounts_write
```

The scanner (`Lotgd\QA\ReactiveCoreInventory`) is a report, not a gate: it
always exits 0. It is token based, so comments and docblocks do not count, and
its matches are deliberately broad. Each category below says how to read it.

## Summary (after Phase 3)

| Category | Core | `NB-Core/modules`¹ | Why it matters |
|---|---:|---:|---|
| `finish_path` | 185 | 351 | Every place a request can end or persist the account; a unit of work needs one defined end. |
| `accounts_write` | 41 | 35 | SQL writes to `accounts` outside `saveUser()`; an optimistic lock (`version`) must cover each one. |
| `transaction_breaker` | 9 | 32 | `LOCK TABLES`, DDL and `OPTIMIZE`/`ANALYZE`/`REPAIR TABLE` implicitly commit an open MySQL transaction; own transactions cannot nest. |
| `side_effect` | 151 | 156 | Mail, HTTP and data-cache writes survive a rollback and must move behind the commit. |
| `inline_script` | 44 | 55 | `<script>` emitted into page output does not run after a partial swap. |

¹ Without `_old_dragonprime_snapshot/`, which is not shipped.

Phase 2 added six finish paths (the partial exits in `PartialMode::preCheck()`,
the lost revision claim in `ForcedNavigation`, the two partial endings in
`Footer::pageFooter()` and the `HX-Redirect` in `Redirect::redirect()`), one
`accounts` write (the revision claim) and two `<script` matches (the check
for it in `PartialRenderer::canRender()` and the pattern `PageParts` uses to
drop the mail slot's script for partial players). The tables below describe the code as
reviewed in Phase 0.

Phase 3 added two `accounts` matches – the version-checked write in
`Accounts::persistChecked()` and the account lock in `Action::run()`
(`SELECT version … FOR UPDATE`, which the token scanner reads as an update) –
and one transaction breaker, `Action::run()`'s own `beginTransaction()`.
Since Phase 3 the bank transfer (`bank.php`) credits the recipient with
`version = version + 1`, and the four runtime breakers below –
`newday.php`'s settings lock, `Newday::dbCleanup()`, `Modules::inject()` and
`Modules::semAcquire()` – refuse to run inside a guarded action
(`Action::guardImplicitCommit()`) instead of committing it.

## Finish paths

How a request ends today:

| Path | Count (core) | Notes |
|---|---:|---|
| `Footer::pageFooter()` / `page_footer()` | 93 | Normal end: builds navs, stats, renders, `saveUser()`, `session_write_close()`, `exit`. |
| `popupFooter()` / `popup_footer()` | 11 | Popup end; also calls `saveUser()`. |
| `Redirect::redirect()` / `redirect()` | 30 | Sets `allowednavs` to the target, stores a fallback `$session['output']`, calls `saveUser()`, sends `Location`. |
| Direct `saveUser()` | 9 | `badnav.php`, `login.php` (×2), `motd.php`, `pages/inn/inn_room.php`, the page and popup footer, `Redirect`, and the legacy wrapper `lib/saveuser.php`. |
| `exit` / `die` | 36 | Mostly after `header()`/download output; each one ends a request without the footer. |

What `saveUser()` does, in order, and why it cannot simply be called twice:

1. `Buffs::restoreBuffFields()`, serialises `allowednavs` and `bufflist` into `$session['user']`.
2. Diffs `$session['user']` against `$baseaccount` and writes the changed fields
   through the cached `Accounts::$accountEntity` (Doctrine) or, without Doctrine,
   one `UPDATE accounts` (`src/Lotgd/Accounts.php:154`). **`laston` is written on
   every call.**
3. Stores `$session['output']` in `accounts_output` (used by `badnav.php`).
4. **Reduces `$session['user']` to the identification fields** and unsets
   `$session['bufflist']`.

Step 4 is why Phase 3 splits `saveUser()` into *compute dirty*, *persist* and
*finish request*. Step 2's unconditional `laston` write is why `laston` must
not bump the planned `accounts.version`.

## Writes to `accounts` outside `saveUser()`

Core call sites (38). They fall into four groups:

| Group | Sites | Planned treatment |
|---|---|---|
| Changes to **another** player | `bank.php:198` (transfer), `src/Lotgd/Pvp.php:101,205,309`, `src/Lotgd/Repository/ClanRepository.php:80`, `pages/clan/clan_membership.php:95,135`, `pages/clan/clan_withdraw.php:28,38` | Conditional atomic deltas with `version = version + 1` (Phase 3). These are the writes the session diff of the target player would otherwise overwrite. |
| Login and session bookkeeping | `login.php` (8 sites), `src/Lotgd/Async/Handler/Timeout.php:124`, `src/Lotgd/ServerFunctions.php:130`, `src/Lotgd/PlayerFunctions.php:84,108`, `src/Lotgd/ForcedNavigation.php` (the Phase 2 page-revision claim) | Bookkeeping fields (`loggedin`, `laston`, …) stay outside the version check. |
| Admin editors | `pages/user/user_save.php:243`, `pages/user/user_special.php` (3), `pages/user/user_saveban.php:64`, `pages/bans/case_saveban.php:64`, `superuser.php:41`, `donators.php:180`, `mounts.php:124`, `bios.php:37,44`, `train.php:234` | Must bump `version` so a playing character notices the admin change instead of overwriting it. |
| Deletes | `pages/user/user_del.php:62`, `prefs.php:167`, `src/Lotgd/ExpireChars.php:127,326` | Already guarded (POST + CSRF); a later Phase must make sure a deleted account cannot be re-created by a stale `saveUser()`. |

`Accounts.php:154` is `saveUser()`'s own non-Doctrine branch.

Modules (35 outside the old snapshot) follow the same split; most are
"another player" writes (PvP, gifts, clan features) and need the same delta
treatment before Phase 3 is switched on for the pages they hook into.

## Transaction breakers

| Site | What | Risk |
|---|---|---|
| `newday.php:370` | `LOCK TABLES settings WRITE` during the game-day rollover | **Runs inside a normal gameplay request.** A request transaction opened in `common.php` would be committed implicitly here. `Action::run()` must never wrap the new-day path until this lock is replaced. |
| `src/Lotgd/Newday.php:39` | `OPTIMIZE TABLE` for every game table in `Newday::dbCleanup()` | Runs during the game-day rollover of a gameplay request unless `newdaycron` is set (then from `cron.php`). Same constraint as the settings lock above. |
| `src/Lotgd/Modules.php:176` | `LOCK TABLES modules WRITE` in `Modules::inject()` when a module file's mtime differs from the stored one | **Reachable from any request** that dispatches a hook right after a module file was deployed, not only from the module manager. |
| `src/Lotgd/Modules.php:1564` | `LOCK TABLES module_settings WRITE` in `Modules::semAcquire()` (`module_sem_acquire()`) | Callable by any module at runtime. |
| `src/Lotgd/MySQL/TableDescriptor.php:406,431` | `ALTER TABLE` / `CREATE TABLE` | Installer / module install; DDL commits implicitly. |
| `src/Lotgd/ExpireChars.php:119` | own `beginTransaction()` | Cron path; must not run inside an `Action::run()`. |
| `src/Lotgd/Payment/IpnPaymentProcessor.php:407` | own `beginTransaction()` | Payment callback, separate request. |

Modules: 32 sites, nearly all DDL in install/uninstall hooks (expected and
harmless outside gameplay). Two take `LOCK TABLES` at **runtime**:
`lodge/prizemount.php:14` and `mail/outbox.php:199`. Pages that trigger them
stay out of `Action::run()` until they are rewritten to `SELECT … FOR UPDATE`.

## Side effects that survive a rollback

| Kind | Core sites | Notes |
|---|---:|---|
| Data cache invalidation/update | 125 (`invalidatedatacache` 80, `massinvalidate` 29, `updatedatacache` 16) | After a rollback the cache may hold the discarded state; Phase 3 invalidates the touched keys on rollback. |
| `Mail::systemMail()` | 15 | Writes the in-game mail row (rolls back) **and** may send e-mail (does not). |
| E-mail transport | 5 (`new PHPMailer` in `Mail::send()`, 4 × `mail()`) | Must become an outbox job. |
| HTTP | 6 (`PullUrl::pull` 2, `curl_exec` 2, `fsockopen` 2) | Version check, payment verification; outside gameplay actions. |

## Inline scripts

42 core sites emit `<script>` into page output. The largest groups are
`src/Lotgd/Forms.php` (6, tabbed forms), `bank.php` (5), `pages/mail/*` (7),
`src/Lotgd/PageParts.php` (2) and `src/Lotgd/Page/Footer.php` (the quick-key
handler, which Phase 2 replaces by a region `init`). Modules add 55 more.
Pages that emit them stay on full-page rendering until they register a
region lifecycle instead (Phase 2 compatibility list).

## Async (Jaxon) state

- Endpoint: `async/process.php`, runs with `OVERRIDE_FORCED_NAV`, rate limited
  by `ajax_rate_limit_seconds` (1.0 s), CSRF scope `Csrf::SCOPE_ASYNC`.
- **Session lock**: released early only for the allow-list in
  `lotgd_async_is_session_readonly_callable()` – `Bans::affectedUsers`,
  `Commentary::commentaryRefresh|pollUpdates|test`, `Mail::mailStatus`,
  `Timeout::timeoutStatus`. `commentaryText` deliberately keeps the lock
  because it persists the last-seen comment. A Phase 4 poll endpoint joins this list.
- Polling intervals: mail every `check_mail_timeout_seconds` (10 s), timeout
  every `check_timeout_seconds` (5 s).
- DOM owned by Jaxon: the mail link (`maillink`), the notification area
  (`notify`) and the commentary blocks. HTMX regions in Phase 2 must stay
  disjoint from these.

## State-changing operations and the Phase 2 allow-list

Phase 2 starts with display-only operations. Candidates: `village.php`, `forest.php`
and `inn.php` without `op` (GET). All three call `Events::handleEvent()`
(`village.php:137`, `forest.php:37`, `inn.php:46`) before rendering, which can
start a special event that changes state – so the pre-check must decide
"partial or not" before that call runs, and a page where an event fired falls
back to the read-only recovery path (`badnav.php`) instead of re-executing. Every `op` that changes state stays full-page until
Phase 3 action ids exist.
