<?php
// Legacy wrapper for \Lotgd\Output class

use Lotgd\Output;

// Provide legacy class name for modules still instantiating output_collector
class output_collector extends Output {}

function set_block_new_output($block)
{
    global $output;
    $output->set_block_new_output($block);
}

function rawoutput($indata)
{
    global $output;
    $output->rawoutput($indata);
}

function output_notl()
{
    global $output;
    $args = func_get_args();
    call_user_func_array([$output, 'output_notl'], $args);
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
