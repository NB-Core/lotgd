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
        'gardens.php' => ['GET' => ['']],
        'encounter.php' => ['GET' => ['', 'view']],
        'rock.php' => ['GET' => ['']],
        'shades.php' => ['GET' => ['']],
        'graveyard.php' => ['GET' => ['']],
        'lodge.php' => ['GET' => ['', 'points']],
        'gypsy.php' => ['GET' => ['', 'talk']],
        'hof.php' => ['GET' => ['', 'kills', 'money', 'gems', 'charm', 'tough', 'resurrects', 'days']],
        'list.php' => ['GET' => ['', 'clan']],
        'bio.php' => ['GET' => ['']],
        'news.php' => ['GET' => ['']],
        'about.php' => ['GET' => ['', 'setup', 'listmodules', 'license']],
        'referral.php' => ['GET' => ['']],
        'stables.php' => ['GET' => ['', 'examine']],
        'weapons.php' => ['GET' => ['']],
        'armor.php' => ['GET' => ['']],
        'mercenarycamp.php' => ['GET' => ['']],
        'healer.php' => ['GET' => ['']],
        'train.php' => ['GET' => ['', 'question']],
        // The forms only: every booking is a POST.
        'bank.php' => ['GET' => ['', 'transfer', 'deposit', 'withdraw', 'borrow']],
        'clan.php' => ['GET' => ['', 'list']],
        'pvp.php' => ['GET' => ['']],
    ];

    /**
     * Parameters that make an allowed (script, op) do something: pvp.php
     * without an op lists the players, with act=attack it starts a fight.
     *
     * @var array<string,list<string>>
     */
    public const FORBIDDEN_PARAMETERS = [
        'pvp.php' => ['act'],
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

        if (self::settingEnabled() && self::isAllowed(self::script(), self::method(), self::op(), $_GET)) {
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

    /**
     * @param array<string,mixed> $query The request's query parameters, for FORBIDDEN_PARAMETERS.
     */
    public static function isAllowed(string $script, string $method, string $op, array $query = []): bool
    {
        if (!in_array($op, self::ALLOWLIST[$script][strtoupper($method)] ?? [], true)) {
            return false;
        }
        foreach (self::FORBIDDEN_PARAMETERS[$script] ?? [] as $parameter) {
            if (isset($query[$parameter]) && $query[$parameter] !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Partial rendering is on for this player: the game allows it and the
     * player chose it -- or never chose, and the game's default for players
     * (`partial_render_default`) is on.
     *
     * @param array<string,mixed> $user The session user, with prefs unserialized.
     */
    public static function isEnabledFor(array $user): bool
    {
        $prefs = $user['prefs'] ?? [];
        if (!self::settingEnabled() || !is_array($prefs)) {
            return false;
        }

        return array_key_exists('partialrender', $prefs) ? !empty($prefs['partialrender']) : self::playerDefault();
    }

    /**
     * The game's choice for players who have not made their own.
     */
    public static function playerDefault(): bool
    {
        return Settings::hasInstance() && (int) Settings::getInstance()->getSetting('partial_render_default', 1) === 1;
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
        return TwigTemplate::isActive() && self::skinDeclares('regions');
    }

    /**
     * Whether the active Twig skin's config.json sets $key to true
     * (`regions`, `overlay`).
     */
    public static function skinDeclares(string $key): bool
    {
        if (!TwigTemplate::isActive()) {
            return false;
        }

        return self::configDeclares(dirname(__DIR__, 3) . '/' . TwigTemplate::getPath() . '/config.json', $key);
    }

    /**
     * Whether the skin named as in the `defaultskin` setting (`twig:aurora`)
     * is a Twig skin whose config.json sets $key to true. Legacy skins never do.
     */
    public static function skinDeclaresFor(string $skin, string $key): bool
    {
        if (!str_starts_with($skin, 'twig:')) {
            return false;
        }
        $name = substr($skin, strlen('twig:'));
        if ($name === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            return false;
        }

        return self::configDeclares(dirname(__DIR__, 3) . '/templates_twig/' . $name . '/config.json', $key);
    }

    private static function configDeclares(string $config, string $key): bool
    {
        if (!is_file($config)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($config), true);

        return is_array($data) && ($data[$key] ?? false) === true;
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
