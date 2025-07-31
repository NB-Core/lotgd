<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Modules;

    Header::pageHeader("Clan Halls");
    $registrar = getsetting('clanregistrar', '`%Karissa');
    Nav::add("Clan Options");
    $output->output("`b`c`&Clan Halls`c`b");
if ($op == "apply") {
        require("pages/clan/applicant_apply.php");
} elseif ($op == "new") {
        require("pages/clan/applicant_new.php");
} else {
    $output->output("`7You stand in the center of a great marble lobby filled with pillars.");
    $output->output("All around the walls of the lobby are various doors which lead to various clan halls.");
    $output->output("The doors each possess a variety of intricate mechanisms which are obviously elaborate locks designed to be opened only by those who have been educated on how to operate them.");
    $output->output("Nearby, you watch another warrior glance about nervously to make sure no one is watching before touching various levers and knobs on the door.");
    $output->output("With a large metallic \"Chunk\" the lock on the door disengages, and the door swings silently open, admitting the warrior before slamming shut.`n`n");
    $output->output("In the center of the lobby sits a highly polished desk, behind which sits `%%s`7, the clan registrar.", $registrar);
    $output->output("She can take your filing for a new clan, or accept your application to an existing clan.`n`n");
/*//*/  Modules::hook("clan-enter");
    if ($op == "withdraw") {
        $session['user']['clanid'] = 0;
        $session['user']['clanrank'] = CLAN_APPLICANT;
        $session['user']['clanjoindate'] = DATETIME_DATEMIN;
        $output->output("`7You tell `%%s`7 that you're no longer interested in joining %s.", $registrar, $claninfo['clanname']);
        $output->output("She reaches into her desk, withdraws your application, and tears it up.  \"`5You wouldn't have been happy there anyhow, I don't think,`7\" as she tosses the shreds in her trash can.");
        $claninfo = array();
        $sql = "DELETE FROM " . Database::prefix("mail") . " WHERE msgfrom=0 AND seen=0 AND subject='" . Database::escape(serialize($apply_subj)) . "'";
        Database::query($sql);
        $output->output("You are not a member of any clan.");
        Nav::add("Apply for Membership to a Clan", "clan.php?op=apply");
        Nav::add("Apply for a New Clan", "clan.php?op=new");
    } else {
        if (isset($claninfo['clanid']) && $claninfo["clanid"] > 0) {
            //applied for membership to a clan
            $output->output("`7You approach `%%s`7 who smiles at you, but lets you know that your application to %s hasn't yet been accepted.", $registrar, $claninfo['clanname']);
            $output->output("Perhaps you'd like to take a seat in the waiting area, she suggests.");
            Nav::add("Waiting Area", "clan.php?op=waiting");
            Nav::add("Withdraw Application", "clan.php?op=withdraw");
        } else {
            //hasn't applied for membership to any clan.
            $output->output("You are not a member of any clan.");
            Nav::add("Apply for Membership to a Clan", "clan.php?op=apply");
            Nav::add("Apply for a New Clan", "clan.php?op=new");
        }
    }
}
