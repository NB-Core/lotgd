<?php
use Lotgd\HolidayText;
require_once 'lib/modules.php';

function holidayize($text, $type = 'unknown')
{
    return HolidayText::holidayize($text, $type);
}
