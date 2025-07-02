<?php
namespace Lotgd;

/**
 * Helpers for MOTD administration.
 */
class Motd
{
    public static function motd_admin($id, $poll = false)
    {
        global $session;
        $id = (int)$id;
        if ($id > 0) {
            $sql = 'SELECT motdtitle,motdbody,motddate,motdauthor FROM ' . db_prefix('motd') . " WHERE motditem=$id";
            $result = db_query($sql);
            if (db_num_rows($result) > 0) {
                $row = db_fetch_assoc($result);
                $subject = $row['motdtitle'];
                $body = $row['motdbody'];
                $date = $row['motddate'];
                $author = $row['motdauthor'];
                self::motditem($subject, $body, $author, $date, $id);
            }
        }
        $sql = 'SELECT motdtitle,motdbody,motddate,motdauthor,motditem FROM ' . db_prefix('motd') . ($poll ? ' WHERE motdtype=1' : ' WHERE motdtype=0') . ' ORDER BY motddate DESC';
        $result = db_query($sql);
        while ($row = db_fetch_assoc($result)) {
            self::motditem($row['motdtitle'], $row['motdbody'], $row['motdauthor'], $row['motddate'], $row['motditem']);
        }
    }

    public static function motditem($subject, $body, $author, $date, $id)
    {
        rawoutput('<div class="motditem" style="margin-bottom: 15px;">');
        rawoutput('<h4>' . htmlentities($subject, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</h4>');
        modulehook('motd-item-intercept', ['id' => $id]);
        rawoutput('<div>' . $body . '</div>');
        rawoutput('<small>' . translate_inline('Posted by') . ' ' . $author . ' - ' . $date . '</small>');
        rawoutput('</div>');
    }

    public static function pollitem($id, $subject, $body, $author, $date, $showpoll = true)
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

    public static function motd_form($id)
    {
        require_once 'lib/showform.php';
        $sql = 'SELECT motdtitle,motdbody,motdtype FROM ' . db_prefix('motd') . " WHERE motditem='$id'";
        $result = db_query($sql);
        if (db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
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
        showform($form, array('motdtitle' => $title, 'motdbody' => $body, 'motdtype' => $poll));
        rawoutput('</form>');
    }

    public static function motd_poll_form()
    {
        require_once 'lib/showform.php';
        $form = array(
            'Poll,title',
            'motdtitle' => array('Title', 'text'),
            'motdbody'  => array('Body', 'textarea'),
            'motdtype'  => array('Type', 'hidden', 'value' => 1),
        );
        output('<form action="motd.php?op=savenew" method="post">', true);
        showform($form);
        rawoutput('</form>');
    }

    public static function motd_del($id)
    {
        $sql = 'DELETE FROM ' . db_prefix('motd') . " WHERE motditem='$id'";
        db_query($sql);
        invalidatedatacache('motd');
    }
}
