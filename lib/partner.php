<?php

// Legacy wrapper for Partner class

use Lotgd\Partner;

function get_partner($player = false)
{
    return Partner::getPartner($player);
}
