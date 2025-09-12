<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\Modules;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\DebugLog;
use Lotgd\GameLog;

        Modules::hook("clan-withdraw", array('clanid' => $session['user']['clanid'], 'clanrank' => $session['user']['clanrank'], 'acctid' => $session['user']['acctid']));
if ($session['user']['clanrank'] >= CLAN_LEADER) {
    //first test to see if we were the leader.
    $sql = "SELECT count(acctid) AS c FROM " . Database::prefix("accounts") . " WHERE clanid={$session['user']['clanid']} AND clanrank>=" . CLAN_LEADER . " AND acctid<>{$session['user']['acctid']}";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    if ($row['c'] == 0) {
        //we were the solitary leader.
        $sql = "SELECT name,acctid,clanrank FROM " . Database::prefix("accounts") . " WHERE clanid={$session['user']['clanid']} AND clanrank > " . CLAN_APPLICANT . " AND acctid<>{$session['user']['acctid']} ORDER BY clanrank DESC, clanjoindate LIMIT 1";
        $result = Database::query($sql);
        if ($row = Database::fetchAssoc($result)) {
            //there is no alternate leader, let's promote the
            //highest ranking member (or oldest member in the
            //event of a tie).  This will capture even people
            //who applied for membership.
            $sql = "UPDATE " . Database::prefix("accounts") . " SET clanrank=" . CLAN_LEADER . " WHERE acctid={$row['acctid']}";
            Database::query($sql);
            $output->output("`^Promoting %s`^ to leader as they are the highest ranking member (or oldest member in the event of a tie).`n`n", $row['name']);
        } else {
            //There are no other members, we need to delete the clan.
            Modules::hook("clan-delete", array("clanid" => $session['user']['clanid']));
            $sql = "DELETE FROM " . Database::prefix("clans") . " WHERE clanid={$session['user']['clanid']}";
            Database::query($sql);
            //just in case we goofed, we don't want to have to worry
            //about people being associated with a deleted clan.
            $sql = "UPDATE " . Database::prefix("accounts") . " SET clanid=0,clanrank=" . CLAN_APPLICANT . ",clanjoindate='" . DATETIME_DATEMIN . "' WHERE clanid={$session['user']['clanid']}";
            Database::query($sql);
            $output->output("`^As you were the last member of this clan, it has been deleted.");
            GameLog::log("Clan " . $session['user']['clanid'] . " has been deleted, last member gone", "clan");
        }
    } else {
        //we don't have to do anything special with this clan as
        //although we were leader, there is another leader already
        //to take our place.
    }
} else {
    //we don't have to do anything special with this clan as we were
    //not the leader, and so there should still be other members.
}
        $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE clanid='{$session['user']['clanid']}' AND clanrank>=" . CLAN_OFFICER . " AND acctid<>'{$session['user']['acctid']}'";
        $result = Database::query($sql);
        $withdraw_subj = array("`\$Clan Withdraw: `&%s`0",$session['user']['name']);
        $msg = array("`^One of your clan members has resigned their membership.  `&%s`^ has surrendered their position within your clan!",$session['user']['name']);
        $sql = "DELETE FROM " . Database::prefix("mail") . " WHERE msgfrom=0 AND seen=0 AND subject='" . addslashes(serialize($withdraw_subj)) . "'"; //addslashes for names with ' inside
        Database::query($sql);
while ($row = Database::fetchAssoc($result)) {
    Mail::systemMail($row['acctid'], $withdraw_subj, $msg);
}

        DebugLog::add($session['user']['login'] . " has withdrawn from his/her clan no. " . $session['user']['clanid']);
        $session['user']['clanid'] = 0;
        $session['user']['clanrank'] = CLAN_APPLICANT;
        $session['user']['clanjoindate'] = DATETIME_DATEMIN;
        $output->output("`&You have withdrawn from your clan.");
        Nav::add("Clan Options");
        Nav::add("Return to the Lobby", "clan.php");
