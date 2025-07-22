<?php

use Lotgd\Backtrace;

function show_no_backtrace()
{
    return Backtrace::showNoBacktrace();
}

function show_backtrace()
{
    return Backtrace::show();
}

function backtrace_getType($in)
{
    return Backtrace::getType($in);
}
