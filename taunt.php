<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Substitute;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Translator;
use Doctrine\DBAL\ParameterType;

// addnews ready
// mail ready
// translator ready
use Lotgd\Output;

require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();
$output = Output::getInstance();
$connection = Database::getDoctrineConnection();

Translator::getInstance()->setSchema("taunt");

SuAccess::check(SU_EDIT_CREATURES);

Header::pageHeader("Taunt Editor");
SuperuserNav::render();
$op = Http::get('op');
$tauntidRequest = Http::get('tauntid');
$tauntid = taunt_normalize_optional_int($tauntidRequest);
$tauntidParam = $tauntid === null ? '' : (string) $tauntid;
if ($op == "edit") {
    Nav::add("Taunts");
    Nav::add("Return to the taunt editor", "taunt.php");
    $output->rawOutput("<form action='taunt.php?op=save&tauntid=" . rawurlencode($tauntidParam) . "' method='POST'>", true);
    Nav::add("", "taunt.php?op=save&tauntid=" . rawurlencode($tauntidParam));
    if ($tauntid !== null) {
        $result = $connection->executeQuery(
            "SELECT * FROM " . Database::prefix("taunts") . " WHERE tauntid = :tauntid",
            ['tauntid' => $tauntid],
            ['tauntid' => ParameterType::INTEGER]
        );
        $row = Database::fetchAssoc($result);
        $badguy = array(
            'creaturename' => 'Baron Munchausen',
            'creatureweapon' => 'Bad Puns',
            'diddamage' => 0,
        );
        $taunt = Substitute::applyArray($row['taunt']);
        $taunt = Translator::sprintfTranslate(...$taunt);
        $output->output("Preview: %s`0`n`n", $taunt);
    } else {
        $row = array('tauntid' => 0, 'taunt' => "");
    }
    $output->output("Taunt: ");
    $output->rawOutput("<input name='taunt' value=\"" . HTMLEntities($row['taunt'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='70'><br>");
    $output->output("The following codes are supported (case matters):`n");
    $output->output("{goodguyname}	= The player's name (also can be specified as {goodguy}`n");
    $output->output("{goodguyweapon}	= The player's weapon (also can be specified as {weapon}`n");
    $output->output("{armorname}	= The player's armor (also can be specified as {armor}`n");
    $output->output("{himher}	= Subjective pronoun for the player (him her)`n");
    $output->output("{hisher}	= Possessive pronoun for the player (his her)`n");
    $output->output("{heshe}		= Objective pronoun for the player (he she)`n");
    $output->output("{badguyname}	= The monster's name (also can be specified as {badguy}`n");
    $output->output("{badguyweapon}	= The monster's weapon (also can be specified as {creatureweapon}`n");
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'>");
    $output->rawOutput("</form>");
} elseif ($op == "del") {
    if ($tauntid === null) {
        $op = "";
        Http::set("op", "");
    } else {
        $connection->executeStatement(
            "DELETE FROM " . Database::prefix("taunts") . " WHERE tauntid = :tauntid",
            ['tauntid' => $tauntid],
            ['tauntid' => ParameterType::INTEGER]
        );
        $op = "";
        Http::set("op", "");
    }
} elseif ($op == "save") {
    $taunt = taunt_normalize_text(Http::post('taunt'));
    if ($tauntid !== null) {
        $connection->executeStatement(
            "UPDATE " . Database::prefix("taunts") . " SET taunt = :taunt, editor = :editor WHERE tauntid = :tauntid",
            [
                'taunt' => $taunt,
                'editor' => (string) $session['user']['login'],
                'tauntid' => $tauntid,
            ],
            [
                'taunt' => ParameterType::STRING,
                'editor' => ParameterType::STRING,
                'tauntid' => ParameterType::INTEGER,
            ]
        );
    } else {
        $connection->executeStatement(
            "INSERT INTO " . Database::prefix("taunts") . " (taunt, editor) VALUES (:taunt, :editor)",
            [
                'taunt' => $taunt,
                'editor' => (string) $session['user']['login'],
            ],
            [
                'taunt' => ParameterType::STRING,
                'editor' => ParameterType::STRING,
            ]
        );
    }
    $op = "";
    Http::set("op", "");
}
if ($op == "") {
    $sql = "SELECT * FROM " . Database::prefix("taunts");
    $result = Database::query($sql);
    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $op = Translator::translateInline("Ops");
    $t = Translator::translateInline("Taunt String");
    $auth = Translator::translateInline("Author");
    $output->rawOutput("<tr class='trhead'><td nowrap>$op</td><td>$t</td><td>$auth</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $output->rawOutput("<tr class='" . ($i % 2 == 0 ? "trdark" : "trlight") . "'>", true);
        $output->rawOutput("<td nowrap>");
        $edit = Translator::translateInline("Edit");
        $del = Translator::translateInline("Del");
        $conf = Translator::translateInline("Are you sure you wish to delete this taunt?");
        $id = $row['tauntid'];
        $output->rawOutput("[ <a href='taunt.php?op=edit&tauntid=$id'>$edit</a> | <a href='taunt.php?op=del&tauntid=$id' onClick='return confirm(\"$conf\");'>$del</a> ]");
        Nav::add("", "taunt.php?op=edit&tauntid=$id");
        Nav::add("", "taunt.php?op=del&tauntid=$id");
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['taunt']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['editor']);
        $output->rawOutput("</td></tr>");
    }
    Nav::add("", "taunt.php?c=" . Http::get('c'));
    $output->rawOutput("</table>");
    Nav::add("Taunts");
    Nav::add("Add a new taunt", "taunt.php?op=edit");
}

/**
 * Normalise request values expected to be optional integer identifiers.
 */
function taunt_normalize_optional_int(mixed $value): ?int
{
    if ($value === '' || $value === null || is_array($value)) {
        return null;
    }

    if (! is_scalar($value)) {
        return null;
    }

    // Accept only unsigned integer identifiers (> 0, digits only).
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_string($value)) {
        // Reject non-digit strings (e.g. "17foo", "-5", "abc").
        if (! ctype_digit($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    // Reject other scalar types such as bool and float.
    return null;
}

/**
 * Normalise request values to a safe string payload for DBAL string binding.
 */
function taunt_normalize_text(mixed $value): string
{
    // Preserve legacy coercion behavior while rejecting array/object payloads.
    if ($value === false || $value === null || is_array($value)) {
        return '';
    }

    if (is_string($value)) {
        return $value;
    }

    return is_scalar($value) ? (string) $value : '';
}
Footer::pageFooter();
