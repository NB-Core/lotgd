<?php
namespace Lotgd;

/**
 * Recursive helper to remove slashes from values.
 */
class Stripslashes
{
    /**
     * Remove slashes recursively from input values.
     */
    public static function deep($input)
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
