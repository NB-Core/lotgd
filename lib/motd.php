<?php

use Lotgd\Motd;

function motd_admin($id, $poll = false)
{
    Motd::motdAdmin($id, $poll);
}

function motditem($subject, $body, $author, $date, $id)
{
    Motd::motdItem($subject, $body, $author, $date, $id);
}

function pollitem($id, $subject, $body, $author, $date, $showpoll = true)
{
    Motd::pollItem($id, $subject, $body, $author, $date, $showpoll);
}

function motd_form($id)
{
    Motd::motdForm($id);
}

function motd_poll_form()
{
    Motd::motdPollForm();
}

function motd_del($id)
{
    Motd::motdDel($id);
}
