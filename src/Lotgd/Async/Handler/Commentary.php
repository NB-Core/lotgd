<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\MySQL\Database;
use Jaxon\Response\Response;
use Lotgd\Commentary as CoreCommentary;
use Lotgd\Util\ScriptName;
use Lotgd\Output;
use Lotgd\Async\Handler\Exception;

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
        $nobios = ['motd' => true];
        $scriptname = $session['last_comment_scriptname'] ?? ScriptName::current();
        if (!array_key_exists($scriptname, $nobios)) {
            $nobios[$scriptname] = false;
        }
        $linkbios = !$nobios[$scriptname];
        $sql = 'SELECT ' . Database::prefix('commentary') . '.*, '
            . Database::prefix('accounts') . '.name, '
            . Database::prefix('accounts') . '.acctid, '
            . Database::prefix('accounts') . '.superuser, '
            . Database::prefix('accounts') . '.clanrank, '
            . Database::prefix('clans') . '.clanshort FROM ' . Database::prefix('commentary')
            . ' LEFT JOIN ' . Database::prefix('accounts') . ' ON ' . Database::prefix('accounts') . '.acctid = '
            . Database::prefix('commentary') . '.author LEFT JOIN ' . Database::prefix('clans')
            . ' ON ' . Database::prefix('clans') . '.clanid=' . Database::prefix('accounts') . '.clanid '
            . "WHERE section='" . addslashes($section) . "' AND commentid > '" . (int) $lastId
            . "' ORDER BY commentid ASC";
        $result = Database::query($sql);
        $newId = $lastId;
        /** @var Output $output */
        $output = Output::getInstance();
        while ($row = Database::fetchAssoc($result)) {
            $newId = $row['commentid'];
            $line = CoreCommentary::renderCommentLine($row, $linkbios);
            // Convert colour codes but preserve embedded HTML like profile links
            $line = $output->appoencode($line, true);
            $comments[] = "<div data-cid='{$row['commentid']}'>" . $line . '</div>';
        }
        Database::freeResult($result);
        $html = implode('', $comments);
        $objResponse = jaxon()->newResponse();
        if ($html !== '') {
            $objResponse->append("{$section}-comment", 'innerHTML', $html);
            $objResponse->script("lotgd_lastCommentId = $newId;");
            if ($lastId > 0 && $lastId < $newId) {
                $objResponse->script('lotgdCommentNotify(' . count($comments) . ');');
            }
        }
        return $objResponse;
    }

    /**
     * Simple test method to check if Jaxon is working.
     */
    public function test(): Response
    {
        $response = jaxon()->newResponse();
        $response->assign('notify', 'innerHTML', 'AJAX Test successful at ' . date('H:i:s'));
        return $response;
    }

    /**
     * Combined polling for mail, timeout and commentary updates.
     *
     * @throws Exception When any sub-handler fails
     */
    public function pollUpdates($section = null, $lastId = null): Response
    {
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

        $response = jaxon()->newResponse();

        try {
            $response->appendResponse((new Mail())->mailStatus(true));
        } catch (\Throwable $e) {
            throw new Exception('AJAX polling: Mail handler error', 0, $e);
        }

        try {
            $response->appendResponse(Timeout::getInstance()->timeoutStatus(true));
        } catch (\Throwable $e) {
            throw new Exception('AJAX polling: Timeout handler error', 0, $e);
        }

        try {
            $response->appendResponse($this->commentaryRefresh($section, $lastId));
        } catch (\Throwable $e) {
            throw new Exception('AJAX polling: Commentary handler error', 0, $e);
        }

        return $response;
    }
}
