<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Http;
use Lotgd\Translator;
use Lotgd\Sanitize;

    Header::pageHeader("Clan Listing");
    $registrar = getsetting('clanregistrar', '`%Karissa');
    Nav::add("Clan Options");
    $order = (int)Http::get('order');
switch ($order) {
    case 1:
        $order = 'clanname ASC';
        break;
    case 2:
        $order = 'clanshort ASC';
        break;
    default:
        $order = 'c DESC';
        break;
}
    $sql = "SELECT MAX(" . Database::prefix("clans") . ".clanid) AS clanid, MAX(clanshort) AS clanshort, MAX(clanname) AS clanname,count(" . Database::prefix("accounts") . ".acctid) AS c FROM " . Database::prefix("clans") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("clans") . ".clanid=" . Database::prefix("accounts") . ".clanid AND clanrank>" . CLAN_APPLICANT . " GROUP BY " . Database::prefix("clans") . ".clanid ORDER BY $order";
    $result = Database::query($sql);
if (Database::numRows($result) > 0) {
    $output->output("`7You ask %s`7 for the clan listings.  She points you toward a marquee board near the entrance of the lobby that lists the clans.`0`n`n", $registrar);
    $v = 0;
    $memb_n = Translator::translateInline("(%s members)");
    $memb_1 = Translator::translateInline("(%s member)");
    $output->rawOutput('<table cellspacing="0" cellpadding="2" align="left">');
    while ($row = Database::fetchAssoc($result)) {
        if ($row['c'] == 0) {
            $sql = "DELETE FROM " . Database::prefix("clans") . " WHERE clanid={$row['clanid']}";
            Database::query($sql);
        } else {
            $output->rawOutput('<tr class="' . ($v % 2 ? "trlight" : "trdark") . '"><td>', true);
            if ($row['c'] == 1) {
                $memb = sprintf($memb_1, $row['c']);
            } else {
                $memb = sprintf($memb_n, $row['c']);
            }
            $output->outputNotl(
                "&#149; &#60;%s&#62; <a href='clan.php?detail=%s'>%s</a> %s`n",
                $row['clanshort'],
                $row['clanid'],
                htmlentities(Sanitize::fullSanitize($row['clanname']), ENT_COMPAT, getsetting("charset", "UTF-8")),
                $memb,
                true
            );
            $output->rawOutput('</td></tr>');
            Nav::add("", "clan.php?detail={$row['clanid']}");
            $v++;
        }
    }
    $output->rawOutput("</table>", true);
    Nav::add("Return to the Lobby", "clan.php");
    Nav::add("Sorting");
    Nav::add("Order by Membercount", "clan.php?op=list&order=0");
    Nav::add("Order by Clanname", "clan.php?op=list&order=1");
    Nav::add("Order by Shortname", "clan.php?op=list&order=2");
} else {
    $output->output("`7You ask %s`7 for the clan listings.  She stares at you blankly for a few moments, then says, \"`5Sorry pal, no one has had enough gumption to start up a clan yet.  Maybe that should be you, eh?`7\"", $registrar);
    Nav::add("Apply for a New Clan", "clan.php?op=new");
    Nav::add("Return to the Lobby", "clan.php");
}

    Footer::pageFooter();
