<?php

use Lotgd\DataCache;
use Lotgd\MySQL\Database;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

function racetroll_getmoduleinfo()
{
    $info = array(
        "name" => "Race - Troll",
        "version" => "1.0",
        "author" => "Eric Stevens",
        "category" => "Races",
        "download" => "core_module",
        "settings" => array(
            "Trollish Race Settings,title",
            "villagename" => "Name for the troll village|Glukmoore",
            "minedeathchance" => "Chance for Trolls to die in the mine,range,0,100,1|90",
        ),
    );
    return $info;
}

function racetroll_install()
{
    module_addhook("chooserace");
    module_addhook("setrace");
    module_addhook("newday");
    module_addhook("villagetext");
    module_addhook("travel");
    module_addhook("charstats");
    module_addhook("validlocation");
    module_addhook("validforestloc");
    module_addhook("moderate");
    module_addhook("changesetting");
    module_addhook("raceminedeath");
    module_addhook("pvpadjust");
    module_addhook("adjuststats");
    module_addhook("racenames");
    return true;
}

function racetroll_uninstall()
{
    global $session;
    $vname = getsetting("villagename", LOCATION_FIELDS);
    $gname = get_module_setting("villagename");
    $conn = Database::getDoctrineConnection();
    $accounts = Database::prefix("accounts");
    $conn->executeStatement(
        "UPDATE {$accounts} SET location = :newCity WHERE location = :oldCity",
        [
            'newCity' => $vname,
            'oldCity' => $gname,
        ]
    );
    if ($session['user']['location'] == $gname) {
        $session['user']['location'] = $vname;
    }
    // Force anyone who was a Troll to rechoose race
    $conn->executeStatement(
        "UPDATE {$accounts} SET race = :unknown WHERE race = :race",
        [
            'unknown' => RACE_UNKNOWN,
            'race' => 'Troll',
        ]
    );
    if ($session['user']['race'] == 'Troll') {
        $session['user']['race'] = RACE_UNKNOWN;
    }

    return true;
}

function racetroll_dohook($hookname, $args)
{
    //yeah, the $resline thing is a hack.  Sorry, not sure of a better way
    // to handle this.
    // Pass it in via args?
    global $session,$resline;
    $city = get_module_setting("villagename");
    $race = "Troll";
    switch ($hookname) {
        case "racenames":
            $args[$race] = $race;
            break;
        case "pvpadjust":
            if ($args['race'] == $race) {
                $args['creatureattack'] += (1 + floor($args['creaturelevel'] / 5));
            }
            break;
        case "adjuststats":
            if ($args['race'] == $race) {
                $args['attack'] += (1 + floor($args['level'] / 5));
            }
            break;
        case "raceminedeath":
            if ($session['user']['race'] == $race) {
                $args['chance'] = get_module_setting("minedeathchance");
            }
            break;
        case "changesetting":
            // Ignore anything other than villagename setting changes
            if ($args['setting'] == "villagename" && $args['module'] == "racetroll") {
                if ($session['user']['location'] == $args['old']) {
                    $session['user']['location'] = $args['new'];
                }
                $conn = Database::getDoctrineConnection();
                $accounts = Database::prefix("accounts");
                $conn->executeStatement(
                    "UPDATE {$accounts} SET location = :newCity WHERE location = :oldCity",
                    [
                        'newCity' => $args['new'],
                        'oldCity' => $args['old'],
                    ]
                );
                if (is_module_active("cities")) {
                    $userPrefs = Database::prefix("module_userprefs");
                    $conn->executeStatement(
                        "UPDATE {$userPrefs} SET value = :newCity"
                        . " WHERE modulename = 'cities' AND setting = 'homecity'"
                        . " AND value = :oldCity",
                        [
                            'newCity' => $args['new'],
                            'oldCity' => $args['old'],
                        ]
                    );
                }
            }
            break;
        case "charstats":
            if ($session['user']['race'] == $race) {
                addcharstat("Vital Info");
                addcharstat("Race", translate_inline($race));
            }
            break;
        case "chooserace":
            output("<a href='newday.php?setrace=$race$resline'>In the swamps of %s</a>`2 as a `@Troll`2, fending for yourself from the very moment you crept out of your leathery egg, slaying your yet unhatched siblings, and feasting on their bones.`n`n", $city, true);
            addnav("`@Troll`0", "newday.php?setrace=$race$resline");
            addnav("", "newday.php?setrace=$race$resline");
            break;
        case "setrace":
            if ($session['user']['race'] == $race) {
                output("`@As a troll, and having always fended for yourself, the ways of battle are not foreign to you.`n");
                output("`^You gain extra attack!");
                if (is_module_active("cities")) {
                    if (
                        $session['user']['dragonkills'] == 0 &&
                        $session['user']['age'] == 0
                    ) {
                        //new farmthing, set them to wandering around this city.
                        set_module_setting(
                            "newest-$city",
                            $session['user']['acctid'],
                            "cities"
                        );
                    }
                    set_module_pref("homecity", $city, "cities");
                    if ($session['user']['age'] == 0) {
                        $session['user']['location'] = $city;
                    }
                }
            }
            break;
        case "validforestloc":
        case "validlocation":
            if (is_module_active("cities")) {
                $args[$city] = "village-$race";
            }
            break;
        case "moderate":
            if (is_module_active("cities")) {
                Translator::getInstance()->setSchema("commentary");
                $args["village-$race"] = Translator::getInstance()->sprintfTranslate("City of %s", $city);
                Translator::getInstance()->setSchema();
            }
            break;
        case "newday":
            if ($session['user']['race'] == $race) {
                racetroll_checkcity();
                apply_buff("racialbenefit", array(
                "name" => "`@Trollish Strength`0",
                "atkmod" => "(<attack>?(1+((1+floor(<level>/5))/<attack>)):0)",
                "allowinpvp" => 1,
                "allowintrain" => 1,
                "rounds" => -1,
                "schema" => "module-racetroll",
                ));
            }
            break;
        case "travel":
            $capital = getsetting("villagename", LOCATION_FIELDS);
            $hotkey = substr($city, 0, 1);
            Translator::getInstance()->setSchema("module-cities");
            if ($session['user']['location'] == $capital) {
                addnav("Safer Travel");
                addnav(array("%s?Go to %s", $hotkey, $city), "runmodule.php?module=cities&op=travel&city=$city");
            } elseif ($session['user']['location'] != $city) {
                addnav("More Dangerous Travel");
                addnav(array("%s?Go to %s", $hotkey, $city), "runmodule.php?module=cities&op=travel&city=$city&d=1");
            }
            if ($session['user']['superuser'] & SU_EDIT_USERS) {
                addnav("Superuser");
                addnav(array("%s?Go to %s", $hotkey, $city), "runmodule.php?module=cities&op=travel&city=$city&su=1");
            }
            Translator::getInstance()->setSchema();
            break;
        case "villagetext":
            racetroll_checkcity();
            if ($session['user']['location'] == $city) {
                $args['text'] = array("`@`b`c%s, Home of the Trolls`c`b`n`2You are standing in a pile of mud, in the heart of a vast swamp.  Around you are the fetid skin-covered hovels that trolls call home.  Well actually, they call them 'ughrrnk', but that's a bit hard on the throats of non-trolls.  Nearby some local peasants squabble over the rapidly decaying remains of the morning's hunt.  Perched atop one of the huts, a badly scarred troll smears indescribable filth over his home's surface in an ill fated attempt to water proof it.`n", $city);
                $args['schemas']['text'] = "module-racetroll";
                $args['clock'] = "`n`2Based on what's left of the morning's kill, you can tell that it is `@%s`2.`n";
                $args['schemas']['clock'] = "module-racetroll";
                if (is_module_active("calendar")) {
                    $args['calendar'] = "`n`2Bellows and noises around you let you know that it is `@%1\$s`2, `@%3\$s %2\$s`2, `@%4\$s`2.`n";
                    $args['schemas']['calendar'] = "module-racetroll";
                }
                $args['title'] = array("The Swamps of %s", $city);
                $args['schemas']['title'] = "module-racetroll";
                $args['sayline'] = "squabbles";
                $args['schemas']['sayline'] = "module-racetroll";
                $args['talk'] = "`n`@Nearby some villagers squabble:`n";
                $args['schemas']['talk'] = "module-racetroll";
                $new = get_module_setting("newest-$city", "cities");
                if ($new != 0) {
                    $cacheKey = "newest-$city";
                    $dataCache = DataCache::getInstance();
                    $row = $dataCache->datacache($cacheKey, 900);
                    if (! is_array($row)) {
                        $conn = Database::getDoctrineConnection();
                        $accounts = Database::prefix("accounts");
                        $row = $conn->fetchAssociative(
                            "SELECT name FROM {$accounts} WHERE acctid = :acctid",
                            [
                                'acctid' => $new,
                            ]
                        ) ?: [];
                        $dataCache->updatedatacache($cacheKey, $row);
                    }
                    if ($row !== []) {
                        $args['newestplayer'] = $row['name'];
                        $args['newestid'] = $new;
                    } else {
                        $args['newestplayer'] = $new;
                        $args['newestid'] = "";
                    }
                } else {
                    $args['newestplayer'] = $new;
                    $args['newestid'] = "";
                }
                if ($new == $session['user']['acctid']) {
                    $args['newest'] = "`n`2You wander the village, picking your teeth with the tiny rib of one of your siblings.  Flicking off a bit of shell that was still stuck to your skin, you watch as it skips several times across the muddy surface of the swamp before a small lizard jumps on it and begins to consume its nutrients.";
                } else {
                    $args['newest'] = "`n`2Picking their teeth with a sliver of bone is `@%s`2, still covered with bits of shell from the hatchery.";
                }
                $args['schemas']['newest'] = "module-racetroll";
                $args['gatenav'] = "Village Gates";
                $args['schemas']['gatenav'] = "module-racetroll";
                $args['fightnav'] = "Barshem Gud";
                $args['schemas']['fightnav'] = "module-racetroll";
                $args['marketnav'] = "Da Gud Stuff";
                $args['schemas']['marketnav'] = "module-racetroll";
                $args['tavernnav'] = "Eatz n' Such";
                $args['schemas']['tavernnav'] = "module-racetroll";
                $args['infonav'] = "Da Infoz";
                $args['schemas']['infonav'] = "module-racetroll";
                $args['section'] = "village-$race";
            }
            break;
    }
    return $args;
}

function racetroll_checkcity()
{
    global $session;
    $race = "Troll";
    $city = get_module_setting("villagename");

    if ($session['user']['race'] == $race && is_module_active("cities")) {
        //if they're this race and their home city isn't right, set it up.
        if (get_module_pref("homecity", "cities") != $city) { //home city is wrong
            set_module_pref("homecity", $city, "cities");
        }
    }
    return true;
}

function racetroll_run()
{
}
