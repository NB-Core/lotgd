<?php
namespace Lotgd;

class Commentary
{
    public static array $comsecs = [];

    public static function commentarylocs(): array
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
            self::$comsecs['superuser'] = translate_inline('Grotto');
        }
        self::$comsecs['shade'] = translate_inline('Land of the Shades');
        self::$comsecs['grassyfield'] = translate_inline('Grassy Field');
        self::$comsecs['inn'] = "$iname";
        self::$comsecs['motd'] = translate_inline('MotD');
        self::$comsecs['veterans'] = translate_inline('Veterans Club');
        self::$comsecs['hunterlodge'] = translate_inline("Hunter's Lodge");
        self::$comsecs['gardens'] = translate_inline('Gardens');
        self::$comsecs['waiting'] = translate_inline('Clan Hall Waiting Area');
        if (getsetting('betaperplayer', 1) == 1 && @file_exists('pavilion.php')) {
            self::$comsecs['beta'] = translate_inline('Pavilion');
        }
        tlschema();
        self::$comsecs = modulehook('moderate', self::$comsecs);
        rawoutput(tlbutton_clear());
        return self::$comsecs;
    }

    public static function addcommentary(): void
    {
        global $session, $emptypost;
        $section = httppost('section');
        $talkline = httppost('talkline');
        $schema = httppost('schema');
        $comment = trim(httppost('insertcommentary'));
        $counter = httppost('counter');
        $remove = URLDecode(httpget('removecomment'));
        if ($remove > 0) {
            $return = httpget('returnpath');
            $section = httpget('section');
            $sql = 'SELECT ' . db_prefix('commentary') . '.*,' . db_prefix('accounts') . '.name,' . db_prefix('accounts') . '.acctid, ' . db_prefix('accounts') . '.clanrank,' . db_prefix('clans') . '.clanshort FROM ' . db_prefix('commentary') . ' INNER JOIN ' . db_prefix('accounts') . ' ON ' . db_prefix('accounts') . '.acctid = ' . db_prefix('commentary') . '.author LEFT JOIN ' . db_prefix('clans') . ' ON ' . db_prefix('clans') . '.clanid=' . db_prefix('accounts') . '.clanid WHERE commentid=$remove';
            $row = db_fetch_assoc(db_query($sql));
            $sql = 'INSERT LOW_PRIORITY INTO ' . db_prefix('moderatedcomments') . " (moderator,moddate,comment) VALUES ('{$session['user']['acctid']}'," . date('Y-m-d H:i:s') . ",\"" . addslashes(serialize($row)) . "\")";
            db_query($sql);
            $sql = 'DELETE FROM ' . db_prefix('commentary') . " WHERE commentid='$remove';";
            db_query($sql);
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
                        self::injectcommentary($section, $talkline, $comment, $schema);
                    }
                }
            }
        }
    }

    public static function injectsystemcomment(string $section, string $comment): void
    {
        if (strncmp($comment, '/game', 5) !== 0) {
            $comment = '/game' . $comment;
        }
        self::injectrawcomment($section, 0, $comment);
    }

    public static function injectrawcomment(string $section, int $author, string $comment): void
    {
        $sql = 'INSERT INTO ' . db_prefix('commentary') . " (postdate,section,author,comment) VALUES ('" . date('Y-m-d H:i:s') . "','$section',$author,'".db_real_escape_string($comment)."')";
        db_query($sql);
        invalidatedatacache("comments-{$section}");
        invalidatedatacache('comments-or11');
    }

    public static function injectcommentary(string $section, string $talkline, string $comment, $schema = false): void
    {
        global $session, $doublepost, $translation_namespace;
        if ($schema === false) {
            $schema = $translation_namespace;
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
            $talkline = translate_inline($talkline);
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
                self::injectsystemcomment($section, $args['commentary']);
            } else {
                $commentary = $args['commentary'];
                $sql = 'SELECT comment,author FROM ' . db_prefix('commentary') . " WHERE section='$section' ORDER BY commentid DESC LIMIT 1";
                $result = db_query($sql);
                if (db_num_rows($result) > 0) {
                    $row = db_fetch_assoc($result);
                    if ($row['comment'] != stripslashes($commentary) || $row['author'] != $session['user']['acctid']) {
                        self::injectrawcomment($section, $session['user']['acctid'], $commentary);
                        $session['user']['laston'] = date('Y-m-d H:i:s');
                    } else {
                        $doublepost = 1;
                    }
                } else {
                    self::injectrawcomment($section, $session['user']['acctid'], $commentary);
                }
            }
            tlschema();
        }
    }

    public static function commentdisplay(string $intro, string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false): void
    {
        $args = modulehook('blockcommentarea', ['section' => $section]);
        if (isset($args['block']) && ($args['block'] == 'yes')) {
            return;
        }
        if ($intro) {
            output($intro);
        }
        self::viewcommentary($section, $message, $limit, $talkline, $schema);
    }

    public static function viewcommentary(string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false, bool $viewonly = false, bool $returnastext = false, $scriptname_pre = false): ?string
    {
        global $session, $REQUEST_URI, $doublepost, $translation_namespace, $emptypost;

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
            $schema = $translation_namespace;
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
                . db_prefix('commentary') . ' LEFT JOIN '
                . db_prefix('accounts') . ' ON '
                . db_prefix('accounts') . '.acctid = '
                . db_prefix('commentary') . '.author WHERE section=\'' . $section . "' AND "
                . '(' . db_prefix('accounts') . '.locked=0 or ' . db_prefix('accounts') . '.locked is null) AND commentid > \'' . $cid . "'";
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            $newadded = $row['newadded'];
        } else {
            $newadded = 0;
        }

        $commentbuffer = [];
        if ($cid == 0) {
            $sql = "SELECT " . db_prefix("commentary") . ".*, " .
                db_prefix("accounts") . ".name, " .
                db_prefix("accounts") . ".acctid, " .
                db_prefix("accounts") . ".superuser, " .
                db_prefix("accounts") . ".clanrank, " .
                db_prefix("clans") .  ".clanshort FROM " .
                db_prefix("commentary") . " LEFT JOIN " .
                db_prefix("accounts") . " ON " .
                db_prefix("accounts") .  ".acctid = " .
                db_prefix("commentary") . ".author LEFT JOIN " .
                db_prefix("clans") . " ON " .
                db_prefix("clans") . ".clanid=" .
                db_prefix("accounts") .
                ".clanid WHERE section = '$section' AND " .
                "( " . db_prefix("accounts") . ".locked=0 OR " . db_prefix("accounts") . ".locked is null ) " .
                "ORDER BY commentid DESC LIMIT " .
                ($com * $limit) . ",$limit";
            if ($com == 0 && strstr($real_request_uri, '/moderate.php') !== $real_request_uri) {
                $result = db_query_cached($sql, "comments-{$section}");
            } else {
                $result = db_query($sql);
            }
            while ($row = db_fetch_assoc($result)) {
                $commentbuffer[] = $row;
            }
        } else {
            $sql = "SELECT " . db_prefix("commentary") . ".*, " .
                db_prefix("accounts") . ".name, " .
                db_prefix("accounts") . ".acctid, " .
                db_prefix("accounts") . ".superuser, " .
                db_prefix("accounts") . ".clanrank, " .
                db_prefix("clans") . ".clanshort FROM " . db_prefix("commentary") .
                " LEFT JOIN " . db_prefix("accounts") . " ON " .
                db_prefix("accounts") . ".acctid = " .
                db_prefix("commentary") . ".author LEFT JOIN " .
                db_prefix("clans") . " ON " . db_prefix("clans") . ".clanid=" .
                db_prefix("accounts") .
                ".clanid WHERE section = '$section' AND " .
                "( " . db_prefix("accounts") . ".locked=0 OR " . db_prefix("accounts") . ".locked is null ) " .
                "AND commentid > '$cid' " .
                "ORDER BY commentid ASC LIMIT $limit";
            $result = db_query($sql);
            while ($row = db_fetch_assoc($result)) {
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
            $row['comment'] = comment_sanitize($row['comment']);
            $row['comment'] = sanitize_mb($row['comment']);
            $commentids[$i] = $row['commentid'];
            if (date('Y-m-d', strtotime($row['postdate'])) == date('Y-m-d')) {
                if (isset($session['user']['name']) && $row['name'] == $session['user']['name']) {
                    $counttoday++;
                }
            }
            $x = 0;
            $ft = '';
            for ($x = 0; strlen($ft) < 5 && $x < strlen($row['comment']); $x++) {
                if (mb_substr($row['comment'], $x, 1) == '`' && strlen($ft) == 0) {
                    $x++;
                } else {
                    $ft .= mb_substr($row['comment'], $x, 1);
                }
            }

            $link = 'bio.php?char=' . $row['acctid'] . '&ret=' . URLEncode($real_request_uri);

            if (mb_substr($ft, 0, 2) == '::') {
                $ft = mb_substr($ft, 0, 2);
            } elseif (mb_substr($ft, 0, 1) == ':') {
                $ft = mb_substr($ft, 0, 1);
            } elseif (mb_substr($ft, 0, 3) == '/me') {
                $ft = mb_substr($ft, 0, 3);
            }

            $row['comment'] = HolidayText::holidayize($row['comment'], 'comment');
            $row['name'] = HolidayText::holidayize($row['name'], 'comment');
            if ($row['clanrank']) {
                $row['name'] = ($row['clanshort'] > '' ? "{$clanrankcolors[ceil($row['clanrank']/10)]}&lt;`2{$row['clanshort']}{$clanrankcolors[ceil($row['clanrank']/10)]}&gt; `&" : '') . $row['name'];
            }

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

            if ($ft == '::' || $ft == '/me' || $ft == ':') {
                $x = strpos($row['comment'], $ft);
                if ($x !== false) {
                    if ($linkbios) {
                        $op[$i] = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0<a href='$link' style='text-decoration: none'>\n`&{$row['name']}`0</a>\n`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
                    } else {
                        $op[$i] = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
                    }
                    $rawc[$i] = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
                }
            }

            if ($ft == '/game' && !$row['name']) {
                $x = strpos($row['comment'], $ft);
                if ($x !== false) {
                    $op[$i] = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`&" . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`0`n";
                }
            }

            if (!isset($op) || !is_array($op)) {
                $op = [];
            }
            if (!array_key_exists($i, $op) || $op[$i] == '') {
                if ($linkbios) {
                    $op[$i] = "`0<a href='$link' style='text-decoration: none'>`&{$row['name']}`0</a>`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`3\"`0`n";
                } elseif (mb_substr($ft, 0, 5) == '/game' && !$row['name']) {
                    $op[$i] = str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1')));
                } else {
                    $op[$i] = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`3\"`0`n";
                }
                $rawc[$i] = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'ISO-8859-1'))) . "`3\"`0`n";
            }

            if (isset($session['user']['prefs']['timeoffset'])) {
                $session['user']['prefs']['timeoffset'] = round($session['user']['prefs']['timeoffset'], 1);
            } else {
                $session['user']['prefs']['timeoffset'] = 0;
            }

            if (!array_key_exists('timestamp', $session['user']['prefs'])) {
                $session['user']['prefs']['timestamp'] = 0;
            }

            if ($session['user']['prefs']['timestamp'] == 1) {
                if (!isset($session['user']['prefs']['timeformat'])) {
                    $session['user']['prefs']['timeformat'] = '[m/d h:ia]';
                }
                $time = strtotime($row['postdate']) + ($session['user']['prefs']['timeoffset'] * 60 * 60);
                $s = date('`7' . $session['user']['prefs']['timeformat'] . '`0 ', $time);
                $op[$i] = $s . $op[$i];
            } elseif ($session['user']['prefs']['timestamp'] == 2) {
                $s = reltime(strtotime($row['postdate']));
                $op[$i] = "`7($s)`0 " . $op[$i];
            }
            if ($message == 'X') {
                $op[$i] = "`0({$row['section']}) " . $op[$i];
            }
            if (isset($session['user']['recentcomments']) && $row['postdate'] >= $session['user']['recentcomments']) {
                $op[$i] = "<img src='images/new.gif' alt='&gt;' width='3' height='5' align='absmiddle'> " . $op[$i];
            }
            addnav('', $link);
            $auth[$i] = $row['author'];
            if (isset($rawc[$i])) {
                $rawc[$i] = full_sanitize($rawc[$i]);
                $rawc[$i] = htmlentities($rawc[$i], ENT_QUOTES, getsetting('charset', 'ISO-8859-1'));
            }
        }
        $i--;
        $outputcomments = [];
        $sect = 'x';

        $del = translate_inline('Del');
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
        $sections = self::commentarylocs();
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
            self::talkline($section, $talkline, $limit, $schema, $counttoday, $message);
        }
        rawoutput("</div><div id='$section-nav'>");
        $jump = false;
        if (!isset($session['user']['prefs']['nojump']) || $session['user']['prefs']['nojump'] == false) {
            $jump = true;
        }

        $firstu = translate_inline('&lt;&lt; First Unseen');
        $prev = translate_inline('&lt; Previous');
        $ref = translate_inline('Refresh');
        $next = translate_inline('Next &gt;');
        $lastu = translate_inline('Last Page &gt;&gt;');
        if ($rowcount >= $limit || $cid > 0) {
            if (isset($session['user']['recentcomments']) && $session['user']['recentcomments'] != '') {
                $sql = 'SELECT count(commentid) AS c FROM ' . db_prefix('commentary') . " WHERE section='$section' AND postdate > '{$session['user']['recentcomments']}'";
            } else {
                $sql = 'SELECT count(commentid) AS c FROM ' . db_prefix('commentary') . " WHERE section='$section' AND postdate > '" . DATETIME_DATEMIN . "'";
            }
            $r = db_query($sql);
            $val = db_fetch_assoc($r);
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
            db_free_result($result);
        }
        tlschema();
        if ($needclose) {
            modulehook('}collapse');
        }
        rawoutput('</div>');
        return null;
    }

    public static function talkline(string $section, string $talkline, int $limit, $schema, int $counttoday, string $message): void
    {
        $args = modulehook("insertcomment", array("section"=>$section));
        if (array_key_exists("mute",$args) && $args['mute'] &&
                        !($session['user']['superuser'] & SU_EDIT_COMMENTS)) {
                output_notl("%s", $args['mutemsg']);
        } elseif ($counttoday<($limit/2)
                        || ($session['user']['superuser']&~SU_DOESNT_GIVE_GROTTO)
                        || ($session['user']['superuser']&SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER
                        || !getsetting('postinglimit',1)){
                if ($message!="X"){
                        $message="`n`@$message`n";
                        output($message);
                        self::talkform($section,$talkline,$limit,$schema);
                }
        }else{
                $message="`n`@$message`n";
                output($message);
                output("Sorry, you've exhausted your posts in this section for now.`0`n");
        }
    }

    public static function talkform(string $section, string $talkline, int $limit = 10, $schema = false)
    {
      global $REQUEST_URI,$session,$translation_namespace;
        if ($schema===false) $schema=$translation_namespace;
        tlschema("commentary");

        $jump = false;
        if (isset($session['user']['prefs']['nojump']) && $session['user']['prefs']['nojump'] == true) {
                $jump = true;
        }

        $counttoday=0;
        if (mb_substr($section,0,5)!="clan-"){
                $sql = "SELECT author FROM " . db_prefix("commentary") . " WHERE section='$section' AND postdate>'".date("Y-m-d 00:00:00")."' ORDER BY commentid DESC LIMIT $limit";
                $result = db_query($sql);
                while ($row=db_fetch_assoc($result)){
                        if ($row['author']==$session['user']['acctid']) $counttoday++;
                }
                if (round($limit/2,0)-$counttoday <= 0 && getsetting('postinglimit',1)){
                        if ($session['user']['superuser']&~SU_DOESNT_GIVE_GROTTO){
                                output("`n`)(You'd be out of posts if you weren't a superuser or moderator.)`n");
                        }else{
                                output("`n`)(You are out of posts for the time being.  Once some of your existing posts have moved out of the comment area, you'll be allowed to post again.)`n");
                                return false;
                        }
                }
        }
        if (translate_inline($talkline,$schema)!="says")
                $tll = strlen(translate_inline($talkline,$schema))+11;
                else $tll=0;
        $req = comscroll_sanitize($REQUEST_URI)."&comment=1";
        if (strpos($req,"?")===false) $req = str_replace("&","?",$req);
        if (preg_match('/[&\?]section=/',$req)==0) $req.="&section=".rawurlencode($section); //add only if not present, and only if in the right form
        $req = str_replace("?&","?",$req);
        if ($jump) {
                $req .= "#$section";
        }
        addnav("",$req);
        output_notl("<form action=\"$req\" method='POST' autocomplete='false'>",true);
        Forms::previewfield("insertcommentary", $session['user']['name'], $talkline, true, array("size"=>getsetting('chatlinelength',40), "maxlength"=>getsetting('maxchars',200)-$tll));
        rawoutput("<input type='hidden' name='talkline' value='$talkline'>");
        rawoutput("<input type='hidden' name='schema' value='$schema'>");
        rawoutput("<input type='hidden' name='counter' value='{$session['counter']}'>");
        $session['commentcounter'] = $session['counter'];
        if ($section=="X"){
                $vname = getsetting("villagename", LOCATION_FIELDS);
                $iname = getsetting("innname", LOCATION_INN);
                $sections = self::commentarylocs();
                reset ($sections);
                output_notl("<select name='section'>",true);
                foreach ($sections as $key=>$val) {
                        output_notl("<option value='$key'>$val</option>",true);
                }
                output_notl("</select>",true);
        }else{
                output_notl("<input type='hidden' name='section' value='$section'>",true);
        }
        if (round($limit/2,0)-$counttoday < 3 && getsetting('postinglimit',1)){
                output("`)(You have %s posts left today)`n`0",(round($limit/2,0)-$counttoday));
        }
        rawoutput("<div id='previewtext'></div></form>");
        tlschema();
    }
}
