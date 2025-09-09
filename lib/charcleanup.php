<?php

use Lotgd\PlayerFunctions;

function char_cleanup($id, $type): bool
{
        return PlayerFunctions::charCleanup((int)$id, (int)$type);
}
