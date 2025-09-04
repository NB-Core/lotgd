<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\ServerFunctions;
use Lotgd\Http;
use Lotgd\Translator;

require_once 'common.php';

Translator::getInstance()->setSchema('globaluserfunctions');

SuAccess::check(SU_MEGAUSER);

Header::pageHeader('Global User Functions');
SuperuserNav::render();
//Nav::add("Refresh the stats", "stats.php");
Nav::add('Actions');
Nav::add('Reset all dragonpoints', 'globaluserfunctions.php?op=dkpointreset');

$output->output('`n`c`q~~~~~ `\$Global User Functions `q~~~~~`c`n`n');

$op = (string) Http::get('op');

switch ($op) {
    case "dkpointreset":
        $output->output("`qThis lets you reset all the dragonpoints for all users on your server.`n`n`\$Handle with care!`q`n`nIf you hit `l\"Reset!\"`q there is no turning back!`n`nAlso note that the hitpoints will be recalculated and the players can respend their points.`n`nThere is also a hook in there allowing modules to reset any things they did.");
        Nav::add('Dragonpoints');
        Nav::add('Reset!', 'globaluserfunctions.php?op=dkpointresetnow');
        break;
    case "dkpointresetnow":
        $output->output("`qExecuting...");
        ServerFunctions::resetAllDragonkillPoints();
        $output->output("... `\$done!`n`n`qIf you need to do a MOTD, you should so so now!");
        break;
    default:
        $output->output("`QWelcome to the Global User Functions.`n`nPlease select your action.");
        break;
}

Footer::pageFooter();
