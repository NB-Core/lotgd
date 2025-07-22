<?php

declare(strict_types=1);

// translator ready
// addnews ready
// mail ready

use Lotgd\Commentary;

$comsecs = [];

function commentarylocs(): array
{
    return Commentary::commentaryLocs();
}

function addcommentary(): void
{
    Commentary::addCommentary();
}

function injectsystemcomment(string $section, string $comment): void
{
    Commentary::injectSystemComment($section, $comment);
}

function injectrawcomment(string $section, int $author, string $comment): void
{
    Commentary::injectRawComment($section, $author, $comment);
}

function injectcommentary(string $section, string $talkline, string $comment, $schema = false): void
{
    Commentary::injectCommentary($section, $talkline, $comment, $schema);
}

function commentdisplay(string $intro, string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false): void
{
    Commentary::commentDisplay($intro, $section, $message, $limit, $talkline, $schema);
}

function viewcommentary(string $section, string $message = 'Interject your own commentary?', int $limit = 10, string $talkline = 'says', $schema = false, bool $viewonly = false, bool $returnastext = false, $scriptname_pre = false): ?string
{
    return Commentary::viewCommentary($section, $message, $limit, $talkline, $schema, $viewonly, $returnastext, $scriptname_pre);
}

function talkline(string $section, string $talkline, int $limit, $schema, int $counttoday, string $message): void
{
    Commentary::talkLine($section, $talkline, $limit, $schema, $counttoday, $message);
}

function talkform(string $section, string $talkline, int $limit = 10, $schema = false): void
{
    Commentary::talkForm($section, $talkline, $limit, $schema);
}
