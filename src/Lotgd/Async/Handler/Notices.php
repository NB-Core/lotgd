<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\Async\Response;
use Lotgd\Page\Notices as PlayerNotices;
use Lotgd\PageParts;

/**
 * The notices in the upper right over the async endpoint
 * (assets/js/lotgd.notices.js): read only. Answers `notices: {mail: {unread,
 * lastId}, motd, mailLink}` -- mailLink the header's mail link as the page
 * draws it -- and the session's time left in `session`.
 */
class Notices
{
    public function status(): Response
    {
        global $session;

        $response = new Response();
        $user = $session['user'] ?? [];
        if ((int) ($user['acctid'] ?? 0) > 0 && !empty($user['loggedin'])) {
            $response->data('notices', PlayerNotices::status($user) + ['mailLink' => PageParts::mailLink()]);
        }

        return $response->merge(Timeout::configured()->timeoutStatus(true));
    }
}
