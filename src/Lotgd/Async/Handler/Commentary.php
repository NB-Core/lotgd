<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Async\Response;
use Lotgd\Commentary as CoreCommentary;
use Lotgd\Util\ScriptName;
use Lotgd\Output;
use Lotgd\Async\Handler\Exception;

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
            return new Response();
        }
        $section = (string) ($args['section'] ?? '');
        if (!CoreCommentary::mayRead($section, $session['user'] ?? [])) {
            return new Response();
        }
        $message = '';
        $limit = 25;
        $talkline = 'says';
        $schema = $args['schema'];
        $viewonly = $args['viewonly'];
        $new = CoreCommentary::viewCommentary($section, $message, $limit, $talkline, $schema, $viewonly, 1);
        $objResponse = new Response();
        $objResponse->assign($section, 'innerHTML', $new);
        return $objResponse;
    }

    /** The most lines one refresh returns; the page shows no more either. */
    private const REFRESH_LIMIT = 50;

    /**
     * Return new commentary posts after a given comment ID, for a section
     * the player may read (Commentary::mayRead()); for any other, nothing.
     */
    public function commentaryRefresh(string $section, int $lastId): Response
    {
        global $session;
        if (!CoreCommentary::mayRead($section, $session['user'] ?? [])) {
            return new Response();
        }
        $comments = [];
        $nobios = ['motd' => true];
        $scriptname = $session['last_comment_scriptname'] ?? ScriptName::current();
        if (!array_key_exists($scriptname, $nobios)) {
            $nobios[$scriptname] = false;
        }
        $linkbios = !$nobios[$scriptname];
        $conn = Database::getDoctrineConnection();
        $commentaryTable = Database::prefix('commentary');
        $accountsTable = Database::prefix('accounts');
        $clansTable = Database::prefix('clans');

        $sql = 'SELECT ' . $commentaryTable . '.*, '
            . $accountsTable . '.name, '
            . $accountsTable . '.acctid, '
            . $accountsTable . '.superuser, '
            . $accountsTable . '.clanrank, '
            . $clansTable . '.clanshort FROM ' . $commentaryTable
            . ' LEFT JOIN ' . $accountsTable . ' ON ' . $accountsTable . '.acctid = '
            . $commentaryTable . '.author LEFT JOIN ' . $clansTable
            . ' ON ' . $clansTable . '.clanid=' . $accountsTable . '.clanid '
            . 'WHERE ' . $commentaryTable . '.section = :section AND '
            . $commentaryTable . '.commentid > :lastId'
            // As the page shows them (Commentary::buildCommentFetchSql()).
            . ' AND (' . $accountsTable . '.locked = 0 OR ' . $accountsTable . '.locked IS NULL)'
            . ' ORDER BY ' . $commentaryTable . '.commentid ASC LIMIT ' . self::REFRESH_LIMIT;

        $rows = $conn->fetchAllAssociative(
            $sql,
            [
                'section' => (string) $section,
                'lastId' => (int) $lastId,
            ],
            [
                'section' => ParameterType::STRING,
                'lastId' => ParameterType::INTEGER,
            ]
        );
        $newId = $lastId;
        /** @var Output $output */
        $output = Output::getInstance();
        foreach ($rows as $row) {
            $newId = $row['commentid'];
            $line = CoreCommentary::renderCommentLine($row, $linkbios);
            // Convert colour codes but preserve embedded HTML like profile links
            $line = \Lotgd\Modules\HookHandler::hook('viewcommentary', ['commentline' => $line])['commentline'];
            $line = $output->appoencode($line, true);
            $comments[] = "<div data-cid='{$row['commentid']}'>" . $line . '</div>';
        }
        $html = implode('', $comments);
        $objResponse = new Response();
        if ($html !== '') {
            $objResponse->append("{$section}-comment", 'innerHTML', $html);
            // The client polls on from here (lotgd.async.js).
            // `more`: the limit cut the answer short; the client asks again.
            $objResponse->data('commentary', [
                'section' => $section,
                'lastId' => (int) $newId,
                'more' => count($rows) === self::REFRESH_LIMIT,
            ]);
            if ($lastId > 0 && $lastId < $newId) {
                $objResponse->call('lotgdCommentNotify', count($comments));
            }
        }
        return $objResponse;
    }

    /**
     * Combined polling for mail, timeout and commentary updates.
     *
     * @throws Exception When any sub-handler fails
     */
    public function pollUpdates($section = null, $lastId = null, $cursor = null): Response
    {
        // '' (or nothing): the page shows no commentary.
        $section = is_string($section) ? $section : '';
        $lastId = is_numeric($lastId) ? (int) $lastId : 0;

        $response = new Response();

        // The mail link of a realtime player is the realtime region's
        // (Realtime::region); this poll keeps it for everyone else.
        global $session;
        if (!\Lotgd\Realtime::enabledFor($session['user'] ?? [])) {
            try {
                $response->merge((new Mail())->mailStatus(true));
            } catch (\Throwable $e) {
                throw new Exception('AJAX polling: Mail handler error', 0, $e);
            }
        }

        try {
            $response->merge(Timeout::getInstance()->timeoutStatus(true));
        } catch (\Throwable $e) {
            throw new Exception('AJAX polling: Timeout handler error', 0, $e);
        }

        // The realtime announcements ride on the same request (one poll per
        // interval, docs/ReactiveCore.md Phase 6); data `realtime`. A new
        // comment is one of them, and the client fetches it when announced,
        // so a realtime poll does not look for comments itself.
        $live = $cursor !== null && is_numeric($cursor) && \Lotgd\Realtime::enabledFor($session['user'] ?? []);

        if (!$live && $section !== '') {
            try {
                $response->merge($this->commentaryRefresh($section, $lastId));
            } catch (\Throwable $e) {
                throw new Exception('AJAX polling: Commentary handler error', 0, $e);
            }
        }

        if ($live) {
            $response->merge((new Realtime())->poll((int) $cursor, $section));
        }

        return $response;
    }
}
