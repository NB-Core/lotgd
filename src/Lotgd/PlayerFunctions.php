<?php
namespace Lotgd;

/**
 * Utility methods for player maintenance tasks.
 */
class PlayerFunctions
{
    /**
     * Cleanup character data when deleting a player.
     *
     * @param int $id   Account id of the player to clean up
     * @param int $type Type of deletion (see constants)
     */
    public static function charCleanup(int $id, int $type): void
    {
        // Run module hooks for character deletion
        modulehook('delete_character', ['acctid' => $id, 'deltype' => $type]);

        // Remove output cache records for this player
        db_query('DELETE FROM ' . db_prefix('accounts_output') . " WHERE acctid=$id;");

        // Remove comments from this player
        db_query('DELETE FROM ' . db_prefix('commentary') . " WHERE author=$id;");

        // Handle clan cleanup logic
        $sql = 'SELECT clanrank,clanid FROM ' . db_prefix('accounts') . " WHERE acctid=$id";
        $res = db_query($sql);
        $row = db_fetch_assoc($res);
        if ($row['clanid'] != 0 && ($row['clanrank'] == CLAN_LEADER || $row['clanrank'] == CLAN_FOUNDER)) {
            $cid = $row['clanid'];
            $sql = 'SELECT count(acctid) as counter FROM ' . db_prefix('accounts')
                . " WHERE clanid=$cid AND clanrank >= " . CLAN_LEADER . " AND acctid<>$id ORDER BY clanrank DESC, clanjoindate";
            $res = db_query($sql);
            $row = db_fetch_assoc($res);
            if ($row['counter'] == 0) {
                $sql = 'SELECT name,acctid,clanrank FROM ' . db_prefix('accounts')
                    . " WHERE clanid=$cid AND clanrank > " . CLAN_APPLICANT . " AND acctid<>$id ORDER BY clanrank DESC, clanjoindate";
                $res = db_query($sql);
                if (db_num_rows($res)) {
                    $row = db_fetch_assoc($res);
                    if ($row['clanrank'] != CLAN_LEADER && $row['clanrank'] != CLAN_FOUNDER) {
                        $id1 = $row['acctid'];
                        $sql = 'UPDATE ' . db_prefix('accounts') . ' SET clanrank=' . CLAN_LEADER . " WHERE acctid=$id1";
                        db_query($sql);
                    }
                    GameLog::log('Clan ' . $cid . ' has a new leader ' . $row['name'] . ' as there were no others left', 'clan');
                } else {
                    $sql = 'DELETE FROM ' . db_prefix('clans') . " WHERE clanid=$cid";
                    db_query($sql);
                    GameLog::log('Clan ' . $cid . ' has been disbanded as the last member left', 'clan');
                    $sql = 'UPDATE ' . db_prefix('accounts') . " SET clanid=0,clanrank=0,clanjoindate='" . DATETIME_DATEMIN . "' WHERE clanid=$cid";
                    db_query($sql);
                }
            }
        }

        // Remove module user preferences
        module_delete_userprefs($id);
    }
}
