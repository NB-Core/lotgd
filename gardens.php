<?php

use Lotgd\Commentary;
use Lotgd\DateTime;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Events;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("gardens");

$output    = Output::getInstance();
$settings  = Settings::getInstance();

Header::pageHeader("The Gardens");

Commentary::addCommentary();
$skipgardendesc = Events::handleEvent("gardens");
$op = Http::get('op');
$com = Http::get('comscroll');
$refresh = Http::get("refresh");
$commenting = Http::get("commenting");
$comment = Http::post('insertcommentary');
// Don't give people a chance at a special event if they are just browsing
// the commentary (or talking) or dealing with any of the hooks in the village.
if (!$op && $com == "" && !$comment && !$refresh && !$commenting) {
    if (HookHandler::moduleEvents("gardens", $settings->getSetting("gardenchance", 0)) != 0) {
        if (Nav::checkNavs()) {
            Footer::pageFooter();
        } else {
            // Reset the special for good.
            $session['user']['specialinc'] = "";
            $session['user']['specialmisc'] = "";
            $skipgardendesc = true;
            $op = "";
            Http::set("op", "");
        }
    }
}
if (!$skipgardendesc) {
    DateTime::checkDay();

    $output->output("`b`c`2The Gardens`0`c`b");

    $output->output("`n`nYou walk through a gate and on to one of the many winding paths that makes its way through the well-tended gardens.");
    $output->output("From the flowerbeds that bloom even in darkest winter, to the hedges whose shadows promise forbidden secrets, these gardens provide a refuge for those seeking out the Green Dragon; a place where they can forget their troubles for a while and just relax.`n`n");
    $output->output("One of the fairies buzzing about the garden flies up to remind you that the garden is a place for roleplaying and peaceful conversation, and to confine out-of-character comments to the other areas of the game.`n`n");
}

VillageNav::render();
HookHandler::hook("gardens", []);

Commentary::commentDisplay("", "gardens", "Whisper here", 30, "whispers");

HookHandler::displayEvents("gardens", "gardens.php");
Footer::pageFooter();
