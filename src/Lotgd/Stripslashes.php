<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Recursive helper to remove slashes from values.
 */
class Stripslashes
{
    /**
     * Remove slashes recursively from input values.
     *
     * @param mixed $input Value or array to clean
     *
     * @return mixed Cleaned value
     */
    public static function deep(mixed $input): mixed
    {
        if (!is_array($input)) {
            return stripslashes($input);
        }
        foreach ($input as $key => $val) {
            $input[$key] = self::deep($val);
        }
        return $input;
    }
}
