<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\Nav as Navigation;
use Lotgd\Output;
use Lotgd\Translator;
use Lotgd\Sanitize;
use Lotgd\Http;
use Lotgd\DataCache;
use Lotgd\DateTime;
use Lotgd\Censor;
use Lotgd\Redirect;
use Lotgd\PhpGenericEnvironment;
use Lotgd\MySQL\Database;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;

class Commentary
{
    public static array $comsecs = [];

    /**
     * Flag indicating a double post attempt occurred.
     */
    private static bool $doublepost = false;

    /**
     * Flag indicating an empty post was submitted.
     */
    private static bool $emptypost = false;

    /**
     * Determine whether the last action was a double post.
     */
    public static function isDoublePost(): bool
    {
        return self::$doublepost;
    }

    /**
     * Set the double post flag.
     */
    public static function setDoublePost(bool $flag): void
    {
        self::$doublepost = $flag;
    }

    /**
     * Determine whether the last action attempted an empty post.
     */
    public static function isEmptyPost(): bool
    {
        return self::$emptypost;
    }

    /**
     * Set the empty post flag.
     */
    public static function setEmptyPost(bool $flag): void
    {
        self::$emptypost = $flag;
    }

    /**
     * Retrieve all sections that accept commentary posts.
     */
    public static function commentaryLocs(): array
    {
        global $session;

        $settings = Settings::getInstance();

        if (is_array(self::$comsecs) && count(self::$comsecs)) {
            return self::$comsecs;
        }

        $vname = $settings->getSetting('villagename', LOCATION_FIELDS);
        $iname = $settings->getSetting('innname', LOCATION_INN);
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
        if ($settings->getSetting('betaperplayer', 1) == 1 && @file_exists('pavilion.php')) {
            self::$comsecs['beta'] = Translator::translateInline('Pavilion');
        }
        $translator->setSchema();
        self::$comsecs = HookHandler::hook('moderate', self::$comsecs);
        Output::getInstance()->rawOutput(Translator::clearButton());
        return self::$comsecs;
    }

    /**
     * Handle POSTed commentary and perform moderation actions.
     */
    public static function addCommentary(): void
    {
        global $session;

        self::setEmptyPost(false);
        $output = Output::getInstance();

        // Gather request parameters
        $section = (string)Http::post('section');
        $talkline = (string)Http::post('talkline');
        $schema = (string)Http::post('schema');
        $comment = trim((string) Http::post('insertcommentary'));
        $counter = (int)Http::post('counter');
        $removeId = (int) URLDecode((string) Http::get('removecomment'));
        $returnPath = (string)Http::get('returnpath');
        $sectionFromUrl = (string)Http::get('section');
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
                $tcom = Sanitize::colorSanitize($comment);

                // Ignore empty or trivial posts
                if ($tcom == '' || $tcom == ':' || $tcom == '::' || $tcom == '/me') {
                    self::setEmptyPost(true);
                } else {
                    // Check that the form section matches the URL section
                    if ($rawSectionFromUrl != $section) {
                        $output->output('`$Please post in the section you should!');
                        $output->debug($rawSectionFromUrl . "-" . $section);
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

        DataCache::getInstance()->invalidatedatacache("comments-$section");
        DataCache::getInstance()->invalidatedatacache('comments-or11');
        $session['user']['specialinc'] == '';

        $returnPath = Sanitize::cmdSanitize($returnPath);
        $returnPath = basename($returnPath);
        if (strpos($returnPath, '?') === false && strpos($returnPath, '&') !== false) {
            $x = strpos($returnPath, '&');
            $returnPath = mb_substr($returnPath, 0, $x - 1) . '?' . mb_substr($returnPath, $x + 1);
        }
        Redirect::redirect($returnPath);
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
        DataCache::getInstance()->invalidatedatacache("comments-{$section}");
        DataCache::getInstance()->invalidatedatacache('comments-or11');
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
        global $session;

        $translator = Translator::getInstance();

        // Use the current translation namespace when no schema is provided
        if ($schema === false) {
            $schema = Translator::getNamespace();
        }

        $comment = stripslashes($comment);
        $translator->setSchema('commentary');
        self::setDoublePost(false);

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

            self::setDoublePost((bool) self::persistComment($result, $commentary, $authorId, $section));
        }

        $translator->setSchema();
    }

    /**
     * Sanitize user supplied commentary, remove line breaks and limit colour codes.
     *
     * @param string $comment Raw comment text to sanitize
     * @return string Sanitized comment text
     */
    private static function sanitizeComment(string $comment): string
    {
        $commentary = str_replace('`n', '', Censor::soap($comment));
        $colorcount = 0;
        $length = strlen($commentary);

        for ($x = 0; $x < $length; $x++) {
            if (mb_substr($commentary, $x, 1) === '`') {
                $colorcount++;
                if ($colorcount >= Settings::getInstance()->getSetting('maxcolors', 10)) {
                    $commentary = mb_substr($commentary, 0, $x) . Sanitize::colorSanitize(mb_substr($commentary, $x));
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
        $translator = Translator::getInstance();
        $args = ['section' => $section, 'commentline' => $commentary, 'commenttalk' => $talkline];
        $args = HookHandler::hook('commentary', $args);
        $commentary = $args['commentline'];
        $talkline = $args['commenttalk'];

        $translator->setSchema($schema);
        $talkline = Translator::translateInline($talkline);
        $translator->setSchema();

        if (Settings::getInstance()->getSetting('soap', 1)) {
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
        $output = Output::getInstance();

        $args = HookHandler::hook('blockcommentarea', ['section' => $section]);
        if (isset($args['block']) && ($args['block'] == 'yes')) {
            return;
        }
        if ($intro) {
            $output->output($intro);
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
        global $session;
        $requestUri = PhpGenericEnvironment::getRequestUri();

        $output = Output::getInstance();
        $translator = Translator::getInstance();

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
        $comscroll = Http::get('comscroll');

        $output->rawOutput("<div id='$section-comment'>");
        if ($returnastext !== false) {
            $oldoutput = Output::getInstance();
            $ref = new \ReflectionClass(Output::class);
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, new Output());
            $collector = Output::getInstance();
        }

        $output->rawOutput("<a name='$section'></a>");

        $args = HookHandler::hook('blockcommentarea', ['section' => $section]);
        if (isset($args['block']) && ($args['block'] == 'yes')) {
            return null;
        }

        if ($schema === false) {
            $schema = Translator::getNamespace();
        }
        $translator->setSchema('commentary');

        $nobios = ['motd' => true];
        if (!array_key_exists($scriptname, $nobios)) {
            $nobios[$scriptname] = false;
        }
        $linkbios = !$nobios[$scriptname];

        if ($message == 'X') {
            $linkbios = true;
        }

        if (self::isDoublePost()) {
            $output->output("`$`bDouble post?`b`0`n");
        }
        if (self::isEmptyPost()) {
            $output->output("`$`bWell, they say silence is a virtue.`b`0`n");
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

        $commentids = [];
        $auth = [];
        $op = [];

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
                Navigation::add('', $return . $one . "removecomment={$commentids[$i]}&section=$section&returnpath=/" . URLEncode($return));
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
                $output->outputNotl("`n<hr><a href='moderate.php?area=%s'>`b`^%s`0`b</a>`n", $sec, isset($sections[$sec]) ? $sections[$sec] : "($sec)", true);
                Navigation::add('', "moderate.php?area=$sec");
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
                $output->outputNotl($val, true);
            }
        }

        if ($returnastext !== false) {
            $collected = $collector->getOutput();
            $prop->setValue(null, $oldoutput);
            return $collected;
        }
        $output->rawOutput('</div>');
        $output->rawOutput("<div id='$section-talkline'>");

        if ($session['user']['loggedin'] && !$viewonly) {
            self::talkLine($section, $talkline, $limit, $schema, $counttoday, $message);
        }
        $output->rawOutput("</div><div id='$section-nav'>");
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
                $first = Sanitize::comscrollSanitize($requestUri) . '&comscroll=' . ($val);
                $first = str_replace('?&', '?', $first);
                if (!strpos($first, '?')) {
                    $first = str_replace('&', '?', $first);
                }
                $first .= '&refresh=1';
                if ($jump) {
                    $first .= "#$section";
                }
                $output->outputNotl("<a href=\"$first\">$firstu</a>", true);
                Navigation::add('', $first);
            } else {
                $output->outputNotl($firstu, true);
            }
            $req = Sanitize::comscrollSanitize($requestUri) . '&comscroll=' . ($com + 1);
            $req = str_replace('?&', '?', $req);
            if (!strpos($req, '?')) {
                $req = str_replace('&', '?', $req);
            }
            $req .= '&refresh=1';
            if ($jump) {
                $req .= "#$section";
            }
            $output->outputNotl("<a href=\"$req\">$prev</a>", true);
            Navigation::add('', $req);
        } else {
            $output->outputNotl("$firstu $prev", true);
        }
        $last = Navigation::appendLink(Sanitize::comscrollSanitize($requestUri), 'refresh=1');

        $last = Navigation::appendCount($last);

        $last = str_replace('?&', '?', $last);
        if ($jump) {
            $last .= "#$section";
        }
        $output->outputNotl("&nbsp;<a href=\"$last\">$ref</a>&nbsp;", true);
        Navigation::add('', $last);
        if ($com > 0 || ($cid > 0 && $newadded > $limit)) {
            $req = Sanitize::comscrollSanitize($requestUri) . '&comscroll=' . ($com - 1);
            $req = str_replace('?&', '?', $req);
            if (!strpos($req, '?')) {
                $req = str_replace('&', '?', $req);
            }
            $req .= '&refresh=1';
            if ($jump) {
                $req .= "#$section";
            }
            $output->outputNotl(" <a href=\"$req\">$next</a>", true);
            Navigation::add('', $req);
            $output->outputNotl(" <a href=\"$last\">$lastu</a>", true);
        } else {
            $output->outputNotl("$next $lastu", true);
        }
        $translator->setSchema();
        if ($needclose) {
            HookHandler::hook('}collapse');
        }
        $output->rawOutput('</div>');
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
        $row['comment'] = Sanitize::sanitizeMb(Sanitize::commentSanitize($row['comment']));
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
            $s = DateTime::relTime(strtotime($row['postdate']));
            $op = "`7($s)`0 " . $op;
        }

        if (isset($session['user']['recentcomments']) && $row['postdate'] >= $session['user']['recentcomments']) {
            $op = "<img src='images/new.gif' alt='&gt;' width='3' height='5' align='absmiddle'> " . $op;
        }

        Navigation::add('', $link);

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
        $superuser = (int) ($row['superuser'] ?? 0);

        if ($name !== '') {
            $name = HolidayText::holidayize($name, 'comment');
        }

        if (!empty($row['clanrank'])) {
            $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];
            $name = ($row['clanshort'] > '' ? "{$clanrankcolors[ceil($row['clanrank'] / 10)]}&lt;`2{$row['clanshort']}{$clanrankcolors[ceil($row['clanrank'] / 10)]}&gt; `&" : '') . $name;
        }

        $settings = Settings::getInstance();

        if ($settings->getSetting('enable_chat_tags', 1) == 1) {
            if (($superuser & SU_MEGAUSER) == SU_MEGAUSER) {
                $name = '`$' . $settings->getSetting('chat_tag_megauser', '[ADMIN]') . '`0' . $name;
            } else {
                if (($superuser & SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER) {
                    $name = '`$' . $settings->getSetting('chat_tag_gm', '[GM]') . '`0' . $name;
                }
                if (($superuser & SU_EDIT_COMMENTS) == SU_EDIT_COMMENTS) {
                    $name = '`$' . $settings->getSetting('chat_tag_mod', '[MOD]') . '`0' . $name;
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
        $settings = Settings::getInstance();
        $charset = $settings->getSetting('charset', 'UTF-8');

        if ($ft == '::' || $ft == '/me' || $ft == ':') {
            $x = strpos($row['comment'], $ft);
            if ($x !== false) {
                if ($linkBios) {
                    $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, $charset)) . "`0<a href='$link' style='text-decoration: none'>\n`&{$row['name']}`0</a>\n`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, $charset)) . "`0`n";
                } else {
                    $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, $charset)) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, $charset)) . "`0`n";
                }
            }
        }

        if ($op == '' && $ft == '/game' && (!isset($row['name']) || $row['name'] === '')) {
            $x = strpos($row['comment'], $ft);
            if ($x !== false) {
                $op = str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], 0, $x), ENT_COMPAT, $charset)) . "`0`&" . str_replace('&amp;', '&', HTMLEntities(mb_substr($row['comment'], $x + strlen($ft)), ENT_COMPAT, $charset)) . "`0`n";
            }
        }

        if ($op == '') {
            if ($linkBios) {
                $op = "`0<a href='$link' style='text-decoration: none'>`&{$row['name']}`0</a>`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, $charset)) . "`3\"`0`n";
            } elseif (mb_substr($ft, 0, 5) == '/game' && ($row['name'] === '' || $row['name'] === null)) {
                $op = str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, $charset));
            } else {
                $op = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT, $charset)) . "`3\"`0`n";
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

        $output = Output::getInstance();

        $args = HookHandler::hook("insertcomment", array("section" => $section));
        if (
            array_key_exists("mute", $args) && $args['mute'] &&
                        !($session['user']['superuser'] & SU_EDIT_COMMENTS)
        ) {
                $output->outputNotl("%s", $args['mutemsg']);
        } elseif (
            $counttoday < ($limit / 2)
                        || ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO)
                        || ($session['user']['superuser'] & SU_IS_GAMEMASTER) == SU_IS_GAMEMASTER
                        || !Settings::getInstance()->getSetting('postinglimit', 1)
        ) {
            if ($message != "X") {
                    $message = "`n`@$message`n";
                    $output->output($message);
                    self::talkForm($section, $talkline, $limit, $schema);
            }
        } else {
                $message = "`n`@$message`n";
                $output->output($message);
                $output->output("Sorry, you've exhausted your posts in this section for now.`0`n");
        }
    }

    /**
     * Render the HTML form used to submit new commentary.
     */
    public static function talkForm(string $section, string $talkline, int $limit = 10, $schema = false)
    {
        global $session;
        $requestUri = PhpGenericEnvironment::getRequestUri();

        $output = Output::getInstance();
        $translator = Translator::getInstance();

        if ($schema === false) {
            $schema = Translator::getNamespace();
        }
        $translator->setSchema("commentary");

        $settings = Settings::getInstance();

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
            if (round($limit / 2, 0) - $counttoday <= 0 && $settings->getSetting('postinglimit', 1)) {
                if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
                        $output->output("`n`)(You'd be out of posts if you weren't a superuser or moderator.)`n");
                } else {
                        $output->output("`n`)(You are out of posts for the time being.  Once some of your existing posts have moved out of the comment area, you'll be allowed to post again.)`n");
                        return false;
                }
            }
        }
        if (Translator::translateInline($talkline, $schema) != "says") {
                $tll = strlen(Translator::translateInline($talkline, $schema)) + 11;
        } else {
            $tll = 0;
        }
        $req = Sanitize::comscrollSanitize($requestUri) . "&comment=1";
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
        Navigation::add("", $req);
        $output->outputNotl("<form action=\"$req\" method='POST' autocomplete='false'>", true);

        Forms::previewfield(
            "insertcommentary",
            $session['user']['name'],
            $talkline,
            true,
            [
                "size" => $settings->getSetting('chatlinelength', 40),
                "maxlength" => $settings->getSetting('maxchars', 200) - $tll,
            ]
        );
        $output->rawOutput("<input type='hidden' name='talkline' value='$talkline'>");
        $output->rawOutput("<input type='hidden' name='schema' value='$schema'>");
        $output->rawOutput("<input type='hidden' name='counter' value='{$session['counter']}'>");
        $session['commentcounter'] = $session['counter'];
        if ($section == "X") {
                $vname = $settings->getSetting("villagename", LOCATION_FIELDS);
                $iname = $settings->getSetting("innname", LOCATION_INN);
                $sections = self::commentaryLocs();
                reset($sections);
                $output->outputNotl("<select name='section'>", true);
            foreach ($sections as $key => $val) {
                    $output->outputNotl("<option value='$key'>$val</option>", true);
            }
                $output->outputNotl("</select>", true);
        } else {
                $output->outputNotl("<input type='hidden' name='section' value='$section'>", true);
        }
        if (round($limit / 2, 0) - $counttoday < 3 && $settings->getSetting('postinglimit', 1)) {
                $output->output("`)(You have %s posts left today)`n`0", (round($limit / 2, 0) - $counttoday));
        }
        $output->rawOutput("<div id='previewtext'></div></form>");
        $translator->setSchema();
    }
}
