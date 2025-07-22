<?php

// Legacy wrapper for OutputArray class
// translator ready
// addnews ready
// mail ready

use Lotgd\OutputArray;

function output_array($array, $prefix = "")
{
    return OutputArray::output($array, $prefix);
}

function code_array($array)
{
    return OutputArray::code($array);
}
