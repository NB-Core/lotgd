# Namespace Overview

This document describes the main namespaces and helper classes within the `src/` directory. The project organizes most PHP code under the `\Lotgd` root namespace. Each file in `src/Lotgd` provides a class of the same name. The following tables summarise what each class is used for and highlight notable methods.

For in-depth explanations see:
- [Lotgd.md](Lotgd.md) – exhaustive reference for every class in the root namespace
- [MySQL.md](MySQL.md) – details on the database layer and connection handling
- [Nav.md](Nav.md) – how to create and render navigation links
- [Forest.md](Forest.md) – combat helpers and forest outcomes

## \Lotgd (root namespace)

The root namespace contains many helper classes. Each file usually offers a collection of static methods or utility features. Below is a short summary of the purpose of every class:

- **Accounts** – utility methods related to account handling. Provides `saveUser()` to persist session data.
- **AddNews** – helper for inserting news entries. Functions `add()` and `addForUser()` log stories for all players or a particular account.
- **Backtrace** – formats call stack information. Methods `show()` and `showNoBacktrace()` return HTML traces used by the error handler.
- **Battle** – common battle logic shared by combat modules.
- **BellRand** – random number helper using a bell‑curve algorithm.
- **Buffs** – manipulation of buffs and companions. Includes `applyBuff()`, `stripBuff()`, `restoreBuffFields()` and related routines.
- **Censor** – replaces banned words in user supplied text.
- **CharStats** – manages sidebar statistics in the template.
- **CheckBan** – functions for checking IP bans.
- **Commentary** – handles the comment system. Important methods are `addComment()` and `renderCommentary()`.
- **Cookies** – cookie helper for storing template preference and similar data.
- **DataCache** – lightweight file cache with `datacache()` and `updateDataCache()` wrappers.
- **DateTime** – date helpers such as `relativeDate()` and `secondsToNextGameDay()`.
- **DeathMessage** – selects random death messages used during combat.
- **DebugLog** – writes entries to `debuglog` table.
- **Dhms** – converts seconds into `D H M S` strings.
- **DumpItem** – exports PHP variables for debugging.
- **EDom** – wrapper around DOMDocument with helper methods.
- **EmailValidator** – validates e‑mail addresses.
- **ErrorHandler** – central error/exception handling. Registers handlers and renders debug output.
- **Events** – helper for the random event system.
- **ExpireChars** – maintenance job for expiring inactive characters.
- **FightBar** – builds the in-combat navigation.
- **ForcedNavigation** – used when the code sets `forcepage` to redirect the player.
- **Forest\Outcomes** – methods for victory/defeat handling in the forest.
- **Forms** – utilities for building HTML forms from descriptor arrays.
- **GameLog** – game activity logging functions.
- **HolidayText** – manages seasonal text replacements.
- **Http** – small HTTP client used to fetch remote content.
- **LocalConfig** – reads the local configuration file.
- **Mail** – handles the internal mail system (sending, deleting and counting messages).
- **Moderate** – moderation tools for the comment board.
- **ModuleManager** – basic loader used during installation.
- **Modules** – large collection of module helper functions. Deals with hooks, preferences and activation.
- **Motd** – message-of-the-day handling.
- **MountName** – generates mount names shown to players.
- **Mounts** – manages purchased mounts.
- **MySQL** – see dedicated section below.
- **Names** – random name generator.
- **Nav** – central navigation builder. See `docs/Nav.md` for details.
- **Newday** – logic run at the start of each game day.
- **Nltoappon** – converts newlines and colour codes.
- **Output** – collects and formats page output before rendering. Replaces the old `output_collector`.
- **OutputArray** – simplified output collector used by the installer.
- **PageParts** – page header/footer generation and stats display.
- **Partner** – generates partner names for the player character.
- **PhpGenericEnvironment** – wrapper to access PHP global variables in a controlled way.
- **PlayerFunctions** – legacy player operations such as incrementing speciality usage.
- **PullUrl** – constructs query strings for module hooks.
- **Pvp** – provides PvP fight checks and warnings.
- **Redirect** – safe HTTP redirects.
- **RegisterGlobal** – recreates deprecated `register_globals` behaviour for compatibility.
- **SafeEscape** – quoting helper for database strings.
- **Sanitize** – sanitizes input values and arrays recursively.
- **Serialization** – wrappers around PHP serialisation with error handling.
- **ServerFunctions** – assorted helper utilities used across the project.
- **Settings** – loads and stores configuration options.
- **Specialty** – manages player specialties.
- **Spell** – helper for mage spells.
- **Sql** – small wrapper around database errors.
- **Stripslashes** – removes slashes from arrays/strings.
- **SuAccess** – determines superuser access rights.
- **Substitute** – simple text substitution engine used by buffs.
- **Template** – loads and selects templates; manages user template cookie.
- **Translator** – translation layer for all user facing text.
- **TwigTemplate** – Template adapter that renders Twig files.
- **UserLookup** – helper to fetch account details by name or id.

## \Lotgd\MySQL

Database related classes reside in the `MySQL` sub‑namespace:

- **Database** – static connection manager with `query()`, caching methods and helper functions like `affectedRows()` and `prefix()`.
- **DbMysqli** – thin wrapper over `mysqli` used by `Database` to perform actual queries.
- **TableDescriptor** – synchronises schema definitions and can generate `CREATE TABLE` statements.

## \Lotgd\Doctrine

Integrates the Doctrine ORM. The `Bootstrap` class creates the `EntityManager` used by
entities in `src/Lotgd/Entity`. See [Doctrine.md](Doctrine.md) for details on
using repositories and running migrations.

## \Lotgd\Nav

Contains the navigation builder used throughout the game. See `docs/Nav.md` for detailed usage of `NavigationItem`, `NavigationSection` and related helpers. Additional classes include `VillageNav` for the village page and `SuperuserNav` for the admin menu.

## \Lotgd\Forest

Currently hosts only `Outcomes` with functions for forest battle results. Future combat helpers may live here as well.

# Why Namespaces?

The original codebase used many global functions defined in `lib/*.php`. Namespaces allow grouping related functionality into classes, improving autoloading and reducing name collisions. Modules should prefer these namespaced classes over the legacy wrappers documented in `docs/LegacyWrappers.md`.


