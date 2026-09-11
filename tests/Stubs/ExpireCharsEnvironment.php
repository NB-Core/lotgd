<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

/**
 * The three collaborators ExpireChars needs, installed in one call.
 *
 * ExpireChars reaches for Lotgd\Settings, Lotgd\PlayerFunctions and
 * Lotgd\GameLog directly, so a test has to stand them up before the real ones
 * autoload. Four test files used to carry their own copy of the same three
 * eval() strings -- the GameLog one alone is a 700-character literal -- and the
 * copies had already drifted: two of them recorded log entries, two threw them
 * away, which is why a test wanting both behaviours could not share a file.
 *
 * Here they are defined once, with the two things tests actually vary exposed
 * as flags rather than as separate eval strings.
 *
 * Because these become real classes in the namespace, the calling test class
 * must run each test in its own process.
 */
final class ExpireCharsEnvironment
{
    public static function install(): void
    {
        if (! class_exists('Lotgd\\Settings', false)) {
            eval(<<<'STUB'
                namespace Lotgd;

                class Settings
                {
                    public function __construct(string $t = "settings_extended")
                    {
                    }

                    public static function getInstance(): self
                    {
                        return new self();
                    }

                    public function getSetting(string $n, mixed $d = null): mixed
                    {
                        return $d;
                    }

                    public function saveSetting(string $n, mixed $v): void
                    {
                    }
                }
                STUB);
        }

        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval(<<<'STUB'
                namespace Lotgd;

                class PlayerFunctions
                {
                    /** Whether charCleanup() reports success, for accounts not named below. */
                    public static bool $cleanupSucceeds = true;

                    /**
                     * Account ids for which charCleanup() reports failure.
                     *
                     * A run processes several accounts, and what a test usually
                     * wants is one of them failing while the rest succeed.
                     *
                     * @var array<int, int>
                     */
                    public static array $cleanupFailsFor = [];

                    /** Whether charCleanup() was reached at all. */
                    public static bool $cleanupCalled = false;

                    public static function charCleanup(int $id, int $type): bool
                    {
                        self::$cleanupCalled = true;

                        if (in_array($id, self::$cleanupFailsFor, true)) {
                            return false;
                        }

                        return self::$cleanupSucceeds;
                    }
                }
                STUB);
        }

        if (! class_exists('Lotgd\\GameLog', false)) {
            eval(<<<'STUB'
                namespace Lotgd;

                class GameLog
                {
                    public const SEVERITY_INFO = "info";
                    public const SEVERITY_WARNING = "warning";
                    public const SEVERITY_ERROR = "error";
                    public const SEVERITY_DEBUG = "debug";
                    public const CATEGORY_GENERAL = "general";
                    public const CATEGORY_SECURITY = "security";
                    public const CATEGORY_MAINTENANCE = "maintenance";
                    public const CATEGORY_EXPIRATION = "expiration";
                    public const CATEGORY_MODULES = "modules";
                    public const CATEGORY_USERS = "user management";
                    public const CATEGORY_SETTINGS = "settings";
                    public const CATEGORY_CLAN = "clan";
                    public const CATEGORY_BATTLE = "battle";
                    public const CATEGORY_CACHE = "cache";

                    /** @var array<int, array{0: string, 1: string, 2: string}> */
                    public static array $entries = [];

                    public static function log(string $m, string $c, bool $f = false, ?int $a = null, string $s = "info"): void
                    {
                        self::$entries[] = [$c, $m, $s];
                    }
                }
                STUB);
        }

        \Lotgd\GameLog::$entries = [];
        \Lotgd\PlayerFunctions::$cleanupSucceeds = true;
        \Lotgd\PlayerFunctions::$cleanupFailsFor = [];
        \Lotgd\PlayerFunctions::$cleanupCalled = false;
    }
}
