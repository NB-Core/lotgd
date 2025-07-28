<?php
declare(strict_types=1);

use Lotgd\Modules;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Nav;

        Modules::hook("collapse{", ["name" => "clanentry"]);
        $output->output("Having pressed the secret levers and turned the secret knobs on the lock of the door to your clan's hall, you gain entrance and chat with your clan mates.`n`n");
        Modules::hook("}collapse");

        $sql = "SELECT name FROM " . Database::prefix("accounts")  . " WHERE acctid={$claninfo['motdauthor']}";
        $result = Database::query($sql);
if (Database::numRows($result) > 0) {
    $row = Database::fetchAssoc($result);
    $motdauthname = $row['name'];
} else {
    $motdauthname = Translator::translateInline("Nobody");
}
        $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid={$claninfo['descauthor']}";
        $result = Database::query($sql);
if (Database::numRows($result) > 0) {
    $row = Database::fetchAssoc($result);
    $descauthname = $row['name'];
} else {
    $descauthname = Translator::translateInline("Nobody");
}

if ($claninfo['clanmotd'] != '') {
    $output->rawOutput("<div style='margin-left: 15px; padding-left: 15px;'>");
    $output->output("`&`bCurrent MoTD:`b `#by %s`2`n", $motdauthname);
    $output->outputNotl(nltoappon($claninfo['clanmotd']) . "`n");
    $output->rawOutput("</div>");
    $output->outputNotl("`n");
}

        // you can modify the displayed clanchat here. more control for modules
        $clan_commentary = Modules::hook("clan-commentary", array("section" => "clan-{$claninfo['clanid']}","clanid" => $claninfo['clanid']));
        $clan_section_name = $clan_commentary['section'];
            Commentary::commentDisplay("", $clan_section_name, "Speak", 25, ($claninfo['customsay'] > '' ? $claninfo['customsay'] : "says"));

        Modules::hook("clanhall");

if ($claninfo['clandesc'] != '') {
    Modules::hook("collapse{", array("name" => "collapsedesc"));
    $output->output("`n`n`&`bCurrent Description:`b `#by %s`2`n", $descauthname);
    $output->outputNotl(nltoappon($claninfo['clandesc']));
    Modules::hook("}collapse");
}
        $sql = "SELECT count(acctid) AS c, clanrank FROM " . Database::prefix("accounts") . " WHERE clanid={$claninfo['clanid']} GROUP BY clanrank ORDER BY clanrank DESC";
        $result = Database::query($sql);
        // begin collapse
        Modules::hook("collapse{", array("name" => "clanmemberdet"));
        $output->output("`n`n`bMembership Details:`b`n");
        $leaders = 0;
while ($row = Database::fetchAssoc($result)) {
    $output->outputNotl((isset($ranks[$row['clanrank']]) ? $ranks[$row['clanrank']] : 'Undefined') . ": `0" . $row['c'] . "`n");
    if ($row['clanrank'] >= CLAN_LEADER) {
        $leaders += $row['c'];
    }
}
        $output->output("`n");
        $noleader = Translator::translateInline("`^There is currently no leader!  Promoting %s`^ to leader as they are the highest ranking member (or oldest member in the event of a tie).`n`n");
if ($leaders == 0) {
    //There's no leader here, probably because the leader's account
    //expired.
    $sql = "SELECT name,acctid,clanrank FROM " . Database::prefix("accounts") . " WHERE clanid={$session['user']['clanid']} AND clanrank > " . CLAN_APPLICANT . " ORDER BY clanrank DESC, clanjoindate";
    $result = Database::query($sql);
    if (Database::numRows($result)) {
        $row = Database::fetchAssoc($result);
        $sql = "UPDATE " . Database::prefix("accounts") . " SET clanrank=" . CLAN_LEADER . " WHERE acctid={$row['acctid']}";
        Database::query($sql);
        $output->outputNotl($noleader, $row['name']);
        if ($row['acctid'] == $session['user']['acctid']) {
            //if it's the current user, we'll need to update their
            //session in order for the db write to take effect.
            $session['user']['clanrank'] = CLAN_LEADER;
        }
    } else {
        // There are no viable leaders.  But we cannot disband the clan
        // here.
    }
}
        // end collapse
        Modules::hook("}collapse");

if ($session['user']['clanrank'] > CLAN_MEMBER) {
    Nav::add("Update MoTD / Clan Desc", "clan.php?op=motd");
}
        Nav::add("M?View Membership", "clan.php?op=membership");
        Nav::add("Online Members", "list.php?op=clan");
        Nav::add("Your Clan's Waiting Area", "clan.php?op=waiting");
        Nav::add("Withdraw From Your Clan", "clan.php?op=withdrawconfirm");
