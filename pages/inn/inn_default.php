<?php

declare(strict_types=1);

use Lotgd\Http;
use Lotgd\Nav;

if ($com == "" && !$comment && $op != "fleedragon") {
    if (module_events("inn", (int)getsetting("innchance", 0)) != 0) {
        if (checknavs()) {
            page_footer();
        } else {
            $skipinndesc = true;
            $session['user']['specialinc'] = "";
            $session['user']['specialmisc'] = "";
            $op = "";
            Http::set("op", "");
        }
    }
}

Nav::add("Things to do");
$args = modulehook("blockcommentarea", array("section" => "inn"));
if (!isset($args['block']) || $args['block'] != 'yes') {
    Nav::add("Converse with patrons", "inn.php?op=converse");
}
Nav::add(array("B?Talk to %s`0 the Barkeep",$barkeep), "inn.php?op=bartender");

Nav::add("Other");
Nav::add("Get a room (log out)", "inn.php?op=room");


if (!$skipinndesc) {
    if ($op == "strolldown") {
        $output->output("You stroll down the stairs of the inn, once again ready for adventure!`n");
    } elseif ($op == "fleedragon") {
        $output->output("You pelt into the inn as if the Devil himself is at your heels.  Slowly you catch your breath and look around.`n");
        $output->output("%s`0 catches your eye and then looks away in disgust at your cowardice!`n`n", $partner);
        $output->output("You `\$lose`0 a charm point.`n`n");
        if ($session['user']['charm'] > 0) {
            $session['user']['charm']--;
        }
    } else {
        $output->output("You duck into a dim tavern that you know well.");
        $output->output("The pungent aroma of pipe tobacco fills the air.`n");
    }

    $output->output("You wave to several patrons that you know.");
    if ($session['user']['sex']) {
        $output->output("You give a special wave and wink to %s`0 who is tuning his harp by the fire.", $partner);
    } else {
        $output->output("You give a special wave and wink to %s`0 who is serving drinks to some locals.", $partner);
    }
    $output->output("%s`0 the innkeep stands behind his counter, chatting with someone.", $barkeep);

    $chats = array(
        translate_inline("dragons"),
        translate_inline(getsetting("bard", "`^Seth")),
        translate_inline(getsetting("barmaid", "`%Violet")),
        translate_inline("`#MightyE"),
        translate_inline("fine drinks"),
        $partner,
    );
    $chats = modulehook("innchatter", $chats);
    $talk = $chats[e_rand(0, count($chats) - 1)];
    $output->output("You can't quite make out what he is saying, but it's something about %s`0.`n`n", $talk);
    $output->output("The clock on the mantle reads `6%s`0.`n", getgametime());
    modulehook("inn-desc", array());
}
modulehook("inn", array());
module_display_events("inn", "inn.php");
