<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Weigh an address's recent failed logins and decide whether it earns a ban.
 *
 * This is the only automatic ban in the game: ten failures from one IP inside
 * a day and the address is locked out for fifteen minutes. A failure against a
 * superuser account counts twice, so five of those are enough -- which is what
 * login.php's comment has always claimed ("5 failed attempts for superuser, 10
 * for regular user") and what nothing ever checked. The rule lived inline in
 * the middle of the failed-login branch, in a loop that also built the alert
 * mail, and the test that covered this page read the page as text: it asserted
 * that the string "if ($c >= 10)" appeared. That assertion holds just as well
 * when the counter is wrong.
 *
 * Separated so the decision can be exercised on its own. login.php keeps its
 * own pass over the same rows for the alert text, which is presentation.
 */
final class LoginFailureTally
{
    /**
     * Weight at which an address is banned.
     *
     * Weight rather than count: a superuser failure contributes two.
     */
    public const BAN_THRESHOLD = 10;

    private function __construct(
        /** Weighted failures from this address in the window. */
        public readonly int $weight,
        /** At least one of them was against an account with superuser rights. */
        public readonly bool $privilegedSeen,
    ) {
    }

    /**
     * Weigh the failure rows read back for this address.
     *
     * @param iterable<array-key, array<string, mixed>> $recentFailures
     *     Rows from the faillog join, each carrying the failed account's
     *     `superuser` column.
     */
    public static function fromRecentFailures(iterable $recentFailures): self
    {
        $weight = 0;
        $privilegedSeen = false;

        foreach ($recentFailures as $failure) {
            // Kept as a loose comparison against the raw column, the way the
            // page has always done it: the driver hands integer columns back
            // as strings, so "1" has to weigh the same as 1. The null
            // coalesce is the one difference -- a row without the column used
            // to raise an undefined-key warning and then count as ordinary,
            // and it now just counts as ordinary.
            if (($failure['superuser'] ?? 0) > 0) {
                $weight += 1;
                $privilegedSeen = true;
            }

            $weight += 1;
        }

        return new self($weight, $privilegedSeen);
    }

    public function warrantsBan(): bool
    {
        return $this->weight >= self::BAN_THRESHOLD;
    }
}
