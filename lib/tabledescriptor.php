<?php

// Legacy wrapper for TableDescriptor class

use Lotgd\MySQL\TableDescriptor;

function synctable($tablename, $descriptor, $nodrop = false)
{
    return TableDescriptor::synctable($tablename, $descriptor, $nodrop);
}
function table_create_from_descriptor($tablename, $descriptor)
{
    return TableDescriptor::tableCreateFromDescriptor($tablename, $descriptor);
}
function table_create_descriptor($tablename)
{
    return TableDescriptor::tableCreateDescriptor($tablename);
}
function descriptor_createsql($input)
{
    return TableDescriptor::descriptorCreateSql($input);
}
function descriptor_sanitize_type($type)
{
    return TableDescriptor::descriptorSanitizeType($type);
}
