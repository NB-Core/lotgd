<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Forest;
use Lotgd\Buffs;
use Lotgd\Forest\Outcomes;
use Lotgd\Battle;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Random;
use Lotgd\Events;
use Lotgd\DateTime;
use Lotgd\Partner;
use Lotgd\CreateString;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";

$output = Output::getInstance();
$settings = Settings::getInstance();


Translator::getInstance()->setSchema("forest");

$fight = false;
Header::pageHeader("The Forest");
$dontdisplayforestmessage = Events::handleEvent("forest");

$op = Http::get("op");

$battle = false;

if ($op == "run") {
    if (Random::eRand() % 3 == 0) {
        $output->output("`c`b`&You have successfully fled your opponent!`0`b`c`n");
        $op = "";
        Http::set('op', "");
                Battle::unsuspendBuffs();
        foreach ($companions as $index => $companion) {
            if (isset($companion['expireafterfight']) && $companion['expireafterfight']) {
                unset($companions[$index]);
            }
        }
    } else {
        $output->output("`c`b`\$You failed to flee your opponent!`0`b`c");
    }
}

if ($op == "dragon") {
    Nav::add("Enter the cave", "dragon.php");
    Nav::add("Run away like a baby", "inn.php?op=fleedragon");
    $output->output("`\$You approach the blackened entrance of a cave deep in the forest, though the trees are scorched to stumps for a hundred yards all around.");
    $output->output("A thin tendril of smoke escapes the roof of the cave's entrance, and is whisked away by a suddenly cold and brisk wind.");
    $output->output("The mouth of the cave lies up a dozen feet from the forest floor, set in the side of a cliff, with debris making a conical ramp to the opening.");
    $output->output("Stalactites and stalagmites near the entrance trigger your imagination to inspire thoughts that the opening is really the mouth of a great leech.`n`n");
    $output->output("You cautiously approach the entrance of the cave, and as you do, you hear, or perhaps feel a deep rumble that lasts thirty seconds or so, before silencing to a breeze of sulfur-air which wafts out of the cave.");
    $output->output("The sound starts again, and stops again in a regular rhythm.`n`n");
    $output->output("You clamber up the debris pile leading to the mouth of the cave, your feet crunching on the apparent remains of previous heroes, or perhaps hors d'oeuvres.`n`n");
    $output->output("Every instinct in your body wants to run, and run quickly, back to the warm inn, and the even warmer %s`\$.", Partner::getPartner());
    $output->output("What do you do?`0");
    $session['user']['seendragon'] = 1;
}

if ($op == "search") {
    DateTime::checkDay();
    if ($session['user']['turns'] <= 0) {
        $output->output("`\$`bYou are too tired to search the forest any longer today.  Perhaps tomorrow you will have more energy.`b`0");
        $op = "";
        Http::set('op', "");
    } else {
        HookHandler::hook("forestsearch", array());
        $args = array(
            'soberval' => 0.9,
            'sobermsg' => "`&Faced with the prospect of death, you sober up a little.`n",
            'schema' => 'forest');
        HookHandler::hook("soberup", $args);
        if (HookHandler::moduleEvents("forest", $settings->getSetting("forestchance", 15)) != 0) {
            if (!Nav::checkNavs()) {
                // If we're showing the forest, make sure to reset the special
                // and the specialmisc
                $session['user']['specialinc'] = "";
                $session['user']['specialmisc'] = "";
                $dontdisplayforestmessage = true;
                $op = "";
                Http::set("op", "");
            } else {
                Footer::pageFooter();
            }
        } else {
            HookHandler::hook("forestsearch_noevent", array());
            $session['user']['turns']--;
            $battle = true;
            if (Random::eRand(0, 2) == 1) {
                $plev = (Random::eRand(1, 5) == 1 ? 1 : 0);
                $nlev = (Random::eRand(1, 3) == 1 ? 1 : 0);
            } else {
                $plev = 0;
                $nlev = 0;
            }
            $type = Http::get('type');
            if ($type == "slum") {
                $nlev++;
                $output->output("`\$You head for the section of forest you know to contain foes that you're a bit more comfortable with.`0`n");
            }
            if ($type == "thrill") {
                $plev++;
                $output->output("`\$You head for the section of forest which contains creatures of your nightmares, hoping to find one of them injured.`0`n");
            }
            $extrabuff = 0;
            if ($type == "suicide") {
                if ($session['user']['level'] <= 7) {
                    $plev += 1;
                    $extrabuf = .25;
                } elseif ($session['user']['level'] < 14) {
                    $plev += 2;
                    $extrabuf = 0;
                } else {
                    $plev++;
                    $extrabuff = .4;
                }
                $output->output("`\$You head for the section of forest which contains creatures of your nightmares, looking for the biggest and baddest ones there.`0`n");
            }
            $multi = 1;
            $targetlevel = ($session['user']['level'] + $plev - $nlev );
            $mintargetlevel = $targetlevel;
            if ($settings->getSetting("multifightdk", 10) <= $session['user']['dragonkills']) {
                if (Random::eRand(1, 100) <= (int)$settings->getSetting("multichance", 25)) {
                    $multi = Random::eRand((int)$settings->getSetting('multibasemin', 2), (int)$settings->getSetting('multibasemax', 3));
                    if ($type == "slum") {
                        $multi -= Random::eRand((int)$settings->getSetting("multislummin", 0), (int)$settings->getSetting("multibasemax", 1));
                        if (Random::eRand(0, 1)) {
                            $mintargetlevel = $targetlevel - 1;
                        } else {
                            $mintargetlevel = $targetlevel - 2;
                        }
                    } elseif ($type == "thrill") {
                        $multi += Random::eRand((int)$settings->getSetting("multithrillmin", 1), (int)$settings->getSetting("multithrillmax", 2));
                        if (Random::eRand(0, 1)) {
                            $targetlevel++;
                            $mintargetlevel = $targetlevel - 1;
                        } else {
                            $mintargetlevel = $targetlevel - 1;
                        }
                    } elseif ($type == "suicide") {
                        $multi += Random::eRand((int)$settings->getSetting("multisuimin", 2), (int)$settings->getSetting("multisuimax", 4));
                        if (Random::eRand(0, 1)) {
                            $mintargetlevel = $targetlevel - 1;
                        } else {
                            $targetlevel++;
                            $mintargetlevel = $targetlevel - 1;
                        }
                    }
                    $multi = min($multi, $session['user']['level']);
                }
            } else {
                $multi = 1;
            }
            if ($targetlevel < 1) {
                $targetlevel = 1;
            }
            if ($mintargetlevel < 1) {
                $mintargetlevel = 1;
            }
            if ($mintargetlevel > $targetlevel) {
                $mintargetlevel = $targetlevel;
            }
            $output->debug("Creatures: $multi Targetlevel: $targetlevel Mintargetlevel: $mintargetlevel");
            $packofmonsters = false;
            if ($multi > 1) {
                if ($settings->getSetting('allowpackmonsters', 0)) {
                    $packofmonsters = (Random::eRand(0, 5) == 0); // true or false
                } else {
                    $packofmonsters = false; //set for later use
                }
                switch ($packofmonsters) {
                    case false:
                        $multicat = "";
                        //$multicat = ($settings->getSetting('multicategory', 0)?"GROUP BY creaturecategory":"");   //grouping like that is against newer sql policies, leave it for now
                        $sql = "SELECT * FROM " . Database::prefix("creatures") . " WHERE creaturelevel <= $targetlevel AND creaturelevel >= $mintargetlevel AND forest=1 $multicat ORDER BY rand(" . Random::eRand() . ") LIMIT $multi";
                        break;
                    case true:
                        $sql = "SELECT * FROM " . Database::prefix("creatures") . " WHERE creaturelevel <= $targetlevel AND creaturelevel >= $mintargetlevel AND forest=1 ORDER BY rand(" . Random::eRand() . ") LIMIT 1";
                        break;
                }
            } else {
                $sql = "SELECT * FROM " . Database::prefix("creatures") . " WHERE creaturelevel <= $targetlevel AND creaturelevel >= $mintargetlevel AND forest=1 ORDER BY rand(" . Random::eRand() . ") LIMIT 1";
            }
            $result = Database::query($sql);
            Buffs::restoreBuffFields();
            if (Database::numRows($result) == 0) {
                // There is nothing in the database to challenge you, let's
                // give you a doppleganger.
                $badguy = ['diddamage' => 0];
                $badguy['creaturename'] =
                    "An evil doppleganger of " . $session['user']['name'];
                $badguy['creatureweapon'] = $session['user']['weapon'];
                $badguy['creaturelevel'] = $session['user']['level'];
                $badguy['creaturegold'] = 0;
                $badguy['creatureexp'] =
                round($session['user']['experience'] / 10, 0);
                $badguy['creaturehealth'] = $session['user']['maxhitpoints'];
                $badguy['creatureattack'] = $session['user']['attack'];
                $badguy['creaturedefense'] = $session['user']['defense'];
                $stack[] = $badguy;
            } else {
                if ($packofmonsters == true) {
                    $initialbadguy = Database::fetchAssoc($result);
                    $prefixs = array("Elite","Dangerous","Lethal","Savage","Deadly","Malevolent","Malignant");
                    for ($i = 0; $i < $multi; $i++) {
                        $initialbadguy['creaturelevel'] = Random::eRand($mintargetlevel, $targetlevel);
                                               $badguy = Outcomes::buffBadguy($initialbadguy);
                        if ($type == "thrill") {
                            // 10% more experience
                            $badguy['creatureexp'] = round($badguy['creatureexp'] * 1.1, 0);
                            // 10% more gold
                            $badguy['creaturegold'] = round($badguy['creaturegold'] * 1.1, 0);
                        }
                        if ($type == "suicide") {
                            // Okay, suicide fights give even more rewards, but
                            // are much harder
                            // 25% more experience
                            $badguy['creatureexp'] = round($badguy['creatureexp'] * 1.25, 0);
                            // 25% more gold
                            $badguy['creaturegold'] = round($badguy['creaturegold'] * 1.25, 0);
                            // Now, make it tougher.
                            $mul = 1.25 + $extrabuff;
                            $badguy['creatureattack'] = round($badguy['creatureattack'] * $mul, 0);
                            $badguy['creaturedefense'] = round($badguy['creaturedefense'] * $mul, 0);
                            $badguy['creaturehealth'] = round($badguy['creaturehealth'] * $mul, 0);
                            // And mark it as an 'elite' troop.
                            $prefixs = Translator::translateInline($prefixs);
                            $key = array_rand($prefixs);
                            $prefix = $prefixs[$key];
                            $badguy['creaturename'] = $prefix . " " . $badguy['creaturename'];
                        }
                        $badguy['playerstarthp'] = $session['user']['hitpoints'];
                        if (!isset($badguy['diddamage'])) {
                            $badguy['diddamage'] = 0;
                        }
                        $stack[$i] = $badguy;
                    }
                    if ($multi > 1) {
                        $output->output("`2You encounter a group of `^%i`2 %s`2.`n`n", $multi, $badguy['creaturename']);
                    }
                } else {
                    while ($badguy = Database::fetchAssoc($result)) {
                        //decode and test the AI script file in place if any
                        $aiscriptfile = "scripts/" . $badguy['creatureaiscript'] . ".php";
                        if (file_exists($aiscriptfile)) {
                            //file there, get content and put it into the ai script field.
                            $badguy['creatureaiscript'] = "require('" . $aiscriptfile . "');";
                        }
                        //AI setup
                                               $badguy = Outcomes::buffBadguy($badguy);
                        // Okay, they are thrillseeking, let's give them a bit extra
                        // exp and gold.
                        if ($type == "thrill") {
                            // 10% more experience
                            $badguy['creatureexp'] = round($badguy['creatureexp'] * 1.1, 0);
                            // 10% more gold
                            $badguy['creaturegold'] = round($badguy['creaturegold'] * 1.1, 0);
                        }
                        if ($type == "suicide") {
                            // Okay, suicide fights give even more rewards, but
                            // are much harder
                            // 25% more experience
                            $badguy['creatureexp'] = round($badguy['creatureexp'] * 1.25, 0);
                            // 25% more gold
                            $badguy['creaturegold'] = round($badguy['creaturegold'] * 1.25, 0);
                            // Now, make it tougher.
                            $mul = 1.25 + $extrabuff;
                            $badguy['creatureattack'] = round($badguy['creatureattack'] * $mul, 0);
                            $badguy['creaturedefense'] = round($badguy['creaturedefense'] * $mul, 0);
                            $badguy['creaturehealth'] = round($badguy['creaturehealth'] * $mul, 0);
                            // And mark it as an 'elite' troop.
                            $prefixs = array("Elite","Dangerous","Lethal","Savage","Deadly","Malevolent","Malignant");
                            $prefixs = Translator::translateInline($prefixs);
                            $key = array_rand($prefixs);
                            $prefix = $prefixs[$key];
                            $badguy['creaturename'] = $prefix . " " . $badguy['creaturename'];
                        }
                        $badguy['playerstarthp'] = $session['user']['hitpoints'];
                        if (!isset($badguy['diddamage'])) {
                            $badguy['diddamage'] = 0;
                        }
                        $stack[] = $badguy;
                    }
                }
            }
            Buffs::calculateBufffields();
            $attackstack = array(
                "enemies" => $stack,
                "options" => array(
                    "type" => "forest"
                )
            );
            $attackstack = HookHandler::hook("forestfight-start", $attackstack);
            $session['user']['badguy'] = CreateString::run($attackstack);
            // If someone for any reason wanted to add a nav where the user cannot choose the number of rounds anymore
            // because they are already set in the nav itself, we need this here.
            // It will not break anything else. I hope.
            if (Http::get('auto') != "") {
                Http::set('op', 'fight');
                $op = 'fight';
            }
        }
    }
}

if ($op == "fight" || $op == "run" || $op == "newtarget") {
    $battle = true;
}

if ($battle) {
        require_once __DIR__ . "/battle.php";

    if ($victory) {
            $op = "";
            Http::set('op', "");
            Outcomes::victory($newenemies, isset($options['denyflawless']) ? $options['denyflawless'] : false);
            $dontdisplayforestmessage = true;
    } elseif ($defeat) {
            Outcomes::defeat($newenemies);
    } else {
        Battle::fightnav();
    }
}

if ($op == "") {
    // Need to pass the variable here so that we show the forest message
    // sometimes, but not others.
    HookHandler::hook("forest_enter", array());
        Forest::forest($dontdisplayforestmessage);
}
Footer::pageFooter();
