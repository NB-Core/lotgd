<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\ParameterType;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\Serialization;
use Lotgd\Output;
use Lotgd\Redirect;
use Lotgd\PhpGenericEnvironment;
use Lotgd\Page\PartialMode;
use Lotgd\Page\PartialRenderer;
use Lotgd\Realtime\Poll;

class ForcedNavigation
{
    public static array $baseAccount = [];

    /**
     * The page revision this request claimed and the links it cleared, until
     * the request saves the account; given back if it never does.
     *
     * @var array{acctid:int,loaded:int,navs:string}|null
     */
    private static ?array $unsavedClaim = null;

    private static bool $releaseRegistered = false;

    /** This request claimed the page revision (consumePageRevision()). */
    private static bool $claimedThisRequest = false;

    /**
     * Handle forced navigation checks.
     */
    public static function doForcedNav(bool $anonymous, bool $overrideforced): void
    {
        global $session;
        $requestUri = PhpGenericEnvironment::getRequestUri();
        Output::getInstance()->rawOutput("<!--\nAllowAnonymous: " . ($anonymous ? "True" : "False") . "\nOverride Forced Nav: " . ($overrideforced ? "True" : "False") . "\n-->");
        if (isset($session['loggedin']) && $session['loggedin']) {
            if (Realtime::enabled() && !(defined('AJAX_MODE') && AJAX_MODE)) {
                Poll::noteStartCursor();
            }
            $result = Database::getDoctrineConnection()->executeQuery(
                'SELECT * FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
                ['acctid' => (int) ($session['user']['acctid'] ?? 0)],
                ['acctid' => ParameterType::INTEGER]
            );
            if (Database::numRows($result) == 1) {
                $session['user'] = Database::fetchAssoc($result);
                global $baseaccount;
                $baseaccount = $session['user'];
                self::$baseAccount = $session['user'];
                $session['bufflist'] = Serialization::safeUnserialize($session['user']['bufflist']);
                if (!is_array($session['bufflist'])) {
                    $session['bufflist'] = [];
                }
                $session['user']['dragonpoints'] = Serialization::safeUnserialize($session['user']['dragonpoints']);
                if (!is_array($session['user']['dragonpoints'])) {
                    $session['user']['dragonpoints'] = [];
                }
                $session['user']['prefs'] = Serialization::safeUnserialize($session['user']['prefs']);
                if (!is_array($session['user']['prefs'])) {
                    $session['user']['prefs'] = [];
                }
                $allowednavs = Serialization::safeUnserialize($session['user']['allowednavs']);
                if (is_array($allowednavs)) {
                    $session['allowednavs'] = $allowednavs;
                } else {
                    $session['allowednavs'] = [];
                }
                if (!$session['user']['loggedin'] || ((date('U') - strtotime($session['user']['laston'])) > Settings::getInstance()->getSetting('LOGINTIMEOUT', 900))) {
                    $session = [];
                    if (defined('AJAX_MODE') && AJAX_MODE) {
                        $session['loggedin'] = false;
                        return;
                    }
                    Redirect::redirect('index.php?op=timeout', 'Account not logged in but session thinks they are.');
                }
            } else {
                $session = [];
                $session['message'] = Translator::translateInline("`4Error, your login was incorrect`0", "login");
                Redirect::redirect('index.php', 'Account Disappeared!');
            }
            Database::freeResult($result);
            if (isset($session['allowednavs'][$requestUri]) && $session['allowednavs'][$requestUri] && $overrideforced !== true) {
                $session['allowednavs'] = [];
                self::consumePageRevision();
            } elseif ($overrideforced !== true && Action::isReplayOf((int) $session['user']['acctid'], $requestUri)) {
                $session['allowednavs'] = [];
                self::consumePageRevision();
            } elseif ($overrideforced !== true && Grant::isOfferedTo((int) $session['user']['acctid'], $requestUri)) {
                // The view link of an open grant, perhaps shown by a realtime
                // update after the page was built. It only displays.
                $session['allowednavs'] = [];
                self::consumePageRevision();
            } else {
                if ($overrideforced !== true) {
                    Redirect::redirect('badnav.php', 'Navigation not allowed to ' . $requestUri);
                }
            }
        } else {
            if (!$anonymous) {
                if (defined('AJAX_MODE') && AJAX_MODE) {
                    $session['loggedin'] = false;
                    $session = [];
                    return;
                }
                Redirect::redirect('index.php?op=timeout', 'Not logged in: ' . $requestUri);
            }
        }
    }

    /**
     * Claim the page this navigation started from, for a player with partial
     * rendering on.
     *
     * The allowed links were read with the account. One conditional update
     * moves page_revision on from the value read in the same row *and* clears
     * the stored links, so consuming them is atomic: a second request that
     * read the same row -- a double click, another tab, another device --
     * loses the update, and one that reads the row afterwards finds no links
     * to follow. Either way it is turned away before the page runs, and must
     * not save, or its empty navigation would overwrite the winner's. The
     * winner writes its new links when it saves.
     *
     * Players without partial rendering keep today's behaviour exactly.
     */
    private static function consumePageRevision(): void
    {
        global $session, $baseaccount;

        if (!PartialMode::isEnabledFor($session['user']) || !array_key_exists('page_revision', $session['user'])) {
            return;
        }

        $loaded = (int) $session['user']['page_revision'];
        // A partial client says which revision it shows. The account row only
        // knows the latest one, and the latest page may offer the same link
        // as the page a stale tab still shows (the inn links to the inn).
        $shown = $_SERVER[PartialRenderer::REQUEST_REVISION_SERVER_KEY] ?? null;
        $stale = PartialMode::isActive() && is_string($shown) && ctype_digit($shown) && (int) $shown !== $loaded;
        $claimed = $stale ? 0 : Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('accounts')
            . " SET page_revision = page_revision + 1, allowednavs = ''"
            . ' WHERE acctid = :acctid AND page_revision = :revision',
            ['acctid' => (int) $session['user']['acctid'], 'revision' => $loaded],
            ['acctid' => ParameterType::INTEGER, 'revision' => ParameterType::INTEGER]
        );

        if ((int) $claimed === 1) {
            // Written already; keep saveUser()'s diff from writing the revision
            // again, and make it always write the new links, even when they
            // serialize to what was stored before the claim cleared them.
            self::$claimedThisRequest = true;
            $session['user']['page_revision'] = $loaded + 1;
            $baseaccount['page_revision'] = $loaded + 1;
            self::$baseAccount['page_revision'] = $loaded + 1;
            $baseaccount['allowednavs'] = '';
            self::$baseAccount['allowednavs'] = '';

            // A request that ends without saving the account (an iframe such
            // as the user editor's last page hit, the grotto's keepalive, an
            // editor's XML lookup, a die()) would leave the account without
            // links. Classic players never lost them there, as nothing was
            // written; give the claim back at the end instead.
            self::$unsavedClaim = [
                'acctid' => (int) $session['user']['acctid'],
                'loaded' => $loaded,
                'navs' => (string) ($session['user']['allowednavs'] ?? ''),
            ];
            if (!self::$releaseRegistered) {
                self::$releaseRegistered = true;
                register_shutdown_function([self::class, 'releaseUnsavedClaim']);
            }

            return;
        }

        if (!defined('NO_SAVE_USER')) {
            define('NO_SAVE_USER', true);
        }

        if (PartialMode::isActive()) {
            http_response_code(409);
            PartialMode::sendHeader('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['reload' => 'badnav.php']);
            exit();
        }

        Redirect::redirect('badnav.php', 'Page revision ' . $loaded . ' was already consumed by another request');
    }

    /**
     * Whether this request claimed the page revision, which is what guards a
     * page against a double click or a second tab. A page that changes in
     * place must have; one that skipped forced navigation (a module with
     * override_forced_nav) has not, and loads in full instead.
     */
    public static function claimedThisRequest(): bool
    {
        return self::$claimedThisRequest;
    }

    /**
     * The request wrote the account, and with it its own links.
     */
    public static function claimSaved(): void
    {
        self::$unsavedClaim = null;
    }

    /**
     * End of a request that claimed the page revision and never saved: put
     * the revision and the links back, as if it had not passed. Only while
     * the row is still as the claim left it -- once another request claimed
     * or saved, there is nothing to give back.
     */
    public static function releaseUnsavedClaim(): void
    {
        $claim = self::$unsavedClaim;
        self::$unsavedClaim = null;
        if ($claim === null) {
            return;
        }

        try {
            Database::getDoctrineConnection()->executeStatement(
                'UPDATE ' . Database::prefix('accounts')
                . ' SET page_revision = :loaded, allowednavs = :navs'
                . " WHERE acctid = :acctid AND page_revision = :claimed AND allowednavs = ''",
                [
                    'loaded' => $claim['loaded'],
                    'navs' => $claim['navs'],
                    'acctid' => $claim['acctid'],
                    'claimed' => $claim['loaded'] + 1,
                ],
                [
                    'loaded' => ParameterType::INTEGER,
                    'navs' => ParameterType::STRING,
                    'acctid' => ParameterType::INTEGER,
                    'claimed' => ParameterType::INTEGER,
                ]
            );
        } catch (\Throwable $e) {
            error_log('Could not give back an unsaved page revision claim: ' . $e->getMessage());
        }
    }

    /**
     * Add links to the navigation stored with the page the player is on.
     *
     * A line the async chat appends after the page was built (the player's
     * own post, a line another player wrote) links its author's bio and,
     * for a moderator, its [Del]. Those links were registered in the async
     * request only, which saves no navigation, so following one ended on
     * badnav.php. This adds them to the stored links of the same page.
     *
     * The write is conditional on the stored links being those just read: if
     * a page change claimed or replaced them meanwhile, nothing is written --
     * the next page draws its own lines and links. A page in the middle of a
     * navigation (no stored links) is left alone the same way.
     *
     * $stillThePage gets the stored links and says whether they are still
     * those of the page the new links belong to; without it, nothing is
     * written.
     *
     * @param list<string>             $links       allowednavs keys, as Nav::add() stores them
     * @param callable(array): bool    $stillThePage
     */
    public static function allowOnStoredPage(int $acctid, array $links, callable $stillThePage): bool
    {
        $links = array_values(array_filter($links, static fn ($link): bool => is_string($link) && $link !== ''));
        if ($acctid <= 0 || $links === []) {
            return false;
        }

        try {
            $conn = Database::getDoctrineConnection();
            $accounts = Database::prefix('accounts');
            $stored = $conn->fetchOne(
                "SELECT allowednavs FROM {$accounts} WHERE acctid = :acctid",
                ['acctid' => $acctid],
                ['acctid' => ParameterType::INTEGER]
            );
            if (!is_string($stored) || $stored === '') {
                return false;
            }
            $navs = Serialization::safeUnserialize($stored);
            if (!is_array($navs) || $navs === [] || !$stillThePage($navs)) {
                return false;
            }
            $added = false;
            foreach ($links as $link) {
                if (empty($navs[$link])) {
                    $navs[$link] = true;
                    $added = true;
                }
            }
            if (!$added) {
                return true;
            }

            return $conn->executeStatement(
                "UPDATE {$accounts} SET allowednavs = :added WHERE acctid = :acctid AND allowednavs = :stored",
                ['added' => serialize($navs), 'acctid' => $acctid, 'stored' => $stored],
                ['added' => ParameterType::STRING, 'acctid' => ParameterType::INTEGER, 'stored' => ParameterType::STRING]
            ) === 1;
        } catch (\Throwable $e) {
            error_log('ForcedNavigation::allowOnStoredPage: ' . $e->getMessage());

            return false;
        }
    }
}
