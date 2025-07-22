<?php

use Lotgd\Events;

function handle_event(string $location, ?string $baseLink = null, bool $needHeader = false)
{
    return Events::handleEvent($location, $baseLink, $needHeader);
}
