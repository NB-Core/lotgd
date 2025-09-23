<?php

use Lotgd\Commentary;
use Lotgd\DateTime;
use Lotgd\Translator;
use Lotgd\Names;
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


Translator::getInstance()->setSchema("lodge");

Commentary::addCommentary();

$op = Http::get('op');
if ($op == "") {
    DateTime::checkDay();
}

$pointsavailable =
    $session['user']['donation'] - $session['user']['donationspent'];
$entry = ($session['user']['donation'] > 0) || ($session['user']['superuser'] & SU_EDIT_COMMENTS);
if ($pointsavailable < 0) {
    $pointsavailable = 0; // something weird.
}

Header::pageHeader("Hunter's Lodge");
Nav::add("Navigation");
VillageNav::render();
Nav::add("General");

Nav::add("Referrals", "referral.php");
if ($op != "" && $entry) {
    Nav::add("L?Back to the Lodge", "lodge.php");
}
Nav::add("Describe Points", "lodge.php?op=points");


if ($op == "") {
    $output->output("`b`c`!The Hunter's Lodge`0`c`b");
    $output->output("`7You follow a narrow path away from the stables and come across a rustic Hunter's Lodge.");
    $output->output("A guard stops you at the door and asks to see your membership card.`n`n");

    if ($entry) {
        HookHandler::hook("lodge-desc");
        $output->output("Upon showing it to him, he says, `3\"Very good %s, welcome to the J. C. Petersen Hunting Lodge.", Translator::translate($session['user']['sex'] ? "ma'am" : "sir"));
        $output->output("You have earned `^%s`3 points and have `^%s`3 points available to spend,\"`7 and admits you in.`n`n", $session['user']['donation'], $pointsavailable);
        $output->output("You enter a room dominated by a large fireplace at the far end.");
        $output->output("The wood-panelled walls are covered with weapons, shields, and mounted hunting trophies, including the heads of several dragons that seem to move in the flickering light.`n`n");
        $output->output("Many high-backed leather chairs fill the room.");
        $output->output("In the chair closest to the fire sits J. C. Petersen, reading a heavy tome entitled \"Alchemy Today.\"`n`n");
        $output->output("As you approach, a large hunting dog at his feet raises her head and looks at you.");
        $output->output("Sensing that you belong, she lays down and goes back to sleep.`n`n");
            Commentary::commentDisplay("Nearby some other rugged hunters talk:`n", "hunterlodge", "Talk quietly", 25);
        Nav::add("Use Points");
        HookHandler::hook("lodge");
    } else {
        $iname = getsetting("innname", LOCATION_INN);
        $output->output("You pull out your Frequent Boozer Card from %s, with 9 out of the 10 slots punched out with a small profile of %s`7's Head.`n`n", $iname, getsetting('barkeep', '`tCedrik'));
        $output->output("The guard glances at it, advises you not to drink so much, and directs you down the path.");
    }
} elseif ($op == "points") {
    $output->output("`b`3Points:`b`n`n");
    $points_messages = HookHandler::hook(
        "donator_point_messages",
        array(
            'messages' => array(
                'default' => Translator::getInstance()->sprintfTranslate("`7For each %s 1 donated, the account which makes the donation will receive %s contributor points in the game (Fractions don't count).", getsetting('paypalcurrency', 'USD'), getsetting('dpointspercurrencyunit', 'USD'))
            )
        )
    );
    foreach ($points_messages['messages'] as $id => $message) {
        $output->outputNotl($message . "`n", true);
    }
    $output->output("`n`n\"`&But what are points,`7\" you ask?");
    $output->output("Points can be redeemed for various advantages in the game.");
    $output->output("You'll find access to these advantages in the Hunter's Lodge.");
    $output->output("As time goes on, more advantages will likely be added, which can be purchased when they are made available.`n`n");
    $output->output("Donating even one %s will gain you a membership card to the Hunter's Lodge, an area reserved exclusively for contributors.", getsetting('paypalcurrency', 'USD'));
    $output->output("Donations are accepted in whole %s increments only.`n`n", getsetting('paypalcurrency', 'USD'));
    $output->output("\"`&But I don't have access to a PayPal account, or I otherwise can't donate to your very wonderful project!`7\"`n");
           // yes, "referer" is misspelt here, but the game setting was also misspelt
    if (getsetting("refereraward", 25)) {
        $output->output("Well, there is another way that you can obtain points: by referring other people to our site!");
        $output->output("You'll get %s points for each person whom you've referred who makes it to level %s.", getsetting("refereraward", 25), getsetting("referminlevel", 4));
        $output->output("Even one person making it to level %s will gain you access to the Hunter's Lodge.`n`n", getsetting("referminlevel", 4));
    }
    $output->output("You can also gain contributor points for contributing in other ways that the administration may specify.");
    $output->output("So, don't despair if you cannot send cash, there will always be non-cash ways of gaining contributor points.`n`n");
    $output->output("`b`3Purchases that are currently available:`0`b`n");
    $args = HookHandler::hook("pointsdesc", array("format" => "`#&#149;`7 %s`n", "count" => 0));
    if ($args['count'] == 0) {
        $output->output("`#&#149;`7None -- Please talk to your admin about creating some.`n", true);
    }
}

Footer::pageFooter();
