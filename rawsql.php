<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// translator ready
// addnews ready
// mail ready
require_once 'common.php';

Translator::getInstance()->setSchema("rawsql");

SuAccess::check(SU_RAW_SQL);

Header::pageHeader('Raw SQL/PHP execution');
SuperuserNav::render();
Nav::add('Execution');
Nav::add('SQL', 'rawsql.php');
Nav::add('PHP', 'rawsql.php?op=php');

$op = (string) Http::get('op');
if ($op == "" || $op == "sql") {
    $sql = (string) Http::post('sql');
    if ($sql != "") {
        $sql = stripslashes($sql);
        modulehook("rawsql-execsql", array("sql" => $sql));
        $r = Database::query($sql, false);
        if (!$r) {
            $output->output("`\$SQL Error:`& %s`0`n`n", Database::error($r));
        } else {
            if (Database::affectedRows() > 0) {
                $output->output("`&%s rows affected.`n`n", Database::affectedRows());
            } else {
                $output->output("No rows have been changed.`n`n");
            }
            $output->rawOutput("<table cellspacing='1' cellpadding='2' border='0' bgcolor='#999999'>");
            if ($r !== true) {
                // if $r===true, it was an UPDATE or DELETE statement, which obviously has no result lines
                $number = Database::numRows($r);
                for ($i = 0; $i < $number; $i++) {
                    $row = Database::fetchAssoc($r);
                    if ($i == 0) {
                        $output->rawOutput("<tr class='trhead'>");
                        $keys = array_keys($row);
                        foreach ($keys as $value) {
                            $output->rawOutput("<td>$value</td>");
                        }
                        $output->rawOutput("</tr>");
                    }
                    $output->rawOutput("<tr class='" . ($i % 2 == 0 ? "trlight" : "trdark") . "'>");
                    foreach ($keys as $value) {
                        $output->rawOutput("<td valign='top'>{$row[$value]}</td>");
                    }
                    $output->rawOutput("</tr>");
                }
            }
            $output->rawOutput("</table>");
        }
    }

    $output->output("Type your query");
    $execute = translate_inline("Execute");
    $ret = modulehook("rawsql-modsql", array("sql" => $sql));
    $sql = $ret['sql'];
    $output->rawOutput("<form action='rawsql.php' method='post'>");
    $output->rawOutput("<textarea name='sql' class='input' cols='60' rows='10'>" . htmlentities($sql, ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br>");
    $output->rawOutput("<input type='submit' class='button' value='$execute'>");
    $output->rawOutput("</form>");
    Nav::add('', 'rawsql.php');
} else {
    $php = stripslashes((string) Http::post('php'));
    $source = translate_inline("Source:");
    $execute = translate_inline("Execute");
    if ($php > "") {
        $output->rawOutput("<div style='background-color: #FFFFFF; color: #000000; width: 100%'><b>$source</b><br>");
        $output->rawOutput(highlight_string("<?php\n$php\n?>", true));
        $output->rawOutput("</div>");
        $output->output("`bResults:`b`n");
        modulehook("rawsql-execphp", array("php" => $php));
        ob_start();
        eval($php);
        $output->output(ob_get_contents(), true);
        ob_end_clean();
    }
    $output->output("`n`nType your code:");
    $ret = modulehook("rawsql-modphp", array("php" => $php));
    $php = $ret['php'];
    $output->rawOutput("<form action='rawsql.php?op=php' method='post'>");
    $output->rawOutput("&lt;?php<br><textarea name='php' class='input' cols='60' rows='10'>" . htmlentities($php, ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea><br>?&gt;<br>");
    $output->rawOutput("<input type='submit' class='button' value='$execute'>");
    $output->rawOutput("</form>");
    Nav::add('', 'rawsql.php?op=php');
}
Footer::pageFooter();
