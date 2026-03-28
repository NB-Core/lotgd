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
use Lotgd\Output;
use Lotgd\Settings;
use Doctrine\DBAL\ParameterType;

        Header::pageHeader("Update Clan Description / MoTD");
        Nav::add("Clan Options");
    $output = Output::getInstance();
    $settings = Settings::getInstance();
    $charset = $settings->getSetting('charset', 'UTF-8');
    $charsetIso = $settings->getSetting('charset', 'ISO-8859-1');
if ($session['user']['clanrank'] >= CLAN_OFFICER) {
    $connection = Database::getDoctrineConnection();
    $clanmotd = Sanitize::sanitizeMb(mb_substr((string) Http::post('clanmotd'), 0, 4096, $charsetIso));
    if (
        Http::postIsset('clanmotd') &&
            stripslashes($clanmotd) != $claninfo['clanmotd']
    ) {
        $connection->executeStatement(
            "UPDATE " . Database::prefix("clans") . " SET clanmotd = :clanmotd, motdauthor = :motdauthor WHERE clanid = :clanid",
            [
                'clanmotd' => $clanmotd,
                'motdauthor' => (int) $session['user']['acctid'],
                'clanid' => (int) $claninfo['clanid'],
            ],
            [
                'clanmotd' => ParameterType::STRING,
                'motdauthor' => ParameterType::INTEGER,
                'clanid' => ParameterType::INTEGER,
            ]
        );
        DataCache::getInstance()->invalidatedatacache("clandata-{$claninfo['clanid']}");
        $claninfo['clanmotd'] = stripslashes($clanmotd);
        $output->output("Updating MoTD`n");
        $claninfo['motdauthor'] = $session['user']['acctid'];
    }
    $clanDescPost = Http::post('clandesc');
    $clandesc = is_string($clanDescPost) ? $clanDescPost : '';
    if (
        Http::postIsset('clandesc') &&
            stripslashes($clandesc) != $claninfo['clandesc'] &&
            $claninfo['descauthor'] != 4294967295
    ) {
        $connection->executeStatement(
            "UPDATE " . Database::prefix("clans") . " SET clandesc = :clandesc, descauthor = :descauthor WHERE clanid = :clanid",
            [
                'clandesc' => mb_substr(stripslashes($clandesc), 0, 4096, $charset),
                'descauthor' => (int) $session['user']['acctid'],
                'clanid' => (int) $claninfo['clanid'],
            ],
            [
                'clandesc' => ParameterType::STRING,
                'descauthor' => ParameterType::INTEGER,
                'clanid' => ParameterType::INTEGER,
            ]
        );
        DataCache::getInstance()->invalidatedatacache("clandata-{$claninfo['clanid']}");
        $output->output("Updating description`n");
        $claninfo['clandesc'] = stripslashes($clandesc);
        $claninfo['descauthor'] = $session['user']['acctid'];
    }
    $customSayPost = Http::post('customsay');
    $customsay = is_string($customSayPost) ? $customSayPost : '';
    if (Http::postIsset('customsay') && $customsay != $claninfo['customsay'] && $session['user']['clanrank'] >= CLAN_LEADER) {
        $connection->executeStatement(
            "UPDATE " . Database::prefix("clans") . " SET customsay = :customsay WHERE clanid = :clanid",
            [
                'customsay' => $customsay,
                'clanid' => (int) $claninfo['clanid'],
            ],
            [
                'customsay' => ParameterType::STRING,
                'clanid' => ParameterType::INTEGER,
            ]
        );
        DataCache::getInstance()->invalidatedatacache("clandata-{$claninfo['clanid']}");
        $output->output("Updating custom say line`n");
        $claninfo['customsay'] = stripslashes($customsay);
    }
    $row = $connection->fetchAssociative(
        "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid = :acctid",
        ['acctid' => (int) $claninfo['motdauthor']],
        ['acctid' => ParameterType::INTEGER]
    );
    if (isset($row['name'])) {
        $motdauthname = $row['name'];
    } else {
        $motdauthname = Translator::translateInline("Lost in memory");
    }

    $row = $connection->fetchAssociative(
        "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid = :acctid",
        ['acctid' => (int) $claninfo['descauthor']],
        ['acctid' => ParameterType::INTEGER]
    );
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
    $output->rawOutput("<textarea name='clanmotd' cols='50' rows='10' class='input' style='width: 66%'>" . htmlentities($claninfo['clanmotd'], ENT_COMPAT, $charset) . "</textarea><br>");
    $output->output("`n`&`bDescription:`b `7(4096 chars)`n");
    $blocked = Translator::translateInline("Your clan has been blocked from posting a description.`n");
    if ($claninfo['descauthor'] == INT_MAX) {
        $output->outputNotl($blocked);
    } else {
        $output->rawOutput("<textarea name='clandesc' cols='50' rows='10' class='input' style='width: 66%'>" . htmlentities($claninfo['clandesc'], ENT_COMPAT, $charset) . "</textarea><br>");
    }
    if ($session['user']['clanrank'] >= CLAN_LEADER) {
        $output->output("`n`&`bCustom Talk Line`b `7(blank means \"says\" -- 15 chars max)`n");
        $output->rawOutput("<input name='customsay' value=\"" . htmlentities($claninfo['customsay'], ENT_COMPAT, $charset) . "\" class='input' maxlength=\"15\"><br/>");
    }
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'>");
    $output->rawOutput("</form>");
} else {
    $output->output("You do not have authority to change your clan's motd or description.");
}
        Nav::add("Return to your clan hall", "clan.php");
