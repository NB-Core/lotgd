<?php

declare(strict_types=1);

use Lotgd\Translator;

/**
 * \file badword.php
 * This file holds the Bad Word Editor for the Grotto. With this editor you can define bad words that get filtered or good words that count as exception to a rule. You have a grotto setting to turn this on or off.
 * @see grotto.php
 */

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

SuAccess::check(SU_EDIT_COMMENTS);

Translator::getInstance()->setSchema("badword");

$op = Http::get('op');
//yuck, this page is a mess, but it gets the job done.
Header::pageHeader("Bad word editor");

SuperuserNav::render();
Nav::add("Bad Word Editor");

Nav::add("Refresh the list", "badword.php");
$output->output("`7Here you can edit the words that the game filters.  Using * at the start or end of a word will be a wildcard matching anything else attached to the word.  These words are only filtered if bad word filtering is turned on in the game settings page.`n`n`0");

$test = translate_inline("Test");
$output->rawOutput("<form action='badword.php?op=test' method='POST'>");
Nav::add("", "badword.php?op=test");
$output->output("`7Test a word:`0");
$output->rawOutput("<input name='word'><input type='submit' class='button' value='$test'></form>");
if ($op == "test") {
    $word = Http::post("word");
    $return = soap($word, true);
    if ($return == $word) {
        $output->output("`7\"%s\" does not trip any filters.`0`n`n", $word);
    } else {
        $output->output("`7%s`0`n`n", $return);
    }
}

$output->outputNotl("<font size='+1'>", true);
$output->output("`7`bGood Words`b`0");
$output->rawOutput("</font>");
$output->output("`7 (bad word exceptions)`0`n");

$add = translate_inline("Add");
$remove = translate_inline("Remove");
$output->rawOutput("<form action='badword.php?op=addgood' method='POST'>");
Nav::add("", "badword.php?op=addgood");
$output->output("`7Add a word:`0");
$output->rawOutput("<input name='word'><input type='submit' class='button' value='$add'></form>");
$output->rawOutput("<form action='badword.php?op=removegood' method='POST'>");
Nav::add("", "badword.php?op=removegood");
$output->output("`7Remove a word:`0");
$output->rawOutput("<input name='word'><input type='submit' class='button' value='$remove'></form>");


$sql = "SELECT * FROM " . Database::prefix("nastywords") . " WHERE type='good'";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$words = explode(" ", $row['words']);
if ($op == "addgood") {
    $newregexp = stripslashes(Http::post('word'));

    // not sure if the line below should appear, as the strings in the good
    // word list have different behaviour than those in the nasty word list,
    // and strings with single quotes in them currently have odd and
    // unreliable behaviour, both under the good word list and the nasty
    // word list
    //  $newregexp = preg_replace('/(?<!\\\\)\'/', '\\\'', $newregexp);

    // $newregexp = str_replace("\n", '', $newregexp);
    // appears to only remove the line feed character, chr(10),
    // but leaves the carriage return character, chr(13), intact
    $newregexp = str_replace("\n", '', $newregexp);
    $newregexp = str_replace("\r", '', $newregexp);

    if ($newregexp !== '') {
        array_push($words, $newregexp);
    }

    //array_push($words,stripslashes(httppost('word')));
}
if ($op == "removegood") {
    // false if not found
    $removekey = array_search(stripslashes(Http::post('word')), $words);
    // $removekey can be 0
    if ($removekey !== false) {
        unset($words[$removekey]);
    }

    //unset($words[array_search(stripslashes(httppost('word')),$words)]);
}

show_word_list($words);
if ($op == "addgood" || $op == "removegood") {
    $sql = "DELETE FROM " . Database::prefix("nastywords") . " WHERE type='good'";
    Database::query($sql);
    $sql = "INSERT INTO " . Database::prefix("nastywords") . " (words,type) VALUES ('" . addslashes(join(" ", $words)) . "','good')";
    Database::query($sql);
    DataCache::getInstance()->invalidatedatacache("goodwordlist");
}

$output->outputNotl("`0`n`n");
$output->rawOutput("<font size='+1'>");
$output->output("`7`bNasty Words`b`0");
$output->rawOutput("</font>");
$output->outputNotl("`n");

$output->rawOutput("<form action='badword.php?op=add' method='POST'>");
Nav::add("", "badword.php?op=add");
$output->output("`7Add a word:`0");
$output->rawOutput("<input name='word'><input type='submit' class='button' value='$add'></form>");
$output->rawOutput("<form action='badword.php?op=remove' method='POST'>");
Nav::add("", "badword.php?op=remove");
$output->output("`7Remove a word:`0");
$output->rawOutput("<input name='word'><input type='submit' class='button' value='$remove'></form>");

$sql = "SELECT * FROM " . Database::prefix("nastywords") . " WHERE type='nasty'";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);

$words = explode(" ", $row['words']);
reset($words);

if ($op == "add") {
    $newregexp = stripslashes(Http::post('word'));

    // automagically escapes all unescaped single quote characters
    $newregexp = preg_replace('/(?<!\\\\)\'/', '\\\'', $newregexp);

    // $newregexp = str_replace("\n", '', $newregexp);
    // appears to only remove the line feed character, chr(10),
    // but leaves the carriage return character, chr(13), intact
    $newregexp = str_replace("\n", '', $newregexp);
    $newregexp = str_replace("\r", '', $newregexp);

    if ($newregexp !== '') {
        array_push($words, $newregexp);
    }

    //array_push($words,stripslashes(httppost('word')));
}
if ($op == "remove") {
    // false if not found
    $removekey = array_search(stripslashes(Http::post('word')), $words);
    // $removekey can be 0
    if ($removekey !== false) {
        unset($words[$removekey]);
    }

    //unset($words[array_search(stripslashes(httppost('word')),$words)]);
}
show_word_list($words);
$output->outputNotl("`0");

if ($op == "add" || $op == "remove") {
    $sql = "DELETE FROM " . Database::prefix("nastywords") . " WHERE type='nasty'";
    Database::query($sql);
    $sql = "INSERT INTO " . Database::prefix("nastywords") . " (words,type) VALUES ('" . addslashes(join(" ", $words)) . "','nasty')";
    Database::query($sql);
    DataCache::getInstance()->invalidatedatacache("nastywordlist");
}
Footer::pageFooter();

function show_word_list($words)
{
    sort($words);
    $lastletter = "";
    foreach ($words as $key => $val) {
        if (trim($val) == "") {
            unset($words[$key]);
        } else {
            if (substr($val, 0, 1) != $lastletter) {
                $lastletter = substr($val, 0, 1);
                $output->outputNotl("`n`n`^`b%s`b`@`n", strtoupper($lastletter));
            }
            $output->outputNotl("%s ", $val);
        }
    }
}
