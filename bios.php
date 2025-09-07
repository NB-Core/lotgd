<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;

/**
 * \file bios.php
 * This file provides the basic block of a users bio, hence it will display a warning. Blocking and unblocking can be done from the bio of the user.
 * @see bio.php
 */

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Mail;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("bio");
SuAccess::check(SU_EDIT_COMMENTS);

$op = Http::get('op');
$userid = (int)Http::get('userid');
if ($op == "block") {
       $sql = "UPDATE " . Database::prefix("accounts") . " SET bio='`iBlocked for inappropriate usage`i',biotime='9999-12-31 23:59:59' WHERE acctid=$userid";
    $subj = array("Your bio has been blocked");
    $msg = array("The system administrators have decided that your bio entry is inappropriate, so it has been blocked.`n`nIf you wish to appeal this decision, you may do so with the petition link.");
    Mail::systemMail($userid, $subj, $msg);
    Database::query($sql);
}
if ($op == "unblock") {
       $sql = "UPDATE " . Database::prefix("accounts") . " SET bio='',biotime='" . DATETIME_DATEMIN . "' WHERE acctid=$userid";
    $subj = array("Your bio has been unblocked");
    $msg = array("The system administrators have decided to unblock your bio.  You can once again enter a bio entry.");
    Mail::systemMail($userid, $subj, $msg);
    Database::query($sql);
}
$sql = "SELECT name,acctid,bio,biotime FROM " . Database::prefix("accounts") . " WHERE biotime<'9999-12-31' AND bio>'' ORDER BY biotime DESC LIMIT 100";
$result = Database::query($sql);
Header::pageHeader("User Bios");
$block = translate_inline("Block");
output("`b`&Player Bios:`0`b`n");
while ($row = Database::fetchAssoc($result)) {
    if ($row['biotime'] > $session['user']['recentcomments']) {
        rawoutput("<img src='images/new.gif' alt='&gt;' width='3' height='5' align='absmiddle'> ");
    }
    output_notl("`![<a href='bios.php?op=block&userid={$row['acctid']}'>$block</a>]", true);
    Nav::add("", "bios.php?op=block&userid={$row['acctid']}");
    output_notl("`&%s`0: `^%s`0`n", $row['name'], soap($row['bio']));
}
Database::freeResult($result);
SuperuserNav::render();

Nav::add("Moderation");

if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    Nav::add("Return to Comment Moderation", "moderate.php");
}

Nav::add("Refresh", "bios.php");
$sql = "SELECT name,acctid,bio,biotime FROM " . Database::prefix("accounts") . " WHERE biotime>'9000-01-01' AND bio>'' ORDER BY biotime DESC LIMIT 100";
$result = Database::query($sql);
output("`n`n`b`&Blocked Bios:`0`b`n");
$unblock = translate_inline("Unblock");
$number = Database::numRows($result);
for ($i = 0; $i < $number; $i++) {
    $row = Database::fetchAssoc($result);

    output_notl("`![<a href='bios.php?op=unblock&userid={$row['acctid']}'>$unblock</a>]", true);
    Nav::add("", "bios.php?op=unblock&userid={$row['acctid']}");
    output_notl("`&%s`0: `^%s`0`n", $row['name'], soap($row['bio']));
}
Database::freeResult($result);
Footer::pageFooter();
