<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\LoginFailureTally;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The automatic ban: ten weighted failures from one address inside a day.
 *
 * This is the only ban the game issues on its own, and until now the test that
 * covered it read login.php as text and asserted that the string
 * "if ($c >= 10)" appeared in it. That assertion says nothing about what the
 * counter counts -- it holds just as well when a superuser failure stops
 * weighing double, when the threshold is never reached, or when it is reached
 * on the first attempt.
 */
final class LoginFailureTallyTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('provideFailureRuns')]
    public function testWeightAndBanDecision(array $rows, int $expectedWeight, bool $expectedBan): void
    {
        $tally = LoginFailureTally::fromRecentFailures($rows);

        self::assertSame($expectedWeight, $tally->weight);
        self::assertSame($expectedBan, $tally->warrantsBan());
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: int, 2: bool}>
     */
    public static function provideFailureRuns(): array
    {
        return [
            'no failures at all' => [[], 0, false],
            'one ordinary failure' => [self::ordinary(1), 1, false],

            // The boundary, from both sides. A rule that fires one attempt
            // early locks out a player who mistyped; one that fires late
            // leaves the door open for an extra guess.
            'nine ordinary failures stay under the threshold' => [self::ordinary(9), 9, false],
            'the tenth ordinary failure earns the ban' => [self::ordinary(10), 10, true],
            'past the threshold stays banned' => [self::ordinary(14), 14, true],

            // "5 failed attempts for superuser, 10 for regular user" is what
            // login.php's comment has always claimed. Nothing checked it.
            'four failures against a superuser are not enough' => [self::privileged(4), 8, false],
            'five failures against a superuser earn the ban' => [self::privileged(5), 10, true],

            // Mixed runs, because the weighting is per row rather than per run.
            'four privileged and one ordinary fall one short' => [
                [...self::privileged(4), ...self::ordinary(1)],
                9,
                false,
            ],
            'four privileged and two ordinary reach it' => [
                [...self::privileged(4), ...self::ordinary(2)],
                10,
                true,
            ],
        ];
    }

    /**
     * The driver hands integer columns back as strings, so the superuser flag
     * arrives as "1" rather than 1 on the legacy path. It has to weigh the
     * same either way -- otherwise the doubled weighting quietly stops
     * applying on exactly one of the two database paths login.php has.
     */
    public function testASuperuserFlagArrivingAsAStringStillWeighsDouble(): void
    {
        $asString = LoginFailureTally::fromRecentFailures([['superuser' => '1']]);
        $asInt = LoginFailureTally::fromRecentFailures([['superuser' => 1]]);

        self::assertSame(2, $asString->weight);
        self::assertSame($asInt->weight, $asString->weight);
        self::assertTrue($asString->privilegedSeen);
    }

    public function testPrivilegedSeenDescribesTheRunRatherThanTheLastRow(): void
    {
        $tally = LoginFailureTally::fromRecentFailures(
            [...self::privileged(1), ...self::ordinary(3)]
        );

        self::assertTrue(
            $tally->privilegedSeen,
            'one privileged failure anywhere in the window is what the alert mail reports on'
        );
        self::assertFalse(LoginFailureTally::fromRecentFailures(self::ordinary(3))->privilegedSeen);
    }

    /**
     * A row without the column counts as ordinary.
     *
     * The inline version read $row['superuser'] unguarded, so this raised an
     * undefined-key warning and then counted as ordinary anyway. The count is
     * what mattered and it is unchanged; only the warning is gone.
     */
    public function testARowWithoutTheColumnCountsAsOrdinary(): void
    {
        $tally = LoginFailureTally::fromRecentFailures([['ip' => '10.0.0.1']]);

        self::assertSame(1, $tally->weight);
        self::assertFalse($tally->privilegedSeen);
    }

    /**
     * The rows arrive from fetchAllAssociative() on one path and from a while
     * loop over fetchAssoc() on the other, so the tally takes an iterable.
     */
    public function testAGeneratorOfRowsIsWeighedTheSameAsAnArray(): void
    {
        $generator = (static function (): \Generator {
            yield ['superuser' => 1];
            yield ['superuser' => 0];
        })();

        self::assertSame(3, LoginFailureTally::fromRecentFailures($generator)->weight);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ordinary(int $count): array
    {
        return array_fill(0, $count, ['superuser' => 0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function privileged(int $count): array
    {
        return array_fill(0, $count, ['superuser' => 1]);
    }
}
