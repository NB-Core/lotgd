<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\DateTime;
use Doctrine\DBAL\ParameterType;

$output = Output::getInstance();
$settings = Settings::getInstance();
$charset = $settings->getSetting('charset', 'UTF-8');

$sql = 'SELECT name,lastip,uniqueid FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . (int) $userid;
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
if (isset($row['name']) && !empty($row['name'])) {
    $output->output("Setting up ban information based on `\$%s`0", $row['name']);
}
$output->rawOutput("<form action='bans.php?op=saveban' method='POST'>");
$output->output("Set up a new ban by IP or by ID.`n");
$output->output("`qWe recommended ID as this bans all users who are sitting on THAT machine with THAT browser. A cookie can be deleted, but the char stays locked anyway, regardless of that.`n`n");
$output->output("If you ban via IP and if you have several different users behind a NAT(sharing IPs, many big providers do this currently), you will ban much more users. However, you can ban multichars from different PCs too.`n`0");
$output->rawOutput("<input type='radio' value='ip' id='ipradio' name='type'>");
$output->output("IP: ");
$output->rawOutput("<input name='ip' id='ip' value=\"" . HTMLEntities($row['lastip'] ?? "", ENT_COMPAT, $settings->getSetting("charset", "UTF-8")) . "\">");
$output->outputNotl("`n");
$output->rawOutput("<input type='radio' value='id' name='type' checked>");
$output->output("ID: ");
$output->rawOutput("<input name='id' value=\"" . HTMLEntities($row['uniqueid'] ?? "", ENT_COMPAT, $settings->getSetting("charset", "UTF-8")) . "\">");
$output->output("`nDuration: ");
$output->rawOutput("<input name='duration' id='duration' size='3' value='14'>");
$output->output("Days (0 for permanent)`n");
$reason = (string) Http::get("reason");
if ($reason == "") {
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
Nav::add("", "bans.php?op=saveban");
if (isset($row['name']) && !empty($row['name'])) {
    $conn = Database::getDoctrineConnection();
    $id = $row['uniqueid'];
    $ip = $row['lastip'];
    $name = $row['name'];
    $output->output("`0To help locate similar users to `@%s`0, here are some other users who are close:`n", $name);
    $output->output("`bSame ID (%s):`b`n", $id);
    /**
     * Stream rows instead of materialising all matches in memory.
     * This keeps legacy broad filters safer on large account tables.
     */
    $sameIdResult = $conn->executeQuery(
        'SELECT name, lastip, uniqueid, laston, gentimecount FROM ' . Database::prefix('accounts') . ' WHERE uniqueid = :uniqueid ORDER BY lastip',
        ['uniqueid' => $id],
        ['uniqueid' => ParameterType::STRING]
    );
    while (($row = $sameIdResult->fetchAssociative()) !== false) {
        $output->output(
            "`0* (%s) `%%s`0 - %s hits, last: %s`n",
            $row['lastip'],
            $row['name'],
            $row['gentimecount'],
            (
                isset($row['laston']) && is_string($row['laston']) && $row['laston'] !== ''
                    ? DateTime::relTime(strtotime($row['laston']))
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
                $output->output("IP Filter: %s ", $thisip);
                $thisIpJson = json_encode($thisip, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                $onClick = "document.getElementById('ip').value={$thisIpJson}; document.getElementById('ipradio').checked = true; return false";
                $output->rawOutput("<a href='#' onClick=\"" . HTMLEntities($onClick, ENT_QUOTES, $charset) . "\">");
                $output->output("Use this filter");
                $output->rawOutput("</a>");
                $output->outputNotl("`n");
            }

                $output->output("&nbsp;&nbsp;", true);
                $output->output(
                    "(%s) [%s] `%%s`0 - %s hits, last: %s`n",
                    $row['lastip'],
                    $row['uniqueid'],
                    $row['name'],
                    $row['gentimecount'],
                    (
                        isset($row['laston']) && is_string($row['laston']) && $row['laston'] !== ''
                            ? DateTime::relTime(strtotime($row['laston']))
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
