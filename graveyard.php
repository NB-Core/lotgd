<?php

use Lotgd\Buffs;
use Lotgd\DateTime;
use Lotgd\DeathMessage;
use Lotgd\Battle;
use Lotgd\AddNews;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Events;

// addnews ready.
// translator ready
// mail ready
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("graveyard");

Header::pageHeader("The Graveyard");
$skipgraveyardtext = Events::handleEvent("graveyard");
$deathoverlord = getsetting('deathoverlord', '`$Ramius');
if (!$skipgraveyardtext) {
    if ($session['user']['alive']) {
        redirect("village.php");
    }

    DateTime::checkDay();
}
$battle = false;
Buffs::stripAllBuffs();
$max = $session['user']['level'] * 10 + $session['user']['dragonkills'] * 2 + 50;
$favortoheal = HookHandler::hook("favortoheal", array("favor" => round(10 * ($max - $session['user']['soulpoints']) / $max)));

$favortoheal = (int)$favortoheal['favor'];

$op = Http::get('op');
switch ($op) {
    case "search":
            require_once __DIR__ . "/pages/graveyard/case_battle_search.php";

        break;
    case "run":
        if (e_rand(0, 2) == 1) {
            $output->output("`\$%s`) curses you for your cowardice.`n`n", $deathoverlord);
            $favor = 5 + e_rand(0, $session['user']['level']);
            if ($favor > $session['user']['deathpower']) {
                $favor = $session['user']['deathpower'];
            }
            if ($favor > 0) {
                $output->output("`)You have `\$LOST `^%s`) favor with `\$%s`).", $favor, $deathoverlord);
                $session['user']['deathpower'] -= $favor;
            }
            Translator::getInstance()->setSchema("nav");
            Nav::add("G?Return to the Graveyard", "graveyard.php");
            Translator::getInstance()->setSchema();
        } else {
            $output->output("`)As you try to flee, you are summoned back to the fight!`n`n");
            $battle = true;
        }
        break;
    case "fight":
        $battle = true;
}

if ($battle) {
    //make some adjustments to the user to put them on mostly even ground
    //with the undead guy.
    $originalhitpoints = $session['user']['hitpoints'];
    $session['user']['hitpoints'] = $session['user']['soulpoints'];
    $originalattack = $session['user']['attack'];
    $originaldefense = $session['user']['defense'];
//  $session['user']['attack'] =
//      10 + round(($session['user']['level'] - 1) * 1.5);
//  $session['user']['defense'] =
//      10 + round(($session['user']['level'] - 1) * 1.5);

    require_once __DIR__ . "/battle.php";

    //reverse those adjustments, battle calculations are over.
    $session['user']['attack'] = $originalattack;
    $session['user']['defense'] = $originaldefense;
    $session['user']['soulpoints'] = $session['user']['hitpoints'];
    $session['user']['hitpoints'] = $originalhitpoints;
    if ($victory) {
        Translator::getInstance()->setSchema("battle");
        $msg = Translator::translate($badguy['creaturelose']);
        Translator::getInstance()->setSchema();
        $output->outputNotl("`b`&%s`0`b`n", $msg);
        $output->output("`b`\$You have tormented %s!`0`b`n", $badguy['creaturename']);
        $output->output("`#You receive `^%s`# favor with `\$%s`#!`n`0", $badguy['creatureexp'], $deathoverlord);
        $session['user']['deathpower'] += $badguy['creatureexp'];
        $op = "";
        Http::set('op', "");
        $skipgraveyardtext = true;
    } else {
        if ($defeat) {
                $taunt = Battle::selectTauntArray();
            $where = Translator::translate("in the graveyard");
            $deathmessage = DeathMessage::selectArray(false, array("{where}"), array($where));
            if ($deathmessage['taunt'] == 1) {
                AddNews::add("%s`n%s", $deathmessage['deathmessage'], $taunt);
            } else {
                AddNews::add("%s", $deathmessage['deathmessage']);
            }
//          AddNews::add("`)%s`) has been defeated in the graveyard by %s.`n%s",$session['user']['name'],$badguy['creaturename'],$taunt);
            $output->output("`b`&You have been defeated by `%%s`&!!!`n", $badguy['creaturename']);
            $output->output("You may not torment any more souls today.");
            $session['user']['gravefights'] = 0;
            Translator::getInstance()->setSchema("nav");
            Nav::add("G?Return to the Graveyard", "graveyard.php");
            Translator::getInstance()->setSchema();
        } else {
                Battle::fightnav(false, true, "graveyard.php");
        }
    }
}

HookHandler::hook("deathoverlord", array());

switch ($op) {
    case "search":
    case "run":
    case "fight":
        break;
    case "enter":
            require_once __DIR__ . "/pages/graveyard/case_enter.php";
        break;
    case "restore":
            require_once __DIR__ . "/pages/graveyard/case_restore.php";
        break;
    case "resurrection":
            require_once __DIR__ . "/pages/graveyard/case_resurrection.php";
        break;
    case "question":
            require_once __DIR__ . "/pages/graveyard/case_question.php";
        break;
    case "haunt":
            require_once __DIR__ . "/pages/graveyard/case_haunt.php";
        break;
    case "haunt2":
            require_once __DIR__ . "/pages/graveyard/case_haunt2.php";
        break;
    case "haunt3":
            require_once __DIR__ . "/pages/graveyard/case_haunt3.php";
        break;
    default:
            require_once __DIR__ . "/pages/graveyard/case_default.php";
        break;
}

Footer::pageFooter();
