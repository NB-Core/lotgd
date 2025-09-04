# Legacy Wrapper Functions

Many older modules rely on global functions defined in `lib/*.php`. These files now act as thin wrappers around the namespaced classes in `src/Lotgd`. New code should call the classes directly.

## Available Wrappers

The following wrappers exist for backwards compatibility:

- `lib/addnews.php` → `\Lotgd\AddNews` (`addnews()`, `addnews_for_user()`)
- `lib/battle-buffs.php` → `\Lotgd\Buffs`
- `lib/battle-functions.php` → `\Lotgd\FightBar`
- `lib/battle-skills.php` → `\Lotgd\Battle`
- `lib/bell_rand.php` → `\Lotgd\BellRand`
- `lib/buffs.php` → `\Lotgd\Buffs` (buff and companion helpers)
- `lib/censor.php` → `\Lotgd\Censor`
- `lib/charcleanup.php` → `\Lotgd\PlayerFunctions`
- `lib/checkban.php` → `\Lotgd\CheckBan`
- `lib/commentary.php` → `\Lotgd\Commentary`
- `lib/datacache.php` → `\Lotgd\DataCache`
- `lib/datetime.php` → `\Lotgd\DateTime`
- `lib/dbmysqli.php` and `lib/dbwrapper.php` → `\Lotgd\MySQL`
- `lib/deathmessage.php` → `\Lotgd\DeathMessage`
- `lib/debuglog.php` → `\Lotgd\DebugLog`
- `lib/dhms.php` → `\Lotgd\Dhms`
- `lib/dump_item.php` → `\Lotgd\DumpItem`
- `lib/e_dom.php` → `\Lotgd\EDom`
- `lib/errorhandler.php` → `\Lotgd\ErrorHandler`
- `lib/events.php` → `\Lotgd\Events`
- `lib/experience.php` → `\Lotgd\PlayerFunctions`
- `lib/expire_chars.php` → `\Lotgd\ExpireChars`
- `lib/fightnav.php` → `\Lotgd\Battle`
- `lib/forcednavigation.php` → `\Lotgd\ForcedNavigation`
- `lib/forest.php` and `lib/forestoutcomes.php` → `\Lotgd\Forest`
- `lib/forms.php` → `\Lotgd\Forms`
- `lib/gamelog.php` → `\Lotgd\GameLog`
- `lib/holiday_texts.php` → `\Lotgd\HolidayText`
- `lib/http.php` → `\Lotgd\Http`
- `lib/increment_specialty.php` → `\Lotgd\Specialty`
- `lib/is_email.php` → `\Lotgd\EmailValidator`
- `lib/local_config.php` → `\Lotgd\LocalConfig`
- `lib/lookup_user.php` → `\Lotgd\UserLookup`
- `lib/mail.php` → `\Lotgd\Mail`
- `lib/moderate.php` → `\Lotgd\Moderate`
- `lib/modules.php` → `\Lotgd\Modules`
- `lib/motd.php` → `\Lotgd\Motd`
- `lib/mountname.php` → `\Lotgd\MountName`
- `lib/mounts.php` → `\Lotgd\Mounts`
- `lib/names.php` → `\Lotgd\Names`
- `lib/nav.php` → `\Lotgd\Nav`
- `lib/nltoappon.php` → `\Lotgd\Nltoappon`
- `lib/output.php` → `\Lotgd\Output`
- `lib/output_array.php` → `\Lotgd\OutputArray`
- `lib/pageparts.php` → `\Lotgd\PageParts`
- `lib/partner.php` → `\Lotgd\Partner`
- `lib/php_generic_environment.php` → `\Lotgd\PhpGenericEnvironment`
- `lib/playerfunctions.php` → `\Lotgd\PlayerFunctions`
- `lib/pullurl.php` → `\Lotgd\PullUrl`
- `lib/pvplist.php`, `lib/pvpsupport.php`, `lib/pvpwarning.php` → `\Lotgd\Pvp`
- `lib/redirect.php` → `\Lotgd\Redirect`
- `lib/safeescape.php` → `\Lotgd\SafeEscape`
- `lib/sanitize.php` → `\Lotgd\Sanitize`
- `lib/saveuser.php` → `\Lotgd\Accounts::saveUser()`
- `lib/sendmail.php` → `\Lotgd\Mail`
- `lib/serialization.php` → `\Lotgd\Serialization`
- `lib/serverfunctions.class.php` → `\Lotgd\ServerFunctions`
- `lib/settings.class.php` and `lib/settings.php` → `\Lotgd\Settings`
- `lib/show_backtrace.php` → `\Lotgd\Backtrace`
- `lib/showform.php` → `\Lotgd\Forms`
- `lib/spell.php` → `\Lotgd\Spell`
- `lib/sql.php` → `\Lotgd\Sql`
- `lib/stripslashes_deep.php` → `\Lotgd\Stripslashes`
- `lib/su_access.php` → `\Lotgd\SuAccess`
- `lib/substitute.php` → `\Lotgd\Substitute`
- `lib/superusernav.php` → `\Lotgd\Nav\SuperuserNav`
- `lib/systemmail.php` → `\Lotgd\Mail`
- `lib/tabledescriptor.php` → `\Lotgd\MySQL\TableDescriptor`
- `lib/taunt.php` → `\Lotgd\Battle`
- `lib/template.php` → `\Lotgd\Template`
- `lib/tempstat.php` → `\Lotgd\PlayerFunctions`
- `lib/titles.php` → `\Lotgd\PlayerFunctions`
- `lib/translator.php` → `\Lotgd\Translator`
- `lib/villagenav.php` → `\Lotgd\Nav\VillageNav`

These files mostly contain simple proxy functions or class aliases. They exist so that old modules written for the pre‑namespaced API continue to run.

## Migration Guide

1. Identify calls to global functions from `lib/`. For example `addnav()` or `mail_delete_message()`.
2. Replace the function call with the equivalent method on the namespaced class. Example:

```php
// Old
addnav('Return', 'village.php');

// New
\Lotgd\Nav::add('Return', 'village.php');
```

3. Remove any `require_once 'lib/xyz.php'` lines when the class is autoloaded via Composer.
4. Test the module. The behaviour should remain the same as the wrapper simply forwards the call.

Dropping the wrappers entirely will reduce global function usage and makes dependencies explicit. New modules should avoid including `lib/*.php` and instead rely solely on the namespaced API.

