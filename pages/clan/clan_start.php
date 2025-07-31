<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Nav;
use Lotgd\Sanitize;

    Header::pageHeader("Clan Hall for %s", Sanitize::fullSanitize($claninfo['clanname']));
    Nav::add("Clan Options");
if ($op == "") {
        require("pages/clan/clan_default.php");
} elseif ($op == "motd") {
        require("pages/clan/clan_motd.php");
} elseif ($op == "membership") {
        require("pages/clan/clan_membership.php");
} elseif ($op == "withdrawconfirm") {
    $output->output("Are you sure you want to withdraw from your clan?");
    Nav::add("Withdraw?");
    Nav::add("No", "clan.php");
    Nav::add("!?Yes", "clan.php?op=withdraw");
} elseif ($op == "withdraw") {
    require("pages/clan/clan_withdraw.php");
}
