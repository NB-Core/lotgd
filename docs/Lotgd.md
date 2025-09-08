# Lotgd Root Namespace

This page provides an overview of the many helper classes located directly under the `\Lotgd` namespace.  Most gameplay functions that were historically global have been refactored into these classes.  They can be autoloaded via Composer and used directly from modules or internal pages.

Each file in `src/Lotgd/` defines a single class.  The classes are largely static collections of methods and act as entry points to the engine.  The following sections highlight the most important groups of functionality.

## Gameplay and Player Helpers

Classes such as `Accounts`, `Newday`, `PlayerFunctions`, `Pvp`, `Specialty` and `Battle` operate on the player session and game state.  They manipulate the `$session` global and frequently call into the database layer.

Example – awarding a player turns at the start of the day:

```php
Lotgd\Newday::dawn();
```

## Output and Templates

`Output`, `PageParts`, `Template`, `TwigTemplate` and `CharStats` compose the HTML pages shown to the user.  They replace the historical `output_collector` and template system.

```php
$output = new Lotgd\Output();
$output->output("Welcome, %s!", $session['user']['name']);
$body = $output->getOutput();
```

`Translator` manages localisation.  The engine pushes and pops translation namespaces internally so module text can be isolated.  Custom pages can call `Lotgd\Translator::translate()` manually.

## Modules and Hooks

The module system allows third party code to extend the game.  `Modules` and `ModuleManager` contain helper functions to install modules, execute hooks and manage preferences.  Module hooks fire throughout the engine via `modulehook()` which proxies to `Lotgd\Modules::modulehook()`.

## Data and Configuration

`Settings`, `LocalConfig` and `DataCache` deal with persistence of configuration and cached data.  `Accounts::saveUser()` writes the `$session['user']` array back to the `accounts` table.

The `MySQL` sub-namespace (see `MySQL.md`) implements the actual database wrapper used by nearly every class.

## Miscellaneous Utilities

Many other files provide focused helpers: `AddNews` writes stories to the news feed, `Http` performs simple HTTP requests, `Mail` implements the in-game mail system and `Substitute` offers a very small template engine used by buffs.  The complete list is available in [Namespaces.md](Namespaces.md). For translation tools and conventions see [TranslationsGuide.md](TranslationsGuide.md).

### Usage Example

A typical page will combine several of these helpers:

```php
Lotgd\ErrorHandler::register();
Lotgd\Translator::translatorSetup();
Lotgd\Output::appoencode("`@Hello %s!`0", true);
Lotgd\Nav::add("Return", "village.php");
```

Understanding these classes makes it easier to port old `lib/*.php` calls to the modern API.

## Class Reference

Below is a more detailed list of the classes found directly in the `\Lotgd` namespace. Each entry highlights common methods and how they relate to other parts of the engine.

### Accounts

Handles saving and loading of player accounts. `saveUser()` writes the current `$session['user']` data back to the database and is typically called after any change to player stats.

```php
Lotgd\Accounts::saveUser();
```

### AddNews

Adds entries to the `news` table. `add()` is a convenience wrapper for the currently logged-in user while `addForUser()` allows specifying an account id.

```php
Lotgd\AddNews::add('`@%s defeated the dragon!`0', $session['user']['name']);
```

### Backtrace

Utility for displaying error traces. `show()` returns a formatted HTML table of the current stack which is consumed by `ErrorHandler`.

### Battle

Shared combat logic used by different fight modules. Functions such as `fightNav()` and `doAttack()` interact with `Buffs`, `Settings` and the `Translator` to perform damage calculations and messaging.

### BellRand

Generates random numbers with a bell curve distribution. Called by modules that need less predictable results than `mt_rand()`.

### Buffs

Applies and manages temporary effects on the player character. Methods like `applyBuff()` and `stripBuff()` cooperate with `Battle` during fights.

### Censor

Filters banned words from player supplied text. Often used before saving commentary or mail.

### CharStats

Builds the right-hand sidebar showing hitpoints, gold and similar statistics. Depends on `Settings` for display options.

### CheckBan

Checks IP or account bans when a page is loaded. Works together with `Http` and `DbMysqli` to record attempts.

### Commentary

Contains the entire commentary system. Important functions are `addComment()` for inserting a new line and `renderCommentary()` for displaying it in a template section.

### Cookies

Stores simple values such as the selected template name. Used heavily by `Template` and `Translator`.

### DataCache

Implements a lightweight file-based cache. `datacache()` reads a key while `updateDataCache()` writes data. Used internally by `Database::queryCached()`.

### DateTime

Helper for date calculations, e.g. `secondsToNextGameDay()` is referenced by the newday countdown in the village.

### DeathMessage

Selects random phrases shown on the "You are Dead" screen.

### DebugLog

Writes debug entries to the `debuglog` table. Called throughout the engine to record significant actions.

### Dhms

Converts seconds to a `D H M S` formatted string.

### DumpItem

Pretty prints PHP variables for debugging. Commonly wrapped by `Output::debug()`.

### EDom

Simplifies DOMDocument usage when building admin pages or the installer.

### EmailValidator

Validates e-mail addresses when players change their settings.

### ErrorHandler

Centralised exception and error handling. Registers custom handlers that make use of `Backtrace` and `Output` to produce readable pages.

### Events

Hosts the random event system. Functions pick events from modules and integrate with `AddNews` and `PageParts`.

### ExpireChars

Maintenance job that removes inactive characters based on configuration thresholds.

### FightBar

Builds the in-combat navigation shown during fights. Heavily relies on `Nav`.

### ForcedNavigation

Redirects the user when game logic requires it, e.g. after newday.

### Forest

Wrapper that outputs the classic forest navigation menu via `Nav`. See `Forest.md` for combat outcomes.

### Forms

Simplified form builder used by setup pages and some modules. Generates HTML from descriptor arrays similar to Drupal's FAPI.

### GameLog

Writes entries describing player actions to the `gamelog` table for later analysis.

### HolidayText

Replaces certain words with seasonal alternatives around special dates.

### Http

Lightweight HTTP client with timeout handling. Modules may fetch remote data using `Http::fetch()`.

### LocalConfig

Reads site configuration values from `config/local.php`.

### Mail

Implements the in-game private mail system. Functions exist for sending, deleting and counting messages.

### Moderate

Administrative tools for moderating the comment boards.

### ModuleManager

Loader used by the installer. Scans module directories and verifies meta information.

### Modules

The heart of the module system. Provides `modulehook()` to call hook points and `set_module_pref()` for storing preferences.

### Motd

Handles the message of the day. Players see it upon login or newday.

### MountName

Generates the names of mounts as shown to the player, using templates defined in the database.

### Mounts

Business logic for buying and managing mounts.

### Names

Random name generator used by certain modules and monsters.

### Nav

Entry point for menu building. Documented separately in `Nav.md`.

### Newday

Contains everything that happens when a new game day starts: refill turns, award interest, reset buffs and more.

### Nltoappon

Converts newlines into LOTGD colour aware `<br>` tags.

### Output

Collects HTML output and provides colour code parsing. All game pages eventually call `Output::getOutput()` to render their body.

### OutputArray

Smaller variant used during installation.

### PageParts

Renders the header, footer and various stat columns. It combines `Output`, `CharStats` and `Template`.

### Partner

Generates default partner names for the marriage feature.

### PhpGenericEnvironment

Wrapper for accessing PHP globals in a controlled way – mainly used in tests.

### PlayerFunctions

Legacy helper with odds and ends related to the player character.

### PullUrl

Builds URLs for module hooks with encoded parameters.

### Pvp

Checks whether a player can engage in PvP combat and shows warnings when necessary.

### Redirect

Safe `Location:` redirects with optional delay and messaging.

### RegisterGlobal

Recreates the old `register_globals` behaviour in a safer fashion.

### SafeEscape

Quoting helper for strings destined for the database layer.

### Sanitize

Recursively cleans arrays or values from potentially dangerous input.

### Serialization

Wraps PHP `serialize()` and `unserialize()` with extra error checks.

### ServerFunctions

Miscellaneous utilities used across the project: sending headers, managing superuser logins and more.

### Settings

Reads and writes configuration values cached in `$settings`.

### Specialty

Manages player specialties including use of speciality points in combat.

### Spell

Mage spell helper invoked during fights.

### Sql

Wrapper around database error information.

### Stripslashes

Removes slashes from arrays or strings that were escaped earlier.

### SuAccess

Determines whether the current user has certain superuser rights.

### Substitute

Very small template engine used internally by buff definitions.

### Template

Selects the HTML template engine and stores template preference cookies.

### Translator

Loads translation resources and exposes `translate()` for modules.

### TwigTemplate

Adapter used when the Twig engine is active.

### UserLookup

Convenience method to fetch account ids or names with `getUserByEmail()` and friends.


