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
        rawoutput('<div class="motditem" style="margin-bottom: 15px;">');
        rawoutput('<h4>' . htmlentities($subject, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</h4>');
        modulehook('motd-item-intercept', ['id' => $id]);
        rawoutput('<div>' . $body . '</div>');
        rawoutput('<small>' . translate_inline('Posted by') . ' ' . $author . ' - ' . $date . '</small>');
        rawoutput('</div>');
    }

    /**
     * Render a poll entry.
     */
    public static function pollItem(int $id, string $subject, string $body, string $author, string $date, bool $showpoll = true): void
    {
        rawoutput('<div class="pollitem">');
        rawoutput('<h4>' . htmlentities($subject, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</h4>');
        rawoutput('<div>' . $body . '</div>');
        rawoutput('<small>' . translate_inline('Posted by') . ' ' . $author . ' - ' . $date . '</small>');
        if ($showpoll) {
            rawoutput('<div>' . translate_inline('Poll ID') . ': ' . $id . '</div>');
        }
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
            $poll = 0;
        }
        $form = array(
            'Motd,title',
            'motdtitle' => array('Title', 'text', 'value' => $title),
            'motdbody'  => array('Body', 'textarea', 'value' => $body),
            'motdtype'  => array('Type', 'checkbox', 'value' => $poll),
        );
        output('<form action="motd.php?op=save&id=' . (int)$id . '" method="post">', true);
        Forms::showForm($form, array('motdtitle' => $title, 'motdbody' => $body, 'motdtype' => $poll));
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
            'motdtitle' => array('Title', 'text'),
            'motdbody'  => array('Body', 'textarea'),
            'motdtype'  => array('Type', 'hidden', 'value' => 1),
        );
        output('<form action="motd.php?op=savenew" method="post">', true);
        Forms::showForm($form);
        rawoutput('</form>');
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
}
