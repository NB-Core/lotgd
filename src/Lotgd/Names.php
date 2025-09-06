<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Utility functions dealing with player names and titles.
 */
class Names
{
    /**
     * Retrieve a player's title string.
     *
     * @param array|false $old Optional player row to inspect
     */
    public static function getPlayerTitle(array|false $old = false)
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

    /**
     * Get a player's base name without title codes.
     *
     * @param array|false $old Optional player row
     */
    public static function getPlayerBasename(array|false $old = false)
    {
        global $session;
        $name = '';
        $title = self::getPlayerTitle($old);
        if ($old === false) {
            $name = $session['user']['name'];
            $pname = $session['user']['playername'];
        } else {
            $name = $old['name'] ?? '';
            $pname = $old['playername'] ?? '';
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

    /**
     * Apply the player's title to a base name.
     */
    public static function changePlayerName(string $newname, array|false $old = false): string
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

    /**
     * Replace the player's custom title.
     */
    public static function changePlayerCtitle(string $nctitle, array|false $old = false): string
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

    /**
     * Replace a standard title while respecting custom titles.
     */
    public static function changePlayerTitle(string $ntitle, array|false $old = false): string
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
