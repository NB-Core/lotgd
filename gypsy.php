<?php

use Lotgd\Commentary;
use Lotgd\DateTime;
use Lotgd\Translator;
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

Translator::getInstance()->setSchema("gypsy");

Commentary::addCommentary();

$cost = $session['user']['level'] * 20;
$op = Http::get('op');
Nav::add("Navigation");
Nav::add("Forget it", "village.php");

if ($op == "pay") {
    if ($session['user']['gold'] >= $cost) { // Gunnar Kreitz
        $session['user']['gold'] -= $cost;
        debuglog("spent $cost gold to speak to the dead");
        redirect("gypsy.php?op=talk");
    } else {
        Header::pageHeader("Gypsy Seer's tent");
        VillageNav::render();
        $output->output("`5You offer the old gypsy woman your `^%s`5 gold for your gen-u-wine say-ance, however she informs you that the dead may be dead, but they ain't cheap.", $session['user']['gold']);
    }
} elseif ($op == "talk") {
    Header::pageHeader("In a deep trance, you talk with the shades");
    Commentary::commentDisplay("`5While in a deep trance, you are able to talk with the dead:`n", "shade", "Project", 25, "projects");
    Nav::add("Snap out of your trance", "gypsy.php");
} else {
    DateTime::checkDay();
    Header::pageHeader("Gypsy Seer's tent");
    $output->output("`5You duck into a gypsy tent like many you have seen throughout the realm.");
    $output->output("All of them promise to let you talk with the deceased, and most of them surprisingly seem to work.");
    $output->output("There are also rumors that the gypsy have the power to speak over distances other than just those of the afterlife.");
    $output->output("In typical gypsy style, the old woman sitting behind a somewhat smudgy crystal ball informs you that the dead only speak with the paying.");
    $output->output("\"`!For you, %s, the price is a trifling `^%s`! gold.`5\", she rasps.", Translator::translate($session['user']['sex'] ? "my pretty" : "my handsome"), $cost);
    Nav::add("Seance");
    Nav::add(array("Pay to talk to the dead (%s gold)", $cost), "gypsy.php?op=pay");
    if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
        Nav::add("Superuser Entry", "gypsy.php?op=talk");
    }
    HookHandler::hook("gypsy");
}
Footer::pageFooter();
