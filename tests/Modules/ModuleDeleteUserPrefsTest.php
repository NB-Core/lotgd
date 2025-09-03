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

namespace Lotgd\Tests\Modules {

use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

final class ModuleDeleteUserPrefsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);

        $conn = new DoctrineConnection();
        Database::$doctrineConnection = $conn;
        \Lotgd\Doctrine\Bootstrap::$conn = $conn;

        global $session, $module_prefs, $massinvalidates;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true]];
        $module_prefs = [];
        $massinvalidates = [];
    }

    protected function tearDown(): void
    {
        Database::$doctrineConnection = null;
        \Lotgd\Doctrine\Bootstrap::$conn = null;
    }

    public function testDeleteUserPrefsClearsGlobalCache(): void
    {
        global $module_prefs, $massinvalidates;

        $userId = 1;
        $module_prefs = [
            $userId => [
                'modA' => ['prefA' => 'value'],
                'modB' => ['prefB' => 'value'],
            ],
            2 => [
                'modC' => ['prefC' => 'value'],
            ],
        ];

        module_delete_userprefs($userId);

        self::assertArrayNotHasKey($userId, $module_prefs);
        self::assertArrayHasKey(2, $module_prefs);
        self::assertContains("module_userprefs-$userId", $massinvalidates);
    }
}

}
