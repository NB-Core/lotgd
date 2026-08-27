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

    /**
     * Supply malformed values that must not be accepted as poll choice indexes.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidChoiceProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'negative integer' => [-1];
        yield 'signed string' => ['-1'];
        yield 'positive signed string' => ['+1'];
        yield 'decimal string' => ['1.5'];
        yield 'decimal number' => [1.5];
        yield 'array' => [['0']];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'quote-bearing injection' => ["0' OR 1=1 --"];
        yield 'integer overflow' => [PHP_INT_MAX . '0'];
    }

    public function testChoiceValidatorAcceptsZeroBasedIndexes(): void
    {
        self::assertSame(0, Motd::validatePollVoteChoice('0'));
        self::assertSame(0, Motd::validatePollVoteChoice(0));
        self::assertSame(4, Motd::validatePollVoteChoice('4'));
    }

    #[DataProvider('invalidChoiceProvider')]
    public function testMalformedChoicesAreRejectedWithoutCoercion(mixed $value): void
    {
        self::assertNull(Motd::validatePollVoteChoice($value));
    }

    public function testValidVoteDeletesPreviousChoiceThenInsertsTypedReplacement(): void
    {
        self::assertSame(17, Motd::validatePollVoteIdentifier('17'));
        self::assertSame(3, Motd::validatePollVoteIdentifier(3));

        $connection = Database::getDoctrineConnection();

        Motd::recordPollVote(17, 0, 42);

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
        self::assertSame(['motditem' => 17, 'choice' => 0, 'account' => 42], $insert['params']);
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
