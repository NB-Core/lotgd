# Administrator Guide

This guide covers essential administrative tasks for Legend of the Green Dragon (LotGD).

## Superuser Permissions

Superusers are trusted accounts granted elevated flags. Use the user editor to assign only the
permissions required. Common flags include access to configuration, translators, and module
management. Avoid giving "Full Superuser" to regular players.

## Accessing the Control Panel

The administrative dashboard lives at [`superuser.php`](../superuser.php). After logging in with a
superuser account, this page exposes links to game settings, user editing, and tools such as cache
clearing.

## Enabling Modules

Modules extend core gameplay. To enable one:

1. Place the module's directory in `modules/`.
2. Visit [`modules.php`](../modules.php) with a superuser account.
3. Install the module if it is new, then enable it from the module list.
4. Configure any settings offered by the module.

See the [Hooks guide](Hooks.md) for details on how modules integrate with the engine.

## Cron and Background Tasks

LotGD relies on scheduled jobs for housekeeping tasks like resetting daily turns or sending queued
email. Run `cron.php` regularly via your system's scheduler, but ensure it cannot be accessed directly
over HTTP. Follow the [Cron Job Setup guidance](../README.md#cron-job-setup) and confirm your web
server denies requests to the script:

```bash
# Example: run every 5 minutes
*/5 * * * * php /path/to/lotgd/cron.php >/dev/null 2>&1
```

`cron.php` accepts an optional bitmask that selects which routines to execute:

| Constant              | Bit value | Routine              | Notes |
| --------------------- | --------- | -------------------- | ----- |
| `CRON_NEWDAY`         | `1`       | Daily reset          | Calls `Newday::runOnce()` for turns, buffs, and cache cleanup. |
| `CRON_DBCLEANUP`      | `2`       | Database maintenance | Runs `Newday::dbCleanup()` (pass a second CLI argument of `1` to force optimization even if it was run less than a day ago). |
| `CRON_COMMENTCLEANUP` | `4`       | Content cleanup      | Executes `Newday::commentCleanup()` to purge aged commentary, news, mail, and logs. |
| `CRON_CHARCLEANUP`    | `8`       | Character expiration | Invokes `Newday::charCleanup()` / `ExpireChars::expire()` to remove inactive characters. |

Omit the argument for the full run (`1|2|4|8 = 15`). To customize, combine bit values: for example,
`php cron.php 13 1` runs the daily reset (`1`), database optimization (`2`, forced by the second
`1`), and character expiration (`8`) while skipping the comment cleanup (`4`).

Every routine writes its activity to the Game Log (`gamelog.php`, available from the Superuser
navigation). Review that log after a cron run to confirm each maintenance step completed; bootstrap
failures are additionally written to `logs/bootstrap.log`. In the Docker image that directory is
root-owned and not writable by the web user on purpose — there, bootstrap and PHP errors go to the
container log instead, so use `docker compose logs web` (the file is denied over HTTP either way).

## Where each kind of message goes

The game keeps several logs, and which one to open depends on what you are looking for.

| Log | What belongs in it | Where to read it |
| --- | --- | --- |
| **Game Log** (`gamelog` table) | What the game and its administrators did: maintenance runs, expirations, module installs, settings changes, and — under the `security` category — every refused or suspicious action. Each row carries a category and a severity (`info`, `warning`, `error`, `debug`) you can filter on. | `gamelog.php` |
| **Debug Log** (`debuglog` table) | A single character's audit trail: gold, gems and experience earned, spent or lost. It answers "what happened to this player's account", not "what did the server refuse". | `user.php?op=debuglog` for one account |
| **PHP error log** | Technical faults, plus a copy of every security event. On Docker this is the container log (`docker compose logs web`). | Container/web server log |
| **`logs/bootstrap.log`** | Failures too early for the game to handle them — a broken `common.php`, a cron that could not start. | `logviewer.php` |
| **`debug` table** | Page and hook runtimes, collected only while the `debug` setting is on. | `debug.php` |
| **`faillog` table** | Every failed login attempt, kept for `expirefaillog` days. Individual attempts are also written to the PHP error log; the automatic ban that follows repeated failures is copied into the Game Log's `security` category, which is the one to watch. | `diagnostics.php` |

All of the above, for a chosen time window, are also pulled together on one screen by
**`diagnostics.php`** — see below.

Security events appear in **both** the Game Log and the PHP error log, and both carry the same
`diag=` correlation id, so a line in the container log can be matched to the row an administrator
sees in the game. Events that an unauthenticated caller can repeat at will — an individual failed
login, a denied or rate-limited async call — are written to the error log only, so that nobody can
drive one database write per request. What reaches the Game Log is the durable outcome: the
automatic ban that follows repeated failures, not each guess.

> ⚠️ **`debug` mode does not publish error details.** Turning `debug` on collects runtimes and
> nothing more. To show error messages, file paths and backtraces to visitors who are not
> megausers you must enable `show_error_details` separately, and you should leave it off on a live
> server.

## The diagnostics page

`diagnostics.php`, reachable from the Superuser Grotto under **Diagnostics**, answers "is
everything still running?" on one screen. It requires `SU_MEGAUSER` — stricter than the other
operational pages, because it puts their contents together with IP addresses and environment
detail in one place.

It is strictly read-only: it changes nothing, offers no buttons, and does not refresh itself. Pick
a time window from the navigation (one hour up to thirty days, twenty-four hours by default) and
the page shows:

- a **runtime snapshot** — game and schema version, PHP and database versions, missing PHP
  extensions, how many players are online, when the last new day and the last maintenance run
  happened, the configured retention for each log, and whether `debug` mode or
  `show_error_details` is switched on. The two versions are reference information rather than a
  check: when they differ the game serves "Upgrade Needed" instead of any page, so reaching this
  one already means they match;
- a **timeline** merging the events worth noticing across sources: security events, anything
  logged as a warning or an error, and failed logins;
- **collapsible sections** per source with the detail — game log, failed logins, the character
  audit trail (read from both `debuglog` and `debuglog_archive`, because the new day routine moves
  the live table into the archive), and the collected runtimes.

Two things it deliberately does **not** show:

- **The submitted form data of a failed login.** The `faillog` table stores the whole POST body,
  which contains the password that was tried. That column is never read.
- **The PHP error log itself.** In the Docker image `error_log` points at `/dev/stderr`, which is
  write-only, so nothing in PHP can read it back. The page names the destination and tells you
  where to look instead. This is why the async diagnostics (`Jaxon csrf`, rate-limit and
  bad-request lines) and individual failed-login records do not appear there: use
  `docker compose logs web`. Failed logins are still visible on the page through the `faillog`
  table.

> ⚠️ **Security reminder:** `cron.php` must never be reachable over HTTP. Depending on the
> `register_argc_argv` setting, a web request can supply the execution bitmask through the query
> string and start a newday or database-cleanup run without any authentication.
>
> The rule now ships with the project: the root [`.htaccess`](../.htaccess) denies it, and the Docker
> virtual host (`docker/apache/lotgd.conf`) denies it independently — Docker deployments set
> `AllowOverride None`, so `.htaccess` files inside the document root are never read there and every
> access rule has to live in the virtual host.
>
> Verify it after every deployment or web-server change; the request must answer `403 Forbidden`:
>
> ```bash
> curl -s -o /dev/null -w '%{http_code}\n' https://your.game/cron.php
> ```
>
> If your server ignores `.htaccess` (Nginx, or Apache with `AllowOverride None`), port the rules
> from the comment block at the end of `.htaccess` into the server configuration, or move the script
> out of the document root so only CLI cron jobs can invoke it.

## SMTP and Email

Configure SMTP credentials in the in-game settings editor ([`configuration.php`](../configuration.php),
reachable from the Superuser navigation); the values are stored as game settings (`gamemailsmtp*`),
not in a file under `config/`. Use authenticated TLS connections and monitor logs for delivery
failures. Avoid running an open relay. The SMTP test in the configuration panel now surfaces the underlying PHPMailer error
message whenever delivery fails, making troubleshooting significantly easier.

For translation details, consult the [Translations guide](TranslationsGuide.md).

