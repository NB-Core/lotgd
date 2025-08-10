<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Nav;
use Lotgd\Commentary;

    Header::pageHeader("Clan Halls");
    Nav::add("Clan Options");
    $output->output("`b`c`&Clan Halls`c`b");
    $output->output("You stroll off to the side where there are some plush leather chairs, and take a seat.");
    $output->output("There are several other warriors sitting here talking amongst themselves.");
    $output->output("Some Ye Olde Muzak is coming from a fake rock sitting at the base of a potted bush.`n`n");
      Commentary::commentDisplay("", "waiting", "Speak", 25);
if ($session['user']['clanrank'] == CLAN_APPLICANT) {
    Nav::add("Return to the Lobby", "clan.php");
} else {
    Nav::add("Return to your Clan Rooms", "clan.php");
}
