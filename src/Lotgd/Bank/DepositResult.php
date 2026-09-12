<?php

declare(strict_types=1);

namespace Lotgd\Bank;

final readonly class DepositResult
{
    public function __construct(
        public bool $accepted,
        public Balance $balance,
        public int $amount,
    ) {
    }
}
