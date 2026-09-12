<?php

declare(strict_types=1);

namespace Lotgd\Bank;

/**
 * What came of a withdrawal request.
 *
 * Withdrawing and borrowing share one entry point in the game, so they share
 * one outcome type: asking for more than the balance is an error unless the
 * request carried the borrow flag, in which case the balance is emptied first
 * and the remainder is taken as a loan.
 */
enum WithdrawOutcome
{
    /** Taken straight from the balance. */
    case Withdrawn;

    /** The balance was emptied and the rest borrowed. */
    case WithdrawnAndBorrowed;

    /** Nothing was in the balance; the whole amount is a loan. */
    case Borrowed;

    /** More than the balance, and the request did not ask to borrow. */
    case NotEnoughInBank;

    /** More than the balance plus what this level may borrow. */
    case OverBorrowingLimit;
}
