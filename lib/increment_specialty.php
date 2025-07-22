<?php

use Lotgd\Specialty;

function increment_specialty($colorcode, $spec = false)
{
    Specialty::increment($colorcode, $spec);
}
