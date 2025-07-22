<?php

use Lotgd\Serialization;

function safe_unserialize($data)
{
    return Serialization::safeUnserialize($data);
}

function is_valid_serialized($data)
{
    return Serialization::isValidSerialized($data);
}
