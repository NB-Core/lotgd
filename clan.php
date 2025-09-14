<?php

use Lotgd\Translator;

/**
 * \file clan.php
 * This file contains the base for the clans. This feature can be deactivated in the grotto.
 * @see village.php
 * @see pages/clan
 */

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/nltoappon.php";
require_once __DIR__ . "/lib/sanitize.php";
require_once __DIR__ . "/lib/http.php";
require_once __DIR__ . "/lib/villagenav.php";

Translator::getInstance()->setSchema("clans");


addnav("Village");
villagenav();
addnav("Clan Options");
addnav("C?List Clans", "clan.php?op=list");
Commentary::addCommentary();
$gold = getsetting("goldtostartclan", 10000);
$gems = getsetting("gemstostartclan", 15);
$ranks = array(CLAN_APPLICANT => "`!Applicant`0",CLAN_MEMBER => "`#Member`0",CLAN_OFFICER => "`^Officer`0",CLAN_ADMINISTRATIVE => "`\$Administrative`0",CLAN_LEADER => "`&Leader`0", CLAN_FOUNDER => "`\$Founder");
$args = modulehook("clanranks", array("ranks" => $ranks, "clanid" => $session['user']['clanid']));
$ranks = translate_inline($args['ranks']);

$apply_short = "`@Clan App: `&%s`0";
$apply_subj = array($apply_short, $session['user']['name']);

$op = httpget('op');

$detail = httpget('detail');
if ($detail > 0) {
        require_once __DIR__ . "/pages/clan/detail.php";
} elseif ($op == "list") {
        require_once __DIR__ . "/pages/clan/list.php";
} elseif ($op == "waiting") {
        require_once __DIR__ . "/pages/clan/waiting.php";
} elseif ($session['user']['clanrank'] == CLAN_APPLICANT) {
        require_once __DIR__ . "/pages/clan/applicant.php";
} else {
        require_once __DIR__ . "/pages/clan/clan_start.php";
}


page_footer();

function clanform()
{
    rawoutput("<form action='clan.php?op=new&apply=1' method='POST'>");
    addnav("", "clan.php?op=new&apply=1");
    output("`b`cNew Clan Application Form`c`b");
    output("Clan Name: ");
    rawoutput("<input name='clanname' maxlength='50' value=\"" . htmlentities(stripslashes(httppost('clanname')), ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
    output("`nShort Name: ");
    rawoutput("<input name='clanshort' maxlength='5' size='5' value=\"" . htmlentities(stripslashes(httppost('clanshort')), ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
    output("`nNote, color codes are permitted in neither clan names nor short names.");
    output("The clan name is shown on player bios and on clan overview pages while the short name is displayed next to players' names in comment areas and such.`n");
    $apply = translate_inline("Apply");
    rawoutput("<input type='submit' class='button' value='$apply'></form>");
}
