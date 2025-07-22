<?php

use Lotgd\Moderate;
use Lotgd\DateTime;
use Lotgd\Commentary;

require_once 'lib/sanitize.php';
require_once 'lib/http.php';

function commentmoderate($intro, $section, $message, $limit = 10, $talkline = 'says', $schema = false, $viewall = false)
{
    Moderate::commentmoderate($intro, $section, $message, $limit, $talkline, $schema, $viewall);
}

function viewmoderatedcommentary($section, $message = 'Interject your own commentary?', $limit = 10, $talkline = 'says', $schema = false, $viewall = false)
{
    Moderate::viewmoderatedcommentary($section, $message, $limit, $talkline, $schema, $viewall);
}
