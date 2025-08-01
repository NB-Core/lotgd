<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Translator;

class Commentary
{
    public static array $comsecs = [];

    /**
     * Retrieve all sections that accept commentary posts.
     */
    public static function commentaryLocs(): array
    {
        global $session;
        if (is_array(self::$comsecs) && count(self::$comsecs)) {
            return self::$comsecs;
        }
        $vname = getsetting('villagename', LOCATION_FIELDS);
        $iname = getsetting('innname', LOCATION_INN);
        tlschema('commentary');
        self::$comsecs['village'] = sprintf_translate('%s Square', $vname);
        if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
            self::$comsecs['superuser'] = Translator::translateInline('Grotto');
        }
        self::$comsecs['shade'] = Translator::translateInline('Land of the Shades');
        self::$comsecs['grassyfield'] = Translator::translateInline('Grassy Field');
        self::$comsecs['inn'] = "$iname";
        self::$comsecs['motd'] = Translator::translateInline('MotD');
        self::$comsecs['veterans'] = Translator::translateInline('Veterans Club');
        self::$comsecs['hunterlodge'] = Translator::translateInline("Hunter's Lodge");
        self::$comsecs['gardens'] = Translator::translateInline('Gardens');
        self::$comsecs['waiting'] = Translator::translateInline('Clan Hall Waiting Area');
        if (getsetting('betaperplayer', 1) == 1 && @file_exists('pavilion.php')) {
            self::$comsecs['beta'] = Translator::translateInline('Pavilion');
        }
        tlschema();
        self::$comsecs = modulehook('moderate', self::$comsecs);
        rawoutput(tlbutton_clear());
        return self::$comsecs;
    }

    /**
     * Handle POSTed commentary and perform moderation actions.
     */
    public static function addCommentary(): void
    {
        global $session, $emptypost;
        $section = httppost('section');
        $talkline = httppost('talkline');
        $schema = httppost('schema');
        $comment = trim((string)httppost('insertcommentary'));
        $counter = httppost('counter');
        $remove = URLDecode((string)httpget('removecomment'));
        if ($remove > 0) {
            $return = httpget('returnpath');
            $section = httpget('section');
            $sql = 'SELECT ' . Database::prefix('commentary') . '.*,' . Database::prefix('accounts') . '.name,' . Database::prefix('accounts') . '.acctid, ' . Database::prefix('accounts') . '.clanrank,' . Database::prefix('clans') . '.clanshort FROM ' . Database::prefix('commentary') . ' INNER JOIN ' . Database::prefix('accounts') . ' ON ' . Database::prefix('accounts') . '.acctid = ' . Database::prefix('commentary') . '.author LEFT JOIN ' . Database::prefix('clans') . ' ON ' . Database::prefix('clans') . '.clanid=' . Database::prefix('accounts') . '.clanid WHERE commentid=' . ((string)$remove);
            $row = Database::fetchAssoc(Database::query($sql));
            $sql = 'INSERT LOW_PRIORITY INTO ' . Database::prefix('moderatedcomments') . " (moderator,moddate,comment) VALUES ('{$session['user']['acctid']}',\"" . date('Y-m-d H:i:s') . "\",\"" . addslashes(serialize($row)) . "\")";
            Database::query($sql);
            $sql = 'DELETE FROM ' . Database::prefix('commentary') . " WHERE commentid='$remove';";
            Database::query($sql);
            invalidatedatacache("comments-$section");
            invalidatedatacache('comments-or11');
            $session['user']['specialinc'] == '';
            $return = cmd_sanitize($return);
            $return = mb_substr($return, strrpos($return, '/') + 1);
            if (strpos($return, '?') === false && strpos($return, '&') !== false) {
                $x = strpos($return, '&');
                $return = mb_substr($return, 0, $x - 1) . '?' . mb_substr($return, $x + 1);
            }
            redirect($return);
        }
        if (array_key_exists('commentcounter', $session) && $session['commentcounter'] == $counter) {
            if ($section || $talkline || $comment) {
                $tcom = color_sanitize($comment);
                if ($tcom == '' || $tcom == ':' || $tcom == '::' || $tcom == '/me') {
                    $emptypost = 1;
                } else {
                    if (rawurldecode(httpget('section')) != $section) {
                        output('`\$Please post in the section you should!');
                        debug(rawurldecode(httpget('section')) . "-" . $section);
                    } else {
                        self::injectCommentary($section, $talkline, $comment, $schema);
                    }
                }
            }
        }
    }

    /**
     * Insert a system generated message into the commentary stream.
     */
    public static function injectSystemComment(string $section, string $comment): void
    {
        if (strncmp($comment, '/game', 5) !== 0) {
            $comment = '/game' . $comment;
        }
        self::injectRawComment($section, 0, $comment);
    }

    /**
     * Directly insert a comment row without additional processing.
     */
    public static function injectRawComment(string $section, int $author, string $comment): void
    {
        $sql = 'INSERT INTO ' . Database::prefix('commentary') . " (postdate,section,author,comment) VALUES ('" . date('Y-m-d H:i:s') . "','$section',$author,'" . Database::escape($comment) . "')";
        Database::query($sql);
        invalidatedatacache("comments-{$section}");
        invalidatedatacache('comments-or11');
    }

    /**
     * Insert a user comment into the database, performing validation and hooks.
     */
    public static function injectCommentary(string $section, string $talkline, string $comment, $schema = false): void
    {
        global $session, $doublepost;
        if ($schema === false) {
            $schema = Translator::getNamespace();
        }
        $comment = stripslashes($comment);
        tlschema('commentary');
        $doublepost = 0;
        $emptypost = 0;
        $colorcount = 0;
        if ($comment != '') {
            $commentary = str_replace('`n', '', soap($comment));
            $y = strlen($commentary);
            for ($x = 0; $x < $y; $x++) {
                if (mb_substr($commentary, $x, 1) == '`') {
                    $colorcount++;
                    if ($colorcount >= getsetting('maxcolors', 10)) {
                        $commentary = mb_substr($commentary, 0, $x) . color_sanitize(mb_substr($commentary, $x));
                        $x = $y;
                    }
                    $x++;
                }
            }
            $args = ['section' => $section, 'commentline' => $commentary, 'commenttalk' => $talkline];
            $args = modulehook('commentary', $args);
            $commentary = $args['commentline'];
            $talkline = $args['commenttalk'];
            tlschema($schema);
            $talkline = Translator::translateInline($talkline);
            tlschema();
            if (getsetting('soap', 1)) {
                $commentary = mb_ereg_replace("'([^[:space:]]{45,45})([^[:space:]])'", "\\1 \\2", $commentary);
            }
            $commentary = addslashes($commentary);
            if ($talkline != 'says' && mb_substr($commentary, 0, 1) != ':' && mb_substr($commentary, 0, 2) != '::' && mb_substr($commentary, 0, 3) != '/me' && mb_substr($commentary, 0, 5) != '/game') {
                $commentary = ":`3$talkline, \"`#$commentary`3\"";
            }
            $args = modulehook('gmcommentarea', ['section' => $section, 'allow_gm' => false, 'commentary' => $commentary]);
            if (mb_substr($commentary, 0, 5) == '/game' && ((($session['user']['superuser'] & SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER) || $args['allow_gm'] === true)) {
                self::injectSystemComment($section, $args['commentary']);
            } else {
                $commentary = $args['commentary'];
                $sql = 'SELECT comment,author FROM ' . Database::prefix('commentary') . " WHERE section='$section' ORDER BY commentid DESC LIMIT 1";
                $result = Database::query($sql);
                if (Database::numRows($result) > 0) {
                    $row = Database::fetchAssoc($result);
                    if ($row['comment'] != stripslashes($commentary) || $row['author'] != $session['user']['acctid']) {
                        self::injectRawComment($section, (int)$session['user']['acctid'], $commentary);
                        $session['user']['laston'] = date('Y-m-d H:i:s');
                    } else {
                        $doublepost = 1;
                    }
                } else {
                    self::injectRawComment($section, (int)$session['user']['acctid'], $commentary);
                }
            }
            tlschema();
        }
    }

    /**
     * Display a block of commentary and an optional input form.
     */
    public static function commentDisplay(string $intro, string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false): void
    {
        $args = modulehook('blockcommentarea', ['section' => $section]);
        if (isset($args['block']) && ($args['block'] == 'yes')) {
            return;
        }
        if ($intro) {
            output($intro);
        }
        self::viewCommentary($section, $message, $limit, $talkline, $schema);
    }

    /**
     * Render commentary lines for a given section.
     */
    public static function viewCommentary(string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false, bool $viewonly = false, bool $returnastext = false, $scriptname_pre = false): ?string
    {
        global $session, $REQUEST_URI, $doublepost, $emptypost;

        // The guard for null is removed as $section is declared as string and cannot be null.

        if ($scriptname_pre === false) {
            $scriptname = $_SERVER['SCRIPT_NAME'];
        } else {
            $scriptname = $scriptname_pre;
        }

        if ($_SERVER['REQUEST_URI'] == '/ext/ajax_process.php') {
            $real_request_uri = $session['last_comment_request_uri'];
        } else {
            $real_request_uri = $_SERVER['REQUEST_URI'];
            $session['last_comment_request_uri'] = $real_request_uri;
        }

        $session['last_comment_section'] = $section;
        $session['last_comment_scriptname'] = $scriptname;

        rawoutput("<div id='$section-comment'>");
        if ($returnastext !== false) {
            global $output;
            $oldoutput = $output;
            $output = new output_collector();
        }

        rawoutput("<a name='$section'></a>");

        $args = modulehook('blockcommentarea', ['section' => $section]);
        if (isset($args['block']) && ($args['block'] == 'yes')) {
            return null;
        }

        if ($schema === false) {
            $schema = Translator::getNamespace();
        }
        tlschema('commentary');

        $nobios = ['motd.php' => true];
        if (!array_key_exists(basename($scriptname), $nobios)) {
            $nobios[basename($scriptname)] = false;
        }
        $linkbios = !$nobios[basename($scriptname)];

        if ($message == 'X') {
            $linkbios = true;
        }

        if ($doublepost) {
            output("`$`bDouble post?`b`0`n");
        }
        if ($emptypost) {
            output("`$`bWell, they say silence is a virtue.`b`0`n");
        }

        $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];

        $com = (int)httpget('comscroll');
        if ($com < 0) {
            $com = 0;
        }
        $cc = false;
        if (!isset($session['lastcom'])) {
            $session['lastcom'] = 0;
        }
        if (httpget('comscroll') !== false && (int)$session['lastcom'] == $com + 1) {
            $cid = (int)$session['lastcommentid'];
        } else {
            $cid = 0;
        }

        $session['lastcom'] = $com;

        if ($com > 0 || $cid > 0) {
            $sql = 'SELECT COUNT(commentid) AS newadded FROM '
                . Database::prefix('commentary') . ' LEFT JOIN '
                . Database::prefix('accounts') . ' ON '
                . Database::prefix('accounts') . '.acctid = '
                . Database::prefix('commentary') . '.author WHERE section=\'' . $section . "' AND "
                . '(' . Database::prefix('accounts') . '.locked=0 or ' . Database::prefix('accounts') . '.locked is null) AND commentid > \'' . $cid . "'";
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            $newadded = $row['newadded'];
        } else {
            $newadded = 0;
        }

        $commentbuffer = [];
        if ($cid == 0) {
            $sql = "SELECT " . Database::prefix("commentary") . ".*, " .
                Database::prefix("accounts") . ".name, " .
                Database::prefix("accounts") . ".acctid, " .
                Database::prefix("accounts") . ".superuser, " .
                Database::prefix("accounts") . ".clanrank, " .
                Database::prefix("clans") .  ".clanshort FROM " .
                Database::prefix("commentary") . " LEFT JOIN " .
                Database::prefix("accounts") . " ON " .
                Database::prefix("accounts") .  ".acctid = " .
                Database::prefix("commentary") . ".author LEFT JOIN " .
                Database::prefix("clans") . " ON " .
                Database::prefix("clans") . ".clanid=" .
                Database::prefix("accounts") .
                ".clanid WHERE section = '$section' AND " .
                "( " . Database::prefix("accounts") . ".locked=0 OR " . Database::prefix("accounts") . ".locked is null ) " .
                "ORDER BY commentid DESC LIMIT " .
                ($com * $limit) . ",$limit";
            if ($com == 0 && strstr($real_request_uri, '/moderate.php') !== $real_request_uri) {
                $result = Database::queryCached($sql, "comments-{$section}");
            } else {
                $result = Database::query($sql);
            }
            while ($row = Database::fetchAssoc($result)) {
                $commentbuffer[] = $row;
            }
        } else {
            $sql = "SELECT " . Database::prefix("commentary") . ".*, " .
                Database::prefix("accounts") . ".name, " .
                Database::prefix("accounts") . ".acctid, " .
                Database::prefix("accounts") . ".superuser, " .
                Database::prefix("accounts") . ".clanrank, " .
                Database::prefix("clans") . ".clanshort FROM " . Database::prefix("commentary") .
                " LEFT JOIN " . Database::prefix("accounts") . " ON " .
                Database::prefix("accounts") . ".acctid = " .
                Database::prefix("commentary") . ".author LEFT JOIN " .
                Database::prefix("clans") . " ON " . Database::prefix("clans") . ".clanid=" .
                Database::prefix("accounts") .
                ".clanid WHERE section = '$section' AND " .
                "( " . Database::prefix("accounts") . ".locked=0 OR " . Database::prefix("accounts") . ".locked is null ) " .
                "AND commentid > '$cid' " .
                "ORDER BY commentid ASC LIMIT $limit";
            $result = Database::query($sql);
            while ($row = Database::fetchAssoc($result)) {
                $commentbuffer[] = $row;
            }
            $commentbuffer = array_reverse($commentbuffer);
        }

        $rowcount = count($commentbuffer);
        if ($rowcount > 0) {
            $session['lastcommentid'] = $commentbuffer[0]['commentid'];
        }

        $is_gm = ($session['user']['superuser'] & SU_IS_GAMEMASTER ? 1 : 0);
        $gm_array = [];

        $counttoday = 0;
        for ($i = 0; $i < $rowcount; $i++) {
            $row = $commentbuffer[$i];
            if (isset($row['acctid']) && isset($session['user']['acctid']) && $row['acctid'] === $session['user']['acctid'] && $is_gm) {
                $gm_array[] = $i;
            }
            $commentids[$i] = $row['commentid'];
            if (date('Y-m-d', strtotime($row['postdate'])) == date('Y-m-d')) {
                if (isset($session['user']['name']) && $row['name'] == $session['user']['name']) {
                    $counttoday++;
                }
            }

            $op[$i] = self::renderCommentLine($row, $linkbios);
            if ($message == 'X') {
                $op[$i] = "`0({$row['section']}) " . $op[$i];
            }

            $auth[$i] = $row['author'];
        }
        $i--;
        $outputcomments = [];
        $sect = 'x';

        $del = Translator::translateInline('Del');
        $scriptname = mb_substr($scriptname, strrpos($scriptname, '/') + 1);
        $pos = strpos($real_request_uri, '?');
        $return = $scriptname . ($pos == false ? '' : mb_substr($real_request_uri, $pos));
        $one = (strstr($return, '?') == false ? '?' : '&');

        $editrights = ($session['user']['superuser'] & SU_EDIT_COMMENTS ? 1 : 0);
        for (; $i >= 0; $i--) {
            $out = '';
            if ($editrights || in_array($i, $gm_array)) {
                $out .= "`2[<a href='" . $return . $one . "removecomment={$commentids[$i]}&section=$section&returnpath=/" . URLEncode($return) . "'>$del</a>`2]`0&nbsp;";
                addnav('', $return . $one . "removecomment={$commentids[$i]}&section=$section&returnpath=/" . URLEncode($return));
            }
            $out .= $op[$i];
            if (!array_key_exists($sect, $outputcomments) || !is_array($outputcomments[$sect])) {
                $outputcomments[$sect] = [];
            }
            array_push($outputcomments[$sect], $out);
        }

        ksort($outputcomments);
        reset($outputcomments);
        $sections = self::commentaryLocs();
        $needclose = 0;

        foreach ($outputcomments as $sec => $v) {
            if ($sec != 'x') {
                if ($needclose) {
                    modulehook('}collapse');
                }
                output_notl("`n<hr><a href='moderate.php?area=%s'>`b`^%s`0`b</a>`n", $sec, isset($sections[$sec]) ? $sections[$sec] : "($sec)", true);
                addnav('', "moderate.php?area=$sec");
                modulehook('collapse{', ['name' => 'com-' . $sec]);
                $needclose = 1;
            } else {
                modulehook('collapse{', ['name' => 'com-' . $section]);
                $needclose = 1;
            }
            reset($v);
            foreach ($v as $key => $val) {
                $args = ['commentline' => $val];
                $args = modulehook('viewcommentary', $args);
                $val = $args['commentline'];
                output_notl($val, true);
            }
        }

        if ($returnastext !== false) {
            $collected = $output->getOutput();
            $output = $oldoutput;
            return $collected;
        }
        rawoutput('</div>');
        rawoutput("<div id='$section-talkline'>");

        if ($session['user']['loggedin'] && !$viewonly) {
            self::talkLine($section, $talkline, $limit, $schema, $counttoday, $message);
        }
        rawoutput("</div><div id='$section-nav'>");
        $jump = false;
        if (!isset($session['user']['prefs']['nojump']) || $session['user']['prefs']['nojump'] == false) {
            $jump = true;
        }

        $firstu = Translator::translateInline('&lt;&lt; First Unseen');
        $prev = Translator::translateInline('&lt; Previous');
        $ref = Translator::translateInline('Refresh');
        $next = Translator::translateInline('Next &gt;');
        $lastu = Translator::translateInline('Last Page &gt;&gt;');
        if ($rowcount >= $limit || $cid > 0) {
            if (isset($session['user']['recentcomments']) && $session['user']['recentcomments'] != '') {
                $sql = 'SELECT count(commentid) AS c FROM ' . Database::prefix('commentary') . " WHERE section='$section' AND postdate > '{$session['user']['recentcomments']}'";
            } else {
                $sql = 'SELECT count(commentid) AS c FROM ' . Database::prefix('commentary') . " WHERE section='$section' AND postdate > '" . DATETIME_DATEMIN . "'";
            }
            $r = Database::query($sql);
            $val = Database::fetchAssoc($r);
            $val = round($val['c'] / $limit + 0.5, 0) - 1;
            if ($val > 0) {
                $first = comscroll_sanitize($REQUEST_URI) . '&comscroll=' . ($val);
                $first = str_replace('?&', '?', $first);
                if (!strpos($first, '?')) {
                    $first = str_replace('&', '?', $first);
                }
                $first .= '&refresh=1';
                if ($jump) {
                    $first .= "#$section";
                }
                output_notl("<a href=\"$first\">$firstu</a>", true);
                addnav('', $first);
            } else {
                output_notl($firstu, true);
            }
            $req = comscroll_sanitize($REQUEST_URI) . '&comscroll=' . ($com + 1);
            $req = str_replace('?&', '?', $req);
            if (!strpos($req, '?')) {
                $req = str_replace('&', '?', $req);
            }
            $req .= '&refresh=1';
            if ($jump) {
                $req .= "#$section";
            }
            output_notl("<a href=\"$req\">$prev</a>", true);
            addnav('', $req);
        } else {
            output_notl("$firstu $prev", true);
        }
        $last = appendlink(comscroll_sanitize($REQUEST_URI), 'refresh=1');

        $last = appendcount($last);

        $last = str_replace('?&', '?', $last);
        if ($jump) {
            $last .= "#$section";
        }
        output_notl("&nbsp;<a href=\"$last\">$ref</a>&nbsp;", true);
        addnav('', $last);
        if ($com > 0 || ($cid > 0 && $newadded > $limit)) {
            $req = comscroll_sanitize($REQUEST_URI) . '&comscroll=' . ($com - 1);
            $req = str_replace('?&', '?', $req);
            if (!strpos($req, '?')) {
                $req = str_replace('&', '?', $req);
            }
            $req .= '&refresh=1';
            if ($jump) {
                $req .= "#$section";
            }
            output_notl(" <a href=\"$req\">$next</a>", true);
            addnav('', $req);
            output_notl(" <a href=\"$last\">$lastu</a>", true);
        } else {
            output_notl("$next $lastu", true);
        }
        if (!$cc) {
            Database::freeResult($result);
        }
        tlschema();
        if ($needclose) {
            modulehook('}collapse');
        }
        rawoutput('</div>');
        return null;
    }

    /**
     * Render a single commentary line.
     *
     * The legacy commentary system mixes HTML with game codes. This method
     * prepares a row from the database and generates a formatted output line.
     * Comments can contain formatting directives ("::", ":", "/me", etc.) which
     * are handled here.  We also honour user display options such as timestamps
     * or chat tags.  The result is the final HTML to output.
     */
    public static function renderCommentLine(array $row, bool $linkBios): string
    {
        global $session;

        // Build a return URL for profile links. Ajax requests reuse the last
        // page URL stored in the session.
        if ($_SERVER['REQUEST_URI'] == '/ext/ajax_process.php') {
            $real_request_uri = $session['last_comment_request_uri'] ?? $_SERVER['REQUEST_URI'];
        } else {
            $real_request_uri = $_SERVER['REQUEST_URI'];
            $session['last_comment_request_uri'] = $real_request_uri;
        }

        // Clean up colour codes and ensure valid UTF-8
        $row['comment'] = comment_sanitize($row['comment']);
        $row['comment'] = sanitize_mb($row['comment']);

        // Determine any command prefix (like ::, : or /me) at the start of the comment
        $ft = '';
        for ($x = 0; mb_strlen($ft) < 5 && $x < mb_strlen($row['comment']); $x++) {
            if (mb_substr($row['comment'], $x, 1) == '`' && strlen($ft) == 0) {
                $x++;
            } else {
                $ft .= mb_substr($row['comment'], $x, 1);
            }
        }

        // Destination for the author's bio
        $link = 'bio.php?char=' . $row['acctid'] . '&ret=' . URLEncode($real_request_uri);

        // Trim prefix to a recognised command token
        if (mb_substr($ft, 0, 2) == '::') {
            $ft = mb_substr($ft, 0, 2);
        } elseif (mb_substr($ft, 0, 1) == ':') {
            $ft = mb_substr($ft, 0, 1);
        } elseif (mb_substr($ft, 0, 3) == '/me') {
            $ft = mb_substr($ft, 0, 3);
        }

        // Apply holiday translations to comment and name
        if (!empty($row['comment'])) {
            $row['comment'] = HolidayText::holidayize($row['comment'], 'comment');
        }
        if (!empty($row['name'])) {
            $row['name'] = HolidayText::holidayize($row['name'], 'comment');
        }


        // Prepend clan tag to the author's name
        if ($row['clanrank']) {
            $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];
            $row['name'] = ($row['clanshort'] > '' ? "{$clanrankcolors[ceil($row['clanrank']/10)]}&lt;`2{$row['clanshort']}{$clanrankcolors[ceil($row['clanrank']/10)]}&gt; `&" : '') . $row['name'];
        }

        // Inject chat tags for staff or moderators
        if (getsetting('enable_chat_tags', 1) == 1) {
            if (($row['superuser'] & SU_MEGAUSER) == SU_MEGAUSER) {
                $row['name'] = '`$' . getsetting('chat_tag_megauser', '[ADMIN]') . '`0' . $row['name'];
            } else {
                if (($row['superuser'] & SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER) {
                    $chat_tag_gm = getsetting('chat_tag_gm', '[GM]');
                    $row['name'] = '`$' . $chat_tag_gm . '`0' . $row['name'];
                }
                if (($row['superuser'] & SU_EDIT_COMMENTS) == SU_EDIT_COMMENTS) {
                    $chat_tag_mod = getsetting('chat_tag_mod', '[MOD]');
                    $row['name'] = '`$' . $chat_tag_mod . '`0' . $row['name'];
                }
            }
        }

        $op = '';
        // Handle roleplay prefixes such as "/me" or the :: shout format
        if ($ft == '::' || $ft == '/me' || $ft == ':') {
            $x = strpos($row['comment'], $ft);
            if ($x !== false) {
                if ($linkBios) {
                    $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0<a href='$link' style='text-decoration: none'>\n`&{$row['name']}`0</a>\n`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
                } else {
                    $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
                }
            }
        }

        // Game messages without an author
        if ($op == '' && $ft == '/game' && !$row['name']) {
            $x = strpos($row['comment'], $ft);
            if ($x !== false) {
                $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`&" . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
            }
        }

        // Default display if we did not handle a special prefix above
        if ($op == '') {
            if ($linkBios) {
                $op = "`0<a href='$link' style='text-decoration: none'>`&{$row['name']}`0</a>`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`3\"`0`n";
            } elseif (mb_substr($ft, 0, 5) == '/game' && !$row['name']) {
                $op = str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1')));
            } else {
                $op = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`3\"`0`n";
            }
        }

        // Timestamp preferences
        $session['user']['prefs']['timeoffset'] = $session['user']['prefs']['timeoffset'] ?? 0;
        $session['user']['prefs']['timestamp'] = $session['user']['prefs']['timestamp'] ?? 0;

        if ($session['user']['prefs']['timestamp'] == 1) {
            $session['user']['prefs']['timeformat'] = $session['user']['prefs']['timeformat'] ?? '[m/d h:ia]';
            $time = strtotime($row['postdate']) + ($session['user']['prefs']['timeoffset'] * 60 * 60);
            $s = date('`7' . $session['user']['prefs']['timeformat'] . '`0 ', $time);
            $op = $s . $op;
        } elseif ($session['user']['prefs']['timestamp'] == 2) {
            $s = reltime(strtotime($row['postdate']));
            $op = "`7($s)`0 " . $op;
        }

        if (isset($session['user']['recentcomments']) && $row['postdate'] >= $session['user']['recentcomments']) {
            $op = "<img src='images/new.gif' alt='&gt;' width='3' height='5' align='absmiddle'> " . $op;
        }

        addnav('', $link);

        return $op;
    }

    /**
     * Output a line prompting for new commentary submissions.
     */
    public static function talkLine(string $section, string $talkline, int $limit, $schema, int $counttoday, string $message): void
    {
        $args = modulehook("insertcomment", array("section" => $section));
        if (
            array_key_exists("mute", $args) && $args['mute'] &&
                        !($session['user']['superuser'] & SU_EDIT_COMMENTS)
        ) {
                output_notl("%s", $args['mutemsg']);
        } elseif (
            $counttoday < ($limit / 2)
                        || ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO)
                        || ($session['user']['superuser'] & SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER
                        || !getsetting('postinglimit', 1)
        ) {
            if ($message != "X") {
                    $message = "`n`@$message`n";
                    output($message);
                    self::talkForm($section, $talkline, $limit, $schema);
            }
        } else {
                $message = "`n`@$message`n";
                output($message);
                output("Sorry, you've exhausted your posts in this section for now.`0`n");
        }
    }

    /**
     * Render the HTML form used to submit new commentary.
     */
    public static function talkForm(string $section, string $talkline, int $limit = 10, $schema = false)
    {
        global $REQUEST_URI,$session;
        if ($schema === false) {
            $schema = Translator::getNamespace();
        }
        tlschema("commentary");

        $jump = false;
        if (isset($session['user']['prefs']['nojump']) && $session['user']['prefs']['nojump'] == true) {
                $jump = true;
        }

        $counttoday = 0;
        if (mb_substr($section, 0, 5) != "clan-") {
                $sql = "SELECT author FROM " . Database::prefix("commentary") . " WHERE section='$section' AND postdate>'" . date("Y-m-d 00:00:00") . "' ORDER BY commentid DESC LIMIT $limit";
                $result = Database::query($sql);
            while ($row = Database::fetchAssoc($result)) {
                if ($row['author'] == $session['user']['acctid']) {
                    $counttoday++;
                }
            }
            if (round($limit / 2, 0) - $counttoday <= 0 && getsetting('postinglimit', 1)) {
                if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
                        output("`n`)(You'd be out of posts if you weren't a superuser or moderator.)`n");
                } else {
                        output("`n`)(You are out of posts for the time being.  Once some of your existing posts have moved out of the comment area, you'll be allowed to post again.)`n");
                        return false;
                }
            }
        }
        if (Translator::translateInline($talkline, $schema) != "says") {
                $tll = strlen(Translator::translateInline($talkline, $schema)) + 11;
        } else {
            $tll = 0;
        }
        $req = comscroll_sanitize($REQUEST_URI) . "&comment=1";
        if (strpos($req, "?") === false) {
            $req = str_replace("&", "?", $req);
        }
        if (preg_match('/[&\?]section=/', $req) == 0) {
            $req .= "&section=" . rawurlencode($section); //add only if not present, and only if in the right form
        }
        $req = str_replace("?&", "?", $req);
        if ($jump) {
                $req .= "#$section";
        }
        addnav("", $req);
        output_notl("<form action=\"$req\" method='POST' autocomplete='false'>", true);
        Forms::previewfield("insertcommentary", $session['user']['name'], $talkline, true, array("size" => getsetting('chatlinelength', 40), "maxlength" => getsetting('maxchars', 200) - $tll));
        rawoutput("<input type='hidden' name='talkline' value='$talkline'>");
        rawoutput("<input type='hidden' name='schema' value='$schema'>");
        rawoutput("<input type='hidden' name='counter' value='{$session['counter']}'>");
        $session['commentcounter'] = $session['counter'];
        if ($section == "X") {
                $vname = getsetting("villagename", LOCATION_FIELDS);
                $iname = getsetting("innname", LOCATION_INN);
                $sections = self::commentaryLocs();
                reset($sections);
                output_notl("<select name='section'>", true);
            foreach ($sections as $key => $val) {
                    output_notl("<option value='$key'>$val</option>", true);
            }
                output_notl("</select>", true);
        } else {
                output_notl("<input type='hidden' name='section' value='$section'>", true);
        }
        if (round($limit / 2, 0) - $counttoday < 3 && getsetting('postinglimit', 1)) {
                output("`)(You have %s posts left today)`n`0", (round($limit / 2, 0) - $counttoday));
        }
        rawoutput("<div id='previewtext'></div></form>");
        tlschema();
    }
}
