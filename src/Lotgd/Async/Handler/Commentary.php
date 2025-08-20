<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Jaxon\Response\Response;
use Lotgd\Commentary as CoreCommentary;
use function Jaxon\jaxon;

/**
 * Handle commentary AJAX interactions.
 */
class Commentary
{
    /**
     * Retrieve a block of commentary for a given section.
     *
     * @param array|bool $args Parameter array from the client
     */
    public function commentaryText($args = false): Response
    {
        global $session;
        if ($args === false || !is_array($args)) {
            return jaxon()->newResponse();
        }
        $section = $args['section'];
        $message = '';
        $limit = 25;
        $talkline = 'says';
        $schema = $args['schema'];
        $viewonly = $args['viewonly'];
        $new = CoreCommentary::viewCommentary($section, $message, $limit, $talkline, $schema, $viewonly, 1);
        $objResponse = jaxon()->newResponse();
        $objResponse->assign($section, 'innerHTML', $new);
        return $objResponse;
    }

    /**
     * Return new commentary posts after a given comment ID.
     */
    public function commentaryRefresh(string $section, int $lastId): Response
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
            . "WHERE section='" . addslashes($section) . "' AND commentid > '" . (int) $lastId
            . "' ORDER BY commentid ASC";
        $result = db_query($sql);
        $newId = $lastId;
        while ($row = db_fetch_assoc($result)) {
            $newId = $row['commentid'];
            $line = CoreCommentary::renderCommentLine($row, $linkbios);
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
     * Simple test method to check if Jaxon is working at all.
     */
    public function test(): Response
    {
        error_log("JAXON DEBUG: test() method called successfully");
        $response = jaxon()->newResponse();
        $response->assign('notify', 'innerHTML', 'Test successful at ' . date('H:i:s'));
        return $response;
    }

    /**
     * Combined polling for mail, timeout and commentary updates.
     * Made more robust to handle parameter issues.
     */
    public function pollUpdates($section = null, $lastId = null): Response
    {
        // DEBUG: Log what we're receiving
        error_log("JAXON DEBUG: pollUpdates called with section: " . var_export($section, true) . ", lastId: " . var_export($lastId, true));
        error_log("JAXON DEBUG: func_get_args(): " . var_export(func_get_args(), true));
        error_log("JAXON DEBUG: POST data: " . var_export($_POST, true));
        
        // Handle parameter conversion issues
        if ($section === null || $section === '') {
            $section = 'superuser'; // fallback
        }
        if ($lastId === null) {
            $lastId = 0; // fallback
        }
        
        // Ensure proper types
        $section = (string) $section;
        $lastId = (int) $lastId;
        
        error_log("JAXON DEBUG: After type conversion - section: $section, lastId: $lastId");
        
        $response = jaxon()->newResponse();
        
        try {
            $response->appendResponse((new Mail())->mailStatus(true));
            error_log("JAXON DEBUG: Mail handler completed");
        } catch (Exception $e) {
            error_log("JAXON DEBUG: Mail handler failed: " . $e->getMessage());
        }
        
        try {
            $response->appendResponse((new Timeout())->timeoutStatus(true));
            error_log("JAXON DEBUG: Timeout handler completed");
        } catch (Exception $e) {
            error_log("JAXON DEBUG: Timeout handler failed: " . $e->getMessage());
        }
        
        try {
            $response->appendResponse($this->commentaryRefresh($section, $lastId));
            error_log("JAXON DEBUG: Commentary handler completed");
        } catch (Exception $e) {
            error_log("JAXON DEBUG: Commentary handler failed: " . $e->getMessage());
        }
        
        return $response;
    }
}
