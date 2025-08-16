<?php

declare(strict_types=1);

use Lotgd\Commentary;
use Lotgd\Modules;
use Lotgd\Nav;
use Lotgd\Repository\ClanRepository;
use Lotgd\Translator;

/**
 * Controller for the default clan hall view.
 */
function renderClanDefault(): void
{
    global $claninfo, $output, $session, $ranks;

    Modules::hook('collapse{', ['name' => 'clanentry']);
    $output->output("Having pressed the secret levers and turned the secret knobs on the lock of the door to your clan's hall, you gain entrance and chat with your clan mates.`n`n");
    Modules::hook('}collapse');

    // Retrieve author names for MoTD and description.
    $motdAuthorName = ClanRepository::fetchAccountName((int) $claninfo['motdauthor']) ?? Translator::translateInline('Nobody');
    $descAuthorName = ClanRepository::fetchAccountName((int) $claninfo['descauthor']) ?? Translator::translateInline('Nobody');

    /**
     * MoTD display section.
     */
    if (isset($claninfo['clanmotd']) && $claninfo['clanmotd'] !== null && $claninfo['clanmotd'] !== '') {
        $output->rawOutput("<div style='margin-left: 15px; padding-left: 15px;'>");
        $output->output("`&`bCurrent MoTD:`b `#by %s`2`n", $motdAuthorName);
        $output->outputNotl(nltoappon($claninfo['clanmotd']) . "`n");
        $output->rawOutput('</div>');
        $output->outputNotl("`n");
    }

    // Display clan chat.
    $clanCommentary = Modules::hook('clan-commentary', ['section' => "clan-{$claninfo['clanid']}", 'clanid' => $claninfo['clanid']]);
    $clanSectionName = $clanCommentary['section'];
    Commentary::commentDisplay('', $clanSectionName, 'Speak', 25, ($claninfo['customsay'] > '' ? $claninfo['customsay'] : 'says'));

    Modules::hook('clanhall');

    /**
     * Clan description display.
     */
    if (isset($claninfo['clandesc']) && $claninfo['clandesc'] !== null && $claninfo['clandesc'] !== '') {
        Modules::hook('collapse{', ['name' => 'collapsedesc']);
        $output->output("`n`n`&`bCurrent Description:`b `#by %s`2`n", $descAuthorName);
        $output->outputNotl(nltoappon($claninfo['clandesc']));
        Modules::hook('}collapse');
    }

    /**
     * Membership summary section.
     */
    $members = ClanRepository::countMembersByRank((int) $claninfo['clanid']);
    Modules::hook('collapse{', ['name' => 'clanmemberdet']);
    $output->output("`n`n`bMembership Details:`b`n");
    $leaders = 0;
    foreach ($members as $row) {
        $output->outputNotl(($ranks[$row['clanrank']] ?? 'Undefined') . ": `0" . $row['c'] . "`n");
        if ($row['clanrank'] >= CLAN_LEADER) {
            $leaders += $row['c'];
        }
    }
    $output->output("`n");

    /**
     * Leader reassignment section.
     */
    $noLeaderText = Translator::translateInline("`^There is currently no leader!  Promoting %s`^ to leader as they are the highest ranking member (or oldest member in the event of a tie).`n`n");
    if ($leaders === 0) {
        $member = ClanRepository::getHighestRankingMember((int) $session['user']['clanid']);
        if ($member !== null) {
            ClanRepository::promoteToLeader((int) $member['acctid']);
            $output->outputNotl($noLeaderText, $member['name']);
            if ((int) $member['acctid'] === (int) $session['user']['acctid']) {
                // Update session rank for current user.
                $session['user']['clanrank'] = CLAN_LEADER;
            }
        }
    }
    Modules::hook('}collapse');

    // Navigation options.
    if ($session['user']['clanrank'] > CLAN_MEMBER) {
        Nav::add('Update MoTD / Clan Desc', 'clan.php?op=motd');
    }
    Nav::add('M?View Membership', 'clan.php?op=membership');
    Nav::add('Online Members', 'list.php?op=clan');
    Nav::add("Your Clan's Waiting Area", 'clan.php?op=waiting');
    Nav::add('Withdraw From Your Clan', 'clan.php?op=withdrawconfirm');
}

renderClanDefault();
