<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Nav;

if ($display == 1) {
    $q = "";
    if ($query) {
        $q = "&q=$query";
    }
    $ops = Translator::translateInline("Ops");
    $acid = Translator::translateInline("AcctID");
    $login = Translator::translateInline("Login");
    $nm = Translator::translateInline("Name");
    $lev = Translator::translateInline("Level");
    $lon = Translator::translateInline("Last On");
    $hits = Translator::translateInline("Hits");
    $lip = Translator::translateInline("Last IP");
    $lid = Translator::translateInline("Last ID");
    $ban = Translator::translateInline("Ban");
        $output->rawOutput("<table>");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td><a href='bans.php?sort=acctid$q'>$acid</a></td><td><a href='bans.php?sort=login$q'>$login</a></td><td><a href='bans.php?sort=name$q'>$nm</a></td><td><a href='bans.php?sort=level$q'>$lev</a></td><td><a href='bans.php?sort=laston$q'>$lon</a></td><td><a href='bans.php?sort=gentimecount$q'>$hits</a></td><td><a href='bans.php?sort=lastip$q'>$lip</a></td><td><a href='bans.php?sort=uniqueid$q'>$lid</a></td></tr>");
    Nav::add("", "bans.php?sort=acctid$q");
    Nav::add("", "bans.php?sort=login$q");
    Nav::add("", "bans.php?sort=name$q");
    Nav::add("", "bans.php?sort=level$q");
    Nav::add("", "bans.php?sort=laston$q");
    Nav::add("", "bans.php?sort=gentimecount$q");
    Nav::add("", "bans.php?sort=lastip$q");
    Nav::add("", "bans.php?sort=uniqueid$q");
    $rn = 0;
    $oorder = "";
    while ($row = Database::fetchAssoc($searchresult)) {
        $laston = relativedate($row['laston']);
        $loggedin =
            (date("U") - strtotime($row['laston']) <
             $settings->getSetting("LOGINTIMEOUT", 900) && $row['loggedin']);
        if ($loggedin) {
            $laston = Translator::translateInline("`#Online`0");
        }
        $row['laston'] = $laston;
        if ($row[$order] != $oorder) {
            $rn++;
        }
        $oorder = $row[$order];
        $output->rawOutput("<tr class='" . ($rn % 2 ? "trlight" : "trdark") . "'>");
        $output->rawOutput("<td nowrap>");
        $output->rawOutput("[ <a href='bans.php?op=setupban&userid={$row['acctid']}'>$ban</a> ]");
        Nav::add("", "bans.php?op=setupban&userid={$row['acctid']}");
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['acctid']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['login']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("`&%s`0", $row['name']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("`^%s`0", $row['level']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['laston']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['gentimecount']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['lastip']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['uniqueid']);
        $output->rawOutput("</td>");
        $gentimecount += $row['gentimecount'];
        $gentime += $row['gentime'];
    }
    $output->rawOutput("</table>");
    $output->output("Total hits: %s`n", $gentimecount);
    $output->output("Total CPU time: %s seconds`n", round($gentime, 3));
    $output->output("Average page gen time is %s seconds`n", round($gentime / max($gentimecount, 1), 4));
}
