<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\Async\Response;
use Lotgd\Realtime as Outbox;
use Lotgd\Realtime\Poll;

/**
 * The realtime outbox over the async endpoint (docs/ReactiveCore.md,
 * Phases 4 and 6): announcements after a cursor, and the current content of
 * a region. The channel is always the player's own, from the account the
 * request read -- never from the request.
 */
class Realtime
{
    /**
     * Announcements for the player after $cursor, in data `realtime`
     * (`{cursor, events, resync?}`, or `{disabled: true}`).
     */
    public function poll(int $cursor = 0): Response
    {
        $response = new Response();
        $acctid = $this->player();
        if ($acctid === null) {
            return $response->data('realtime', ['disabled' => true]);
        }

        return $response->data('realtime', Poll::events([Outbox::userChannel($acctid)], max(0, $cursor)));
    }

    /**
     * A region as the page renders it now, in data `region`
     * (`{region, version, html}`).
     */
    public function region(string $region): Response
    {
        $response = new Response();
        $acctid = $this->player();
        if ($acctid === null || !in_array($region, Poll::REGIONS, true)) {
            return $response->data('region', null);
        }

        return $response->data('region', Poll::region($region, $acctid));
    }

    private function player(): ?int
    {
        global $session;

        $user = $session['user'] ?? [];
        $acctid = (int) ($user['acctid'] ?? 0);
        if ($acctid <= 0 || empty($user['loggedin']) || !Outbox::enabledFor($user)) {
            return null;
        }

        return $acctid;
    }
}
