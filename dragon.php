<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Buffs;
use Lotgd\FightNav;
use Lotgd\PlayerFunctions;
use Lotgd\Http;
use Lotgd\Battle;
use Lotgd\Names;
use Lotgd\AddNews;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Modules\HookHandler;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";


Translator::getInstance()->setSchema("dragon");
$battle = false;
Header::pageHeader("The Green Dragon!");
$op = Http::get('op');
if ($op == "") {
    if (!Http::get('nointro')) {
        $output->output("`\$Fighting down every urge to flee, you cautiously enter the cave entrance, intent on catching the great green dragon sleeping, so that you might slay it with a minimum of pain.");
        $output->output("Sadly, this is not to be the case, for as you round a corner within the cave you discover the great beast sitting on its haunches on a huge pile of gold, picking its teeth with a rib.");
    }
    $badguy = array(
        "creaturename" => Translator::translate("`@The Green Dragon`0"),
        "creaturelevel" => getsetting('maxlevel', 15) + 2,
        "creatureweapon" => Translator::translate("Great Flaming Maw"),
                "creatureattack" => 30 + getsetting('maxlevel', 15),
                "creaturedefense" => 10 + getsetting('maxlevel', 15),
                "creaturehealth" => 150 + getsetting('maxlevel', 15) * 10,
        "diddamage" => 0,
        "type" => "dragon");

    //toughen up each consecutive dragon.
    // First, find out how each dragonpoint has been spent and count those
    // used on attack and defense.
    // Coded by JT, based on collaboration with MightyE
    restore_buff_fields();
    $points  = round(get_player_dragonkillmod(true) * 0.75, 0);

    $atkflux = e_rand(0, $points);
    $defflux = e_rand(0, $points - $atkflux);

    $hpflux = ($points - ($atkflux + $defflux)) * 5;
    debug("DEBUG: $points modification points total.`0`n");
    debug("DEBUG: +$atkflux allocated to attack.`n");
    debug("DEBUG: +$defflux allocated to defense.`n");
    debug("DEBUG: +" . ($hpflux / 5) . "*5 to hitpoints.`0`n");
    calculate_buff_fields();
    $badguy['creatureattack'] += $atkflux;
    $badguy['creaturedefense'] += $defflux;
    $badguy['creaturehealth'] += $hpflux;

    $badguy = HookHandler::hook("buffdragon", $badguy);

    $session['user']['badguy'] = createstring($badguy);
    $battle = true;
} elseif ($op == "prologue1") {
    $output->output("`@Victory!`n`n");
        $flawless = (int)(Http::get('flawless'));
    if ($flawless) {
        $output->output("`b`c`&~~ Flawless Fight ~~`0`c`b`n`n");
    }
    $output->output("`2Before you, the great dragon lies immobile, its heavy breathing like acid to your lungs.");
    $output->output("You are covered, head to toe, with the foul creature's thick black blood.");
    $output->output("The great beast begins to move its mouth.  You spring back, angry at yourself for having been fooled by its ploy of death, and watch for its huge tail to come sweeping your way.");
    $output->output("But it does not.");
    $output->output("Instead the dragon begins to speak.`n`n");
    $output->output("\"`^Why have you come here mortal?  What have I done to you?`2\" it says with obvious effort.");
    $output->output("\"`^Always my kind are sought out to be destroyed.  Why?  Because of stories from distant lands that tell of dragons preying on the weak?  I tell you that these stories come only from misunderstanding of us, and not because we devour your children.`2\"");
    $output->output("The beast pauses, breathing heavily before continuing, \"`^I will tell you a secret.  Behind me now are my eggs.  They will hatch, and the young will battle each other.  Only one will survive, but she will be the strongest.  She will quickly grow, and be as powerful as me.`2\"");
    $output->output("Breath comes shorter and shallower for the great beast.`n`n");
    $output->output("\"`#Why do you tell me this?  Don't you know that I will destroy your eggs?`2\" you ask.`n`n");
    $output->output("\"`^No, you will not, for I know of one more secret that you do not.`2\"`n`n");
    $output->output("\"`#Pray tell oh mighty beast!`2\"`n`n");
    $output->output("The great beast pauses, gathering the last of its energy.  \"`^Your kind cannot tolerate the blood of my kind.  Even if you survive, you will be a feeble creature, barely able to hold a weapon, your mind blank of all that you have learned.  No, you are no threat to my children, for you are already dead!`2\"`n`n");
    $output->output("Realizing that already the edges of your vision are a little dim, you flee from the cave, bound to reach the healer's hut before it is too late.");
    $output->output("Somewhere along the way you lose your weapon, and finally you trip on a stone in a shallow stream, sight now limited to only a small circle that seems to float around your head.");
    $output->output("As you lay, staring up through the trees, you think that nearby you can hear the sounds of the village.");
    $output->output("Your final thought is that although you defeated the dragon, you reflect on the irony that it defeated you.`n`n");
    $output->output("As your vision winks out, far away in the dragon's lair, an egg shuffles to its side, and a small crack appears in its thick leathery skin.");

    if ($flawless) {
        $output->output("`n`nYou fall forward, and remember at the last moment that you at least managed to grab some of the dragon's treasure, so maybe it wasn't all a total loss.");
    }
    Nav::add("It is a new day", "news.php");
    Buffs::stripAllBuffs();
    $sql = "DESCRIBE " . Database::prefix("accounts");
    $result = Database::query($sql);

    $dkpoints = 0;
    foreach ($session['user']['dragonpoints'] as $val) {
        if ($val == "hp") {
            $dkpoints += 5;
        }
    }

    restore_buff_fields();
    $hpgain = array(
            'total' => $session['user']['maxhitpoints'],
            'dkpoints' => $dkpoints,
            'extra' => $session['user']['maxhitpoints'] - $dkpoints -
                    ($session['user']['level'] * 10),
            'base' => $dkpoints + ($session['user']['level'] * 10),
            );
    $hpgain = HookHandler::hook("hprecalc", $hpgain);
    calculate_buff_fields();

    $nochange = array("acctid" => 1
                   ,"name" => 1
                   ,"sex" => 1
                   ,"playername" => 1
                   ,"strength" => 1
                   ,"dexterity" => 1
                   ,"intelligence" => 1
                   ,"constitution" => 1
                   ,"wisdom" => 1
                   ,"password" => 1
                   ,"marriedto" => 1
                   ,"title" => 1
                   ,"login" => 1
                   ,"dragonkills" => 1
                   ,"locked" => 1
                   ,"loggedin" => 1
                   ,"superuser" => 1
                   ,"gems" => 1
                   ,"hashorse" => 1
                   ,"gentime" => 1
                   ,"gentimecount" => 1
                   ,"lastip" => 1
                   ,"uniqueid" => 1
                   ,"dragonpoints" => 1
                   ,"laston" => 1
                   ,"prefs" => 1
                   ,"lastmotd" => 1
                   ,"emailaddress" => 1
                   ,"emailvalidation" => 1
                   ,"gensize" => 1
                   ,"bestdragonage" => 1
                   ,"dragonage" => 1
                   ,"donation" => 1
                   ,"donationspent" => 1
                   ,"donationconfig" => 1
                   ,"bio" => 1
                   ,"charm" => 1
                   ,"banoverride" => 1
                   ,"referer" => 1
                   ,"refererawarded" => 1
                   ,"ctitle" => 1
                   ,"beta" => 1
                   ,"clanid" => 1
                   ,"clanrank" => 1
                   ,"clanjoindate" => 1
                   ,"translatorlanguages" => 1
                   ,"replaceemail" => 1
                   ,"forgottenpassword" => 1
                   );

    $nochange = HookHandler::hook("dk-preserve", $nochange);

    $badguys = $session['user']['badguy']; //needed for the dragons name later

    $session['user']['dragonage'] = $session['user']['age'];
    if (
        $session['user']['dragonage'] <  $session['user']['bestdragonage'] ||
            $session['user']['bestdragonage'] == 0
    ) {
        $session['user']['bestdragonage'] = $session['user']['dragonage'];
    }
    while ($row = Database::fetchAssoc($result)) {
        if (
            array_key_exists($row['Field'], $nochange) &&
                $nochange[$row['Field']]
        ) {
            continue;
        }

        $value = $row['Default'];
        $type = strtolower($row['Type']);
        $baseType = strtok($type, '(');

        if (strpos($baseType, 'int') !== false) {
            $value = (int) $value;
        } elseif (in_array($baseType, ['float', 'double', 'decimal'])) {
            $value = (float) $value;
        } elseif ($baseType === 'tinyint' && $type === 'tinyint(1)') {
            $value = (bool) $value;
        }

        $session['user'][$row['Field']] = $value;
    }
    $session['user']['gold'] = getsetting("newplayerstartgold", 50);
    $session['user']['location'] = getsetting('villagename', LOCATION_FIELDS);
    $session['user']['armor'] = getsetting('startarmor', 'T-Shirt');
    $session['user']['weapon'] = getsetting('startweapon', 'Fists');

        $newtitle = PlayerFunctions::getDkTitle($session['user']['dragonkills'], $session['user']['sex']);

    $restartgold = $session['user']['gold'] +
        getsetting("newplayerstartgold", 50) * $session['user']['dragonkills'];
    $restartgems = 0;
    if ($restartgold > getsetting("maxrestartgold", 300)) {
        $restartgold = getsetting("maxrestartgold", 300);
        $restartgems = max(0, ($session['user']['dragonkills'] -
                (getsetting("maxrestartgold", 300) /
                 getsetting("newplayerstartgold", 50)) - 1));
        if ($restartgems > getsetting("maxrestartgems", 10)) {
            $restartgems = getsetting("maxrestartgems", 10);
        }
    }
    $session['user']['gold'] = $restartgold;
    $session['user']['gems'] += $restartgems;

    if ($flawless) {
        $session['user']['gold'] += 3 * getsetting("newplayerstartgold", 50);
        $session['user']['gems'] += 1;
    }

    $session['user']['maxhitpoints'] = 10 + $hpgain['dkpoints'] +
        $hpgain['extra'];
    $session['user']['hitpoints'] = $session['user']['maxhitpoints'];

    // Sanity check
    if ($session['user']['maxhitpoints'] < 1) {
        // Yes, this is a freaking hack.
        die("ACK!! Somehow this user would end up perma-dead.. Not allowing DK to proceed!  Notify admin and figure out why this would happen so that it can be fixed before DK can continue. Most likely, you have less than Level*10 Hitpoints when you kill the dragon. Let this fix and tell the admin that such a case should not happen. If necessary, bite her/his toes until she/he complies.");
        exit();
    }

    // Set the new title.
    $newname = Names::changePlayerTitle($newtitle);
    $session['user']['title'] = $newtitle;
    $session['user']['name'] = $newname;

    foreach ($session['user']['dragonpoints'] as $val) {
        switch ($val) {
        //legacy support
            case "at":
                $session['user']['attack']++;
                break;
            case "de":
                $session['user']['defense']++;
                break;
        }
    }
    $session['user']['laston'] = date("Y-m-d H:i:s", strtotime("-1 day"));
    if (!getsetting('pvpdragonoptout', 0)) {
        $session['user']['slaydragon'] = 1;
    }
    $companions = array();
    $session['user']['companions'] = array();

    $output->output("`n`nYou wake up in the midst of some trees.  Nearby you hear the sounds of a village.");
    $output->output("Dimly you remember that you are a new warrior, and something of a dangerous Green Dragon that is plaguing the area.  You decide you would like to earn a name for yourself by perhaps some day confronting this vile creature.");

    // allow explanative text as well.
    HookHandler::hook("dragonkilltext");

    $regname = Names::getPlayerBasename();
    //get the dragons name
    $badguys = @unserialize($badguys);
    $badguy = array(
        "creaturename" => Translator::translate("`@The Green Dragon`0"),
        "diddamage" => 0,
    );
    foreach ($badguys['enemies'] as $opponent) {
        if ($opponent['type'] == 'dragon') {
            //hit
            $badguy = $opponent;
            break;
        }
    }



    $howoften = ($session['user']['dragonkills'] > 1 ? "times" : "time"); // no translation, we never know who is viewing...
    AddNews::add("`#%s`# has earned the title `&%s`# for having slain `@%s`& `^%s`# %s!", $regname, $session['user']['title'], $badguy['creaturename'], $session['user']['dragonkills'], $howoften);
    $output->output("`n`n`^You are now known as `&%s`^!!", $session['user']['name']);
    $output->output("`n`n`&Because you have slain %s`& %s %s, you start with some extras.  You also keep additional permanent hitpoints you've earned.`n", $badguy['creaturename'], $session['user']['dragonkills'], $howoften);
    $session['user']['charm'] += 5;
    $output->output("`^You gain FIVE charm points for having defeated the dragon!`n");
    debuglog("slew the dragon and starts with {$session['user']['gold']} gold and {$session['user']['gems']} gems");

    // Moved this hear to make some things easier.
    HookHandler::hook("dragonkill", array());
    invalidatedatacache("list.php-warsonline");
}

if ($op == "run") {
    $output->output("The creature's tail blocks the only exit to its lair!");
    $op = "fight";
        Http::set('op', 'fight');
}
if ($op == "fight" || $op == "run") {
    $battle = true;
}
if ($battle) {
    require_once __DIR__ . "/battle.php";

    if ($victory) {
        $flawless = 0;
        if (isset($badguy['diddamage']) && $badguy['diddamage'] != 1) {
            $flawless = 1;
        }
        $session['user']['dragonkills']++;
        $output->output("`&With a mighty final blow, `@%s`& lets out a tremendous bellow and falls at your feet, dead at last.", $badguy['creaturename']);
        AddNews::add("`&%s has slain the hideous creature known as `@%s`&.  All across the land, people rejoice!", $session['user']['name'], $badguy['creaturename']);
        Translator::getInstance()->setSchema("nav");
        Nav::add("Continue", "dragon.php?op=prologue1&flawless=$flawless");
        Translator::getInstance()->setSchema();
    } else {
        if ($defeat) {
            Translator::getInstance()->setSchema("nav");
            Nav::add("Daily news", "news.php");
            Translator::getInstance()->setSchema();
                        $taunt = Battle::selectTauntArray();
            if ($session['user']['sex']) {
                AddNews::add("`%%s`5 has been slain when she encountered `@%s`5!!!  Her bones now litter the cave entrance, just like the bones of those who came before.`n%s", $session['user']['name'], $badguy['creaturename'], $taunt);
            } else {
                AddNews::add("`%%s`5 has been slain when he encountered `@%s`5!!!  His bones now litter the cave entrance, just like the bones of those who came before.`n%s", $session['user']['name'], $badguy['creaturename'], $taunt);
            }
            $session['user']['alive'] = 0;
            debuglog("lost {$session['user']['gold']} gold when they were slain");
            $session['user']['gold'] = 0;
            $session['user']['hitpoints'] = 0;
            $output->output("`b`&You have been slain by `@%s`&!!!`n", $badguy['creaturename']);
            $output->output("`4All gold on hand has been lost!`n");
            //grant modules a chance to exclusively hook in here and do worse things to the user =)
            $output->outputNotl("`n");
            HookHandler::hook("dragondeath", array());
            $output->outputNotl("`n");
            $output->output("You may begin fighting again tomorrow.");

            Footer::pageFooter();
        } else {
                  Battle::fightnav(true, false);
        }
    }
}
Footer::pageFooter();
