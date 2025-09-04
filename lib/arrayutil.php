<?php

declare(strict_types=1);

// translator ready
// addnews ready
// mail ready

use Lotgd\CreateString;

/**
 * Convert a value to string, serializing arrays when needed.
 */
function createstring(mixed $array): string
{
    return CreateString::run($array);
}
