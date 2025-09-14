<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\PhpGenericEnvironment;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/sanitize.php";
require_once __DIR__ . "/lib/http.php";

SuAccess::check(0xFFFFFFFF & ~ SU_DOESNT_GIVE_GROTTO);
Commentary::addCommentary();
Translator::getInstance()->setSchema("superuser");

SuperuserNav::render();
addnav("Q?`%Quit`0 to the heavens", "login.php?op=logout", true);

$op = httpget('op');
if ($op == "keepalive") {
    $sql = "UPDATE " . Database::prefix("accounts") . " SET laston='" . date("Y-m-d H:i:s") . "' WHERE acctid='{$session['user']['acctid']}'";
    Database::query($sql);
    echo '<html><meta http-equiv="Refresh" content="30;url=' . PhpGenericEnvironment::getRequestUri() . '"></html><body>' . date("Y-m-d H:i:s") . "</body></html>";
    exit();
} elseif ($op == "newsdelete") {
    $sql = "DELETE FROM " . Database::prefix("news") . " WHERE newsid='" . httpget('newsid') . "'";
    Database::query($sql);
    $return = httpget('return');
    $return = cmd_sanitize($return);
    $return = basename($return);
    redirect($return);
}

page_header("Superuser Grotto");

$lines = modulehook("superuser-headlines", array());
output_notl("`c");
foreach ($lines as $line) {
    //output it like an announcement, if any argument is given, automatically(!) centered
    //ATTENTION! pre-translate your stuff in your own schema with translate_inline or Translator::getInstance()->sprintfTranslate()!
    if (is_array($line)) {
        call_user_func_array('output_notl', $line);
    } else {
        output_notl($line);
    }
    output_notl("`n`n"); //separate lines
}
output_notl("`c");

output("`^You duck into a secret cave that few know about. ");
if ($session['user']['sex'] == SEX_FEMALE) {
    output("Inside you are greeted by the sight of numerous muscular bare-chested men who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
} else {
    output("Inside you are greeted by the sight of numerous scantily clad buxom women who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
}
//comment visible only for those who are MORE than translators
//make section customizable for view / switching to different superuser chats possible
$args = modulehook("superusertop", array("section" => "superuser"));
if ($session['user']['superuser'] != SU_IS_TRANSLATOR) {
     Commentary::commentDisplay("", $args['section'], "Engage in idle conversation with other gods:", 25);
}

addnav("Actions");
if ($session['user']['superuser'] & SU_EDIT_PETITIONS) {
    addnav("Petition Viewer", "viewpetition.php");
}
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    addnav("C?Comment Moderation", "moderate.php");
}
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    addnav("B?Player Bios", "bios.php");
}
if ($session['user']['superuser'] & SU_EDIT_DONATIONS) {
    addnav("Donator Page", "donators.php");
}
if (
    file_exists("paylog.php")  &&
        ($session['user']['superuser'] & SU_EDIT_PAYLOG)
) {
    addnav("Payment Log", "paylog.php");
}
if ($session['user']['superuser'] & SU_RAW_SQL) {
    addnav("Q?Run Raw SQL", "rawsql.php");
}
if ($session['user']['superuser'] & SU_IS_TRANSLATOR) {
    addnav("U?Untranslated Texts", "untranslated.php");
}

addnav("Places");
if ($session['user']['superuser'] & SU_IS_TRANSLATOR) {
    addnav("L?Translator Lounge", "translatorlounge.php");
}


addnav("Editors");
if ($session['user']['superuser'] & SU_EDIT_USERS) {
    addnav("User Editor", "user.php");
}
if ($session['user']['superuser'] & SU_EDIT_BANS) {
    addnav("Ban Editor", "bans.php");
}

if ($session['user']['superuser'] & SU_EDIT_USERS) {
    addnav("Title Editor", "titleedit.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    addnav("E?Creature Editor", "creatures.php");
}
if ($session['user']['superuser'] & SU_EDIT_MOUNTS) {
    addnav("Mount Editor", "mounts.php");
}
if ($session['user']['superuser'] & SU_EDIT_MOUNTS) {
    addnav("Companion Editor", "companions.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    addnav("Taunt Editor", "taunt.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    addnav("Deathmessage Editor", "deathmessages.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    addnav("Master Editor", "masters.php");
}
if ($session['user']['superuser'] & SU_EDIT_EQUIPMENT) {
    addnav("Weapon Editor", "weaponeditor.php");
}
if ($session['user']['superuser'] & SU_EDIT_EQUIPMENT) {
    addnav("Armor Editor", "armoreditor.php");
}
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    addnav("Nasty Word Editor", "badword.php");
}
if ($session['user']['superuser'] & SU_MANAGE_MODULES) {
    addnav("Manage Modules", "modules.php");
}

if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    addnav("Mechanics");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    addnav("Game Settings", "configuration.php");
}
if ($session['user']['superuser'] & SU_MEGAUSER) {
    addnav("Core News", "corenews.php");
}
if ($session['user']['superuser'] & SU_MEGAUSER) {
    addnav("Global User Functions", "globaluserfunctions.php");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    addnav("Debug Analysis", "debug.php");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    addnav("Referring URLs", "referers.php");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    addnav("Stats", "stats.php");
}
/*//*/if (
    file_exists("gamelog.php") &&
/*//*/      $session['user']['superuser'] & SU_EDIT_CONFIG
) {
/*//*/  addnav("Gamelog Viewer", "gamelog.php");
/*//*/
}

if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
                    addnav('L?View Log Files', 'logviewer.php');
}

addnav("Module Configurations");

modulehook("superuser", array(), true);

page_footer();
