<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Simple email address validation helper.
 */
class EmailValidator
{
    /**
     * Validate an email address format.
     */
    public static function isValid(string $email): bool
    {
        return (bool)preg_match('/^[[:alnum:]_.-]+@[[:alnum:]_.-]{2,}\.[[:alnum:]_.-]{2,}$/', $email);
    }
}
