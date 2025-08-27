<?php

declare(strict_types=1);

namespace Lotgd\Util;

/**
 * Utility to determine the current script name without its extension.
 */
class ScriptName
{
    /**
     * Get the current script name without its file extension.
     */
    public static function current(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $script = rtrim($script, '/');
        return pathinfo(basename($script), PATHINFO_FILENAME);
    }
}
