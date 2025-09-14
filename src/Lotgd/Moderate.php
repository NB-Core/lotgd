<?php

declare(strict_types=1);

/**
 * Tools for comment moderation.
 */

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\Nav as Navigation;
use Lotgd\Output;
use Lotgd\Translator;
use Lotgd\Sanitize;
use Lotgd\Http;
use Lotgd\DateTime;
use Lotgd\PhpGenericEnvironment;
use Lotgd\MySQL\Database;
use Lotgd\Forms;
use Lotgd\HolidayText;
use Lotgd\Commentary;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;

class Moderate
{
    /**
     * Show a moderation form for a commentary section.
     */
    public static function commentmoderate(string $intro, string $section, string $message, int $limit = 10, string $talkline = 'says', ?string $schema = null, bool $viewall = false): void
    {
        $output = Output::getInstance();

        if ($intro) {
            $output->output($intro);
        }

        self::viewmoderatedcommentary($section, $message, $limit, $talkline, $schema, $viewall);
    }

    /**
     * Fetch a block of comments for moderation.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function getComments(string $sectselect, int $limit, int $com, int $cid, string $section): array
    {
        // Retrieve the batch of comments for this page
        if ($cid == 0) {
            $sql = 'SELECT ' . Database::prefix('commentary') . '.*, '
                . Database::prefix('accounts') . '.name, '
                . Database::prefix('accounts') . '.acctid, '
                . Database::prefix('accounts') . '.clanrank, '
                . Database::prefix('clans') . '.clanshort FROM ' . Database::prefix('commentary') . ' LEFT JOIN '
                . Database::prefix('accounts') . ' ON ' . Database::prefix('accounts') . '.acctid = ' . Database::prefix('commentary') . '.author LEFT JOIN '
                . Database::prefix('clans') . ' ON ' . Database::prefix('clans') . '.clanid=' . Database::prefix('accounts') . '.clanid WHERE '
                . "$sectselect (" . Database::prefix('accounts') . ".locked=0 OR " . Database::prefix('accounts') . ".locked is null ) ORDER BY commentid DESC LIMIT " . ($com * $limit) . ",$limit";
            $result = Database::query($sql);
        } else {
            $sql = 'SELECT ' . Database::prefix('commentary') . '.*, '
                . Database::prefix('accounts') . '.name, '
                . Database::prefix('accounts') . '.acctid, '
                . Database::prefix('accounts') . '.clanrank, '
                . Database::prefix('clans') . '.clanshort FROM ' . Database::prefix('commentary') . ' LEFT JOIN '
                . Database::prefix('accounts') . ' ON ' . Database::prefix('accounts') . '.acctid = ' . Database::prefix('commentary') . '.author LEFT JOIN '
                . Database::prefix('clans') . ' ON ' . Database::prefix('clans') . '.clanid=' . Database::prefix('accounts') . '.clanid WHERE '
                . "$sectselect (" . Database::prefix('accounts') . ".locked=0 OR " . Database::prefix('accounts') . ".locked is null ) AND commentid > '$cid' ORDER BY commentid ASC LIMIT $limit";
            $result = Database::query($sql);
        }

        $commentbuffer = [];
        while ($row = Database::fetchAssoc($result)) {
            $commentbuffer[] = $row;
        }
        Database::freeResult($result);
        if ($cid > 0) {
            // Reverse order when appending new comments to the top
            $commentbuffer = array_reverse($commentbuffer);
        }
        return $commentbuffer;
    }

    /**
     * Render pagination and navigation links under the comment block.
     * Encapsulates the old navigation logic for readability.
     */
    private static function showNavLinks(string $section, int $limit, int $cid, int $rowcount, bool $jump, int $com, string $requestUri, int $newadded): void
    {
        global $session;

        $output = Output::getInstance();

        $firstu = Translator::translateInline('&lt;&lt; First Unseen');
        $prev = Translator::translateInline('&lt; Previous');
        $ref = Translator::translateInline('Refresh');
        $next = Translator::translateInline('Next &gt;');
        $lastu = Translator::translateInline('Last Page &gt;&gt;');

        if ($rowcount >= $limit || $cid > 0) {
            $sql = "SELECT count(commentid) AS c FROM " . Database::prefix('commentary') . " WHERE section='$section' AND postdate > '{$session['user']['recentcomments']}'";
            $r = Database::query($sql);
            $val = Database::fetchAssoc($r);
            Database::freeResult($r);
            $val = round($val['c'] / $limit + 0.5, 0) - 1;
            if ($val > 0) {
                $first = Sanitize::comscrollSanitize($requestUri) . '&comscroll=' . $val;
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
    }

    /**
     * View a commentary area for moderation purposes.
     */
    public static function viewmoderatedcommentary(string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', ?string $schema = null, bool $viewall = false): void
    {
        global $session;
        $requestUri = PhpGenericEnvironment::getRequestUri();

        $output = Output::getInstance();
        $translator = Translator::getInstance();

        $settings = Settings::getInstance();
        $charset = $settings->getSetting('charset', 'UTF-8');

        // Decide whether to limit to a specific section or view all
        if ($viewall === false) {
            $output->rawOutput("<a name='$section'></a>");
            $args = HookHandler::hook('blockcommentarea', ['section' => $section]);
            if (isset($args['block']) && ($args['block'] == 'yes')) {
                return;
            }
            $sectselect = "section='$section' AND ";
        } else {
            $sectselect = '';
        }

        // Some sections may be globally excluded from moderation output
        $excludes = $settings->getSetting('moderateexcludes', '');
        if ($excludes != '') {
            $array = explode(',', $excludes);
            foreach ($array as $entry) {
                $sectselect .= "section NOT LIKE '$entry' AND ";
            }

            $excludedList = implode(', ', $array);
            if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
                $output->output('Excluded sections: %s`n', $excludedList);
            }
        }

        // Determine which translation schema to use for output
        if ($schema === null) {
            $schema = Translator::getNamespace();
        }
        $translator->setSchema('commentary');

        $scriptname = ScriptName::current() . '.php';

        // Some pages should not link to character bios from commentary lines
        $nobios = ['motd.php' => true];
        if (!array_key_exists($scriptname, $nobios)) {
            $nobios[$scriptname] = false;
        }
        $linkbios = !$nobios[$scriptname];
        if ($message == 'X') {
            $linkbios = true;
        }

        // Inform the player about posting issues
        if (Commentary::isDoublePost()) {
            $output->output("`$`bDouble post?`b`0`n");
        }
        if (Commentary::isEmptyPost()) {
            // Player attempted to submit an empty line
            $output->output("`$`bWell, they say silence is a virtue.`b`0`n");
        }

        $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];

        $com = (int)Http::get('comscroll');
        if ($com < 0) {
            $com = 0;
        }
        // If the user has scrolled, load comments after the last seen id
        if (Http::get('comscroll') !== false && isset($session['lastcom']) && (int)$session['lastcom'] == $com + 1) {
            $cid = (int)$session['lastcommentid'];
        } else {
            $cid = 0;
        }

        $session['lastcom'] = $com;

        // Count how many new comments have been added since the users last visit
        // Determine how many new comments exist beyond the last id
        if ($com > 0 || $cid > 0) {
            $sql = 'SELECT COUNT(commentid) AS newadded FROM '
                . Database::prefix('commentary') . ' LEFT JOIN '
                . Database::prefix('accounts') . ' ON '
                . Database::prefix('accounts') . '.acctid = '
                . Database::prefix('commentary') . ".author WHERE $sectselect "
                . '(' . Database::prefix('accounts') . '.locked=0 or ' . Database::prefix('accounts') . ".locked is null) AND commentid > '$cid'";
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            Database::freeResult($result);
            $newadded = (int)$row['newadded'];
        } else {
            $newadded = 0;
        }

        // Load the actual comment rows
        $commentbuffer = self::getComments($sectselect, $limit, $com, $cid, $section);

        $rowcount = count($commentbuffer);
        if ($rowcount > 0) {
            $session['lastcommentid'] = $commentbuffer[0]['commentid'];
        }

        $commentids = [];
        $auth = [];
        $op = [];
        $rawc = [];

        $counttoday = 0;
        for ($i = 0; $i < $rowcount; $i++) {
            $row = $commentbuffer[$i];
            $row['comment'] = Sanitize::commentSanitize($row['comment']);
            $commentids[$i] = $row['commentid'];
            if (date('Y-m-d', strtotime($row['postdate'])) == date('Y-m-d')) {
                if ($row['name'] == $session['user']['name']) {
                    $counttoday++;
                }
            }
            $x = 0;
            $ft = '';
            for ($x = 0; strlen($ft) < 5 && $x < strlen($row['comment']); $x++) {
                if (substr($row['comment'], $x, 1) == '`' && strlen($ft) == 0) {
                    $x++;
                } else {
                    $ft .= substr($row['comment'], $x, 1);
                }
            }

            $link = 'bio.php?char=' . $row['acctid'] . '&ret=' . URLEncode($_SERVER['REQUEST_URI']);

            if (substr($ft, 0, 2) == '::') {
                $ft = substr($ft, 0, 2);
            } elseif (substr($ft, 0, 1) == ':') {
                $ft = substr($ft, 0, 1);
            } elseif (substr($ft, 0, 3) == '/me') {
                $ft = substr($ft, 0, 3);
            }

            if (!empty($row['comment'])) {
                $row['comment'] = HolidayText::holidayize($row['comment'], 'comment');
            }
            if (!empty($row['name'])) {
                $row['name'] = HolidayText::holidayize($row['name'], 'comment');
            }
            if ($row['clanrank']) {
                $row['name'] = ($row['clanshort'] > '' ? "{$clanrankcolors[ceil($row['clanrank']/10)]}&lt;`2{$row['clanshort']}{$clanrankcolors[ceil($row['clanrank']/10)]}&gt; `&" : '') . $row['name'];
            }

            if ($ft == '::' || $ft == '/me' || $ft == ':') {
                $x = strpos($row['comment'], $ft);
                if ($x !== false) {
                    if ($linkbios) {
                        $op[$i] = str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], 0, $x), ENT_COMPAT,)) . "`0<a href='$link' style='text-decoration: none'>\n`&{$row['name']}`0</a>\n`& " . str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], $x + strlen($ft)), ENT_COMPAT,)) . "`0`n";
                    } else {
                        $op[$i] = str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], 0, $x), ENT_COMPAT,)) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], $x + strlen($ft)), ENT_COMPAT,)) . "`0`n";
                    }
                    $rawc[$i] = str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], 0, $x), ENT_COMPAT,)) . "`0`&{$row['name']}`0`& " . str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], $x + strlen($ft)), ENT_COMPAT,)) . "`0`n";
                }
            }

            if ($ft == '/game' && !$row['name']) {
                $x = strpos($row['comment'], $ft);
                if ($x !== false) {
                    $op[$i] = str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], 0, $x), ENT_COMPAT,)) . "`0`&" . str_replace('&amp;', '&', HTMLEntities(substr($row['comment'], $x + strlen($ft)), ENT_COMPAT,)) . "`0`n";
                }
            }

            if (!array_key_exists($i, $op) || $op[$i] == '') {
                if ($linkbios) {
                    $op[$i] = "`0<a href='$link' style='text-decoration: none'>`&{$row['name']}`0</a>`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT,)) . "`3\"`0`n";
                } elseif (substr($ft, 0, 5) == '/game' && !$row['name']) {
                    $op[$i] = str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT,));
                } else {
                    $op[$i] = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT,)) . "`3\"`0`n";
                }
                $rawc[$i] = "`&{$row['name']}`3 says, \"`#" . str_replace('&amp;', '&', HTMLEntities($row['comment'], ENT_COMPAT,)) . "`3\"`0`n";
            }

            $session['user']['prefs']['timeoffset'] = round((float)$session['user']['prefs']['timeoffset'], 1);

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
                $s = DateTime::relTime(strtotime($row['postdate']));
                $op[$i] = "`7($s)`0 " . $op[$i];
            }
            if ($message == 'X') {
                $op[$i] = "`0({$row['section']}) " . $op[$i];
            }
            if ($row['postdate'] >= $session['user']['recentcomments']) {
                $op[$i] = "<img src='images/new.gif' alt='&gt;' width='3' height='5' align='absmiddle'> " . $op[$i];
            }
            Navigation::add('', $link);
            $auth[$i] = $row['author'];
            if (isset($rawc[$i])) {
                $rawc[$i] = Sanitize::fullSanitize($rawc[$i]);
                $rawc[$i] = htmlentities($rawc[$i], ENT_QUOTES,);
            }
        }
        $i--;
        $outputcomments = [];
        $sect = 'x';

        $moderating = false;
        if (($session['user']['superuser'] & SU_EDIT_COMMENTS) && $message == 'X') {
            $moderating = true;
        }

        $del = Translator::translateInline('Del');
        $scriptname = ScriptName::current() . '.php';
        $pos = strpos($_SERVER['REQUEST_URI'], '?');
        $return = $scriptname . ($pos === false ? '' : substr($_SERVER['REQUEST_URI'], $pos));
        $one = (strstr($return, '?') === false ? '?' : '&');

        for (; $i >= 0; $i--) {
            $out = '';
            if ($moderating) {
                if ($session['user']['superuser'] & SU_EDIT_USERS) {
                    $reason = $rawc[$i] ?? '';
                    $out .= "`0[ <input type='checkbox' name='comment[{$commentids[$i]}]'> | <a href='user.php?op=setupban&userid=" . $auth[$i] . "&reason=" . rawurlencode((string) $reason) . "'>Ban</a> ]&nbsp;";
                    Navigation::add('', "user.php?op=setupban&userid=" . $auth[$i] . "&reason=" . rawurlencode((string) $reason));
                } else {
                    $out .= "`0[ <input type='checkbox' name='comment[{$commentids[$i]}]'> ]&nbsp;";
                }
                $matches = [];
                preg_match('/[(]([^)]*)[)]/', $op[$i], $matches);
                $sect = trim($matches[1]);
                if (substr($sect, 0, 5) != 'clan-' || $sect == $section) {
                    if (substr($sect, 0, 4) != 'pet-') {
                        $out .= $op[$i];
                        if (!isset($outputcomments[$sect]) || !is_array($outputcomments[$sect])) {
                            $outputcomments[$sect] = [];
                        }
                        array_push($outputcomments[$sect], $out);
                    }
                }
            } else {
                if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
                    $out .= "`2[<a href='" . $return . $one . "removecomment={$commentids[$i]}&section=$section&returnpath=/" . URLEncode($return) . "'>$del</a>`2]`0&nbsp;";
                    Navigation::add('', $return . $one . "removecomment={$commentids[$i]}&section=$section&returnpath=/" . URLEncode($return));
                }
                $out .= $op[$i];
                if (!array_key_exists($sect, $outputcomments) || !is_array($outputcomments[$sect])) {
                    $outputcomments[$sect] = [];
                }
                array_push($outputcomments[$sect], $out);
            }
        }

        if ($moderating) {
            $scriptname = ScriptName::current() . '.php';
            Navigation::add('', "$scriptname?op=commentdelete&return=" . URLEncode($_SERVER['REQUEST_URI']));
            $mod_Del1 = htmlentities(Translator::translateInline('Delete Checked Comments'), ENT_COMPAT,);
            $mod_Del2 = htmlentities(Translator::translateInline('Delete Checked & Ban (3 days)'), ENT_COMPAT,);
            $mod_Del_confirm = addslashes(htmlentities(Translator::translateInline('Are you sure you wish to ban this user and have you specified the exact reason for the ban, i.e. cut/pasted their offensive comments?'), ENT_COMPAT,));
            $mod_reason = Translator::translateInline('Reason:');
            $mod_reason_desc = htmlentities(Translator::translateInline('Banned for comments you posted.'), ENT_COMPAT,);

            $output->outputNotl("<form action='$scriptname?op=commentdelete&return=" . URLEncode($_SERVER['REQUEST_URI']) . "' method='POST'>", true);
            $output->outputNotl("<input type='submit' class='button' value=\"$mod_Del1\">", true);
            $output->outputNotl("<input type='submit' class='button' name='delnban' value=\"$mod_Del2\" onClick=\"return confirm('$mod_Del_confirm');\">", true);
            $output->outputNotl("`n$mod_reason <input name='reason0' size='40' value=\"$mod_reason_desc\" onChange=\"document.getElementById('reason').value=this.value;\">", true);
        }

        ksort($outputcomments);
        reset($outputcomments);
        $sections = Commentary::commentaryLocs();
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

        if ($moderating && $needclose) {
            HookHandler::hook('}collapse');
            $needclose = 0;
        }

        if ($moderating) {
            $output->outputNotl("`n");
            $output->rawOutput("<input type='submit' class='button' value=\"$mod_Del1\">");
            $output->rawOutput("<input type='submit' class='button' name='delnban' value=\"$mod_Del2\" onClick=\"return confirm('$mod_Del_confirm');\">");
            $output->outputNotl("`n%s ", $mod_reason);
            $output->rawOutput("<input name='reason' size='40' id='reason' value=\"$mod_reason_desc\">");
            $output->rawOutput("</form>");
            $output->outputNotl("`n");
        }

        if ($session['user']['loggedin']) {
            $args = HookHandler::hook('insertcomment', ['section' => $section]);
            if (array_key_exists('mute', $args) && $args['mute'] && !($session['user']['superuser'] & SU_EDIT_COMMENTS)) {
                $output->outputNotl('%s', $args['mutemsg']);
            } elseif ($counttoday < ($limit / 2) || ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) || !$settings->getSetting('postinglimit', 1)) {
                if ($message != 'X') {
                    $message = "`n`@" . $message . '`n';
                    $output->output($message);
                    Commentary::talkForm($section, $talkline, $limit, $schema);
                }
            } else {
                $message = "`n`@" . $message . '`n';
                $output->output($message);
                $output->output("Sorry, you've exhausted your posts in this section for now.`0`n");
            }
        }

        $jump = !isset($session['user']['prefs']['nojump']) || $session['user']['prefs']['nojump'] == false;

        // Render pagination navigation for the comment block
        self::showNavLinks($section, $limit, $cid, $rowcount, $jump, $com, $requestUri, $newadded);
        $translator->setSchema();
        if ($needclose) {
            HookHandler::hook('}collapse');
        }
    }
}
