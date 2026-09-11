<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Bank\Balance;
use Lotgd\Bank\DepositResult;
use Lotgd\Bank\TransferLimits;
use Lotgd\Bank\TransferRefusal;
use Lotgd\Bank\TransferResult;
use Lotgd\Bank\WithdrawOutcome;
use Lotgd\Bank\WithdrawResult;

/**
 * The bank's arithmetic, with nothing read from global scope and nothing
 * printed.
 *
 * bank.php keeps every message, translation and navigation entry it always
 * had; it builds a Balance, calls one of these, and picks the message from the
 * result. The split is what makes the money movement testable at all -- until
 * now all of it sat inline between output lines, reading $session directly and
 * writing straight back.
 *
 * Every method returns a fresh Balance rather than mutating one. A refused
 * operation hands back the balance it was given, unchanged, so a caller that
 * writes the result back cannot half-apply a rejection.
 */
class Bank
{
    /**
     * Read a posted amount as a non-negative integer.
     *
     * The three pages used to write `abs(is_numeric($v) ? (int) $v : 0)` each
     * for themselves. That was harmless while the value only flowed into
     * comparisons and string interpolation, and stopped being harmless the
     * moment these methods took a typed `int`: `abs(PHP_INT_MIN)` is a float,
     * because the positive counterpart does not fit, and a float against an
     * `int` parameter is a TypeError under strict_types. `amount=-9223372036854775808`
     * is a perfectly postable string, so that was a crash a player could ask
     * for.
     *
     * Saturating at PHP_INT_MAX keeps the old outcome: a figure that large was
     * refused for exceeding the balance before, and still is.
     */
    public static function postedAmount(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $amount = (int) $value;

        return $amount === PHP_INT_MIN ? PHP_INT_MAX : abs($amount);
    }

    /**
     * Move gold from one account to another.
     *
     * The order of the checks is behaviour, not bookkeeping. The cover check
     * runs before the recipient is examined, so a player who is both broke and
     * naming a stranger is told they are broke -- and a caller that reordered
     * these would change what players are told without changing what they are
     * allowed to do.
     *
     * @param array<string, mixed>|null $recipient The matched account row, or
     *                                             null when no name matched.
     */
    public static function transfer(
        Balance $sender,
        int $senderAccountId,
        ?array $recipient,
        int $amount,
        TransferLimits $limits
    ): TransferResult {
        $senderDailyLimit = $sender->level * $limits->maxTransferOutPerLevel;
        $recipientLimit = (int) ($recipient['level'] ?? 0) * $limits->transferPerLevel;

        $refuse = static fn (TransferRefusal $why): TransferResult => new TransferResult(
            $why,
            $sender,
            $senderDailyLimit,
            $recipientLimit,
            $amount
        );

        if ($sender->transferable() < $amount) {
            return $refuse(TransferRefusal::NotEnoughMoney);
        }

        if ($recipient === null) {
            return $refuse(TransferRefusal::RecipientNotFound);
        }

        if (!empty($recipient['locked'])) {
            return $refuse(TransferRefusal::RecipientLocked);
        }

        if ($sender->amountOutToday + $amount > $senderDailyLimit) {
            return $refuse(TransferRefusal::SenderDailyLimit);
        }

        if ($recipientLimit < $amount) {
            return $refuse(TransferRefusal::RecipientPerTransferLimit);
        }

        if ((int) ($recipient['transferredtoday'] ?? 0) >= $limits->transfersReceivedPerDay) {
            return $refuse(TransferRefusal::RecipientDailyCount);
        }

        if ($amount < $sender->level) {
            return $refuse(TransferRefusal::BelowMinimum);
        }

        if ((int) ($recipient['acctid'] ?? 0) === $senderAccountId) {
            return $refuse(TransferRefusal::SelfTransfer);
        }

        // Taken from the cash in hand first; whatever that cannot cover is
        // drawn from the balance, which is what the negative detour below
        // expresses -- gold goes below zero and that shortfall is added to
        // goldInBank, which is itself signed.
        $gold = $sender->gold - $amount;
        $goldInBank = $sender->goldInBank;
        if ($gold < 0) {
            $goldInBank += $gold;
            $gold = 0;
        }

        return new TransferResult(
            null,
            new Balance($gold, $goldInBank, $sender->level, $sender->amountOutToday + $amount),
            $senderDailyLimit,
            $recipientLimit,
            $amount
        );
    }

    /**
     * Put gold in, or pay a debt down.
     *
     * An amount of zero means all of it, which is what the form tells the
     * player. Nothing distinguishes a deposit from a repayment here: both add
     * to a signed balance.
     */
    public static function deposit(Balance $balance, int $amount): DepositResult
    {
        if ($amount === 0) {
            $amount = $balance->gold;
        }

        if ($amount > $balance->gold) {
            return new DepositResult(false, $balance, $amount);
        }

        return new DepositResult(
            true,
            new Balance(
                $balance->gold - $amount,
                $balance->goldInBank + $amount,
                $balance->level,
                $balance->amountOutToday
            ),
            $amount
        );
    }

    /**
     * Take gold out, borrowing the remainder when asked to.
     *
     * Zero means the whole balance. Asking for more than the balance is an
     * error unless $mayBorrow is set, in which case the balance is emptied
     * first and the rest becomes debt -- the ceiling is on the total owed, so
     * an existing debt eats into what may still be borrowed.
     */
    public static function withdraw(
        Balance $balance,
        int $amount,
        bool $mayBorrow,
        int $borrowPerLevel
    ): WithdrawResult {
        $borrowingLimit = $balance->level * $borrowPerLevel;

        if ($amount === 0) {
            $amount = abs($balance->goldInBank);
        }

        $unchanged = static fn (WithdrawOutcome $outcome): WithdrawResult => new WithdrawResult(
            $outcome,
            $balance,
            0,
            0,
            $borrowingLimit,
            $amount
        );

        if ($amount <= $balance->goldInBank) {
            return new WithdrawResult(
                WithdrawOutcome::Withdrawn,
                new Balance(
                    $balance->gold + $amount,
                    $balance->goldInBank - $amount,
                    $balance->level,
                    $balance->amountOutToday
                ),
                $amount,
                0,
                $borrowingLimit,
                $amount
            );
        }

        if (!$mayBorrow) {
            return $unchanged(WithdrawOutcome::NotEnoughInBank);
        }

        if ($amount > $balance->goldInBank + $borrowingLimit) {
            return $unchanged(WithdrawOutcome::OverBorrowingLimit);
        }

        $withdrawn = 0;
        $remaining = $amount;
        $goldInBank = $balance->goldInBank;
        if ($goldInBank > 0) {
            $withdrawn = $goldInBank;
            $remaining -= $goldInBank;
            $goldInBank = 0;
        }

        return new WithdrawResult(
            $withdrawn > 0 ? WithdrawOutcome::WithdrawnAndBorrowed : WithdrawOutcome::Borrowed,
            new Balance(
                $balance->gold + $withdrawn + $remaining,
                $goldInBank - $remaining,
                $balance->level,
                $balance->amountOutToday
            ),
            $withdrawn,
            $remaining,
            $borrowingLimit,
            $amount
        );
    }
}
