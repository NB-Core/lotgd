<?php

declare(strict_types=1);

namespace Lotgd\Bank;

/**
 * Why a transfer did not happen.
 *
 * The order of the cases is the order the bank applies them, and it is part of
 * the behaviour rather than an implementation detail: a player who is both
 * broke and naming a stranger is told they are broke, because the cover check
 * runs before the recipient is looked at.
 */
enum TransferRefusal
{
    /** Cash plus balance does not cover the amount. */
    case NotEnoughMoney;

    /** No account matched the name given. */
    case RecipientNotFound;

    /** The recipient's account is locked. */
    case RecipientLocked;

    /** The sender has already sent their daily allowance. */
    case SenderDailyLimit;

    /**
     * More than the recipient may receive in one transfer, by their level.
     *
     * Per transfer, not per day -- the daily ceiling is the separate count in
     * RecipientDailyCount, so a recipient may take several transfers of this
     * size. The player-facing message in bank.php still says "per day"; that
     * wording is older than this enum and changing it would orphan its
     * translation.
     */
    case RecipientPerTransferLimit;

    /** The recipient has already taken as many transfers as they may today. */
    case RecipientDailyCount;

    /** Less than the sender's own level, so not worth the paperwork. */
    case BelowMinimum;

    /** You cannot pay yourself. */
    case SelfTransfer;
}
