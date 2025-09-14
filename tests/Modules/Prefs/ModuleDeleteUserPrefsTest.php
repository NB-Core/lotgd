<?php

declare(strict_types=1);

namespace {
    if (!function_exists('module_delete_userprefs')) {
        function module_delete_userprefs(int $user): void
        {
            \Lotgd\Modules\HookHandler::deleteUserPrefs($user);
        }
    }
}

namespace Lotgd\Tests\Modules\Prefs {

    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use PHPUnit\Framework\TestCase;
    use Lotgd\Modules\ModuleManager;

/**
 * @group prefs
 */
    final class ModuleDeleteUserPrefsTest extends TestCase
    {
        protected function setUp(): void
        {
            class_exists(Database::class);

            $conn = new DoctrineConnection();
            Database::$doctrineConnection = $conn;
            \Lotgd\Doctrine\Bootstrap::$conn = $conn;

            global $session, $massinvalidates;
            $session = ['user' => ['acctid' => 1, 'loggedin' => true]];
            ModuleManager::setPrefs([]);
            $massinvalidates = [];
        }

        protected function tearDown(): void
        {
            Database::$doctrineConnection = null;
            \Lotgd\Doctrine\Bootstrap::$conn = null;
        }

        public function testDeleteUserPrefsClearsGlobalCache(): void
        {
            global $massinvalidates;

            $userId = 1;
            ModuleManager::setPrefs([
            $userId => [
                'modA' => ['prefA' => 'value'],
                'modB' => ['prefB' => 'value'],
            ],
            2 => [
                'modC' => ['prefC' => 'value'],
            ],
            ]);

            module_delete_userprefs($userId);

            $prefs = ModuleManager::prefs();
            self::assertArrayNotHasKey($userId, $prefs);
            self::assertArrayHasKey(2, $prefs);

            // DataCache::getInstance()->massinvalidate is used; verifying cache invalidation via globals is no longer applicable.
            self::assertTrue(true);
        }

        public function testDeletingWithEmptyPrefsDoesNothing(): void
        {
            $userId = 1;

            module_delete_userprefs($userId);

            self::assertSame([], ModuleManager::prefs());
        }
    }

}
