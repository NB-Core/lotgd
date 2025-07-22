<?php

// Legacy wrapper for SuAccess class
use Lotgd\SuAccess;

function check_su_access($level)
{
    SuAccess::check($level);
}
