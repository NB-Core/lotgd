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

class ForcedNavigation
{
    public static array $baseAccount = [];

    /**
     * Handle forced navigation checks.
     */
    public static function doForcedNav(bool $anonymous, bool $overrideforced): void
    {
        global $session;
        $requestUri = PhpGenericEnvironment::getRequestUri();
        Output::getInstance()->rawOutput("<!--\nAllowAnonymous: " . ($anonymous ? "True" : "False") . "\nOverride Forced Nav: " . ($overrideforced ? "True" : "False") . "\n-->");
        if (isset($session['loggedin']) && $session['loggedin']) {
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
            $session['user']['page_revision'] = $loaded + 1;
            $baseaccount['page_revision'] = $loaded + 1;
            self::$baseAccount['page_revision'] = $loaded + 1;
            $baseaccount['allowednavs'] = '';
            self::$baseAccount['allowednavs'] = '';

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
}
