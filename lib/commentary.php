<?php
// translator ready
// addnews ready
// mail ready

use Lotgd\Commentary;

$comsecs = [];

function commentarylocs()
{
    return Commentary::commentaryLocs();
}

function addcommentary()
{
    Commentary::addCommentary();
}

function injectsystemcomment($section, $comment)
{
    Commentary::injectSystemComment($section, $comment);
}

function injectrawcomment($section, $author, $comment)
{
    Commentary::injectRawComment($section, $author, $comment);
}

function injectcommentary($section, $talkline, $comment, $schema = false)
{
    Commentary::injectCommentary($section, $talkline, $comment, $schema);
}

function commentdisplay($intro, $section, $message = 'Interject your own commentary?', $limit = 10, $talkline = 'says', $schema = false)
{
    Commentary::commentDisplay($intro, $section, $message, $limit, $talkline, $schema);
}

function viewcommentary($section, $message = 'Interject your own commentary?', $limit = 10, $talkline = 'says', $schema = false, $viewonly = false, $returnastext = false, $scriptname_pre = false)
{
    Commentary::viewCommentary($section, $message, $limit, $talkline, $schema, $viewonly, $returnastext, $scriptname_pre);
}

function talkline($section, $talkline, $limit, $schema, $counttoday, $message)
{
    return Commentary::talkLine($section, $talkline, $limit, $schema, $counttoday, $message);
}

function talkform($section, $talkline, $limit = 10, $schema = false)
{
    Commentary::talkForm($section, $talkline, $limit, $schema);
}
