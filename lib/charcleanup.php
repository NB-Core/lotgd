<?php

use Lotgd\PlayerFunctions;

function char_cleanup($id, $type)
{
        PlayerFunctions::charCleanup((int)$id, (int)$type);
}

?>
