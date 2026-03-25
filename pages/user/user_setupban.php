<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Http;
use Lotgd\Sanitize;
use Doctrine\DBAL\ParameterType;

global $session;

$sql = "SELECT name,lastip,uniqueid FROM " . Database::prefix("accounts") . " WHERE acctid=\"$userid\"";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$output = Output::getInstance();
$settings = Settings::getInstance();
$charset = $settings->getSetting('charset', 'UTF-8');
if ($row['name'] != "") {
    $output->output("Setting up ban information based on `\$%s`0", $row['name']);
}
$output->rawOutput("<form action='user.php?op=saveban' method='POST'>");
$output->output("Set up a new ban by IP or by ID (recommended IP, though if you have several different users behind a NAT, you can try ID which is easily defeated)`n");
$output->rawOutput("<input type='radio' value='ip' id='ipradio' name='type' checked>");
$output->output("IP: ");
$output->rawOutput("<input name='ip' id='ip' value=\"" . HTMLEntities($row['lastip'], ENT_COMPAT, $charset) . "\">");
$output->outputNotl("`n");
$output->rawOutput("<input type='radio' value='id' name='type'>");
$output->output("ID: ");
$output->rawOutput("<input name='id' value=\"" . HTMLEntities($row['uniqueid'], ENT_COMPAT, $charset) . "\">");
$output->output("`nDuration: ");
$output->rawOutput("<input name='duration' id='duration' size='3' value='14'>");
$output->output("Days (0 for permanent)`n");
$commentId = (int) Http::get('commentid');
$reason = '';

if ($commentId > 0) {
    $reason = $session['moderation']['ban_reasons'][$commentId] ?? '';

    if ($reason == '') {
        $commentTable = Database::prefix('commentary');
        $accountsTable = Database::prefix('accounts');
        $sqlComment = "SELECT {$commentTable}.comment, {$accountsTable}.name FROM {$commentTable} LEFT JOIN {$accountsTable} ON {$accountsTable}.acctid = {$commentTable}.author WHERE {$commentTable}.commentid = $commentId";
        $commentResult = Database::query($sqlComment);
        if (is_array($commentResult) || is_object($commentResult)) {
            $commentRow = Database::fetchAssoc($commentResult);
            Database::freeResult($commentResult);
        } else {
            $commentRow = null;
        }

        if ($commentRow) {
            $name = $commentRow['name'] ?? '';
            if ($name === '') {
                $name = Translator::translateInline('Someone');
            }

            $commentText = HTMLEntities($commentRow['comment'] ?? '', ENT_COMPAT, $charset);
            $commentText = str_replace('&amp;', '&', $commentText);
            $compiled = "`&{$name}`3 says, \"`#{$commentText}`3\"`0`n";
            $compiled = Sanitize::fullSanitize($compiled);
            $reason = htmlentities($compiled, ENT_QUOTES, $charset);
        }
    }
}

if ($reason == '') {
    $reason = Translator::translateInline("Don't mess with me.");
}
$output->output("Reason for the ban: ");
$output->rawOutput("<input name='reason' size=50 value=\"$reason\">");
$output->outputNotl("`n");
$pban = Translator::translateInline("Post ban");
$conf = Translator::translateInline("Are you sure you wish to issue a permanent ban?");
$output->rawOutput("<input type='submit' class='button' value='$pban' onClick='if (document.getElementById(\"duration\").value==0) {return confirm(\"$conf\");} else {return true;}'>");
$output->rawOutput("</form>");
$output->output("For an IP ban, enter the beginning part of the IP you wish to ban if you wish to ban a range, or simply a full IP to ban a single IP`n`n");
Nav::add("", "user.php?op=saveban");
if ($row['name'] != "") {
    $conn = Database::getDoctrineConnection();
    $id = $row['uniqueid'];
    $ip = $row['lastip'];
    $name = $row['name'];
    $output->output("`0To help locate similar users to `@%s`0, here are some other users who are close:`n", $name);
    $output->output("`bSame ID (%s):`b`n", $id);
    /**
     * Stream rows instead of fetching everything at once so broad matches
     * remain predictable on larger installs.
     */
    $sameIdResult = $conn->executeQuery(
        'SELECT name, lastip, uniqueid, laston, gentimecount FROM ' . Database::prefix('accounts') . ' WHERE uniqueid = :uniqueid ORDER BY lastip',
        ['uniqueid' => $id],
        ['uniqueid' => ParameterType::STRING]
    );
    while (($row = $sameIdResult->fetchAssociative()) !== false) {
        $output->output(
            "`0• (%s) `%%s`0 - %s hits, last: %s`n",
            $row['lastip'],
            $row['name'],
            $row['gentimecount'],
            (
                isset($row['laston']) && is_string($row['laston']) && $row['laston'] !== ''
                    ? reltime(strtotime($row['laston']))
                    : Translator::translateInline('unknown')
            )
        );
    }
    $sameIdResult->free();
    $output->outputNotl("`n");
        $oip = "";
    $dots = 0;
    $output->output("`bSimilar IP's`b`n");
    for ($x = strlen($ip); $x > 0; $x--) {
        if ($dots > 1) {
            break;
        }
        $thisip = substr($ip, 0, $x);
        $similarIpResult = $conn->executeQuery(
            'SELECT name, lastip, uniqueid, laston, gentimecount FROM ' . Database::prefix('accounts') . ' WHERE lastip LIKE :thisIp AND NOT (lastip LIKE :oldIp) ORDER BY uniqueid',
            [
                'thisIp' => $thisip . '%',
                'oldIp' => $oip,
            ],
            [
                'thisIp' => ParameterType::STRING,
                'oldIp' => ParameterType::STRING,
            ]
        );

        $hasRows = false;
        while (($row = $similarIpResult->fetchAssociative()) !== false) {
            if (!$hasRows) {
                $hasRows = true;
                $output->output("• IP Filter: %s ", $thisip);
                $thisIpJson = json_encode($thisip, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                $onClick = "document.getElementById('ip').value={$thisIpJson}; document.getElementById('ipradio').checked = true; return false";
                $output->rawOutput("<a href='#' onClick=\"" . HTMLEntities($onClick, ENT_QUOTES, $charset) . "\">");
                $output->output("Use this filter");
                $output->rawOutput("</a>");
                $output->outputNotl("`n");
            }

                $output->output("&nbsp;&nbsp;", true);
                $output->output(
                    "• (%s) [%s] `%%s`0 - %s hits, last: %s`n",
                    $row['lastip'],
                    $row['uniqueid'],
                    $row['name'],
                    $row['gentimecount'],
                    (
                        isset($row['laston']) && is_string($row['laston']) && $row['laston'] !== ''
                            ? reltime(strtotime($row['laston']))
                            : Translator::translateInline('unknown')
                    )
                );
        }
        $similarIpResult->free();

        if ($hasRows) {
            $output->outputNotl("`n");
        }
        if (substr($ip, $x - 1, 1) == ".") {
            $x--;
            $dots++;
        }
        $oip = $thisip . "%";
    }
}
