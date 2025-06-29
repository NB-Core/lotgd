<?php
// translator ready
// addnews ready
// mail ready

use Lotgd\DataCache;

function datacache(string $name, int $duration = 60)
{
    return DataCache::datacache($name, $duration);
}

function updatedatacache(string $name, $data)
{
    return DataCache::updatedatacache($name, $data);
}

function invalidatedatacache(string $name, bool $withpath = true)
{
    DataCache::invalidatedatacache($name, $withpath);
}

function massinvalidate(string $name = '')
{
    DataCache::massinvalidate($name);
}

function makecachetempname(string $name)
{
    return DataCache::makecachetempname($name);
}
