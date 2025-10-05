<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;
use Lotgd\Settings;

// addnews ready
// translator ready
// mail ready
use Lotgd\Output;

define("OVERRIDE_FORCED_NAV", true);

require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();
$output = Output::getInstance();
Translator::getInstance()->setSchema("translatortool");

SuAccess::check(SU_IS_TRANSLATOR);
$opRequest = Http::get('op');
$op = is_string($opRequest) ? $opRequest : '';
if ($op == "") {
    popup_header("Translator Tool");
    $uriRequest = Http::get('u');
    $uri = is_string($uriRequest) ? rawurldecode($uriRequest) : '';
    $textRequest = Http::get('t');
    $text = is_string($textRequest) ? stripslashes(rawurldecode($textRequest)) : '';
    $translation = translate_loadnamespace($uri);
    if (isset($translation[$text])) {
        $trans = $translation[$text];
    } else {
        $trans = "";
    }
    $namespace = Translator::translate("Namespace:");
    $texta = Translator::translate("Text:");
    $translation = Translator::translate("Translation:");
    $saveclose = htmlentities(Translator::translate("Save & Close"), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
    $savenotclose = htmlentities(Translator::translate("Save No Close"), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
    $output->rawOutput("<form action='translatortool.php?op=save' method='POST'>");
    $output->rawOutput("$namespace <input name='uri' value=\"" . htmlentities(stripslashes($uri), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" readonly><br/>");
    $output->rawOutput("$texta<br>");
    $output->rawOutput("<textarea name='text' cols='60' rows='5' readonly>" . htmlentities($text, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "</textarea><br/>");
    $output->rawOutput("$translation<br>");
    $output->rawOutput("<textarea name='trans' cols='60' rows='5'>" . htmlentities(stripslashes($trans), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "</textarea><br/>");
    $output->rawOutput("<input type='submit' value=\"$saveclose\" class='button'>");
    $output->rawOutput("<input type='submit' value=\"$savenotclose\" class='button' name='savenotclose'>");
    $output->rawOutput("</form>");
    popup_footer();
} elseif ($op == 'save') {
    $uriPost = Http::post('uri');
    $uri = is_string($uriPost) ? $uriPost : '';
    $textPost = Http::post('text');
    $text = is_string($textPost) ? $textPost : '';
    $transPost = Http::post('trans');
    $trans = is_string($transPost) ? $transPost : '';

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
    $saveNotClosePost = Http::post('savenotclose');
    if (is_string($saveNotClosePost) && $saveNotClosePost > "") {
        header("Location: translatortool.php?op=list&u=$page");
        exit();
    } else {
        popup_header("Updated");
        $output->rawOutput("<script language='javascript'>window.close();</script>");
        popup_footer();
    }
} elseif ($op == "list") {
    popup_header("Translation List");
    $sql = "SELECT uri,count(*) AS c FROM " . Database::prefix("translations") . " WHERE language='" . LANGUAGE . "' GROUP BY uri ORDER BY uri ASC";
    $result = Database::query($sql);
    $output->outputNotl("<form action='translatortool.php' method='GET'>", true);
    $output->outputNotl("<input type='hidden' name='op' value='list'>", true);
        $output->outputNotl("<label for='u'>", true);
        $output->output("Known Namespaces:");
        $output->outputNotl("</label>", true);
        $output->outputNotl("<select name='u' id='u'>", true);
    while ($row = Database::fetchAssoc($result)) {
        $output->outputNotl("<option value=\"" . htmlentities($row['uri']) . "\">" . htmlentities($row['uri'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . " ({$row['c']})</option>", true);
    }
    $output->outputNotl("</select>", true);
    $show = Translator::translateInline("Show");
    $output->outputNotl("<input type='submit' class='button' value=\"$show\">", true);
    $output->outputNotl("</form>", true);
    $ops = Translator::translateInline("Ops");
    $from = Translator::translateInline("From");
    $to = Translator::translateInline("To");
    $version = Translator::translateInline("Version");
    $author = Translator::translateInline("Author");
    $norows = Translator::translateInline("No rows found");
    $output->outputNotl("<table border='0' cellpadding='2' cellspacing='0'>", true);
    $output->outputNotl("<tr class='trhead'><td>$ops</td><td>$from</td><td>$to</td><td>$version</td><td>$author</td></tr>", true);
    $sql = "SELECT * FROM " . Database::prefix("translations") . " WHERE language='" . LANGUAGE . "' AND uri='" . (string) Http::get('u') . "'";
    $result = Database::query($sql);
    if (Database::numRows($result) > 0) {
        $i = 0;
        while ($row = Database::fetchAssoc($result)) {
            $i++;
            $output->outputNotl("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>", true);
            $edit = Translator::translateInline("Edit");
            $output->outputNotl("<a href='translatortool.php?u=" . rawurlencode($row['uri']) . "&t=" . rawurlencode($row['intext']) . "'>$edit</a>", true);
            $output->outputNotl("</td><td>", true);
            $output->rawOutput(htmlentities($row['intext'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')));
            $output->outputNotl("</td><td>", true);
            $output->rawOutput(htmlentities($row['outtext'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')));
            $output->outputNotl("</td><td>", true);
            $output->outputNotl($row['version']);
            $output->outputNotl("</td><td>", true);
            $output->outputNotl($row['author']);
            $output->outputNotl("</td></tr>", true);
        }
    } else {
        $output->outputNotl("<tr><td colspan='5'>$norows</td></tr>", true);
    }
    $output->outputNotl("</table>", true);
    popup_footer();
}
