<?php
namespace Lotgd;
use Lotgd\Forms;
use Lotgd\MySQL\Database;

/**
 * Tools for comment moderation.
 */
class Moderate
{
    /**
     * Show a moderation form for a commentary section.
     */
    public static function commentmoderate($intro, $section, $message, $limit = 10, $talkline = 'says', $schema = false, $viewall = false): void
    {
        if ($intro) {
            output($intro);
        }
        self::viewmoderatedcommentary($section, $message, $limit, $talkline, $schema, $viewall);
    }

    /**
     * View a commentary area for moderation purposes.
     */
    public static function viewmoderatedcommentary($section, $message = 'Interject your own commentary?', $limit = 10, $talkline = 'says', $schema = false, $viewall = false): void
    {
        global $session, $REQUEST_URI, $doublepost, $translation_namespace, $emptypost;
        if ($viewall === false) {
            rawoutput("<a name='$section'></a>");
            $args = modulehook('blockcommentarea', ['section' => $section]);
            if (isset($args['block']) && ($args['block'] == 'yes')) {
                return;
            }
            $sectselect = "section='$section' AND ";
        } else {
            $sectselect = '';
        }
        $excludes = getsetting('moderateexcludes', '');
        if ($excludes != '') {
            $array = explode(',', $excludes);
            foreach ($array as $entry) {
                $sectselect .= "section NOT LIKE '$entry' AND ";
            }
        }
        debug('Select: ' . $sectselect);
        if ($schema === false) {
            $schema = $translation_namespace;
        }
        tlschema('commentary');
        $nobios = ['motd.php' => true];
        if (!array_key_exists(basename($_SERVER['SCRIPT_NAME']), $nobios)) {
            $nobios[basename($_SERVER['SCRIPT_NAME'])] = false;
        }
        if ($nobios[basename($_SERVER['SCRIPT_NAME'])]) {
            $linkbios = false;
        } else {
            $linkbios = true;
        }
        if ($message == 'X') {
            $linkbios = true;
        }
        if ($doublepost) {
            output("`\$`bDouble post?`b`0`n");
        }
        if ($emptypost) {
            output("`\$`bWell, they say silence is a virtue.`b`0`n");
        }
        $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];
        $com = (int)httpget('comscroll');
        if ($com < 0) {
            $com = 0;
        }
        $cc = false;
        if (httpget('comscroll') !== false && (int)$session['lastcom'] == $com + 1) {
            $cid = (int)$session['lastcommentid'];
        } else {
            $cid = 0;
        }
        $session['lastcom'] = $com;
        $newadded = 0;
        if ($com > 0 || $cid > 0) {
            $sql = "SELECT COUNT(commentid) AS newadded FROM " . db_prefix('commentary') . " LEFT JOIN " . db_prefix('accounts') . " ON " . db_prefix('accounts') . ".acctid = " . db_prefix('commentary') . ".author WHERE $sectselect (" . db_prefix('accounts') . ".locked=0 or " . db_prefix('accounts') . ".locked is null) AND commentid > '$cid'";
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            $newadded = (int)$row['newadded'];
            if ($newadded > 0) {
                $session['lastcommentid'] = commentary_getcommentid($section);
                $cid = $session['lastcommentid'];
            }
        }
        modulehook('moderate', ['section' => $section, 'commentary' => $com]);
        if ($viewall !== false) {
            $sql = "SELECT * FROM " . db_prefix('commentary') . " WHERE $sectselect" . ($cid > 0 ? "commentid > $cid" : '1=1') . " ORDER BY commentid DESC LIMIT " . ($limit + 1);
        } else {
            $sql = "SELECT * FROM " . db_prefix('commentary') . " WHERE $sectselect commentid > $cid ORDER BY commentid DESC LIMIT " . ($limit + 1);
        }
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        output($sql);
        if (Database::numRows($result) > 0) {
            $cid = (int)$row['commentid'];
            $newadded = 0;
            if (isset($session['lastcommentid']) && $session['lastcommentid'] > $cid) {
                $newadded = (int)$session['lastcommentid'] - $cid;
            }
            if ($newadded < 0) {
                $newadded = 0;
            }
            $newadded = sprintf_translate('%s new comments have been added since you left this page', $newadded);
            output('`#%s`0`n', $newadded);
        }
        tlschema($schema);
        output("`c`b%s`b`c", translate_inline($message, $schema));
        // Check if $section is empty, if so, don't display the form
        if (empty($section)) {
            output("`n`n`c`b%s`b`c", translate_inline('No section specified for commentary. Please check your configuration.'));
            return;
        }
        else {
            Forms::showForm($section, $cid, $limit, $talkline);
        }
        tlschema();
    }
}
