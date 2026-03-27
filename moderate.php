<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\Moderate;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Sanitize;
use Lotgd\DataCache;
use Lotgd\Redirect;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";


$output = Output::getInstance();
$settings = Settings::getInstance();

$translator = Translator::getInstance();

$translator->setSchema("moderate");

Commentary::addCommentary();

SuAccess::check(SU_EDIT_COMMENTS);

SuperuserNav::render();

Nav::add("Other");
Nav::add("Commentary Overview", "moderate.php");
Nav::add("Reset Seen Comments", "moderate.php?seen=" . rawurlencode(date("Y-m-d H:i:s")));
Nav::add("B?Player Bios", "bios.php");
if ($session['user']['superuser'] & SU_AUDIT_MODERATION) {
    Nav::add("Audit Moderation", "moderate.php?op=audit");
}
Nav::add("Review by Moderator");
Nav::add("Commentary");
Nav::add("Sections");
Nav::add("Modules");
Nav::add("Clan Halls");

$op = Http::get("op");
if ($op == "commentdelete") {
    $comment = Http::post('comment');
    $conn = Database::getDoctrineConnection();
    $commentaryTable = Database::prefix('commentary');
    $accountsTable = Database::prefix('accounts');
    $clansTable = Database::prefix('clans');
    $moderatedCommentsTable = Database::prefix('moderatedcomments');

    if (Http::post('delnban') > '') {
        $commentIds = array_keys($comment ?? []);
        $commentIds = array_map(static fn ($value): int => (int) $value, $commentIds);

        if ($commentIds !== []) {
            $bansTable = Database::prefix('bans');

            $rows = $conn->fetchAllAssociative(
                "SELECT DISTINCT a.acctid AS author, a.uniqueid, a.lastip FROM {$commentaryTable} c INNER JOIN {$accountsTable} a ON a.acctid = c.author WHERE c.commentid IN (?)",
                [$commentIds],
                [ArrayParameterType::INTEGER]
            );

            $untildate = date("Y-m-d H:i:s", strtotime("+3 days"));
            $reason = (string) Http::post('reason');
            $reason0 = (string) Http::post('reason0');
            $default = "Banned for comments you posted.";

            if ($reason0 !== $reason && $reason0 !== $default) {
                $reason = $reason0;
            }

            if ($reason === '') {
                $reason = $default;
            }

            $banParameters = [
                'banexpire' => $untildate,
                'banreason' => $reason,
                'banner'    => (string) ($session['user']['name'] ?? ''),
            ];

            $authorsToLogout = [];
            $inserted = 0;

            foreach ($rows as $row) {
                $uniqueId = (string) ($row['uniqueid'] ?? '');
                $ipFilter = (string) ($row['lastip'] ?? '');

                if ($uniqueId === '' && $ipFilter === '') {
                    continue;
                }

                $existing = $conn->fetchAssociative(
                    "SELECT banexpire FROM {$bansTable} WHERE (:uniqueid <> '' AND uniqueid = :uniqueid) OR (:ipfilter <> '' AND ipfilter = :ipfilter) ORDER BY banexpire DESC LIMIT 1",
                    [
                        'uniqueid' => $uniqueId,
                        'ipfilter' => $ipFilter,
                    ],
                    [
                        'uniqueid' => ParameterType::STRING,
                        'ipfilter' => ParameterType::STRING,
                    ]
                );

                if ($existing && $existing['banexpire'] >= $untildate) {
                    continue;
                }

                $banInsertParameters = $banParameters + [
                    'ipfilter' => $ipFilter,
                    'uniqueid' => $uniqueId,
                ];

                $inserted += $conn->executeStatement(
                    "INSERT INTO {$bansTable} (ipfilter, uniqueid, banexpire, banreason, banner) VALUES (:ipfilter, :uniqueid, :banexpire, :banreason, :banner)",
                    $banInsertParameters,
                    [
                        'ipfilter'  => ParameterType::STRING,
                        'uniqueid'  => ParameterType::STRING,
                        'banexpire' => ParameterType::STRING,
                        'banreason' => ParameterType::STRING,
                        'banner'    => ParameterType::STRING,
                    ]
                );

                $authorsToLogout[] = (int) $row['author'];
            }

            if ($inserted > 0) {
                Database::setAffectedRows($inserted);
            }

            if ($authorsToLogout !== []) {
                $authorsToLogout = array_values(array_unique($authorsToLogout));
                $affected = $conn->executeStatement(
                    "UPDATE {$accountsTable} SET loggedin = 0 WHERE acctid IN (?)",
                    [$authorsToLogout],
                    [ArrayParameterType::INTEGER]
                );

                Database::setAffectedRows($affected);
            }
        }
    }

    if (!isset($comment) || !is_array($comment)) {
        $comment = array();
    }
    $commentIds = moderateNormalizeIntegerKeys($comment);
    $invalsections = array();
    if ($commentIds !== []) {
        $rows = $conn->fetchAllAssociative(
            "SELECT c.*, a.name, a.login, a.clanrank, cl.clanshort
                FROM {$commentaryTable} c
                INNER JOIN {$accountsTable} a ON a.acctid = c.author
                LEFT JOIN {$clansTable} cl ON cl.clanid = a.clanid
                WHERE c.commentid IN (?)",
            [$commentIds],
            [ArrayParameterType::INTEGER]
        );

        foreach ($rows as $row) {
            $conn->executeStatement(
                "INSERT LOW_PRIORITY INTO {$moderatedCommentsTable} (moderator, moddate, comment) VALUES (:moderator, :moddate, :comment)",
                [
                    'moderator' => (int) $session['user']['acctid'],
                    'moddate'   => date("Y-m-d H:i:s"),
                    'comment'   => serialize($row),
                ],
                [
                    'moderator' => ParameterType::INTEGER,
                    'moddate'   => ParameterType::STRING,
                    'comment'   => ParameterType::STRING,
                ]
            );
            $invalsections[$row['section']] = 1;
        }

        $conn->executeStatement(
            "DELETE FROM {$commentaryTable} WHERE commentid IN (?)",
            [$commentIds],
            [ArrayParameterType::INTEGER]
        );
    }

    $return = Http::get('return');
    $return = Sanitize::cmdSanitize($return);
    $return = basename($return);
    if (strpos($return, "?") === false && strpos($return, "&") !== false) {
        $x = strpos($return, "&");
        $return = substr($return, 0, $x - 1) . "?" . substr($return, $x + 1);
    }
    foreach ($invalsections as $key => $dummy) {
        DataCache::getInstance()->invalidatedatacache("comments-$key");
    }
    //update moderation cache
    DataCache::getInstance()->invalidatedatacache("comments-or11");
    Redirect::redirect($return);
}

$seen = Http::get("seen");
if ($seen > "") {
    $session['user']['recentcomments'] = $seen;
}

Header::pageHeader("Comment Moderation");


if ($op == "") {
    $area = Http::get('area');
    $link = "moderate.php" . ($area ? "?area=$area" : "");
    $refresh = Translator::translateInline("Refresh");
    $output->rawOutput("<form action='$link' method='POST'>");
    $output->rawOutput("<input type='submit' class='button' value='$refresh'>");
    $output->rawOutput("</form>");
    Nav::add("", "$link");
    if ($area == "") {
        Commentary::talkForm("X", "says");
        //commentdisplay("", "' or '1'='1","X",100); //sure, encourage hacking...
        Moderate::commentmoderate('', '', 'X', 100, 'says', null, true);
    } else {
        Moderate::commentmoderate("", $area, "X", 100);
        Commentary::talkForm($area, "says");
    }
} elseif ($op == "audit") {
    $subop = Http::get("subop");
    if ($subop == "undelete") {
        $unkeys = Http::post("mod");
        if ($unkeys && is_array($unkeys)) {
            $modIds = moderateNormalizeIntegerKeys($unkeys);
            $conn = Database::getDoctrineConnection();
            $moderatedCommentsTable = Database::prefix('moderatedcomments');
            $commentaryTable = Database::prefix('commentary');

            $rows = [];
            if ($modIds !== []) {
                $rows = $conn->fetchAllAssociative(
                    "SELECT * FROM {$moderatedCommentsTable} WHERE modid IN (?)",
                    [$modIds],
                    [ArrayParameterType::INTEGER]
                );
            }

            foreach ($rows as $row) {
                $comment = unserialize($row['comment'], ['allowed_classes' => false]);
                if (!is_array($comment)) {
                    continue;
                }

                $section = (string) ($comment['section'] ?? '');
                $conn->executeStatement(
                    "INSERT LOW_PRIORITY INTO {$commentaryTable} (commentid, postdate, section, author, comment) VALUES (:commentid, :postdate, :section, :author, :comment)",
                    [
                        'commentid' => (int) ($comment['commentid'] ?? 0),
                        'postdate'  => (string) ($comment['postdate'] ?? ''),
                        'section'   => $section,
                        'author'    => (int) ($comment['author'] ?? 0),
                        'comment'   => (string) ($comment['comment'] ?? ''),
                    ],
                    [
                        'commentid' => ParameterType::INTEGER,
                        'postdate'  => ParameterType::STRING,
                        'section'   => ParameterType::STRING,
                        'author'    => ParameterType::INTEGER,
                        'comment'   => ParameterType::STRING,
                    ]
                );
                DataCache::getInstance()->invalidatedatacache("comments-{$section}");
            }

            if ($modIds !== []) {
                $conn->executeStatement(
                    "DELETE FROM {$moderatedCommentsTable} WHERE modid IN (?)",
                    [$modIds],
                    [ArrayParameterType::INTEGER]
                );
            }
        } else {
            $output->output("No items selected to undelete -- Please try again`n`n");
        }
    }
    $sql = "SELECT DISTINCT acctid, name FROM " . Database::prefix("accounts") .
        " INNER JOIN " . Database::prefix("moderatedcomments") .
        " ON acctid=moderator ORDER BY name";
    $result = Database::query($sql);
    Nav::add("Commentary");
    Nav::add("Sections");
    Nav::add("Modules");
    Nav::add("Clan Halls");
    Nav::add("Review by Moderator");
    $translator->setSchema("notranslate");
    while ($row = Database::fetchAssoc($result)) {
        Nav::add(" ?" . $row['name'], "moderate.php?op=audit&moderator={$row['acctid']}");
    }
    $translator->setSchema();
    Nav::add("Commentary");
    $output->output("`c`bComment Auditing`b`c");
    $ops = Translator::translateInline("Ops");
    $mod = Translator::translateInline("Moderator");
    $when = Translator::translateInline("When");
    $com = Translator::translateInline("Comment");
    $unmod = Translator::translateInline("Unmoderate");
    $output->rawOutput("<form action='moderate.php?op=audit&subop=undelete' method='POST'>");
    Nav::add("", "moderate.php?op=audit&subop=undelete");
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='0'>");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$mod</td><td>$when</td><td>$com</td></tr>");
    $limit = "75";
    $where = "1=1 ";
    $moderator = Http::get("moderator");
    if ($moderator > "") {
        $where .= "AND moderator=$moderator ";
    }
    $sql = "SELECT name, " . Database::prefix("moderatedcomments") .
        ".* FROM " . Database::prefix("moderatedcomments") . " LEFT JOIN " .
        Database::prefix("accounts") .
        " ON acctid=moderator WHERE $where ORDER BY moddate DESC LIMIT $limit";
    $result = Database::query($sql);
    $i = 0;
    $clanrankcolors = array("`!","`#","`^","`&","\$");
    while ($row = Database::fetchAssoc($result)) {
        $i++;
        $output->rawOutput("<tr class='" . ($i % 2 ? 'trlight' : 'trdark') . "'>");
        $output->rawOutput("<td><input type='checkbox' name='mod[{$row['modid']}]' value='1'></td>");
        $output->rawOutput("<td>");
        $output->outputNotl("%s", $row['name']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $output->outputNotl("%s", $row['moddate']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $comment = unserialize($row['comment'], ['allowed_classes' => false]);
        if (!is_array($comment) || !isset($comment['section'])) {
            $output->output("---no comment found---");
            $output->rawOutput("</td>");
            $output->rawOutput("</tr>");
            continue; //whatever we did here
        }
        $output->outputNotl("`0(%s)", $comment['section']);

        if ($comment['clanrank'] > 0) {
            $output->outputNotl(
                "%s<%s%s>`0",
                $clanrankcolors[ceil($comment['clanrank'] / 10)],
                $comment['clanshort'],
                $clanrankcolors[ceil($comment['clanrank'] / 10)]
            );
        }
        $output->outputNotl("%s", $comment['name']);
        $output->outputNotl("-");
        $output->outputNotl("%s", Sanitize::commentSanitize($comment['comment']));
        $output->rawOutput("</td>");
        $output->rawOutput("</tr>");
    }
    $output->rawOutput("</table>");
    $output->rawOutput("<input type='submit' class='button' value='$unmod'>");
    $output->rawOutput("</form>");
}


Nav::add("Sections");
$translator->setSchema("commentary");
$vname = $settings->getSetting('villagename', LOCATION_FIELDS);
Nav::add(array("%s Square", $vname), "moderate.php?area=village");

if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
    Nav::add("Grotto", "moderate.php?area=superuser");
}

Nav::add("Land of the Shades", "moderate.php?area=shade");
Nav::add("Grassy Field", "moderate.php?area=grassyfield");

$iname = $settings->getSetting('innname', LOCATION_INN);
// the inn name is a proper name and shouldn't be translated.
$translator->setSchema("notranslate");
Nav::add($iname, "moderate.php?area=inn");
$translator->setSchema();

Nav::add("MotD", "moderate.php?area=motd");
Nav::add("Veterans Club", "moderate.php?area=veterans");
Nav::add("Hunter's Lodge", "moderate.php?area=hunterlodge");
Nav::add("Gardens", "moderate.php?area=gardens");
Nav::add("Clan Hall Waiting Area", "moderate.php?area=waiting");

if ($settings->getSetting('betaperplayer', 1) == 1 && @file_exists("pavilion.php")) {
    Nav::add("Beta Pavilion", "moderate.php?area=beta");
}
$translator->setSchema();

if ($session['user']['superuser'] & SU_MODERATE_CLANS) {
    Nav::add("Clan Halls");
    $sql = "SELECT clanid,clanname,clanshort FROM " . Database::prefix("clans") . " ORDER BY clanid";
    $result = Database::query($sql);
    // these are proper names and shouldn't be translated.
    $translator->setSchema("notranslate");
    while ($row = Database::fetchAssoc($result)) {
        Nav::add(
            array("<%s> %s", $row['clanshort'], $row['clanname']),
            "moderate.php?area=clan-{$row['clanid']}"
        );
    }
    $translator->setSchema();
} elseif (
    $session['user']['superuser'] & SU_EDIT_COMMENTS &&
        $settings->getSetting('officermoderate', 0)
) {
    // the CLAN_OFFICER requirement was chosen so that moderators couldn't
    // just get accepted as a member to any random clan and then proceed to
    // wreak havoc.
    // although this isn't really a big deal on most servers, the choice was
    // made so that staff won't have to have another issue to take into
    // consideration when choosing moderators.  the issue is moot in most
    // cases, as players that are trusted with moderator powers are also
    // often trusted with at least the rank of officer in their respective
    // clans.
    if (
        ($session['user']['clanid'] != 0) &&
            ($session['user']['clanrank'] >= CLAN_OFFICER)
    ) {
        Nav::add("Clan Halls");
        $sql = "SELECT clanid,clanname,clanshort FROM " . Database::prefix("clans") . " WHERE clanid='" . $session['user']['clanid'] . "'";
        $result = Database::query($sql);
        // these are proper names and shouldn't be translated.
        $translator->setSchema("notranslate");
        if ($row = Database::fetchAssoc($result)) {
            Nav::add(
                array("<%s> %s", $row['clanshort'], $row['clanname']),
                "moderate.php?area=clan-{$row['clanid']}"
            );
        } else {
            debug("There was an error while trying to access your clan.");
        }
        $translator->setSchema();
    }
}
Nav::add("Modules");
$mods = array();
$mods = HookHandler::hook("moderate", $mods);
reset($mods);

// These are already translated in the module.
$translator->setSchema("notranslate");
foreach ($mods as $area => $name) {
    Nav::add($name, "moderate.php?area=$area");
}
$translator->setSchema();

Footer::pageFooter();

/**
 * Normalize request map keys into a clean integer list for SQL IN clauses.
 *
 * @param mixed $values Request payload map where IDs are keys.
 *
 * @return list<int>
 */
function moderateNormalizeIntegerKeys($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $keys = array_keys($values);
    $keys = array_map(static fn ($value): int => (int) $value, $keys);

    return array_values(array_unique(array_filter($keys, static fn (int $value): bool => $value > 0)));
}
