<?php

use Lotgd\EmailValidator;

function is_email($email)
{
    return EmailValidator::isValid($email);
}
