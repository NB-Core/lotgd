<?php

declare(strict_types=1);

// translator ready
// addnews ready
// mail ready
require_once("common.php");
use Lotgd\FightNav;
use Lotgd\Pvp;
use Lotgd\Battle;
use Lotgd\AddNews;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Http;

global $output;

tlschema("pvp");

$iname = getsetting("innname", LOCATION_INN);
$battle = false;

page_header("PvP Combat!");
$op = Http::get('op');
$act = Http::get('act');

if ($op == "" && $act != "attack") {
    checkday();
        Pvp::warn();
    $args = array(
        'atkmsg' => '`4You head out to the fields, where you know some unwitting warriors are sleeping.`n`nYou have `^%s`4 PvP fights left for today.`n`n',
        'schemas' => array('atkmsg' => 'pvp')
    );
    $args = modulehook("pvpstart", $args);
    tlschema($args['schemas']['atkmsg']);
    $output->output($args['atkmsg'], $session['user']['playerfights']);
    tlschema();
    Nav::add("L?Refresh List of Warriors", "pvp.php");
        Pvp::listTargets();
    VillageNav::render();
} elseif ($act == "attack") {
    $name = Http::get('name');
        $badguy = Pvp::setupTarget($name);
    $options['type'] = "pvp";
    $failedattack = false;
    if ($badguy === false) {
        $failedattack = true;
    } else {
        $battle = true;
        if ($badguy['location'] == $iname) {
            $badguy['bodyguardlevel'] = $badguy['boughtroomtoday'];
        }
        $attackstack['enemies'][0] = $badguy;
        $attackstack['options'] = $options;
        $session['user']['badguy'] = createstring($attackstack);
        //debug($session['user']['badguy']);
        $session['user']['playerfights']--;
    }

    if ($failedattack) {
        if (Http::get('inn') > "") {
            Nav::add("Return to Listing", "inn.php?op=bartender&act=listupstairs");
        } else {
            Nav::add("Return to Listing", "pvp.php");
        }
    }
}

if ($op == "run") {
    $output->output("Your pride prevents you from running");
    $op = "fight";
    Http::set('op', $op);
}

$skill = Http::get('skill');
if ($skill != "") {
    $output->output("Your honor prevents you from using any special ability");
    $skill = "";
    Http::set('skill', $skill);
}
if ($op == "fight" || $op == "run") {
    $battle = true;
}
if ($battle) {
    require_once("battle.php");
    if ($victory) {
        $killedin = $badguy['location'];
                $handled = Pvp::victory($badguy, $killedin, $options);

        // Handled will be true if a module has already done the addnews or
        // whatever was needed.
        if (!$handled) {
            if ($killedin == $iname) {
                AddNews::add("`4%s`3 defeated `4%s`3 by sneaking into their room in the inn!", $session['user']['name'], $badguy['creaturename']);
            } else {
                AddNews::add("`4%s`3 defeated `4%s`3 in fair combat in the fields of %s.", $session['user']['name'], $badguy['creaturename'], $killedin);
            }
        }

        $op = "";
        Http::set('op', $op);
        if ($killedin == $iname) {
            Nav::add("Return to the inn", "inn.php");
        } else {
            VillageNav::render();
        }
        if ($session['user']['hitpoints'] <= 0) {
            $output->output("`n`n`&Using a bit of cloth nearby, you manage to staunch your wounds so that you do not die as well.");
            $session['user']['hitpoints'] = 1;
        }
    } elseif ($defeat) {
        $killedin = $badguy['location'];
                $taunt = Battle::selectTauntArray();
        // This is okay because system mail which is all it's used for is
        // not translated
                $handled = Pvp::defeat($badguy, $killedin, $taunt, $options);
        // Handled will be true if a module has already done the addnews or
        // whatever was needed.
        if (!$handled) {
            if ($killedin == $iname) {
                AddNews::add("`%%s`5 has been slain while breaking into the inn room of `^%s`5 in order to attack them.`n%s`0", $session['user']['name'], $badguy['creaturename'], $taunt);
            } else {
                AddNews::add("`%%s`5 has been slain while attacking `^%s`5 in the fields of `&%s`5.`n%s`0", $session['user']['name'], $badguy['creaturename'], $killedin, $taunt);
            }
        }
    } else {
        $extra = "";
        if (Http::get('inn')) {
            $extra = "?inn=1";
        }
                FightNav::fightnav(false, false, "pvp.php$extra");
    }
}
page_footer();
