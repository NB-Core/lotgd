<?php

declare(strict_types=1);

use Lotgd\Commentary;
use Lotgd\Buffs;
use Lotgd\DateTime;
use Lotgd\Nav\VillageNav;
use Lotgd\Sanitize;
use Lotgd\Http;
use Lotgd\Events;
use Lotgd\Translator;
use Lotgd\Pvp;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Partner;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";


Translator::getInstance()->setSchema("inn");

Commentary::addCommentary();
$iname = getsetting("innname", LOCATION_INN);
$vname = getsetting("villagename", LOCATION_FIELDS);
$barkeep = getsetting('barkeep', '`tCedrik');

$op = Http::get('op');
// Correctly reset the location if they fleeing the dragon
// This needs to be done up here because a special could alter your op.
if ($op == "fleedragon") {
    $session['user']['location'] = $vname;
}

Header::pageHeader(["%s", Sanitize::sanitize($iname)]);
$skipinndesc = Events::handleEvent("inn");

if (!$skipinndesc) {
    DateTime::checkDay();
    $output->rawOutput("<span style='color: #9900FF'>");
    $output->outputNotl("`c`b");
    $output->output($iname);
    $output->outputNotl("`b`c");
}

$subop = Http::get('subop');

$com = Http::get('comscroll');
$comment = Http::post('insertcommentary');

$partner = Partner::getPartner();
Nav::add("Other");
VillageNav::render();
Nav::add("I?Return to the Inn", "inn.php");

switch ($op) {
    case "":
    case "strolldown":
    case "fleedragon":
            require __DIR__ . "/pages/inn/inn_default.php";
            blocknav("inn.php");
        break;
    case "converse":
            Commentary::commentDisplay("You stroll over to a table, place your foot up on the bench and listen in on the conversation:`n", "inn", "Add to the conversation?", 20);
        break;
    case "bartender":
            require __DIR__ . "/pages/inn/inn_bartender.php";
        break;
    case "room":
            require __DIR__ . "/pages/inn/inn_room.php";
        break;
}

if (!$skipinndesc) {
    $output->rawOutput("</span>");
}

Footer::pageFooter();
