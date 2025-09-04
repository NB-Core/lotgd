<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Modules;
use Lotgd\DebugLog;
use Lotgd\SafeEscape;
use Lotgd\Translator;
use Lotgd\Sanitize;
use Lotgd\Output;

/**
 * Handle clan membership operations.
 */
function clanMembership(): void
{
    global $session, $claninfo, $apply_short, $ranks;

    $output = Output::getInstance();

    Nav::add('Clan Hall', 'clan.php');
    Nav::add('Clan Options');

    $output->output('`i`$Clan Rank Structure:`n');
    $output->output('`2Rank >=Officer(20) can promote/demote people equal or lower than his rank.`n');
    $output->output('`2Rank >=Administrative(25) can promote/demote AND remove people equal or lower than his rank.`n`n');
    $output->output('`$Exception: A founder can never be removed, a leader can by another leader.`i`0`n`n');
    $output->output('`4This is your current clan membership:`n');

    // Retrieve request variables
    $setrank = (int) Http::post('setrank');
    if ($setrank === 0) {
        $setrank = (int) Http::get('setrank');
    }
    $whoacctid = (int) Http::get('whoacctid');
    $remove = Http::get('remove');

    // Promotion / demotion
    if ($whoacctid > 0 && $setrank >= 0 && $setrank <= $session['user']['clanrank']) {
        $sql = sprintf(
            <<<'SQL'
                SELECT name, login, clanrank
                FROM %s
                WHERE acctid = %d
                LIMIT 1
            SQL,
            Database::prefix('accounts'),
            $whoacctid
        );

        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        $who = $row['login'];
        $whoname = $row['name'];

        if ($setrank > 0) {
            $args = Modules::hook('clan-setrank', [
                'setrank' => $setrank,
                'login' => $who,
                'name' => $whoname,
                'acctid' => $whoacctid,
                'clanid' => $session['user']['clanid'],
                'oldrank' => $row['clanrank'],
            ]);

            if (!(isset($args['handled']) && $args['handled'])) {
                $sql = sprintf(
                    <<<'SQL'
                        UPDATE %s
                        SET clanrank = GREATEST(0, LEAST(%d, %d))
                        WHERE acctid = %d
                    SQL,
                    Database::prefix('accounts'),
                    $session['user']['clanrank'],
                    $setrank,
                    $whoacctid
                );

                Database::query($sql);
                DebugLog::add("Player {$session['user']['name']} changed rank of {$whoname} to {$setrank}.", $whoacctid);
            }
        }
    }

    // Removal
    if ($remove > '') {
        $sql = sprintf(
            <<<'SQL'
                SELECT name, login, clanrank
                FROM %s
                WHERE acctid = '%s'
            SQL,
            Database::prefix('accounts'),
            $remove
        );

        $row = Database::fetchAssoc(Database::query($sql));
        $args = Modules::hook('clan-setrank', [
            'setrank' => 0,
            'login' => $row['login'],
            'name' => $row['name'],
            'acctid' => $remove,
            'clanid' => $session['user']['clanid'],
            'oldrank' => $row['clanrank'],
        ]);

        $sql = sprintf(
            <<<'SQL'
                UPDATE %s
                SET clanrank = %d, clanid = 0, clanjoindate = '%s'
                WHERE acctid = '%s' AND clanrank <= %d
            SQL,
            Database::prefix('accounts'),
            CLAN_APPLICANT,
            DATETIME_DATEMIN,
            $remove,
            $session['user']['clanrank']
        );

        Database::query($sql);
        DebugLog::add(
            "Player {$session['user']['name']} removed player {$row['login']} from {$claninfo['clanname']}.",
            $remove
        );

        // Delete unread application emails from this user.
        $subj = SafeEscape::escape(serialize([$apply_short, $row['name']]));
        $sql = sprintf(
            <<<'SQL'
                DELETE FROM %s
                WHERE msgfrom = 0 AND seen = 0 AND subject = '%s'
            SQL,
            Database::prefix('mail'),
            $subj
        );

        Database::query($sql);
    }

    // Listing
    $sql = sprintf(
        <<<'SQL'
            SELECT name, login, acctid, clanrank, laston, clanjoindate, dragonkills, level
            FROM %s
            WHERE clanid = %d
            ORDER BY clanrank DESC, dragonkills DESC, level DESC, clanjoindate
        SQL,
        Database::prefix('accounts'),
        $claninfo['clanid']
    );

    $result = Database::query($sql);
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='0'>");
    $rank = Translator::translateInline('Rank');
    $name = Translator::translateInline('Name');
    $lev = Translator::translateInline('Level');
    $dk = Translator::translateInline('Dragon Kills');
    $jd = Translator::translateInline('Join Date');
    $lo = Translator::translateInline('Last On');
    $ops = Translator::translateInline('Operations');
    $promote = Translator::translateInline('Promote');
    $demote = Translator::translateInline('Demote');
    $stepdown = Translator::translateInline('`$Step down as founder');
    $removeText = Translator::translateInline('Remove From Clan');
    $submit = Translator::translateInline('Set Rank');
    $confirm = Translator::translateInline('Are you sure you wish to remove this member from your clan?');

    $output->rawOutput(
        "<tr class='trhead'><td>$rank</td><td>$name</td><td>$lev</td><td>$dk</td><td>$jd</td><td>$lo</td><td>$ops</td></tr>",
        true
    );
    $i = false;
    $tot = 0;

    require 'pages/clan/func.php';
    $validranks = array_intersect_key($ranks, range(0, $session['user']['clanrank']));

    while ($row = Database::fetchAssoc($result)) {
        $i = ! $i;
        $list = '';

        foreach ($validranks as $key => $value) {
            if ($key > $session['user']['clanrank'] || $key == CLAN_FOUNDER) {
                continue;
            }

            $list .= "<option value='$key' " . ($row['clanrank'] == $key ? 'selected ' : '') . '>' . Sanitize::sanitize($value) . '</option>';
        }

        $tot += $row['dragonkills'];
        $output->rawOutput("<tr class='" . ($i ? 'trlight' : 'trdark') . "'>");
        $output->rawOutput('<td>');

        if (isset($ranks[$row['clanrank']])) {
            $output->outputNotl($ranks[$row['clanrank']]);
        } else {
            $output->output('-unset clan rank-');
        }

        $output->rawOutput('</td><td>');
        $link = 'bio.php?char=' . $row['acctid'] . '&ret=' . urlencode($_SERVER['REQUEST_URI']);
        $output->rawOutput("<a href='$link'>", true);
        Nav::add('', $link);
        $output->outputNotl('`&%s`0', $row['name']);
        $output->rawOutput('</a>');
        $output->rawOutput("</td><td align='center'>");
        $output->outputNotl('`^%s`0', $row['level']);
        $output->rawOutput("</td><td align='center'>");
        $output->outputNotl('`$%s`0', $row['dragonkills']);
        $output->rawOutput('</td><td>');
        $output->outputNotl('`3%s`0', $row['clanjoindate']);
        $output->rawOutput('</td><td>');
        $output->outputNotl('`#%s`0', reltime(strtotime($row['laston'])));
        $output->rawOutput('</td>');

        if ($session['user']['clanrank'] >= CLAN_OFFICER && $row['clanrank'] <= $session['user']['clanrank']) {
            $output->rawOutput('<td nowrap>');

            // new promote/demote system
            if ($row['clanrank'] == CLAN_FOUNDER && $row['login'] == $session['user']['login']) {
                $conf = Translator::translateInline('Are you really sure to step down as founder? You can NEVER rise again to that rank!');
                $output->outputNotl(
                    "<form action='clan.php?op=membership&setrank=" . clan_previousrank($ranks, $row['clanrank']) . "&whoacctid=" . $row['acctid'] . "' METHOD='POST'><input type='submit' class='button' onClick='return confirm(\"$conf\");' value='" . Sanitize::sanitize($stepdown) . "'></form> | ",
                    true
                );
                Nav::add('', 'clan.php?op=membership&setrank=' . clan_previousrank($ranks, $row['clanrank']) . '&whoacctid=' . $row['acctid']);
            } elseif ($row['clanrank'] != CLAN_FOUNDER) {
                $output->rawOutput("<form action='clan.php?op=membership&whoacctid={$row['acctid']}' method='post'><select name='setrank'>");
                $output->rawOutput($list);
                $output->rawOutput('</select>');
                $output->rawOutput("<input type='submit' class='button' value='$submit'></form>");
                Nav::add('', "clan.php?op=membership&whoacctid={$row['acctid']}");
            }

            if (
                $row['clanrank'] <= $session['user']['clanrank']
                && $row['login'] != $session['user']['login']
                && $row['clanrank'] < CLAN_FOUNDER
                && $session['user']['clanrank'] >= CLAN_ADMINISTRATIVE
            ) {
                $output->rawOutput("[<a href='clan.php?op=membership&remove=" . $row['acctid'] . "' onClick=\"return confirm('$confirm');\"> $removeText</a> ]");
                Nav::add('', 'clan.php?op=membership&remove=' . $row['acctid']);
            } else {
                $output->outputNotl('`2[ `)%s`2 ]', $removeText);
            }

            $output->rawOutput('</td>');
        } else {
            $output->rawOutput('<td>');
            $output->output('None');
            $output->rawOutput('</td>');
        }

        $output->rawOutput('</tr>');
    }

    $output->rawOutput('</table>');
    $output->output('`n`n`^This clan has a total of `$%s`^ dragon kills.', $tot);
}

clanMembership();
