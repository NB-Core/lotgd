<?php

declare(strict_types=1);

use Lotgd\Translator;

/**
 * \file battle.php
 * This file holds the generic battle code that gets normally require()'d and executes basic fight functions.
 * @see lib/buffs.php
 * @see lib/battle-buffs.php
 * @see lib/battle-skills.php
 */

use Lotgd\Buffs;
use Lotgd\Battle;
use Lotgd\Substitute;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";


//just in case we're called from within a function.Yuck is this ugly.
global $badguy,$enemies,$newenemies,$session,$creatureattack,$creatureatkmod, $beta;
global $creaturedefmod,$adjustment,$defmod,$atkmod,$compdefmod,$compatkmod,$buffset,$atk,$def,$options;
global $companions,$companion,$newcompanions,$count,$defended,$needtostopfighting,$roll;

Translator::getInstance()->setSchema("battle");

$newcompanions = array();
$attackstack = @unserialize($session['user']['badguy']);
if (isset($attackstack['enemies'])) {
    $enemies = $attackstack['enemies'];
}
if (isset($attackstack['options'])) {
    $options = $attackstack['options'];
}

// Make the new battle script compatible with old, single enemy fights.
if (isset($attackstack['creaturename']) && $attackstack['creaturename'] > "") {
    $safe = $attackstack;
    $enemies = array();
    $enemies[0] = $safe;
    unset($safe);
} elseif (isset($attackstack[0]['creaturename']) && $attackstack['creaturename'] > "") {
    $enemies = $attackstack;
}
if (!isset($options)) {
    if (isset($enemies[0]['type'])) {
        $options['type'] = $enemies[0]['type'];
    }
}

$options = Battle::prepareFight($options);

$roundcounter = 0;
$adjustment = 1;

$count = 1;
$auto = Http::get('auto');
if ($auto == 'full') {
    $count = -1;
} elseif ($auto == 'five') {
    $count = 5;
} elseif ($auto == 'ten') {
    $count = 10;
}
$enemycounter = count($enemies);
$enemies = Battle::autoSetTarget($enemies);

$op = Http::get("op");
$skill = Http::get("skill");
$l = Http::get("l");
$newtarget = Http::get('newtarget');
if ($newtarget != "") {
    $op = "newtarget";
}
if ($op == "fight") {
    Battle::applySkill($skill, $l);
} elseif ($op == "newtarget") {
    foreach ($enemies as $index => $badguy) {
        if ($index == (int)$newtarget) {
            if (!isset($badguy['cannotbetarget']) || $badguy['cannotbetarget'] === false) {
                $enemies[$index]['istarget'] = 1;
            } else {
                if (is_array($badguy['cannotbetarget'])) {
                    $msg = Translator::getInstance()->sprintfTranslate($badguy['cannotbetarget']);
                    $msg = Substitute::apply($msg);
                    output_notl($msg); //Here it's already translated
                } else {
                    if ($badguy['cannotbetarget'] === true) {
                        $msg = "{badguy} cannot be selected as target.";
                    } else {
                        $msg = $badguy['cannotbetarget'];
                    }
                    $msg = Substitute::applyArray("`5" . $msg . "`0`n");
                    output($msg);
                }
            }
        } else {
            $enemies[$index]['istarget'] = 0;
        }
    }
}

$victory = false;
$defeat = false;

foreach ($enemies as $index => $enemy) {
    //sue me
    if (!isset($enemies[$index]['creaturemaxhealth'])) {
        $enemies[$index]['creaturemaxhealth'] = $enemies[$index]['creaturehealth']; //set in the first round - we do better later in OOP :(
    }
}

if ($enemycounter > 0) {
    output("`\$`c`b~ ~ ~ Fight ~ ~ ~`b`c`0");
    HookHandler::hook("battle", $enemies);
    foreach ($enemies as $index => $badguy) {
        if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0) {
            output("`@You have encountered `^%s`@ which lunges at you with `%%s`@!`0`n", $badguy['creaturename'], $badguy['creatureweapon']);
        }
    }
    output_notl("`n");
        Battle::showEnemies($enemies);
}

if (!isset($options['type'])) {
    // no type set, do it for them, to pve
    $options['type'] = 'forest';
}
Battle::suspendBuffs((($options['type'] == 'pvp') ? "allowinpvp" : false));
Battle::suspendCompanions((($options['type'] == 'pvp') ? "allowinpvp" : false));

// Now that the bufflist is sane, see if we should add in the bodyguard.
$inn = (int)Http::get('inn');
if ($options['type'] == 'pvp' && $inn == 1) {
    Battle::applyBodyguard($badguy['bodyguardlevel']);
}

$surprised = false;
if ($op != "run" && $op != "fight" && $op != "newtarget") {
    if (count($enemies) > 1) {
        $surprised = true;
        output("`b`^YOUR ENEMIES`\$ surprise you and get the first round of attack!`0`b`n`n");
    } else {
        // Let's try this instead.Biggest change is that it adds possibility of
        // being surprised to all fights.
        if (!array_key_exists('didsurprise', $options) || !$options['didsurprise']) {
            // By default, surprise is 50/50
            $surprised = e_rand(0, 1) ? true : false;
            // Now, adjust for slum/thrill
            $type = Http::get('type');
            if ($type == 'slum' || $type == 'thrill') {
                $num = e_rand(0, 2);
                $surprised = true;
                if ($type == 'slum' && $num != 2) {
                    $surprised = false;
                }
                if (($type == 'thrill' || $type == 'suicide') && $num == 2) {
                    $surprised = false;
                }
            }
            if (!$surprised) {
                output("`b`\$Your skill allows you to get the first attack!`0`b`n`n");
            } else {
                if ($options['type'] == 'pvp') {
                    output("`b`^%s`\$'s skill allows them to get the first round of attack!`0`b`n`n", $badguy['creaturename']);
                } else {
                    output("`b`^%s`\$ surprises you and gets the first round of attack!`0`b`n`n", $badguy['creaturename']);
                }
                $op = "run";
            }
            $options['didsurprise'] = 1;
        }
    }
}
$needtostopfighting = false;
if ($op != "newtarget") {
    // Run through as many rounds as needed.
    do {
        //we need to restore and calculate here to reflect changes that happen throughout the course of multiple rounds.
        restore_buff_fields();
        calculate_buff_fields();
                Battle::prepareCompanions();
        //$newenemies = array();
        // Run the beginning of round buffs (this also calculates all modifiers)
        foreach ($enemies as $index => $badguy) {
            if ($badguy['dead'] == false && $badguy['creaturehealth'] > 0) {
                if (isset($badguy['alwaysattacks']) && $badguy['alwaysattacks'] == true) {
                } else {
                    $roundcounter++;
                }
                if (($roundcounter > $options['maxattacks']) && $badguy['istarget'] == false) {
                    $newcompanions = $companions;
                } else {
                    $buffset = Buffs::activateBuffs("roundstart");
                    if ($badguy['creaturehealth'] <= 0 || $session['user']['hitpoints'] <= 0) {
                        $creaturedmg = 0;
                        $selfdmg = 0;
                        if ($badguy['creaturehealth'] <= 0) {
                            $badguy['dead'] = true;
                            $badguy['istarget'] = false;
                            $count = 1;
                            $needtostopfighting = true;
                        }
                        if ($session['user']['hitpoints'] <= 0) {
                            $count = 1;
                            $needtostopfighting = true;
                        }
                        $newenemies[$index] = $badguy;
                        $newcompanions = $companions;
                        // No break here. It would break the foreach statement.
                    } else {
                        $creaturedefmod = $buffset['badguydefmod'];
                        $creatureatkmod = $buffset['badguyatkmod'];
                        $atkmod = $buffset['atkmod'];
                        $defmod = $buffset['defmod'];
                        $compatkmod = $buffset['compatkmod'];
                        $compdefmod = $buffset['compdefmod'];
                        if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && $badguy['istarget']) {
                            if (is_array($companions)) {
                                $newcompanions = array();
                                foreach ($companions as $name => $companion) {
                                    if (isset($companion['hitpoints']) && $companion['hitpoints'] > 0) {
                                                                       $buffer = Battle::reportCompanionMove($badguy, $companion, "heal");
                                        if ($buffer !== false) {
                                            $newcompanions[$name] = $buffer;
                                            unset($buffer);
                                        } else {
                                            unset($companion);
                                            unset($newcompanions[$name]);
                                        }
                                    } else {
                                        $newcompanions[$name] = $companion;
                                    }
                                }
                            }
                        } else {
                            $newcompanions = $companions;
                        }
                        $companions = $newcompanions;

                        if ($op == "fight" || $op == "run" || $surprised) {
                            // Grab an initial roll.
                                                    $roll = Battle::rollDamage($badguy);
                            if ($op == "fight" && !$surprised) {
                                $ggchancetodouble = $session['user']['dragonkills'];
                                $bgchancetodouble = $session['user']['dragonkills'];

                                if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0) {
                                                                   $buffset = Buffs::activateBuffs("offense");
                                    if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && $badguy['istarget']) {
                                        if (is_array($companions)) {
                                            $newcompanions = array();
                                            foreach ($companions as $name => $companion) {
                                                if (isset($companion['hitpoits']) && $companion['hitpoints'] > 0) {
                                                                       $buffer = Battle::reportCompanionMove($badguy, $companion, "magic");
                                                    if ($buffer !== false) {
                                                        $newcompanions[$name] = $buffer;
                                                        unset($buffer);
                                                    } else {
                                                        unset($companion);
                                                        unset($newcompanions[$name]);
                                                    }
                                                } else {
                                                    $newcompanions[$name] = $companion;
                                                }
                                            }
                                        }
                                    } else {
                                        $newcompanions = $companions;
                                    }
                                    $companions = $newcompanions;
                                    if ($badguy['creaturehealth'] <= 0 || $session['user']['hitpoints'] <= 0) {
                                        $creaturedmg = 0;
                                        $selfdmg = 0;
                                        if ($badguy['creaturehealth'] <= 0) {
                                            $badguy['dead'] = true;
                                            $badguy['istarget'] = false;
                                            $count = 1;
                                            $needtostopfighting = true;
                                        }
                                        $newenemies[$index] = $badguy;
                                        $newcompanions = $companions;
                                        // No break here. It would break the foreach statement.
                                    } elseif ($badguy['istarget'] == true) {
                                        do {
                                            if ($badguy['creaturehealth'] <= 0 || $session['user']['hitpoints'] <= 0) {
                                                $creaturedmg = 0;
                                                $selfdmg = 0;
                                                $newenemies[$index] = $badguy;
                                                $newcompanions = $companions;
                                                $needtostopfighting = true;
                                            } else {
                                                $needtostopfighting = battle_player_attacks($badguy);
                                            }
                                            $r = mt_rand(0, 100);
                                            if ($r < $ggchancetodouble && $badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && !$needtostopfighting) {
                                                $additionalattack = true;
                                                $ggchancetodouble -= ($r + 5);
                                                                           $roll = Battle::rollDamage($badguy);
                                            } else {
                                                $additionalattack = false;
                                            }
                                        } while ($additionalattack && !$needtostopfighting);
                                        if ($needtostopfighting) {
                                            $newcompanions = $companions;
                                        }
                                    } else {
                                    }
                                }
                            } elseif ($op == "run" && !$surprised) {
                                output("`4You are too busy trying to run away like a cowardly dog to try to fight `^%s`4.`n", $badguy['creaturename']);
                            }

                            //Need to insert this here because of auto-fighting!
                            if ($op != "newtarget") {
                                $op = "fight";
                            }

                            // We need to check both user health and creature health. Otherwise
                            // the user can win a battle by a RIPOSTE after he has gone <= 0 HP.
                            //-- Gunnar Kreitz
                            if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && $roundcounter <= $options['maxattacks']) {
                                                           $buffset = Buffs::activateBuffs("defense");
                                do {
                                    $defended = false;
                                    $needtostopfighting = battle_badguy_attacks($badguy);
                                    $r = mt_rand(0, 100);
                                    if (!isset($bgchancetodouble)) {
                                        $bgchancetodouble = 0;
                                    }
                                    if ($r < $bgchancetodouble && $badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && !$needtostopfighting) {
                                        $additionalattack = true;
                                        $bgchancetodouble -= ($r + 5);
                                                                           $roll = Battle::rollDamage($badguy);
                                    } else {
                                        $additionalattack = false;
                                    }
                                } while ($additionalattack && !$defended);
                            }
                            $companions = $newcompanions;
                            if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && $badguy['istarget']) {
                                if (is_array($companions)) {
                                    foreach ($companions as $name => $companion) {
                                        if (isset($companion['hitpoints']) && $companion['hitpoints'] > 0) {
                                                                       $buffer = Battle::reportCompanionMove($badguy, $companion, "fight");
                                            if ($buffer !== false) {
                                                $newcompanions[$name] = $buffer;
                                                unset($buffer);
                                            } else {
                                                unset($companion);
                                                unset($newcompanions[$name]);
                                            }
                                        } else {
                                            $newcompanions[$name] = $companion;
                                        }
                                    }
                                }
                            } else {
                                $newcompanions = $companions;
                            }
                        } else {
                            $newcompanions = $companions;
                        }
                        if ($badguy['dead'] == false && isset($badguy['creatureaiscript']) && $badguy['creatureaiscript'] > "") {
                            global $unsetme,$session;
                            $lasthp = $session['user']['hitpoints'];
                            Battle::executeAiScript($badguy['creatureaiscript']);
                            if ($session['user']['hitpoints'] < $lasthp) {
                                $badguy['diddamage'] = 1;
                            }
                        }
                    }
                }
            } else {
                $newcompanions = $companions;
            }
            // Copy the companions back so in the next round (multiple rounds) they can be used again.
            // We will also delete the now old set of companions. Just in case.
            $companions = $newcompanions;
            unset($newcompanions);

            // If any A.I. script wants the current enemy to be deleted completely, we will obey.
            // For multiple rounds/multiple A.I. scripts we will although unset this order.

            if (isset($unsetme) && $unsetme === true) {
                $unsetme = false;
                unset($unsetme);
            } else {
                $newenemies[$index] = $badguy;
            }
        }
        Buffs::expireBuffs();
        $creaturedmg = 0;
        $selfdmg = 0;

        if (($count != 1 || ($needtostopfighting && $count > 1)) && $session['user']['hitpoints'] > 0 && count($enemies) > 0) {
            output("`2`bNext round:`b`n");
        }

        if (count($newenemies) > 0) {
            $alive = 0;
            $fleeable = 0;
            $leaderisdead = false;
            foreach ($newenemies as $index => $badguy) {
                if ((isset($badguy['dead']) && $badguy['dead'] == true) || $badguy['creaturehealth'] <= 0) {
                    if (isset($badguy['essentialleader']) && $badguy['essentialleader'] == true) {
                        $defeat = false;
                        $victory = true;
                        $needtostopfighting = true;
                        $leaderisdead = true;
                    }
                    $badguy['istarget'] = false;
                    // We'll either add the experience right away or store it in a seperate array.
                    // If through any script enemies are added during the fight, the amount of
                    // experience would stay the same
                    // We'll also check if the user is actually alive. If we didn't, we would hand out
                    // experience for graveyard fights.
                    if (!isset($badguy['creatureexp'])) {
                        $badguy['creatureexp'] = 0; //if this was not set, make it
                    }
                    if (getsetting("instantexp", false) == true && $session['user']['alive'] && $options['type'] != "pvp" && $options['type'] != "train") {
                        if (!isset($badguy['expgained']) || $badguy['expgained'] == false) {
                            $cr_xp_gain = round($badguy['creatureexp'] / count($newenemies));
                            $args = HookHandler::hook("forest-victory-xp", $args = array('experience' => $cr_xp_gain));
                            $cr_xp_gain = $args['experience'];
                            $session['user']['experience'] += $cr_xp_gain;
                            if (isset($badguy['creatureexp'])) {
                                output("`#You receive `^%s`# experience!`n`0", $cr_xp_gain);
                            }
                            $options['experience'][$index] = $badguy['creatureexp'];
                            $options['experiencegained'][$index] = $cr_xp_gain;
                            $badguy['expgained'] = true;
                        }
                    } else {
                        $options['experience'][$index] = $badguy['creatureexp'];
                        $options['experiencegained'][$index] = $badguy['creatureexp'];
                    }
                } else {
                    $alive++;
                    if (isset($badguy['fleesifalone']) && $badguy['fleesifalone'] == true) {
                        $fleeable++;
                    }
                    if ($session['user']['hitpoints'] <= 0) {
                        $defeat = true;
                        $victory = false;
                        break;
                    } elseif (!$leaderisdead) {
                        $defeat = false;
                        $victory = false;
                    }
                }
                $verynewenemies[$index] = $badguy;
            }
            $enemiesflown = false;
            if ($alive == $fleeable && $session['user']['hitpoints'] > 0) {
                $defeat = false;
                $victory = true;
                $enemiesflown = true;
                $needtostopfighting = true;
            }
            if (getsetting("instantexp", false) == true && isset($verynewenemies)) {
                $newenemies = $verynewenemies;
            }
        }
        if ($alive == 0) {
            $defeat = false;
            $victory = true;
            $needtostopfighting = true;
        }
        if ($count != -1) {
            $count--;
        }
        if ($needtostopfighting) {
            $count = 0;
        }
        if ($enemiesflown) {
            foreach ($newenemies as $index => $badguy) {
                if (isset($badguy['fleesifalone']) && $badguy['fleesifalone'] == true) {
                    if (is_array($badguy['fleesifalone'])) {
                        $msg = Translator::getInstance()->sprintfTranslate($badguy['fleesifalone']);
                        $msg = Substitute::apply($msg);
                        output_notl($msg); //Here it's already translated
                    } else {
                        if ($badguy['fleesifalone'] === true) {
                            $msg = "{badguy} flees in panic.";
                        } else {
                            $msg = $badguy['fleesifalone'];
                        }
                        $msg = Substitute::applyArray("`5" . $msg . "`0`n");
                        output($msg);
                    }
                } else {
                    $newenemies[$index] = $badguy;
                }
            }
        } elseif ($leaderisdead) {
            if (isset($badguy['essentialleader']) && is_array($badguy['essentialleader'])) {
                $msg = Translator::getInstance()->sprintfTranslate($badguy['essentialleader']);
                $msg = Substitute::apply($msg);
                output_notl($msg); //Here it's already translated
            } elseif (isset($badguy['essentialleader'])) {
                if ($badguy['essentialleader'] === true) {
                    $msg = "All other other enemies flee in panic as `^{badguy}`5 falls to the ground.";
                } else {
                    $msg = $badguy['essentialleader'];
                }
                $msg = Substitute::applyArray("`5" . $msg . "`0`n");
                output($msg);
            }
        }
        if (is_array($newenemies)) {
            $enemies = $newenemies;
        }
        $roundcounter = 0;
    } while ($count > 0 || $count == -1);
    $newenemies = $enemies;
} else {
    $newenemies = $enemies;
}

$newenemies = Battle::autoSetTarget($newenemies);

if ($session['user']['hitpoints'] > 0 && count($newenemies) > 0 && ($op == "fight" || $op == "run")) {
    output("`2`bEnd of Round:`b`n");
        Battle::showEnemies($newenemies);
}

if ($session['user']['hitpoints'] <= 0) {
    $session['user']['hitpoints'] = 0;
    $victory = false;
    $defeat = true;
    //you can whatever you want with this after your include();
    //dead user is dead user. will not talk much.
}


if ($victory || $defeat) {
    // expire any buffs which cannot persist across fights
    Buffs::expireBuffsAfterbattle();
    //unsuspend any suspended buffs
    Battle::unsuspendBuffs((($options['type'] == 'pvp') ? "allowinpvp" : false));


    if ($session['user']['alive']) {
            Battle::unsuspendCompanions((($options['type'] == 'pvp') ? "allowinpvp" : false));
    }
    foreach ($companions as $index => $companion) {
        if (isset($companion['expireafterfight']) && $companion['expireafterfight']) {
            if (isset($companion['dyingtext'])) {
                output($companion['dyingtext']);
            }
            unset($companions[$index]);
        }
    }
    if (is_array($newenemies)) {
        foreach ($newenemies as $index => $badguy) {
            //global $output;
//          $badguy['fightoutput'] = $output;
            // legacy support. Will be removed in one of the following versions!
            // Please update all modules, that use the following hook to use the
            // $options array instead of the $args array for their code.
            $badguy['type'] = $options['type'];

            if ($victory) {
                $badguy = HookHandler::hook("battle-victory", $badguy);
            }
            if ($defeat) {
                $badguy = HookHandler::hook("battle-defeat", $badguy);
            }
//          unset($badguy['fightoutput']);
        }
    }
}
$attackstack = array('enemies' => $newenemies, 'options' => $options);
$session['user']['badguy'] = createstring($attackstack);
$session['user']['companions'] = createstring($companions);
Translator::getInstance()->setSchema();

function battle_player_attacks(&$badguy)
{
    global $enemies,$newenemies,$session,$creatureattack,$creatureatkmod, $beta;
    global $creaturedefmod,$adjustment,$defmod,$atkmod,$compatkmod,$compdefmod,$buffset,$atk,$def,$options;
    global $companions,$companion,$newcompanions,$roll,$count,$needtostopfighting;

    $break = false;
    $creaturedmg = $roll['creaturedmg'];
    if ($options['type'] != "pvp") {
            $creaturedmg = Battle::reportPowerMove((int)$atk, (int)$creaturedmg);
    }
    if ($creaturedmg == 0) {
        output("`4You try to hit `^%s`4 but `\$MISS!`n", $badguy['creaturename']);
        Buffs::processDmgshield($buffset['dmgshield'], 0);
        Buffs::processLIfetaps($buffset['lifetap'], 0);
    } elseif ($creaturedmg < 0) {
        output("`4You try to hit `^%s`4 but are `\$RIPOSTED `4for `\$%s`4 points of damage!`n", $badguy['creaturename'], (0 - $creaturedmg));
        $badguy['diddamage'] = 1;
        $session['user']['hitpoints'] += $creaturedmg;
        if ($session['user']['hitpoints'] <= 0) {
            $badguy['killedplayer'] = true;
            $count = 1;
            $break = true;
            $needtostopfighting = true;
        }
        Buffs::processDmgshield($buffset['dmgshield'], -$creaturedmg);
        Buffs::processLIfetaps($buffset['lifetap'], $creaturedmg);
    } else {
        output("`4You hit `^%s`4 for `^%s`4 points of damage!`n", $badguy['creaturename'], $creaturedmg);
        $badguy['creaturehealth'] -= $creaturedmg;
        Buffs::processDmgshield($buffset['dmgshield'], -$creaturedmg);
        Buffs::processLIfetaps($buffset['lifetap'], $creaturedmg);
    }
    if ($badguy['creaturehealth'] <= 0) {
        $badguy['dead'] = true;
        $badguy['istarget'] = false;
        $count = 1;
        $break = true;
    }
    return $break;
}

function battle_badguy_attacks(&$badguy)
{
    global $enemies,$newenemies,$session,$creatureattack,$creatureatkmod, $beta;
    global $creaturedefmod,$adjustment,$defmod,$atkmod,$compatkmod,$compdefmod,$buffset,$atk,$def,$options;
    global $companions,$companion,$newcompanions,$roll,$count,$index,$defended,$needtostopfighting;

    $break = false;
    $selfdmg = $roll['selfdmg'];
    if ($badguy['creaturehealth'] <= 0 && $session['user']['hitpoints'] <= 0) {
        $creaturedmg = 0;
        $selfdmg = 0;
        if ($badguy['creaturehealth'] <= 0) {
            $badguy['dead'] = true;
            $badguy['istarget'] = false;
            $count = 1;
            $needtostopfighting = true;
            $break = true;
        }
        $newenemies[$index] = $badguy;
        $newcompanions = $companions;
        $break = true;
    } else {
        if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0 && $badguy['istarget']) {
            if (is_array($companions)) {
                foreach ($companions as $name => $companion) {
                    if (isset($companion['hitpoints']) && $companion['hitpoints'] > 0) {
                                        $buffer = Battle::reportCompanionMove($badguy, $companion, "defend");
                        if ($buffer !== false) {
                            $newcompanions[$name] = $buffer;
                            unset($buffer);
                        } else {
                            unset($companion);
                            unset($newcompanions[$name]);
                        }
                    } else {
                        $newcompanions[$name] = $companion;
                    }
                }
            }
        } else {
            $newcompanions = $companions;
        }
        $companions = $newcompanions;
        if ($defended == false) {
            if ($selfdmg == 0) {
                output("`^%s`4 tries to hit you but `^MISSES!`n", $badguy['creaturename']);
                Buffs::processDmgshield($buffset['dmgshield'], 0);
                Buffs::processLifetaps($buffset['lifetap'], 0);
            } elseif ($selfdmg < 0) {
                output("`^%s`4 tries to hit you but you `^RIPOSTE`4 for `^%s`4 points of damage!`n", $badguy['creaturename'], (0 - $selfdmg));
                $badguy['creaturehealth'] += $selfdmg;
                Buffs::processLifetaps($buffset['lifetap'], -$selfdmg);
                Buffs::processDmgshield($buffset['dmgshield'], $selfdmg);
            } else {
                output("`^%s`4 hits you for `\$%s`4 points of damage!`n", $badguy['creaturename'], $selfdmg);
                $session['user']['hitpoints'] -= $selfdmg;
                if ($session['user']['hitpoints'] <= 0) {
                    $badguy['killedplayer'] = true;
                    $count = 1;
                }
                Buffs::processDmgshield($buffset['dmgshield'], $selfdmg);
                Buffs::processLifetaps($buffset['lifetap'], -$selfdmg);
                $badguy['diddamage'] = 1;
            }
        }
        if ($badguy['creaturehealth'] <= 0) {
            $badguy['dead'] = true;
            $badguy['istarget'] = false;
            $count = 1;
            $break = true;
        }
    }
    return $break;
}
