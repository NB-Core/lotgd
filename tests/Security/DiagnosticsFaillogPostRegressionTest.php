<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Diagnostics;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * The failed-login viewer must never read the submitted form data.
 *
 * `faillog.post` holds a serialize() of the entire POST body of a failed login
 * attempt, so it contains the password that was tried. Until this page existed
 * nothing read the table back at all, which is why the column was never a
 * problem; a viewer makes it one.
 *
 * Two independent guards, because either alone can be defeated: the statement
 * the collector actually issues, and the source of both the collector and the
 * page. A `SELECT *` would slip past a source check while still fetching the
 * column, and an explicit list could be widened without anyone noticing in the
 * generated SQL.
 */
final class DiagnosticsFaillogPostRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        Database::resetDoctrineConnection();
        parent::tearDown();
    }

    public function testTheFailedLoginQueryNeverAsksForTheSubmittedPost(): void
    {
        (new Diagnostics())->failLog(24, 50);

        $statement = Database::getDoctrineConnection()->fetchAllLog[0] ?? null;
        self::assertNotNull($statement, 'expected the failed-login query to be issued');

        $sql = (string) $statement['sql'];
        self::assertStringContainsString('FROM faillog', $sql);
        self::assertStringNotContainsString('post', $sql);
        self::assertStringNotContainsString('*', $sql, 'an explicit column list is what keeps post out');
    }

    public function testTheTimelineDoesNotReachForItEither(): void
    {
        Database::$mockResults = [
            [],
            [
                [
                    'eventid' => 1,
                    'date' => '2026-09-09 11:30:00',
                    'ip' => '203.0.113.7',
                    'acctid' => 7,
                    'name' => 'Admin',
                    'login' => 'admin',
                    'superuser' => 1,
                ],
            ],
        ];

        $timeline = (new Diagnostics())->timeline(24, 50);

        self::assertCount(1, $timeline);
        self::assertStringContainsString('203.0.113.7', $timeline[0]['text']);

        foreach (Database::getDoctrineConnection()->fetchAllLog as $statement) {
            self::assertStringNotContainsString('post', (string) $statement['sql']);
        }
    }

    public function testNeitherTheCollectorNorThePageSelectsTheColumn(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['/src/Lotgd/Diagnostics.php', '/diagnostics.php'] as $file) {
            $source = (string) file_get_contents($root . $file);

            // Strip comments so the prose explaining *why* the column is avoided
            // does not itself trip the check.
            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            self::assertStringNotContainsString(
                'post',
                $code,
                $file . ' must not name the faillog post column anywhere in its code'
            );
        }
    }
}
