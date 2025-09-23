<?php

use Lotgd\DateTime;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Buffs;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$translator = Translator::getInstance();

$translator->setSchema("mercenarycamp");

DateTime::checkDay();
$name = stripslashes(rawurldecode(Http::get('name')));
if (isset($companions[$name])) {
    $displayname = $companions[$name]['name'];
} else {
    $displayname = Translator::translate("your companion");
}

$basetext = array(
    "title"         =>  "A Mercenary Camp",
    "desc"          =>  array(
        "`n`QYou step out of the gates of the village and stand for a moment to take a look around.",
        "A slight breeze in the air stirs the pennants mounted above your head before it touches your skin.",
        "Sounds of dogs barking draw your attention to the makeshift camp which is set slightly apart from the village.",
        "You walk towards the encampment trying to avoid muddy puddles left from the rainfall the prior night.",
        "The odor of cooking fires permeates the air.`n`n",

        "As you approach you notice two men seated on rough hewn logs in front of a tent.",
        "Propped against one of the logs are a pair of long handled battle axes and a bastard sword.",
        "One of the men turns his weatherbeaten face towards you.",
        "You try to suppress a shudder as you recoil from the sight of his face.",
        "A ragged scar marks his face from forehead to jaw, crossing an empty hole where his eye should be.",
        "He spits into the campfire before him.`n`n",

        "\"`4Are you looking for someone?`Q\", he asks in a gravelly voice that comes from deep within.`n`n",

        "At that moment a slender elfin woman with her golden hair pulled back in a warrior's braid brushes past you.",
        "Strapped across her back is a long bow and a leather quiver full of arrows fletched with turkey feathers.",
        "She gives you a smirk as she passes." .

        "You turn as the elfin archer continues on her way.",
        "That is when you notice a large mangy dog in a tug-of-war with a troll.",
        "Clenched in the dog's teeth is a very large bone with bits of flesh still clinging to it.",
        "You can't tell if the troll is growling louder than the dog as it tries to wrest the bone from its jaw.",
        "Hanging from the troll's wide belt is a gnarled club tht resets against filthy breeches of animal skins.",

        "The sound of the man's voice brings your attention back to the matter at hand.`n`n",
        "\"`PYes. As a matter of fact I am looking for someone,`Q\"  you reply.",
        "\"`PI have gold in my purse to pay for the best fighter willing to join me in ridding this realm of vermin.`Q\"`n`n",
        "You look around to see if somebody is willing to join you.`n`n"
    ),
    "buynav"        => "Hire a mercenary",
    "healnav"       => "Heal a companion",
    "healtext"        => array(
        "`QA surgeon takes a careful look at the many wounds of your companion.",
        "After murmuring to himself as he makes the evaluation, he turns to you to name the price to care for the wounds.",
    ),
    "healnotenough" => array(
        "`QThe surgeon shakes his head then shrugs before turning away.",
        "You are left standing with your empty purse.",
        "No healing for someone who cannot pay.",
    ),
    "healpaid" => array(
        array("`QA surgeon is caring for the wounds of %s`Q and bandages them with learned skill.", $displayname),
        "You gladly hand him the money owed for healing your companion and start heading back to the village.",
    ),
    "toomanycompanions" => array(
        "It seems no one his willing to follow you.",
        "You simply lead too many companions at the moment."
    )
);

$schemas = array(
    "title" => "mercenarycamp",
    "desc" => "mercenarycamp",
    "buynav" => "mercenarycamp",
    "healnav" => "mercenarycamp",
    "healtext" => "mercenarycamp",
    "healnotenough" => "mercenarycamp",
    "healpaid" => "mercenarycamp",
    "toomanycompanions" => "mercenarycamp"
);

$basetext['schemas'] = $schemas;
$texts = HookHandler::hook("mercenarycamptext", $basetext);
$schemas = $texts['schemas'];

$translator->setSchema($schemas['title']);
Header::pageHeader($texts['title']);
$output->output("`c`b`&" . $texts['title'] . "`0`b`c");
$translator->setSchema();

$op = Http::get("op");

if ($op == "") {
    if (Http::get('skip') != 1) {
        $translator->setSchema($schemas['desc']);
        if (is_array($texts['desc'])) {
            foreach ($texts['desc'] as $description) {
                $output->outputNotl($translator->sprintfTranslate($description));
            }
        } else {
            $output->output($texts['desc']);
        }
        $translator->setSchema();
    }

    $sql = "SELECT * FROM " .  Database::prefix("companions") . "
				WHERE companioncostdks<={$session['user']['dragonkills']}
				AND (companionlocation = '{$session['user']['location']}' OR companionlocation = 'all')
				AND companionactive = 1";
    $result = Database::query($sql);
    $translator->setSchema($schemas['buynav']);
    Nav::add($texts['buynav']);
    $translator->setSchema();
    while ($row = Database::fetchAssoc($result)) {
        $row = HookHandler::hook("alter-companion", $row);
        if ($row['companioncostgold'] && $row['companioncostgems']) {
            if ($session['user']['gold'] >= $row['companioncostgold'] && $session['user']['gems'] >= $row['companioncostgems'] && !isset($companions[$row['name']])) {
                Nav::add(array("%s`n`^%s Gold, `%%%s Gems`0",$row['name'], $row['companioncostgold'], $row['companioncostgems']), "mercenarycamp.php?op=buy&id={$row['companionid']}");
            } else {
                Nav::add(array("%s`n`^%s Gold, `%%%s Gems`0",$row['name'], $row['companioncostgold'], $row['companioncostgems']), "");
            }
        } elseif ($row['companioncostgold']) {
            if ($session['user']['gold'] >= $row['companioncostgold'] && !isset($companions[$row['name']])) {
                Nav::add(array("%s`n`^%s Gold`0",$row['name'], $row['companioncostgold']), "mercenarycamp.php?op=buy&id={$row['companionid']}");
            } else {
                Nav::add(array("%s`n`^%s Gold`0",$row['name'], $row['companioncostgold']), "");
            }
        } elseif ($row['companioncostgems']) {
            if ($session['user']['gems'] >= $row['companioncostgems'] && !isset($companions[$row['name']])) {
                Nav::add(array("%s`n`%%%s Gems`0",$row['name'], $row['companioncostgems']), "mercenarycamp.php?op=buy&id={$row['companionid']}");
            } else {
                Nav::add(array("%s`n`%%%s Gems`0",$row['name'], $row['companioncostgems']), "");
            }
        } elseif (!isset($companions[$row['name']])) {
            Nav::add(array("%s",$row['name']), "mercenarycamp.php?op=buy&id={$row['companionid']}");
        }
        $output->output("`#%s`n`7%s`n`n", $row['name'], $row['description']);
    }
    healnav($companions, $texts, $schemas);
} elseif ($op == "heal") {
    $cost = Http::get('cost');
    if ($cost == 'notenough') {
        $translator->setSchema($schemas['healpaid']);
        if (is_array($texts['healnotenough'])) {
            foreach ($texts['healnotenough'] as $healnotenough) {
                $output->outputNotl($translator->sprintfTranslate($healnotenough));
            }
        } else {
            $output->output($texts['healnotenough']);
        }
        $translator->setSchema();
    } else {
        $companions[$name]['hitpoints'] = $companions[$name]['maxhitpoints'];
        $session['user']['companions'] = serialize($companions);
        $session['user']['gold'] -= $cost;
        debuglog("spent $cost gold on healing a companion", false, false, "healcompanion", $cost);
        $translator->setSchema($schemas['healpaid']);
        if (is_array($texts['healpaid'])) {
            foreach ($texts['healpaid'] as $healpaid) {
                $output->outputNotl($translator->sprintfTranslate($healpaid));
            }
        } else {
            $output->output($texts['healpaid']);
        }
        $translator->setSchema();
    }
    healnav($companions, $texts, $schemas);
    Nav::add("Navigation");
    Nav::add("Return to the camp", "mercenarycamp.php?skip=1");
} elseif ($op == "buy") {
    $id = Http::get('id');
    $sql = "SELECT * FROM " . Database::prefix("companions") . " WHERE companionid = $id";
    $result = Database::query($sql);
    if ($row = Database::fetchAssoc($result)) {
        $row['attack'] = $row['attack'] + $row['attackperlevel'] * $session['user']['level'];
        $row['defense'] = $row['defense'] + $row['defenseperlevel'] * $session['user']['level'];
        $row['maxhitpoints'] = $row['maxhitpoints'] + $row['maxhitpointsperlevel'] * $session['user']['level'];
        $row['hitpoints'] = $row['maxhitpoints'];
        $row = HookHandler::hook("alter-companion", $row);
        $row['abilities'] = @unserialize($row['abilities']);
        if (Buffs::applyCompanion($row['name'], $row)) {
            $output->output("`QYou hand over `^%s gold`Q and `%%s %s`Q.`n`n", (int)$row['companioncostgold'], (int)$row['companioncostgems'], Translator::translate($row['companioncostgems'] == 1 ? "gem" : "gems"));
            if (isset($row['jointext']) && $row['jointext'] > "") {
                $output->output($row['jointext']);
            }
            $session['user']['gold'] -= $row['companioncostgold'];
            $session['user']['gems'] -= $row['companioncostgems'];
            debuglog("has spent {$row['companioncostgold']} gold and {$row['companioncostgems']} gems on hiring a mercenary ({$row['name']}).");
        } else {
            // applying the companion failed. Most likely they already have more than enough companions...
            $translator->setSchema($schemas['toomanycompanions']);
            if (is_array($texts['toomanycompanions'])) {
                foreach ($texts['toomanycompanions'] as $toomanycompanions) {
                    $output->outputNotl($translator->sprintfTranslate($toomanycompanions));
                }
            } else {
                $output->output($texts['toomanycompanions']);
            }
            $translator->setSchema();
        }
    }
    Nav::add("Navigation");
    Nav::add("Return to the camp", "mercenarycamp.php?skip=1");
}
Nav::add("Navigation");
VillageNav::render();
Footer::pageFooter();


function healnav($companions, $texts, $schemas)
{
    global $session, $translator;
    $translator->setSchema($schemas['healnav']);
    Nav::add($texts['healnav']);
    $translator->setSchema();
    $healable = false;
    foreach ($companions as $name => $companion) {
        if (isset($companion['cannotbehealed']) && $companion['cannotbehealed'] == true) {
        } else {
            $pointstoheal = $companion['maxhitpoints'] - $companion['hitpoints'];
            if ($pointstoheal > 0) {
                $healable = true;
                $costtoheal = round(log($session['user']['level'] + 1) * ($pointstoheal + 10) * 1.33);
                if ($session['user']['gold'] >= $costtoheal) {
                    Nav::add(array("%s`0 (`^%s Gold`0)", $companion['name'], $costtoheal), "mercenarycamp.php?op=heal&name=" . rawurlencode($name) . "&cost=$costtoheal");
                } else {
                    Nav::add(array("%s`0 (`\$Not enough gold`0)", $companion['name']), "mercenarycamp.php?op=heal&name=" . rawurlencode($name) . "&cost=notenough");
                }
            }
        }
    }
    if ($healable == true) {
        $translator->setSchema($schemas['healtext']);
        if (is_array($texts['healtext'])) {
            foreach ($texts['healtext'] as $healtext) {
                $output->outputNotl($translator->sprintfTranslate($healtext));
            }
        } else {
            $output->output($texts['healtext']);
        }
        $translator->setSchema();
    }
}
