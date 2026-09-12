<?php

declare(strict_types=1);

namespace Lotgd\Tests\Bank;

use Lotgd\Bank;
use Lotgd\Bank\Balance;
use Lotgd\Bank\TransferLimits;
use Lotgd\Bank\TransferRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bank::transfer() moves gold between two player accounts. It had no tests at
 * all, and it is the only place in the game where one account's money becomes
 * another's, so a rule that fails open here is a money press rather than a
 * transfer.
 *
 * Seven rules stand between the form and the booking, and they are applied in a
 * fixed order. That order is behaviour rather than bookkeeping: a player who is
 * both broke and naming a stranger is told they are broke, because the cover
 * check runs before the recipient is looked at.
 *
 * Every refusal case asserts that **nothing moved** -- not merely that a
 * refusal came back. A rule that refuses but has already debited the sender is
 * the failure that would actually matter, and asserting only on the reason
 * would not catch it.
 */
final class TransferTest extends TestCase
{
    private const SENDER_ACCOUNT = 42;

    private static function sender(int $gold = 1000, int $goldInBank = 0, int $level = 10, int $outToday = 0): Balance
    {
        return new Balance($gold, $goldInBank, $level, $outToday);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function recipient(array $overrides = []): array
    {
        return $overrides + [
            'acctid' => 7,
            'name' => 'Receiver',
            'level' => 10,
            'transferredtoday' => 0,
            'locked' => 0,
        ];
    }

    private static function limits(): TransferLimits
    {
        return new TransferLimits(maxTransferOutPerLevel: 25, transferPerLevel: 25, transfersReceivedPerDay: 3);
    }

    public function testAnAcceptedTransferDebitsTheSenderAndCountsAgainstTheirDay(): void
    {
        $result = Bank::transfer(self::sender(gold: 1000), self::SENDER_ACCOUNT, self::recipient(), 200, self::limits());

        self::assertTrue($result->accepted());
        self::assertSame(800, $result->sender->gold, 'taken from the cash in hand');
        self::assertSame(0, $result->sender->goldInBank, 'the balance is untouched while cash covers it');
        self::assertSame(200, $result->sender->amountOutToday, 'and it counts against the daily allowance');
    }

    /**
     * The sender's balance is only reached when the cash in hand runs out, and
     * the code expresses that as a negative detour: gold is allowed to go below
     * zero and that shortfall is then added to the signed balance.
     *
     * Worth a table rather than a single case, because the detour is the kind
     * of arithmetic that works for the obvious inputs and not the rest.
     */
    #[DataProvider('debitProvider')]
    public function testTheBalanceCoversWhatTheCashCannot(
        int $gold,
        int $goldInBank,
        int $amount,
        int $expectedGold,
        int $expectedBank
    ): void {
        $result = Bank::transfer(
            self::sender(gold: $gold, goldInBank: $goldInBank),
            self::SENDER_ACCOUNT,
            self::recipient(),
            $amount,
            self::limits()
        );

        self::assertTrue($result->accepted(), 'precondition: this row is meant to be an accepted transfer');
        self::assertSame($expectedGold, $result->sender->gold);
        self::assertSame($expectedBank, $result->sender->goldInBank);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    public static function debitProvider(): array
    {
        return [
            'cash covers it' => [1000, 0, 200, 800, 0],
            'cash covers it exactly' => [200, 0, 200, 0, 0],
            'cash runs out, balance pays' => [50, 500, 200, 0, 350],
            'no cash at all, balance pays' => [0, 500, 200, 0, 300],
            'cash and balance exactly cover' => [50, 150, 200, 0, 0],
            'balance carries an existing debt' => [300, -50, 200, 100, -50],
            'debt deepens when cash runs out' => [50, 200, 240, 0, 10],
        ];
    }

    /**
     * Pinned because it surprises: the cover check counts the balance, and a
     * debt makes that balance negative -- so an outstanding loan blocks a
     * transfer the cash in hand could pay for on its own.
     */
    public function testADebtBlocksATransferTheCashAloneWouldCover(): void
    {
        $result = Bank::transfer(
            self::sender(gold: 100, goldInBank: -50),
            self::SENDER_ACCOUNT,
            self::recipient(),
            60,
            self::limits()
        );

        self::assertSame(TransferRefusal::NotEnoughMoney, $result->refusal, '100 in hand, but 50 of it owed');

        $withoutDebt = Bank::transfer(
            self::sender(gold: 100, goldInBank: 0),
            self::SENDER_ACCOUNT,
            self::recipient(),
            60,
            self::limits()
        );

        self::assertTrue($withoutDebt->accepted(), 'control: the same cash without the debt goes through');
    }

    /**
     * A refused transfer must leave every figure exactly where it was.
     *
     * @param array<string, mixed>|null $recipient
     */
    #[DataProvider('refusalProvider')]
    public function testARefusedTransferMovesNothing(
        TransferRefusal $expected,
        Balance $sender,
        ?array $recipient,
        int $amount
    ): void {
        $result = Bank::transfer($sender, self::SENDER_ACCOUNT, $recipient, $amount, self::limits());

        self::assertSame($expected, $result->refusal, 'the rule that should have caught it');
        self::assertFalse($result->accepted());
        self::assertSame($sender->gold, $result->sender->gold, 'no gold may move');
        self::assertSame($sender->goldInBank, $result->sender->goldInBank, 'no balance may move');
        self::assertSame($sender->amountOutToday, $result->sender->amountOutToday, 'and nothing may count against the day');
    }

    /**
     * @return array<string, array{0: TransferRefusal, 1: Balance, 2: array<string, mixed>|null, 3: int}>
     */
    public static function refusalProvider(): array
    {
        return [
            'more than cash and balance together' => [
                TransferRefusal::NotEnoughMoney, self::sender(gold: 10, goldInBank: 10), self::recipient(), 100,
            ],
            'nobody of that name' => [
                TransferRefusal::RecipientNotFound, self::sender(), null, 100,
            ],
            'the recipient is locked' => [
                TransferRefusal::RecipientLocked, self::sender(), self::recipient(['locked' => 1]), 100,
            ],
            'the sender has sent their allowance' => [
                // level 10 x 25 = 250 a day, 200 already gone
                TransferRefusal::SenderDailyLimit, self::sender(outToday: 200), self::recipient(), 100,
            ],
            'more than the recipient may take at once' => [
                // a level 1 recipient may receive 25
                TransferRefusal::RecipientPerTransferLimit, self::sender(), self::recipient(['level' => 1]), 100,
            ],
            'the recipient has had enough today' => [
                TransferRefusal::RecipientDailyCount, self::sender(), self::recipient(['transferredtoday' => 3]), 100,
            ],
            'less than the sender is worth' => [
                TransferRefusal::BelowMinimum, self::sender(level: 10), self::recipient(), 9,
            ],
            'paying yourself' => [
                TransferRefusal::SelfTransfer, self::sender(), self::recipient(['acctid' => self::SENDER_ACCOUNT]), 100,
            ],
        ];
    }

    /**
     * The order the rules run in, stated as its own case because it decides
     * what the player is told rather than what they may do.
     *
     * Each row sets up a sender who breaks several rules at once and names the
     * one that is meant to answer.
     */
    public function testTheCoverCheckAnswersBeforeTheRecipientIsEvenLookedAt(): void
    {
        $result = Bank::transfer(
            self::sender(gold: 0, goldInBank: 0),
            self::SENDER_ACCOUNT,
            null,
            100,
            self::limits()
        );

        self::assertSame(
            TransferRefusal::NotEnoughMoney,
            $result->refusal,
            'broke and naming a stranger: the player is told they are broke'
        );
    }

    public function testALockedRecipientAnswersBeforeTheDailyLimits(): void
    {
        $result = Bank::transfer(
            self::sender(outToday: 9999),
            self::SENDER_ACCOUNT,
            self::recipient(['locked' => 1, 'transferredtoday' => 99]),
            100,
            self::limits()
        );

        self::assertSame(TransferRefusal::RecipientLocked, $result->refusal);
    }

    /**
     * The minimum is the sender's own level, so it moves with them.
     */
    public function testTheMinimumIsTheSendersLevel(): void
    {
        $atLevel = Bank::transfer(self::sender(level: 10), self::SENDER_ACCOUNT, self::recipient(), 10, self::limits());
        self::assertTrue($atLevel->accepted(), 'exactly the level is enough');

        $belowLevel = Bank::transfer(self::sender(level: 10), self::SENDER_ACCOUNT, self::recipient(), 9, self::limits());
        self::assertSame(TransferRefusal::BelowMinimum, $belowLevel->refusal);

        $lowLevel = Bank::transfer(self::sender(level: 2), self::SENDER_ACCOUNT, self::recipient(), 9, self::limits());
        self::assertTrue($lowLevel->accepted(), 'the same 9 gold from a level 2 sender is fine');
    }

    /**
     * Both limits are reported whether or not they were the reason, because
     * bank.php quotes them back to the player.
     */
    public function testTheQuotedLimitsAreAlwaysFilledIn(): void
    {
        $result = Bank::transfer(self::sender(level: 10), self::SENDER_ACCOUNT, self::recipient(['level' => 4]), 50, self::limits());

        self::assertSame(250, $result->senderDailyLimit, '10 x 25');
        self::assertSame(100, $result->recipientPerTransferLimit, '4 x 25');
    }

    /**
     * The daily allowance counts what is already gone, not just this transfer.
     */
    public function testTheDailyAllowanceIsCumulative(): void
    {
        $limits = self::limits();

        $first = Bank::transfer(self::sender(level: 10, outToday: 0), self::SENDER_ACCOUNT, self::recipient(), 250, $limits);
        self::assertTrue($first->accepted(), 'the whole allowance in one go is allowed');
        self::assertSame(250, $first->sender->amountOutToday);

        $second = Bank::transfer($first->sender, self::SENDER_ACCOUNT, self::recipient(), 1, $limits);
        self::assertSame(TransferRefusal::SenderDailyLimit, $second->refusal, 'and nothing more that day');
    }
}
