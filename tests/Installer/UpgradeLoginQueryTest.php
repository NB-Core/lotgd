<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use PHPUnit\Framework\TestCase;

/**
 * The installer's upgrade login looks up the administrator with bound
 * parameters, not with a login name escaped into the SQL string.
 */
final class UpgradeLoginQueryTest extends TestCase
{
    public function testTheUpgradeLoginBindsTheLoginName(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/install/lib/Installer.php');

        self::assertStringNotContainsString('Database::escape(Http::post("username"))', $source);
        self::assertStringContainsString("WHERE login = :login AND (superuser & :megauser) <> 0", $source);
        self::assertStringContainsString("['login' => (string) Http::post(\"username\"), 'megauser' => SU_MEGAUSER]", $source);
    }
}
