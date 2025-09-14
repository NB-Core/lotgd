<?php

declare(strict_types=1);

// translator ready
// addnews ready
// mail ready

use Lotgd\Censor;

/**
 * Filter a text string for banned words.
 *
 * @param string $input    Input string
 * @param bool   $debug    Output debug information
 * @param bool   $skiphook Skip module hook
 *
 * @return string Filtered string
 */
function soap(string $input, bool $debug = false, bool $skiphook = false): string
{
    return Censor::soap($input, $debug, $skiphook);
}

/**
 * Retrieve exception words that bypass the filter.
 *
 * @return array<string> List of allowed words
 */
function good_word_list(): array
{
    return Censor::goodWordList();
}

/**
 * List of banned words used by the filter.
 *
 * @return array<string> Compiled regexes
 */
function nasty_word_list(): array
{
    return Censor::nastyWordList();
}
