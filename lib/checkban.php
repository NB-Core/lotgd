<?php
use Lotgd\CheckBan;

declare(strict_types=1);

function checkban(?string $login = null)
{
    CheckBan::check($login);
}

