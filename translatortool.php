<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;

// addnews ready
// translator ready
// mail ready
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";
Translator::getInstance()->setSchema("translatortool");

SuAccess::check(SU_IS_TRANSLATOR);
$op = (string) Http::get('op');
if ($op == "") {
    popup_header("Translator Tool");
    $uri = rawurldecode((string) Http::get('u'));
    $text = stripslashes(rawurldecode((string) Http::get('t')));
    $translation = translate_loadnamespace($uri);
    if (isset($translation[$text])) {
        $trans = $translation[$text];
    } else {
        $trans = "";
    }
    $namespace = Translator::translate("Namespace:");
    $texta = Translator::translate("Text:");
    $translation = Translator::translate("Translation:");
    $saveclose = htmlentities(Translator::translate("Save & Close"), ENT_COMPAT, getsetting("charset", "UTF-8"));
    $savenotclose = htmlentities(Translator::translate("Save No Close"), ENT_COMPAT, getsetting("charset", "UTF-8"));
    rawoutput("<form action='translatortool.php?op=save' method='POST'>");
    rawoutput("$namespace <input name='uri' value=\"" . htmlentities(stripslashes($uri), ENT_COMPAT, getsetting("charset", "UTF-8")) . "\" readonly><br/>");
    rawoutput("$texta<br>");
    rawoutput("<textarea name='text' cols='60' rows='5' readonly>" . htmlentities($text, ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br/>");
    rawoutput("$translation<br>");
    rawoutput("<textarea name='trans' cols='60' rows='5'>" . htmlentities(stripslashes($trans), ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br/>");
    rawoutput("<input type='submit' value=\"$saveclose\" class='button'>");
    rawoutput("<input type='submit' value=\"$savenotclose\" class='button' name='savenotclose'>");
    rawoutput("</form>");
    popup_footer();
} elseif ($op == 'save') {
    $uri = (string) Http::post('uri');
    $text = (string) Http::post('text');
    $trans = (string) Http::post('trans');

    $page = $uri;
    if (strpos($page, "?") !== false) {
        $page = substr($page, 0, strpos($page, "?"));
    }

    if ($trans == "") {
        $sql = "DELETE ";
    } else {
        $sql = "SELECT * ";
    }
    $sql .= "
		FROM " . Database::prefix("translations") . "
		WHERE language='" . LANGUAGE . "'
			AND intext='$text'
			AND (uri='$page' OR uri='$uri')";
    if ($trans > "") {
        $result = Database::query($sql);
        invalidatedatacache("translations-" . $uri . "-" . $language);
        if (Database::numRows($result) == 0) {
            $sql = "INSERT INTO " . Database::prefix("translations") . " (language,uri,intext,outtext,author,version) VALUES ('" . LANGUAGE . "','$uri','$text','$trans','{$session['user']['login']}','$logd_version ')";
            $sql1 = "DELETE FROM " . Database::prefix("untranslated") .
                " WHERE intext='$text' AND language='" . LANGUAGE .
                "' AND namespace='$url'";
            Database::query($sql1);
        } elseif (Database::numRows($result) == 1) {
            $row = Database::fetchAssoc($result);
            // MySQL is case insensitive so we need to do it here.
            if ($row['intext'] == $text) {
                $sql = "UPDATE " . Database::prefix("translations") . " SET author='{$session['user']['login']}', version='$logd_version', uri='$uri', outtext='$trans' WHERE tid={$row['tid']}";
            } else {
                $sql = "INSERT INTO " . Database::prefix("translations") . " (language,uri,intext,outtext,author,version) VALUES ('" . LANGUAGE . "','$uri','$text','$trans','{$session['user']['login']}','$logd_version ')";
                $sql1 = "DELETE FROM " . Database::prefix("untranslated") . " WHERE intext='$text' AND language='" . LANGUAGE . "' AND namespace='$url'";
                Database::query($sql1);
            }
        } elseif (Database::numRows($result) > 1) {
        /* To say the least, this case is bad. Simply because if there are duplicates, you make them even more equal. But most likely you won't get this far, as the code itself should not produce duplicates unless you insert manually or via module the same row more than once*/
            $rows = array();
            while ($row = Database::fetchAssoc($result)) {
                // MySQL is case insensitive so we need to do it here.
                if ($row['intext'] == $text) {
                    $rows[] = $row['tid'];
                }
            }
            $sql = "UPDATE " . Database::prefix("translations") . " SET author='{$session['user']['login']}', version='$logd_version', uri='$page', outtext='$trans' WHERE tid IN (" . join(",", $rows) . ")";
        }
    }
    Database::query($sql);
    if ((string) Http::post('savenotclose') > "") {
        header("Location: translatortool.php?op=list&u=$page");
        exit();
    } else {
        popup_header("Updated");
        rawoutput("<script language='javascript'>window.close();</script>");
        popup_footer();
    }
} elseif ($op == "list") {
    popup_header("Translation List");
    $sql = "SELECT uri,count(*) AS c FROM " . Database::prefix("translations") . " WHERE language='" . LANGUAGE . "' GROUP BY uri ORDER BY uri ASC";
    $result = Database::query($sql);
    output_notl("<form action='translatortool.php' method='GET'>", true);
    output_notl("<input type='hidden' name='op' value='list'>", true);
        output_notl("<label for='u'>", true);
        output("Known Namespaces:");
        output_notl("</label>", true);
        output_notl("<select name='u' id='u'>", true);
    while ($row = Database::fetchAssoc($result)) {
        output_notl("<option value=\"" . htmlentities($row['uri']) . "\">" . htmlentities($row['uri'], ENT_COMPAT, getsetting("charset", "UTF-8")) . " ({$row['c']})</option>", true);
    }
    output_notl("</select>", true);
    $show = Translator::translate("Show");
    output_notl("<input type='submit' class='button' value=\"$show\">", true);
    output_notl("</form>", true);
    $ops = Translator::translate("Ops");
    $from = Translator::translate("From");
    $to = Translator::translate("To");
    $version = Translator::translate("Version");
    $author = Translator::translate("Author");
    $norows = Translator::translate("No rows found");
    output_notl("<table border='0' cellpadding='2' cellspacing='0'>", true);
    output_notl("<tr class='trhead'><td>$ops</td><td>$from</td><td>$to</td><td>$version</td><td>$author</td></tr>", true);
    $sql = "SELECT * FROM " . Database::prefix("translations") . " WHERE language='" . LANGUAGE . "' AND uri='" . (string) Http::get('u') . "'";
    $result = Database::query($sql);
    if (Database::numRows($result) > 0) {
        $i = 0;
        while ($row = Database::fetchAssoc($result)) {
            $i++;
            output_notl("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>", true);
            $edit = Translator::translate("Edit");
            output_notl("<a href='translatortool.php?u=" . rawurlencode($row['uri']) . "&t=" . rawurlencode($row['intext']) . "'>$edit</a>", true);
            output_notl("</td><td>", true);
            rawoutput(htmlentities($row['intext'], ENT_COMPAT, getsetting("charset", "UTF-8")));
            output_notl("</td><td>", true);
            rawoutput(htmlentities($row['outtext'], ENT_COMPAT, getsetting("charset", "UTF-8")));
            output_notl("</td><td>", true);
            output_notl($row['version']);
            output_notl("</td><td>", true);
            output_notl($row['author']);
            output_notl("</td></tr>", true);
        }
    } else {
        output_notl("<tr><td colspan='5'>$norows</td></tr>", true);
    }
    output_notl("</table>", true);
    popup_footer();
}
