<?php

declare(strict_types=1);

namespace Lotgd;

class RegisterGlobal
{
    /**
     * Copy variables from the given array into the global namespace.
     *
     * @param array $var Key-value pairs to export
     *
     * @return void
     */
    public static function register(array $var): void
    {
        foreach ($var as $key => $val) {
            $GLOBALS[$key] = $val;
        }
    }
}
