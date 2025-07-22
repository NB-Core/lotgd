<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Translator;

class AddNews
{
    /**
     * Add a news entry for the current user.
     *
     * @param string $text         News text with placeholders
     * @param mixed  ...$replacements Replacement values
     *
     * @return mixed Database query result
     */
    public static function add(string $text, mixed ...$replacements): mixed
    {
        global $session;
        $args = [$session['user']['acctid'], $text, ...$replacements];
        return call_user_func_array([self::class, 'addForUser'], $args);
    }

    /**
     * Create a news entry for a given account.
     *
     * @param int    $user  Account id
     * @param string $news  News text
     * @param mixed  ...$args Additional arguments
     *
     * @return mixed Database query result
     */
    public static function addForUser(int $user, string $news, mixed ...$args): mixed
    {
        $hidefrombio = false;

        if (count($args) > 0) {
            $arguments = [];
            foreach ($args as $key => $val) {
                if ($key == count($args) - 1 && $val === true) {
                    $hidefrombio = true;
                } else {
                    $arguments[] = $val;
                }
            }
            $arguments = serialize($arguments);
        } else {
            $arguments = '';
        }

        if ($hidefrombio === true) {
            $user = 0;
        }

        $sql = 'INSERT INTO ' . Database::prefix('news')
            . ' (newstext,newsdate,accountid,arguments,tlschema) VALUES ('
            . '\'' . addslashes($news) . '\','
            . '\'' . date('Y-m-d H:i:s') . '\','
            . $user . ',\'' . addslashes($arguments) . '\','
            . '\'' . Translator::getNamespace() . '\')';

        return Database::query($sql);
    }
}
