# Upgrading Guide

This document explains how to upgrade from **LotGD 1.3.x** to the new **2.0.x** branch.  
The 2.0 line introduces major architectural changes (Composer, PSR-4, Doctrine, Twig, Async), so please follow these steps carefully.

---

## 1. Requirements

- **PHP 8.3** (minimum)
  Earlier PHP versions are not supported.
- Database: MySQL 8.x or MariaDB 10.6+ recommended.  
- Composer installed (`composer --version`).
- A writable cache directory (for Twig templates and async operations).
- CLI access to run Doctrine migrations.

---

## 2. Backup First

Before doing anything:

- Dump your database:  
  ```bash
  mysqldump -u user -p dbname > backup.sql
  ```
- Copy your `public/` (or `htdocs/`) files and `config` directory.
- Ensure you can roll back if something goes wrong.

---

## 3. Update Codebase

1. Replace your old code with the new release (download or `git pull`).  
2. Keep your `config` folder (but update as noted below).  
3. Run `composer install` to pull required dependencies.

### Docker deployments: the runtime image now serves PHP 8.4

The container image moved from `thecodingmachine/php:8.3-v4-apache` to
`8.4-v5-apache`, because the `v4` line stopped receiving upstream rebuilds in
June 2025 and no longer carries PHP or distribution security patches. The
Composer build-stage image was refreshed to the current `composer:2` digest in
the same change; it only produces the `vendor/` tree and never ships in the
runtime, so it needs no action from operators. All three pinned images —
runtime, Composer, and MySQL — are now current; see [Docker deployment: Status
as of 2026-09](docs/Docker.md#status-as-of-2026-09).

For most deployments this is a rebuild and nothing else: `docker compose up -d
--build web`. The application's supported floor is unchanged at PHP 8.3, so
non-Docker installations are unaffected, and CI runs the test suite on 8.3 and
8.4 alike.

Two things are worth checking if you maintain custom modules or a custom image:

- **Custom modules** now run on PHP 8.4. Code removed in 8.4 or relying on
  behaviour deprecated in 8.3 will surface in the container log
  (`docker compose logs web`). The core itself is clean on 8.4.
- **A derived image or extra ini files.** The new runtime is Ubuntu-based, so
  PHP's configuration directory is `/etc/php/8.4/apache2/conf.d`, not
  `/usr/local/etc/php/conf.d`. A file dropped into the old path is not an
  error — PHP just never reads it. The development override's ini mount moved
  to `/etc/lotgd/php-development.ini` accordingly; if you copied that mount
  into your own Compose file, update it.

The full list of runtime assumptions is in [Docker deployment: PHP runtime
image](docs/Docker.md#php-runtime-image).

### Forwarded-protocol headers are no longer trusted from public peers

`X-Forwarded-Proto` (and the other forwarded protocol headers) decide whether
the application considers a request HTTPS, which in turn decides whether
session cookies carry the `Secure` flag and whether HSTS is sent. Previously an
empty `SECURITY_TRUSTED_PROXIES` list meant *every* source was trusted, so any
visitor could assert the header for their own request.

Now, with no explicit allowlist, those headers are honoured only from loopback
and private network ranges and ignored from public addresses. A reverse proxy
on the same host or on a container network is unaffected.

Action is needed only if **your proxy reaches the application from a public IP
address** — a TLS terminator on a different machine, for example. Set the peer
explicitly; the list now accepts CIDR blocks as well as literal addresses:

```php
// dbconnect.php
'SECURITY_TRUST_FORWARDED_PROTO' => true,
'SECURITY_TRUSTED_PROXIES' => '198.51.100.7, 203.0.113.0/24',
```

The symptom of a missed configuration is a site that works but issues
non-`Secure` session cookies and stops sending HSTS. An explicit list replaces
the private-range default rather than extending it, so include every peer that
terminates TLS.

### Docker deployments using legacy example passwords

The hardened Compose configuration rejects the previously documented
`lotgdpass` and `rootpass` values. Existing `db_data` volumes retain MySQL
credentials independently of `.env`, so those credentials must be rotated
before the upgraded web container starts. Follow the complete, backup-aware
procedure in [Docker deployment: Rotating legacy Docker example
passwords](docs/Docker.md#rotating-legacy-docker-example-passwords); changing
only `.env` will leave the application unable to connect.

---

## 4. Run Legacy Upgrade (1.x → 2.x bridge)

If you are coming directly from **1.3.2** (or earlier 1.x):

1. Visit `/installer/` in the browser.
2. Run the **legacy upgrade** script.  
   - This brings your schema in line with the pre-Doctrine format.  
   - It also fixes historical issues (prefixes, indexes, null defaults).
3. Confirm the installer reports success.

---

## 5. Run Doctrine Migrations

Copying the new files alone is **not** a complete upgrade. Before permitting
players to log in, either finish the browser installer or run the Doctrine
migration command below. Migration `Version20250724000021` widens
`accounts.password` for bcrypt hashes and adds `accounts.password_algo`; logins
before those schema changes can truncate new hashes or fail.

Once the legacy upgrade is complete:

1. In your project root, run:
    ```bash
    php bin/doctrine migrations:migrate
    ```
2. The command reads `src/Lotgd/Config/migrations.php` and
   `src/Lotgd/Config/migrations-db.php` by default. If you use custom paths,
   supply `--configuration` and `--db-configuration` flags with the appropriate
   locations.
3. This will apply all new **2.x schema changes**.
4. Watch for any DB prefix issues — these are now supported and should migrate correctly.

### Password recovery for ancient accounts

The browser installer immediately converts the administrator credential used
to authorize an ancient upgrade to bcrypt. It preserves all other 32-character
MD5-shaped values because a single-MD5 hash and a double-MD5 hash cannot be
distinguished without knowing the plaintext password. It also does not guess
that arbitrary short database values are plaintext passwords. Players whose
single-MD5 or plaintext credentials cannot be verified must use the **Forgotten
Password** flow (or have an administrator set a new password); operators must
not apply another MD5 pass to stored account values.

### Advanced admins: CLI-only upgrade

If you prefer to skip the browser installer after copying a new **2.x** release,
you can complete the upgrade purely from the command line:

1. Copy the new release files over your existing installation (preserving the
   `config/` directory).
2. Run Composer to refresh vendor dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Execute the Doctrine migrations:
   ```bash
   php bin/doctrine migrations:migrate
   ```
4. Update the `settings` table so the `installer_version` value matches the
   `$logd_version` defined near the top of `common.php`. You can read the target
   version string directly from that file whenever a new release ships.

Example SQL for updating the installer version:

```sql
UPDATE settings
SET value = '2.x.y'
WHERE setting = 'installer_version';
```

Replace `2.x.y` with the exact value of `$logd_version` from your current
`common.php`. Skipping the browser installer is **only** safe when you already
have an upgraded **2.x** database; fresh installs and legacy bridge upgrades
still need the web installer to seed legacy SQL data and verify required
modules.

---

## 6. Configuration Changes

- **Logging consolidation (this release)**
  - **New: `show_error_details`** (Error Notification section, default off).
    Detailed error output — message, file path, backtrace — used to be published
    to *every* visitor whenever `debug` was on. `debug` turns on page and hook
    profiling and its own warning text talks about load, so an operator enabling
    it to find a slow page had no reason to expect backtraces to become public.
    Detail is now shown to megausers, or to everyone only when this new setting
    is switched on deliberately. **If you relied on `debug` to see errors on a
    live site, enable `show_error_details` after upgrading.**
  - **New: `expiredebug`** (Server Maintenance / Debugging, default 7 days).
    The `debug` table had no retention at all and grew by two rows per page view
    plus one per module hook for as long as `debug` stayed on. It now carries a
    `date` column (migration `Version20250724000024`) and is trimmed by the
    comment cleanup cron routine like every other log table. Set it to `0` to
    keep the old unbounded behaviour.
  - Security-relevant outcomes — a denied superuser page, a refused CSRF token,
    a failed login, an automatic ban, a 2FA failure or lockout, a denied async
    call — now all go to **one** place: the game log under the `security`
    category, and PHP's error log with a matching `diag=` correlation id.
    Several of these were previously only in the per-character debug log or only
    in `error_log`. Nothing needs configuring; the entries simply appear in
    `gamelog.php` where the severity filter can reach them.
  - Events an unauthenticated caller can repeat at will — an individual failed
    login, an async authorization denial — stay in the error log only, so a
    caller cannot drive one database row per request. The durable outcome is
    what gets persisted: the automatic ban, not each guess.
  - Game log categories are a closed vocabulary now (`Lotgd\GameLog::CATEGORY_*`).
    `char expiration` and `char deletion failure` became `expiration`, and
    `comment expiration` became `maintenance`, with failure carried by the
    **severity** rather than by a separate category. Existing rows keep their old
    category strings, so both appear in the category navigation until they age
    out of the retention window.

- **Async (Ajax)**  
  - Config is in `config/async.settings.php`.  
  - Default rate limit: ~1 request/second.  
  - Requests beyond this return HTTP 429. Adjust if needed.
  - **New: `csrf_mode`** — `off`, `log` (new default) or `enforce`. The async
    endpoint now issues a per-session CSRF token and expects it back in an
    `X-LotGD-Csrf` header. Existing installs pick the default up automatically,
    because the loader merges `config/async.settings.php` over the shipped
    `.dist` defaults, so **no action is required to upgrade**.
  - **Still ships as `log`**, and the reason has changed. The transport is now
    verified — the Jaxon client runtime is served from `async/js/vendor/jaxon`
    instead of a CDN, and the header was confirmed to reach the server in a
    real browser against those files. What remains is the upgrade itself.
  - That check found a real defect, which is why the earlier release would have
    logged a failure on every poll: Jaxon defaults `httpRequestOptions.mode` to
    `no-cors`, under which the browser silently drops every non-safelisted
    request header — on same-origin requests too. `async/js/lotgd.jaxon.js` now
    sets `same-origin`.
  - Set `'csrf_mode' => 'log'` if you carry local modifications to the async
    client and want evidence first. Failures are recorded as
    `Jaxon csrf <reason>` in `error_log`, naming the handler.
  - A browser tab left open across the upgrade holds the **old** inlined client,
    which has neither the `same-origin` fix nor the recovery handler added here.
    Enforcing immediately would leave those tabs polling into a 403 with nothing
    but a manual reload to fix it, which is why this stays on `log` for now.
  - **When to promote:** deploy, let open tabs age out (any page load delivers
    the new client), confirm `error_log` carries no `Jaxon csrf` lines, then set
    `'csrf_mode' => 'enforce'` at a time of your choosing. Clients rendered
    after the upgrade recover on their own: a refusal stops the polling loop and
    reloads the page once, guarded in `sessionStorage` so a persistent failure
    cannot turn into a reload loop.
  - Lines naming `TwoFactorAuthPasskey` are the deliberately observe-only
    pre-login pair and never refuse anything; other handlers are the ones to act
    on.
  - Only logged-in callers are recorded, so the log stays about players. An
    unauthenticated request is refused on authentication whatever its token
    says, and logging it would mean the signal never falls quiet: a tab whose
    session timed out keeps polling with the token inlined into the page it came
    from, and a bare POST to the endpoint carries no token at all. Neither says
    anything about whether the transport works.
  - Gameplay is unaffected in every mode: async carries commentary, mail and
    timeout polling, never a game action.

- **Async client assets**
  - The Jaxon browser runtime is no longer fetched from `cdn.jsdelivr.net`. It
    ships in `async/js/vendor/jaxon` and is served from your own installation.
    Nothing to configure; the files are part of the release archive.
  - If your deployment blocks or rewrites `/async/js/`, allow it: without those
    files the async layer does not load at all.
  - `tests/Async/check-jaxon-assets.sh` verifies the files against recorded
    checksums and reports when upstream has moved on. Nothing proposes that
    update automatically, because the files are not a declared dependency.

- **Mail**  
  - Uses **PHPMailer** via Composer.  
  - Check your SMTP settings in `config/config.php`.  
  - `mail()` fallback is no longer recommended.

- **Templates**  
  - **Twig** is now the default.  
  - Templates live under `templates_twig/<skin>/`.  
  - Each skin requires a `config.json` and core files (`page.twig`, `popup.twig`).  
  - Old `.htm` templates still work but are deprecated.
  - Head rendering now supports `headscript_pre` and `headscript_mid` hook buckets, and base layouts emit head assets in the order: `headscript_pre`, Bootstrap assets, `headscript_mid`, `templates/common/colors.css`, template-specific styles, then `headscript`/`script`.

- **Doctrine Mapping**  
  - Entity mappings now rely on PHP attributes; annotation-based mappings are no longer supported.  
  - Ensure custom entities use attributes and remove any legacy annotation tooling dependencies.

- **Performance Defaults**  
  - Output compression via zlib is enabled by default when the `zlib` extension is present. Disable at the PHP level if undesired.  
  - Data cache requires a writable directory: set `DB_USEDATACACHE=1` and `DB_DATACACHEPATH=/path/to/cache` in `dbconnect.php`. The app will warn admins if the path is missing or not writable, even if a temporary fallback directory is used for resilience; those warnings are intentional and should be addressed by setting a stable, writable path.  
  - Twig will cache compiled templates under `<datacachepath>/twig` when writable; otherwise it runs without caching.
  - Runtime hardening is initialized before `session_start()` in `common.php`. Optional rollout switches live in `dbconnect.php` (`SESSION_COOKIE_*`, `SECURITY_*` keys described in `SECURITY.md`).

### Security rollout checklist (TLS / proxy / HSTS / CSP)

1. **Confirm HTTPS detection**
   - Verify your reverse proxy sets `X-Forwarded-Proto: https` for TLS traffic.
   - Enable `SECURITY_TRUST_FORWARDED_PROTO=true` and define `SECURITY_TRUSTED_PROXIES` with your proxy IPs.
   - Validate that direct HTTP requests do not report HTTPS accidentally.
2. **Session cookie rollout**
   - Keep `SESSION_COOKIE_SECURE_AUTO=true` (default).
   - Start with `SESSION_COOKIE_SAMESITE=Lax`; move to `Strict` only after verifying login/payment and cross-site flows.
3. **Header rollout**
   - Keep `SECURITY_HEADERS_ENABLED=true`.
   - Start with `X-Frame-Options` default; migrate to CSP framing with:
     - `SECURITY_USE_CSP_FRAME_ANCESTORS=true`
     - `SECURITY_CSP_FRAME_ANCESTORS='self'` (or stricter)
4. **HSTS phased enablement**
   - Enable with a low initial max age:
     - `SECURITY_HSTS_ENABLED=true`
     - `SECURITY_HSTS_MAX_AGE=300`
   - Increase `SECURITY_HSTS_MAX_AGE` gradually after validation.
   - Add `SECURITY_HSTS_INCLUDE_SUBDOMAINS=true` only when every subdomain is HTTPS-ready.
   - Add `SECURITY_HSTS_PRELOAD=true` only when you fully satisfy browser preload requirements.
5. **Session fixation controls**
   - Ensure custom authentication or privilege elevation code paths call session ID regeneration similarly to `login.php` after authentication success.

---

## 7. Breaking Changes

- **Companions now actually gain levels, which they never did before.** The
  `companionslevelup` setting has existed and defaulted to on, and the code
  behind it computed each companion's new attack, defence and maximum hitpoints
  correctly — and then threw the result away. `train.php` built the updated list
  in `$newcompanions` and never assigned it back or serialised it into the
  session, so the whole block was a no-op for every release it has shipped in.

  It is kept now. A companion is already scaled by the player's level when it is
  hired (`companions.php`, `mercenarycamp.php`); this is what keeps that current
  as the player climbs, rather than leaving the companion frozen at hire-time
  strength.

  Measured over a climb from level 3 to 15:

  | Companion | hired at level 3 | after the climb |
  |---|---|---|
  | light scout (1/1/3 per level) | atk 5, def 4, hp 20 | atk 17, def 16, hp 56 |
  | hired blade (2/2/5 per level) | atk 10, def 8, hp 40 | atk 34, def 32, hp 100 |
  | war beast (3/1/8 per level) | atk 14, def 6, hp 60 | atk 50, def 18, hp 156 |

  Roughly **+240% to +260% attack** over a full climb, where before every figure
  stayed at the left-hand column. A companion is also healed to its new maximum
  on each master defeat.

  **To keep the old behaviour**, set `companionslevelup` to `0`. That switch now
  does what its name says in both positions.

  Two defects in the same block are fixed with it, because they only became
  observable once the result was kept: the healing step tested for an `attack`
  key and then read `maxhitpoints`, so a non-fighting companion was never healed
  and a fighting one without a maximum had its hitpoints set to `null` with an
  undefined-key warning.

- **The core's own state-changing operations now require a CSRF token, and
  every destructive trigger is a button rather than a link.**
  - A module or bookmark that links to a *core* state-changing operation stops
    working — `?op=del`, `?op=delete`, `?op=remove`, `?op=uninstall`,
    `?op=install`, `?op=activate`, `?op=deactivate`, `?op=reinstall`,
    `?op=delban`, `mail.php?op=unread`, `donators.php?op=add2` or a clan
    `&remove=`/`&setrank=` URL. A GET can never be verified, which is the point:
    following a crafted link was the original hole. These render through `Forms::postButton()` now, which
    emits an inline POST form with the token. Build one the same way rather than
    an anchor.
  - A handwritten form posting to one of *these operations* needs
    `Forms::csrfField()` inside it, or the page treats the submission as if
    nothing had been sent (HTTP 400, `$op` and the body cleared). A GET form —
    search, filtering, pagination — is unaffected.

  **Modules are deliberately not affected.** `runmodule.php` carries no guard at
  all, so `runmodule.php?module=yours&op=whatever` is untouched. On the core
  pages that run module hooks — `prefs.php`, `clan.php`, `mail.php`,
  `moderate.php` — the guard fires only for the operations that page implements
  itself, named in a list beside it. An `$op` the core does not know belongs to
  a module and passes through, so an old module that posts its own form keeps
  working without carrying a token it has never heard of. Pages whose writes key
  off a posted field rather than `$op` (`viewpetition.php`, the clan pages)
  guard at the write and name the fields, for the same reason.

  Output encoding moved into `Lotgd\Security\Escape`. If your module builds a
  `confirm()` handler by hand, use `Escape::confirmAttribute()`; `addslashes`
  and `htmlentities` into JavaScript were both wrong and are gone.

- **Destructive operations are POST-only and carry a CSRF token.** Three things
  that used to be reachable by making a browser issue a request no longer are:
  - `user.php?op=del&userid=N` (deleting an account) was a link in the user
    list; it is a form button now. A module or bookmark that links to that URL
    stops working — it needs to post `Csrf::hiddenField(Csrf::SCOPE_USER_EDITOR)`.
  - `prefs.php?op=suicide` (a player deleting their own character) requires a
    POST with `Csrf::SCOPE_SELF_DELETE`, and **the `userid` parameter is gone**:
    the account comes from the session. A link carrying `&userid=` now deletes
    nothing. `PlayerFunctions::charCleanup()` never compared that id to the
    session, so the parameter was doing more than it looked like.
  - `rawsql.php` refuses to execute SQL or PHP without `Csrf::SCOPE_RAW_SQL`.
    Anything driving that page programmatically must render its form first and
    submit the token it contains.

  A `Nav::add()` entry next to a form does not make a GET equivalent:
  `ForcedNavigation` matches the URI and ignores the method, and `SameSite=Lax`
  sends the session cookie on a top-level GET navigation.
- **Forms built by `Forms::showForm()` now carry a CSRF token, and the pages
  behind them validate it.** This affects custom code in two ways:
  - A submittable `showForm()`/`showFormTabbed()` form gains a hidden
    `form_csrf_token` input. A module that iterates the POST body and writes
    every key must run it through `Csrf::stripFrom()`, or it will store the
    token as data. Forms rendered with `$nosave = true` are unchanged: they
    cannot post, so they get no token.
  - Anything posting to `configuration.php`, `prefs.php`, `titleedit.php` or
    `user.php` (`op=save`, `savemodule`, `special`) without that field is now
    refused with 400. Render the page's form and submit the token it contains;
    a handwritten form posting to the same script can use
    `Forms::csrfField()`.

  The field is deliberately not named `csrf_token`: a page may render its own
  token *and* contain a `showForm()` form, and PHP keeps the last input of a
  given name.
- **Namespaces**: Core code moved to `Lotgd\...`. Custom modules calling internal functions may need refactoring.
- **Twig**: Default rendering pipeline. Legacy template hooks may not work without updates.
- **Doctrine**: Direct SQL hacks should be migrated to repositories or services.
- **Doctrine ORM 3**:
  - The ORM dependency now targets 3.x and requires Doctrine DBAL 4.0+.
  - Bootstraps must replace `EntityManager::create()` with `DriverManager::getConnection()` plus the `EntityManager` constructor.
  - Attribute metadata should enable the `reportFieldsWhereDeclared` mode to align with ORM 3 validation.
  - Event listeners must use the dedicated event args classes instead of the deprecated `LifecycleEventArgs`.
  - Custom repositories must continue to extend `EntityRepository` and should be fetched via `getRepository(Fully\Qualified\Entity::class)`; string shorthand is no longer supported.
  - Table prefix subscribers need to prefix both primary tables and join tables using the new mapping object structures.
- **PHP 8.3**: Old PHP 7.x-style code (e.g., deprecated array/string operations) will break.
- **Async**: All Ajax endpoints rewritten to use Jaxon.
- **Two-factor auth key derivation**:
  - The `twofactorauth` module now derives its crypto key from installation-secret material stored in the main settings table (`twofactorauth_key_material`) instead of only `serverurl` and `gameadminemail`.
  - Existing installs keep a compatibility window: encrypted TOTP secrets and signed disable links are still accepted with the legacy key and TOTP secrets are re-encrypted with the new key after a successful verification.
  - Rotating or deleting `twofactorauth_key_material` invalidates any TOTP secrets already re-encrypted with the new key and any disable links signed with it. Plan rotations carefully and keep backups if you must replace the secret.

### DBAL 4 Migration Inventory (2.x)

The 2.x branch is already aligned with Doctrine DBAL 4 result APIs and parameter typing, but legacy wrappers still exist for module compatibility. The list below inventories current DBAL usage and maps it to DBAL 4 migration guide sections so you can evaluate upgrades or custom modules consistently.

**Legacy layer usage (DBAL calls via compatibility wrappers):**
- `src/Lotgd/MySQL/Database.php` → `Connection::executeQuery/executeStatement`, `Result::fetchAssociative/fetchAllAssociative`, `Result::rowCount`, `Connection::quote`.
- `src/Lotgd/MySQL/DbMysqli.php` → legacy `mysqli` driver implementation (no DBAL APIs, kept for modules).
- `src/Lotgd/MySQL/TableDescriptor.php` → schema discovery and DDL via `Database::query()` plus selective `Connection::executeStatement()` calls.

**Direct DBAL usage in core (`src/Lotgd/*`):**
- `src/Lotgd/Mail.php` (DBAL fetch/execute + parameter typing).
- `src/Lotgd/PlayerSearch.php` (executeQuery → `fetchAllAssociative`).
- `src/Lotgd/AddNews.php`, `src/Lotgd/Settings.php`, `src/Lotgd/GameLog.php`, `src/Lotgd/ModuleManager.php` (executeStatement).
- `src/Lotgd/RefererLogger.php`, `src/Lotgd/Translator.php`, `src/Lotgd/Motd.php`, `src/Lotgd/Newday.php` (fetchAssociative/fetchAllAssociative).
- `src/Lotgd/Async/Handler/Commentary.php`, `src/Lotgd/Async/Handler/Bans.php` (fetchAllAssociative + typed params).

**DBAL 4 migration guide mapping (what to watch for):**
- **Result handling changes**: `Result::fetchAssociative()` / `fetchAllAssociative()` usage replaces legacy fetch loops.
- **Statement execution**: `executeQuery()` returns `Result` for reads; `executeStatement()` returns affected row count for writes.
- **Parameter typing changes**: `ParameterType` / `ArrayParameterType` are used for explicit binding (string/int/array parameters).
- **Removed connection fetch helpers**: avoid `fetchAssoc`/`fetchArray`/`fetchColumn` in favor of typed `fetchAssociative()` or `fetchOne()`.

If you maintain custom modules, update any legacy calls to `Database::fetchAssoc()` loops by switching to the DBAL `Result` APIs and named parameters as shown in the refactoring examples above.

### Superuser endpoint hardening update

Recent 2.x updates switched several superuser and security-sensitive endpoints to explicit Doctrine parameter binding (`executeStatement()` / `executeQuery()` with typed params) instead of string-built SQL:

**Migrated in this wave:**
- `moderate.php` (comment delete / restore writes, moderation inserts, dynamic `IN` list binding with `ArrayParameterType::INTEGER`).
- `payment.php` (IPN duplicate check, account donation credit update, and paylog persistence writes).
- `badword.php` (good/nasty word list rewrite operations).
- `masters.php` (training master insert/update/delete writes).
- `titleedit.php` (title insert/update/delete and account title reset updates).
- `creatures.php` (creature insert/update/delete writes with typed DBAL parameters).
- `referers.php` (cleanup delete + site rebuild updates now use typed parameter binding).
- `paylog.php` (process date backfill update is bound through DBAL).
- `configuration.php` (account location mass updates for village/inn rename flows).
- `create.php` (validation/forgot-password/email-change account writes now use bound parameters).
- Previously migrated: `deathmessages.php`, `taunt.php`, `untranslated.php`.

**Pending superuser pages still using legacy string-built writes (track for next waves):**
- None currently tracked in this hardening wave.

If you maintain custom overrides of any migrated page, update those overrides to match bound-parameter execution semantics (including explicit type maps and DBAL array binding for dynamic `IN` clauses).

### SQL addslashes baseline status

The SQL addslashes QA baseline (`src/Lotgd/QA/SqlAddslashesUsageCheck.php`) remains empty: all previously tracked core call sites have been migrated to Doctrine DBAL parameter binding. Any new SQL-building `addslashes()` usage will fail QA and should be migrated to `executeQuery()` / `executeStatement()` with explicit parameter types.

The check now also scans the PHP files that sit directly in the repository root. It previously covered `pages/` and `src/` only, which is how `gamelog.php` kept building its category filter with `addslashes()` on a value taken straight from the query string long after the rest of the tree had moved on. That call site is now bound; it was the last one in the core.

A companion guard, `src/Lotgd/QA/SqlValueInterpolationCheck.php`, flags request values interpolated into the **value** position of an SQL string. It replaces an earlier `InterpolatedDatabaseQueryCheck` that required `Database::query()` to receive a string literal: 193 of 194 call sites in this codebase pass a previously assembled `$sql` variable, so that rule could never be switched on, and it never ran anywhere.

The new rule follows the value instead of the shape of the call. Identifier interpolation (a table name after `FROM`/`UPDATE`, or in backticks) is not reported, because identifiers cannot be bound. An `(int)` cast — at the query or at the assignment — is accepted; a `(string)` cast is not, since it changes the type and nothing about the content.

In CI it runs only over the lines a change actually adds (`--changed-since=<ref>`), so it blocks new cases in about a tenth of a second without touching the historical ones. Run `composer qa:sql-interpolation` for a full audit of the tree; that reports the existing findings too and is not part of `composer static`.

Recent hardening pass migration status:

- Fully migrated (DBAL parameter binding): `src/Lotgd/Security/PasskeyCredentialRepository.php`, `src/Lotgd/CheckBan.php`, `src/Lotgd/ForcedNavigation.php`, `src/Lotgd/Async/Handler/Timeout.php`, and `src/Lotgd/PlayerFunctions.php`.
- Partially migrated (legacy SQL still present in staged areas): `src/Lotgd/Modules.php`, `src/Lotgd/Newday.php`, `src/Lotgd/Commentary.php`, `src/Lotgd/Pvp.php`.

### Refactoring Legacy SQL to Prepared Statements

Legacy database calls often used `Lotgd\MySQL\Database::query()` together with manual escaping via `addslashes`. When upgrading, migrate those calls to Doctrine DBAL prepared statements obtained through `Lotgd\MySQL\Database::getDoctrineConnection()`. The following example shows how to convert a legacy lookup:

```php
// Before: manual escaping and direct query execution.
$db = Lotgd\MySQL\Database::getInstance();
$login = addslashes($login);
$sql = "SELECT acctid, name FROM accounts WHERE login='{$login}'";
$result = $db->query($sql);
```

Replace the manual escaping and `query()` call with a prepared statement that binds parameters. Doctrine handles quoting and typing, so `addslashes` (or similar functions) become unnecessary once the parameter is bound:

```php
$db = Lotgd\MySQL\Database::getInstance();
$conn = $db->getDoctrineConnection();

$sql = 'SELECT acctid, name FROM accounts WHERE login = :login';
$stmt = $conn->prepare($sql);
$stmt->bindValue('login', $login);
$result = $stmt->executeQuery();
```

You can also execute inline without calling `prepare()` explicitly when no cursor reuse is required:

```php
$result = $conn->executeQuery($sql, ['login' => $login]);
```

`executeQuery()` returns a `Result` object for `SELECT` statements, while `executeStatement()` returns the affected row count for `INSERT`, `UPDATE`, or `DELETE` queries. See [docs/Doctrine.md#prepared-statements](docs/Doctrine.md#prepared-statements) and the official [Doctrine DBAL prepared statement guide](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/data-retrieval-and-manipulation.html#prepared-statements) for more details.

### HTTP API Policy and Deprecation Timeline

To keep request handling explicit and secure during the 2.x modernization:

- **Core/refactored code policy (effective now in 2.x):**
  - Use `Lotgd\Http` (`Http::get()`, `Http::post()`, `Http::allGet()`, `Http::allPost()`) as the only HTTP API.
  - Do not introduce `httpget()` / `httppost()` in core/refactored paths.
- **Legacy compatibility policy (2.x only):**
  - `lib/http.php` wrappers remain for legacy/module compatibility.
  - Those wrappers intentionally preserve legacy escaped behaviour.
- **Planned major-version change (3.0 target):**
  - Escaped wrapper semantics are scheduled for retirement in the next major version.
  - Legacy wrappers will either be removed or aligned to raw `Lotgd\Http` semantics; module maintainers should migrate to `Lotgd\Http` and bound DBAL parameters before upgrading to 3.0.

As of this policy, static QA enforcement runs during `composer static` and fails when `httpget()` / `httppost()` usage appears in core/refactored paths.

---

## 8. After Upgrade

- Test **installer logs** for warnings.  
- Check **LoGDnet integration** (settings updated).  
- Verify **email sending** works.  
- Clear and rewarm the Twig cache:  
  ```bash
  rm -rf var/cache/*
  ```
- Run your site and verify:
  - Login works  
  - Commentary updates async  
  - MOTD loads  
  - PvP mail sends correctly

---

## 9. Optional for Developers

- Run PHPUnit tests:
  ```bash
  vendor/bin/phpunit
  ```
- Explore new namespaces under `src/Lotgd/`.
- Review `CHANGELOG.md` for feature-by-feature changes.

---

## 10. Known Issues

- Modules using raw SQL or legacy template hooks may need manual updates.
- Some installer paths still produce warnings if cache is unwritable.
- If you used **custom commentary code**, review the new sanitization and pagination.

---

## Summary

- Backup → Update → Legacy Upgrade → Doctrine Migrate → Config Check → Test.  
- Expect to update **templates, modules, and custom code** for Twig, Composer/PSR-4, and Doctrine.  
- Once migrated, you’ll benefit from a modern stack: Composer dependencies, PHP 8.3 support, Twig theming, async UX, and Doctrine-managed DB schema.

---

👉 See also [CHANGELOG.md](CHANGELOG.md) for detailed release notes.

---

## 11. Operator Hardening Checklist

After upgrade and smoke tests, run this operator-focused hardening pass:

- Verify HTTPS termination correctness end-to-end (TLS at edge/proxy, forwarded scheme handling, and no mixed-content/login downgrade paths).
- Re-check cache path permissions (`DB_DATACACHEPATH` and Twig cache path) and confirm directories are writable by the runtime user only as needed.
- Verify cookie and session behavior in production-like conditions (secure transport, expected login/session persistence, logout invalidation, and async/session continuity).
- Run post-upgrade admin endpoint smoke checks (superuser login, key admin pages, and at least one state-changing admin action with expected auth/CSRF behavior).
