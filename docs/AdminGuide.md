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
email. Run `cron.php` regularly via your system's scheduler:

```bash
# Example: run every 5 minutes
*/5 * * * * php /path/to/lotgd/cron.php >/dev/null 2>&1
```

## SMTP and Email

Configure SMTP credentials in `config/configuration.php` or your environment to send reliable
email. Use authenticated TLS connections and monitor logs for delivery failures. Avoid running an
open relay. The SMTP test in the configuration panel now surfaces the underlying PHPMailer error
message whenever delivery fails, making troubleshooting significantly easier.

For translation details, consult the [Translations guide](TranslationsGuide.md).

