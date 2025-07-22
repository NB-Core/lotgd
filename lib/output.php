<?php

namespace Lotgd {

use Lotgd\Output;

class OutputCollector extends Output
{
}

\class_alias(OutputCollector::class, 'output_collector');

}

namespace {

function set_block_new_output($block)
{
    global $output;
    $output->setBlockNewOutput($block);
}

function rawoutput($indata)
{
    global $output;
    $output->rawOutput($indata);
}

function output_notl()
{
    global $output;
    $args = func_get_args();
    call_user_func_array([$output, 'outputNotl'], $args);
}

function output()
{
    global $output;
    $args = func_get_args();
    call_user_func_array([$output, 'output'], $args);
}

function debug($text, $force = false)
{
    global $output;
    $output->debug($text, $force);
}

function appoencode($data, $priv = false)
{
    global $output;
    return $output->appoencode($data, $priv);
}

}
