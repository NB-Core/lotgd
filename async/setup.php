<?php

declare(strict_types=1);

use Lotgd\Async\Handler\Mail;
use Lotgd\Async\Handler\Timeout;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;

/**
 * Prepare a page for the async client (assets/js/lotgd.async.js,
 * docs/ReactiveCore.md Phase 6).
 *
 * Writes what the client starts from into <meta name="lotgd-async"> -- the
 * endpoint, the async CSRF token, the comment section and the last ids the
 * page shows, the poll interval -- and queues the client script. No inline
 * script: a partial answer can swap pages without running anything, and the
 * per-page values travel in X-Lotgd-Async-State.
 *
 * Callers read $lastUnreadMailId and $lastUnreadMailCount afterwards
 * (Footer::buildPageState()), and $pre_headscript receives the markup.
 */

if (!empty($GLOBALS['lotgd_async_setup_loaded'])) {
    return;
}

$GLOBALS['lotgd_async_setup_loaded'] = true;

require_once __DIR__ . '/common/settings.php';

global $session;

// Favicon for notifications.
$default = "<link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/images/favicon/favicon-32x32.png\">";
$favData = HookHandler::hook('pageparts-favicon', ['favicon-link' => $default]);
if (preg_match('/<link[^>]*rel="icon"[^>]*sizes="32x32"[^>]*href="([^"]+)"/i', (string) ($favData['favicon-link'] ?? ''), $matches)) {
    $favicon32 = $matches[1];
} else {
    $favicon32 = '/images/favicon/favicon-32x32.png';
}

$lastUnreadMailId = 0;
$lastUnreadMailCount = 0;
if (!empty($session['user']['acctid'])) {
    ['lastid' => $lastUnreadMailId, 'unread' => $lastUnreadMailCount] = Mail::counts((int) $session['user']['acctid']);
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$currentScript = is_string($requestPath) ? basename($requestPath) : '';
// The two-factor challenge and setup pages load this for the passkey calls
// only: the background poll would interrupt the ceremony.
$isTwoFactorChallengeRoute = $currentScript === 'runmodule.php'
    && (string) ($_GET['module'] ?? '') === 'twofactorauth'
    && in_array((string) ($_GET['op'] ?? ''), ['challenge', 'setup'], true);

$asyncClientConfig = [
    'url' => '/async/process.php',
    // Page-render context, so generating here is correct -- the request
    // handler must never do it, see \Lotgd\Security\Csrf.
    'csrf' => \Lotgd\Security\Csrf::token(\Lotgd\Security\Csrf::SCOPE_ASYNC),
    // The player's updates (mail, timeout, commentary): the game setting and
    // the player's AJAX preference. Without them only the realtime poll runs.
    'updates' => (int) \Lotgd\Settings::getInstance()->getSetting('ajax', 1) === 1 && !empty($session['user']['prefs']['ajax']),
    'polling' => !$isTwoFactorChallengeRoute,
    'interval' => Timeout::getInstance()->getCheckMailTimeoutSeconds() * 1000,
    // The section this page shows; '' when it shows none, so nothing is polled.
    'section' => \Lotgd\Commentary::shownSection(),
    'lastCommentId' => (int) ($session['lastcommentid'] ?? 0),
    'lastMailId' => (int) $lastUnreadMailId,
    'mailCount' => (int) $lastUnreadMailCount,
    'icon' => $favicon32,
    // Verbose client logging is opt-in via `debug_console` in config/async.settings.php.
    'debug' => \Lotgd\Async\DebugMode::isEnabled(),
];

$pre_headscript = ($pre_headscript ?? '')
    . '<meta name="lotgd-async" content="'
    . htmlspecialchars((string) json_encode($asyncClientConfig), ENT_QUOTES, 'UTF-8') . '">'
    . "<div id='notify'></div>";

Output::requireVendorAsset('lotgd-async', 'js');
// jQuery stays on these pages: modules written for the Jaxon era expect it.
Output::requireVendorAsset('jquery', 'js');
