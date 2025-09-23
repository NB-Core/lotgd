<?php

use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;

/**
 * \file clan.php
 * This file contains the base for the clans. This feature can be deactivated in the grotto.
 * @see village.php
 * @see pages/clan
 */


// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();

Translator::getInstance()->setSchema("clans");


Nav::add("Village");
VillageNav::render();
Nav::add("Clan Options");
Nav::add("C?List Clans", "clan.php?op=list");
Commentary::addCommentary();
$gold = $settings->getSetting('goldtostartclan', 10000);
$gems = $settings->getSetting('gemstostartclan', 15);
$ranks = array(CLAN_APPLICANT => "`!Applicant`0",CLAN_MEMBER => "`#Member`0",CLAN_OFFICER => "`^Officer`0",CLAN_ADMINISTRATIVE => "`\$Administrative`0",CLAN_LEADER => "`&Leader`0", CLAN_FOUNDER => "`\$Founder");
$args = HookHandler::hook("clanranks", array("ranks" => $ranks, "clanid" => $session['user']['clanid']));
$ranks = Translator::translateInline($args['ranks']);

$apply_short = "`@Clan App: `&%s`0";
$apply_subj = array($apply_short, $session['user']['name']);

$op = Http::get('op');

$detail = Http::get('detail');
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


Footer::pageFooter();

function clanform()
{
    $output = Output::getInstance();
    $settings = Settings::getInstance();

    $output->rawOutput("<form action='clan.php?op=new&apply=1' method='POST'>");
    Nav::add("", "clan.php?op=new&apply=1");
    $output->output("`b`cNew Clan Application Form`c`b");
    $output->output("Clan Name: ");
    $output->rawOutput("<input name='clanname' maxlength='50' value=\"" . htmlentities(stripslashes(Http::post('clanname')), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\">");
    $output->output("`nShort Name: ");
    $output->rawOutput("<input name='clanshort' maxlength='5' size='5' value=\"" . htmlentities(stripslashes(Http::post('clanshort')), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\">");
    $output->output("`nNote, color codes are permitted in neither clan names nor short names.");
    $output->output("The clan name is shown on player bios and on clan overview pages while the short name is displayed next to players' names in comment areas and such.`n");
    $apply = Translator::translateInline("Apply");
    $output->rawOutput("<input type='submit' class='button' value='$apply'></form>");
}
