<?php

use Lotgd\PlayerFunctions;

function valid_dk_title($title, $dks, $gender)
{
    return PlayerFunctions::validDkTitle($title, $dks, $gender);
}
function get_dk_title($dks, $gender, $ref = false)
{
    return PlayerFunctions::getDkTitle($dks, $gender, $ref);
}
