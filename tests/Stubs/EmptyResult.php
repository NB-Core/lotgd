<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

/**
 * A result handle with no rows.
 *
 * The driver stub has to hand back something that behaves like what the real
 * Database returns: truthy (callers gate on `if (!Database::query($sql))`) and
 * accepted by fetchAssoc() and freeResult(), which take arrays and result
 * objects only. A bare array fails the first requirement when empty, a bare
 * string fails the second.
 */
final class EmptyResult
{
    public function fetchAssociative(): false
    {
        return false;
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function free(): void
    {
    }
}
