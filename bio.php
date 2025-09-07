<?php

declare(strict_types=1);

use Lotgd\Translator;
// addnews ready
// translator ready
// mail ready
/**
* \file bio.php
* This file holds the code for the user bio view. It features hooks to let modules add code and hence descriptions. The user can set a bio description in his preferences.
* @see village.php
* @see prefs.php
*/
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Modules;
use Lotgd\Modules\HookHandler;
use Lotgd\MySQL\Database;
use Lotgd\Sanitize;
use Lotgd\DateTime;
use Lotgd\Nltoappon;
use Lotgd\Output;

require_once __DIR__ . "/common.php";
$output = Output::getInstance();

$translator = Translator::getInstance();

$translator->setSchema("bio");

DateTime::checkDay();

$ret = Http::get('ret');
if ($ret == "") {
    $return = "/list.php";
} else {
    $return = Sanitize::cmdSanitize($ret);
}

$char = Http::get('char');
//Legacy support
if (is_numeric($char)) {
    $where = "acctid = $char";
} else {
    $where = "login = '$char'";
}
$sql = "SELECT login, name, level, sex, title, specialty, hashorse, acctid, resurrections, bio, dragonkills, race, clanname, clanshort, clanrank, " . Database::prefix("accounts") . ".clanid, laston, loggedin FROM " . Database::prefix("accounts") . " LEFT JOIN " . Database::prefix("clans") . " ON " . Database::prefix("accounts") . ".clanid = " . Database::prefix("clans") . ".clanid WHERE $where";
$result = Database::query($sql);
if ($target = Database::fetchAssoc($result)) {
  // Let a module get the values if necessary
    $target = HookHandler::hook("biotarget", $target);
    $target['login'] = rawurlencode($target['login']);
    $id = $target['acctid'];
    $target['return_link'] = $return;

    Header::pageHeader("Character Biography: %s", Sanitize::fullSanitize($target['name']));

    $translator->setSchema("nav");
    Nav::add("Return");
    $translator->setSchema();

    if ($session['user']['superuser'] & SU_EDIT_USERS) {
        Nav::add("Superuser");
        Nav::add("Edit User", "user.php?op=edit&userid=$id");
    }

    HookHandler::hook("biotop", $target);

    $output->output("`^Biography for %s`^.", $target['name']);
    $write = translate_inline("Write Mail");
    if ($session['user']['loggedin']) {
        $output->rawOutput("<a href=\"mail.php?op=write&to={$target['login']}\" target=\"_blank\" onClick=\"" . popup("mail.php?op=write&to={$target['login']}") . ";return false;\"><img src='images/newscroll.GIF' width='16' height='16' alt='$write' border='0'></a>");
    }
    $output->outputNotl("`n`n");

    if ($target['clanname'] > "" && getsetting("allowclans", false)) {
        $ranks = array(CLAN_APPLICANT => "`!Applicant`0",CLAN_MEMBER => "`#Member`0",CLAN_OFFICER => "`^Officer`0",CLAN_LEADER => "`&Leader`0", CLAN_FOUNDER => "`\$Founder");
        $ranks = HookHandler::hook("clanranks", array("ranks" => $ranks, "clanid" => $target['clanid']));
        $translator->setSchema("clans"); //just to be in the right schema
        array_push($ranks['ranks'], "`\$Founder");
        $ranks = translate_inline($ranks['ranks']);
        $translator->setSchema();
        $output->output("`@%s`2 is a %s`2 to `%%s`2`n", $target['name'], str_replace(array("`c","`i"), "", $ranks[$target['clanrank']]), str_replace(array("`c","`i"), "", $target['clanname']));
    }

    $output->output("`^Title: `@%s`n", $target['title']);
    $output->output("`^Level: `@%s`n", $target['level']);
    $loggedin = false;
    if (
        $target['loggedin'] &&
          (date("U") - strtotime($target['laston']) <
            getsetting("LOGINTIMEOUT", 900))
    ) {
        $loggedin = true;
    }
    $status = translate_inline($loggedin ? "`#Online`0" : "`\$Offline`0");
    $output->output("`^Status: %s`n", $status);

    $output->output("`^Resurrections: `@%s`n", $target['resurrections']);

    $race = $target['race'];
    if (!$race) {
        $race = RACE_UNKNOWN;
    }
    $race = translate_inline($race, "race", "race");
    $output->output("`^Race: `@%s`n", $race);

    $genders = array("Male","Female");
    $genders = translate_inline($genders);
    $output->output("`^Gender: `@%s`n", $genders[$target['sex']]);

    $specialties = HookHandler::hook(
        "specialtynames",
        array("" => translate_inline("Unspecified"))
    );
    if (isset($specialties[$target['specialty']])) {
        $output->output("`^Specialty: `@%s`n", $specialties[$target['specialty']]);
    }
    $sql = "SELECT * FROM " . Database::prefix("mounts") . " WHERE mountid='{$target['hashorse']}'";
    $result = Database::queryCached($sql, "mountdata-{$target['hashorse']}", 3600);
    $mount = Database::fetchAssoc($result);

    $mount['acctid'] = $target['acctid'];
    $mount = HookHandler::hook("bio-mount", $mount);
    $none = translate_inline("`iNone`i");
    if (!isset($mount['mountname']) || $mount['mountname'] == "") {
          $mount['mountname'] = $none;
    }
    $output->output("`^Creature: `@%s`0`n", $mount['mountname']);

    HookHandler::hook("biostat", $target);

    if ($target['dragonkills'] > 0) {
        $output->output("`^Dragon Kills: `@%s`n", $target['dragonkills']);
    }

    if ($target['bio'] > "") {
        $output->output("`^Bio: `@`n%s`n", Nltoappon::convert(soap($target['bio'])));
    }

    HookHandler::hook("bioinfo", $target);

    $output->output("`n`^Recent accomplishments (and defeats) of %s`^", $target['name']);
    $result = Database::query("SELECT * FROM " . Database::prefix("news") . " WHERE accountid={$target['acctid']} ORDER BY newsdate DESC,newsid ASC LIMIT 100");

    $odate = "";
    $translator->setSchema("news");
    while ($row = Database::fetchAssoc($result)) {
        $translator->setSchema($row['tlschema']);
        if ($row['arguments'] > "") {
            $arguments = array();
            $base_arguments = unserialize($row['arguments']);
            array_push($arguments, $row['newstext']);
            foreach ($base_arguments as $val) {
                array_push($arguments, $val);
            }
            $news = $translator->sprintfTranslate(...$arguments);
            $output->rawOutput(Translator::clearButton());
        } else {
            $news = translate_inline($row['newstext']);
            $output->rawOutput(Translator::clearButton());
        }
        $translator->setSchema();
        if ($odate != $row['newsdate']) {
            $output->outputNotl(
                "`n`b`@%s`0`b`n",
                date("D, M d", strtotime($row['newsdate']))
            );
            $odate = $row['newsdate'];
        }
        $output->outputNotl("`@" . sanitize_mb($news) . "`0`n");
    }
    $translator->setSchema();

    if ($ret == "") {
        $return = basename($return);
        $translator->setSchema("nav");
        Nav::add("Return");
        Nav::add("Return to the warrior list", $return);
        $translator->setSchema();
    } else {
        $return = basename($return);
        $translator->setSchema("nav");
        Nav::add("Return");
        if ($return == "list.php") {
            Nav::add("Return to the warrior list", $return);
        } else {
            Nav::add("Return whence you came", $return);
        }
        $translator->setSchema();
    }

    HookHandler::hook("bioend", $target);
    Footer::pageFooter();
} else {
    Header::pageHeader("Character has been deleted");
    $output->output("This character is already deleted.");
    if ($ret == "") {
        $return = basename($return);
        $translator->setSchema("nav");
        Nav::add("Return");
        Nav::add("Return to the warrior list", $return);
        $translator->setSchema();
    } else {
        $return = basename($return);
        $translator->setSchema("nav");
        Nav::add("Return");
        if ($return == "list.php") {
            Nav::add("Return to the warrior list", $return);
        } else {
            Nav::add("Return whence you came", $return);
        }
        $translator->setSchema();
    }
    Footer::pageFooter();
}
