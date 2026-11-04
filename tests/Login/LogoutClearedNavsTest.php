<?php

declare(strict_types=1);

namespace Lotgd\Tests\Login;

use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Logging out keeps the page to come back to among the stored links. With
 * partial rendering on, the page revision claim stores the links as '' until
 * a page saves new ones, and logout read that back as a list.
 */
final class LogoutClearedNavsTest extends TestCase
{
    public function testLoggingOutAfterTheClaimClearedTheLinksKeepsTheRestorePage(): void
    {
        $run = PageRunner::withRequest(
            'login.php',
            0,
            'GET',
            ['op' => 'logout'],
            [],
            ['accounts_table' => [1 => ['restorepage' => 'village.php']]],
            ['show_error_details' => 1],
            [],
            [],
            ['/SELECT restorepage, allowednavs FROM accounts/' => [['restorepage' => 'village.php', 'allowednavs' => '']]]
        );

        self::assertStringNotContainsString('Application Error', $run->html, substr($run->html, 0, 1500));
        $saved = array_values(preg_grep('/^UPDATE accounts SET allowednavs = /', array_map('strval', $run->queries)));
        self::assertCount(1, $saved, implode("\n", array_map('strval', $run->queries)));
        self::assertStringContainsString('village.php', $saved[0]);
    }
}
