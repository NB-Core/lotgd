<?php

use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\Buffs;
use Lotgd\MountName;
use Lotgd\Mounts;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Sanitize;
use Lotgd\DebugLog;
use Lotgd\DateTime;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$output = Output::getInstance();
$settings = Settings::getInstance();

$translator = Translator::getInstance();

$translator->setSchema('stables');

$basetext = array(
    "title" => "Merick's Stables",
    "desc" => array(
        "`7Behind the inn, and a little to the left of Ye Olde Bank, is as fine a stable as one might expect to find in any village. ",
        "In it, Merick, a burly looking dwarf tends to various beasts.`n`n",
        array("You approach, and he whirls around, pointing a pitchfork in your general direction, \"`&Ach, sorry m'%s, I dinnae hear ya' comin' up on me, an' I thoht fer sure ye were %s`&; he what been tryin' to improve on his dwarf tossin' skills. ", Translator::translateInline($session['user']['sex'] ? 'lass' : 'lad'), $settings->getSetting('barkeep', '`tCedrik')),
        "Naahw, wha' can oye do fer ya?`7\" he asks.",
    ),
    "nosuchbeast" => "`7\"`&Ach, thar dinnae be any such beestie here!`7\" shouts the dwarf!",
    "finebeast" => array(
        "`7\"`&Aye, tha' be a foyne beastie indeed!`7\" comments the dwarf.`n`n",
        "`7\"`&Ye cert'nly have an oye fer quality!`7\" exclaims the dwarf.`n`n",
        "`7\"`&Och, this beastie will serve ye well indeed,`7\" says the dwarf.`n`n",
        "`7\"`&That beastie be one o' me finest!`7\" says the dwarf with pride.`n`n",
        "`7\"`&Ye couldnae hae made a foyner choice o' beasts!`7\" says the dwarf with pride.`n`n"
    ),
    "toolittle" => "`7Merick looks at you sorta sideways.  \"`&'Ere, whadday ya think yeer doin'?  Cannae ye see that %s`& costs `^%s`& gold an' `%%s`& gems?`7\"",
    "replacemount" => "`7You hand over the reins to %s`7 and the purchase price of your new critter, and Merick leads out a fine new `&%s`7 for you!`n`n",
    "newmount" => "`7You hand over the purchase price of your new critter, and Merick leads out a fine `&%s`7 for you!`n`n",
    "nofeed" => "`7\"`&Ach, m'%s, what dae ye think this is, a hostelry?  I cannae feed yer critter here!`7\"`nMerick thumps you on the back good naturedly, and sends you on your way.",
    "nothungry" => "%s`7 isn't hungry.  Merick hands your gold back.",
    "halfhungry" => "%s`7 pinches a bit of the given food and leaves the rest alone. %s`7 is fully restored. Because there is still more than half of the food left, Merick gives you 50%% discount.`nYou only pay %s gold.",
    "hungry" => "%s`7 eats all the food greedily.`n%s`7 is fully restored and you give your %s gold to Merick.",
    "mountfull" => "`n`7\"`&Aye, there ye go %s, yer %s`& be full o' foyne grub. I willnae be able t' feed 'em again 'til the morrow though.  Well, enjoy ye day!`7\"`nMerick whistles a jaunty tune and heads back to work.",
    "nofeedgold" => "`7You don't have enough gold with you to pay for the food. Merick refuses to feed your creature and advises you to look for somewhere else to let %s`7 graze for free, such as in the `@Forest`7.",
    "confirmsale" => "`n`n`7Merick whistles.  \"`&Yer mount shure is a foyne one, %s. Are ye sure ye wish t' part wae it?`7\"`n`nHe waits for your answer.`0",
    "mountsold" => "`7As sad as it is to do so, you give up your precious %s`7, and a lone tear escapes your eye.`n`nHowever, the moment you spot the %s, you find that you're feeling quite a bit better.",
    "offer" => "`n`nMerick offers you `^%s`& gold and `%%s`& gems for %s`7.",
    "lad" => "lad",
    "lass" => "lass",
);
$schemas = array(
    'title' => 'stables',
    'desc' => 'stables',
    'nosuchbeast' => 'stables',
    'finebeast' => 'stables',
    'toolittle' => 'stables',
    'replacemount' => 'stables',
    'newmount' => 'stables',
    'nofeed' => 'stables',
    'nothungry' => 'stables',
    'halfhungry' => 'stables',
    'hungry' => 'stables',
    'mountfull' => 'stables',
    'nofeedgold' => 'stables',
    'confirmsale' => 'stables',
    'mountsold' => 'stables',
    'offer' => 'stables',
);
$basetext['schemas'] = $schemas;
$texts = HookHandler::hook("stabletext", $basetext);
$schemas = $texts['schemas'];

$translator->setSchema($schemas['title']);
Header::pageHeader($texts['title']);
$translator->setSchema();

Nav::add("Other");
VillageNav::render();
HookHandler::hook("stables-nav");

list($name, $lcname) = MountName::getmountname();

$mounts      = Mounts::getInstance();
$playerMount = $mounts->getPlayerMount();

$repaygold = 0;
$repaygems = 0;
$grubprice = 0;

if ($playerMount) {
    $repaygold = round($playerMount['mountcostgold'] * 2 / 3, 0);
    $repaygems = round($playerMount['mountcostgems'] * 2 / 3, 0);
    $grubprice = round($session['user']['level'] * $playerMount['mountfeedcost'], 0);
}
$confirm = 0;

$op = Http::get('op');
$id = Http::get('id');


if ($op == "") {
    DateTime::checkDay();
    $translator->setSchema($schemas['desc']);
    if (is_array($texts['desc'])) {
        foreach ($texts['desc'] as $description) {
            $output->outputNotl($translator->sprintfTranslate($description));
        }
    } else {
        $output->output($texts['desc']);
    }
    $translator->setSchema();
    HookHandler::hook("stables-desc");
} elseif ($op == "examine") {
    $sql = "SELECT * FROM " . Database::prefix("mounts") . " WHERE mountid='$id'";
    $result = Database::queryCached($sql, "mountdata-$id", 3600);
    if (Database::numRows($result) <= 0) {
        $translator->setSchema($schemas['nosuchbeast']);
        $output->output($texts['nosuchbeast']);
        $translator->setSchema();
    } else {
        // Idea taken from Robert of dragonprime.cawsquad.net
        $t = e_rand(0, count($texts['finebeast']) - 1);
        $translator->setSchema($schemas['finebeast']);
        $output->output($texts['finebeast'][$t]);
        $translator->setSchema();
        $mount = Database::fetchAssoc($result);
        $mount = HookHandler::hook("mount-modifycosts", $mount);
        $output->output("`7Creature: `&%s`0`n", $mount['mountname']);
        $output->output("`7Description: `&%s`0`n", $mount['mountdesc']);
        $output->output("`7Cost: `^%s`& gold, `%%s`& gems`n`n", $mount['mountcostgold'], $mount['mountcostgems']);
        Nav::add(array("New %s", $mount['mountname']));
        Nav::add("Buy this creature", "stables.php?op=buymount&id={$mount['mountid']}");
    }
} elseif ($op == 'buymount') {
    if ($session['user']['hashorse']) {
        $translator->setSchema($schemas['confirmsale']);
        $output->output(
            $texts['confirmsale'],
            Translator::translateInline($session['user']['sex'] ? $texts['lass'] : $texts['lad'])
        );
        $translator->setSchema();
        Nav::add("Confirm trade");
        Nav::add("Yes", "stables.php?op=confirmbuy&id=$id");
        Nav::add("No", "stables.php");
        $confirm = 1;
    } else {
        $op = "confirmbuy";
        Http::set("op", $op);
    }
}
if ($op == 'confirmbuy') {
    $sql = "SELECT * FROM " . Database::prefix("mounts") . " WHERE mountid='$id'";
    $result = Database::queryCached($sql, "mountdata-$id", 3600);
    if (Database::numRows($result) <= 0) {
        $translator->setSchema($schemas['nosuchbeast']);
        $output->output($texts['nosuchbeast']);
        $translator->setSchema();
    } else {
        $mount = Database::fetchAssoc($result);
        $mount = HookHandler::hook("mount-modifycosts", $mount);
        if (
            ($session['user']['gold'] + $repaygold) < $mount['mountcostgold'] ||
            ($session['user']['gems'] + $repaygems) < $mount['mountcostgems']
        ) {
            $translator->setSchema($schemas['toolittle']);
            $output->output($texts['toolittle'], $mount['mountname'], $mount['mountcostgold'], $mount['mountcostgems']);
            $translator->setSchema();
        } else {
            if ($session['user']['hashorse'] > 0) {
                $translator->setSchema($schemas['replacemount']);
                $output->output($texts['replacemount'], $lcname, $mount['mountname']);
                $translator->setSchema();
            } else {
                $translator->setSchema($schemas['newmount']);
                $output->output($texts['newmount'], $mount['mountname']);
                $translator->setSchema();
            }
            if (isset($playerMount['mountname'])) {
                $debugmount1 = $playerMount['mountname'];
                if ($debugmount1) {
                    $debugmount1 = "a " . $debugmount1;
                }
            } else {
                $debugmount1 = '';
            }
            $session['user']['hashorse'] = $mount['mountid'];
            $debugmount2 = $mount['mountname'];
            $goldcost = $repaygold - $mount['mountcostgold'];
            $session['user']['gold'] += $goldcost;
            $gemcost = $repaygems - $mount['mountcostgems'];
            $session['user']['gems'] += $gemcost;
            DebugLog::add(($goldcost <= 0 ? 'spent ' : 'gained ') . abs($goldcost) . ' gold and ' . ($gemcost <= 0 ? 'spent ' : 'gained ') . abs($gemcost) . " gems trading $debugmount1 for a new mount, a $debugmount2");
            $buff = unserialize($mount['mountbuff']);
            if ($buff['schema'] == "") {
                $buff['schema'] = "mounts";
            }
            Buffs::applyBuff('mount', $buff);
            // Recalculate so the selling stuff works right
            $playerMount = Mounts::getmount($mount['mountid']);
            $mounts->setPlayerMount($playerMount);
            $repaygold = round($playerMount['mountcostgold'] * 2 / 3, 0);
            $repaygems = round($playerMount['mountcostgems'] * 2 / 3, 0);
            // Recalculate the special name as well.
            HookHandler::hook("stable-mount", array());
            HookHandler::hook("boughtmount");
                        list($name, $lcname) = MountName::getmountname();
            $grubprice = round($session['user']['level'] * $playerMount['mountfeedcost'], 0);
        }
    }
} elseif ($op == 'feed') {
    if ($settings->getSetting('allowfeed', 0) == 0) {
        $translator->setSchema($schemas['nofeed']);
        $output->output(
            $texts['nofeed'],
            Translator::translateInline($session['user']['sex'] ? $texts['lass'] : $texts['lad'])
        );
        $translator->setSchema();
    } elseif ($session['user']['gold'] >= $grubprice) {
        $buff = unserialize($playerMount['mountbuff']);
        if ($buff['schema'] == "") {
            $buff['schema'] = "mounts";
        }
        if (isset($session['bufflist']['mount']['rounds']) && $session['bufflist']['mount']['rounds'] == $buff['rounds']) {
            $translator->setSchema($schemas['nothungry']);
            $output->output($texts['nothungry'], $name);
            $translator->setSchema();
        } else {
            if (isset($session['bufflist']['mount']['rounds']) && $session['bufflist']['mount']['rounds'] > $buff['rounds'] * .5) {
                $grubprice = round($grubprice / 2, 0);
                $translator->setSchema($schemas['halfhungry']);
                $output->output($texts['halfhungry'], $name, $name, $grubprice);
                $translator->setSchema();
                $session['user']['gold'] -= $grubprice;
            } else {
                $session['user']['gold'] -= $grubprice;
                $translator->setSchema($schemas['hungry']);
                $output->output($texts['hungry'], $name, $name, $grubprice);
                $translator->setSchema();
            }
            DebugLog::add("spent $grubprice feeding their mount");
            Buffs::applyBuff('mount', $buff);
            $session['user']['fedmount'] = 1;
            $translator->setSchema($schemas['mountfull']);
            $output->output(
                $texts['mountfull'],
                Translator::translateInline($session['user']['sex'] ? $texts['lass'] : $texts['lad']),
                (isset($playerMount['basename']) && $playerMount['basename'] ?
                 $playerMount['basename'] : $playerMount['mountname'])
            );
            $translator->setSchema();
        }
    } else {
        $translator->setSchema($schemas['nofeedgold']);
        $output->output($texts['nofeedgold'], $lcname);
        $translator->setSchema();
    }
} elseif ($op == 'sellmount') {
    $translator->setSchema($schemas['confirmsale']);
    $output->output(
        $texts['confirmsale'],
        Translator::translateInline($session['user']['sex'] ? $texts['lass'] : $texts['lad'])
    );
    $translator->setSchema();
    Nav::add("Confirm sale");
    Nav::add("Yes", "stables.php?op=confirmsell");
    Nav::add("No", "stables.php");
    $confirm = 1;
} elseif ($op == 'confirmsell') {
    $session['user']['gold'] += $repaygold;
    $session['user']['gems'] += $repaygems;
    $debugmount = $playerMount['mountname'];
    DebugLog::add("gained $repaygold gold and $repaygems gems selling their mount, a $debugmount");
    Buffs::stripBuff('mount');
    $session['user']['hashorse'] = 0;
    HookHandler::hook("soldmount");

    $amtstr = "";
    if ($repaygold > 0) {
        $amtstr .= "%s gold";
    }
    if ($repaygems > 0) {
        if ($repaygold) {
            $amtstr .= " and ";
        }
        $amtstr .= "%s gems";
    }
    if ($repaygold > 0 && $repaygems > 0) {
        $amtstr = $translator->sprintfTranslate($amtstr, $repaygold, $repaygems);
    } elseif ($repaygold > 0) {
        $amtstr = $translator->sprintfTranslate($amtstr, $repaygold);
    } else {
        $amtstr = $translator->sprintfTranslate($amtstr, $repaygems);
    }

    $translator->setSchema($schemas['mountsold']);
    $output->output(
        $texts['mountsold'],
        (isset($playerMount['newname']) ?
               $playerMount['newname'] : $playerMount['mountname']),
        $amtstr
    );
    $translator->setSchema();
}

if ($confirm == 0) {
    if ($session['user']['hashorse'] > 0) {
        Nav::add(array("%s", Sanitize::colorSanitize($name)));
        $translator->setSchema($schemas['offer']);
        $output->output($texts['offer'], $repaygold, $repaygems, $lcname);
        $translator->setSchema();
        Nav::add(array("Sell %s`0", $lcname), "stables.php?op=sellmount");
        if ($settings->getSetting('allowfeed', 0) && $session['user']['fedmount'] == 0) {
            Nav::add(
                array("Feed %s`0 (`^%s`0 gold)", $lcname, $grubprice),
                "stables.php?op=feed"
            );
        }
    }

    $sql = "SELECT mountname,mountid,mountcategory,mountdkcost FROM " . Database::prefix("mounts") .  " WHERE mountactive=1 AND mountlocation IN ('all','{$session['user']['location']}') ORDER BY mountcategory,mountcostgems,mountcostgold";
    $result = Database::query($sql);
    $category = "";
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        if ($category != $row['mountcategory']) {
            Nav::add(array("%s", $row['mountcategory']));
            $category = $row['mountcategory'];
        }
        if ($row['mountdkcost'] <= $session['user']['dragonkills']) {
            Nav::add(array("Examine %s`0", $row['mountname']), "stables.php?op=examine&id={$row['mountid']}");
        }
    }
}

Footer::pageFooter();
