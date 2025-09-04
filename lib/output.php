<?php

namespace Lotgd {

    use Lotgd\Output;

    class OutputCollector extends Output
    {
    }

    \class_alias(OutputCollector::class, 'output_collector');

}

namespace {

    use Lotgd\Output;

    function set_block_new_output($block)
    {
        Output::getInstance()->setBlockNewOutput($block);
    }

    function rawoutput($indata)
    {
        Output::getInstance()->rawOutput($indata);
    }

    function output_notl()
    {
        $args = func_get_args();
        call_user_func_array([Output::getInstance(), 'outputNotl'], $args);
    }

    function output()
    {
        $args = func_get_args();
        call_user_func_array([Output::getInstance(), 'output'], $args);
    }

    function debug($text, $force = false)
    {
        Output::getInstance()->debug($text, $force);
    }

    function appoencode($data, $priv = false)
    {
        return Output::getInstance()->appoencode($data, $priv);
    }

}
