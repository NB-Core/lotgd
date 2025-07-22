<?php

use Lotgd\DumpItem;

function dump_item($item)
{
    return DumpItem::dump($item);
}

function dump_item_ascode($item, $indent = "\t")
{
    return DumpItem::dumpAsCode($item, $indent);
}
