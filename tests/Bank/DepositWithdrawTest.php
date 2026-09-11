<?php

declare(strict_types=1);

namespace Lotgd\Tests\Bank;

use Lotgd\Bank;
use Lotgd\Bank\Balance;
use Lotgd\Bank\WithdrawOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bank::deposit() and Bank::withdraw(), neither of which had a test.
 *
 * The balance is signed throughout: a negative goldInBank is a debt to the
 * bank. That one fact explains most of what looks odd here -- a deposit and a
 * repayment are the same operation, and withdrawing "all of it" from a debt
 * means taking the debt's magnitude as the amount.
 *
 * Withdrawing and borrowing share a single entry point, because the form that
 * borrows posts to the same place as the form that withdraws and only adds a
 * flag. Asking for more than the balance is an error without that flag and a
 * loan with it.
 */
final class DepositWithdrawTest extends TestCase
{
    private const BORROW_PER_LEVEL = 20;

    /**
     * A posted amount becomes a non-negative integer, and the one input that
     * used to crash no longer does.
     *
     * `abs(PHP_INT_MIN)` is a float, because the positive counterpart does not
     * fit in an int -- and a float against these methods' `int` parameters is a
     * TypeError under strict_types. `amount=-9223372036854775808` is a
     * perfectly postable string, so it was a crash a player could ask for.
     * Reported by Codex on #1528.
     */
    #[DataProvider('postedAmountProvider')]
    public function testAPostedAmountBecomesANonNegativeInteger(mixed $posted, int $expected): void
    {
        self::assertSame($expected, Bank::postedAmount($posted));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function postedAmountProvider(): array
    {
        return [
            'a plain figure' => ['250', 250],
            'a negative is taken as its magnitude' => ['-250', 250],
            'a decimal is truncated' => ['12.9', 12],
            'nothing at all' => ['', 0],
            'not a number' => ['all of it', 0],
            'missing' => [null, 0],
            'an array' => [['1'], 0],
            'the integer minimum saturates rather than throwing' => ['-9223372036854775808', PHP_INT_MAX],
            'the integer maximum survives' => ['9223372036854775807', PHP_INT_MAX],
        ];
    }

    /**
     * And the saturated figure still reaches a refusal rather than an exception
     * -- which is what it did before these methods were typed.
     */
    public function testTheSaturatedAmountIsRefusedRatherThanThrowing(): void
    {
        $amount = Bank::postedAmount('-9223372036854775808');

        $deposit = Bank::deposit(new Balance(gold: 100, goldInBank: 0), $amount);
        self::assertFalse($deposit->accepted, 'far more than the purse holds');

        $withdrawal = Bank::withdraw(new Balance(gold: 0, goldInBank: 100, level: 10), $amount, false, self::BORROW_PER_LEVEL);
        self::assertSame(WithdrawOutcome::NotEnoughInBank, $withdrawal->outcome);
    }

    public function testADepositMovesCashIntoTheBalance(): void
    {
        $result = Bank::deposit(new Balance(gold: 500, goldInBank: 100), 200);

        self::assertTrue($result->accepted);
        self::assertSame(300, $result->balance->gold);
        self::assertSame(300, $result->balance->goldInBank);
        self::assertSame(200, $result->amount);
    }

    /**
     * Paying a debt down is the same arithmetic, which is why one method serves
     * both and the page only changes its wording.
     */
    public function testADepositAgainstADebtPaysItDown(): void
    {
        $partial = Bank::deposit(new Balance(gold: 500, goldInBank: -300), 100);

        self::assertSame(-200, $partial->balance->goldInBank, 'still owing 200');

        $cleared = Bank::deposit(new Balance(gold: 500, goldInBank: -300), 400);

        self::assertSame(100, $cleared->balance->goldInBank, 'paid off and 100 to the good');
    }

    /**
     * The form says so in as many words: enter nothing to deposit it all.
     */
    public function testADepositOfZeroMeansEverythingInHand(): void
    {
        $result = Bank::deposit(new Balance(gold: 500, goldInBank: 0), 0);

        self::assertSame(500, $result->amount, 'the whole purse');
        self::assertSame(0, $result->balance->gold);
        self::assertSame(500, $result->balance->goldInBank);
    }

    public function testADepositBeyondTheCashInHandIsRefusedAndMovesNothing(): void
    {
        $before = new Balance(gold: 100, goldInBank: 50);
        $result = Bank::deposit($before, 200);

        self::assertFalse($result->accepted);
        self::assertSame($before->gold, $result->balance->gold, 'no cash may move');
        self::assertSame($before->goldInBank, $result->balance->goldInBank, 'no balance may move');
        self::assertSame(200, $result->amount, 'and the figure is carried back for the message');
    }

    public function testAWithdrawalMovesTheBalanceIntoCash(): void
    {
        $result = Bank::withdraw(new Balance(gold: 10, goldInBank: 500), 200, false, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::Withdrawn, $result->outcome);
        self::assertSame(210, $result->balance->gold);
        self::assertSame(300, $result->balance->goldInBank);
        self::assertSame(200, $result->withdrawn);
        self::assertSame(0, $result->borrowed);
    }

    public function testAWithdrawalOfZeroMeansTheWholeBalance(): void
    {
        $result = Bank::withdraw(new Balance(gold: 10, goldInBank: 500), 0, false, self::BORROW_PER_LEVEL);

        self::assertSame(510, $result->balance->gold);
        self::assertSame(0, $result->balance->goldInBank);
        self::assertSame(500, $result->withdrawn);
    }

    /**
     * Zero against a debt takes the debt's magnitude as the amount, so a player
     * who owes 300 and enters nothing is asking to borrow another 300 -- which
     * is refused without the borrow flag, exactly as any other overdraw is.
     */
    public function testZeroAgainstADebtAsksForTheDebtsMagnitude(): void
    {
        $result = Bank::withdraw(new Balance(gold: 0, goldInBank: -300, level: 1), 0, false, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::NotEnoughInBank, $result->outcome);
        self::assertSame(300, $result->requested, 'the magnitude of the debt, not zero');
    }

    public function testOverdrawingWithoutTheBorrowFlagIsRefusedAndMovesNothing(): void
    {
        $before = new Balance(gold: 10, goldInBank: 100, level: 10);
        $result = Bank::withdraw($before, 500, false, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::NotEnoughInBank, $result->outcome);
        self::assertFalse($result->accepted());
        self::assertSame($before->gold, $result->balance->gold);
        self::assertSame($before->goldInBank, $result->balance->goldInBank);
    }

    /**
     * With the flag, the balance is emptied first and only the remainder
     * becomes debt.
     */
    public function testBorrowingEmptiesTheBalanceBeforeTakingALoan(): void
    {
        // level 10 may owe 200 in total; 100 in the bank, asking for 250
        $result = Bank::withdraw(new Balance(gold: 0, goldInBank: 100, level: 10), 250, true, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::WithdrawnAndBorrowed, $result->outcome);
        self::assertSame(100, $result->withdrawn, 'the balance goes first');
        self::assertSame(150, $result->borrowed, 'and the rest is a loan');
        self::assertSame(250, $result->balance->gold);
        self::assertSame(-150, $result->balance->goldInBank, 'which is the debt');
    }

    public function testBorrowingAgainstAnEmptyBalanceIsAllLoan(): void
    {
        $result = Bank::withdraw(new Balance(gold: 0, goldInBank: 0, level: 10), 150, true, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::Borrowed, $result->outcome);
        self::assertSame(0, $result->withdrawn);
        self::assertSame(150, $result->borrowed);
        self::assertSame(-150, $result->balance->goldInBank);
    }

    /**
     * The ceiling is on the total owed, so an existing debt eats into what may
     * still be borrowed.
     */
    public function testAnExistingDebtCountsAgainstTheBorrowingLimit(): void
    {
        // level 10 may owe 200; 120 is already owed, so 80 is left
        $withinLimit = Bank::withdraw(new Balance(gold: 0, goldInBank: -120, level: 10), 80, true, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::Borrowed, $withinLimit->outcome);
        self::assertSame(-200, $withinLimit->balance->goldInBank, 'exactly at the ceiling');

        $overLimit = Bank::withdraw(new Balance(gold: 0, goldInBank: -120, level: 10), 81, true, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::OverBorrowingLimit, $overLimit->outcome);
        self::assertSame(0, $overLimit->balance->gold, 'and nothing moved');
        self::assertSame(-120, $overLimit->balance->goldInBank);
    }

    /**
     * The limit is level times the setting, so it grows with the player and can
     * be switched off entirely by setting the rate to zero.
     */
    #[DataProvider('borrowingLimitProvider')]
    public function testTheBorrowingLimitIsLevelTimesTheRate(int $level, int $rate, int $expected): void
    {
        $result = Bank::withdraw(new Balance(gold: 0, goldInBank: 0, level: $level), 1, true, $rate);

        self::assertSame($expected, $result->borrowingLimit);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function borrowingLimitProvider(): array
    {
        return [
            'a beginner'      => [1, 20, 20],
            'a veteran'       => [15, 20, 300],
            'a raised rate'   => [10, 50, 500],
            'lending is off'  => [10, 0, 0],
        ];
    }

    /**
     * Borrowing exactly the ceiling is allowed; one gold more is not.
     */
    public function testTheCeilingIsInclusive(): void
    {
        $atCeiling = Bank::withdraw(new Balance(gold: 0, goldInBank: 0, level: 10), 200, true, self::BORROW_PER_LEVEL);
        self::assertTrue($atCeiling->accepted());

        $overCeiling = Bank::withdraw(new Balance(gold: 0, goldInBank: 0, level: 10), 201, true, self::BORROW_PER_LEVEL);
        self::assertSame(WithdrawOutcome::OverBorrowingLimit, $overCeiling->outcome);
    }

    /**
     * A withdrawal of exactly the balance is a withdrawal, not a loan -- the
     * boundary the old code expressed as `$amount > $goldinbank`.
     */
    public function testTakingExactlyTheBalanceIsNotBorrowing(): void
    {
        $result = Bank::withdraw(new Balance(gold: 0, goldInBank: 100, level: 10), 100, true, self::BORROW_PER_LEVEL);

        self::assertSame(WithdrawOutcome::Withdrawn, $result->outcome, 'no loan is taken');
        self::assertSame(0, $result->borrowed);
        self::assertSame(0, $result->balance->goldInBank);
    }
}
