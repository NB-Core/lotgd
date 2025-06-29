<?php
namespace Lotgd;

class AddNews
{
    public static function add(string $text, ...$replacements)
    {
        global $session;
        $args = [$session['user']['acctid'], $text, ...$replacements];
        return call_user_func_array([self::class, 'addForUser'], $args);
    }

    public static function addForUser(int $user, string $news, ...$args)
    {
        global $translation_namespace;
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

        $sql = 'INSERT INTO ' . db_prefix('news')
            . ' (newstext,newsdate,accountid,arguments,tlschema) VALUES ('
            . '\'' . addslashes($news) . '\','
            . '\'' . date('Y-m-d H:i:s') . '\','
            . $user . ',\'' . addslashes($arguments) . '\','
            . '\'' . $translation_namespace . '\')';

        return db_query($sql);
    }
}
