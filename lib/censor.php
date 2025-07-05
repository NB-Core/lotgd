<?php
// translator ready
// addnews ready
// mail ready

use Lotgd\Censor;

function soap($input, $debug = false, $skiphook = false)
{
    return Censor::soap($input, $debug, $skiphook);
}

function good_word_list()
{
    return Censor::goodWordList();
}

function nasty_word_list()
{
    return Censor::nastyWordList();
}
