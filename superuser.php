<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\PhpGenericEnvironment;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Sanitize;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

SuAccess::check(0xFFFFFFFF & ~ SU_DOESNT_GIVE_GROTTO);
Commentary::addCommentary();
Translator::getInstance()->setSchema("superuser");

SuperuserNav::render();
Nav::add("Q?`%Quit`0 to the heavens", "login.php?op=logout", true);

$op = Http::get('op');
if ($op == "keepalive") {
    $sql = "UPDATE " . Database::prefix("accounts") . " SET laston='" . date("Y-m-d H:i:s") . "' WHERE acctid='{$session['user']['acctid']}'";
    Database::query($sql);
    echo '<html><meta http-equiv="Refresh" content="30;url=' . PhpGenericEnvironment::getRequestUri() . '"></html><body>' . date("Y-m-d H:i:s") . "</body></html>";
    exit();
} elseif ($op == "newsdelete") {
    $sql = "DELETE FROM " . Database::prefix("news") . " WHERE newsid='" . Http::get('newsid') . "'";
    Database::query($sql);
    $return = Http::get('return');
    $return = Sanitize::cmdSanitize($return);
    $return = basename($return);
    redirect($return);
}

Header::pageHeader("Superuser Grotto");

$lines = HookHandler::hook("superuser-headlines", array());
$output->outputNotl("`c");
foreach ($lines as $line) {
    //output it like an announcement, if any argument is given, automatically(!) centered
    //ATTENTION! pre-translate your stuff in your own schema with Translator::translate or Translator::getInstance()->sprintfTranslate()!
    if (is_array($line)) {
        call_user_func_array([$output, 'outputNotl'], $line);
    } else {
        $output->outputNotl($line);
    }
    $output->outputNotl("`n`n"); //separate lines
}
$output->outputNotl("`c");

$output->output("`^You duck into a secret cave that few know about. ");
if ($session['user']['sex'] == SEX_FEMALE) {
    $output->output("Inside you are greeted by the sight of numerous muscular bare-chested men who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
} else {
    $output->output("Inside you are greeted by the sight of numerous scantily clad buxom women who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
}
//comment visible only for those who are MORE than translators
//make section customizable for view / switching to different superuser chats possible
$args = HookHandler::hook("superusertop", array("section" => "superuser"));
if ($session['user']['superuser'] != SU_IS_TRANSLATOR) {
     Commentary::commentDisplay("", $args['section'], "Engage in idle conversation with other gods:", 25);
}

Nav::add("Actions");
if ($session['user']['superuser'] & SU_EDIT_PETITIONS) {
    Nav::add("Petition Viewer", "viewpetition.php");
}
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    Nav::add("C?Comment Moderation", "moderate.php");
}
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    Nav::add("B?Player Bios", "bios.php");
}
if ($session['user']['superuser'] & SU_EDIT_DONATIONS) {
    Nav::add("Donator Page", "donators.php");
}
if (
    file_exists("paylog.php")  &&
        ($session['user']['superuser'] & SU_EDIT_PAYLOG)
) {
    Nav::add("Payment Log", "paylog.php");
}
if ($session['user']['superuser'] & SU_RAW_SQL) {
    Nav::add("Q?Run Raw SQL", "rawsql.php");
}
if ($session['user']['superuser'] & SU_IS_TRANSLATOR) {
    Nav::add("U?Untranslated Texts", "untranslated.php");
}

Nav::add("Places");
if ($session['user']['superuser'] & SU_IS_TRANSLATOR) {
    Nav::add("L?Translator Lounge", "translatorlounge.php");
}


Nav::add("Editors");
if ($session['user']['superuser'] & SU_EDIT_USERS) {
    Nav::add("User Editor", "user.php");
}
if ($session['user']['superuser'] & SU_EDIT_BANS) {
    Nav::add("Ban Editor", "bans.php");
}

if ($session['user']['superuser'] & SU_EDIT_USERS) {
    Nav::add("Title Editor", "titleedit.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    Nav::add("E?Creature Editor", "creatures.php");
}
if ($session['user']['superuser'] & SU_EDIT_MOUNTS) {
    Nav::add("Mount Editor", "mounts.php");
}
if ($session['user']['superuser'] & SU_EDIT_MOUNTS) {
    Nav::add("Companion Editor", "companions.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    Nav::add("Taunt Editor", "taunt.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    Nav::add("Deathmessage Editor", "deathmessages.php");
}
if ($session['user']['superuser'] & SU_EDIT_CREATURES) {
    Nav::add("Master Editor", "masters.php");
}
if ($session['user']['superuser'] & SU_EDIT_EQUIPMENT) {
    Nav::add("Weapon Editor", "weaponeditor.php");
}
if ($session['user']['superuser'] & SU_EDIT_EQUIPMENT) {
    Nav::add("Armor Editor", "armoreditor.php");
}
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    Nav::add("Nasty Word Editor", "badword.php");
}
if ($session['user']['superuser'] & SU_MANAGE_MODULES) {
    Nav::add("Manage Modules", "modules.php");
}

if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    Nav::add("Mechanics");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    Nav::add("Game Settings", "configuration.php");
}
if ($session['user']['superuser'] & SU_MEGAUSER) {
    Nav::add("Core News", "corenews.php");
}
if ($session['user']['superuser'] & SU_MEGAUSER) {
    Nav::add("Global User Functions", "globaluserfunctions.php");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    Nav::add("Debug Analysis", "debug.php");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    Nav::add("Referring URLs", "referers.php");
}
if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
    Nav::add("Stats", "stats.php");
}
/*//*/if (
    file_exists("gamelog.php") &&
/*//*/      $session['user']['superuser'] & SU_EDIT_CONFIG
) {
/*//*/  Nav::add("Gamelog Viewer", "gamelog.php");
/*//*/
}

if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
                    Nav::add('L?View Log Files', 'logviewer.php');
}

Nav::add("Module Configurations");

HookHandler::hook("superuser", array(), true);

Footer::pageFooter();
