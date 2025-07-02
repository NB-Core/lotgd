<?php
namespace Lotgd;

class RegisterGlobal
{
    /**
     * Copy variables from the given array into the global namespace.
     */
    public static function register(array $var): void
    {
        foreach ($var as $key => $val) {
            $GLOBALS[$key] = $val;
        }
    }
}
