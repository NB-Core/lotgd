<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Translator;

Nav::add("About LoGD");
Nav::add("About LoGD", "about.php");
Nav::add("Game Setup Info", "about.php?op=setup");
Nav::add("License Info", "about.php?op=license");
$sql = "SELECT * from " . Database::prefix("modules") . " WHERE active=1 ORDER BY category,formalname";
$result = Database::query($sql);
$mname = Translator::translateInline("Module Name");
$mver = Translator::translateInline("Version");
$mauth = Translator::translateInline("Module Author");
$mdown = Translator::translateInline("Download Location");
$output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>", true);
$output->rawOutput("<tr class='trhead'><td>$mname</td><td>$mver</td><td>$mauth</td><td>$mdown</td></tr>", true);
if (Database::numRows($result) == 0) {
    $output->rawOutput("<tr class='trlight'><td colspan='4' align='center'>");
    $output->output("`i-- No modules installed --`i");
    $output->rawOutput("</td></tr>");
}
$cat = "";
$i = 0;
while ($row = Database::fetchAssoc($result)) {
    $i++;
    if ($cat != $row['category']) {
        $output->rawOutput("<tr class='trhead'><td colspan='4' align='left'>");
        $output->output($row['category']);
        $output->rawOutput(":</td></tr>");
        $cat = $row['category'];
    }

    $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
    $output->rawOutput("<td valign='top'>");
    $output->outputNotl("`&%s`0", $row['formalname']);
    $output->rawOutput("<td valign='top'>", true);
    $output->outputNotl("`^%s`0", $row['version']);
    $output->rawOutput("</td><td valign='top'>");
    $output->outputNotl("`^%s`0", $row['moduleauthor'], true);
    $output->rawOutput("</td><td nowrap valign='top'>");
    if ($row['download'] == "core_module") {
        $output->rawOutput("<a href='http://dragonprime.net/index.php?op=download;id=8' target='_blank'>");
        $output->output("Core Distribution");
        $output->rawOutput("</a>");
    } elseif ($row['download']) {
        // This will take care of download strings such as: not publically released or contact admin
        if (strpos($row['download'], "http://") === false) {
            $output->output("`\$Contact Admin for Release");
        } else {
            $output->rawOutput("<a href='{$row['download']}' target='_blank'>");
            $output->output("Download");
            $output->rawOutput("</a>");
        }
    } else {
        $output->output("`\$Not publically released.`0");
    }
    $output->rawOutput("</td>");
    $output->rawOutput("</tr>");
}
$output->rawOutput("</table>");
