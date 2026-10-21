<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\Async\Response;
use Lotgd\Commentary as CoreCommentary;
use Lotgd\Realtime as Outbox;
use Lotgd\Realtime\Poll;

/**
 * The realtime outbox over the async endpoint (docs/ReactiveCore.md,
 * Phases 4 and 6): announcements after a cursor, and the current content of
 * a region. The channels are the player's own, from the account the
 * request read, and a commentary section the server showed them -- never
 * one the request names on its own.
 */
class Realtime
{
    /**
     * Announcements for the player after $cursor, in data `realtime`
     * (`{cursor, events, resync?}`, or `{disabled: true}`). With $section,
     * the new comments of that section too -- if the player may read it
     * (Commentary::mayRead()); otherwise the section is left out.
     */
    public function poll(int $cursor = 0, string $section = ''): Response
    {
        global $session;

        $response = new Response();
        $acctid = $this->player();
        if ($acctid === null) {
            return $response->data('realtime', ['disabled' => true]);
        }

        $channels = [Outbox::userChannel($acctid)];
        if ($section !== '' && CoreCommentary::mayRead($section, $session['user'] ?? [])) {
            $channels[] = Outbox::sectionChannel($section);
        }

        return $response->data('realtime', Poll::events($channels, max(0, $cursor)));
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
