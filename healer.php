<?php

use Lotgd\Translator;
use Lotgd\DateTime;
use Lotgd\Forest;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";


Translator::getInstance()->setSchema("healer");

$config = unserialize($session['user']['donationconfig']);

$return = Http::get("return");
$returnline = $return > "" ? "&return=$return" : "";

Header::pageHeader("Healer's Hut");
$output->output("`#`b`cHealer's Hut`c`b`n");

$cost = log($session['user']['level']) * (($session['user']['maxhitpoints'] - $session['user']['hitpoints']) + 10);
$result = HookHandler::hook("healmultiply", array("alterpct" => 1.0));
$cost *= $result['alterpct'];
$cost = round($cost, 0);

Translator::getInstance()->setSchema("nav");
Nav::add("`bNavigation`b");
Translator::getInstance()->setSchema();

$op = Http::get('op');
if ($op == "") {
    DateTime::checkDay();
    $output->output("`3You duck into the small smoke-filled grass hut.");
    $output->output("The pungent aroma makes you cough, attracting the attention of a grizzled old person that does a remarkable job of reminding you of a rock, which probably explains why you didn't notice them until now.");
    $output->output("Couldn't be your failure as a warrior.");
    $output->output("Nope, definitely not.`n`n");
    if ($session['user']['hitpoints'] < $session['user']['maxhitpoints']) {
        $output->output("\"`6See you, I do.  Before you did see me, I think, hmm?`3\" the old thing remarks.");
        $output->output("\"`6Know you, I do; healing you seek.  Willing to heal am I, but only if willing to pay are you.`3\"`n`n");
        $output->output("\"`5Uh, um.  How much?`3\" you ask, ready to be rid of the smelly old thing.`n`n");
        $output->output("The old being thumps your ribs with a gnarly staff.  \"`6For you... `$`b%s`b`6 gold pieces for a complete heal!!`3\" it says as it bends over and pulls a clay vial from behind a pile of skulls sitting in the corner.", $cost);
        $output->output("The view of the thing bending over to remove the vial almost does enough mental damage to require a larger potion.");
        $output->output("\"`6I also have some, erm... 'bargain' potions available,`3\" it says as it gestures at a pile of dusty, cracked vials.");
        $output->output("\"`6They'll heal a certain percent of your `idamage`i.`3\"");
    } elseif ($session['user']['hitpoints'] == $session['user']['maxhitpoints']) {
        $output->output("`3The old creature grunts as it looks your way. \"`6Need a potion, you do not.  Wonder why you bother me, I do.`3\" says the hideous thing.");
        $output->output("The aroma of its breath makes you wish you hadn't come in here in the first place.  You think you had best leave.");
    } else {
        $output->output("`3The old creature glances at you, then in a `^whirlwind of movement`3 that catches you completely off guard, brings its gnarled staff squarely in contact with the back of your head.");
        $output->output("You gasp as you collapse to the ground.`n`n");
        $output->output("Slowly you open your eyes and realize the beast is emptying the last drops of a clay vial down your throat.`n`n");
        $output->output("\"`6No charge for that potion.`3\" is all it has to say.");
        $output->output("You feel a strong urge to leave as quickly as you can.");
        $session['user']['hitpoints'] = $session['user']['maxhitpoints'];
    }
} elseif ($op == "buy") {
    $pct = Http::get('pct');
    $newcost = round($pct * $cost / 100, 0);
    if ($session['user']['gold'] >= $newcost) {
        $session['user']['gold'] -= $newcost;
        debuglog("spent gold on healing", false, false, "healing", $newcost);
        $diff = round(($session['user']['maxhitpoints'] - $session['user']['hitpoints']) * $pct / 100, 0);
        $session['user']['hitpoints'] += $diff;
        if ($newcost) {
            $output->output("`3With a grimace, you up-end the potion the creature hands you, and despite the foul flavor, you feel a warmth spreading through your veins as your muscles knit back together.");
            $output->output("Staggering some, you hand it your gold and are ready to be out of here.");
        } else {
            $output->output("`3With a grimace, you up-end the potion the creature hands you, and despite the foul flavor, you feel a warmth spreading through your veins.");
            $output->output("Staggering some you are ready to be out of here.");
        }
        $output->output("`n`n`#You have been healed for %s points!", $diff);
    } else {
        $output->output("`3The old creature pierces you with a gaze hard and cruel.");
        $output->output("Your lightning quick reflexes enable you to dodge the blow from its gnarled staff.");
        $output->output("Perhaps you should get some more money before you attempt to engage in local commerce.`n`n");
        $output->output("You recall that the creature had asked for `b`\$%s`3`b gold.", $newcost);
    }
} elseif ($op == "companion") {
    $compcost = Http::get('compcost');

    if ($session['user']['gold'] < $compcost) {
        $output->output("`3The old creature pierces you with a gaze hard and cruel.`n");
        $output->output("Your lightning quick reflexes enable you to dodge the blow from its gnarled staff.`n");
        $output->output("Perhaps you should get some more money before you attempt to engage in local commerce.`n`n");
        $output->output("You recall that the creature had asked for `b`\$%s`3`b gold.", $compcost);
    } else {
        $name = stripslashes(rawurldecode(Http::get('name')));
        $session['user']['gold'] -= $compcost;
        $companions[$name]['hitpoints'] = $companions[$name]['maxhitpoints'];
        $session['user']['companions'] = serialize($companions);
        $output->output("`3With a grimace, %s`3 up-ends the potion from the creature.`n", $companions[$name]['name']);
        $output->output("Muscles knit back together, cuts close and bruises fade. %s`3 is ready to battle once again!`n", $companions[$name]['name']);
        $output->output("You hand the creature your gold and are ready to be out of here.");
    }
}
$playerheal = false;
if ($session['user']['hitpoints'] < $session['user']['maxhitpoints']) {
    $playerheal = true;
    Nav::add("Potions");
    Nav::add("`^Complete Healing`0", "healer.php?op=buy&pct=100$returnline");
    //if cost is 0, usually on level 1 due to log algorithm, a free full healing is always preferred instead of a partial one
    if ($cost >= 0) {
        for ($i = 90; $i > 0; $i -= 10) {
            Nav::add(array("%s%% - %s gold", $i, round($cost * $i / 100, 0)), "healer.php?op=buy&pct=$i$returnline");
        }
    }
    HookHandler::hook('potion');
}
Nav::add("`bHeal Companions`b");
$compheal = false;
foreach ($companions as $name => $companion) {
    //inverse logic to if it set and value is true
    if (!isset($companion['cannotbehealed']) || $companion['cannotbehealed'] === false) {
        if (isset($companion['maxhitpoints']) && isset($companion['hitpoints'])) {
            $points = $companion['maxhitpoints'] - $companion['hitpoints'];
            if ($points > 0) {
                $compcost = round(log($session['user']['level'] + 1) * ($points + 10) * 1.33);
                Nav::add(array("%s`0 (`^%s Gold`0)", $companion['name'], $compcost), "healer.php?op=companion&name=" . rawurlencode($name) . "&compcost=$compcost$returnline");
                $compheal = true;
            }
        }
    }
}
//needs to be after the code
Translator::getInstance()->setSchema("nav");
Nav::add("`bNavigation`b");
if ($return == "") {
    if ($playerheal || $compheal) {
        Nav::add("F?Back to the Forest", "forest.php");
        VillageNav::render();
    } else {
                Forest::forest(true);
    }
} elseif ($return == "village.php") {
    VillageNav::render();
} else {
    Nav::add("R?Return whence you came", $return);
}
Translator::getInstance()->setSchema("");
$output->outputNotl("`0");
Footer::pageFooter();
