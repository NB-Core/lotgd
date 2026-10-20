<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Settings;
use Lotgd\TwigTemplate;

/**
 * Whether a request is answered with page regions instead of a full page.
 *
 * Partial rendering is opt-in twice: the game setting `partial_render` and the
 * player's preference `partialrender`. The client script is only sent to a
 * player who has both, on a skin that declares regions, so a partial request
 * from anyone else is answered with a plain page change.
 *
 * The decision is split in two, see docs/ReactiveCore.md:
 *
 *  - preCheck() runs in common.php before forced navigation, before the page
 *    script, the new day and module events. A request that cannot be partial
 *    is sent to the same URL as a normal page change, which is harmless
 *    because nothing has happened yet.
 *  - The footer decides late whether the finished page can be swapped in
 *    (no inline script, a skin with regions, the preference still on). If
 *    not, it sends the player to badnav.php, which shows the stored page,
 *    and never runs the URL a second time.
 */
final class PartialMode
{
    /**
     * Pages that may answer partially: script => method => allowed `op`
     * values, '' meaning "no op". Only operations that display a place belong
     * here; anything that changes state stays a full page until guarded game
     * actions exist (Phase 3).
     *
     * The village and the inn carry a commentary block; for a partial
     * player its preview field is declarative markup the client handles
     * (Forms::previewField()), so it swaps in like any other region content.
     *
     * @var array<string,array<string,list<string>>>
     */
    public const ALLOWLIST = [
        'village.php' => ['GET' => ['']],
        'forest.php' => ['GET' => ['']],
        'inn.php' => ['GET' => ['']],
    ];

    public const REQUEST_HEADER = 'HTTP_HX_REQUEST';
    public const HISTORY_RESTORE_HEADER = 'HTTP_HX_HISTORY_RESTORE_REQUEST';

    private static bool $active = false;

    /** @var (callable(string,bool):void)|null */
    private static $headerSink = null;

    /**
     * Early decision, before anything in the request has changed state.
     *
     * Returns normally when the request is not partial or may be answered
     * partially; otherwise sends a page change to the same URL and exits.
     */
    public static function preCheck(): void
    {
        self::$active = false;
        if (!self::isRequested()) {
            return;
        }

        if (self::settingEnabled() && self::isAllowed(self::script(), self::method(), self::op())) {
            self::$active = true;

            return;
        }

        self::sendHeader('HX-Redirect: ' . self::requestUri());
        exit();
    }

    /**
     * Whether this request is being answered partially (preCheck() passed).
     */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Whether the client asked for regions. A history restore is a full
     * page load in disguise and is never answered partially.
     */
    public static function isRequested(): bool
    {
        return ($_SERVER[self::REQUEST_HEADER] ?? '') === 'true'
            && ($_SERVER[self::HISTORY_RESTORE_HEADER] ?? '') !== 'true';
    }

    public static function isAllowed(string $script, string $method, string $op): bool
    {
        return in_array($op, self::ALLOWLIST[$script][strtoupper($method)] ?? [], true);
    }

    /**
     * Partial rendering is on for this player: the game allows it and the
     * player chose it.
     *
     * @param array<string,mixed> $user The session user, with prefs unserialized.
     */
    public static function isEnabledFor(array $user): bool
    {
        $prefs = $user['prefs'] ?? [];

        return self::settingEnabled() && is_array($prefs) && !empty($prefs['partialrender']);
    }

    /**
     * The partial client runs on this player's pages: partial rendering is
     * on for them and the skin declares regions. Code that would otherwise
     * emit an inline script asks this to emit declarative markup instead.
     *
     * @param array<string,mixed> $user The session user, with prefs unserialized.
     */
    public static function clientActive(array $user): bool
    {
        return self::isEnabledFor($user) && self::skinSupportsRegions();
    }

    /**
     * The active skin is a Twig skin whose config.json declares regions.
     */
    public static function skinSupportsRegions(): bool
    {
        if (!TwigTemplate::isActive()) {
            return false;
        }

        $config = dirname(__DIR__, 3) . '/' . TwigTemplate::getPath() . '/config.json';
        if (!is_file($config)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($config), true);

        return is_array($data) && ($data['regions'] ?? false) === true;
    }

    /**
     * Send a header through the sink tests install, or PHP's header().
     */
    public static function sendHeader(string $header, bool $replace = true): void
    {
        if (self::$headerSink !== null) {
            (self::$headerSink)($header, $replace);

            return;
        }

        if (!headers_sent()) {
            header($header, $replace);
        }
    }

    /**
     * @param (callable(string,bool):void)|null $sink
     */
    public static function setHeaderSink(?callable $sink): void
    {
        self::$headerSink = $sink;
    }

    public static function setActive(bool $active): void
    {
        self::$active = $active;
    }

    public static function resetState(): void
    {
        self::$active = false;
        self::$headerSink = null;
    }

    private static function settingEnabled(): bool
    {
        return Settings::hasInstance() && (int) Settings::getInstance()->getSetting('partial_render', 0) === 1;
    }

    private static function script(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    }

    private static function method(): string
    {
        return (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    private static function op(): string
    {
        $op = $_GET['op'] ?? '';

        return is_string($op) ? $op : '';
    }

    private static function requestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        // Header injection: a URI never carries a line break legitimately.
        return str_replace(["\r", "\n"], '', $uri);
    }
}
