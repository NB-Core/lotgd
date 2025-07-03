<?php
use Lotgd\Events;

function handle_event($location, $baseLink=false, $needHeader=false)
{
    return Events::handleEvent($location, $baseLink, $needHeader);
}

