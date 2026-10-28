<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Modules;
use Lotgd\Settings;
use Lotgd\Template;
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
     * values, '' meaning "no op".
     *
     * The fights are here too (forest, graveyard, masters, dragon, PvP):
     * every round is a GET link the page granted, consumed with the page
     * revision like any other, and the fight lives in the account
     * (badguy, bufflist), so a swapped round is no less safe than a full
     * page. What a fight does not swap: the dragon's kill (prologue*) and
     * the graveyard's resurrection and haunting.
     *
     * The shops and the inn followed for the same reason: buying, selling,
     * healing, the bartender and the room are granted GET links too.
     *
     * Forms post in place where they are listed under POST: the bank's
     * bookings, the preferences, the player search, the bartender's colour
     * test and the clan's forms. A form's target is granted like a link
     * (Nav::add('', target)) and consumes the page revision once, so a second
     * click is turned away as it would be on a full page; the bank's bookings
     * are guarded actions on top. The client sends only the forms the page
     * names (pageForms()); every other form is an ordinary page change.
     *
     * Module pages (runmodule.php) are not listed here: a module declares its
     * own under the info key `partial`, in the same shape, see
     * Modules::partialDeclaration().
     *
     * The village and the inn carry a commentary block; for a partial
     * player its preview field is declarative markup the client handles
     * (Forms::previewField()), so it swaps in like any other region content.
     *
     * @var array<string,array<string,list<string>>>
     */
    public const ALLOWLIST = [
        'village.php' => ['GET' => ['']],
        'forest.php' => ['GET' => ['', 'search', 'fight', 'run', 'dragon']],
        'inn.php' => [
            'GET' => ['', 'strolldown', 'fleedragon', 'converse', 'bartender', 'room'],
            'POST' => ['bartender'],
        ],
        'gardens.php' => ['GET' => ['']],
        'encounter.php' => ['GET' => ['', 'view']],
        'rock.php' => ['GET' => ['']],
        'shades.php' => ['GET' => ['']],
        'graveyard.php' => ['GET' => ['', 'search', 'fight', 'run']],
        'lodge.php' => ['GET' => ['', 'points']],
        'gypsy.php' => ['GET' => ['', 'talk', 'pay']],
        'hof.php' => ['GET' => ['', 'kills', 'money', 'gems', 'charm', 'tough', 'resurrects', 'days', 'duels']],
        'list.php' => ['GET' => ['', 'clan'], 'POST' => ['search']],
        'bio.php' => ['GET' => ['']],
        'news.php' => ['GET' => ['']],
        'about.php' => ['GET' => ['', 'setup', 'listmodules', 'license']],
        'referral.php' => ['GET' => ['']],
        'stables.php' => ['GET' => ['', 'examine', 'buymount', 'confirmbuy', 'sellmount', 'confirmsell', 'feed']],
        'weapons.php' => ['GET' => ['', 'buy']],
        'armor.php' => ['GET' => ['', 'buy']],
        'mercenarycamp.php' => ['GET' => ['', 'buy', 'heal']],
        'healer.php' => ['GET' => ['', 'buy', 'companion']],
        'train.php' => ['GET' => ['', 'question', 'challenge', 'autochallenge', 'fight', 'run']],
        'bank.php' => [
            'GET' => ['', 'transfer', 'deposit', 'withdraw', 'borrow'],
            'POST' => ['transfer2', 'transfer3', 'depositfinish', 'withdrawfinish'],
        ],
        // Leaving a clan (withdraw*) stays a full page.
        'clan.php' => [
            'GET' => ['', 'list', 'waiting', 'apply', 'new', 'motd', 'membership', 'grant'],
            'POST' => ['new', 'motd', 'membership', 'grant'],
        ],
        // The preference form; changing the e-mail address and deleting the
        // character stay full pages.
        'prefs.php' => ['GET' => [''], 'POST' => ['save']],
        'pvp.php' => ['GET' => ['', 'fight', 'run']],
        'dragon.php' => ['GET' => ['', 'fight', 'run']],
    ];

    /**
     * Answer to a form sent partially that may not be: the client sends the
     * same form again as an ordinary page change, so nothing typed is lost.
     */
    public const RESUBMIT_HEADER = 'X-Lotgd-Resubmit';

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

        if (strtoupper(self::method()) === 'POST') {
            // A redirect would turn the POST into a GET and lose the form.
            // Nothing has run yet, so the client can send it again, whole.
            self::sendHeader(self::RESUBMIT_HEADER . ': 1');
            exit();
        }

        self::sendHeader('HX-Redirect: ' . self::requestUri());
        exit();
    }

    /**
     * The form targets on this page that may be sent partially: the granted
     * navigation (allowednavs) whose script and op are allowed for POST.
     * Only what this page offers, usually none to a handful, never the
     * whole allowlist.
     *
     * @param array<array-key,mixed> $allowednavs The navigation the page just granted.
     *
     * @return list<string>
     */
    public static function pageForms(array $allowednavs): array
    {
        $forms = [];
        foreach ($allowednavs as $uri => $granted) {
            if (!$granted || !is_string($uri) || $uri === '') {
                continue;
            }
            $parts = parse_url($uri);
            if ($parts === false || isset($parts['host']) || !isset($parts['path'])) {
                continue;
            }
            $query = [];
            parse_str($parts['query'] ?? '', $query);
            // A link carries the page counter (Nav::add() with a text); a
            // form target is granted without one.
            if (isset($query['c'])) {
                continue;
            }
            $op = isset($query['op']) && is_string($query['op']) ? $query['op'] : '';
            if (self::isAllowed(basename($parts['path']), 'POST', $op, $query)) {
                $forms[$uri] = true;
            }
        }

        return array_keys($forms);
    }

    /**
     * The meta tag `lotgd-partial-forms` with pageForms(). It may differ
     * from page to page: lotgd-* meta tags are left out of the head
     * signature, and a partial answer brings the new list in its
     * X-Lotgd-Partial-Forms header.
     *
     * @param list<string> $forms
     */
    public static function formsMeta(array $forms): string
    {
        return '<meta name="lotgd-partial-forms" content="' . htmlspecialchars(self::formsJson($forms), ENT_QUOTES) . '">';
    }

    /**
     * @param list<string> $forms
     */
    public static function formsJson(array $forms): string
    {
        // ASCII only, so it is a valid header value too.
        return (string) json_encode(array_values($forms), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
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
     * @param array<array-key,mixed> $query The request's query parameters; a
     *                                      module page is named by `module`.
     */
    public static function isAllowed(string $script, string $method, string $op, array $query = []): bool
    {
        if ($script === 'runmodule.php') {
            $module = $query['module'] ?? '';
            // The module's administration (admin=1) always loads in full.
            if (!is_string($module) || $module === '' || isset($query['admin'])) {
                return false;
            }

            return in_array($op, Modules::partialDeclaration($module)[strtoupper($method)] ?? [], true);
        }

        return in_array($op, self::ALLOWLIST[$script][strtoupper($method)] ?? [], true);
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
     * The active skin declares regions: a Twig skin in its config.json, a
     * legacy template in its `config` section.
     */
    public static function skinSupportsRegions(): bool
    {
        return self::skinDeclares('regions');
    }

    /**
     * Whether the active skin sets $key to true (`regions`, `overlay`): a
     * Twig skin in its config.json, a legacy `.htm` template in the JSON of
     * its `<!--!config-->` section (templates/README.md).
     */
    public static function skinDeclares(string $key): bool
    {
        if (TwigTemplate::isActive()) {
            return self::configDeclares(dirname(__DIR__, 3) . '/' . TwigTemplate::getPath() . '/config.json', $key);
        }

        return self::sectionDeclares((string) (Template::getInstance()->getTemplate()['config'] ?? ''), $key);
    }

    /**
     * Whether the skin named as in the `defaultskin` setting (`twig:aurora`,
     * `jade.htm`, `legacy:jade.htm`) sets $key to true.
     */
    public static function skinDeclaresFor(string $skin, string $key): bool
    {
        if (str_starts_with($skin, 'twig:')) {
            $name = substr($skin, strlen('twig:'));
            if ($name === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
                return false;
            }

            return self::configDeclares(dirname(__DIR__, 3) . '/templates_twig/' . $name . '/config.json', $key);
        }
        $name = str_starts_with($skin, 'legacy:') ? substr($skin, strlen('legacy:')) : $skin;
        if (!preg_match('/^[A-Za-z0-9_-]+\.htm$/', $name)) {
            return false;
        }
        $file = dirname(__DIR__, 3) . '/templates/' . $name;
        if (!is_file($file)) {
            return false;
        }
        // The section as Template::loadTemplate() cuts it.
        $parts = explode('<!--!config-->', (string) file_get_contents($file), 2);
        $section = isset($parts[1]) ? explode('<!--!', $parts[1], 2)[0] : '';

        return self::sectionDeclares($section, $key);
    }

    private static function configDeclares(string $config, string $key): bool
    {
        if (!is_file($config)) {
            return false;
        }

        return self::sectionDeclares((string) file_get_contents($config), $key);
    }

    private static function sectionDeclares(string $json, string $key): bool
    {
        $data = json_decode(trim($json), true);

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

    /**
     * The game lets pages change in place at all (partial_render).
     */
    public static function settingEnabled(): bool
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
