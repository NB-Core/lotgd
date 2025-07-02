<?php
namespace Lotgd;

class EmailValidator
{
    public static function isValid(string $email): bool
    {
        return (bool)preg_match('/^[[:alnum:]_.-]+@[[:alnum:]_.-]{2,}\.[[:alnum:]_.-]{2,}$/', $email);
    }
}
