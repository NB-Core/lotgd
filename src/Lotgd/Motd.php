<?php
declare(strict_types=1);
namespace Lotgd;
use Lotgd\MySQL\Database;

use Lotgd\Forms;
/**
 * Helpers for MOTD administration.
 */
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
            $sql = 'SELECT motdtitle,motdbody,motddate,motdauthor FROM ' . Database::prefix('motd') . " WHERE motditem=$id";
            $result = Database::query($sql);
            if (Database::numRows($result) > 0) {
                $row = Database::fetchAssoc($result);
                $subject = $row['motdtitle'];
                $body = $row['motdbody'];
                $date = $row['motddate'];
                $author = $row['motdauthor'];
                self::motdItem($subject, $body, $author, $date, $id);
            }
        }
        $sql = 'SELECT motdtitle,motdbody,motddate,motdauthor,motditem FROM ' . Database::prefix('motd') . ($poll ? ' WHERE motdtype=1' : ' WHERE motdtype=0') . ' ORDER BY motddate DESC';
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            self::motdItem($row['motdtitle'], $row['motdbody'], $row['motdauthor'], $row['motddate'], $row['motditem']);
        }
    }

    /**
     * Render a MOTD item.
     */
    public static function motdItem(string $subject, string $body, string $author, string $date, int $id): void
    {
	global $output;
        rawoutput('<div class="motditem" style="margin-bottom: 15px;">');
        rawoutput('<h4>' . htmlentities($output->appoencode($subject), ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</h4>');
        modulehook('motd-item-intercept', ['id' => $id]);
        rawoutput('<div>' . $output->appoencode($body) . '</div>');
        rawoutput('<small>' . translate_inline('Posted by') . ' ' . $output->appoencode($author) . ' - ' . $date . '</small>');
        self::motdAdminLinks($id, false);
        rawoutput('</div>');
    }

    /**
     * Render a poll entry.
     */
    public static function pollItem(int $id, string $subject, string $body, string $author, string $date, bool $showpoll = true): void
    {
        rawoutput('<div class="pollitem">');
        rawoutput('<h4>' . htmlentities($output->appoencode($subject), ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</h4>');
        rawoutput('<div>' . $body . '</div>');
        rawoutput('<small>' . translate_inline('Posted by') . ' ' . $output->appoencode($author) . ' - ' . $date . '</small>');
        if ($showpoll) {
            rawoutput('<div>' . translate_inline('Poll ID') . ': ' . $id . '</div>');
        }
        self::motdAdminLinks($id, true);
        rawoutput('</div>');
    }

    /**
     * Display edit form for a MOTD record.
     */
    public static function motdForm(int $id): void
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
        $form = array(
            'Motd,title',
            'motdtitle' => 'Title,text',
            'motdbody'  => 'Body,textarea',
            'motdtype'  => 'Type,viewhiddenonly',
        );
        if ($id > 0) {
            $form['changeauthor'] = 'Change Author,checklist';
            $form['changedate'] = 'Change Date (force popup),checklist';
        }
        output('<form action="motd.php?op=save&id=' . (int)$id . '" method="post">', true);
        $data = ['motdtitle' => $title, 'motdbody' => $body, 'motdtype' => $poll, 'changeauthor' => '0', 'changedate' => '0'];
        Forms::showForm($form, $data);
        rawoutput('</form>');
    }

    /**
     * Show form to create a new poll entry.
     */
    public static function motdPollForm(): void
    {
        require_once 'lib/showform.php';
        $form = array(
            'Poll,title',
            'motdtitle' => 'Question,text',
            'choice1'   => 'Option 1,text',
            'choice2'   => 'Option 2,text',
            'choice3'   => 'Option 3,text',
            'choice4'   => 'Option 4,text',
            'motdtype'  => 'Type,viewhiddenonly',
        );
        output('`b`4Note:`0 Polls cannot be edited after creation.`n`n');
        output('<form action="motd.php?op=savenew" method="post">', true);
        Forms::showForm($form, array('motdtitle' => '', 'motdtype' => '1', 'choice1' => '', 'choice2' => '', 'choice3' => '', 'choice4' => ''));
        rawoutput('</form>');
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
    }

    /**
     * Create a new poll entry from form data.
     */
    public static function savePoll(): void
    {
        global $session;
        $title = httppost('motdtitle');
        $choices = [];
        for ($i = 1; $i <= 4; $i++) {
            $c = trim((string) httppost("choice$i"));
            if ($c !== '') {
                $choices[] = $c;
            }
        }
        $body = serialize($choices);
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
    }

    /**
     * Output edit and delete links for an entry if user can post.
     */
    private static function motdAdminLinks(int $id, bool $poll): void
    {
        global $session;
        if ($session['user']['superuser'] & SU_POST_MOTD) {
            $edit = translate_inline('Edit');
            $del = translate_inline('Del');
            $conf = translate_inline('Are you sure you wish to delete this entry?');
            $editop = $poll ? 'addpoll' : 'add';
            rawoutput(" [ <a href='motd.php?op=$editop&id=$id'>$edit</a> | <a href='motd.php?op=del&id=$id' onClick='return confirm(\"$conf\");'>$del</a> ]");
            addnav('', "motd.php?op=$editop&id=$id");
            addnav('', "motd.php?op=del&id=$id");
        }
    }
}
