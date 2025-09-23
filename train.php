<?php

use Lotgd\DateTime;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\AddNews;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Substitute;
use Lotgd\Battle;
use Lotgd\Mail;
use Lotgd\Output;
use Lotgd\DataCache;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;
use Lotgd\Specialty;
use Lotgd\PlayerFunctions;

//addnews ready
// mail ready
// translator ready
require_once __DIR__ . "/common.php";
$settings = Settings::getInstance();
$output = Output::getInstance();

Translator::getInstance()->setSchema("train");

Header::pageHeader("Bluspring's Warrior Training");

$battle = false;
$victory = false;
$defeat = false;
$point = $settings->getSetting('moneydecimalpoint', '.');
$sep = $settings->getSetting('moneythousandssep', ',');

$output->output("`b`cBluspring's Warrior Training`c`b");

$mid = Http::get("master");
if ($mid) {
    $sql = "SELECT * FROM " . Database::prefix("masters") . " WHERE creatureid=$mid";
} else {
    $sql = "SELECT max(creaturelevel) as level FROM " . Database::prefix("masters") . " WHERE creaturelevel <= " . $session['user']['level'];
    $res = Database::query($sql);
    $row = Database::fetchAssoc($res);
    $l = (int)$row['level'];

    $sql = "SELECT * FROM " . Database::prefix("masters") . " WHERE creaturelevel=$l ORDER BY RAND(" . e_rand() . ") LIMIT 1";
}

$result = Database::query($sql);
if (Database::numRows($result) > 0 && $session['user']['level'] < (int) $settings->getSetting('maxlevel', 15)) {
    $master = Database::fetchAssoc($result);
    $mid = $master['creatureid'];
    $master['creaturename'] = stripslashes($master['creaturename']);
    $master['creaturewin'] = stripslashes($master['creaturewin']);
    $master['creaturelose'] = stripslashes($master['creaturelose']);
    $master['creatureweapon'] = stripslashes($master['creatureweapon']);
    //this is a piece of old work I will leave in, if you don't have Gadriel, then well...
    if (
        $master['creaturename'] == "Gadriel the Elven Ranger" &&
        $session['user']['race'] == "Elf"
    ) {
        $master['creaturewin'] = "You call yourself an Elf?? Maybe Half-Elf! Come back when you've been better trained.";
        $master['creaturelose'] = "It is only fitting that another Elf should best me.  You make good progress.";
    }
    //end of old piece
    $level = $session['user']['level'];
    $dks = $session['user']['dragonkills'];
    $exprequired = PlayerFunctions::expForNextLevel($level, $dks);

    $op = Http::get('op');
    if ($op == "") {
        DateTime::checkDay();
        $output->output("The sound of conflict surrounds you.  The clang of weapons in grisly battle inspires your warrior heart. ");
        $output->output(
            "`n`n`^%s stands ready to evaluate you.`0",
            $master['creaturename']
        );
        Nav::add("Navigation");
        VillageNav::render();
        Nav::add("Actions");
        Nav::add("Question Master", "train.php?op=question&master=$mid");
        Nav::add("M?Challenge Master", "train.php?op=challenge&master=$mid");
        if ($session['user']['superuser'] & SU_DEVELOPER) {
            Nav::add("Superuser Gain level", "train.php?op=challenge&victory=1&master=$mid&sugain=1");
        }
    } elseif ($op == "challenge") {
        if (Http::get('victory')) {
            $victory = true;
            $defeat = false;
            if ($session['user']['experience'] < $exprequired) {
                $session['user']['experience'] = $exprequired;
            }
            $session['user']['seenmaster'] = 0;
        }
        if ($session['user']['seenmaster']) {
            $output->output("You think that, perhaps, you've seen enough of your master for today, the lessons you learned earlier prevent you from so willingly subjecting yourself to that sort of humiliation again.");
            Nav::add("Navigation");
            VillageNav::render();
            Nav::add("Actions");
        } else {
            /* OK, let's fix the multimaster thing */
            $session['user']['seenmaster'] = 1;
            debuglog("Challenged master, setting seenmaster to 1");

            if ($session['user']['experience'] >= $exprequired) {
                restore_buff_fields();
                $dk  = round(get_player_dragonkillmod(true) * 0.33, 0);

                $atkflux = e_rand(0, $dk);
                $atkflux = min($atkflux, round($dk * .25));
                $defflux = e_rand(0, ($dk - $atkflux));
                $defflux = min($defflux, round($dk * .25));

                $hpflux = ($dk - ($atkflux + $defflux)) * 5;
                $output->debug("DEBUG: $dk modification points total.`n");
                $output->debug("DEBUG: +$atkflux allocated to attack.`n");
                $output->debug("DEBUG: +$defflux allocated to defense.`n");
                $output->debug("DEBUG: +" . ($hpflux / 5) . "*5 to hitpoints`n");
                calculate_buff_fields();

                $master['creatureattack'] += $atkflux;
                $master['creaturedefense'] += $defflux;
                $master['creaturehealth'] += $hpflux;
                $attackstack['enemies'][0] = $master;
                $attackstack['options']['type'] = 'train';
                $session['user']['badguy'] = createstring($attackstack);

                $battle = true;
                if ($victory) {
                    $badguy = unserialize($session['user']['badguy']);
                    $output->output("With a flurry of blows you dispatch your master.`n");
                }
            } else {
                $output->output("You ready your %s`0 and %s`0 and approach `^%s`0.`n`n", $session['user']['weapon'], $session['user']['armor'], $master['creaturename']);
                $output->output("A small crowd of onlookers has gathered, and you briefly notice the smiles on their faces, but you feel confident. ");
                $output->output("You bow before `^%s`0, and execute a perfect spin-attack, only to realize that you are holding NOTHING!", $master['creaturename']);
                $output->output("`^%s`0 stands before you holding your weapon.", $master['creaturename']);
                $output->output("Meekly you retrieve your %s, and slink out of the training grounds to the sound of boisterous guffaws.", $session['user']['weapon']);
                Nav::add("Navigation");
                VillageNav::render();
                Nav::add("Actions");
            }
        }
    } elseif ($op == "question") {
        DateTime::checkDay();
        Nav::add("Navigation");
        VillageNav::render();
        Nav::add("Actions");
        $output->output("You approach `^%s`0 timidly and inquire as to your standing in the class.", $master['creaturename']);
        if ($session['user']['experience'] >= $exprequired) {
            $output->output("`n`n`^%s`0 says, \"Gee, your muscles are getting bigger than mine...\"", $master['creaturename']);
        } else {
            $output->output("`n`n`^%s`0 states that you will need `%%s`0 more experience before you are ready to challenge him in battle.", $master['creaturename'], number_format($exprequired - $session['user']['experience'], 0, $point, $sep));
        }
        Nav::add("Question Master", "train.php?op=question&master=$mid");
        Nav::add("M?Challenge Master", "train.php?op=challenge&master=$mid");
        if ($session['user']['superuser'] & SU_DEVELOPER) {
            Nav::add("Superuser Gain level", "train.php?op=challenge&victory=1&master=$mid&sugain=1");
        }
    } elseif ($op == "autochallenge") {
        Nav::add("Fight Your Master", "train.php?op=challenge&master=$mid");
        $output->output("`^%s`0 has heard of your prowess as a warrior, and heard of rumors that you think you are so much more powerful than he that you don't even need to fight him to prove anything. ", $master['creaturename']);
        $output->output("His ego is understandably bruised, and so he has come to find you.");
        $output->output("`^%s`0 demands an immediate battle from you, and your own pride prevents you from refusing the demand.", $master['creaturename']);
        if ($session['user']['hitpoints'] < $session['user']['maxhitpoints']) {
            $output->output("`n`nBeing a fair person, your master gives you a healing potion before the fight begins.");
            $session['user']['hitpoints'] = $session['user']['maxhitpoints'];
        }
        HookHandler::hook("master-autochallenge");
        if ((int) $settings->getSetting('displaymasternews', 1)) {
            AddNews::add("`3%s`3 was hunted down by their master, `^%s`3, for being truant.", $session['user']['name'], $master['creaturename']);
        }
    }
    if ($op == "fight") {
        $battle = true;
    }
    if ($op == "run") {
        $output->output("`\$Your pride prevents you from running from this conflict!`0");
        $op = "fight";
        $battle = true;
    }

    if ($battle) {
        Battle::suspendBuffs('allowintrain', "`&Your pride prevents you from using extra abilities during the fight!`0`n");
        Battle::suspendCompanions("allowintrain");
        if (!$victory) {
            require_once __DIR__ . "/battle.php";
        }
        if ($victory) {
            if (Http::get('sugain') == 1) {
                //Set badguy to defeat
                $badguy = $attackstack['enemies'][0];
            }
            if (!empty($badguy['creaturelose'])) {
                $badguy['creaturelose'] = $badguy['creaturelose'];
            } else {
                $badguy['creaturelose'] = "";
            }
            $badguy['creaturelose'] = Substitute::applyArray($badguy['creaturelose']);
            $output->outputNotl("`b`&");
            $output->output($badguy['creaturelose']);
            $output->outputNotl("`0`b`n");
            $output->output("`b`\$You have defeated %s!`0`b`n", $badguy['creaturename']);

            $session['user']['level']++;
            $session['user']['maxhitpoints'] += 10;
            $session['user']['soulpoints'] += 5;
            $session['user']['attack']++;
            $session['user']['defense']++;
            // Fix the multimaster bug
            if ((int) $settings->getSetting('multimaster', 1) === 1) {
                $session['user']['seenmaster'] = 0;
                debuglog("Defeated master, setting seenmaster to 0");
            }
            $output->output("`#You advance to level `^%s`#!`n", $session['user']['level']);
            $output->output("Your maximum hitpoints are now `^%s`#!`n", $session['user']['maxhitpoints']);
            $output->output("You gain an attack point!`n");
            $output->output("You gain a defense point!`n");
            if ($session['user']['level'] < 15) {
                $output->output("You have a new master.`n");
            } else {
                $output->output("None in the land are mightier than you!`n");
            }
            if ($session['user']['referer'] > 0 && ($session['user']['level'] >= (int) $settings->getSetting('referminlevel', 4) || $session['user']['dragonkills'] > 0) && $session['user']['refererawarded'] < 1) {
                $sql = "UPDATE " . Database::prefix("accounts") . " SET donation=donation+" . (int) $settings->getSetting('refereraward', 25) . " WHERE acctid={$session['user']['referer']}";
                Database::query($sql);
                $session['user']['refererawarded'] = 1;
                $subj = array("`%One of your referrals advanced!`0");
                $body = array("`&%s`# has advanced to level `^%s`#, and so you have earned `^%s`# points!", $session['user']['name'], $session['user']['level'], $settings->getSetting('refereraward', 25));
                Mail::systemMail($session['user']['referer'], $subj, $body);
            }
            Specialty::increment("`^");

            // Level-Up companions
            // We only get one level per pageload. So we just add the per-level-values.
            // No need to multiply and/or substract anything.
            if ((bool) $settings->getSetting('companionslevelup', 1)) {
                $newcompanions = $companions;
                foreach ($companions as $name => $companion) {
                    if (isset($companion['attack'])) {
                        $companion['attack'] = $companion['attack'] + (isset($companion['attackperlevel']) ? $companion['attackperlevel'] : 0);
                    }
                    if (isset($companion['defense'])) {
                        $companion['defense'] = $companion['defense'] + (isset($companion['defenseperlevel']) ? $companion['defenseperlevel'] : 0);
                    }
                    if (isset($companion['maxhitpoints'])) {
                        $companion['maxhitpoints'] = $companion['maxhitpoints'] + (isset($companion['maxhitpointsperlevel']) ? $companion['maxhitpointsperlevel'] : 0);
                    }
                    if (isset($companion['attack'])) {
                        $companion['hitpoints'] = $companion['maxhitpoints'];
                    }
                    $newcompanions[$name] = $companion;
                }
            }

            DataCache::getInstance()->invalidatedatacache("list.php-warsonline");

            Nav::add("Navigation");
            VillageNav::render();
            Nav::add("Actions");
            Nav::add("Question Master", "train.php?op=question");
            Nav::add("M?Challenge Master", "train.php?op=challenge");
            if ($session['user']['superuser'] & SU_DEVELOPER) {
                Nav::add("Superuser Gain level", "train.php?op=challenge&victory=1&master=" . ((string)((int)$mid + 1)) . "&sugain=1");
            }
            if ($session['user']['age'] == 1) {
                if ((int) $settings->getSetting('displaymasternews', 1)) {
                    AddNews::add("`%%s`3 has defeated " . ($session['user']['sex'] ? "her" : "his") . " master, `%%s`3 to advance to level `^%s`3 after `^1`3 day!!", $session['user']['name'], $badguy['creaturename'], $session['user']['level']);
                }
            } else {
                if ((int) $settings->getSetting('displaymasternews', 1)) {
                    AddNews::add("`%%s`3 has defeated " . ($session['user']['sex'] ? "her" : "his") . " master, `%%s`3 to advance to level `^%s`3 after `^%s`3 days!!", $session['user']['name'], $badguy['creaturename'], $session['user']['level'], $session['user']['age']);
                }
            }
            if ($session['user']['hitpoints'] < $session['user']['maxhitpoints']) {
                $session['user']['hitpoints'] = $session['user']['maxhitpoints'];
            }
            HookHandler::hook("training-victory", $badguy);
        } elseif ($defeat) {
            $taunt = Battle::selectTauntArray();

            if ((int) $settings->getSetting('displaymasternews', 1)) {
                AddNews::add("`%%s`5 has challenged their master, %s and lost!`n%s", $session['user']['name'], $badguy['creaturename'], $taunt);
            }
            $session['user']['hitpoints'] = $session['user']['maxhitpoints'];
            $output->output("`&`bYou have been defeated by `%%s`&!`b`n", $badguy['creaturename']);
            $output->output("`%%s`\$ halts just before delivering the final blow, and instead extends a hand to help you to your feet, and hands you a complementary healing potion.`n", $badguy['creaturename']);
            $badguy['creaturewin'] = Substitute::applyArray($badguy['creaturewin']);
            $output->outputNotl("`^`b");
            $output->output($badguy['creaturewin']);
            $output->outputNotl("`b`0`n");
            Nav::add("Navigation");
            VillageNav::render();
            Nav::add("Actions");
            Nav::add("Question Master", "train.php?op=question&master=$mid");
            Nav::add("M?Challenge Master", "train.php?op=challenge&master=$mid");
            if ($session['user']['superuser'] & SU_DEVELOPER) {
                Nav::add("Superuser Gain level", "train.php?op=challenge&victory=1&master=$mid");
            }
            HookHandler::hook("training-defeat", $badguy);
        } else {
            battle::fightnav(false, false, "train.php?master=$mid");
        }
        if ($victory || $defeat) {
            Battle::unsuspendBuffs('allowintrain', "`&You now feel free to make use of your buffs again!`0`n");
            Battle::unsuspendCompanions("allowintrain");
        }
    }
} else {
    DateTime::checkDay();
    $output->output("You stroll into the battle grounds.");
    $output->output("Younger warriors huddle together and point as you pass by.");
    $output->output("You know this place well.");
    $output->output("Bluspring hails you, and you grasp her hand firmly.");
    $output->output("There is nothing left for you here but memories.");
    $output->output("You remain a moment longer, and look at the warriors in training before you turn to return to the village.");
    Nav::add("Navigation");
    VillageNav::render();
    Nav::add("Actions");
}
Footer::pageFooter();
