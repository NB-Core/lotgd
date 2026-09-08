<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\Http;
use Lotgd\Sanitize;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Modules;
use Lotgd\Nltoappon;
use Lotgd\Page\Header;
use Lotgd\Output;
use Lotgd\Settings;

$output = Output::getInstance();
$settings = Settings::getInstance();
$charset = $settings->getSetting('charset', 'UTF-8');

if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    $clanname = Http::post('clanname');
    if ($clanname) {
        $clanname = Sanitize::stripAllColorCodes($clanname);
    }
    $clanshort = Http::post('clanshort');
    if ($clanshort) {
        $clanshort = Sanitize::stripAllColorCodes($clanshort);
    }
    if ($clanname > "" && $clanshort > "") {
        // Both values arrive from the request body. Sanitize::stripAllColorCodes()
        // removes LotGD colour markup and nothing else, so they are bound here
        // rather than interpolated.
        Database::getDoctrineConnection()->executeStatement(
            "UPDATE " . Database::prefix("clans")
                . " SET clanname = :clanname, clanshort = :clanshort WHERE clanid = :clanid",
            ['clanname' => $clanname, 'clanshort' => $clanshort, 'clanid' => $detail],
            ['clanname' => ParameterType::STRING, 'clanshort' => ParameterType::STRING, 'clanid' => ParameterType::INTEGER]
        );
        $output->output("Updating clan names`n");
        DataCache::getInstance()->invalidatedatacache("clandata-$detail");
    }
    if (Http::post('block') > "") {
        // Translated text is editable through the translation tool and may
        // legitimately contain an apostrophe.
        $blockdesc = Translator::translateInline("Description blocked for inappropriate usage.");
        Database::getDoctrineConnection()->executeStatement(
            "UPDATE " . Database::prefix("clans")
                . " SET descauthor = 4294967295, clandesc = :clandesc WHERE clanid = :clanid",
            ['clandesc' => $blockdesc, 'clanid' => $detail],
            ['clandesc' => ParameterType::STRING, 'clanid' => ParameterType::INTEGER]
        );
        $output->output("Blocking public description`n");
        DataCache::getInstance()->invalidatedatacache("clandata-$detail");
    } elseif (Http::post('unblock') > "") {
        Database::getDoctrineConnection()->executeStatement(
            "UPDATE " . Database::prefix("clans")
                . " SET descauthor = 0, clandesc = '' WHERE clanid = :clanid",
            ['clanid' => $detail],
            ['clanid' => ParameterType::INTEGER]
        );
        $output->output("UNblocking public description`n");
        DataCache::getInstance()->invalidatedatacache("clandata-$detail");
    }
}
    // queryCached() takes no bound parameters, so the integer nature of the id
    // is made visible here rather than relying on the cast in clan.php.
    $sql = "SELECT * FROM " . Database::prefix("clans") . " WHERE clanid=" . (int) $detail;
    $result1 = Database::queryCached($sql, "clandata-" . (int) $detail, 3600);
    $row1 = Database::fetchAssoc($result1);
if ($session['user']['superuser'] & SU_AUDIT_MODERATION) {
    $output->rawOutput("<div id='hidearea'>");
    $output->rawOutput("<form action='clan.php?detail=$detail' method='POST'>");
    Nav::add("", "clan.php?detail=$detail");
    $output->output("Superuser / Moderator renaming:`n");
    $output->output("Long Name: ");
    $output->rawOutput("<input name='clanname' value=\"" . htmlentities($row1['clanname'], ENT_COMPAT, $charset) . "\" maxlength=50 size=50>");
    $output->output("`nShort Name: ");
    $output->rawOutput("<input name='clanshort' value=\"" . htmlentities($row1['clanshort'], ENT_COMPAT, $charset) . "\" maxlength=5 size=5>");
    $output->outputNotl("`n");
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value=\"$save\">");
    $snu = htmlentities(Translator::translateInline("Save & UNblock public description"), ENT_COMPAT, $charset);
    $snb = htmlentities(Translator::translateInline("Save & Block public description"), ENT_COMPAT, $charset);
    if ($row1['descauthor'] == "4294967295") {
        $output->rawOutput("<input type='submit' name='unblock' value=\"$snu\" class='button'>");
    } else {
        $output->rawOutput("<input type='submit' name='block' value=\"$snb\" class='button'>");
    }
    $output->rawOutput("</form>");
    $output->rawOutput("</div>");
    $output->rawOutput("<script language='JavaScript'>var hidearea = document.getElementById('hidearea');hidearea.style.visibility='hidden';hidearea.style.display='none';</script>", true);
    $e = Translator::translateInline("Edit Clan Info");
    $output->rawOutput("<a href='#' onClick='hidearea.style.visibility=\"visible\"; hidearea.style.display=\"inline\"; return false;'>$e</a>", true);
    $output->outputNotl("`n");
}

    $clanDescription = (string) ($row1['clandesc'] ?? '');
    $convertedDescription = $clanDescription === '' ? '' : Nltoappon::convert($clanDescription);

    $output->outputNotl($convertedDescription);
    if ($convertedDescription != "") {
        $output->output("`n`n");
    }
    $output->output("`0This is the current clan membership of %s < %s >:`n", $row1['clanname'], $row1['clanshort']);
    Header::pageHeader("Clan Membership for %s &lt;%s&gt;", Sanitize::stripAllColorCodes($row1['clanname']), Sanitize::stripAllColorCodes($row1['clanshort']));
    Nav::add("Clan Options");
    $rank = Translator::translateInline("Rank");
    $name = Translator::translateInline("Name");
    $dk = Translator::translateInline("Dragon Kills");
    $jd = Translator::translateInline("Join Date");
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='0'>");
    $output->rawOutput("<tr class='trhead'><td>$rank</td><td>$name</td><td>$dk</td><td>$jd</td></tr>");
    $i = 0;
    $result = Database::getDoctrineConnection()->executeQuery(
        "SELECT acctid,name,login,clanrank,clanjoindate,dragonkills FROM " . Database::prefix("accounts")
            . " WHERE clanid = :clanid ORDER BY clanrank DESC,clanjoindate",
        ['clanid' => $detail],
        ['clanid' => ParameterType::INTEGER]
    );
    $tot = 0;
    //little hack with the hook...can't think of any other way
    $ranks = array(CLAN_APPLICANT => "`!Applicant`0",CLAN_MEMBER => "`#Member`0",CLAN_OFFICER => "`^Officer`0",CLAN_LEADER => "`&Leader`0", CLAN_FOUNDER => "`\$Founder");
    $args = Modules::hook("clanranks", array("ranks" => $ranks, "clanid" => $detail));
    $ranks = Translator::translateInline($args['ranks']);
    //end
while ($row = Database::fetchAssoc($result)) {
    $i++;
    $tot += $row['dragonkills'];
    $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
    $output->rawOutput("<td>");
    $output->outputNotl($ranks[$row['clanrank']]); //translated earlier
    $output->rawOutput("</td><td>");
    $link = "bio.php?char=" . $row['acctid'] . "&ret=" . urlencode($_SERVER['REQUEST_URI']);
    $output->rawOutput("<a href='$link'>");
    Nav::add("", $link);
    $output->outputNotl("`&%s`0", $row['name']);
    $output->rawOutput("</a>");
    $output->rawOutput("</td><td align='center'>");
    $output->outputNotl("`\$%s`0", $row['dragonkills']);
    $output->rawOutput("</td><td>");
    $output->outputNotl("`3%s`0", $row['clanjoindate']);
    $output->rawOutput("</td></tr>");
}
    $output->rawOutput("</table>");
    $output->output("`n`n`^This clan has a total of `\$%s`^ dragon kills.", $tot);
