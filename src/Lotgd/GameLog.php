<?php
namespace Lotgd;

class GameLog
{
    public static function log(string $message, string $category = 'general', bool $filed = false): void
    {
        global $session;
        $sql = 'INSERT INTO ' . db_prefix('gamelog') .
            ' (message,category,filed,date,who) VALUES (' .
            "'" . addslashes($message) . "','" . addslashes($category) . "','" . ($filed?"1":"0") . "','" . date('Y-m-d H:i:s') . "','" . ((int)($session['user']['acctid'] ?? 0)) . "')";
        db_query($sql);
    }
}
