<?php

// translator ready
// addnews ready
// mail ready

use Lotgd\DataCache;

function datacache(string $name, int $duration = 60)
{
    return DataCache::getInstance()->datacache($name, $duration);
}

function updatedatacache(string $name, $data)
{
    return DataCache::getInstance()->updatedatacache($name, $data);
}

function invalidatedatacache(string $name, bool $withpath = true)
{
    DataCache::getInstance()->invalidatedatacache($name, $withpath);
}

function massinvalidate(string $name = '')
{
    DataCache::getInstance()->massinvalidate($name);
}

function makecachetempname(string $name)
{
    return DataCache::getInstance()->makecachetempname($name);
}
