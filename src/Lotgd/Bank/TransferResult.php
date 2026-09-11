<?php

declare(strict_types=1);

namespace Lotgd\Bank;

/**
 * The outcome of a transfer attempt.
 *
 * A refused transfer carries the sender's balance back unchanged, so a caller
 * that writes the result back cannot accidentally half-apply a rejection --
 * the failure this test suite cares about most.
 *
 * The two limits are carried whether or not they were the reason for refusal,
 * because bank.php quotes them in its messages.
 */
final readonly class TransferResult
{
    public function __construct(
        public ?TransferRefusal $refusal,
        public Balance $sender,
        public int $senderDailyLimit,
        public int $recipientPerTransferLimit,
        public int $amount = 0,
    ) {
    }

    public function accepted(): bool
    {
        return $this->refusal === null;
    }
}
