<?php

/**
 * Realtime poll endpoint (docs/ReactiveCore.md, Phase 4).
 *
 * GET with the header X-Lotgd-Poll: 1, either ?cursor=<n> for the
 * announcements after delivery number n, or ?region=mail|stats for the
 * current content of a region. Answers JSON. Reads only; it saves nothing
 * but the time of the poll, and releases the session before it answers.
 */

declare(strict_types=1);

define('OVERRIDE_FORCED_NAV', true);
define('ALLOW_ANONYMOUS', true);

require_once __DIR__ . '/common/bootstrap.php';

$lotgdPoll = \Lotgd\Realtime\Poll::handle($_GET, $_SERVER, microtime(true));
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!headers_sent()) {
    http_response_code($lotgdPoll['status']);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
}
echo json_encode($lotgdPoll['body'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
