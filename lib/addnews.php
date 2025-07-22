<?php

declare(strict_types=1);

// addnews ready (duh ;))
// translator ready
// mail ready

use Lotgd\AddNews;

function addnews(string $text, ...$replacements)
{
    return AddNews::add($text, ...$replacements);
}

function addnews_for_user(int $user, string $news, ...$args)
{
    return AddNews::addForUser($user, $news, ...$args);
}
