<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Configures PHP error reporting for the application.
 */
final class ErrorHandling
{
    /**
     * Set the error reporting level to all errors except notices.
     */
    public static function configure(): void
    {
        error_reporting(E_ALL ^ E_NOTICE);
    }
}

