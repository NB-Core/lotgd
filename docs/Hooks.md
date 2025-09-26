# Module Hook Reference

Legend of the Green Dragon exposes hook points so modules can extend or override core behaviour without
patching the engine. Each hook receives an array of arguments and should return the (potentially modified)
array so downstream listeners see the latest values.

When a page renders, the footer is populated by three global hooks:

- `footer-$script` fires first using the current script name (for example, `footer-village` or `footer-newday`).
- If the request is handled by `runmodule.php`, the engine additionally calls `footer-$module` with the module's short name.
- Finally, `everyfooter` runs for all standard footers and `footer-popup` runs for popup layouts.

These hooks should return an associative array of token => output fragments. The engine concatenates the
fragments and swaps them into the footer template, so always append to the array instead of overwriting it.

## Table of Contents
- [Core Pages](#core-pages)
- [Combat and Progression](#combat-and-progression)
- [Town Services and Shops](#town-services-and-shops)
- [Locations and Travel](#locations-and-travel)
- [Communication and Social Features](#communication-and-social-features)
- [Administration and Tools](#administration-and-tools)
- [Module Utilities](#module-utilities)
- [Installer and Configuration](#installer-and-configuration)

## Core Pages

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `accountstats` | `account.php` | List of `['title' => string, 'value' => string]` rows | Append statistics to the account summary page. |
| `check-create` | `create.php` | Request data from `Http::allPost()` plus `blockaccount`/`msg` flags | Validate registration input or block character creation with a custom message. |
| `create-form` | `create.php` | None | Inject additional form fields into the registration form. |
| `process-create` | `create.php` | `Http::allPost()` plus the newly created `acctid` | Run follow-up tasks (starter gear, welcome mail, etc.) after account creation. |
| `check-login` | `login.php` | None | Abort or redirect during authentication (e.g., to enforce maintenance windows). |
| `player-login` | `login.php` | None | React when a player successfully logs in (cache invalidation, welcome messages). |
| `player-logout` | `login.php` | None | Clean up state when a player signs out (purge caches, revoke tokens). |
| `index` | `home.php` | None | Add navigation items or announcements above the login panel. |
| `index-login` | `home.php` | None | Extend the login form (CAPTCHA, extra footnotes). |
| `index_bottom` | `home.php` | None | Append information to the bottom of the public home page. |
| `newday-intercept` | `newday.php` | None | Stop the standard new day reset to run custom logic first. |
| `news-intercept` | `news.php` | None | Override the daily news feed. |
| `header-popup` | `src/Lotgd/Page/Header.php` | None | Inject assets or metadata into popup windows before rendering. |
| `everyheader` / `header-{script}` | `src/Lotgd/Page/Header.php` | `['script' => string]` for `everyheader`; none for `header-{script}` | Provide per-page header customisation or run setup code once per request. |

## Combat and Progression

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `battle` | `battle.php` | Enemy stack array | Adjust creature stats or inject special effects at fight start. |
| `battle-victory` | `battle.php` | Current `$badguy` data | Modify rewards or perform post-fight actions when the player wins. |
| `battle-defeat` | `battle.php` | Current `$badguy` data | Handle player defeat (revivals, penalties). |
| `forestsearch` / `forestsearch_noevent` | `forest.php` | None | Track forest exploration or provide fallback encounters. |
| `forestfight-start` | `forest.php` | Attack stack array | Override the generated enemy list before combat begins. |
| `forest_enter` | `forest.php` | None | Display narrative or gate entry to the forest. |
| `forest-victory-xp` | `battle.php` | `['experience' => int]` | Change the amount of experience awarded for forest battles. |
| `soberup` | `forest.php`, `modules/cities/run.php` | `['soberval' => float, 'sobermsg' => string, 'schema' => string]` | Alter how quickly intoxication fades. |
| `pvpstart` | `pvp.php` | `['atkmsg' => string, 'schemas' => ['atkmsg' => string]]` | Customise PvP introduction text or schema. |
| `master-autochallenge` | `train.php` | None | Automatically start special sparring encounters. |
| `training-victory` / `training-defeat` | `train.php` | `$badguy` array | Adjust outcomes for training fights. |
| `buffdragon` | `dragon.php` | `$badguy` array | Modify the final dragon encounter stats. |
| `hprecalc` | `dragon.php` | Numeric HP gain | Adjust hit point growth on dragon kill. |
| `dk-preserve` | `dragon.php` | Boolean flag | Decide whether prestige choices persist across dragon kills. |
| `dragonkilltext` | `dragon.php` | None | Replace the victory narrative after slaying the dragon. |
| `dragonkill` | `dragon.php` | None | Run extra rewards or resets after a dragon kill. |
| `dragondeath` | `dragon.php` | None | Handle defeat in the dragon cave. |
| `dkpointlabels` | `newday.php` | `['desc' => array, 'buy' => bool]` | Rename dragon point spending options. |
| `favortoheal` | `graveyard.php` | `['favor' => int]` | Adjust the favour cost to return to life. |
| `deathoverlord` | `graveyard.php` | None | Add encounters in the realm of the dead. |

## Town Services and Shops

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `inn` / `inn-desc` | `pages/inn/inn_default.php` | None | Extend inn menus or descriptions. |
| `innchatter` | `pages/inn/inn_default.php` | Chat transcript array | Inject NPC banter into the inn common room. |
| `innrooms` | `pages/inn/inn_room.php` | None | Offer alternative lodging options upstairs. |
| `bartenderbribe` | `pages/inn/inn_bartender.php` | None | React to bribes paid to the bartender. |
| `ale` | `pages/inn/inn_bartender.php` | None | Provide alternate drink options. |
| `gardens` | `gardens.php` | None | Add interactions within the gardens. |
| `gypsy` | `gypsy.php` | None | Expand fortune-teller dialogue. |
| `healmultiply` | `healer.php` | `['alterpct' => float]` | Discount or surcharge healing prices. |
| `potion` | `healer.php` | None | Add extra healing items. |
| `mercenarycamptext` | `mercenarycamp.php` | `$basetext` array | Customise mercenary camp copy. |
| `alter-companion` | `mercenarycamp.php` | Companion row | Adjust companion stats or availability. |
| `camplocs` | `companions.php` | Location list | Control where companions can be summoned. |
| `armortext` / `modify-armor` | `armor.php` | `$basetext` array / armor row | Update blacksmith flavour or modify stock items. |
| `weaponstext` / `modify-weapon` | `weapons.php` | `$basetext` array / weapon row | Update weapon shop descriptions or adjust items. |
| `lodge` / `lodge-desc` | `lodge.php` | None | Add Hunter's Lodge services or flavour text. |
| `pointsdesc` | `lodge.php` | `['format' => string, 'count' => int]` | Describe point purchases in the lodge. |
| `mountfeatures` | `mounts.php` | Feature description array | Advertise mount abilities. |
| `mount-modifycosts` | `stables.php` | Mount record | Adjust purchase prices or upkeep. |
| `boughtmount` / `soldmount` | `stables.php` | None | React when a player acquires or sells a mount. |
| `stable-mount` | `stables.php` | None | Gate mount adoption with extra conditions. |
| `stables-nav` / `stables-desc` / `stabletext` | `stables.php` | None / None / `$basetext` array | Extend stable navigation, descriptions, and prompts. |
| `stablelocs` | `mounts.php` | Location map | Control where individual mounts appear. |
| `rock` | `rock.php` | None | Add secrets to the mysterious rock. |
| `shades` | `shades.php` | None | Extend the Shades of Death experience. |

## Locations and Travel

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `validlocation` | `village.php` | Array of valid location keys | Authorise custom villages or travel destinations. |
| `villagetext` / `villagetext-{location}` | `village.php` | Base text array | Rewrite generic or location-specific village prose. |
| `village` / `village-{location}` | `village.php` | Display text array | Add interactive features to the main village view. |
| `village-desc` / `village-desc-{location}` | `village.php` | Display text array | Customise the sidebar description block. |
| `collapse{` / `}collapse` | `village.php`, `modules/cities/run.php` | `['name' => string]` or none | Wrap custom content in collapsible sections. |
| `blockcommentarea` | `village.php`, `pages/inn/inn_default.php` | `['section' => string]` | Disable or alter commentary sections. |
| `count-travels` | `modules/cities.php`, `modules/cities/run.php` | `['available' => int, 'used' => int]` | Adjust how many travel actions remain today. |
| `pre-travel` | `modules/cities/run.php` | None | Inject UI before showing travel options. |
| `travel` | `modules/cities/run.php` | None | Add destinations or events to the travel list. |
| `travel-cost` | `modules/cities/run.php` | `['from' => string, 'to' => string, 'cost' => int]` | Set gold/turn cost for travelling to a city. |
| `forest_enter` | `forest.php` | None | Offer alternatives before players enter the forest. |

## Communication and Social Features

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `header-mail` | `mail.php` | `['done' => int]` | Override mail actions (e.g., disable deletion). |
| `mailfunctions` | `mail.php` | Array of `[page, label]` pairs | Add custom tabs to the mail client. |
| `mailform` | `pages/mail/case_default.php` | None | Append content below the inbox list. |
| `mail-write-notify` | `pages/mail/case_write.php` | `['acctid_to' => int]` | Warn players before messaging certain users. |
| `addpetition` | `pages/petition/petition_default.php` | Form post array | Inspect or alter petitions before saving. |
| `petitionform` | `pages/petition/petition_default.php` | None | Inject helper text into the petition form. |
| `petition-status` | `viewpetition.php` | Status definition array | Add new petition workflow states. |
| `petitions-descriptions` | `viewpetition.php` | None | Explain custom petition categories. |
| `petition-abuse` | `viewpetition.php` | `['acctid' => int, 'abused' => string]` | Flag players who misuse petitions. |
| `faq-pretoc` / `faq-toc` / `faq-posttoc` | `pages/petition/petition_faq.php` | None | Extend the petition FAQ table of contents. |
| `about` | `pages/about/about_default.php` | None | Add project credits or server information. |
| `showsettings` | `pages/about/about_setup.php` | Structured settings array | Publish extra configuration values. |
| `biotarget` / `biotop` | `bio.php` | Target account array | Redirect bios or add summary headers. |
| `biostat` | `bio.php` | Target account array | Append statistics to a player's biography. |
| `bioinfo` | `bio.php` | Target account array | Output additional biography content. |
| `bioend` | `bio.php` | Target account array | Close with footer content (links, credits). |
| `bio-mount` | `bio.php` | Mount display array | Tweak how mounts appear in bios. |
| `clanranks` | `bio.php`, `clan.php`, `user.php` | `['ranks' => array, 'clanid' => ?int, 'userid' => ?int]` | Define custom clan rank titles. |
| `specialtynames` | `pages/inn/inn_bartender.php`, `user.php` | Optional map of `[specid => label]` | Register new combat specialties. |
| `racenames` | `user.php` | None | Add playable races to selection lists. |
| `warriorlist` | `list.php` | Player row array | Annotate the warrior listing with module data. |

## Administration and Tools

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `superuser-headlines` | `superuser.php` | Array of pre-rendered lines | Display alerts in the Superuser Grotto header. |
| `superusertop` | `superuser.php` | `['section' => string]` | Choose which commentary board appears in the grotto. |
| `superuser` | `superuser.php` | None (allow inactive) | Add administrative panels. |
| `moderate` | `moderate.php` | Commentary section list | Register extra moderation queues. |
| `paylog` | `paylog.php` | None | Extend the payment log view. |
| `donation` | `donators.php`, `payment.php` | `['id' => int, 'amt' => int, 'manual' => bool]` | React when donation points are granted. |
| `donation_adjustments` | `donators.php`, `payment.php` | `['points' => int, 'amount' => float, 'acctid' => int, 'messages' => array]` | Modify point totals or add audit messages. |
| `donation-error` | `payment.php` | Payment POST data | Handle gateway failures. |
| `donation-processed` | `payment.php` | Payment POST data | Trigger webhooks after successful processing. |
| `rawsql-execsql` / `rawsql-modsql` | `rawsql.php` | `['sql' => string]` | Vet SQL queries or capture audit logs. |
| `rawsql-execphp` / `rawsql-modphp` | `rawsql.php` | `['php' => string]` | Inspect or block PHP snippets before execution. |

## Module Utilities

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `everyhit` | `common.php` | None | Run lightweight tasks on every request (avoid heavy logic). |
| `core-colors` | `common.php` | Color mapping array | Extend colour codes understood by the output engine. |
| `core-nestedtags` | `common.php` | Nested tag definition array | Support custom markup tags. |
| `core-nestedtags-eval` | `common.php` | Evaluation callback list | Control which tags can execute PHP code. |
| `charstats` | `src/Lotgd/PageParts.php` | None | Add extra stat blocks to the left-hand sidebar. |
| `loggedin` | `src/Lotgd/PageParts.php` | Array of online player rows | Modify the data used to render the online character list. |
| `petitioncount` | `src/Lotgd/PageParts.php` | `['petitioncount' => string]` | Replace the superuser petition counter output. |
| `onlinecharlist` | `src/Lotgd/PageParts.php` | `['count' => int, 'list' => string, 'handled' => bool]` | Rewrite the "who's online" snippet shown in footers. |
| `template-{fieldname}` | `src/Lotgd/Template.php` | `['content' => string]` | Replace template fragments before they reach the browser. |
| `adjuststats` | `modules/darkhorse.php` | Player stat array | Mask stats shown by the Dark Horse tavern. |
| `darkhorse-learning` / `darkhorsegame` | `modules/darkhorse.php` | None / `['return' => string]` | Add content to the Dark Horse mini-games. |
| `namechange` | `modules/titlechange.php`, `modules/namecolor.php` | None | React whenever a player changes their name. |
| `charrestore_nosavemodules` | `modules/charrestore.php` | None | Block specific modules from being restored with a character. |

## Installer and Configuration

| Hook name | Context | Arguments | Typical uses |
| --- | --- | --- | --- |
| `showsettings` | `pages/about/about_setup.php` | Structured settings array | Publish installer-specific configuration data. |
| `mod-dyn-settings` | `configuration.php` | Module settings definition array | Add dynamic module configuration fields. |
| `validatesettings` | `configuration.php` | Posted module settings array | Prevent invalid values during module setup. |
| `validateprefs` | `pages/user/user_savemodule.php` | Posted module preference array | Guard user preference updates from the module editor. |
| `addpetition` | `pages/petition/petition_default.php` | Petition POST data | Extend the installer/contact workflow for support. |

