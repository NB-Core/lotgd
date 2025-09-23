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

// translator ready
// addnews ready
// mail ready

// Okay, someone wants to use this outside of normal game flow.. no real harm
define("OVERRIDE_FORCED_NAV", true);

// Translate Untranslated Strings
// Originally Written by Christian Rutsch
// Slightly modified by JT Traub
require_once __DIR__ . "/common.php";

SuAccess::check(SU_IS_TRANSLATOR);

Translator::getInstance()->setSchema("untranslated");

$op = Http::get('op');
Header::pageHeader("Untranslated Texts");

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
        rawoutput("<option value=\"" . htmlentities($row['namespace'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "\"" . ((htmlentities($row['namespace'], ENT_COMPAT, getsetting("charset", "UTF-8")) == $namespace) ? "selected" : "") . ">" . htmlentities($row['namespace'], ENT_COMPAT, getsetting("charset", "UTF-8")) . " ({$row['c']})</option>");
    }
    $output->rawOutput("</select>");
    $output->rawOutput("<input type='submit' class='button' value='" . Translator::translate("Show") . "'>");
    $output->rawOutput("<br>");

    if ($mode == "edit") {
        rawoutput(Translator::translate("Text:") . "<br>");
        rawoutput("<textarea name='intext' cols='60' rows='5' readonly>" . htmlentities(stripslashes(Http::get('intext')), ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br/>");
        rawoutput(Translator::translate("Translation:") . "<br>");
        rawoutput("<textarea name='outtext' cols='60' rows='5'></textarea><br/>");
        rawoutput("<input type='submit' value='" . Translator::translate("Save") . "' class='button'>");
    } else {
        rawoutput("<table border='0' cellpadding='2' cellspacing='0'>");
        rawoutput("<tr class='trhead'><td>" . Translator::translate("Ops") . "</td><td>" . Translator::translate("Text") . "</td></tr>");
        $sql = "SELECT * FROM " . Database::prefix("untranslated") . " WHERE language='" . $session['user']['prefs']['language'] . "' AND namespace='" . $namespace . "'";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $i = 0;
            while ($row = Database::fetchAssoc($result)) {
                $i++;
                rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>");
                rawoutput("<a href='untranslated.php?op=list&mode=edit&ns=" . rawurlencode($row['namespace']) . "&intext=" . rawurlencode($row['intext']) . "'>" . Translator::translate("Edit") . "</a>");
                Nav::add("", "untranslated.php?op=list&mode=edit&ns=" . rawurlencode($row['namespace']) . "&intext=" . rawurlencode($row['intext']));
                rawoutput("</td><td>");
                $output->rawOutput(htmlentities($row['intext'], ENT_COMPAT, getsetting("charset", "UTF-8")));
                $output->rawOutput("</td></tr>");
            }
        } else {
            $output->rawOutput("<tr><td colspan='2'>" . Translator::translate("No rows found") . "</td></tr>");
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
        $sql = "SELECT * FROM " . Database::prefix("untranslated") . " WHERE language = '" . $session['user']['prefs']['language'] . "' ORDER BY rand(" . e_rand() . ") LIMIT 1";
        $result = Database::query($sql);
        if (Database::numRows($result) == 1) {
            $row = Database::fetchAssoc($result);
            $row['intext'] = stripslashes($row['intext']);
            $submit = Translator::translate("Save Translation");
            $skip = Translator::translate("Skip Translation");
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
Nav::add("R?Restart Translator", "untranslated.php");
Nav::add("N?Translate by Namespace", "untranslated.php?op=list");
SuperuserNav::render();
Footer::pageFooter();
