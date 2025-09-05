<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Nav;
use Lotgd\Sanitize;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;
use Lotgd\Translator;
use Lotgd\Nltoappon;

        Header::pageHeader("Update Clan Description / MoTD");
        Nav::add("Clan Options");
if ($session['user']['clanrank'] >= CLAN_OFFICER) {
    $clanmotd = Sanitize::sanitizeMb(mb_substr((string) Http::post('clanmotd'), 0, 4096, getsetting('charset', 'ISO-8859-1')));
    if (
        Http::postIsset('clanmotd') &&
            stripslashes($clanmotd) != $claninfo['clanmotd']
    ) {
        $sql = "UPDATE " . Database::prefix("clans") . " SET clanmotd='" . addslashes($clanmotd) . "',motdauthor={$session['user']['acctid']} WHERE clanid={$claninfo['clanid']}";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("clandata-{$claninfo['clanid']}");
        $claninfo['clanmotd'] = stripslashes($clanmotd);
        $output->output("Updating MoTD`n");
        $claninfo['motdauthor'] = $session['user']['acctid'];
    }
    $clandesc = Http::post('clandesc');
    if (
        Http::postIsset('clandesc') &&
            stripslashes($clandesc) != $claninfo['clandesc'] &&
            $claninfo['descauthor'] != 4294967295
    ) {
        $sql = "UPDATE " . Database::prefix("clans") . " SET clandesc='" . addslashes(mb_substr(stripslashes($clandesc), 0, 4096, getsetting('charset', 'UTF-8'))) . "',descauthor={$session['user']['acctid']} WHERE clanid={$claninfo['clanid']}";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("clandata-{$claninfo['clanid']}");
        $output->output("Updating description`n");
        $claninfo['clandesc'] = stripslashes($clandesc);
        $claninfo['descauthor'] = $session['user']['acctid'];
    }
    $customsay = Http::post('customsay');
    if (Http::postIsset('customsay') && $customsay != $claninfo['customsay'] && $session['user']['clanrank'] >= CLAN_LEADER) {
        $sql = "UPDATE " . Database::prefix("clans") . " SET customsay='$customsay' WHERE clanid={$claninfo['clanid']}";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("clandata-{$claninfo['clanid']}");
        $output->output("Updating custom say line`n");
        $claninfo['customsay'] = stripslashes($customsay);
    }
    $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid={$claninfo['motdauthor']}";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    if (isset($row['name'])) {
        $motdauthname = $row['name'];
    } else {
        $motdauthname = Translator::translateInline("Lost in memory");
    }

    $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid={$claninfo['descauthor']}";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    if (isset($row['name'])) {
        $descauthname = $row['name'];
    } else {
        $descauthname = Translator::translateInline("Lost in memory");
    }

    $output->output("`&`bCurrent MoTD:`b `#by %s`2`n", $motdauthname);
    $output->outputNotl(Nltoappon::convert($claninfo['clanmotd']) . "`n");
    $output->output("`&`bCurrent Description:`b `#by %s`2`n", $descauthname);
    $output->outputNotl(Nltoappon::convert($claninfo['clandesc']) . "`n");

    $output->rawOutput("<form action='clan.php?op=motd' method='POST'>");
    Nav::add("", "clan.php?op=motd");
    $output->output("`&`bMoTD:`b `7(4096 chars)`n");
    $output->rawOutput("<textarea name='clanmotd' cols='50' rows='10' class='input' style='width: 66%'>" . htmlentities($claninfo['clanmotd'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br>");
    $output->output("`n`&`bDescription:`b `7(4096 chars)`n");
    $blocked = Translator::translateInline("Your clan has been blocked from posting a description.`n");
    if ($claninfo['descauthor'] == INT_MAX) {
        $output->outputNotl($blocked);
    } else {
        $output->rawOutput("<textarea name='clandesc' cols='50' rows='10' class='input' style='width: 66%'>" . htmlentities($claninfo['clandesc'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br>");
    }
    if ($session['user']['clanrank'] >= CLAN_LEADER) {
        $output->output("`n`&`bCustom Talk Line`b `7(blank means \"says\" -- 15 chars max)`n");
        $output->rawOutput("<input name='customsay' value=\"" . htmlentities($claninfo['customsay'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "\" class='input' maxlength=\"15\"><br/>");
    }
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'>");
    $output->rawOutput("</form>");
} else {
    $output->output("You do not have authority to change your clan's motd or description.");
}
        Nav::add("Return to your clan hall", "clan.php");
