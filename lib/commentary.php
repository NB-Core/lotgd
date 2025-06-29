<?php
// translator ready
// addnews ready
// mail ready

use Lotgd\Commentary;

$comsecs = [];

function commentarylocs()
{
    return Commentary::commentarylocs();
}

function addcommentary()
{
    Commentary::addcommentary();
}

function injectsystemcomment($section, $comment)
{
    Commentary::injectsystemcomment($section, $comment);
}

function injectrawcomment($section, $author, $comment)
{
    Commentary::injectrawcomment($section, $author, $comment);
}

function injectcommentary($section, $talkline, $comment, $schema = false)
{
    Commentary::injectcommentary($section, $talkline, $comment, $schema);
}

function commentdisplay($intro, $section, $message = 'Interject your own commentary?', $limit = 10, $talkline = 'says', $schema = false)
{
    Commentary::commentdisplay($intro, $section, $message, $limit, $talkline, $schema);
}

function viewcommentary($section, $message = 'Interject your own commentary?', $limit = 10, $talkline = 'says', $schema = false, $viewonly = false, $returnastext = false, $scriptname_pre = false)
{
    Commentary::viewcommentary($section, $message, $limit, $talkline, $schema, $viewonly, $returnastext, $scriptname_pre);
}

function talkline($section, $talkline, $limit, $schema, $counttoday, $message)
{
    return Commentary::talkline($section, $talkline, $limit, $schema, $counttoday, $message);
}

function talkform($section, $talkline, $limit = 10, $schema = false)
{
    Commentary::talkform($section, $talkline, $limit, $schema);
}
