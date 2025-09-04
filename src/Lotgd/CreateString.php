<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Helper for converting values to strings.
 *
 * Serializes arrays and casts scalar values to string.
 */
class CreateString
{
    /**
     * Convert a value to string, serializing arrays.
     */
    public static function run(mixed $value): string
    {
        return is_array($value) ? serialize($value) : (string) $value;
    }
}
