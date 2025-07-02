<?php
// Legacy wrapper for Forest class
use Lotgd\Forest;
require_once 'lib/villagenav.php';

function forest($noshowmessage = false)
{
    Forest::forest($noshowmessage);
}
