<?php

declare(strict_types=1);

use Lotgd\CheckBan;

function checkban(?string $login = null)
{
    CheckBan::check($login);
}
