<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\DBAL\ParameterType;
use Lotgd\Motd;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MotdPollVoteSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/DoctrineBootstrap.php';

        Database::$doctrineConnection = null;
        Database::$instance = null;
        DoctrineBootstrap::$conn = null;
    }

    /**
     * Supply values that must never cross the poll vote validation boundary.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'array' => [['1']];
        yield 'quote-bearing injection' => ["1' OR 1=1 --"];
        yield 'decimal suffix' => ['1.0'];
        yield 'zero integer' => [0];
        yield 'zero string' => ['0'];
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-1'];
        yield 'boolean' => [true];
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function testMalformedIdentifiersAreRejectedWithoutCoercion(mixed $value): void
    {
        self::assertNull(Motd::validatePollVoteIdentifier($value));
    }

    public function testValidVoteDeletesPreviousChoiceThenInsertsTypedReplacement(): void
    {
        self::assertSame(17, Motd::validatePollVoteIdentifier('17'));
        self::assertSame(3, Motd::validatePollVoteIdentifier(3));

        $connection = Database::getDoctrineConnection();

        Motd::recordPollVote(17, 3, 42);

        self::assertCount(2, $connection->executeStatements);
        [$delete, $insert] = $connection->executeStatements;

        self::assertSame(
            'DELETE FROM pollresults WHERE motditem = :motditem AND account = :account',
            $delete['sql']
        );
        self::assertSame(['motditem' => 17, 'account' => 42], $delete['params']);
        self::assertSame(
            ['motditem' => ParameterType::INTEGER, 'account' => ParameterType::INTEGER],
            $delete['types']
        );

        self::assertSame(
            'INSERT INTO pollresults (choice, account, motditem) VALUES (:choice, :account, :motditem)',
            $insert['sql']
        );
        self::assertSame(['motditem' => 17, 'choice' => 3, 'account' => 42], $insert['params']);
        self::assertSame(
            [
                'motditem' => ParameterType::INTEGER,
                'choice' => ParameterType::INTEGER,
                'account' => ParameterType::INTEGER,
            ],
            $insert['types']
        );
    }
}
