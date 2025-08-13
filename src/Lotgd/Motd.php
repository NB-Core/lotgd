<?php

declare(strict_types=1);

/**
 * Helpers for MOTD administration.
 */

namespace Lotgd;

use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\Forms;
use Lotgd\Nltoappon;

class Motd
{
    /**
     * Display MOTD administration interface.
     *
     * @param int  $id   MOTD identifier
     * @param bool $poll Whether the MOTD is a poll
     */
    public static function motdAdmin(int $id, bool $poll = false): void
    {
        global $session;
        $id = (int)$id;
        if ($id > 0) {
            $sql = 'SELECT motdtitle,motdbody,motddate,motdauthor,motdtype FROM ' . Database::prefix('motd') . " WHERE motditem=$id";
            $result = Database::query($sql);
            if (Database::numRows($result) > 0) {
                $row = Database::fetchAssoc($result);
                $subject = $row['motdtitle'];
                $body = $row['motdbody'];
                $date = $row['motddate'];
                $author = $row['motdauthor'];
                if ((int)$row['motdtype'] === 1) {
                    self::pollItem($id, $subject, $body, (string)$author, $date, false);
                } else {
                    self::motdItem($subject, $body, (string)$author, $date, $id);
                }
            }
        }
        $sql = 'SELECT motdtitle,motdbody,motddate,motdauthor,motditem,motdtype FROM ' . Database::prefix('motd') . ($poll ? ' WHERE motdtype=1' : ' WHERE motdtype=0') . ' ORDER BY motddate DESC';
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            if ((int)$row['motdtype'] === 1) {
                self::pollItem((int)$row['motditem'], $row['motdtitle'], $row['motdbody'], (string)$row['motdauthor'], $row['motddate']);
            } else {
                self::motdItem($row['motdtitle'], $row['motdbody'], (string)$row['motdauthor'], $row['motddate'], (int)$row['motditem']);
            }
        }
    }

    /**
     * Render a MOTD item.
     */
    public static function motdItem(string $subject, string $body, string $author, string $date, int $id): void
    {
        global $output;
        $anchor = 'motd' . date('YmdHis', strtotime($date));
        rawoutput("<a name='$anchor'>");
        rawoutput('<div class="motditem" style="margin-bottom: 15px;">');
        output_notl('<h4>%s</h4>', $subject, true);
        modulehook('motd-item-intercept', ['id' => $id]);
        $body = Nltoappon::convert($body);
        output_notl('<div>%s</div>', $body, true);
        output_notl('<small>%s %s - %s</small>', Translator::translateInline('Posted by'), $author, $date, true);
        self::motdAdminLinks($id, false);
        rawoutput('</div>');
        rawoutput('<hr>');
        rawoutput('</a>');
    }

    /**
     * Render a poll entry.
     */
    public static function pollItem(int $id, string $subject, string $body, string $author, string $date, bool $showpoll = true): void
    {
        global $output, $session;

        $sql = 'SELECT count(resultid) AS c, MAX(choice) AS choice FROM ' . Database::prefix('pollresults') . " WHERE motditem='$id' AND account='{$session['user']['acctid']}'";
        $result = Database::query($sql);
        $row = Database::numRows($result) > 0 ? Database::fetchAssoc($result) : [];
        $choice = $row['choice'] ?? null;

        $bodyData = @unserialize(stripslashes($body));
        if (!is_array($bodyData)) {
            $bodyData = ['body' => $body, 'opt' => []];
        }

        rawoutput('<div class="pollitem">');
        output_notl('<h4>%s</h4>', $subject, true);
        $bodyText = Nltoappon::convert(stripslashes((string)$bodyData['body']));
        output_notl('<div>%s</div>', $bodyText, true);
        output_notl('<small>%s %s - %s</small>', Translator::translateInline('Posted by'), $author, $date, true);

        $sql = 'SELECT count(resultid) AS c, choice FROM ' . Database::prefix('pollresults') . " WHERE motditem='$id' GROUP BY choice ORDER BY choice";
        $results = Database::queryCached($sql, "poll-$id");
        $choices = [];
        $totalanswers = 0;
        $maxitem = 0;
        foreach ($results as $r) {
            $choices[$r['choice']] = (int)$r['c'];
            $totalanswers += (int)$r['c'];
            if ((int)$r['c'] > $maxitem) {
                $maxitem = (int)$r['c'];
            }
        }

        if ($session['user']['loggedin'] && $showpoll) {
            rawoutput("<form action='motd.php?op=vote' method='POST'>");
            rawoutput("<input type='hidden' name='motditem' value='$id'>", true);
        }

        foreach ($bodyData['opt'] as $key => $val) {
            if (trim((string)$val) !== '') {
                if ($totalanswers <= 0) {
                    $totalanswers = 1;
                }
                $percent = isset($choices[$key]) ? round($choices[$key] / $totalanswers * 100, 1) : 0;

                if ($session['user']['loggedin'] && $showpoll) {
                    rawoutput("<input type='radio' name='choice' value='$key'" . ($choice == $key ? ' checked' : '') . '>');
                }

                output_notl('%s (%s - %s%%)`n', stripslashes((string)$val), $choices[$key] ?? 0, $percent);

                $width = ($maxitem == 0 || !isset($choices[$key])) ? 1 : (int)round($choices[$key] / $maxitem * 400, 0);
                $width = max($width, 1);
                rawoutput("<img src='images/rule.gif' width='$width' height='2' alt='$percent'><br>");
            }
        }

        if ($session['user']['loggedin'] && $showpoll) {
            $vote = Translator::translateInline('Vote');
            rawoutput("<input type='submit' class='button' value='$vote'></form>");
        }

        if ($showpoll) {
            rawoutput('<div>' . Translator::translateInline('Poll ID') . ': ' . $id . '</div>');
        }
        self::motdAdminLinks($id, true);
        rawoutput('</div>');
        rawoutput('<hr>');
    }

    /**
     * Display edit form for a MOTD record.
     */
    public static function motdForm(int $id, array $data = []): void
    {
        require_once 'lib/showform.php';
        $sql = 'SELECT motdtitle,motdbody,motdtype FROM ' . Database::prefix('motd') . " WHERE motditem='$id'";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            $title = $row['motdtitle'];
            $body = $row['motdbody'];
            $poll = $row['motdtype'];
        } else {
            $title = '';
            $body = '';
            $poll = '0';
        }
        $form = [
            'Motd,title',
            'motdtitle' => 'Title,string,50',
            'motdbody'  => 'Body,textarea,37',
            'motdtype'  => 'Type,viewhiddenonly',
        ];
        if ($id > 0) {
            $form['changeauthor'] = 'Change Author,checkbox';
            $form['changedate'] = 'Change Date (force popup),checkbox';
        }
        output('<form action="motd.php?op=save&id=' . (int)$id . '" method="post">', true);
        $defaults = [
            'motdtitle'    => $title,
            'motdbody'     => $body,
            'motdtype'     => $poll,
            'changeauthor' => 0,
            'changedate'   => 0,
        ];
        $data = array_merge($defaults, $data);
        // The third parameter 'true' enables form preview mode.
        Forms::showForm($form, $data, true);
        $preview = Translator::translateInline('Preview');
        $save = Translator::translateInline('Save');
        rawoutput("<input type='submit' name='preview' class='button' value='$preview'>");
        rawoutput("<input type='submit' class='button' value='$save'>");
        rawoutput('</form>');
    }

    /**
     * Show form to create a new poll entry.
     */
    public static function motdPollForm(): void
    {
        $title = httppost('motdtitle');
        $body  = httppost('motdbody');

        output('`$NOTE:`^ Polls cannot be edited after creation.`0`n`n');
        rawoutput("<form action='motd.php?op=savenew' method='post'>");
        output('Subject: ');
        rawoutput("<input type='text' size='50' name='motdtitle' value=\"" . HTMLEntities(stripslashes((string)$title), ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\"><br/>");
        output('Body:`n');
        rawoutput("<textarea class='input' name='motdbody' cols='37' rows='5'>" . HTMLEntities(stripslashes((string)$body), ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "</textarea><br/>");
        $option = Translator::translateInline('Option');
        output('Choices:`n');
        $pollitem = "$option <input name='opt[]'><br/>";
        for ($i = 0; $i < 5; $i++) {
            rawoutput($pollitem);
        }
        rawoutput("<div id='hidepolls'></div>");
        rawoutput("<script language='JavaScript'>document.getElementById('hidepolls').innerHTML = '';</script>", true);
        $addi = Translator::translateInline('Add Poll Item');
        $add = Translator::translateInline('Add');
        rawoutput("<a href=\"#\" onClick=\"javascript:document.getElementById('hidepolls').innerHTML += '" . addslashes($pollitem) . "'; return false;\">$addi</a><br>");
        rawoutput("<input type='submit' class='button' value='$add'></form>");
    }

    /**
     * Insert or update a MOTD entry.
     */
    public static function saveMotd(int $id): void
    {
        global $session;
        $title = httppost('motdtitle');
        $body = httppost('motdbody');
        $type = (int) httppost('motdtype');
        $changeauthor = (bool) httppost('changeauthor');
        $changedate = (bool) httppost('changedate');

        $author = $session['user']['acctid'];
        $date = date('Y-m-d H:i:s');
        if ($id > 0) {
            $sql = 'SELECT motdauthor,motddate FROM ' . Database::prefix('motd') . " WHERE motditem=$id";
            $res = Database::query($sql);
            $row = Database::numRows($res) > 0 ? Database::fetchAssoc($res) : [];
            if (!$changeauthor && isset($row['motdauthor'])) {
                $author = $row['motdauthor'];
            }
            if (!$changedate && isset($row['motddate'])) {
                $date = $row['motddate'];
            }
            $sql = 'UPDATE ' . Database::prefix('motd') .
                " SET motdtitle=\"$title\",motdbody=\"$body\",motdtype=$type,motddate=\"$date\",motdauthor=$author WHERE motditem=$id";
        } else {
            $sql = 'INSERT INTO ' . Database::prefix('motd') .
                " (motdtitle,motdbody,motddate,motdtype,motdauthor) VALUES (\"$title\",\"$body\",\"$date\",$type,$author)";
        }
        Database::query($sql);
        invalidatedatacache('motd');
        invalidatedatacache('lastmotd');
        invalidatedatacache('motddate');
    }

    /**
     * Create a new poll entry from form data.
     */
    public static function savePoll(): void
    {
        global $session;
        $title = httppost('motdtitle');
        $text  = httppost('motdbody');
        $choices = httppost('opt');
        if (!is_array($choices)) {
            $choices = [];
        }
        $data = ['body' => $text, 'opt' => $choices];
        $body = addslashes(serialize($data));
        $date = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . Database::prefix('motd') .
            " (motdtitle,motdbody,motddate,motdtype,motdauthor) VALUES (\"$title\",\"$body\",\"$date\",1,{$session['user']['acctid']})";
        Database::query($sql);
        invalidatedatacache('motd');
    }

    /**
     * Delete a MOTD record.
     */
    public static function motdDel(int $id): void
    {
        $sql = 'DELETE FROM ' . Database::prefix('motd') . " WHERE motditem='$id'";
        Database::query($sql);
        invalidatedatacache('motd');
        invalidatedatacache('lastmotd');
        invalidatedatacache('motddate');
    }

    /**
     * Output edit and delete links for an entry if user can post.
     */
    private static function motdAdminLinks(int $id, bool $poll): void
    {
        global $session;
        if ($session['user']['superuser'] & SU_POST_MOTD) {
            $edit = Translator::translateInline('Edit');
            $del = Translator::translateInline('Del');
            $conf = Translator::translateInline('Are you sure you wish to delete this entry?');
            $editop = $poll ? 'addpoll' : 'add';
            rawoutput(" [ <a href='motd.php?op=$editop&id=$id'>$edit</a> | <a href='motd.php?op=del&id=$id' onClick='return confirm(\"$conf\");'>$del</a> ]");
            addnav('', "motd.php?op=$editop&id=$id");
            addnav('', "motd.php?op=del&id=$id");
        }
    }
}
