# Module Hook Reference

In general: there is always:

- **every-footer (quite costly, use sparingly)
- **footer-$modulename (where $modulename is any module that is currently run - a module 'jeweler', hook into 'footer-jeweler' if you want to i.e. display your own navs in it, or block navs)

| Category | Filename(s) | Hookname | Input Parameters | Description | Used_for |
|---|---|---|---|---|---|
| root | account.php | accountstats | $stats | Modules can hook into 'accountstats' events. | Extend or customize accountstats. |
| modules | modules/darkhorse.php | adjuststats | $row | Modules can hook into 'adjuststats' events. | Extend or customize adjuststats. |
| modules, pages | modules/racedwarf.php, pages/inn/inn_bartender.php | ale |  | Modules can hook into 'ale' events. | Extend or customize ale. |
| root | mercenarycamp.php | alter-companion | $row | Modules can hook into 'alter-companion' events. | Extend or customize alter-companion. |
| root | armor.php | armortext | $basetext | Modules can hook into 'armortext' events. | Extend or customize armortext. |
| pages | pages/inn/inn_bartender.php | bartenderbribe |  | Modules can hook into 'bartenderbribe' events. | Extend or customize bartenderbribe. |
| root | battle.php | battle | $enemies | Modules can hook into 'battle' events. | Extend or customize battle. |
| root | battle.php | battle-defeat | $badguy | Modules can hook into 'battle-defeat' events. | Extend or customize battle-defeat. |
| root | battle.php | battle-victory | $badguy | Modules can hook into 'battle-victory' events. | Extend or customize battle-victory. |
| modules, pages, root | modules/darkhorse.php, pages/inn/inn_default.php, village.php | blockcommentarea | "section" => "inn"; "section" => "darkhorse" | Modules can hook into 'blockcommentarea' events. | Extend or customize blockcommentarea. |
| root | stables.php | boughtmount |  | Modules can hook into 'boughtmount' events. | Extend or customize boughtmount. |
| root | dragon.php | buffdragon | $badguy | Modules can hook into 'buffdragon' events. | Extend or customize buffdragon. |
| modules | modules/charrestore.php | charrestore_nosavemodules |  | Modules can hook into 'charrestore_nosavemodules' events. | Extend or customize charrestore_nosavemodules. |
| root | create.php | check-create | httpallpost( | Modules can hook into 'check-create' events. | Extend or customize check-create. |
| root | login.php | check-login |  | Modules can hook into 'check-login' events. | Extend or customize check-login. |
| root | clan.php, user.php | clanranks | "ranks" => $ranks, "clanid" => null, "userid" => $userid; "ranks" => $ranks, "clanid"=>$session'user''clanid' | Modules can hook into 'clanranks' events. | Extend or customize clanranks. |
| modules, root | modules/cities/run.php, village.php | collapse{ | "name" => "traveldesc"; "name" => "villageclock-" . $session'user''location' | Modules can hook into 'collapse{' events. | Extend or customize collapse{. |
| root | common.php | core-colors | $output->getColors( | Modules can hook into 'core-colors' events. | Extend or customize core-colors. |
| root | common.php | core-nestedtags | $output->getNestedTags( | Modules can hook into 'core-nestedtags' events. | Extend or customize core-nestedtags. |
| root | common.php | core-nestedtags-eval | $output->getNestedTagEval( | Modules can hook into 'core-nestedtags-eval' events. | Extend or customize core-nestedtags-eval. |
| modules | modules/cities.php, modules/cities/run.php | count-travels | 'available' => 0,'used' => 0 | Modules can hook into 'count-travels' events. | Extend or customize count-travels. |
| root | create.php | create-form |  | Modules can hook into 'create-form' events. | Extend or customize create-form. |
| modules | modules/darkhorse.php | darkhorse-learning |  | Modules can hook into 'darkhorse-learning' events. | Extend or customize darkhorse-learning. |
| modules | modules/darkhorse.php | darkhorsegame | "return" => $gameret | Modules can hook into 'darkhorsegame' events. | Extend or customize darkhorsegame. |
| root | graveyard.php | deathoverlord |  | Modules can hook into 'deathoverlord' events. | Extend or customize deathoverlord. |
| root | dragon.php | dk-preserve | $nochange | Modules can hook into 'dk-preserve' events. | Extend or customize dk-preserve. |
| root | newday.php | dkpointlabels | 'desc' => $labels, 'buy' => $canbuy | Modules can hook into 'dkpointlabels' events. | Extend or customize dkpointlabels. |
| root | donators.php, payment.php | donation | "id" => $id, "amt" => $points, "manual" => ($txnid > "" ? false : true; "id" => $acctid, "amt" => $donation * getsetting('dpointspercurrencyunit', 100 | Modules can hook into 'donation' events. | Extend or customize donation. |
| root | payment.php | donation-error | $post | Modules can hook into 'donation-error' events. | Extend or customize donation-error. |
| root | payment.php | donation-processed | $post | Modules can hook into 'donation-processed' events. | Extend or customize donation-processed. |
| root | donators.php, payment.php | donation_adjustments | "points" => $donation * getsetting('dpointspercurrencyunit', 100; "points" => $amt,"amount" => $amt / getsetting('dpointspercurrencyunit', 100 | Modules can hook into 'donation_adjustments' events. | Extend or customize donation_adjustments. |
| root | dragon.php | dragondeath |  | Modules can hook into 'dragondeath' events. | Extend or customize dragondeath. |
| root | dragon.php | dragonkill |  | Modules can hook into 'dragonkill' events. | Extend or customize dragonkill. |
| root | dragon.php | dragonkilltext |  | Modules can hook into 'dragonkilltext' events. | Extend or customize dragonkilltext. |
| root | common.php | everyhit |  | Modules can hook into 'everyhit' events. | Extend or customize everyhit. |
| root | graveyard.php | favortoheal | "favor" => round(10 * ($max - $session'user''soulpoints' | Modules can hook into 'favortoheal' events. | Extend or customize favortoheal. |
| root | battle.php | forest-victory-xp | $args = 'experience' => $cr_xp_gain | Modules can hook into 'forest-victory-xp' events. | Extend or customize forest-victory-xp. |
| root | forest.php | forest_enter |  | Modules can hook into 'forest_enter' events. | Extend or customize forest_enter. |
| root | forest.php | forestfight-start | $attackstack | Modules can hook into 'forestfight-start' events. | Extend or customize forestfight-start. |
| root | forest.php | forestsearch |  | Modules can hook into 'forestsearch' events. | Extend or customize forestsearch. |
| root | forest.php | forestsearch_noevent |  | Modules can hook into 'forestsearch_noevent' events. | Extend or customize forestsearch_noevent. |
| root | gardens.php | gardens |  | Modules can hook into 'gardens' events. | Extend or customize gardens. |
| root | gypsy.php | gypsy |  | Modules can hook into 'gypsy' events. | Extend or customize gypsy. |
| root | mail.php | header-mail | "done" => 0 | Modules can hook into 'header-mail' events. | Extend or customize header-mail. |
| root | healer.php | healmultiply | "alterpct" => 1.0 | Modules can hook into 'healmultiply' events. | Extend or customize healmultiply. |
| root | hof.php | hof-add |  | Modules can hook into 'hof-add' events. | Extend or customize hof-add. |
| root | dragon.php | hprecalc | $hpgain | Modules can hook into 'hprecalc' events. | Extend or customize hprecalc. |
| root | home.php | index |  | Modules can hook into 'index' events. | Extend or customize index. |
| root | home.php | index-login |  | Modules can hook into 'index-login' events. | Extend or customize index-login. |
| root | home.php | index_bottom |  | Modules can hook into 'index_bottom' events. | Extend or customize index_bottom. |
| pages | pages/inn/inn_default.php | inn |  | Modules can hook into 'inn' events. | Extend or customize inn. |
| pages | pages/inn/inn_default.php | inn-desc |  | Modules can hook into 'inn-desc' events. | Extend or customize inn-desc. |
| pages | pages/inn/inn_default.php | innchatter | $chats | Modules can hook into 'innchatter' events. | Extend or customize innchatter. |
| pages | pages/inn/inn_room.php | innrooms |  | Modules can hook into 'innrooms' events. | Extend or customize innrooms. |
| root | lodge.php | lodge |  | Modules can hook into 'lodge' events. | Extend or customize lodge. |
| root | lodge.php | lodge-desc |  | Modules can hook into 'lodge-desc' events. | Extend or customize lodge-desc. |
| pages | pages/mail/case_write.php | mail-write-notify | 'acctid_to' => $acctidTo | Modules can hook into 'mail-write-notify' events. | Extend or customize mail-write-notify. |
| pages | pages/mail/case_default.php | mailform |  | Modules can hook into 'mailform' events. | Extend or customize mailform. |
| root | mail.php | mailfunctions | $args | Modules can hook into 'mailfunctions' events. | Extend or customize mailfunctions. |
| root | train.php | master-autochallenge |  | Modules can hook into 'master-autochallenge' events. | Extend or customize master-autochallenge. |
| root | mercenarycamp.php | mercenarycamptext | $basetext | Modules can hook into 'mercenarycamptext' events. | Extend or customize mercenarycamptext. |
| root | configuration.php | mod-dyn-settings | $msettings | Modules can hook into 'mod-dyn-settings' events. | Extend or customize mod-dyn-settings. |
| root | moderate.php | moderate | $mods | Modules can hook into 'moderate' events. | Extend or customize moderate. |
| root | armor.php | modify-armor | $row | Modules can hook into 'modify-armor' events. | Extend or customize modify-armor. |
| root | weapons.php | modify-weapon | $row | Modules can hook into 'modify-weapon' events. | Extend or customize modify-weapon. |
| pages | pages/user/user_edit.php | modifyuserview | "userinfo" => $userinfo, "user" => $row | Modules can hook into 'modifyuserview' events. | Extend or customize modifyuserview. |
| root | stables.php | mount-modifycosts | $mount | Modules can hook into 'mount-modifycosts' events. | Extend or customize mount-modifycosts. |
| root | mounts.php | mountfeatures | $args | Modules can hook into 'mountfeatures' events. | Extend or customize mountfeatures. |
| modules | modules/namecolor.php, modules/titlechange.php | namechange |  | Modules can hook into 'namechange' events. | Extend or customize namechange. |
| root | newday.php | newday-intercept |  | Modules can hook into 'newday-intercept' events. | Extend or customize newday-intercept. |
| root | news.php | news-intercept |  | Modules can hook into 'news-intercept' events. | Extend or customize news-intercept. |
| root | paylog.php | paylog |  | Modules can hook into 'paylog' events. | Extend or customize paylog. |
| root | login.php | player-login |  | Modules can hook into 'player-login' events. | Extend or customize player-login. |
| root | login.php | player-logout |  | Modules can hook into 'player-logout' events. | Extend or customize player-logout. |
| root | lodge.php | pointsdesc | "format" => "`#&#149;`7 %s`n", "count" => 0 | Modules can hook into 'pointsdesc' events. | Extend or customize pointsdesc. |
| root | healer.php | potion |  | Modules can hook into 'potion' events. | Extend or customize potion. |
| modules | modules/cities/run.php | pre-travel |  | Modules can hook into 'pre-travel' events. | Extend or customize pre-travel. |
| root | create.php | process-create | $args | Modules can hook into 'process-create' events. | Extend or customize process-create. |
| root | pvp.php | pvpstart | $args | Modules can hook into 'pvpstart' events. | Extend or customize pvpstart. |
| root | user.php | racenames |  | Modules can hook into 'racenames' events. | Extend or customize racenames. |
| root | rawsql.php | rawsql-execphp | "php" => $php | Modules can hook into 'rawsql-execphp' events. | Extend or customize rawsql-execphp. |
| root | rawsql.php | rawsql-execsql | "sql" => $sql | Modules can hook into 'rawsql-execsql' events. | Extend or customize rawsql-execsql. |
| root | rawsql.php | rawsql-modphp | "php" => $php | Modules can hook into 'rawsql-modphp' events. | Extend or customize rawsql-modphp. |
| root | rawsql.php | rawsql-modsql | "sql" => $sql | Modules can hook into 'rawsql-modsql' events. | Extend or customize rawsql-modsql. |
| root | rock.php | rock |  | Modules can hook into 'rock' events. | Extend or customize rock. |
| root | shades.php | shades |  | Modules can hook into 'shades' events. | Extend or customize shades. |
| modules, root | forest.php, modules/cities/run.php | soberup | $args | Modules can hook into 'soberup' events. | Extend or customize soberup. |
| root | stables.php | soldmount |  | Modules can hook into 'soldmount' events. | Extend or customize soldmount. |
| pages, root | pages/inn/inn_bartender.php, user.php | specialtynames | $specialties | Modules can hook into 'specialtynames' events. | Extend or customize specialtynames. |
| root | stables.php | stable-mount |  | Modules can hook into 'stable-mount' events. | Extend or customize stable-mount. |
| root | mounts.php | stablelocs | $locs | Modules can hook into 'stablelocs' events. | Extend or customize stablelocs. |
| root | stables.php | stables-desc |  | Modules can hook into 'stables-desc' events. | Extend or customize stables-desc. |
| root | stables.php | stables-nav |  | Modules can hook into 'stables-nav' events. | Extend or customize stables-nav. |
| root | stables.php | stabletext | $basetext | Modules can hook into 'stabletext' events. | Extend or customize stabletext. |
| root | superuser.php | superuser |  | Modules can hook into 'superuser' events. | Extend or customize superuser. |
| root | superuser.php | superuser-headlines |  | Modules can hook into 'superuser-headlines' events. | Extend or customize superuser-headlines. |
| root | superuser.php | superusertop | "section" => "superuser" | Modules can hook into 'superusertop' events. | Extend or customize superusertop. |
| root | titleedit.php | titleedit |  | Modules can hook into 'titleedit' events. | Extend or customize titleedit. |
| root | train.php | training-defeat | $badguy | Modules can hook into 'training-defeat' events. | Extend or customize training-defeat. |
| root | train.php | training-victory | $badguy | Modules can hook into 'training-victory' events. | Extend or customize training-victory. |
| modules | modules/cities/run.php | travel |  | Modules can hook into 'travel' events. | Extend or customize travel. |
| modules | modules/cities/run.php | travel-cost | "from" => $session'user''location',"to" => $city,"cost" => 0 | Modules can hook into 'travel-cost' events. | Extend or customize travel-cost. |
| pages | pages/user/user_savemodule.php | validateprefs | $post, true, $module | Modules can hook into 'validateprefs' events. | Extend or customize validateprefs. |
| root | configuration.php | validatesettings | $post, true, $module | Modules can hook into 'validatesettings' events. | Extend or customize validatesettings. |
| root | village.php | validlocation | $valid_loc | Modules can hook into 'validlocation' events. | Extend or customize validlocation. |
| root | village.php | village | $texts | Modules can hook into 'village' events. | Extend or customize village. |
| root | village.php | village-desc | $texts | Modules can hook into 'village-desc' events. | Extend or customize village-desc. |
| root | village.php | village-desc-$location | $texts | Modules can hook into 'village-desc-$location' events. | Extend or customize text from village-desc-$location |
| root | village.php | village-$location | $texts | Modules can hook into 'village-$location and execute code specific for this village | Extend or customize village-$location |
| root | village.php | villagetext | $origtexts | Modules can hook into 'villagetext' events. | Extend or customize villagetext. |
| root | village.php | villagetext-$location | $texts | Modules can hook into 'village-$location and customize all texts for that village | Extend or customize village-$location |
| root | list.php | warriorlist | $rows | Modules can hook into 'warriorlist' events. | Extend or customize warriorlist. |
| root | weapons.php | weaponstext | $basetext | Modules can hook into 'weaponstext' events. | Extend or customize weaponstext. |
| modules, root | modules/cities/run.php, village.php | }collapse |  | Modules can hook into '}collapse' events. | Extend or customize }collapse. |
