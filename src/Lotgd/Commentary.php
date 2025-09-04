<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
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
        $translator = Translator::getInstance();
        $translator->setSchema('commentary');
        self::$comsecs['village'] = $translator->sprintfTranslate('%s Square', $vname);
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
        $translator->setSchema();
        self::$comsecs = HookHandler::hook('moderate', self::$comsecs);
        rawoutput(tlbutton_clear());
        return self::$comsecs;
    }

    /**
     * Handle POSTed commentary and perform moderation actions.
     */
    public static function addCommentary(): void
    {
        global $session, $emptypost;

        // Gather request parameters
        $section = (string)httppost('section');
        $talkline = (string)httppost('talkline');
        $schema = (string)httppost('schema');
        $comment = trim((string) httppost('insertcommentary'));
        $counter = (int)httppost('counter');
        $removeId = (int) URLDecode((string) httpget('removecomment'));
        $returnPath = (string)httpget('returnpath');
        $sectionFromUrl = (string)httpget('section');
        $rawSectionFromUrl = rawurldecode($sectionFromUrl);

        // Handle comment removal request
        if ($removeId > 0) {
            self::handleRemoval($sectionFromUrl, $returnPath, $removeId);

            return;
        }

        // Prevent double submissions using session counter
        if (array_key_exists('commentcounter', $session) && $session['commentcounter'] == $counter) {
            // Ensure there is data to process
            if ($section || $talkline || $comment) {
                $tcom = color_sanitize($comment);

                // Ignore empty or trivial posts
                if ($tcom == '' || $tcom == ':' || $tcom == '::' || $tcom == '/me') {
                    $emptypost = 1;
                } else {
                    // Check that the form section matches the URL section
                    if ($rawSectionFromUrl != $section) {
                        output('`$Please post in the section you should!');
                        debug($rawSectionFromUrl . "-" . $section);
                    } else {
                        // Valid comment, inject into the database
                        self::injectCommentary($section, $talkline, $comment, $schema);
                    }
                }
            }
        }
    }

    /**
     * Remove a commentary post and log the action.
     */
    private static function handleRemoval(string $section, string $returnPath, int $removeId): void
    {
        global $session;

        $commentary = Database::prefix('commentary');
        $accounts   = Database::prefix('accounts');
        $clans      = Database::prefix('clans');

        $sql = <<<SQL
SELECT
    c.*,             -- full commentary row
    a.name,          -- account name
    a.acctid,        -- account identifier
    a.clanrank,      -- author's clan rank
    cl.clanshort     -- clan abbreviation
FROM {$commentary} c
INNER JOIN {$accounts} a ON a.acctid = c.author -- link author data
LEFT JOIN {$clans} cl ON cl.clanid = a.clanid   -- link clan data
WHERE commentid = {$removeId}
SQL;
        $row = Database::fetchAssoc(Database::query($sql));

        $moderated = Database::prefix('moderatedcomments');
        $now       = date('Y-m-d H:i:s');
        $comment   = addslashes(serialize($row));

        $sql = <<<SQL
INSERT LOW_PRIORITY INTO {$moderated}
    (moderator, moddate, comment)            -- moderation record columns
VALUES
    ('{$session['user']['acctid']}',         -- moderator ID
     '{$now}',                               -- time of moderation
     '{$comment}'                            -- serialized comment data
    )
SQL;
        Database::query($sql);

        $sql = <<<SQL
DELETE FROM {$commentary}                 -- remove comment entry
WHERE commentid = {$removeId}             -- by comment identifier
SQL;
        Database::query($sql);

        invalidatedatacache("comments-$section");
        invalidatedatacache('comments-or11');
        $session['user']['specialinc'] == '';

        $returnPath = cmd_sanitize($returnPath);
        $returnPath = basename($returnPath);
        if (strpos($returnPath, '?') === false && strpos($returnPath, '&') !== false) {
            $x = strpos($returnPath, '&');
            $returnPath = mb_substr($returnPath, 0, $x - 1) . '?' . mb_substr($returnPath, $x + 1);
        }
        redirect($returnPath);
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
     *
     * @param string     $section Section identifier for the commentary stream
     * @param string     $talkline Verb used to prefix the comment
     * @param string     $comment  Raw comment text
     * @param string|bool $schema  Translation schema for the talkline
     */
    public static function injectCommentary(string $section, string $talkline, string $comment, $schema = false): void
    {
        global $session, $doublepost;

        // Use the current translation namespace when no schema is provided
        if ($schema === false) {
            $schema = Translator::getNamespace();
        }

        $comment = stripslashes($comment);
        Translator::getInstance()->setSchema('commentary');
        $doublepost = 0;

        if ($comment === '') {
            return;
        }

        // Sanitize user input and enforce color limits
        $commentary = self::sanitizeComment($comment);

        // Apply module hooks and format the talkline
        [$commentary, $talkline] = self::applyHooks($section, $commentary, $talkline, $schema);

        // Determine if comment is a /game command and if the user is a GM
        $args = HookHandler::hook('gmcommentarea', ['section' => $section, 'allow_gm' => false, 'commentary' => $commentary]);
        $isGameComment = strncmp($commentary, '/game', 5) === 0;
        $isGm = (($session['user']['superuser'] & SU_IS_GAMEMASTER) === SU_IS_GAMEMASTER) || $args['allow_gm'] === true;

        if ($isGameComment && $isGm) {
            // Persist system messages posted by game masters
            self::injectSystemComment($section, $args['commentary']);
        } else {
            // Check for duplicate posts and persist the comment
            $commentary = $args['commentary'];
            $commentarySql = self::buildCommentQuery($section);
            $result = Database::query($commentarySql);
            $authorId = (int) $session['user']['acctid'];

            $doublepost = self::persistComment($result, $commentary, $authorId, $section);
        }

        Translator::getInstance()->setSchema();
    }

    /**
     * Sanitize user supplied commentary, remove line breaks and limit colour codes.
     *
     * @param string $comment Raw comment text to sanitize
     * @return string Sanitized comment text
     */
    private static function sanitizeComment(string $comment): string
    {
        $commentary = str_replace('`n', '', soap($comment));
        $colorcount = 0;
        $length = strlen($commentary);

        for ($x = 0; $x < $length; $x++) {
            if (mb_substr($commentary, $x, 1) === '`') {
                $colorcount++;
                if ($colorcount >= getsetting('maxcolors', 10)) {
                    $commentary = mb_substr($commentary, 0, $x) . color_sanitize(mb_substr($commentary, $x));
                    break;
                }

                $x++;
            }
        }

        return $commentary;
    }

    /**
     * Apply module hooks and translate the talkline if needed.
     *
     * @return array{string,string} Updated commentary and talkline
     */
    private static function applyHooks(string $section, string $commentary, string $talkline, $schema): array
    {
        $args = ['section' => $section, 'commentline' => $commentary, 'commenttalk' => $talkline];
        $args = HookHandler::hook('commentary', $args);
        $commentary = $args['commentline'];
        $talkline = $args['commenttalk'];

        Translator::getInstance()->setSchema($schema);
        $talkline = Translator::translateInline($talkline);
        Translator::getInstance()->setSchema();

        if (getsetting('soap', 1)) {
            $commentary = mb_ereg_replace("'([^[:space:]]{45,45})([^[:space:]])'", '\\1 \\2', $commentary);
        }

        if ($talkline !== 'says' && mb_substr($commentary, 0, 1) !== ':' && mb_substr($commentary, 0, 2) !== '::' && mb_substr($commentary, 0, 3) !== '/me' && mb_substr($commentary, 0, 5) !== '/game') {
            $commentary = ":`3$talkline, \"`#$commentary`3\"";
        }

        return [$commentary, $talkline];
    }

    /**
     * Build the SQL query used to retrieve the latest comment in a section.
     *
     * @param string $section The section to retrieve the latest comment from
     * @return string The SQL query string
     */
    private static function buildCommentQuery(string $section): string
    {
        return 'SELECT comment, author FROM '
            . Database::prefix('commentary')
            . " WHERE section = '$section'"
            . ' ORDER BY commentid DESC LIMIT 1';
    }

    /**
     * Persist a comment if it is not a duplicate of the latest entry.
     *
     * @return int 1 if the comment is a double post, 0 otherwise
     */
    private static function persistComment($result, string $commentary, int $authorId, string $section): int
    {
        global $session;

        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            if ($row['comment'] !== $commentary || (int) $row['author'] !== $authorId) {
                self::injectRawComment($section, $authorId, $commentary);
                $session['user']['laston'] = date('Y-m-d H:i:s');

                return 0;
            }

            return 1;
        }

        self::injectRawComment($section, $authorId, $commentary);

        return 0;
    }

    /**
     * Calculate pagination information for the commentary view.
     *
     * @param string     $section    Commentary section identifier
     * @param int        $limit      Number of comments per page
     * @param mixed      $comscroll  Raw HTTP request value for comscroll
     *
     * @return array{int,int,int}   [current page, last comment id, new comment count]
     */
    private static function getPaginationData(string $section, int $limit, $comscroll): array
    {
        global $session;

        // Normalise the requested page number
        $com = (int) $comscroll;
        if ($com < 0) {
            $com = 0;
        }

        if (!isset($session['lastcom'])) {
            $session['lastcom'] = 0;
        }

        // Determine the last comment id when scrolling forward
        $cid = 0;
        if ($comscroll !== false && (int) $session['lastcom'] === $com + 1) {
            $cid = (int) $session['lastcommentid'];
        }

        $session['lastcom'] = $com;

        // Count new comments when scrolling
        $newadded = 0;
        if ($com > 0 || $cid > 0) {
            $sql = self::buildNewAddedSql($section, $cid);
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            $newadded = (int) $row['newadded'];
            Database::freeResult($result);
        }

        return [$com, $cid, $newadded];
    }

    /**
     * Build the SQL used to count new comments when paginating.
     */
    private static function buildNewAddedSql(string $section, int $cid): string
    {
        return 'SELECT COUNT(commentid) AS newadded FROM '
            . Database::prefix('commentary') . ' LEFT JOIN '
            . Database::prefix('accounts') . ' ON '
            . Database::prefix('accounts') . '.acctid = '
            . Database::prefix('commentary') . ".author WHERE section='$section' AND "
            . '(' . Database::prefix('accounts') . '.locked=0 or '
            . Database::prefix('accounts') . ".locked is null) AND commentid > '$cid'";
    }

    /**
     * Build the SQL query used to fetch commentary rows for a section.
     */
    private static function buildCommentFetchSql(string $section, int $limit, int $com, int $cid): string
    {
        $base = 'SELECT '
            . Database::prefix('commentary') . '.*, '
            . Database::prefix('accounts') . '.name, '
            . Database::prefix('accounts') . '.acctid, '
            . Database::prefix('accounts') . '.superuser, '
            . Database::prefix('accounts') . '.clanrank, '
            . Database::prefix('clans') . '.clanshort FROM '
            . Database::prefix('commentary') . ' LEFT JOIN '
            . Database::prefix('accounts') . ' ON '
            . Database::prefix('accounts') . '.acctid = '
            . Database::prefix('commentary') . '.author LEFT JOIN '
            . Database::prefix('clans') . ' ON '
            . Database::prefix('clans') . '.clanid='
            . Database::prefix('accounts') . ".clanid WHERE section = '$section'"
            . ' AND (' . Database::prefix('accounts') . '.locked=0 OR '
            . Database::prefix('accounts') . '.locked is null) ';

        if ($cid === 0) {
            return $base . 'ORDER BY commentid DESC LIMIT ' . ($com * $limit) . ',' . $limit;
        }

        return $base . "AND commentid > '$cid' ORDER BY commentid ASC LIMIT $limit";
    }

    /**
     * Retrieve commentary rows from the database.
     *
     * @return array<int, array>
     */
    private static function fetchCommentBuffer(string $section, int $limit, int $com, int $cid, string $real_request_uri): array
    {
        $sql = self::buildCommentFetchSql($section, $limit, $com, $cid);
        $useCache = $cid === 0 && $com === 0 && strstr($real_request_uri, '/moderate.php') === false;

        if ($useCache) {
            $result = Database::queryCached($sql, "comments-{$section}");
        } else {
            $result = Database::query($sql);
        }

        $buffer = [];
        while ($row = Database::fetchAssoc($result)) {
            $buffer[] = $row;
        }

        if (!$useCache) {
            Database::freeResult($result);
        }

        if ($cid !== 0) {
            $buffer = array_reverse($buffer);
        }

        return $buffer;
    }

    /**
     * Display a block of commentary and an optional input form.
     */
    public static function commentDisplay(string $intro, string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false): void
    {
        $args = HookHandler::hook('blockcommentarea', ['section' => $section]);
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
    public static function viewCommentary(
        string $section,
        string $message = 'Interject your own commentary?',
        int $limit = 10,
        string $talkline = 'says',
        $schema = false,
        bool $viewonly = false,
        bool $returnastext = false,
        $scriptname_pre = false
    ): ?string {
        global $session, $REQUEST_URI, $doublepost, $emptypost;

        // The guard for null is removed as $section is declared as string and cannot be null.

        if ($scriptname_pre === false) {
            $scriptname = ScriptName::current();
        } else {
            $scriptname = pathinfo(basename((string) $scriptname_pre), PATHINFO_FILENAME);
        }

        if ($_SERVER['REQUEST_URI'] == '/async/process.php') {
            $real_request_uri = $session['last_comment_request_uri'];
        } else {
            $real_request_uri = $_SERVER['REQUEST_URI'];
            $session['last_comment_request_uri'] = $real_request_uri;
        }

        $session['last_comment_section'] = $section;
        $session['last_comment_scriptname'] = $scriptname;

        // Capture pagination request parameter
        $comscroll = httpget('comscroll');

        rawoutput("<div id='$section-comment'>");
        if ($returnastext !== false) {
            $oldoutput = Output::getInstance();
            $ref = new \ReflectionClass(Output::class);
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, new output_collector());
            $collector = Output::getInstance();
        }

        rawoutput("<a name='$section'></a>");

        $args = HookHandler::hook('blockcommentarea', ['section' => $section]);
        if (isset($args['block']) && ($args['block'] == 'yes')) {
            return null;
        }

        if ($schema === false) {
            $schema = Translator::getNamespace();
        }
        Translator::getInstance()->setSchema('commentary');

        $nobios = ['motd' => true];
        if (!array_key_exists($scriptname, $nobios)) {
            $nobios[$scriptname] = false;
        }
        $linkbios = !$nobios[$scriptname];

        if ($message == 'X') {
            $linkbios = true;
        }

        if ($doublepost) {
            output("`$`bDouble post?`b`0`n");
        }
        if ($emptypost) {
            output("`$`bWell, they say silence is a virtue.`b`0`n");
        }

        // Determine pagination data and fetch comments
        [$com, $cid, $newadded] = self::getPaginationData($section, $limit, $comscroll);
        $commentbuffer = self::fetchCommentBuffer($section, $limit, $com, $cid, $real_request_uri);

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
        $scriptnameForReturn = $scriptname . '.php';
        $pos = strpos($real_request_uri, '?');
        $return = $scriptnameForReturn . ($pos === false ? '' : mb_substr($real_request_uri, $pos));
        $one = (strstr($return, '?') === false ? '?' : '&');

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
                    HookHandler::hook('}collapse');
                }
                output_notl("`n<hr><a href='moderate.php?area=%s'>`b`^%s`0`b</a>`n", $sec, isset($sections[$sec]) ? $sections[$sec] : "($sec)", true);
                addnav('', "moderate.php?area=$sec");
                HookHandler::hook('collapse{', ['name' => 'com-' . $sec]);
                $needclose = 1;
            } else {
                HookHandler::hook('collapse{', ['name' => 'com-' . $section]);
                $needclose = 1;
            }
            reset($v);
            foreach ($v as $key => $val) {
                $args = ['commentline' => $val];
                $args = HookHandler::hook('viewcommentary', $args);
                $val = $args['commentline'];
                output_notl($val, true);
            }
        }

        if ($returnastext !== false) {
            $collected = $collector->getOutput();
            $prop->setValue(null, $oldoutput);
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
        Translator::getInstance()->setSchema();
        if ($needclose) {
            HookHandler::hook('}collapse');
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

        $realRequestUri = self::determineReturnUrl();
        $row['comment'] = sanitize_mb(comment_sanitize($row['comment']));
        $ft = self::parseCommandPrefix($row['comment']);
        $link = 'bio.php?char=' . $row['acctid'] . '&ret=' . URLEncode($realRequestUri);

        if (!empty($row['comment'])) {
            $row['comment'] = HolidayText::holidayize($row['comment'], 'comment');
        }

        $row['name'] = self::formatName($row);

        $op = self::buildCommentHtml($ft, $row, $link, $linkBios);

        $session['user']['prefs']['timeoffset'] = $session['user']['prefs']['timeoffset'] ?? 0;
        $session['user']['prefs']['timestamp'] = $session['user']['prefs']['timestamp'] ?? 0;

        if ($session['user']['prefs']['timestamp'] == 1) {
            $session['user']['prefs']['timeformat'] = $session['user']['prefs']['timeformat'] ?? '[m/d h:ia]';
            $time = strtotime($row['postdate']) + ($session['user']['prefs']['timeoffset'] * 60 * 60);
            $s = date('`7' . $session['user']['prefs']['timeformat'] . '`0 ', (int) $time);
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
     * Determine the request URI used when linking to a player's bio.
     */
    private static function determineReturnUrl(): string
    {
        global $session;

        if ($_SERVER['REQUEST_URI'] == '/async/process.php') {
            return $session['last_comment_request_uri'] ?? $_SERVER['REQUEST_URI'];
        }

        $session['last_comment_request_uri'] = $_SERVER['REQUEST_URI'];

        return $_SERVER['REQUEST_URI'];
    }

    /**
     * Extract a command prefix (such as ::, : or /me) from a comment.
     */
    private static function parseCommandPrefix(string $comment): string
    {
        $ft = '';
        for ($x = 0; mb_strlen($ft) < 5 && $x < mb_strlen($comment); $x++) {
            if (mb_substr($comment, $x, 1) == '`' && strlen($ft) == 0) {
                $x++;
            } else {
                $ft .= mb_substr($comment, $x, 1);
            }
        }

        if (mb_substr($ft, 0, 2) == '::') {
            return mb_substr($ft, 0, 2);
        }
        if (mb_substr($ft, 0, 1) == ':') {
            return mb_substr($ft, 0, 1);
        }
        if (mb_substr($ft, 0, 3) == '/me') {
            return mb_substr($ft, 0, 3);
        }
        if (mb_substr($ft, 0, 5) == '/game') {
            return mb_substr($ft, 0, 5);
        }

        return '';
    }

    /**
     * Format a player's name with holiday text, clan tags and staff badges.
     */
    private static function formatName(array $row): string
    {
        $name = $row['name'] ?? '';

        if ($name !== '') {
            $name = HolidayText::holidayize($name, 'comment');
        }

        if (!empty($row['clanrank'])) {
            $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];
            $name = ($row['clanshort'] > '' ? "{$clanrankcolors[ceil($row['clanrank'] / 10)]}&lt;`2{$row['clanshort']}{$clanrankcolors[ceil($row['clanrank'] / 10)]}&gt; `&" : '') . $name;
        }

        if (getsetting('enable_chat_tags', 1) == 1) {
            if (($row['superuser'] & SU_MEGAUSER) == SU_MEGAUSER) {
                $name = '`$' . getsetting('chat_tag_megauser', '[ADMIN]') . '`0' . $name;
            } else {
                if (($row['superuser'] & SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER) {
                    $name = '`$' . getsetting('chat_tag_gm', '[GM]') . '`0' . $name;
                }
                if (($row['superuser'] & SU_EDIT_COMMENTS) == SU_EDIT_COMMENTS) {
                    $name = '`$' . getsetting('chat_tag_mod', '[MOD]') . '`0' . $name;
                }
            }
        }

        return $name;
    }

    /**
     * Render the final HTML for a comment line.
     */
    private static function buildCommentHtml(string $ft, array $row, string $link, bool $linkBios): string
    {
        $op = '';

        if ($ft == '::' || $ft == '/me' || $ft == ':') {
            $x = strpos($row['comment'], $ft);
            if ($x !== false) {
                if ($linkBios) {
                    $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`0<a href='$link' style='text-decoration: none'>\n`&{$row['name']}`0</a>\n`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`0`n";
                } else {
                    $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`0`n";
                }
            }
        }

        if ($op == '' && $ft == '/game' && (!isset($row['name']) || $row['name'] === '')) {
            $x = strpos($row['comment'], $ft);
            if ($x !== false) {
                $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`0`&" . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`0`n";
            }
        }

        if ($op == '') {
            if ($linkBios) {
                $op = "`0<a href='$link' style='text-decoration: none'>`&{$row['name']}`0</a>`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`3\"`0`n";
            } elseif (mb_substr($ft, 0, 5) == '/game' && ($row['name'] === '' || $row['name'] === null)) {
                $op = str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'UTF-8')));
            } else {
                $op = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, getsetting('charset', 'UTF-8'))) . "`3\"`0`n";
            }
        }

        return $op;
    }

    /**
     * Output a line prompting for new commentary submissions.
     */
    public static function talkLine(string $section, string $talkline, int $limit, $schema, int $counttoday, string $message): void
    {
        global $session;

        $args = HookHandler::hook("insertcomment", array("section" => $section));
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
        Translator::getInstance()->setSchema("commentary");

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
        Translator::getInstance()->setSchema();
    }
}
