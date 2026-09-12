<?php

declare(strict_types=1);

namespace Lotgd\Bank;

final readonly class WithdrawResult
{
    public function __construct(
        public WithdrawOutcome $outcome,
        public Balance $balance,
        /** Taken from the balance. */
        public int $withdrawn,
        /** Taken as a loan on top. */
        public int $borrowed,
        /** What this level may owe in total. */
        public int $borrowingLimit,
        /** What was asked for. */
        public int $requested,
    ) {
    }

    public function accepted(): bool
    {
        return $this->outcome !== WithdrawOutcome::NotEnoughInBank
            && $this->outcome !== WithdrawOutcome::OverBorrowingLimit;
    }
}
