<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Settings;
use Lotgd\Random;

// translator ready
// addnews ready
// mail ready

// Okay, someone wants to use this outside of normal game flow.. no real harm
use Lotgd\Output;

define("OVERRIDE_FORCED_NAV", true);

// Translate Untranslated Strings
// Originally Written by Christian Rutsch
// Slightly modified by JT Traub

require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();
$output = Output::getInstance();

SuAccess::check(SU_IS_TRANSLATOR);

Translator::getInstance()->setSchema("untranslated");

$op = Http::get('op');
Header::pageHeader("Untranslated Texts");

Nav::add("Navigation");
Nav::add("Actions");

//chcek if he/she is allowed to edit that language
if (!in_array($session['user']['prefs']['language'], explode(",", $session['user']['translatorlanguages']))) {
    $output->output("Sorry, please change your language to one you are allowed to translate.`n`n");
    SuperuserNav::render();
    Footer::pageFooter();
}

if ($op == "list") {
    $mode = Http::get('mode');
    $namespace = Http::get('ns');

    if ($mode == "save") {
        $intext = Http::post('intext');
        $outtext = Http::post('outtext');
        if ($outtext <> "") {
            $login = $session['user']['login'];
            $language = $session['user']['prefs']['language'];
            $sql = "INSERT INTO " . Database::prefix("translations") . " (language,uri,intext,outtext,author,version) VALUES" . " ('$language','$namespace','$intext','$outtext','$login','$logd_version')";
            Database::query($sql);
            $sql = "DELETE FROM " . Database::prefix("untranslated") . " WHERE intext = '$intext' AND language = '$language' AND namespace = '$namespace'";
            Database::query($sql);
        }
    }

    if ($mode == "edit") {
        $output->rawOutput("<form action='untranslated.php?op=list&mode=save&ns=" . rawurlencode($namespace) . "' method='post'>");
        Nav::add("", "untranslated.php?op=list&mode=save&ns=" . rawurlencode($namespace));
    } else {
        $output->rawOutput("<form action='untranslated.php?op=list' method='get'>");
        Nav::add("", "untranslated.php?op=list");
    }

    $sql = "SELECT namespace,count(*) AS c FROM " . Database::prefix("untranslated") . " WHERE language='" . $session['user']['prefs']['language'] . "' GROUP BY namespace ORDER BY namespace ASC";
    $result = Database::query($sql);
    $output->rawOutput("<input type='hidden' name='op' value='list'>");
        $output->rawOutput("<label for='ns'>");
        $output->output("Known Namespaces:");
        $output->rawOutput("</label>");
        $output->rawOutput("<select name='ns' id='ns'>");
    while ($row = Database::fetchAssoc($result)) {
        $output->rawOutput("<option value=\"" . htmlentities($row['namespace'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"" . ((htmlentities($row['namespace'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) == $namespace) ? "selected" : "") . ">" . htmlentities($row['namespace'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . " ({$row['c']})</option>");
    }
    $output->rawOutput("</select>");
    $output->rawOutput("<input type='submit' class='button' value='" . Translator::translateInline("Show") . "'>");
    $output->rawOutput("<br>");

    if ($mode == "edit") {
        $output->rawOutput(Translator::translateInline("Text:") . "<br>");
        $intextRequest = Http::get('intext');
        $intext = is_string($intextRequest) ? stripslashes($intextRequest) : '';
        $output->rawOutput("<textarea name='intext' cols='60' rows='5' readonly>" . htmlentities($intext, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "</textarea><br/>");
        $output->rawOutput(Translator::translateInline("Translation:") . "<br>");
        $output->rawOutput("<textarea name='outtext' cols='60' rows='5'></textarea><br/>");
        $output->rawOutput("<input type='submit' value='" . Translator::translateInline("Save") . "' class='button'>");
    } else {
        $output->rawOutput("<table border='0' cellpadding='2' cellspacing='0'>");
        $output->rawOutput("<tr class='trhead'><td>" . Translator::translateInline("Ops") . "</td><td>" . Translator::translateInline("Text") . "</td></tr>");
        $sql = "SELECT * FROM " . Database::prefix("untranslated") . " WHERE language='" . $session['user']['prefs']['language'] . "' AND namespace='" . $namespace . "'";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $i = 0;
            while ($row = Database::fetchAssoc($result)) {
                $i++;
                $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>");
                $output->rawOutput("<a href='untranslated.php?op=list&mode=edit&ns=" . rawurlencode($row['namespace']) . "&intext=" . rawurlencode($row['intext']) . "'>" . Translator::translateInline("Edit") . "</a>");
                Nav::add("", "untranslated.php?op=list&mode=edit&ns=" . rawurlencode($row['namespace']) . "&intext=" . rawurlencode($row['intext']));
                $output->rawOutput("</td><td>");
                $output->rawOutput(htmlentities($row['intext'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')));
                $output->rawOutput("</td></tr>");
            }
        } else {
            $output->rawOutput("<tr><td colspan='2'>" . Translator::translateInline("No rows found") . "</td></tr>");
        }
        $output->rawOutput("</table>");
    }

    $output->rawOutput("</form>");
} else {
    if ($op == "step2") {
        $intext = Http::post('intext');
        $outtext = Http::post('outtext');
        $namespace = Http::post('namespace');
        $language = Http::post('language');
        if ($outtext <> "") {
            $login = $session['user']['login'];
            $sql = "INSERT INTO " . Database::prefix("translations") . " (language,uri,intext,outtext,author,version) VALUES" . " ('$language','$namespace','$intext','$outtext','$login','$logd_version')";
            Database::query($sql);
            $sql = "DELETE FROM " . Database::prefix("untranslated") . " WHERE intext = '$intext' AND language = '$language' AND namespace = '$namespace'";
            Database::query($sql);
            invalidatedatacache("translations-" . $namespace . "-" . $language);
        }
    }

    $sql = "SELECT count(intext) AS count FROM " . Database::prefix("untranslated");
    $count = Database::fetchAssoc(Database::query($sql));
    if ($count['count'] > 0) {
        $sql = "SELECT * FROM " . Database::prefix("untranslated") . " WHERE language = '" . $session['user']['prefs']['language'] . "' ORDER BY rand(" . Random::eRand() . ") LIMIT 1";
        $result = Database::query($sql);
        if (Database::numRows($result) == 1) {
            $row = Database::fetchAssoc($result);
            $row['intext'] = stripslashes($row['intext']);
            $submit = Translator::translateInline("Save Translation");
            $skip = Translator::translateInline("Skip Translation");
            $output->rawOutput("<form action='untranslated.php?op=step2' method='post'>");
            $output->output("`^`cThere are `&%s`^ untranslated texts in the database.`c`n`n", $count['count']);
            $output->rawOutput("<table width='80%'>");
            $output->rawOutput("<tr><td width='30%'>");
            $output->output("Target Language: %s", $row['language']);
            $output->rawOutput("</td><td></td></tr>");
            $output->rawOutput("<tr><td width='30%'>");
            $output->output("Namespace: %s", $row['namespace']);
            $output->rawOutput("</td><td></td></tr>");
            $output->rawOutput("<tr><td width='30%'><textarea cols='35' rows='4' name='intext'>" . $row['intext'] . "</textarea></td>");
            $output->rawOutput("<td width='30%'><textarea cols='25' rows='4' name='outtext'></textarea></td></tr></table>");
            $output->rawOutput("<input type='hidden' name='id' value='{$row['id']}'>");
            $output->rawOutput("<input type='hidden' name='language' value='{$row['language']}'>");
            $output->rawOutput("<input type='hidden' name='namespace' value='{$row['namespace']}'>");
            $output->rawOutput("<input type='submit' value='$submit' class='button'>");
            $output->rawOutput("</form>");
            $output->rawOutput("<form action='untranslated.php' method='post'>");
            $output->rawOutput("<input type='submit' value='$skip' class='button'>");
            $output->rawOutput("</form>");
            Nav::add("", "untranslated.php?op=step2");
            Nav::add("", "untranslated.php");
        } else {
            $output->output("There are `&%s`^ untranslated texts in the database, but none for your selected language.", $count['count']);
            $output->output("Please change your language to translate these texts.");
        }
    } else {
        $output->output("There are no untranslated texts in the database!");
        $output->output("Congratulations!!!");
    } // end if
} // end list if
Nav::add("Actions");
Nav::add("R?Restart Translator", "untranslated.php");
Nav::add("N?Translate by Namespace", "untranslated.php?op=list");
Nav::add("Navigation");
SuperuserNav::render();
Footer::pageFooter();
