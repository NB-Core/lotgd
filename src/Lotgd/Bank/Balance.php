<?php

declare(strict_types=1);

namespace Lotgd\Bank;

/**
 * One account's money, as the bank sees it.
 *
 * goldInBank is signed: a negative balance is a debt to the bank, and the
 * arithmetic depends on that everywhere. Deposits pay a debt down, and the
 * transfer cover check counts a debt against the cash in hand.
 */
final readonly class Balance
{
    public function __construct(
        public int $gold,
        public int $goldInBank,
        public int $level = 1,
        public int $amountOutToday = 0,
    ) {
    }

    /**
     * What the bank counts as available for a transfer.
     *
     * Cash plus balance, so an outstanding debt reduces it -- a player holding
     * 100 gold against a 50 gold debt may transfer 50, not 100.
     */
    public function transferable(): int
    {
        return $this->gold + $this->goldInBank;
    }
}
