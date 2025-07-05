<?php
namespace Lotgd;

/**
 * Utility functions dealing with player names and titles.
 */
class Names
{
    public static function getPlayerTitle($old = false)
    {
        global $session;
        $title = '';
        if ($old === false) {
            $title = $session['user']['title'];
            if ($session['user']['ctitle']) {
                $title = $session['user']['ctitle'];
            }
        } else {
            $title = $old['title'];
            if ($old['ctitle']) {
                $title = $old['ctitle'];
            }
        }
        return $title;
    }

    public static function getPlayerBasename($old = false)
    {
        global $session;
        $name = '';
        $title = self::getPlayerTitle($old);
        if ($old === false) {
            $name = $session['user']['name'];
            $pname = $session['user']['playername'];
        } else {
            $name = $old['name'];
            $pname = $old['playername'];
        }
        if ($pname != '') {
            return str_replace('`0', '', $pname);
        }
        if ($title) {
            $x = strpos($name, $title);
            if ($x !== false) {
                $pname = trim(substr($name, $x + strlen($title)));
            }
        }
        $pname = str_replace('`0', '', $pname);
        return $pname;
    }

    public static function changePlayerName($newname, $old = false)
    {
        if ($newname == '') {
            $newname = self::getPlayerBasename($old);
        }
        $newname = str_replace('`0', '', $newname);
        $title = self::getPlayerTitle($old);
        if ($title) {
            $newname = $title . ' ' . $newname . '`0';
        }
        return $newname;
    }

    public static function changePlayerCtitle($nctitle, $old = false)
    {
        global $session;
        if ($nctitle == '') {
            if ($old == false) {
                $nctitle = $session['user']['title'];
            } else {
                $nctitle = $old['title'];
            }
        }
        $newname = self::getPlayerBasename($old) . '`0';
        if ($nctitle) {
            $newname = $nctitle . ' ' . $newname;
        }
        return $newname;
    }

    public static function changePlayerTitle($ntitle, $old = false)
    {
        global $session;
        if ($old === false) {
            $ctitle = $session['user']['ctitle'];
        } else {
            $ctitle = $old['ctitle'];
        }
        $newname = self::getPlayerBasename($old) . '`0';
        if ($ctitle == '') {
            if ($ntitle != '') {
                $newname = $ntitle . ' ' . $newname;
            }
        } else {
            $newname = $ctitle . ' ' . $newname;
        }
        return $newname;
    }
}
