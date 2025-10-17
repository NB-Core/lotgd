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
failures are additionally written to `logs/bootstrap.log`.

> ⚠️ **Security reminder:** Keep `cron.php` outside the document root or restrict it in your web
> server configuration. On Apache, add the rule below to the root [`.htaccess`](../.htaccess) file to
> deny direct access:
>
> ```apache
> <Files "cron.php">
>     Require all denied
> </Files>
> ```
>
> Apply the same protection in other servers (for example, returning `403` from an Nginx `location`
> block) or remove the script from the public directory so that only CLI cron jobs can invoke it.
> After deploying the rule, test that your web server responds with `403 Forbidden` (or equivalent)
> when requesting `/cron.php` directly.

## SMTP and Email

Configure SMTP credentials in `config/configuration.php` or your environment to send reliable
email. Use authenticated TLS connections and monitor logs for delivery failures. Avoid running an
open relay. The SMTP test in the configuration panel now surfaces the underlying PHPMailer error
message whenever delivery fails, making troubleshooting significantly easier.

For translation details, consult the [Translations guide](TranslationsGuide.md).

