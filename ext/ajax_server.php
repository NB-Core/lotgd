<?php

declare(strict_types=1);

/**
 * Collection of server-side Jaxon callbacks used by the AJAX
 * interface. Handles mail notifications, timeout warnings and
 * commentary updates.
 */

/* you need to check if somebody timed out.
   if you call common.php and we have a timeout, he will the redirect to index.php?op=timeout, resulting in a full page
   which will (called in 1s intervals) download a lot of useless traffic to him and from your server

   therefore, a common.php is used that will not do a DO_FORCED_NAVIGATION.
   This will just make our mailinfo return a small string in case of a timeout, not an entire error page
 */
//if ($_SERVER['REMOTE_ADDR']=="86.123.157.144") {
#   $s=print_r($_POST,true);
#   $s=$_SERVER['REMOTE_ADDR'].$s;
#   file_put_contents("/var/www/html/naruto/debug.txt",$s, FILE_APPEND);
//}
use Jaxon\Response\Response;          // and the Response class
use Lotgd\Commentary;

use function Jaxon\jaxon;

/**
 * Return mail and timeout status information for the active user.
 *
 * @param bool $args Trigger flag from the client
 * @return Response
 */
function mail_status($args = false): Response
{
    global $start_timeout_show_seconds, $session;
    $cwd = getcwd();
    if (!chdir(__DIR__ . '/..')) {
        // Failed to change directory, return an empty response or handle error
        return jaxon()->newResponse();
    }
    try {
        if ($args === false) {
            return jaxon()->newResponse();
        };
        $timeout_setting = getsetting("LOGINTIMEOUT", 360); // seconds
        $new = maillink();
        $tabtext = maillinktabtext();

        // Get the highest message ID for the current user there is
        $sql = "SELECT MAX(messageid) AS lastid FROM " . db_prefix('mail') . " WHERE msgto=\"" . $session['user']['acctid'] . "\"";
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        if ($row === false) {
            $row = ['lastid' => 0];
        }
        db_free_result($result);
        $lastMailId = (int)($row['lastid'] ?? 0);
        $objResponse = jaxon()->newResponse();
        $objResponse->assign("maillink", "innerHTML", $new);
        if ($tabtext == '') { // there are no unseen mails
            return $objResponse;
        } else {
            $tabtext = translate_inline('Legend of the Green Dragon', 'home') . ' - ' . $tabtext;
            $objResponse->script("document.title=\"" . $tabtext . "\";");
            $objResponse->script('lotgdMailNotify(' .  $lastMailId . ');');
        }
        return $objResponse;
    } finally {
        chdir($cwd);
    }
}

/**
 * Update last activity time and report remaining session timeout.
 *
 * @param bool $args Trigger flag from the client
 * @return Response
 */
function timeout_status($args = false): Response
{
    global $start_timeout_show_seconds, $never_timeout_if_browser_open;
    $cwd = getcwd();
    if (!chdir(__DIR__ . '/..')) {
        throw new \RuntimeException("Failed to change directory to " . (__DIR__ . '/..'));
    }
    try {
        if ($args === false) {
            return jaxon()->newResponse();
        };
        global $session;
        if (!isset($session['user'])) {
            error_log('timeout_status: session user not set');
            return jaxon()->newResponse();
        }
        $warning = '';
        if ($never_timeout_if_browser_open == 1) {
            $session['user']['laston'] = date("Y-m-d H:i:s"); // set to now
            //manual db update
            $sql = "UPDATE " . db_prefix('accounts') . " set laston='" . $session['user']['laston'] . "' WHERE acctid=" . $session['user']['acctid'];
            db_query($sql);
        }
        $timeout = strtotime($session['user']['laston']) - strtotime(date("Y-m-d H:i:s", strtotime("-" . getsetting("LOGINTIMEOUT", 900) . " seconds")));
        if ($timeout <= 1) {
            $warning = "" . appoencode("`\$`b") . "Your session has timed out!" . appoencode("`b");
        } elseif ($timeout < $start_timeout_show_seconds) {
            if ($timeout > 60) {
                $min = floor($timeout / 60);
                $sec = $timeout % 60;
                $warning = "<br/>" . appoencode("`t") . sprintf_translate("TIMEOUT in %d minute%s und %d second%s!", $min, $min > 1 ? translate_inline('s') : '', $sec, $sec != 1 ? translate_inline('s') : '');
            } else {
                $warning = "<br/>" . appoencode("`t") . sprintf_translate("TIMEOUT in %d second%s!", $timeout, $timeout != 1 ? translate_inline('s') : '');
            }
        } else {
            $warning = '';
        }
        $objResponse = jaxon()->newResponse();
        $objResponse->assign("notify", "innerHTML", $warning);
        return $objResponse;
    } finally {
        chdir($cwd);
    }
}


/**
 * Retrieve a block of commentary for a given section.
 *
 * @param array|bool $args Parameter array from the client
 * @return Response
 */
function commentary_text($args = false): Reponse
{
    global $session;
    if ($args === false || !is_array($args)) {
        return jaxon()->newResponse();
    };
    $section = $args['section'];
    $message = "";
    $limit = 25;
    $talkline = "says";
    $schema = $args['schema'];
    $viewonly = $args['viewonly'];
    $new = Commentary::viewCommentary($section, $message, $limit, $talkline, $schema, $viewonly, 1);
    $objResponse = jaxon()->newResponse();
    $objResponse->assign($section, "innerHTML", $new);
    return $objResponse;
}

/**
 * Return new commentary posts after a given comment ID.
 *
 * @param string $section The commentary section name
 * @param int $lastId ID of the last comment already displayed
 * @return Response
 */
function commentary_refresh(string $section, int $lastId): Response
{
        global $session;
        $comments = [];
        $nobios = ['motd.php' => true];
        $scriptname = $session['last_comment_scriptname'] ?? $_SERVER['SCRIPT_NAME'];
    if (!array_key_exists(basename($scriptname), $nobios)) {
            $nobios[basename($scriptname)] = false;
    }
        $linkbios = !$nobios[basename($scriptname)];
        $sql = 'SELECT ' . db_prefix('commentary') . '.*, '
            . db_prefix('accounts') . '.name, '
            . db_prefix('accounts') . '.acctid, '
            . db_prefix('accounts') . '.superuser, '
            . db_prefix('accounts') . '.clanrank, '
            . db_prefix('clans') . '.clanshort FROM ' . db_prefix('commentary')
            . ' LEFT JOIN ' . db_prefix('accounts') . ' ON ' . db_prefix('accounts') . '.acctid = '
            . db_prefix('commentary') . '.author LEFT JOIN ' . db_prefix('clans')
            . ' ON ' . db_prefix('clans') . '.clanid=' . db_prefix('accounts') . '.clanid '
            . "WHERE section='" . addslashes($section) . "' AND commentid > '" . (int)$lastId
            . "' ORDER BY commentid ASC";
        $result = db_query($sql);
        $newId = $lastId;
    while ($row = db_fetch_assoc($result)) {
            $newId = $row['commentid'];
            $line = Commentary::renderCommentLine($row, $linkbios);
            // Convert colour codes but preserve embedded HTML like profile links
            $line = appoencode($line, true);
            $comments[] = "<div data-cid='{$row['commentid']}'>" . $line . '</div>';
    }
        db_free_result($result);
        $html = implode('', $comments);
        $objResponse = jaxon()->newResponse();
    if ($html !== '') {
            $objResponse->append("{$section}-comment", 'innerHTML', $html);
            $objResponse->script("lotgd_lastCommentId = $newId;");
            $objResponse->script('lotgdCommentNotify(' . count($comments) . ');');
    }
        return $objResponse;
}

/**
 * Combined polling for mail, timeout and commentary updates.
 *
 * @param string $section The commentary section name
 * @param int $lastId The last comment ID already displayed
 * @return Response
 */
function poll_updates(string $section, int $lastId): Response
{
        $response = jaxon()->newResponse();
        $response->appendResponse(mail_status(true));
        $response->appendResponse(timeout_status(true));
        $response->appendResponse(commentary_refresh($section, $lastId));
        return $response;
}
