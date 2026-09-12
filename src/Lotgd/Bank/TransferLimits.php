<?php

declare(strict_types=1);

namespace Lotgd\Bank;

/**
 * The game settings a transfer is judged against.
 *
 * Named rather than passed as four integers because three of them are
 * per-level rates and one is a flat count, and getting that pairing wrong is
 * exactly the kind of mistake a type should prevent.
 */
final readonly class TransferLimits
{
    public function __construct(
        /** Gold a sender may send per day, per level. */
        public int $maxTransferOutPerLevel = 25,
        /** Gold a recipient may receive per transfer, per their level. */
        public int $transferPerLevel = 25,
        /** Number of transfers a recipient may receive per day. */
        public int $transfersReceivedPerDay = 3,
    ) {
    }
}
